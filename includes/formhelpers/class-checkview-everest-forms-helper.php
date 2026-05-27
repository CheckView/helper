<?php
/**
 * Checkview_Everest_Forms_Helper class
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

if ( ! class_exists( 'Checkview_Everest_Forms_Helper' ) ) {
	/**
	 * Adds support for Everest Forms.
	 *
	 * During CheckView tests, modifies Everest Forms hooks, overwrites the
	 * recipient email address, and handles test cleanup.
	 *
	 * @package Checkview
	 * @subpackage Checkview/includes/formhelpers
	 * @author Check View <support@checkview.io>
	 */
	class Checkview_Everest_Forms_Helper {
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

			// Bypass all captcha types (reCAPTCHA v2/v3, hCaptcha, Turnstile).
			add_filter(
				'everest_forms_recaptcha_disabled',
				'__return_true',
				10
			);

			// Remove reCAPTCHA rendering hook (EVF registers it at init:0, before our filter).
			remove_action( 'everest_forms_frontend_output', array( 'EVF_Shortcode_Form', 'recaptcha' ), 20 );

			// Nullify reCAPTCHA keys so field_display() returns early without rendering.
			add_filter( 'option_everest_forms_recaptcha_v2_site_key', '__return_empty_string' );
			add_filter( 'option_everest_forms_recaptcha_v2_secret_key', '__return_empty_string' );
			add_filter( 'option_everest_forms_recaptcha_v2_invisible_site_key', '__return_empty_string' );
			add_filter( 'option_everest_forms_recaptcha_v2_invisible_secret_key', '__return_empty_string' );
			add_filter( 'option_everest_forms_recaptcha_v3_site_key', '__return_empty_string' );
			add_filter( 'option_everest_forms_recaptcha_v3_secret_key', '__return_empty_string' );

			// Remove CleanTalk rendering hook (same init:0 timing issue).
			remove_action( 'everest_forms_frontend_output', array( 'EVF_Shortcode_Form', 'clean_talk' ), 15 );

			// Bypass nonce validation.
			add_filter(
				'evf_bypass_form_nonce_validation',
				'__return_true',
				10,
				2
			);

			// Bypass honeypot.
			add_filter(
				'everest_forms_process_honeypot',
				'__return_false',
				10
			);

			// Bypass Akismet.
			add_filter(
				'akismet_get_api_key',
				'__return_null',
				-10
			);

			// Bypass hCaptcha.
			add_filter(
				'hcap_activate',
				'__return_false',
				10
			);

			// Bypass Cloudflare Turnstile.
			add_filter(
				'cfturnstile_whitelisted',
				'__return_true',
				999
			);

			// Bypass EVF's built-in CleanTalk integration.
			add_filter(
				'option_everest_forms_recaptcha_cleantalk_access_key',
				'__return_empty_string',
				10
			);

			// Clone entry on form submission.
			add_action(
				'everest_forms_process_complete',
				array(
					$this,
					'checkview_clone_entry',
				),
				99,
				4
			);

			if ( defined( 'TEST_EMAIL' ) ) {
				// Redirect email to test address.
				add_filter(
					'everest_forms_entry_email_atts',
					array(
						$this,
						'checkview_inject_email',
					),
					99,
					4
				);

				// Suppress CC.
				add_filter(
					'everest_forms_email_cc',
					'__return_empty_string',
					99
				);

				// Suppress BCC.
				add_filter(
					'everest_forms_email_bcc',
					'__return_empty_string',
					99
				);

				// Belt-and-suspenders: strip CC/BCC from headers.
				add_filter(
					'everest_forms_email_headers',
					array(
						$this,
						'checkview_strip_cc_bcc_headers',
					),
					99,
					2
				);
			}
		}

		/**
		 * Injects testing email recipient.
		 *
		 * @param array $email    Email attributes array with 'address', 'subject', 'message' keys.
		 * @param array $fields   Processed form fields.
		 * @param array $entry    Raw entry data.
		 * @param array $form_data Form configuration data.
		 * @return array Modified email attributes.
		 */
		public function checkview_inject_email( $email, $fields, $entry, $form_data ) {
			$cv_test_id = get_checkview_test_id();
			if ( ! $cv_test_id || 'true' != get_option( 'disable_email_receipt_' . $cv_test_id, false ) ) {
				$email['address'] = array( TEST_EMAIL );
			} else {
				$email['address'][] = TEST_EMAIL;
			}

			Checkview_Admin_Logs::add( 'ip-logs', 'Submission recipient email address: ' . wp_json_encode( $email['address'] ) );
			return $email;
		}

		/**
		 * Strips CC and BCC from email headers.
		 *
		 * @param string $headers    Email headers string.
		 * @param object $emails_obj EVF_Emails instance.
		 * @return string Filtered headers string.
		 */
		public function checkview_strip_cc_bcc_headers( $headers, $emails_obj ) {
			$cv_test_id = get_checkview_test_id();
			if ( $cv_test_id && 'true' == get_option( 'disable_email_receipt_' . $cv_test_id, false ) ) {
				return $headers;
			}

			if ( ! is_string( $headers ) ) {
				return $headers;
			}

			$lines = explode( "\r\n", $headers );

			$filtered = array_filter(
				$lines,
				function ( $line ) {
					return stripos( $line, 'Cc:' ) !== 0 && stripos( $line, 'Bcc:' ) !== 0;
				}
			);

			$result = implode( "\r\n", array_values( $filtered ) );
			Checkview_Admin_Logs::add( 'ip-logs', 'Submission email headers: ' . wp_json_encode( $result ) );
			return $result;
		}

		/**
		 * Stores the test results and finishes the testing session.
		 *
		 * Clones form entry to CheckView tables, deletes the original EVF entry,
		 * and optionally unhooks external integrations.
		 *
		 * @param array $fields   Processed form fields.
		 * @param array $entry    Raw entry data.
		 * @param array $form_data Form configuration data.
		 * @param int   $entry_id  Entry ID from EVF entry_save.
		 * @return void
		 */
		public function checkview_clone_entry( $fields, $entry, $form_data, $entry_id ) {
			global $wpdb;

			$form_id = $form_data['id'];

			// Guard against failed entry save.
			if ( empty( $entry_id ) || ! is_numeric( $entry_id ) ) {
				Checkview_Admin_Logs::add( 'ip-logs', 'Cannot clone entry: entry_save returned invalid ID.' );
				return;
			}

			$checkview_test_id = get_checkview_test_id();

			// Unhook external integrations when disable_actions is enabled.
			if ( $checkview_test_id && 'true' == get_option( 'disable_actions_' . $checkview_test_id, false ) ) {
				remove_all_actions( 'everest_forms_process_complete_send_data_to_zapier_app' );
				remove_all_actions( "everest_forms_process_complete_{$form_id}" );
			}

			Checkview_Admin_Logs::add( 'ip-logs', 'Cloning submission entry [' . $entry_id . ']...' );

			if ( empty( $checkview_test_id ) ) {
				$checkview_test_id = $form_id . gmdate( 'Ymd' );
			}

			// Insert entry into CheckView table.
			$entry_data  = array(
				'form_id'      => $form_id,
				'status'       => 'publish',
				'source_url'   => isset( $_SERVER['HTTP_REFERER'] ) ? substr( sanitize_url( wp_unslash( $_SERVER['HTTP_REFERER'] ) ), 0, 200 ) : '',
				'date_created' => current_time( 'mysql' ),
				'date_updated' => current_time( 'mysql' ),
				'uid'          => $checkview_test_id,
				'form_type'    => 'EverestForms',
			);
			$entry_table = $wpdb->prefix . 'cv_entry';

			$result = $wpdb->insert( $entry_table, $entry_data );

			if ( ! $result ) {
				Checkview_Admin_Logs::add( 'ip-logs', 'Failed to clone submission entry data. wpdb->last_error=[' . $wpdb->last_error . ']' );
			} else {
				Checkview_Admin_Logs::add( 'ip-logs', 'Cloned submission entry data (inserted ' . (int) $result . ' rows into ' . $entry_table . ').' );
			}

			// Skip meta loop when parent insert failed: $wpdb->insert_id
			// is 0, meta rows would be orphaned with entry_id=0.
			// EVF entry delete and complete_checkview_test() below still run.
			if ( $result ) {
				// Clone entry meta, excluding non-data field types.
				$excluded_types  = array( 'html', 'title', 'captcha', 'divider', 'reset', 'recaptcha', 'hcaptcha', 'turnstile', 'private-note' );
				$entry_meta_table = $wpdb->prefix . 'cv_entry_meta';
				$count            = 0;

				foreach ( $fields as $field ) {
					if ( isset( $field['type'] ) && in_array( $field['type'], $excluded_types, true ) ) {
						continue;
					}

					if ( isset( $field['meta_key'], $field['value'] ) && '' !== $field['value'] ) {
						$entry_metadata = array(
							'uid'        => $checkview_test_id,
							'form_id'    => $form_id,
							'entry_id'   => $entry_id,
							'meta_key'   => checkview_truncate_meta_key( 'evf_' . sanitize_key( $field['meta_key'] ) ),
							'meta_value' => maybe_serialize( $field['value'] ),
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
					if ( count( $fields ) > 0 ) {
						Checkview_Admin_Logs::add( 'ip-logs', 'Failed to clone submission entry meta data. wpdb->last_error=[' . $wpdb->last_error . ']' );
					}
				}
			}

			// Delete original EVF entry.
			$wpdb->delete( $wpdb->prefix . 'evf_entries', array( 'entry_id' => $entry_id ) );
			$wpdb->delete( $wpdb->prefix . 'evf_entrymeta', array( 'entry_id' => $entry_id ) );

			complete_checkview_test( $checkview_test_id );
		}
	}

	$checkview_everest_forms_helper = new Checkview_Everest_Forms_Helper();
}
