<?php
/**
 * WooCommerce Blocks (checkout blocks) integration for the Bachs gateway.
 *
 * @package Bachs_WooCommerce_Payment_Gateway
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class Bachs_WC_Blocks_Support extends AbstractPaymentMethodType {

	/**
	 * @var string
	 */
	protected $name = 'bachs';

	public function initialize() {
		$this->settings = get_option( 'woocommerce_bachs_settings', array() );
	}

	/**
	 * @return bool
	 */
	public function is_active() {
		$gateways = WC()->payment_gateways ? WC()->payment_gateways()->payment_gateways() : array();
		return isset( $gateways['bachs'] ) && $gateways['bachs']->is_available();
	}

	/**
	 * @return string[]
	 */
	public function get_payment_method_script_handles() {

		$asset_path = BACHS_WC_PATH . '/assets/js/blocks/bachs-wc-blocks.asset.php';
		$asset      = file_exists( $asset_path )
			? require $asset_path
			: array(
				'dependencies' => array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities', 'wp-i18n' ),
				'version'      => BACHS_WC_VERSION,
			);

		wp_register_script(
			'bachs-wc-blocks',
			BACHS_WC_URL . '/assets/js/blocks/bachs-wc-blocks.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( 'bachs-wc-blocks', 'bachs-woocommerce-payment-gateway', BACHS_WC_PATH . '/languages' );
		}

		return array( 'bachs-wc-blocks' );
	}

	/**
	 * @return array
	 */
	public function get_payment_method_data() {
		$gateways = WC()->payment_gateways ? WC()->payment_gateways()->payment_gateways() : array();
		$gateway  = isset( $gateways['bachs'] ) ? $gateways['bachs'] : null;

		return array(
			'title'       => $gateway ? $gateway->get_title() : $this->get_setting( 'title', __( 'Bachs', 'bachs-woocommerce-payment-gateway' ) ),
			'description' => $gateway ? $gateway->get_description() : $this->get_setting( 'description' ),
			'supports'    => $gateway ? array_filter( $gateway->supports, array( $gateway, 'supports' ) ) : array( 'products', 'refunds' ),
			'icon'        => BACHS_WC_URL . '/assets/images/bachs-icon.png',
		);
	}
}
