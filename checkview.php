<?php
/**
 * CheckView plugin
 *
 * @link https://checkview.io
 *
 * @since 1.0.0
 * @package CheckView
 *
 * @wordpress-plugin
 * Plugin Name:       CheckView
 * Plugin URI:        https://checkview.io
 * Description:       CheckView is the #1 fully automated solution to test your WordPress forms and detect form problems fast.  Automatically test your WordPress forms to ensure you never miss a lead again.
 * Version:           2.2.0
 * Author:            CheckView
 * Author URI:        https://checkview.io/
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       checkview
 * Requires PHP:      7.4
 * WC requires at least: 7.0
 * WC tested up to: 8.3
 * Domain Path:       /languages
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Current plugin version.
 *
 * Start at version 1.0.0 and use SemVer. Rename this for your plugin and
 * update it as you release new versions.
 *
 * @link https://semver.org
 */
define( 'CHECKVIEW_VERSION', '2.2.0' );

if ( ! defined( 'CHECKVIEW_BASE_DIR' ) ) {
	define( 'CHECKVIEW_BASE_DIR', plugin_basename( __FILE__ ) );
}
if ( ! defined( 'CHECKVIEW_PLUGIN_DIR' ) ) {
	define( 'CHECKVIEW_PLUGIN_DIR', trailingslashit( plugin_dir_path( __FILE__ ) ) );
}

if ( ! defined( 'CHECKVIEW_INC_DIR' ) ) {
	define( 'CHECKVIEW_INC_DIR', trailingslashit( plugin_dir_path( __FILE__ ) ) . 'includes/' );
}

if ( ! defined( 'CHECKVIEW_PUBLIC_DIR' ) ) {
	define( 'CHECKVIEW_PUBLIC_DIR', trailingslashit( plugin_dir_path( __FILE__ ) ) . 'public/' );
}

if ( ! defined( 'CHECKVIEW_ADMIN_DIR' ) ) {
	define( 'CHECKVIEW_ADMIN_DIR', trailingslashit( plugin_dir_path( __FILE__ ) ) . 'admin/' );
}

if ( ! defined( 'CHECKVIEW_ADMIN_ASSETS' ) ) {
	define( 'CHECKVIEW_ADMIN_ASSETS', trailingslashit( plugin_dir_url( __FILE__ ) ) . 'admin/assets/' );
}

if ( ! defined( 'CHECKVIEW_PUBLIC_ASSETS' ) ) {
	define( 'CHECKVIEW_PUBLIC_ASSETS', trailingslashit( plugin_dir_url( __FILE__ ) ) . 'public/assets/' );
}

if ( ! defined( 'CHECKVIEW_EMAIL' ) ) {
	define( 'CHECKVIEW_EMAIL', 'verify@test-mail.checkview.io' );
}
if ( ! defined( 'CHECKVIEW_URI' ) ) {
	define( 'CHECKVIEW_URI', trailingslashit( plugin_dir_url( __FILE__ ) ) );
}
/**
 * Handles CheckView activation.
 */
function activate_checkview() {
	// checkview-functions.php is normally loaded on plugins_loaded, which
	// hasn't fired yet during activation. Pull it in so the activator can
	// call checkview_dbdelta_or_query().
	require_once plugin_dir_path( __FILE__ ) . 'includes/checkview-functions.php';
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-checkview-activator.php';
	Checkview_Activator::activate();
}
register_activation_hook( __FILE__, 'activate_checkview' );

/**
 * Handles CheckView deactivation.
 */
function deactivate_checkview() {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-checkview-deactivator.php';
	Checkview_Deactivator::deactivate();
}
register_deactivation_hook( __FILE__, 'deactivate_checkview' );

if ( ! function_exists( 'is_plugin_active' ) ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
}

// Load CheckView Helper Plugins.
require plugin_dir_path( __FILE__ ) . 'includes/checkview-helper-functions.php';

// Load CheckView class.
require plugin_dir_path( __FILE__ ) . 'includes/class-checkview.php';

/**
 * Seed the suppression kill-switch option with autoload=yes.
 *
 * H8 incident-response escape hatch: ops can flip
 * `cv_suppression_kill_switch` to `'true'` via WP-CLI to bypass all
 * webhook/action suppression on a customer site, even on stamped test
 * orders. The option is read on every webhook delivery
 * (`cv_is_suppressible_test_order`), so we want it autoloaded for cache-hit
 * reads instead of a DB query per webhook.
 *
 * Registers on `init` (priority 1) instead of `register_activation_hook`
 * because activation hooks only fire on (re)activation — existing customer
 * sites that auto-update would never run the seed. Init-hook seeding
 * self-heals on first request after deploy. Cost: one cached `get_option`
 * per request until seeded once, then no-ops cheaply forever.
 */
add_action(
	'init',
	function () {
		if ( false === get_option( 'cv_suppression_kill_switch' ) ) {
			add_option( 'cv_suppression_kill_switch', 'false', '', 'yes' );
		}
	},
	1
);

/**
 * Initiates the main CheckView class.
 *
 * @since 1.0.0
 */
function run_checkview() {
	$plugin = CheckView::get_instance();
	$plugin->run();
}
add_action( 'plugins_loaded', 'run_checkview', '10' );

/**
 * Declares compatibility with WooCommerce high-performance order storage.
 *
 * @link https://woocommerce.com/document/high-performance-order-storage/
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);
