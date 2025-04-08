<?php

namespace Drupal\govuk_pay;

use Swagger\Client\Model\PaymentRefundRequest;
use Swagger\Client\Model\ExternalMetadata;
use Swagger\Client\Model\CreateCardPaymentRequest;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\ClientInterface;
use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Service for interacting with the GOV.UK Pay API.
 */
class ApiService {

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Constructs a new ApiService object.
   *
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The HTTP client.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   */
  public function __construct(ClientInterface $http_client, ConfigFactoryInterface $config_factory) {
    $this->httpClient = $http_client;
    $this->configFactory = $config_factory;
  }

  /**
   * Create a payment using the GOV.UK Pay API.
   *
   * @param int $amount
   *   The payment amount in pence.
   * @param string $reference
   *   The payment reference.
   * @param string $description
   *   The payment description.
   * @param \GuzzleHttp\Psr7\Uri $return_url
   *   The return URL.
   * @param array $metadata
   *   Optional metadata to include with the payment.
   *
   * @return \Swagger\Client\Model\CreatePaymentResult
   *   The payment result.
   */
  public function createPayment($amount, $reference, $description, Uri $return_url, array $metadata = []) {
    // Use the factory to create an API client.
    $cardPaymentsApi = PayClientFactory::createCardPaymentsApi($this->httpClient, $this->configFactory);

    // Prepare the payment request.
    $payment_request = new CreateCardPaymentRequest();
    $payment_request->setAmount($amount);
    $payment_request->setReference($reference);
    $payment_request->setDescription($description);
    $payment_request->setReturnUrl((string) $return_url);

    if (!empty($metadata)) {
      $external_metadata = new ExternalMetadata();
      foreach ($metadata as $key => $value) {
        $external_metadata[$key] = $value;
      }
      $payment_request->setMetadata($external_metadata);
    }

    // Create the payment.
    return $cardPaymentsApi->createAPayment($payment_request);
  }

  /**
   * Get a payment using the GOV.UK Pay API.
   *
   * @param string $payment_id
   *   The payment ID.
   *
   * @return \Swagger\Client\Model\PaymentWithAllLinks
   *   The payment.
   */
  public function getPayment($payment_id) {
    // Use the factory to create an API client.
    $cardPaymentsApi = PayClientFactory::createCardPaymentsApi($this->httpClient, $this->configFactory);

    return $cardPaymentsApi->getAPayment($payment_id);
  }

  /**
   * Get payment events using the GOV.UK Pay API.
   *
   * @param string $payment_id
   *   The payment ID.
   *
   * @return \Swagger\Client\Model\PaymentEvents
   *   The payment events.
   */
  public function getPaymentEvents($payment_id) {
    // Use the factory to create an API client.
    $cardPaymentsApi = PayClientFactory::createCardPaymentsApi($this->httpClient, $this->configFactory);

    return $cardPaymentsApi->getEventsForAPayment($payment_id);
  }

  /**
   * Cancel a payment using the GOV.UK Pay API.
   *
   * @param string $payment_id
   *   The payment ID.
   */
  public function cancelPayment($payment_id) {
    // Use the factory to create an API client.
    $cardPaymentsApi = PayClientFactory::createCardPaymentsApi($this->httpClient, $this->configFactory);

    $cardPaymentsApi->cancelAPayment($payment_id);
  }

  /**
   * Refund a payment using the GOV.UK Pay API.
   *
   * @param string $payment_id
   *   The payment ID.
   * @param int $amount
   *   The refund amount in pence.
   * @param string $refund_amount_available
   *   The available refund amount in pence.
   *
   * @return \Swagger\Client\Model\Refund
   *   The refund.
   */
  public function refundPayment($payment_id, $amount, $refund_amount_available) {
    // Use the factory to create an API client.
    $refundingApi = PayClientFactory::createRefundingCardPaymentsApi($this->httpClient, $this->configFactory);

    $refund_request = new PaymentRefundRequest();
    $refund_request->setAmount($amount);
    $refund_request->setRefundAmountAvailable($refund_amount_available);
    return $refundingApi->submitARefundForAPayment($payment_id, $refund_request);
  }

  /**
   * Get a refund using the GOV.UK Pay API.
   *
   * @param string $payment_id
   *   The payment ID.
   * @param string $refund_id
   *   The refund ID.
   *
   * @return \Swagger\Client\Model\Refund
   *   The refund.
   */
  public function getRefund($payment_id, $refund_id) {
    // Use the factory to create an API client.
    $refundingApi = PayClientFactory::createRefundingCardPaymentsApi($this->httpClient, $this->configFactory);

    return $refundingApi->getAPaymentRefund($payment_id, $refund_id);
  }

  /**
   * Get all refunds for a payment using the GOV.UK Pay API.
   *
   * @param string $payment_id
   *   The payment ID.
   *
   * @return \Swagger\Client\Model\RefundsResponse
   *   The refunds.
   */
  public function getRefunds($payment_id) {
    // Use the factory to create an API client.
    $refundingApi = PayClientFactory::createRefundingCardPaymentsApi($this->httpClient, $this->configFactory);

    return $refundingApi->getAllRefundsForAPayment($payment_id);
  }

}
