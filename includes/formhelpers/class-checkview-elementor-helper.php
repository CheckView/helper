<?php
/**
 * Checkview_Elementor_Helper class
 *
 * @since 1.0.0
 *
 * @package Checkview
 * @subpackage Checkview/includes/formhelpers
 */

if ( ! defined( 'WPINC' ) ) {
	die( 'Direct access not Allowed.' );
}

if ( ! class_exists( 'Checkview_Elementor_Helper' ) ) {
	/**
	 * Adds support for Elementor Forms.
	 *
	 * During CheckView tests, modifies Elementor Forms hooks, overwrites the
	 * recipient email address, and handles test cleanup.
	 *
	 * @package Checkview
	 * @subpackage Checkview/includes/formhelpers
	 * @author Check View <support@checkview.io>
	 */
	class Checkview_Elementor_Helper {
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
				'elementor_pro/forms/new_record',
				array(
					$this,
					'checkview_clone_elementor_entry',
				),
				999,
				2
			);

			// Skip Elementor Pro captcha/honeypot validation during test runs.
			add_filter(
				'elementor_pro/forms/validation/skip_types',
				array(
					$this,
					'checkview_skip_captcha_types',
				),
				999
			);

			// Redirect Elementor Form email notifications to the CheckView test inbox.
			add_filter(
				'elementor_pro/forms/wp_mail_fields',
				array(
					$this,
					'checkview_override_mail_fields',
				),
				99,
				2
			);

			// Limit Elementor Form submit actions to email only during test runs.
			add_filter(
				'elementor_pro/forms/submit_actions',
				array(
					$this,
					'checkview_restrict_submit_actions',
				),
				999,
				3
			);

			// Expose allowed file types as a data attribute on upload fields.
			add_action(
				'elementor_pro/forms/render_field/upload',
				array(
					$this,
					'checkview_add_upload_file_types',
				),
				9,
				3
			);

			add_filter(
				'cfturnstile_whitelisted',
				'__return_true',
				999
			);
			add_filter(
				'akismet_get_api_key',
				'__return_null',
				-10
			);

			// Bypass hCaptcha.
			add_filter(
				'hcap_activate',
				'__return_false'
			);
		}

		/**
		 * Clones the Elementor submission into CheckView tables, deletes the
		 * original Elementor submission, and finishes the testing session.
		 *
		 * @param \ElementorPro\Modules\Forms\Classes\Form_Record  $record  Form record.
		 * @param \ElementorPro\Modules\Forms\Classes\Ajax_Handler $handler Ajax handler.
		 * @return void
		 */
		public function checkview_clone_elementor_entry( $record, $handler ) {
			global $wpdb;

			if ( ! $record || ! $handler ) {
				return;
			}

			$form_id           = $record->get_form_settings( 'id' );
			$checkview_test_id = get_checkview_test_id();

			if ( empty( $checkview_test_id ) ) {
				$checkview_test_id = $form_id . gmdate( 'Ymd' );
			}

			// `cv_entry.form_id` is numeric (mediumint). Elementor's form id can
			// be a non-numeric string, which the column would silently store as
			// 0. Surface it so the SaaS-side form-id strategy can be finalized.
			if ( ! is_numeric( $form_id ) ) {
				Checkview_Admin_Logs::add( 'ip-logs', 'WARNING: Elementor form id [' . $form_id . '] is non-numeric; cv_entry.form_id will store 0.' );
			}

			Checkview_Admin_Logs::add( 'ip-logs', 'Cloning Elementor submission for form [' . $form_id . ']...' );

			$form_data = $record->get_formatted_data();
			if ( ! is_array( $form_data ) ) {
				$form_data = array();
			}

			$entry_data  = array(
				'form_id'      => $form_id,
				'status'       => 'publish',
				'source_url'   => isset( $_SERVER['HTTP_REFERER'] ) ? substr( sanitize_url( wp_unslash( $_SERVER['HTTP_REFERER'] ) ), 0, 200 ) : '',
				'date_created' => current_time( 'mysql' ),
				'date_updated' => current_time( 'mysql' ),
				'uid'          => $checkview_test_id,
				'form_type'    => 'Elementor',
			);
			$entry_table = $wpdb->prefix . 'cv_entry';
			$result      = $wpdb->insert( $entry_table, $entry_data );
			$inserted_entry_id = $wpdb->insert_id;

			if ( ! $result ) {
				Checkview_Admin_Logs::add( 'ip-logs', 'Failed to clone Elementor submission entry data. wpdb->last_error=[' . $wpdb->last_error . ']' );
			} else {
				Checkview_Admin_Logs::add( 'ip-logs', 'Cloned Elementor submission entry data (inserted ' . (int) $result . ' rows into ' . $entry_table . ').' );

				// Skip the meta loop when the parent insert failed: insert_id is
				// 0, so meta rows would be orphaned with entry_id=0.
				$entry_meta_table = $wpdb->prefix . 'cv_entry_meta';
				$count            = 0;

				foreach ( $form_data as $key => $val ) {
					$entry_metadata = array(
						'uid'        => $checkview_test_id,
						'form_id'    => $form_id,
						'entry_id'   => $inserted_entry_id,
						'meta_key'   => checkview_truncate_meta_key( $key ),
						'meta_value' => is_array( $val ) ? wp_json_encode( $val ) : $val,
					);

					if ( $wpdb->insert( $entry_meta_table, $entry_metadata ) ) {
						$count++;
					}
				}

				if ( $count > 0 ) {
					Checkview_Admin_Logs::add( 'ip-logs', 'Cloned Elementor submission entry meta data (inserted ' . $count . ' rows into ' . $entry_meta_table . ').' );
				} elseif ( ! empty( $form_data ) ) {
					Checkview_Admin_Logs::add( 'ip-logs', 'Failed to clone Elementor submission entry meta data. wpdb->last_error=[' . $wpdb->last_error . ']' );
				}
			}

			// Remove the just-saved submission from Elementor's tables.
			$submissions_table = $wpdb->prefix . 'e_submissions';
			$values_table      = $wpdb->prefix . 'e_submissions_values';

			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $submissions_table ) ) === $submissions_table ) {
				// TODO: This removes ANY submission from the form - high-velocity forms could have a submission between CheckView submission and here
				$submission_id = $wpdb->get_var(
					$wpdb->prepare(
						'SELECT id FROM ' . $submissions_table . ' WHERE element_id = %s ORDER BY id DESC LIMIT 1',
						$form_id
					)
				);

				if ( $submission_id ) {
					$wpdb->delete( $values_table, array( 'submission_id' => $submission_id ), array( '%d' ) );
					$wpdb->delete( $submissions_table, array( 'id' => $submission_id ), array( '%d' ) );
					Checkview_Admin_Logs::add( 'ip-logs', 'Deleted Elementor submission [' . $submission_id . '] from ' . $submissions_table . '.' );
				} else {
					Checkview_Admin_Logs::add( 'ip-logs', 'Could delete Elementor submission with submission ID: ' . wp_json_encode( $submission_id ) );
				}
			}

			complete_checkview_test( $checkview_test_id );
		}

		/**
		 * Redirects Elementor Form email notifications to the CheckView test inbox.
		 *
		 * Diverts the To address to the test inbox during a test run. When the
		 * flow's `disable_email_receipt` toggle is set, the real recipient is
		 * kept and the test inbox is appended; otherwise the recipient is
		 * replaced and CC/BCC stripped so test mail cannot reach real recipients.
		 *
		 * @param array                                           $fields wp_mail() arguments (email_to, email_to_cc, email_to_bcc, etc.).
		 * @param \ElementorPro\Modules\Forms\Classes\Form_Record $record Form record.
		 * @return array
		 */
		public function checkview_override_mail_fields( $fields, $record ) {
			$cv_test_id = get_checkview_test_id();

			if ( ! $cv_test_id || ! defined( 'TEST_EMAIL' ) ) {
				return $fields;
			}

			if ( 'true' === get_option( 'disable_email_receipt_' . $cv_test_id, false ) ) {
				// Keep the form's real recipient and also deliver to the test inbox.
				$fields['email_to'] = ( ! empty( $fields['email_to'] ) )
					? $fields['email_to'] . ', ' . TEST_EMAIL
					: TEST_EMAIL;
			} else {
				// Divert to the test inbox only; strip CC/BCC so test-run emails
				// cannot reach the site's real recipients.
				$fields['email_to']     = TEST_EMAIL;
				$fields['email_to_cc']  = '';
				$fields['email_to_bcc'] = '';
			}

			return $fields;
		}

		/**
		 * Restricts Elementor Form submit actions to the email action during test runs.
		 *
		 * Only applies when the flow's `disable_actions` toggle is set (matching
		 * the other form helpers), preventing third-party integrations (CRMs,
		 * webhooks, MailChimp, etc.) from firing on CheckView test submissions.
		 *
		 * @param array                                            $actions Registered submit action IDs.
		 * @param \ElementorPro\Modules\Forms\Classes\Form_Record  $record  Form record.
		 * @param \ElementorPro\Modules\Forms\Classes\Ajax_Handler $handler Ajax handler.
		 * @return array
		 */
		public function checkview_restrict_submit_actions( $actions, $record, $handler ) {
			$cv_test_id = get_checkview_test_id();

			if ( ! $cv_test_id || 'true' !== get_option( 'disable_actions_' . $cv_test_id, false ) ) {
				return $actions;
			}

			// array_values() so the returned list has sequential keys (Elementor
			// iterates it); array_intersect preserves the original keys.
			return array_values( array_intersect( $actions, array( 'email', 'save-to-database' ) ) );
		}

		/**
		 * Adds a `data-cv-file-types` attribute to Elementor upload fields.
		 *
		 * @param array                                                  $item       Field settings.
		 * @param int                                                    $item_index Field index.
		 * @param \ElementorPro\Modules\Forms\Widgets\Form               $form       Form widget instance.
		 * @return void
		 */
		public function checkview_add_upload_file_types( $item, $item_index, $form ) {
			if ( empty( $item['file_types'] ) ) {
				return;
			}

			$form->add_render_attribute( 'input' . $item_index, 'data-cv-file-types', esc_attr( $item['file_types'] ) );
		}

		/**
		 * Skips Elementor Pro captcha and honeypot validation types during test runs.
		 *
		 * @param array $skip_types Field types to skip validation for.
		 * @return array
		 */
		public function checkview_skip_captcha_types( $skip_types ) {
			if ( ! get_checkview_test_id() ) {
				return $skip_types;
			}

			return array_merge(
				$skip_types,
				array(
					'recaptcha',
					'recaptcha_v3',
					'honeypot',
					'hcaptcha',
					'turnstile',
				)
			);
		}
	}

	$checkview_elementor_helper = new Checkview_Elementor_Helper();
}
