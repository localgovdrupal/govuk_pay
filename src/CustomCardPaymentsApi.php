<?php

namespace Drupal\govuk_pay;

use Swagger\Client\Configuration;
use Swagger\Client\Api\CardPaymentsApi;
use GuzzleHttp\ClientInterface;

/**
 * Custom implementation of CardPaymentsApi to fix authentication.
 */
class CustomCardPaymentsApi extends CardPaymentsApi {

  /**
   * The API key.
   *
   * @var string
   */
  protected $apiKey;

  /**
   * {@inheritdoc}
   */
  public function __construct(ClientInterface $client, Configuration $config, $api_key) {
    parent::__construct($client, $config);
    $this->apiKey = $api_key;
  }

  /**
   * {@inheritdoc}
   */
  public function createAPayment($create_card_payment_request = NULL, $idempotency_key = NULL, $string = NULL) {
    // Set the authentication header directly on the request options.
    $this->getConfig()->setApiKey('Authorization', $this->apiKey);
    $this->getConfig()->setApiKeyPrefix('Authorization', 'Bearer');

    return parent::createAPayment($create_card_payment_request, $idempotency_key, $string);
  }

  /**
   * {@inheritdoc}
   */
  public function getAPayment($payment_id) {
    // Set the authentication header directly on the request options.
    $this->getConfig()->setApiKey('Authorization', $this->apiKey);
    $this->getConfig()->setApiKeyPrefix('Authorization', 'Bearer');

    return parent::getAPayment($payment_id);
  }

  /**
   * {@inheritdoc}
   */
  public function getEventsForAPayment($payment_id) {
    // Set the authentication header directly on the request options.
    $this->getConfig()->setApiKey('Authorization', $this->apiKey);
    $this->getConfig()->setApiKeyPrefix('Authorization', 'Bearer');

    return parent::getEventsForAPayment($payment_id);
  }

  /**
   * {@inheritdoc}
   */
  public function cancelAPayment($payment_id) {
    // Set the authentication header directly on the request options.
    $this->getConfig()->setApiKey('Authorization', $this->apiKey);
    $this->getConfig()->setApiKeyPrefix('Authorization', 'Bearer');

    return parent::cancelAPayment($payment_id);
  }

}
