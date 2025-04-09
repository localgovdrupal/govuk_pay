<?php

namespace Drupal\govuk_pay_webform\Controller;

use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\webform\Entity\Webform;
use Drupal\govuk_pay_webform\GovUkPayWebformService;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Component\Render\FormattableMarkup;
use Drupal\Core\Utility\Token;

/**
 * Page controller for displaying GOV.UK Pay content on behalf of Webform.
 */
class GovPayWebformController extends ControllerBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

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
   * Constructs a new GovPayWebformController object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\govuk_pay_webform\GovUkPayWebformService $payment_service
   *   The GOV.UK Pay Webform service.
   * @param \Drupal\Core\Utility\Token $token
   *   The token service.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    GovUkPayWebformService $payment_service,
    Token $token,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->paymentService = $payment_service;
    $this->token = $token;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('govuk_pay_webform.payment_service'),
      $container->get('token')
    );
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
    // Default render array with empty values.
    $data = [
      '#theme' => 'govuk_pay_webform__govuk_confirmation_page',
      '#payment_id' => NULL,
      '#payment_amount' => NULL,
      '#payment_status' => NULL,
      '#payment_message' => NULL,
      '#payment_for' => NULL,
      '#payment_reference' => NULL,
      '#confirmation_message' => NULL,
    ];

    // Get the confirmation message from the GovPayHandler configuration.
    $webform = Webform::load($webform_id);
    $webform_submission = NULL;

    // Load the submission if available for token replacement.
    if ($submission_id) {
      $webform_submission = $this->entityTypeManager->getStorage('webform_submission')->load($submission_id);
    }

    if ($webform) {
      foreach ($webform->getHandlers() as $handler) {
        if ($handler->getPluginId() === 'govuk_pay') {
          $configuration = $handler->getConfiguration();

          // Get confirmation message.
          $confirmation_message = $configuration['settings']['confirmation_message'] ? $configuration['settings']['confirmation_message'] : NULL;
          if ($confirmation_message && $webform_submission) {
            // Replace tokens in the confirmation message.
            $token_data = [
              'webform' => $webform,
              'webform_submission' => $webform_submission,
            ];
            $confirmation_message = $this->token->replace($confirmation_message, $token_data);
          }

          if ($confirmation_message) {
            $data['#confirmation_message'] = new FormattableMarkup($confirmation_message, []);
          }
          break;
        }
      }
    }

    // Provide default confirmation message if empty.
    if (empty($data['#confirmation_message'])) {
      $default_message = $this->t('Thank you for making a payment via GOV.UK Pay.<br/>
        If your payment has not shown as complete for over 1 day, 
        please contact us with your payment ID.
      ');
      $data['#confirmation_message'] = new FormattableMarkup($default_message, []);
    }

    // Fetch payment details using the service.
    $payment_details = $this->paymentService->getPaymentDetails($uuid, $webform_id, $submission_id);

    // Set payment details in the render array.
    $data['#payment_id'] = $payment_details['payment_id'];
    $data['#payment_amount'] = $payment_details['amount'];
    $data['#payment_status'] = $payment_details['status'];
    $data['#payment_message'] = $payment_details['message'];
    $data['#payment_for'] = $payment_details['payment_for'];
    $data['#payment_reference'] = $payment_details['payment_reference'];

    return $data;
  }

}
