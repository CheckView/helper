<?php
/**
 * Checkview_Deactivator class
 *
 * @since 1.0.0
 *
 * @package Checkview
 * @subpackage Checkview/includes
 */

/**
 * Handles plugin deactivation.
 *
 * @since 1.0.0
 * @package Checkview
 * @subpackage Checkview/includes
 * @author Check View <support@checkview.io>
 */
class Checkview_Deactivator {

	/**
	 * Deactivation sequence.
	 *
	 * Clears scheduled jobs and events.
	 *
	 * @since 1.0.0
	 */
	public static function deactivate() {
		// Clear the cron job.
		wp_clear_scheduled_hook( 'checkview_delete_orders_action' );
		$timestamp = wp_next_scheduled( 'checkview_nonce_cleanup_cron' );
		wp_unschedule_event( $timestamp, 'checkview_nonce_cleanup_cron' );
		$timestamp = wp_next_scheduled( 'checkview_delete_table_cron_hook' );
		wp_unschedule_event( $timestamp, 'checkview_delete_table_cron_hook' );
		$timestamp = wp_next_scheduled( 'checkview_options_cleanup_cron' );
		wp_unschedule_event( $timestamp, 'checkview_options_cleanup_cron' );
	}
}
