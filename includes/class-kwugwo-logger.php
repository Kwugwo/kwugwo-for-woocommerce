<?php
/**
 * Writes to the WooCommerce log (source "kwugwo") when the gateway's debug
 * log setting is on.
 *
 * @package Kwugwo\WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Logger.
 */
class Kwugwo_Logger {

	/**
	 * Whether logging is on, cached per request.
	 *
	 * @var bool|null
	 */
	private static $enabled = null;

	/**
	 * Write a line to the log.
	 *
	 * @param string $message Message.
	 * @param string $level   A WC_Log_Levels level.
	 */
	public static function log( $message, $level = 'info' ) {
		if ( null === self::$enabled ) {
			$settings      = get_option( 'woocommerce_' . KWUGWO_WC_GATEWAY_ID . '_settings', array() );
			self::$enabled = isset( $settings['debug'] ) && 'yes' === $settings['debug'];
		}

		if ( self::$enabled && function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->log( $level, (string) $message, array( 'source' => 'kwugwo' ) );
		}
	}
}
