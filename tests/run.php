<?php
/**
 * Plain-PHP tests for the payment, webhook and refund logic. Run: php tests/run.php
 *
 * @package Bachs_WooCommerce_Payment_Gateway
 */

// phpcs:ignoreFile

require __DIR__ . '/bootstrap.php';

/**
 * Exposes the gateway's protected methods to the tests.
 */
class Testable_Gateway extends Bachs_WC_Gateway {
	public function __call( $name, $args ) {
		return $this->{$name}( ...$args );
	}
}

$failures = 0;
$passes   = 0;

function check( $label, $condition ) {
	global $failures, $passes;
	if ( $condition ) {
		++$passes;
		return;
	}
	++$failures;
	echo "FAIL: {$label}\n";
}

function setup( $settings = array() ) {
	bachs_test_reset();
	update_option(
		'woocommerce_bachs_settings',
		array_merge(
			array(
				'enabled'             => 'yes',
				'testmode'            => 'yes',
				'test_api_key'        => 'sk_sandbox_123',
				'test_webhook_secret' => 'whsec_sandbox',
				'live_api_key'        => 'sk_live_123',
				'live_webhook_secret' => 'whsec_live',
				'checkout_type'       => 'redirect',
			),
			$settings
		)
	);
	return new Testable_Gateway();
}

function make_order( $id = 1, $total = '100.00', $currency = 'USD' ) {
	$order                                = new Fake_Order( $id );
	$order->total                         = $total;
	$order->currency                      = $currency;
	$GLOBALS['bachs_test']['orders'][ $id ] = $order;
	return $order;
}

function event( $id, $type, $data ) {
	return array( (object) $data, (object) array( 'id' => $id, 'type' => $type ) );
}

function succeeded_data( $overrides = array() ) {
	return array_merge(
		array(
			'checkout_id'         => 'chk_1',
			'charge_id'           => 'ch_1',
			'status'              => 'SUCCEEDED',
			'amount'              => '100.00',
			'currency'            => 'USD',
			'settlement_amount'   => '98.50',
			'settlement_currency' => 'USD',
			'metadata'            => (object) array( 'order_id' => '1' ),
		),
		$overrides
	);
}

function last_note( $order ) {
	return end( $order->notes );
}

// --- Checkout session creation ----------------------------------------------------

$gw    = setup();
$order = make_order( 1, '100.00', 'USD' );
bachs_test_queue_response( 201, array( 'checkout_id' => 'chk_1', 'checkout_url' => 'https://checkout.bachs.io/c/chk_1', 'status' => 'open' ) );
$result = $gw->process_payment( 1 );
$req    = bachs_test_requests()[0];

check( 'new session: success redirect to checkout', 'success' === $result['result'] && 'https://checkout.bachs.io/c/chk_1' === $result['redirect'] );
check( 'new session: uses ad-hoc pricing, no product', isset( $req['body']['pricing'] ) && ! isset( $req['body']['product_cart'] ) );
check( 'new session: amount and currency', '100.00' === $req['body']['pricing']['amount'] && 'USD' === $req['body']['pricing']['currency'] );
check( 'new session: only one API call (no product calls)', 1 === count( bachs_test_requests() ) );
check( 'new session: idempotency key from reference', 'bachs-wc-checkout-wc_order_key1' === $req['headers']['Idempotency-Key'] );
check( 'new session: sandbox URL', 0 === strpos( $req['url'], 'https://sandbox-api.bachs.io/v1/checkout-sessions' ) );
check( 'new session: session recorded with amount', '100.00' === $order->meta['_bachs_sessions']['chk_1']['amount'] && 'sandbox' === $order->meta['_bachs_sessions']['chk_1']['mode'] );

// Zero-decimal currency.
$gw    = setup();
$order = make_order( 1, '25000', 'UGX' );
bachs_test_queue_response( 201, array( 'checkout_id' => 'chk_1', 'checkout_url' => 'https://checkout.bachs.io/c/chk_1' ) );
$gw->process_payment( 1 );
check( 'UGX amount has no decimals', '25000' === bachs_test_requests()[0]['body']['pricing']['amount'] );

