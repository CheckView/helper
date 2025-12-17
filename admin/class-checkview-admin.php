<?php
/**
 * Checkview_Admin class
 *
 * @since 1.0.0
 *
 * @package Checkview
 * @subpackage Checkview/admin
 */

use AIOWPS\Firewall\Allow_List;
use WP_Defender\Component\IP\Global_IP;
/**
 * Handles various admin area features.
 *
 * Initializes tests, enqueues scripts and styles, schedules nonce cleanup.
 *
 * @package Checkview
 * @subpackage Checkview/admin
 * @author Check View <support@checkview.io>
 */
class Checkview_Admin {

	/**
	 * Plugin identifier.
	 *
	 * @since 1.0.0
	 * @access private
	 *
	 * @var string $plugin_name The ID of this plugin.
	 */
	private $plugin_name;

	/**
	 * Plugin version.
	 *
	 * @since 1.0.0
	 * @access private
	 *
	 * @var string $version The current version of this plugin.
	 */
	private $version;

	/**
	 * Constructor.
	 *
	 * Sets class properties and adds cron cleanup hooks.
	 *
	 * @since 1.0.0
	 *
	 * @param string $plugin_name The name of this plugin.
	 * @param string $version The version of this plugin.
	 */
	public function __construct( $plugin_name, $version ) {

		$this->plugin_name = $plugin_name;
		$this->version     = $version;
		add_action(
			'wp',
			array( $this, 'checkview_schedule_nonce_cleanup' )
		);

		add_action(
			'checkview_nonce_cleanup_cron',
			array( $this, 'checkview_delete_expired_nonces' )
		);

		add_filter(
			'all_plugins',
			array( $this, 'checkview_hide_me' )
		);

		add_filter(
			'debug_information',
			array(
				$this,
				'checkview_handle_plugin_health_info',
			),
			10,
			1
		);
		add_filter(
			'plugin_row_meta',
			array( $this, 'checkview_hide_plugin_details' ),
			10,
			2
		);
	}

