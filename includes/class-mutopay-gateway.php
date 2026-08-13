<?php
/**
 * MutoPay Payment Gateway
 *
 * @package MutoPay_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MutoPay_Gateway extends WC_Payment_Gateway {

	public function __construct() {
		$this->id                 = 'mutopay';
		$this->icon               = plugin_dir_url( MUTOPAY_WC_PLUGIN_FILE ) . 'assets/mutopay-icon.svg';
		$this->has_fields         = false;
		$this->method_title       = __( 'MutoPay', 'mutopay-for-woocommerce' );
		$this->method_description = __( 'Accept crypto payments via MutoPay. Customers pay with any token on 7+ chains.', 'mutopay-for-woocommerce' );
		$this->supports           = array( 'products' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title          = $this->get_option( 'title' );
		$this->description    = $this->get_option( 'description' );
		$this->enabled        = $this->get_option( 'enabled' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'validate_settings_on_save' ), 20 );
		add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'check_payment_on_return' ) );
	}

	/**
	 * Enqueue admin assets on the MutoPay gateway settings screen.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public static function enqueue_admin_assets( $hook ) {
		if ( 'woocommerce_page_wc-settings' !== $hook ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$tab     = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : '';
		$section = isset( $_GET['section'] ) ? sanitize_text_field( wp_unslash( $_GET['section'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( 'checkout' !== $tab || 'mutopay' !== $section ) {
			return;
		}

		wp_enqueue_script(
			'mutopay-admin-settings',
			plugin_dir_url( MUTOPAY_WC_PLUGIN_FILE ) . 'assets/admin/settings.js',
			array(),
			MUTOPAY_WC_VERSION,
			true
		);

		wp_localize_script(
			'mutopay-admin-settings',
			'mutopayAdminSettings',
			array(
				'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
				'advancedTitle'   => __( 'Advanced Settings', 'mutopay-for-woocommerce' ),
				'disconnectNonce' => wp_create_nonce( 'mutopay_disconnect' ),
				'i18n'            => array(
					'disconnectConfirm' => __( 'Disconnect from MutoPay? You will need to reconnect to accept payments.', 'mutopay-for-woocommerce' ),
					'disconnecting'     => __( 'Disconnecting...', 'mutopay-for-woocommerce' ),
				),
			)
		);
	}

	/**
	 * Define gateway settings fields.
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'        => array(
				'title'   => __( 'Enable/Disable', 'mutopay-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable MutoPay payments', 'mutopay-for-woocommerce' ),
				'default' => 'no',
			),
			'title'          => array(
				'title'       => __( 'Title', 'mutopay-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Payment method title shown at checkout.', 'mutopay-for-woocommerce' ),
				'default'     => __( 'Pay with Crypto', 'mutopay-for-woocommerce' ),
				'desc_tip'    => true,
			),
			'description'    => array(
				'title'       => __( 'Description', 'mutopay-for-woocommerce' ),
				'type'        => 'textarea',
				'description' => __( 'Description shown at checkout.', 'mutopay-for-woocommerce' ),
				'default'     => __( 'Pay with any token on 7+ chains.', 'mutopay-for-woocommerce' ),
				'desc_tip'    => true,
			),
			'connect_button' => array(
				'title'       => __( 'MutoPay Connection', 'mutopay-for-woocommerce' ),
				'type'        => 'connect_button',
				'description' => __( 'Connect your store to MutoPay with one click. API key and webhook are configured automatically.', 'mutopay-for-woocommerce' ),
			),
			'expiry_minutes' => array(
				'title'       => __( 'Payment Expiry (minutes)', 'mutopay-for-woocommerce' ),
				'type'        => 'number',
				'description' => __( 'Time limit for customers to complete payment (15–10080 minutes). When a payment expires, the WooCommerce order is automatically cancelled.', 'mutopay-for-woocommerce' ),
				'default'     => '60',
				'desc_tip'    => true,
				'custom_attributes' => array(
					'min' => 15,
					'max' => 10080,
				),
			),
			'debug'          => array(
				'title'       => __( 'Debug Log', 'mutopay-for-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'Enable debug logging', 'mutopay-for-woocommerce' ),
				'description' => __( 'Log API requests to WooCommerce → Status → Logs.', 'mutopay-for-woocommerce' ),
				'default'     => 'no',
				'desc_tip'    => true,
			),
			'advanced_toggle' => array(
				'title' => __( 'Advanced Settings', 'mutopay-for-woocommerce' ),
				'type'  => 'advanced_toggle',
			),
			'base_url'       => array(
				'title'       => __( 'MutoPay URL', 'mutopay-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'MutoPay instance URL. Change only for testing.', 'mutopay-for-woocommerce' ),
				'default'     => 'https://mutopay.com',
				'desc_tip'    => true,
				'class'       => 'mutopay-advanced-field',
			),
		);
	}

	/**
	 * Process payment for an order.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return array Result with 'result' and 'redirect' keys.
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			wc_add_notice( __( 'Order not found.', 'mutopay-for-woocommerce' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$client = new MutoPay_API_Client(
			$this->get_option( 'base_url', 'https://mutopay.com' ),
			$this->get_option( 'api_key' ),
			'yes' === $this->get_option( 'debug' )
		);

		$data = array(
			'amount'             => (float) $order->get_total(),
			'currency'           => $order->get_currency(),
			'external_id'        => 'wc_' . $order_id,
			'callback_url'       => $order->get_checkout_order_received_url(),
			'metadata'           => array(
				'store_url'      => home_url(),
				'order_number'   => $order->get_order_number(),
				'items_count'    => $order->get_item_count(),
				'customer_email' => $order->get_billing_email(),
			),
			'expires_in_minutes' => (int) $this->get_option( 'expiry_minutes', 60 ),
		);

		$response = $client->create_payment( $data );

		if ( is_wp_error( $response ) ) {
			$error_msg = $response->get_error_message();
			$order->add_order_note(
				/* translators: %s: error message */
				sprintf( __( 'MutoPay payment creation failed: %s', 'mutopay-for-woocommerce' ), $error_msg )
			);
			wc_add_notice( __( 'Payment could not be initiated. Please try again.', 'mutopay-for-woocommerce' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$payment_id  = $response['id'] ?? '';
		$payment_url = $response['payment_url'] ?? '';
		$base_url    = rtrim( $this->get_option( 'base_url', 'https://mutopay.com' ), '/' );

		$order->update_meta_data( '_mutopay_payment_id', $payment_id );
		$order->update_meta_data( '_mutopay_payment_url', $base_url . $payment_url );
		$order->save();

		$order->update_status( 'on-hold', __( 'Awaiting crypto payment via MutoPay.', 'mutopay-for-woocommerce' ) );
		$order->add_order_note(
			/* translators: %s: MutoPay payment ID */
			sprintf( __( 'MutoPay payment created (ID: %s). Awaiting customer payment.', 'mutopay-for-woocommerce' ), $payment_id )
		);

		return array(
			'result'   => 'success',
			'redirect' => $base_url . $payment_url,
		);
	}

	/**
	 * Handle refund. Crypto refunds must be done manually.
	 *
	 * @param int        $order_id Order ID.
	 * @param float|null $amount   Refund amount.
	 * @param string     $reason   Refund reason.
	 * @return WP_Error Always returns error.
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		return new WP_Error(
			'mutopay_no_refund',
			__( 'Crypto payments cannot be refunded automatically. Please arrange a manual refund to the customer\'s wallet address.', 'mutopay-for-woocommerce' )
		);
	}

	/**
	 * Render the advanced settings toggle.
	 *
	 * @param string $key  Field key.
	 * @param array  $data Field data.
	 * @return string HTML.
	 */
	public function generate_advanced_toggle_html( $key, $data ) {
		$defaults = array( 'title' => '' );
		$data     = wp_parse_args( $data, $defaults );

		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc" colspan="2" style="padding-top: 16px;">
				<a href="#" id="mutopay-advanced-toggle" style="text-decoration: none; font-size: 13px; color: #2271b1;">
					▶ <?php echo esc_html( $data['title'] ); ?>
				</a>
			</th>
		</tr>
		<?php
		return ob_get_clean();
	}

	/**
	 * Render the connect button or connected status.
	 *
	 * @param string $key  Field key.
	 * @param array  $data Field data.
	 * @return string HTML.
	 */
	public function generate_connect_button_html( $key, $data ) {
		$defaults = array( 'title' => '', 'description' => '' );
		$data     = wp_parse_args( $data, $defaults );

		$connected_email = $this->get_option( 'connected_email' );
		$is_connected    = ! empty( $connected_email ) && ! empty( $this->get_option( 'api_key' ) );

		$base_url = rtrim( $this->get_option( 'base_url', 'https://mutopay.com' ), '/' );

		// CSRF defense for the OAuth callback: generate a one-time state token
		// bound to the current admin, echo it through return_url, verify on return.
		$state = wp_generate_password( 32, false );
		set_transient( 'mutopay_connect_state_' . get_current_user_id(), $state, 15 * MINUTE_IN_SECONDS );

		$return_url  = add_query_arg(
			array( 'state' => $state ),
			admin_url( 'admin.php?page=mutopay-connect' )
		);
		$webhook_url = rest_url( 'mutopay/v1/webhook' );
		$site_name   = wp_parse_url( home_url(), PHP_URL_HOST );

		$connect_url = add_query_arg(
			array(
				'return_url'  => rawurlencode( $return_url ),
				'webhook_url' => rawurlencode( $webhook_url ),
				'platform'    => 'woocommerce',
				'site_name'   => rawurlencode( $site_name ),
			),
			$base_url . '/dashboard/connect'
		);

		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<label><?php echo esc_html( $data['title'] ); ?></label>
			</th>
			<td class="forminp">
				<fieldset>
				<?php if ( $is_connected ) : ?>
					<div style="display:flex;align-items:center;gap:12px;margin-bottom:8px;">
						<span class="dashicons dashicons-yes-alt" style="color:#46b450;font-size:20px;"></span>
						<span>
							<?php
							printf(
								/* translators: %s: merchant email */
								esc_html__( 'Connected as %s', 'mutopay-for-woocommerce' ),
								'<strong>' . esc_html( $connected_email ) . '</strong>'
							);
							?>
						</span>
					</div>
					<div style="display:flex;gap:8px;align-items:center;margin-bottom:8px;">
						<span class="description">
							<?php
							printf(
								/* translators: %s: webhook URL */
								esc_html__( 'Webhook: %s', 'mutopay-for-woocommerce' ),
								'<code>' . esc_html( $webhook_url ) . '</code>'
							);
							?>
						</span>
					</div>
					<div style="display:flex;gap:8px;align-items:center;margin-bottom:8px;">
						<a href="<?php echo esc_url( $base_url . '/dashboard' ); ?>" target="_blank" class="button">
							<?php esc_html_e( 'Open MutoPay Dashboard', 'mutopay-for-woocommerce' ); ?> ↗
						</a>
						<span class="description"><?php esc_html_e( 'View analytics, manage settings, and more.', 'mutopay-for-woocommerce' ); ?></span>
					</div>
					<button type="button" class="button" id="mutopay-disconnect" style="color:#a00;">
						<?php esc_html_e( 'Disconnect', 'mutopay-for-woocommerce' ); ?>
					</button>
				<?php else : ?>
					<a href="<?php echo esc_url( $connect_url ); ?>" class="button button-primary">
						<?php esc_html_e( 'Connect to MutoPay', 'mutopay-for-woocommerce' ); ?>
					</a>
					<p class="description"><?php echo esc_html( $data['description'] ); ?></p>
				<?php endif; ?>
				</fieldset>
			</td>
		</tr>
		<?php
		return ob_get_clean();
	}

	/**
	 * Check payment status when customer returns to the Thank You page.
	 * Updates the order immediately if the webhook hasn't arrived yet.
	 *
	 * @param int $order_id WooCommerce order ID.
	 */
	public function check_payment_on_return( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || $order->get_payment_method() !== $this->id ) {
			return;
		}

		// Only check if order is still awaiting payment.
		if ( ! $order->has_status( 'on-hold' ) ) {
			return;
		}

		$payment_id = $order->get_meta( '_mutopay_payment_id' );
		if ( empty( $payment_id ) ) {
			return;
		}

		$client = new MutoPay_API_Client(
			$this->get_option( 'base_url', 'https://mutopay.com' ),
			$this->get_option( 'api_key' ),
			'yes' === $this->get_option( 'debug' )
		);

		$response = $client->get_payment_status( $payment_id );
		if ( is_wp_error( $response ) ) {
			return;
		}

		$status = $response['status'] ?? '';

		if ( 'completed' === $status ) {
			$tx_hash = $response['tx_hash'] ?? '';
			$order->update_meta_data( '_mutopay_tx_hash', $tx_hash );
			$order->save();
			$order->payment_complete( $tx_hash );
			$order->add_order_note(
				sprintf(
					/* translators: %s: transaction hash */
					__( 'Crypto payment confirmed (tx: %s). Verified on return to store.', 'mutopay-for-woocommerce' ),
					$tx_hash
				)
			);
		} elseif ( 'failed' === $status ) {
			$reason = $response['failure_reason'] ?? __( 'Unknown reason', 'mutopay-for-woocommerce' );
			$order->update_status( 'failed',
				/* translators: %s: failure reason */
				sprintf( __( 'Crypto payment failed: %s.', 'mutopay-for-woocommerce' ), $reason )
			);
		} elseif ( 'expired' === $status ) {
			$order->update_status( 'cancelled', __( 'Crypto payment expired.', 'mutopay-for-woocommerce' ) );
		}
	}

	/**
	 * Validate settings on save. Prevents enabling if setup is incomplete.
	 */
	public function validate_settings_on_save() {
		if ( 'yes' !== $this->get_option( 'enabled' ) ) {
			return;
		}

		$api_key  = $this->get_option( 'api_key' );
		$base_url = $this->get_option( 'base_url', 'https://mutopay.com' );

		if ( ! empty( $base_url ) && strpos( $base_url, 'https://' ) !== 0 ) {
			WC_Admin_Settings::add_error(
				__( 'MutoPay: Base URL must use HTTPS for secure communication.', 'mutopay-for-woocommerce' )
			);
		}

		// Block enabling if not connected.
		if ( empty( $api_key ) ) {
			$this->update_option( 'enabled', 'no' );
			WC_Admin_Settings::add_error(
				__( 'MutoPay cannot be enabled: not connected. Click "Connect to MutoPay" to set up your store.', 'mutopay-for-woocommerce' )
			);
			return;
		}

		// Block enabling if wallet address not set in MutoPay dashboard.
		$client = new MutoPay_API_Client(
			$base_url,
			$api_key,
			'yes' === $this->get_option( 'debug' )
		);

		$status = $client->check_setup_status();

		if ( is_wp_error( $status ) ) {
			// API unreachable, allow enabling but warn.
			WC_Admin_Settings::add_error(
				__( 'MutoPay: Could not verify setup status. Ensure your wallet address is configured in the MutoPay dashboard.', 'mutopay-for-woocommerce' )
			);
			return;
		}

		if ( empty( $status['wallet_address_set'] ) ) {
			$this->update_option( 'enabled', 'no' );
			$dashboard_url = esc_url( rtrim( $base_url, '/' ) . '/dashboard/settings' );
			WC_Admin_Settings::add_error(
				sprintf(
					/* translators: %s: MutoPay dashboard URL */
					__( 'MutoPay cannot be enabled: wallet address not configured. <a href="%s" target="_blank">Set your wallet address in the MutoPay dashboard</a> first.', 'mutopay-for-woocommerce' ),
					$dashboard_url
				)
			);
			return;
		}
	}
}
