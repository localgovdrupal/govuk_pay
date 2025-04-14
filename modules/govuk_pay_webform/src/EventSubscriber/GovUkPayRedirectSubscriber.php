<?php

namespace Drupal\govuk_pay_webform\EventSubscriber;

use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Event subscriber for handling GOV.UK Pay redirects.
 *
 * This subscriber listens to the KernelEvents::RESPONSE event and
 * issues a redirect if one has been stored by the GovUkPayWebformService.
 */
class GovUkPayRedirectSubscriber implements EventSubscriberInterface {

  /**
   * The tempstore service.
   *
   * @var \Drupal\Core\TempStore\PrivateTempStore
   */
  protected $tempStore;

  /**
   * The logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * Constructs a new GovUkPayRedirectSubscriber.
   *
   * @param \Drupal\Core\TempStore\PrivateTempStoreFactory $temp_store_factory
   *   The tempstore factory.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   */
  public function __construct(
    PrivateTempStoreFactory $temp_store_factory,
    LoggerChannelFactoryInterface $logger_factory,
  ) {
    $this->tempStore = $temp_store_factory->get('govuk_pay_webform');
    $this->logger = $logger_factory->get('govuk_pay_webform');
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    // Use a high priority to ensure this runs before other response subscribers.
    $events[KernelEvents::RESPONSE][] = ['onKernelResponse', 100];
    return $events;
  }

  /**
   * Handles the response event by checking for a stored redirect URL.
   *
   * @param \Symfony\Component\HttpKernel\Event\ResponseEvent $event
   *   The response event.
   */
  public function onKernelResponse(ResponseEvent $event) {
    if (!$event->isMainRequest()) {
      return;
    }

    try {
      // Check if there's a redirect URL stored in the tempStore.
      $redirect_url = $this->tempStore->get('redirect_url');

      if (!empty($redirect_url)) {
        // Create and prepare the redirect response.
        $response = new TrustedRedirectResponse($redirect_url, 302);
        $response->prepare($event->getRequest());

        // Set the response on the event.
        $event->setResponse($response);

        // Clear the stored redirect URL to prevent repeated redirects.
        $this->tempStore->delete('redirect_url');
      }
    }
    catch (\Exception $e) {
      // Log the error but don't throw an exception to avoid breaking the site.
      $this->logger->error(
        'Error processing GOV.UK Pay redirect: @error',
        ['@error' => $e->getMessage()]
      );
    }
  }

}
