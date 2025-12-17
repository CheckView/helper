<?php
/**
 * Checkview_WSF_Helper class
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

if ( ! class_exists( 'Checkview_WSF_Helper' ) ) {
	/**
	 * Adds support for WS Forms.
	 *
	 * During CheckView tests, modifies WS Forms hooks, overwrites the
	 * recipient email address, and handles test cleanup.
	 *
	 * @package Checkview
	 * @subpackage Checkview/includes/formhelpers
	 * @author Check View <support@checkview.io>
	 */
	class Checkview_WSF_Helper {
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

			add_action(
				'wsf_submit_post_complete',
				array(
					$this,
					'checkview_clone_entry',
				),
				999,
				1
			);

			add_filter(
				'akismet_get_api_key',
				'__return_null',
				-10
			);

			add_filter(
				'cfturnstile_whitelisted',
				'__return_true',
				999
			);

			if ( defined( 'TEST_EMAIL' ) ) {
				add_filter(
					'wsf_action_email_to',
					array(
						$this,
						'checkview_inject_email',
					),
					99,
					4
				);
			}

			add_filter(
				'wsf_pre_render',
				array(
					$this,
					'checkview_remove_unwanted_fields',
				),
				99,
				2
			);

			add_filter(
				'wsf_config_meta_keys',
				array( $this, 'config_meta_keys' ),
				10,
				2
			);

			add_filter(
				'wsf_action_post_do',
				array( $this, 'checkview_disable_addons_feed' ),
				99,
				6
			);

			add_filter(
				'wsf_action_email_headers',
				array(
					$this,
					'checkview_remove_email_header',
				),
				99,
				4
			);
		}

		/**
		 * Hides honey pot.
		 *
		 * @param array   $meta_keys meta keys.
		 * @param integer $form_id form id.
		 * @return array
		 */
		public function config_meta_keys( $meta_keys = array(), $form_id = 0 ) {
			$meta_keys['honeypot'] = array();
			return (array) $meta_keys;
		}
		/**
		 * Injects testing email recipient.
		 *
		 * @param array  $to An array of email addresses in RFC 2822 format to send the email to.
		 * @param object $form The form object.
		 * @param string $submit The submit object.
		 * @param array  $action The action configuration.
		 * @return bool
		 */
		public function checkview_inject_email( $to, $form, $submit, $action ) {
			if ( get_option( 'disable_email_receipt', false ) == false ) {
				$to = array(
					'"CheckView" <' . TEST_EMAIL . '>',
				);
			} elseif ( is_array( $to ) ) {
				$to[] = '"CheckView" <' . TEST_EMAIL . '>';
			} else {
				$to .= ', "CheckView" <' . TEST_EMAIL . '>';
			}
			return $to;
		}
		/**
		 * Injects email to WS forms supported emails.
		 *
		 * @param array  $headers An array of email addresses in RFC 2822 format to send the email to.
		 * @param object $form The form object.
		 * @param string $submit_parse The submit object.
		 * @param array  $config The action configuration.
		 * @return bool
		 */
		public function checkview_remove_email_header( $headers, $form, $submit_parse, $config ) {
			if ( get_option( 'disable_email_receipt', false ) !== false ) {
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
					return stripos( $header, 'bcc:' ) !== 0 && stripos( $header, 'cc:' ) !== 0;
				}
			);

			return array_values( $filtered_headers );
		}
		/**
		 * Stores the test results and finishes the testing session.
		 *
		 * Deletes test submission from Formidable database table.
		 *
		 * @param array $form_data Form data.
		 * @return void
		 */
		public function checkview_clone_entry( $form_data ) {
			global $wpdb;

			$form_id  = $form_data->form_id;
			$entry_id = $form_data->id;
			$checkview_test_id = get_checkview_test_id();

			Checkview_Admin_Logs::add( 'ip-logs', 'Cloning submission entry [' . $entry_id . ']...' );

			if ( empty( $checkview_test_id ) ) {
				$checkview_test_id = $form_id . gmdate( 'Ymd' );
			}

			// Insert Entry.
			$entry_data  = array(
				'form_id' => $form_id,
				'status' => 'publish',
				'source_url' => isset( $_SERVER['HTTP_REFERER'] ) ? sanitize_url( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '',
				'date_created' => current_time( 'mysql' ),
				'date_updated' => current_time( 'mysql' ),
				'uid' => $checkview_test_id,
				'form_type' => 'WSForms',
			);
			$entry_table = $wpdb->prefix . 'cv_entry';

			$result = $wpdb->insert( $entry_table, $entry_data );

			if ( ! $result ) {
				Checkview_Admin_Logs::add( 'ip-logs', 'Failed to clone submission entry data.' );
			} else {
				Checkview_Admin_Logs::add( 'ip-logs', 'Cloned submission entry data (inserted ' . (int) $result . ' rows into ' . $entry_table . ').' );
			}

			$entry_meta_table = $wpdb->prefix . 'cv_entry_meta';
			$field_id_prefix  = 'wsf';
			$tablename = $wpdb->prefix . 'wsf_submit_meta';
			$form_fields = $wpdb->get_results( $wpdb->prepare( 'Select * from ' . $tablename . ' where parent_id=%d', $entry_id ) );
			$count = 0;

			foreach ( $form_fields as $field ) {
				if ( ! in_array( $field->meta_key, array( '_form_id', 'post_id', 'wsf_meta_key_hidden' ) ) ) {
					$entry_metadata = array(
						'uid' => $checkview_test_id,
						'form_id' => $form_id,
						'entry_id' => $entry_id,
						'meta_key' => $field_id_prefix . str_replace( '_', '-', $field->meta_key ),
						'meta_value' => $field->meta_value,
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

			cv_update_option( $checkview_test_id . '_wsf_entry_id', $entry_id );
			cv_update_option( $checkview_test_id . '_wsf_frm_id', $form_id );

			complete_checkview_test( $checkview_test_id );
		}

		/**
		 * Removes ReCAPTHCAs from the testing form.
		 *
		 * @param array $form The form object.

		 * @param bool  $preview Boolean Whether the form rendering is in preview mode.
		 * @return Object Form object.
		 */
		public function checkview_remove_unwanted_fields( $form, $preview ) {
			$fields = WS_Form_Common::get_fields_from_form( $form, true );
			// Process fields.
			foreach ( $fields as $field ) {

				if ( ! isset( $field->type ) ) {
					continue;
				}
				switch ( $field->type ) {
					case 'recaptcha':
					case 'hcaptcha':
					case 'turnstile':
						// Get keys.
						$field_key   = $field->field_key;
						$section_key = $field->section_key;
						$group_key   = $field->group_key;
						unset( $form->groups[ $group_key ]->sections[ $section_key ]->fields[ $field_key ] );
						break;
					default:
						break;
				}
			}

			return $form;
		}

		/**
		 * Disable addons feed.
		 *
		 * @param boolean $run run action or not.
		 * @param object  $form form object.
		 * @param object  $submit submit object.
		 * @param array   $action_id_filter array of filters.
		 * @param boolean $database_only save to db.
		 * @param array   $config config.
		 * @return boolean
		 */
		public function checkview_disable_addons_feed( $run, $form, $submit, $action_id_filter, $database_only, $config ): bool {
			$skip_actions = array( 'database', 'message', 'email' );
			if ( in_array( $config['id'], $skip_actions, true ) ) {
				return true;
			}
			return false;
		}
	}

	$checkview_wsf_helper = new Checkview_WSF_Helper();
}
