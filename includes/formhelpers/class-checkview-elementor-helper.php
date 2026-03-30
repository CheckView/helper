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
		 * Constructor.
		 *
		 * Initiates loader property, adds hooks.
		 */
		public function __construct() {
			$this->loader = new Checkview_Loader();

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
		}

		/**
		 * Stores the test results and finishes the testing session.
		 *
		 * @param \ElementorPro\Modules\Forms\Classes\Form_Record  $record  Form record.
		 * @param \ElementorPro\Modules\Forms\Classes\Ajax_Handler $handler Ajax handler.
		 * @return void
		 */
		public function checkview_clone_elementor_entry( $record, $handler ) {
			global $wpdb;

			$checkview_test_id = get_checkview_test_id();
			$form_id           = $record->get_form_settings( 'id' );

			if ( empty( $checkview_test_id ) ) {
				$checkview_test_id = $form_id . gmdate( 'Ymd' );
			}

			if ( ! $record || ! $handler ) {
				return;
			}

			$form_data = $record->get_formatted_data();

			$entry_data  = array(
				'form_id'      => $form_id,
				'status'       => 'publish',
				'source_url'   => isset( $_SERVER['HTTP_REFERER'] ) ? sanitize_url( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '',
				'date_created' => current_time( 'mysql' ),
				'date_updated' => current_time( 'mysql' ),
				'uid'          => $checkview_test_id,
				'form_type'    => 'Elementor',
			);
			$entry_table = $wpdb->prefix . 'cv_entry';
			$wpdb->insert( $entry_table, $entry_data );
			$inserted_entry_id = $wpdb->insert_id;

			$entry_meta_table = $wpdb->prefix . 'cv_entry_meta';

			foreach ( $form_data as $key => $val ) {
				$entry_metadata = array(
					'uid'        => $checkview_test_id,
					'form_id'    => $form_id,
					'entry_id'   => $inserted_entry_id,
					'meta_key'   => $key,
					'meta_value' => $val,
				);
				$wpdb->insert( $entry_meta_table, $entry_metadata );
			}

			complete_checkview_test( $checkview_test_id );
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

			return array_merge( $skip_types, array(
				'recaptcha',
				'recaptcha_v3',
				'honeypot',
				'hcaptcha',
				'turnstile',
			) );
		}
	}

	$checkview_elementor_helper = new Checkview_Elementor_Helper();
}
