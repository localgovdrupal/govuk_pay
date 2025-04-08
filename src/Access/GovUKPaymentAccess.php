<?php

namespace Drupal\govuk_pay\Access;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Access\AccessResult;

/**
 * Defines the custom access control handler for the user accounts.
 */
class GovUKPaymentAccess implements AccessInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * Constructs a GovUKPaymentAccess object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack.
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    RequestStack $request_stack,
    AccountProxyInterface $current_user,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->requestStack = $request_stack;
    $this->currentUser = $current_user;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('request_stack'),
      $container->get('current_user')
    );
  }

  /**
   * Determine access to a GovUKPayment via the request parameters.
   *
   * @param \Drupal\Core\Session\AccountInterface|null $account
   *   The account to check access for.
   *
   * @return \Drupal\Core\Access\AccessResult
   *   Returned access grant.
   */
  public function access(?AccountInterface $account = NULL) {
    $request = $this->requestStack->getCurrentRequest();
    $uuid = $request->get('uuid');
    $webform_id = $request->get('webform_id');
    $submission_id = $request->get('submission_id');

    // If no account is provided, use the current user.
    if (!$account) {
      $account = $this->currentUser;
    }

    // Query to find the entity ID.
    $query = $this->entityTypeManager->getStorage('govukpayment')->getQuery();
    $query->accessCheck(TRUE);
    $query->condition('uuid', $uuid);
    if ($webform_id) {
      $query->condition('webform_id', $webform_id);
    }
    if ($submission_id) {
      $query->condition('submission_id', $submission_id);
    }
    $entity_ids = $query->execute();

    // If no entity found, deny access.
    if (empty($entity_ids)) {
      return AccessResult::forbidden('No payment entity found with the provided parameters.');
    }

    // Load the entity and check access.
    $entity_id = reset($entity_ids);
    $entity = $this->entityTypeManager->getStorage('govukpayment')->load($entity_id);
    if (!$entity) {
      return AccessResult::forbidden('Payment entity could not be loaded.');
    }

    // Check if the user has access to view this entity.
    return $entity->access('view', $account, TRUE);
  }

}
