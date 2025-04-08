<?php

namespace Drupal\govuk_pay;

use Swagger\Client\Configuration;
use Swagger\Client\Api\RefundingCardPaymentsApi;
use Swagger\Client\Api\CardPaymentsApi;
use Swagger\Client\Api\AgreementsApi;
use GuzzleHttp\ClientInterface;
use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Instantiate GOV.UK Pay library using API key from config.
 */
class PayClientFactory {

  /**
   * Create a new CardPaymentsApi instance.
   *
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The HTTP client to use.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory to use.
   *
   * @return \Swagger\Client\Api\CardPaymentsApi
   *   The created CardPaymentsApi instance.
   */
  public static function createCardPaymentsApi(ClientInterface $http_client, ConfigFactoryInterface $config_factory) {
    $config = self::createConfiguration($config_factory);
    return new CardPaymentsApi($http_client, $config);
  }

  /**
   * Create a new RefundingCardPaymentsApi instance.
   *
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The HTTP client to use.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory to use.
   *
   * @return \Swagger\Client\Api\RefundingCardPaymentsApi
   *   The created RefundingCardPaymentsApi instance.
   */
  public static function createRefundingCardPaymentsApi(ClientInterface $http_client, ConfigFactoryInterface $config_factory) {
    $config = self::createConfiguration($config_factory);
    return new RefundingCardPaymentsApi($http_client, $config);
  }

  /**
   * Create a new AgreementsApi instance.
   *
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The HTTP client to use.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory to use.
   *
   * @return \Swagger\Client\Api\AgreementsApi
   *   The created AgreementsApi instance.
   */
  public static function createAgreementsApi(ClientInterface $http_client, ConfigFactoryInterface $config_factory) {
    $config = self::createConfiguration($config_factory);
    return new AgreementsApi($http_client, $config);
  }

  /**
   * Create a Configuration instance with API key from Drupal config.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory to use.
   *
   * @return \Swagger\Client\Configuration
   *   The created Configuration instance.
   */
  private static function createConfiguration(ConfigFactoryInterface $config_factory) {
    $drupal_config = $config_factory->get('govuk_pay.settings');
    $api_key = $drupal_config->get('gov_pay__apikey');
    $config = new Configuration();

    // GOV.UK Pay authenticates using OAuth2 HTTP bearer tokens.
    // Format: "Authorization: Bearer {YOUR_API_KEY}".
    // For some reason the Swagger client only looks at the value of
    // $this->accessToken when setting the Bearer instead of the available
    // $this->setApiKey method, so the API key is set here.
    $config->setAccessToken($api_key);
    return $config;
  }

}
