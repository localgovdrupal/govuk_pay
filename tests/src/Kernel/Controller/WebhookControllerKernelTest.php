<?php

namespace Drupal\Tests\govuk_pay\Kernel\Controller;

use Symfony\Component\HttpFoundation\Request;
use Drupal\govuk_pay\Entity\GovUkPayment;
use Drupal\govuk_pay\Controller\WebhookController;
use Drupal\KernelTests\KernelTestBase;

/**
 * Tests the WebhookController with real entities and database operations.
 *
 * @group govuk_pay
 */
class WebhookControllerKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'govuk_pay',
  ];

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The webhook controller.
   *
   * @var \Drupal\govuk_pay\Controller\WebhookController
   */
  protected $webhookController;

  /**
   * The test payment entity.
   *
   * @var \Drupal\govuk_pay\Entity\GovUkPayment
   */
  protected $payment;

  /**
   * The payment ID for testing.
   *
   * @var string
   */
  protected $paymentId;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Install necessary schemas and config.
    $this->installEntitySchema('user');
    $this->installEntitySchema('govukpayment');
    $this->installEntitySchema('govukpayment_event');
    $this->installConfig(['govuk_pay']);

    // Get services.
    $this->entityTypeManager = $this->container->get('entity_type.manager');

    // Manually instantiate the webhook controller since it's not a registered
    // service.
    $loggerFactory = $this->container->get('logger.factory');
    $apiService = $this->container->get('govuk_pay.api_service');
    $paymentEventService = $this->container->get('govuk_pay.payment_event_service');

    $this->webhookController = new WebhookController(
      $this->entityTypeManager,
      $loggerFactory,
      $apiService,
      $paymentEventService
    );

    // Set up a test webhook signing secret.
    $this->container->get('config.factory')
      ->getEditable('govuk_pay.settings')
      ->set('gov_pay__webhook_signing_secret', 'test-signing-secret')
      ->save();

    // Create a unique payment ID for this test.
    $this->paymentId = 'test-payment-' . time();

    // Create a test payment entity.
    $this->payment = GovUkPayment::create([
      'payment_id' => $this->paymentId,
      'status' => 'created',
      'amount' => 5000,
      'description' => 'Test Payment',
      'reference' => 'REF123',
      'return_url' => 'https://example.com/return',
      'next_url' => 'https://example.com/next',
      'payment_for' => 'Test Service Payment',
      'payment_reference' => 'TEST-REF-123',
    ]);
    $this->payment->save();
  }

  /**
   * Tests that webhook requests create payment events and update status.
   */
  public function testWebhookProcessing() {
    // Create a webhook payload for a "submitted" status update.
    $submittedPayload = $this->createWebhookPayload('submitted', time());
    $submittedRequest = $this->createWebhookRequest($submittedPayload);

    // Send the webhook request.
    $response = $this->webhookController->handleWebhook($submittedRequest);
    $this->assertEquals(200, $response->getStatusCode(), 'Webhook request should return 200 OK');

    // Verify that a payment event was created.
    $eventStorage = $this->entityTypeManager->getStorage('govukpayment_event');
    $query = $eventStorage->getQuery()
      ->condition('payment_id', $this->paymentId)
      ->condition('status', 'submitted')
      ->accessCheck(FALSE);
    $eventIds = $query->execute();
    $this->assertCount(1, $eventIds, 'One payment event should be created');

    // Verify that the payment status was updated.
    $this->entityTypeManager->getStorage('govukpayment')->resetCache([$this->payment->id()]);
    $updatedPayment = $this->entityTypeManager->getStorage('govukpayment')->load($this->payment->id());
    $this->assertEquals('submitted', $updatedPayment->status->value, 'Payment status should be updated to submitted');

    // Create a webhook payload for a "success" status update.
    $successPayload = $this->createWebhookPayload('success', time() + 60);
    $successRequest = $this->createWebhookRequest($successPayload);

    // Send the webhook request.
    $response = $this->webhookController->handleWebhook($successRequest);
    $this->assertEquals(200, $response->getStatusCode(), 'Webhook request should return 200 OK');

    // Verify that another payment event was created.
    $query = $eventStorage->getQuery()
      ->condition('payment_id', $this->paymentId)
      ->condition('status', 'success')
      ->accessCheck(FALSE);
    $eventIds = $query->execute();
    $this->assertCount(1, $eventIds, 'One success payment event should be created');

    // Verify that the payment status was updated.
    $this->entityTypeManager->getStorage('govukpayment')->resetCache([$this->payment->id()]);
    $updatedPayment = $this->entityTypeManager->getStorage('govukpayment')->load($this->payment->id());
    $this->assertEquals('success', $updatedPayment->status->value, 'Payment status should be updated to success');

    // Test out-of-order event processing.
    // Create a webhook payload for an older "created" status update.
    $createdPayload = $this->createWebhookPayload('created', time() - 60);
    $createdRequest = $this->createWebhookRequest($createdPayload);

    // Send the webhook request.
    $response = $this->webhookController->handleWebhook($createdRequest);
    $this->assertEquals(200, $response->getStatusCode(), 'Webhook request should return 200 OK');

    // Verify that another payment event was created.
    $query = $eventStorage->getQuery()
      ->condition('payment_id', $this->paymentId)
      ->condition('status', 'created')
      ->accessCheck(FALSE);
    $eventIds = $query->execute();
    $this->assertCount(1, $eventIds, 'One created payment event should be created');

    // Verify that the payment status was NOT
    // updated (since this is an older event).
    $this->entityTypeManager->getStorage('govukpayment')->resetCache([$this->payment->id()]);
    $updatedPayment = $this->entityTypeManager->getStorage('govukpayment')->load($this->payment->id());
    $this->assertEquals('success', $updatedPayment->status->value, 'Payment status should still be success');

    // Verify that only the latest event is marked as current state.
    $query = $eventStorage->getQuery()
      ->condition('payment_id', $this->paymentId)
      ->condition('is_current_state', TRUE)
      ->accessCheck(FALSE);
    $currentEventIds = $query->execute();
    $this->assertCount(1, $currentEventIds, 'Only one event should be marked as current state');

    $currentEvent = $eventStorage->load(reset($currentEventIds));
    $this->assertEquals('success', $currentEvent->status->value, 'Current event should have success status');
  }

  /**
   * Tests webhook signature validation.
   */
  public function testWebhookSignatureValidation() {
    // Create a webhook payload.
    $payload = $this->createWebhookPayload('success', time());

    // Create a request with an invalid signature.
    $invalidRequest = $this->createWebhookRequest($payload, 'invalid-signature');

    // Send the webhook request with invalid signature.
    $response = $this->webhookController->handleWebhook($invalidRequest);
    $this->assertEquals(400, $response->getStatusCode(), 'Webhook with invalid signature should be rejected');

    // Verify that no payment event was created.
    $eventStorage = $this->entityTypeManager->getStorage('govukpayment_event');
    $query = $eventStorage->getQuery()
      ->condition('payment_id', $this->paymentId)
      ->accessCheck(FALSE);
    $eventIds = $query->execute();
    $this->assertCount(0, $eventIds, 'No payment events should be created for invalid signature');

    // Verify that the payment status was not updated.
    $this->entityTypeManager->getStorage('govukpayment')->resetCache([$this->payment->id()]);
    $updatedPayment = $this->entityTypeManager->getStorage('govukpayment')->load($this->payment->id());
    $this->assertEquals('created', $updatedPayment->status->value, 'Payment status should not be updated');
  }

  /**
   * Creates a webhook payload for testing.
   *
   * @param string $status
   *   The payment status.
   * @param int $timestamp
   *   The event timestamp.
   *
   * @return string
   *   The JSON webhook payload.
   */
  protected function createWebhookPayload($status, $timestamp) {
    $date = date('c', $timestamp);
    $payload = [
      'resource_type' => 'payment',
      'resource_id' => $this->paymentId,
      'created_date' => $date,
      'event_type' => 'payment.status_updated',
      'resource' => [
        'payment_id' => $this->paymentId,
        'amount' => 5000,
        'description' => 'Test Payment',
        'reference' => 'REF123',
        'state' => [
          'status' => $status,
          'finished' => $status === 'success',
        ],
      ],
    ];

    return json_encode($payload);
  }

  /**
   * Creates a webhook request with the given payload.
   *
   * @param string $payload
   *   The JSON webhook payload.
   * @param string|null $customSignature
   *   Optional custom signature to use instead of the calculated one.
   *
   * @return \Symfony\Component\HttpFoundation\Request
   *   The webhook request.
   */
  protected function createWebhookRequest($payload, $customSignature = NULL) {
    $request = Request::create(
      '/govuk-pay/webhook',
      'POST',
      [],
      [],
      [],
      ['CONTENT_TYPE' => 'application/json'],
      $payload
    );

    // Calculate the signature or use the provided custom signature.
    $signature = $customSignature ?? hash_hmac('sha256', $payload, 'test-signing-secret');

    // Add the Pay-Signature header.
    $request->headers->set('Pay-Signature', $signature);

    return $request;
  }

}
