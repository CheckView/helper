<?php
/**
 * Tests that ordinary visitors never read the SaaS IP allowlist, and that
 * CheckView test requests are recognized exactly as before.
 *
 * Self-contained: WordPress is stubbed below, so this runs with plain PHP
 * (8.0+) from the plugin root:
 *
 *     php tests/allowlist-gate-test.php
 *
 * Exits non-zero when any assertion fails.
 *
 * @package CheckView
 */

// phpcs:disable -- Test script with WordPress stubs; not shipped (see .distignore).

define( 'ABSPATH', __DIR__ . '/' );
define( 'WPINC', 'wp-includes' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'KB_IN_BYTES', 1024 );
define( 'CHECKVIEW_INC_DIR', dirname( __DIR__ ) . '/includes/' );
define( 'CHECKVIEW_ADMIN_DIR', dirname( __DIR__ ) . '/admin/' );
define( 'CHECKVIEW_EMAIL', 'verify@test-mail.checkview.io' );

const SITE_URL  = 'https://example.test';
const BOT_IP    = '203.0.113.10'; // RFC 5737 documentation addresses.
const OTHER_IP  = '198.51.100.7'; // RFC 5737 documentation addresses.
const TEST_UUID = '1b4e28ba-2fa1-4d3b-8c5e-6a1f0b3c9d2e';

/** Counters for every read and outside call the code under test makes. */
$GLOBALS['cv_t'] = array();

function cv_t_reset() {
	$GLOBALS['cv_t'] = array(
		'store'        => array(),
		'transient_reads' => array(),
		'option_reads' => array(),
		'http'         => array(),
		'scheduled'    => array(),
		'filters'      => array(),
		'whitelist'    => array( 'ipAddresses' => array( BOT_IP ) ),
	);
	unset( $GLOBALS['checkview_api_ip_fetch_memo'] );
	$_SERVER  = array( 'REQUEST_URI' => '/', 'REMOTE_ADDR' => OTHER_IP );
	$_GET     = array();
	$_POST    = array();
	$_REQUEST = array();
	$_COOKIE  = array();
}

function cv_t_allowlist_reads() {
	$n = 0;
	foreach ( array( 'transient_reads', 'option_reads' ) as $k ) {
		foreach ( $GLOBALS['cv_t'][ $k ] as $name ) {
			if ( false !== strpos( $name, 'checkview_saas_ip' ) ) {
				++$n;
			}
		}
	}
	return $n;
}

// A die() in the code under test exits 0 with no summary; count that as a failure.
register_shutdown_function(
	function () {
		if ( empty( $GLOBALS['cv_t_done'] ) ) {
			echo "\nFAIL the script stopped before the summary\n";
			exit( 1 );
		}
	}
);

/* ---- WordPress stubs ---------------------------------------------------- */

class WP_Error {
	public $code;
	public $message;
	public function __construct( $code = '', $message = '' ) {
		$this->code    = $code;
		$this->message = $message;
	}
	public function get_error_code() {
		return $this->code;
	}
	public function get_error_message() {
		return $this->message;
	}
}
class Checkview_Admin_Logs {
	public static $lines = array();
	public static function add( $type, $line ) {
		self::$lines[] = $line;
	}
}

