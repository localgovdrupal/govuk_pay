<?php

namespace Drupal\govuk_pay;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\user\EntityOwnerInterface;

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

}
