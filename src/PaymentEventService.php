<?php

namespace Drupal\govuk_pay;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Service for handling GOV.UK Payment events.
 */
class PaymentEventService {
  use StringTranslationTrait;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * The logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * The date formatter service.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected $dateFormatter;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Whether verbose logging is enabled.
   *
   * @var bool
   */
  protected $verboseLogging;

  /**
   * Constructs a new PaymentEventService.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $date_formatter
   *   The date formatter service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    LoggerChannelFactoryInterface $logger_factory,
    DateFormatterInterface $date_formatter,
    ConfigFactoryInterface $config_factory,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->loggerFactory = $logger_factory;
    $this->logger = $logger_factory->get('govuk_pay');
    $this->dateFormatter = $date_formatter;
    $this->configFactory = $config_factory;
    $this->verboseLogging = (bool) $this->configFactory->get('govuk_pay.settings')->get('verbose_logging');
  }

  /**
   * Records a payment event and updates the payment entity if needed.
   *
   * @param string $payment_id
   *   The GOV.UK Pay payment ID.
   * @param string $status
   *   The payment status.
   * @param string $event_type
   *   The event type.
   * @param string $source
   *   The source of the event (webhook, redirect, api, etc).
   * @param array $data
   *   The complete event data.
   * @param int|null $event_timestamp
   *   The timestamp of the event. If NULL, current time will be used.
   *
   * @return bool
   *   TRUE if the payment was updated, FALSE otherwise.
   */
  public function recordPaymentEvent(
    $payment_id,
    $status,
    $event_type,
    $source,
    array $data = [],
    $event_timestamp = NULL,
  ) {
    // Use current time if no timestamp provided.
    if ($event_timestamp === NULL) {
      $event_timestamp = time();
    }

    try {
      // Find the payment entity with this payment ID.
      $payment_entity = $this->findPaymentEntity($payment_id);
      if (!$payment_entity) {
        return FALSE;
      }

      // 1. First, log this event in our event log (store everything).
      $event_entity = $this->createEventEntity(
        $payment_id,
        $payment_entity->id(),
        $event_timestamp,
        $event_type,
        $status,
        $source,
        $data
      );

      // Log event recording if verbose logging is enabled.
      if ($this->verboseLogging) {
        $this->logger->info('Recorded payment event for @id of type @type with status @status', [
          '@id' => $payment_id,
          '@type' => $event_type,
          '@status' => $status,
        ]);
      }

      // Get the current latest event timestamp from the payment entity.
      $current_latest_timestamp = $payment_entity->latest_event_timestamp->value ?: 0;

      // 2. Update the payment entity status only if this event is newer.
      if ($event_timestamp > $current_latest_timestamp) {
        $this->updatePaymentEntityStatus(
          $payment_entity,
          $status,
          $event_timestamp,
          $source,
          $event_type
        );

        // Mark this event as the current state.
        $event_entity->is_current_state = TRUE;
        $event_entity->save();

        // Update any other events to not be the current state.
        $this->updateEventCurrentStateFlags($payment_entity->id(), $event_entity->id());

        // Log state update if verbose logging is enabled.
        if ($this->verboseLogging) {
          $this->logger->info('Updated canonical payment state for @id to @status', [
            '@id' => $payment_id,
            '@status' => $status,
          ]);
        }

        return TRUE;
      }
      else {
        $this->logOutOfOrderEvent($payment_id, $event_timestamp, $current_latest_timestamp);
        return FALSE;
      }
    }
    catch (\Exception $e) {
      $this->logger->error('Error processing payment event: @message', ['@message' => $e->getMessage()]);
      return FALSE;
    }
  }

  /**
   * Updates the current state flags for all events of a payment.
   *
   * @param int $payment_id
   *   The payment entity ID.
   * @param int $current_event_id
   *   The ID of the event that should be marked as current.
   */
  protected function updateEventCurrentStateFlags($payment_id, $current_event_id) {
    try {
      $event_storage = $this->entityTypeManager->getStorage('govukpayment_event');

      // Find all events for this payment except the current one.
      $query = $event_storage->getQuery()
        ->condition('govukpayment_id', $payment_id)
        ->condition('id', $current_event_id, '!=')
        ->condition('is_current_state', TRUE)
        ->accessCheck(FALSE);

      $event_ids = $query->execute();

      if (!empty($event_ids)) {
        $events = $event_storage->loadMultiple($event_ids);
        foreach ($events as $event) {
          $event->is_current_state = FALSE;
          $event->save();
        }
      }
    }
    catch (\Exception $e) {
      $this->logger->error('Error updating event current state flags: @message', ['@message' => $e->getMessage()]);
    }
  }

