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
      'fields' => [
        'amount' => '',
        'name' => '',
        'email' => '',
        'address' => [
          'line1' => '',
          'line2' => '',
          'postcode' => '',
          'city' => '',
          'country' => 'GB',
        ],
      ],
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

    $form['fields'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Payment Fields'),
      '#description' => $this->t('Configure fields that will be sent to the GOV.UK Pay gateway.'),
      '#parents' => ['settings', 'fields'],
    ];

    $form['fields']['amount'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Amount'),
      '#description' => $this->t('Enter the amount for the payment. You can use tokens to map webform fields (e.g., [webform_submission:values:your_field_name]). The value will be converted to pence.'),
      '#default_value' => $this->configuration['fields']['amount'] ?? '',
      '#required' => TRUE,
    ];

    $form['fields']['email'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Email'),
      '#description' => $this->t('Enter a token to map to the customer email field (e.g., [webform_submission:values:email]). This will pre-fill the email field on the payment page.'),
      '#default_value' => $this->configuration['fields']['email'] ?? '',
    ];

    $form['fields']['name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Cardholder Name'),
      '#description' => $this->t('Enter a token to map to the cardholder name field (e.g., [webform_submission:values:name]). This will pre-fill the cardholder name on the payment page.'),
      '#default_value' => $this->configuration['fields']['name'] ?? '',
    ];

    $form['fields']['address'] = [
      '#type' => 'details',
      '#title' => $this->t('Billing Address'),
      '#description' => $this->t('Configure the billing address fields that will be pre-filled on the payment page.'),
      '#open' => TRUE,
    ];

    $form['fields']['address']['line1'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Address Line 1'),
      '#description' => $this->t('Enter a token to map to the address line 1 field (e.g., [webform_submission:values:address_line1]).'),
      '#default_value' => $this->configuration['fields']['address']['line1'] ?? '',
    ];

    $form['fields']['address']['line2'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Address Line 2'),
      '#description' => $this->t('Enter a token to map to the address line 2 field (e.g., [webform_submission:values:address_line2]).'),
      '#default_value' => $this->configuration['fields']['address']['line2'] ?? '',
    ];

    $form['fields']['address']['postcode'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Postcode'),
      '#description' => $this->t('Enter a token to map to the postcode field (e.g., [webform_submission:values:postcode]).'),
      '#default_value' => $this->configuration['fields']['address']['postcode'] ?? '',
    ];

    $form['fields']['address']['city'] = [
      '#type' => 'textfield',
      '#title' => $this->t('City'),
      '#description' => $this->t('Enter a token to map to the city field (e.g., [webform_submission:values:city]).'),
      '#default_value' => $this->configuration['fields']['address']['city'] ?? '',
    ];

    $form['fields']['address']['country'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Country'),
      '#description' => $this->t('Enter a token to map to the country field or a two-letter country code (e.g., GB for United Kingdom).'),
      '#default_value' => $this->configuration['fields']['address']['country'] ?? 'GB',
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

    // Get the amount from the configuration.
    $amount_value = $this->configuration['fields']['amount'] ?? '';

    // Process tokens in the amount value.
    if (!empty($amount_value)) {
      $processed_amount = $this->token->replace($amount_value, [
        'webform' => $webform_submission->getWebform(),
        'webform_submission' => $webform_submission,
      ]);

      if (is_numeric($processed_amount)) {
        // Convert to pence (multiply by 100 and ensure integer).
        $amount = (int) (floatval($processed_amount) * 100);
      }
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
