<?php

namespace Drupal\commerce_authnet\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\CreditCard;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Entity\PaymentMethodInterface;
use Drupal\commerce_payment\Exception\HardDeclineException;
use Drupal\commerce_payment\Exception\InvalidResponseException;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_price\Price;
use CommerceGuys\AuthNet\CreateCustomerPaymentProfileRequest;
use CommerceGuys\AuthNet\CreateCustomerProfileRequest;
use CommerceGuys\AuthNet\CreateTransactionRequest;
use CommerceGuys\AuthNet\DataTypes\BillTo;
use CommerceGuys\AuthNet\DataTypes\CreditCard as CreditCardDataType;
use CommerceGuys\AuthNet\DataTypes\LineItem;
use CommerceGuys\AuthNet\DataTypes\Order as OrderDataType;
use CommerceGuys\AuthNet\DataTypes\OpaqueData;
use CommerceGuys\AuthNet\DataTypes\PaymentProfile;
use CommerceGuys\AuthNet\DataTypes\Profile;
use CommerceGuys\AuthNet\DataTypes\TransactionRequest;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsRefundsInterface;
use CommerceGuys\AuthNet\DataTypes\ShipTo;

/**
 * Provides the Accept.js payment gateway.
 *
 * @CommercePaymentGateway(
 *   id = "authorizenet_acceptjs",
 *   label = "Authorize.net (Accept.js)",
 *   display_label = "Authorize.net",
 *   forms = {
 *     "add-payment-method" = "Drupal\commerce_authnet\PluginForm\AcceptJsAddForm",
 *   },
 *   payment_method_types = {"credit_card"},
 *   credit_card_types = {
 *     "amex", "dinersclub", "discover", "jcb", "mastercard", "visa"
 *   },
 * )
 */
class AcceptJs extends OnsiteBase implements SupportsRefundsInterface {

  /**
   * {@inheritdoc}
   */
  public function createPayment(PaymentInterface $payment, $capture = TRUE) {
    $this->assertPaymentState($payment, ['new']);
    $payment_method = $payment->getPaymentMethod();
    $this->assertPaymentMethod($payment_method);


    $order = $payment->getOrder();
    $owner = $payment_method->getOwner();

    // Transaction request.
    $transaction_request = new TransactionRequest([
      'transactionType' => ($capture) ? TransactionRequest::AUTH_CAPTURE : TransactionRequest::AUTH_ONLY,
      'amount' => $payment->getAmount()->getNumber(),
    ]);

    // @todo update SDK to support data type like this.
    // Initializing the profile to charge and adding it to the transaction.
    $customer_profile_id = $this->getRemoteCustomerId($owner);

    // Anonymous users get the customer profile and payment profile ids from
    // the payment method remote id.
    if (!$customer_profile_id) {
      list($customer_profile_id, $payment_profile_id) = explode('|', $payment_method->getRemoteId());
    }
    else {
      $payment_profile_id = $payment_method->getRemoteId();
    }
    $profile_to_charge = new Profile(['customerProfileId' => $customer_profile_id]);
    $profile_to_charge->addData('paymentProfile', ['paymentProfileId' => $payment_profile_id]);
    $transaction_request->addData('profile', $profile_to_charge->toArray());
    if (\Drupal::moduleHandler()->moduleExists('commerce_shipping') && $order->hasField('shipments') && !($order->get('shipments')->isEmpty())) {
      /** @var \Drupal\commerce_shipping\Entity\ShipmentInterface[] $shipments */
      $shipments = $payment->getOrder()->get('shipments')->referencedEntities();
      $first_shipment = reset($shipments);
      /** @var \Drupal\address\Plugin\Field\FieldType\AddressItem $shipping_address */
      $shipping_address = $first_shipment->getShippingProfile()->address->first();
      $ship_data = [
        // @todo how to allow customizing this.
        'firstName' => $shipping_address->getGivenName(),
        'lastName' => $shipping_address->getFamilyName(),
        'address' => substr($shipping_address->getAddressLine1() . ' ' . $shipping_address->getAddressLine2(), 0, 60),
        'country' => $shipping_address->getCountryCode(),
        'company' => $shipping_address->getOrganization(),
      ];
      if ($shipping_address->getLocality() != '') {
        $ship_data['city'] = $shipping_address->getLocality();
      }
      if ($shipping_address->getAdministrativeArea() != '') {
        $ship_data['state'] = $shipping_address->getAdministrativeArea();
      }
      if ($shipping_address->getPostalCode() != '') {
        $ship_data['zip'] = $shipping_address->getPostalCode();
      }
      $transaction_request->addDataType(new ShipTo($ship_data));
    }

    // Adding order information to the transaction.
    $transaction_request->addOrder(new OrderDataType([
      'invoiceNumber' => $order->getOrderNumber() ?: $order->id(),
    ]));
    $transaction_request->addData('customerIP', $order->getIpAddress());

    // Adding line items.
    $line_items = $this->getLineItems($order);
    foreach ($line_items as $line_item) {
      $transaction_request->addLineItem($line_item);
    }

    // Adding tax information to the transaction.
    $transaction_request->addData('tax', $this->getTax($order)->toArray());

    $request = new CreateTransactionRequest($this->authnetConfiguration, $this->httpClient);
    $request->setTransactionRequest($transaction_request);
    $response = $request->execute();

    if ($response->getResultCode() != 'Ok') {
      $this->logResponse($response);
      $message = $response->getMessages()[0];
      switch ($message->getCode()) {
        case 'E00040':
          $payment_method->delete();
          throw new PaymentGatewayException('The provided payment method is no longer valid');

        default:
          throw new PaymentGatewayException($message->getText());
      }
    }

    if (!empty($response->getErrors())) {
      $message = $response->getErrors()[0];
      throw new HardDeclineException($message->getText());
    }

    $next_state = $capture ? 'completed' : 'authorization';
    $payment->setState($next_state);
    $payment->setRemoteId($response->transactionResponse->transId);
    // @todo Find out how long an authorization is valid, set its expiration.
    $payment->save();
  }

