<?php

namespace Drupal\Tests\govuk_pay\Unit;

use Swagger\Client\Model\PaymentWithAllLinks;
use Swagger\Client\Model\PaymentState;
use Swagger\Client\Model\CreatePaymentResult;
use Swagger\Client\Model\CreateCardPaymentRequest;
use Swagger\Client\Api\CardPaymentsApi;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Argument;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\ClientInterface;
use Drupal\govuk_pay\PayClientService;
use Drupal\govuk_pay\ApiService;
use Drupal\Tests\UnitTestCase;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Unit tests for the ApiService class.
 *
 * @group govuk_pay
 * @coversDefaultClass \Drupal\govuk_pay\ApiService
 */
class ApiServiceTest extends UnitTestCase {

  use ProphecyTrait;

  /**
   * The HTTP client.
   *
   * @var \Prophecy\Prophecy\ObjectProphecy<\GuzzleHttp\ClientInterface>
   */
  protected $httpClient;

  /**
   * The config factory.
   *
   * @var \Prophecy\Prophecy\ObjectProphecy<\Drupal\Core\Config\ConfigFactoryInterface>
   */
  protected $configFactory;

  /**
   * The Pay client service.
   *
   * @var \Prophecy\Prophecy\ObjectProphecy<\Drupal\govuk_pay\PayClientService>
   */
  protected $payClientService;

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
   * The API service under test.
   *
   * @var \Drupal\govuk_pay\ApiService
   */
  protected $apiService;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->httpClient = $this->prophesize(ClientInterface::class);
    $this->configFactory = $this->prophesize(ConfigFactoryInterface::class);
    $this->payClientService = $this->prophesize(PayClientService::class);
    $this->loggerFactory = $this->prophesize(LoggerChannelFactoryInterface::class);
    $this->logger = $this->prophesize(LoggerChannelInterface::class);

    $this->loggerFactory->get('govuk_pay')->willReturn($this->logger->reveal());

