<?php
/**
 * CheckView core: Checkview class
 *
 * @since 1.0.0
 *
 * @package Checkview
 * @subpackage Checkview/includes
 */

/**
 * Loads plugin dependencies and files, runs hooks.
 *
 * @since 1.0.0
 * @package Checkview
 * @subpackage Checkview/includes
 * @author Check View <support@checkview.io>
 */
class CheckView {

	/**
	 * Hook loader.
	 *
	 * @since 1.0.0
	 * @access protected
	 *
	 * @var Checkview_Loader $loader Maintains and registers all hooks for the plugin.
	 */
	protected $loader;

	/**
	 * Plugin name.
	 *
	 * @since 1.0.0
	 * @access protected
	 *
	 * @var string $plugin_name The string used to uniquely identify this plugin.
	 */
	protected $plugin_name;

	/**
	 * Plugin version.
	 *
	 * @since 1.0.0
	 * @access protected
	 *
	 * @var string $version The current version of the plugin.
	 */
	protected $version;

	/**
	 * Class singleton.
	 *
	 * @since 1.0.0
	 * @access private
	 *
	 * @var CheckView $instance The instance of the class.
	 */

	private static $instance = null;

	/**
	 * Bot cookie name.
	 *
	 * @since 2.0.20
	 * @access private
	 *
	 * @var string $bot_cookie Bot cookie name.
	 */
	private static string $bot_cookie = 'cv_running';

	/**
	 * Constructor.
	 *
	 * Sets up class properties, loads dependencies, and hooks up functions.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		if ( defined( 'CHECKVIEW_VERSION' ) ) {
			$this->version = CHECKVIEW_VERSION;
		} else {
			$this->version = '2.0.23';
		}
		$this->plugin_name = 'checkview';

		$this->load_dependencies();

		$this->loader->add_action( 'init', $this, 'load_textdomain' );
		$this->loader->add_action( 'wp_enqueue_scripts', $this, 'dequeue_scripts', 20 );

		$this->define_admin_hooks();
	}

	/**
	 * Gets the instance of this class.
	 *
	 * Creates an instance of itself if there was not one found before returning.
	 *
	 * @since 1.0.0
	 *
	 * @return self Class instance
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new CheckView();
		}

		return self::$instance;
	}

	/**
	 * Determine if the request contains the cookie necessary for running testing code.
	 *
	 * Returns one of `woo_checkout`, `form`, or `custom`. False if not set or invalid value.
	 *
	 * @return string|false Validated cookie value, or false.
	 */
	public static function has_cookie() {
		$valid_values = array('woo_checkout', 'form', 'custom');

		if ( isset( $_COOKIE[self::$bot_cookie] ) && in_array( $_COOKIE[self::$bot_cookie], $valid_values ) ) {
			return $_COOKIE[self::$bot_cookie];
		}

		return false;
	}

	/**
	 * Determine if the current request is a CheckView bot.
	 *
	 * @return bool
	 */
	public static function is_bot(): bool {
		$visitor_ip = checkview_get_visitor_ip();
		$cv_bot_ip = checkview_get_api_ip();
		$ip_verified = is_array( $cv_bot_ip ) && in_array( $visitor_ip, $cv_bot_ip );

		if ( isset( $_REQUEST['checkview_test_id'] ) && ! $ip_verified ) {
			Checkview_Admin_Logs::add( 'ip-logs', 'Although checkview_test_id is set in the request, failing bot check due to visitor IP [' . $visitor_ip . '] not existing in bot IP list [' . implode(', ', $cv_bot_ip) . '].' );

			return false;
		}

		$has_cookie = self::has_cookie();

		return $has_cookie && $ip_verified;
	}

