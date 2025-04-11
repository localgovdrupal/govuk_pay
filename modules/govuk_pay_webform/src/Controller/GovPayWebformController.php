<?php

namespace Drupal\govuk_pay_webform\Controller;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Psr\Log\LoggerInterface;
use Drupal\webform\Entity\Webform;
use Drupal\govuk_pay_webform\GovUkPayWebformService;
use Drupal\Core\Utility\Token;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Component\Render\FormattableMarkup;

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
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * The messenger.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

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
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   * @param \Drupal\Core\Utility\Token $token
   *   The token service.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    GovUkPayWebformService $payment_service,
    RequestStack $request_stack,
    MessengerInterface $messenger,
    LoggerInterface $logger,
    Token $token,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->paymentService = $payment_service;
    $this->requestStack = $request_stack;
    $this->messenger = $messenger;
    $this->logger = $logger;
    $this->token = $token;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
    $container->get('entity_type.manager'),
    $container->get('govuk_pay_webform.payment_service'),
    $container->get('request_stack'),
    $container->get('messenger'),
    $container->get('logger.factory')->get('govuk_pay_webform'),
    $container->get('token')
    );
  }

  /**
   * Builds the Confirmation page.
   *
   * @return array
   *   Render array.
   */
  public function confirmationPage() {
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

    // Get payment details from the session.
    $payment_data = $this->paymentService->getPaymentData();

    if (empty($payment_data)) {
      $this->messenger->addError($this->t('Payment information not found. The payment session may have expired.'));
      return $data;
    }

    try {
      if (!isset($payment_data['uuid']) || !isset($payment_data['webform_id']) || !isset($payment_data['submission_id'])) {
        $this->messenger->addError($this->t('Invalid payment data. Please try again or contact support.'));
        return $data;
      }

      $uuid = $payment_data['uuid'];
      $webform_id = $payment_data['webform_id'];
      $submission_id = $payment_data['submission_id'];

      // Get payment details from the session.
      $payment_data = $this->paymentService->getPaymentData();

      if (empty($payment_data)) {
        $this->messenger->addError($this->t('Payment information not found. The payment session may have expired.'));
        return $data;
      }

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
        $data['#cache']['contexts'][] = 'session';

        // Update Payment entity with status from payment details.
        if (!empty($payment_details['payment_id']) && !empty($payment_details['status'])) {
          try {
            // Load the payment entity by UUID.
            $payment_storage = $this->entityTypeManager->getStorage('govukpayment');
            $payment_entities = $payment_storage->loadByProperties(['uuid' => $uuid]);

            if (!empty($payment_entities)) {
              /** @var \Drupal\govuk_pay\Entity\GovUkPayment $payment_entity */
              $payment_entity = reset($payment_entities);

              // Only update if the status has changed.
              if ($payment_entity->get('status')->value !== $payment_details['status']) {
                $payment_entity->setNewRevision(TRUE);
                $payment_entity->setRevisionCreationTime(time());
                $payment_entity->setRevisionLogMessage('Payment status updated from ' . $payment_entity->get('status')->value . ' to ' . $payment_details['status']);
                // Set the revision owner to the current user if available.
                $current_user = $this->currentUser();
                if ($current_user) {
                  $payment_entity->setRevisionUserId($current_user->id());
                }
                $payment_entity->set('status', $payment_details['status']);
                $payment_entity->save();
                $this->logger->info('Payment status updated for UUID: @uuid from @old_status to @new_status', [
                  '@uuid' => $uuid,
                  '@old_status' => $payment_entity->get('status')->value,
                  '@new_status' => $payment_details['status'],
                ]);
              }
            }
          }
          catch (\Exception $e) {
            $this->logger->error('Error updating payment entity status: @error', ['@error' => $e->getMessage()]);
          }
        }
      }
    }
    catch (\Exception $e) {
      $this->messenger->addError($this->t('An error occurred while processing your payment information. Please contact support.'));
      $this->logger->error('Error processing payment confirmation: @error', ['@error' => $e->getMessage()]);
    }

    return $data;
  }

}
