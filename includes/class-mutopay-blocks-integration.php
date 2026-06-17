<?php
/**
 * MutoPay Block Checkout Integration
 *
 * @package MutoPay_WooCommerce
 */

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MutoPay_Blocks_Integration extends AbstractPaymentMethodType {

	protected $name = 'mutopay';

	/**
	 * Initialize the block integration.
	 */
	public function initialize() {
		$this->settings = get_option( 'woocommerce_mutopay_settings', array() );
	}

	/**
	 * Whether the payment method is active.
	 */
	public function is_active() {
		return ! empty( $this->settings['enabled'] ) && 'yes' === $this->settings['enabled'];
	}

	/**
	 * Register the block checkout JS.
	 */
	public function get_payment_method_script_handles() {
		$asset_url = plugin_dir_url( MUTOPAY_WC_PLUGIN_FILE ) . 'assets/blocks/checkout.js';

		wp_register_script(
			'mutopay-blocks',
			$asset_url,
			array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities' ),
			MUTOPAY_WC_VERSION,
			true
		);

		return array( 'mutopay-blocks' );
	}

	/**
	 * Data passed to the JS payment method.
	 */
	public function get_payment_method_data() {
		return array(
			'title'       => $this->settings['title'] ?? 'Pay with Crypto',
			'description' => $this->settings['description'] ?? 'Pay with any token on 7+ chains.',
			'icon'        => plugin_dir_url( MUTOPAY_WC_PLUGIN_FILE ) . 'assets/mutopay-icon.svg',
			'supports'    => array( 'products' ),
		);
	}
}