  /**
   * {@inheritdoc}
   */
  public function refundPayment(PaymentInterface $payment, Price $amount = NULL) {
    /** @var \Drupal\commerce_payment\Entity\PaymentMethod $payment_method */
    $payment_method = $payment->getPaymentMethod();
    $this->assertPaymentState($payment, ['completed', 'partially_refunded']);
    // If not specified, refund the entire amount.
    $amount = $amount ?: $payment->getAmount();
    $this->assertRefundAmount($payment, $amount);

    $request = new CreateTransactionRequest($this->authnetConfiguration, $this->httpClient);
    $transaction_request = new TransactionRequest([
      'transactionType' => TransactionRequest::REFUND,
      'amount' => $amount->getNumber(),
      'refTransId' => $payment->getRemoteId(),
    ]);
    // Adding order information to the transaction.
    $order = $payment->getOrder();
    $transaction_request->addOrder(new OrderDataType([
      'invoiceNumber' => $order->getOrderNumber() ?: $order->id(),
    ]));
    $transaction_request->addPayment(new CreditCardDataType([
      'cardNumber' => $payment_method->card_number->value,
      'expirationDate' => str_pad($payment_method->card_exp_month->value, 2, '0', STR_PAD_LEFT) . str_pad($payment_method->card_exp_year->value, 2, '0', STR_PAD_LEFT),
    ]));
    $request->setTransactionRequest($transaction_request);
    $response = $request->execute();

    if ($response->getResultCode() != 'Ok') {
      $this->logResponse($response);
      $message = $response->getMessages()[0];
      throw new PaymentGatewayException($message->getText());
    }

    $old_refunded_amount = $payment->getRefundedAmount();
    $new_refunded_amount = $old_refunded_amount->add($amount);
    if ($new_refunded_amount->lessThan($payment->getAmount())) {
      $payment->setState('partially_refunded');
    }
    else {
      $payment->setState('refunded');
    }

    $payment->setRefundedAmount($new_refunded_amount);
    $payment->save();
  }

  /**
   * {@inheritdoc}
   *
   * @todo Needs kernel test
   */
  public function createPaymentMethod(PaymentMethodInterface $payment_method, array $payment_details) {
    $required_keys = [
      'data_descriptor', 'data_value',
    ];
    foreach ($required_keys as $required_key) {
      if (empty($payment_details[$required_key])) {
        throw new \InvalidArgumentException(sprintf('$payment_details must contain the %s key.', $required_key));
      }
    }
    $remote_payment_method = $this->doCreatePaymentMethod($payment_method, $payment_details);
    $payment_method->card_type = $this->mapCreditCardType($remote_payment_method['card_type']);
    $payment_method->card_number = $remote_payment_method['last4'];
    $payment_method->card_exp_month = $remote_payment_method['expiration_month'];
    $payment_method->card_exp_year = $remote_payment_method['expiration_year'];
    $payment_method->setRemoteId($remote_payment_method['remote_id']);
    $expires = CreditCard::calculateExpirationTimestamp($remote_payment_method['expiration_month'], $remote_payment_method['expiration_year']);
    $payment_method->setExpiresTime($expires);

    $payment_method->save();
  }