// Unsupported currency.
$gw    = setup();
$order = make_order( 1, '10.00', 'EUR' );
$result = $gw->process_payment( 1 );
check( 'unsupported currency fails without API call', 'failure' === $result['result'] && 0 === count( bachs_test_requests() ) );

// Reuse an open session for the same total.
$gw    = setup();
$order = make_order();
$order->meta['_bachs_checkout_id'] = 'chk_1';
$order->meta['_bachs_sessions']    = array( 'chk_1' => array( 'amount' => '100.00', 'currency' => 'USD', 'mode' => 'sandbox' ) );
bachs_test_queue_response( 200, array( 'status' => 'open', 'checkout_url' => 'https://checkout.bachs.io/c/chk_1', 'expires_at' => gmdate( 'c', time() + 3600 ) ) );
$result = $gw->process_payment( 1 );
check( 'open session reused', 'https://checkout.bachs.io/c/chk_1' === $result['redirect'] && 1 === count( bachs_test_requests() ) );

// Open session but the order total changed: new session with the next reference.
$gw    = setup();
$order = make_order( 1, '150.00' );
$order->meta['_bachs_checkout_id']       = 'chk_1';
$order->meta['_bachs_checkout_attempts'] = 1;
$order->meta['_bachs_sessions']          = array( 'chk_1' => array( 'amount' => '100.00', 'currency' => 'USD', 'mode' => 'sandbox' ) );
bachs_test_queue_response( 200, array( 'status' => 'open', 'checkout_url' => 'https://checkout.bachs.io/c/chk_1', 'expires_at' => gmdate( 'c', time() + 3600 ) ) );
bachs_test_queue_response( 201, array( 'checkout_id' => 'chk_2', 'checkout_url' => 'https://checkout.bachs.io/c/chk_2' ) );
$result = $gw->process_payment( 1 );
$req    = bachs_test_requests()[1];
check( 'changed total: new session created', 'https://checkout.bachs.io/c/chk_2' === $result['redirect'] );
check( 'changed total: new amount and reference', '150.00' === $req['body']['pricing']['amount'] && 'wc_order_key1-2' === $req['body']['reference'] );

// Completed session (webhook pending): no second session.
$gw    = setup();
$order = make_order();
$order->meta['_bachs_checkout_id'] = 'chk_1';
$order->meta['_bachs_sessions']    = array( 'chk_1' => array( 'amount' => '100.00', 'currency' => 'USD', 'mode' => 'sandbox' ) );
bachs_test_queue_response( 200, array( 'status' => 'complete' ) );
$result = $gw->process_payment( 1 );
check( 'completed session: no new session', 1 === count( bachs_test_requests() ) );
check( 'completed session: sent to order-received', false !== strpos( $result['redirect'], 'order-received' ) );

// Session lookup fails: refuse rather than risk a double charge.
$gw    = setup();
$order = make_order();
$order->meta['_bachs_checkout_id'] = 'chk_1';
bachs_test_queue_response( 500, array( 'detail' => 'boom' ) );
$result = $gw->process_payment( 1 );
check( 'session lookup failure: payment refused', 'failure' === $result['result'] && 1 === count( bachs_test_requests() ) );

// Expired session: new one.
$gw    = setup();
$order = make_order();
$order->meta['_bachs_checkout_id']       = 'chk_1';
$order->meta['_bachs_checkout_attempts'] = 1;
bachs_test_queue_response( 200, array( 'status' => 'expired' ) );
bachs_test_queue_response( 201, array( 'checkout_id' => 'chk_2', 'checkout_url' => 'https://checkout.bachs.io/c/chk_2' ) );
$result = $gw->process_payment( 1 );
check( 'expired session: new session', 'https://checkout.bachs.io/c/chk_2' === $result['redirect'] );

