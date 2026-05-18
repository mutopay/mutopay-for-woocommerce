<?php
/**
 * MutoPay Order Meta Box
 *
 * Displays MutoPay payment details in the WooCommerce admin order view.
 *
 * @package MutoPay_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MutoPay_Order_Meta {

	/**
	 * Register hooks.
	 */
	public static function register() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ) );
		add_action( 'wp_ajax_mutopay_recheck_status', array( __CLASS__, 'ajax_recheck_status' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ) );
	}

	/**
	 * Enqueue the recheck-status script on order edit screens for MutoPay orders.
	 */
	public static function enqueue_admin_assets() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) {
			return;
		}

		$order_screen = self::get_order_screen();
		if ( ! $order_screen || $screen->id !== $order_screen ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$order_id = 0;
		if ( isset( $_GET['post'] ) ) {
			$order_id = absint( wp_unslash( $_GET['post'] ) );
		} elseif ( isset( $_GET['id'] ) ) {
			$order_id = absint( wp_unslash( $_GET['id'] ) );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! $order_id ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order || $order->get_payment_method() !== 'mutopay' || ! $order->has_status( 'on-hold' ) ) {
			return;
		}

		wp_enqueue_script(
			'mutopay-admin-order-meta',
			plugin_dir_url( MUTOPAY_WC_PLUGIN_FILE ) . 'assets/admin/order-meta.js',
			array(),
			MUTOPAY_WC_VERSION,
			true
		);

		wp_localize_script(
			'mutopay-admin-order-meta',
			'mutopayOrderMeta',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'orderId' => $order_id,
				'nonce'   => wp_create_nonce( 'mutopay_recheck_' . $order_id ),
				'i18n'    => array(
					'checking'      => __( 'Checking...', 'mutopay-for-woocommerce' ),
					'checkFailed'   => __( 'Check failed.', 'mutopay-for-woocommerce' ),
					'recheck'       => __( 'Re-check payment status', 'mutopay-for-woocommerce' ),
					'requestFailed' => __( 'Request failed.', 'mutopay-for-woocommerce' ),
				),
			)
		);
	}

	/**
	 * Add the MutoPay meta box to the order edit screen.
	 */
	public static function add_meta_box() {
		$screen = self::get_order_screen();
		if ( ! $screen ) {
			return;
		}

		add_meta_box(
			'mutopay-payment-details',
			__( 'MutoPay Payment', 'mutopay-for-woocommerce' ),
			array( __CLASS__, 'render' ),
			$screen,
			'side',
			'high'
		);
	}

	/**
	 * Get the correct screen ID for orders (supports both legacy and HPOS).
	 *
	 * @return string|null Screen ID or null if unavailable.
	 */
	private static function get_order_screen() {
		if ( class_exists( '\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController' ) ) {
			$controller = wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class );
			if ( $controller->custom_orders_table_usage_is_enabled() ) {
				return wc_get_page_screen_id( 'shop-order' );
			}
		}
		return 'shop_order';
	}

	/**
	 * Render the meta box content.
	 *
	 * @param WP_Post|WC_Order $post_or_order Post object or order object (HPOS).
	 */
	public static function render( $post_or_order ) {
		$order = ( $post_or_order instanceof WP_Post )
			? wc_get_order( $post_or_order->ID )
			: $post_or_order;

		if ( ! $order || $order->get_payment_method() !== 'mutopay' ) {
			echo '<p>' . esc_html__( 'Not a MutoPay payment.', 'mutopay-for-woocommerce' ) . '</p>';
			return;
		}

		$payment_id  = $order->get_meta( '_mutopay_payment_id' );
		$payment_url = $order->get_meta( '_mutopay_payment_url' );
		$tx_hash     = $order->get_meta( '_mutopay_tx_hash' );

		if ( empty( $payment_id ) ) {
			echo '<p>' . esc_html__( 'No payment data available.', 'mutopay-for-woocommerce' ) . '</p>';
			return;
		}
		?>
		<div style="line-height:1.8;">
			<p style="margin:0 0 8px;">
				<strong><?php esc_html_e( 'Payment ID', 'mutopay-for-woocommerce' ); ?></strong><br>
				<code style="font-size:11px;word-break:break-all;"><?php echo esc_html( $payment_id ); ?></code>
			</p>

			<?php if ( ! empty( $payment_url ) ) : ?>
				<p style="margin:0 0 8px;">
					<strong><?php esc_html_e( 'Payment Link', 'mutopay-for-woocommerce' ); ?></strong><br>
					<a href="<?php echo esc_url( $payment_url ); ?>" target="_blank" rel="noopener noreferrer">
						<?php esc_html_e( 'Open payment page', 'mutopay-for-woocommerce' ); ?>
						<span class="dashicons dashicons-external" style="font-size:14px;line-height:1.6;"></span>
					</a>
				</p>
			<?php endif; ?>

			<?php if ( ! empty( $tx_hash ) ) : ?>
				<p style="margin:0 0 8px;">
					<strong><?php esc_html_e( 'Transaction', 'mutopay-for-woocommerce' ); ?></strong><br>
					<code style="font-size:11px;word-break:break-all;"><?php echo esc_html( $tx_hash ); ?></code>
				</p>
			<?php endif; ?>

			<?php if ( $order->has_status( 'on-hold' ) ) : ?>
				<div style="margin-top:10px;padding-top:10px;border-top:1px solid #eee;">
					<button type="button" class="button" id="mutopay-recheck-status">
						<?php esc_html_e( 'Re-check payment status', 'mutopay-for-woocommerce' ); ?>
					</button>
					<span id="mutopay-recheck-result" style="display:block;margin-top:6px;font-size:12px;"></span>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * AJAX handler to re-check payment status from MutoPay API.
	 */
	public static function ajax_recheck_status() {
		$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
		$nonce    = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, 'mutopay_recheck_' . $order_id ) || ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'mutopay-for-woocommerce' ) ) );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order || $order->get_payment_method() !== 'mutopay' ) {
			wp_send_json_error( array( 'message' => __( 'Invalid order.', 'mutopay-for-woocommerce' ) ) );
		}

		$payment_id = $order->get_meta( '_mutopay_payment_id' );
		if ( empty( $payment_id ) ) {
			wp_send_json_error( array( 'message' => __( 'No MutoPay payment ID on this order.', 'mutopay-for-woocommerce' ) ) );
		}

		$gateway = WC()->payment_gateways()->payment_gateways()['mutopay'] ?? null;
		if ( ! $gateway ) {
			wp_send_json_error( array( 'message' => __( 'Gateway not available.', 'mutopay-for-woocommerce' ) ) );
		}

		$client = new MutoPay_API_Client(
			$gateway->get_option( 'base_url', 'https://mutopay.com' ),
			$gateway->get_option( 'api_key' ),
			'yes' === $gateway->get_option( 'debug' )
		);

		$response = $client->get_payment_status( $payment_id );
		if ( is_wp_error( $response ) ) {
			wp_send_json_error( array( 'message' => $response->get_error_message() ) );
		}

		$status  = $response['status'] ?? '';
		$changed = false;

		if ( 'completed' === $status ) {
			$tx_hash = $response['tx_hash'] ?? '';
			$order->update_meta_data( '_mutopay_tx_hash', $tx_hash );
			$order->save();
			$order->payment_complete( $tx_hash );
			$order->add_order_note(
				/* translators: %s: transaction hash */
				sprintf( __( 'Crypto payment confirmed (tx: %s). Verified via manual re-check.', 'mutopay-for-woocommerce' ), $tx_hash )
			);
			$changed = true;
		} elseif ( 'failed' === $status ) {
			$reason = $response['failure_reason'] ?? __( 'Unknown reason', 'mutopay-for-woocommerce' );
			$order->update_status( 'failed',
				/* translators: %s: failure reason */
				sprintf( __( 'Crypto payment failed: %s. Verified via manual re-check.', 'mutopay-for-woocommerce' ), $reason )
			);
			$changed = true;
		} elseif ( 'expired' === $status ) {
			$order->update_status( 'cancelled', __( 'Crypto payment expired. Verified via manual re-check.', 'mutopay-for-woocommerce' ) );
			$changed = true;
		}

		wp_send_json_success( array(
			'message' => $changed
				/* translators: %s: payment status */
				? sprintf( __( 'Payment status: %s. Order updated.', 'mutopay-for-woocommerce' ), $status )
				/* translators: %s: payment status */
				: sprintf( __( 'Payment status: %s. No change needed.', 'mutopay-for-woocommerce' ), $status ),
			'status'  => $status,
			'changed' => $changed,
		) );
	}
}
