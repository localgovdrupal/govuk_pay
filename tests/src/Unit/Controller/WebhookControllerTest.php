<?php

namespace Drupal\Tests\govuk_pay\Unit\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\HeaderBag;
use Drupal\govuk_pay\PaymentEventService;
use Drupal\govuk_pay\Controller\WebhookController;
use Drupal\govuk_pay\ApiServiceInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Tests the WebhookController.
 *
 * @group govuk_pay
 * @coversDefaultClass \Drupal\govuk_pay\Controller\WebhookController
 */
class WebhookControllerTest extends UnitTestCase {

  /**
   * The mocked entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $entityTypeManager;

  /**
   * The mocked logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $loggerFactory;

  /**
   * The mocked logger.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $logger;

  /**
   * The mocked API service.
   *
   * @var \Drupal\govuk_pay\ApiServiceInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $apiService;

  /**
   * The mocked payment event service.
   *
   * @var \Drupal\govuk_pay\PaymentEventService|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $paymentEventService;

  /**
   * The mocked config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $configFactory;

  /**
   * The mocked config.
   *
   * @var \Drupal\Core\Config\ImmutableConfig|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $config;

  /**
   * The webhook controller under test.
   *
   * @var \Drupal\govuk_pay\Controller\WebhookController
   */
  protected $webhookController;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $this->logger = $this->createMock(LoggerChannelInterface::class);
    $this->apiService = $this->createMock(ApiServiceInterface::class);
    $this->paymentEventService = $this->createMock(PaymentEventService::class);
    $this->configFactory = $this->createMock(ConfigFactoryInterface::class);
    $this->config = $this->createMock(ImmutableConfig::class);

    // Set up the logger factory to return our mocked logger.
    $this->loggerFactory->expects($this->any())
      ->method('get')
      ->with('govuk_pay')
      ->willReturn($this->logger);

    // Set up the config factory to return our mocked config.
    $this->configFactory->expects($this->any())
      ->method('get')
      ->with('govuk_pay.settings')
      ->willReturn($this->config);

    // Create a partial mock of the webhook controller to test validateWebhookData.
    $this->webhookController = $this->getMockBuilder(WebhookController::class)
      ->setConstructorArgs([
        $this->entityTypeManager,
        $this->loggerFactory,
        $this->apiService,
        $this->paymentEventService,
      ])
      ->onlyMethods(['config'])
      ->getMock();

