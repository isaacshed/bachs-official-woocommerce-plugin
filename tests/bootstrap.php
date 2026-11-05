<?php
/**
 * Minimal WordPress / WooCommerce stand-ins so the gateway logic can be tested with plain PHP.
 *
 * Only what the plugin calls is implemented. HTTP calls go through wp_remote_request(), which
 * replays responses queued with bachs_test_queue_response() and records each request.
 *
 * @package Bachs_Payment_Gateway_For_WooCommerce
 */

// phpcs:ignoreFile

define( 'ABSPATH', __DIR__ . '/' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

$GLOBALS['bachs_test'] = array();

function bachs_test_reset() {
	$GLOBALS['bachs_test'] = array(
		'options'    => array(),
		'transients' => array(),
		'responses'  => array(),
		'requests'   => array(),
		'notices'    => array(),
		'logs'       => array(),
		'orders'     => array(),
		'user_meta'  => array(),
		'actions'    => array(),
		'currency'   => 'USD',
	);
}
bachs_test_reset();

function bachs_test_queue_response( $code, $body, $headers = array() ) {
	$GLOBALS['bachs_test']['responses'][] = array(
		'response' => array( 'code' => $code ),
		'body'     => is_string( $body ) ? $body : json_encode( $body ),
		'headers'  => $headers,
	);
}

function bachs_test_requests() {
	return $GLOBALS['bachs_test']['requests'];
}

// --- WordPress ---------------------------------------------------------------

function __( $text, $domain = null ) { return $text; }
function esc_html__( $text, $domain = null ) { return $text; }
function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES ); }
function esc_attr( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES ); }
function esc_url( $url ) { return (string) $url; }
function wp_kses_post( $text ) { return (string) $text; }
function sanitize_text_field( $text ) { return trim( (string) $text ); }
function wp_unslash( $value ) { return $value; }
function absint( $value ) { return abs( (int) $value ); }
function untrailingslashit( $value ) { return rtrim( $value, '/\\' ); }
function home_url() { return 'https://shop.example'; }
function admin_url( $path = '' ) { return 'https://shop.example/wp-admin/' . $path; }
function wp_json_encode( $data ) { return json_encode( $data ); }
function wp_parse_args( $args, $defaults ) { return array_merge( $defaults, $args ); }
function add_query_arg( $args, $url ) { return $url . '?' . http_build_query( $args ); }
function apply_filters( $hook, $value ) { return $value; }
function do_action( $hook, ...$args ) { $GLOBALS['bachs_test']['actions'][] = $hook; }
function add_action() {}
function add_filter() {}
function wp_doing_ajax() { return false; }
function is_wp_error( $thing ) { return $thing instanceof WP_Error; }
function status_header( $code ) { $GLOBALS['bachs_test']['status'] = $code; }

function get_option( $key, $default = false ) {
	return array_key_exists( $key, $GLOBALS['bachs_test']['options'] ) ? $GLOBALS['bachs_test']['options'][ $key ] : $default;
}
function update_option( $key, $value ) { $GLOBALS['bachs_test']['options'][ $key ] = $value; }
function get_transient( $key ) {
	return array_key_exists( $key, $GLOBALS['bachs_test']['transients'] ) ? $GLOBALS['bachs_test']['transients'][ $key ] : false;
}
function set_transient( $key, $value, $ttl = 0 ) { $GLOBALS['bachs_test']['transients'][ $key ] = $value; }
function get_user_meta( $user_id, $key, $single = false ) {
	return isset( $GLOBALS['bachs_test']['user_meta'][ $user_id ][ $key ] ) ? $GLOBALS['bachs_test']['user_meta'][ $user_id ][ $key ] : '';
}
function update_user_meta( $user_id, $key, $value ) { $GLOBALS['bachs_test']['user_meta'][ $user_id ][ $key ] = $value; }

function wp_remote_request( $url, $args ) {
	$GLOBALS['bachs_test']['requests'][] = array(
		'url'     => $url,
		'method'  => $args['method'],
		'headers' => $args['headers'],
		'body'    => isset( $args['body'] ) ? json_decode( $args['body'], true ) : null,
	);
	if ( empty( $GLOBALS['bachs_test']['responses'] ) ) {
		throw new RuntimeException( 'Unexpected HTTP request: ' . $args['method'] . ' ' . $url );
	}
	return array_shift( $GLOBALS['bachs_test']['responses'] );
}
function wp_remote_retrieve_response_code( $response ) { return $response['response']['code']; }
function wp_remote_retrieve_body( $response ) { return $response['body']; }
function wp_remote_retrieve_header( $response, $name ) { return isset( $response['headers'][ $name ] ) ? $response['headers'][ $name ] : ''; }

class WP_Error {
	public $code;
	public $message;
	public function __construct( $code = '', $message = '' ) {
		$this->code    = $code;
		$this->message = $message;
	}
	public function get_error_message() { return $this->message; }
}

// --- WooCommerce ---------------------------------------------------------------

