<?php
/**
 * Checkview_Cf7_Helper class
 *
 * @since 1.0.0
 *
 * @package Checkview
 * @subpackage Checkview/includes/formhelpers
 */

if ( ! defined( 'WPINC' ) ) {
	die( 'Direct access not Allowed.' );
}

if ( ! class_exists( 'Checkview_Cf7_Helper' ) ) {
	/**
	 * Adds support for Contact Form 7.
	 *
	 * During CheckView tests, modifies Contact Form 7 hooks, overwrites the
	 * recipient email address, and handles test cleanup.
	 *
	 * @package Checkview
	 * @subpackage Checkview/includes/formhelpers
	 * @author Check View <support@checkview.io>
	 */
	class Checkview_Cf7_Helper {
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
			if ( defined( 'TEST_EMAIL' ) ) {
				// change emial address.
				add_filter(
					'wpcf7_mail_components',
					array(
						$this,
						'checkview_inject_email',
					),
					99,
					1
				);
			}
			add_action(
				'wpcf7_before_send_mail',
				array(
					$this,
					'checkview_cf7_before_send_mail',
				),
				99,
				1
			);

			add_action(
				'cfdb7_after_save_data',
				array(
					$this,
					'checkview_delete_entry',
				),
				999,
				1
			);
			add_filter(
				'wpcf7_spam',
				array(
					$this,
					'checkview_return_false',
				),
				999
			);
			add_filter(
				'wpcf7_skip_spam_check',
				'__return_true',
				999
			);

			add_filter(
				'wpcf7_submission_has_disallowed_words',
				'__return_false',
				999,
				2
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
			add_filter(
				'wpcf7_flamingo_submit_if',
				array(
					$this,
					'checkview_bypass_flamingo',
				),
				99
			);

			// Set Piped values back to original values for required select fields
			add_filter('wpcf7_posted_data_select*', array($this, 'checkview_handled_cf7_piped_data'), 99, 3);

			// Set Piped values back to original values for optional select fields
			add_filter('wpcf7_posted_data_select', array($this, 'checkview_handled_cf7_piped_data'), 99, 3);

			// disable_actions enumeration: when the flow's disable_actions flag
			// resolves to "yes", remove third-party callbacks on CF7's email-stage
			// hooks so integrations (Mailchimp, Zapier, webhooks, etc.) don't fire.
			// Registered at -PHP_INT_MAX to run before any plausible third-party
			// priority, including legacy negative-priority registrations. We hook
			// the target action itself (not wp_loaded) to catch lazy registrations
			// that happen during request handling.
			//
			// Closure callbacks cannot be reliably classified by class name and
			// are preserved (acknowledged limitation). Real-world CF7 third-party
			// addons use class-based hooks.
			add_action(
				'wpcf7_before_send_mail',
				array( $this, 'checkview_cf7_disable_actions_enumerate' ),
				-PHP_INT_MAX,
				0
			);
			add_action(
				'wpcf7_mail_sent',
				array( $this, 'checkview_cf7_disable_actions_enumerate' ),
				-PHP_INT_MAX,
				0
			);
		}

		/**
		 * Class-name substring backstop for known third-party CF7 integrations.
		 * Used by should_remove() to identify callbacks that don't follow CF7's
		 * WPCF7_* core prefix convention (e.g. CF7-to-Webhook plugin classes).
		 * Case-insensitive match via stripos().
		 */
		const THIRD_PARTY_BACKSTOP = array(
			'Mailchimp',
			'Zapier',
			'Webhook',
			'Hubspot',
			'Salesforce',
			'ActiveCampaign',
			'ConvertKit',
			'SendGrid',
			'Postmark',
			'Slack',
			'Brevo',
			'Sendinblue',
			'Klaviyo',
			'ConstantContact',
			'AWeber',
			'Drip',
		);

		/**
		 * Enumerates callbacks on the firing CF7 hook (wpcf7_before_send_mail or
		 * wpcf7_mail_sent) and removes third-party callbacks when the flow's
		 * disable_actions flag is set. Preserves CF7 core (WPCF7_*) and Checkview
		 * (Checkview_*) callbacks. Two-pass to avoid mutation-during-iteration.
		 *
		 * @return void
		 */
		public function checkview_cf7_disable_actions_enumerate() {
			$cv_test_id = get_checkview_test_id();
			if ( ! $cv_test_id || 'true' !== get_option( 'disable_actions_' . $cv_test_id, false ) ) {
				return;
			}

			$hook = current_filter();
			if ( ! isset( $GLOBALS['wp_filter'][ $hook ] ) ) {
				return;
			}

			$to_remove = array();
			foreach ( $GLOBALS['wp_filter'][ $hook ]->callbacks as $priority => $priority_callbacks ) {
				foreach ( $priority_callbacks as $cb_id => $cb_data ) {
					$callable = $cb_data['function'];
					if ( $this->should_remove( $callable ) ) {
						$to_remove[] = array(
							'callable' => $callable,
							'priority' => $priority,
						);
					}
				}
			}

			foreach ( $to_remove as $r ) {
				remove_filter( $hook, $r['callable'], $r['priority'] );
			}
		}

		/**
		 * Classifies a CF7 hook callback as third-party (remove) or core/keep.
		 *
		 * Order matters — first match wins:
		 *   Closure                  KEEP  (can't classify; acknowledged limit)
		 *   Checkview_*              KEEP  (self-removal guard)
		 *   THIRD_PARTY_BACKSTOP    REMOVE (overrides WPCF7 keep for hypothetical
		 *                                  WPCF7_SendGrid_* etc.)
		 *   WPCF7_*                  KEEP  (CF7 core)
		 *   plain function / no class KEEP (unidentifiable, defensive)
		 *   default                 REMOVE
		 *
		 * @param mixed $callable Callback as stored in WP_Hook::callbacks.
		 * @return bool True if the callback should be removed.
		 */
		private function should_remove( $callable ) {
			$class_name = '';
			if ( is_array( $callable ) && isset( $callable[0] ) ) {
				$class_name = is_object( $callable[0] ) ? get_class( $callable[0] ) : (string) $callable[0];
			} elseif ( is_object( $callable ) ) {
				$class_name = get_class( $callable );
			} elseif ( is_string( $callable ) && strpos( $callable, '::' ) !== false ) {
				list( $class_name, ) = explode( '::', $callable, 2 );
			}

			if ( 'Closure' === $class_name ) {
				return false;
			}
			if ( 0 === strpos( $class_name, 'Checkview_' ) ) {
				return false;
			}
			foreach ( self::THIRD_PARTY_BACKSTOP as $needle ) {
				if ( false !== stripos( $class_name, $needle ) ) {
					return true;
				}
			}
			if ( 0 === strpos( $class_name, 'WPCF7_' ) ) {
				return false;
			}
			if ( '' === $class_name ) {
				return false;
			}
			return true;
		}

		/**
		 * Stores the test results and finishes the testing session.
		 *
		 * @param Object $form_tag Form object by CFS.
		 * @return void
		 */
		public function checkview_cf7_before_send_mail( $form_tag ) {
			global $wpdb;

			$form_id = $form_tag->id();
			$wp_filesystem_direct = new WP_Filesystem_Direct( array() );
			$checkview_test_id = get_checkview_test_id();

			if ( empty( $checkview_test_id ) ) {
				$checkview_test_id = $form_id . gmdate( 'Ymd' );
			}

			$upload_dir = wp_upload_dir();
			$cv_cf7_dirname = $upload_dir['basedir'] . '/cv_cf7_uploads';

			if ( ! file_exists( $cv_cf7_dirname ) ) {
				$wp_filesystem_direct->mkdir( $cv_cf7_dirname, 0777, true );
			}

			$time_now = time();
			$submission = WPCF7_Submission::get_instance();

			if ( $submission ) {
				$contact_form = $submission->get_contact_form();
			}

			$tags_names = array();

			if ( $submission ) {
				Checkview_Admin_Logs::add( 'ip-logs', 'Cloning submission entry...' );

				$tags = $contact_form->scan_form_tags();

				foreach ( $tags as $tag ) {
					if ( ! empty( $tag->name ) ) {
						$tags_names[] = $tag->name;
					}
				}

				$allowed_tags = $tags_names;
				$not_allowed_tags = array( 'g-recaptcha-response' );
				$data = $submission->get_posted_data();
				$files = $submission->uploaded_files();
				$uploaded_files = array();

				foreach ( $_FILES as $file_key => $file ) {
					array_push( $uploaded_files, $file_key );
				}

				foreach ( $files as $file_key => $file ) {
					$file = is_array( $file ) ? reset( $file ) : $file;
					if ( empty( $file ) ) {
						continue;
					}
					copy( $file, $cv_cf7_dirname . '/' . $time_now . '-' . $file_key . '-' . basename( $file ) );
				}

				$form_data = array();

				foreach ( $data as $key => $d ) {
					if ( ! in_array( $key, $allowed_tags ) ) {
						continue;
					}

					if ( ! in_array( $key, $not_allowed_tags ) && ! in_array( $key, $uploaded_files ) ) {
						$tmp_d = $d;

						if ( ! is_array( $d ) ) {
							$bl = array( '\"', "\'", '/', '\\', '"', "'" );
							$wl = array( '&quot;', '&#039;', '&#047;', '&#092;', '&quot;', '&#039;' );
							$tmp_d = str_replace( $bl, $wl, $tmp_d );
						}

						if ( is_array( $d ) ) {
							$tmp_d = serialize( $d );
						}

						$form_data[ $key ] = $tmp_d;
					}

					if ( in_array( $key, $uploaded_files ) ) {
						$file = is_array( $files[ $key ] ) ? reset( $files[ $key ] ) : $files[ $key ];
						$file_name = empty( $file ) ? '' : $time_now . '-' . $key . '-' . basename( $file );
						// Store under suffixed key for backward compat (no current readers, but preserved).
						$form_data[ $key . 'cv_cf7_file' ] = $file_name;
						// Also store under original field name so CheckView SaaS validation
						// (which looks up by input name/id) can find the uploaded file.
						$form_data[ $key ] = $file_name;
					}
				}

				// insert entry.
				$entry_data  = array(
					'form_id' => $form_id,
					'status' => 'publish',
					'source_url' => isset( $_SERVER['HTTP_REFERER'] ) ? substr( sanitize_url( wp_unslash( $_SERVER['HTTP_REFERER'] ) ), 0, 200 ) : '',
					'date_created' => current_time( 'mysql' ),
					'date_updated' => current_time( 'mysql' ),
					'uid' => $checkview_test_id,
					'form_type' => 'CF7',
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
				// complete_checkview_test() below still runs.
				if ( $result ) {
					$inserted_entry_id = $wpdb->insert_id;
					$entry_meta_table  = $wpdb->prefix . 'cv_entry_meta';
					$count             = 0;

					foreach ( $form_data as $key => $val ) {
						$entry_metadata = array(
							'uid' => $checkview_test_id,
							'form_id' => $form_id,
							'entry_id' => $inserted_entry_id,
							'meta_key' => checkview_truncate_meta_key( $key ),
							'meta_value' => $val,
						);

						$result = $wpdb->insert( $entry_meta_table, $entry_metadata );

						if ( $result ) {
							$count++;
						}
					}

					if ( $count > 0 ) {
						Checkview_Admin_Logs::add( 'ip-logs', 'Cloned submission entry meta data (inserted ' . $count . ' rows into ' . $entry_meta_table . ').' );
					} else {
						if ( count( $form_data ) > 0 ) {
							Checkview_Admin_Logs::add( 'ip-logs', 'Failed to clone submission entry meta data. wpdb->last_error=[' . $wpdb->last_error . ']' );
						}
					}
				}

				complete_checkview_test( $checkview_test_id );
			}
		}

		/**
		 * Deletes the form entry from the database.
		 *
		 * @param int $insert_id The inserted ID from CF7 form.
		 * @return void
		 */
		public function checkview_delete_entry( $insert_id ) {
			global $wpdb;
			$wpdb->delete( $wpdb->prefix . 'db7_forms', array( 'form_id' => $insert_id ) );
		}


		/**
		 * Injects testing email recipient.
		 *
		 * @param array $args Emails.
		 * @return array
		 */
		public function checkview_inject_email( $args ) {
			$cv_test_id = get_checkview_test_id();
			if ( $cv_test_id && 'true' === get_option( 'disable_email_receipt_' . $cv_test_id, false ) ) {
				$args['recipient'] .= ', ' . TEST_EMAIL;
			} else {
				$args['recipient'] = TEST_EMAIL;
				$headers           = '';
				// Remove bcc and cc headers.
				$headers = preg_replace( '/^(bcc:|cc:).*$/mi', '', $args['additional_headers'] );

				// Clean up any extra newlines.
				$headers                    = preg_replace( '/^\s*[\r\n]+/m', '', $headers );
				$args['additional_headers'] = $headers;
			}

			Checkview_Admin_Logs::add( 'ip-logs', 'Submission recipient email address: ' . wp_json_encode( $args['recipient'] ?? null ) );
			Checkview_Admin_Logs::add( 'ip-logs', 'Submission sender email address: ' . wp_json_encode( $args['sender'] ?? null ) );
			Checkview_Admin_Logs::add( 'ip-logs', 'Submission email additional headers: ' . wp_json_encode( $args['additional_headers'] ?? null ) );
			return $args;
		}

		/**
		 * Returns false.
		 *
		 * @return bool
		 */
		public function checkview_return_false() {
			return false;
		}

		/**
		 * Bypass flaimgo.
		 *
		 * @param array $cases cases to bypass.
		 * @return array cases.
		 */
		public function checkview_bypass_flamingo( array $cases ): array {
			$cases   = array();
			$cases[] = 'checkview_bot';
			return $cases;
		}

		/**
		 * Assign original data instead of piped data to fields during CF7 submissions.
		 *
		 * @since 2.0.22
		 *
		 * @param array|mixed|string $value Piped value.
		 * @param array|mixed|string $value_orig Original value.
		 * @param mixed $tag Tag.
		 *
		 * @return array|mixed|string
		 */
		public function checkview_handled_cf7_piped_data( $value, $value_orig, $tag ) {
			if ( ! is_array( $value ) || ! is_string( $value[0]) || ! is_string( $value_orig ) ) {
				return $value;
			}

			if ($value[0] !== $value_orig) {
				Checkview_Admin_Logs::add( 'api-logs', 'Detected piped CF7 select field, restoring original value.' );

				return $value_orig;
			}

			return $value;
		}
	}

	$checkview_cf7_helper = new checkview_cf7_helper();
}
