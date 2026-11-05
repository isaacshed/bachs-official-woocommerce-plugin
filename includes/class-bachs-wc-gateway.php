<?php
/**
 * Bachs WooCommerce payment gateway (hosted checkout, by redirect or popup).
 *
 * @package Bachs_WooCommerce_Payment_Gateway
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Bachs_WC_Gateway extends WC_Payment_Gateway {

	/*
	 * Order meta keys.
	 */
	const META_CHECKOUT_ID          = '_bachs_checkout_id';
	const META_CHECKOUT_URL         = '_bachs_checkout_url';
	const META_CHECKOUT_ATTEMPTS    = '_bachs_checkout_attempts';
	const META_SESSIONS             = '_bachs_sessions';
	const META_MODE                 = '_bachs_mode';
	const META_CHARGE_ID            = '_bachs_charge_id';
	const META_CHARGE_AMOUNT        = '_bachs_charge_amount';
	const META_CHARGE_CURRENCY      = '_bachs_charge_currency';
	const META_SETTLEMENT_AMOUNT    = '_bachs_settlement_amount';
	const META_SETTLEMENT_CURRENCY  = '_bachs_settlement_currency';
	const META_DUPLICATE_CHARGES    = '_bachs_duplicate_charges';
	const META_EVENT_IDS            = '_bachs_event_ids';
	const META_REFUND_ID            = '_bachs_refund_id';
	const META_REFUND_STATUS        = '_bachs_refund_status';

	/**
	 * Don't reuse an open session that expires within this many seconds.
	 */
	const SESSION_REUSE_MARGIN = 300;

	/**
	 * How many processed webhook event IDs to remember per order.
	 */
	const MAX_STORED_EVENT_IDS = 50;

	/**
	 * Checkout session statuses that mean the customer has not paid on the session.
	 */
	const UNPAID_SESSION_STATUSES = array( 'expired', 'canceled', 'cancelled', 'failed' );

	/**
	 * @var bool
	 */
	public $testmode;

	/**
	 * @var bool
	 */
	public $debug;

	/**
	 * 'redirect' or 'popup'.
	 *
	 * @var string
	 */
	public $checkout_type;

	public function __construct() {

		$this->id                 = Bachs_WC_Helpers::GATEWAY_ID;
		$this->method_title       = __( 'Bachs', 'bachs-woocommerce-payment-gateway' );
		$this->method_description = __( 'Bachs for WooCommerce allows your WooCommerce store to accept secure payments from multiple local and global payment channels and currencies. Customers pay on the Bachs hosted checkout, by redirect or in an on-site popup.', 'bachs-woocommerce-payment-gateway' );
		$this->icon               = apply_filters( 'bachs_wc_gateway_icon_url', BACHS_WC_URL . '/assets/images/bachs-icon.png' );
		$this->has_fields         = false;

		$this->supports = array(
			'products',
			'refunds',
		);

		$this->init_form_fields();
		$this->init_settings();

		$this->title         = $this->get_option( 'title' );
		$this->description   = $this->get_option( 'description' );
		$this->enabled       = $this->get_option( 'enabled' );
		$this->testmode      = 'yes' === $this->get_option( 'testmode', 'yes' );
		$this->debug         = 'yes' === $this->get_option( 'debug', 'no' );
		$this->checkout_type = $this->get_option( 'checkout_type', 'redirect' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'refresh_currencies_after_save' ), 20 );
		add_action( 'woocommerce_api_' . $this->id, array( $this, 'process_webhook' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_scripts' ) );
		add_action( 'woocommerce_receipt_' . $this->id, array( $this, 'receipt_page' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'payment_scripts' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'checkout_styles' ) );
		add_action( 'woocommerce_pay_order_after_submit', array( $this, 'pay_order_cancel_link' ) );
	}

	/**
	 * Whether the store currency is supported by Bachs.
	 *
	 * @return bool
	 */
	public function is_valid_for_use() {
		return Bachs_WC_Helpers::is_currency_supported( get_woocommerce_currency() );
	}

	/**
	 * @return bool
	 */
	public function is_available() {
		if ( 'yes' !== $this->enabled || ! Bachs_WC_Helpers::has_keys() ) {
			return false;
		}
		return $this->is_valid_for_use();
	}

	/**
	 * Gateway icon for the classic checkout, sized to match the checkout block's label icon.
	 *
	 * @return string
	 */
	public function get_icon() {
		$icon = '';

		if ( $this->icon ) {
			$icon = sprintf(
				'<img src="%1$s" alt="%2$s" height="24" style="height:24px;width:auto;max-width:100px;margin:0;" />',
				esc_url( WC_HTTPS::force_https_url( $this->icon ) ),
				esc_attr( $this->get_title() )
			);
		}

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core WooCommerce filter, applied as WC_Payment_Gateway::get_icon() does.
		return apply_filters( 'woocommerce_gateway_icon', $icon, $this->id );
	}

	/**
	 * The WooCommerce API (wc-api) URL Bachs delivers webhooks to.
	 *
	 * @return string
	 */
	public function get_webhook_url() {
		return WC()->api_request_url( $this->id );
	}

	public function init_form_fields() {

		$this->form_fields = array(
			'enabled'             => array(
				'title'   => __( 'Enable/Disable', 'bachs-woocommerce-payment-gateway' ),
				'label'   => __( 'Enable Bachs', 'bachs-woocommerce-payment-gateway' ),
				'type'    => 'checkbox',
				'default' => 'no',
			),
			'title'               => array(
				'title'       => __( 'Title', 'bachs-woocommerce-payment-gateway' ),
				'type'        => 'text',
				'description' => __( 'Payment method title shown to the customer during checkout.', 'bachs-woocommerce-payment-gateway' ),
				'default'     => __( 'Bachs', 'bachs-woocommerce-payment-gateway' ),
				'desc_tip'    => true,
			),
			'description'         => array(
				'title'       => __( 'Description', 'bachs-woocommerce-payment-gateway' ),
				'type'        => 'textarea',
				'description' => __( 'Payment method description shown to the customer during checkout.', 'bachs-woocommerce-payment-gateway' ),
				'default'     => __( 'Pay securely with Bachs using your preferred local or global payment method and currency.', 'bachs-woocommerce-payment-gateway' ),
				'desc_tip'    => true,
			),
			'checkout_type'       => array(
				'title'       => __( 'Checkout type', 'bachs-woocommerce-payment-gateway' ),
				'type'        => 'select',
				'description' => __( 'Redirect sends the customer to the Bachs hosted checkout page. Popup opens the Bachs checkout in a modal on your site (the customer stays on your store).', 'bachs-woocommerce-payment-gateway' ),
				'default'     => 'redirect',
				'desc_tip'    => true,
				'options'     => array(
					'redirect' => __( 'Redirect (hosted checkout page)', 'bachs-woocommerce-payment-gateway' ),
					'popup'    => __( 'Popup (on-site modal)', 'bachs-woocommerce-payment-gateway' ),
				),
			),
			'testmode'            => array(
				'title'       => __( 'Test mode', 'bachs-woocommerce-payment-gateway' ),
				'label'       => __( 'Enable test (sandbox) mode', 'bachs-woocommerce-payment-gateway' ),
				'type'        => 'checkbox',
				'description' => __( 'Uses the Bachs sandbox environment and your sandbox keys. Uncheck to accept live payments.', 'bachs-woocommerce-payment-gateway' ),
				'default'     => 'yes',
				'desc_tip'    => true,
			),
			'test_api_key'        => array(
				'title'       => __( 'Sandbox API Key', 'bachs-woocommerce-payment-gateway' ),
				'type'        => 'password',
				'description' => __( 'Your Bachs sandbox API key (starts with sk_sandbox_). Find it under Developer → API keys in your Bachs dashboard.', 'bachs-woocommerce-payment-gateway' ),
				'default'     => '',
				'class'       => 'bachs-wc-test-field',
			),
			'test_webhook_secret' => array(
				'title'       => __( 'Sandbox Webhook Signing Secret', 'bachs-woocommerce-payment-gateway' ),
				'type'        => 'password',
				'description' => __( 'The signing secret for your sandbox webhook endpoint, from the Bachs developer portal.', 'bachs-woocommerce-payment-gateway' ),
				'default'     => '',
				'class'       => 'bachs-wc-test-field',
			),
			'live_api_key'        => array(
				'title'       => __( 'Live API Key', 'bachs-woocommerce-payment-gateway' ),
				'type'        => 'password',
				'description' => __( 'Your Bachs live API key (starts with sk_live_). Find it under Developer → API keys in your Bachs dashboard.', 'bachs-woocommerce-payment-gateway' ),
				'default'     => '',
				'class'       => 'bachs-wc-live-field',
			),
			'live_webhook_secret' => array(
				'title'       => __( 'Live Webhook Signing Secret', 'bachs-woocommerce-payment-gateway' ),
				'type'        => 'password',
				'description' => __( 'The signing secret for your live webhook endpoint, from the Bachs developer portal.', 'bachs-woocommerce-payment-gateway' ),
				'default'     => '',
				'class'       => 'bachs-wc-live-field',
			),
			'webhook_url'         => array(
				'title'       => __( 'Webhook URL', 'bachs-woocommerce-payment-gateway' ),
				'type'        => 'bachs_webhook_url',
				'description' => sprintf(
					/* translators: 1: comma-separated list of webhook event names, 2: opening link tag, 3: closing link tag */
					__( 'Add this URL as a webhook endpoint in your %2$sBachs developer portal%3$s and subscribe to these events: %1$s.', 'bachs-woocommerce-payment-gateway' ),
					'<code>' . implode( ', ', array_keys( self::webhook_events() ) ) . '</code>',
					'<a href="' . esc_url( Bachs_WC_Helpers::dashboard_base_url() ) . '" target="_blank" rel="noopener">',
					'</a>'
				),
			),
			'debug'               => array(
				'title'       => __( 'Debug log', 'bachs-woocommerce-payment-gateway' ),
				'label'       => __( 'Enable logging', 'bachs-woocommerce-payment-gateway' ),
				'type'        => 'checkbox',
				'description' => sprintf(
					/* translators: %s: log location */
					__( 'Log gateway events to %s. Errors are always logged.', 'bachs-woocommerce-payment-gateway' ),
					'<code>WooCommerce → Status → Logs</code>'
				),
				'default'     => 'no',
			),
		);
	}

	/**
	 * Render the read-only webhook URL. It is computed on each load rather than saved, so it
	 * stays correct if the site URL changes.
	 *
	 * @param string $key
	 * @param array  $data
	 * @return string
	 */
	public function generate_bachs_webhook_url_html( $key, $data ) {
		$field_key = $this->get_field_key( $key );

		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<label for="<?php echo esc_attr( $field_key ); ?>"><?php echo esc_html( $data['title'] ); ?></label>
			</th>
			<td class="forminp">
				<fieldset>
					<input class="input-text regular-input" type="text" id="<?php echo esc_attr( $field_key ); ?>" value="<?php echo esc_attr( $this->get_webhook_url() ); ?>" readonly="readonly" onclick="this.select();" />
					<p class="description"><?php echo wp_kses_post( $data['description'] ); ?></p>
				</fieldset>
			</td>
		</tr>
		<?php
		return ob_get_clean();
	}

	/**
	 * The webhook URL is display-only, so never store it.
	 *
	 * @param string $key
	 * @param mixed  $value
	 * @return string
	 */
	public function validate_bachs_webhook_url_field( $key, $value ) {
		return '';
	}

	/**
	 * Re-read the supported currencies when the keys or mode change.
	 */
	public function refresh_currencies_after_save() {
		Bachs_WC_Helpers::maybe_refresh_supported_currencies( true );
	}

	/**
	 * Bachs webhook events this gateway handles.
	 *
	 * @return array
	 */
	public static function webhook_events() {
		return array(
			'collection.succeeded' => 'handle_collection_succeeded',
			'collection.failed'    => 'handle_collection_failed',
			'collection.underpaid' => 'handle_collection_underpaid',
			'checkout.expired'     => 'handle_checkout_expired',
			'refund.paid'          => 'handle_refund_paid',
			'refund.failed'        => 'handle_refund_failed',
		);
	}

	/*
	|--------------------------------------------------------------------------
	| Payment
	|--------------------------------------------------------------------------
	*/

	/**
	 * Create (or reuse) the Bachs checkout session and send the customer to it.
	 *
	 * @param int $order_id
	 * @return array
	 */
	public function process_payment( $order_id ) {

		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return array( 'result' => 'failure' );
		}

		try {

			if ( ! Bachs_WC_Helpers::is_currency_supported( $order->get_currency() ) ) {
				/* translators: %s: currency code */
				throw new Exception( sprintf( __( 'Bachs does not support the %s currency.', 'bachs-woocommerce-payment-gateway' ), $order->get_currency() ) );
			}

			$state = $this->get_session_state( $order );

			if ( 'paid' === $state['action'] ) {
				// The customer already paid on this order's checkout and the webhook hasn't landed
				// yet. Don't open a second session, or they could be charged twice.
				$this->log( sprintf( 'Order #%d: checkout session %s is %s, so no new session was created.', $order_id, $state['checkout_id'], $state['status'] ), 'warning' );

				return array(
					'result'   => 'success',
					'redirect' => $this->get_return_url( $order ),
				);
			}

			if ( 'reuse' === $state['action'] ) {
				$this->log( sprintf( 'Reusing open checkout session %s for order #%d.', $state['checkout_id'], $order_id ) );
				return $this->payment_redirect( $order, $state['checkout_url'] );
			}

			$attempts = $this->get_checkout_attempts( $order );

			try {
				$response = $this->create_checkout_session( $order, $attempts + 1 );
			} catch ( Exception $e ) {
				if ( ! $this->is_reference_conflict() ) {
					throw $e;
				}
				// A session already holds this reference (for example, one created by a request that
				// timed out, or one for an earlier order total). Move on to the next reference once.
				$this->log( sprintf( 'Reference conflict for order #%d, retrying with a new reference: %s', $order_id, $e->getMessage() ), 'warning' );
				++$attempts;
				$response = $this->create_checkout_session( $order, $attempts + 1 );
			}

			if ( ! isset( $response->checkout_url, $response->checkout_id ) ) {
				throw new Exception( sprintf( 'Unexpected response when creating Bachs checkout session: %s', wp_json_encode( $response ) ) );
			}

			$this->record_session( $order, $response, $attempts + 1 );

			$this->log( sprintf( 'Checkout session %s created for order #%d (%s).', $response->checkout_id, $order_id, $this->checkout_type ) );

			return $this->payment_redirect( $order, $response->checkout_url );

		} catch ( Exception $e ) {

			$this->log( sprintf( 'process_payment error for order #%d: %s', $order_id, $e->getMessage() ), 'error' );

			wc_add_notice( __( 'Unable to start the Bachs payment. Please try again.', 'bachs-woocommerce-payment-gateway' ), 'error' );

			return array( 'result' => 'failure' );
		}
	}

	/**
	 * Create a Bachs checkout session priced at the order total.
	 *
	 * The session uses ad-hoc `pricing`, so no Bachs product is created per order. The reference
	 * doubles as the Idempotency-Key: if a request times out after Bachs created the session, the
	 * next identical request returns that session. A changed body (new total or customer details)
	 * gets IDEMPOTENCY_CONFLICT, and the caller moves on to the next reference.
	 *
	 * @param WC_Order $order
	 * @param int      $attempt 1-based session number.
	 * @return object API response.
	 *
	 * @throws Exception On an API error.
	 */
	protected function create_checkout_session( $order, $attempt ) {

		$currency = $order->get_currency();

		$args = apply_filters(
			'bachs_wc_create_checkout_session_args',
			array(
				'pricing'     => array(
					'currency' => $currency,
					'amount'   => Bachs_WC_Helpers::format_amount( $order->get_total(), $currency ),
				),
				'customer'    => $this->get_customer_payload( $order ),
				'success_url' => $this->get_return_url( $order ),
				'cancel_url'  => $order->get_checkout_payment_url( false ),
				'reference'   => $this->get_checkout_reference( $order, $attempt ),
				'metadata'    => array(
					'order_id'     => (string) $order->get_id(),
					'order_number' => (string) $order->get_order_number(),
					'source'       => 'woocommerce',
				),
			),
			$order
		);

		$key = 'bachs-wc-checkout-' . ( isset( $args['reference'] ) ? $args['reference'] : $order->get_id() . '-' . $attempt );

		return Bachs_WC_API::get_client()->make_request( 'checkout-sessions', $args, array( 'Idempotency-Key' => $key ) );
	}

	/**
	 * Store a new checkout session on the order, including the amount it was created for, so the
	 * webhook can check the customer paid the current total.
	 *
	 * @param WC_Order $order
	 * @param object   $response Create-session response.
	 * @param int      $attempt
	 */
	protected function record_session( $order, $response, $attempt ) {

		$sessions = $this->get_sessions( $order );

		$sessions[ (string) $response->checkout_id ] = array(
			'amount'   => Bachs_WC_Helpers::format_amount( $order->get_total(), $order->get_currency() ),
			'currency' => $order->get_currency(),
			'mode'     => Bachs_WC_Helpers::get_mode(),
			'created'  => time(),
		);

		$order->update_meta_data( self::META_SESSIONS, $sessions );
		$order->update_meta_data( self::META_CHECKOUT_ID, (string) $response->checkout_id );
		$order->update_meta_data( self::META_CHECKOUT_URL, (string) $response->checkout_url );
		$order->update_meta_data( self::META_CHECKOUT_ATTEMPTS, $attempt );
		$order->update_meta_data( self::META_MODE, Bachs_WC_Helpers::get_mode() );
	}

	/**
	 * @param WC_Order $order
	 * @return array checkout_id => array( amount, currency, mode, created ).
	 */
	protected function get_sessions( $order ) {
		$sessions = $order->get_meta( self::META_SESSIONS );
		return is_array( $sessions ) ? $sessions : array();
	}

	/**
	 * Whether the last API error means the checkout reference is already taken.
	 *
	 * @return bool
	 */
	protected function is_reference_conflict() {
		$client = Bachs_WC_API::get_client();

		return 'IDEMPOTENCY_CONFLICT' === $client->last_error_code || false !== stripos( $client->last_error_detail, 'duplicate reference' );
	}

	/**
	 * Mark the order as awaiting payment and send the customer to the checkout.
	 *
	 * @param WC_Order $order
	 * @param string   $checkout_url
	 * @return array
	 */
	protected function payment_redirect( $order, $checkout_url ) {

		$is_popup = 'popup' === $this->checkout_type;

		$order->update_meta_data( self::META_CHECKOUT_URL, $checkout_url );
		$order->update_status( 'pending', __( 'Awaiting Bachs payment.', 'bachs-woocommerce-payment-gateway' ) );
		$order->save();

		return array(
			'result'   => 'success',
			'redirect' => $is_popup ? $order->get_checkout_payment_url( true ) : $checkout_url,
		);
	}

	/**
	 * Decide what to do with the order's current checkout session.
	 *
	 * - 'new':   no usable session; create one.
	 * - 'reuse': an open session for the current total; send the customer back to it.
	 * - 'paid':  the session isn't open and isn't known to be unpaid, so the customer has most
	 *            likely paid. Creating another session could charge them twice.
	 *
	 * @param WC_Order $order
	 * @return array
	 *
	 * @throws Exception When the session status can't be checked.
	 */
	protected function get_session_state( $order ) {

		$checkout_id = (string) $order->get_meta( self::META_CHECKOUT_ID );
		$state       = array(
			'action'       => 'new',
			'checkout_id'  => $checkout_id,
			'checkout_url' => '',
			'status'       => '',
		);

		if ( '' === $checkout_id ) {
			return $state;
		}

		$sessions = $this->get_sessions( $order );
		$record   = isset( $sessions[ $checkout_id ] ) ? $sessions[ $checkout_id ] : array();
		$mode     = isset( $record['mode'] ) ? $record['mode'] : $order->get_meta( self::META_MODE );

		try {
			$session = Bachs_WC_API::get_client( $mode )->make_request( 'checkout-sessions/' . rawurlencode( $checkout_id ), array(), array(), 'GET' );
		} catch ( Exception $e ) {
			// Without the status we can't rule out a completed payment, so don't open another session.
			throw new Exception( sprintf( 'Could not retrieve checkout session %s: %s', esc_html( $checkout_id ), esc_html( $e->getMessage() ) ) );
		}

		$status          = is_object( $session ) && isset( $session->status ) ? strtolower( (string) $session->status ) : '';
		$state['status'] = $status;

		if ( in_array( $status, self::UNPAID_SESSION_STATUSES, true ) ) {
			return $state;
		}

		if ( 'open' !== $status ) {
			$state['action'] = 'paid';
			return $state;
		}

		// Open, but created for a different total (for example, the order was edited). Leave it
		// for a new session; if the customer still pays on the old one, the webhook holds the order.
		if ( ! $this->session_matches_order( $order, $record ) ) {
			return $state;
		}

		if ( ! empty( $session->expires_at ) ) {
			$expires = strtotime( $session->expires_at );
			if ( $expires && $expires - time() < self::SESSION_REUSE_MARGIN ) {
				return $state;
			}
		}

		$checkout_url = ! empty( $session->checkout_url ) ? $session->checkout_url : $order->get_meta( self::META_CHECKOUT_URL );

		if ( empty( $checkout_url ) ) {
			return $state;
		}

		$state['action']       = 'reuse';
		$state['checkout_url'] = $checkout_url;

		return $state;
	}

	/**
	 * Whether a stored session was created for the order's current total and currency.
	 *
	 * @param WC_Order $order
	 * @param array    $record Stored session record.
	 * @return bool
	 */
	protected function session_matches_order( $order, $record ) {
		if ( empty( $record['currency'] ) || ! isset( $record['amount'] ) ) {
			return false;
		}
		return $record['currency'] === $order->get_currency()
			&& 0 === Bachs_WC_Helpers::compare_amounts( $record['amount'], $order->get_total(), $order->get_currency() );
	}

	/**
	 * Number of checkout sessions already created for the order.
	 *
	 * @param WC_Order $order
	 * @return int
	 */
	protected function get_checkout_attempts( $order ) {
		return absint( $order->get_meta( self::META_CHECKOUT_ATTEMPTS ) );
	}

	/**
	 * Bachs reference for a checkout session. References must be unique, so sessions after the
	 * first add the attempt number to the order key (wc_order_abc123-2).
	 *
	 * @param WC_Order $order
	 * @param int      $attempt 1-based session number.
	 * @return string
	 */
	protected function get_checkout_reference( $order, $attempt ) {
		return $attempt > 1 ? $order->get_order_key() . '-' . $attempt : $order->get_order_key();
	}

	/**
	 * Build the checkout-session customer payload, reusing a stored Bachs customer when possible.
	 *
	 * @param WC_Order $order
	 * @return array
	 */
	protected function get_customer_payload( $order ) {

		$user_id = $order->get_customer_id();

		if ( $user_id ) {
			$stored = get_user_meta( $user_id, '_bachs_customer_id_' . Bachs_WC_Helpers::get_mode(), true );
			if ( ! empty( $stored ) ) {
				return array( 'customer_id' => $stored );
			}
		}

		$email = $order->get_billing_email();
		$name  = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );

		$payload = array(
			'email' => $email,
			'name'  => '' !== $name ? $name : $email,
		);

		$phone = $order->get_billing_phone();
		if ( ! empty( $phone ) ) {
			$payload['phone_number'] = $phone;
		}

		return $payload;
	}

	/*
	|--------------------------------------------------------------------------
	| Refunds
	|--------------------------------------------------------------------------
	*/

	/**
	 * Refund a Bachs payment.
	 *
	 * Bachs allows one refund per payment, and reads a partial refund amount in the payment's
	 * settlement currency. A partial refund is therefore only sent when the order was settled in
	 * its own currency; otherwise the merchant is asked to refund from the Bachs dashboard.
	 *
	 * @param int    $order_id
	 * @param float  $amount
	 * @param string $reason
	 * @return bool|WP_Error
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {

		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return new WP_Error( 'bachs_wc_refund_error', __( 'Order not found.', 'bachs-woocommerce-payment-gateway' ) );
		}

		$charge_id = $order->get_meta( self::META_CHARGE_ID );
		if ( empty( $charge_id ) ) {
			$charge_id = $order->get_transaction_id();
		}

		if ( empty( $charge_id ) ) {
			return new WP_Error( 'bachs_wc_refund_error', __( 'No Bachs charge ID is stored on this order, so it cannot be refunded through Bachs.', 'bachs-woocommerce-payment-gateway' ) );
		}

		$previous_refund = (string) $order->get_meta( self::META_REFUND_ID );
		if ( '' !== $previous_refund && 'failed' !== $order->get_meta( self::META_REFUND_STATUS ) ) {
			return new WP_Error(
				'bachs_wc_refund_error',
				sprintf(
					/* translators: %s: Bachs refund ID */
					__( 'This payment already has a Bachs refund (%s). Bachs allows one refund per payment, so it cannot be refunded again through Bachs.', 'bachs-woocommerce-payment-gateway' ),
					$previous_refund
				)
			);
		}

		$currency = $order->get_currency();
		$amount   = is_null( $amount ) ? (float) $order->get_total() : (float) $amount;

		if ( $amount <= 0 ) {
			return new WP_Error( 'bachs_wc_refund_error', __( 'Refund amount must be greater than zero.', 'bachs-woocommerce-payment-gateway' ) );
		}

		$is_full = Bachs_WC_Helpers::compare_amounts( $amount, $order->get_total(), $currency ) >= 0;

		// One stable key per WooCommerce refund, so a retried request can't create a second refund.
		$key = sprintf( 'wc-refund-%d-%d-%d', $order->get_id(), count( $order->get_refunds() ), Bachs_WC_Helpers::to_minor_units( $amount, $currency ) );

		$payload = array(
			'charge_id'       => $charge_id,
			'reference'       => $key,
			'idempotency_key' => $key,
		);

		if ( ! $is_full ) {
			$settlement_currency = strtoupper( (string) $order->get_meta( self::META_SETTLEMENT_CURRENCY ) );

			if ( '' === $settlement_currency ) {
				return new WP_Error( 'bachs_wc_refund_error', __( 'The settlement currency of this Bachs payment is unknown, so a partial refund cannot be sent safely. Refund the full amount, or make the partial refund from your Bachs dashboard.', 'bachs-woocommerce-payment-gateway' ) );
			}

			if ( $settlement_currency !== $currency ) {
				return new WP_Error(
					'bachs_wc_refund_error',
					sprintf(
						/* translators: 1: settlement currency, 2: order currency */
						__( 'This payment settled in %1$s but the order is in %2$s. Bachs reads partial refund amounts in the settlement currency, so make this partial refund from your Bachs dashboard, or refund the full amount here.', 'bachs-woocommerce-payment-gateway' ),
						$settlement_currency,
						$currency
					)
				);
			}

			$payload['amount'] = Bachs_WC_Helpers::format_amount( $amount, $currency );
		}

		if ( '' !== (string) $reason ) {
			$payload['reason'] = $reason;
		}

		try {

			$response = Bachs_WC_API::get_client( $this->get_order_mode( $order ) )->make_request( 'refunds', $payload, array( 'Idempotency-Key' => $key ) );

			$refund_id = isset( $response->refund_id ) ? (string) $response->refund_id : '';
			$status    = isset( $response->status ) ? strtolower( (string) $response->status ) : 'processing';

			$order->update_meta_data( self::META_REFUND_ID, $refund_id );
			$order->update_meta_data( self::META_REFUND_STATUS, $status );
			$order->save();

			$order->add_order_note(
				sprintf(
					/* translators: 1: amount, 2: Bachs refund ID */
					__( 'Bachs refund initiated for %1$s. Refund ID: %2$s. Refunds settle asynchronously and are confirmed by a refund.paid webhook.', 'bachs-woocommerce-payment-gateway' ),
					wc_price( $amount, array( 'currency' => $currency ) ),
					$refund_id
				)
			);

			$this->log( sprintf( 'Refund %s initiated for order #%d.', $refund_id, $order_id ) );

			return true;

		} catch ( Exception $e ) {

			$this->log( sprintf( 'process_refund error for order #%d: %s', $order_id, $e->getMessage() ), 'error' );

			return new WP_Error( 'bachs_wc_refund_error', $e->getMessage() );
		}
	}

	/**
	 * The environment an order was paid in.
	 *
	 * @param WC_Order $order
	 * @return string
	 */
	protected function get_order_mode( $order ) {
		$mode = $order->get_meta( self::META_MODE );
		return in_array( $mode, array( 'sandbox', 'live' ), true ) ? $mode : Bachs_WC_Helpers::get_mode();
	}

	/*
	|--------------------------------------------------------------------------
	| Webhooks
	|--------------------------------------------------------------------------
	*/

	/**
	 * Webhook entry point (wc-api). Verifies the signature then dispatches by event type.
	 *
	 * Responses follow Bachs' retry rules: 400 for a rejected delivery (not retried), 500 when
	 * processing fails (retried), 200 otherwise.
	 */
	public function process_webhook() {

		$raw_body  = file_get_contents( 'php://input' );
		$timestamp = isset( $_SERVER['HTTP_X_BACHS_TIMESTAMP'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_BACHS_TIMESTAMP'] ) ) : '';
		$signature = isset( $_SERVER['HTTP_X_BACHS_SIGNATURE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_BACHS_SIGNATURE'] ) ) : '';
		$sig_v2    = isset( $_SERVER['HTTP_X_BACHS_SIGNATURE_V2'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_BACHS_SIGNATURE_V2'] ) ) : '';

		$mode = Bachs_WC_Helpers::verify_webhook( (string) $raw_body, $timestamp, $signature, $sig_v2 );

		if ( ! $mode ) {
			$this->log( 'Webhook rejected: signature did not verify.', 'warning' );
			$this->end_webhook( 400 );
			return;
		}

		$event = json_decode( $raw_body );

		if ( ! is_object( $event ) || empty( $event->type ) ) {
			$this->end_webhook( 400 );
			return;
		}

		$events = self::webhook_events();

		if ( isset( $events[ $event->type ] ) && isset( $event->data ) && is_object( $event->data ) ) {
			try {
				$handler = $events[ $event->type ];
				$this->{$handler}( $event->data, $event );
				do_action( 'bachs_wc_webhook_event', $event->type, $event, $mode );
			} catch ( Throwable $e ) {
				$this->log( sprintf( 'Webhook %s (%s) failed: %s', $event->type, isset( $event->id ) ? $event->id : '-', $e->getMessage() ), 'error' );
				$this->end_webhook( 500 );
				return;
			}
		}

		$this->end_webhook( 200 );
	}

	/**
	 * Send the webhook response and stop.
	 *
	 * @param int $code
	 */
	protected function end_webhook( $code ) {
		status_header( $code );
		if ( apply_filters( 'bachs_wc_webhook_exit', true ) ) {
			exit;
		}
	}

	/**
	 * Whether this order already processed a webhook event. Bachs delivers at least once.
	 *
	 * @param WC_Order $order
	 * @param object   $event
	 * @return bool
	 */
	protected function is_event_processed( $order, $event ) {
		if ( empty( $event->id ) ) {
			return false;
		}
		$ids = $order->get_meta( self::META_EVENT_IDS );
		return is_array( $ids ) && in_array( (string) $event->id, $ids, true );
	}

	/**
	 * Remember a processed event ID on the order (saved with the order).
	 *
	 * @param WC_Order $order
	 * @param object   $event
	 */
	protected function mark_event_processed( $order, $event ) {
		if ( empty( $event->id ) ) {
			return;
		}
		$ids   = $order->get_meta( self::META_EVENT_IDS );
		$ids   = is_array( $ids ) ? $ids : array();
		$ids[] = (string) $event->id;
		$order->update_meta_data( self::META_EVENT_IDS, array_slice( array_values( array_unique( $ids ) ), -self::MAX_STORED_EVENT_IDS ) );
		$order->save();
	}

	/**
	 * @param object $data
	 * @param object $event
	 */
	protected function handle_collection_succeeded( $data, $event ) {

		$order = $this->resolve_order( $data );

		if ( ! $order || $this->is_event_processed( $order, $event ) ) {
			return;
		}

		$charge_id = isset( $data->charge_id ) ? (string) $data->charge_id : '';

		if ( $this->order_already_paid( $order ) ) {
			$this->maybe_record_duplicate_charge( $order, $data );
			$this->mark_event_processed( $order, $event );
			return;
		}

		// The same charge was already put on hold by an earlier delivery.
		if ( '' !== $charge_id && $charge_id === $order->get_meta( self::META_CHARGE_ID ) && $order->has_status( 'on-hold' ) ) {
			$this->mark_event_processed( $order, $event );
			return;
		}

		$this->store_charge_details( $order, $data );
		$this->maybe_store_customer_id( $order, $data );

		$problem = $this->get_payment_problem( $order, $data );

		if ( '' !== $problem ) {
			if ( '' !== $charge_id ) {
				$order->set_transaction_id( $charge_id );
			}
			$order->update_status(
				'on-hold',
				sprintf(
					/* translators: 1: problem description, 2: Bachs charge ID */
					__( 'Bachs payment received but needs review: %1$s Charge ID: %2$s. Check the payment in your Bachs dashboard before fulfilling this order.', 'bachs-woocommerce-payment-gateway' ),
					$problem,
					'' !== $charge_id ? $charge_id : __( 'n/a', 'bachs-woocommerce-payment-gateway' )
				)
			);
			$this->log( sprintf( 'collection.succeeded put order #%d on hold: %s', $order->get_id(), $problem ), 'warning' );
			$this->mark_event_processed( $order, $event );
			return;
		}

		$order->payment_complete( $charge_id );

		$note = sprintf(
			/* translators: 1: amount and currency paid, 2: Bachs charge ID */
			__( 'Bachs payment of %1$s completed. Charge ID: %2$s', 'bachs-woocommerce-payment-gateway' ),
			$this->describe_amount( $data ),
			'' !== $charge_id ? $charge_id : __( 'n/a', 'bachs-woocommerce-payment-gateway' )
		);

		if ( isset( $data->status ) && 'OVERPAID' === strtoupper( (string) $data->status ) ) {
			$note .= ' ' . __( 'The customer overpaid; see the payment in your Bachs dashboard for the extra amount.', 'bachs-woocommerce-payment-gateway' );
		}

		$order->add_order_note( $note );
		$this->mark_event_processed( $order, $event );

		$this->log( sprintf( 'collection.succeeded completed order #%d.', $order->get_id() ) );
	}

	/**
	 * Check a successful collection against the order before completing it.
	 *
	 * @param WC_Order $order
	 * @param object   $data collection.succeeded data.
	 * @return string Empty when the payment covers the order, otherwise why it needs review.
	 */
	protected function get_payment_problem( $order, $data ) {

		$order_currency = $order->get_currency();
		$order_total    = $order->get_total();
		$status         = isset( $data->status ) ? strtoupper( (string) $data->status ) : '';

		if ( 'ACCEPTED' === $status ) {
			return __( 'Bachs accepted this payment on terms other than the exact amount requested.', 'bachs-woocommerce-payment-gateway' );
		}

		$checkout_id = isset( $data->checkout_id ) ? (string) $data->checkout_id : '';

		if ( '' === $checkout_id ) {
			return __( 'the payment is not linked to a Bachs checkout session.', 'bachs-woocommerce-payment-gateway' );
		}

		$sessions = $this->get_sessions( $order );

		if ( ! isset( $sessions[ $checkout_id ] ) ) {
			/* translators: %s: Bachs checkout ID */
			return sprintf( __( 'the payment came from checkout session %s, which this order did not create.', 'bachs-woocommerce-payment-gateway' ), $checkout_id );
		}

		$record = $sessions[ $checkout_id ];

		if ( ! $this->session_matches_order( $order, $record ) ) {
			return sprintf(
				/* translators: 1: session amount, 2: session currency, 3: order total, 4: order currency */
				__( 'the checkout session was created for %1$s %2$s, but the order total is now %3$s %4$s.', 'bachs-woocommerce-payment-gateway' ),
				isset( $record['amount'] ) ? $record['amount'] : '?',
				isset( $record['currency'] ) ? $record['currency'] : '?',
				Bachs_WC_Helpers::format_amount( $order_total, $order_currency ),
				$order_currency
			);
		}

		// The customer may pay in another currency (Adaptive Pricing); amounts can only be
		// compared directly when they paid in the order currency.
		$paid_currency = isset( $data->currency ) ? strtoupper( (string) $data->currency ) : '';

		if ( $paid_currency === $order_currency && isset( $data->amount ) && is_numeric( $data->amount ) ) {
			if ( Bachs_WC_Helpers::compare_amounts( $data->amount, $order_total, $order_currency ) < 0 ) {
				return sprintf(
					/* translators: 1: amount paid, 2: order total, 3: currency */
					__( 'the customer paid %1$s but the order total is %2$s %3$s.', 'bachs-woocommerce-payment-gateway' ),
					Bachs_WC_Helpers::format_amount( $data->amount, $order_currency ),
					Bachs_WC_Helpers::format_amount( $order_total, $order_currency ),
					$order_currency
				);
			}
		}

		return '';
	}

	/**
	 * Whether the order is already paid for.
	 *
	 * @param WC_Order $order
	 * @return bool
	 */
	protected function order_already_paid( $order ) {
		return $order->is_paid() || $order->has_status( array( 'processing', 'completed', 'refunded' ) );
	}

	/**
	 * A payment arrived for an order that is already paid: tell the merchant so they can refund it.
	 *
	 * @param WC_Order $order
	 * @param object   $data
	 */
	protected function maybe_record_duplicate_charge( $order, $data ) {

		$charge_id = isset( $data->charge_id ) ? (string) $data->charge_id : '';
		$paid_with = (string) $order->get_meta( self::META_CHARGE_ID );

		if ( '' === $paid_with ) {
			$paid_with = (string) $order->get_transaction_id();
		}

		if ( '' === $charge_id || $charge_id === $paid_with ) {
			return;
		}

		$duplicates = $order->get_meta( self::META_DUPLICATE_CHARGES );
		$duplicates = is_array( $duplicates ) ? $duplicates : array();

		if ( isset( $duplicates[ $charge_id ] ) ) {
			return;
		}

		$duplicates[ $charge_id ] = array(
			'amount'      => isset( $data->amount ) ? (string) $data->amount : '',
			'currency'    => isset( $data->currency ) ? (string) $data->currency : '',
			'checkout_id' => isset( $data->checkout_id ) ? (string) $data->checkout_id : '',
			'received'    => time(),
		);

		$order->update_meta_data( self::META_DUPLICATE_CHARGES, $duplicates );
		$order->save();

		$order->add_order_note(
			sprintf(
				/* translators: 1: amount and currency, 2: duplicate charge ID, 3: original charge ID */
				__( 'Duplicate payment: Bachs received another payment of %1$s (charge %2$s) for this order, which was already paid by charge %3$s. Refund the duplicate charge from your Bachs dashboard.', 'bachs-woocommerce-payment-gateway' ),
				$this->describe_amount( $data ),
				$charge_id,
				'' !== $paid_with ? $paid_with : __( 'n/a', 'bachs-woocommerce-payment-gateway' )
			)
		);

		$this->log( sprintf( 'Duplicate charge %s received for already-paid order #%d.', $charge_id, $order->get_id() ), 'error' );

		do_action( 'bachs_wc_duplicate_charge', $order, $data );
	}

	/**
	 * Store the charge and its settlement details on the order. The settlement currency decides
	 * whether a partial refund can be sent from WooCommerce.
	 *
	 * @param WC_Order $order
	 * @param object   $data
	 */
	protected function store_charge_details( $order, $data ) {
		$fields = array(
			'charge_id'           => self::META_CHARGE_ID,
			'amount'              => self::META_CHARGE_AMOUNT,
			'currency'            => self::META_CHARGE_CURRENCY,
			'settlement_amount'   => self::META_SETTLEMENT_AMOUNT,
			'settlement_currency' => self::META_SETTLEMENT_CURRENCY,
		);

		foreach ( $fields as $field => $meta_key ) {
			if ( isset( $data->{$field} ) && is_scalar( $data->{$field} ) && '' !== (string) $data->{$field} ) {
				$order->update_meta_data( $meta_key, (string) $data->{$field} );
			}
		}

		if ( ! $order->get_meta( self::META_MODE ) && isset( $data->checkout_id ) ) {
			$sessions = $this->get_sessions( $order );
			if ( isset( $sessions[ $data->checkout_id ]['mode'] ) ) {
				$order->update_meta_data( self::META_MODE, $sessions[ $data->checkout_id ]['mode'] );
			}
		}
	}

	/**
	 * "75000.00 NGN" from event data.
	 *
	 * @param object $data
	 * @return string
	 */
	protected function describe_amount( $data ) {
		$amount   = isset( $data->amount ) && is_scalar( $data->amount ) ? (string) $data->amount : '?';
		$currency = isset( $data->currency ) && is_scalar( $data->currency ) ? (string) $data->currency : '';
		return trim( $amount . ' ' . $currency );
	}

	/**
	 * @param object $data
	 * @param object $event
	 */
	protected function handle_collection_failed( $data, $event ) {

		$order = $this->resolve_order( $data );

		if ( ! $order || $this->is_event_processed( $order, $event ) ) {
			return;
		}

		// Don't fail an order that is paid, or on hold because money was received.
		if ( $this->order_already_paid( $order ) || $order->has_status( 'on-hold' ) || $this->is_stale_session_event( $order, $data ) ) {
			$this->mark_event_processed( $order, $event );
			return;
		}

		$reason = isset( $data->reason ) && is_scalar( $data->reason ) ? (string) $data->reason : __( 'Payment failed at Bachs.', 'bachs-woocommerce-payment-gateway' );

		/* translators: %s: failure reason */
		$order->update_status( 'failed', sprintf( __( 'Bachs payment failed: %s', 'bachs-woocommerce-payment-gateway' ), $reason ) );
		$this->mark_event_processed( $order, $event );
	}

	/**
	 * The customer paid less than the order total: hold the order for the merchant to decide.
	 *
	 * Not skipped for replaced sessions, because money was received either way.
	 *
	 * @param object $data
	 * @param object $event
	 */
	protected function handle_collection_underpaid( $data, $event ) {

		$order = $this->resolve_order( $data );

		if ( ! $order || $this->is_event_processed( $order, $event ) ) {
			return;
		}

		if ( $this->order_already_paid( $order ) ) {
			$this->maybe_record_duplicate_charge( $order, $data );
			$this->mark_event_processed( $order, $event );
			return;
		}

		$charge_id = isset( $data->charge_id ) ? (string) $data->charge_id : '';

		// Store the charge so the merchant can refund the partial payment from WooCommerce.
		$this->store_charge_details( $order, $data );
		if ( '' !== $charge_id ) {
			$order->set_transaction_id( $charge_id );
		}

		$currency = isset( $data->currency ) && is_scalar( $data->currency ) ? (string) $data->currency : '';

		$order->update_status(
			'on-hold',
			sprintf(
				/* translators: 1: amount paid, 2: amount due, 3: amount outstanding, 4: currency code, 5: Bachs charge ID */
				__( 'Bachs payment was short. Paid %1$s of %2$s %4$s, leaving %3$s %4$s outstanding. Charge ID: %5$s. Collect the balance or refund the payment before fulfilling this order.', 'bachs-woocommerce-payment-gateway' ),
				isset( $data->amount_paid ) && is_scalar( $data->amount_paid ) ? $data->amount_paid : '?',
				isset( $data->amount_expected ) && is_scalar( $data->amount_expected ) ? $data->amount_expected : '?',
				isset( $data->amount_remaining ) && is_scalar( $data->amount_remaining ) ? $data->amount_remaining : '?',
				$currency,
				'' !== $charge_id ? $charge_id : __( 'n/a', 'bachs-woocommerce-payment-gateway' )
			)
		);

		$this->mark_event_processed( $order, $event );

		$this->log( sprintf( 'collection.underpaid put order #%d on hold.', $order->get_id() ) );
	}

	/**
	 * The checkout session lapsed without payment.
	 *
	 * The order is left pending so the customer can still pay from the order-pay page, which
	 * starts a new session. WooCommerce's Hold stock setting cancels unpaid orders.
	 *
	 * @param object $data
	 * @param object $event
	 */
	protected function handle_checkout_expired( $data, $event ) {

		$order = $this->resolve_order( $data );

		if ( ! $order || $this->is_event_processed( $order, $event ) ) {
			return;
		}

		if ( ! $order->has_status( array( 'pending', 'failed' ) ) || $this->is_stale_session_event( $order, $data ) ) {
			$this->mark_event_processed( $order, $event );
			return;
		}

		$order->add_order_note(
			sprintf(
				/* translators: %s: Bachs checkout ID */
				__( 'Bachs checkout session %s expired without payment. The customer can still pay from the order payment page, which starts a new session.', 'bachs-woocommerce-payment-gateway' ),
				isset( $data->checkout_id ) && is_scalar( $data->checkout_id ) ? $data->checkout_id : ''
			)
		);

		$this->mark_event_processed( $order, $event );
	}

	/**
	 * @param object $data
	 * @param object $event
	 */
	protected function handle_refund_paid( $data, $event ) {

		$order = $this->get_order_by_charge( $data );

		if ( ! $order || $this->is_event_processed( $order, $event ) ) {
			return;
		}

		if ( isset( $data->refund_id ) && (string) $data->refund_id === (string) $order->get_meta( self::META_REFUND_ID ) ) {
			$order->update_meta_data( self::META_REFUND_STATUS, 'success' );
		}

		$refunded_amount = isset( $data->refunded_amount ) && is_scalar( $data->refunded_amount ) ? (string) $data->refunded_amount : '';

		$order->add_order_note(
			sprintf(
				/* translators: 1: refunded amount, 2: Bachs refund ID */
				__( 'Bachs refund settled. Amount refunded: %1$s. Refund ID: %2$s', 'bachs-woocommerce-payment-gateway' ),
				'' !== $refunded_amount ? $refunded_amount : __( 'n/a', 'bachs-woocommerce-payment-gateway' ),
				isset( $data->refund_id ) && is_scalar( $data->refund_id ) ? $data->refund_id : __( 'n/a', 'bachs-woocommerce-payment-gateway' )
			)
		);

		$this->mark_event_processed( $order, $event );
	}

	/**
	 * Bachs couldn't deliver the refund, but WooCommerce already recorded it. Tell the merchant.
	 *
	 * @param object $data
	 * @param object $event
	 */
	protected function handle_refund_failed( $data, $event ) {

		$order = $this->get_order_by_charge( $data );

		if ( ! $order || $this->is_event_processed( $order, $event ) ) {
			return;
		}

		// A failed refund doesn't count against the one-refund limit, so allow another attempt.
		if ( isset( $data->refund_id ) && (string) $data->refund_id === (string) $order->get_meta( self::META_REFUND_ID ) ) {
			$order->update_meta_data( self::META_REFUND_STATUS, 'failed' );
		}

		$order->add_order_note(
			sprintf(
				/* translators: 1: Bachs refund ID, 2: requested amount, 3: failure reason */
				__( 'Bachs refund %1$s for %2$s failed, so the customer has not been refunded, although this order still shows the refund. Reason: %3$s. Delete the refund from this order and refund again, or refund the customer another way.', 'bachs-woocommerce-payment-gateway' ),
				isset( $data->refund_id ) && is_scalar( $data->refund_id ) ? $data->refund_id : '',
				isset( $data->requested_amount ) && is_scalar( $data->requested_amount ) ? $data->requested_amount : __( 'n/a', 'bachs-woocommerce-payment-gateway' ),
				! empty( $data->reason ) && is_scalar( $data->reason ) ? $data->reason : __( 'not given', 'bachs-woocommerce-payment-gateway' )
			)
		);

		$this->mark_event_processed( $order, $event );

		$this->log( sprintf( 'refund.failed for order #%d (refund %s).', $order->get_id(), isset( $data->refund_id ) ? $data->refund_id : '' ), 'error' );
	}

	/**
	 * Whether a collection event belongs to a session the order has since replaced.
	 *
	 * @param WC_Order $order
	 * @param object   $data Event data.
	 * @return bool
	 */
	protected function is_stale_session_event( $order, $data ) {

		$current = $order->get_meta( self::META_CHECKOUT_ID );

		return ! empty( $data->checkout_id ) && ! empty( $current ) && $data->checkout_id !== $current;
	}

	/**
	 * Find the order behind a refund.* event by its charge ID.
	 *
	 * @param object $data Event data.
	 * @return WC_Order|null
	 */
	protected function get_order_by_charge( $data ) {

		$charge_id = isset( $data->charge_id ) && is_scalar( $data->charge_id ) ? (string) $data->charge_id : '';

		if ( '' === $charge_id ) {
			return null;
		}

		$orders = wc_get_orders(
			array(
				'limit'          => 1,
				'payment_method' => $this->id,
				'transaction_id' => $charge_id,
			)
		);

		if ( empty( $orders ) ) {
			$orders = wc_get_orders(
				array(
					'limit'          => 1,
					'payment_method' => $this->id,
					'meta_key'       => self::META_CHARGE_ID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value'     => $charge_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				)
			);
		}

		if ( empty( $orders ) || $orders[0]->get_payment_method() !== $this->id ) {
			return null;
		}

		return $orders[0];
	}

	/**
	 * Resolve the WooCommerce order behind a collection.* or checkout.* event.
	 *
	 * @param object $data Event data.
	 * @return WC_Order|null
	 */
	protected function resolve_order( $data ) {

		$order = false;

		if ( isset( $data->metadata->order_id ) && is_scalar( $data->metadata->order_id ) ) {
			$order = wc_get_order( absint( $data->metadata->order_id ) );
		}

		if ( ! $order && ! empty( $data->reference ) && is_scalar( $data->reference ) ) {
			$order_id = wc_get_order_id_by_order_key( preg_replace( '/-\d+$/', '', (string) $data->reference ) );
			if ( $order_id ) {
				$order = wc_get_order( $order_id );
			}
		}

		if ( ! $order || $order->get_payment_method() !== $this->id ) {
			return null;
		}

		return $order;
	}

	/**
	 * Persist the Bachs customer ID against the WooCommerce user for reuse.
	 *
	 * @param WC_Order $order
	 * @param object   $data
	 */
	protected function maybe_store_customer_id( $order, $data ) {
		$user_id           = $order->get_customer_id();
		$bachs_customer_id = isset( $data->customer->id ) && is_scalar( $data->customer->id ) ? (string) $data->customer->id : '';

		if ( $user_id && '' !== $bachs_customer_id ) {
			update_user_meta( $user_id, '_bachs_customer_id_' . $this->get_order_mode( $order ), $bachs_customer_id );
		}
	}

	/*
	|--------------------------------------------------------------------------
	| Front end and admin
	|--------------------------------------------------------------------------
	*/

	/**
	 * Order-pay ("receipt") page for the popup flow: renders the opener markup that bachs.js
	 * uses to open the hosted checkout in a modal.
	 *
	 * @param int $order_id
	 */
	public function receipt_page( $order_id ) {

		$order = wc_get_order( $order_id );

		if ( ! $order || 'popup' !== $this->checkout_type ) {
			return;
		}

		$checkout_url = $order->get_meta( self::META_CHECKOUT_URL );

		echo '<div id="bachs-wc-popup">';

		if ( empty( $checkout_url ) ) {
			echo '<p>' . esc_html__( 'We could not load the Bachs checkout. Please try again.', 'bachs-woocommerce-payment-gateway' ) . '</p>';
		} else {
			echo '<div class="bachs-wc-spinner" aria-hidden="true"></div>';
			echo '<p>' . esc_html__( 'Your secure Bachs checkout is opening. If it does not appear, use the button below.', 'bachs-woocommerce-payment-gateway' ) . '</p>';
			echo '<p id="bachs-wc-status" role="status" aria-live="polite"></p>';
			echo '<p><button type="button" id="bachs-wc-pay" class="button alt">' . esc_html__( 'Pay with Bachs', 'bachs-woocommerce-payment-gateway' ) . '</button></p>';
		}

		echo '<p><a class="bachs-wc-cancel" href="' . esc_url( $order->get_cancel_order_url() ) . '">' . esc_html__( 'Cancel order &amp; restore cart', 'bachs-woocommerce-payment-gateway' ) . '</a></p>';
		echo '</div>';
	}

	/**
	 * Add the cancel link to the order-pay form, which WooCommerce doesn't show there.
	 */
	public function pay_order_cancel_link() {

		global $wp;

		$order_id = isset( $wp->query_vars['order-pay'] ) ? absint( $wp->query_vars['order-pay'] ) : 0;
		$order    = $order_id ? wc_get_order( $order_id ) : false;

		if ( ! $order || $order->get_payment_method() !== $this->id ) {
			return;
		}

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core WooCommerce filter, so the link shows exactly when WooCommerce allows cancelling.
		$cancel_statuses = apply_filters( 'woocommerce_valid_order_statuses_for_cancel', array( 'pending', 'failed' ), $order );

		if ( ! $order->has_status( $cancel_statuses ) ) {
			return;
		}

		echo '<p class="bachs-wc-cancel-order"><a class="bachs-wc-cancel" href="' . esc_url( $order->get_cancel_order_url() ) . '">' . esc_html__( 'Cancel order &amp; restore cart', 'bachs-woocommerce-payment-gateway' ) . '</a></p>';
	}

	/**
	 * Show the gateway icon before the title on the classic checkout.
	 */
	public function checkout_styles() {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}

		wp_register_style( 'bachs-wc-checkout', false, array(), BACHS_WC_VERSION );
		wp_enqueue_style( 'bachs-wc-checkout' );
		wp_add_inline_style(
			'bachs-wc-checkout',
			'.wc_payment_method.payment_method_' . $this->id . ' > label{display:inline-flex;align-items:center;gap:0.5em;}'
			. '.wc_payment_method.payment_method_' . $this->id . ' > label > img{order:-1;}'
		);
	}

	/**
	 * Enqueue bachs.js and the popup opener on the order-pay page for a popup-mode Bachs order.
	 */
	public function payment_scripts() {

		if ( ! function_exists( 'is_wc_endpoint_url' ) || ! is_wc_endpoint_url( 'order-pay' ) ) {
			return;
		}

		global $wp;
		$order_id = isset( $wp->query_vars['order-pay'] ) ? absint( $wp->query_vars['order-pay'] ) : 0;

		if ( ! $order_id ) {
			return;
		}

		$order = wc_get_order( $order_id );

		if ( ! $order || $order->get_payment_method() !== $this->id || 'popup' !== $this->checkout_type ) {
			return;
		}

		$checkout_url = $order->get_meta( self::META_CHECKOUT_URL );

		if ( empty( $checkout_url ) ) {
			return;
		}

		wp_enqueue_style( 'bachs-wc-popup', BACHS_WC_URL . '/assets/css/bachs-popup.css', array(), BACHS_WC_VERSION );
		// phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- External Bachs SDK; its version is controlled by Bachs, not this plugin.
		wp_enqueue_script( 'bachs-wc-bachs-js', 'https://checkout.bachs.io/bachs.js', array(), null, true );
		wp_enqueue_script( 'bachs-wc-popup', BACHS_WC_URL . '/assets/js/bachs-popup.js', array( 'bachs-wc-bachs-js' ), BACHS_WC_VERSION, true );

		wp_localize_script(
			'bachs-wc-popup',
			'bachs_wc_popup',
			array(
				'checkout_url' => $checkout_url,
				'return_url'   => $this->get_return_url( $order ),
				'cancel_url'   => $order->get_cancel_order_url(),
				'retry_url'    => $order->get_checkout_payment_url( false ),
				'debug'        => $this->debug ? '1' : '0',
				'i18n'         => array(
					'closed'  => __( 'Checkout closed. Click "Pay with Bachs" to try again.', 'bachs-woocommerce-payment-gateway' ),
					'failed'  => __( 'The payment failed. Click "Pay with Bachs" to try again.', 'bachs-woocommerce-payment-gateway' ),
					'expired' => __( 'This checkout session expired. Taking you back to start a new one…', 'bachs-woocommerce-payment-gateway' ),
					'blocked' => __( 'The Bachs checkout could not load. Please disable any ad/script blocker for this page and try again.', 'bachs-woocommerce-payment-gateway' ),
					'error'   => __( 'Something went wrong opening the checkout. Please try again, or cancel to start over.', 'bachs-woocommerce-payment-gateway' ),
				),
			)
		);
	}

	/**
	 * Enqueue the admin toggle script on the gateway settings screen only.
	 *
	 * @param string $hook
	 */
	public function admin_scripts( $hook ) {
		if ( 'woocommerce_page_wc-settings' !== $hook ) {
			return;
		}
		$section = filter_input( INPUT_GET, 'section', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		if ( $this->id !== $section ) {
			return;
		}
		wp_enqueue_script( 'bachs-wc-admin', BACHS_WC_URL . '/assets/js/bachs-admin.js', array( 'jquery' ), BACHS_WC_VERSION, true );
	}

	/**
	 * @param string $message
	 * @param string $level info|warning|error|debug
	 */
	public function log( $message, $level = 'info' ) {
		if ( ! $this->debug && ! in_array( $level, array( 'error', 'critical', 'warning' ), true ) ) {
			return;
		}
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->log( $level, $message, array( 'source' => 'bachs-woocommerce-payment-gateway' ) );
		}
	}
}
