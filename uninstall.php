<?php
/**
 * Removes the plugin's tables and options when it is deleted from the Plugins screen.
 *
 * @package Saizul\RedirectManager
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Drop the tables and delete the options of the current site.
 */
function wp_redirect_manager_uninstall_site() {
	global $wpdb;
	foreach ( array( 'redirect_manager', 'redirect_manager_404' ) as $wp_redirect_manager_table ) {
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . $wp_redirect_manager_table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
	}
	delete_option( 'wp_redirect_manager_settings' );
	delete_option( 'wp_redirect_manager_index' );
	delete_option( 'wp_redirect_manager_db_version' );
	wp_clear_scheduled_hook( 'wp_redirect_manager_purge_404' );
}

if ( is_multisite() ) {
	$wp_redirect_manager_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);
	foreach ( $wp_redirect_manager_site_ids as $wp_redirect_manager_site_id ) {
		switch_to_blog( (int) $wp_redirect_manager_site_id );
		wp_redirect_manager_uninstall_site();
		restore_current_blog();
	}
} else {
	wp_redirect_manager_uninstall_site();
}
