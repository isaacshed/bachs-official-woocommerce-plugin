<?php
/**
 * Shared helpers: settings, environments, currencies, amounts and webhook signatures.
 *
 * @package Bachs_WooCommerce_Payment_Gateway
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Bachs_WC_Helpers {

	/**
	 * Gateway ID, also used for the settings option (woocommerce_bachs_settings).
	 */
	const GATEWAY_ID = 'bachs';

	/**
	 * Maximum acceptable drift (seconds) between the Bachs signing timestamp and server clock.
	 */
	const MAX_TIMESTAMP_DRIFT = 300;

	/**
	 * Transient caching the live list of supported currencies, per mode.
	 */
	const CURRENCIES_TRANSIENT = 'bachs_wc_supported_currencies_';

	/**
	 * Fiat currencies Bachs can price a checkout in, used until the live list has been fetched.
	 * https://docs.bachs.io/for-you/supported-currencies
	 */
	const FALLBACK_CURRENCIES = array( 'USD', 'NGN', 'GHS', 'KES', 'MWK', 'RWF', 'TZS', 'UGX', 'XAF', 'XOF', 'ZMW' );

	/**
	 * ISO 4217 currencies with no minor unit. Every other currency uses two decimals.
	 */
	const ZERO_DECIMAL_CURRENCIES = array( 'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF' );

	/**
	 * @return array The gateway settings option.
	 */
	public static function get_settings() {
		$settings = get_option( 'woocommerce_' . self::GATEWAY_ID . '_settings', array() );
		return is_array( $settings ) ? $settings : array();
	}

	/**
	 * @param string $key
	 * @param string $default
	 * @return string
	 */
	public static function get_setting( $key, $default = '' ) {
		$settings = self::get_settings();
		return isset( $settings[ $key ] ) ? $settings[ $key ] : $default;
	}

	/**
	 * Active environment: 'sandbox' when test mode is on, otherwise 'live'.
	 *
	 * @return string
	 */
	public static function get_mode() {
		return 'no' === self::get_setting( 'testmode', 'yes' ) ? 'live' : 'sandbox';
	}

	/**
	 * @return bool
	 */
	public static function is_test_mode() {
		return 'sandbox' === self::get_mode();
	}

	/**
	 * @param string|null $mode Defaults to the active mode.
	 * @return string
	 */
	public static function get_api_key( $mode = null ) {
		$mode = $mode ? $mode : self::get_mode();
		return trim( (string) self::get_setting( 'sandbox' === $mode ? 'test_api_key' : 'live_api_key' ) );
	}

	/**
	 * @param string|null $mode Defaults to the active mode.
	 * @return string
	 */
	public static function get_webhook_secret( $mode = null ) {
		$mode = $mode ? $mode : self::get_mode();
		return trim( (string) self::get_setting( 'sandbox' === $mode ? 'test_webhook_secret' : 'live_webhook_secret' ) );
	}

	/**
	 * @return bool
	 */
	public static function has_keys() {
		return '' !== self::get_api_key();
	}

	/**
	 * @param string|null $mode Defaults to the active mode.
	 * @return string
	 */
	public static function api_base_url( $mode = null ) {
		$mode = $mode ? $mode : self::get_mode();
		return 'sandbox' === $mode ? 'https://sandbox-api.bachs.io' : 'https://api.bachs.io';
	}

	/**
	 * The Bachs dashboard is a single app for both environments.
	 *
	 * @return string
	 */
	public static function dashboard_base_url() {
		return 'https://app.bachs.io';
	}

	/*
	|--------------------------------------------------------------------------
	| Currencies
	|--------------------------------------------------------------------------
	*/

	/**
	 * Fiat currencies Bachs can price a checkout in.
	 *
	 * Uses the live list from GET /v1/currencies/supported, cached by
	 * maybe_refresh_supported_currencies(), and the documented list until then. Crypto
	 * codes are left out: they can't be used as a price currency.
	 *
	 * @return array
	 */
	public static function supported_currencies() {
		$cached     = get_transient( self::CURRENCIES_TRANSIENT . self::get_mode() );
		$currencies = ( is_array( $cached ) && ! empty( $cached ) ) ? $cached : self::FALLBACK_CURRENCIES;

		return array_values( array_unique( (array) apply_filters( 'bachs_wc_supported_currencies', $currencies ) ) );
	}

	/**
	 * @param string|null $currency Defaults to the store currency.
	 * @return bool
	 */
	public static function is_currency_supported( $currency = null ) {
		if ( null === $currency ) {
			$currency = get_woocommerce_currency();
		}
		return in_array( strtoupper( (string) $currency ), self::supported_currencies(), true );
	}

	/**
	 * Refresh the cached supported-currency list. Runs in the admin only, so a slow or failed
	 * request never holds up the storefront.
	 *
	 * @param bool $force Fetch even when a cached list exists.
	 */
	public static function maybe_refresh_supported_currencies( $force = false ) {
		if ( wp_doing_ajax() ) {
			return;
		}

		$mode = self::get_mode();
		$key  = self::CURRENCIES_TRANSIENT . $mode;

		if ( ! $force && false !== get_transient( $key ) ) {
			return;
		}

		$currencies = self::fetch_supported_currencies( $mode );

		if ( empty( $currencies ) ) {
			// Try again in an hour; the documented list is used meanwhile.
			set_transient( $key, self::FALLBACK_CURRENCIES, HOUR_IN_SECONDS );
			return;
		}

		set_transient( $key, $currencies, 12 * HOUR_IN_SECONDS );
	}

	/**
	 * @param string $mode
	 * @return array Upper-case fiat codes, or an empty array on failure.
	 */
	protected static function fetch_supported_currencies( $mode ) {
		try {
			$response = Bachs_WC_API::get_client( $mode )->make_request( 'currencies/supported', array(), array(), 'GET', 10 );
		} catch ( Exception $e ) {
			return array();
		}

		if ( ! is_object( $response ) || empty( $response->fiat ) || ! is_array( $response->fiat ) ) {
			return array();
		}

		$codes = array();
		foreach ( $response->fiat as $code ) {
			if ( is_string( $code ) && preg_match( '/^[A-Z]{3}$/', strtoupper( $code ) ) ) {
				$codes[] = strtoupper( $code );
			}
		}

		return array_values( array_unique( $codes ) );
	}

	/*
	|--------------------------------------------------------------------------
	| Amounts
	|--------------------------------------------------------------------------
	*/

	/**
	 * Number of decimals Bachs expects for a currency.
	 *
	 * @param string $currency
	 * @return int
	 */
	public static function currency_decimals( $currency ) {
		$decimals = in_array( strtoupper( (string) $currency ), self::ZERO_DECIMAL_CURRENCIES, true ) ? 0 : 2;
		return (int) apply_filters( 'bachs_wc_currency_decimals', $decimals, $currency );
	}

	/**
	 * Bachs expects money as a decimal string at the currency's precision, e.g. "29.00".
	 *
	 * @param mixed  $amount
	 * @param string $currency
	 * @return string
	 */
	public static function format_amount( $amount, $currency = '' ) {
		return number_format( (float) $amount, self::currency_decimals( $currency ), '.', '' );
	}

	/**
	 * An amount in the currency's smallest unit, for exact comparisons.
	 *
	 * @param mixed  $amount
	 * @param string $currency
	 * @return int
	 */
	public static function to_minor_units( $amount, $currency ) {
		return (int) round( (float) $amount * pow( 10, self::currency_decimals( $currency ) ) );
	}

	/**
	 * Compare two amounts in the same currency: -1, 0 or 1.
	 *
	 * @param mixed  $a
	 * @param mixed  $b
	 * @param string $currency
	 * @return int
	 */
	public static function compare_amounts( $a, $b, $currency ) {
		$a = self::to_minor_units( $a, $currency );
		$b = self::to_minor_units( $b, $currency );
		return $a <=> $b;
	}

	/*
	|--------------------------------------------------------------------------
	| Webhook signatures
	|--------------------------------------------------------------------------
	*/

	/**
	 * Verify a delivery against the sandbox and live signing secrets.
	 *
	 * Trying both means events for orders placed before a mode switch still verify.
	 *
	 * @param string $raw_body
	 * @param string $timestamp X-Bachs-Timestamp.
	 * @param string $signature X-Bachs-Signature.
	 * @param string $sig_v2    X-Bachs-Signature-V2.
	 * @return string|false The mode whose secret matched, or false.
	 */
	public static function verify_webhook( $raw_body, $timestamp, $signature, $sig_v2 ) {
		if ( '' === (string) $raw_body ) {
			return false;
		}

		$modes = array_unique( array( self::get_mode(), 'sandbox', 'live' ) );

		foreach ( $modes as $mode ) {
			$secret = self::get_webhook_secret( $mode );
			if ( '' === $secret ) {
				continue;
			}
			// Prefer V2, which carries every valid signature during a secret rotation.
			if ( self::verify_webhook_signature_v2( $raw_body, $sig_v2, $secret ) || self::verify_webhook_signature( $raw_body, $timestamp, $signature, $secret ) ) {
				return $mode;
			}
		}

		return false;
	}

	/**
	 * Verify a Bachs webhook signature.
	 *
	 * Bachs signs each delivery with two headers: X-Bachs-Timestamp (unix seconds) and
	 * X-Bachs-Signature (HMAC-SHA256 hex digest of "{timestamp}.{raw_body}").
	 *
	 * @param string $raw_body
	 * @param string $timestamp_header
	 * @param string $signature_header
	 * @param string $secret
	 * @return bool
	 */
	public static function verify_webhook_signature( $raw_body, $timestamp_header, $signature_header, $secret ) {

		if ( empty( $secret ) || empty( $timestamp_header ) || empty( $signature_header ) || ! ctype_digit( (string) $timestamp_header ) ) {
			return false;
		}

		if ( abs( time() - (int) $timestamp_header ) > self::MAX_TIMESTAMP_DRIFT ) {
			return false;
		}

		$computed = hash_hmac( 'sha256', $timestamp_header . '.' . $raw_body, $secret );

		return hash_equals( $computed, strtolower( (string) $signature_header ) );
	}

	/**
	 * Verify a Bachs X-Bachs-Signature-V2 header: "t={timestamp},v1={signature}[,v1=...]".
	 *
	 * It carries the same digest as X-Bachs-Signature, plus one v1 entry per valid secret
	 * while a signing secret is being rotated, so any matching v1 is accepted.
	 *
	 * @param string $raw_body
	 * @param string $header
	 * @param string $secret
	 * @return bool
	 */
	public static function verify_webhook_signature_v2( $raw_body, $header, $secret ) {

		if ( empty( $secret ) || empty( $header ) ) {
			return false;
		}

		$timestamp  = '';
		$signatures = array();

		foreach ( explode( ',', $header ) as $part ) {
			$pair = explode( '=', trim( $part ), 2 );
			if ( 2 !== count( $pair ) ) {
				continue;
			}
			if ( 't' === $pair[0] ) {
				$timestamp = $pair[1];
			} elseif ( 'v1' === $pair[0] ) {
				$signatures[] = $pair[1];
			}
		}

		foreach ( $signatures as $signature ) {
			if ( self::verify_webhook_signature( $raw_body, $timestamp, $signature, $secret ) ) {
				return true;
			}
		}

		return false;
	}
}
