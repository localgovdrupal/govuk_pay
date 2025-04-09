<?php

namespace Drupal\govuk_pay_webform\Access;

use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\TempStore\PrivateTempStoreFactory;

/**
 * Defines the access control handler for GOV.UK Pay webform confirmation page.
 */
class GovPayWebformAccess implements AccessInterface {

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * The private temp store factory.
   *
   * @var \Drupal\Core\TempStore\PrivateTempStoreFactory
   */
  protected $tempStoreFactory;

  /**
   * Constructs a GovPayWebformAccess object.
   *
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user.
   * @param \Drupal\Core\TempStore\PrivateTempStoreFactory $temp_store_factory
   *   The temp store factory.
   */
  public function __construct(
    AccountProxyInterface $current_user,
    PrivateTempStoreFactory $temp_store_factory,
  ) {
    $this->currentUser = $current_user;
    $this->tempStoreFactory = $temp_store_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('current_user'),
      $container->get('tempstore.private')
    );
  }

  /**
   * Determine access to the GOV.UK Pay webform confirmation page.
   *
   * @param \Drupal\Core\Session\AccountInterface|null $account
   *   The account to check access for.
   *
   * @return \Drupal\Core\Access\AccessResult
   *   Returned access grant.
   */
  public function access(?AccountInterface $account = NULL) {
    // If no account is provided, use the current user.
    if (!$account) {
      $account = $this->currentUser;
    }

    // Check if the user has the payment data in their session.
    $tempStore = $this->tempStoreFactory->get('govuk_pay_webform');
    $payment_data = $tempStore->get('payment_data');

    if (!empty($payment_data) &&
      isset($payment_data['uuid']) &&
      isset($payment_data['webform_id']) &&
      isset($payment_data['submission_id'])) {
      // User has valid payment data in session, grant access.
      return AccessResult::allowed()
        ->addCacheContexts(['session'])
        ->addCacheTags(['govukpayment']);
    }

    // If user has admin permission, also grant access.
    if ($account->hasPermission('administer govuk payments')) {
      return AccessResult::allowed()
        ->addCacheContexts(['user.permissions'])
        ->addCacheTags(['govukpayment']);
    }

    // No valid session data and no admin permission, deny access.
    return AccessResult::forbidden('No valid payment session found.');
  }

}
