<?php
/**
 * CheckView testing
 */

error_reporting( E_ALL & ~E_DEPRECATED );

// Composer autoloader must be loaded before WP_PHPUNIT__DIR will be available
require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// Define plugin paths as constants
define( 'PLUGIN_DIR', dirname( __DIR__ ) );
define( 'WC_PLUGIN_DIR', PLUGIN_DIR . '/plugins/woocommerce' );
define( 'CV_PLUGIN_DIR', PLUGIN_DIR . '/plugins/checkview' );
// Bootstrap WP_Mock to initialize built-in features
// (removed commented code)

// Give access to tests_add_filter() function.
require_once getenv( 'WP_PHPUNIT__DIR' ) . '/includes/functions.php';

ob_start();
require_once dirname( __DIR__, 4 ) . '/wp-load.php';
tests_add_filter(
	'muplugins_loaded',
	function () {

		// require_once WC_PLUGIN_DIR . '/woocommerce.php';
		//require_once CV_PLUGIN_DIR . '/checkview.php';
		//
		 // test set up, plugin activation, etc.
		//require PLUGIN_DIR . '/example-plugin.php';
	}
);

// Start up the WP testing environment.
require getenv( 'WP_PHPUNIT__DIR' ) . '/includes/bootstrap.php';
