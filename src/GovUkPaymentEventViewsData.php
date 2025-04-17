<?php

namespace Drupal\govuk_pay;

use Drupal\views\EntityViewsData;

/**
 * Provides Views data for GOV.UK Payment Event entities.
 */
class GovUkPaymentEventViewsData extends EntityViewsData {

  /**
   * {@inheritdoc}
   */
  public function getViewsData() {
    $data = parent::getViewsData();

    // Add a relationship to the payment entity.
    $data['govukpayment_event']['govukpayment_id']['relationship'] = [
      'base' => 'govukpayment',
      'base field' => 'id',
      'id' => 'standard',
      'label' => $this->t('Payment'),
      'title' => $this->t('Payment entity'),
      'help' => $this->t('The payment entity this event is associated with.'),
    ];

    // Add a field for the formatted event data.
    $data['govukpayment_event']['event_data_formatted'] = [
      'title' => $this->t('Event data (formatted)'),
      'help' => $this->t('Displays the event data in a formatted way.'),
      'field' => [
        'id' => 'govukpayment_event_data',
      ],
    ];

    return $data;
  }

}
