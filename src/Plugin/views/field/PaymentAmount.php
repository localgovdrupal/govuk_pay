<?php

namespace Drupal\govuk_pay\Plugin\views\field;

use Drupal\views\ResultRow;
use Drupal\views\Plugin\views\field\NumericField;

/**
 * Field handler to display payment amounts in GBP format.
 *
 * @ViewsField("govuk_pay_payment_amount")
 */
class PaymentAmount extends NumericField {

  /**
   * {@inheritdoc}
   */
  public function render(ResultRow $values) {
    $value = $this->getValue($values, $this->field);
    if (!is_numeric($value)) {
      return ['#markup' => ''];
    }
    $amount = $value / 100;
    return [
      '#markup' => '£' . number_format($amount, 2),
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function defineOptions() {
    $options = parent::defineOptions();
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function getValue(ResultRow $values, $field = NULL) {
    // Try to get from the raw result row using field_alias.
    if (!empty($this->field_alias) && isset($values->{$this->field_alias})) {
      return $values->{$this->field_alias};
    }

    // Try to get using the raw field name.
    if (isset($values->amount)) {
      return $values->amount;
    }

    return NULL;
  }

}
