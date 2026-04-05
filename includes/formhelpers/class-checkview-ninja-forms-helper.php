<?php
/**
 * Checkview_Ninja_Forms_Helper class
 *
 * @since 1.0.0
 *
 * @package Checkview
 * @subpackage Checkview/includes/formhelpers
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die( 'Direct access not Allowed.' );
}

if ( ! class_exists( 'Checkview_Ninja_Forms_Helper' ) ) {
	/**
	 * Adds support for Ninja Forms.
	 *
	 * During CheckView tests, modifies Ninja Forms hooks, overwrites the
	 * recipient email address, and handles test cleanup.
	 *
	 * @package Checkview
	 * @subpackage Checkview/includes/formhelpers
	 * @author Check View <support@checkview.io>
	 */
	class Checkview_Ninja_Forms_Helper {
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

			add_action(
				'ninja_forms_after_submission',
				array(
					$this,
					'checkview_clone_entry',
				),
				99,
				1
			);

			add_filter(
				'akismet_get_api_key',
				'__return_null',
				-10
			);

			add_filter(
				'ninja_forms_form_fields',
				array(
					$this,
					'checkview_maybe_remove_v2_field',
				),
				20
			);

			add_filter(
				'ninja_forms_validate_fields',
				function ( $check, $data ) {
					return false;
				},
				99,
				2
			);

			add_filter(
				'cfturnstile_whitelisted',
				'__return_true',
				999
			);

			add_filter(
				'ninja_forms_action_recaptcha__verify_response',
				'__return_true',
				99
			);

			if ( defined( 'TEST_EMAIL' ) ) {
				add_filter(
					'ninja_forms_action_email_send',
					array(
						$this,
						'checkview_inject_email',
					),
					99,
					5
				);
			}

			// Disable form actions.
			add_filter(
				'ninja_forms_submission_actions',
				array(
					$this,
					'checkview_disable_form_actions',
				),
				99,
				3
			);
		}

		/**
		 * Removes CC and BCC from the form submission email.
		 *
		 * @param string $sent Status of email.
		 * @param array  $action_settings Settings for actions.
		 * @param string $message Message to be sent.
		 * @param array  $headers Headers details.
		 * @param array  $attachments Attachments if any.
		 * @return bool
		 */
		public function checkview_inject_email( $sent, $action_settings, $message, $headers, $attachments ) {
			// Ensure headers are an array.
			if ( ! is_array( $headers ) ) {
				$headers = explode( "\r\n", $headers );
			}

			// Filter out 'Cc:' and 'Bcc:' headers.
			$filtered_headers = array_filter(
				$headers,
				function ( $header ) {
					return stripos( $header, 'Cc:' ) === false && stripos( $header, 'Bcc:' ) === false;
				}
			);

			Checkview_Admin_Logs::add( 'ip-logs', 'Submission recipient email address: ' . wp_json_encode( TEST_EMAIL ) );
			Checkview_Admin_Logs::add( 'ip-logs', 'Submission email headers: ' . wp_json_encode( $filtered_headers ) );

			// Send the email without the 'Cc:' and 'Bcc:' headers.
			wp_mail( TEST_EMAIL, wp_strip_all_tags( $action_settings['email_subject'] ), $message, $filtered_headers, $attachments );
			$cv_test_id = get_checkview_test_id();
			if ( ! $cv_test_id || 'true' != get_option( 'disable_email_receipt_' . $cv_test_id, false ) ) {
				return true;
			} else {
				return false;
			}
		}
		/**
		 * Stores the test results and finishes the testing session.
		 *
		 * Deletes test submission from Formidable database table.
		 *
		 * @param array $form_data Form data.
		 * @return void
		 */
		public function checkview_clone_entry( $form_data ) {
			global $wpdb;

			$form_id  = $form_data['form_id'];
			$entry_id = (int) ( isset( $form_data['actions']['save']['sub_id'] ) ? $form_data['actions']['save']['sub_id'] : 0 );

			Checkview_Admin_Logs::add( 'ip-logs', 'Cloning submission entry [' . $entry_id . ']...' );

			$checkview_test_id = get_checkview_test_id();

			if ( empty( $checkview_test_id ) ) {
				$checkview_test_id = $form_id . gmdate( 'Ymd' );
			}

			// Insert Entry.
			$entry_data  = array(
				'form_id' => $form_id,
				'status' => 'publish',
				'source_url' => isset( $_SERVER['HTTP_REFERER'] ) ? sanitize_url( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '',
				'date_created' => current_time( 'mysql' ),
				'date_updated' => current_time( 'mysql' ),
				'uid' => $checkview_test_id,
				'form_type' => 'NinjaForms',
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

			// Read field values from the raw AJAX POST data instead of
			// wp_postmeta or $form_data['fields']. NF actions in the
			// Submission.php action loop (lines 500-508) can replace
			// $this->_data, clearing values for conditionally hidden
			// fields before ninja_forms_after_submission fires.
			// The raw $_POST['formData'] JSON contains the original
			// values submitted by the browser.
			$raw_fields = array();
			if ( isset( $_POST['formData'] ) ) {
				$raw_form = json_decode( stripslashes( $_POST['formData'] ), true );
				if ( is_array( $raw_form ) && ! empty( $raw_form['fields'] ) && is_array( $raw_form['fields'] ) ) {
					$raw_fields = $raw_form['fields'];
				}
			}

			// Use $form_data['fields'] for field metadata (type, key)
			// but override the value from raw POST when available.
			$fields_source = ( ! empty( $form_data['fields'] ) && is_array( $form_data['fields'] ) )
				? $form_data['fields']
				: array();

			if ( empty( $fields_source ) ) {
				Checkview_Admin_Logs::add( 'ip-logs', 'WARNING: form_data[fields] is missing or not an array.' );
			} else {
				$skip_types = array( 'submit', 'html', 'hr', 'divider', 'note', 'confirm', 'save', 'recaptcha', 'spam', 'hcaptcha-for-ninja-forms' );

				foreach ( $fields_source as $field_id => $field ) {
					if ( ! is_array( $field ) ) {
						continue;
					}

					$type = $field['type'] ?? '';
					if ( in_array( $type, $skip_types, true ) ) {
						continue;
					}

					// Prefer the raw POST value over the (possibly modified) hook value.
					$value = '';
					if ( isset( $raw_fields[ $field_id ]['value'] ) ) {
						$value = $raw_fields[ $field_id ]['value'];
					} else {
						$value = $field['value'] ?? '';
					}

					if ( is_array( $value ) ) {
						$value = serialize( $value );
					} else {
						$value = (string) $value;
					}

					$entry_metadata = array(
						'uid'        => $checkview_test_id,
						'form_id'    => $form_id,
						'entry_id'   => $entry_id,
						'meta_key'   => 'nf-field-' . $field_id,
						'meta_value' => $value,
					);

					$result = $wpdb->insert( $entry_meta_table, $entry_metadata );

					if ( $result ) {
						$count++;
					}
				}
			}

			if ( $count > 0 ) {
				Checkview_Admin_Logs::add( 'ip-logs', 'Cloned submission entry meta data (inserted ' . $count . ' rows into ' . $entry_meta_table . ').' );
			} else {
				Checkview_Admin_Logs::add( 'ip-logs', 'Failed to clone submission entry meta data.' );
			}

			wp_delete_post( $entry_id, true );

			complete_checkview_test( $checkview_test_id );
		}

		/**
		 * Removes ReCAPTCHA fields from the test form.
		 *
		 * @param array $fields Fields of the form.
		 *
		 * @return array
		 */
		public function checkview_maybe_remove_v2_field( $fields ) {
			foreach ( $fields as $key => $field ) {
				if ( 'recaptcha' === $field->get_setting( 'type' ) || 'hcaptcha-for-ninja-forms' === $field->get_setting( 'type' ) || 'akismet' === $field->get_setting( 'type' ) ) {
					// Remove v2 reCAPTCHA, hcaptcha fields if still configured.
					unset( $fields[ $key ] );
				}
			}
			return $fields;
		}

		/**
		 * Disables Form actions.
		 *
		 * @param array $form_cache_actions form actions.
		 * @param array $form_cache form cache.
		 * @param array $form_data form data.
		 * @return array
		 */
		public function checkview_disable_form_actions( $form_cache_actions, $form_cache, $form_data ) {
			$cv_test_id = get_checkview_test_id();
			if ( ! $cv_test_id || 'true' != get_option( 'disable_actions_' . $cv_test_id, false ) ) {
				return $form_cache_actions;
			}
			// List of allowed action types.
			$allowed_actions = array( 'email', 'successmessage', 'save' );

			// Iterate over each action and check type.
			foreach ( $form_cache_actions as &$action ) {
				// Check if the type is in allowed types.
				if ( ! in_array( $action['settings']['type'], $allowed_actions ) ) {
					$action['settings']['active'] = 0; // Set active to 0 if type is not in allowed types.
				}
			}
			return $form_cache_actions;
		}
	}

	$checkview_ninjaforms_helper = new Checkview_Ninja_Forms_Helper();
}
