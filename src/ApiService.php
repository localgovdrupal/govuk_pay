<?php

namespace Drupal\govuk_pay;

use Swagger\Client\Model\RefundForSearchResult;
use Swagger\Client\Model\Refund;
use Swagger\Client\Model\PaymentWithAllLinks;
use Swagger\Client\Model\PaymentRefundRequest;
use Swagger\Client\Model\PaymentEvents;
use Swagger\Client\Model\CreatePaymentResult;
use Swagger\Client\Model\CreateCardPaymentRequest;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\ClientInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Service for interacting with the GOV.UK Pay API.
 */
class ApiService implements ApiServiceInterface {

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
   * Whether verbose logging is enabled.
   *
   * @var bool
   */
  protected $verboseLogging;

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
    $this->verboseLogging = (bool) $this->configFactory->get('govuk_pay.settings')->get('verbose_logging');
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
   * @param string|null $email
   *   Optional email address to pre-fill on the payment page.
   * @param array|null $prefilled_cardholder_details
   *   Optional cardholder details to pre-fill on the payment page.
   *
   * @return \Swagger\Client\Model\CreatePaymentResult
   *   The payment result.
   *
   * @throws \InvalidArgumentException
   *   Thrown when input parameters are invalid.
   * @throws \RuntimeException
   *   Thrown when the payment creation fails.
   */
  public function createPayment($amount, string $reference, string $description, Uri $return_url, array $metadata = [], ?string $email = NULL, ?array $prefilled_cardholder_details = NULL): CreatePaymentResult {
    try {
      // Validate input parameters.
      $this->validatePositiveNumber($amount, 'Payment amount');
      $this->validateNonEmptyString($reference, 'Payment reference');
      $this->validateNonEmptyString($description, 'Payment description');

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

      // Add optional metadata if provided.
      if (!empty($metadata)) {
        $payment_request->setMetadata($metadata);
      }

      // Add optional email if provided.
      if (!empty($email)) {
        $payment_request->setEmail($email);
      }

      // Add optional prefilled cardholder details if provided.
      if (!empty($prefilled_cardholder_details)) {
        $payment_request->setPrefilledCardholderDetails($prefilled_cardholder_details);
      }

      // Create the payment.
      $result = $cardPaymentsApi->createAPayment($payment_request);

      // Log the successful creation if verbose logging is enabled.
      if ($this->verboseLogging) {
        $this->logger->info('Created payment with ID: @id, amount: @amount', [
          '@id' => $result->getPaymentId(),
          '@amount' => $amount,
        ]);
      }

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
      $this->validateNonEmptyString($payment_id, 'Payment ID');

      // Use the service to create an API client.
      $cardPaymentsApi = $this->payClientService->createCardPaymentsApi();

      $result = $cardPaymentsApi->getAPayment($payment_id);
      $this->logger->debug('Retrieved payment with ID: @id', ['@id' => $payment_id]);
      return $result;
    }
    catch (\Exception $e) {
      return $this->handleApiException($e, 'Failed to get payment');
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
      $this->validateNonEmptyString($payment_id, 'Payment ID');

      // Use the service to create an API client.
      $cardPaymentsApi = $this->payClientService->createCardPaymentsApi();

      $result = $cardPaymentsApi->getEventsForAPayment($payment_id);
      $this->logger->debug('Retrieved payment events for ID: @id', ['@id' => $payment_id]);
      return $result;
    }
    catch (\Exception $e) {
      return $this->handleApiException($e, 'Failed to get payment events');
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
      $this->validateNonEmptyString($payment_id, 'Payment ID');

      // Use the service to create an API client.
      $cardPaymentsApi = $this->payClientService->createCardPaymentsApi();

      $cardPaymentsApi->cancelAPayment($payment_id);

      // Log the successful cancellation if verbose logging is enabled.
      if ($this->verboseLogging) {
        $this->logger->info('Cancelled payment with ID: @id', ['@id' => $payment_id]);
      }
    }
    catch (\Exception $e) {
      $this->handleApiException($e, 'Failed to cancel payment');
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
   *   The refund amount available in pence.
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
      $this->validateNonEmptyString($payment_id, 'Payment ID');
      $this->validatePositiveNumber($amount, 'Refund amount');

      // Ensure amount is an integer.
      $amount = (int) $amount;

      // Use the service to create an API client.
      $refundingApi = $this->payClientService->createRefundingCardPaymentsApi();

      $refund_request = new PaymentRefundRequest();
      $refund_request->setAmount($amount);
      $refund_request->setRefundAmountAvailable($refund_amount_available);

      $result = $refundingApi->submitARefundForAPayment($payment_id, $refund_request);

      // Log the successful refund if verbose logging is enabled.
      if ($this->verboseLogging) {
        $this->logger->info('Refunded payment with ID: @id, amount: @amount', [
          '@id' => $payment_id,
          '@amount' => $amount,
        ]);
      }

      return $result;
    }
    catch (\Exception $e) {
      return $this->handleApiException($e, 'Failed to refund payment');
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
      $this->validateNonEmptyString($payment_id, 'Payment ID');
      $this->validateNonEmptyString($refund_id, 'Refund ID');

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
      return $this->handleApiException($e, 'Failed to get refund', [
        '@refund_id' => $refund_id,
        '@id' => $payment_id,
      ]);
    }
  }

