<?php

namespace Drupal\govuk_pay_webform;

use Symfony\Component\HttpFoundation\RequestStack;
use GuzzleHttp\Psr7\Uri;
use Drupal\webform\WebformSubmissionInterface;
use Drupal\govuk_pay\Entity\GovUkPayment;
use Drupal\govuk_pay\ApiService;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Url;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;

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
   * The GOV.UK Pay API service.
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
   * The logger service.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Constructs a new GovUkPayWebformService.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\govuk_pay\ApiService $api_service
   *   The GOV.UK Pay API service.
   * @param \Drupal\Component\Uuid\UuidInterface $uuid_service
   *   The UUID service.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    ApiService $api_service,
    UuidInterface $uuid_service,
    RequestStack $request_stack,
    EntityTypeManagerInterface $entity_type_manager,
    LoggerChannelFactoryInterface $logger_factory,
  ) {
    $this->configFactory = $config_factory;
    $this->apiService = $api_service;
    $this->uuidService = $uuid_service;
    $this->requestStack = $request_stack;
    $this->entityTypeManager = $entity_type_manager;
    $this->logger = $logger_factory->get('govuk_pay_webform');
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
    if (empty($config->get('gov_pay__reference'))) {
      throw new \RuntimeException('GOV.UK Pay reference is not configured. This is a required field on /admin/config/govuk_pay/settings.');
    }
    if (empty($config->get('gov_pay__apikey'))) {
      throw new \RuntimeException('GOV.UK Pay API key is not configured. This is a required field on /admin/config/govuk_pay/settings.');
    }

    $sid = $webform_submission->id();
    $webform = $webform_submission->getWebform();
    $amount = $this->calculateAmount($webform_submission, $configuration);

    if (empty($amount)) {
      throw new \RuntimeException('Payment amount could not be determined.');
    }

    $message = $configuration['payment_message'] ?? $webform->label();
    if (strlen($message) > 254) {
      $message = substr($message, 0, 251) . '...';
    }

    $uuid = $this->uuidService->generate();
    $route_params = [
      'uuid' => $uuid,
      'webform_id' => $webform->id(),
      'submission_id' => $sid,
    ];

    $url_object = Url::fromRoute('govuk_pay_webform.confirmation_page', $route_params, ['absolute' => TRUE]);
    $returnUrl = new Uri($url_object->toString());

    $payment_response = $this->apiService->createPayment(
      $amount,
      $config->get('gov_pay__reference'),
      $message,
      $returnUrl
    );

    $payment = GovUkPayment::create([
      'payment_id' => $payment_response->getPaymentId(),
      'amount' => $amount,
      'uuid' => $uuid,
      'status' => $payment_response->getState()->getStatus(),
      'webform_id' => $webform->id(),
      'submission_id' => $sid,
    ]);
    $payment->save();

    $links = $payment_response->getLinks();
    $nextUrl = $links['next_url']->getHref() ?? NULL;

    if (!is_null($nextUrl)) {
      $response = new TrustedRedirectResponse($nextUrl, 302);
      $request = $this->requestStack->getCurrentRequest();
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
   *   Payment details array with keys for payment_id, amount, status, and message.
   */
  public function getPaymentDetails($uuid, $webform_id, $submission_id) {
    $details = [
      'payment_id' => NULL,
      'amount' => NULL,
      'status' => NULL,
      'message' => NULL,
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
    }

    return $details;
  }

}