  /**
   * Creates the payment method on the gateway.
   *
   * @todo Rename to customer profile
   * @todo Make a method for just creating payment profile on existing profile.
   *
   * @param \Drupal\commerce_payment\Entity\PaymentMethodInterface $payment_method
   *   The payment method.
   * @param array $payment_details
   *   The gateway-specific payment details.
   *
   * @return array
   *   The payment method information returned by the gateway. Notable keys:
   *   - token: The remote ID.
   *   Credit card specific keys:
   *   - card_type: The card type.
   *   - last4: The last 4 digits of the credit card number.
   *   - expiration_month: The expiration month.
   *   - expiration_year: The expiration year.
   */
  protected function doCreatePaymentMethod(PaymentMethodInterface $payment_method, array $payment_details) {
    $owner = $payment_method->getOwner();
    $customer_profile_id = NULL;
    $customer_data = [];
    if ($owner && !$owner->isAnonymous()) {
      $customer_profile_id = $this->getRemoteCustomerId($owner);
      $customer_data['email'] = $owner->getEmail();
    }

    if ($customer_profile_id) {
      $payment_profile = $this->buildCustomerPaymentProfile($payment_method, $payment_details, $customer_profile_id);
      $request = new CreateCustomerPaymentProfileRequest($this->authnetConfiguration, $this->httpClient);
      $request->setCustomerProfileId($customer_profile_id);
      $request->setPaymentProfile($payment_profile);
      $response = $request->execute();

      if ($response->getResultCode() != 'Ok') {
        $this->logResponse($response);
        $error = $response->getMessages()[0];
        switch ($error->getCode()) {
          case 'E00039':
            if (!isset($response->customerPaymentProfileId)) {
              throw new InvalidResponseException('Duplicate payment profile ID, however could not get existing ID.');
            }
            break;

          case 'E00040':
            // The customer record ID is invalid, remove it.
            // @note this should only happen in development scenarios.
            $this->setRemoteCustomerId($owner, NULL);
            $owner->save();
            throw new InvalidResponseException('The customer record could not be found');

          default:
            throw new InvalidResponseException($error->getText());
        }
      }

      $payment_profile_id = $response->customerPaymentProfileId;
    }
    else {
      $request = new CreateCustomerProfileRequest($this->authnetConfiguration, $this->httpClient);

      if ($owner->isAuthenticated()) {
        $profile = new Profile([
          // @todo how to allow altering.
          'merchantCustomerId' => $owner->id(),
          'email' => $owner->getEmail(),
        ]);
      }
      else {
        $profile = new Profile([
          // @todo how to allow altering.
          'merchantCustomerId' => $owner->id() . '_' . $this->time->getRequestTime(),
          'email' => $payment_details['customer_email'],
        ]);
      }
      $profile->addPaymentProfile($this->buildCustomerPaymentProfile($payment_method, $payment_details));
      $request->setProfile($profile);
      $response = $request->execute();

      if ($response->getResultCode() == 'Ok') {
        $payment_profile_id = $response->customerPaymentProfileIdList->numericString;
        $customer_profile_id = $response->customerProfileId;
      }
      else {
        // Handle duplicate.
        if ($response->getMessages()[0]->getCode() == 'E00039') {
          $result = array_filter(explode(' ', $response->getMessages()[0]->getText()), 'is_numeric');
          $customer_profile_id = reset($result);

          $payment_profile = $this->buildCustomerPaymentProfile($payment_method, $payment_details, $customer_profile_id);
          $request = new CreateCustomerPaymentProfileRequest($this->authnetConfiguration, $this->httpClient);
          $request->setCustomerProfileId($customer_profile_id);
          $request->setPaymentProfile($payment_profile);
          $response = $request->execute();

          if ($response->getResultCode() != 'Ok') {
            $this->logResponse($response);
            throw new InvalidResponseException("Unable to create payment profile for existing customer");
          }

          $payment_profile_id = $response->customerPaymentProfileId;
        }
        else {
          $this->logResponse($response);
          throw new InvalidResponseException("Unable to create customer profile.");
        }
      }

      if ($owner) {
        $this->setRemoteCustomerId($owner, $customer_profile_id);
        $owner->save();
      }
    }

    // Maybe we should make sure that this is going to be a string before calling an explode on it.
    if ($owner->isAuthenticated()) {
      $validation_direct_response = explode(',', $response->contents()->validationDirectResponse);

      // when user is authenticated we can retrieve customer profile from the user entity so
      // we only need to save the payment profile id as token.
      $remote_id = $payment_profile_id;
    }
    else {
      // somehow for anonymous user it's returning this way
      $validation_direct_response = explode(',', $response->contents()->validationDirectResponseList->string);

      // For anonymous user we use both customer id
      // and payment profile id as token.
      $remote_id = $customer_profile_id . '|' . $payment_profile_id;
    }
    // Assuming the explode is working card_type is at index 51.
    $card_type = $validation_direct_response[51];
    return [
      'remote_id' => $remote_id,
      'card_type' => $card_type,
      'last4' => $payment_details['last4'],
      'expiration_month' => $payment_details['expiration_month'],
      'expiration_year' => $payment_details['expiration_year'],
    ];
  }

