<?php

namespace Drupal\Tests\govuk_pay\Kernel;

use Drupal\govuk_pay\Entity\GovUkPayment;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Core\Datetime\DateFormatterInterface;

/**
 * Tests the PaymentEventService.
 *
 * @group govuk_pay
 */
class PaymentEventServiceTest extends KernelTestBase {

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
   * The payment event service.
   *
   * @var \Drupal\govuk_pay\PaymentEventService
   */
  protected $paymentEventService;

  /**
   * The test payment entity.
   *
   * @var \Drupal\govuk_pay\Entity\GovUkPayment
   */
  protected $payment;

  /**
   * An array of test events.
   *
   * @var \Drupal\govuk_pay\Entity\GovUkPaymentEvent[]
   */
  protected $events = [];

  /**
   * The date formatter service.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected $dateFormatter;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('govukpayment');
    $this->installEntitySchema('govukpayment_event');
    $this->installConfig(['govuk_pay']);

    $this->entityTypeManager = $this->container->get('entity_type.manager');

    // Create a simple date formatter implementation if needed.
    if (!$this->container->has('date.formatter')) {
      $this->dateFormatter = new TestDateFormatter();
      $this->container->set('date.formatter', $this->dateFormatter);
    }

    $this->paymentEventService = $this->container->get('govuk_pay.payment_event_service');

    // Create a test payment entity.
    $this->payment = GovUkPayment::create([
      'payment_id' => 'test-payment-' . time(),
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
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    // Clean up test events.
    foreach ($this->events as $event) {
      if ($event->id()) {
        $event->delete();
      }
    }

    // Clean up test payment.
    if ($this->payment && $this->payment->id()) {
      $this->payment->delete();
    }

    parent::tearDown();
  }

  /**
   * Tests that parent payment entity status is updated with event status.
   */
  public function testPaymentStatusUpdate() {
    // Record a payment event with a different status than the payment.
    $event_data = [
      'payment_id' => $this->payment->payment_id->value,
      'status' => 'success',
      'amount' => 5000,
      'description' => 'Test Payment',
      'reference' => 'REF123',
    ];

    // Record the event using the service.
    $result = $this->paymentEventService->recordPaymentEvent(
      $this->payment->payment_id->value,
      'success',
      'payment.status_updated',
      'webhook',
      $event_data
    );

    $this->assertTrue($result, 'Payment event was recorded successfully.');

    // Clear static cache to ensure we're loading from storage.
    $this->entityTypeManager->getStorage('govukpayment')->resetCache([$this->payment->id()]);

    // Reload the payment entity.
    $updated_payment = $this->entityTypeManager->getStorage('govukpayment')->load($this->payment->id());

    // Verify the payment status has been updated to match the event status.
    $this->assertEquals('success', $updated_payment->status->value, 'Payment status was updated to match the event status.');

    // Record another event with a different status.
    $event_data2 = [
      'payment_id' => $this->payment->payment_id->value,
      'status' => 'cancelled',
    ];

    // Use a timestamp one minute in the future to ensure it's newer.
    $future_timestamp = time() + 60;

    // Record the second event using the service.
    $result2 = $this->paymentEventService->recordPaymentEvent(
      $this->payment->payment_id->value,
      'cancelled',
      'payment.status_updated',
      'api',
      $event_data2,
      $future_timestamp
    );

    $this->assertTrue($result2, 'Second payment event was recorded successfully.');

    // Clear static cache again.
    $this->entityTypeManager->getStorage('govukpayment')->resetCache([$this->payment->id()]);

    // Reload the payment entity.
    $updated_payment = $this->entityTypeManager->getStorage('govukpayment')->load($this->payment->id());

    // Verify the payment status has been updated to match the new event status.
    $this->assertEquals('cancelled', $updated_payment->status->value, 'Payment status was updated to match the new event status.');

    // Verify that only the latest event is marked as current state.
    $event_storage = $this->entityTypeManager->getStorage('govukpayment_event');
    $query = $event_storage->getQuery()
      ->condition('payment_id', $this->payment->payment_id->value)
      ->condition('is_current_state', TRUE)
      ->accessCheck(FALSE);
    $current_event_ids = $query->execute();

    $this->assertCount(1, $current_event_ids, 'Only one event is marked as current state.');

    $current_event = $event_storage->load(reset($current_event_ids));
    $this->assertEquals('cancelled', $current_event->status->value, 'Current event has the expected status.');
    $this->assertEquals($future_timestamp, $current_event->event_timestamp->value, 'Current event has the expected timestamp.');
  }