  /**
   * Get all refunds for a payment using the GOV.UK Pay API.
   *
   * @param string $payment_id
   *   The payment ID.
   *
   * @return \Swagger\Client\Model\RefundForSearchResult
   *   The refunds.
   *
   * @throws \InvalidArgumentException
   *   Thrown when the payment ID is empty.
   * @throws \RuntimeException
   *   Thrown when the refunds retrieval fails.
   */
  public function getRefunds(string $payment_id): RefundForSearchResult {
    try {
      $this->validateNonEmptyString($payment_id, 'Payment ID');

      // Use the service to create an API client.
      $refundingApi = $this->payClientService->createRefundingCardPaymentsApi();

      $result = $refundingApi->getAllRefundsForAPayment($payment_id);
      $this->logger->debug('Retrieved all refunds for payment @id', ['@id' => $payment_id]);
      return $result;
    }
    catch (\Exception $e) {
      return $this->handleApiException($e, 'Failed to get refunds', ['@id' => $payment_id]);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getClient() {
    return $this->payClientService->createCardPaymentsApi();
  }

  /**
   * Validates that a value is a non-empty string.
   *
   * @param string $value
   *   The value to validate.
   * @param string $name
   *   The name of the parameter for error messages.
   *
   * @throws \InvalidArgumentException
   *   If the value is empty.
   */
  protected function validateNonEmptyString(string $value, string $name): void {
    if (empty($value)) {
      throw new \InvalidArgumentException($name . ' cannot be empty.');
    }
  }

  /**
   * Validates that a value is a positive number.
   *
   * @param mixed $value
   *   The value to validate.
   * @param string $name
   *   The name of the parameter for error messages.
   *
   * @throws \InvalidArgumentException
   *   If the value is not a positive number.
   */
  protected function validatePositiveNumber($value, string $name): void {
    if (!is_numeric($value) || $value <= 0) {
      throw new \InvalidArgumentException($name . ' must be a positive number.');
    }
  }

  /**
   * Handles API exceptions consistently.
   *
   * @param \Exception $e
   *   The exception to handle.
   * @param string $message
   *   The error message prefix.
   * @param array $log_context
   *   Additional context for logging.
   *
   * @return mixed
   *   Never returns as it always throws an exception.
   *
   * @throws \RuntimeException
   *   Always thrown with the formatted error message.
   */
  protected function handleApiException(\Exception $e, string $message, array $log_context = []): mixed {
    $context = $log_context + ['@message' => $e->getMessage()];
    $this->logger->error($message . ': @message', $context);
    throw new \RuntimeException($message . ': ' . $e->getMessage(), 0, $e);
  }

}
