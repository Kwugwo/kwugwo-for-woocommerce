<?php
/**
 * Remove the plugin's settings when it is deleted. Order data is kept,
 * because payment records belong to the store's accounts.
 *
 * @package Kwugwo\WooCommerce
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'woocommerce_kwugwo_settings' );
delete_option( 'kwugwo_wc_site_id' );
delete_option( 'kwugwo_wc_verified' );
delete_option( 'kwugwo_wc_last_webhook' );

global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off cleanup of this plugin's transients.
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_kwugwo_wc_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_kwugwo_wc_' ) . '%'
	)
);

if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'kwugwo_wc_reconcile' );
}
