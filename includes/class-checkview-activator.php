<?php
/**
 * Checkview_Activator class
 *
 * @since 1.0.0
 *
 * @package Checkview
 * @subpackage Checkview/includes
 */

/**
 * Sets up CheckView on plugin activation.
 *
 * Sets up database tables used by the plugin.
 *
 * @since 1.0.0
 * @package Checkview
 * @subpackage Checkview/includes
 * @author Check View <support@checkview.io>
 */
class Checkview_Activator {

	/**
	 * Runs the activation sequence.
	 *
	 * @since 1.0.0
	 */
	public static function activate() {
		self::checkview_run_sql();
		// Set transient.
		set_transient( 'checkview_activation_notification', true, 5 );
	}

	/**
	 * Generates database tables.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function checkview_run_sql() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$cv_entry_table = $wpdb->prefix . 'cv_entry';
		if ( $wpdb->get_var( $wpdb->prepare( 'show tables like %s', $cv_entry_table ) ) !== $cv_entry_table ) {
			$sql  = "CREATE TABLE  `$cv_entry_table` (";
			$sql .= '`id` int(10) unsigned NOT NULL AUTO_INCREMENT,';
			$sql .= '`form_id` mediumint(10) unsigned NOT NULL,';
			$sql .= '`uid` varchar(255) COLLATE utf8mb4_unicode_520_ci NOT NULL,';
			$sql .= '`post_id` bigint(10) unsigned DEFAULT NULL,';
			$sql .= '`date_created` datetime NOT NULL,';
			$sql .= '`date_updated` datetime DEFAULT NULL,';
			$sql .= '`is_starred` tinyint(10) NOT NULL DEFAULT 0,';
			$sql .= '`is_read` tinyint(10) NOT NULL DEFAULT 0,';
			$sql .= '`ip` varchar(45) COLLATE utf8mb4_unicode_520_ci NOT NULL,';
			$sql .= "`source_url` varchar(200) COLLATE utf8mb4_unicode_520_ci NOT NULL DEFAULT '',";
			$sql .= "`user_agent` varchar(250) COLLATE utf8mb4_unicode_520_ci NOT NULL DEFAULT '',";
			$sql .= '`currency` varchar(5) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,';
			$sql .= '`payment_status` varchar(15) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,';
			$sql .= '`payment_date` datetime DEFAULT NULL,';
			$sql .= '`payment_amount` decimal(19,2) DEFAULT NULL,';
			$sql .= '`payment_method` varchar(30) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,';
			$sql .= '`transaction_id` varchar(50) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,';
			$sql .= '`is_fulfilled` tinyint(10) DEFAULT NULL,';
			$sql .= '`created_by` bigint(10) unsigned DEFAULT NULL,';
			$sql .= '`transaction_type` tinyint(10) DEFAULT NULL,';
			$sql .= "`status` varchar(20) COLLATE utf8mb4_unicode_520_ci NOT NULL DEFAULT 'active',";
			$sql .= '`form_type` varchar(20) COLLATE utf8mb4_unicode_520_ci NOT NULL,';
			$sql .= '`response` longtext COLLATE utf8mb4_unicode_520_ci NOT NULL,';
			$sql .= 'PRIMARY KEY (`id`),';
			$sql .= 'KEY `form_id` (`form_id`)';
			$sql .= ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;';
			dbDelta( $sql );
		}
		$cv_entry_meta_table = $wpdb->prefix . 'cv_entry_meta';
		if ( $wpdb->get_var( $wpdb->prepare( 'show tables like %s', $cv_entry_meta_table ) ) !== $cv_entry_meta_table ) {
			$sql  = "CREATE TABLE `$cv_entry_meta_table` (";
			$sql .= '`id` bigint(10) unsigned NOT NULL AUTO_INCREMENT,';
			$sql .= '`form_id` mediumint(10) unsigned NOT NULL DEFAULT 0,';
			$sql .= '`uid` varchar(255) COLLATE utf8mb4_unicode_520_ci NOT NULL,';
			$sql .= '`entry_id` bigint(10) unsigned NOT NULL,';
			$sql .= '`meta_key` varchar(255) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,';
			$sql .= '`meta_value` longtext COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,';
			$sql .= '`item_index` varchar(60) COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,';
			$sql .= 'PRIMARY KEY (`id`),';
			$sql .= 'KEY `meta_key` (`meta_key`(191)),';
			$sql .= 'KEY `entry_id` (`entry_id`),';
			$sql .= 'KEY `meta_value` (`meta_value`(191))';
			$sql .= ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;';
			dbDelta( $sql );
		}

		$cv_session_table = $wpdb->prefix . 'cv_session';
		if ( $wpdb->get_var( $wpdb->prepare( 'show tables like %s', $cv_session_table ) ) !== $cv_session_table ) {
			$sql  = "CREATE TABLE `$cv_session_table` (";
			$sql .= '`visitor_ip` varchar(255) DEFAULT NULL,';
			$sql .= '`test_key` varchar(255) DEFAULT NULL,';
			$sql .= '`test_id` varchar(255) DEFAULT NULL';
			$sql .= ') ENGINE=InnoDB DEFAULT CHARSET=utf8;';
			dbDelta( $sql );
		}

		$cv_used_nonces = $wpdb->prefix . 'cv_used_nonces';

		$charset_collate = $wpdb->get_charset_collate();
		if ( $wpdb->get_var( $wpdb->prepare( 'show tables like %s', $cv_used_nonces ) ) !== $cv_used_nonces ) {
				$sql = "CREATE TABLE $cv_used_nonces (
				id BIGINT(20) NOT NULL AUTO_INCREMENT,
				nonce VARCHAR(255) NOT NULL,
				used_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY nonce (nonce)
			) $charset_collate;";
			dbDelta( $sql );
		}
	}
}
