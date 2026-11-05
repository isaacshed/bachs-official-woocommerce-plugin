<?php
/**
 * Plugin Name: Bachs WooCommerce Payment Gateway
 * Plugin URI: https://bachs.io
 * Description: Bachs for WooCommerce allows your WooCommerce store to accept secure payments from multiple local and global payment channels and currencies.
 * Version: 1.0.0
 * Author: Bachs
 * Author URI: https://bachs.io
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires Plugins: woocommerce
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * WC requires at least: 8.0
 * WC tested up to: 11.1
 * Text Domain: bachs-woocommerce-payment-gateway
 * Domain Path: /languages
 *
 * @package Bachs_WooCommerce_Payment_Gateway
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BACHS_WC_MAIN_FILE', __FILE__ );
define( 'BACHS_WC_URL', untrailingslashit( plugins_url( '/', __FILE__ ) ) );
define( 'BACHS_WC_PATH', untrailingslashit( plugin_dir_path( __FILE__ ) ) );
define( 'BACHS_WC_VERSION', '1.0.0' );

/**
 * Bootstrap the gateway once all plugins are loaded.
 */
function bachs_wc_init() {

	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		add_action( 'admin_notices', 'bachs_wc_missing_wc_notice' );
		return;
	}

	require_once BACHS_WC_PATH . '/includes/class-bachs-wc-api.php';
	require_once BACHS_WC_PATH . '/includes/class-bachs-wc-helpers.php';
	require_once BACHS_WC_PATH . '/includes/class-bachs-wc-gateway.php';

	add_filter( 'woocommerce_payment_gateways', 'bachs_wc_add_gateway', 99 );
	add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'bachs_wc_plugin_action_links' );
	add_action( 'admin_notices', 'bachs_wc_admin_notices' );
	add_action( 'admin_init', array( 'Bachs_WC_Helpers', 'maybe_refresh_supported_currencies' ) );
}
add_action( 'plugins_loaded', 'bachs_wc_init', 99 );

/**
 * Register the WooCommerce Blocks (checkout blocks) integration.
 */
function bachs_wc_blocks_support() {
	if ( class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
		require_once BACHS_WC_PATH . '/includes/class-bachs-wc-blocks-support.php';
		add_action(
			'woocommerce_blocks_payment_method_type_registration',
			function ( Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry ) {
				$payment_method_registry->register( new Bachs_WC_Blocks_Support() );
			}
		);
	}
}
add_action( 'woocommerce_blocks_loaded', 'bachs_wc_blocks_support' );

/**
 * Declare compatibility with HPOS (custom order tables) and cart/checkout blocks.
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', BACHS_WC_MAIN_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', BACHS_WC_MAIN_FILE, true );
		}
	}
);

/**
 * @param array $methods
 * @return array
 */
function bachs_wc_add_gateway( $methods ) {
	$methods[] = 'Bachs_WC_Gateway';
	return $methods;
}

/**
 * URL of the gateway settings screen.
 *
 * @return string
 */
function bachs_wc_settings_url() {
	return admin_url( 'admin.php?page=wc-settings&tab=checkout&section=bachs' );
}

/**
 * @param array $links
 * @return array
 */
function bachs_wc_plugin_action_links( $links ) {
	$settings_link = array(
		'settings' => '<a href="' . esc_url( bachs_wc_settings_url() ) . '">' . esc_html__( 'Settings', 'bachs-woocommerce-payment-gateway' ) . '</a>',
	);
	return array_merge( $settings_link, $links );
}

/**
 * Notice shown when WooCommerce is not active.
 */
function bachs_wc_missing_wc_notice() {
	if ( wp_doing_ajax() || ! is_admin() ) {
		return;
	}
	$screen = get_current_screen();
	if ( ! $screen || ! in_array( $screen->id, array( 'dashboard', 'plugins' ), true ) ) {
		return;
	}
	/* translators: %s: WooCommerce link */
	echo '<div class="notice notice-error"><p><strong>' . wp_kses_post( sprintf( __( 'Bachs WooCommerce Payment Gateway requires WooCommerce to be installed and active. You can download %s here.', 'bachs-woocommerce-payment-gateway' ), '<a href="https://woocommerce.com/" target="_blank" rel="noopener">WooCommerce</a>' ) ) . '</strong></p></div>';
}

/**
 * Admin notices: test mode, missing keys, and an unsupported store currency.
 */
function bachs_wc_admin_notices() {
	if ( wp_doing_ajax() || ! is_admin() || ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}
	$screen = get_current_screen();
	if ( ! $screen || ! in_array( $screen->id, array( 'dashboard', 'woocommerce_page_wc-settings', 'plugins' ), true ) ) {
		return;
	}

	if ( 'yes' !== Bachs_WC_Helpers::get_setting( 'enabled', 'no' ) ) {
		return;
	}

	$settings_url = esc_url( bachs_wc_settings_url() );

	$currency = get_woocommerce_currency();
	if ( ! Bachs_WC_Helpers::is_currency_supported( $currency ) ) {
		echo '<div class="notice notice-error"><p>' . wp_kses_post(
			sprintf(
				/* translators: 1: store currency code, 2: comma-separated list of supported currency codes */
				__( '<strong>Bachs is not available at checkout</strong> because your store currency (%1$s) is not supported by Bachs. Supported currencies: %2$s. Change it under WooCommerce → Settings → General.', 'bachs-woocommerce-payment-gateway' ),
				esc_html( $currency ),
				esc_html( implode( ', ', Bachs_WC_Helpers::supported_currencies() ) )
			)
		) . '</p></div>';
	}

	if ( ! Bachs_WC_Helpers::has_keys() ) {
		/* translators: %s: settings URL */
		echo '<div class="notice notice-error"><p>' . wp_kses_post( sprintf( __( 'Bachs is enabled but no API key is set for the current mode. Add your keys <a href="%s">here</a>.', 'bachs-woocommerce-payment-gateway' ), $settings_url ) ) . '</p></div>';
	}

	if ( Bachs_WC_Helpers::is_test_mode() ) {
		/* translators: %s: settings URL */
		echo '<div class="notice notice-warning is-dismissible"><p>' . wp_kses_post( sprintf( __( 'Bachs is in <strong>test mode</strong>. Disable it <a href="%s">here</a> when you are ready to accept live payments.', 'bachs-woocommerce-payment-gateway' ), $settings_url ) ) . '</p></div>';
	}
}
