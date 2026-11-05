<?php
/**
 * Uninstall: remove the plugin's settings and cached data.
 *
 * Order and user meta (Bachs charge, refund and customer IDs) are kept, because they are part
 * of the store's payment records.
 *
 * @package Bachs_WooCommerce_Payment_Gateway
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'woocommerce_bachs_settings' );
delete_transient( 'bachs_wc_supported_currencies_sandbox' );
delete_transient( 'bachs_wc_supported_currencies_live' );
