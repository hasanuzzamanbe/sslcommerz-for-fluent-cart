# SSL Commerz for FluentCart

A WordPress plugin that integrates SSL Commerz payment gateway with FluentCart.

## Features

- ✅ One-time payments
- ✅ Hosted checkout (redirect)
- ✅ Modal checkout (popup)
- ✅ IPN/Webhook integration
- ✅ Test and Live modes
- ✅ Multiple currency support (BDT, USD, EUR, GBP, etc.)
- ✅ Multiple payment methods (Cards, Mobile Banking, Internet Banking)
- ⚠️ No subscription support (SSL Commerz limitation)

## Installation

1. Clone or download this repository to your WordPress plugins directory:
   ```bash
   cd wp-content/plugins/
   git clone [your-repo-url] sslcommerz-for-fluent-cart
   ```

2. Activate the plugin in WordPress admin

3. Go to FluentCart > Settings > Payment Methods

4. Enable and configure SSL Commerz with your credentials from [SSL Commerz Dashboard](https://merchant.sslcommerz.com/)

## Requirements

- WordPress 5.6 or higher
- PHP 7.4 or higher
- FluentCart plugin (free or pro version)
- SSL Commerz merchant account

## Configuration

### Getting Credentials

1. Log into your [SSL Commerz Merchant Panel](https://merchant.sslcommerz.com/)
2. Navigate to **API Integration** section
3. Copy your **Store ID** and **Store Password**

### Setting Up IPN

The IPN URL is automatically configured. It will be displayed in the settings:
```
https://yourdomain.com/?fluent-cart=fct_payment_listener_ipn&method=sslcommerz
```

### Checkout Types

- **Hosted Checkout**: Redirects customer to SSL Commerz payment page
- **Modal Checkout**: Opens SSL Commerz payment page in a popup overlay

## Development

### Directory Structure

```
sslcommerz-for-fluent-cart/
├── sslcommerz-for-fluent-cart.php    # Main plugin file
├── assets/
│   ├── sslcommerz-checkout.js        # Frontend payment handler
│   └── images/
│       └── sslcommerz-logo.svg       # Payment method logo
├── includes/
│   ├── SslcommerzGateway.php         # Main gateway class
│   ├── API/
│   │   └── SslcommerzAPI.php         # API client
│   ├── Webhook/
│   │   └── SslcommerzWebhook.php     # Webhook/IPN handler
│   ├── Onetime/
│   │   └── SslcommerzProcessor.php   # Payment processor
│   ├── Settings/
│   │   └── SslcommerzSettingsBase.php  # Settings management
│   └── Confirmations/
│       └── SslcommerzConfirmations.php # Payment confirmations
└── README.md
```

### API Endpoints

#### Sandbox
- Payment Gateway: `https://sandbox.sslcommerz.com/gwprocess/v4/api.php`
- Validation: `https://sandbox.sslcommerz.com/validator/api/validationserverAPI.php`

#### Live
- Payment Gateway: `https://securepay.sslcommerz.com/gwprocess/v4/api.php`
- Validation: `https://securepay.sslcommerz.com/validator/api/validationserverAPI.php`

### Hooks and Filters

#### Filters

- `sslcommerz_fc/payment_args` - Modify payment arguments before sending to SSL Commerz
- `sslcommerz_fc/sslcommerz_settings` - Modify SSL Commerz settings

### Payment Flow

1. **Customer initiates checkout** → FluentCart collects order info
2. **FluentCart calls** `makePaymentFromPaymentInstance()` 
3. **SslcommerzProcessor** prepares payment data
4. **API call** initializes payment with SSL Commerz
5. **Customer redirected** to SSL Commerz (hosted) or popup opens (modal)
6. **Customer pays** on SSL Commerz's secure platform
7. **SSL Commerz IPN** notifies your site
8. **SslcommerzWebhook** validates payment with SSL Commerz API
9. **FluentCart** completes the order

## Testing

### Test Mode

1. Enable **Test Mode** in settings
2. Use test Store ID and Password from SSL Commerz
3. Use [test cards](https://developer.sslcommerz.com/doc/v4/#test-cards) provided by SSL Commerz

### Test Cards

See SSL Commerz documentation for test card numbers:
https://developer.sslcommerz.com/doc/v4/#test-cards

## Important Notes

### Currency Requirements

- Primary currency should match your SSL Commerz account currency
- For Bangladesh merchants, use BDT as primary currency
- Multi-currency support available based on your SSL Commerz account setup

### Refunds

SSL Commerz refunds must be processed manually through the SSL Commerz merchant dashboard. Automated refunds are not supported by SSL Commerz API.

### Subscriptions

SSL Commerz does not support automated recurring billing. This plugin only supports one-time payments.

## Troubleshooting

### Common Issues

1. **Payment initialization fails**
   - Check Store ID and Password are correct
   - Verify you're using correct mode (test/live)
   - Check SSL Commerz account status

2. **IPN not received**
   - Verify your site is publicly accessible
   - Check webhook URL is correct
   - Test IPN using SSL Commerz dashboard tools

3. **Payment shows pending after success**
   - Manually trigger IPN from SSL Commerz dashboard
   - Check IPN logs in FluentCart

### Debug Logging

Add to `wp-config.php`:

```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
```

Check logs at `wp-content/debug.log`

## Support

For issues, questions, or contributions:
- SSL Commerz API: https://developer.sslcommerz.com/
- FluentCart Documentation: https://fluentcart.com/docs/

## License

GPLv2 or later. See LICENSE file for details.

## Credits

Built for FluentCart following SSL Commerz API documentation.

