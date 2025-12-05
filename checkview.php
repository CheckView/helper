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
 * Version:           2.0.24
 * Author:            CheckView
 * Author URI:        https://checkview.io/
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       checkview
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
define( 'CHECKVIEW_VERSION', '2.0.24' );

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
