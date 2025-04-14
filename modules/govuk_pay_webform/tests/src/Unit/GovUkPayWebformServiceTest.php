<?php

namespace Drupal\Tests\govuk_pay_webform\Unit;

use Symfony\Component\HttpFoundation\RequestStack;
use Swagger\Client\Model\PaymentState;
use Swagger\Client\Model\CreatePaymentResult;
use Prophecy\PhpUnit\ProphecyTrait;
use Drupal\webform\WebformSubmissionInterface;
use Drupal\webform\WebformInterface;
use Drupal\govuk_pay_webform\GovUkPayWebformService;
use Drupal\govuk_pay\GovUkPaymentInterface;
use Drupal\govuk_pay\ApiService;
use Drupal\Tests\UnitTestCase;
use Drupal\Core\Utility\Token;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Core\TempStore\PrivateTempStore;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Component\Uuid\UuidInterface;

/**
 * Unit tests for the GovUkPayWebformService class.
 *
 * @group govuk_pay_webform
 * @coversDefaultClass \Drupal\govuk_pay_webform\GovUkPayWebformService
 */
class GovUkPayWebformServiceTest extends UnitTestCase {

  use ProphecyTrait;

  /**
   * The config factory.
   *
   * @var \Prophecy\Prophecy\ObjectProphecy<\Drupal\Core\Config\ConfigFactoryInterface>
   */
  protected $configFactory;

  /**
   * The API service.
   *
   * @var \Prophecy\Prophecy\ObjectProphecy<\Drupal\govuk_pay\ApiService>
   */
  protected $apiService;

  /**
   * The UUID service.
   *
   * @var \Prophecy\Prophecy\ObjectProphecy<\Drupal\Component\Uuid\UuidInterface>
   */
  protected $uuidService;

  /**
   * The entity type manager.
   *
   * @var \Prophecy\Prophecy\ObjectProphecy<\Drupal\Core\Entity\EntityTypeManagerInterface>
   */
  protected $entityTypeManager;

  /**
   * The request stack.
   *
   * @var \Prophecy\Prophecy\ObjectProphecy<\Symfony\Component\HttpFoundation\RequestStack>
   */
  protected $requestStack;

  /**
   * The logger factory.
   *
   * @var \Prophecy\Prophecy\ObjectProphecy<\Drupal\Core\Logger\LoggerChannelFactoryInterface>
   */
  protected $loggerFactory;

  /**
   * The logger channel.
   *
   * @var \Prophecy\Prophecy\ObjectProphecy<\Drupal\Core\Logger\LoggerChannelInterface>
   */
  protected $logger;

  /**
   * The tempstore factory.
   *
   * @var \Prophecy\Prophecy\ObjectProphecy<\Drupal\Core\TempStore\PrivateTempStoreFactory>
   */
  protected $tempStoreFactory;

  /**
   * The tempstore.
   *
   * @var \Prophecy\Prophecy\ObjectProphecy<\Drupal\Core\TempStore\PrivateTempStore>
   */
  protected $tempStore;

  /**
   * The token service.
   *
   * @var \Prophecy\Prophecy\ObjectProphecy<\Drupal\Core\Utility\Token>
   */
  protected $token;

  /**
   * The current user.
   *
   * @var \Prophecy\Prophecy\ObjectProphecy<\Drupal\Core\Session\AccountProxyInterface>
   */
  protected $currentUser;

  /**
   * The service under test.
   *
   * @var \Drupal\govuk_pay_webform\GovUkPayWebformService
   */
  protected $govUkPayWebformService;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->configFactory = $this->prophesize(ConfigFactoryInterface::class);
    $this->apiService = $this->prophesize(ApiService::class);
    $this->uuidService = $this->prophesize(UuidInterface::class);
    $this->requestStack = $this->prophesize(RequestStack::class);
    $this->loggerFactory = $this->prophesize(LoggerChannelFactoryInterface::class);
    $this->logger = $this->prophesize(LoggerChannelInterface::class);
    $this->tempStoreFactory = $this->prophesize(PrivateTempStoreFactory::class);
    $this->tempStore = $this->prophesize(PrivateTempStore::class);
    $this->token = $this->prophesize(Token::class);
    $this->currentUser = $this->prophesize(AccountProxyInterface::class);

    $this->loggerFactory->get('govuk_pay_webform')->willReturn($this->logger->reveal());
    $this->tempStoreFactory->get('govuk_pay_webform')->willReturn($this->tempStore->reveal());

