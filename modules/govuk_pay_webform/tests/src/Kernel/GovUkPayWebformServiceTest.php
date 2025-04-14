<?php

namespace Drupal\Tests\govuk_pay_webform\Kernel;

use Swagger\Client\Model\PaymentState;
use Swagger\Client\Model\CreatePaymentResult;

/**
 * Tests the GOV.UK Pay Webform service.
 *
 * @group govuk_pay_webform
 */
class GovUkPayWebformServiceTest extends GovUkPayWebformTestBase {

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

    // Create a test webform.
    $elements = [
      'name' => [
        '#type' => 'textfield',
        '#title' => 'Name',
      ],
      'email' => [
        '#type' => 'email',
        '#title' => 'Email',
      ],
      'amount' => [
        '#type' => 'number',
        '#title' => 'Amount',
        '#default_value' => 50,
      ],
    ];

    $this->webform = $this->createTestWebform($elements, 'test_payment_form', 'Test Payment Form');

    // Create a test webform submission.
    $values = [
      'name' => 'Test User',
      'email' => 'test@example.com',
      'amount' => 50,
    ];

    $this->webformSubmission = $this->createTestSubmission($values, 'test_payment_form');
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

    parent::tearDown();
  }

  /**
   * Tests the payment data storage and retrieval.
   */
  public function testPaymentDataStorage() {
    // Test payment data.
    $payment_data = [
      'uuid' => '12345678-1234-5678-1234-567812345678',
      'webform_id' => 'test_payment_form',
      'submission_id' => '123',
    ];

    // Store payment data.
    $this->paymentService->setPaymentData($payment_data);

    // Retrieve payment data.
    $retrieved_data = $this->paymentService->getPaymentData();

    // Verify the data was stored and retrieved correctly.
    $this->assertEquals($payment_data, $retrieved_data, 'Payment data was stored and retrieved correctly.');

    // Clear payment data.
    $this->paymentService->clearPaymentData();

    // Verify data was cleared.
    $this->assertNull($this->paymentService->getPaymentData(), 'Payment data was cleared correctly.');
  }

  /**
   * Tests token replacement in payment description and reference.
   */
  public function testTokenReplacement() {
    // Create configuration with tokens.
    $configuration = [
      'payment_for' => 'Payment for [webform_submission:values:name]',
      'payment_reference' => 'REF-[webform_submission:sid]',
    ];

    // Use reflection to access protected method.
    $reflection = new \ReflectionClass($this->paymentService);
    $method = $reflection->getMethod('replaceTokens');
    $method->setAccessible(TRUE);

    // Test token replacement in payment description.
    $processed_description = $method->invoke(
      $this->paymentService,
      $configuration['payment_for'],
      $this->webformSubmission,
      TRUE
    );
    $this->assertEquals(
      'Payment for Test User',
      $processed_description,
      'Tokens in payment description were replaced correctly.'
    );

    // Test token replacement in payment reference.
    $processed_reference = $method->invoke(
      $this->paymentService,
      $configuration['payment_reference'],
      $this->webformSubmission,
      TRUE
    );
    $this->assertEquals(
      'REF-' . $this->webformSubmission->id(),
      $processed_reference,
      'Tokens in payment reference were replaced correctly.'
    );
  }

  /**
   * Tests creating a payment entity.
   */
  public function testCreatePaymentEntity() {
    // Set a submission ID directly instead of saving to the database.
    $this->webformSubmission->set('sid', 12345);

    // Mock successful payment creation response.
    $payment_id = 'pay_' . $this->randomMachineName();
    $payment_status = 'created';

    // Create a mock payment response object.
    $payment_state = new PaymentState();
    $payment_state->setStatus($payment_status);

    $payment_response = new CreatePaymentResult();
    $payment_response->setPaymentId($payment_id);
    $payment_response->setState($payment_state);

    // Test data.
    $uuid = '12345678-1234-5678-1234-567812345678';
    $amount = 5000;
    $webform_id = 'test_payment_form';
    $submission_id = $this->webformSubmission->id();
    $payment_for = 'Test Payment';
    $payment_reference = 'REF-123';

    // Create payment entity.
    $payment = $this->paymentService->createPaymentEntity(
      $payment_response,
      $uuid,
      $amount,
      $webform_id,
      $submission_id,
      $payment_for,
      $payment_reference
    );

    $this->payments[] = $payment;

    // Verify the payment entity was created correctly.
    $this->assertNotEmpty($payment->id(), 'Payment entity was created with an ID.');
    $this->assertEquals($payment_id, $payment->payment_id->value, 'Payment ID was set correctly.');
    $this->assertEquals($amount, $payment->amount->value, 'Amount was set correctly.');
    $this->assertEquals($uuid, $payment->uuid->value, 'UUID was set correctly.');
    $this->assertEquals($payment_status, $payment->status->value, 'Status was set correctly.');
    $this->assertEquals($webform_id, $payment->webform_id->value, 'Webform ID was set correctly.');
    $this->assertEquals($submission_id, $payment->submission_id->value, 'Submission ID was set correctly.');
    $this->assertEquals($payment_for, $payment->payment_for->value, 'Payment for was set correctly.');
    $this->assertEquals($payment_reference, $payment->payment_reference->value, 'Payment reference was set correctly.');
  }

  /**
   * Tests validating payment configuration.
   */
  public function testValidatePaymentConfiguration() {
    // Use reflection to access protected method.
    $reflection = new \ReflectionClass($this->paymentService);
    $method = $reflection->getMethod('validatePaymentConfiguration');
    $method->setAccessible(TRUE);

    // Test with valid configuration.
    $valid_config = [
      'payment_for' => 'Test Payment',
      'payment_reference' => 'REF-123',
    ];

    // This should not throw an exception.
    try {
      $method->invoke($this->paymentService, $valid_config);
      $this->assertTrue(TRUE, 'Valid configuration passed validation.');
    }
    catch (\Exception $e) {
      $this->fail('Valid configuration should not throw an exception: ' . $e->getMessage());
    }

    // Test with missing payment_for.
    $invalid_config1 = [
      'payment_reference' => 'REF-123',
    ];

    // This should throw an exception.
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('Missing required payment description (payment_for)');
    $method->invoke($this->paymentService, $invalid_config1);
  }

}