	/**
	 * Removes expired nonces from the database.
	 *
	 * @return void
	 */
	public function checkview_delete_expired_nonces() {
		global $wpdb;
		$table_name = $wpdb->prefix . 'cv_used_nonces';

		// Define the expiration period (e.g., 24 hours).
		$expiration = gmdate( 'Y-m-d H:i:s', strtotime( '-24 hours' ) );

		// Delete nonces older than the expiration time.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM $table_name WHERE used_at < %s",
				$expiration
			)
		);
	}

	/**
	 * Schedules nonce clean-up on an hourly basis.
	 *
	 * @return void
	 */
	public function checkview_schedule_nonce_cleanup() {
		if ( ! wp_next_scheduled( 'checkview_nonce_cleanup_cron' ) ) {
			wp_schedule_event( time(), 'hourly', 'checkview_nonce_cleanup_cron' );
		}
	}
	/**
	 * Enqueues styles for the admin area.
	 *
	 * @since 1.0.0
	 */
	public function enqueue_styles() {
		// Don't enqueue styles for other admin screens.
		$screen = get_current_screen();
		if ( 'checkview-options' !== $screen->base && 'settings_page_checkview-options' !== $screen->base ) {
			return;
		}
		wp_enqueue_style(
			$this->plugin_name,
			CHECKVIEW_ADMIN_ASSETS . 'css/checkview-admin.css',
			array(),
			$this->version,
			'all'
		);

		wp_enqueue_style(
			$this->plugin_name . 'external',
			'https://cdn.jsdelivr.net/npm/bootstrap@3.3.7/dist/css/bootstrap.min.css',
			array(),
			$this->version,
			'all'
		);

		wp_enqueue_style(
			$this->plugin_name . '-swal',
			CHECKVIEW_ADMIN_ASSETS . 'css/checkview-swal2.css',
			array(),
			$this->version,
			'all'
		);
	}

	/**
	 * Enqueues scripts for the admin area.
	 *
	 * @since 1.0.0
	 */
	public function enqueue_scripts() {
		// Don't enqueue scripts for other admin screens.
		$screen = get_current_screen();
		if ( 'checkview-options' !== $screen->base && 'settings_page_checkview-options' !== $screen->base ) {
			return;
		}
		wp_enqueue_script(
			$this->plugin_name,
			CHECKVIEW_ADMIN_ASSETS . 'js/checkview-admin.js',
			array( 'jquery' ),
			$this->version,
			false
		);

		wp_enqueue_script(
			'checkview-sweetalert2.js',
			'https://cdn.jsdelivr.net/npm/sweetalert2@9',
			array( 'jquery' ),
			$this->version,
			true
		);
		if ( isset( $_GET['tab'] ) ) {
			$tab = sanitize_text_field( wp_unslash( $_GET['tab'] ) );
		} else {
			$tab = '';
		}

		$user_id = get_current_user_id();
		wp_localize_script(
			$this->plugin_name,
			'checkview_ajax_obj',
			array(
				'ajaxurl'                         => admin_url( 'admin-ajax.php' ),
				'user_id'                         => $user_id,
				'blog_id'                         => get_current_blog_id(),
				'tab'                             => $tab,
				'checkview_create_token_security' => wp_create_nonce( 'create-token-' . $user_id ),
			)
		);
	}

	/**
	 * Initializes a test.
	 *
	 * Should only be called if the request is a verified CheckView bot.
	 *
	 * @return void
	 */
	public function checkview_init_current_test() {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			include_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$is_helper_api_request = isset( $_SERVER['REQUEST_URI'] ) && strpos( $_SERVER['REQUEST_URI'], '_checkview_timestamp' );

		if ( $is_helper_api_request ) {
			return;
		}

		$visitor_ip = checkview_get_visitor_ip();

		Checkview_Admin_Logs::add( 'ip-logs', 'Visitor IP [' . $visitor_ip . '] determined to be in bot IP list, continuing with test code.' );

		if ( is_plugin_active( 'cleantalk-spam-protect/cleantalk.php' ) ) {
			checkview_whitelist_api_ip();
		}

		if ( is_plugin_active( 'wordfence/wordfence.php' ) ) {
			wordfence::whitelistIP( $visitor_ip );
		}

		if ( is_plugin_active( 'all-in-one-wp-security-and-firewall/wp-security.php' ) ) {
			$allowlist                  = Allow_List::get_ips();
			$aiowps_firewall_allow_list = AIOS_Firewall_Resource::request( AIOS_Firewall_Resource::ALLOW_LIST );

			if ( ! empty( $allowlist ) ) {
				$allowlist .= "\n" . $visitor_ip;
			} else {
				$allowlist = $visitor_ip;
			}

			$ips = sanitize_textarea_field( wp_unslash( $allowlist ) );
			$ips = AIOWPSecurity_Utility_IP::create_ip_list_array_from_string_with_newline( $ips );
			$validated_ip_list_array = AIOWPSecurity_Utility_IP::validate_ip_list( $ips, 'firewall_allowlist' );

			if ( is_wp_error( $validated_ip_list_array ) ) {
				$success = false;
				$message = nl2br( $validated_ip_list_array->get_error_message() );

				Checkview_Admin_Logs::add( 'ip-logs', 'Error ' . $message );
			} else {
				$aiowps_firewall_allow_list::add_ips( $validated_ip_list_array );
			}
		}

		if ( is_plugin_active( 'defender-security/wp-defender.php' ) ) {
			$data = array();
			$data['allow_list'] = (array) $visitor_ip;
			$data['block_list'] = array();
			$data['last_update_time'] = '';
			$data['last_update_time_utc'] = '';

			$global_ip_component = wd_di()->get( Global_IP::class );
			$result = $global_ip_component->set_global_ip_list( $data );
		}

		if ( is_plugin_active( 'enhanced-cloudflare-turnstile/enhanced-cloudflare-turnstile.php' ) ) {
			$ect_ip_address = ecft_get_option( 'ecft_ip_address' );
			$ect_ip_address_array = explode( "\n", $ect_ip_address );

			if ( ! empty( $ect_ip_address_array ) && is_array( $ect_ip_address_array ) ) {
				if ( ! in_array( $visitor_ip, $ect_ip_address_array ) ) {
					$ect_ip_address .= "\n" . $visitor_ip;

					cv_update_option( 'ecft_ip_address', $ect_ip_address );
				}
			} else {
				$ect_ip_address = $visitor_ip;

				cv_update_option( 'ecft_ip_address', $ect_ip_address );
			}
		}

		if ( is_plugin_active( 'koala-google-recaptcha-for-woocommerce/class-main-ka-add-recaptcha.php' ) ) {
			// Get the existing whitelist from the database.
			$captcha_ip_range_opt = get_option( 'captcha_ip_range_opt', '' );

			// Convert to array (IPs stored as comma-separated values with dots replaced by commas).
			$captcha_ip_range = array_filter( explode( ',', $captcha_ip_range_opt ) );

			// Check if the IP is already in the list.
			if ( ! in_array( $visitor_ip, $captcha_ip_range ) ) {
				// Add new IP to the list.
				$captcha_ip_range[] = $visitor_ip;

				// Save the updated list back to the database.
				cv_update_option( 'captcha_ip_range_opt', implode( ',', $captcha_ip_range ) );
			}
		}

		if ( is_plugin_active( 'recaptcha-for-woocommerce/woo-recaptcha.php' ) ) {
			$captcha_ip_range = '';
			$captcha_ip_range_opt = '';
			$captcha_ip_range = array();

			// Get the existing whitelist from the database.
			$captcha_ip_range_opt = sanitize_text_field( wp_unslash( get_option( 'i13_recapcha_ip_to_skip_captcha' ) ) );

			// Convert to array (IPs stored as comma-separated values with dots replaced by commas).
			$captcha_ip_range = array_filter( explode( ',', $captcha_ip_range_opt ) );

			// Check if the IP is already in the list.
			if ( ! in_array( $visitor_ip, $captcha_ip_range ) ) {
				// Add new IP to the list.
				$captcha_ip_range[] = $visitor_ip;
				if ( count( $captcha_ip_range ) > 1 ) {
					// Save the updated list back to the database.
					cv_update_option( 'i13_recapcha_ip_to_skip_captcha', implode( ',', $captcha_ip_range ) );
				} else {
					cv_update_option( 'i13_recapcha_ip_to_skip_captcha',  $visitor_ip );
				}
			}
		}

		$cv_test_id = isset( $_REQUEST['checkview_test_id'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['checkview_test_id'] ) ) : '';
		$disable_email_receipt = isset( $_REQUEST['disable_email_receipt'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['disable_email_receipt'] ) ) : false;
		$disable_webhooks = isset( $_REQUEST['disable_webhooks'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['disable_webhooks'] ) ) : false;
		$referrer_url = sanitize_url( wp_get_raw_referer(), array( 'http', 'https' ) );

		// If not Ajax submission and found test_id.
		if ( isset( $_SERVER['REQUEST_URI'] ) && strpos( sanitize_url( wp_unslash( $_SERVER['REQUEST_URI'] ) ), 'admin-ajax.php' ) === false && '' !== $cv_test_id ) {
			// Create session for later use when form submit VIA AJAX.
			checkview_create_cv_session( $visitor_ip, $cv_test_id );

			cv_update_option( $visitor_ip, 'checkview-saas', true);
		}

		// If submit VIA AJAX.
		if ( isset( $_SERVER['REQUEST_URI'] ) && strpos( sanitize_url( wp_unslash( $_SERVER['REQUEST_URI'] ) ), 'admin-ajax.php' ) !== false ) {
			$referer_url_query = wp_parse_url( $referrer_url, PHP_URL_QUERY );
			$qry_str = array();

			if ( $referer_url_query ) {
				parse_str( $referer_url_query, $qry_str );
			}

			if ( isset( $qry_str['checkview_test_id'] ) ) {
				$cv_test_id = $qry_str['checkview_test_id'];
			}
		}

		if ( ! empty( $cv_test_id ) && ! checkview_is_valid_uuid( $cv_test_id ) ) {
			Checkview_Admin_Logs::add( 'ip-logs', 'Test ID format failed to validate, exiting.' );

			return;
		}

		if ( $cv_test_id && '' !== $cv_test_id ) {
			setcookie( 'checkview_test_id', $cv_test_id, time() + 6600, COOKIEPATH, COOKIE_DOMAIN );
		}

		if ( $cv_test_id && '' !== $cv_test_id ) {
			setcookie( 'checkview_test_id' . $cv_test_id, $cv_test_id, time() + 3600, COOKIEPATH, COOKIE_DOMAIN );
		}

		$cv_session = checkview_get_cv_session( $visitor_ip, $cv_test_id );

		if ( ! empty( $cv_test_id ) ) {
			$send_to = $cv_test_id . '@test-mail.checkview.io';
		} else {
			$cv_test_id = get_checkview_test_id();
			if ( ! empty( $cv_test_id ) ) {
				$send_to = $cv_test_id . '@test-mail.checkview.io';
			} else {
				$send_to = CHECKVIEW_EMAIL;
			}
		}

		if ( ! empty( $cv_session ) ) {
			$test_key = $cv_session[0]['test_key'];
			$test_form = get_option( $test_key, '' );

			if ( ! empty( $test_form ) ) {
				$test_form = json_decode( $test_form, true );
			}

			if ( isset( $test_form['send_to'] ) && '' !== $test_form['send_to'] ) {
				$send_to = $test_form['send_to'];
			}

			if ( ! defined( 'CV_TEST_ID' ) ) {
				define( 'CV_TEST_ID', $cv_test_id );

				Checkview_Admin_Logs::add( 'ip-logs', 'Defined constant [CV_TEST_ID] as [ ' . CV_TEST_ID . ' ].' );
			}
		} else {
			Checkview_Admin_Logs::add( 'ip-logs', 'The CheckView session was empty, but continuing.' );
		}

		if ( ! defined( 'TEST_EMAIL' ) ) {
			define( 'TEST_EMAIL', $send_to );

			Checkview_Admin_Logs::add( 'ip-logs', 'Defined constant [TEST_EMAIL] as [ ' . TEST_EMAIL . ' ].' );
		}

		if ( ! defined( 'CV_DISABLE_EMAIL_RECEIPT' ) && $disable_email_receipt ) {
			define( 'CV_DISABLE_EMAIL_RECEIPT', 'true' );

			Checkview_Admin_Logs::add( 'ip-logs', 'Defined constant [CV_DISABLE_EMAIL_RECEIPT] as [' . CV_DISABLE_EMAIL_RECEIPT . '].' );

			cv_update_option('disable_email_receipt', 'true', true);
		}

		if ( ! defined( 'CV_DISABLE_WEBHOOKS' ) && $disable_webhooks ) {
			define( 'CV_DISABLE_WEBHOOKS', 'true' );

			Checkview_Admin_Logs::add( 'ip-logs', 'Defined constant [CV_DISABLE_WEBHOOKS] as [' . CV_DISABLE_WEBHOOKS . '].' );

			cv_update_option('disable_webhooks', 'true', true);
		}

		delete_transient( 'checkview_forms_test_transient' );
		delete_transient( 'checkview_store_orders_transient' );

		if ( is_plugin_active( 'gravityforms/gravityforms.php' ) ) {
			Checkview_Admin_Logs::add( 'ip-logs', 'Loading Gravity Forms helper.' );

			require_once CHECKVIEW_INC_DIR . 'formhelpers/class-checkview-gforms-helper.php';
		}

		if ( is_plugin_active( 'fluentform/fluentform.php' ) ) {
			Checkview_Admin_Logs::add( 'ip-logs', 'Loading Fluent Forms helper.' );

			require_once CHECKVIEW_INC_DIR . 'formhelpers/class-checkview-fluent-forms-helper.php';
		}

		if ( is_plugin_active( 'ninja-forms/ninja-forms.php' ) ) {
			Checkview_Admin_Logs::add( 'ip-logs', 'Loading Ninja Forms helper.' );

			require_once CHECKVIEW_INC_DIR . 'formhelpers/class-checkview-ninja-forms-helper.php';
		}

		if ( function_exists( 'wpforms' ) && ( is_plugin_active( 'wpforms/wpforms.php' ) || is_plugin_active( 'wpforms-lite/wpforms.php' ) ) ) {
			Checkview_Admin_Logs::add( 'ip-logs', 'Loading WP Forms helper.' );

			require_once CHECKVIEW_INC_DIR . 'formhelpers/class-checkview-wpforms-helper.php';
		}

		if ( is_plugin_active( 'formidable/formidable.php' ) ) {
			Checkview_Admin_Logs::add( 'ip-logs', 'Loading Formidable Forms helper.' );

			require_once CHECKVIEW_INC_DIR . 'formhelpers/class-checkview-formidable-helper.php';
		}

		if ( is_plugin_active( 'forminator/forminator.php' ) ) {
			Checkview_Admin_Logs::add( 'ip-logs', 'Loading Forminator helper.' );

			require_once CHECKVIEW_INC_DIR . 'formhelpers/class-checkview-forminator-helper.php';
		}

		if ( is_plugin_active( 'ws-form/ws-form.php' ) || is_plugin_active( 'ws-form-pro/ws-form.php' ) ) {
			Checkview_Admin_Logs::add( 'ip-logs', 'Loading WS Form helper.' );

			require_once CHECKVIEW_INC_DIR . 'formhelpers/class-checkview-wsf-helper.php';
		}

		if ( is_plugin_active( 'contact-form-7/wp-contact-form-7.php' ) ) {
			Checkview_Admin_Logs::add( 'ip-logs', 'Loading Contact Form 7 helper.' );

			require_once CHECKVIEW_INC_DIR . 'formhelpers/class-checkview-cf7-helper.php';
		}
	}

	/**
	 * Hides checkview
	 *
	 * @param array $plugins array of plugins.
	 * @return array
	 */
	public function checkview_hide_me( array $plugins ): array {
		$hide_me = get_option( 'checkview_hide_me', false );
		if ( ! is_array( $plugins ) || false == $hide_me ) {
			return $plugins;
		}
		foreach ( $plugins as $slug => $brand ) {
			if ( ! isset( $slug ) || ! array_key_exists( $slug, $plugins ) || ! is_array( $brand ) ) {
				continue;
			}
			if ( 'checkview/checkview.php' === $slug ) {
				unset( $plugins[ $slug ] );
			}
		}
		return $plugins;
	}

	/**
	 * Hides plugin health Info.
	 *
	 * @param array $plugins array of plugins.
	 * @return array
	 */
	public function checkview_handle_plugin_health_info( $plugins ) {
		$hide_me = get_option( 'checkview_hide_me', false );
		if ( ! isset( $plugins['wp-plugins-active'] ) ||
			! isset( $plugins['wp-plugins-active']['fields'] ) || false == $hide_me ) {
			return $plugins;
		}
		foreach ( $plugins as $slug => $brand ) {
			if ( ! isset( $slug ) || ! array_key_exists( $slug, $plugins ) || ! is_array( $brand ) ) {
				continue;
			}
			if ( ! empty( $plugins['wp-plugins-active']['fields']['CheckView'] ) ) {
				unset( $plugins['wp-plugins-active']['fields']['CheckView'] );
			}
		}
		return $plugins;
	}

	/**
	 * Hides Plugin Details.
	 *
	 * @param [array]  $plugin_metas plugin metas.
	 * @param [string] $slug plugin slug.
	 * @return array
	 */
	public function checkview_hide_plugin_details( $plugin_metas, $slug ) {
		$hide_me = get_option( 'checkview_hide_me', false );
		if ( ! is_array( $plugin_metas ) || false == $hide_me || 'checkview' !== $slug ) {
			return $plugin_metas;
		}

		foreach ( $plugin_metas as $plugin_key => $plugin_value ) {
			if ( strpos( $plugin_value, sprintf( '>%s<', translate( 'View details' ) ) ) ) {
				unset( $plugin_metas[ $plugin_key ] );
				break;
			}
		}
		return $plugin_metas;
	}
}
