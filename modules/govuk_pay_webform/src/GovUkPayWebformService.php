<?php

namespace Drupal\govuk_pay_webform;

use Symfony\Component\HttpFoundation\RequestStack;
use GuzzleHttp\Psr7\Uri;
use Drupal\webform\WebformSubmissionInterface;
use Drupal\govuk_pay\Entity\GovUkPayment;
use Drupal\govuk_pay\ApiService;
use Drupal\Core\Utility\Token;
use Drupal\Core\Url;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Component\Uuid\UuidInterface;

/**
 * Service for GOV.UK Pay Webform operations.
 */
class GovUkPayWebformService {

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The API service.
   *
   * @var \Drupal\govuk_pay\ApiService
   */
  protected $apiService;

  /**
   * The UUID service.
   *
   * @var \Drupal\Component\Uuid\UuidInterface
   */
  protected $uuidService;

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * The logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * The tempstore service.
   *
   * @var \Drupal\Core\TempStore\PrivateTempStore
   */
  protected $tempStore;

  /**
   * The token service.
   *
   * @var \Drupal\Core\Utility\Token
   */
  protected $token;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * Constructs a new GovUkPayWebformService.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\govuk_pay\ApiService $api_service
   *   The API service.
   * @param \Drupal\Component\Uuid\UuidInterface $uuid_service
   *   The UUID service.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Drupal\Core\TempStore\PrivateTempStoreFactory $temp_store_factory
   *   The tempstore factory.
   * @param \Drupal\Core\Utility\Token $token
   *   The token service.
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    ApiService $api_service,
    UuidInterface $uuid_service,
    RequestStack $request_stack,
    EntityTypeManagerInterface $entity_type_manager,
    LoggerChannelFactoryInterface $logger_factory,
    PrivateTempStoreFactory $temp_store_factory,
    Token $token,
    AccountProxyInterface $current_user,
  ) {
    $this->configFactory = $config_factory;
    $this->apiService = $api_service;
    $this->uuidService = $uuid_service;
    $this->requestStack = $request_stack;
    $this->entityTypeManager = $entity_type_manager;
    $this->loggerFactory = $logger_factory;
    $this->logger = $logger_factory->get('govuk_pay_webform');
    $this->tempStore = $temp_store_factory->get('govuk_pay_webform');
    $this->token = $token;
    $this->currentUser = $current_user;
  }

  /**
   * Create and process a payment for a webform submission.
   *
   * @param \Drupal\webform\WebformSubmissionInterface $webform_submission
   *   The webform submission.
   * @param array $configuration
   *   The handler configuration.
   *
   * @return bool
   *   TRUE if payment was created and redirect initiated, FALSE otherwise.
   *
   * @throws \Exception
   *   Throws exception if payment creation fails.
   */
  public function createPayment(WebformSubmissionInterface $webform_submission, array $configuration) {
    // Validate configuration and required parameters.
    $this->validatePaymentConfiguration($configuration);

    $sid = $webform_submission->id();
    $webform = $webform_submission->getWebform();
    $amount = $this->calculateAmount($webform_submission, $configuration);

    if (empty($amount)) {
      throw new \RuntimeException('Payment amount could not be determined.');
    }

    // Process payment description and reference.
    $payment_for = $this->processPaymentDescription($configuration, $webform_submission, $webform);
    $payment_reference = $this->processPaymentReference($configuration, $webform_submission);

    // Generate UUID and store payment data in session.
    $uuid = $this->uuidService->generate();
    $this->storePaymentData($uuid, $webform->id(), $sid);

    // Create return URL for GOV.UK Pay to redirect back to.
    $returnUrl = $this->createReturnUrl();

    // Process metadata and cardholder details.
    $metadata = $this->processMetadata($configuration, $webform_submission);
    $email = $this->processEmailField($configuration, $webform_submission);
    $prefilled_cardholder_details = $this->processCardholderDetails($configuration, $webform_submission);

    // Create the payment via API.
    $payment_response = $this->apiService->createPayment(
      $amount,
      $payment_reference,
      $payment_for,
      $returnUrl,
      $metadata,
      $email,
      $prefilled_cardholder_details
    );

    // Create the payment entity.
    $this->createPaymentEntity(
      $payment_response,
      $uuid,
      $amount,
      $webform->id(),
      $sid,
      $payment_for,
      $payment_reference,
    );

    // Handle redirect to GOV.UK Pay.
    return $this->handlePaymentRedirect($payment_response);
  }

  /**
   * Validate payment configuration and required parameters.
   *
   * @param array $configuration
   *   The handler configuration.
   *
   * @throws \RuntimeException
   *   If required configuration is missing.
   */
  protected function validatePaymentConfiguration(array $configuration = []) {
    // Load config for GOV.UK Pay.
    $config = $this->configFactory->get('govuk_pay.settings');

    // Validate required parameters.
    if (empty($config->get('gov_pay__apikey'))) {
      throw new \RuntimeException('GOV.UK Pay API key is not configured. This is a required field on /admin/config/govuk_pay/settings.');
    }

    // Validate payment_for is present in configuration.
    if (!empty($configuration) && empty($configuration['payment_for'])) {
      throw new \RuntimeException('Missing required payment description (payment_for)');
    }
  }

  /**
   * Process the payment description.
   *
   * @param array $configuration
   *   The handler configuration.
   * @param \Drupal\webform\WebformSubmissionInterface $webform_submission
   *   The webform submission.
   * @param \Drupal\webform\WebformInterface $webform
   *   The webform.
   *
   * @return string
   *   The processed payment description.
   */
  protected function processPaymentDescription(array $configuration, WebformSubmissionInterface $webform_submission, $webform) {
    // Process the payment description with token replacement.
    $payment_for = $this->replaceTokens($configuration['payment_for'] ?? '', $webform_submission);
    if (empty($payment_for)) {
      $payment_for = $webform->label();
    }

    // Ensure the description doesn't exceed GOV.UK Pay's limit.
    if (strlen($payment_for) > 254) {
      $payment_for = substr($payment_for, 0, 251) . '...';
    }

    return $payment_for;
  }

  /**
   * Process the payment reference.
   *
   * @param array $configuration
   *   The handler configuration.
   * @param \Drupal\webform\WebformSubmissionInterface $webform_submission
   *   The webform submission.
   *
   * @return string
   *   The processed payment reference.
   *
   * @throws \RuntimeException
   *   If payment reference cannot be determined.
   */
  protected function processPaymentReference(array $configuration, WebformSubmissionInterface $webform_submission) {
    // Process payment reference with token replacement.
    $payment_reference = $this->replaceTokens($configuration['payment_reference'] ?? '', $webform_submission);

    // If payment_reference is empty, use the global reference from settings as fallback.
    if (empty($payment_reference)) {
      $config = $this->configFactory->get('govuk_pay.settings');
      if (empty($config->get('gov_pay__reference'))) {
        throw new \RuntimeException('GOV.UK Pay reference is not configured. This is a required field on /admin/config/govuk_pay/settings.');
      }
      $payment_reference = $config->get('gov_pay__reference');
    }

    return $payment_reference;
  }

  /**
   * Store payment data in the session.
   *
   * @param string $uuid
   *   The UUID for the payment.
   * @param string $webform_id
   *   The webform ID.
   * @param string $submission_id
   *   The submission ID.
   */
  protected function storePaymentData($uuid, $webform_id, $submission_id) {
    // Create the payment data array to be stored in the session.
    $payment_data = [
      'uuid' => $uuid,
      'webform_id' => $webform_id,
      'submission_id' => $submission_id,
    ];

    // Store payment data in the session.
    $this->setPaymentData($payment_data);
  }

  /**
   * Create the return URL for GOV.UK Pay.
   *
   * @return \GuzzleHttp\Psr7\Uri
   *   The return URL.
   */
  protected function createReturnUrl() {
    // Create the return URL.
    $url_object = Url::fromRoute('govuk_pay_webform.confirmation_page', [], ['absolute' => TRUE]);
    return new Uri($url_object->toString());
  }

  /**
   * Process metadata from configuration.
   *
   * @param array $configuration
   *   The handler configuration.
   * @param \Drupal\webform\WebformSubmissionInterface $webform_submission
   *   The webform submission.
   *
   * @return array
   *   The processed metadata.
   */
  protected function processMetadata(array $configuration, WebformSubmissionInterface $webform_submission) {
    $metadata = [];
    if (!empty($configuration['metadata']) && is_array($configuration['metadata'])) {
      foreach ($configuration['metadata'] as $item) {
        if (!empty($item['key']) && isset($item['value'])) {
          // Process tokens in both key and value.
          $key = $this->replaceTokens($item['key'], $webform_submission);
          $value = $this->replaceTokens($item['value'], $webform_submission);
          // Only add non-empty keys with defined values.
          if (!empty($key) && $value !== '') {
            $metadata[$key] = $value;
          }
        }
      }
    }
    return $metadata;
  }

  /**
   * Process the email field from configuration.
   *
   * @param array $configuration
   *   The handler configuration.
   * @param \Drupal\webform\WebformSubmissionInterface $webform_submission
   *   The webform submission.
   *
   * @return string|null
   *   The processed email or NULL if not available.
   */
  protected function processEmailField(array $configuration, WebformSubmissionInterface $webform_submission) {
    // Process email field with token replacement.
    $email = NULL;
    if (!empty($configuration['fields']['email'])) {
      $email = $this->replaceTokens($configuration['fields']['email'], $webform_submission, TRUE);
    }
    return $email;
  }

  /**
   * Process cardholder details from configuration.
   *
   * @param array $configuration
   *   The handler configuration.
   * @param \Drupal\webform\WebformSubmissionInterface $webform_submission
   *   The webform submission.
   *
   * @return array|null
   *   The processed cardholder details or NULL if not available.
   */
  protected function processCardholderDetails(array $configuration, WebformSubmissionInterface $webform_submission) {
    // Process prefilled cardholder details.
    $prefilled_cardholder_details = NULL;
    $cardholder_name = NULL;
    $billing_address = NULL;

    // Check if name field is configured and has a value.
    if (!empty($configuration['fields']['name'])) {
      $cardholder_name = $this->replaceTokens($configuration['fields']['name'], $webform_submission, TRUE);
    }

    // Check if address fields are configured and have values.
    $line1 = !empty($configuration['fields']['address']['line1'])
      ? $this->replaceTokens($configuration['fields']['address']['line1'], $webform_submission, TRUE)
      : NULL;
    $line2 = !empty($configuration['fields']['address']['line2'])
      ? $this->replaceTokens($configuration['fields']['address']['line2'], $webform_submission, TRUE)
      : NULL;
    $postcode = !empty($configuration['fields']['address']['postcode'])
      ? $this->replaceTokens($configuration['fields']['address']['postcode'], $webform_submission, TRUE)
      : NULL;
    $city = !empty($configuration['fields']['address']['city'])
      ? $this->replaceTokens($configuration['fields']['address']['city'], $webform_submission, TRUE)
      : NULL;
    $country = !empty($configuration['fields']['address']['country'])
      ? $this->replaceTokens($configuration['fields']['address']['country'], $webform_submission, TRUE)
      : 'GB';

    // Only create billing address if at least line1 is provided.
    if (!empty($line1)) {
      $billing_address = [
        'line1' => $line1,
      ];

      // Add optional address fields if they have values.
      if (!empty($line2)) {
        $billing_address['line2'] = $line2;
      }
      if (!empty($postcode)) {
        $billing_address['postcode'] = $postcode;
      }
      if (!empty($city)) {
        $billing_address['city'] = $city;
      }
      if (!empty($country)) {
        $billing_address['country'] = $country;
      }
    }

    // Create prefilled_cardholder_details if we have either name or address.
    if (!empty($cardholder_name) || !empty($billing_address)) {
      $prefilled_cardholder_details = [];

      if (!empty($cardholder_name)) {
        $prefilled_cardholder_details['cardholder_name'] = $cardholder_name;
      }

      if (!empty($billing_address)) {
        $prefilled_cardholder_details['billing_address'] = $billing_address;
      }
    }

    return $prefilled_cardholder_details;
  }

  /**
   * Handle the redirect to GOV.UK Pay.
   *
   * @param \Swagger\Client\Model\CreatePaymentResult $payment_response
   *   The payment response from the GOV.UK Pay API.
   *
   * @return bool
   *   TRUE if redirect URL was stored for later processing, FALSE otherwise.
   */
  protected function handlePaymentRedirect($payment_response) {
    $links = $payment_response->getLinks();
    $nextUrl = $links->getNextUrl();

    if (!is_null($nextUrl)) {
      $request = $this->requestStack->getCurrentRequest();

      // Ensure a session is initialised for anonymous users.
      try {
        if ($request->hasSession()) {
          $request->getSession()->save();
        }
      }
      catch (\Exception $e) {
        // Log the session error but continue with the redirect.
        $this->logger->warning('Session error during payment redirect: @message', ['@message' => $e->getMessage()]);
      }

      // Store the redirect URL in the tempStore
      // for later processing by the EventSubscriber.
      $this->tempStore->set('redirect_url', $nextUrl->getHref());
      return TRUE;
    }

    return FALSE;
  }

  /**
   * Calculate payment amount based on configuration.
   *
   * @param \Drupal\webform\WebformSubmissionInterface $webform_submission
   *   The webform submission.
   * @param array $configuration
   *   The handler configuration.
   *
   * @return int
   *   The payment amount in pence.
   *
   * @throws \RuntimeException
   *   Throws exception if payment amount cannot be determined.
   */
  public function calculateAmount(WebformSubmissionInterface $webform_submission, array $configuration) {
    $amount = 0;

    // Get the amount from the configuration.
    $amount_value = $configuration['fields']['amount'] ?? '';

    if (!empty($amount_value)) {
      // Use the replaceTokens method with plain_text=TRUE.
      // to avoid HTML encoding.
      $processed_amount = $this->replaceTokens(
        $amount_value,
        $webform_submission,
        TRUE
      );

      if (is_numeric($processed_amount)) {
        // Convert to pence (multiply by 100 and ensure integer).
        $amount = (int) (floatval($processed_amount) * 100);
      }
    }

    // If we still don't have a valid amount, throw an exception.
    if ($amount <= 0) {
      throw new \RuntimeException('Payment amount could not be determined. Please check your webform configuration.');
    }

    return $amount;
  }

  /**
   * Retrieve a payment record by UUID.
   *
   * @param string $uuid
   *   UUID of payment.
   * @param string $webform_id
   *   Optional Webform ID of payment.
   * @param string $submission_id
   *   Optional Submission ID of payment.
   *
   * @return \Drupal\govuk_pay\Entity\GovUkPayment|null
   *   Return loaded gov_payment entity or NULL if not found.
   */
  public function getPaymentByUuid($uuid, $webform_id, $submission_id) {
    // Initial Query.
    $query = $this->entityTypeManager->getStorage('govukpayment')->getQuery();

    $query->condition('uuid', $uuid);
    $query->condition('webform_id', $webform_id);
    $query->condition('submission_id', $submission_id);
    $query->accessCheck(FALSE);
    $result = $query->execute();

    if (!empty($result)) {
      $payments = GovUkPayment::loadMultiple($result);
      return reset($payments);
    }

    return NULL;
  }

  /**
   * Get payment details for the confirmation page.
   *
   * @param string $uuid
   *   UUID of the payment.
   * @param string $webform_id
   *   Webform ID.
   * @param string $submission_id
   *   Submission ID.
   *
   * @return array
   *   Payment details array with keys for
   *   payment_id,
   *   amount, status, and message.
   */
  public function getPaymentDetails($uuid, $webform_id, $submission_id) {
    $details = [
      'payment_id' => NULL,
      'amount' => NULL,
      'status' => NULL,
      'message' => NULL,
      'payment_for' => NULL,
      'payment_reference' => NULL,
    ];

    $payment = $this->getPaymentByUuid($uuid, $webform_id, $submission_id);
    if ($payment) {
      $payment_id = $payment->get('payment_id')->getValue()[0]['value'];
      $details['payment_id'] = $payment_id;

      $api_record = $this->apiService->getPayment($payment_id);

      $state = $api_record->getState();
      $details['status'] = $state ? $state->getStatus() : 'Status not found.';
      $details['message'] = $state ? $state->getMessage() : '';

      if ($api_record->getAmount()) {
        $details['amount'] = '£' . number_format(floatval($api_record->getAmount()) / 100, 2);
      }
      else {
        $details['amount'] = 'Payment not found';
      }

      // Get payment_for and payment_reference from the entity if available.
      if ($payment->hasField('payment_for') && !$payment->get('payment_for')->isEmpty()) {
        $details['payment_for'] = $payment->get('payment_for')->getValue()[0]['value'];
      }

      if ($payment->hasField('payment_reference') && !$payment->get('payment_reference')->isEmpty()) {
        $details['payment_reference'] = $payment->get('payment_reference')->getValue()[0]['value'];
      }
      // If payment_reference is still empty, use the global reference from
      // settings as fallback.
      elseif (empty($details['payment_reference'])) {
        $config = $this->configFactory->get('govuk_pay.settings');
        $details['payment_reference'] = $config->get('gov_pay__reference');
      }
    }

    return $details;
  }

  /**
   * Store payment data in the session.
   *
   * @param array $payment_data
   *   The payment data to store.
   */
  public function setPaymentData(array $payment_data) {
    try {
      // Store data in the private temp store with
      // a 1-hour expiration (default).
      $this->tempStore->set('payment_data', $payment_data);
    }
    catch (\Exception $e) {
      $this->logger->error(
        'Error storing payment data in session: @error',
        ['@error' => $e->getMessage()]
      );
    }
  }

  /**
   * Retrieve payment data from the session.
   *
   * @return array|null
   *   The payment data array, or NULL if not found.
   */
  public function getPaymentData() {
    try {
      $data = $this->tempStore->get('payment_data');
      if (empty($data)) {
        $this->logger->notice('No payment data found in session.');
        return NULL;
      }
      return $data;
    }
    catch (\Exception $e) {
      $this->logger->error(
        'Error retrieving payment data from session: @error',
        ['@error' => $e->getMessage()]
      );
      return NULL;
    }
  }

  /**
   * Clear payment data from the session.
   */
  public function clearPaymentData() {
    try {
      $this->tempStore->delete('payment_data');
    }
    catch (\Exception $e) {
      $this->logger->error(
        'Error clearing payment data from session: @error',
        ['@error' => $e->getMessage()]
      );
    }
  }

  /**
   * Replace tokens in text with webform submission values.
   *
   * @param string $text
   *   The text to process.
   * @param \Drupal\webform\WebformSubmissionInterface $webform_submission
   *   A webform submission.
   * @param bool $plain_text
   *   Whether to return plain text (TRUE) or HTML (FALSE).
   *
   * @return string
   *   Text with tokens replaced.
   */
  protected function replaceTokens($text, WebformSubmissionInterface $webform_submission, $plain_text = FALSE) {
    if (empty($text) || strpos($text, '[') === FALSE) {
      return $text;
    }

    $token_data = [
      'webform' => $webform_submission->getWebform(),
      'webform_submission' => $webform_submission,
    ];

    // Use replacePlain for plain text output (no HTML encoding).
    if ($plain_text) {
      return $this->token->replacePlain($text, $token_data);
    }

    // Use replace for HTML output (with HTML encoding).
    return $this->token->replace($text, $token_data);
  }

  /**
   * Create a payment entity from a payment response.
   *
   * @param \Swagger\Client\Model\CreatePaymentResult $payment_response
   *   The payment response from the GOV.UK Pay API.
   * @param string $uuid
   *   The UUID for the payment.
   * @param int $amount
   *   The payment amount in pence.
   * @param string $webform_id
   *   The webform ID.
   * @param string $submission_id
   *   The submission ID.
   * @param string $payment_for
   *   The payment description.
   * @param string $payment_reference
   *   The payment reference.
   *
   * @return \Drupal\govuk_pay\Entity\GovUkPayment
   *   The created payment entity.
   */
  public function createPaymentEntity(
    $payment_response,
    $uuid,
    $amount,
    $webform_id,
    $submission_id,
    $payment_for,
    $payment_reference,
  ) {
    $payment = GovUkPayment::create([
      'payment_id' => $payment_response->getPaymentId(),
      'amount' => $amount,
      'uuid' => $uuid,
      'status' => $payment_response->getState()->getStatus(),
      'webform_id' => $webform_id,
      'submission_id' => $submission_id,
      'payment_for' => $payment_for,
      'payment_reference' => $payment_reference,
    ]);

    // Set up the initial revision.
    $payment->setNewRevision(TRUE);
    $payment->setRevisionCreationTime(time());
    $payment->setRevisionLogMessage('Payment created with initial status: ' . $payment_response->getState()->getStatus());

    // Set the revision owner to the current user if available.
    if ($this->currentUser) {
      $payment->setRevisionUserId($this->currentUser->id());
    }

    $payment->save();
    return $payment;
  }

}
