<?php

namespace Drupal\Tests\govuk_pay\Kernel\Entity;

use Drupal\user\Entity\User;
use Drupal\govuk_pay\Entity\GovUkPaymentEvent;
use Drupal\govuk_pay\Entity\GovUkPayment;
use Drupal\KernelTests\KernelTestBase;

/**
 * Tests the GovUkPaymentEvent entity.
 *
 * @group govuk_pay
 */
class GovUkPaymentEventEntityTest extends KernelTestBase {

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
   * A test user.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $user;

  /**
   * A test payment entity.
   *
   * @var \Drupal\govuk_pay\Entity\GovUkPayment
   */
  protected $payment;

  /**
   * Payment events created during tests.
   *
   * @var array
   */
  protected $events = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('govukpayment');
    $this->installEntitySchema('govukpayment_event');
    $this->installSchema('user', ['users_data']);

    $this->entityTypeManager = $this->container->get('entity_type.manager');

    // Create a test user.
    $this->user = User::create([
      'name' => 'test_user',
      'mail' => 'test@example.com',
    ]);
    $this->user->save();

    // Create a test payment entity to associate with events.
    $this->payment = GovUkPayment::create([
      'payment_id' => 'pay_test_123456789',
      'status' => 'created',
      'amount' => 5000,
      'payment_for' => 'Test Payment',
      'payment_reference' => 'REF123',
      'uid' => $this->user->id(),
    ]);
    $this->payment->save();
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    // Delete any payment event entities created during tests.
    foreach ($this->events as $event) {
      if ($event && $event->id()) {
        $event->delete();
      }
    }

    // Delete the test payment.
    if ($this->payment && $this->payment->id()) {
      $this->payment->delete();
    }

    // Delete the test user.
    if ($this->user && $this->user->id()) {
      $this->user->delete();
    }

