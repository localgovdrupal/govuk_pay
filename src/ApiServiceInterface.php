<?php

namespace Drupal\govuk_pay;

use GuzzleHttp\Psr7\Uri;

/**
 * Interface for the GOV.UK Pay API service.
 */
interface ApiServiceInterface {

  /**
   * Gets a payment from the GOV.UK Pay API.
   *
   * @param string $payment_id
   *   The payment ID.
   *
   * @return \Swagger\Client\Model\PaymentWithAllLinks
   *   The payment.
   */
  public function getPayment(string $payment_id);

  /**
   * Creates a payment using the GOV.UK Pay API.
   *
   * @param int|string $amount
   *   The payment amount in pence. Will be converted to integer.
   * @param string $reference
   *   The payment reference.
   * @param string $description
   *   The payment description.
   * @param \GuzzleHttp\Psr7\Uri $return_url
   *   The return URL.
   * @param array $metadata
   *   Optional metadata to include with the payment.
   * @param string|null $email
   *   Optional email address to pre-fill on the payment page.
   * @param array|null $prefilled_cardholder_details
   *   Optional cardholder details to pre-fill on the payment page.
   *
   * @return \Swagger\Client\Model\CreatePaymentResult
   *   The created payment.
   */
  public function createPayment(
    $amount,
    string $reference,
    string $description,
    Uri $return_url,
    array $metadata = [],
    ?string $email = NULL,
    ?array $prefilled_cardholder_details = NULL,
  );

  /**
   * Gets the API client.
   *
   * @return \GovukPay\Api\PaymentsApi
   *   The API client.
   */
  public function getClient();

}
