<?php
/**
 * Removes the plugin's options and cached results.
 *
 * @package SimpleVerifierEmailValidation
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'svev_settings' );
delete_option( 'svev_api_status' );

global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_svev_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_svev_' ) . '%',
		$wpdb->esc_like( 'svev_' ) . '%'
	)
);
