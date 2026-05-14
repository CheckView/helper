<?php
/**
 * Checkview_Gforms_Helper class
 *
 * @since 1.0.0
 *
 * @package Checkview
 * @subpackage Checkview/includes/formhelpers
 */

if ( ! defined( 'WPINC' ) ) {
	die( 'Direct access not Allowed.' );
}

if ( ! class_exists( 'Checkview_Gforms_Helper' ) ) {
	/**
	 * Adds support for Gravity Forms.
	 *
	 * During CheckView tests, modifies Gravity Forms hooks, overwrites the
	 * recipient email address, and handles test cleanup.
	 *
	 * @package Checkview
	 * @subpackage Checkview/includes/formhelpers
	 * @author Check View <support@checkview.io>
	 */
	class Checkview_Gforms_Helper {
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
				// Change email address to our test email.
				add_filter(
					'gform_pre_send_email',
					array(
						$this,
						'checkview_inject_email',
					),
					99,
					1
				);
				// Divert and suppress postmark.
				add_filter(
					'gform_postmark_email',
					array(
						$this,
						'checkview_modify_postmark_email',
					),
					99,
					1
				);

				// Divert and suppress postmark.
				add_filter(
					'gform_sendgrid_email',
					array(
						$this,
						'checkview_modify_sendgrid_email',
					),
					99,
					1
				);

			}
			// Disable addons found in forms.
			add_filter(
				'gform_addon_pre_process_feeds',
				array(
					$this,
					'checkview_disable_addons_feed',
				),
				999,
				3
			);
			// Disable PDF addon if added to form.
			add_filter(
				'gfpdf_pdf_config',
				array(
					$this,
					'checkview_disable_pdf_addon',
				),
				999,
				2
			);

			// Disable Zero Spam addon for form testing.
			add_filter(
				'gf_zero_spam_check_key_field',
				array(
					$this,
					'checkview_disable_zero_spam_addon',
				),
				99,
				4
			);

			add_action(
				'gform_after_submission',
				array(
					$this,
					'checkview_clone_entry',
				),
				99,
				2
			);

			add_filter(
				'cfturnstile_whitelisted',
				'__return_true',
				999
			);

			// Defensive backstop against the GF 2.7+ honeypot abort path.
			// Normally Playwright posts the JS-injected `version_hash` back
			// fine, so honeypot doesn't trip on most sites — but if anything
			// interferes with that round-trip (e.g. a third-party plugin
			// that wraps GF's submit button and bypasses GF's pre-submit JS,
			// or aggressive page caching that serves a stale `version_hash`),
			// `GF_Honeypot_Handler::handle_abort_submission` short-circuits
			// `GFFormDisplay::process_form()` BEFORE `handle_submission()`,
			// returning a success-style confirmation with no saved entry and
			// no `gform_after_submission` — which surfaces as
			// `submission-not-found` because `checkview_clone_entry` never
			// runs. Forcing the abort filter false guarantees the entry is
			// saved regardless of whether the honeypot heuristic was happy.
			//
			// Hook at the submission/validation path (not on
			// `gform_form_post_get_meta`) so the bypass does not pollute
			// the persistent `gravityforms_meta` object cache on
			// Redis/Memcached-backed hosts.
			// PHP_INT_MAX so we beat any third-party spam plugin that
			// hooks the same filter at a high priority (Akismet for GF,
			// CleanTalk, Zero Spam, GravityWiz Anti-Spam, etc.).
			add_filter(
				'gform_abort_submission_with_confirmation',
				'__return_false',
				PHP_INT_MAX
			);
			// Defensive: prevents the cloned entry from being flagged
			// `status='spam'` if any other GF spam plugin slips through.
			add_filter(
				'gform_entry_is_spam',
				'__return_false',
				PHP_INT_MAX
			);

			add_filter(
				'gform_pre_render',
				array( $this, 'maybe_hide_recaptcha' )
			);

			// Note: when changing choice values, we also need to use the gform_pre_validation so that the new values are available when validating the field.
			add_filter(
				'gform_pre_validation',
				array( $this, 'maybe_hide_recaptcha' )
			);

			// Note: when changing choice values, we also need to use the gform_admin_pre_render so that the right values are displayed when editing the entry.
			add_filter(
				'gform_admin_pre_render',
				array( $this, 'maybe_hide_recaptcha' )
			);

			// Note: this will allow for the labels to be used during the submission process in case values are enabled.
			add_filter(
				'gform_pre_submission_filter',
				array( $this, 'maybe_hide_recaptcha' )
			);
			// Bypass hCaptcha.
			add_filter(
				'hcap_activate',
				'__return_false'
			);
			// Bypass Akismet.
			add_filter(
				'akismet_get_api_key',
				'__return_null',
				-10
			);

			// Bypass CAPTCHA/anti-bot validation failures. The GF reCAPTCHA
			// Add-On v2.x validates via gform_validation, not via a captcha-type
			// field, so maybe_hide_recaptcha() cannot catch it. This runs at
			// PHP_INT_MAX (guaranteed last) and clears any remaining failures.
			add_filter(
				'gform_validation',
				array( $this, 'checkview_bypass_captcha_validation' ),
				PHP_INT_MAX
			);
		}
		/**
		 * Unsets Captchas from the form.
		 *
		 * @param array $form Form object.
		 * @return form
		 */
		public function maybe_hide_recaptcha( $form ) {
			$fields = $form['fields'];

			$spam_field_types = array( 'captcha', 'hcaptcha', 'turnstile', 'honeypot' );

			foreach ( $form['fields'] as $key => $field ) {
				if ( in_array( $field->type, $spam_field_types, true ) ) {
					Checkview_Admin_Logs::add( 'ip-logs', 'Unset spam-protection field type [' . $field->type . '].' );

					unset( $fields[ $key ] );
				}
			}

			$form['fields'] = $fields;

			return $form;
		}

		/**
		 * Bypasses CAPTCHA/anti-bot validation failures during CheckView tests.
		 *
		 * The GF reCAPTCHA Add-On v2.x validates via gform_validation, not via
		 * a captcha-type field. maybe_hide_recaptcha() only removes fields, so
		 * it cannot catch this. This filter runs at PHP_INT_MAX priority
		 * (guaranteed last, after all other validation hooks) and clears failures.
		 *
		 * Only loaded when is_bot() is true (constructor gated by
		 * checkview_init_current_test which requires is_bot()).
		 *
		 * @param array $validation_result GF validation result.
		 * @return array
		 */
		public function checkview_bypass_captcha_validation( $validation_result ) {
			if ( ! $validation_result['is_valid'] ) {
				Checkview_Admin_Logs::add( 'ip-logs',
					'Form validation failed during CheckView test. Clearing validation failures.' );

				$fields = $validation_result['form']['fields'] ?? array();
				foreach ( $fields as &$field ) {
					if ( $field->failed_validation ) {
						Checkview_Admin_Logs::add( 'ip-logs',
							'Cleared validation failure for field [' . $field->id . '] type [' . $field->type . '].' );
						$field->failed_validation  = false;
						$field->validation_message = '';
					}
				}

				$validation_result['is_valid'] = true;
			}

			return $validation_result;
		}

		/**
		 * Clones the GF submission to cv_entry tables, schedules deferred deletion
		 * of the source GF entry, and finishes the testing session.
		 *
		 * Deletion is deferred ~15 min to allow GF async feed processing
		 * (Mailchimp, Webhooks, etc.) to load the entry and fire third-party
		 * integrations. See checkview_gf_should_defer_delete() for the
		 * emergency-rollback escape hatch.
		 *
		 * @param array  $entry Form entry data.
		 * @param object $form  Form object.
		 * @return void
		 */
		public function checkview_clone_entry( $entry, $form ) {
			$form_id = rgar( $form, 'id' );
			$checkview_test_id = get_checkview_test_id();

			if ( empty( $checkview_test_id ) ) {
				$checkview_test_id = $form_id . gmdate( 'Ymd' );
			}

			self::checkview_clone_gf_entry( $entry['id'], $form_id, $checkview_test_id );

			if ( isset( $entry['id'] ) ) {
				$entry_id = (int) $entry['id'];
				if ( checkview_gf_should_defer_delete() ) {
					// Defer entry deletion so GF async feed processing
					// (GF_Background_Process) has time to load the entry and fire
					// third-party integrations. Without this delay,
					// GF_Feed_Processor::task() aborts with "entry not found".
					wp_schedule_single_event(
						time() + 15 * MINUTE_IN_SECONDS,
						'checkview_gf_deferred_entry_delete',
						array( $entry_id )
					);
				} else {
					// Emergency-rollback escape hatch — legacy synchronous deletion.
					GFAPI::delete_entry( $entry_id );
				}
			}

			complete_checkview_test( $checkview_test_id );
		}
		/**
		 * Modifies the submission recipient email addreesss.
		 *
		 * @param array $email Address.
		 * @return array Email.
		 */
		public function checkview_inject_email( $email ) {
			$cv_test_id = get_checkview_test_id();
			if ( ! $cv_test_id || 'true' != get_option( 'disable_email_receipt_' . $cv_test_id, false ) ) {
				$email['to'] = TEST_EMAIL;
				$headers     = $email['headers'];
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
				$email['headers'] = $filtered_headers;
			} elseif ( is_array( $email['to'] ) ) {
				$email['to'][] = TEST_EMAIL;
			} else {
				$email['to'] .= ', ' . TEST_EMAIL;
			}

			Checkview_Admin_Logs::add( 'ip-logs', 'Submission recipient email address: ' . wp_json_encode( $email['to'] ?? null ) );
			Checkview_Admin_Logs::add( 'ip-logs', 'Submission sender email address: ' . wp_json_encode( $email['headers']['From'] ?? null ) );
			Checkview_Admin_Logs::add( 'ip-logs', 'Submission email headers: ' . wp_json_encode( $email['headers'] ?? null ) );
			return $email;
		}

		/**
		 * Modifies Sendgrid email.
		 *
		 * @param array $email modifies sendgrid emails.
		 * @return array
		 */
		public function checkview_modify_sendgrid_email( array $email ): array {
			$cv_test_id = get_checkview_test_id();
			if ( ! $cv_test_id || 'true' != get_option( 'disable_email_receipt_' . $cv_test_id, false ) ) {
				$email['personalizations'][0]['to']  = TEST_EMAIL;
				$email['personalizations'][0]['cc']  = '';
				$email['personalizations'][0]['bcc'] = '';
			} else {
				$email['personalizations'][0]['to'][] = TEST_EMAIL;
			}

			Checkview_Admin_Logs::add( 'ip-logs', 'Submission recipient email address: ' . wp_json_encode( $email['to'] ?? null ) );
			Checkview_Admin_Logs::add( 'ip-logs', 'Submission sender email address: ' . wp_json_encode( $email['headers']['From'] ?? null ) );
			Checkview_Admin_Logs::add( 'ip-logs', 'Submission email headers: ' . wp_json_encode( $email['headers'] ?? null ) );
			return $email;
		}
		/**
		 * Modifies PM emails.
		 *
		 * @param array $email Postmark email.
		 * @return array
		 */
		public function checkview_modify_postmark_email( array $email ): array {
			$cv_test_id = get_checkview_test_id();
			if ( ! $cv_test_id || 'true' != get_option( 'disable_email_receipt_' . $cv_test_id, false ) ) {
				$email['To'] = TEST_EMAIL;
				$headers     = $email['Headers'];
				if ( ! is_array( $headers ) ) {
					$headers = explode( "\r\n", $headers );
				}
				$filtered_headers = array_filter(
					$headers,
					function ( $header ) {
						// Exclude headers that start with 'bcc:' or 'cc:'.
						return stripos( $header, 'Bcc:' ) !== 0 && stripos( $header, 'CC:' ) !== 0;
					}
				);
				$email['Headers'] = $filtered_headers;
				$email['Bcc']     = '';
				$email['CC']      = '';
			} elseif ( is_array( $email['To'] ) ) {
				$email['To'][] = TEST_EMAIL;
			} else {
				$email['To'] .= ', ' . TEST_EMAIL;
			}

			Checkview_Admin_Logs::add( 'ip-logs', 'Submission recipient email address: ' . wp_json_encode( $email['to'] ?? null ) );
			Checkview_Admin_Logs::add( 'ip-logs', 'Submission sender email address: ' . wp_json_encode( $email['headers']['From'] ?? null ) );
			Checkview_Admin_Logs::add( 'ip-logs', 'Submission email headers: ' . wp_json_encode( $email['headers'] ?? null ) );
			return $email;
		}
		/**
		 * Clones the form submission to CheckView tables.
		 *
		 * @param int $entry_id Entry ID of the form.
		 * @param int $form_id Form submitted ID.
		 * @param int $uid User submitted ID.
		 * @return void
		 */
		public function checkview_clone_gf_entry( $entry_id, $form_id, $uid ) {
			global $wpdb;

			Checkview_Admin_Logs::add( 'ip-logs', 'Cloning submission entry [' . $entry_id . '] with unique ID [' . $uid . ']...' );

			$tablename = $wpdb->prefix . 'gf_entry_meta';
			$rows = $wpdb->get_results( $wpdb->prepare( 'Select * from ' . $tablename . ' where entry_id=%d and form_id=%d order by id ASC', $entry_id, $form_id ) );
			$entry_meta_table = $wpdb->prefix . 'cv_entry_meta';
			$count = 0;

			foreach ( $rows as $row ) {
				$data  = array(
					'uid' => $uid,
					'form_id' => $row->form_id,
					'entry_id' => $row->entry_id,
					'meta_key' => $row->meta_key,
					'meta_value' => $row->meta_value,
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

			$tablename = $wpdb->prefix . 'gf_entry';
			$row = $wpdb->get_row( $wpdb->prepare( 'Select * from ' . $tablename . ' where id=%d and form_id=%d LIMIT 1', $entry_id, $form_id ), ARRAY_A );

			unset( $row['id'] );
			unset( $row['source_id'] );

			$entry_table = $wpdb->prefix . 'cv_entry';
			$row['uid'] = $uid;
			$row['form_type'] = 'GravityForms';
			$result = $wpdb->insert( $entry_table, $row );

			if ( ! $result ) {
				Checkview_Admin_Logs::add( 'ip-logs', 'Failed to clone submission entry data.' );
			} else {
				Checkview_Admin_Logs::add( 'ip-logs', 'Cloned submission entry data (inserted ' . (int) $result . ' rows into ' . $entry_table . ').' );
			}
		}

		/**
		 * Returns false.
		 *
		 * @param int    $form_id Form's ID.
		 * @param int    $should_check_key_field Check for filed.
		 * @param object $form Forms object.
		 * @param array  $entry Entry details.
		 * @return bool
		 */
		public function checkview_disable_zero_spam_addon( $form_id, $should_check_key_field, $form, $entry ) {
			return false;
		}

		/**
		 * Disables Gravity Forms PDF addons.
		 *
		 * @param array $settings Settings for form helper.
		 * @param int   $form_id ID of the form submitted.
		 * @return array
		 */
		public function checkview_disable_pdf_addon( $settings, $form_id ) {

			$settings['notification']       = '';
			$settings['conditional']        = 1;
			$settings['enable_conditional'] = 'Yes';
			$settings['conditionalLogic']   = array(
				'actionType' => 'hide',
				'logicType'  => 'all',
				'rules'      =>
					array(
						array(
							'fieldId'  => 1,
							'operator' => 'isnot',
							'value'    => esc_html__( 'Check Form Helper', 'checkview' ),
						),
					),
			);

			return $settings;
		}

		/**
		 * Disables conditional logic for feeds.
		 *
		 * @param array  $feeds Form feeds.
		 * @param array  $entry Form entry data.
		 * @param object $form Form object.
		 * @return array
		 */
		public function checkview_disable_addons_feed( $feeds, $entry, $form ) {
			$cv_test_id = get_checkview_test_id();
			if ( $cv_test_id && 'true' == get_option( 'disable_actions_' . $cv_test_id, false ) ) {
				if ( is_array( $feeds ) ) {
					foreach ( $feeds as $feed ) {
						if ( ! is_array( $feed ) ) {
							continue;
						}
						if ( isset( $feed['addon_slug'] ) ) {
							$slug = $feed['addon_slug'];
						} elseif ( isset( $feed['id'] ) ) {
							$slug = 'feed_id_' . $feed['id'];
						} else {
							$slug = 'unknown';
						}
						Checkview_Admin_Logs::add(
							'ip-logs',
							'Disabled GF addon feed [' . $slug . '] for CheckView test.'
						);
					}
				}
				return array();
			}
			return $feeds;
		}
	}
	$checkview_gforms_helper = new Checkview_Gforms_Helper();
}
