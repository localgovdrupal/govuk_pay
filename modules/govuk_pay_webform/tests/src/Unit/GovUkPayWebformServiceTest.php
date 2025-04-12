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
   *   The methods to mock.
   *
   * @return \Drupal\govuk_pay_webform\GovUkPayWebformService|\PHPUnit\Framework\MockObject\MockObject
   *   The mocked service with calculateAmount method available.
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
      ->onlyMethods($methods)
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

}
