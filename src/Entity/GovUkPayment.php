<?php

namespace Drupal\govuk_pay\Entity;

use Drupal\user\EntityOwnerTrait;
use Drupal\govuk_pay\GovUkPaymentInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\ContentEntityBase;

/**
 * Defines the GovUkPayment entity.
 *
 * @ContentEntityType(
 *   id = "govukpayment",
 *   label = @Translation("GOV.UK Payment"),
 *   base_table = "govukpayment",
 *   admin_permission = "administer govukpayment entity",
 *   fieldable = FALSE,
 *   handlers = {
 *     "access" = "Drupal\govuk_pay\GovUkPaymentAccessControlHandler",
 *     "views_data" = "Drupal\views\EntityViewsData",
 *     "form" = {
 *       "default" = "Drupal\Core\Entity\ContentEntityForm",
 *       "delete" = "Drupal\Core\Entity\ContentEntityDeleteForm",
 *     },
 *   },
 *   permission_granularity = "entity_type",
 *   entity_keys = {
 *     "id" = "id",
 *     "payment_id" = "payment_id",
 *     "uuid" = "uuid",
 *     "webform_id" = "webform_id",
 *     "submission_id" = "submission_id",
 *     "status" = "status",
 *     "amount" = "amount",
 *     "uid" = "uid",
 *     "payment_for" = "payment_for",
 *     "payment_reference" = "payment_reference",
 *     "owner" = "uid",
 *   },
 *   config_export = {
 *     "id",
 *     "payment_id",
 *     "uuid",
 *     "webform_id",
 *     "submission_id",
 *     "status",
 *     "amount",
 *     "payment_for",
 *     "payment_reference",
 *     "uid",
 *   }
 * )
 */
class GovUkPayment extends ContentEntityBase implements GovUkPaymentInterface {

  // Implements methods defined by EntityChangedInterface.
  use EntityChangedTrait;
  use EntityOwnerTrait;

  /**
   * {@inheritdoc}
   *
   * When a new entity instance is added, set the uid entity reference to
   * the current user as the creator of the instance.
   */
  public static function preCreate(EntityStorageInterface $storage_controller, array &$values) {
    parent::preCreate($storage_controller, $values);
    $values += [
      'uid' => \Drupal::currentUser()->id(),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getCreatedTime() {
    return $this->get('created')->value;
  }

  /**
   * {@inheritdoc}
   *
   * Define the field properties here.
   *
   * Field name, type and size determine the table structure.
   *
   * In addition, we can define how the field and its content can be manipulated
   * in the GUI. The behaviour of the widgets used can be determined here.
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields += static::ownerBaseFieldDefinitions($entity_type);

    // Standard field, used as unique if primary index.
    $fields['id'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('ID'))
      ->setDescription(t('The ID of the GovUkPayment entity.'))
      ->setReadOnly(TRUE);

    // Standard field, unique outside of the scope of the current project.
    $fields['uuid'] = BaseFieldDefinition::create('uuid')
      ->setLabel(t('UUID'))
      ->setDescription(t('The UUID of the GovUkPayment entity.'))
      ->setReadOnly(TRUE);

    $fields['payment_id'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Payment ID'))
      ->setDescription(t('The GOV.UK Pay set Payment ID  of the GovUKPayment entity.'))
      ->setSettings([
        'default_value' => '',
        'max_length' => 255,
        'text_processing' => 0,
      ])
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'string',
        'weight' => -6,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['webform_id'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Webform ID'))
      ->setDescription(t('The Webform ID  of the GovUKPayment entity.'))
      ->setSettings([
        'default_value' => '',
        'max_length' => 255,
        'text_processing' => 0,
      ])
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'string',
        'weight' => -6,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['submission_id'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Submission ID'))
      ->setDescription(t('The Webform Submission ID  of the GovUKPayment entity.'))
      ->setSettings([
        'default_value' => '',
        'max_length' => 255,
        'text_processing' => 0,
      ])
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'string',
        'weight' => -6,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['status'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Status'))
      ->setDescription(t('The last saved Status of the GovUKPayment entity.'))
      ->setSettings([
        'default_value' => '',
        'max_length' => 255,
        'text_processing' => 0,
      ])
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'string',
        'weight' => -6,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['amount'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Amount'))
      ->setDescription(t('The Amount of the GovUKPayment entity.'))
      ->setSettings([
        'default_value' => '',
        'max_length' => 255,
        'text_processing' => 0,
      ])
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'string',
        'weight' => -6,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['payment_for'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Payment For'))
      ->setDescription(t('The payment description shown to the user.'))
      ->setSettings([
        'default_value' => '',
        'max_length' => 255,
        'text_processing' => 0,
      ])
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'string',
        'weight' => -5,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['payment_reference'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Payment Reference'))
      ->setDescription(t('The payment reference shown to the user.'))
      ->setSettings([
        'default_value' => '',
        'max_length' => 255,
        'text_processing' => 0,
      ])
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'string',
        'weight' => -4,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Created'))
      ->setDescription(t('The time that the entity was created.'));
    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(t('Changed'))
      ->setDescription(t('The time that the entity was last edited.'));

    return $fields;
  }

}
