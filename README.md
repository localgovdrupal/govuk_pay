# GOV.UK Pay for Drupal

## Overview
This module provides integration with the GOV.UK Pay service for Drupal websites. GOV.UK Pay is the UK government's online payment service that allows users to make payments for government services.

## Features
- Secure API integration with GOV.UK Pay service
- Bearer token authentication with the GOV.UK Pay API
- Base configuration and service methods for other modules to utilize
- Extensible architecture allowing for additional payment functionality
- Webhook integration for automatic payment status updates

## Requirements
- Drupal 9.x or 10.x
- Access to a GOV.UK Pay account and API key

## Installation
1. Install the module using Composer:
   ```
   composer require drupal/govuk_pay
   ```
2. Enable the module through the Drupal admin interface or using Drush:
   ```
   drush en govuk_pay
   ```

## Configuration
1. Navigate to the configuration page at Administration → Configuration → Web services → GOV.UK Pay
2. Enter your GOV.UK Pay API key
3. Configure the payment reference format that will appear on all completed payments
4. Save the configuration

## API Authentication
The module handles authentication with the GOV.UK Pay API using the HTTP Bearer token format in the Authorization header. The API key is stored in Drupal's configuration system. You may want to override this key in your environment's `settings.local.php` file. To do so you would add the following lines:

```php
// Override the GOV.UK Pay API key
$config['govuk_pay.settings']['gov_pay__apikey'] = 'your_api_key_here';

// Override the payment reference if needed
$config['govuk_pay.settings']['gov_pay__reference'] = 'Your Payment Reference';
```

## Webhooks
The module provides a webhook endpoint that automatically updates payment entity statuses when they change in the GOV.UK Pay system. This ensures your Drupal site always has the most up-to-date payment information, even if users don't return to your site after completing a payment.

### Webhook Endpoint
The webhook endpoint is available at:
```
/api/govuk-pay/webhook
```

### Setting Up Webhooks in GOV.UK Pay
1. Log in to your GOV.UK Pay account
2. Navigate to the webhook settings section
3. Add a new webhook with your site's URL: `https://your-domain.com/api/govuk-pay/webhook`
4. Select which payment status events you want to receive notifications for (recommended: all status changes)

### How It Works
When a payment status changes in GOV.UK Pay:
1. GOV.UK Pay sends a notification to your webhook endpoint
2. The webhook controller validates the incoming data
3. The controller finds the corresponding payment entity in your Drupal site
4. The payment status is updated to match the current status in GOV.UK Pay
5. All actions are logged for auditing and debugging purposes

### Security Considerations
- The webhook endpoint is publicly accessible (as required for webhooks)
- Basic validation is performed on all incoming webhook data
- For additional security, consider implementing IP restrictions at the server level to only allow requests from GOV.UK Pay IP addresses

## Extension
This module provides the base integration with GOV.UK Pay. Additional functionality is available through submodules:
- **GOV.UK Pay Webform**: Adds GOV.UK Pay integration to Drupal Webforms

## Troubleshooting
- Ensure your API key is valid and has the correct permissions
- Check the Drupal logs for any API communication errors
- Verify your server can make outbound HTTPS requests to the GOV.UK Pay API endpoints

## Credits
Originally developed by [Webcurl](https://webcurl.co.uk/).
Refactored and updated by [Royal Borough of Greenwich](https://www.royalgreenwich.gov.uk).

## License
This project is licensed under the GNU General Public License v2.0 or later.