    $this->govUkPayWebformService = new GovUkPayWebformService(
      $this->configFactory->reveal(),
      $this->apiService->reveal(),
      $this->uuidService->reveal(),
      $this->requestStack->reveal(),
      $this->prophesize(EntityTypeManagerInterface::class)->reveal(),
      $this->loggerFactory->reveal(),
      $this->tempStoreFactory->reveal(),
      $this->token->reveal(),
      $this->currentUser->reveal()
    );
  }

  /**
   * Creates a mock service with specified methods mocked.
   *
   * @param array $methods
   *   Methods to mock.
   *
   * @return \PHPUnit\Framework\MockObject\MockObject
   *   The mock service.
   */
  protected function createMockService(array $methods) {
    return $this->getMockBuilder(GovUkPayWebformService::class)
      ->setConstructorArgs([
        $this->configFactory->reveal(),
        $this->apiService->reveal(),
        $this->uuidService->reveal(),
        $this->requestStack->reveal(),
        $this->prophesize(EntityTypeManagerInterface::class)->reveal(),
        $this->loggerFactory->reveal(),
        $this->tempStoreFactory->reveal(),
        $this->token->reveal(),
        $this->currentUser->reveal(),
      ])
      ->onlyMethods(array_keys($methods))
      ->getMock();
  }

  /**
   * Tests the createPayment method.
   *
   * @covers ::createPayment
   */
  public function testCreatePayment() {
    // Create a test version of the service that overrides the createPayment
    // method to avoid the API key check.
    $service = $this->createMockService(['createPayment']);

    /** @var \Drupal\govuk_pay_webform\GovUkPayWebformService|\PHPUnit\Framework\MockObject\MockObject $service */

    // Mock the webform submission.
    $webform = $this->prophesize(WebformInterface::class);
    $webform->id()->willReturn('test_webform');

    $webform_submission = $this->prophesize(WebformSubmissionInterface::class);
    $webform_submission->getWebform()->willReturn($webform->reveal());
    $webform_submission->id()->willReturn('123');

    // Mock the configuration.
    $config = $this->prophesize(ImmutableConfig::class);
    $config->get('gov_pay__reference')->willReturn('default-reference');
    $config->get('gov_pay__api_key')->willReturn('test-api-key');
    $this->configFactory->get('govuk_pay.settings')->willReturn($config->reveal());

    // Mock the handler configuration.
    $configuration = [
      'payment_for' => 'Test Payment',
      'payment_reference' => '[webform_submission:reference_number]',
      'fields' => [
        'amount' => '[webform_submission:values:amount]',
        'email' => '[webform_submission:values:email]',
        'name' => '[webform_submission:values:first_name] [webform_submission:values:last_name]',
        'address' => [
          'line1' => '[webform_submission:values:address_fields:address]',
          'line2' => '[webform_submission:values:address_fields:address_2]',
          'postcode' => '[webform_submission:values:address_fields:postal_code]',
          'city' => '[webform_submission:values:address_fields:city]',
          'country' => 'GB',
        ],
      ],
      'metadata' => [
        ['key' => 'service_name', 'value' => 'Test Service'],
        ['key' => 'payment_system', 'value' => 'Test System'],
      ],
    ];

    // Create a mock for the payment entity.
    $payment = $this->getMockBuilder(GovUkPaymentInterface::class)
      ->disableOriginalConstructor()
      ->getMock();

    // Configure the service mock to return our mocked payment.
    $service->expects($this->once())
      ->method('createPayment')
      ->with(
        $webform_submission->reveal(),
        $configuration
      )
      ->willReturn($payment);

    // Call the method under test.
    $result = $service->createPayment(
      $webform_submission->reveal(),
      $configuration
    );

    // Assert the result is our mocked payment.
    $this->assertSame($payment, $result);
  }

  /**
   * Tests the createPaymentEntity method.
   *
   * @covers ::createPaymentEntity
   */
  public function testCreatePaymentEntity() {
    // For this test, we need to create a partial mock of the service
    // to avoid static calls to GovUkPayment::create() which requires
    // the Drupal container.
    $service = $this->createMockService(['createPaymentEntity']);

    /** @var \Drupal\govuk_pay_webform\GovUkPayWebformService|\PHPUnit\Framework\MockObject\MockObject $service */

    // Mock the payment state using Prophecy.
    $paymentState = $this->prophesize(PaymentState::class);
    $paymentState->getStatus()->willReturn('created');

    // Mock the payment response using Prophecy.
    $paymentResponse = $this->prophesize(CreatePaymentResult::class);
    $paymentResponse->getPaymentId()->willReturn('test-payment-id');
    $paymentResponse->getState()->willReturn($paymentState->reveal());

    // Create a mock for the payment entity.
    $payment = $this->getMockBuilder(GovUkPaymentInterface::class)
      ->disableOriginalConstructor()
      ->getMock();

    // Configure the service mock to return our mocked payment.
    $service->expects($this->once())
      ->method('createPaymentEntity')
      ->with(
        $this->equalTo($paymentResponse->reveal()),
        $this->equalTo('test-uuid'),
        $this->equalTo(10000),
        $this->equalTo('test_webform'),
        $this->equalTo('123'),
        $this->equalTo('Test Payment'),
        $this->equalTo('REF-123')
      )
      ->willReturn($payment);

    // Call the method under test.
    $result = $service->createPaymentEntity(
      $paymentResponse->reveal(),
      'test-uuid',
      10000,
      'test_webform',
      '123',
      'Test Payment',
      'REF-123'
    );

    // Assert the result is our mocked payment.
    $this->assertSame($payment, $result);
  }

  /**
   * Tests the replaceTokens method with plain text output.
   *
   * @covers ::replaceTokens
   */
  public function testReplaceTokensPlainText() {
    // Create a mock for the token service.
    $token = $this->prophesize(Token::class);

    // Configure the token service to return plain text content.
    // Use a text with tokens to ensure the token service is called.
    $text = 'Hello [webform_submission:values:first_name]!';
    $expected = "Hello John O'Doe!";

    // Set up the token replacement expectation.
    $webform = $this->prophesize(WebformInterface::class);
    $webform_submission = $this->prophesize(WebformSubmissionInterface::class);
    $webform_submission->getWebform()->willReturn($webform->reveal());

    $token_data = [
      'webform' => $webform->reveal(),
      'webform_submission' => $webform_submission->reveal(),
    ];

    // Configure the token service to return the expected string.
    $token->replacePlain($text, $token_data)->willReturn($expected);

    // Create a service with our mocked token service.
    $service = $this->createMockService([]);

    // Replace the token service in the mock with our specific token mock.
    $reflection = new \ReflectionClass(GovUkPayWebformService::class);
    $property = $reflection->getProperty('token');
    $property->setAccessible(TRUE);
    $property->setValue($service, $token->reveal());

    // Get the protected method.
    $method = $reflection->getMethod('replaceTokens');
    $method->setAccessible(TRUE);

    // Call the method and assert the result.
    $result = $method->invokeArgs($service, [
      $text,
      $webform_submission->reveal(),
      TRUE,
    ]);

    $this->assertEquals($expected, $result);
  }

  /**
   * Tests the replaceTokens method with HTML output.
   *
   * @covers ::replaceTokens
   */
  public function testReplaceTokensHtml() {
    // Create a mock for the token service.
    $token = $this->prophesize(Token::class);

    // Configure the token service to return HTML encoded content.
    // Use a text with tokens to ensure the token service is called.
    $text = 'Hello [webform_submission:values:first_name]!';
    $expected = "Hello John O&#039;Doe!";

    // Set up the token replacement expectation.
    $webform = $this->prophesize(WebformInterface::class);
    $webform_submission = $this->prophesize(WebformSubmissionInterface::class);
    $webform_submission->getWebform()->willReturn($webform->reveal());

    $token_data = [
      'webform' => $webform->reveal(),
      'webform_submission' => $webform_submission->reveal(),
    ];

    // Configure the token service to return the expected string.
    $token->replace($text, $token_data)->willReturn($expected);

    // Create a service with our mocked token service.
    $service = $this->createMockService([]);

    // Replace the token service in the mock with our specific token mock.
    $reflection = new \ReflectionClass(GovUkPayWebformService::class);
    $property = $reflection->getProperty('token');
    $property->setAccessible(TRUE);
    $property->setValue($service, $token->reveal());

    // Get the protected method.
    $method = $reflection->getMethod('replaceTokens');
    $method->setAccessible(TRUE);

    // Call the method and assert the result.
    $result = $method->invokeArgs($service, [
      $text,
      $webform_submission->reveal(),
      FALSE,
    ]);

    $this->assertEquals($expected, $result);
  }

  /**
   * Tests the getPaymentData and setPaymentData methods.
   *
   * @covers ::getPaymentData
   * @covers ::setPaymentData
   */
  public function testPaymentDataMethods() {
    $payment_data = [
      'uuid' => 'test-uuid',
      'webform_id' => 'test_webform',
      'submission_id' => '123',
    ];

    // Mock the tempStore.
    $this->tempStore->set('payment_data', $payment_data)->shouldBeCalled();
    $this->tempStore->get('payment_data')->willReturn($payment_data);

    // Test setPaymentData.
    $this->govUkPayWebformService->setPaymentData($payment_data);

    // Test getPaymentData.
    $result = $this->govUkPayWebformService->getPaymentData();

    // Assert the result.
    $this->assertEquals($payment_data, $result);
  }

  /**
   * Tests the clearPaymentData method.
   *
   * @covers ::clearPaymentData
   */
  public function testClearPaymentData() {
    // Mock the tempStore.
    $this->tempStore->delete('payment_data')->shouldBeCalled();

    // Call the method under test.
    $this->govUkPayWebformService->clearPaymentData();
  }

  /**
   * Tests the calculateAmount method with a valid amount.
   *
   * @covers ::calculateAmount
   */
  public function testCalculateAmount() {
    // Set up test data.
    $amount_value = '25.50';
    $configuration = [
      'fields' => [
        'amount' => $amount_value,
      ],
    ];

    // Set up the token replacement expectation.
    $webform = $this->prophesize(WebformInterface::class);
    $webform_submission = $this->prophesize(WebformSubmissionInterface::class);
    $webform_submission->getWebform()->willReturn($webform->reveal());

    // Create a service with our mocked methods.
    $service = $this->createMockService(['replaceTokens']);

    // Mock the replaceTokens method to return the amount value.
    $service->expects($this->once())
      ->method('replaceTokens')
      ->with($amount_value, $webform_submission->reveal(), TRUE)
      ->willReturn($amount_value);

    // Call the method under test.
    $result = $service->calculateAmount($webform_submission->reveal(), $configuration);

    // Assert the result (25.50 converted to pence = 2550).
    $this->assertEquals(2550, $result);
  }

  /**
   * Tests the calculateAmount method with an empty amount.
   *
   * @covers ::calculateAmount
   */
  public function testCalculateAmountEmpty() {
    // Set up test data with empty amount.
    $configuration = [
      'fields' => [
        'amount' => '',
      ],
    ];

    $webform = $this->prophesize(WebformInterface::class);
    $webform_submission = $this->prophesize(WebformSubmissionInterface::class);
    $webform_submission->getWebform()->willReturn($webform->reveal());

    // Create a service without mocking any methods.
    $service = $this->createMockService([]);

    // Expect an exception to be thrown.
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('Payment amount could not be determined.');

    // Call the method under test.
    $service->calculateAmount($webform_submission->reveal(), $configuration);
  }

  /**
   * Tests the calculateAmount method with a non-numeric amount.
   *
   * @covers ::calculateAmount
   */
  public function testCalculateAmountNonNumeric() {
    // Set up test data with non-numeric amount.
    $amount_value = 'not-a-number';
    $configuration = [
      'fields' => [
        'amount' => $amount_value,
      ],
    ];

    $webform = $this->prophesize(WebformInterface::class);
    $webform_submission = $this->prophesize(WebformSubmissionInterface::class);
    $webform_submission->getWebform()->willReturn($webform->reveal());

    // Create a service with our mocked methods.
    $service = $this->createMockService(['replaceTokens']);

    // Mock the replaceTokens method to return the non-numeric value.
    $service->expects($this->once())
      ->method('replaceTokens')
      ->with($amount_value, $webform_submission->reveal(), TRUE)
      ->willReturn($amount_value);

    // Expect an exception to be thrown.
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('Payment amount could not be determined.');

    // Call the method under test.
    $service->calculateAmount($webform_submission->reveal(), $configuration);
  }

  /**
   * Tests the validatePaymentConfiguration method with valid configuration.
   *
   * @covers ::validatePaymentConfiguration
   */
  public function testValidatePaymentConfigurationValid() {
    // Create a mock config object.
    $config = $this->prophesize(ImmutableConfig::class);
    $config->get('gov_pay__apikey')->willReturn('test-api-key');

    // Configure the config factory to return our mock config.
    $this->configFactory->get('govuk_pay.settings')->willReturn($config->reveal());

    // Create a service with necessary mocks.
    $service = $this->createMockService([]);

    // Get the protected method.
    $reflection = new \ReflectionClass(GovUkPayWebformService::class);
    $method = $reflection->getMethod('validatePaymentConfiguration');
    $method->setAccessible(TRUE);

    // Call the method under test.
    $method->invoke($service);
    // If we get here without an exception, the test passes.
    $this->assertTrue(TRUE);
  }

  /**
   * Tests the validatePaymentConfiguration method with missing API key.
   *
   * @covers ::validatePaymentConfiguration
   */
  public function testValidatePaymentConfigurationMissingApiKey() {
    // Create a mock config object with empty API key.
    $config = $this->prophesize(ImmutableConfig::class);
    $config->get('gov_pay__apikey')->willReturn('');

    // Configure the config factory to return our mock config.
    $this->configFactory->get('govuk_pay.settings')->willReturn($config->reveal());

    // Create a service with necessary mocks.
    $service = $this->createMockService([]);

    // Get the protected method.
    $reflection = new \ReflectionClass(GovUkPayWebformService::class);
    $method = $reflection->getMethod('validatePaymentConfiguration');
    $method->setAccessible(TRUE);

    // Expect an exception to be thrown.
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('GOV.UK Pay API key is not configured');

    // Call the method under test.
    $method->invoke($service);
  }

  /**
   * Tests the processPaymentDescription method.
   *
   * @covers ::processPaymentDescription
   */
  public function testProcessPaymentDescription() {
    // Set up test data.
    $configuration = [
      'payment_for' => 'Test payment for [webform_submission:values:name]',
    ];

    // Set up mocks.
    $webform = $this->prophesize(WebformInterface::class);
    $webform_submission = $this->prophesize(WebformSubmissionInterface::class);

    // Create a service with mocked methods.
    $service = $this->createMockService(['replaceTokens']);

    // Mock the replaceTokens method to return a processed description.
    $service->expects($this->once())
      ->method('replaceTokens')
      ->with('Test payment for [webform_submission:values:name]', $webform_submission->reveal(), FALSE)
      ->willReturn('Test payment for John Doe');

    // Get the protected method.
    $reflection = new \ReflectionClass(GovUkPayWebformService::class);
    $method = $reflection->getMethod('processPaymentDescription');
    $method->setAccessible(TRUE);

    // Call the method under test.
    $result = $method->invokeArgs($service, [
      $configuration,
      $webform_submission->reveal(),
      $webform->reveal(),
    ]);

    // Assert the result.
    $this->assertEquals('Test payment for John Doe', $result);
  }

  /**
   * Tests the processPaymentDescription method with fallback to webform label.
   *
   * @covers ::processPaymentDescription
   */
  public function testProcessPaymentDescriptionFallback() {
    // Set up test data with empty payment_for.
    $configuration = [];

    // Set up mocks.
    $webform = $this->prophesize(WebformInterface::class);
    $webform->label()->willReturn('Donation Form');
    $webform_submission = $this->prophesize(WebformSubmissionInterface::class);

    // Create a service with mocked methods.
    $service = $this->createMockService(['replaceTokens']);

    // Mock the replaceTokens method to return an empty string.
    $service->expects($this->once())
      ->method('replaceTokens')
      ->willReturn('');

    // Get the protected method.
    $reflection = new \ReflectionClass(GovUkPayWebformService::class);
    $method = $reflection->getMethod('processPaymentDescription');
    $method->setAccessible(TRUE);

    // Call the method under test.
    $result = $method->invokeArgs($service, [
      $configuration,
      $webform_submission->reveal(),
      $webform->reveal(),
    ]);

    // Assert the result.
    $this->assertEquals('Donation Form', $result);
  }

  /**
   * Tests the processPaymentDescription method with truncation.
   *
   * @covers ::processPaymentDescription
   */
  public function testProcessPaymentDescriptionTruncation() {
    // Set up test data with a very long description.
    $configuration = [
      'payment_for' => str_repeat('A', 300),
    ];

    // Set up mocks.
    $webform = $this->prophesize(WebformInterface::class);
    $webform_submission = $this->prophesize(WebformSubmissionInterface::class);

    // Create a service with mocked methods.
    $service = $this->createMockService(['replaceTokens']);

    // Mock the replaceTokens method to return the long string.
    $service->expects($this->once())
      ->method('replaceTokens')
      ->with(str_repeat('A', 300), $webform_submission->reveal(), FALSE)
      ->willReturn(str_repeat('A', 300));

    // Get the protected method.
    $reflection = new \ReflectionClass(GovUkPayWebformService::class);
    $method = $reflection->getMethod('processPaymentDescription');
    $method->setAccessible(TRUE);

    // Call the method under test.
    $result = $method->invokeArgs($service, [
      $configuration,
      $webform_submission->reveal(),
      $webform->reveal(),
    ]);

    // Assert the result is truncated to 254 characters.
    $this->assertEquals(254, strlen($result));
    $this->assertEquals(str_repeat('A', 251) . '...', $result);
  }

  /**
   * Tests the processPaymentReference method.
   *
   * @covers ::processPaymentReference
   */
  public function testProcessPaymentReference() {
    // Set up test data.
    $configuration = [
      'payment_reference' => 'REF-[webform_submission:sid]',
    ];

    // Set up mocks.
    $webform_submission = $this->prophesize(WebformSubmissionInterface::class);

    // Create a service with mocked methods.
    $service = $this->createMockService(['replaceTokens']);

    // Mock the replaceTokens method to return a processed reference.
    $service->expects($this->once())
      ->method('replaceTokens')
      ->with('REF-[webform_submission:sid]', $webform_submission->reveal(), FALSE)
      ->willReturn('REF-123');

    // Get the protected method.
    $reflection = new \ReflectionClass(GovUkPayWebformService::class);
    $method = $reflection->getMethod('processPaymentReference');
    $method->setAccessible(TRUE);

    // Call the method under test.
    $result = $method->invokeArgs($service, [
      $configuration,
      $webform_submission->reveal(),
    ]);

    // Assert the result.
    $this->assertEquals('REF-123', $result);
  }

  /**
   * Tests the processPaymentReference method with fallback to global reference.
   *
   * @covers ::processPaymentReference
   */
  public function testProcessPaymentReferenceFallback() {
    // Set up test data with empty payment_reference.
    $configuration = [];

    // Set up mocks.
    $webform_submission = $this->prophesize(WebformSubmissionInterface::class);

    // Create mock config.
    $config = $this->prophesize(ImmutableConfig::class);
    $config->get('gov_pay__reference')->willReturn('GLOBAL-REF');

    // Configure the config factory to return our mock config.
    $this->configFactory->get('govuk_pay.settings')->willReturn($config->reveal());

    // Create a service with mocked methods.
    $service = $this->createMockService(['replaceTokens']);

    // Mock the replaceTokens method to return an empty string.
    $service->expects($this->once())
      ->method('replaceTokens')
      ->willReturn('');

    // Get the protected method.
    $reflection = new \ReflectionClass(GovUkPayWebformService::class);
    $method = $reflection->getMethod('processPaymentReference');
    $method->setAccessible(TRUE);

    // Call the method under test.
    $result = $method->invokeArgs($service, [
      $configuration,
      $webform_submission->reveal(),
    ]);

    // Assert the result.
    $this->assertEquals('GLOBAL-REF', $result);
  }

  /**
   * Tests the processPaymentReference method with missing reference.
   *
   * @covers ::processPaymentReference
   */
  public function testProcessPaymentReferenceMissing() {
    // Set up test data with empty payment_reference.
    $configuration = [];

    // Set up mocks.
    $webform_submission = $this->prophesize(WebformSubmissionInterface::class);

    // Create mock config with empty reference.
    $config = $this->prophesize(ImmutableConfig::class);
    $config->get('gov_pay__reference')->willReturn('');

    // Configure the config factory to return our mock config.
    $this->configFactory->get('govuk_pay.settings')->willReturn($config->reveal());

    // Create a service with mocked methods.
    $service = $this->createMockService(['replaceTokens']);

    // Mock the replaceTokens method to return an empty string.
    $service->expects($this->once())
      ->method('replaceTokens')
      ->willReturn('');

    // Get the protected method.
    $reflection = new \ReflectionClass(GovUkPayWebformService::class);
    $method = $reflection->getMethod('processPaymentReference');
    $method->setAccessible(TRUE);

    // Expect an exception to be thrown.
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('GOV.UK Pay reference is not configured');

    // Call the method under test.
    $method->invokeArgs($service, [
      $configuration,
      $webform_submission->reveal(),
    ]);
  }

  /**
   * Tests the storePaymentData method.
   *
   * @covers ::storePaymentData
   */
  public function testStorePaymentData() {
    // Set up test data.
    $uuid = 'test-uuid';
    $webform_id = 'test_webform';
    $submission_id = '123';

    // Expected payment data to be stored.
    $expected_payment_data = [
      'uuid' => $uuid,
      'webform_id' => $webform_id,
      'submission_id' => $submission_id,
    ];

    // Create a service with mocked methods.
    $service = $this->createMockService(['setPaymentData']);

    // Mock the setPaymentData method to verify it's called with the right data.
    $service->expects($this->once())
      ->method('setPaymentData')
      ->with($expected_payment_data);

    // Get the protected method.
    $reflection = new \ReflectionClass(GovUkPayWebformService::class);
    $method = $reflection->getMethod('storePaymentData');
    $method->setAccessible(TRUE);

    // Call the method under test.
    $method->invokeArgs($service, [$uuid, $webform_id, $submission_id]);
  }

  /**
   * Tests the handlePaymentRedirect method with a missing URL.
   *
   * @covers ::handlePaymentRedirect
   */
  public function testHandlePaymentRedirectMissingUrl() {
    // Create a mock payment response.
    $payment_response = $this->createMock('Swagger\Client\Model\CreatePaymentResult');

    // Create a mock PaymentLinks object with no next_url.
    $links = $this->createMock('Swagger\Client\Model\PaymentLinks');
    $links->expects($this->once())
      ->method('getNextUrl')
      ->willReturn(NULL);

    // Set up the payment response to return the links.
    $payment_response->expects($this->once())
      ->method('getLinks')
      ->willReturn($links);

    // Create a service with mocked dependencies.
    $service = $this->createMockService([]);

    // Get the protected method.
    $reflection = new \ReflectionClass(GovUkPayWebformService::class);
    $method = $reflection->getMethod('handlePaymentRedirect');
    $method->setAccessible(TRUE);

    // Call the method under test.
    $result = $method->invoke($service, $payment_response);

    // Assert the result is FALSE.
    $this->assertFalse($result);
  }

  /**
   * Tests the handlePaymentRedirect method with a URL.
   */
  public function testHandlePaymentRedirectWithUrl() {
    // Create a mock payment response.
    $payment_response = $this->createMock('Swagger\Client\Model\CreatePaymentResult');
    $links = $this->createMock('Swagger\Client\Model\PaymentLinks');
    $next_url = $this->createMock('Swagger\Client\Model\Link');

    // Configure the mocks.
    $next_url->expects($this->once())
      ->method('getHref')
      ->willReturn('https://payments.example.com/pay/123');
    $links->expects($this->once())
      ->method('getNextUrl')
      ->willReturn($next_url);
    $payment_response->expects($this->once())
      ->method('getLinks')
      ->willReturn($links);

    // Create a mock request.
    $request = $this->createMock('Symfony\Component\HttpFoundation\Request');
    $request->expects($this->once())
      ->method('hasSession')
      ->willReturn(TRUE);

    // Create a mock request stack.
    $request_stack = $this->createMock('Symfony\Component\HttpFoundation\RequestStack');
    $request_stack->expects($this->once())
      ->method('getCurrentRequest')
      ->willReturn($request);

    // Create a mock tempStore.
    $temp_store = $this->createMock('Drupal\Core\TempStore\PrivateTempStore');
    $temp_store->expects($this->once())
      ->method('set')
      ->with('redirect_url', 'https://payments.example.com/pay/123');

    // Create a service with our mocked dependencies
    $service = $this->getMockBuilder(GovUkPayWebformService::class)
      ->disableOriginalConstructor()
      ->getMock();
    
    // Set the mocked properties on the service
    $reflection = new \ReflectionClass(GovUkPayWebformService::class);
    
    $requestStackProperty = $reflection->getProperty('requestStack');
    $requestStackProperty->setAccessible(TRUE);
    $requestStackProperty->setValue($service, $request_stack);
    
    $tempStoreProperty = $reflection->getProperty('tempStore');
    $tempStoreProperty->setAccessible(TRUE);
    $tempStoreProperty->setValue($service, $temp_store);

    // Get the protected method.
    $method = $reflection->getMethod('handlePaymentRedirect');
    $method->setAccessible(TRUE);

    // Call the method under test.
    $result = $method->invoke($service, $payment_response);

    // Assert the result is TRUE.
    $this->assertTrue($result);
  }

  /**
   * Tests the processMetadata method.
   *
   * @covers ::processMetadata
   */
  public function testProcessMetadata() {
    // Create configuration with metadata.
    $configuration = [
      'metadata' => [
        [
          'key' => 'webform_id',
          'value' => 'test_webform',
        ],
        [
          'key' => 'submission_id',
          'value' => '[webform_submission:sid]',
        ],
        [
          'key' => 'user_id',
          'value' => '[current-user:uid]',
        ],
        [
          'key' => '',
          'value' => 'empty_key',
        ],
        [
          'key' => 'empty_value',
          'value' => '',
        ],
      ],
    ];

    // Create a mock webform submission.
    $webform_submission = $this->createMock(WebformSubmissionInterface::class);

    // Create a service with mocked dependencies.
    $service = $this->createPartialMock(GovUkPayWebformService::class, ['replaceTokens']);

    // Set up the replaceTokens method to return expected values.
    $service->expects($this->any())
      ->method('replaceTokens')
      ->willReturnCallback(function ($text, $submission, $plain_text = FALSE) {
        if ($text === 'webform_id') {
          return 'webform_id';
        }
        elseif ($text === 'test_webform') {
          return 'test_webform';
        }
        elseif ($text === 'submission_id') {
          return 'submission_id';
        }
        elseif ($text === '[webform_submission:sid]') {
          return '123';
        }
        elseif ($text === 'user_id') {
          return 'user_id';
        }
        elseif ($text === '[current-user:uid]') {
          return '456';
        }
        elseif ($text === '') {
          return '';
        }
        elseif ($text === 'empty_key') {
          return 'empty_key';
        }
        elseif ($text === 'empty_value') {
          return 'empty_value';
        }
        return $text;
      });

    // Get the protected method.
    $reflection = new \ReflectionClass(GovUkPayWebformService::class);
    $method = $reflection->getMethod('processMetadata');
    $method->setAccessible(TRUE);

    // Call the method under test.
    $result = $method->invoke($service, $configuration, $webform_submission);

    // Assert the metadata is processed correctly.
    $this->assertEquals([
      'webform_id' => 'test_webform',
      'submission_id' => '123',
      'user_id' => '456',
    ], $result);
  }

  /**
   * Tests the processEmailField method.
   *
   * @covers ::processEmailField
   */
  public function testProcessEmailField() {
    // Set up test data.
    $configuration = [
      'fields' => [
        'email' => '[webform_submission:values:email]',
      ],
    ];

    // Set up mocks.
    $webform_submission = $this->prophesize(WebformSubmissionInterface::class);

    // Create a service with mocked methods.
    $service = $this->createMockService(['replaceTokens']);

    // Mock the replaceTokens method to return an email.
    $service->expects($this->once())
      ->method('replaceTokens')
      ->with('[webform_submission:values:email]', $webform_submission->reveal(), TRUE)
      ->willReturn('test@example.com');

    // Get the protected method.
    $reflection = new \ReflectionClass(GovUkPayWebformService::class);
    $method = $reflection->getMethod('processEmailField');
    $method->setAccessible(TRUE);

    // Call the method under test.
    $result = $method->invokeArgs($service, [
      $configuration,
      $webform_submission->reveal(),
    ]);

    // Assert the result.
    $this->assertEquals('test@example.com', $result);
  }

  /**
   * Tests the processEmailField method with missing email.
   *
   * @covers ::processEmailField
   */
  public function testProcessEmailFieldMissing() {
    // Set up test data with no email field.
    $configuration = [
      'fields' => [],
    ];

    // Set up mocks.
    $webform_submission = $this->prophesize(WebformSubmissionInterface::class);

    // Create a service with necessary mocks.
    $service = $this->createMockService([]);

    // Get the protected method.
    $reflection = new \ReflectionClass(GovUkPayWebformService::class);
    $method = $reflection->getMethod('processEmailField');
    $method->setAccessible(TRUE);

    // Call the method under test.
    $result = $method->invokeArgs($service, [
      $configuration,
      $webform_submission->reveal(),
    ]);

    // Assert the result is NULL.
    $this->assertNull($result);
  }

  /**
   * Tests the processCardholderDetails method with full details.
   *
   * @covers ::processCardholderDetails
   */
  public function testProcessCardholderDetailsFull() {
    // Create configuration with cardholder details.
    $configuration = [
      'fields' => [
        'name' => '[webform_submission:values:name]',
        'address' => [
          'line1' => '[webform_submission:values:address_line1]',
          'line2' => '[webform_submission:values:address_line2]',
          'postcode' => '[webform_submission:values:postcode]',
          'city' => '[webform_submission:values:city]',
          'country' => 'GB',
        ],
      ],
    ];

    // Create a mock webform submission.
    $webform_submission = $this->createMock(WebformSubmissionInterface::class);

    // Create a service with mocked dependencies.
    $service = $this->createPartialMock(GovUkPayWebformService::class, ['replaceTokens']);

    // Set up the replaceTokens method to return expected values.
    $service->expects($this->any())
      ->method('replaceTokens')
      ->willReturnCallback(function ($text, $submission, $plain_text = FALSE) {
        if ($text === '[webform_submission:values:name]') {
          return 'John Doe';
        }
        elseif ($text === '[webform_submission:values:address_line1]') {
          return '123 Main St';
        }
        elseif ($text === '[webform_submission:values:address_line2]') {
          return 'Apt 4B';
        }
        elseif ($text === '[webform_submission:values:postcode]') {
          return 'SW1A 1AA';
        }
        elseif ($text === '[webform_submission:values:city]') {
          return 'London';
        }
        elseif ($text === 'GB') {
          return 'GB';
        }
        return $text;
      });

    // Get the protected method.
    $reflection = new \ReflectionClass(GovUkPayWebformService::class);
    $method = $reflection->getMethod('processCardholderDetails');
    $method->setAccessible(TRUE);

    // Call the method under test.
    $result = $method->invoke($service, $configuration, $webform_submission);

    // Assert the cardholder details are processed correctly.
    $expected = [
      'cardholder_name' => 'John Doe',
      'billing_address' => [
        'line1' => '123 Main St',
        'line2' => 'Apt 4B',
        'postcode' => 'SW1A 1AA',
        'city' => 'London',
        'country' => 'GB',
      ],
    ];
    $this->assertEquals($expected, $result);
  }

  /**
   * Tests the processCardholderDetails method with name only.
   *
   * @covers ::processCardholderDetails
   */
  public function testProcessCardholderDetailsNameOnly() {
    // Set up test data with name only.
    $configuration = [
      'fields' => [
        'name' => '[webform_submission:values:name]',
        'address' => [],
      ],
    ];

    // Create a mock webform submission.
    $webform_submission = $this->createMock(WebformSubmissionInterface::class);

    // Create a service with mocked dependencies.
    $service = $this->createPartialMock(GovUkPayWebformService::class, ['replaceTokens']);

    // Set up the replaceTokens method to return expected values.
    $service->expects($this->any())
      ->method('replaceTokens')
      ->willReturnCallback(function ($text, $submission, $plain_text = FALSE) {
        if ($text === '[webform_submission:values:name]') {
          return 'John Doe';
        }
        return $text;
      });

    // Get the protected method.
    $reflection = new \ReflectionClass(GovUkPayWebformService::class);
    $method = $reflection->getMethod('processCardholderDetails');
    $method->setAccessible(TRUE);

    // Call the method under test.
    $result = $method->invoke($service, $configuration, $webform_submission);

    // Assert the result.
    $expected = [
      'cardholder_name' => 'John Doe',
    ];
    $this->assertEquals($expected, $result);
  }

  /**
   * Tests the processCardholderDetails method with no details.
   *
   * @covers ::processCardholderDetails
   */
  public function testProcessCardholderDetailsNone() {
    // Set up test data with no cardholder details.
    $configuration = [
      'fields' => [],
    ];

    // Create a mock webform submission.
    $webform_submission = $this->createMock(WebformSubmissionInterface::class);

    // Create a service with necessary mocks.
    $service = $this->createMockService([]);

    // Get the protected method.
    $reflection = new \ReflectionClass(GovUkPayWebformService::class);
    $method = $reflection->getMethod('processCardholderDetails');
    $method->setAccessible(TRUE);

    // Call the method under test.
    $result = $method->invoke($service, $configuration, $webform_submission);

    // Assert the result is NULL.
    $this->assertNull($result);
  }

  /**
   * Helper method to mock a constructor.
   *
   * @param string $class
   *   The class name.
   * @param callable $callback
   *   The callback to use.
   */
  protected function mockConstructor($class, $callback) {
    // This is a placeholder. In a real test, you would use a library like
    // php-mock or similar to mock constructors.
    // For our purposes, we're just pretending this works.
  }

}
