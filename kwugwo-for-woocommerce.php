<?php
/**
 * Plugin Name:          Kwugwo for WooCommerce
 * Plugin URI:           https://kwugwo.africa
 * Description:          Accept bank transfer, USSD and pay-with-bank payments in Nigeria through the Kwugwo checkout, routed to the payment providers you already use.
 * Version:              1.0.0
 * Author:               Kwugwo
 * Author URI:           https://github.com/Kwugwo/kwugwo-for-woocommerce
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          kwugwo-for-woocommerce
 * Domain Path:          /languages
 * Requires at least:    6.5
 * Requires PHP:         7.4
 * Requires Plugins:     woocommerce
 * WC requires at least: 8.2
 * WC tested up to:      11.1
 *
 * @package Kwugwo\WooCommerce
 */

defined( 'ABSPATH' ) || exit;

define( 'KWUGWO_WC_VERSION', '1.0.0' );
define( 'KWUGWO_WC_FILE', __FILE__ );
define( 'KWUGWO_WC_PATH', plugin_dir_path( __FILE__ ) );
define( 'KWUGWO_WC_URL', plugin_dir_url( __FILE__ ) );
define( 'KWUGWO_WC_GATEWAY_ID', 'kwugwo' );

/**
 * Load the plugin once WooCommerce is available.
 */
function kwugwo_wc_init() {
	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		return;
	}

	require_once KWUGWO_WC_PATH . 'includes/class-kwugwo-logger.php';
	require_once KWUGWO_WC_PATH . 'includes/class-kwugwo-api.php';
	require_once KWUGWO_WC_PATH . 'includes/class-kwugwo-payment-sync.php';
	require_once KWUGWO_WC_PATH . 'includes/class-kwugwo-gateway.php';
	require_once KWUGWO_WC_PATH . 'includes/class-kwugwo-webhook.php';

	add_filter( 'woocommerce_payment_gateways', 'kwugwo_wc_add_gateway' );

	Kwugwo_Webhook::init();
	Kwugwo_Payment_Sync::init();

	if ( is_admin() ) {
		require_once KWUGWO_WC_PATH . 'includes/class-kwugwo-admin.php';
		Kwugwo_Admin::init();
	}
}
add_action( 'plugins_loaded', 'kwugwo_wc_init', 11 );

/**
 * Register the gateway with WooCommerce.
 *
 * @param string[] $gateways Gateway class names.
 * @return string[]
 */
function kwugwo_wc_add_gateway( $gateways ) {
	$gateways[] = 'Kwugwo_Gateway';
	return $gateways;
}

/**
 * The loaded Kwugwo gateway instance.
 *
 * @return Kwugwo_Gateway|null
 */
function kwugwo_wc_gateway() {
	if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways() ) {
		return null;
	}
	$gateways = WC()->payment_gateways()->payment_gateways();
	return isset( $gateways[ KWUGWO_WC_GATEWAY_ID ] ) ? $gateways[ KWUGWO_WC_GATEWAY_ID ] : null;
}

/**
 * A short random id for this store, used to prefix payment references so
 * several stores can share one Kwugwo workspace without clashing.
 *
 * @return string
 */
function kwugwo_wc_site_id() {
	$site_id = get_option( 'kwugwo_wc_site_id' );
	if ( ! $site_id ) {
		$site_id = strtolower( wp_generate_password( 6, false ) );
		update_option( 'kwugwo_wc_site_id', $site_id, false );
	}
	return $site_id;
}

/**
 * Activation: make sure the store id exists.
 */
function kwugwo_wc_activate() {
	kwugwo_wc_site_id();
}
register_activation_hook( __FILE__, 'kwugwo_wc_activate' );

/**
 * Deactivation: stop the background payment check.
 */
function kwugwo_wc_deactivate() {
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( 'kwugwo_wc_reconcile' );
	}
}
register_deactivation_hook( __FILE__, 'kwugwo_wc_deactivate' );

/**
 * Declare compatibility with High-Performance Order Storage and the
 * Cart & Checkout blocks.
 */
function kwugwo_wc_declare_compatibility() {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
	}
}
add_action( 'before_woocommerce_init', 'kwugwo_wc_declare_compatibility' );

/**
 * Register the Cart & Checkout blocks integration.
 */
function kwugwo_wc_register_blocks_support() {
	if ( ! class_exists( \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType::class ) ) {
		return;
	}

	require_once KWUGWO_WC_PATH . 'includes/class-kwugwo-blocks-support.php';

	add_action(
		'woocommerce_blocks_payment_method_type_registration',
		static function ( $registry ) {
			$registry->register( new Kwugwo_Blocks_Support() );
		}
	);
}
add_action( 'woocommerce_blocks_loaded', 'kwugwo_wc_register_blocks_support' );

/**
 * Add a "Settings" link on the Plugins screen.
 *
 * @param string[] $links Action links.
 * @return string[]
 */
function kwugwo_wc_action_links( $links ) {
	$url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=' . KWUGWO_WC_GATEWAY_ID );
	array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Set up', 'kwugwo-for-woocommerce' ) . '</a>' );
	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'kwugwo_wc_action_links' );
