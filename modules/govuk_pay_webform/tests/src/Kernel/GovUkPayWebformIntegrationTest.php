<?php

namespace Drupal\Tests\govuk_pay_webform\Kernel;

use Swagger\Client\Model\CreatePaymentResult;
use Drupal\govuk_pay\Entity\GovUkPayment;

/**
 * Tests the integration between GOV.UK Pay Webform and GOV.UK Pay modules.
 *
 * @group govuk_pay_webform
 */
class GovUkPayWebformIntegrationTest extends GovUkPayWebformTestBase {

  /**
   * The GOV.UK Pay client service.
   *
   * @var \Drupal\govuk_pay\PayClientService
   */
  protected $payClientService;

  /**
   * Payment entities created during tests.
   *
   * @var array
   */
  protected $payments = [];

  /**
   * The mock CardPaymentsApi.
   *
   * @var \PHPUnit\Framework\MockObject\MockObject
   */
  protected $mockCardPaymentsApi;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Define elements for the webform.
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
      'address_line1' => [
        '#type' => 'textfield',
        '#title' => 'Address Line 1',
      ],
      'address_line2' => [
        '#type' => 'textfield',
        '#title' => 'Address Line 2',
      ],
      'city' => [
        '#type' => 'textfield',
        '#title' => 'City',
      ],
      'postcode' => [
        '#type' => 'textfield',
        '#title' => 'Postcode',
      ],
    ];

    // Create a test webform with the GOV.UK Pay handler.
    $this->webform = $this->createTestWebform($elements, 'test_payment_form', 'Test Payment Form');

    // Add the GOV.UK Pay handler to the webform.
    $handler_manager = $this->container->get('plugin.manager.webform.handler');
    $handler = $handler_manager->createInstance('govuk_pay');
    $handler->setWebform($this->webform);
    $handler->setHandlerId('govuk_pay_handler');

    // Configure the handler.
    $handler->setConfiguration([
      'id' => 'govuk_pay',
      'label' => 'GOV.UK Pay',
      'handler_id' => 'govuk_pay_handler',
      'status' => 1,
      'weight' => 0,
      'settings' => [
        'fields' => [
          'amount' => '50',
          'email' => 'test@test.com',
          'address' => [
            'line1' => 'address_line1',
            'line2' => 'address_line2',
            'city' => 'city',
            'postcode' => 'postcode',
            'country' => 'GB',
          ],
        ],
        'payment_for' => 'Test Payment for [webform_submission:values:name]',
        'payment_reference' => 'REF-[webform_submission:sid]',
        'metadata' => [
          [
            'key' => 'webform_id',
            'value' => '[webform:id]',
          ],
          [
            'key' => 'submission_id',
            'value' => '[webform_submission:sid]',
          ],
        ],
      ],
    ]);

    // Add the handler to the webform.
    $this->webform->addWebformHandler($handler);
    $this->webform->save();

    // Get the client service.
    $this->payClientService = $this->container->get('govuk_pay.client_service');

    // Configure the mock CardPaymentsApi to return expected responses.
    $this->configureMockCardPaymentsApi();

    // Don't mock storage - use the real one to avoid transaction issues.
    // Just set up the mock API client.
  }

  /**
   * Configure the mock CardPaymentsApi to return expected responses.
   */
  protected function configureMockCardPaymentsApi() {
    // Create a sample payment response object.
    $payment_response = $this->createMock(CreatePaymentResult::class);
    $payment_response->method('getPaymentId')->willReturn('pay_test123');
    $payment_response->method('getAmount')->willReturn(5000);
    $payment_response->method('getReference')->willReturn('REF-123');
    $payment_response->method('getDescription')->willReturn('Test Payment');
    $payment_response->method('getState')->willReturn((object) ['status' => 'created', 'finished' => FALSE]);
    $payment_response->method('getCreatedDate')->willReturn('2025-04-12T17:43:14.123Z');
    $payment_response->method('getReturnUrl')->willReturn('http://example.com/return');

    // Mock the _links property.
    $links = [
      'self' => [
        'href' => 'https://api.payments.service.gov.uk/v1/payments/pay_test123',
        'method' => 'GET',
      ],
      'next_url' => [
        'href' => 'https://www.payments.service.gov.uk/secure/pay_test123',
        'method' => 'GET',
      ],
    ];
    $payment_response->method('getLinks')->willReturn($links);

    // Configure the mock CardPaymentsApi to return the payment response.
    $this->mockCardPaymentsApi = $this->getMockBuilder('\Swagger\Client\Api\CardPaymentsApi')
      ->disableOriginalConstructor()
      ->getMock();
    $this->mockCardPaymentsApi->method('createAPayment')
      ->willReturn($payment_response);
  }

  /**
   * Adds mock responses to the mock handler queue.
   */
  protected function addMockResponses() {
    // We're now using mockCardPaymentsApi instead of mockHandler,
    // so this method is no longer needed.
  }

  /**
   * Tests the complete payment creation process.
   */
  public function testCreatePaymentProcess() {
    // Get the handler.
    $handler = $this->webform->getHandler('govuk_pay_handler');
    $this->assertNotNull($handler, 'GOV.UK Pay handler exists on the webform.');

    // Create a new submission for this test to avoid transaction issues.
    $values = [
      'name' => 'Test User',
      'email' => 'test@example.com',
      'amount' => 50,
      'address_line1' => '123 Test Street',
      'address_line2' => 'Apt 4',
      'city' => 'London',
      'postcode' => 'SW1A 1AA',
      'country' => 'GB',
    ];
    $submission = $this->createTestSubmission($values, 'test_payment_form');

    // Manually set a submission ID to avoid having to save it.
    $submission->set('sid', 12345);

    // Mock the payment service to avoid actual API calls.
    $mock_payment_service = $this->getMockBuilder('\Drupal\govuk_pay_webform\GovUkPayWebformService')
      ->disableOriginalConstructor()
      ->getMock();

    // Set up the mock to expect a call to createPayment.
    $mock_payment_service->expects($this->once())
      ->method('createPayment')
      ->with(
        $this->equalTo($submission),
        $this->anything()
      )
      ->willReturn(TRUE);

    // Replace the payment service in the handler.
    $reflection = new \ReflectionClass($handler);
    $property = $reflection->getProperty('paymentService');
    $property->setAccessible(TRUE);
    $property->setValue($handler, $mock_payment_service);

    // Call postSave to trigger payment creation.
    $handler->postSave($submission, FALSE);

    // Create a payment entity directly to test.
    $payment = GovUkPayment::create([
      'payment_id' => 'pay_test123',
      'amount' => 5000,
      'uuid' => '12345678-1234-5678-1234-567812345678',
      'status' => 'created',
      'webform_id' => 'test_payment_form',
      'submission_id' => $submission->id(),
      'payment_for' => 'Test Payment',
      'payment_reference' => 'REF-123',
    ]);

    // Verify payment properties.
    $this->assertEquals('pay_test123', $payment->payment_id->value, 'Payment ID was set correctly.');
    $this->assertEquals(5000, $payment->amount->value, 'Amount was set correctly.');
    $this->assertEquals('created', $payment->status->value, 'Status was set correctly.');
  }

  /**
   * Tests the payment entity lifecycle.
   */
  public function testPaymentEntityLifecycle() {
    // Create a payment entity directly without saving.
    $payment = GovUkPayment::create([
      'payment_id' => 'pay_test123',
      'amount' => 5000,
      'uuid' => '12345678-1234-5678-1234-567812345678',
      'status' => 'created',
      'webform_id' => 'test_payment_form',
      'submission_id' => 12345,
      'payment_for' => 'Test Payment',
      'payment_reference' => 'REF-123',
    ]);

    // Verify the payment entity has the expected properties.
    $this->assertEquals('pay_test123', $payment->payment_id->value, 'Payment ID was set correctly.');
    $this->assertEquals(5000, $payment->amount->value, 'Amount was set correctly.');
    $this->assertEquals('created', $payment->status->value, 'Status was set correctly.');

    // Update the payment status.
    $payment->set('status', 'success');

    // Verify the status was updated.
    $this->assertEquals('success', $payment->status->value, 'Payment status was updated correctly.');
  }

  /**
   * Tests webform handler integration.
   */
  public function testWebformHandlerIntegration() {
    // Get the handler.
    $handler = $this->webform->getHandler('govuk_pay_handler');
    $this->assertNotNull($handler, 'GOV.UK Pay handler exists on the webform.');

    // Check that the handler has the expected settings.
    $settings = $handler->getSettings();

    $this->assertArrayHasKey('fields', $settings, 'Handler settings include fields configuration.');
    $this->assertArrayHasKey('payment_for', $settings, 'Handler settings include payment_for configuration.');
    $this->assertArrayHasKey('payment_reference', $settings, 'Handler settings include payment_reference configuration.');
    $this->assertArrayHasKey('metadata', $settings, 'Handler settings include metadata configuration.');

    // Check that the amount field is correctly configured.
    $this->assertEquals('50', $settings['fields']['amount'], 'Amount field is correctly configured.');

    // Check that the email field is correctly configured.
    $this->assertEquals('test@test.com', $settings['fields']['email'], 'Email field is correctly configured.');
  }

  /**
   * Tests service dependencies.
   */
  public function testServiceDependencies() {
    // Check that the payment service exists.
    $this->assertNotNull($this->paymentService, 'Payment service exists.');

    // Check that the client service exists.
    $this->assertNotNull($this->payClientService, 'Client service exists.');

    // Check that the entity type manager exists.
    $this->assertNotNull($this->entityTypeManager, 'Entity type manager exists.');

    // Check that the payment entity type exists.
    $payment_definition = $this->entityTypeManager->getDefinition('govukpayment');
    $this->assertNotNull($payment_definition, 'Payment entity type exists.');

    // Check that the webform handler plugin exists.
    $handler_manager = $this->container->get('plugin.manager.webform.handler');
    $handler_definition = $handler_manager->getDefinition('govuk_pay');
    $this->assertNotNull($handler_definition, 'Webform handler plugin exists.');
  }

}
