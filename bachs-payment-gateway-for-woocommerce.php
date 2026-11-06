<?php
/**
 * Plugin Name: Bachs Payment Gateway for WooCommerce
 * Plugin URI: https://bachs.io/wordpress
 * Description: Bachs for WooCommerce allows your WooCommerce store to accept card, bank transfer, mobile money and crypto payments in different currencies.
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
 * Text Domain: bachs-payment-gateway-for-woocommerce
 * Domain Path: /languages
 *
 * @package Bachs_Payment_Gateway_For_WooCommerce
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

	// Payment checks for when the webhook is late. Registered here rather than in the gateway,
	// so they also run where WooCommerce hasn't loaded the gateways (the unpaid-order cron).
	add_action( 'template_redirect', 'bachs_wc_check_payment_on_return' );
	add_filter( 'woocommerce_cancel_unpaid_order', 'bachs_wc_filter_cancel_unpaid_order', 10, 2 );
}
add_action( 'plugins_loaded', 'bachs_wc_init', 99 );

/**
 * The Bachs gateway instance, if WooCommerce has it registered.
 *
 * @return Bachs_WC_Gateway|null
 */
function bachs_wc_gateway() {
	if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways() ) {
		return null;
	}
	$gateways = WC()->payment_gateways()->payment_gateways();
	return isset( $gateways['bachs'] ) && $gateways['bachs'] instanceof Bachs_WC_Gateway ? $gateways['bachs'] : null;
}

/**
 * On the order-received page, check the Bachs session before the page renders. Runs on
 * template_redirect so it works with classic and block (Order Confirmation) themes alike.
 */
function bachs_wc_check_payment_on_return() {
	if ( ! function_exists( 'is_order_received_page' ) || ! is_order_received_page() ) {
		return;
	}

	global $wp;
	$order_id = isset( $wp->query_vars['order-received'] ) ? absint( $wp->query_vars['order-received'] ) : 0;
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The order key is the access check WooCommerce uses for this page.
	$order_key = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
	$order     = $order_id ? wc_get_order( $order_id ) : false;

	if ( ! $order || ! hash_equals( $order->get_order_key(), $order_key ) ) {
		return;
	}

	$gateway = bachs_wc_gateway();
	if ( $gateway ) {
		$gateway->check_payment_on_return( $order_id );
	}
}

/**
 * @param bool     $cancel
 * @param WC_Order $order
 * @return bool
 */
function bachs_wc_filter_cancel_unpaid_order( $cancel, $order ) {
	$gateway = bachs_wc_gateway();
	return $gateway ? $gateway->filter_cancel_unpaid_order( $cancel, $order ) : $cancel;
}

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
		'settings' => '<a href="' . esc_url( bachs_wc_settings_url() ) . '">' . esc_html__( 'Settings', 'bachs-payment-gateway-for-woocommerce' ) . '</a>',
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
	echo '<div class="notice notice-error"><p><strong>' . wp_kses_post( sprintf( __( 'Bachs Payment Gateway for WooCommerce requires WooCommerce to be installed and active. You can download %s here.', 'bachs-payment-gateway-for-woocommerce' ), '<a href="https://woocommerce.com/" target="_blank" rel="noopener">WooCommerce</a>' ) ) . '</strong></p></div>';
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
				__( '<strong>Bachs is not available at checkout</strong> because your store currency (%1$s) is not supported by Bachs. Supported currencies: %2$s. Change it under WooCommerce → Settings → General.', 'bachs-payment-gateway-for-woocommerce' ),
				esc_html( $currency ),
				esc_html( implode( ', ', Bachs_WC_Helpers::supported_currencies() ) )
			)
		) . '</p></div>';
	}

	if ( ! Bachs_WC_Helpers::has_keys() ) {
		/* translators: %s: settings URL */
		echo '<div class="notice notice-error"><p>' . wp_kses_post( sprintf( __( 'Bachs is enabled but no API key is set for the current mode. Add your keys <a href="%s">here</a>.', 'bachs-payment-gateway-for-woocommerce' ), $settings_url ) ) . '</p></div>';
	}

	if ( Bachs_WC_Helpers::is_test_mode() ) {
		/* translators: %s: settings URL */
		echo '<div class="notice notice-warning is-dismissible"><p>' . wp_kses_post( sprintf( __( 'Bachs is in <strong>test mode</strong>. Disable it <a href="%s">here</a> when you are ready to accept live payments.', 'bachs-payment-gateway-for-woocommerce' ), $settings_url ) ) . '</p></div>';
	}
}
