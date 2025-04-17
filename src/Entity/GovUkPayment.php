<?php

namespace Drupal\govuk_pay\Entity;

use Drupal\user\EntityOwnerTrait;
use Drupal\govuk_pay\GovUkPaymentInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Entity\RevisionableContentEntityBase;
use Drupal\Core\Entity\RevisionLogEntityTrait;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityChangedTrait;

/**
 * Defines the GovUkPayment entity.
 *
 * @ContentEntityType(
 *   id = "govukpayment",
 *   label = @Translation("GOV.UK Payment"),
 *   base_table = "govukpayment",
 *   data_table = "govukpayment",
 *   revision_table = "govukpayment_revision",
 *   admin_permission = "administer govukpayment entity",
 *   fieldable = FALSE,
 *   handlers = {
 *     "access" = "Drupal\govuk_pay\GovUkPaymentAccessControlHandler",
 *     "list_builder" = "Drupal\Core\Entity\EntityListBuilder",
 *     "views_data" = "Drupal\govuk_pay\GovUkPaymentViewsData",
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
 *     "status" = "status",
 *     "amount" = "amount",
 *     "uid" = "uid",
 *     "payment_for" = "payment_for",
 *     "payment_reference" = "payment_reference",
 *     "owner" = "uid",
 *     "revision" = "vid",
 *     "revision_translation_affected" = "revision_translation_affected",
 *   },
 *   config_export = {
 *     "id",
 *     "payment_id",
 *     "uuid",
 *     "status",
 *     "amount",
 *     "payment_for",
 *     "payment_reference",
 *     "uid",
 *   },
 *   revision_metadata_keys = {
 *     "revision_user" = "revision_user",
 *     "revision_created" = "revision_created",
 *     "revision_log_message" = "revision_log_message"
 *   },
 *   links = {
 *     "canonical" = "/govuk-payment/{govukpayment}",
 *     "edit-form" = "/govuk-payment/{govukpayment}/edit",
 *     "delete-form" = "/govuk-payment/{govukpayment}/delete",
 *     "collection" = "/admin/govuk_pay/payments",
 *   },
 * )
 */
class GovUkPayment extends RevisionableContentEntityBase implements GovUkPaymentInterface {

  // Implements methods defined by EntityChangedInterface.
  use EntityChangedTrait;
  use EntityOwnerTrait;
  use RevisionLogEntityTrait;

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
      ->setDisplayConfigurable('view', TRUE)
      ->setRevisionable(TRUE);

    // Removed webform_id field to decouple govuk_pay from webform.
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
      ->setDisplayConfigurable('view', TRUE)
      ->setRevisionable(TRUE);

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
      ->setDisplayConfigurable('view', TRUE)
      ->setRevisionable(TRUE);

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
      ->setDisplayConfigurable('view', TRUE)
      ->setRevisionable(TRUE);

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
      ->setDisplayConfigurable('view', TRUE)
      ->setRevisionable(TRUE);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Created'))
      ->setDescription(t('The time that the entity was created.'))
      ->setRevisionable(TRUE);

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(t('Changed'))
      ->setDescription(t('The time that the entity was last edited.'))
      ->setRevisionable(TRUE);

    // Revision metadata fields.
    $fields['revision_created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Revision created'))
      ->setDescription(t('The time that the current revision was created.'))
      ->setRevisionable(TRUE);

    $fields['revision_user'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Revision user'))
      ->setDescription(t('The user ID of the author of the current revision.'))
      ->setSetting('target_type', 'user')
      ->setRevisionable(TRUE);

    $fields['revision_log_message'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Revision log message'))
      ->setDescription(t('Briefly describe the changes you have made.'))
      ->setRevisionable(TRUE)
      ->setDefaultValue('')
      ->setDisplayOptions('form', [
        'type' => 'string_textarea',
        'weight' => 25,
        'settings' => [
          'rows' => 4,
        ],
      ]);

    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public function getPaymentId() {
    return $this->get('payment_id')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getStatus() {
    return $this->get('status')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getAmount() {
    return [
      'value' => $this->get('amount')->value,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getPaymentReference() {
    return $this->get('payment_reference')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getPaymentFor() {
    return $this->get('payment_for')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getUrlInfo($rel = 'canonical') {
    return $this->toUrl($rel);
  }

}
