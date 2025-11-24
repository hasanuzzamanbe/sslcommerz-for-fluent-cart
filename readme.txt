=== Bangladeshi Payment Gateway SSLCommerz for FluentCart ===
Contributors: hasanuzzamanshamim, wpminers
Tags: sslcommerz, payment gateway, fluentcart, bangladesh, bdt
Requires at least: 5.6
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept payments via Bangladeshi Payment Gateway SSLCommerz for FluentCart - supports one-time payments, multiple payment methods, and automatic payment verification.

== Description ==

SSL Commerz for FluentCart seamlessly integrates SSL Commerz payment gateway with FluentCart, allowing you to accept payments from customers in Bangladesh and internationally.

= Features =

* **One-time Payments**: Accept card payments, mobile banking, internet banking, and more
* **Multiple Payment Options**: Support for all SSL Commerz payment methods
* **Hosted & Modal Checkout**: Choose between redirect or popup checkout experience
* **IPN/Webhook Support**: Automatic payment verification via IPN
* **Test Mode**: Test your integration before going live
* **Secure**: All transactions are encrypted and secure

= Supported Payment Methods =

* Credit/Debit Cards (Visa, Mastercard, Amex)
* Mobile Banking (bKash, Rocket, Nagad, Upay)
* Internet Banking
* Mobile Wallets

= Supported Currencies =

* BDT (Bangladesh Taka) - Primary
* USD, EUR, GBP, AUD, CAD, SGD, MYR, INR, JPY, CNY

= Requirements =

* FluentCart plugin (free or pro)
* SSL Commerz merchant account ([Sign up here](https://www.sslcommerz.com/))

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/sslcommerz-for-fluent-cart/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to FluentCart > Settings > Payment Methods
4. Enable and configure SSL Commerz with your Store ID and Password
5. Choose your checkout type (Hosted or Modal)
6. Save your settings

== Frequently Asked Questions ==

= Do I need an SSL Commerz account? =

Yes, you need an SSL Commerz merchant account. You can sign up at https://www.sslcommerz.com/

= Where do I get my Store ID and Password? =

Log into your SSL Commerz merchant panel and navigate to the API integration section.

= What is the difference between Hosted and Modal checkout? =

Hosted checkout redirects customers to SSL Commerz's payment page, while Modal checkout opens the payment page in a popup overlay on your site.

= Does this support subscriptions? =

No, SSL Commerz integration currently supports one-time payments only. Subscription support may be added in future versions.

= Is it secure? =

Yes, all transactions are processed securely through SSL Commerz's infrastructure. No card details are stored on your server.

= How do I configure IPN? =

The plugin automatically handles IPN configuration. The IPN URL is displayed in the payment settings and is automatically sent to SSL Commerz with each transaction.

== Screenshots ==

1. Payment method settings page
2. Hosted checkout experience
3. Modal checkout experience
4. Transaction management

== Changelog ==

= 1.0.0 =
* Initial release
* Support for one-time payments
* Hosted and modal checkout options
* IPN integration
* Test and live mode
* Multiple currency support

== Upgrade Notice ==

= 1.0.0 =
Initial release of SSL Commerz for FluentCart.

