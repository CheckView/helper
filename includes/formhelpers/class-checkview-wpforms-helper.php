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
			if ( get_option( 'disable_email_receipt', false ) == false ) {
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
				'source_url' => isset( $_SERVER['HTTP_REFERER'] ) ? sanitize_url( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '',
				'date_created' => current_time( 'mysql' ),
				'date_updated' => current_time( 'mysql' ),
				'uid' => $checkview_test_id,
				'form_type' => 'WpForms',
			);
			$entry_table = $wpdb->prefix . 'cv_entry';

			$result = $wpdb->insert( $entry_table, $entry_data );

			if ( ! $result ) {
				Checkview_Admin_Logs::add( 'ip-logs', 'Failed to clone submission entry data.' );
			} else {
				Checkview_Admin_Logs::add( 'ip-logs', 'Cloned submission entry data (inserted ' . (int) $result . ' rows into ' . $entry_table . ').' );
			}

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
						if ( '' === $field['middle'] && '' === $field['last'] ) {
							$entry_metadata = array(
								'uid'        => $checkview_test_id,
								'form_id'    => $form_id,
								'entry_id'   => $inserted_entry_id,
								'meta_key'   => $field_id_prefix . $field['id'],
								'meta_value' => $field['first'],
							);

							$result = $wpdb->insert( $entry_meta_table, $entry_metadata );

							if ( $result ) {
								$count++;
							}
						} elseif ( '' === $field['middle'] ) {
							$entry_metadata = array(
								'uid'        => $checkview_test_id,
								'form_id'    => $form_id,
								'entry_id'   => $inserted_entry_id,
								'meta_key'   => $field_id_prefix . $field['id'],
								'meta_value' => $field['first'],
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
								'meta_value' => $field['last'],
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
								'meta_value' => $field['first'],
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
								'meta_value' => $field['middle'],
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
								'meta_value' => $field['last'],
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
					Checkview_Admin_Logs::add( 'ip-logs', 'Failed to clone submission entry meta data.' );
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
