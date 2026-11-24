<?php
/**
 * Removes the plugin's settings and log when it is deleted.
 *
 * @package Hermaily
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Deletes the plugin's data on the current site.
 */
function hermaily_uninstall_site() {
	global $wpdb;

	delete_option( 'hermaily_settings' );
	delete_option( 'hermaily_domain' );
	delete_option( 'hermaily_db_version' );
	wp_clear_scheduled_hook( 'hermaily_purge_log' );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Dropping the plugin's own table.
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}hermaily_log" );
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids' ) ) as $hermaily_site_id ) {
		switch_to_blog( $hermaily_site_id );
		hermaily_uninstall_site();
		restore_current_blog();
	}
} else {
	hermaily_uninstall_site();
}
