<?php

namespace Drupal\govuk_pay_webform\Controller;

use Drupal\webform\Entity\Webform;
use Drupal\govuk_pay\Entity\GovUkPayment;
use Drupal\Core\Controller\ControllerBase;

/**
 * Page controller for displaying GOV.UK Pay content on behalf of Webform.
 */
class GovPayWebformController extends ControllerBase {

  /**
   * Retrieve onsite record of a gov_payment.
   *
   * @param string $uuid
   *   UUID of payment.
   * @param int $webform_id
   *   Optional Webform ID of payment.
   * @param int $submission_id
   *   Optional Submission ID of payment.
   *
   * @return array
   *   Return loaded gov_payment entities.
   */
  private function fetchLocalPaymentRecord($uuid, $webform_id, $submission_id) {
    $return = [];

    // Initial Query.
    $query = \Drupal::entityQuery('content_entity_govukpayment');

    // Duplication of access controls(?)
    $query->condition('uuid', $uuid);
    $query->condition('webform_id', $webform_id);
    $query->condition('submission_id', $submission_id);
    $result = $query->execute();

    if (!empty($result)) {
      $return = GovUkPayment::loadMultiple($result);
    }

    return $return;
  }

  /**
   * Builds the Confirmation page.
   *
   * @param string $uuid
   *   UUID of the payment to load.
   * @param string $webform_id
   *   Optional Webform ID.
   * @param string $submission_id
   *   Optional Submission ID.
   *
   * @return array
   *   Render array.
   */
  public function confirmationPage($uuid, $webform_id = NULL, $submission_id = NULL) {
    // Base variables to return.
    $paymentId = NULL;
    $amount = NULL;
    $paymentStatus = NULL;
    $paymentMessage = NULL;
    $confirmationMessage = NULL;

    // Find GOV.UK Pay element.
    $webform = Webform::load($webform_id);
    $elements = $webform->getElementsInitialized();
    $govPayElement = NULL;
    foreach ($elements as $element) {
      if ($element['#type'] === 'webform_govuk_integrations_pay') {
        $govPayElement = $element;
        break;
      }
    }

    // Fetch GOV.UK Pay values out of element.
    $confirmationMessage = $govPayElement['#confirmation_message'] ?? NULL;

    // Provide default confirmation message if empty.
    if (is_null($confirmationMessage)) {
      $confirmationMessage = $this->t('
        Thank you for making a payment via GOV.UK Pay.<br/>
        If your payment has not shown as complete for over 1 day, 
        please contact us with your payment ID.
      ');
    }

    // Fetch on-site payment record.
    $fetchPayment = $this->fetchLocalPaymentRecord($uuid, $webform_id, $submission_id);

    // Ensure only 1 payment matches.
    if (count($fetchPayment) === 1) {

      // Fetch GOV.UK Pay payment.
      $paymentObject = $fetchPayment[array_keys($fetchPayment)[0]];
      $paymentId = $paymentObject->get('payment_id')->getValue()[0]['value'];

      $pay_client = \Drupal::service('govuk_integrations_pay.api_service');
      $api_record = $pay_client->getPayment($paymentId);

      // Set Status & Message.
      $paymentStatus = $api_record->state->status ??
        'Status not found.';
      $paymentMessage = $api_record->state->message ??
        '';
      $amount = isset($api_record->amount) ?
        '£' . number_format(floatval($api_record->amount) / 100, 2) :
        $this->t('Payment not found');
    }

    return [
      '#theme' => 'govuk_integrations_pay_webform__govuk_confirmation_page',
      '#payment_id' => $paymentId,
      '#payment_amount' => $amount,
      '#payment_status' => $paymentStatus,
      '#payment_message' => $paymentMessage,
      '#confirmation_message' => $confirmationMessage,
    ];
  }

}
