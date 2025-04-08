<?php

namespace Drupal\govuk_pay;

use Swagger\Client\Configuration;
use Swagger\Client\Api\RefundingCardPaymentsApi;
use Swagger\Client\Api\CardPaymentsApi;
use Swagger\Client\Api\AgreementsApi;
use GuzzleHttp\ClientInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Service for creating GOV.UK Pay API clients.
 */
class PayClientService {

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The logger service.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Constructs a new PayClientService.
   *
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The HTTP client.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   */
  public function __construct(
    ClientInterface $http_client,
    ConfigFactoryInterface $config_factory,
    LoggerChannelFactoryInterface $logger_factory,
  ) {
    $this->httpClient = $http_client;
    $this->configFactory = $config_factory;
    $this->logger = $logger_factory->get('govuk_pay');
  }

  /**
   * Create a new CardPaymentsApi instance.
   *
   * @return \Swagger\Client\Api\CardPaymentsApi
   *   The created CardPaymentsApi instance.
   *
   * @throws \RuntimeException
   *   Thrown when the API key is not configured or client creation fails.
   */
  public function createCardPaymentsApi(): CardPaymentsApi {
    try {
      $config = $this->createConfiguration();
      return new CardPaymentsApi($this->httpClient, $config);
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to create CardPaymentsApi: @message', ['@message' => $e->getMessage()]);
      throw new \RuntimeException('Failed to create GOV.UK Pay API client: ' . $e->getMessage(), 0, $e);
    }
  }

  /**
   * Create a new RefundingCardPaymentsApi instance.
   *
   * @return \Swagger\Client\Api\RefundingCardPaymentsApi
   *   The created RefundingCardPaymentsApi instance.
   *
   * @throws \RuntimeException
   *   Thrown when the API key is not configured or client creation fails.
   */
  public function createRefundingCardPaymentsApi(): RefundingCardPaymentsApi {
    try {
      $config = $this->createConfiguration();
      return new RefundingCardPaymentsApi($this->httpClient, $config);
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to create RefundingCardPaymentsApi: @message', ['@message' => $e->getMessage()]);
      throw new \RuntimeException('Failed to create GOV.UK Pay refunding API client: ' . $e->getMessage(), 0, $e);
    }
  }

  /**
   * Create a new AgreementsApi instance.
   *
   * @return \Swagger\Client\Api\AgreementsApi
   *   The created AgreementsApi instance.
   *
   * @throws \RuntimeException
   *   Thrown when the API key is not configured or client creation fails.
   */
  public function createAgreementsApi(): AgreementsApi {
    try {
      $config = $this->createConfiguration();
      return new AgreementsApi($this->httpClient, $config);
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to create AgreementsApi: @message', ['@message' => $e->getMessage()]);
      throw new \RuntimeException('Failed to create GOV.UK Pay agreements API client: ' . $e->getMessage(), 0, $e);
    }
  }

  /**
   * Create a Configuration instance with API key from Drupal config.
   *
   * @return \Swagger\Client\Configuration
   *   The created Configuration instance.
   *
   * @throws \RuntimeException
   *   Thrown when the API key is not configured.
   */
  protected function createConfiguration(): Configuration {
    $drupal_config = $this->configFactory->get('govuk_pay.settings');
    $api_key = $drupal_config->get('gov_pay__apikey');

    if (empty($api_key)) {
      $this->logger->error('GOV.UK Pay API key is not configured.');
      throw new \RuntimeException('GOV.UK Pay API key is not configured.');
    }

    $config = new Configuration();

    // GOV.UK Pay authenticates using the HTTP Bearer token format.
    // The Swagger client uses accessToken for Bearer authentication.
    $config->setAccessToken($api_key);

    return $config;
  }

}
