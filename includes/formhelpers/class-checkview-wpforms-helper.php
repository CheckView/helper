<?php
/**
 * Checkview_Wpforms_Helper class
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

if ( ! class_exists( 'Checkview_Wpforms_Helper' ) ) {
	/**
	 * Adds support for WP Forms.
	 *
	 * During CheckView tests, modifies WP Forms hooks, overwrites the
	 * recipient email address, and handles test cleanup.
	 *
	 * @package Checkview
	 * @subpackage Checkview/includes/formhelpers
	 * @author Check View <support@checkview.io>
	 */
	class Checkview_Wpforms_Helper {
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

			if ( ! is_admin() ) {
				include_once ABSPATH . 'wp-admin/includes/plugin.php';
			}

			add_filter( 'wpforms_frontend_form_data', array( $this, 'checkview_disable_turnstile' ) );

			add_filter( 'wpforms_process_before_form_data', array( $this, 'checkview_disable_turnstile' ) );

			add_filter( 'wpforms_frontend_captcha_api', array( $this, 'checkview_disable_frontend_captcha_api' ) );

			add_filter( 'wpforms_frontend_recaptcha_disable', '__return_true', 99 );

			// Disable validation and verification on the backend.
			add_filter(
				'wpforms_process_bypass_captcha',
				'__return_true',
				99
			);

			remove_action(
				'wpforms_frontend_output',
				array(
					wpforms()->get( 'frontend' ),
					'recaptcha',
				),
				20
			);

			// IMPORTANT: register as `array( $this, 'method' )`, not as a static
			// `array( __CLASS__, 'method' )` — checkview_wpforms_disable_addons()
			// whitelists this callback via `instanceof Checkview_Wpforms_Helper`
			// on $cb['function'][0]. A static-method registration would store a
			// string class name in slot 0, fail the instance check, and our own
			// cloning callback would be silently purged alongside third-party
			// listeners under disable_actions.
			add_action(
				'wpforms_process_complete',
				array(
					$this,
					'checkview_log_wpform_test_entry',
				),
				99,
				4
			);

			/**
			 * Disables the email address suggestion.
			 *
			 * @link https://wpforms.com/developers/how-to-disable-the-email-suggestion-on-the-email-form-field/
			 */
			add_filter(
				'wpforms_mailcheck_enabled',
				'__return_false'
			);

			if ( defined( 'TEST_EMAIL' ) ) {
				// change email to send to our test account.
				add_filter(
					'wpforms_entry_email_atts',
					array(
						$this,
						'checkview_inject_email',
					),
					99,
					1
				);
			}
			add_filter(
				'cfturnstile_whitelisted',
				'__return_true',
				999
			);

			// Bypass hCaptcha.
			add_filter( 'hcap_activate', '__return_false' );

			// Bypass Akismet.
			add_filter(
				'akismet_get_api_key',
				'__return_null',
				-10
			);

			add_filter(
				'wpforms_frontend_form_data',
				array(
					$this,
					'checkview_disable_wpforms_custom_captcha',
				),
				99,
				1
			);

			add_filter(
				'wpforms_process_before_form_data',
				array(
					$this,
					'checkview_disable_wpforms_custom_captcha',
				),
				99,
				1
			);

			add_action(
				'wpforms_process',
				array(
					$this,
					'checkview_wpforms_disable_addons',
				),
				1,
				3
			);
		}

		/**
		 * Suppress third-party integrations on wpforms_process_complete when the
		 * test flow's disable_actions flag is set.
		 *
		 * Hooks early on wpforms_process (which fires before wpforms_process_complete)
		 * and walks the global $wp_filter, removing every callback registered on
		 * wpforms_process_complete and its form-id-scoped variant — except this
		 * helper's own checkview_log_wpform_test_entry, which clones the submission
		 * into the cv_entry / cv_entry_meta tables. Whitelisting by class instance
		 * (not priority) survives any future priority change to our own callback.
		 *
		 * Why blanket removal: WPForms providers (Mailchimp, ActiveCampaign,
		 * ConvertKit, Salesforce, Drip, Sendinblue, Zoho), the User Registration
		 * addon, the Post Submissions addon, the Webhooks addon, and any custom
		 * site code all hook wpforms_process_complete. Stripping individual
		 * form_data keys would miss addons (User Registration / Post Submissions)
		 * that read from $form_data['settings'] sub-keys and any custom code that
		 * doesn't read form_data at all. Removing all listeners is the same
		 * pattern the everest-forms helper uses.
		 *
		 * Preserved side effects (NOT on this hook):
		 * - Native email notifications fire via WPForms_Process::process_emails(),
		 *   a direct call after the action returns, so assert_email_received still
		 *   receives the confirmation mail at <test_run_id>@test-mail.checkview.io.
		 * - Entry save into wpforms_entries happens earlier in entry_save(); the
		 *   row exists by the time our cloning callback runs.
		 *
		 * @param array $fields    Form fields.
		 * @param array $entry     Entry data.
		 * @param array $form_data Form data and settings.
		 *
		 * @return void
		 */
		public function checkview_wpforms_disable_addons( $fields, $entry, $form_data ) {
			$cv_test_id = get_checkview_test_id();
			if ( ! $cv_test_id || 'true' !== get_option( 'disable_actions_' . $cv_test_id, false ) ) {
				return;
			}

			global $wp_filter;
			$form_id = isset( $form_data['id'] ) ? (int) $form_data['id'] : 0;
			$hooks   = array( 'wpforms_process_complete' );
			if ( $form_id ) {
				$hooks[] = 'wpforms_process_complete_' . $form_id;
			}

			$removed_count = 0;
			foreach ( $hooks as $hook ) {
				if ( ! isset( $wp_filter[ $hook ] ) || empty( $wp_filter[ $hook ]->callbacks ) ) {
					continue;
				}
				foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
					foreach ( $callbacks as $cb_id => $cb ) {
						if (
							is_array( $cb['function'] ) &&
							isset( $cb['function'][0] ) &&
							is_object( $cb['function'][0] ) &&
							$cb['function'][0] instanceof Checkview_Wpforms_Helper
						) {
							continue;
						}
						unset( $wp_filter[ $hook ]->callbacks[ $priority ][ $cb_id ] );
						$removed_count++;
					}
					if ( empty( $wp_filter[ $hook ]->callbacks[ $priority ] ) ) {
						unset( $wp_filter[ $hook ]->callbacks[ $priority ] );
					}
				}
			}

			Checkview_Admin_Logs::add( 'ip-logs', 'WPForms: removed ' . $removed_count . ' third-party listener(s) from wpforms_process_complete under disable_actions.' );
		}

		/**
		 * Disable Cloudflare Turnstile.
		 *
		 * @param array $form_data Form data.
		 *
		 * @return array Modified form data.
		 *
		 * @since 2.0.19
		 */
		public function checkview_disable_turnstile( $form_data ) {
			$form_data['settings']['recaptcha'] = '0';

			return $form_data;
		}

		/**
		 * Disable WP Forms frontend CAPTCHA API.
		 *
		 * @param $captcha_api string CAPTCHA API.
		 *
		 * @return string
		 *
		 * @since 2.0.19
		 */
		public function checkview_disable_frontend_captcha_api( $captcha_api ) {
			$captcha_settings = wpforms_get_captcha_settings();

			if ( $captcha_settings['provider'] === 'turnstile' ) {
				return '';
			}

			return $captcha_api;
		}

		/**
		 * Injects testing email address.
		 *
		 * @param array $email Email address details.
		 * @return array
		 */
		public function checkview_inject_email( $email ) {
			$cv_test_id = get_checkview_test_id();
			if ( ! $cv_test_id || 'true' != get_option( 'disable_email_receipt_' . $cv_test_id, false ) ) {
				$count = count( $email['address'] );
				for ( $i = 0; $i < $count; $i++ ) {
					$email['address'][ $i ]    = TEST_EMAIL;
					$email['carboncopy'][ $i ] = '';
				}
			} elseif ( is_array( $email['address'] ) ) {
				$email['address'][] = TEST_EMAIL;
			} else {
				$email['address'] .= ', ' . TEST_EMAIL;
			}

			Checkview_Admin_Logs::add( 'ip-logs', 'Submission recipient email address: ' . wp_json_encode( $email['address'] ?? null ) );
			Checkview_Admin_Logs::add( 'ip-logs', 'Submission sender email address: ' . wp_json_encode( $email['sender_address'] ?? null ) );
			return $email;
		}
		/**
		 * Stores the test results and finishes the testing session.
		 *
		 * Deletes test submission from Formidable database table.
		 *
		 * @param array $form_fields Form fields.
		 * @param array $entry Form entry details.
		 * @param array $form_data Form data.
		 * @param int   $entry_id Form entry ID.
		 * @return void
		 */
		public function checkview_log_wpform_test_entry( $form_fields, $entry, $form_data, $entry_id ) {
			global $wpdb;

			Checkview_Admin_Logs::add( 'ip-logs', 'Cloning submission entry [' . $entry_id . ']...' );

			if ( ! function_exists( 'is_plugin_active' ) ) {
				include_once ABSPATH . 'wp-admin/includes/plugin.php';
			}

			$form_id = $form_data['id'];
			$checkview_test_id = get_checkview_test_id();

			if ( empty( $checkview_test_id ) ) {
				$checkview_test_id = $form_id . gmdate( 'Ymd' );
			}

			$entry_data  = array(
				'form_id' => $form_id,
				'status' => 'publish',
				'source_url' => isset( $_SERVER['HTTP_REFERER'] ) ? substr( sanitize_url( wp_unslash( $_SERVER['HTTP_REFERER'] ) ), 0, 200 ) : '',
				'date_created' => current_time( 'mysql' ),
				'date_updated' => current_time( 'mysql' ),
				'uid' => $checkview_test_id,
				'form_type' => 'WpForms',
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
			// WPForms entry delete and complete_checkview_test() below still run.
			if ( $result ) {
				$inserted_entry_id = $wpdb->insert_id;
				$entry_meta_table  = $wpdb->prefix . 'cv_entry_meta';
				$field_id_prefix   = 'wpforms-' . $form_id . '-field_';
				$count = 0;

				foreach ( $form_fields as $field ) {
					if ( ! isset( $field['value'] ) || '' === $field['value'] ) {
						continue;
					}

					$field_value = is_array( $field['value'] ) ? serialize( $field['value'] ) : $field['value'];
					$type = isset( $field['type'] ) ? $field['type'] : '';

					switch ( $type ) {
						case 'name':
							$first  = isset( $field['first'] ) ? $field['first'] : '';
							$middle = isset( $field['middle'] ) ? $field['middle'] : '';
							$last   = isset( $field['last'] ) ? $field['last'] : '';

							// Simple Name format: WPForms sets first/middle/last to empty strings
							// (unlike compound formats where they hold actual values), so fall
							// back to the combined value when all subfields are empty.
							if ( empty( $field['first'] ) && empty( $field['last'] ) && '' !== $field_value ) {
								$first = $field_value;
							}

							if ( '' === $middle && '' === $last ) {
								$entry_metadata = array(
									'uid'        => $checkview_test_id,
									'form_id'    => $form_id,
									'entry_id'   => $inserted_entry_id,
									'meta_key'   => $field_id_prefix . $field['id'],
									'meta_value' => $first,
								);

								$result = $wpdb->insert( $entry_meta_table, $entry_metadata );

								if ( $result ) {
									$count++;
								}
							} elseif ( '' === $middle ) {
								$entry_metadata = array(
									'uid'        => $checkview_test_id,
									'form_id'    => $form_id,
									'entry_id'   => $inserted_entry_id,
									'meta_key'   => $field_id_prefix . $field['id'],
									'meta_value' => $first,
								);

								$result = $wpdb->insert( $entry_meta_table, $entry_metadata );

								if ( $result ) {
									$count++;
								}

								$entry_metadata = array(
									'uid' => $checkview_test_id,
									'form_id' => $form_id,
									'entry_id' => $inserted_entry_id,
									'meta_key' => $field_id_prefix . $field['id'] . '-last',
									'meta_value' => $last,
								);

								$result = $wpdb->insert( $entry_meta_table, $entry_metadata );

								if ( $result ) {
									$count++;
								}
							} else {
								$entry_metadata = array(
									'uid' => $checkview_test_id,
									'form_id' => $form_id,
									'entry_id' => $inserted_entry_id,
									'meta_key' => $field_id_prefix . $field['id'],
									'meta_value' => $first,
								);

								$result = $wpdb->insert( $entry_meta_table, $entry_metadata );

								if ( $result ) {
									$count++;
								}

								$entry_metadata = array(
									'uid' => $checkview_test_id,
									'form_id' => $form_id,
									'entry_id' => $inserted_entry_id,
									'meta_key' => $field_id_prefix . $field['id'] . '-middle',
									'meta_value' => $middle,
								);

								$result = $wpdb->insert( $entry_meta_table, $entry_metadata );

								if ( $result ) {
									$count++;
								}

								$entry_metadata = array(
									'uid' => $checkview_test_id,
									'form_id' => $form_id,
									'entry_id' => $inserted_entry_id,
									'meta_key' => $field_id_prefix . $field['id'] . '-last',
									'meta_value' => $last,
								);

								$result = $wpdb->insert( $entry_meta_table, $entry_metadata );

								if ( $result ) {
									$count++;
								}
							}
							break;
						default:
							$entry_metadata = array(
								'uid' => $checkview_test_id,
								'form_id' => $form_id,
								'entry_id' => $inserted_entry_id,
								'meta_key' => $field_id_prefix . $field['id'],
								'meta_value' => $field_value,
							);

							$result = $wpdb->insert( $entry_meta_table, $entry_metadata );

							if ( $result ) {
								$count++;
							}

							break;
					}
				}

				if ( $count > 0 ) {
					Checkview_Admin_Logs::add( 'ip-logs', 'Cloned submission entry meta data (inserted ' . $count . ' rows into ' . $entry_meta_table . ').' );
				} else {
					if ( count( $form_fields ) > 0 ) {
						Checkview_Admin_Logs::add( 'ip-logs', 'Failed to clone submission entry meta data. wpdb->last_error=[' . $wpdb->last_error . ']' );
					}
				}
			}

			// Remove entry if Pro plugin.
			if ( is_plugin_active( 'wpforms/wpforms.php' ) ) {
				// Remove Test Entry From WpForms Tables.
				$wpdb->delete(
					$wpdb->prefix . 'wpforms_entries',
					array(
						'entry_id' => $entry_id,
						'form_id'  => $form_id,
					)
				);

				$wpdb->delete(
					$wpdb->prefix . 'wpforms_entry_fields',
					array(
						'entry_id' => $entry_id,
						'form_id'  => $form_id,
					)
				);
			}

			complete_checkview_test( $checkview_test_id );
		}
		/**
		 * Disable Custom CAPTCHA in WPForms.
		 *
		 * @param array $form_data Form data and settings.
		 * @return array Modified form data.
		 */
		public function checkview_disable_wpforms_custom_captcha( $form_data ) {
			if ( empty( $form_data['fields'] ) ) {
				return $form_data;
			}

			foreach ( $form_data['fields'] as $id => $field ) {
				if ( 'captcha' === $field['type'] ) {
					unset( $form_data['fields'][ $id ] );
				}
			}

			return $form_data;
		}
	}
	$checkview_wpforms_helper = new Checkview_Wpforms_Helper();
}
