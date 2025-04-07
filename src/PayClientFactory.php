<?php

namespace Drupal\govuk_integrations_pay;

use Alphagov\Pay\Client;

/**
 * Instantiate GOV.UK Pay library using API key from config.
 */
class PayClientFactory {

  /**
   * Create a new PayClient instance.
   *
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The HTTP client to use.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory to use.
   *
   * @return \Alphagov\Pay\Client
   *   The created PayClient instance.
   */
  public static function create($http_client, $config_factory) {
    $config = $config_factory->get('govuk_integrations_pay.settings');

    return new Client([
      'apiKey' => $config->get('gov_pay__apikey'),
      'httpClient' => $http_client,
    ]);
  }

}
