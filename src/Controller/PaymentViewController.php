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
    // Load all payment events for this payment.
    $event_storage = $this->entityTypeManager->getStorage('govukpayment_event');
    $event_ids = $event_storage->getQuery()
      ->condition('govukpayment_id', $govukpayment->id())
      ->sort('event_timestamp', 'DESC')
      ->accessCheck(TRUE)
      ->execute();

    $payment_events = [];
    if (!empty($event_ids)) {
      $events = $event_storage->loadMultiple($event_ids);
      foreach ($events as $event) {
        $payment_events[] = [
          'event_timestamp' => $event->event_timestamp->value,
          'status' => $event->status->value,
          'event_type' => $event->event_type->value,
          'source' => $event->source->value,
          'is_current_state' => (bool) $event->is_current_state->value,
        ];
      }
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
    ];

    $build['payment'] = [
      '#theme' => 'govuk_payment_view',
      '#payment' => $payment_data,
      '#payment_events' => $payment_events,
      '#govukpayment' => $govukpayment,
      '#cache' => [
        'tags' => $govukpayment->getCacheTags(),
      ],
    ];

    return $build;
  }

}