function get_woocommerce_currency() { return $GLOBALS['bachs_test']['currency']; }
function wc_add_notice( $message, $type = 'success' ) { $GLOBALS['bachs_test']['notices'][] = array( $type, $message ); }
function wc_price( $amount, $args = array() ) { return $amount . ' ' . ( isset( $args['currency'] ) ? $args['currency'] : '' ); }
function wc_get_order( $id ) { return isset( $GLOBALS['bachs_test']['orders'][ $id ] ) ? $GLOBALS['bachs_test']['orders'][ $id ] : false; }
function wc_get_order_id_by_order_key( $key ) {
	foreach ( $GLOBALS['bachs_test']['orders'] as $order ) {
		if ( $order->get_order_key() === $key ) {
			return $order->get_id();
		}
	}
	return 0;
}
function wc_get_orders( $args ) {
	$found = array();
	foreach ( $GLOBALS['bachs_test']['orders'] as $order ) {
		if ( isset( $args['payment_method'] ) && $order->get_payment_method() !== $args['payment_method'] ) {
			continue;
		}
		if ( isset( $args['transaction_id'] ) && $order->get_transaction_id() !== $args['transaction_id'] ) {
			continue;
		}
		if ( isset( $args['meta_key'] ) && $order->get_meta( $args['meta_key'] ) !== $args['meta_value'] ) {
			continue;
		}
		$found[] = $order;
	}
	return array_slice( $found, 0, $args['limit'] );
}
function wc_get_logger() {
	return new class() {
		public function log( $level, $message ) { $GLOBALS['bachs_test']['logs'][] = array( $level, $message ); }
	};
}
function WC() {
	return new class() {
		public function api_request_url( $id ) { return 'https://shop.example/wc-api/' . $id . '/'; }
	};
}

class WC_Payment_Gateway {
	public $id;
	public $title;
	public $description;
	public $enabled;
	public $icon;
	public $has_fields;
	public $supports = array();
	public $form_fields = array();
	public $settings = array();
	public $method_title;
	public $method_description;

	public function init_settings() {
		$this->settings = get_option( 'woocommerce_' . $this->id . '_settings', array() );
	}
	public function get_option( $key, $default = '' ) {
		return isset( $this->settings[ $key ] ) ? $this->settings[ $key ] : $default;
	}
	public function get_return_url( $order ) { return 'https://shop.example/checkout/order-received/' . $order->get_id() . '/'; }
	public function get_title() { return $this->title; }
}

class WC_Order {}

class Fake_Order extends WC_Order {
	public $id;
	public $status       = 'pending';
	public $total        = '100.00';
	public $currency     = 'USD';
	public $transaction  = '';
	public $meta         = array();
	public $notes        = array();
	public $refunds      = array();
	public $completed    = 0;
	public $saves        = 0;
	public $payment      = 'bachs';
	public $created      = null;

	public function __construct( $id ) { $this->id = $id; }
	public function get_id() { return $this->id; }
	public function get_order_number() { return (string) $this->id; }
	public function get_order_key() { return 'wc_order_key' . $this->id; }
	public function get_total() { return $this->total; }
	public function get_currency() { return $this->currency; }
	public function get_payment_method() { return $this->payment; }
	public function get_transaction_id() { return $this->transaction; }
	public function set_transaction_id( $id ) { $this->transaction = $id; }
	public function get_meta( $key ) { return isset( $this->meta[ $key ] ) ? $this->meta[ $key ] : ''; }
	public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
	public function delete_meta_data( $key ) { unset( $this->meta[ $key ] ); }
	public function get_date_created() { return new DateTime( '@' . ( null === $this->created ? time() : $this->created ) ); }
	public function save() { ++$this->saves; }
	public function has_status( $status ) { return in_array( $this->status, (array) $status, true ); }
	public function is_paid() { return in_array( $this->status, array( 'processing', 'completed' ), true ); }
	public function update_status( $status, $note = '' ) {
		$this->status = $status;
		if ( $note ) {
			$this->notes[] = $note;
		}
		$this->save();
	}
	public function payment_complete( $transaction_id = '' ) {
		++$this->completed;
		$this->status      = 'processing';
		$this->transaction = $transaction_id;
		$this->save();
	}
	public function add_order_note( $note ) { $this->notes[] = $note; }
	public function get_refunds() { return $this->refunds; }
	public function get_customer_id() { return 0; }
	public function get_billing_email() { return 'jane@example.com'; }
	public function get_billing_first_name() { return 'Jane'; }
	public function get_billing_last_name() { return 'Doe'; }
	public function get_billing_phone() { return ''; }
	public function get_checkout_payment_url( $on_checkout = false ) { return 'https://shop.example/checkout/order-pay/' . $this->id . '/'; }
	public function get_cancel_order_url() { return 'https://shop.example/cart/?cancel_order=' . $this->id; }
}

define( 'BACHS_WC_URL', 'https://shop.example/wp-content/plugins/bachs-payment-gateway-for-woocommerce' );
define( 'BACHS_WC_PATH', dirname( __DIR__ ) );
define( 'BACHS_WC_VERSION', 'test' );

require_once BACHS_WC_PATH . '/includes/class-bachs-wc-api.php';
require_once BACHS_WC_PATH . '/includes/class-bachs-wc-helpers.php';
require_once BACHS_WC_PATH . '/includes/class-bachs-wc-gateway.php';