$GLOBALS['cv_t_hooks'] = array();
function add_action( $hook, $cb, $prio = 10, $args = 1 ) {
	$GLOBALS['cv_t_hooks'][ $hook ][] = $cb;
	return true;
}
function add_filter( $hook, $cb, $prio = 10, $args = 1 ) {
	return add_action( $hook, $cb, $prio, $args );
}
function apply_filters( $hook, $value ) {
	return array_key_exists( $hook, $GLOBALS['cv_t']['filters'] ) ? $GLOBALS['cv_t']['filters'][ $hook ] : $value;
}
function get_transient( $name ) {
	$GLOBALS['cv_t']['transient_reads'][] = $name;
	return $GLOBALS['cv_t']['store'][ '_transient_' . $name ] ?? false;
}
function set_transient( $name, $value, $exp = 0 ) {
	$GLOBALS['cv_t']['store'][ '_transient_' . $name ] = $value;
	return true;
}
function delete_transient( $name ) {
	unset( $GLOBALS['cv_t']['store'][ '_transient_' . $name ] );
	return true;
}
function get_option( $name, $default = false ) {
	$GLOBALS['cv_t']['option_reads'][] = $name;
	return $GLOBALS['cv_t']['store'][ $name ] ?? $default;
}
function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['cv_t']['store'][ $name ] = $value;
	return true;
}
function delete_option( $name ) {
	unset( $GLOBALS['cv_t']['store'][ $name ] );
	return true;
}
function wp_remote_get( $url, $args = array() ) {
	$GLOBALS['cv_t']['http'][] = $url;
	return array(
		'response' => array( 'code' => 200 ),
		'body'     => json_encode( $GLOBALS['cv_t']['whitelist'] ),
	);
}
function wp_safe_remote_get( $url, $args = array() ) {
	return wp_remote_get( $url, $args );
}
function wp_remote_retrieve_response_code( $r ) {
	return $r['response']['code'];
}
function wp_remote_retrieve_body( $r ) {
	return $r['body'];
}
function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}
function get_current_blog_id() {
	return 1;
}
function wp_using_ext_object_cache() {
	return false;
}
function wp_cache_delete( $key, $group = '' ) {
	return true;
}
/** Enough of $wpdb for the allowlist fetch lock (checkview_acquire_fetch_lock()). */
class CV_T_WPDB {
	public $options = 'wp_options';
	public function prepare( $sql, ...$args ) {
		return array( $sql, $args );
	}
	public function get_var( $q ) {
		return $GLOBALS['cv_t']['store'][ $q[1][0] ] ?? null;
	}
	public function query( $q ) {
		list( $sql, $args ) = $q;
		if ( 0 === strpos( $sql, 'INSERT IGNORE' ) ) {
			if ( isset( $GLOBALS['cv_t']['store'][ $args[0] ] ) ) {
				return 0;
			}
			$GLOBALS['cv_t']['store'][ $args[0] ] = $args[1];
			return 1;
		}
		return 0;
	}
	public function delete( $table, $where ) {
		unset( $GLOBALS['cv_t']['store'][ $where['option_name'] ] );
		return 1;
	}
}
$GLOBALS['wpdb'] = new CV_T_WPDB();
function wp_next_scheduled( $hook ) {
	return in_array( $hook, $GLOBALS['cv_t']['scheduled'], true );
}
function wp_schedule_single_event( $time, $hook, $args = array() ) {
	$GLOBALS['cv_t']['scheduled'][] = $hook;
	return true;
}
function wp_unslash( $v ) {
	return is_string( $v ) ? stripslashes( $v ) : $v;
}
function sanitize_text_field( $v ) {
	return trim( wp_strip_all_tags( (string) $v ) );
}
function wp_strip_all_tags( $v ) {
	return strip_tags( (string) $v );
}
function wp_json_encode( $v ) {
	return json_encode( $v );
}
function esc_html( $v ) {
	return $v;
}
function esc_html__( $v ) {
	return $v;
}
function __( $v ) {
	return $v;
}
function get_bloginfo( $what ) {
	return SITE_URL;
}
function wp_get_raw_referer() {
	return $_SERVER['HTTP_REFERER'] ?? false;
}
function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}
function rest_get_url_prefix() {
	return 'wp-json';
}
function is_plugin_active( $plugin ) {
	return in_array( $plugin, array( 'defender-security/wp-defender.php', 'better-wp-security/better-wp-security.php' ), true );
}
function checkview_should_allow_original_recipients() {
	return false;
}

/* ---- Code under test ---------------------------------------------------- */

