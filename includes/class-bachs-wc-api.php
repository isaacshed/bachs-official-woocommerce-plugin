<?php
/**
 * Thin wrapper around the Bachs REST API.
 *
 * @package Bachs_Payment_Gateway_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Bachs_WC_API {

	/**
	 * @var string
	 */
	private $api_key;

	/**
	 * @var string
	 */
	private $api_url;

	/**
	 * Response code from the last request.
	 *
	 * @var int
	 */
	public $last_response_code = 0;

	/**
	 * Bachs error_code from the last failed request (for example IDEMPOTENCY_CONFLICT).
	 *
	 * @var string
	 */
	public $last_error_code = '';

	/**
	 * Unescaped error detail from the last failed request.
	 *
	 * @var string
	 */
	public $last_error_detail = '';

	/**
	 * @param string $api_key Bachs API key. May be empty for unauthenticated endpoints.
	 * @param string $api_url Environment base URL (no version suffix).
	 */
	public function __construct( $api_key, $api_url ) {
		$this->api_key = (string) $api_key;
		$this->api_url = rtrim( $api_url, '/' ) . '/v1/';
	}

	/**
	 * Make a request to the Bachs API.
	 *
	 * @param string $endpoint Endpoint relative to the /v1/ base (no leading slash).
	 * @param array  $body     Request body, or query string for GET.
	 * @param array  $headers  Extra headers.
	 * @param string $method   HTTP method.
	 * @param int    $timeout  Request timeout in seconds.
	 *
	 * @return mixed Decoded JSON response.
	 *
	 * @throws Exception On a missing key, a transport error or a >= 400 response.
	 */
	public function make_request( $endpoint, $body = array(), $headers = array(), $method = 'POST', $timeout = 30 ) {

		$this->last_error_code    = '';
		$this->last_error_detail  = '';
		$this->last_response_code = 0;

		$default_headers = array(
			'Accept'       => 'application/json',
			'Content-Type' => 'application/json',
		);

		if ( '' !== $this->api_key ) {
			$default_headers['Authorization'] = sprintf( 'Bearer %s', $this->api_key );
		} elseif ( 'GET' !== $method ) {
			throw new Exception( 'Bachs API key is not configured.' );
		}

		$request_args = array(
			'method'     => $method,
			'timeout'    => $timeout,
			'headers'    => wp_parse_args( $headers, $default_headers ),
			'user-agent' => 'BachsPaymentGatewayForWooCommerce/' . BACHS_WC_VERSION . '; ' . home_url(),
		);

		$url = $this->api_url . ltrim( $endpoint, '/' );

		if ( ! empty( $body ) ) {
			if ( 'GET' === $method ) {
				$url = add_query_arg( array_map( 'rawurlencode', $body ), $url );
			} else {
				$request_args['body'] = wp_json_encode( $body );
			}
		}

		$response = wp_remote_request( $url, $request_args );

		if ( is_wp_error( $response ) ) {
			throw new Exception( esc_html( $response->get_error_message() ) );
		}

		$this->last_response_code = (int) wp_remote_retrieve_response_code( $response );

		$decoded = json_decode( wp_remote_retrieve_body( $response ) );

		if ( $this->last_response_code >= 400 ) {
			$this->last_error_code   = isset( $decoded->error_code ) ? (string) $decoded->error_code : '';
			$this->last_error_detail = isset( $decoded->detail ) && is_scalar( $decoded->detail ) ? (string) $decoded->detail : wp_remote_retrieve_body( $response );

			// Bachs support asks for the x-request-id, so keep it in the message that gets logged.
			$request_id = wp_remote_retrieve_header( $response, 'x-request-id' );

			throw new Exception(
				sprintf(
					'Bachs API error (%d%s): %s%s',
					(int) $this->last_response_code,
					'' !== $this->last_error_code ? ', ' . esc_html( $this->last_error_code ) : '',
					esc_html( $this->last_error_detail ),
					$request_id ? ' [request ' . esc_html( $request_id ) . ']' : ''
				)
			);
		}

		return $decoded;
	}

	/**
	 * Shared client for an environment.
	 *
	 * Orders remember the environment they were paid in, so a sandbox order is still
	 * refunded against the sandbox after the store switches to live, and vice versa.
	 *
	 * @param string|null $mode 'sandbox' or 'live'. Defaults to the active mode.
	 * @return Bachs_WC_API
	 */
	public static function get_client( $mode = null ) {
		static $instances = array();

		if ( 'sandbox' !== $mode && 'live' !== $mode ) {
			$mode = Bachs_WC_Helpers::get_mode();
		}

		if ( ! isset( $instances[ $mode ] ) ) {
			$instances[ $mode ] = new self( Bachs_WC_Helpers::get_api_key( $mode ), Bachs_WC_Helpers::api_base_url( $mode ) );
		}

		return $instances[ $mode ];
	}
}
