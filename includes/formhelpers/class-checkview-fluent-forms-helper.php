<?php
/**
 * Checkview_Fluent_Forms_Helper class
 *
 * @since 1.0.0
 *
 * @package Checkview
 * @subpackage Checkview/includes/formhelpers
 */

if ( ! defined( 'WPINC' ) ) {
	die( 'Direct access not Allowed.' );
}

if ( ! class_exists( 'Checkview_Fluent_Forms_Helper' ) ) {
	/**
	 * Adds support for Fluent Forms.
	 *
	 * During CheckView tests, modifies Fluent Forms hooks, overwrites the
	 * recipient email address, and handles test cleanup.
	 *
	 * @package Checkview
	 * @subpackage Checkview/includes/formhelpers
	 * @author Check View <support@checkview.io>
	 */
	class Checkview_Fluent_Forms_Helper {
		/**
		 * Loader.
		 *
		 * @since 1.0.0
		 * @access protected
		 *
		 * @var Checkview_Loader $loader Maintains and registers all hooks for the plugin.
		 */
		public $loader;

		/**
		 * Constructor.
		 *
		 * Initiates loader property, adds hooks.
		 */
		public function __construct() {
			$this->loader = new Checkview_Loader();

			// Change Email address to our test email.
			if ( defined( 'TEST_EMAIL' ) && get_option( 'disable_email_receipt' ) == false ) {
				add_filter(
					'fluentform/email_to',
					array( $this, 'checkview_remove_receipt' ),
					99,
					4,
				);

				add_filter(
					'fluentform/email_template_header',
					array( $this, 'checkview_remove_email_header' ),
					99,
					2,
				);
			}

			// Disable email recipients.
			if ( defined( 'TEST_EMAIL' ) && get_option( 'disable_email_receipt' ) == true ) {
				add_filter(
					'fluentform/email_to',
					array( $this, 'checkview_inject_email' ),
					99,
					4,
				);
			}

			add_action(
				'fluentform/submission_inserted',
				array( $this, 'checkview_clone_fluentform_entry' ),
				99,
				3
			);

			add_filter(
				'fluentform/has_recaptcha',
				'__return_false',
				20,
			);

			add_filter(
				'fluentform/has_hcaptcha',
				'__return_false',
			);

			add_filter(
				'fluentform/has_turnstile',
				'__return_false',
			);

			add_filter(
				'fluentform/akismet_check_spam',
				'__return_false',
				20,
			);

			add_filter(
				'cfturnstile_whitelisted',
				'__return_true',
				999,
			);

			add_filter(
				'fluentform/recaptcha_v3_ref_score',
				function ( $score ) {
					return -8;
				},
				99,
			);

			// Bypass hCaptcha.
			add_filter( 'hcap_activate', '__return_false' );

			// Bypass Akismet.
			add_filter(
				'akismet_get_api_key',
				'__return_null',
				-10
			);

			// Disable feeds.
			add_filter(
				'fluentform/global_notification_active_types',
				array( $this, 'checkview_disable_form_actions' ),
				99,
				2,
			);

			// Disbale honeypot.
			add_filter(
				'fluentform/honeypot_status',
				'__return_false',
				999,
			);

			add_filter(
				'fluentform/token_based_spam_protection_status',
				'__return_false',
				999
			);

			add_filter(
				'fluentform/disable_captcha',
				'__return_true',
				999
			);
		}

		/**
		 * Appends our test email for test form submissions.
		 *
		 * @param string $address Email address.
		 * @param string $notification Email notification.
		 * @param array  $submitted_data Fluent Forms submitted data.
		 * @param object $form Fluent Forms form object.
		 * @return string Email.
		 */
		public function checkview_inject_email( $address, $notification, $submitted_data, $form ) {
			if ( is_array( $address ) ) {
				$address[] = TEST_EMAIL;
			} else {
				$address .= ', ' . TEST_EMAIL;
			}
			return $address;
		}

		/**
		 * Overwrites email recipient for test form submissions.
		 *
		 * @param string $address Email address.
		 * @param string $notification Email notification.
		 * @param array  $submitted_data Fluent Forms submitted data.
		 * @param object $form Fluent Forms form object.
		 * @return string Email.
		 */
		public function checkview_remove_receipt( $address, $notification, $submitted_data, $form ) {
			return TEST_EMAIL;
		}

		/**
		 * Removes email headers.
		 *
		 * @param array $headers email header.
		 * @param array $notification .notifications.
		 * @return array
		 */
		public function checkview_remove_email_header( array $headers, array $notification ): array {
			// Ensure headers are an array.
			if ( ! is_array( $headers ) ) {
				$headers = explode( "\r\n", $headers );
			}
			$filtered_headers = array_filter(
				$headers,
				function ( $header ) {
					// Exclude headers that start with 'bcc:' or 'cc:'.
					return stripos( $header, 'bcc:' ) !== 0 && stripos( $header, 'cc:' ) !== 0;
				}
			);
			return array_values( $filtered_headers );
		}
		/**
		 * Stores the test results and finishes the testing session.
		 *
		 * Deletes test submission from Formidable database table.
		 *
		 * @param int    $entry_id Fluent Form ID.
		 * @param array  $form_data Fluent Form data.
		 * @param object $form Fluent Form object.
		 * @return void
		 */
		public function checkview_clone_fluentform_entry( $entry_id, $form_data, $form ) {
			global $wpdb;

			Checkview_Admin_Logs::add( 'ip-logs', 'Cloning submission entry [' . $entry_id . ']...' );

			$form_id = $form->id;
			$checkview_test_id = get_checkview_test_id();

			if ( empty( $checkview_test_id ) ) {
				$checkview_test_id = $form_id . gmdate( 'Ymd' );
			}

			// Clone entry to check view tables.
			$tablename = $wpdb->prefix . 'fluentform_entry_details';
			$rows = $wpdb->get_results( $wpdb->prepare( 'Select * from ' . $tablename . ' where submission_id=%d and form_id=%d order by id ASC', $entry_id, $form_id ) );
			$count = 0;
			$entry_meta_table = $wpdb->prefix . 'cv_entry_meta';

			foreach ( $rows as $row ) {
				$meta_key = 'ff_' . $form_id . '_' . $row->field_name;

				if ( '' !== $row->sub_field_name ) {
					$meta_key .= '_' . $row->sub_field_name . '_';
				}

				$data  = array(
					'uid'        => $checkview_test_id,
					'form_id'    => $form_id,
					'entry_id'   => $row->submission_id,
					'meta_key'   => $meta_key,
					'meta_value' => $row->field_value,
				);

				$result = $wpdb->insert( $entry_meta_table, $data );

				if ( $result ) {
					$count++;
				}
			}

			if ( $count > 0 ) {
				Checkview_Admin_Logs::add( 'ip-logs', 'Cloned submission entry meta data (inserted ' . $count . ' rows into ' . $entry_meta_table . ').' );
			} else {
				if ( count( $rows ) > 0 ) {
					Checkview_Admin_Logs::add( 'ip-logs', 'Failed to clone submission entry meta data.' );
				}
			}

			$tablename = $wpdb->prefix . 'fluentform_submissions';
			$row = $wpdb->get_row( $wpdb->prepare( 'Select * from ' . $tablename . ' where id=%d and form_id=%d LIMIT 1', $entry_id, $form_id ), ARRAY_A );
			$entry_table = $wpdb->prefix . 'cv_entry';
			$data = array(
				'uid' => $checkview_test_id,
				'form_type' => 'FluentForms',
				'form_id' => $form_id,
				'source_url' => isset( $row['source_url'] ) ? $row['source_url'] : 'n/a',
				'response' => isset( $row['response'] ) ? $row['response'] : 'n/a',
				'user_agent' => isset( $row['browser'] ) ? $row['browser'] : 'n/a',
				'ip' => isset( $row['ip'] ) ? $row['ip'] : 'n/a',
				'date_created' => isset( $row['created_at'] ) ? $row['created_at'] : 'n/a',
				'date_updated' => isset( $row['updated_at'] ) ? $row['updated_at'] : 'n/a',
				'payment_status' => isset( $row['payment_status'] ) ? $row['payment_status'] : 'n/a',
				'payment_method' => isset( $row['payment_method'] ) ? $row['payment_payment'] : 'n/a',
				'payment_amount' => isset( $row['payment_total'] ) ? $row['payment_total'] : 0,
			);

			$result = $wpdb->insert( $entry_table, $data );

			if ( ! $result ) {
				Checkview_Admin_Logs::add( 'ip-logs', 'Failed to clone submission entry data.' );
			} else {
				Checkview_Admin_Logs::add( 'ip-logs', 'Cloned submission entry data (inserted ' . (int) $result . ' rows into ' . $entry_table . ').' );
			}

			// remove entry from Fluent forms tables.
			$delete = wpFluent()->table( 'fluentform_submissions' )
			->where( 'form_id', $form_id )
			->where( 'id', '=', $entry_id )
			->delete();
			$delete = wpFluent()->table( 'fluentform_entry_details' )
			->where( 'form_id', $form_id )
			->where( 'submission_id', '=', $entry_id )
			->delete();

			complete_checkview_test( $checkview_test_id );
		}

		/**
		 * Disables Form actions.
		 *
		 * @param array $notifications form actions.
		 * @param int   $form_id form id.
		 * @return array
		 */
		public function checkview_disable_form_actions( $notifications, $form_id ) {
			if ( get_option( 'disable_actions', false ) ) {
				// List of allowed action types.
				$notifications['notifications'] = 'email_notifications';
			}
			return $notifications;
		}
	}

	$checkview_fluent_forms_helper = new Checkview_Fluent_Forms_Helper();
}