require CHECKVIEW_INC_DIR . 'vendor/autoload.php';
require CHECKVIEW_INC_DIR . 'checkview-functions.php';
require CHECKVIEW_INC_DIR . 'checkview-helper-functions.php';
require CHECKVIEW_INC_DIR . 'class-checkview.php';
require CHECKVIEW_INC_DIR . 'woocommercehelper/class-checkview-woo-automated-testing.php';

use Firebase\JWT\JWT;

$cv_t_key     = openssl_pkey_new( array( 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA ) );
$cv_t_public  = openssl_pkey_get_details( $cv_t_key )['key'];
openssl_pkey_export( $cv_t_key, $cv_t_private );

/** A signature the SaaS would send, signed with the key the site trusts. */
function cv_t_signature( $private_key = null ) {
	return JWT::encode(
		array(
			'websiteUrl'       => SITE_URL,
			'exp'              => time() + 60,
			'_checkview_nonce' => 'nonce-' . mt_rand(),
		),
		$private_key ?? $GLOBALS['cv_t_private'],
		'RS256'
	);
}

/** Sets up a stored allowlist and public key, as on a site that has run tests. */
function cv_t_site_with_cache( $cache = 'fresh' ) {
	cv_t_reset();
	$GLOBALS['cv_t']['store']['_transient_checkview_saas_pk'] = $GLOBALS['cv_t_public'];
	$GLOBALS['cv_t']['store']['checkview_saas_ip_address_last_good'] = array(
		'value'      => array( BOT_IP ),
		'fetched_at' => time() - HOUR_IN_SECONDS,
	);
	$GLOBALS['cv_t']['store']['checkview_saas_ip_address_seeded'] = 1;
	if ( 'fresh' === $cache ) {
		$GLOBALS['cv_t']['store']['_transient_checkview_saas_ip_address'] = array( BOT_IP );
	}
	if ( 'empty' === $cache ) {
		unset( $GLOBALS['cv_t']['store']['checkview_saas_ip_address_last_good'], $GLOBALS['cv_t']['store']['checkview_saas_ip_address_seeded'] );
	}
}

function cv_t_firewall( $hook ) {
	$out = array( 'existing' );
	foreach ( $GLOBALS['cv_t_hooks'][ $hook ] ?? array() as $cb ) {
		$out = call_user_func( $cb, $out );
	}
	return $out;
}

function cv_t_order_email( $payment_method = 'stripe' ) {
	$order = new class( $payment_method ) {
		private $pm;
		public function __construct( $pm ) {
			$this->pm = $pm;
		}
		public function get_payment_method() {
			return $this->pm;
		}
		public function get_meta( $key ) {
			return '';
		}
	};
	$woo = ( new ReflectionClass( 'Checkview_Woo_Automated_Testing' ) )->newInstanceWithoutConstructor();
	return $woo->checkview_filter_admin_emails( 'shop@example.test', $order, null );
}

/* ---- Assertions --------------------------------------------------------- */

$cv_t_failures = 0;
$cv_t_count    = 0;
function check( $name, $cond ) {
	global $cv_t_failures, $cv_t_count;
	++$cv_t_count;
	if ( $cond ) {
		echo "ok   $name\n";
		return;
	}
	++$cv_t_failures;
	echo "FAIL $name\n";
}

/* ---- Cases -------------------------------------------------------------- */

