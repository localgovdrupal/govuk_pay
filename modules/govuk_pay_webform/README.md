# GOV.UK Pay Webform Integration

## Overview
This submodule extends the GOV.UK Pay module by providing integration with Drupal's Webform module. It adds a dedicated GOV.UK Pay handler that can be used with any webform to process payments through the GOV.UK Pay service.

## Features
- Custom GOV.UK Pay handler
- Support for both static and dynamic payment amounts
- Configurable payment message and confirmation message
- Automatic redirection to GOV.UK Pay for payment processing

## Requirements
- Drupal 9.x or 10.x
- GOV.UK Pay module (parent)
- Webform module

## Installation
1. Ensure the parent GOV.UK Pay module is installed
2. Enable this submodule through the Drupal admin interface or using Drush:
   ```
   drush en govuk_pay_webform
   ```

## Adding GOV.UK Pay to a Webform

### Basic Setup
1. Navigate to your webform you wish to add GOV.UK Pay to
2. In the settings -> handlers section add a new handler and choose GOV.UK Pay
3. Configure the handler as needed and save it

Note that you need to have an API key and to enter the key and reference in the parent GOV.UK Pay module's settings form, located at `/admin/config/govuk_pay/settings`.

### Handler configuration options

#### Payment Amount
Choose how the payment amount will be determined:

- **Webform element**: Use a value from another form element
  - Compatible element types:
    - Hidden
    - Number
    - Radios
    - Radios Other
    - Select
    - Select Other
    - Value
    - Computed Token
    - Computed Twig
- **Fixed amount**: Set a fixed payment amount

#### Messages
- **Payment message**: A custom message displayed on the GOV.UK Pay payment page
- **Confirmation message**: A custom message displayed on the confirmation page

#### Metadata
- **Metadata key/value pairs**: Optional metadata that will be sent with the payment to GOV.UK Pay
  - You can add multiple key/value pairs by clicking the "Add another metadata item" button
  - Both keys and values support token replacement, allowing you to include dynamic data from the webform submission
  - Keys must be strings and values must be scalar (string, number, boolean)
  - This metadata will be available in GOV.UK Pay reports and can be used for filtering and reporting purposes
  - Example use cases: tracking department codes, cost centers, service identifiers, or any other custom data needed for reconciliation

## How It Works
1. When a user submits a webform with the govuk_pay webform handler, the normal webform submission process is intercepted when the handler triggers
2. The user is redirected to GOV.UK Pay to complete their payment
3. After payment processing, the user is redirected back to a confirmation page on your site
4. The confirmation page displays the confirmation message and payment details

## Troubleshooting
- Check that the parent GOV.UK Pay module is properly configured with a valid API key
- Verify that the amount provider is correctly set up and returning a valid numeric value (if not using a fixed amount)
- Review Drupal logs for any API communication errors