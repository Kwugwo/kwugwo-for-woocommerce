<?php
/**
 * Cart & Checkout blocks integration.
 *
 * Payment runs through the gateway's process_payment(), which sends the
 * customer to the order-pay page where the Kwugwo window opens, the same
 * as with the classic checkout.
 *
 * @package Kwugwo\WooCommerce
 */

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * Blocks payment method type.
 */
final class Kwugwo_Blocks_Support extends AbstractPaymentMethodType {

	/**
	 * Payment method name.
	 *
	 * @var string
	 */
	protected $name = KWUGWO_WC_GATEWAY_ID;

	/**
	 * Nothing to load up front; settings are read from the gateway.
	 */
	public function initialize() {}

	/**
	 * Whether the method is offered in the block checkout.
	 *
	 * @return bool
	 */
	public function is_active() {
		$gateway = kwugwo_wc_gateway();
		return $gateway && $gateway->is_available();
	}

	/**
	 * Script handles for the block checkout.
	 *
	 * @return string[]
	 */
	public function get_payment_method_script_handles() {
		wp_register_script(
			'kwugwo-blocks',
			KWUGWO_WC_URL . 'assets/js/blocks.js',
			array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities' ),
			KWUGWO_WC_VERSION,
			true
		);
		return array( 'kwugwo-blocks' );
	}

	/**
	 * Data available to blocks.js through getSetting( 'kwugwo_data' ).
	 *
	 * @return array
	 */
	public function get_payment_method_data() {
		$gateway = kwugwo_wc_gateway();
		if ( ! $gateway ) {
			return array();
		}

		return array(
			'title'        => $gateway->get_title(),
			'description'  => $gateway->get_description(),
			'icon'         => $gateway->icon,
			'testMode'     => 'sandbox' === $gateway->get_mode(),
			'testModeText' => __( 'Test mode: no real money will be taken.', 'kwugwo-for-woocommerce' ),
			'supports'     => array_values( $gateway->supports ),
		);
	}
}
