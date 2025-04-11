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
use Drupal\Core\Routing\TrustedRedirectResponse;
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
    // Load config for GOV.UK Pay.
    $config = $this->configFactory->get('govuk_pay.settings');

    // Validate required parameters.
    if (empty($config->get('gov_pay__apikey'))) {
      throw new \RuntimeException('GOV.UK Pay API key is not configured. This is a required field on /admin/config/govuk_pay/settings.');
    }

    $sid = $webform_submission->id();
    $webform = $webform_submission->getWebform();
    $amount = $this->calculateAmount($webform_submission, $configuration);

    if (empty($amount)) {
      throw new \RuntimeException('Payment amount could not be determined.');
    }

    // Process the payment description with token replacement.
    $payment_for = $this->replaceTokens($configuration['payment_for'] ?? '', $webform_submission);
    if (empty($payment_for)) {
      $payment_for = $webform->label();
    }

    // Ensure the description doesn't exceed GOV.UK Pay's limit.
    if (strlen($payment_for) > 254) {
      $payment_for = substr($payment_for, 0, 251) . '...';
    }

    $uuid = $this->uuidService->generate();

    // Create the payment data array to be stored in the session.
    $payment_data = [
      'uuid' => $uuid,
      'webform_id' => $webform->id(),
      'submission_id' => $sid,
    ];

    // Store payment data in the session.
    $this->setPaymentData($payment_data);

    // Create the return URL without parameters.
    $url_object = Url::fromRoute('govuk_pay_webform.confirmation_page', [], ['absolute' => TRUE]);
    $returnUrl = new Uri($url_object->toString());

    // Process payment reference with token replacement.
    $payment_reference = $this->replaceTokens($configuration['payment_reference'] ?? '', $webform_submission);

    // If payment_reference is empty, use the global reference
    // from settings as fallback.
    if (empty($payment_reference)) {
      if (empty($config->get('gov_pay__reference'))) {
        throw new \RuntimeException('GOV.UK Pay reference is not configured. This is a required field on /admin/config/govuk_pay/settings.');
      }
      $payment_reference = $config->get('gov_pay__reference');
    }

    // Process metadata from configuration.
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

    $payment_response = $this->apiService->createPayment(
      $amount,
      $payment_reference,
      $payment_for,
      $returnUrl,
      $metadata
    );

    // Create the payment entity and get the next URL from the response.
    $this->createPaymentEntity(
      $payment_response,
      $uuid,
      $amount,
      $webform->id(),
      $sid,
      $payment_for,
      $payment_reference,
    );

    $links = $payment_response->getLinks();
    $nextUrl = $links['next_url']->getHref() ?? NULL;

    if (!is_null($nextUrl)) {
      $response = new TrustedRedirectResponse($nextUrl, 302);
      $request = $this->requestStack->getCurrentRequest();
      // Ensure a session is initialised for anonymous users.
      $request->getSession()->save();
      $response->prepare($request);
      $response->send();
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
   */
  public function calculateAmount(WebformSubmissionInterface $webform_submission, array $configuration) {
    $amount = 0;

    switch ($configuration['amount_provider']) {
      case 'element':
        $element_name = $configuration['amount_element'];
        if (!empty($element_name)) {
          $value = $webform_submission->getElementData($element_name);
          if (is_numeric($value)) {
            // Convert to pence (multiply by 100 and ensure integer).
            $amount = (int) (floatval($value) * 100);
          }
        }
        break;

      case 'static':
        if (!empty($configuration['amount_static']) && is_numeric($configuration['amount_static'])) {
          // Convert to pence (multiply by 100 and ensure integer).
          $amount = (int) (floatval($configuration['amount_static']) * 100);
        }
        break;
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
   *
   * @return string
   *   Text with tokens replaced.
   */
  protected function replaceTokens($text, WebformSubmissionInterface $webform_submission) {
    if (empty($text) || strpos($text, '[') === FALSE) {
      return $text;
    }

    $token_data = [
      'webform' => $webform_submission->getWebform(),
      'webform_submission' => $webform_submission,
    ];

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
