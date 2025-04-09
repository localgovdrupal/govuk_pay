<?php

namespace Drupal\govuk_pay_webform\Plugin\WebformHandler;

use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\webform\WebformSubmissionInterface;
use Drupal\webform\Plugin\WebformHandlerBase;
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
   * The logger service.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->configFactory = $container->get('config.factory');
    $instance->apiService = $container->get('govuk_pay.api_service');
    $instance->uuidService = $container->get('uuid');
    $instance->requestStack = $container->get('request_stack');
    $instance->logger = $container->get('logger.factory')->get('govuk_pay_webform');
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
   *   The payment amount in pence.
   */
  protected function getAmount(WebformSubmissionInterface $webform_submission) {
    $amount = 0;

    switch ($this->configuration['amount_provider']) {
      case 'element':
        $element_name = $this->configuration['amount_element'];
        if (!empty($element_name)) {
          $value = $webform_submission->getElementData($element_name);
          if (is_numeric($value)) {
            // Convert to pence (multiply by 100 and ensure integer).
            $amount = (int) (floatval($value) * 100);
          }
        }
        break;

      case 'static':
        if (!empty($this->configuration['amount_static']) && is_numeric($this->configuration['amount_static'])) {
          // Convert to pence (multiply by 100 and ensure integer).
          $amount = (int) (floatval($this->configuration['amount_static']) * 100);
        }
        break;
    }
    return $amount;
  }

  /**
   * {@inheritDoc}
   */
  public function postSave(WebformSubmissionInterface $webform_submission, $update = TRUE) {
    try {
      /** @var \Drupal\govuk_pay_webform\GovUkPayWebformService $payment_service */
      $payment_service = \Drupal::service('govuk_pay_webform.payment_service');
      $payment_service->createPayment($webform_submission, $this->configuration);
    }
    catch (\Exception $e) {
      // Log the error with appropriate context.
      $context = [
        '@error' => $e->getMessage(),
        '@webform' => $webform_submission->getWebform()->id(),
        '@submission' => $webform_submission->id(),
      ];
      if ($e instanceof \RuntimeException) {
        // Configuration or validation errors.
        $this->logger->error('Configuration error: @error', $context);
      }
      else {
        // API or other errors.
        $this->logger->error('Payment creation failed: @error for webform @webform submission @submission', $context);
      }
      throw $e;
    }
  }

}