    parent::tearDown();
  }

  /**
   * Tests creating a payment event entity.
   */
  public function testCreatePaymentEvent() {
    // Create a payment event entity.
    $event = GovUkPaymentEvent::create([
      'payment_id' => $this->payment->payment_id->value,
      'govukpayment_id' => $this->payment->id(),
      'event_timestamp' => time(),
      'event_type' => 'payment.created',
      'status' => 'created',
      'source' => 'api',
      'data' => [
        'payment_id' => $this->payment->payment_id->value,
        'status' => 'created',
        'amount' => 5000,
        'description' => 'Test Payment',
        'reference' => 'REF123',
      ],
      'is_current_state' => TRUE,
    ]);

    $event->save();
    $this->events[] = $event;

    // Verify the event was saved correctly.
    $this->assertNotEmpty($event->id(), 'Payment event entity was saved with an ID.');
    $this->assertEquals($this->payment->payment_id->value, $event->payment_id->value, 'Payment ID was saved correctly.');
    $this->assertEquals($this->payment->id(), $event->govukpayment_id->target_id, 'Payment entity reference was saved correctly.');
    $this->assertEquals('payment.created', $event->event_type->value, 'Event type was saved correctly.');
    $this->assertEquals('created', $event->status->value, 'Status was saved correctly.');
    $this->assertEquals('api', $event->source->value, 'Source was saved correctly.');
    $this->assertEquals(1, $event->is_current_state->value, 'Current state flag was saved correctly.');

    // Verify the data field contains the expected values.
    $data = $event->get('data')->getValue();
    $this->assertNotEmpty($data, 'Data field has values.');
    $this->assertIsArray($data, 'Data field is an array.');
    $this->assertArrayHasKey(0, $data, 'Data field has first element.');

    // Unserialize the data value to get the actual stored data.
    $stored_data = reset($data);
    $this->assertIsArray($stored_data, 'Stored data is an array.');

    // Verify the stored data contains the expected values.
    $this->assertArrayHasKey('payment_id', $stored_data, 'Stored data contains payment_id.');
    $this->assertArrayHasKey('status', $stored_data, 'Stored data contains status.');
    $this->assertArrayHasKey('amount', $stored_data, 'Stored data contains amount.');
    $this->assertArrayHasKey('description', $stored_data, 'Stored data contains description.');
    $this->assertArrayHasKey('reference', $stored_data, 'Stored data contains reference.');

    // Verify the stored data values match what we expect.
    $this->assertEquals($this->payment->payment_id->value, $stored_data['payment_id'], 'Stored payment_id matches.');
    $this->assertEquals('created', $stored_data['status'], 'Stored status matches.');
    $this->assertEquals(5000, $stored_data['amount'], 'Stored amount matches.');
    $this->assertEquals('Test Payment', $stored_data['description'], 'Stored description matches.');
    $this->assertEquals('REF123', $stored_data['reference'], 'Stored reference matches.');
  }

  /**
   * Tests loading a payment event entity.
   */
  public function testLoadPaymentEvent() {
    // Create a payment event entity.
    $event = GovUkPaymentEvent::create([
      'payment_id' => $this->payment->payment_id->value,
      'govukpayment_id' => $this->payment->id(),
      'event_timestamp' => time(),
      'event_type' => 'payment.status_updated',
      'status' => 'success',
      'source' => 'webhook',
      'data' => [
        'payment_id' => $this->payment->payment_id->value,
        'status' => 'success',
        'amount' => 5000,
      ],
      'is_current_state' => TRUE,
    ]);

    $event->save();
    $event_id = $event->id();
    $this->events[] = $event;

    // Clear static cache to ensure we're loading from storage.
    $this->entityTypeManager->getStorage('govukpayment_event')->resetCache([$event_id]);

    // Load the event.
    $loaded_event = GovUkPaymentEvent::load($event_id);

    // Verify the loaded event.
    $this->assertNotNull($loaded_event, 'Payment event was loaded successfully.');
    $this->assertEquals($this->payment->payment_id->value, $loaded_event->payment_id->value, 'Payment ID was loaded correctly.');
    $this->assertEquals($this->payment->id(), $loaded_event->govukpayment_id->target_id, 'Payment entity reference was loaded correctly.');
    $this->assertEquals('payment.status_updated', $loaded_event->event_type->value, 'Event type was loaded correctly.');
    $this->assertEquals('success', $loaded_event->status->value, 'Status was loaded correctly.');
    $this->assertEquals('webhook', $loaded_event->source->value, 'Source was loaded correctly.');
    $this->assertEquals(1, $loaded_event->is_current_state->value, 'Current state flag was loaded correctly.');
  }

  /**
   * Tests updating a payment event entity.
   */
  public function testUpdatePaymentEvent() {
    // Create a payment event entity.
    $event = GovUkPaymentEvent::create([
      'payment_id' => $this->payment->payment_id->value,
      'govukpayment_id' => $this->payment->id(),
      'event_timestamp' => time(),
      'event_type' => 'payment.status_updated',
      'status' => 'submitted',
      'source' => 'redirect',
      'data' => [
        'payment_id' => $this->payment->payment_id->value,
        'status' => 'submitted',
      ],
      'is_current_state' => TRUE,
    ]);

    $event->save();
    $event_id = $event->id();
    $this->events[] = $event;

    // Update the event's current state flag.
    $event->is_current_state->value = FALSE;
    $event->save();

    // Clear static cache to ensure we're loading from storage.
    $this->entityTypeManager->getStorage('govukpayment_event')->resetCache([$event_id]);

    // Load the updated event.
    $updated_event = GovUkPaymentEvent::load($event_id);

    // Verify the update.
    $this->assertEquals(0, $updated_event->is_current_state->value, 'Current state flag was updated correctly.');
    $this->assertEquals('submitted', $updated_event->status->value, 'Status remained unchanged.');
    $this->assertEquals('redirect', $updated_event->source->value, 'Source remained unchanged.');
  }

  /**
   * Tests querying payment events by payment ID.
   */
  public function testQueryPaymentEvents() {
    // Create multiple events for the same payment.
    // 1 hour ago.
    $timestamp1 = time() - 3600;
    $event1 = GovUkPaymentEvent::create([
      'payment_id' => $this->payment->payment_id->value,
      'govukpayment_id' => $this->payment->id(),
      'event_timestamp' => $timestamp1,
      'event_type' => 'payment.created',
      'status' => 'created',
      'source' => 'api',
      'data' => ['status' => 'created'],
      'is_current_state' => FALSE,
    ]);
    $event1->save();
    $this->events[] = $event1;

    // 30 minutes ago.
    $timestamp2 = time() - 1800;
    $event2 = GovUkPaymentEvent::create([
      'payment_id' => $this->payment->payment_id->value,
      'govukpayment_id' => $this->payment->id(),
      'event_timestamp' => $timestamp2,
      'event_type' => 'payment.status_updated',
      'status' => 'submitted',
      'source' => 'redirect',
      'data' => ['status' => 'submitted'],
      'is_current_state' => FALSE,
    ]);
    $event2->save();
    $this->events[] = $event2;

    // Now.
    $timestamp3 = time();
    $event3 = GovUkPaymentEvent::create([
      'payment_id' => $this->payment->payment_id->value,
      'govukpayment_id' => $this->payment->id(),
      'event_timestamp' => $timestamp3,
      'event_type' => 'payment.status_updated',
      'status' => 'success',
      'source' => 'webhook',
      'data' => ['status' => 'success'],
      'is_current_state' => TRUE,
    ]);
    $event3->save();
    $this->events[] = $event3;

    // Query events by payment ID.
    $query = $this->entityTypeManager->getStorage('govukpayment_event')->getQuery()
      ->condition('payment_id', $this->payment->payment_id->value)
      ->sort('event_timestamp', 'ASC')
      ->accessCheck(FALSE);
    $event_ids = $query->execute();

    // Verify we got all three events in chronological order.
    $this->assertCount(3, $event_ids, 'Found all three events for the payment.');

    $events = $this->entityTypeManager->getStorage('govukpayment_event')->loadMultiple($event_ids);
    $this->assertEquals('created', reset($events)->status->value, 'First event has created status.');
    $this->assertEquals('submitted', next($events)->status->value, 'Second event has submitted status.');
    $this->assertEquals('success', next($events)->status->value, 'Third event has success status.');
  }

  /**
   * Tests querying for the current state event.
   */
  public function testQueryCurrentStateEvent() {
    // Create multiple events for the same payment.
    $event1 = GovUkPaymentEvent::create([
      'payment_id' => $this->payment->payment_id->value,
      'govukpayment_id' => $this->payment->id(),
      'event_timestamp' => time() - 3600,
      'event_type' => 'payment.created',
      'status' => 'created',
      'source' => 'api',
      'data' => ['status' => 'created'],
      'is_current_state' => FALSE,
    ]);
    $event1->save();
    $this->events[] = $event1;

    $event2 = GovUkPaymentEvent::create([
      'payment_id' => $this->payment->payment_id->value,
      'govukpayment_id' => $this->payment->id(),
      'event_timestamp' => time(),
      'event_type' => 'payment.status_updated',
      'status' => 'success',
      'source' => 'webhook',
      'data' => ['status' => 'success'],
      'is_current_state' => TRUE,
    ]);
    $event2->save();
    $this->events[] = $event2;

    // Query for the current state event.
    $query = $this->entityTypeManager->getStorage('govukpayment_event')->getQuery()
      ->condition('payment_id', $this->payment->payment_id->value)
      ->condition('is_current_state', TRUE)
      ->accessCheck(FALSE);
    $event_ids = $query->execute();

    // Verify we got only the current state event.
    $this->assertCount(1, $event_ids, 'Found one current state event.');

    $event = $this->entityTypeManager->getStorage('govukpayment_event')->load(reset($event_ids));
    $this->assertEquals('success', $event->status->value, 'Current state event has success status.');
  }

  /**
   * Tests deleting a payment event entity.
   */
  public function testDeletePaymentEvent() {
    // Create a payment event entity.
    $event = GovUkPaymentEvent::create([
      'payment_id' => $this->payment->payment_id->value,
      'govukpayment_id' => $this->payment->id(),
      'event_timestamp' => time(),
      'event_type' => 'payment.created',
      'status' => 'created',
      'source' => 'api',
      'data' => ['status' => 'created'],
      'is_current_state' => TRUE,
    ]);
    $event->save();
    $this->events[] = $event;

    // Delete the event.
    $event_id = $event->id();
    $event->delete();

    // Verify the event was deleted.
    $deleted_event = $this->entityTypeManager->getStorage('govukpayment_event')->load($event_id);
    $this->assertNull($deleted_event, 'Payment event was deleted successfully.');
  }

}