  /**
   * Creates a new customer payment profile in Authorize.net CIM.
   *
   * @param \Drupal\commerce_payment\Entity\PaymentMethodInterface $payment_method
   *   The payment method.
   * @param array $payment_details
   *   The gateway-specific payment details.
   * @param string $customer_id
   *   The remote customer ID, if available.
   *
   * @return \CommerceGuys\AuthNet\DataTypes\PaymentProfile
   *   The payment profile data type.
   */
  protected function buildCustomerPaymentProfile(PaymentMethodInterface $payment_method, array $payment_details, $customer_id = NULL) {
    /** @var \Drupal\address\AddressInterface $address */
    $address = $payment_method->getBillingProfile()->address->first();
    $bill_to = [
      // @todo how to allow customizing this.
      'firstName' => $address->getGivenName(),
      'lastName' => $address->getFamilyName(),
      'company' => $address->getOrganization(),
      'address' => substr($address->getAddressLine1() . ' ' . $address->getAddressLine2(), 0, 60),
      'country' => $address->getCountryCode(),
      // @todo support adding phone and fax
    ];
    if ($address->getLocality() != '') {
      $bill_to['city'] = $address->getLocality();
    }
    if ($address->getAdministrativeArea() != '') {
      $bill_to['state'] = $address->getAdministrativeArea();
    }
    if ($address->getPostalCode() != '') {
      $bill_to['zip'] = $address->getPostalCode();
    }

    $payment = new OpaqueData([
      'dataDescriptor' => $payment_details['data_descriptor'],
      'dataValue' => $payment_details['data_value'],
    ]);

    $payment_profile = new PaymentProfile([
      // @todo how to allow customizing this.
      'customerType' => 'individual',
    ]);
    $payment_profile->addBillTo(new BillTo($bill_to));
    $payment_profile->addPayment($payment);

    return $payment_profile;
  }

  /**
   * Maps the Authorize.Net credit card type to a Commerce credit card type.
   *
   * @param string $card_type
   *   The Authorize.Net credit card type.
   *
   * @return string
   *   The Commerce credit card type.
   */
  protected function mapCreditCardType($card_type) {
    $map = [
      'American Express' => 'amex',
      'Diners Club' => 'dinersclub',
      'Discover' => 'discover',
      'JCB' => 'jcb',
      'MasterCard' => 'mastercard',
      'Visa' => 'visa',
    ];
    if (!isset($map[$card_type])) {
      throw new HardDeclineException(sprintf('Unsupported credit card type "%s".', $card_type));
    }

    return $map[$card_type];
  }

  /**
   * Gets the line items from order.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return \CommerceGuys\AuthNet\DataTypes\LineItem[]
   *   An array of line items.
   */
  protected function getLineItems(OrderInterface $order) {
    $line_items = [];
    foreach ($order->getItems() as $order_item) {
      $name = $order_item->label();
      $name = (strlen($name) > 31) ? substr($name, 0, 28) . '...' : $name;

      $line_items[] = new LineItem([
        'itemId' => $order_item->id(),
        'name' => $name,
        'quantity' => $order_item->getQuantity(),
        'unitPrice' => $order_item->getUnitPrice()->getNumber(),
      ]);
    }

    return $line_items;
  }

}
