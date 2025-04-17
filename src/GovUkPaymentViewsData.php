<?php

namespace Drupal\govuk_pay;

use Drupal\views\EntityViewsData;

/**
 * Provides the views data for the GOV.UK Payment entity.
 */
class GovUkPaymentViewsData extends EntityViewsData {

  /**
   * {@inheritdoc}
   */
  public function getViewsData() {
    $data = parent::getViewsData();

    // Group label.
    $data['govukpayment']['table']['group'] = $this->t('GOV.UK Payment');

    // Status field with filter.
    $data['govukpayment']['status'] = [
      'title' => $this->t('Payment status'),
      'help' => $this->t('The payment status.'),
      'field' => [
        'id' => 'string',
      ],
      'filter' => [
        'id' => 'string',
      ],
      'sort' => [
        'id' => 'standard',
      ],
    ];

    // Amount field with GBP formatting (converts pence to pounds)
    $data['govukpayment']['amount'] = [
      'title' => $this->t('Payment amount'),
      'help' => $this->t('The payment amount in GBP format (converted from pence).'),
      'field' => [
        'id' => 'govuk_pay_payment_amount',
        'click sortable' => TRUE,
      ],
      'filter' => [
        'id' => 'numeric',
      ],
      'sort' => [
        'id' => 'standard',
      ],
    ];

    // Operations links.
    $data['govukpayment']['operations'] = [
      'field' => [
        'title' => $this->t('Operations links'),
        'help' => $this->t('Provides links to perform operations on the payment.'),
        'id' => 'entity_operations',
      ],
    ];

    // Bulk operations.
    $data['govukpayment']['bulk_form'] = [
      'title' => $this->t('Bulk operations'),
      'help' => $this->t('Form element to perform operations on multiple payments.'),
      'field' => [
        'id' => 'bulk_form',
      ],
    ];

    return $data;
  }

}
