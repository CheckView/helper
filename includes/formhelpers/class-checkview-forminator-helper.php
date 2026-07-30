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

			// Remove Forminator's captcha field for the duration of the test.
			//
			// `forminator_disabled_fields` takes a list of field TYPES and is
			// applied inside the form model's _load()
			// (class-custom-form-model.php:328 via class-base-form-model.php:849),
			// so the field is gone before render, validation and mail ever see
			// it. Forminator uses this filter natively to strip stripe/paypal
			// when payments are disabled, so this is its intended use. One hook
			// covers all three providers (reCAPTCHA v2/v3, hCaptcha, Turnstile).
			//
			// This replaces `forminator_invalid_captcha_message` => __return_null,
			// which never bypassed anything: it only blanked the message, while
			// `is_valid_entry()` does `empty( $this->validation_message )` on an
			// array that still has the captcha key, so validation still failed —
			// just without saying why.
			add_filter(
				'forminator_disabled_fields',
				array(
					$this,
					'checkview_disable_captcha_field_type',
				),
				PHP_INT_MAX,
				1
			);

			// Second layer, deliberately kept: strip captcha wrappers at render
			// too. If `forminator_disabled_fields` is ever renamed upstream this
			// degrades to a working render strip instead of silently doing
			// nothing. Same belt-and-braces shape as the GF helper's explicit
			// reCAPTCHA unhook plus its marker fallback.
			add_filter(
				'forminator_cform_render_fields',
				array(
					$this,
					'remove_recaptcha_field_from_list',
				),
				PHP_INT_MAX,
				2
			);

			// Bypass hCaptcha. Forminator's own hCaptcha is the `captcha` field
			// type handled above, but the standalone "hCaptcha for WordPress"
			// plugin ships a separate Forminator integration that works outside
			// that field type. Matches the GF, WPForms, CF7, Fluent, Everest and
			// Elementor helpers.
			add_filter( 'hcap_activate', '__return_false' );

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
			$cv_test_id = get_checkview_test_id();
			if ( ! $cv_test_id || 'true' != get_option( 'disable_email_receipt_' . $cv_test_id, false ) ) {
				$email   = array();
				$email[] = TEST_EMAIL;
			} elseif ( is_array( $email ) ) {
				$email[] = TEST_EMAIL;
			} else {
				$email .= ', ' . TEST_EMAIL;
			}

			Checkview_Admin_Logs::add( 'ip-logs', 'Submission recipient email address: ' . wp_json_encode( $email ) );
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

			$array_values = array_values( $filtered_headers );
			Checkview_Admin_Logs::add( 'ip-logs', 'Submission email headers: ' . wp_json_encode( $array_values ) );
			return $array_values;
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
		public function checkview_log_form_test_entry( $entry, $form_id, $form_fields = array() ) {
			if ( ! is_object( $entry ) || empty( $entry->entry_id ) ) {
				Checkview_Admin_Logs::add( 'ip-logs', 'Forminator submission hook fired without a usable entry; nothing to clone.' );
				return;
			}

			$entry_id = (int) $entry->entry_id;
			$form_id  = ! empty( $entry->form_id ) ? (int) $entry->form_id : (int) $form_id;

			// Idempotence: this must not run twice for the same entry in one
			// request. Mirrors the guard in
			// Checkview_Fluent_Forms_Helper::checkview_clone_fluentform_entry().
			static $scheduled = array();
			$key              = $entry_id . '_' . $form_id;
			if ( isset( $scheduled[ $key ] ) ) {
				return;
			}
			$scheduled[ $key ] = true;

			// Defer the clone to `shutdown` rather than doing it here.
			//
			// This action fires at front-action.php:1771, which is BEFORE
			// `attach_addons_add_entry_fields()` (1777) and before
			// `$entry->set_fields()` persists the entry (~1818). Cloning the
			// in-flight `$form_fields` array here would therefore miss any
			// addon-contributed fields, and deleting the entry here would leave
			// `set_fields()` writing meta rows for a row that no longer exists —
			// it gates only on the in-memory `! $this->entry_id`, so the insert
			// would still run. There is no post-save action to hook instead:
			// `set_fields()` fires none, and no `forminator_*after*save*entry`
			// action exists anywhere in Forminator's library.
			//
			// By `shutdown` the entry is fully persisted, so we read it back
			// through the model and get exactly what Forminator stored. Email
			// ordering is unaffected: `process_mail` runs at
			// front-action.php:1748, before both this hook and shutdown.
			//
			// `shutdown` is already used this way in
			// Checkview_Woo_Automated_Testing::checkview_complete_test_deferred().
			add_action(
				'shutdown',
				function () use ( $entry_id, $form_id ) {
					$this->checkview_clone_entry_deferred( $entry_id, $form_id );
				}
			);
		}

		/**
		 * Clones the persisted Forminator entry, removes it, and finishes the
		 * testing session. Runs on `shutdown` — see
		 * checkview_log_form_test_entry() for why.
		 *
		 * @param int $entry_id Forminator entry ID.
		 * @param int $form_id  Forminator form ID.
		 * @return void
		 */
		public function checkview_clone_entry_deferred( $entry_id, $form_id ) {
			global $wpdb;

			if ( ! class_exists( 'Forminator_Form_Entry_Model' ) ) {
				Checkview_Admin_Logs::add( 'ip-logs', 'Forminator_Form_Entry_Model unavailable at shutdown; skipping clone of entry [' . $entry_id . '].' );
				return;
			}

			$entry = new Forminator_Form_Entry_Model( $entry_id );

			if ( empty( $entry->entry_id ) ) {
				Checkview_Admin_Logs::add( 'ip-logs', 'Forminator entry [' . $entry_id . '] not found at shutdown; nothing to clone.' );
				return;
			}

			// Only real submissions. `status` is a public property documented as
			// 'active'|'spam'|'draft'|'abandoned' and is populated on both the
			// cache and DB paths of Forminator_Form_Entry_Model::get()
			// (class-form-entry-model.php:66-83, 197-225).
			//
			// Without this we would clone and complete on a saved draft, an
			// abandoned entry, or a spam-flagged submission — and delete the
			// entry the visitor is still working on. Note Forminator itself
			// guards its own mail send the same way
			// (`! $is_leads && ! $is_draft && ! $is_spam`, front-action.php:1747),
			// so on a spam-flagged entry no email was sent and completing the
			// test would strand assert_email_received with no explanation.
			if ( 'active' !== $entry->status ) {
				Checkview_Admin_Logs::add( 'ip-logs', 'Skipping Forminator entry [' . $entry_id . '] with status [' . $entry->status . ']; not a completed submission.' );
				return;
			}

			$checkview_test_id = get_checkview_test_id();

			Checkview_Admin_Logs::add( 'ip-logs', 'Cloning submission entry [' . $entry_id . ']...' );

			if ( empty( $checkview_test_id ) ) {
				$checkview_test_id = $form_id . gmdate( 'Ymd' );
			}

			// Insert entry.
			$entry_data = array(
				'form_id'      => $form_id,
				'status'       => 'publish',
				'source_url'   => isset( $_SERVER['HTTP_REFERER'] ) ? substr( sanitize_url( wp_unslash( $_SERVER['HTTP_REFERER'] ) ), 0, 200 ) : '',
				'date_created' => current_time( 'mysql' ),
				'date_updated' => current_time( 'mysql' ),
				'uid'          => $checkview_test_id,
				'form_type'    => 'Forminator',
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
			// complete_checkview_test() and Forminator entry delete below still run.
			if ( $result ) {
				$entry_meta_table = $wpdb->prefix . 'cv_entry_meta';
				$count            = 0;

				// `meta_data` is keyed by meta_key with the shape
				// array( 'id' => meta_id, 'value' => mixed ) and its values are
				// already run through maybe_unserialize() by
				// Forminator_Form_Entry_Model::load_meta(). Reading it here — rather
				// than the pre-persistence array the submit hook hands us — is what
				// makes addon-contributed fields (and, later, payment data) appear.
				$meta_data = is_array( $entry->meta_data ) ? $entry->meta_data : array();

				foreach ( $meta_data as $meta_key => $meta ) {
					if ( '_forminator_user_ip' === $meta_key ) {
						continue;
					}

					// Composite and multi-value fields (name, address, checkbox,
					// select) unserialize to arrays. cv_entry_meta.meta_value is
					// longtext, so re-serialize rather than handing wpdb an array.
					$field_value = is_array( $meta ) && array_key_exists( 'value', $meta ) ? $meta['value'] : '';
					$field_value = is_array( $field_value ) ? maybe_serialize( $field_value ) : $field_value;

					$entry_metadata = array(
						'uid'        => $checkview_test_id,
						'form_id'    => $form_id,
						'entry_id'   => $entry_id,
						'meta_key'   => checkview_truncate_meta_key( $meta_key ),
						'meta_value' => $field_value,
					);

					if ( $wpdb->insert( $entry_meta_table, $entry_metadata ) ) {
						++$count;
					}
				}

				if ( $count > 0 ) {
					Checkview_Admin_Logs::add( 'ip-logs', 'Cloned submission entry meta data (inserted ' . $count . ' rows into ' . $entry_meta_table . ').' );
				} elseif ( ! empty( $meta_data ) ) {
					Checkview_Admin_Logs::add( 'ip-logs', 'Failed to clone submission entry meta data. wpdb->last_error=[' . $wpdb->last_error . ']' );
				}
			}

			// Delete BEFORE completing. Every other helper does it in this order,
			// and completing first tears down the session (and the append-mode
			// flags) while cleanup could still fail. Safe to delete here: by
			// shutdown, set_fields() has already written its meta, so nothing
			// re-inserts rows against a deleted entry.
			Forminator_Form_Entry_Model::delete_by_entry( $entry_id );

			complete_checkview_test( $checkview_test_id );
		}

		/**
		 * Adds Forminator's `captcha` field type to the list of field types
		 * stripped from the form model during a CheckView test.
		 *
		 * @param array $types Disabled field types.
		 * @return array
		 */
		public function checkview_disable_captcha_field_type( $types ) {
			$types = is_array( $types ) ? $types : array();

			if ( ! in_array( 'captcha', $types, true ) ) {
				$types[] = 'captcha';
			}

			return $types;
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
			$cv_test_id = get_checkview_test_id();
			if ( $cv_test_id && 'true' == get_option( 'disable_actions_' . $cv_test_id, false ) ) {
				return false;
			}
			return $enabled;
		}
	}

	$checkview_forminator_helper = new Checkview_Forminator_Helper();
}
