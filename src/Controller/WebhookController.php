<?php

namespace Drupal\govuk_pay\Controller;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\govuk_pay\ApiService;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Controller\ControllerBase;

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
   * The GOV.UK Pay API service.
   *
   * @var \Drupal\govuk_pay\ApiService
   */
  protected $apiService;

  /**
   * Constructs a new WebhookController.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Drupal\govuk_pay\ApiService $api_service
   *   The GOV.UK Pay API service.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    LoggerChannelFactoryInterface $logger_factory,
    ApiService $api_service,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->logger = $logger_factory->get('govuk_pay');
    $this->apiService = $api_service;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('logger.factory'),
      $container->get('govuk_pay.api_service'),
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

    // Find the payment entity with this payment ID.
    $payment_storage = $this->entityTypeManager->getStorage('govukpayment');
    $query = $payment_storage->getQuery()
      ->condition('payment_id', $payment_id)
      ->accessCheck(FALSE);
    $entity_ids = $query->execute();

    if (empty($entity_ids)) {
      $this->logger->warning('Payment not found for ID: @id', ['@id' => $payment_id]);
      return;
    }

    // Load the payment entity.
    $payment_entity = $payment_storage->load(reset($entity_ids));
    if (!$payment_entity) {
      $this->logger->warning('Failed to load payment entity for ID: @id', ['@id' => $payment_id]);
      return;
    }

    // Update the payment status.
    try {
      // Get the current status from the GOV.UK Pay API.
      $payment_details = $this->apiService->getPayment($payment_id);

      // Update the payment entity with the new status.
      $payment_entity->status->value = $payment_details->getState()->getStatus();
      $payment_entity->save();

      $this->logger->info('Updated payment @id status to @status', [
        '@id' => $payment_id,
        '@status' => $payment_details->getState()->getStatus(),
      ]);
    }
    catch (\Exception $e) {
      $this->logger->error('Error updating payment status: @message', ['@message' => $e->getMessage()]);
    }
  }

}
