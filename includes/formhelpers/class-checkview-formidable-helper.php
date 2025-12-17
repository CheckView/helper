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
			if ( get_option( 'disable_email_receipt', false ) == false ) {
				$email = TEST_EMAIL;
			} elseif ( is_array( $email ) ) {
				$email[] = TEST_EMAIL;
			} else {
				$email .= ', ' . TEST_EMAIL;
			}
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
			if ( true == get_option( 'disable_email_receipt', false ) ) {
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
			return array_values( $filtered_headers );
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
				'source_url' => isset( $_SERVER['HTTP_REFERER'] ) ? sanitize_url( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '',
				'date_created' => current_time( 'mysql' ),
				'date_updated' => current_time( 'mysql' ),
				'uid' => $checkview_test_id,
				'form_type' => 'Formidable',
			);
			$entry_table = $wpdb->prefix . 'cv_entry';

			$result  = $wpdb->insert( $entry_table, $entry_data );

			if ( ! $result ) {
				Checkview_Admin_Logs::add( 'ip-logs', 'Failed to clone submission entry data.' );
			} else {
				Checkview_Admin_Logs::add( 'ip-logs', 'Cloned submission entry data (inserted ' . (int) $result . ' rows into ' . $entry_table . ').' );
			}

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

				if ( 'name' === $fields[ $field->field_id ]['type'] ) {
					$field_values = maybe_unserialize( $field->meta_value );
					$name_format = $fields[ $field->field_id ]['name_layout'];

					switch ( $name_format ) {
						case 'first_middle_last':
							// First.
							$entry_metadata = array(
								'uid' => $checkview_test_id,
								'form_id' => $form_id,
								'entry_id' => $entry_id,
								'meta_key' => $fields[ $field->field_id ]['sub_fields'][0]['field_id'],
								'meta_value' => $field_values['first'],
							);

							$result = $wpdb->insert( $entry_meta_table, $entry_metadata );

							if ( $result ) {
								$count++;
							}

							// middle.
							$entry_metadata = array(
								'uid' => $checkview_test_id,
								'form_id' => $form_id,
								'entry_id' => $entry_id,
								'meta_key' => $fields[ $field->field_id ]['sub_fields'][1]['field_id'],
								'meta_value' => $field_values['middle'],
							);

							$result = $wpdb->insert( $entry_meta_table, $entry_metadata );

							if ( $result ) {
								$count++;
							}

							// last.
							$entry_metadata = array(
								'uid' => $checkview_test_id,
								'form_id' => $form_id,
								'entry_id' => $entry_id,
								'meta_key' => $fields[ $field->field_id ]['sub_fields'][2]['field_id'],
								'meta_value' => $field_values['last'],
							);

							$result = $wpdb->insert( $entry_meta_table, $entry_metadata );

							if ( $result ) {
								$count++;
							}

							break;
						case 'first_last':
							// First.
							$entry_metadata = array(
								'uid' => $checkview_test_id,
								'form_id' => $form_id,
								'entry_id' => $entry_id,
								'meta_key' => $fields[ $field->field_id ]['sub_fields'][0]['field_id'],
								'meta_value' => $field_values['first'],
							);

							$result = $wpdb->insert( $entry_meta_table, $entry_metadata );

							if ( $result ) {
								$count++;
							}

							// last.
							$entry_metadata = array(
								'uid' => $checkview_test_id,
								'form_id' => $form_id,
								'entry_id' => $entry_id,
								'meta_key' => $fields[ $field->field_id ]['sub_fields'][1]['field_id'],
								'meta_value' => $field_values['last'],
							);

							$result = $wpdb->insert( $entry_meta_table, $entry_metadata );

							if ( $result ) {
								$count++;
							}

							break;
						case 'last_first':
							// First.
							$entry_metadata = array(
								'uid' => $checkview_test_id,
								'form_id' => $form_id,
								'entry_id' => $entry_id,
								'meta_key' => $fields[ $field->field_id ]['sub_fields'][1]['field_id'],
								'meta_value' => $field_values['first'],
							);

							$result = $wpdb->insert( $entry_meta_table, $entry_metadata );

							if ( $result ) {
								$count++;
							}

							// last.
							$entry_metadata = array(
								'uid' => $checkview_test_id,
								'form_id' => $form_id,
								'entry_id' => $entry_id,
								'meta_key' => $fields[ $field->field_id ]['sub_fields'][0]['field_id'],
								'meta_value' => $field_values['last'],
							);

							$result = $wpdb->insert( $entry_meta_table, $entry_metadata );

							if ( $result ) {
								$count++;
							}

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
					Checkview_Admin_Logs::add( 'ip-logs', 'Failed to clone submission entry meta data.' );
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
							$fields[ $field->id ] = array(
								'type'        => $field->type,
								'key'         => $field->field_key,
								'id'          => $field->id,
								'formId'      => $form_id,
								'Name'        => $field->name,
								'label'       => $field->name,
								'name_layout' => $field_options['name_layout'],
							);
							$name_format          = $field_options['name_layout'];
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
							foreach ( $field_options as $key => $val ) {
								$field_options[ $key ]['field_id'] = $field_id . '-' . $key;
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
							foreach ( $field_options as $key => $val ) {
								// Ensure the current value is an array.
								if ( is_array( $val ) ) {
									$field_options[ $key ]['field_id'] = $field_id . '-' . $key;
								} else {
									error_log( "Non-array value detected in field_options for key '{$field_id }': " . print_r( $val, true ) );
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
			if ( get_option( 'disable_actions', false ) ) {
				return true;
			}
			return false;
		}
	}

	$checkview_formidable_helper = new Checkview_Formidable_Helper();
}