// Idempotency conflict: retry with next reference.
$gw    = setup();
$order = make_order();
bachs_test_queue_response( 409, array( 'error_code' => 'IDEMPOTENCY_CONFLICT', 'detail' => 'Idempotency-Key was already used with a different request.' ) );
bachs_test_queue_response( 201, array( 'checkout_id' => 'chk_2', 'checkout_url' => 'https://checkout.bachs.io/c/chk_2' ) );
$result = $gw->process_payment( 1 );
check( 'idempotency conflict: retried with next reference', 'wc_order_key1-2' === bachs_test_requests()[1]['body']['reference'] && 'success' === $result['result'] );

// --- collection.succeeded ------------------------------------------------------------

function paid_order_setup( $total = '100.00', $currency = 'USD' ) {
	$gw    = setup();
	$order = make_order( 1, $total, $currency );
	$order->meta['_bachs_checkout_id'] = 'chk_1';
	$order->meta['_bachs_sessions']    = array( 'chk_1' => array( 'amount' => $total, 'currency' => $currency, 'mode' => 'sandbox' ) );
	return array( $gw, $order );
}

list( $gw, $order ) = paid_order_setup();
list( $data, $evt ) = event( 'evt_1', 'collection.succeeded', succeeded_data() );
$gw->handle_collection_succeeded( $data, $evt );
check( 'matching payment completes the order', 1 === $order->completed && 'processing' === $order->status && 'ch_1' === $order->transaction );
check( 'settlement currency stored', 'USD' === $order->meta['_bachs_settlement_currency'] );

$notes = count( $order->notes );
$gw->handle_collection_succeeded( $data, $evt );
check( 'redelivered event is ignored', 1 === $order->completed && count( $order->notes ) === $notes );

list( $gw, $order ) = paid_order_setup();
list( $data, $evt ) = event( 'evt_1', 'collection.succeeded', succeeded_data( array( 'amount' => '60.00' ) ) );
$gw->handle_collection_succeeded( $data, $evt );
check( 'short payment in order currency is held', 0 === $order->completed && 'on-hold' === $order->status );

list( $gw, $order ) = paid_order_setup();
list( $data, $evt ) = event( 'evt_1', 'collection.succeeded', succeeded_data( array( 'status' => 'ACCEPTED' ) ) );
$gw->handle_collection_succeeded( $data, $evt );
check( 'ACCEPTED status is held', 0 === $order->completed && 'on-hold' === $order->status );

list( $gw, $order ) = paid_order_setup();
list( $data, $evt ) = event( 'evt_1', 'collection.succeeded', succeeded_data( array( 'checkout_id' => 'chk_other' ) ) );
$gw->handle_collection_succeeded( $data, $evt );
check( 'unknown checkout session is held', 0 === $order->completed && 'on-hold' === $order->status );

list( $gw, $order ) = paid_order_setup();
$order->total       = '150.00'; // Order edited after the session was created.
list( $data, $evt ) = event( 'evt_1', 'collection.succeeded', succeeded_data() );
$gw->handle_collection_succeeded( $data, $evt );
check( 'session created for an old total is held', 0 === $order->completed && 'on-hold' === $order->status );

list( $gw, $order ) = paid_order_setup();
list( $data, $evt ) = event( 'evt_1', 'collection.succeeded', succeeded_data( array( 'amount' => '13000.00', 'currency' => 'KES' ) ) );
$gw->handle_collection_succeeded( $data, $evt );
check( 'payment in another currency (Adaptive Pricing) completes', 1 === $order->completed );

list( $gw, $order ) = paid_order_setup();
list( $data, $evt ) = event( 'evt_1', 'collection.succeeded', succeeded_data( array( 'status' => 'OVERPAID', 'amount' => '120.00' ) ) );
$gw->handle_collection_succeeded( $data, $evt );
check( 'overpayment completes with a note', 1 === $order->completed && false !== strpos( last_note( $order ), 'overpaid' ) );

