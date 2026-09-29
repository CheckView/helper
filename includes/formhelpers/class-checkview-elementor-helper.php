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
		 * Highest `e_submissions.id` seen immediately before Elementor ran its
		 * submit actions, keyed by the submission element id.
		 *
		 * The key is the value from `checkview_get_submission_element_id()`,
		 * i.e. what Elementor writes to `e_submissions.element_id` — not
		 * `$record->get_form_settings( 'id' )`, which differs for forms inside
		 * a global template.
		 *
		 * Used to bound submission cleanup to rows this test created. A missing
		 * key means no watermark was captured for that element, in which case
		 * cleanup refuses to delete anything rather than guessing.
		 *
		 * @var array<string,int>
		 */
		private $submission_watermarks = array();

		/**
		 * Constructor.
		 *
		 * Initiates loader property, adds hooks.
		 */
		public function __construct() {
			$this->loader = new Checkview_Loader();

			// Record the newest pre-existing submission id BEFORE Elementor runs
			// its submit actions, so cleanup can tell our row from the customer's.
			// `elementor_pro/forms/record/actions_before` (Elementor Pro 3.3.0+,
			// Ajax_Handler) is the last seam before the action loop that contains
			// `save-to-database`. A `new_record` callback cannot serve here at any
			// priority — that action fires *after* the whole action loop.
			add_filter(
				'elementor_pro/forms/record/actions_before',
				array(
					$this,
					'checkview_capture_submission_watermark',
				),
				1,
				2
			);

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

			// The filter above is necessary but not sufficient for Elementor.
			// See checkview_unhook_turnstile_elementor_widget() for why.
			$this->checkview_unhook_turnstile_elementor_widget();

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
		 * Records the highest existing `e_submissions.id` for this form before
		 * Elementor runs its submit actions.
		 *
		 * Runs on `elementor_pro/forms/record/actions_before`, the last hook
		 * that fires before the action loop containing `save-to-database`.
		 * Everything created at or below this id predates the test submission
		 * and must never be deleted by cleanup.
		 *
		 * Registered as a filter and returns `$record` untouched — the hook is
		 * a filter on the record, not an action.
		 *
		 * @param \ElementorPro\Modules\Forms\Classes\Form_Record  $record  Form record.
		 * @param \ElementorPro\Modules\Forms\Classes\Ajax_Handler $handler Ajax handler.
		 * @return \ElementorPro\Modules\Forms\Classes\Form_Record Unmodified record.
		 */
		public function checkview_capture_submission_watermark( $record, $handler ) {
			global $wpdb;

			// Deliberately NOT gated on get_checkview_test_id(): cleanup in
			// checkview_clone_elementor_entry() runs whenever this helper is
			// loaded and tolerates an empty test id, so gating capture on it
			// would let the two diverge — cleanup would find no watermark and
			// silently skip. The helper is only ever loaded inside a verified
			// test request, so capturing unconditionally costs one indexed
			// MAX() per submission.
			if ( ! $record ) {
				return $record;
			}

			$element_id = $this->checkview_get_submission_element_id( $handler );
			if ( null === $element_id ) {
				return $record;
			}

			$submissions_table = $wpdb->prefix . 'e_submissions';
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $submissions_table ) ) !== $submissions_table ) {
				return $record;
			}

			$this->submission_watermarks[ $element_id ] = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COALESCE(MAX(id), 0) FROM ' . $submissions_table . ' WHERE element_id = %s',
					$element_id
				)
			);

			return $record;
		}

		/**
		 * Resolves the value Elementor stores in `e_submissions.element_id`.
		 *
		 * Must come from `Ajax_Handler::get_current_form()['id']`, because that
		 * is exactly what the `save-to-database` action writes to the column.
		 * `$record->get_form_settings( 'id' )` is NOT equivalent: Ajax_Handler
		 * assigns `$form['settings']['id'] = $form_id` from the posted id, but
		 * when the form lives inside a global template it first reassigns
		 * `$form = $template->get_elements_data()[0]`, leaving `$form['id']`
		 * pointing at the template's element while `settings['id']` keeps the
		 * posted one. Querying by the settings value silently matches nothing
		 * for those forms.
		 *
		 * `current_form` is populated before `record/actions_before` fires, so
		 * this resolves identically in both the capture and cleanup callbacks.
		 *
		 * @param \ElementorPro\Modules\Forms\Classes\Ajax_Handler $handler Ajax handler.
		 * @return string|null Element id, or null when it cannot be resolved.
		 */
		private function checkview_get_submission_element_id( $handler ) {
			if ( ! $handler || ! method_exists( $handler, 'get_current_form' ) ) {
				return null;
			}

			$form = $handler->get_current_form();
			if ( ! is_array( $form ) || empty( $form['id'] ) ) {
				return null;
			}

			return (string) $form['id'];
		}

		/**
		 * Decides which post-watermark submissions belong to this test run.
		 *
		 * Attribution is by visitor IP: Elementor stores the submitter's IP in
		 * `e_submissions.user_ip`, and a real customer will not be submitting
		 * from CheckView's bot IP. When the form has IP collection disabled
		 * (`user_ip` is stored empty), fall back to the unambiguous case of a
		 * single new row.
		 *
		 * The IP match is best-effort and often will not fire behind a proxy.
		 * Elementor resolves the stored value with `Utils::get_client_ip()`,
		 * whose header list omits `HTTP_CF_CONNECTING_IP` and which requires the
		 * whole header to pass `filter_var()` — so a comma-list
		 * `X-Forwarded-For` falls through to `REMOTE_ADDR`. Behind Cloudflare
		 * that is the edge IP, while `checkview_get_visitor_ip()` resolves the
		 * real bot IP from `CF-Connecting-IP`. The two then disagree and no
		 * candidate matches, which degrades safely to the single-row rule (and
		 * to deleting nothing when a race actually occurs).
		 *
		 * Widening the comparison to "whatever Elementor would have stored for
		 * this request" would be actively unsafe: behind a proxy a concurrent
		 * customer submission carries that same edge IP, so it would start
		 * matching real submissions. Only an exact bot-IP match is trustworthy,
		 * because nothing else can legitimately carry it.
		 *
		 * Returns an empty array when attribution is ambiguous — the caller
		 * must then delete nothing. Leaving a test row behind is recoverable;
		 * deleting a customer's submission is not.
		 *
		 * Pure function of its inputs so it can be tested without a database.
		 *
		 * @param array  $candidates Rows with `id` and `user_ip`, newer than the watermark.
		 * @param string $visitor_ip Current visitor IP (CheckView's bot IP during a test).
		 * @return int[] Submission ids safe to delete; empty when undecidable.
		 */
		public function checkview_select_own_submissions( array $candidates, $visitor_ip ) {
			$visitor_ip = (string) $visitor_ip;
			$matched    = array();

			if ( '' !== $visitor_ip ) {
				foreach ( $candidates as $row ) {
					$row_ip = is_object( $row ) ? ( $row->user_ip ?? '' ) : ( $row['user_ip'] ?? '' );
					if ( (string) $row_ip === $visitor_ip ) {
						$row_id    = is_object( $row ) ? ( $row->id ?? 0 ) : ( $row['id'] ?? 0 );
						$matched[] = (int) $row_id;
					}
				}
			}

			if ( ! empty( $matched ) ) {
				return $matched;
			}

			// No IP match. A single new row is unambiguously the one this test
			// just created (Elementor stores an empty user_ip when the form
			// omits `remote_ip` from its submissions metadata).
			if ( 1 === count( $candidates ) ) {
				$only    = reset( $candidates );
				$only_id = is_object( $only ) ? ( $only->id ?? 0 ) : ( $only['id'] ?? 0 );
				return array( (int) $only_id );
			}

			return array();
		}

		/**
		 * Stops Simple CAPTCHA with Cloudflare Turnstile from rendering its
		 * widget on Elementor forms during a test.
		 *
		 * That plugin consults `cfturnstile_whitelisted()` only in its
		 * verification callback (`elementor_pro/forms/validation`). Its Elementor
		 * widget is rendered by a *separate* `wp_enqueue_scripts` callback which
		 * never checks the whitelist, and which loads
		 * `js/integrations/elementor-forms.js` to inject the field client-side.
		 *
		 * So during a test the widget still renders, `cf-turnstile-response`
		 * stays empty, and the submission is blocked in the browser — meaning
		 * the validation hook never fires and the `cfturnstile_whitelisted`
		 * filter is never reached. Whitelisting alone guards a door the request
		 * never gets to; the widget has to be stopped from rendering.
		 *
		 * Elementor is the only integration in that plugin with this gap. Every
		 * other one (CF7, WPForms, Gravity, Fluent, Formidable, Forminator,
		 * WooCommerce) renders through the shared `cfturnstile_field_show()`,
		 * which does check `cfturnstile_whitelisted()` before emitting anything.
		 *
		 * Timing: that plugin registers the enqueue when its integration file is
		 * included at plugin-load, while this helper is constructed from
		 * `checkview_init_current_test()` on `init` priority 10 — earlier than
		 * `wp_enqueue_scripts`, so the callback is present and removable here.
		 *
		 * The priority is looked up rather than hardcoded, so an upstream change
		 * to it degrades into a logged no-op instead of a silent one.
		 *
		 * @return void
		 */
		private function checkview_unhook_turnstile_elementor_widget() {
			// Absent unless Simple CF Turnstile is active with its Elementor
			// integration loaded. Nothing to do, and nothing worth logging.
			if ( ! function_exists( 'cfturnstile_elementor_enqueue_scripts' ) ) {
				return;
			}

			// Strict comparison: has_action() returns the priority, which may
			// legitimately be 0.
			$priority = has_action( 'wp_enqueue_scripts', 'cfturnstile_elementor_enqueue_scripts' );

			if ( false === $priority ) {
				Checkview_Admin_Logs::add( 'ip-logs', 'Simple CF Turnstile Elementor enqueue exists but is not hooked to wp_enqueue_scripts; the Turnstile widget may still render and block submission (upstream hook may have changed).' );
				return;
			}

			remove_action( 'wp_enqueue_scripts', 'cfturnstile_elementor_enqueue_scripts', $priority );
			Checkview_Admin_Logs::add( 'ip-logs', 'Unhooked Simple CF Turnstile Elementor widget enqueue (priority ' . $priority . ') so the Turnstile field does not render during the test.' );
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

			// Elementor form ids are 7-character widget element ids, so they are
			// non-numeric roughly 96% of the time, and `cv_entry.form_id` is a
			// mediumint that silently coerces them to 0. This is inert today:
			// results are retrieved by `uid`, never by `form_id` (see
			// Checkview_Api::checkview_get_test_results). Logged as a note
			// rather than a WARNING because it is the normal case, not a fault.
			// If `form_id` is ever made meaningful for Elementor, the column has
			// to become a varchar first.
			if ( ! is_numeric( $form_id ) ) {
				Checkview_Admin_Logs::add( 'ip-logs', 'Note: Elementor form id [' . $form_id . '] is non-numeric; cv_entry.form_id stores 0 (unused for Elementor lookups).' );
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

			// Remove the just-saved submission from Elementor's tables. All three
			// are keyed to the submission: `e_submissions_actions_log` was
			// previously left behind, orphaning one row per non-save action
			// (e.g. the email action) on every test run.
			$submissions_table = $wpdb->prefix . 'e_submissions';
			$values_table      = $wpdb->prefix . 'e_submissions_values';
			$actions_log_table = $wpdb->prefix . 'e_submissions_actions_log';

			// Elementor only writes to `e_submissions` when the form's
			// `save-to-database` action is enabled. When it is not, this test
			// created no row at all, so anything newer than the watermark
			// belongs to a real visitor and must be left alone. Forms that once
			// saved submissions and no longer do still have history here, which
			// is what made the previous unscoped delete destructive.
			$saves_submissions = in_array(
				'save-to-database',
				(array) $record->get_form_settings( 'submit_actions' ),
				true
			);

			// Query by the element id Elementor actually stored, not by the form
			// settings id — see checkview_get_submission_element_id().
			$element_id = $this->checkview_get_submission_element_id( $handler );

			if ( ! $saves_submissions ) {
				Checkview_Admin_Logs::add( 'ip-logs', 'Skipping Elementor submission cleanup for form [' . $form_id . ']: form does not have the save-to-database action, so this test created no submission row.' );
			} elseif ( null === $element_id ) {
				Checkview_Admin_Logs::add( 'ip-logs', 'Skipping Elementor submission cleanup for form [' . $form_id . ']: could not resolve the submission element id from the ajax handler.' );
			} elseif ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $submissions_table ) ) === $submissions_table ) {
				$watermark = isset( $this->submission_watermarks[ $element_id ] )
					? $this->submission_watermarks[ $element_id ]
					: null;

				if ( null === $watermark ) {
					// No watermark means we cannot distinguish our submission from
					// the customer's. Deleting the newest row here is what caused
					// real submissions to be destroyed; leaving the test row behind
					// is the strictly safer failure.
					Checkview_Admin_Logs::add( 'ip-logs', 'Skipping Elementor submission cleanup for form [' . $form_id . ']: no pre-submission watermark was captured, so the test row cannot be identified safely.' );
				} else {
					// Only rows created after the watermark can belong to this test.
					$candidates = $wpdb->get_results(
						$wpdb->prepare(
							'SELECT id, user_ip FROM ' . $submissions_table . ' WHERE element_id = %s AND id > %d ORDER BY id ASC',
							$element_id,
							$watermark
						)
					);

					$ids = $this->checkview_select_own_submissions(
						is_array( $candidates ) ? $candidates : array(),
						checkview_get_visitor_ip()
					);

					if ( empty( $ids ) ) {
						Checkview_Admin_Logs::add(
							'ip-logs',
							'Skipping Elementor submission cleanup for form [' . $form_id . '] (element_id [' . $element_id . ']): '
							. count( is_array( $candidates ) ? $candidates : array() )
							. ' row(s) newer than watermark [' . $watermark . '] and none could be attributed to this test.'
						);
					}

					$has_actions_log = ! empty( $ids )
						&& $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $actions_log_table ) ) === $actions_log_table;

					foreach ( $ids as $submission_id ) {
						if ( $has_actions_log ) {
							$wpdb->delete( $actions_log_table, array( 'submission_id' => $submission_id ), array( '%d' ) );
						}
						$wpdb->delete( $values_table, array( 'submission_id' => $submission_id ), array( '%d' ) );
						$wpdb->delete( $submissions_table, array( 'id' => $submission_id ), array( '%d' ) );
						Checkview_Admin_Logs::add( 'ip-logs', 'Deleted Elementor submission [' . $submission_id . '] (element_id [' . $element_id . ']) from ' . $submissions_table . '.' );
					}
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
