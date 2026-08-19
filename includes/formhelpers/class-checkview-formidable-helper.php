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

			// Accepts 3 args: Formidable passes compact( 'is_child' ) as the
			// third (FrmEntry::after_entry_created_actions), which the handler
			// needs to skip repeater/embedded-form child entries.
			add_action(
				'frm_after_create_entry',
				array(
					$this,
					'checkview_log_form_test_entry',
				),
				99,
				3
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
		public function checkview_log_form_test_entry( $entry_id, $form_id, $args = array() ) {
			global $wpdb;

			$entry_id = (int) $entry_id;
			$form_id  = (int) $form_id;

			// Refuse a non-positive entry id before anything else touches the
			// database. This is not defensive tidiness — the child-entry cleanup
			// below queries `WHERE parent_item_id = %d`, and Formidable stores 0
			// in that column for every TOP-LEVEL entry
			// (FrmMigrate.php:234: `parent_item_id BIGINT(20) default 0`). So an
			// entry_id of 0 would select the customer's entire entry table and
			// then delete it.
			//
			// Formidable core cannot pass 0 here — the action fires with a real
			// insert id — but the previous code was a harmless no-op in that case
			// and the child cleanup made it destructive, so the invariant is now
			// enforced rather than assumed.
			if ( $entry_id <= 0 ) {
				Checkview_Admin_Logs::add( 'ip-logs', 'Formidable submission hook fired with a non-positive entry id [' . $entry_id . ']; refusing to touch the database.' );
				return;
			}

			// Child entries: `frm_after_create_entry` also fires for repeater
			// and embedded-form child entries, passing compact( 'is_child' )
			// (FrmEntry::after_entry_created_actions). Without this guard a form
			// with a repeater cloned one cv_entry row per child AND called
			// complete_checkview_test() on the first of them — tearing the
			// session down before the parent entry was ever cloned, which
			// surfaces to the SaaS as submission-not-found.
			if ( ! empty( $args['is_child'] ) ) {
				Checkview_Admin_Logs::add( 'ip-logs', 'Skipping Formidable child entry [' . $entry_id . '] (repeater/embedded form); the parent entry is cloned separately.' );
				return;
			}

			// Draft entries: Formidable Pro's "Save Draft" creates a real entry
			// with is_draft=1 (FrmEntry::get_is_draft_value via the
			// frm_saving_draft post value) and this action fires for it. Cloning
			// and completing on a draft ends the test session while the visitor
			// is still partway through a multi-page form.
			$is_draft = (int) $wpdb->get_var(
				$wpdb->prepare( 'SELECT is_draft FROM ' . $wpdb->prefix . 'frm_items WHERE id=%d', $entry_id )
			);
			if ( 1 === $is_draft ) {
				Checkview_Admin_Logs::add( 'ip-logs', 'Skipping Formidable draft entry [' . $entry_id . ']; waiting for the final submission.' );
				return;
			}

			// Idempotence: guards against this handler running twice for the
			// same entry within one request (a second helper instance, or a
			// plugin re-firing the action). A repeat would insert a duplicate
			// cv_entry row and call complete_checkview_test() again after the
			// session was already torn down. Mirrors the guard in
			// Checkview_Fluent_Forms_Helper::checkview_clone_fluentform_entry().
			static $processed = array();
			$processed_key    = $entry_id . '_' . $form_id;
			if ( isset( $processed[ $processed_key ] ) ) {
				Checkview_Admin_Logs::add( 'ip-logs', 'Formidable entry [' . $entry_id . '] already cloned in this request; skipping duplicate.' );
				return;
			}
			$processed[ $processed_key ] = true;

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

				// Previously `return`ed here. That exited the whole method,
				// skipping BOTH the Formidable entry delete and
				// complete_checkview_test() below — leaving the test submission
				// in the customer's database and hanging the test until it timed
				// out. It also contradicted the comment directly above, which
				// states that cleanup still runs.
				//
				// Fall through with an empty map instead: every row in the meta
				// loop below is skipped (no matching field definition), and
				// cleanup proceeds as documented.
				if ( empty( $fields ) ) {
					Checkview_Admin_Logs::add( 'ip-logs', 'No field definitions found for form [' . $form_id . ']; skipping entry meta clone but continuing cleanup.' );
					$fields = array();
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
					// `! empty( $fields )` keeps this from reporting a database
					// failure when the real cause was no field definitions —
					// that case is logged above with its actual reason and would
					// otherwise print an empty wpdb->last_error.
					if ( count( $form_fields ) > 0 && ! empty( $fields ) ) {
						Checkview_Admin_Logs::add( 'ip-logs', 'Failed to clone submission entry meta data. wpdb->last_error=[' . $wpdb->last_error . ']' );
					}
				}
			}

			// Remove test entry from Formidable.
			//
			// Child entries first. Repeater and embedded-form rows are separate
			// frm_items records linked by parent_item_id, and they are now
			// skipped by the is_child guard at the top of this method — so
			// unlike before, nothing else deletes them. Without this they would
			// accumulate in the customer's tables, one set per test run.
			//
			// Scoped strictly to children of the entry just cloned, so
			// attribution is exact: parent_item_id is set by Formidable when it
			// creates the child and is never guessed here. Metas are removed
			// before the parent rows so the id list is still resolvable.
			$child_ids = $wpdb->get_col(
				$wpdb->prepare( 'SELECT id FROM ' . $wpdb->prefix . 'frm_items WHERE parent_item_id=%d', $entry_id )
			);

			if ( ! empty( $child_ids ) ) {
				$child_ids = array_map( 'intval', $child_ids );
				// Placeholders are generated from the row count, never from a
				// value; every id is still bound through prepare(), and the ids
				// themselves came from the database as integers.
				// WPDBPREPARE.
				$placeholders = implode( ',', array_fill( 0, count( $child_ids ), '%d' ) );

				$wpdb->query(
					$wpdb->prepare(
						'DELETE FROM ' . $wpdb->prefix . 'frm_item_metas WHERE item_id IN (' . $placeholders . ')',
						$child_ids
					)
				);
				$wpdb->query(
					$wpdb->prepare( 'DELETE FROM ' . $wpdb->prefix . 'frm_items WHERE parent_item_id=%d', $entry_id )
				);

				Checkview_Admin_Logs::add( 'ip-logs', 'Removed ' . count( $child_ids ) . ' Formidable child entr' . ( 1 === count( $child_ids ) ? 'y' : 'ies' ) . ' of entry [' . $entry_id . '].' );
			}

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
