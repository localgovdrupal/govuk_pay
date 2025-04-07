<?php

namespace Drupal\govuk_pay\Access;

use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Access\AccessResult;

/**
 * Defines the custom access control handler for the user accounts.
 */
class GovUKPaymentAccess implements AccessInterface {

  /**
   * Determine access to a GovUKPayment via the request parameters.
   *
   * @return \Drupal\Core\Access\AccessResult
   *   Returned access grant.
   */
  public static function access() {
    $access = FALSE;
    $request = \Drupal::request();
    $uuid = $request->get('uuid');
    $webform_id = $request->get('webform_id');
    $submission_id = $request->get('submission_id');

    $query = \Drupal::entityQuery('content_entity_govukpayment');
    $query->accessCheck(TRUE);
    $query->condition('uuid', $uuid);
    if ($webform_id) {
      $query->condition('webform_id', $webform_id);
    }
    if ($submission_id) {
      $query->condition('submission_id', $submission_id);
    }
    $entity_ids = $query->execute();
    if (count($entity_ids) === 1) {
      $access = TRUE;
    }
    $access_result = AccessResult::allowedIf(($access));
    return $access_result;
  }

}