  /**
   * Tests that out-of-order events are handled correctly.
   */
  public function testOutOfOrderEvents() {
    // Create a current timestamp.
    $current_time = time();

    // Record an event with the current timestamp.
    $result1 = $this->paymentEventService->recordPaymentEvent(
      $this->payment->payment_id->value,
      'success',
      'payment.status_updated',
      'webhook',
      ['status' => 'success'],
      $current_time
    );

    $this->assertTrue($result1, 'First payment event was recorded successfully.');

    // Reload the payment entity.
    $this->entityTypeManager->getStorage('govukpayment')->resetCache([$this->payment->id()]);
    $updated_payment = $this->entityTypeManager->getStorage('govukpayment')->load($this->payment->id());
    $this->assertEquals('success', $updated_payment->status->value, 'Payment status was updated to success.');

    // Record an event with an older timestamp (should not update payment status).
    $older_time = $current_time - 60;
    // 1 minute earlier.
    $result2 = $this->paymentEventService->recordPaymentEvent(
      $this->payment->payment_id->value,
      'submitted',
      'payment.status_updated',
      'api',
      ['status' => 'submitted'],
      $older_time
    );

    // Record an event with an older timestamp (should not update payment status).
    $this->assertFalse($result2, 'Out-of-order event was recorded but did not update payment status.');

    // Reload the payment entity.
    $this->entityTypeManager->getStorage('govukpayment')->resetCache([$this->payment->id()]);
    $updated_payment = $this->entityTypeManager->getStorage('govukpayment')->load($this->payment->id());

    // Verify the payment status has NOT been updated to the older event's status.
    $this->assertEquals('success', $updated_payment->status->value, 'Payment status was not changed by older event.');

    // Verify that the current state flag is still on the newer event.
    $event_storage = $this->entityTypeManager->getStorage('govukpayment_event');
    $query = $event_storage->getQuery()
      ->condition('payment_id', $this->payment->payment_id->value)
      ->condition('is_current_state', TRUE)
      ->accessCheck(FALSE);
    $current_event_ids = $query->execute();

    $this->assertCount(1, $current_event_ids, 'Only one event is marked as current state.');

    $current_event = $event_storage->load(reset($current_event_ids));
    $this->assertEquals('success', $current_event->status->value, 'Current event still has the newer status.');
    $this->assertEquals($current_time, $current_event->event_timestamp->value, 'Current event has the newer timestamp.');
  }

}

/**
 * Simple date formatter implementation for testing.
 */
class TestDateFormatter implements DateFormatterInterface {

  /**
   * {@inheritdoc}
   */
  public function format($timestamp, $type = 'medium', $format = '', $timezone = NULL, $langcode = NULL) {
    if ($type === 'custom' && $format === 'Y-m-d\TH:i:s\Z') {
      return date('Y-m-d\TH:i:s\Z', $timestamp);
    }
    return date('Y-m-d H:i:s', $timestamp);
  }

  /**
   * {@inheritdoc}
   */
  public function formatInterval($interval, $granularity = 2, $langcode = NULL) {
    return '';
  }

  /**
   * {@inheritdoc}
   */
  public function formatTimeDiffUntil($timestamp, $options = []) {
    return '';
  }

  /**
   * {@inheritdoc}
   */
  public function formatTimeDiffSince($timestamp, $options = []) {
    return '';
  }

  /**
   * {@inheritdoc}
   */
  public function getSampleDateFormats($langcode = NULL, $timestamp = NULL, $timezone = NULL) {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function formatDiff($from, $to, $options = []) {
    return '';
  }

}