// Duplicate charge on a paid order.
list( $gw, $order ) = paid_order_setup();
list( $data, $evt ) = event( 'evt_1', 'collection.succeeded', succeeded_data() );
$gw->handle_collection_succeeded( $data, $evt );
list( $data2, $evt2 ) = event( 'evt_2', 'collection.succeeded', succeeded_data( array( 'charge_id' => 'ch_2', 'checkout_id' => 'chk_2' ) ) );
$gw->handle_collection_succeeded( $data2, $evt2 );
check( 'duplicate charge recorded', isset( $order->meta['_bachs_duplicate_charges']['ch_2'] ) && 1 === $order->completed );
check( 'duplicate charge note added', false !== strpos( last_note( $order ), 'Duplicate payment' ) );
check( 'duplicate charge action fired', in_array( 'bachs_wc_duplicate_charge', $GLOBALS['bachs_test']['actions'], true ) );

// --- Other events -------------------------------------------------------------------

list( $gw, $order ) = paid_order_setup();
list( $data, $evt ) = event( 'evt_1', 'collection.failed', array( 'checkout_id' => 'chk_1', 'metadata' => (object) array( 'order_id' => '1' ) ) );
$gw->handle_collection_failed( $data, $evt );
check( 'collection.failed fails a pending order', 'failed' === $order->status );

list( $gw, $order ) = paid_order_setup();
$order->status      = 'on-hold';
list( $data, $evt ) = event( 'evt_1', 'collection.failed', array( 'checkout_id' => 'chk_1', 'metadata' => (object) array( 'order_id' => '1' ) ) );
$gw->handle_collection_failed( $data, $evt );
check( 'collection.failed leaves an on-hold order alone', 'on-hold' === $order->status );

list( $gw, $order ) = paid_order_setup();
list( $data, $evt ) = event( 'evt_1', 'collection.underpaid', array( 'checkout_id' => 'chk_1', 'charge_id' => 'ch_9', 'amount_paid' => '50.00', 'amount_expected' => '100.00', 'amount_remaining' => '50.00', 'currency' => 'USD', 'settlement_currency' => 'USD', 'metadata' => (object) array( 'order_id' => '1' ) ) );
$gw->handle_collection_underpaid( $data, $evt );
check( 'underpaid holds the order and stores the charge', 'on-hold' === $order->status && 'ch_9' === $order->transaction );

// --- Refunds ------------------------------------------------------------------------

function refundable_order( $settlement = 'USD', $currency = 'USD' ) {
	$gw    = setup();
	$order = make_order( 1, '100.00', $currency );
	$order->status                               = 'processing';
	$order->transaction                          = 'ch_1';
	$order->meta['_bachs_charge_id']             = 'ch_1';
	$order->meta['_bachs_settlement_currency']   = $settlement;
	$order->meta['_bachs_mode']                  = 'sandbox';
	$order->refunds                              = array( 'refund' );
	return array( $gw, $order );
}

list( $gw, $order ) = refundable_order();
bachs_test_queue_response( 201, array( 'refund_id' => 'rf_1', 'status' => 'processing' ) );
$result = $gw->process_refund( 1, 40, 'Damaged' );
$req    = bachs_test_requests()[0];
check( 'partial refund in settlement currency is sent', true === $result && '40.00' === $req['body']['amount'] );
check( 'refund has stable idempotency key and reference', $req['body']['reference'] === $req['body']['idempotency_key'] && $req['headers']['Idempotency-Key'] === $req['body']['reference'] );
check( 'refund ID stored', 'rf_1' === $order->meta['_bachs_refund_id'] );

$result = $gw->process_refund( 1, 10, '' );
check( 'second refund blocked locally', $result instanceof WP_Error && 1 === count( bachs_test_requests() ) );

list( $data, $evt ) = event( 'evt_r1', 'refund.failed', array( 'refund_id' => 'rf_1', 'charge_id' => 'ch_1' ) );
$gw->handle_refund_failed( $data, $evt );
check( 'refund.failed allows another refund', 'failed' === $order->meta['_bachs_refund_status'] );
bachs_test_queue_response( 201, array( 'refund_id' => 'rf_2', 'status' => 'processing' ) );
check( 'refund allowed after failure', true === $gw->process_refund( 1, 10, '' ) );

