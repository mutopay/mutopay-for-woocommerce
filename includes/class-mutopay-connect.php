<?php
/**
 * MutoPay Connect — handles OAuth-style connect flow.
 *
 * @package MutoPay_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MutoPay_Connect {

	/**
	 * Register hooks.
	 */
	public static function register() {
		add_action( 'admin_menu', array( __CLASS__, 'add_hidden_page' ) );
		add_action( 'wp_ajax_mutopay_disconnect', array( __CLASS__, 'handle_disconnect' ) );
		add_action( 'admin_notices', array( __CLASS__, 'show_connect_notice' ) );
	}

	/**
	 * Add a hidden admin page to handle the OAuth return redirect.
	 */
	public static function add_hidden_page() {
		add_submenu_page(
			null,
			'MutoPay Connect',
			'MutoPay Connect',
			'manage_woocommerce',
			'mutopay-connect',
			array( __CLASS__, 'handle_callback' )
		);
	}

	/**
	 * Handle the OAuth callback — exchange token for credentials.
	 */
	public static function handle_callback() {
		// Only WooCommerce admins may complete the connect flow. The hidden
		// submenu is already capability-restricted, this is defense in depth.
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die(
				esc_html__( 'You do not have permission to connect this store to MutoPay.', 'mutopay-for-woocommerce' ),
				esc_html__( 'Connection Failed', 'mutopay-for-woocommerce' ),
				array( 'response' => 403 )
			);
		}

		// CSRF check: the state parameter is generated when the admin clicks
		// "Connect to MutoPay", stored in a per-user transient, and echoed back
		// here via return_url. A mismatch means the callback was not initiated
		// by this admin (forged link, replayed callback, etc.) and is rejected.
		$received_state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		$expected_state = get_transient( 'mutopay_connect_state_' . get_current_user_id() );
		delete_transient( 'mutopay_connect_state_' . get_current_user_id() );

		if ( empty( $received_state ) || empty( $expected_state ) || ! hash_equals( (string) $expected_state, $received_state ) ) {
			wp_die(
				esc_html__( 'Invalid or expired connect request. Please start the connection again from the MutoPay settings page.', 'mutopay-for-woocommerce' ),
				esc_html__( 'Connection Failed', 'mutopay-for-woocommerce' ),
				array( 'response' => 403, 'back_link' => true )
			);
		}

		$token = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';

		if ( empty( $token ) ) {
			wp_die(
				esc_html__( 'Missing connect token. Please try connecting again from the MutoPay settings page.', 'mutopay-for-woocommerce' ),
				esc_html__( 'Connection Failed', 'mutopay-for-woocommerce' ),
				array( 'back_link' => true )
			);
		}

		$gateway  = self::get_gateway();
		$base_url = rtrim( $gateway->get_option( 'base_url', 'https://mutopay.com' ), '/' );

		$response = wp_remote_post(
			$base_url . '/api/connect/exchange',
			array(
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( array( 'token' => $token ) ),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			wp_die(
				sprintf(
					/* translators: %s: error message */
					esc_html__( 'Connection failed: %s', 'mutopay-for-woocommerce' ),
					esc_html( $response->get_error_message() )
				),
				esc_html__( 'Connection Failed', 'mutopay-for-woocommerce' ),
				array( 'back_link' => true )
			);
		}

		$status = wp_remote_retrieve_response_code( $response );
		$body   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $status || empty( $body['api_key'] ) ) {
			$error = $body['error'] ?? $body['message'] ?? __( 'Unknown error', 'mutopay-for-woocommerce' );
			wp_die(
				sprintf(
					/* translators: %s: error message */
					esc_html__( 'Connection failed: %s', 'mutopay-for-woocommerce' ),
					esc_html( $error )
				),
				esc_html__( 'Connection Failed', 'mutopay-for-woocommerce' ),
				array( 'back_link' => true )
			);
		}

		// Store credentials.
		$gateway->update_option( 'api_key', $body['api_key'] );
		$gateway->update_option( 'webhook_secret', $body['webhook_secret'] );
		$gateway->update_option( 'connected_email', $body['merchant_email'] );

		// Redirect to settings with success notice.
		set_transient( 'mutopay_connected_notice', true, 30 );
		wp_safe_redirect(
			admin_url( 'admin.php?page=wc-settings&tab=checkout&section=mutopay' )
		);
		exit;
	}

	/**
	 * AJAX handler: disconnect (clear credentials).
	 */
	public static function handle_disconnect() {
		check_ajax_referer( 'mutopay_disconnect', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$gateway = self::get_gateway();
		$gateway->update_option( 'api_key', '' );
		$gateway->update_option( 'webhook_secret', '' );
		$gateway->update_option( 'connected_email', '' );

		wp_send_json_success();
	}

	/**
	 * Show admin notice after successful connection.
	 */
	public static function show_connect_notice() {
		if ( ! get_transient( 'mutopay_connected_notice' ) ) {
			return;
		}
		delete_transient( 'mutopay_connected_notice' );
		$gateway     = self::get_gateway();
		$dashboard_url = rtrim( $gateway->get_option( 'base_url', 'https://mutopay.com' ), '/' ) . '/dashboard/settings';
		echo '<div class="notice notice-success is-dismissible"><p>';
		echo esc_html__( 'MutoPay connected successfully! Your API key and webhook have been configured automatically.', 'mutopay-for-woocommerce' );
		echo '</p><p>';
		printf(
			/* translators: %s: link to MutoPay dashboard settings */
			esc_html__( 'Next step: %s to set your wallet address and start receiving payments.', 'mutopay-for-woocommerce' ),
			'<a href="' . esc_url( $dashboard_url ) . '" target="_blank"><strong>' . esc_html__( 'Open MutoPay Dashboard', 'mutopay-for-woocommerce' ) . '</strong></a>'
		);
		echo '</p></div>';
	}

	/**
	 * Get the MutoPay gateway instance.
	 *
	 * @return MutoPay_Gateway
	 */
	private static function get_gateway() {
		$gateways = WC()->payment_gateways()->payment_gateways();
		return $gateways['mutopay'];
	}
}
