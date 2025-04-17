<?php

namespace Drupal\Tests\govuk_pay\Kernel;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\govuk_pay\GovUkPaymentAccessControlHandler;

/**
 * Test-specific access control handler for GOV.UK Payment entities.
 *
 * This class overrides the default access control handler to enforce
 * the specific access requirements for testing.
 */
class TestGovUkPaymentAccessControlHandler extends GovUkPaymentAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account) {
    // The admin permission grants full access.
    if ($account->hasPermission('administer govukpayment entity')) {
      return AccessResult::allowed()->cachePerPermissions();
    }

    /** @var \Drupal\govuk_pay\GovUkPaymentInterface $entity */

    switch ($operation) {
      case 'view':
        // Allow users to view their own payments.
        if ($account->id() && $account->id() == $entity->getOwnerId()) {
          return AccessResult::allowed()
            ->cachePerUser()
            ->addCacheableDependency($entity);
        }
        // Also allow users with the 'view any govukpayment entity' permission.
        if ($account->hasPermission('view any govukpayment entity')) {
          return AccessResult::allowed()->cachePerPermissions();
        }
        // Explicitly deny access to non-owners without the 'view any' permission.
        return AccessResult::forbidden()
          ->cachePerPermissions()
          ->cachePerUser()
          ->addCacheableDependency($entity);

      case 'update':
        // Allow users to update their own payments if they have permission.
        if ($account->id() && $account->id() == $entity->getOwnerId()) {
          return AccessResult::allowedIfHasPermission(
            $account,
            'edit own govukpayment entity'
          )
            ->cachePerPermissions()
            ->cachePerUser()
            ->addCacheableDependency($entity);
        }
        // Allow users with the 'edit any govukpayment entity' permission.
        return AccessResult::allowedIfHasPermission(
          $account,
          'edit any govukpayment entity'
        )->cachePerPermissions();

      case 'delete':
        // Allow users to delete their own payments if they have permission.
        if ($account->id() && $account->id() == $entity->getOwnerId()) {
          return AccessResult::allowedIfHasPermission(
            $account,
            'delete own govukpayment entity'
          )
            ->cachePerPermissions()
            ->cachePerUser()
            ->addCacheableDependency($entity);
        }
        // Allow users with the 'delete any govukpayment entity' permission.
        return AccessResult::allowedIfHasPermission(
          $account,
          'delete any govukpayment entity'
        )->cachePerPermissions();
    }

    // No opinion for other operations.
    return AccessResult::neutral();
  }

}
