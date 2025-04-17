<?php

namespace Drupal\govuk_pay;

use Drupal\user\EntityOwnerInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\ContentEntityInterface;

/**
 * Provides an interface for defining GOV.UK Payment entities.
 */
interface GovUkPaymentInterface extends ContentEntityInterface, EntityChangedInterface, EntityOwnerInterface {

  /**
   * Gets the GOV.UK Payment creation timestamp.
   *
   * @return int
   *   Creation timestamp of the GOV.UK Payment.
   */
  public function getCreatedTime();

  /**
   * Gets the payment ID.
   *
   * @return string
   *   The payment ID.
   */
  public function getPaymentId();

  /**
   * Gets the payment status.
   *
   * @return string
   *   The payment status.
   */
  public function getStatus();

  /**
   * Gets the payment amount.
   *
   * @return array
   *   An array with 'value' and 'currency_code' keys.
   */
  public function getAmount();

  /**
   * Gets the payment reference.
   *
   * @return string
   *   The payment reference.
   */
  public function getPaymentReference();

  /**
   * Gets the payment description.
   *
   * @return string
   *   The payment description.
   */
  public function getPaymentFor();

}
