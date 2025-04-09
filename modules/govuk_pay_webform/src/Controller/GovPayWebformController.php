<?php

namespace Drupal\govuk_pay_webform\Controller;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Psr\Log\LoggerInterface;
use Drupal\webform\Entity\Webform;
use Drupal\govuk_pay_webform\GovUkPayWebformService;
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
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    GovUkPayWebformService $payment_service,
    RequestStack $request_stack,
    MessengerInterface $messenger,
    LoggerInterface $logger,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->paymentService = $payment_service;
    $this->requestStack = $request_stack;
    $this->messenger = $messenger;
    $this->logger = $logger;
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
      $container->get('logger.factory')->get('govuk_pay_webform')
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

      // Get the confirmation message from the GovPayHandler configuration.
      $webform = Webform::load($webform_id);
      if ($webform) {
        foreach ($webform->getHandlers() as $handler) {
          if ($handler->getPluginId() === 'govuk_pay') {
            $configuration = $handler->getConfiguration();
            $confirmation_message = $configuration['settings']['confirmation_message'] ? $configuration['settings']['confirmation_message'] : NULL;
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
    }
    catch (\Exception $e) {
      $this->messenger->addError($this->t('An error occurred while processing your payment information. Please contact support.'));
      $this->logger->error('Error processing payment confirmation: @error', ['@error' => $e->getMessage()]);
    }

    return $data;
  }

}