  /**
   * Finds a payment entity by its GOV.UK Pay payment ID.
   *
   * @param string $payment_id
   *   The GOV.UK Pay payment ID.
   *
   * @return \Drupal\govuk_pay\GovUkPaymentInterface|null
   *   The payment entity, or NULL if not found.
   */
  protected function findPaymentEntity($payment_id) {
    try {
      $payment_storage = $this->entityTypeManager->getStorage('govukpayment');
      $query = $payment_storage->getQuery()
        ->condition('payment_id', $payment_id)
        ->accessCheck(FALSE);
      $entity_ids = $query->execute();

      if (empty($entity_ids)) {
        $this->logger->warning('Payment not found for ID: @id', ['@id' => $payment_id]);
        return NULL;
      }

      // Load the payment entity.
      $payment_entity = $payment_storage->load(reset($entity_ids));
      if (!$payment_entity) {
        $this->logger->warning('Failed to load payment entity for ID: @id', ['@id' => $payment_id]);
        return NULL;
      }

      return $payment_entity;
    }
    catch (\Exception $e) {
      $this->logger->error('Error finding payment entity: @message', ['@message' => $e->getMessage()]);
      return NULL;
    }
  }

  /**
   * Creates a new payment event entity.
   *
   * @param string $payment_id
   *   The GOV.UK Pay payment ID.
   * @param int $govukpayment_id
   *   The payment entity ID.
   * @param int $event_timestamp
   *   The timestamp of the event.
   * @param string $event_type
   *   The event type.
   * @param string $status
   *   The payment status.
   * @param string $source
   *   The source of the event.
   * @param array $data
   *   The event data.
   *
   * @return \Drupal\Core\Entity\EntityInterface
   *   The created event entity.
   */
  protected function createEventEntity(
    $payment_id,
    $govukpayment_id,
    $event_timestamp,
    $event_type,
    $status,
    $source,
    array $data,
  ) {
    $event_storage = $this->entityTypeManager->getStorage('govukpayment_event');
    $event_entity = $event_storage->create([
      'payment_id' => $payment_id,
      'govukpayment_id' => $govukpayment_id,
      'event_timestamp' => $event_timestamp,
      'event_type' => $event_type,
      'status' => $status,
      'source' => $source,
      'data' => $data,
      'is_current_state' => FALSE,
    ]);
    $event_entity->save();
    return $event_entity;
  }

  /**
   * Updates a payment entity's status.
   *
   * @param \Drupal\govuk_pay\GovUkPaymentInterface $payment_entity
   *   The payment entity to update.
   * @param string $status
   *   The new status.
   * @param int $event_timestamp
   *   The timestamp of the event.
   * @param string $source
   *   The source of the event.
   * @param string $event_type
   *   The event type.
   */
  protected function updatePaymentEntityStatus(
    $payment_entity,
    $status,
    $event_timestamp,
    $source,
    $event_type,
  ) {
    // Update the payment entity with the new status.
    $payment_entity->status->value = $status;
    $payment_entity->latest_event_timestamp->value = $event_timestamp;

    /** @var \Drupal\govuk_pay\Entity\GovUkPayment $payment_entity */
    $payment_entity->setNewRevision(TRUE);
    $payment_entity->setRevisionLogMessage(
      $this->t("Payment status updated to '@status' via @source event '@event_type'", [
        '@status' => $status,
        '@source' => $source,
        '@event_type' => $event_type,
      ])
    );
    $payment_entity->save();
  }

  /**
   * Logs an out-of-order event.
   *
   * @param string $payment_id
   *   The GOV.UK Pay payment ID.
   * @param int $event_timestamp
   *   The timestamp of the event.
   * @param int $current_latest_timestamp
   *   The current latest event timestamp.
   */
  protected function logOutOfOrderEvent($payment_id, $event_timestamp, $current_latest_timestamp) {
    // Log out-of-order event if verbose logging is enabled.
    if ($this->verboseLogging) {
      $this->logger->info('Recorded out-of-order event for payment @id. Event timestamp: @event_time, Current timestamp: @current_time', [
        '@id' => $payment_id,
        '@event_time' => $this->dateFormatter->format($event_timestamp, 'custom', 'Y-m-d\\TH:i:s\\Z'),
        '@current_time' => $this->dateFormatter->format($current_latest_timestamp, 'custom', 'Y-m-d\\TH:i:s\\Z'),
      ]);
    }
  }

}
