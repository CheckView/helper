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

				// Force notifications to dispatch synchronously during a test.
				// GF 2.10+ ships "Background Notifications" (queued via the
				// GF_Notifications_Processor admin-ajax task). That separate
				// request doesn't carry the CheckView test context, so
				// TEST_EMAIL is never defined for it and gform_pre_send_email
				// above never fires — the notification goes to the original
				// recipient. Forcing sync during the test keeps the dispatch
				// inside the form-submission request where our filters are
				// already registered. Customers' non-test traffic is
				// unaffected.
				add_filter(
					'gform_is_asynchronous_notifications_enabled',
					'__return_false',
					999
				);

				// Explicitly unhook the GF reCAPTCHA Add-On's callbacks during
				// a test. Without this, the add-on's validate_submission()
				// runs and attaches a failure to a non-captcha field
				// (maybe_hide_recaptcha cannot catch it because the add-on
				// doesn't use a captcha-type field). The
				// checkview_bypass_captcha_validation marker fallback would
				// still catch it, but explicit unhook is more precise and
				// avoids depending on the English-only "recaptcha" substring.
				// Hooked on gform_pre_validation at priority 1 so the
				// unhook runs before gform_validation fires regardless of
				// init ordering between this plugin and the add-on.
				add_filter(
					'gform_pre_validation',
					array( $this, 'unhook_gf_recaptcha_addon' ),
					1
				);

				// CleanTalk Spam Protect bypass.
				//
				// CleanTalk's GF integration registers a callback on
				// `gform_entry_is_spam`. When the verdict is spam it
				// ALSO calls `GFFormsModel::delete_lead( $entry['id'] )`
				// inline — bypassing every WP filter we hook, so the
				// existing `gform_entry_is_spam` → `__return_false` at
				// PHP_INT_MAX cannot save the entry from being deleted.
				//
				// CleanTalk itself uses the `$cleantalk_executed` global
				// as an "already handled, don't re-check" sentinel after
				// successful base API calls. Setting it truthy here causes
				// `apbct_form__gravityForms__isSkippedRequest()` to return
				// true, which causes `testSpam()` to return the unmodified
				// `$is_spam` without calling the API or `delete_lead()`.
				//
				// Sources (cleantalk-spam-protect plugin, wp.org trunk;
				// line numbers verified at the time of writing — re-verify
				// on plugin updates):
				//   - inc/cleantalk-public-integrations.php L2270-2303
				//     (`apbct_form__gravityForms__isSkippedRequest()`
				//     reads `$cleantalk_executed`)
				//   - inc/cleantalk-public-integrations.php L2128-2134
				//     (`apbct_form__gravityForms__testSpam()` calls
				//     `isSkippedRequest()` first, returns `$is_spam`
				//     unmodified before the remote API call or
				//     `GFFormsModel::delete_lead()`)
				//   - inc/cleantalk-public-validate.php L472
				//     (CleanTalk sets the global itself after successful
				//     base API calls — pattern is canonical, not
				//     internal-only)
				//
				// CAVEAT: this is an INTERNAL global, not a documented
				// public API. CleanTalk could rename/remove it in any
				// release. Harmless if CleanTalk isn't installed (just
				// creates an unused global). Re-verify on plugin updates
				// — if the sentinel goes away, fall back to
				// `remove_filter('gform_entry_is_spam', ...)` on the
				// add-on's callback.
				global $cleantalk_executed;
				$cleantalk_executed = true;
				Checkview_Admin_Logs::add(
					'ip-logs',
					'Set $cleantalk_executed = true to bypass CleanTalk Spam Protect during test (if installed).'
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

			// Same allowlist as is_anti_bot_failure() so customers extending
			// `checkview_anti_bot_field_types` get consistent treatment: a
			// custom type is removed pre-validation AND its failures are
			// cleared post-validation.
			$spam_field_types = (array) apply_filters(
				'checkview_anti_bot_field_types',
				array( 'captcha', 'hcaptcha', 'turnstile', 'honeypot' )
			);

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
		 * Scoped to anti-bot fields only: clearing every failure regardless of
		 * cause was masking real test-flow gaps (e.g. a customer adds a required
		 * dropdown but the saved test flow has no step to fill it — the test
		 * would silently "pass" instead of failing honestly). Non-anti-bot
		 * failures (required fields, format errors, etc.) are kept so they
		 * surface as real test failures.
		 *
		 * Only loaded when is_bot() is true (constructor gated by
		 * checkview_init_current_test which requires is_bot()).
		 *
		 * @param array $validation_result GF validation result.
		 * @return array
		 */
		public function checkview_bypass_captcha_validation( $validation_result ) {
			if ( $validation_result['is_valid'] ) {
				return $validation_result;
			}

			Checkview_Admin_Logs::add( 'ip-logs',
				'Form validation failed during CheckView test. Evaluating which failures to clear.' );

			$fields = $validation_result['form']['fields'] ?? array();
			$remaining_failures = 0;

			foreach ( $fields as &$field ) {
				if ( empty( $field->failed_validation ) ) {
					continue;
				}

				if ( self::is_anti_bot_failure( $field ) ) {
					Checkview_Admin_Logs::add( 'ip-logs',
						'Cleared anti-bot validation failure for field [' . $field->id . '] type [' . $field->type . '].' );
					$field->failed_validation  = false;
					$field->validation_message = '';
					continue;
				}

				$remaining_failures++;
				Checkview_Admin_Logs::add( 'ip-logs',
					'Kept validation failure for non-anti-bot field [' . $field->id . '] type [' . $field->type . '] message [' . substr( (string) ( $field->validation_message ?? '' ), 0, 200 ) . '].' );
			}
			unset( $field );

			// Only mark the form valid if every remaining failure was anti-bot related.
			// Otherwise let GF surface the genuine failure so the test fails honestly.
			if ( 0 === $remaining_failures ) {
				$validation_result['is_valid'] = true;
			} else {
				Checkview_Admin_Logs::add( 'ip-logs',
					'Form still has [' . $remaining_failures . '] non-anti-bot validation failure(s); not marking as valid.' );
			}

			return $validation_result;
		}

		/**
		 * Determines whether a field's validation failure is anti-bot/captcha related.
		 *
		 * Matches by field type (`captcha`, `hcaptcha`, `turnstile`, `honeypot`) or
		 * by validation message pattern — the GF reCAPTCHA Add-On v2.x and similar
		 * anti-bot validators attach their failure to ordinary fields (often a
		 * hidden text or form-level marker) rather than a captcha-type field.
		 *
		 * The type allowlist is filterable via `checkview_anti_bot_field_types`
		 * and the marker list via `checkview_anti_bot_validation_markers`, so
		 * customers/integrators can add patterns specific to their stack (e.g.,
		 * non-English error messages or niche anti-spam plugins) without
		 * modifying the plugin.
		 *
		 * @param object $field GF field with `failed_validation` set.
		 * @return bool
		 */
		private static function is_anti_bot_failure( $field ): bool {
			/**
			 * Filters the GF field types treated as anti-bot/captcha during
			 * CheckView tests. Failures on these field types are always
			 * cleared. Default: captcha, hcaptcha, turnstile, honeypot.
			 *
			 * Note: GF's native honeypot field doesn't normally trip
			 * `gform_validation` (it uses gform_entry_is_spam + the
			 * abort path, both covered elsewhere in this class). The
			 * `honeypot` entry here is harmless defense-in-depth for
			 * third-party plugins that use the type but route through
			 * gform_validation.
			 *
			 * WARNING: this filter is consumed by TWO independent layers
			 * — `maybe_hide_recaptcha()` (strips fields of these types
			 * pre-validation) AND `is_anti_bot_failure()` (clears their
			 * failures post-validation). Returning an empty array or
			 * `null` disables BOTH layers, not just one. Use the
			 * `checkview_anti_bot_validation_markers` filter for
			 * message-based extensions instead of weakening this
			 * type list.
			 *
			 * @since 2.0.35
			 *
			 * @param string[] $types Lowercase GF field-type identifiers.
			 */
			$bypass_types = apply_filters(
				'checkview_anti_bot_field_types',
				array( 'captcha', 'hcaptcha', 'turnstile', 'honeypot' )
			);
			if ( in_array( $field->type ?? '', (array) $bypass_types, true ) ) {
				return true;
			}

			$message = strtolower( (string) ( $field->validation_message ?? '' ) );
			if ( '' === $message ) {
				return false;
			}

			/**
			 * Filters substring patterns (lowercase) matched against a field's
			 * `validation_message` to detect anti-bot/anti-spam failures during
			 * CheckView tests. Failures whose message contains any of these
			 * substrings are cleared. Add your plugin/locale-specific markers
			 * here (e.g., translated reCAPTCHA messages, niche anti-spam
			 * plugins).
			 *
			 * The default list covers plugins that surface their failure via
			 * a field's `validation_message`. Plugins that flag submissions
			 * via `gform_entry_is_spam` instead (CleanTalk, OOPSpam, Akismet,
			 * native GF Honeypot, etc.) are handled by the existing
			 * `gform_entry_is_spam` → `__return_false` filter in the
			 * constructor — not by this list.
			 *
			 * @since 2.0.35
			 *
			 * @param string[] $markers Lowercase substring patterns.
			 */
			$bypass_markers = apply_filters(
				'checkview_anti_bot_validation_markers',
				// Each marker must be verified against a specific source
				// file + line in the plugin emitting the validation_message
				// default. Markers added without that verification have
				// been wrong four times in this PR's history. If you can't
				// cite source:line, don't add the marker — customers can
				// extend via the `checkview_anti_bot_validation_markers`
				// filter for their specific stack.
				array(
					// GF core `GF_Field_CAPTCHA` (Really Simple CAPTCHA /
					// math captcha): "The CAPTCHA wasn't entered correctly…"
					// Source: gravityforms/includes/fields/class-gf-field-captcha.php:216,252
					'captcha',
					// GF reCAPTCHA Add-On default: "The reCAPTCHA was invalid…"
					// Source: gravityforms/includes/fields/class-gf-field-captcha.php:293
					// (defense-in-depth alongside `unhook_gf_recaptcha_addon()`)
					'recaptcha',
					// Simple Cloudflare Turnstile default: "Please verify
					// that you are human." `are you human` is a substring.
					// Source: simple-cloudflare-turnstile/inc/errors.php:95
					'are you human',
					// Maspik default: "This looks like spam. Try to rephrase…"
					// The plugin slug never appears in the user-facing message.
					// Source: contact-forms-anti-spam/includes/functions.php:1281
					'looks like spam',
				)
			);
			foreach ( (array) $bypass_markers as $marker ) {
				if ( is_string( $marker ) && '' !== $marker && false !== strpos( $message, $marker ) ) {
					return true;
				}
			}

			return false;
		}

		/**
		 * Removes the GF reCAPTCHA Add-On's callbacks at runtime so its
		 * validation doesn't run during a CheckView test.
		 *
		 * The add-on (slug `gravityformsrecaptcha`, class `GF_RECAPTCHA`)
		 * hooks both `gform_validation` (response token check) and
		 * `gform_entry_is_spam` (low-score → spam) at default priority 10.
		 *
		 * Complements `remove_gravityforms_recaptcha_addon()` in
		 * `includes/checkview-helper-functions.php`, which removes the
		 * add-on's bootstrap from `gform_loaded` on the initial GET request
		 * (when `$_REQUEST['checkview_test_id']` is present). That early
		 * path doesn't fire on the AJAX form submission, where `test_id`
		 * is only in the cookie/referer; by then the add-on has loaded
		 * normally and we need to remove its callbacks at runtime instead.
		 *
		 * Wider category-level filters (`gform_entry_is_spam`
		 * → `__return_false`, marker-bypass in
		 * `checkview_bypass_captcha_validation`) still catch the case
		 * where the class can't be found — e.g., a future rename or a
		 * fork.
		 *
		 * Hooked at `gform_pre_validation` priority 1 so the unhook happens
		 * before any `gform_validation` callback fires. Returns the form
		 * unchanged.
		 *
		 * @since 2.0.35
		 *
		 * @param array $form The form being validated.
		 * @return array
		 */
		public function unhook_gf_recaptcha_addon( $form ) {
			// Idempotent — `gform_pre_validation` fires once per form, so on
			// a page with multiple GF forms this callback would otherwise run
			// repeatedly. After the first run the add-on's callbacks are
			// already removed and subsequent runs would log a misleading
			// "detected but no callbacks unhooked" warning. Static flag
			// scopes the attempt to the first form-validation per request.
			static $attempted = false;
			if ( $attempted ) {
				return $form;
			}
			$attempted = true;

			if ( ! class_exists( 'GF_RECAPTCHA' ) || ! is_callable( array( 'GF_RECAPTCHA', 'get_instance' ) ) ) {
				return $form;
			}

			$instance = GF_RECAPTCHA::get_instance();
			if ( ! is_object( $instance ) ) {
				return $form;
			}

			// Pass priority 10 explicitly — the add-on registers both
			// callbacks at default priority, and remove_filter must match
			// the registered priority. If the add-on bumps the priority
			// in a future release this will silently no-op and the
			// marker-bypass fallback in checkview_bypass_captcha_validation
			// picks up the slack.
			$removed = 0;
			if ( method_exists( $instance, 'validate_submission' )
				&& remove_filter( 'gform_validation', array( $instance, 'validate_submission' ), 10 ) ) {
				$removed++;
			}
			if ( method_exists( $instance, 'check_for_spam_entry' )
				&& remove_filter( 'gform_entry_is_spam', array( $instance, 'check_for_spam_entry' ), 10 ) ) {
				$removed++;
			}

			if ( $removed > 0 ) {
				Checkview_Admin_Logs::add( 'ip-logs', 'Unhooked [' . $removed . '] GF reCAPTCHA Add-On callback(s).' );
			} else {
				// Class is present but neither expected callback was
				// removable. Most likely the add-on renamed its methods
				// or changed its priority — flag for support so we know
				// our shim has drifted from the add-on's internals.
				Checkview_Admin_Logs::add( 'ip-logs', 'GF reCAPTCHA Add-On detected but no callbacks were unhooked (renamed methods or priority changed?) — marker-bypass fallback still active.' );
			}

			return $form;
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
					'meta_key' => checkview_truncate_meta_key( $row->meta_key ),
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
					Checkview_Admin_Logs::add( 'ip-logs', 'Failed to clone submission entry meta data. wpdb->last_error=[' . $wpdb->last_error . ']' );
				}
			}

			$tablename = $wpdb->prefix . 'gf_entry';
			$row = $wpdb->get_row( $wpdb->prepare( 'Select * from ' . $tablename . ' where id=%d and form_id=%d LIMIT 1', $entry_id, $form_id ), ARRAY_A );

			unset( $row['id'] );
			unset( $row['source_id'] );

			$entry_table = $wpdb->prefix . 'cv_entry';
			$row['uid'] = $uid;
			$row['form_type'] = 'GravityForms';

			// gf_entry's varchars are wider than cv_entry's (e.g.,
			// transaction_id 255 vs 50, payment_method 255 vs 30) so a
			// wholesale row copy can hit wpdb::process_field_lengths()
			// rejection on payment forms.
			$row = checkview_truncate_for_cv_entry( $row );

			$result = $wpdb->insert( $entry_table, $row );

			if ( ! $result ) {
				Checkview_Admin_Logs::add( 'ip-logs', 'Failed to clone submission entry data. wpdb->last_error=[' . $wpdb->last_error . ']' );
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
