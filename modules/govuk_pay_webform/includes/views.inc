<?php

/**
 * @file
 * Functions to customize views for GOV.UK Pay Webform integration.
 */

/**
 * Updates the govuk_pay_payments view with webform-specific elements.
 *
 * This function adds webform-specific fields, relationships, and actions
 * to the GOV.UK Pay payments view.
 */
function govuk_pay_webform_update_payments_view() {
  // Load the view configuration.
  \Drupal::service('plugin.manager.views.display')->clearCachedDefinitions();

  $config_factory = \Drupal::configFactory();
  $view_config = $config_factory->getEditable('views.view.govuk_pay_payments');

  if ($view_config && !$view_config->isNew()) {
    // Update dependencies to include webform and this module.
    $dependencies = $view_config->get('dependencies.module') ?: [];
    if (!in_array('govuk_pay_webform', $dependencies)) {
      $dependencies[] = 'govuk_pay_webform';
    }
    if (!in_array('webform', $dependencies)) {
      $dependencies[] = 'webform';
    }
    $view_config->set('dependencies.module', $dependencies);

    // Add submission_id relationship.
    $relationships = $view_config->get('display.default.display_options.relationships') ?: [];
    if (!isset($relationships['submission_id'])) {
      $relationships['submission_id'] = [
        'id' => 'submission_id',
        'table' => 'govukpayment_revision',
        'field' => 'submission_id',
        'relationship' => 'none',
        'group_type' => 'group',
        'admin_label' => 'Webform submission',
        'entity_type' => 'govukpayment',
        'entity_field' => 'submission_id',
        'plugin_id' => 'standard',
        'required' => FALSE,
      ];
      $view_config->set('display.default.display_options.relationships', $relationships);
    }

    // Add view_webform_submission field.
    $fields = $view_config->get('display.default.display_options.fields') ?: [];

    if (!isset($fields['view_webform_submission'])) {
      $fields['view_webform_submission'] = [
        'id' => 'view_webform_submission',
        'table' => 'webform_submission',
        'field' => 'view_webform_submission',
        'relationship' => 'submission_id',
        'group_type' => 'group',
        'admin_label' => '',
        'entity_type' => 'webform_submission',
        'plugin_id' => 'entity_link',
        'label' => 'Link to Webform submission',
        'exclude' => TRUE,
        'alter' => [
          'alter_text' => FALSE,
          'text' => '',
          'make_link' => FALSE,
          'path' => '',
          'absolute' => FALSE,
          'external' => FALSE,
          'replace_spaces' => FALSE,
          'path_case' => 'none',
          'trim_whitespace' => FALSE,
          'alt' => '',
          'rel' => '',
          'link_class' => '',
          'prefix' => '',
          'suffix' => '',
          'target' => '',
          'nl2br' => FALSE,
          'max_length' => 0,
          'word_boundary' => TRUE,
          'ellipsis' => TRUE,
          'more_link' => FALSE,
          'more_link_text' => '',
          'more_link_path' => '',
          'strip_tags' => FALSE,
          'trim' => FALSE,
          'preserve_tags' => '',
          'html' => FALSE,
        ],
        'element_type' => '',
        'element_class' => '',
        'element_label_type' => '',
        'element_label_class' => '',
        'element_label_colon' => TRUE,
        'element_wrapper_type' => '',
        'element_wrapper_class' => '',
        'element_default_classes' => TRUE,
        'empty' => '',
        'hide_empty' => FALSE,
        'empty_zero' => FALSE,
        'hide_alter_empty' => TRUE,
        'text' => 'View submission',
        'output_url_as_text' => FALSE,
        'absolute' => FALSE,
      ];
      $view_config->set('display.default.display_options.fields.view_webform_submission', $fields['view_webform_submission']);
    }

    // Ensure view_govukpayment field has exclude=TRUE.
    // First check if the field exists in the configuration.
    $view_govukpayment = $view_config->get('display.default.display_options.fields.view_govukpayment');
    if ($view_govukpayment) {
      // Field exists, update its exclude property.
      $view_govukpayment['exclude'] = TRUE;
      $view_config->set(
        'display.default.display_options.fields.view_govukpayment',
        $view_govukpayment
      );
    }

    // Add dropbutton field.
    if (!isset($fields['dropbutton'])) {
      $fields['dropbutton'] = [
        'id' => 'dropbutton',
        'table' => 'views',
        'field' => 'dropbutton',
        'relationship' => 'none',
        'group_type' => 'group',
        'admin_label' => '',
        'plugin_id' => 'dropbutton',
        'label' => 'Actions',
        'exclude' => FALSE,
        'alter' => [
          'alter_text' => FALSE,
          'text' => '',
          'make_link' => FALSE,
          'path' => '',
          'absolute' => FALSE,
          'external' => FALSE,
          'replace_spaces' => FALSE,
          'path_case' => 'none',
          'trim_whitespace' => FALSE,
          'alt' => '',
          'rel' => '',
          'link_class' => '',
          'prefix' => '',
          'suffix' => '',
          'target' => '',
          'nl2br' => FALSE,
          'max_length' => 0,
          'word_boundary' => TRUE,
          'ellipsis' => TRUE,
          'more_link' => FALSE,
          'more_link_text' => '',
          'more_link_path' => '',
          'strip_tags' => FALSE,
          'trim' => FALSE,
          'preserve_tags' => '',
          'html' => FALSE,
        ],
        'element_type' => '',
        'element_class' => '',
        'element_label_type' => '',
        'element_label_class' => '',
        'element_label_colon' => TRUE,
        'element_wrapper_type' => '',
        'element_wrapper_class' => '',
        'element_default_classes' => TRUE,
        'empty' => '',
        'hide_empty' => FALSE,
        'empty_zero' => FALSE,
        'hide_alter_empty' => TRUE,
        'destination' => TRUE,
        'fields' => [
          'view_webform_submission' => 'view_webform_submission',
          'view_govukpayment' => 'view_govukpayment',
          'created' => '0',
          'amount' => '0',
          'payment_for' => '0',
          'payment_id' => '0',
          'payment_reference' => '0',
        ],
      ];
      $view_config->set('display.default.display_options.fields.dropbutton', $fields['dropbutton']);
    }

    // Save the updated configuration.
    $view_config->save();

    // Clear views cache to ensure changes take effect.
    \Drupal::service('plugin.manager.views.display')->clearCachedDefinitions();
    \Drupal::service('router.builder')->rebuild();

    // Log a message about the update.
    \Drupal::logger('govuk_pay_webform')->notice('Updated GOV.UK Pay payments view with webform integration.');
  }
}
