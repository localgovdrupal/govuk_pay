<?php

namespace Drupal\govuk_pay\Controller;

use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Url;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Controller\ControllerBase;

/**
 * Controller for listing GovUkPayment entities.
 */
class PaymentListController extends ControllerBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a new PaymentListController.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager')
    );
  }

  /**
   * Lists all GovUkPayment entities.
   *
   * @return array
   *   A render array for the payment list.
   */
  public function listPayments() {
    // For now, redirect to the view that displays payments.
    // You can replace this with custom code if needed.
    $url = Url::fromRoute('view.govuk_pay_payments.table');
    return $this->redirect($url->getRouteName());
  }

}
