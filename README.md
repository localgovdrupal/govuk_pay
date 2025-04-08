# GOV.UK Pay for Drupal

## Overview
This module provides integration with the GOV.UK Pay service for Drupal websites. GOV.UK Pay is the UK government's online payment service that allows users to make payments for government services.

## Features
- Secure API integration with GOV.UK Pay service
- Bearer token authentication with the GOV.UK Pay API
- Base configuration and service methods for other modules to utilize
- Extensible architecture allowing for additional payment functionality

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
