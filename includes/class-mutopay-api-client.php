<?php
/**
 * MutoPay API Client
 *
 * @package MutoPay_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MutoPay_API_Client {

	private string $base_url;
	private string $api_key;
	private bool $debug;

	public function __construct( string $base_url, string $api_key, bool $debug = false ) {
		$this->base_url = rtrim( $base_url, '/' );
		$this->api_key  = $api_key;
		$this->debug    = $debug;
	}

	/**
	 * Create a payment via MutoPay API.
	 *
	 * @param array $data Payment data.
	 * @return array|WP_Error Decoded response or error.
	 */
	public function create_payment( array $data ) {
		return $this->request( 'POST', '/api/payments', $data );
	}

	/**
	 * Get payment status.
	 *
	 * @param string $payment_id MutoPay payment ID.
	 * @return array|WP_Error Decoded response or error.
	 */
	public function get_payment_status( string $payment_id ) {
		return $this->request( 'GET', '/api/payments/' . $payment_id . '/status' );
	}

	/**
	 * Check merchant setup status (wallet address configured).
	 *
	 * @return array|WP_Error Decoded response or error.
	 */
	public function check_setup_status() {
		return $this->request( 'GET', '/api/merchant/setup-status' );
	}

	/**
	 * Send an HTTP request to the MutoPay API.
	 *
	 * @param string     $method HTTP method.
	 * @param string     $path   API path.
	 * @param array|null $body   Request body for POST.
	 * @return array|WP_Error Decoded response or error.
	 */
	private function request( string $method, string $path, ?array $body = null ) {
		$url  = $this->base_url . $path;
		$args = array(
			'method'  => $method,
			'timeout' => 30,
			'headers' => array(
				'X-API-Key'    => $this->api_key,
				'Content-Type' => 'application/json',
				'User-Agent'   => 'MutoPay-WooCommerce/1.0',
			),
		);

		if ( $body !== null ) {
			$args['body'] = wp_json_encode( $body );
		}

		$this->log( "Request: {$method} {$url}" );

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			$this->log( 'Error: ' . $response->get_error_message() );
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$raw_body    = wp_remote_retrieve_body( $response );
		$decoded     = json_decode( $raw_body, true );

		$this->log( "Response: {$status_code} - " . substr( $raw_body, 0, 500 ) );

		if ( $status_code < 200 || $status_code >= 300 ) {
			$message = $decoded['error'] ?? $decoded['message'] ?? "API error (HTTP {$status_code})";
			return new WP_Error( 'mutopay_api_error', $message );
		}

		return $decoded;
	}

	/**
	 * Log a message if debug mode is enabled.
	 *
	 * @param string $message Log message.
	 */
	private function log( string $message ): void {
		if ( $this->debug ) {
			$logger = wc_get_logger();
			$logger->debug( $message, array( 'source' => 'mutopay' ) );
		}
	}
}
