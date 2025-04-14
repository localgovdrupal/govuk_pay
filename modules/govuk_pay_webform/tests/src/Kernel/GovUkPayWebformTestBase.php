<?php

namespace Drupal\Tests\govuk_pay_webform\Kernel;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Client;
use Drupal\webform\Entity\WebformSubmission;
use Drupal\webform\Entity\Webform;
use Drupal\user\Entity\User;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Core\StreamWrapper\PublicStream;
use Drupal\Core\StreamWrapper\PrivateStream;
use Drupal\Core\Serialization\Yaml;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use PHPUnit\Framework\MockObject\MockObject;
use Swagger\Client\Api\CardPaymentsApi;
use Drupal\govuk_pay\PayClientService;

/**
 * Base class for GOV.UK Pay Webform kernel tests.
 */
abstract class GovUkPayWebformTestBase extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'webform',
    'govuk_pay',
    'govuk_pay_webform',
    'path',
    'path_alias',
    'datetime',
    'file',
    'token',
  ];

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The GOV.UK Pay webform service.
   *
   * @var \Drupal\govuk_pay_webform\GovUkPayWebformService
   */
  protected $paymentService;

  /**
   * The GOV.UK Pay client service.
   *
   * @var \PHPUnit\Framework\MockObject\MockObject|\Drupal\govuk_pay\PayClientService
   */
  protected $payClientService;

  /**
   * A test user.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $user;

  /**
   * A test webform.
   *
   * @var \Drupal\webform\WebformInterface
   */
  protected $webform;

  /**
   * A test webform submission.
   *
   * @var \Drupal\webform\WebformSubmissionInterface
   */
  protected $webformSubmission;

  /**
   * Mock HTTP client.
   *
   * @var \GuzzleHttp\Client
   */
  protected $mockHttpClient;

  /**
   * Mock handler for HTTP requests.
   *
   * @var \GuzzleHttp\Handler\MockHandler
   */
  protected $mockHandler;

  /**
   * Mock CardPaymentsApi.
   *
   * @var \PHPUnit\Framework\MockObject\MockObject
   */
  protected $mockCardPaymentsApi;

  /**
   * Original HTTP client service.
   *
   * @var object
   */
  protected $originalHttpClient;

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container) {
    parent::register($container);

    // Register stream wrappers.
    $container->register('stream_wrapper.public', PublicStream::class)
      ->addTag('stream_wrapper', ['scheme' => 'public']);
    $container->register('stream_wrapper.private', PrivateStream::class)
      ->addTag('stream_wrapper', ['scheme' => 'private']);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUpFilesystem() {
    // Set up the file directories as in Drupal core tests.
    $public_file_directory = $this->siteDirectory . '/files';
    $private_file_directory = $this->siteDirectory . '/private';

    // Create the directories.
    mkdir($this->siteDirectory, 0775);
    mkdir($public_file_directory, 0775);
    mkdir($private_file_directory, 0775);

    // Register the stream wrappers.
    $this->setSetting('file_public_path', $public_file_directory);
    $this->setSetting('file_private_path', $private_file_directory);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Install entity schemas.
    $this->installEntitySchema('user');
    $this->installEntitySchema('govukpayment');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('date_format');
    $this->installEntitySchema('file');
    $this->installSchema('user', ['users_data']);
    $this->installSchema('file', ['file_usage']);

    // Install webform schema.
    $this->installSchema('webform', ['webform']);
    $this->installEntitySchema('webform_submission');

    // Install config.
    $this->installConfig(['system', 'user', 'govuk_pay', 'govuk_pay_webform', 'webform', 'file', 'token']);

    $this->entityTypeManager = $this->container->get('entity_type.manager');

    // Create a test user.
    $this->user = User::create([
      'name' => 'test_user',
      'mail' => 'test@example.com',
    ]);
    $this->user->save();

    // Store original services for restoration in tearDown.
    $this->originalHttpClient = $this->container->get('http_client');

    // Set up mock HTTP client with mock responses.
    $this->mockHandler = new MockHandler();
    $handlerStack = HandlerStack::create($this->mockHandler);
    $this->mockHttpClient = new Client(['handler' => $handlerStack]);

    // Replace the HTTP client service with our mock.
    $this->container->set('http_client', $this->mockHttpClient);

    // Create services with our mock HTTP client.
    $config_factory = $this->container->get('config.factory');

    // Set up config with API key.
    $config = $config_factory->getEditable('govuk_pay.settings');
    $config->set('gov_pay__apikey', 'test_api_key');
    $config->save();

    // Create a mock CardPaymentsApi.
    $this->mockCardPaymentsApi = $this->createMock(CardPaymentsApi::class);

    // Get the payment service.
    $this->paymentService = $this->container->get('govuk_pay_webform.payment_service');

    // Get the client service and replace the createCardPaymentsApi method.
    $this->payClientService = $this->getMockBuilder(PayClientService::class)
      ->disableOriginalConstructor()
      ->getMock();
    $this->payClientService->method('createCardPaymentsApi')
      ->willReturn($this->mockCardPaymentsApi);

    // Replace the client service in the container.
    $this->container->set('govuk_pay.client_service', $this->payClientService);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    // Restore original HTTP client before parent tearDown.
    if ($this->originalHttpClient) {
      $this->container->set('http_client', $this->originalHttpClient);
    }

    parent::tearDown();
  }

  /**
   * Creates a test webform with the provided elements.
   *
   * @param array $elements
   *   The elements to add to the webform.
   * @param string $id
   *   The webform ID.
   * @param string $title
   *   The webform title.
   *
   * @return \Drupal\webform\WebformInterface
   *   The created webform.
   */
  protected function createTestWebform(array $elements, $id = 'test_form', $title = 'Test Form') {
    $webform = Webform::create([
      'id' => $id,
      'title' => $title,
      'elements' => Yaml::encode($elements),
    ]);
    $webform->save();
    return $webform;
  }

  /**
   * Creates a test webform submission.
   *
   * @param array $values
   *   The submission values.
   * @param string $webform_id
   *   The webform ID.
   * @param int $uid
   *   The user ID.
   *
   * @return \Drupal\webform\WebformSubmissionInterface
   *   The created webform submission.
   */
  protected function createTestSubmission(array $values, $webform_id, $uid = NULL) {
    if ($uid === NULL && $this->user) {
      $uid = $this->user->id();
    }

    // Create the submission without saving it to avoid transaction issues.
    $submission = WebformSubmission::create([
      'webform_id' => $webform_id,
      'data' => $values,
      'uid' => $uid,
      'in_draft' => FALSE,
    ]);

    // Set the created and changed time to avoid issues with auto-timestamping.
    $time = time();
    $submission->set('created', $time);
    $submission->set('changed', $time);

    return $submission;
  }

}
