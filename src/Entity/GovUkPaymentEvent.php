<?php

namespace Drupal\govuk_pay\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Entity\EntityChangedTrait;

/**
 * Defines the GOV.UK Payment Event entity.
 *
 * @ContentEntityType(
 *   id = "govukpayment_event",
 *   label = @Translation("GOV.UK Payment Event"),
 *   base_table = "govukpayment_event",
 *   admin_permission = "administer govukpayment entity",
 *   fieldable = FALSE,
 *   handlers = {
 *     "access" = "Drupal\govuk_pay\GovUkPaymentAccessControlHandler",
 *     "list_builder" = "Drupal\Core\Entity\EntityListBuilder",
 *     "views_data" = "Drupal\govuk_pay\GovUkPaymentEventViewsData",
 *     "form" = {
 *       "default" = "Drupal\Core\Entity\ContentEntityForm",
 *       "delete" = "Drupal\Core\Entity\ContentEntityDeleteForm",
 *     },
 *   },
 *   permission_granularity = "entity_type",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *   },
 *   links = {
 *     "canonical" = "/admin/content/govuk-payment-event/{govukpayment_event}",
 *     "collection" = "/admin/content/govuk-payment-events",
 *   },
 * )
 */
class GovUkPaymentEvent extends ContentEntityBase {

  use EntityChangedTrait;

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['payment_id'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Payment ID'))
      ->setDescription(t('The GOV.UK Pay payment ID.'))
      ->setRequired(TRUE)
      ->setSettings([
        'max_length' => 255,
        'text_processing' => 0,
      ])
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'string',
        'weight' => -4,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['govukpayment_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Payment Entity'))
      ->setDescription(t('The Drupal payment entity this event is associated with.'))
      ->setSetting('target_type', 'govukpayment')
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'entity_reference_label',
        'weight' => -3,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['event_timestamp'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(t('Event Timestamp'))
      ->setDescription(t('The timestamp when the event occurred (from GOV.UK Pay).'))
      ->setRequired(TRUE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'timestamp',
        'weight' => -2,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Created'))
      ->setDescription(t('The time that the entity was created.'));

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(t('Changed'))
      ->setDescription(t('The time that the entity was last edited.'));

    $fields['event_type'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Event Type'))
      ->setDescription(t('The type of the payment event.'))
      ->setRequired(TRUE)
      ->setSettings([
        'max_length' => 255,
        'text_processing' => 0,
      ])
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'string',
        'weight' => -1,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['status'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Status'))
      ->setDescription(t('The payment status from this event.'))
      ->setRequired(TRUE)
      ->setSettings([
        'max_length' => 255,
        'text_processing' => 0,
      ])
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'string',
        'weight' => 0,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['source'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Source'))
      ->setDescription(t('The source of the event (webhook, redirect, etc).'))
      ->setRequired(TRUE)
      ->setSettings([
        'max_length' => 255,
        'text_processing' => 0,
      ])
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'string',
        'weight' => 1,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['data'] = BaseFieldDefinition::create('map')
      ->setLabel(t('Event Data'))
      ->setDescription(t('The complete event data.'))
      ->setDisplayOptions('view', [
        'label' => 'hidden',
        'type' => 'string',
        'weight' => 2,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['is_current_state'] = BaseFieldDefinition::create('boolean')
      ->setLabel(t('Is Current State'))
      ->setDescription(t('Whether this event represents the current state of the payment.'))
      ->setDefaultValue(FALSE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'boolean',
        'weight' => 3,
      ])
      ->setDisplayConfigurable('view', TRUE);

    return $fields;
  }

}
