<?php
/**
 * Checkview_Forminator_Helper class
 *
 * @since 1.0.0
 *
 * @package Checkview
 * @subpackage Checkview/includes/formhelpers
 */

if ( ! defined( 'WPINC' ) ) {
	die( 'Direct access not Allowed.' );
}

if ( ! class_exists( 'Checkview_Forminator_Helper' ) ) {
	/**
	 * Adds support for Forminator.
	 *
	 * During CheckView tests, modifies Forminator hooks, overwrites the
	 * recipient email address, and handles test cleanup.
	 *
	 * @package Checkview
	 * @subpackage Checkview/includes/formhelpers
	 * @author Check View <support@checkview.io>
	 */
	class Checkview_Forminator_Helper {
		/**
		 * Loader.
		 *
		 * @since 1.0.0
		 * @access protected
		 *
		 * @var Checkview_Loader $loader Maintains and registers all hooks for the plugin.
		 */
		protected $loader;
		/**
		 * Constructor.
		 *
		 * Initiates loader property, adds hooks.
		 */
		public function __construct() {
			$this->loader = new Checkview_Loader();

			if ( defined( 'TEST_EMAIL' ) ) {
				// update email to our test email.
				add_filter(
					'forminator_form_get_admin_email_recipients',
					array(
						$this,
						'checkview_inject_email',
					),
					999,
					1
				);
			}

			add_filter(
				'forminator_mailer_headers',
				array(
					$this,
					'checkview_remove_email_header',
				),
				99,
				1
			);

			add_action(
				'forminator_custom_form_submit_before_set_fields',
				array(
					$this,
					'checkview_log_form_test_entry',
				),
				90,
				3
			);

			add_filter(
				'akismet_get_api_key',
				'__return_null',
				-10
			);

			add_filter(
				'forminator_spam_protection',
				'__return_false',
				99
			);

			add_filter(
				'cfturnstile_whitelisted',
				'__return_true',
				999
			);

			add_filter(
				'forminator_invalid_captcha_message',
				'__return_null'
			);

			// Disbale form action.
			add_filter(
				'forminator_is_addons_feature_enabled',
				array(
					$this,
					'checkview_disable_form_actions',
				),
				99,
				1
			);
		}
		/**
		 * Sets our email for test submissions.
		 *
		 * @param string $email Email address.
		 * @return string/ARRAY Email.
		 */
		public function checkview_inject_email( $email ) {
			if ( ! defined( 'CV_DISABLE_EMAIL_RECEIPT' ) ) {
				$email   = array();
				$email[] = TEST_EMAIL;
			} elseif ( is_array( $email ) ) {
				$email[] = TEST_EMAIL;
			} else {
				$email .= ', ' . TEST_EMAIL;
			}
			return $email;
		}
		/**
		 * Removes email headers.
		 *
		 * @param array $headers email header.
		 * @return array
		 */
		public function checkview_remove_email_header( array $headers ): array {
			// Ensure headers are an array.
			if ( ! is_array( $headers ) ) {
				$headers = explode( "\r\n", $headers );
			}
			$filtered_headers = array_filter(
				$headers,
				function ( $header ) {
					// Exclude headers that start with 'bcc:' or 'cc:'.
					return stripos( $header, 'BCC:' ) !== 0 && stripos( $header, 'CC:' ) !== 0;
				}
			);

			return array_values( $filtered_headers );
		}
		/**
		 * Stores the test results and finishes the testing session.
		 *
		 * Deletes test submission from Forminator database table.
		 *
		 * @param object $entry entry object.
		 * @param int    $form_id Form entry ID.
		 * @param array  $form_fields Form's fields.
		 * @return void
		 */
		public function checkview_log_form_test_entry($entry, $form_id, $form_fields) {
			global $wpdb;

			$checkview_test_id = get_checkview_test_id();
			$entry_id = $entry->entry_id;
			$form_id = $entry->form_id;

			Checkview_Admin_Logs::add( 'ip-logs', 'Cloning submission entry [' . $entry_id . ']...' );

			if ( empty( $checkview_test_id ) ) {
				$checkview_test_id = $form_id . gmdate( 'Ymd' );
			}

			// Insert entry.
			$entry_data = array(
				'form_id'      => $form_id,
				'status'       => 'publish',
				'source_url'   => isset( $_SERVER['HTTP_REFERER'] ) ? sanitize_url( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '',
				'date_created' => current_time( 'mysql' ),
				'date_updated' => current_time( 'mysql' ),
				'uid'          => $checkview_test_id,
				'form_type'    => 'Forminator',
			);

			$entry_table = $wpdb->prefix . 'cv_entry';
			$result = $wpdb->insert( $entry_table, $entry_data );

			if ( ! $result ) {
				Checkview_Admin_Logs::add( 'ip-logs', 'Failed to clone submission entry data.' );
			} else {
				Checkview_Admin_Logs::add( 'ip-logs', 'Cloned submission entry data (inserted ' . (int) $result . ' rows into ' . $entry_table . ').' );
			}

			$entry_meta_table = $wpdb->prefix . 'cv_entry_meta';
			$count = 0;

			foreach ( $form_fields as $field ) {
				if ( '_forminator_user_ip' === $field['name'] ) {
					continue;
				}

				$field_value    = $field['value'];
				$meta_key       = $field['name'];
				$entry_metadata = array(
					'uid'        => $checkview_test_id,
					'form_id'    => $form_id,
					'entry_id'   => $entry_id,
					'meta_key'   => $meta_key,
					'meta_value' => $field_value,
				);

				$result = $wpdb->insert( $entry_meta_table, $entry_metadata );

				if ( $result ) {
					$count++;
				}
			}

			if ( $count > 0 ) {
				Checkview_Admin_Logs::add( 'ip-logs', 'Cloned submission entry meta data (inserted ' . $count . ' rows into ' . $entry_meta_table . ').' );
			} else {
				if ( count( $form_fields ) > 0 ) {
					Checkview_Admin_Logs::add( 'ip-logs', 'Failed to clone submission entry meta data.' );
				}
			}

			complete_checkview_test( $checkview_test_id );

			// Delete entry.
			Forminator_Form_Entry_Model::delete_by_entry( $entry_id );
		}

		/**
		 * Removes ReCAPTCHA field from form fields and form validation.
		 *
		 * @param array $fields Array of fields.
		 * @param array $form_id Form id.
		 */
		public function remove_recaptcha_field_from_list( $fields, $form_id ) {

			// Iterate and remove captcha fields.
			// Iterate through the form data.
			foreach ( $fields as $key => &$wrapper ) {
				if ( isset( $wrapper['fields'] ) && is_array( $wrapper['fields'] ) ) {
					foreach ( $wrapper['fields'] as $field_key => $field ) {
						// Check if the field type is 'captcha'.
						if ( isset( $field['type'] ) && $field['type'] === 'captcha' ) {
							unset( $wrapper['fields'][ $field_key ] ); // Remove the captcha field.
						}
					}
					// Re-index the fields array if necessary.
					$wrapper['fields'] = array_values( $wrapper['fields'] );

					// Remove the entire wrapper if 'fields' becomes empty.
					if ( empty( $wrapper['fields'] ) ) {
						unset( $fields[ $key ] );
					}
				}
			}
			return $fields;
		}

		/**
		 * Allows custom form action trigger.
		 *
		 * @since 2.0.8
		 *
		 * @param bool $enabled   enabled default trigger.
		 */
		public function checkview_disable_form_actions( $enabled ) {
			if ( get_option( 'disable_actions', false ) ) {
				$enabled = true;
			}
			$enabled = false;
			return $enabled;
		}
		/**
		 * Bypasses captchas.
		 *
		 * @param array $settings form settings.
		 * @return array
		 */
		public function checkview_bypass_captcha( array $settings ): array {
			unset( $settings['honeypot'] );
			unset( $settings['captcha_settings'] );
			return $settings;
		}
	}

	$checkview_formidable_helper = new Checkview_Forminator_Helper();
}