	/**
	 * Loads plugin dependencies.
	 *
	 * Loads WordPress Core dependenceis, vendor files, and CheckView classes.
	 * Additionally sets up more class properties, conditionally loads WooCommerce
	 * helper, adds admin plugin list action links, and initializes the API class.
	 *
	 * @since 1.0.0
	 * @access private
	 */
	private function load_dependencies() {
		// WordPress Core
		if ( ! function_exists( 'is_plugin_active' ) ) {
			include_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		// WordPress Core
		require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';

		// Vendor
		require_once plugin_dir_path( __DIR__ ) . 'includes/vendor/autoload.php';

		// CheckView
		require_once plugin_dir_path( __DIR__ ) . 'includes/checkview-functions.php';
		require_once plugin_dir_path( __DIR__ ) . 'includes/class-checkview-loader.php';
		require_once plugin_dir_path( __DIR__ ) . 'admin/class-checkview-admin.php';
		require_once plugin_dir_path( __DIR__ ) . 'admin/class-checkview-admin-logs.php';
		require_once plugin_dir_path( __DIR__ ) . 'admin/settings/class-checkview-admin-settings.php';

		$this->loader = new Checkview_Loader();

		// Current visitor IP.
		$visitor_ip = checkview_get_visitor_ip();
		$woo_helper = '';
		// Check view Bot IP.
		$cv_bot_ip = checkview_get_api_ip();

		// TODO: What is this for?
		if ( ( 'checkview-saas' === get_option( $visitor_ip ) || isset( $_REQUEST['checkview_test_id'] ) || ( is_array( $cv_bot_ip ) && in_array( $visitor_ip, $cv_bot_ip ) ) ) ) {
			update_option( $visitor_ip, 'checkview-saas', true );
		}

		if ( class_exists( 'WooCommerce' ) ) {
			require_once plugin_dir_path( __DIR__ ) . 'includes/woocommercehelper/class-checkview-woo-automated-testing.php';

			$woo_helper = new Checkview_Woo_Automated_Testing( $this->get_plugin_name(), $this->get_version(), $this->loader );
		}

		$this->loader->add_filter(
			'plugin_action_links_' . CHECKVIEW_BASE_DIR,
			$this,
			'checkview_settings_link'
		);

		// Require API class.
		require_once plugin_dir_path( __DIR__ ) . 'includes/API/class-checkview-api.php';

		// Initialize the plugin's API.
		$plugin_api = new CheckView_Api();

		// Hook our routes into WordPress.
		$this->loader->add_action(
			'rest_api_init',
			$plugin_api,
			'checkview_register_rest_route'
		);
	}

	/**
	 * Loads the CheckView text domain.
	 *
	 * @since 1.0.0
	 */
	public function load_textdomain() {
		load_plugin_textdomain(
			'checkview',
			false,
			dirname( plugin_basename( __FILE__ ) ) . '/languages/'
		);
	}

	/**
	 * Adds a "Settings" link to admin plugin list page.
	 *
	 * @since 1.0.0
	 *
	 * @param array $links The `href` value for settings pages.
	 * @return array Modified array of plugin action links with the "Settings" link included.
	 */
	public function checkview_settings_link( $links ) {
		$settings_link = '<a href="admin.php?page=checkview-options">' . esc_html__( 'Settings', 'checkview' ) . '</a>';
		array_unshift( $links, $settings_link );
		return $links;
	}
	/**
	 * Sets up admin classes and hooks.
	 *
	 * Initializes various admin classes and hooks up methods from those classes.
	 *
	 * @since 1.0.0
	 * @access private
	 */
	private function define_admin_hooks() {
		$plugin_admin    = new Checkview_Admin( $this->get_plugin_name(), $this->get_version() );
		$plugin_settings = new Checkview_Admin_Settings( $this->get_plugin_name(), $this->get_version() );
		$plugin_logs     = new Checkview_Admin_Logs();

		if ( is_admin() ) {
			// load backend hooks.
			$this->loader->add_action(
				'admin_post_checkview_admin_logs_settings',
				$plugin_logs,
				'checkview_admin_logs_settings_save'
			);
			$this->loader->add_action(
				'admin_footer_text',
				$plugin_settings,
				'checkview_add_footer_admin'
			);
			$this->loader->add_action(
				'admin_post_checkview_admin_advance_settings',
				$plugin_settings,
				'checkview_admin_advance_settings_save'
			);

			$this->loader->add_action(
				'wp_ajax_checkview_update_cache',
				$plugin_settings,
				'checkview_update_cache'
			);

			$this->loader->add_action(
				'admin_enqueue_scripts',
				$plugin_admin,
				'enqueue_styles'
			);

			$this->loader->add_action(
				'admin_enqueue_scripts',
				$plugin_admin,
				'enqueue_scripts'
			);

			$this->loader->add_action(
				'admin_menu',
				$plugin_settings,
				'checkview_menu',
				220
			);

			$this->loader->add_action(
				'admin_notices',
				$plugin_settings,
				'checkview_admin_notices'
			);

			$this->loader->add_action(
				'save_post',
				$plugin_settings,
				'checkview_update_cache_non_ajax',
				11,
				3
			);
		}

		if ( self::is_bot() ) {
			$this->loader->add_action(
				'init',
				$plugin_admin,
				'checkview_init_current_test'
			);
		}

		$this->loader->add_action(
			'upgrader_process_complete',
			$this,
			'checkview_track_updates_notification',
			10,
			2,
		);
	}

	/**
	 * Sets up public classes and hooks.
	 *
	 * Initializes various public classes and hooks up methods from those classes.
	 *
	 * @since 1.0.0
	 * @access private
	 */
	public function dequeue_scripts() {
		if ( self::is_bot() ) {
			wp_dequeue_script( 'contact-form-7' );
			wp_dequeue_style( 'contact-form-7' );
			wp_dequeue_script( 'wpcf7-recaptcha' );
			wp_dequeue_style( 'wpcf7-recaptcha' );

			$this->loader->add_action(
				'pre_option_require_name_email',
				'',
				'checkview_whitelist_saas_ip_addresses'
			);
		}
	}
	/**
	 * Resets plugin cache if being updated.
	 *
	 * @param object $upgrader_object Class upgrader.
	 * @param array  $options Options.
	 * @return void
	 */
	public function checkview_track_updates_notification( $upgrader_object, $options ) {
		global $wpdb;

		// If an update has taken place and the updated type is plugins and the plugins element exists.
		if ( 'update' === $options['action'] && 'plugin' === $options['type'] && isset( $options['plugins'] ) ) {
			foreach ( $options['plugins'] as $plugin ) {
				if ( CHECKVIEW_BASE_DIR === $plugin ) {
					checkview_reset_cache( true );
					// Include upgrade.php for dbDelta.
					if ( ! function_exists( 'dbDelta' ) ) {
						require_once ABSPATH . 'wp-admin/includes/upgrade.php';
					}
					$cv_used_nonces = $wpdb->prefix . 'cv_used_nonces';

					$charset_collate = $wpdb->get_charset_collate();
					if ( $wpdb->get_var( "SHOW TABLES LIKE '{$cv_used_nonces}'" ) !== $cv_used_nonces ) {
						$sql = "CREATE TABLE $cv_used_nonces (
								id BIGINT(20) NOT NULL AUTO_INCREMENT,
								nonce VARCHAR(255) NOT NULL,
								used_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL,
								PRIMARY KEY (id),
								UNIQUE KEY nonce (nonce)
							) $charset_collate;";
						dbDelta( $sql );
					}
				}
			}
		}
	}
	/**
	 * Hooks up the actions and filters stored in the loader.
	 *
	 * @since 1.0.0
	 */
	public function run() {
		$this->loader->run();
	}

	/**
	 * Gets the plugin name.
	 *
	 * @since 1.0.0
	 *
	 * @return string The name of the plugin.
	 */
	public function get_plugin_name() {
		return $this->plugin_name;
	}

	/**
	 * Gets the loader.
	 *
	 * @since 1.0.0
	 *
	 * @return Checkview_Loader Orchestrates the hooks of the plugin.
	 */
	public function get_loader() {
		return $this->loader;
	}

	/**
	 * Gets the plugin version.
	 *
	 * @since 1.0.0
	 *
	 * @return string The version number of the plugin.
	 */
	public function get_version() {
		return $this->version;
	}
}
