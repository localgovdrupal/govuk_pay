<?php

namespace Drupal\govuk_pay_webform\Access;

use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\govuk_pay\GovUkPaymentInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Access\AccessResult;

/**
 * Provides access control for GOV.UK Payment entities.
 */
class GovPayPaymentAccess implements AccessInterface, ContainerInjectionInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The private tempstore factory.
   *
   * @var \Drupal\Core\TempStore\PrivateTempStoreFactory
   */
  protected $tempStoreFactory;

  /**
   * Constructs a GovPayPaymentAccess object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\TempStore\PrivateTempStoreFactory $temp_store_factory
   *   The private tempstore factory.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, PrivateTempStoreFactory $temp_store_factory) {
    $this->entityTypeManager = $entity_type_manager;
    $this->tempStoreFactory = $temp_store_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('tempstore.private')
    );
  }

  /**
   * Custom access check for viewing payment entities.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user account.
   * @param \Drupal\govuk_pay\GovUkPaymentInterface $govukpayment
   *   The payment entity.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public static function viewAccess(AccountInterface $account, GovUkPaymentInterface $govukpayment) {
    // First check if the user has standard permissions to view the payment.
    if ($account->hasPermission('view any govukpayment entity') ||
        ($account->hasPermission('view own govukpayment entity') && $govukpayment->getOwnerId() == $account->id())) {
      return AccessResult::allowed()
        ->addCacheContexts(['user.permissions'])
        ->addCacheTags(['govukpayment:' . $govukpayment->id()]);
    }

    // If the user doesn't have standard permissions, check if they have a valid session.
    $tempStore = \Drupal::service('tempstore.private')->get('govuk_pay_webform');
    $payment_data = $tempStore->get('payment_data');

    if (!empty($payment_data) &&
        isset($payment_data['uuid']) &&
        isset($payment_data['webform_id']) &&
        isset($payment_data['submission_id'])) {
      // Check if this payment is associated with the webform submission in the session.
      $storage = \Drupal::entityTypeManager()->getStorage('govukpayment');
      $query = $storage->getQuery()
        ->condition('id', $govukpayment->id())
        ->condition('webform_id', $payment_data['webform_id'])
        ->condition('submission_id', $payment_data['submission_id'])
        ->accessCheck(FALSE)
        ->count();

      $count = $query->execute();

      if ($count > 0) {
        // This payment matches the session data, allow access.
        return AccessResult::allowed()
          ->addCacheContexts(['session'])
          ->addCacheTags(['govukpayment:' . $govukpayment->id()]);
      }
    }

    // No permission and no valid session, deny access.
    return AccessResult::forbidden('No permission to view this payment.');
  }

  /**
   * Custom access check for payment entities.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user account.
   * @param mixed $govukpayment
   *   The payment entity or entity ID.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function access(AccountInterface $account, $govukpayment = NULL) {
    // If govukpayment is an ID, load the entity.
    if (is_numeric($govukpayment)) {
      $govukpayment = $this->entityTypeManager->getStorage('govukpayment')
        ->load($govukpayment);
    }

    if (!$govukpayment) {
      return AccessResult::forbidden('Payment entity not found.');
    }

    // First check if the user has standard permissions to view the payment.
    if ($account->hasPermission('view any govukpayment entity') ||
        ($account->hasPermission('view own govukpayment entity') &&
         $govukpayment->getOwnerId() == $account->id())) {
      return AccessResult::allowed()
        ->addCacheContexts(['user.permissions'])
        ->addCacheTags(['govukpayment:' . $govukpayment->id()]);
    }

    // If the user doesn't have standard permissions, check if they have a valid session.
    $tempStore = $this->tempStoreFactory->get('govuk_pay_webform');
    $payment_data = $tempStore->get('payment_data');

    if (!empty($payment_data) &&
        isset($payment_data['uuid']) &&
        isset($payment_data['webform_id']) &&
        isset($payment_data['submission_id'])) {
      // Check if this payment is associated with the webform submission in the session.
      $query = $this->entityTypeManager->getStorage('govukpayment')->getQuery()
        ->condition('id', $govukpayment->id())
        ->condition('webform_id', $payment_data['webform_id'])
        ->condition('submission_id', $payment_data['submission_id'])
        ->accessCheck(FALSE)
        ->count();

      $count = $query->execute();

      if ($count > 0) {
        // This payment matches the session data, allow access.
        return AccessResult::allowed()
          ->addCacheContexts(['session'])
          ->addCacheTags(['govukpayment:' . $govukpayment->id()]);
      }
    }

    // No permission and no valid session, deny access.
    return AccessResult::forbidden('No permission to view this payment.');
  }

}
