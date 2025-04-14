<?php

namespace Drupal\Tests\govuk_pay_webform\Kernel;

use GuzzleHttp\Psr7\Response;
use Drupal\Core\Form\FormState;

/**
 * Tests the GOV.UK Pay Webform handler.
 *
 * @group govuk_pay_webform
 */
class GovPayHandlerTest extends GovUkPayWebformTestBase {

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
          'amount' => 'amount',
          'email' => 'email',
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

    // Create a test webform submission.
    $values = [
      'name' => 'Test User',
      'email' => 'test@example.com',
      'amount' => 50,
      'address_line1' => '123 Test Street',
      'address_line2' => 'Apt 4',
      'city' => 'London',
      'postcode' => 'SW1A 1AA',
    ];

    $this->webformSubmission = $this->createTestSubmission($values, 'test_payment_form');
  }

  /**
   * Tests the webform handler configuration form.
   */
  public function testHandlerConfigurationForm() {
    // Get the handler.
    $handler = $this->webform->getHandler('govuk_pay_handler');
    $this->assertNotNull($handler, 'GOV.UK Pay handler exists on the webform.');

    // Create a form state.
    $form_state = new FormState();

    // Build the configuration form.
    $form = [];
    $form = $handler->buildConfigurationForm($form, $form_state);

    // Check that the configuration form has the expected fields.
    $this->assertArrayHasKey('fields', $form, 'Configuration form has fields section.');
    $this->assertArrayHasKey('messages', $form, 'Configuration form has messages section.');
    $this->assertArrayHasKey('payment_for', $form['messages'], 'Configuration form has payment_for field.');
    $this->assertArrayHasKey('payment_reference', $form['messages'], 'Configuration form has payment_reference field.');
    $this->assertArrayHasKey('metadata_container', $form, 'Configuration form has metadata section.');
  }

  /**
   * Tests the default configuration of the handler.
   */
  public function testDefaultConfiguration() {
    // Get the handler.
    $handler = $this->webform->getHandler('govuk_pay_handler');
    $this->assertNotNull($handler, 'GOV.UK Pay handler exists on the webform.');

    // Get the default configuration.
    $default_config = $handler->defaultConfiguration();

    // Check that the default configuration has the expected structure.
    $this->assertArrayHasKey('fields', $default_config, 'Default configuration has fields section.');
    $this->assertArrayHasKey('payment_for', $default_config, 'Default configuration has payment_for field.');
    $this->assertArrayHasKey('payment_reference', $default_config, 'Default configuration has payment_reference field.');
    $this->assertArrayHasKey('metadata', $default_config, 'Default configuration has metadata section.');
  }

  /**
   * Tests the handler's getAmount method.
   */
  public function testGetAmount() {
    // Get the handler.
    $handler = $this->webform->getHandler('govuk_pay_handler');
    $this->assertNotNull($handler, 'GOV.UK Pay handler exists on the webform.');

    // Make sure the handler configuration has the correct amount field.
    // The amount field should match the field name in the submission.
    $configuration = $handler->getConfiguration();
    $configuration['settings']['fields']['amount'] = 'amount';
    $handler->setConfiguration($configuration);

    // Create a mock token service that will return our expected amount.
    $mockToken = $this->getMockBuilder('\Drupal\Core\Utility\Token')
      ->disableOriginalConstructor()
      ->getMock();
    
    // Set up the mock to return 50 when replacing tokens.
    $mockToken->method('replace')
      ->willReturn('50');
    
    // Replace the token service in the handler using reflection.
    $reflection = new \ReflectionClass($handler);
    $tokenProperty = $reflection->getProperty('token');
    $tokenProperty->setAccessible(TRUE);
    $tokenProperty->setValue($handler, $mockToken);

    // Use reflection to access protected method.
    $method = $reflection->getMethod('getAmount');
    $method->setAccessible(TRUE);

    // Test getting the amount.
    $amount = $method->invoke($handler, $this->webformSubmission);
    $this->assertEquals(5000, $amount, 'Amount is correctly calculated in pence.');
  }

  /**
   * Tests the handler's postSave method.
   */
  public function testPostSave() {
    // Mock a successful payment response.
    $responseBody = json_encode([
      'amount' => 5000,
      'state' => ['status' => 'created'],
      'description' => 'Test Payment for Test User',
      'reference' => 'REF-' . $this->webformSubmission->id(),
      'payment_id' => 'pay_test123',
      '_links' => [
        'self' => [
          'href' => 'https://gov.uk/pay/self-url',
          'method' => 'GET',
        ],
        'next_url' => [
          'href' => 'https://gov.uk/pay/next-url',
          'method' => 'GET',
        ],
      ],
    ]);

    $this->mockHandler->append(
      new Response(200, ['Content-Type' => 'application/json'], $responseBody)
    );

    // Get the handler.
    $handler = $this->webform->getHandler('govuk_pay_handler');
    $this->assertNotNull($handler, 'GOV.UK Pay handler exists on the webform.');

    // Mock the createPayment method in the payment service
    // to avoid actual API calls.
    $mock_payment_service = $this->getMockBuilder('\Drupal\govuk_pay_webform\GovUkPayWebformService')
      ->disableOriginalConstructor()
      ->getMock();

    // Set up the mock to expect a call to createPayment.
    $mock_payment_service->expects($this->once())
      ->method('createPayment')
      ->with(
        $this->equalTo($this->webformSubmission),
        $this->anything()
      )
      ->willReturn(TRUE);

    // Replace the payment service in the handler.
    $reflection = new \ReflectionClass($handler);
    $property = $reflection->getProperty('paymentService');
    $property->setAccessible(TRUE);
    $property->setValue($handler, $mock_payment_service);

    // Call postSave.
    $handler->postSave($this->webformSubmission, FALSE);

    // If we get here without exceptions, the test passed.
    $this->assertTrue(TRUE, 'Handler postSave method executed successfully.');
  }

}