list( $gw, $order ) = refundable_order( 'USD', 'NGN' );
$result = $gw->process_refund( 1, 40, '' );
check( 'partial refund blocked when settlement currency differs', $result instanceof WP_Error && 0 === count( bachs_test_requests() ) );

list( $gw, $order ) = refundable_order( 'USD', 'NGN' );
bachs_test_queue_response( 201, array( 'refund_id' => 'rf_1' ) );
$result = $gw->process_refund( 1, 100, '' );
check( 'full refund omits amount even across currencies', true === $result && ! isset( bachs_test_requests()[0]['body']['amount'] ) );

list( $gw, $order ) = refundable_order( '', 'USD' );
$result = $gw->process_refund( 1, 10, '' );
check( 'partial refund blocked when settlement currency unknown', $result instanceof WP_Error );

list( $gw, $order ) = refundable_order();
update_option( 'woocommerce_bachs_settings', array_merge( get_option( 'woocommerce_bachs_settings' ), array( 'testmode' => 'no' ) ) );
bachs_test_queue_response( 201, array( 'refund_id' => 'rf_1' ) );
$gw->process_refund( 1, 100, '' );
check( 'sandbox order refunded against sandbox after going live', 0 === strpos( bachs_test_requests()[0]['url'], 'https://sandbox-api.bachs.io' ) );

// --- Signatures ---------------------------------------------------------------------

setup();
$body = '{"id":"evt_1","type":"collection.succeeded","data":{}}';
$ts   = (string) time();
$sig  = hash_hmac( 'sha256', $ts . '.' . $body, 'whsec_sandbox' );
check( 'v1 signature verifies (sandbox)', 'sandbox' === Bachs_WC_Helpers::verify_webhook( $body, $ts, $sig, '' ) );
check( 'v2 signature with rotation verifies', 'sandbox' === Bachs_WC_Helpers::verify_webhook( $body, '', '', "t={$ts},v1=deadbeef,v1={$sig}" ) );
check( 'tampered body rejected', false === Bachs_WC_Helpers::verify_webhook( $body . ' ', $ts, $sig, '' ) );
$old = (string) ( time() - 1000 );
check( 'stale timestamp rejected', false === Bachs_WC_Helpers::verify_webhook( $body, $old, hash_hmac( 'sha256', $old . '.' . $body, 'whsec_sandbox' ), '' ) );
$live_sig = hash_hmac( 'sha256', $ts . '.' . $body, 'whsec_live' );
check( 'live-secret event verifies while in test mode', 'live' === Bachs_WC_Helpers::verify_webhook( $body, $ts, $live_sig, '' ) );

// --- Currencies ---------------------------------------------------------------------

setup();
check( 'fallback list includes KES and XOF', Bachs_WC_Helpers::is_currency_supported( 'KES' ) && Bachs_WC_Helpers::is_currency_supported( 'XOF' ) );
check( 'EUR not supported by default', ! Bachs_WC_Helpers::is_currency_supported( 'EUR' ) );
bachs_test_queue_response( 200, array( 'fiat' => array( 'USD', 'NGN', 'ZAR' ), 'crypto' => array( 'USDT_TRC20' ) ) );
Bachs_WC_Helpers::maybe_refresh_supported_currencies( true );
check( 'live list replaces fallback', Bachs_WC_Helpers::is_currency_supported( 'ZAR' ) && ! Bachs_WC_Helpers::is_currency_supported( 'KES' ) );
check( 'crypto codes not treated as price currencies', ! Bachs_WC_Helpers::is_currency_supported( 'USDT_TRC20' ) );
check( 'currency list fetched with GET', 'GET' === bachs_test_requests()[0]['method'] && false !== strpos( bachs_test_requests()[0]['url'], '/v1/currencies/supported' ) );

setup();
bachs_test_queue_response( 503, 'unavailable' );
Bachs_WC_Helpers::maybe_refresh_supported_currencies( true );
check( 'failed fetch keeps the documented list', Bachs_WC_Helpers::is_currency_supported( 'GHS' ) );

echo "\n{$passes} passed, {$failures} failed\n";
exit( $failures ? 1 : 0 );
