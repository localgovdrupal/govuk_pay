<?php

namespace Drupal\govuk_pay;

use Swagger\Client\Model\RefundsResponse;
use Swagger\Client\Model\Refund;
use Swagger\Client\Model\PaymentWithAllLinks;
use Swagger\Client\Model\PaymentRefundRequest;
use Swagger\Client\Model\PaymentEvents;
use Swagger\Client\Model\ExternalMetadata;
use Swagger\Client\Model\CreatePaymentResult;
use Swagger\Client\Model\CreateCardPaymentRequest;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\ClientInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
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
   * The Pay client service.
   *
   * @var \Drupal\govuk_pay\PayClientService
   */
  protected $payClientService;

  /**
   * The logger service.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Constructs a new ApiService object.
   *
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The HTTP client.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\govuk_pay\PayClientService $pay_client_service
   *   The Pay client service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   */
  public function __construct(
    ClientInterface $http_client,
    ConfigFactoryInterface $config_factory,
    PayClientService $pay_client_service,
    LoggerChannelFactoryInterface $logger_factory,
  ) {
    $this->httpClient = $http_client;
    $this->configFactory = $config_factory;
    $this->payClientService = $pay_client_service;
    $this->logger = $logger_factory->get('govuk_pay');
  }

  /**
   * Create a payment using the GOV.UK Pay API.
   *
   * @param int|string $amount
   *   The payment amount in pence. Will be converted to integer.
   * @param string $reference
   *   The payment reference.
   * @param string $description
   *   The payment description.
   * @param \GuzzleHttp\Psr7\Uri $return_url
   *   The return URL.
   * @param array $metadata
   *   Optional metadata to include with the payment.
   *   Keys must be strings, values must be scalar.
   *
   * @return \Swagger\Client\Model\CreatePaymentResult
   *   The payment result.
   *
   * @throws \InvalidArgumentException
   *   Thrown when input parameters are invalid.
   * @throws \RuntimeException
   *   Thrown when the payment creation fails.
   */
  public function createPayment($amount, string $reference, string $description, Uri $return_url, array $metadata = []): CreatePaymentResult {
    try {
      // Validate input parameters.
      if (!is_numeric($amount) || $amount <= 0) {
        throw new \InvalidArgumentException('Payment amount must be a positive number.');
      }
      if (empty($reference)) {
        throw new \InvalidArgumentException('Payment reference cannot be empty.');
      }
      if (empty($description)) {
        throw new \InvalidArgumentException('Payment description cannot be empty.');
      }

      // Ensure amount is an integer.
      $amount = (int) $amount;

      // Validate metadata values are scalar.
      foreach ($metadata as $key => $value) {
        if (!is_scalar($value)) {
          throw new \InvalidArgumentException(sprintf('Metadata value for key "%s" must be a scalar value.', $key));
        }
      }

      // Use the service to create an API client.
      $cardPaymentsApi = $this->payClientService->createCardPaymentsApi();

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
      $result = $cardPaymentsApi->createAPayment($payment_request);
      $this->logger->info('Created payment with ID: @id', ['@id' => $result->getPaymentId()]);
      return $result;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to create payment: @message', ['@message' => $e->getMessage()]);
      throw new \RuntimeException('Failed to create payment: ' . $e->getMessage(), 0, $e);
    }
  }

  /**
   * Get a payment using the GOV.UK Pay API.
   *
   * @param string $payment_id
   *   The payment ID.
   *
   * @return \Swagger\Client\Model\PaymentWithAllLinks
   *   The payment.
   *
   * @throws \InvalidArgumentException
   *   Thrown when the payment ID is empty.
   * @throws \RuntimeException
   *   Thrown when the payment retrieval fails.
   */
  public function getPayment(string $payment_id): PaymentWithAllLinks {
    try {
      if (empty($payment_id)) {
        throw new \InvalidArgumentException('Payment ID cannot be empty.');
      }

      // Use the service to create an API client.
      $cardPaymentsApi = $this->payClientService->createCardPaymentsApi();

      $result = $cardPaymentsApi->getAPayment($payment_id);
      $this->logger->debug('Retrieved payment with ID: @id', ['@id' => $payment_id]);
      return $result;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to get payment @id: @message', [
        '@id' => $payment_id,
        '@message' => $e->getMessage(),
      ]);
      throw new \RuntimeException('Failed to get payment: ' . $e->getMessage(), 0, $e);
    }
  }

  /**
   * Get payment events using the GOV.UK Pay API.
   *
   * @param string $payment_id
   *   The payment ID.
   *
   * @return \Swagger\Client\Model\PaymentEvents
   *   The payment events.
   *
   * @throws \InvalidArgumentException
   *   Thrown when the payment ID is empty.
   * @throws \RuntimeException
   *   Thrown when the payment events retrieval fails.
   */
  public function getPaymentEvents(string $payment_id): PaymentEvents {
    try {
      if (empty($payment_id)) {
        throw new \InvalidArgumentException('Payment ID cannot be empty.');
      }

      // Use the service to create an API client.
      $cardPaymentsApi = $this->payClientService->createCardPaymentsApi();

      $result = $cardPaymentsApi->getEventsForAPayment($payment_id);
      $this->logger->debug('Retrieved events for payment with ID: @id', ['@id' => $payment_id]);
      return $result;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to get payment events for @id: @message', [
        '@id' => $payment_id,
        '@message' => $e->getMessage(),
      ]);
      throw new \RuntimeException('Failed to get payment events: ' . $e->getMessage(), 0, $e);
    }
  }

  /**
   * Cancel a payment using the GOV.UK Pay API.
   *
   * @param string $payment_id
   *   The payment ID.
   *
   * @throws \InvalidArgumentException
   *   Thrown when the payment ID is empty.
   * @throws \RuntimeException
   *   Thrown when the payment cancellation fails.
   */
  public function cancelPayment(string $payment_id): void {
    try {
      if (empty($payment_id)) {
        throw new \InvalidArgumentException('Payment ID cannot be empty.');
      }

      // Use the service to create an API client.
      $cardPaymentsApi = $this->payClientService->createCardPaymentsApi();

      $cardPaymentsApi->cancelAPayment($payment_id);
      $this->logger->info('Cancelled payment with ID: @id', ['@id' => $payment_id]);
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to cancel payment @id: @message', [
        '@id' => $payment_id,
        '@message' => $e->getMessage(),
      ]);
      throw new \RuntimeException('Failed to cancel payment: ' . $e->getMessage(), 0, $e);
    }
  }

  /**
   * Refund a payment using the GOV.UK Pay API.
   *
   * @param string $payment_id
   *   The payment ID.
   * @param int|string $amount
   *   The refund amount in pence. Will be converted to integer.
   * @param string $refund_amount_available
   *   The available refund amount in pence.
   *
   * @return \Swagger\Client\Model\Refund
   *   The refund.
   *
   * @throws \InvalidArgumentException
   *   Thrown when input parameters are invalid.
   * @throws \RuntimeException
   *   Thrown when the payment refund fails.
   */
  public function refundPayment(string $payment_id, $amount, string $refund_amount_available): Refund {
    try {
      if (empty($payment_id)) {
        throw new \InvalidArgumentException('Payment ID cannot be empty.');
      }

      if (!is_numeric($amount) || $amount <= 0) {
        throw new \InvalidArgumentException('Refund amount must be a positive number.');
      }

      // Ensure amount is an integer.
      $amount = (int) $amount;

      // Use the service to create an API client.
      $refundingApi = $this->payClientService->createRefundingCardPaymentsApi();

      $refund_request = new PaymentRefundRequest();
      $refund_request->setAmount($amount);
      $refund_request->setRefundAmountAvailable($refund_amount_available);

      $result = $refundingApi->submitARefundForAPayment($payment_id, $refund_request);
      $this->logger->info('Refunded payment with ID: @id, amount: @amount', [
        '@id' => $payment_id,
        '@amount' => $amount,
      ]);
      return $result;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to refund payment @id: @message', [
        '@id' => $payment_id,
        '@message' => $e->getMessage(),
      ]);
      throw new \RuntimeException('Failed to refund payment: ' . $e->getMessage(), 0, $e);
    }
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
   *
   * @throws \InvalidArgumentException
   *   Thrown when input parameters are invalid.
   * @throws \RuntimeException
   *   Thrown when the refund retrieval fails.
   */
  public function getRefund(string $payment_id, string $refund_id): Refund {
    try {
      if (empty($payment_id)) {
        throw new \InvalidArgumentException('Payment ID cannot be empty.');
      }

      if (empty($refund_id)) {
        throw new \InvalidArgumentException('Refund ID cannot be empty.');
      }

      // Use the service to create an API client.
      $refundingApi = $this->payClientService->createRefundingCardPaymentsApi();

      $result = $refundingApi->getAPaymentRefund($payment_id, $refund_id);
      $this->logger->debug('Retrieved refund @refund_id for payment @id', [
        '@refund_id' => $refund_id,
        '@id' => $payment_id,
      ]);
      return $result;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to get refund @refund_id for payment @id: @message', [
        '@refund_id' => $refund_id,
        '@id' => $payment_id,
        '@message' => $e->getMessage(),
      ]);
      throw new \RuntimeException('Failed to get refund: ' . $e->getMessage(), 0, $e);
    }
  }

  /**
   * Get all refunds for a payment using the GOV.UK Pay API.
   *
   * @param string $payment_id
   *   The payment ID.
   *
   * @return \Swagger\Client\Model\RefundsResponse
   *   The refunds.
   *
   * @throws \InvalidArgumentException
   *   Thrown when the payment ID is empty.
   * @throws \RuntimeException
   *   Thrown when the refunds retrieval fails.
   */
  public function getRefunds(string $payment_id): RefundsResponse {
    try {
      if (empty($payment_id)) {
        throw new \InvalidArgumentException('Payment ID cannot be empty.');
      }

      // Use the service to create an API client.
      $refundingApi = $this->payClientService->createRefundingCardPaymentsApi();

      $result = $refundingApi->getAllRefundsForAPayment($payment_id);
      $this->logger->debug('Retrieved all refunds for payment @id', ['@id' => $payment_id]);
      return $result;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to get refunds for payment @id: @message', [
        '@id' => $payment_id,
        '@message' => $e->getMessage(),
      ]);
      throw new \RuntimeException('Failed to get refunds: ' . $e->getMessage(), 0, $e);
    }
  }

}
