<?php

namespace Drupal\govuk_pay_webform\Routing;

use Symfony\Component\Routing\RouteCollection;
use Drupal\Core\Routing\RouteSubscriberBase;

/**
 * Subscribes to route events for GOV.UK Pay Webform integration.
 */
class RouteSubscriber extends RouteSubscriberBase {

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection) {
    // Alter the entity.govukpayment.canonical route to use our
    // custom access checker.
    if ($route = $collection->get('entity.govukpayment.canonical')) {
      // Replace the permission requirement with our custom access check.
      // This allows anonymous users to access the payment view if they have
      // the payment data in their session.
      $route->setOption('_govuk_pay_webform_payment_access', TRUE);
      $route->setRequirement('_permission', 'access content');
      $route->setRequirement('_custom_access', '\Drupal\govuk_pay_webform\Access\GovPayPaymentAccess::access');
    }
  }

}
