<?php
/**
 * Plugin Name: MutoPay for WooCommerce
 * Description: Accept crypto payments via MutoPay. Customers pay with any token on 7+ chains, you receive stablecoins.
 * Version: 1.0.2
 * Author: MutoPay
 * Author URI: https://mutopay.com
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: mutopay-for-woocommerce
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Requires Plugins: woocommerce
 * WC requires at least: 8.0
 * WC tested up to: 9.5
 *
 * @package MutoPay_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MUTOPAY_WC_VERSION', '1.0.2' );
define( 'MUTOPAY_WC_PLUGIN_FILE', __FILE__ );

/**
 * Declare HPOS (High-Performance Order Storage) compatibility.
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

/**
 * Initialize the plugin after all plugins are loaded.
 */
add_action(
	'plugins_loaded',
	function () {
		// Check that WooCommerce is active.
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				function () {
					echo '<div class="error"><p>';
					echo esc_html__( 'MutoPay for WooCommerce requires WooCommerce to be installed and active.', 'mutopay-for-woocommerce' );
					echo '</p></div>';
				}
			);
			return;
		}

		// Load classes.
		require_once plugin_dir_path( __FILE__ ) . 'includes/class-mutopay-api-client.php';
		require_once plugin_dir_path( __FILE__ ) . 'includes/class-mutopay-gateway.php';
		require_once plugin_dir_path( __FILE__ ) . 'includes/class-mutopay-webhook.php';
		require_once plugin_dir_path( __FILE__ ) . 'includes/class-mutopay-cron.php';
		require_once plugin_dir_path( __FILE__ ) . 'includes/class-mutopay-connect.php';
		require_once plugin_dir_path( __FILE__ ) . 'includes/class-mutopay-order-meta.php';

		// Register connect flow handlers.
		MutoPay_Connect::register();

		// Register order meta box.
		MutoPay_Order_Meta::register();

		// Register payment gateway.
		add_filter(
			'woocommerce_payment_gateways',
			function ( $gateways ) {
				$gateways[] = 'MutoPay_Gateway';
				return $gateways;
			}
		);

		// Register webhook REST route.
		add_action( 'rest_api_init', array( 'MutoPay_Webhook', 'register_routes' ) );

		// Enqueue admin assets for gateway settings screen.
		add_action( 'admin_enqueue_scripts', array( 'MutoPay_Gateway', 'enqueue_admin_assets' ) );

		// Register cron handler.
		add_action( 'mutopay_poll_pending_payments', array( 'MutoPay_Cron', 'run' ) );

		// Register block checkout integration.
		add_action(
			'woocommerce_blocks_payment_method_type_registration',
			function ( $registry ) {
				require_once plugin_dir_path( __FILE__ ) . 'includes/class-mutopay-blocks-integration.php';
				$registry->register( new MutoPay_Blocks_Integration() );
			}
		);
	}
);

/**
 * Plugin activation: schedule cron job.
 */
register_activation_hook(
	__FILE__,
	function () {
		if ( ! wp_next_scheduled( 'mutopay_poll_pending_payments' ) ) {
			wp_schedule_event( time(), 'mutopay_fifteen_minutes', 'mutopay_poll_pending_payments' );
		}
		set_transient( 'mutopay_activated', true, 30 );
	}
);

/**
 * Redirect to settings page after activation.
 */
add_action(
	'admin_init',
	function () {
		if ( ! get_transient( 'mutopay_activated' ) ) {
			return;
		}
		delete_transient( 'mutopay_activated' );

		// Skip redirect during bulk activation or if WooCommerce isn't active.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- WordPress core sets activate-multi during bulk activation.
		if ( wp_doing_ajax() || isset( $_GET['activate-multi'] ) || ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		wp_safe_redirect( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=mutopay' ) );
		exit;
	}
);

/**
 * Plugin deactivation: clear cron job.
 */
register_deactivation_hook(
	__FILE__,
	function () {
		wp_clear_scheduled_hook( 'mutopay_poll_pending_payments' );
	}
);

/**
 * Add custom cron schedule (every 15 minutes).
 */
add_filter(
	'cron_schedules',
	function ( $schedules ) {
		$schedules['mutopay_fifteen_minutes'] = array(
			'interval' => 900,
			'display'  => __( 'Every 15 minutes', 'mutopay-for-woocommerce' ),
		);
		return $schedules;
	}
);
