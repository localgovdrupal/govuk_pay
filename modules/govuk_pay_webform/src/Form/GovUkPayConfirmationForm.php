<?php

namespace Drupal\govuk_pay_webform\Form;

use Drupal\webform\Entity\Webform;
use Drupal\govuk_pay\Controller\GovPayController;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\FormBase;

/**
 * Form to display payment confirmation status.
 */
class GovUkPayConfirmationForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'govuk_pay_confirmation';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $request = \Drupal::request();
    $uuid = $request->get('uuid');
    $webform_id = $request->get('webform_id');

    $confirmationMessage = NULL;
    $amount = NULL;
    $status = NULL;

    // Find GOV.UK Pay element.
    $webform = Webform::load($webform_id);
    $elements = $webform->getElementsInitialized();
    $govPayElement = NULL;
    foreach ($elements as $element) {
      if ($element['#type'] === 'webform_govuk_pay') {
        $govPayElement = $element;
        break;
      }
    }

    // Fetch GOV.UK Pay values out of element.
    $amount = isset($govPayElement['#amount']) && $govPayElement['#amount'] ? $govPayElement['#amount'] : 0;
    $confirmationMessage = $govPayElement['#confirmation_message'] ?? NULL;

    // Provide default confirmation message.
    if (is_null($confirmationMessage)) {
      $confirmationMessage = '
        <div class="infobox infobox-info">
          <div class="icon fa fa-info"></div>
          <div class="content">
            Thank you for making a payment via GOV.UK Pay.</br>
            If your payment has not shown as complete for over 1 day, 
            please contact us with your payment ID.
          </div>
        </div>
      ';
    }

    // Fetch on-site payment record.
    $govPay = new GovPayController();
    $fetchPayment = $govPay->fetchGovPayment($uuid);
    $paymentStatus = 'Status not found.';

    // Ensure only 1 payment matches.
    if (count($fetchPayment) === 1) {
      // Allow alterations to the information before sending.
      \Drupal::moduleHandler()->alter('govuk_pay_confirmation', $fetchPayment);

      // Fetch GOV.UK Pay payment.
      $paymentObject = $fetchPayment[array_keys($fetchPayment)[0]];
      $paymentId = $paymentObject->get('payment_id')->getValue()[0]['value'];
      $getPayment = $govPay->getPayment($paymentId);

      // Set Status & Message.
      $paymentStatus = $getPayment->state->status ??
        'Status not found.';
      $paymentMessage = $getPayment->state->message ??
        '';
      $amount = isset($getPayment->amount) ?
        '£' . number_format(floatval($getPayment->amount) / 100, 2) :
        'Payment not found';

      $status = "
        <div class='payment-status'>
          <strong>$paymentStatus</strong>
        </div>
        <div class='payment-message'>
          $paymentMessage
        </div>
      ";
    }
    // Container.
    $form['container'] = [
      '#type' => 'container',
      '#prefix' => "<div class='gov-pay-container'>",
      '#suffix' => "</div>",
    ];

    $form['container']['message'] = [
      '#type' => 'markup',
      '#prefix' => "<div class='gov-pay-confirmation-message'>",
      '#suffix' => "</div>",
      '#markup' => $confirmationMessage,
    ];

    $form['container']['paymentID'] = [
      '#type' => 'markup',
      '#prefix' => "<div class='gov-pay-paymentID'>",
      '#suffix' => "</div>",
      '#markup' => "<b>Payment ID:</b> $uuid",
    ];

    // Container -> Payment.
    $form['container']['payment'] = [
      '#type' => 'container',
      '#prefix' => "<div class='gov-pay-payment'>",
      '#suffix' => "</div>",
    ];

    $form['container']['payment']['payment_amount'] = [
      '#type' => 'markup',
      '#prefix' => "<div class='gov-pay-payment-amount'>",
      '#suffix' => "</div>",
      '#markup' => "<label>Amount:</label><div class='amount'>$amount</div>",
    ];

    $form['container']['payment']['status'] = [
      '#type' => 'markup',
      '#markup' => "$status",
      '#prefix' => '<div id="gov-pay-payment-status" class="alert-' . $paymentStatus . '">',
      '#suffix' => '</div>',
    ];

    // Container -> Actions.
    $form['container']['actions'] = [
      '#type' => 'container',
      '#prefix' => "<div class='gov-pay-actions'>",
      '#suffix' => "</div>",
    ];

    $form['container']['actions']['refresh'] = [
      '#type' => 'button',
      '#value' => 'Refresh status',
      '#submit' => [],
      '#attributes' => [
        'class' => ['button btn btn-default'],
      ],
      '#ajax' => [
        'callback' => '::GovPayRefresh',
        'wrapper' => 'gov-pay-payment-status',
        'method' => 'replace',
        'effect' => 'fade',
      ],
    ];

    $form['container']['actions']['back'] = [
      '#type' => 'submit',
      '#value' => 'Back to site',
      '#attributes' => [
        'class' => ['button btn btn-default'],
      ],
      '#submit' => ['::backToSite'],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {}

  /**
   * Redirects user to front page.
   *
   * @param array $form
   *   Form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   */
  public function backToSite(array $form, FormStateInterface $form_state) {
    $form_state->setRedirect('<front>');
  }

  /**
   * Refreshes the form to get the live GOV.UK Pay status.
   *
   * @param array $form
   *   Form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   *
   * @return mixed
   *   Resultant status string.
   */
  public function govPayRefresh(array $form, FormStateInterface $form_state) {
    $form_state->setRebuild(TRUE);
    return $form["container"]["payment"]["status"];
  }

}