    // Set up the controller to use our mocked config factory.
    $this->webhookController->expects($this->any())
      ->method('config')
      ->with('govuk_pay.settings')
      ->willReturn($this->config);
  }

  /**
   * Tests webhook data validation with valid data.
   *
   * @covers ::validateWebhookData
   */
  public function testValidateWebhookDataWithValidData() {
    $data = [
      'resource_type' => 'payment',
      'resource_id' => 'test-payment-123',
    ];

    $method = new \ReflectionMethod($this->webhookController, 'validateWebhookData');
    $method->setAccessible(TRUE);

    $result = $method->invoke($this->webhookController, $data);
    $this->assertTrue($result, 'Valid webhook data should pass validation');
  }

  /**
   * Tests webhook data validation with invalid data.
   *
   * @covers ::validateWebhookData
   */
  public function testValidateWebhookDataWithInvalidData() {
    // Test with NULL data.
    $method = new \ReflectionMethod($this->webhookController, 'validateWebhookData');
    $method->setAccessible(TRUE);

    $result = $method->invoke($this->webhookController, NULL);
    $this->assertFalse($result, 'NULL data should fail validation');

    // Test with missing resource_type.
    $data = [
      'resource_id' => 'test-payment-123',
    ];
    $result = $method->invoke($this->webhookController, $data);
    $this->assertFalse($result, 'Data with missing resource_type should fail validation');

    // Test with wrong resource_type.
    $data = [
      'resource_type' => 'refund',
      'resource_id' => 'test-payment-123',
    ];
    $result = $method->invoke($this->webhookController, $data);
    $this->assertFalse($result, 'Data with wrong resource_type should fail validation');

    // Test with missing resource_id.
    $data = [
      'resource_type' => 'payment',
    ];
    $result = $method->invoke($this->webhookController, $data);
    $this->assertFalse($result, 'Data with missing resource_id should fail validation');
  }

  /**
   * Tests webhook signature validation with valid signature.
   *
   * @covers ::validateWebhookData
   */
  public function testValidateWebhookSignatureWithValidSignature() {
    // Create test data.
    $webhookMessageBody = '{"resource_type":"payment","resource_id":"test-payment-123"}';
    $webhookSigningSecret = 'test-signing-secret';
    $validSignature = hash_hmac('sha256', $webhookMessageBody, $webhookSigningSecret);

    // Create a mock request with the test data.
    $request = $this->createMock(Request::class);
    $headers = $this->createMock(HeaderBag::class);
    $request->headers = $headers;

    // Set up the request to return our test data.
    $request->expects($this->any())
      ->method('getContent')
      ->willReturn($webhookMessageBody);

    // Set up the headers to return our valid signature.
    $headers->expects($this->any())
      ->method('get')
      ->with('Pay-Signature')
      ->willReturn($validSignature);

    // Set up the config to return our test signing secret.
    $this->config->expects($this->any())
      ->method('get')
      ->with('gov_pay__webhook_signing_secret')
      ->willReturn($webhookSigningSecret);

    // Parse the webhook message body as JSON.
    $data = json_decode($webhookMessageBody, TRUE);

    // Call the validateWebhookData method.
    $method = new \ReflectionMethod($this->webhookController, 'validateWebhookData');
    $method->setAccessible(TRUE);

    $result = $method->invoke($this->webhookController, $data, $request);
    $this->assertTrue($result, 'Webhook with valid signature should pass validation');
  }

  /**
   * Tests webhook signature validation with invalid signature.
   *
   * @covers ::validateWebhookData
   */
  public function testValidateWebhookSignatureWithInvalidSignature() {
    // Create test data.
    $webhookMessageBody = '{"resource_type":"payment","resource_id":"test-payment-123"}';
    $webhookSigningSecret = 'test-signing-secret';
    $invalidSignature = 'invalid-signature';

    // Create a mock request with the test data.
    $request = $this->createMock(Request::class);
    $headers = $this->createMock(HeaderBag::class);
    $request->headers = $headers;

    // Set up the request to return our test data.
    $request->expects($this->any())
      ->method('getContent')
      ->willReturn($webhookMessageBody);

    // Set up the headers to return our invalid signature.
    $headers->expects($this->any())
      ->method('get')
      ->with('Pay-Signature')
      ->willReturn($invalidSignature);

    // Set up the config to return our test signing secret.
    $this->config->expects($this->any())
      ->method('get')
      ->with('gov_pay__webhook_signing_secret')
      ->willReturn($webhookSigningSecret);

    // The logger should log an error about the invalid signature.
    $this->logger->expects($this->once())
      ->method('error')
      ->with(
        'Invalid webhook signature. Expected @expected, got @actual.',
        $this->callback(function ($context) use ($invalidSignature) {
          $expectedHmac = hash_hmac('sha256', '{"resource_type":"payment","resource_id":"test-payment-123"}', 'test-signing-secret');
          return $context['@expected'] === $expectedHmac && $context['@actual'] === $invalidSignature;
        })
      );

    // Parse the webhook message body as JSON.
    $data = json_decode($webhookMessageBody, TRUE);

    // Call the validateWebhookData method.
    $method = new \ReflectionMethod($this->webhookController, 'validateWebhookData');
    $method->setAccessible(TRUE);

    $result = $method->invoke($this->webhookController, $data, $request);
    $this->assertFalse($result, 'Webhook with invalid signature should fail validation');
  }

  /**
   * Tests webhook signature validation with missing signature header.
   *
   * @covers ::validateWebhookData
   */
  public function testValidateWebhookSignatureWithMissingSignatureHeader() {
    // Create test data.
    $webhookMessageBody = '{"resource_type":"payment","resource_id":"test-payment-123"}';
    $webhookSigningSecret = 'test-signing-secret';

    // Create a mock request with the test data.
    $request = $this->createMock(Request::class);
    $headers = $this->createMock(HeaderBag::class);
    $request->headers = $headers;

    // Set up the request to return our test data.
    $request->expects($this->any())
      ->method('getContent')
      ->willReturn($webhookMessageBody);

    // Set up the headers to return null for the Pay-Signature header.
    $headers->expects($this->any())
      ->method('get')
      ->with('Pay-Signature')
      ->willReturn(NULL);

    // Set up the config to return our test signing secret.
    $this->config->expects($this->any())
      ->method('get')
      ->with('gov_pay__webhook_signing_secret')
      ->willReturn($webhookSigningSecret);

    // The logger should log an error about the missing header.
    $this->logger->expects($this->once())
      ->method('error')
      ->with('Missing Pay-Signature header in webhook request');

    // Parse the webhook message body as JSON.
    $data = json_decode($webhookMessageBody, TRUE);

    // Call the validateWebhookData method.
    $method = new \ReflectionMethod($this->webhookController, 'validateWebhookData');
    $method->setAccessible(TRUE);

    $result = $method->invoke($this->webhookController, $data, $request);
    $this->assertFalse($result, 'Webhook with missing signature header should fail validation');
  }

  /**
   * Tests webhook signature validation with missing signing secret.
   *
   * @covers ::validateWebhookData
   */
  public function testValidateWebhookSignatureWithMissingSigningSecret() {
    // Create test data.
    $webhookMessageBody = '{"resource_type":"payment","resource_id":"test-payment-123"}';
    $validSignature = 'some-signature';

    // Create a mock request with the test data.
    $request = $this->createMock(Request::class);
    $headers = $this->createMock(HeaderBag::class);
    $request->headers = $headers;

    // Set up the request to return our test data.
    $request->expects($this->any())
      ->method('getContent')
      ->willReturn($webhookMessageBody);

    // Set up the headers to return our signature.
    $headers->expects($this->any())
      ->method('get')
      ->with('Pay-Signature')
      ->willReturn($validSignature);

    // Set up the config to return null for the signing secret.
    $this->config->expects($this->any())
      ->method('get')
      ->with('gov_pay__webhook_signing_secret')
      ->willReturn(NULL);

    // The logger should log a warning about the missing signing secret.
    $this->logger->expects($this->once())
      ->method('warning')
      ->with(
        'Webhook signing secret not configured. Skipping signature validation.'
      );

    // Parse the webhook message body as JSON.
    $data = json_decode($webhookMessageBody, TRUE);

    // Call the validateWebhookData method.
    $method = new \ReflectionMethod($this->webhookController, 'validateWebhookData');
    $method->setAccessible(TRUE);

    $result = $method->invoke($this->webhookController, $data, $request);
    $this->assertTrue($result, 'Webhook validation should pass when signing secret is not configured');
  }

}
