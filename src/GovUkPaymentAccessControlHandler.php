<?php

namespace Drupal\govuk_pay;

use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Access\AccessResult;

/**
 * Access controller for the GOV.UK Payment entity.
 */
class GovUkPaymentAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account) {
    // The admin permission grants full access.
    if ($account->hasPermission('administer govukpayment entity')) {
      return AccessResult::allowed()->cachePerPermissions();
    }

    /** @var \Drupal\govuk_pay\GovUkPaymentInterface $entity */

    // Check if the entity implements the EntityOwnerInterface.
    switch ($operation) {
      case 'view':
        // Allow users to view their own payments.
        if ($account->id() == $entity->getOwnerId()) {
          return AccessResult::allowed()
            ->cachePerUser()
            ->addCacheableDependency($entity);
        }
        // Also allow users with the 'view any govukpayment entity'
        // permission.
        return AccessResult::allowedIfHasPermission(
          $account,
          'view any govukpayment entity'
        )->cachePerPermissions();

      case 'update':
        // Allow users to update their own payments if they have permission.
        if ($account->id() == $entity->getOwnerId()) {
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
        if ($account->id() == $entity->getOwnerId()) {
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

    // No opinion for non-owner entities or other operations.
    return AccessResult::neutral();
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL) {
    // Allow users with the 'create govukpayment entity' permission.
    return AccessResult::allowedIfHasPermission($account, 'create govukpayment entity')
      ->cachePerPermissions();
  }

}
