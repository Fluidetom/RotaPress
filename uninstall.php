<?php
/**
 * RotaPress uninstall script.
 *
 * @package RotaPress
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$rotapress_keep = get_option( 'rotapress_keep_data', '0' );
if ( '1' === $rotapress_keep ) {
	return;
}

// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'rotapress\_%'" );

// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$rotapress_post_ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'rp_event'" );
if ( $rotapress_post_ids ) {
	foreach ( $rotapress_post_ids as $id ) {
		wp_delete_post( (int) $id, true );
	}
}

delete_metadata( 'user', 0, '_rp_ical_token', '', true );

wp_clear_scheduled_hook( 'rotapress_daily_reminders' );
