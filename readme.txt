=== Bachs WooCommerce Payment Gateway ===
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

Bachs for WooCommerce allows your WooCommerce store to accept secure payments from multiple local and global payment channels and currencies. This is the official [Bachs](https://bachs.io) plugin for WooCommerce.

= Features =

* **Hosted checkout, by redirect or on-site popup.** Customers pay on the secure Bachs checkout, either on the Bachs page or in a modal on your store. Card and payment details never touch your server.
* **Local and global payment methods.** Cards, bank transfer, mobile money and crypto stablecoins, all handled on the Bachs checkout.
* **Price in your local currency.** Sell in USD, NGN, GHS, KES, MWK, RWF, TZS, UGX, XAF, XOF or ZMW. The plugin reads the live list of supported currencies from Bachs.
* **Webhook-verified fulfilment.** Orders complete on the signed `collection.succeeded` event, after the plugin checks the payment matches the order total. Payments that don't match are put on hold for review.
* **Protection against double charges.** A customer who returns to pay an order that is already paid isn't sent to a second checkout, and any duplicate payment is flagged on the order for refund.
* **Failed, expired and short payments.** Failed payments mark the order failed, short payments put it on hold, and customers whose checkout expired can pay again or cancel.
* **Refunds.** Refund from the WooCommerce order screen.
* **Sandbox mode.** Build and test against the Bachs sandbox before going live.
* **WooCommerce Blocks and HPOS.** Works with the classic and block-based checkout, and with High-Performance Order Storage.

= Requirements =

* WordPress 6.2 or higher
* WooCommerce 8.0 or higher
* PHP 7.4 or higher
* An SSL certificate (required for live payments)
* A Bachs account. Sign up at [bachs.io](https://bachs.io).

== Installation ==

1. Install the plugin through **Plugins → Add New**, or upload it to `/wp-content/plugins/`.
2. Activate the plugin.
3. Go to **WooCommerce → Settings → Payments → Bachs**.
4. Enable the gateway and enter your Bachs API keys (sandbox and/or live).
5. Copy the **Webhook URL** shown on the settings screen into your Bachs developer portal, subscribe to the listed events, and paste the webhook signing secret back into the plugin.
6. Save. Use test mode with a sandbox key to run a test payment before going live.

== Frequently Asked Questions ==

= Which currencies are supported? =

Your store currency must be one Bachs can price a checkout in: USD, NGN, GHS, KES, MWK, RWF, TZS, UGX, XAF, XOF or ZMW. The plugin keeps this list up to date from Bachs. If your store currency isn't supported, an admin notice tells you and Bachs is hidden at checkout. Bachs settles funds to your balance in a currency you hold (USD, or NGN when enabled).

= Do I need to configure webhooks? =

Yes. Orders are completed by webhooks. Add the Webhook URL from the plugin settings to your Bachs developer portal, subscribe to `collection.succeeded`, `collection.failed`, `collection.underpaid`, `checkout.expired`, `refund.paid` and `refund.failed`, and paste the signing secret into the plugin.

= Why is a paid order on hold? =

The plugin checks every successful payment against the order before completing it. An order is put on hold, with a note explaining why, when the payment doesn't match the order total, comes from a checkout the order didn't create, or was accepted by Bachs on terms other than the exact amount. Check the payment in your Bachs dashboard, then complete or refund the order.

= Why did my order not complete after payment? =

Orders complete when Bachs delivers the signed `collection.succeeded` webhook. Confirm your webhook URL and signing secret are set correctly, and that your site is reachable from the internet.

= Can I refund a payment? =

Yes, from the WooCommerce order screen. Bachs allows one refund per payment, so after a full or partial refund the payment can't be refunded again. Partial refunds are sent from WooCommerce when the payment settled in the order currency. When it settled in another currency, make the partial refund from your Bachs dashboard (full refunds always work from WooCommerce).

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

This plugin connects to the Bachs API to create checkout sessions, check their status, look up supported currencies, and process refunds. It is required for the plugin to function.

When a customer pays with Bachs, the plugin sends the following to Bachs (`https://api.bachs.io`, or `https://sandbox-api.bachs.io` in test mode):

* Order ID, order number and order reference
* Order total and currency
* Customer billing details (name, email address, and phone number, when provided)

In the WordPress admin, the plugin also requests the list of supported currencies from the same API.

The customer then pays on the Bachs hosted checkout (`https://checkout.bachs.io`), either by redirect or, when the Popup checkout type is selected, in a modal opened by the Bachs checkout script (`https://checkout.bachs.io/bachs.js`) loaded on the order-pay page. Card and payment details are handled entirely by Bachs; this plugin never stores or processes card numbers or other sensitive payment data. Bachs sends signed webhook notifications back to your site to confirm payment and refund status.

For details on how Bachs handles data, see:

* Bachs website: https://bachs.io
* Bachs documentation: https://docs.bachs.io
* Bachs Privacy Policy: https://bachs.io/privacy
* Bachs Buyer Terms: https://bachs.io/buyer-terms
