<?php
/**
 * Checkview_Formidable_Helper class
 *
 * @since 1.0.0
 *
 * @package Checkview
 * @subpackage Checkview/includes/formhelpers
 */

if ( ! defined( 'WPINC' ) ) {
	die( 'Direct access not Allowed.' );
}

if ( ! class_exists( 'Checkview_Formidable_Helper' ) ) {
	/**
	 * Adds support for Formidable.
	 *
	 * During CheckView tests, modifies Formidable hooks, overwrites the
	 * recipient email address, and handles test cleanup.
	 *
	 * @package Checkview
	 * @subpackage Checkview/includes/formhelpers
	 * @author Check View <support@checkview.io>
	 */
	class Checkview_Formidable_Helper {
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
					'frm_to_email',
					array(
						$this,
						'checkview_inject_email',
					),
					99,
					1
				);
			}

			add_filter(
				'frm_email_header',
				array(
					$this,
					'checkview_remove_email_header',
				),
				99,
				2
			);

			add_action(
				'frm_after_create_entry',
				array(
					$this,
					'checkview_log_form_test_entry',
				),
				99,
				2
			);

			add_filter(
				'frm_fields_in_form',
				array(
					$this,
					'remove_recaptcha_field_from_list',
				),
				11,
				2
			);

			add_filter(
				'akismet_get_api_key',
				'__return_null',
				-10
			);

			add_filter(
				'frm_fields_to_validate',
				array(
					$this,
					'remove_recaptcha_field_from_list',
				),
				20,
				2
			);

			add_filter(
				'cfturnstile_whitelisted',
				'__return_true',
				999
			);

			add_filter(
				'frm_run_honeypot',
				'__return_false'
			);

			// Anti-spam bypass, precise layer.
			//
			// Formidable's spam pipeline (FrmEntryValidate::spam_check) has
			// eight independent gates. Only the honeypot (above) and the
			// captcha field type (frm_fields_to_validate) were handled. Each
			// filter below is the documented switch for one gate, verified
			// against Formidable 6.33.1:
			//
			//   frm_run_antispam    FrmAntiSpam::run_antispam() — the token
			//                       check. Fails closed when the token is
			//                       missing, which happens on a stale cached
			//                       page (tokens are only valid +/- 2 days) or
			//                       when the form is rendered without its JS.
			//   frm_check_blacklist FrmSpamCheckWPDisallowedWords::is_enabled()
			//                       — the only gate that is ALWAYS on. It runs
			//                       wp_check_comment_disallowed_list() over the
			//                       entry content, the submitting IP and the
			//                       user agent whenever WordPress's own
			//                       disallowed_keys option is non-empty, which
			//                       security plugins routinely populate.
			//   frm_check_denylist  FrmSpamCheckDenylist::is_enabled() — the
			//                       Formidable denylist (words, emails, IPs).
			//
			// StopForumSpam and the WP-comment-spam check have no enable
			// filter, and Formidable's Akismet path reads
			// get_option( 'wordpress_api_key' ) directly rather than going
			// through `akismet_get_api_key` above — so neither is reachable
			// from here. Those are caught by the frm_validate_entry backstop
			// instead.
			add_filter( 'frm_run_antispam', '__return_false', PHP_INT_MAX );
			add_filter( 'frm_check_blacklist', '__return_false', PHP_INT_MAX );
			add_filter( 'frm_check_denylist', '__return_false', PHP_INT_MAX );

			// Bypass hCaptcha. Formidable's own hCaptcha support is the
			// `captcha` field type already stripped by
			// remove_recaptcha_field_from_list(), but the standalone
			// "hCaptcha for WordPress" plugin ships its own Formidable
			// integration that operates outside that field type. Matches the
			// GF, WPForms, CF7, Fluent, Everest and Elementor helpers.
			add_filter( 'hcap_activate', '__return_false' );

			// Third-party anti-spam plugins that attach their failure to a key
			// other than `spam`, which the frm_validate_entry backstop below
			// cannot clear. Registered on `init` at PHP_INT_MAX because WP Armour
			// includes its integrations from an `init` callback at the DEFAULT
			// priority (wp-armour.php:17-24), i.e. the same priority this helper
			// is loaded at — so ordering between the two would otherwise depend
			// on registration order.
			add_action(
				'init',
				array(
					$this,
					'checkview_unhook_third_party_spam_filters',
				),
				PHP_INT_MAX
			);

			// Anti-spam bypass, backstop layer.
			//
			// Single choke point: frm_validate_entry fires AFTER spam_check()
			// and its return value replaces the error array
			// (FrmEntryValidate::validate). Clearing $errors['spam'] here
			// therefore neutralises every gate at once, including the three
			// that have no filter of their own. Runs at PHP_INT_MAX so it lands
			// after any third-party callback that might add a spam verdict.
			//
			// Both layers are kept deliberately. The filters above are precise
			// but fail silently if Formidable renames one; this backstop still
			// catches the resulting error. Same belt-and-braces shape as the GF
			// helper's explicit reCAPTCHA unhook plus its marker fallback.
			add_filter(
				'frm_validate_entry',
				array(
					$this,
					'checkview_bypass_spam_validation',
				),
				PHP_INT_MAX,
				1
			);

			// Disbale form action.
			add_filter(
				'frm_custom_trigger_action',
				array(
					$this,
					'checkview_disable_form_actions',
				),
				99,
				5
			);
		}
		/**
		 * Sets our email for test submissions.
		 *
		 * @param string $email Email address.
		 * @return string Email.
		 */
		public function checkview_inject_email( $email ) {
			$cv_test_id = get_checkview_test_id();
			if ( ! $cv_test_id || 'true' != get_option( 'disable_email_receipt_' . $cv_test_id, false ) ) {
				$email = TEST_EMAIL;
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
		 * @param array $atts attributes.
		 * @return array
		 */
		public function checkview_remove_email_header( array $headers, array $atts ): array {
			$cv_test_id = get_checkview_test_id();
			if ( $cv_test_id && 'true' == get_option( 'disable_email_receipt_' . $cv_test_id, false ) ) {
				return $headers;
			}
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
		 * Deletes test submission from Formidable database table.
		 *
		 * @param int $entry_id Form's ID.
		 * @param int $form_id Form entry ID.
		 * @return void
		 */
		public function checkview_log_form_test_entry( $entry_id, $form_id ) {
			global $wpdb;

			Checkview_Admin_Logs::add( 'ip-logs', 'Cloning submission entry [' . $entry_id . ']...' );

			$checkview_test_id = get_checkview_test_id();

			if ( empty( $checkview_test_id ) ) {
				$checkview_test_id = $form_id . gmdate( 'Ymd' );
			}

			// Insert entry.
			$entry_data = array(
				'form_id' => $form_id,
				'status' => 'publish',
				'source_url' => isset( $_SERVER['HTTP_REFERER'] ) ? substr( sanitize_url( wp_unslash( $_SERVER['HTTP_REFERER'] ) ), 0, 200 ) : '',
				'date_created' => current_time( 'mysql' ),
				'date_updated' => current_time( 'mysql' ),
				'uid' => $checkview_test_id,
				'form_type' => 'Formidable',
			);
			$entry_table = $wpdb->prefix . 'cv_entry';

			$result  = $wpdb->insert( $entry_table, $entry_data );

			if ( ! $result ) {
				Checkview_Admin_Logs::add( 'ip-logs', 'Failed to clone submission entry data. wpdb->last_error=[' . $wpdb->last_error . ']' );
			} else {
				Checkview_Admin_Logs::add( 'ip-logs', 'Cloned submission entry data (inserted ' . (int) $result . ' rows into ' . $entry_table . ').' );
			}

			// Skip meta loop when parent insert failed: $wpdb->insert_id
			// is 0, meta rows would be orphaned with entry_id=0.
			// Formidable entry delete and complete_checkview_test() below still run.
			if ( $result ) {
				// Insert entry meta.
				$entry_meta_table = $wpdb->prefix . 'cv_entry_meta';
				$fields = $this->get_form_fields( $form_id );

				if ( empty( $fields ) ) {
					return;
				}

				$tablename = $wpdb->prefix . 'frm_item_metas';
				$form_fields = $wpdb->get_results( $wpdb->prepare( 'Select * from ' . $tablename . ' where item_id=%d', $entry_id ) );
				$count = 0;

				foreach ( $form_fields as $field ) {
					if ( empty( $field->field_id ) ) {
						continue;
					}

					// Skip fields not in the form definition (e.g., deleted
					// fields or repeater child fields from a different form).
					if ( ! isset( $fields[ $field->field_id ] ) ) {
						continue;
					}

					if ( 'name' === $fields[ $field->field_id ]['type'] ) {
						$field_values = maybe_unserialize( $field->meta_value );

						// Handle non-array values (corrupted data, plain
						// string, or failed unserialize).
						if ( ! is_array( $field_values ) ) {
							$field_values = array(
								'first'  => is_string( $field_values ) ? $field_values : '',
								'middle' => '',
								'last'   => '',
							);
						}

						// Safe key extraction — Formidable's array_filter
						// strips empty sub-keys before serializing.
						$first  = isset( $field_values['first'] ) ? $field_values['first'] : '';
						$middle = isset( $field_values['middle'] ) ? $field_values['middle'] : '';
						$last   = isset( $field_values['last'] ) ? $field_values['last'] : '';

						$name_format = $fields[ $field->field_id ]['name_layout'];
						$sub_fields  = isset( $fields[ $field->field_id ]['sub_fields'] ) ? $fields[ $field->field_id ]['sub_fields'] : array();

						switch ( $name_format ) {
							case 'first_middle_last':
								if ( count( $sub_fields ) < 3 ) {
									Checkview_Admin_Logs::add( 'ip-logs', 'Expected 3 sub_fields for first_middle_last, got ' . count( $sub_fields ) . ' for field ' . $field->field_id );
									break;
								}

								// First.
								$entry_metadata = array(
									'uid' => $checkview_test_id,
									'form_id' => $form_id,
									'entry_id' => $entry_id,
									'meta_key' => $sub_fields[0]['field_id'],
									'meta_value' => $first,
								);

								$result = $wpdb->insert( $entry_meta_table, $entry_metadata );

								if ( $result ) {
									$count++;
								}

								// Middle.
								$entry_metadata = array(
									'uid' => $checkview_test_id,
									'form_id' => $form_id,
									'entry_id' => $entry_id,
									'meta_key' => $sub_fields[1]['field_id'],
									'meta_value' => $middle,
								);

								$result = $wpdb->insert( $entry_meta_table, $entry_metadata );

								if ( $result ) {
									$count++;
								}

								// Last.
								$entry_metadata = array(
									'uid' => $checkview_test_id,
									'form_id' => $form_id,
									'entry_id' => $entry_id,
									'meta_key' => $sub_fields[2]['field_id'],
									'meta_value' => $last,
								);

								$result = $wpdb->insert( $entry_meta_table, $entry_metadata );

								if ( $result ) {
									$count++;
								}

								break;
							case 'first_last':
								if ( count( $sub_fields ) < 2 ) {
									Checkview_Admin_Logs::add( 'ip-logs', 'Expected 2 sub_fields for first_last, got ' . count( $sub_fields ) . ' for field ' . $field->field_id );
									break;
								}

								// First.
								$entry_metadata = array(
									'uid' => $checkview_test_id,
									'form_id' => $form_id,
									'entry_id' => $entry_id,
									'meta_key' => $sub_fields[0]['field_id'],
									'meta_value' => $first,
								);

								$result = $wpdb->insert( $entry_meta_table, $entry_metadata );

								if ( $result ) {
									$count++;
								}

								// Last.
								$entry_metadata = array(
									'uid' => $checkview_test_id,
									'form_id' => $form_id,
									'entry_id' => $entry_id,
									'meta_key' => $sub_fields[1]['field_id'],
									'meta_value' => $last,
								);

								$result = $wpdb->insert( $entry_meta_table, $entry_metadata );

								if ( $result ) {
									$count++;
								}

								break;
							case 'last_first':
								if ( count( $sub_fields ) < 2 ) {
									Checkview_Admin_Logs::add( 'ip-logs', 'Expected 2 sub_fields for last_first, got ' . count( $sub_fields ) . ' for field ' . $field->field_id );
									break;
								}

								// First.
								$entry_metadata = array(
									'uid' => $checkview_test_id,
									'form_id' => $form_id,
									'entry_id' => $entry_id,
									'meta_key' => $sub_fields[1]['field_id'],
									'meta_value' => $first,
								);

								$result = $wpdb->insert( $entry_meta_table, $entry_metadata );

								if ( $result ) {
									$count++;
								}

								// Last.
								$entry_metadata = array(
									'uid' => $checkview_test_id,
									'form_id' => $form_id,
									'entry_id' => $entry_id,
									'meta_key' => $sub_fields[0]['field_id'],
									'meta_value' => $last,
								);

								$result = $wpdb->insert( $entry_meta_table, $entry_metadata );

								if ( $result ) {
									$count++;
								}

								break;
							default:
								Checkview_Admin_Logs::add( 'ip-logs', 'Unknown name layout: ' . ( $name_format ?? 'null' ) );
								break;
						}
					} else {
						$field_value = $field->meta_value;
						$entry_metadata = array(
							'uid' => $checkview_test_id,
							'form_id' => $form_id,
							'entry_id' => $entry_id,
							'meta_key' => $fields[ $field->field_id ]['field_id'],
							'meta_value' => $field_value,
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
					if ( count( $form_fields ) > 0 ) {
						Checkview_Admin_Logs::add( 'ip-logs', 'Failed to clone submission entry meta data. wpdb->last_error=[' . $wpdb->last_error . ']' );
					}
				}
			}

			// Remove test entry form Formidable.
			$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . $wpdb->prefix . 'frm_item_metas WHERE item_id=%d', $entry_id ) );
			$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . $wpdb->prefix . 'frm_items WHERE id=%d', $entry_id ) );

			complete_checkview_test( $checkview_test_id );
		}

		/**
		 * Retrieves form fields for a form.
		 *
		 * @param int $form_id ID of the form.
		 * @return array
		 */
		public function get_form_fields( $form_id ) {
			global $wpdb;

			$fields      = array();
			$tablename   = $wpdb->prefix . 'frm_fields';
			$fields_data = $wpdb->get_results( $wpdb->prepare( 'Select * from ' . $tablename . ' where form_id=%d', $form_id ) );
			if ( ! empty( $fields_data ) && is_array( $fields_data ) ) {
				foreach ( $fields_data as $field ) {
					$type     = $field->type;
					$field_id = 'field_' . $field->field_key;
					switch ( $type ) {
						case 'name':
							$field_options        = maybe_unserialize( $field->field_options );
							$name_format          = ( is_array( $field_options ) && isset( $field_options['name_layout'] ) )
								? $field_options['name_layout']
								: 'first_last';
							$fields[ $field->id ] = array(
								'type'        => $field->type,
								'key'         => $field->field_key,
								'id'          => $field->id,
								'formId'      => $form_id,
								'Name'        => $field->name,
								'label'       => $field->name,
								'name_layout' => $name_format,
							);
							$index                = $field->id;

							if ( 'first_last' === $name_format ) {
								$fields[ $index ]['sub_fields'][0]['type']     = 'text';
								$fields[ $index ]['sub_fields'][0]['name']     = 'First Name';
								$fields[ $index ]['sub_fields'][0]['field_id'] = $field_id . '_first';
								$fields[ $index ]['sub_fields'][1]['type']     = 'text';
								$fields[ $index ]['sub_fields'][1]['name']     = 'Last Name';
								$fields[ $index ]['sub_fields'][1]['field_id'] = $field_id . '_last';
							}

							if ( 'last_first' === $name_format ) {
								$fields[ $index ]['sub_fields'][0]['type']     = 'text';
								$fields[ $index ]['sub_fields'][0]['name']     = 'Last Name';
								$fields[ $index ]['sub_fields'][0]['field_id'] = $field_id . '_last';
								$fields[ $index ]['sub_fields'][1]['type']     = 'text';
								$fields[ $index ]['sub_fields'][1]['name']     = 'First Name';
								$fields[ $index ]['sub_fields'][1]['field_id'] = $field_id . '_first';
							}

							if ( 'first_middle_last' === $name_format ) {
								$fields[ $index ]['sub_fields'][0]['type']     = 'text';
								$fields[ $index ]['sub_fields'][0]['name']     = 'First Name';
								$fields[ $index ]['sub_fields'][0]['field_id'] = $field_id . '_first';
								$fields[ $index ]['sub_fields'][1]['type']     = 'text';
								$fields[ $index ]['sub_fields'][1]['name']     = 'Middle Name';
								$fields[ $index ]['sub_fields'][1]['field_id'] = $field_id . '_middle';
								$fields[ $index ]['sub_fields'][2]['type']     = 'text';
								$fields[ $index ]['sub_fields'][2]['name']     = 'Last Name';
								$fields[ $index ]['sub_fields'][2]['field_id'] = $field_id . '_last';
							}

							break;
						case 'radio':
							$field_options = maybe_unserialize( $field->options );
							if ( is_array( $field_options ) ) {
								foreach ( $field_options as $key => $val ) {
									if ( is_array( $val ) ) {
										$field_options[ $key ]['field_id'] = $field_id . '-' . $key;
									} else {
										error_log( "Non-array value detected in field_options for field '{$field_id}', key '{$key}': " . print_r( $val, true ) );
									}
								}
							}
							$fields[ $field->id ] = array(
								'type'     => $field->type,
								'key'      => $field->field_key,
								'id'       => $field->id,
								'formId'   => $form_id,
								'Name'     => $field->name,
								'label'    => $field->name,
								'choices'  => $field_options,
								'field_id' => $field_id,
							);
							break;
						case 'checkbox':
							$field_options = maybe_unserialize( $field->options );
							if ( is_array( $field_options ) ) {
								foreach ( $field_options as $key => $val ) {
									if ( is_array( $val ) ) {
										$field_options[ $key ]['field_id'] = $field_id . '-' . $key;
									} else {
										error_log( "Non-array value detected in field_options for field '{$field_id}', key '{$key}': " . print_r( $val, true ) );
									}
								}
							}
							$fields[ $field->id ] = array(
								'type'     => $field->type,
								'key'      => $field->field_key,
								'id'       => $field->id,
								'formId'   => $form_id,
								'Name'     => $field->name,
								'label'    => $field->name,
								'choices'  => $field_options,
								'field_id' => $field_id,
							);

							break;
						default:
							$fields[ $field->id ] = array(
								'type'       => $field->type,
								'key'        => $field->field_key,
								'id'         => $field->id,
								'formId'     => $form_id,
								'Name'       => $field->name,
								'label'      => $field->name,
								'field_name' => $field_id,
								'field_id'   => $field_id,
							);
							break;
					}
				}
			}
			return $fields;
		}
		/**
		 * Removes third-party anti-spam callbacks that reject a Formidable
		 * submission through an error key the spam backstop cannot clear.
		 *
		 * `checkview_bypass_spam_validation()` unsets only `$errors['spam']`,
		 * which is correct for Formidable core — core writes field failures as
		 * `field<id>` and nonce/permission failures as `form`, and only
		 * `spam_check()` writes `spam`. Third-party plugins are not bound by that
		 * convention.
		 *
		 * WP Armour is the known case. Its Formidable integration hooks
		 * `frm_validate_entry` at priority 10 and writes `$errors['my_error']`
		 * (honeypot/includes/integration/wpa_formidable.php:6-14), so the backstop
		 * runs after it, sees the key and leaves it — the submission still fails.
		 *
		 * It is also likely to fire rather than unlikely: `wpa_check_is_spam()`
		 * (honeypot/includes/wpa_functions.php:136-152) is fails-closed. It
		 * returns "not spam" only when the POST carries WP Armour's JS-injected
		 * `alt_s` and named field, and treats anything else as spam. So the usual
		 * "a headless browser will not fill a hidden honeypot, therefore it
		 * passes" reasoning is inverted here and does not apply.
		 *
		 * Degrades to a logged no-op: nothing removed and no such function means
		 * the plugin is absent; the function existing but not removable means it
		 * moved priority or was renamed, which is worth knowing about.
		 *
		 * @since 2.3.1
		 *
		 * @return void
		 */
		public function checkview_unhook_third_party_spam_filters() {
			if ( remove_filter( 'frm_validate_entry', 'wpa_formidable_extra_validation', 10 ) ) {
				Checkview_Admin_Logs::add( 'ip-logs', 'Unhooked WP Armour from frm_validate_entry for this CheckView test.' );
				return;
			}

			if ( function_exists( 'wpa_formidable_extra_validation' ) ) {
				Checkview_Admin_Logs::add( 'ip-logs', 'WP Armour detected but its frm_validate_entry callback was not removable (priority changed or renamed?) — Formidable submissions may still be rejected as spam.' );
			}
		}

		/**
		 * Clears Formidable's spam verdict during a CheckView test.
		 *
		 * `frm_validate_entry` is applied after `FrmEntryValidate::spam_check()`
		 * and its return value replaces the error array, so this is the one
		 * place that catches every spam gate — including StopForumSpam, the
		 * WP-comment-spam check and Akismet, none of which expose a filter this
		 * helper can switch off directly.
		 *
		 * Scoping is provable rather than best-effort. Formidable keys genuine
		 * field failures as `field<id>` (and `field<id>-<name>` for sub-fields),
		 * nonce/permission failures as `form`, and uses `message`, `trash` and
		 * `json` elsewhere. `spam` is written only by `spam_check()`. Removing
		 * that single key therefore cannot suppress a real validation error, so
		 * a test still fails honestly when the form genuinely rejects the
		 * submission — the same principle as
		 * Checkview_Gforms_Helper::checkview_bypass_captcha_validation(), which
		 * deliberately keeps non-anti-bot failures.
		 *
		 * Only ever registered inside a verified test session: the helper is
		 * loaded from checkview_init_current_test(), which requires is_bot().
		 *
		 * @param array $errors Validation errors keyed by field or reason.
		 * @return array Errors with any spam verdict removed.
		 */
		public function checkview_bypass_spam_validation( $errors ) {
			if ( ! is_array( $errors ) || ! isset( $errors['spam'] ) ) {
				return $errors;
			}

			$message = is_string( $errors['spam'] ) ? $errors['spam'] : '';
			unset( $errors['spam'] );

			// Log the remaining keys so a genuine failure alongside the spam
			// verdict stays visible in support logs rather than looking like a
			// clean pass.
			Checkview_Admin_Logs::add(
				'ip-logs',
				'Cleared Formidable spam verdict during CheckView test. Message was [' . substr( $message, 0, 200 ) . ']. Remaining validation errors: [' . ( empty( $errors ) ? 'none' : implode( ', ', array_keys( $errors ) ) ) . '].'
			);

			return $errors;
		}

		/**
		 * Removes ReCAPTCHA field from form fields and form validation.
		 *
		 * @param array $fields Array of fields.
		 * @param array $form Form.
		 */
		public function remove_recaptcha_field_from_list( $fields, $form ) {

			foreach ( $fields as $key => $field ) {
				if ( 'recaptcha' === FrmField::get_field_type( $field ) || 'captcha' === FrmField::get_field_type( $field ) || 'hcaptcha' === FrmField::get_field_type( $field ) || 'turnstile' === FrmField::get_field_type( $field ) ) {
					unset( $fields[ $key ] );
				}
			}
			return $fields;
		}

		/**
		 * Allows custom form action trigger.
		 *
		 * @since 6.10
		 *
		 * @param bool   $skip   Skip default trigger.
		 * @param object $action Action object.
		 * @param object $entry  Entry object.
		 * @param object $form   Form object.
		 * @param string $event  Event ('create' or 'update').
		 */
		function checkview_disable_form_actions( $skip, $action, $entry, $form, $event ) {
			// Keys to keep.
			$keys_to_keep = array( 'email', 'register', 'on_submit' );
			if ( in_array( $action->post_excerpt, $keys_to_keep, true ) ) {
				return false;
			}
			$cv_test_id = get_checkview_test_id();
			if ( $cv_test_id && 'true' == get_option( 'disable_actions_' . $cv_test_id, false ) ) {
				Checkview_Admin_Logs::add(
					'ip-logs',
					'Disabled Formidable action type [' . ( $action->post_excerpt ?? 'unknown' ) . '] for CheckView test.'
				);
				return true;
			}
			return false;
		}
	}

	$checkview_formidable_helper = new Checkview_Formidable_Helper();
}
