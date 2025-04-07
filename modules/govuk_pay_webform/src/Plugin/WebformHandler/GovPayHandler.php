<?php

namespace Drupal\govuk_pay_webform\Plugin\WebformHandler;

use GuzzleHttp\Psr7\Uri;
use Drupal\webform\WebformSubmissionInterface;
use Drupal\webform\Plugin\WebformHandlerBase;
use Drupal\govuk_pay\Entity\GovUkPayment;
use Drupal\Core\Url;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\Form\FormStateInterface;

/**
 * @WebformHandler(
 *   id = "govuk_pay",
 *   label = @Translation("GOV.UK Pay"),
 *   category = @Translation("Payments"),
 *   description = @Translation("Redirect to GOV.UK Pay to handle payment"),
 *   cardinality = \Drupal\webform\Plugin\WebformHandlerInterface::CARDINALITY_SINGLE,
 *   results = \Drupal\webform\Plugin\WebformHandlerInterface::RESULTS_IGNORED,
 *   submission = \Drupal\webform\Plugin\WebformHandlerInterface::SUBMISSION_OPTIONAL,
 *   )
 */
class GovPayHandler extends WebformHandlerBase {

  /**
   * {@inheritDoc}
   */
  public function defaultConfiguration() {
    return [
      'wrapper_attributes' => [],
        // Component settings.
      'amount_provider' => '',
      'amount_element' => '',
      'amount_static' => '',
      'default_markup' => '',
      'payment_message' => '',
      'confirmation_message' => '',
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritDoc}
   */
  protected function valueElements() {
    // Define which element types are allowed to be set as a mapped element
    // to the GOV.UK Pay element.
    $allowed_element_types = [
      'hidden',
      'number',
      'radios',
      'select',
      'value',
      'webform_computed_token',
      'webform_computed_twig',
      'webform_radios_other',
      'webform_select_other',
    ];

    // Fetch the current Webform elements to choose from.
    $elements = $this->webform->getElementsInitialized();

    // Add any valid elements to the option list.
    $webform_element_list = [];
    foreach ($elements as $element_name => $element_array) {
      if (in_array($element_array['#type'], $allowed_element_types)) {
        $webform_element_list[$element_name] = $element_array['#title'];
      }
    }

    return $webform_element_list;
  }

  /**
   * {@inheritDoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {

    $form['amount'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Payment Amount'),
      '#parents' => ['settings'],
    ];

    $form['amount']['amount_provider'] = [
      '#type' => 'radios',
      '#required' => TRUE,
      '#title' => $this->t('Amount source'),
      '#description' => $this->t('Choose how the amount of the GOV.UK Payment will be provided.'),
      '#default_value' => $this->configuration['amount_provider'],
      '#options' => [
        'element' => $this->t('Webform element'),
        'static' => $this->t('Fixed amount'),
      ],
    ];

    $amount_provider_element_key = ':input[name="settings[amount_provider]"]';

    $form['amount']['amount_element'] = [
      '#type' => 'select',
      '#title' => $this->t('Amount element'),
      '#description' => $this->t('Choose which webform element will provide the value to GOV.UK Pay.'),
      '#default_value' => $this->configuration['amount_element'],
      '#options' => $this->valueElements(),
      '#states' => [
        'visible' => [
          $amount_provider_element_key => ['value' => 'element'],
        ],
        'required' => [
          $amount_provider_element_key => ['value' => 'element'],
        ],
      ],
    ];
    $form['amount']['amount_static'] = [
      '#type' => 'textfield',
      '#attributes' => [
        ' type' => 'number',
        ' min' => 1,
      ],
      '#title' => $this->t('Amount'),
      '#description' => $this->t('Choose the amount that a payment will be made for.'),
      '#default_value' => $this->configuration['amount_static'],
      '#states' => [
        'visible' => [
          $amount_provider_element_key => ['value' => 'static'],
        ],
        'required' => [
          $amount_provider_element_key => ['value' => 'static'],
        ],
      ],
    ];

    $form['messages'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Messages'),
      '#parents' => ['settings'],
    ];
    $form['messages']['payment_message'] = [
      '#type' => 'textarea',
      '#title' => $this->t('GOV.UK Pay summary'),
      '#description' => $this->t('Text to display to user once they are redirected to GOV.UK Pay.'),
      '#maxlength' => 255,
      '#default_value' => $this->configuration['payment_message'],
    ];
    $form['messages']['confirmation_message'] = [
      '#type' => 'webform_html_editor',
      '#title' => $this->t('Confirmation message'),
      '#description' => $this->t('Text to display to user once they return to the site from GOV.UK Pay.'),
      '#default_value' => $this->configuration['confirmation_message'],
    ];

    return $form;
  }

  /**
   * {@inheritDoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);

    $values = $form_state->getValues();

    foreach ($this->configuration as $name => $value) {
      if (isset($values[$name])) {
        $this->configuration[$name] = $values[$name];
      }
    }

  }

  /**
   * Helper function to determine payment amount.
   *
   * @param \Drupal\webform\WebformSubmissionInterface $webform_submission
   *   The webform submission.
   *
   * @return int
   *   The payment amount.
   */
  protected function getAmount(WebformSubmissionInterface $webform_submission) {

    // Determine GOV.UK Pay amount from the element amount provider.
    $amount = NULL;
    switch ($this->configuration['amount_provider']) {
      case 'element':
        $value = $webform_submission->getElementData($this->configuration['amount_element']);
        $amount = intval($value) * 100;
        break;

      case 'static':
        $amount = intval($this->configuration['amount_static']) * 100;
        break;
    }

    return $amount;
  }

  /**
   * {@inheritDoc}
   */
  public function postSave(WebformSubmissionInterface $webform_submission, $update = TRUE) {

    // Load config for GOV.UK Pay.
    $config = \Drupal::config('govuk_integrations_pay.settings');

    // Fetch Submission ID from submission.
    $sid = $webform_submission->id();
    $webform = $webform_submission->getWebform();

    $amount = $this->getAmount($webform_submission);

    // Fetch payment message from element.
    $message = $this->configuration['payment_message'] ?? $webform->label();

    // Reduce message length (GOV.UK Pay accepts 255 characters max).
    if (strlen($message) > 254) {
      $message = substr($message, 0, 251) . '...';
    }

    // Generate UUID.
    $uuidService = \Drupal::service('uuid');
    $uuid = $uuidService->generate();

    $route_params = [
      'uuid' => $uuid,
      'webform_id' => $webform->id(),
      'submission_id' => $sid,
    ];

    $url_object = Url::fromRoute('govuk_integrations_pay_webform.confirmation_page', $route_params, ['absolute' => TRUE]);
    $returnUrl = new Uri($url_object->toString());

    $pay_client = \Drupal::service('govuk_integrations_pay.api_service');

    /** @var \Alphagov\Pay\Response\Payment $payment_response */
    $payment_response = $pay_client->createPayment($amount, $config->get('gov_pay__reference'), $message, $returnUrl);

    // Setup entity record.
    $payment = GovUkPayment::create([
      'payment_id' => $payment_response->payment_id,
      'amount' => $amount,
      'uuid' => $uuid,
      'status' => $payment_response->state->status,
      'webform_id' => $webform->id(),
      'submission_id' => $sid,
    ]);
    $payment->save();

    // Setup redirect to GOV.UK Pay.
    $nextUrl = $payment_response->getPaymentPageUrl();

    if (!is_null($nextUrl)) {

      // Redirect code, cribbed from RemotePostWebformHandler()
      $response = new TrustedRedirectResponse((string) $nextUrl, 302);
      $request = $this->requestStack->getCurrentRequest();
      // Save the session so things like messages get saved.
      $request->getSession()->save();
      $response->prepare($request);
      // Make sure to trigger kernel events.
      $this->kernel->terminate($request, $response);
      $response->send();
    }
  }

  /**
   * {@inheritDoc}
   */
  public function confirmForm(array &$form, FormStateInterface $form_state, WebformSubmissionInterface $webform_submission) {
    // @todo Change the autogenerated stub.
    parent::confirmForm($form, $form_state, $webform_submission);

    // https://drupal.stackexchange.com/questions/245285/page-redirect-in-custom-webformhandlerbase
    // $form_state->setRedirectUrl();
  }

}
