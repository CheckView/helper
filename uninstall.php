<?php
/**
 * CheckView uninstallation
 *
 * @since 1.0.0
 *
 * @package Checkview
 */

// If uninstall not called from WordPress, then exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Check if the current user has the necessary capability.
if ( ! current_user_can( 'manage_options' ) ) {
	die( esc_html__( 'You are not allowed to uninstall this plugin.', 'checkview' ) );
}

global $wpdb;
$checkview_options    = get_option( 'checkview_advance_options', array() );
$checkview_delete_all = ! empty( $checkview_options['checkview_delete_data'] ) ? $checkview_options['checkview_delete_data'] : '';

if ( $checkview_delete_all ) {
	delete_option( 'checkview_advance_options' );
	delete_option( 'checkview_log_options' );
	delete_option( 'checkview_logs_dir_key' ); // Written by 2.4.1 pre-release builds only.
	delete_option( 'checkview_logs_folder_version' );
	delete_site_option( 'checkview_admin_menu_title' );

	$checkview_tables = array( 'cv_entry', 'cv_entry_meta', 'cv_session', 'cv_used_nonces' );
	foreach ( $checkview_tables as $checkview_table ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- Plugin-owned table.
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . $checkview_table ) );
	}

	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
			'%' . $wpdb->esc_like( 'CF_TEST_' ) . '%'
		)
	);
}