// 1. Ordinary visitor: no allowlist read, no download, every caller.
foreach ( array( 'fresh', 'expired', 'empty' ) as $cache ) {
	cv_t_site_with_cache( $cache );
	$_SERVER['HTTP_X_FORWARDED_FOR'] = BOT_IP; // Even from a listed IP.
	$is_bot  = CheckView::is_bot();
	$itsec   = cv_t_firewall( 'itsec_white_ips' );
	$def     = cv_t_firewall( 'ip_lockout_default_whitelist_ip' );
	$email   = cv_t_order_email();
	check( "visitor ($cache cache): not a bot", false === $is_bot );
	check( "visitor ($cache cache): firewall lists unchanged", array( 'existing' ) === $itsec && array( 'existing' ) === $def );
	check( "visitor ($cache cache): order email goes to the shop", 'shop@example.test' === $email );
	check( "visitor ($cache cache): no allowlist reads", 0 === cv_t_allowlist_reads() );
	check( "visitor ($cache cache): no HTTP calls", array() === $GLOBALS['cv_t']['http'] );
	check( "visitor ($cache cache): nothing scheduled", array() === $GLOBALS['cv_t']['scheduled'] );
}

// 2. Real signed test request is recognized.
cv_t_site_with_cache();
$_GET[ CheckView::PARAM_TEST_TYPE ]     = 'form';
$_REQUEST[ CheckView::PARAM_TEST_TYPE ] = 'form';
$_GET[ CheckView::PARAM_TEST_ID ]       = TEST_UUID;
$_REQUEST[ CheckView::PARAM_TEST_ID ]   = TEST_UUID;
$_SERVER['HTTP_X_CHECKVIEW_SIGNATURE']  = cv_t_signature();
$_SERVER['REMOTE_ADDR']                 = BOT_IP;
check( 'signed test: is a bot', true === CheckView::is_bot() );
check( 'signed test: Solid Security allowlists the bot IP', in_array( BOT_IP, cv_t_firewall( 'itsec_white_ips' ), true ) );
check( 'signed test: Defender allowlists the bot IP', in_array( BOT_IP, cv_t_firewall( 'ip_lockout_default_whitelist_ip' ), true ) );
check( 'signed test: order email goes to CheckView', CHECKVIEW_EMAIL === cv_t_order_email() );

// 2a. A signed test on a site whose stored list ran out still fetches a fresh one.
foreach ( array( 'expired', 'empty' ) as $cache ) {
	cv_t_site_with_cache( $cache );
	$_REQUEST[ CheckView::PARAM_TEST_TYPE ] = 'form';
	$_REQUEST[ CheckView::PARAM_TEST_ID ]   = TEST_UUID;
	$_SERVER['HTTP_X_CHECKVIEW_SIGNATURE']  = cv_t_signature();
	$_SERVER['REMOTE_ADDR']                 = BOT_IP;
	check( "signed test ($cache cache): Defender allowlists the bot IP", in_array( BOT_IP, cv_t_firewall( 'ip_lockout_default_whitelist_ip' ), true ) );
	check( "signed test ($cache cache): fetched the list once", 1 === count( $GLOBALS['cv_t']['http'] ) );
	check( "signed test ($cache cache): stored the fresh list", array( BOT_IP ) === ( $GLOBALS['cv_t']['store']['_transient_checkview_saas_ip_address'] ?? null ) );
}

// 2b. Signed test whose test type comes from the referrer (AJAX submit).
cv_t_site_with_cache();
$_SERVER['HTTP_REFERER']               = SITE_URL . '/contact/?' . CheckView::PARAM_TEST_TYPE . '=form&' . CheckView::PARAM_TEST_ID . '=' . TEST_UUID;
$_SERVER['HTTP_X_CHECKVIEW_SIGNATURE'] = cv_t_signature();
check( 'signed AJAX test (referrer): is a bot', true === CheckView::is_bot() );

// 3. Forged requests are rejected.
cv_t_site_with_cache();
$_REQUEST[ CheckView::PARAM_TEST_TYPE ] = 'form';
$_SERVER['HTTP_X_FORWARDED_FOR']        = BOT_IP;
check( 'test type + spoofed X-Forwarded-For, no signature: not a bot', false === CheckView::is_bot() );