    $this->apiService = new ApiService(
      $this->httpClient->reveal(),
      $this->configFactory->reveal(),
      $this->payClientService->reveal(),
      $this->loggerFactory->reveal()
    );
  }

  /**
   * Tests the createPayment method with basic parameters.
   *
   * @covers ::createPayment
   */
  public function testCreatePaymentBasic() {
    // Mock the card payments API.
    $cardPaymentsApi = $this->prophesize(CardPaymentsApi::class);
    $this->payClientService->createCardPaymentsApi()->willReturn($cardPaymentsApi->reveal());

    // Mock the payment result.
    $paymentState = $this->prophesize(PaymentState::class);
    $paymentState->getStatus()->willReturn('created');

    $paymentResult = $this->prophesize(CreatePaymentResult::class);
    $paymentResult->getPaymentId()->willReturn('test-payment-id');
    $paymentResult->getState()->willReturn($paymentState->reveal());

    // Set up the expected request and response.
    $cardPaymentsApi->createAPayment(Argument::type(CreateCardPaymentRequest::class))
      ->willReturn($paymentResult->reveal());

    // Log the payment creation.
    $this->logger->info('Created payment with ID: @id', ['@id' => 'test-payment-id'])
      ->shouldBeCalled();

    // Call the method under test.
    $amount = 1000;
    $reference = 'test-reference';
    $description = 'Test payment';
    $returnUrl = new Uri('https://example.com/return');
    $metadata = ['key1' => 'value1', 'key2' => 'value2'];
    $email = 'test@example.com';
    $prefilled_cardholder_details = [
      'cardholder_name' => 'Test User',
      'billing_address' => [
        'line1' => '123 Test Street',
        'postcode' => 'TE1 1ST',
        'city' => 'Testville',
        'country' => 'GB',
      ],
    ];

    $result = $this->apiService->createPayment(
      $amount,
      $reference,
      $description,
      $returnUrl,
      $metadata,
      $email,
      $prefilled_cardholder_details
    );

    // Assert the result.
    $this->assertEquals('test-payment-id', $result->getPaymentId());
    $this->assertEquals('created', $result->getState()->getStatus());
  }

  /**
   * Tests the createPayment method with invalid amount.
   *
   * @covers ::createPayment
   */
  public function testCreatePaymentInvalidAmount() {
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('Failed to create payment: Payment amount must be a positive number.');

    $amount = -100;
    $reference = 'test-reference';
    $description = 'Test payment';
    $returnUrl = new Uri('https://example.com/return');

    $this->apiService->createPayment($amount, $reference, $description, $returnUrl);
  }

  /**
   * Tests the createPayment method with empty reference.
   *
   * @covers ::createPayment
   */
  public function testCreatePaymentEmptyReference() {
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('Failed to create payment: Payment reference cannot be empty.');

    $amount = 1000;
    $reference = '';
    $description = 'Test payment';
    $returnUrl = new Uri('https://example.com/return');

    $this->apiService->createPayment($amount, $reference, $description, $returnUrl);
  }

  /**
   * Tests the createPayment method with empty description.
   *
   * @covers ::createPayment
   */
  public function testCreatePaymentEmptyDescription() {
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('Failed to create payment: Payment description cannot be empty.');

    $amount = 1000;
    $reference = 'test-reference';
    $description = '';
    $returnUrl = new Uri('https://example.com/return');

    $this->apiService->createPayment($amount, $reference, $description, $returnUrl);
  }

  /**
   * Tests the createPayment method with non-scalar metadata value.
   *
   * @covers ::createPayment
   */
  public function testCreatePaymentNonScalarMetadata() {
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('Failed to create payment: Metadata value for key "key2" must be a scalar value.');

    $amount = 1000;
    $reference = 'test-reference';
    $description = 'Test payment';
    $returnUrl = new Uri('https://example.com/return');
    $metadata = ['key1' => 'value1', 'key2' => ['nested' => 'value']];

    $this->apiService->createPayment($amount, $reference, $description, $returnUrl, $metadata);
  }

  /**
   * Tests the createPayment method with string amount.
   *
   * @covers ::createPayment
   */
  public function testCreatePaymentStringAmount() {
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('Failed to create payment: Payment amount must be a positive number.');

    $amount = 'one-hundred';
    $reference = 'test-reference';
    $description = 'Test payment';
    $returnUrl = new Uri('https://example.com/return');

    $this->apiService->createPayment($amount, $reference, $description, $returnUrl);
  }

  /**
   * Tests the getPayment method.
   *
   * @covers ::getPayment
   */
  public function testGetPayment() {
    // Mock the card payments API.
    $cardPaymentsApi = $this->prophesize(CardPaymentsApi::class);
    $this->payClientService->createCardPaymentsApi()->willReturn($cardPaymentsApi->reveal());

    // Mock the payment result.
    $paymentState = $this->prophesize(PaymentState::class);
    $paymentState->getStatus()->willReturn('success');

    // Use PaymentWithAllLinks instead of CreatePaymentResult.
    $paymentResult = $this->prophesize(PaymentWithAllLinks::class);
    $paymentResult->getPaymentId()->willReturn('test-payment-id');
    $paymentResult->getState()->willReturn($paymentState->reveal());

    // Set up the expected request and response.
    $cardPaymentsApi->getAPayment('test-payment-id')
      ->willReturn($paymentResult->reveal());

    // Log the payment retrieval.
    $this->logger->debug('Retrieved payment with ID: @id', ['@id' => 'test-payment-id'])
      ->shouldBeCalled();

    // Call the method under test.
    $result = $this->apiService->getPayment('test-payment-id');

    // Assert the result.
    $this->assertEquals('test-payment-id', $result->getPaymentId());
    $this->assertEquals('success', $result->getState()->getStatus());
  }

  /**
   * Tests the getPayment method with empty payment ID.
   *
   * @covers ::getPayment
   */
  public function testGetPaymentEmptyId() {
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('Failed to get payment: Payment ID cannot be empty.');

    $this->apiService->getPayment('');
  }

}
