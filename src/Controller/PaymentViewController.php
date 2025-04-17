<?php

namespace Drupal\govuk_pay\Controller;

use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\govuk_pay\GovUkPaymentInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Controller\ControllerBase;

/**
 * Controller for viewing GovUKPayment entities.
 */
class PaymentViewController extends ControllerBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a new PaymentViewController.
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
   * Displays a GovUKPayment entity.
   *
   * @param \Drupal\govuk_pay\GovUkPaymentInterface $govukpayment
   *   The GovUKPayment entity.
   *
   * @return array
   *   A render array for the view.
   */
  public function view(GovUkPaymentInterface $govukpayment) {
    // Load all revisions of this payment.
    $storage = $this->entityTypeManager->getStorage('govukpayment');
    $revision_ids = $storage->getQuery()
      ->allRevisions()
      ->condition('id', $govukpayment->id())
      ->sort('revision_created', 'ASC')
      ->accessCheck(TRUE)
      ->execute();

    $revision_ids = array_keys($revision_ids);

    $revisions = [];
    foreach ($revision_ids as $revision_id) {
      /** @var \Drupal\Core\Entity\RevisionableStorageInterface $storage */
      $revision = $storage->loadRevision($revision_id);
      /** @var \Drupal\Core\Entity\RevisionableInterface $revision */
      $revisions[] = [
        'revision_created' => $revision->created->value,
        'status' => $revision->status->value,
      ];
    }

    // Prepare payment data for the template.
    $payment_data = [
      'id' => $govukpayment->id(),
      'status' => $govukpayment->getStatus(),
      'amount' => [
        'value' => $govukpayment->getAmount()['value'] / 100,
      ],
      'reference' => $govukpayment->getPaymentReference(),
      'created' => $govukpayment->getCreatedTime(),
      'payment_id' => $govukpayment->getPaymentId(),
      'payment_for' => $govukpayment->getPaymentFor(),
      'revisions' => $revisions,
    ];

    $build['payment'] = [
      '#theme' => 'govuk_payment_view',
      '#payment' => $payment_data,
      '#govukpayment' => $govukpayment,
      '#cache' => [
        'tags' => $govukpayment->getCacheTags(),
      ],
    ];

    return $build;
  }

}
