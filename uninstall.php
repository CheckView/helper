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
$checkview_options = get_option( 'checkview_advance_options', array() );
$delete_all        = ! empty( $checkview_options['checkview_delete_data'] ) ? $checkview_options['checkview_delete_data'] : '';
if ( $delete_all ) {
	delete_option( 'checkview_advance_options' );
	delete_option( 'checkview_log_options' );
	delete_site_option( 'checkview_admin_menu_title' );

	$cv_entry_table = $wpdb->prefix . 'cv_entry';
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %s', $cv_entry_table ) );

	$cv_entry_meta_table = $wpdb->prefix . 'cv_entry_meta';
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %s', $cv_entry_meta_table ) );

	$cv_session_table = $wpdb->prefix . 'cv_session';
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %s', $cv_session_table ) );

	$options_table = $wpdb->prefix . 'options';
	$wpdb->query( $wpdb->prepare( 'Delete from %s where option_name like %s', $options_table, '%CF_TEST_%' ) );
}
