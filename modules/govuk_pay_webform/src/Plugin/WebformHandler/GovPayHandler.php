<?php

namespace Drupal\govuk_pay_webform\Plugin\WebformHandler;

use Symfony\Component\DependencyInjection\ContainerInterface;
use GuzzleHttp\Psr7\Uri;
use Drupal\webform\WebformSubmissionInterface;
use Drupal\webform\Plugin\WebformHandlerBase;
use Drupal\govuk_pay\Entity\GovUkPayment;
use Drupal\Core\Url;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\Form\FormStateInterface;

/**
 * GOV.UK Pay webform handler.
 *
 * Provides a webform handler to redirect to GOV.UK Pay to handle payment.
 *
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
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The GOV.UK Pay API service.
   *
   * @var \Drupal\govuk_pay\ApiService
   */
  protected $apiService;

  /**
   * The UUID service.
   *
   * @var \Drupal\Core\Uuid\UuidInterface
   */
  protected $uuidService;

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->configFactory = $container->get('config.factory');
    $instance->apiService = $container->get('govuk_pay.api_service');
    $instance->uuidService = $container->get('uuid');
    $instance->requestStack = $container->get('request_stack');
    return $instance;
  }

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
        'type' => 'number',
        'min' => 1,
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
      '#type' => 'textfield',
      '#title' => $this->t('Payment message'),
      '#description' => $this->t('Text to display to the user on the GOV.UK Pay page.'),
      '#default_value' => $this->configuration['payment_message'],
    ];

    $form['messages']['confirmation_message'] = [
      '#type' => 'webform_html_editor',
      '#title' => $this->t('Confirmation message'),
      '#description' => $this->t('Text to display to the user once they return to the site from GOV.UK Pay.'),
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
    try {
      // Load config for GOV.UK Pay.
      $config = $this->configFactory->get('govuk_pay.settings');

      // Validate required parameters.
      if (empty($config->get('gov_pay__reference'))) {
        throw new \RuntimeException('GOV.UK Pay reference is not configured. This is a required field on /admin/config/govuk_pay/settings');
      }
      if (empty($config->get('gov_pay__apikey'))) {
        throw new \RuntimeException('GOV.UK Pay API key is not configured. This is a required field on /admin/config/govuk_pay/settings');
      }

      $sid = $webform_submission->id();
      $webform = $webform_submission->getWebform();
      $amount = $this->getAmount($webform_submission);

      if (empty($amount)) {
        throw new \RuntimeException('Payment amount could not be determined');
      }

      $message = !empty($this->configuration['payment_message']) ? $this->configuration['payment_message'] : $webform->label();
      if (strlen($message) > 254) {
        $message = substr($message, 0, 251) . '...';
      }

      $uuid = $this->uuidService->generate();
      $route_params = [
        'uuid' => $uuid,
        'webform_id' => $webform->id(),
        'submission_id' => $sid,
      ];

      $url_object = Url::fromRoute('govuk_pay_webform.confirmation_page', $route_params, ['absolute' => TRUE]);
      $returnUrl = new Uri($url_object->toString());

      try {
        $payment_response = $this->apiService->createPayment(
          $amount,
          $config->get('gov_pay__reference'),
          $message,
          $returnUrl
        );

        $payment = GovUkPayment::create([
          'payment_id' => $payment_response->getPaymentId(),
          'amount' => $amount,
          'uuid' => $uuid,
          'status' => $payment_response->getState()->getStatus(),
          'webform_id' => $webform->id(),
          'submission_id' => $sid,
        ]);
        $payment->save();

        $links = $payment_response->getLinks();
        $nextUrl = isset($links['next_url']) ? $links['next_url']->getHref() : NULL;

        if (!is_null($nextUrl)) {
          $response = new TrustedRedirectResponse($nextUrl, 302);
          $request = $this->requestStack->getCurrentRequest();
          $request->getSession()->save();
          $response->prepare($request);
          $response->send();
          exit;
        }
      }
      catch (\Exception $e) {
        \Drupal::logger('govuk_pay_webform')->error('Payment creation failed: @error', ['@error' => $e->getMessage()]);
        throw new \RuntimeException('Payment could not be created: ' . $e->getMessage(), 0, $e);
      }
    }
    catch (\Exception $e) {
      \Drupal::logger('govuk_pay_webform')->error('Error in GovPayHandler postSave: @error', ['@error' => $e->getMessage()]);
      throw $e;
    }
  }

}