cv_t_site_with_cache();
$_REQUEST[ CheckView::PARAM_TEST_TYPE ] = 'form';
$_SERVER['HTTP_X_CHECKVIEW_SIGNATURE']  = 'not-a-jwt';
check( 'test type + garbage signature: not a bot', false === CheckView::is_bot() );

cv_t_site_with_cache();
$other_key = openssl_pkey_new( array( 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA ) );
openssl_pkey_export( $other_key, $other_private );
$_REQUEST[ CheckView::PARAM_TEST_TYPE ] = 'form';
$_SERVER['HTTP_X_CHECKVIEW_SIGNATURE']  = cv_t_signature( $other_private );
$_SERVER['HTTP_X_FORWARDED_FOR']        = BOT_IP;
check( 'test type + signature from another key + spoofed XFF: not a bot', false === CheckView::is_bot() );

cv_t_site_with_cache();
$_SERVER['HTTP_X_CHECKVIEW_SIGNATURE'] = cv_t_signature();
check( 'valid signature but no test type: not a bot', false === CheckView::is_bot() );

// 4. Transitional mode (signature optional): the allowlist still decides.
cv_t_site_with_cache();
$GLOBALS['cv_t']['filters']['checkview_require_signed_request'] = false;
$_REQUEST[ CheckView::PARAM_TEST_TYPE ] = 'form';
$_SERVER['REMOTE_ADDR']                 = BOT_IP;
check( 'transitional: unsigned test from a listed IP is a bot', true === CheckView::is_bot() );
check( 'transitional: that check read the allowlist', cv_t_allowlist_reads() > 0 );

cv_t_site_with_cache();
$GLOBALS['cv_t']['filters']['checkview_require_signed_request'] = false;
$_REQUEST[ CheckView::PARAM_TEST_TYPE ] = 'form';
check( 'transitional: unsigned test from an unlisted IP is not a bot', false === CheckView::is_bot() );

cv_t_site_with_cache();
$GLOBALS['cv_t']['filters']['checkview_require_signed_request'] = false;
$_SERVER['REMOTE_ADDR'] = BOT_IP;
check( 'transitional: listed IP without a test type is not a bot', false === CheckView::is_bot() );
check( 'transitional: no test type means no allowlist read', 0 === cv_t_allowlist_reads() );

// 5. Firewalls: a CheckView REST call from the SaaS is still allowlisted.
foreach ( array( '/wp-json/checkview/v1/forms/formslist', '/?rest_route=/checkview/v1/forms/formslist' ) as $uri ) {
	cv_t_site_with_cache();
	$_SERVER['REQUEST_URI'] = $uri;
	$_SERVER['REMOTE_ADDR'] = BOT_IP;
	if ( false !== strpos( $uri, 'rest_route' ) ) {
		$_GET['rest_route'] = '/checkview/v1/forms/formslist';
	}
	check( "REST $uri from the SaaS: Defender allowlists it", in_array( BOT_IP, cv_t_firewall( 'ip_lockout_default_whitelist_ip' ), true ) );

	$_SERVER['REMOTE_ADDR'] = OTHER_IP;
	check( "REST $uri from elsewhere: not allowlisted", array( 'existing' ) === cv_t_firewall( 'ip_lockout_default_whitelist_ip' ) );
}

// 6. Order emails: test orders still go to CheckView without the allowlist.
cv_t_site_with_cache();
check( 'order paid with the CheckView gateway goes to CheckView', CHECKVIEW_EMAIL === cv_t_order_email( 'checkview' ) );
check( 'CheckView gateway order: no allowlist read', 0 === cv_t_allowlist_reads() );

cv_t_site_with_cache();
$_REQUEST[ CheckView::PARAM_TEST_ID ] = TEST_UUID;
check( 'order with a test ID goes to CheckView', CHECKVIEW_EMAIL === cv_t_order_email() );

$GLOBALS['cv_t_done'] = true;
echo "\n$cv_t_count checks, $cv_t_failures failed\n";
exit( $cv_t_failures ? 1 : 0 );
