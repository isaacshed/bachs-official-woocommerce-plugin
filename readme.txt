=== Bachs Payment Gateway for WooCommerce ===
Contributors: bachs
Tags: woocommerce, payment gateway, bachs, payments, africa
Requires at least: 6.2
Tested up to: 7.1
Stable tag: 1.0.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Bachs for WooCommerce allows your WooCommerce store to accept secure payments from multiple local and global payment channels and currencies.

== Description ==

Bachs Payment Gateway for WooCommerce lets your store accept payments through [Bachs](https://bachs.io/), a payments platform for internet businesses across African markets.

= Features =

* **Hosted checkout redirect or on-site popup** — choose whether customers are redirected to the Bachs hosted checkout page or pay in a Bachs modal without leaving your store. No card data ever touches your server either way.
* **Multiple payment methods** — cards, bank transfer, mobile money, and crypto stablecoins (USDT, USDC), all handled on the Bachs checkout.
* **Sell in USD or NGN, get paid across Africa** — price your store in USD or NGN and, with Bachs Adaptive Pricing enabled, customers can pay in their own local currency (NGN, GHS, KES, UGX, TZS, XAF, XOF, ZMW, RWF and more). Bachs settles to you in USD or NGN.
* **Webhook-verified fulfilment** — orders complete on the `collection.succeeded` event, with signature verification, not on the browser redirect.
* **Failed, expired and short payments** — failed payments mark the order failed, short payments put it on hold for you to review, and customers whose checkout expired can pay again or cancel from the order payment page.
* **Refunds** — issue a refund straight from the WooCommerce order screen.
* **Sandbox mode** — build and test against the Bachs sandbox before going live.
* **WooCommerce Blocks** — works with both the classic and block-based checkout.
* **HPOS compatible** — supports WooCommerce High-Performance Order Storage.

= Requirements =

* WordPress 6.2 or higher
* WooCommerce 8.0 or higher
* PHP 7.4 or higher
* An SSL certificate (required for live payments)
* A Bachs account — sign up at [bachs.io](https://bachs.io/)

== Installation ==

= Automatic Installation =

1. Log in to your WordPress Admin Dashboard.
2. Navigate to **Plugins > Add New**.
3. Search for "Bachs Payment Gateway for WooCommerce".
4. Click **Install Now** and then **Activate**.

= Manual Installation =

1. Download the plugin .zip from the [WordPress.org plugin repository](https://wordpress.org/plugins/bachs-payment-gateway-for-woocommerce/).
2. Upload the `bachs-payment-gateway-for-woocommerce` folder to `/wp-content/plugins/` via FTP or your hosting control panel.
3. Go to **Plugins > Installed Plugins** and click **Activate** under Bachs Payment Gateway for WooCommerce.
4. Navigate to **WooCommerce > Settings > Payments** to configure.

== Frequently Asked Questions ==

= Which currencies are supported? =

Set your WooCommerce store currency to USD or NGN — these are the currencies Bachs prices and settles in. Your customers are not limited to those, though: with Adaptive Pricing enabled in your Bachs dashboard, shoppers across Africa can pay in their own local currency (such as GHS, KES, UGX, TZS, XAF, XOF, ZMW and RWF), or with cards, bank transfer, mobile money, or crypto stablecoins (USDT, USDC), all on the Bachs checkout. Bachs converts and settles the funds to your balance in USD or NGN.

= Do I need to configure webhooks? =

Yes. Fulfilment relies on webhooks. Add the Webhook URL from the plugin settings to your Bachs developer portal, subscribe to `collection.succeeded`, `collection.failed`, `collection.underpaid`, `checkout.expired`, `refund.paid` and `refund.failed`, and paste the signing secret into the plugin.

= Redirect or popup? =

Under Checkout type you can choose Redirect (the customer is sent to the Bachs hosted checkout page) or Popup (the Bachs checkout opens in a modal on your store, so the customer never leaves your site). Both use the same secure Bachs checkout and are confirmed by the same webhook.

= Why did my order not complete after payment? =

Orders complete when Bachs delivers the signed `collection.succeeded` webhook. Confirm your webhook URL and signing secret are set correctly, and that your site is reachable from the internet.

= Can I refund a payment? =

Yes, from the WooCommerce order screen. Note that Bachs allows a single refund per charge, so once a charge has been refunded (fully or partially) a further refund through Bachs is not possible.

= Does it support subscriptions? =

Not in this version. This release covers one-time payments and refunds.

== Screenshots ==

1. Bachs gateway settings in WooCommerce: checkout type, sandbox and live API keys, and the webhook URL.
2. Bachs shown as a payment option on the WooCommerce checkout.
3. The Bachs popup checkout on your store.

== Changelog ==

= 1.0.0 =
* Initial release of the official Bachs plugin for WooCommerce.

== Upgrade Notice ==

= 1.0.0 =
Initial release.

== External services ==

This plugin connects to the Bachs API to create checkout sessions, verify payments, and process refunds. It is required for the plugin to function.

When a customer pays with Bachs, the plugin sends the following to Bachs (`https://api.bachs.io`, or `https://sandbox-api.bachs.io` in test mode):

* Order reference, order ID and order number
* Order total amount
* Currency
* Customer billing details (name, email address, and phone number, when provided)

In the WordPress admin, the plugin also requests the list of supported currencies from the same API. No customer or order data is sent with that request.

The customer then pays on the Bachs hosted checkout (`https://checkout.bachs.io`) — either by redirect, or, when the Popup checkout type is selected, in a modal opened by the Bachs checkout script (`https://checkout.bachs.io/bachs.js`) loaded on the order-pay page. Card and payment details are handled entirely by Bachs; this plugin never stores or processes card numbers or other sensitive payment data. Bachs also sends signed webhook notifications back to your site to confirm payment and refund status.

For details on how Bachs handles data, see:

* Bachs website: https://bachs.io
* Bachs documentation: https://docs.bachs.io
* Bachs Privacy Policy: https://bachs.io/privacy
* Bachs Buyer Terms: https://bachs.io/buyer-terms
