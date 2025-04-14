<?php

namespace Drupal\Tests\govuk_pay\Kernel\Entity;

use Drupal\user\Entity\User;
use Drupal\govuk_pay\Entity\GovUkPayment;
use Drupal\KernelTests\KernelTestBase;

/**
 * Tests the GovUkPayment entity.
 *
 * @group govuk_pay
 */
class GovUkPaymentEntityTest extends KernelTestBase {

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
   * Payment entities created during tests.
   *
   * @var array
   */
  protected $payments = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('govukpayment');
    $this->installSchema('user', ['users_data']);

    $this->entityTypeManager = $this->container->get('entity_type.manager');

    // Create a test user.
    $this->user = User::create([
      'name' => 'test_user',
      'mail' => 'test@example.com',
    ]);
    $this->user->save();
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    // Delete any payment entities created during tests.
    foreach ($this->payments as $payment) {
      if ($payment && $payment->id()) {
        $payment->delete();
      }
    }

    // Delete the test user.
    if ($this->user && $this->user->id()) {
      $this->user->delete();
    }

    parent::tearDown();
  }

  /**
   * Tests creating a payment entity.
   */
  public function testCreatePayment() {
    // Create a payment entity.
    $payment = GovUkPayment::create([
      'payment_id' => 'pay_123456789',
      'webform_id' => 'test_payment_form',
      'submission_id' => '123',
      'status' => 'created',
    // £50.00 in pence
      'amount' => 5000,
      'payment_for' => 'Test Payment',
      'payment_reference' => 'REF123',
      'uid' => $this->user->id(),
    ]);

    $payment->save();
    $this->payments[] = $payment;

    // Verify the payment was saved correctly.
    $this->assertNotEmpty($payment->id(), 'Payment entity was saved with an ID.');
    $this->assertEquals('pay_123456789', $payment->payment_id->value, 'Payment ID was saved correctly.');
    $this->assertEquals('test_payment_form', $payment->webform_id->value, 'Webform ID was saved correctly.');
    $this->assertEquals('123', $payment->submission_id->value, 'Submission ID was saved correctly.');
    $this->assertEquals('created', $payment->status->value, 'Status was saved correctly.');
    $this->assertEquals(5000, $payment->amount->value, 'Amount was saved correctly.');
    $this->assertEquals('Test Payment', $payment->payment_for->value, 'Payment for was saved correctly.');
    $this->assertEquals('REF123', $payment->payment_reference->value, 'Payment reference was saved correctly.');
    $this->assertEquals($this->user->id(), $payment->getOwnerId(), 'Owner was saved correctly.');
  }

  /**
   * Tests loading a payment entity.
   */
  public function testLoadPayment() {
    // Create a payment entity.
    $payment = GovUkPayment::create([
      'payment_id' => 'pay_987654321',
      'webform_id' => 'test_payment_form',
      'submission_id' => '456',
      'status' => 'created',
    // £100.00 in pence
      'amount' => 10000,
      'payment_for' => 'Test Payment 2',
      'payment_reference' => 'REF456',
      'uid' => $this->user->id(),
    ]);

    $payment->save();
    $payment_id = $payment->id();
    $this->payments[] = $payment;

    // Clear static cache to ensure we're loading from storage.
    $this->entityTypeManager->getStorage('govukpayment')->resetCache([$payment_id]);

    // Load the payment.
    $loaded_payment = GovUkPayment::load($payment_id);

    // Verify the loaded payment.
    $this->assertNotNull($loaded_payment, 'Payment was loaded successfully.');
    $this->assertEquals('pay_987654321', $loaded_payment->payment_id->value, 'Payment ID was loaded correctly.');
    $this->assertEquals('test_payment_form', $loaded_payment->webform_id->value, 'Webform ID was loaded correctly.');
    $this->assertEquals('456', $loaded_payment->submission_id->value, 'Submission ID was loaded correctly.');
    $this->assertEquals('created', $loaded_payment->status->value, 'Status was loaded correctly.');
    $this->assertEquals(10000, $loaded_payment->amount->value, 'Amount was loaded correctly.');
    $this->assertEquals('Test Payment 2', $loaded_payment->payment_for->value, 'Payment for was loaded correctly.');
    $this->assertEquals('REF456', $loaded_payment->payment_reference->value, 'Payment reference was loaded correctly.');
  }

  /**
   * Tests updating a payment entity.
   */
  public function testUpdatePayment() {
    // Create a payment entity.
    $payment = GovUkPayment::create([
      'payment_id' => 'pay_update_test',
      'webform_id' => 'test_payment_form',
      'submission_id' => '789',
      'status' => 'created',
    // £75.00 in pence
      'amount' => 7500,
      'payment_for' => 'Update Test',
      'payment_reference' => 'REF789',
      'uid' => $this->user->id(),
    ]);

    $payment->save();
    $payment_id = $payment->id();
    $this->payments[] = $payment;

    // Update the payment status.
    $payment->status->value = 'success';
    $payment->save();

    // Clear static cache to ensure we're loading from storage.
    $this->entityTypeManager->getStorage('govukpayment')->resetCache([$payment_id]);

    // Load the updated payment.
    $updated_payment = GovUkPayment::load($payment_id);

    // Verify the update.
    $this->assertEquals('success', $updated_payment->status->value, 'Status was updated correctly.');
    $this->assertEquals('pay_update_test', $updated_payment->payment_id->value, 'Payment ID remained unchanged.');
    $this->assertEquals(7500, $updated_payment->amount->value, 'Amount remained unchanged.');
  }

  /**
   * Tests payment entity revision creation.
   */
  public function testPaymentRevisions() {
    // Create a payment entity.
    $payment = GovUkPayment::create([
      'payment_id' => 'pay_revision_test',
      'webform_id' => 'test_payment_form',
      'submission_id' => '101',
      'status' => 'created',
    // £150.00 in pence
      'amount' => 15000,
      'payment_for' => 'Revision Test',
      'payment_reference' => 'REF101',
      'uid' => $this->user->id(),
    ]);

    $payment->save();
    $payment_id = $payment->id();
    $original_vid = $payment->getRevisionId();
    $this->payments[] = $payment;

    // Update the payment with a new revision.
    $payment->setNewRevision(TRUE);
    $payment->status->value = 'submitted';
    $payment->setRevisionLogMessage('Payment submitted to GOV.UK Pay');
    $payment->save();

    $new_vid = $payment->getRevisionId();

    // Verify we have a new revision.
    $this->assertNotEquals($original_vid, $new_vid, 'A new revision was created.');

    // Load the latest revision.
    $latest_revision = $this->entityTypeManager->getStorage('govukpayment')->loadRevision($new_vid);

    // Verify the revision data.
    $this->assertEquals('submitted', $latest_revision->status->value, 'Status was updated in the new revision.');
    $this->assertEquals('Payment submitted to GOV.UK Pay', $latest_revision->getRevisionLogMessage(), 'Revision log message was saved correctly.');
  }

  /**
   * Tests deleting a payment entity.
   */
  public function testDeletePayment() {
    // Create a payment entity.
    $payment = GovUkPayment::create([
      'payment_id' => 'pay_delete_test',
      'webform_id' => 'test_payment_form',
      'submission_id' => '202',
      'status' => 'created',
    // £20.00 in pence
      'amount' => 2000,
      'payment_for' => 'Delete Test',
      'payment_reference' => 'REF202',
      'uid' => $this->user->id(),
    ]);

    $payment->save();
    $payment_id = $payment->id();

    // Delete the payment.
    $payment->delete();

    // Try to load the deleted payment.
    $deleted_payment = GovUkPayment::load($payment_id);

    // Verify the payment was deleted.
    $this->assertNull($deleted_payment, 'Payment was deleted successfully.');
  }

}
