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
   * The GOV.UK Pay Webform service.
   *
   * @var \Drupal\govuk_pay_webform\GovUkPayWebformService
   */
  protected $paymentService;

  /**
   * The token service.
   *
   * @var \Drupal\Core\Utility\Token
   */
  protected $token;

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
    $instance->paymentService = $container->get('govuk_pay_webform.payment_service');
    $instance->token = $container->get('token');
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
      'payment_for' => '',
      'payment_reference' => '',
      'confirmation_message' => '',
      'metadata' => [],
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
      '#title' => $this->t('Element'),
      '#description' => $this->t('Choose the element that will provide the amount for the payment.'),
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
      '#description' => $this->t('Messages to display to the user on the GOV.UK Pay page and confirmation page. Token can be used in any of these fields.'),
      '#parents' => ['settings'],
    ];

    $form['messages']['payment_for'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Payment for'),
      '#description' => $this->t('Text to display to the user on the GOV.UK Pay page. Will also show on the receipt email sent from the gateway prefixed with the label "Payment for:"'),
      '#default_value' => $this->configuration['payment_for'],
    ];

    $form['messages']['payment_reference'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Payment reference'),
      '#description' => $this->t('Will be displayed on the confirmation page and also in the email send from the gateway, prefixed with the label "Reference:"'),
      '#default_value' => $this->configuration['payment_reference'],
    ];

    $form['messages']['confirmation_message'] = [
      '#type' => 'webform_html_editor',
      '#title' => $this->t('Confirmation message'),
      '#description' => $this->t('Additional text to display to the user once they return to the site from GOV.UK Pay.'),
      '#default_value' => $this->configuration['confirmation_message'],
    ];

    // Add metadata field for key/value pairs.
    $form['metadata_container'] = [
      '#type' => 'details',
      '#title' => $this->t('Metadata'),
      '#description' => $this->t('Add optional metadata to be sent with the payment. Keys must be strings and values must be scalar (string, number, boolean).'),
      '#open' => TRUE,
    ];

    // Use Drupal's multiple values pattern.
    $form['metadata_container']['metadata'] = [
      '#type' => 'webform_multiple',
      '#title' => $this->t('Metadata key/value pairs'),
      '#description' => $this->t('Add optional metadata to be sent with the payment. Keys must be strings and values must be scalar (string, number, boolean). Tokens can also be used but must resolve to a scalar value.'),
      '#title_display' => 'invisible',
      '#default_value' => $this->configuration['metadata'] ?? [],
      '#add_more_text' => $this->t('Add another metadata item'),
      '#empty_items' => 1,
      '#no_items_message' => $this->t('No metadata has been added.'),
      '#element' => [
        'key' => [
          '#type' => 'textfield',
          '#title' => $this->t('Key'),
          '#placeholder' => $this->t('Enter metadata key'),
          '#maxlength' => 128,
        ],
        'value' => [
          '#type' => 'textfield',
          '#title' => $this->t('Value'),
          '#placeholder' => $this->t('Enter metadata value'),
          '#maxlength' => 255,
        ],
      ],
    ];

    // Add token help if module is available.
    if (\Drupal::moduleHandler()->moduleExists('token')) {
      $form['messages']['token_help'] = [
        '#type' => 'details',
        '#title' => $this->t('Available tokens'),
        '#description' => $this->t('Use these tokens to include submission data in the payment information.'),
        '#open' => FALSE,
      ];
      $form['messages']['token_help']['token_tree'] = [
        '#theme' => 'token_tree_link',
        '#token_types' => ['webform', 'webform_submission'],
      ];
    }

    return $form;
  }

  /**
   * Ajax callback for the metadata table.
   */
  public function metadataTableAjaxCallback(array &$form, FormStateInterface $form_state) {
    return $form['metadata_container']['metadata'];
  }

  /**
   * Submit handler for adding more metadata rows.
   */
  public function addMetadataCallback(array &$form, FormStateInterface $form_state) {
    $metadata = $form_state->get('metadata');
    $metadata[] = ['key' => '', 'value' => ''];
    $form_state->set('metadata', $metadata);
    $form_state->setRebuild();
  }

  /**
   * Submit handler for removing metadata rows.
   */
  public function removeMetadataCallback(array &$form, FormStateInterface $form_state) {
    $trigger = $form_state->getTriggeringElement();
    $delta = str_replace('remove_metadata_', '', $trigger['#name']);

    $metadata = $form_state->get('metadata');
    unset($metadata[$delta]);
    // Re-index the array.
    $metadata = array_values($metadata);
    $form_state->set('metadata', $metadata);
    $form_state->setRebuild();
  }

  /**
   * {@inheritDoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);

    $values = $form_state->getValues();

    // Get metadata values.
    if (isset($values['metadata_container']['metadata'])) {
      $this->configuration['metadata'] = $values['metadata_container']['metadata'];
    }

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
      $this->paymentService->createPayment($webform_submission, $this->configuration);
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
