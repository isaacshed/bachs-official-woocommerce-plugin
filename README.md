# Bachs Payment Gateway for WooCommerce

The official [Bachs](https://bachs.io) payment gateway for WooCommerce.

Bachs for WooCommerce allows your WooCommerce store to accept secure payments from multiple local and global payment channels and currencies.

The WordPress.org listing text, installation steps and FAQ are in [`readme.txt`](readme.txt).

## How it works

- At checkout the plugin creates a Bachs checkout session priced at the order total (`pricing`, with no Bachs products) and sends the customer to the hosted checkout, by redirect or in an on-site popup.
- Orders complete on the signed `collection.succeeded` webhook, after the payment is checked against the order. A payment that doesn't match is put on hold with a note.
- When the customer lands on the order-received page, and before WooCommerce's Hold stock setting cancels an unpaid order, the plugin asks Bachs for the checkout session status. A paid session moves the order to on-hold (stock stays reduced, no auto-cancel) until the webhook confirms the payment; the session status alone never completes an order.
- A customer who returns to pay an order whose checkout is already complete isn't given a second checkout. A payment received for an order that is already paid is flagged on the order for refund.
- Refunds go through `POST /v1/refunds`. Partial refunds are only sent when the payment settled in the order currency.

## Development

Requirements: PHP 7.4+.

```bash
php tests/run.php
```

The tests use lightweight WordPress and WooCommerce stand-ins (`tests/bootstrap.php`) and need no WordPress install. They run on every push via GitHub Actions.

### Layout

| Path | Purpose |
| --- | --- |
| `bachs-payment-gateway-for-woocommerce.php` | Plugin bootstrap, admin notices |
| `includes/class-bachs-wc-gateway.php` | Payment, webhook and refund handling |
| `includes/class-bachs-wc-helpers.php` | Settings, currencies, amounts, webhook signatures |
| `includes/class-bachs-wc-api.php` | Bachs REST API client |
| `includes/class-bachs-wc-blocks-support.php` | Block checkout integration |
| `assets/` | Checkout JS, CSS and images |
| `languages/` | Translation template |

### Release zip

```bash
bin/build-zip.sh
```

This builds `dist/bachs-payment-gateway-for-woocommerce-<version>.zip` from the committed tree. It checks that the plugin header version, `BACHS_WC_VERSION` and the readme's `Stable tag` match. Dev files (`tests/`, `bin/`, `.github/`, this README) are excluded through `.gitattributes`; `.distignore` lists the same files for tools such as `wp dist-archive`.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
