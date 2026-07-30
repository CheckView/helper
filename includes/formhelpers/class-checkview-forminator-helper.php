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
		 * Entry id captured from the pre-save hook, used by the post-save hook.
		 *
		 * Forminator does NOT expose the entry id on the post-save action — see
		 * checkview_capture_entry_id() for why this relay exists.
		 *
		 * @var int
		 */
		protected $pending_entry_id = 0;

		/**
		 * Whether Forminator got as far as persisting an entry this request.
		 *
		 * Set from the pre-save hook, which fires after validation. Used only to
		 * tell "rejected before save" apart from "saved but our hook never ran"
		 * in the shutdown diagnostic.
		 *
		 * @var bool
		 */
		protected $submission_reached_save = false;

		/**
		 * Whether the post-save handler ran this request.
		 *
		 * @var bool
		 */
		protected $handler_ran = false;

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

			// Two hooks, because Forminator splits what we need across them.
			//
			// CAPTURE the entry id at `..._submit_before_set_fields`
			// (front-action.php:1771). That hook receives the entry object, and it
			// is the ONLY place the id is available to us — see
			// checkview_capture_entry_id().
			//
			// ACT after the entry is persisted. `..._after_save_entry` fires in
			// save_entry() at abstract-class-front-action.php:528, after
			// handle_form() returns (:499) — entry persisted, addon fields
			// attached, notifications sent — and immediately before
			// wp_send_json_*.
			//
			// `..._after_handle_submit` (:339) is the NON-AJAX equivalent, in
			// handle_submit() (:298). Forminator forms submit as an ordinary POST
			// unless AJAX is enabled, so hooking only the AJAX action would have
			// dropped those submissions entirely. Both pass ( $form_id, $response )
			// and both call handle_form(), so the capture covers both paths.
			//
			// Cloning at the pre-save hook is what the earlier revision did and is
			// wrong: it runs before addon fields attach (:1777) and before
			// set_fields() persists (~:1815), so it missed data and its delete
			// orphaned meta rows.
			//
			// The act tag is built as 'forminator_' . module_slug . $status_suffix
			// . '_after_save_entry' (:479-486), so the UNSUFFIXED name never fires
			// for drafts or abandoned entries. Spam is NOT excluded that way —
			// $status_suffix is computed before handle_form() while self::$is_spam
			// is set during it — so the entry status is checked explicitly below.
			add_action(
				'forminator_custom_form_submit_before_set_fields',
				array(
					$this,
					'checkview_capture_entry_id',
				),
				1,
				3
			);

			add_action(
				'forminator_form_after_save_entry',
				array(
					$this,
					'checkview_log_form_test_entry',
				),
				90,
				2
			);

			add_action(
				'forminator_form_after_handle_submit',
				array(
					$this,
					'checkview_log_form_test_entry',
				),
				90,
				2
			);

			// Diagnostic only — turns a silent failure into a stated reason.
			// `shutdown` fires even when a request die()s, because WordPress
			// registers shutdown_action_hook() through register_shutdown_function()
			// (wp-settings.php:146, load.php:1094), which covers the wp_ajax path
			// Forminator submits through.
			add_action(
				'shutdown',
				array(
					$this,
					'checkview_log_unfinished_submission',
				),
				0
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

			// CleanTalk Spam Protect.
			//
			// `forminator_spam_protection` => __return_false does NOT stop
			// CleanTalk: it registers with add_action (Integrations.php:44-62),
			// so on a filter its return value is discarded, and it also sits at
			// priority 1 on wp_ajax[_nopriv]_forminator_submit_form_custom-forms
			// ahead of Forminator's own save_entry at 10
			// (abstract-class-front-action.php:126-127), where it terminates the
			// request via doBlock() before Forminator ever handles it.
			//
			// The `$cleantalk_executed` sentinel does work here, contrary to an
			// earlier reading of this code. It is consulted one call deeper than
			// the integration class: apbct_base_call() short-circuits on it and
			// returns `array( 'ct_result' => new CleantalkResponse() )`
			// (inc/cleantalk-common.php:110-124). That default response has
			// `allow = 1` (CleantalkResponse.php:154), and checkSpam() only calls
			// doBlock() when `allow == 0` (Integrations.php:190). So the check
			// short-circuits to "allowed" on every Forminator hook at once, with
			// no coupling to CleanTalk's hook list or class names.
			//
			// Same two lines the GF helper already uses. CAVEAT, as noted there:
			// this is an INTERNAL global, not a public API, so re-verify on
			// CleanTalk updates. Harmless when CleanTalk is not installed.
			//
			// Complementary, not redundant: checkview_init_current_test() already
			// calls checkview_whitelist_api_ip() when CleanTalk is active
			// (admin/class-checkview-admin.php:336-338), but that is a REMOTE
			// allowlist call that early-returns without a configured service id
			// and API key. This sentinel is local and unconditional.
			global $cleantalk_executed;
			$cleantalk_executed = true;

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
		 * Records the entry id for the post-save handler to use.
		 *
		 * This relay exists because Forminator never hands the entry id to the
		 * action we actually want to work on. `..._after_save_entry` and
		 * `..._after_handle_submit` both pass only ( $form_id, $response ), and
		 * `$response['entry_id']` is populated ONLY for leads forms — it is
		 * assigned inside `if ( self::$is_leads )` at front-action.php:1809-1810
		 * and nowhere else. `..._submit_before_set_fields` (:1771) is the one hook
		 * that receives the entry object, but it fires too early to clone from.
		 *
		 * So: read the id here, use it there.
		 *
		 * Registered at priority 1 so the id is recorded before anything else on
		 * this hook can interfere. A missing or empty `entry_id` is left as 0 on
		 * purpose — that is the legitimate `prevent_store()` case, where
		 * front-action.php:1658-1660 skips `$entry->save()` entirely and no row
		 * exists to clone.
		 *
		 * @param object $entry       Forminator entry model.
		 * @param int    $form_id     Form ID (unused; the act hook supplies it).
		 * @param array  $form_fields Pre-persistence field data (unused — the act
		 *                            hook reads the persisted entry instead).
		 * @return void
		 */
		public function checkview_capture_entry_id( $entry, $form_id, $form_fields = array() ) {
			// Reaching this hook at all means validation passed and the entry was
			// persisted — see checkview_log_unfinished_submission().
			$this->submission_reached_save = true;

			if ( is_object( $entry ) && ! empty( $entry->entry_id ) ) {
				$this->pending_entry_id = (int) $entry->entry_id;
			}
		}

		/**
		 * Says why a Forminator submission never reached CheckView.
		 *
		 * Two very different failures look identical from the SaaS side — a green
		 * submit click, then submission-not-found and a hung assert_email_received:
		 *
		 *   1. Forminator rejected the submission during validation (honeypot,
		 *      spam, captcha, or an ordinary required-field error). Nothing was
		 *      saved, so there was never anything to clone.
		 *   2. Forminator saved the entry but neither post-save action reached
		 *      this helper — a hook name or priority problem on our side.
		 *
		 * Both are silent otherwise. The pre-save hook fires only after validation
		 * passes, so whether it ran separates the two cleanly.
		 *
		 * @return void
		 */
		public function checkview_log_unfinished_submission() {
			if ( $this->handler_ran ) {
				return;
			}

			// Both the AJAX and non-AJAX paths carry the same action marker
			// (abstract-class-front-action.php:150), so one check covers each.
			// Read-only diagnostic: Forminator has already verified its own nonce
			// by this point, and nothing here acts on the value.
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$action = isset( $_POST['action'] ) ? sanitize_text_field( wp_unslash( $_POST['action'] ) ) : '';

			if ( 0 !== strpos( $action, 'forminator_submit_form_' ) ) {
				return;
			}

			if ( $this->submission_reached_save ) {
				Checkview_Admin_Logs::add(
					'ip-logs',
					'Forminator saved an entry for this submission but neither forminator_form_after_save_entry nor forminator_form_after_handle_submit reached CheckView, so nothing was cloned and the test was not completed. This is a hook-side problem, NOT a spam or honeypot rejection.'
				);
				return;
			}

			Checkview_Admin_Logs::add(
				'ip-logs',
				'Forminator submission request ended before an entry was saved, so validation rejected it — honeypot, spam protection, captcha, or an ordinary required-field error. CheckView never saw a submission to clone. This is NOT a missing-hook problem.'
			);
		}

		/**
		 * Clones the persisted entry, removes it, and finishes the testing session.
		 *
		 * @param int   $form_id  Forminator form ID.
		 * @param array $response Submission response (the id is NOT in here — see below).
		 * @return void
		 */
		public function checkview_log_form_test_entry( $form_id, $response = array() ) {
			$form_id = (int) $form_id;

			// DO NOT read the entry id from $response.
			//
			// Forminator only puts it there for LEADS forms:
			// `self::$response_attrs['entry_id'] = $entry->entry_id;` is assigned
			// inside `if ( self::$is_leads )` at front-action.php:1809-1810 and
			// nowhere else in the file. For an ordinary custom form the key is
			// absent, so reading it yielded 0 — and this handler then took the
			// "nothing was stored" branch, cloned nothing, never deleted the test
			// entry, and completed the test green with no field data.
			$entry_id = (int) $this->pending_entry_id;
			$this->pending_entry_id = 0;
			$this->handler_ran      = true;

			// Idempotence: mirrors the guard in
			// Checkview_Fluent_Forms_Helper::checkview_clone_fluentform_entry().
			static $processed = array();
			$key              = $entry_id . '_' . $form_id;
			if ( isset( $processed[ $key ] ) ) {
				return;
			}
			$processed[ $key ] = true;

			// No entry was persisted. Happens when the form has "Store
			// submissions" disabled (prevent_store) and on leads forms, where
			// $response['entry_id'] stays 0. There is nothing to clone or delete,
			// but the notification WAS sent, so the test still has to be
			// completed — otherwise it hangs to timeout.
			if ( $entry_id <= 0 ) {
				Checkview_Admin_Logs::add( 'ip-logs', 'Forminator form [' . $form_id . '] stored no entry (submission storage disabled or leads form); completing the test without cloning.' );
				complete_checkview_test( get_checkview_test_id() );
				return;
			}

			$this->checkview_clone_entry( $entry_id, $form_id );
		}

		/**
		 * Clones the persisted Forminator entry, removes it, and finishes the
		 * testing session.
		 *
		 * @param int $entry_id Forminator entry ID.
		 * @param int $form_id  Forminator form ID.
		 * @return void
		 */
		public function checkview_clone_entry( $entry_id, $form_id ) {
			global $wpdb;

			if ( ! class_exists( 'Forminator_Form_Entry_Model' ) ) {
				Checkview_Admin_Logs::add( 'ip-logs', 'Forminator_Form_Entry_Model unavailable; skipping clone of entry [' . $entry_id . '].' );
				return;
			}

			$entry = new Forminator_Form_Entry_Model( $entry_id );

			if ( empty( $entry->entry_id ) ) {
				Checkview_Admin_Logs::add( 'ip-logs', 'Forminator entry [' . $entry_id . '] not found; nothing to clone.' );
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
				// Reachable for spam only: draft and abandoned entries fire
				// suffixed action tags this helper does not hook.
				//
				// Still remove the row — it is our own test submission and would
				// otherwise sit in the customer's entries as CheckView spam. But
				// do NOT complete the test: Forminator skips the notification for
				// a spam-flagged submission (front-action.php:1746), so completing
				// would report a found submission for a test whose email never
				// sent. Failing on the email assertion is the honest outcome.
				Forminator_Form_Entry_Model::delete_by_entry( $entry_id );

				Checkview_Admin_Logs::add( 'ip-logs', 'Forminator entry [' . $entry_id . '] has status [' . $entry->status . '] — no notification was sent, so the test is deliberately left to fail. Entry removed.' );
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

				// Raw submitted values for choice fields, NOT the labels.
				//
				// Everything in $meta_data has been through
				// replace_values_to_labels() (front-action.php:1800), so a select
				// stores its label. The SaaS compares against the DOM `value`
				// attribute captured in the test step, and Forminator keeps value
				// and label separate (library/fields/select.php:96-102), so sending
				// labels compares two different strings.
				//
				// It would not fail cleanly either: the comparison lowercases,
				// strips punctuation and falls back to substring containment, so
				// value="one"/label="One" passes while
				// value="opt_1"/label="Premium Plan" fails. Per-form, intermittent,
				// and harder to debug than a consistent break.
				//
				// Forminator preserves the raw values for us: it stashes a
				// slug => value map under `_forminator_choice_values` BEFORE the
				// label swap (front-action.php:1779-1797), covering select-,
				// radio- and checkbox-prefixed fields. Only <select> actually
				// matters to the SaaS today — radio and checkbox emit check steps
				// that carry no value — but the map covers all three, so prefer it
				// for any field it knows about.
				$raw_choice_values = array();
				if ( isset( $meta_data['_forminator_choice_values']['value'] ) && is_array( $meta_data['_forminator_choice_values']['value'] ) ) {
					$raw_choice_values = $meta_data['_forminator_choice_values']['value'];
				}

				foreach ( $meta_data as $meta_key => $meta ) {
					// Forminator's own bookkeeping rows, not submitted fields.
					if ( in_array( $meta_key, array( '_forminator_user_ip', '_forminator_choice_values' ), true ) ) {
						continue;
					}

					$field_value = is_array( $meta ) && array_key_exists( 'value', $meta ) ? $meta['value'] : '';

					if ( array_key_exists( $meta_key, $raw_choice_values ) ) {
						$field_value = $raw_choice_values[ $meta_key ];
					}

					// Flatten to a string. load_meta() already ran
					// maybe_unserialize() (class-form-entry-model.php:342), so
					// multi-value and composite fields arrive as arrays — but
					// FormSubmission.field_value is typed `string` on the SaaS side,
					// and handing wpdb an array or object is a PHP 8 fatal that
					// would abort before the delete and the completion below.
					//
					// Joined rather than re-serialized so the SaaS substring match
					// works on purpose instead of incidentally matching inside
					// serialized output. array_walk_recursive keeps nested
					// composites (name, address) safe from an
					// "Array to string conversion" notice.
					if ( is_array( $field_value ) ) {
						$parts = array();
						array_walk_recursive(
							$field_value,
							function ( $leaf ) use ( &$parts ) {
								if ( is_scalar( $leaf ) || null === $leaf ) {
									$parts[] = (string) $leaf;
								}
							}
						);
						$field_value = implode( ', ', $parts );
					} elseif ( ! is_scalar( $field_value ) && null !== $field_value ) {
						// Objects have no sensible joined form; serialize so the
						// insert cannot fatal.
						$field_value = maybe_serialize( $field_value );
					}

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
