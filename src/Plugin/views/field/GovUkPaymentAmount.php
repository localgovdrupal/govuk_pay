<?php

namespace Drupal\govuk_pay\Plugin\views\field;

use Drupal\Core\Form\FormStateInterface;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;

/**
 * Field handler to convert payment amount from pence to pounds.
 *
 * @ingroup views_field_handlers
 *
 * @ViewsField("govuk_pay_payment_amount")
 */
class GovUkPaymentAmount extends FieldPluginBase {

  /**
   * {@inheritdoc}
   */
  public function usesGroupBy() {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  protected function defineOptions() {
    $options = parent::defineOptions();
    $options['currency_symbol'] = ['default' => '£'];
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function buildOptionsForm(&$form, FormStateInterface $form_state) {
    $form['currency_symbol'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Currency symbol'),
      '#description' => $this->t('Currency symbol to display before the amount.'),
      '#default_value' => $this->options['currency_symbol'],
    ];
    
    parent::buildOptionsForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function render(ResultRow $values) {
    $value = $this->getValue($values);
    
    // Convert from pence to pounds (divide by 100)
    $pounds = number_format($value / 100, 2);
    
    // Add currency symbol
    return $this->options['currency_symbol'] . $pounds;
  }

}
