<?php

namespace Drupal\govuk_pay\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\ConfigFormBase;

/**
 * Settings form for GOV.UK pay.
 */
class GovUkPayGeneralSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'govuk_pay_general_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['govuk_pay.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('govuk_pay.settings');

    $form['settings'] = [
      '#type' => 'fieldset',
      '#title' => 'Settings',
    ];

    $form['settings']['gov_pay__apikey'] = [
      '#title' => 'Active API key',
      '#required' => TRUE,
      '#type' => 'textfield',
      '#default_value' => $config->get('gov_pay__apikey'),
      '#description' => $this->t('The API key used for interacting with GOV.UK Pay.'),
    ];

    $form['settings']['gov_pay__reference'] = [
      '#title' => 'Payment reference',
      '#required' => TRUE,
      '#type' => 'textfield',
      '#default_value' => $config->get('gov_pay__reference'),
      '#description' => $this->t('The payment reference assigned to all GOV.UK Pay transactions on this site.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->config('govuk_pay.settings');

    $config
      ->set('gov_pay__apikey', $form_state->getValue('gov_pay__apikey'))
      ->set('gov_pay__reference', $form_state->getValue('gov_pay__reference'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
