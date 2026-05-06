<?php
/**
 * MutoPay Webhook Handler
 *
 * @package MutoPay_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MutoPay_Webhook {

	/**
	 * Register the webhook REST route.
	 */
	public static function register_routes() {
		register_rest_route(
			'mutopay/v1',
			'/webhook',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Handle incoming webhook from MutoPay.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response
	 */
	public static function handle( WP_REST_Request $request ) {
		$raw_body = $request->get_body();

		// Verify HMAC signature.
		$gateway = self::get_gateway_settings();
		if ( ! $gateway ) {
			return new WP_REST_Response( array( 'error' => 'Gateway not configured' ), 500 );
		}

		$secret   = $gateway->get_option( 'webhook_secret' );
		$expected = 'sha256=' . hash_hmac( 'sha256', $raw_body, $secret );
		$received = $request->get_header( 'X-MutoPay-Signature' ) ?? '';

		if ( ! hash_equals( $expected, $received ) ) {
			self::log( 'Webhook signature verification failed' );
			return new WP_REST_Response( array( 'error' => 'Invalid signature' ), 401 );
		}

		$payload = json_decode( $raw_body, true );
		if ( ! $payload || empty( $payload['event'] ) || empty( $payload['external_id'] ) ) {
			return new WP_REST_Response( array( 'error' => 'Invalid payload' ), 400 );
		}

		$event       = $payload['event'];
		$external_id = $payload['external_id'];

		// Extract WC order ID from "wc_123" format.
		if ( strpos( $external_id, 'wc_' ) !== 0 ) {
			self::log( "Unknown external_id format: {$external_id}" );
			return new WP_REST_Response( array( 'ok' => true ) );
		}

		$order_id = (int) substr( $external_id, 3 );
		$order    = wc_get_order( $order_id );

		if ( ! $order ) {
			self::log( "Order #{$order_id} not found for webhook" );
			return new WP_REST_Response( array( 'ok' => true ) );
		}

		// Idempotency: skip if order is already in matching terminal state.
		$current_status = $order->get_status();

		if ( $event === 'payment.completed' && in_array( $current_status, array( 'completed', 'processing' ), true ) ) {
			self::log( "Order #{$order_id} already {$current_status}, ignoring duplicate payment.completed" );
			return new WP_REST_Response( array( 'ok' => true ) );
		}

		if ( $event === 'payment.failed' && $current_status === 'failed' ) {
			self::log( "Order #{$order_id} already failed, ignoring duplicate" );
			return new WP_REST_Response( array( 'ok' => true ) );
		}

		if ( $event === 'payment.expired' && in_array( $current_status, array( 'cancelled', 'failed' ), true ) ) {
			self::log( "Order #{$order_id} already {$current_status}, ignoring duplicate payment.expired" );
			return new WP_REST_Response( array( 'ok' => true ) );
		}

		// Process event.
		switch ( $event ) {
			case 'payment.completed':
				$tx_hash = $payload['tx_hash'] ?? '';
				$order->update_meta_data( '_mutopay_tx_hash', $tx_hash );
				$order->save();

				// Reopen cancelled orders that received payment.
				if ( $current_status === 'cancelled' ) {
					$order->update_status( 'processing', __( 'Payment received after cancellation — order reopened.', 'mutopay-for-woocommerce' ) );
				} else {
					$order->payment_complete( $tx_hash );
				}

				$order->add_order_note(
					/* translators: %s: transaction hash */
					sprintf( __( 'Crypto payment confirmed (tx: %s)', 'mutopay-for-woocommerce' ), $tx_hash )
				);
				self::log( "Order #{$order_id} marked completed (tx: {$tx_hash})" );
				break;

			case 'payment.failed':
				$reason = $payload['failure_reason'] ?? __( 'Unknown reason', 'mutopay-for-woocommerce' );
				$order->update_status( 'failed',
					/* translators: %s: failure reason */
					sprintf( __( 'Crypto payment failed: %s', 'mutopay-for-woocommerce' ), $reason )
				);
				self::log( "Order #{$order_id} marked failed: {$reason}" );
				break;

			case 'payment.expired':
				$order->update_status( 'cancelled', __( 'Crypto payment expired', 'mutopay-for-woocommerce' ) );
				self::log( "Order #{$order_id} marked cancelled (expired)" );
				break;

			case 'payment.underpaid':
				$order->update_status( 'on-hold', __( 'Crypto payment underpaid — manual review needed', 'mutopay-for-woocommerce' ) );
				self::log( "Order #{$order_id} set on-hold (underpaid)" );
				break;

			default:
				self::log( "Unknown webhook event: {$event}" );
				break;
		}

		return new WP_REST_Response( array( 'ok' => true ) );
	}

	/**
	 * Get the MutoPay gateway instance.
	 *
	 * @return MutoPay_Gateway|null
	 */
	private static function get_gateway_settings() {
		$gateways = WC()->payment_gateways()->payment_gateways();
		return $gateways['mutopay'] ?? null;
	}

	/**
	 * Log a webhook message.
	 *
	 * @param string $message Log message.
	 */
	private static function log( string $message ): void {
		$logger = wc_get_logger();
		$logger->info( '[Webhook] ' . $message, array( 'source' => 'mutopay' ) );
	}
}
