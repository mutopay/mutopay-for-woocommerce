<?php
/**
 * MutoPay Cron Fallback Poller
 *
 * @package MutoPay_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MutoPay_Cron {

	/**
	 * Poll pending MutoPay orders for status updates.
	 */
	public static function run() {
		$gateway = self::get_gateway();
		if ( ! $gateway ) {
			return;
		}

		$client = new MutoPay_API_Client(
			$gateway->get_option( 'base_url', 'https://mutopay.com' ),
			$gateway->get_option( 'api_key' ),
			'yes' === $gateway->get_option( 'debug' )
		);

		self::log( 'Cron run started.' );

		$orders = wc_get_orders(
			array(
				'status'     => 'on-hold',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Bounded cron query (on-hold status, last 7 days, limit 50); meta lookup is required to find MutoPay orders.
				'meta_key'   => '_mutopay_payment_id',
				'date_after' => gmdate( 'Y-m-d', strtotime( '-7 days' ) ),
				'limit'      => 50,
				'orderby'    => 'date',
				'order'      => 'ASC',
			)
		);

		self::log( sprintf( 'Found %d on-hold order(s) to poll.', count( $orders ) ) );

		foreach ( $orders as $order ) {
			$payment_id = $order->get_meta( '_mutopay_payment_id' );
			if ( empty( $payment_id ) ) {
				continue;
			}

			self::log( sprintf( 'Polling order #%d (payment: %s)', $order->get_id(), $payment_id ) );

			$response = $client->get_payment_status( $payment_id );
			if ( is_wp_error( $response ) ) {
				self::log( "Failed to poll payment {$payment_id}: " . $response->get_error_message() );
				continue;
			}

			$status = $response['status'] ?? '';
			self::log( sprintf( 'Order #%d — MutoPay status: %s', $order->get_id(), $status ) );
			self::process_status( $order, $status, $response );
		}
	}

	/**
	 * Process a status response and update the WC order if needed.
	 *
	 * @param WC_Order $order    The WooCommerce order.
	 * @param string   $status   Payment status from MutoPay.
	 * @param array    $response Full API response.
	 */
	private static function process_status( WC_Order $order, string $status, array $response ): void {
		$note_suffix = __( 'Status updated via polling (webhook may have failed).', 'mutopay-for-woocommerce' );

		switch ( $status ) {
			case 'completed':
				$tx_hash = $response['tx_hash'] ?? '';
				$order->update_meta_data( '_mutopay_tx_hash', $tx_hash );
				$order->save();
				$order->payment_complete( $tx_hash );
				$order->add_order_note(
					sprintf(
						/* translators: 1: transaction hash, 2: note suffix */
						__( 'Crypto payment confirmed (tx: %1$s). %2$s', 'mutopay-for-woocommerce' ),
						$tx_hash,
						$note_suffix
					)
				);
				break;

			case 'failed':
				$reason = $response['failure_reason'] ?? __( 'Unknown reason', 'mutopay-for-woocommerce' );
				$order->update_status(
					'failed',
					sprintf(
						/* translators: 1: failure reason, 2: note suffix */
						__( 'Crypto payment failed: %1$s. %2$s', 'mutopay-for-woocommerce' ),
						$reason,
						$note_suffix
					)
				);
				break;

			case 'expired':
				$order->update_status(
					'cancelled',
					sprintf(
						/* translators: %s: note suffix */
						__( 'Crypto payment expired. %s', 'mutopay-for-woocommerce' ),
						$note_suffix
					)
				);
				break;

			case 'underpaid':
				$order->update_status(
					'on-hold',
					sprintf(
						/* translators: %s: note suffix */
						__( 'Crypto payment underpaid — manual review needed. %s', 'mutopay-for-woocommerce' ),
						$note_suffix
					)
				);
				break;

			default:
				// Still pending/processing — no action needed.
				break;
		}
	}

	/**
	 * Get the MutoPay gateway instance.
	 *
	 * @return MutoPay_Gateway|null
	 */
	private static function get_gateway() {
		$gateways = WC()->payment_gateways()->payment_gateways();
		return $gateways['mutopay'] ?? null;
	}

	/**
	 * Log a cron message.
	 *
	 * @param string $message Log message.
	 */
	private static function log( string $message ): void {
		$logger = wc_get_logger();
		$logger->info( '[Cron] ' . $message, array( 'source' => 'mutopay' ) );
	}
}
