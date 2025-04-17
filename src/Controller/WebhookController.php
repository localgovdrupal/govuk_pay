<?php

namespace Drupal\govuk_pay\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\govuk_pay\ApiServiceInterface;
use Drupal\govuk_pay\PaymentEventService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Controller for handling GOV.UK Pay webhooks.
 */
class WebhookController extends ControllerBase {

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
   * The API service.
   *
   * @var \Drupal\govuk_pay\ApiServiceInterface
   */
  protected $apiService;

  /**
   * The payment event service.
   *
   * @var \Drupal\govuk_pay\PaymentEventService
   */
  protected $paymentEventService;

  /**
   * Constructs a new WebhookController object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Drupal\govuk_pay\ApiServiceInterface $api_service
   *   The API service.
   * @param \Drupal\govuk_pay\PaymentEventService $payment_event_service
   *   The payment event service.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    LoggerChannelFactoryInterface $logger_factory,
    ApiServiceInterface $api_service,
    PaymentEventService $payment_event_service
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->loggerFactory = $logger_factory;
    $this->logger = $logger_factory->get('govuk_pay');
    $this->apiService = $api_service;
    $this->paymentEventService = $payment_event_service;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('logger.factory'),
      $container->get('govuk_pay.api_service'),
      $container->get('govuk_pay.payment_event_service')
    );
  }

  /**
   * Handles the webhook notification from GOV.UK Pay.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The response object.
   */
  public function handleWebhook(Request $request) {
    try {
      // Get the request content.
      $content = $request->getContent();
      $data = json_decode($content, TRUE);

      // Validate the webhook data.
      if (!$this->validateWebhookData($data)) {
        $this->logger->error('Invalid webhook data received: @data', ['@data' => $content]);
        return new Response('Invalid webhook data', 400);
      }

      // Log the webhook data.
      $this->logger->info('Received webhook: @data', ['@data' => $content]);

      // Process the payment update.
      $this->processPaymentUpdate($data);

      // Return a success response.
      return new Response('Webhook processed successfully', 200);
    }
    catch (\Exception $e) {
      $this->logger->error('Error processing webhook: @message', ['@message' => $e->getMessage()]);
      return new Response('Error processing webhook', 500);
    }
  }

  /**
   * Validates the webhook data.
   *
   * @param array|null $data
   *   The webhook data.
   *
   * @return bool
   *   TRUE if the data is valid, FALSE otherwise.
   */
  protected function validateWebhookData($data) {
    // Check if the data is valid JSON.
    if ($data === NULL) {
      return FALSE;
    }

    // Check if the required fields are present.
    if (empty($data['resource_type']) || $data['resource_type'] !== 'payment') {
      return FALSE;
    }

    if (empty($data['resource_id'])) {
      return FALSE;
    }

    return TRUE;
  }

  /**
   * Processes the payment update.
   *
   * @param array $data
   *   The webhook data.
   */
  protected function processPaymentUpdate(array $data) {
    // Get the payment ID from the webhook data.
    $payment_id = $data['resource_id'];

    // Parse the webhook timestamp.
    $webhook_created_date = $data['created_date'];
    $webhook_timestamp = strtotime($webhook_created_date);
    $new_status = $data['resource']['state']['status'];
    $event_type = $data['event_type'];

    // Use the payment event service to record the event and update the payment.
    $this->paymentEventService->recordPaymentEvent(
      $payment_id,
      $new_status,
      $event_type,
      'webhook',
      $data,
      $webhook_timestamp
    );
  }

}
