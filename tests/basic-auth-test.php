<?php
/**
 * Tests the two changes that let CheckView reach a site behind HTTP basic auth:
 * the API permission check reading the token from `_checkview_token` when the
 * `Authorization` header holds the gate's credentials, and the filter that
 * keeps WordPress from reading those credentials as an application password
 * login on a verified CheckView request.
 *
 * On CheckView's own routes the 401 from a failed application password login
 * is discarded by Checkview_Admin::bypass_rest_authentication(), which is what
 * lets CheckView's server calls through; the last block pins that. The filter
 * is for the test browser's requests to other REST routes (for example a form
 * that submits over the REST API), which that bypass does not cover.
 *
 * Self-contained: WordPress is stubbed below, so this runs with plain PHP
 * (8.0+) from the plugin root:
 *
 *     php tests/basic-auth-test.php
 *
 * The API permission check runs against a small in-memory stand-in for the
 * nonce table, to show a token sent in the URL is still single use.
 *
 * Exits non-zero when any assertion fails. WordPress's own order of events
 * (the current user asked for during a REST request, before core's check) is
 * not reproducible with stubs; it is checked on a real site.
 *
 * @package CheckView
 */

// phpcs:disable -- Test script with WordPress stubs; not shipped (see .distignore).

// Command line only: if a copy of this file reaches a site (deploys installed
// branch zips that carried it), a web request must not run it.
if ( 'cli' !== PHP_SAPI ) {
	http_response_code( 404 );
	exit( 1 ); // Non-zero so a shell run under phpdbg or php-cgi fails instead of passing.
}

define( 'ABSPATH', __DIR__ . '/' );
define( 'WPINC', 'wp-includes' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'CHECKVIEW_INC_DIR', dirname( __DIR__ ) . '/includes/' );

const SITE_URL = 'https://example.test';

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
	return $value;
}

/** The filter WordPress is currently running, as doing_filter() reports it. */
$GLOBALS['cv_t_doing'] = '';
function doing_filter( $hook = null ) {
	return $hook === $GLOBALS['cv_t_doing'];
}

/**
 * Public key reads stand in for "the validator ran": checkview_validate_jwt_token()
 * reads the key first. A callback set here runs during that read, to act like
 * code that asks for the current user again while a token is being validated.
 * With `cv_t_key_available` false the key is not cached and its download has
 * just failed, so checkview_get_publickey() has no key to give.
 */
$GLOBALS['cv_t_key_reads']     = 0;
$GLOBALS['cv_t_during_check']  = null;
$GLOBALS['cv_t_key_available'] = true;
$GLOBALS['cv_t_transients']    = array();
function get_transient( $name ) {
	if ( 'checkview_saas_pk' === $name ) {
		++$GLOBALS['cv_t_key_reads'];
		if ( is_callable( $GLOBALS['cv_t_during_check'] ) ) {
			call_user_func( $GLOBALS['cv_t_during_check'] );
		}
		return $GLOBALS['cv_t_key_available'] ? $GLOBALS['cv_t_public'] : false;
	}
	if ( 'checkview_saas_pk_fetch_failed' === $name ) {
		return ! $GLOBALS['cv_t_key_available'];
	}
	return $GLOBALS['cv_t_transients'][ $name ] ?? false;
}
function set_transient( $name, $value, $expiration = 0 ) {
	$GLOBALS['cv_t_transients'][ $name ] = $value;
	return true;
}
function get_option( $name, $default = false ) {
	return $default;
}
function update_option( $name, $value, $autoload = null ) {
	return true;
}
function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}
function wp_unslash( $v ) {
	return is_string( $v ) ? stripslashes( $v ) : $v;
}
function sanitize_text_field( $v ) {
	return trim( strip_tags( (string) $v ) );
}
function esc_html( $v ) {
	return $v;
}
function get_bloginfo( $what ) {
	return SITE_URL;
}
function esc_html__( $text, $domain = 'default' ) {
	return $text;
}
function nocache_headers() {
}
function is_ssl() {
	return true;
}
$GLOBALS['cv_t_logged_in'] = false;
function is_user_logged_in() {
	return $GLOBALS['cv_t_logged_in'];
}
function wp_using_ext_object_cache( $using = null ) {
	return false;
}
class CheckView {
	const PARAM_TEST_ID = 'checkview_test_id';
	public static function is_checkview_dev(): bool {
		return false;
	}
}
class WP_REST_Request {
	private $headers;
	private $params;
	public function __construct( $headers = array(), $params = array() ) {
		$this->headers = $headers;
		$this->params  = $params;
	}
	public function get_header( $name ) {
		return $this->headers[ strtolower( $name ) ] ?? null;
	}
	public function get_param( $name ) {
		return $this->params[ $name ] ?? null;
	}
}
/** Enough of $wpdb for the nonce table in the API permission check. */
class CV_T_WPDB {
	public $prefix     = 'wp_';
	public $last_error = '';
	public $used       = array();
	public function prepare( $sql, ...$args ) {
		return array( $sql, $args );
	}
	public function get_var( $q ) {
		list( $sql, $args ) = $q;
		if ( 0 === strpos( $sql, 'SHOW TABLES' ) ) {
			return $args[0];
		}
		if ( 0 === strpos( $sql, 'SELECT COUNT(*)' ) ) {
			return in_array( end( $args ), $this->used, true ) ? '1' : '0';
		}
		throw new RuntimeException( 'Unexpected query in the test: ' . $sql );
	}
	public function insert( $table, $data ) {
		$this->used[] = $data['nonce'];
		return 1;
	}
	public function get_charset_collate() {
		return '';
	}
}
$GLOBALS['wpdb'] = new CV_T_WPDB();

/* ---- Code under test ---------------------------------------------------- */

require CHECKVIEW_INC_DIR . 'vendor/autoload.php';
require CHECKVIEW_INC_DIR . 'checkview-functions.php';
require CHECKVIEW_INC_DIR . 'API/class-checkview-api.php';
require dirname( __DIR__ ) . '/admin/class-checkview-admin.php';

use Firebase\JWT\JWT;

$cv_t_key    = openssl_pkey_new( array( 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA ) );
$cv_t_public = openssl_pkey_get_details( $cv_t_key )['key'];
openssl_pkey_export( $cv_t_key, $cv_t_private );

$cv_t_other_key = openssl_pkey_new( array( 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA ) );
openssl_pkey_export( $cv_t_other_key, $cv_t_other_private );

/** A random version 4 UUID, the shape CheckView's nonces have. */
function cv_t_uuid() {
	$b    = random_bytes( 16 );
	$b[6] = chr( ord( $b[6] ) & 0x0f | 0x40 );
	$b[8] = chr( ord( $b[8] ) & 0x3f | 0x80 );
	return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $b ), 4 ) );
}

/** A token CheckView would send, signed with the key the site trusts unless told otherwise. */
function cv_t_token( $overrides = array(), $private_key = null ) {
	return JWT::encode(
		array_merge(
			array(
				'websiteUrl'       => SITE_URL,
				'exp'              => time() + 60,
				'_checkview_nonce' => cv_t_uuid(),
			),
			$overrides
		),
		$private_key ?? $GLOBALS['cv_t_private'],
		'RS256'
	);
}

/**
 * A fresh request: no headers, no query, outside any filter. Each one gets its
 * own start time, as a real request does, so the opt-out's per-request answer
 * from an earlier case cannot decide a later one.
 */
$GLOBALS['cv_t_request_n'] = 0;
function cv_t_request( $server = array(), $get = array(), $doing = 'determine_current_user' ) {
	$_SERVER                      = $server + array( 'REQUEST_TIME_FLOAT' => 1700000000 + ++$GLOBALS['cv_t_request_n'] );
	$_GET                         = $get;
	$GLOBALS['cv_t_doing']        = $doing;
	$GLOBALS['cv_t_key_reads']     = 0;
	$GLOBALS['cv_t_during_check']  = null;
	$GLOBALS['cv_t_key_available'] = true;
	$GLOBALS['cv_t_transients']    = array();
	Checkview_Admin_Logs::$lines   = array();
}

/** Runs the filter the way WordPress applies it inside the login attempt. */
function cv_t_filter( $is_api_request = true ) {
	return checkview_skip_application_password_for_checkview( $is_api_request );
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

/* ---- Cases: which token the API permission check uses ------------------- */

$cv_t_jwt = cv_t_token();

check( 'header: a Bearer header is used as before, even with a query token', 'Bearer header-jwt' === checkview_auth_header_for_request( 'Bearer header-jwt', $cv_t_jwt ) );
check( 'header: a Basic header with a query token uses the query token', 'Bearer ' . $cv_t_jwt === checkview_auth_header_for_request( 'Basic Z2F0ZTpzZWNyZXQ=', $cv_t_jwt ) );
check( 'header: an empty header with a query token uses the query token', 'Bearer ' . $cv_t_jwt === checkview_auth_header_for_request( '', $cv_t_jwt ) );
check( 'header: a Basic header with no query token is unchanged', 'Basic Z2F0ZTpzZWNyZXQ=' === checkview_auth_header_for_request( 'Basic Z2F0ZTpzZWNyZXQ=', null ) );
check( 'header: no header and no query token stays empty', '' === checkview_auth_header_for_request( '', null ) );
check( 'header: a blank query token is ignored', '' === checkview_auth_header_for_request( '', '   ' ) );
check( 'header: an array query token is ignored', 'Basic x' === checkview_auth_header_for_request( 'Basic x', array( $cv_t_jwt ) ) );
check( 'header: a query token already prefixed is not prefixed twice', 'Bearer ' . $cv_t_jwt === checkview_auth_header_for_request( '', 'Bearer ' . $cv_t_jwt ) );

/* ---- Cases: the application password opt-out --------------------------- */

check( 'opt-out: registered on application_password_is_api_request', in_array( 'checkview_skip_application_password_for_checkview', $GLOBALS['cv_t_hooks']['application_password_is_api_request'] ?? array(), true ) );

cv_t_request( array( 'HTTP_X_CHECKVIEW_SIGNATURE' => cv_t_token() ) );
check( 'opt-out: a page view (input false) stays false', false === cv_t_filter( false ) );
check( 'opt-out: a page view never runs the validator', 0 === $GLOBALS['cv_t_key_reads'] );

cv_t_request( array( 'HTTP_X_CHECKVIEW_SIGNATURE' => cv_t_token() ), array(), 'authenticate' );
check( 'opt-out: outside the current-user lookup the input is kept', true === cv_t_filter() );
check( 'opt-out: outside the current-user lookup the validator does not run', 0 === $GLOBALS['cv_t_key_reads'] );

cv_t_request();
check( 'opt-out: a request with no CheckView token keeps application passwords on', true === cv_t_filter() );
check( 'opt-out: a request with no CheckView token never runs the validator', 0 === $GLOBALS['cv_t_key_reads'] );
check( 'opt-out: a request with no CheckView token writes no log line', array() === Checkview_Admin_Logs::$lines );

cv_t_request( array( 'HTTP_X_CHECKVIEW_SIGNATURE' => cv_t_token() ) );
check( 'opt-out: a valid browser signature turns application passwords off', false === cv_t_filter() );

cv_t_request( array( 'HTTP_X_CHECKVIEW_SIGNATURE' => 'Bearer ' . cv_t_token() ) );
check( 'opt-out: a valid signature with a Bearer prefix also works', false === cv_t_filter() );

cv_t_request( array(), array( '_checkview_token' => cv_t_token() ) );
check( 'opt-out: a valid query token turns application passwords off', false === cv_t_filter() );

cv_t_request( array( 'HTTP_X_CHECKVIEW_SIGNATURE' => cv_t_token( array(), $cv_t_other_private ) ) );
check( 'opt-out: a signature from another key keeps application passwords on', true === cv_t_filter() );

cv_t_request( array( 'HTTP_X_CHECKVIEW_SIGNATURE' => cv_t_token( array( 'exp' => time() - 600 ) ) ) );
check( 'opt-out: an expired token keeps application passwords on', true === cv_t_filter() );

cv_t_request( array( 'HTTP_X_CHECKVIEW_SIGNATURE' => cv_t_token( array( 'websiteUrl' => 'https://other.test' ) ) ) );
check( 'opt-out: a token for another site keeps application passwords on', true === cv_t_filter() );

cv_t_request( array( 'HTTP_X_CHECKVIEW_SIGNATURE' => 'not-a-jwt' ) );
check( 'opt-out: garbage in the signature header keeps application passwords on', true === cv_t_filter() );

cv_t_request( array( 'HTTP_X_CHECKVIEW_SIGNATURE' => 'not-a-jwt-either' ), array( '_checkview_token' => cv_t_token() ) );
check( 'opt-out: a bad signature header is not rescued by a good query token', true === cv_t_filter() );
check( 'opt-out: only the signature header was validated', 1 === $GLOBALS['cv_t_key_reads'] );

cv_t_request( array(), array( '_checkview_token' => array( cv_t_token() ) ) );
check( 'opt-out: an array query token is ignored', true === cv_t_filter() );
check( 'opt-out: an array query token never runs the validator', 0 === $GLOBALS['cv_t_key_reads'] );

// WordPress can look up the current user more than once in a request.
cv_t_request( array( 'HTTP_X_CHECKVIEW_SIGNATURE' => cv_t_token() ) );
$cv_t_first = cv_t_filter();
check( 'opt-out: a second lookup in the same request gives the same answer', false === $cv_t_first && false === cv_t_filter() );
check( 'opt-out: a second lookup in the same request does not validate again', 1 === $GLOBALS['cv_t_key_reads'] );

// A runtime that keeps PHP running between requests: the same token on the
// next request is validated again, not answered from the last one.
$cv_t_same = cv_t_token();
cv_t_request( array( 'HTTP_X_CHECKVIEW_SIGNATURE' => $cv_t_same ) );
cv_t_filter();
cv_t_request( array( 'HTTP_X_CHECKVIEW_SIGNATURE' => $cv_t_same ) );
cv_t_filter();
check( 'opt-out: the same token on a later request is validated again', 1 === $GLOBALS['cv_t_key_reads'] );

// Anyone can send a Basic header with a made-up token: the validator's own
// lines are kept out, and the opt-out writes one line per 10 minutes.
cv_t_request( array( 'HTTP_X_CHECKVIEW_SIGNATURE' => cv_t_token( array(), $cv_t_other_private ) ) );
cv_t_filter();
check( 'opt-out: a refused token writes one log line', 1 === count( Checkview_Admin_Logs::$lines ) );
$_SERVER['HTTP_X_CHECKVIEW_SIGNATURE'] = cv_t_token( array( 'exp' => time() - 600 ) );
cv_t_filter();
$_SERVER['HTTP_X_CHECKVIEW_SIGNATURE'] = 'still-not-a-jwt';
cv_t_filter();
check( 'opt-out: further refused tokens within 10 minutes write nothing', 1 === count( Checkview_Admin_Logs::$lines ) );

// The public key is not cached and cannot be downloaded right now.
cv_t_request( array( 'HTTP_X_CHECKVIEW_SIGNATURE' => cv_t_token() ) );
$GLOBALS['cv_t_key_available'] = false;
check( 'opt-out: with no public key, application passwords stay on', true === cv_t_filter() );
check( 'opt-out: with no public key, one log line is written', 1 === count( Checkview_Admin_Logs::$lines ) );

// Something asks for the current user again while the token is being
// validated: the nested call must not let the login attempt run, and must not
// validate again.
cv_t_request( array( 'HTTP_X_CHECKVIEW_SIGNATURE' => cv_t_token() ) );
$cv_t_nested = 'not run';
$GLOBALS['cv_t_during_check'] = function () use ( &$cv_t_nested ) {
	$GLOBALS['cv_t_during_check'] = null; // Only the first read re-enters.
	$cv_t_nested                  = cv_t_filter();
};
$cv_t_outer = cv_t_filter();
check( 'opt-out: a nested call during validation turns application passwords off', false === $cv_t_nested );
check( 'opt-out: a nested call during validation does not validate again', 1 === $GLOBALS['cv_t_key_reads'] );
check( 'opt-out: the outer call still turns application passwords off', false === $cv_t_outer );

// The re-entry guard is released afterwards, even when validation fails.
cv_t_request( array( 'HTTP_X_CHECKVIEW_SIGNATURE' => 'not-a-jwt' ) );
cv_t_filter();
cv_t_request( array( 'HTTP_X_CHECKVIEW_SIGNATURE' => cv_t_token() ) );
check( 'opt-out: the guard is released after a failed validation', false === cv_t_filter() );

// A nested call during a validation that then fails: the outer call still
// leaves application passwords on.
cv_t_request( array( 'HTTP_X_CHECKVIEW_SIGNATURE' => cv_t_token( array(), $cv_t_other_private ) ) );
$GLOBALS['cv_t_during_check'] = function () {
	$GLOBALS['cv_t_during_check'] = null;
	cv_t_filter();
};
check( 'opt-out: a failed validation with a nested call keeps application passwords on', true === cv_t_filter() );

/* ---- Cases: the API permission check, end to end ----------------------- */

$cv_t_api = new CheckView_Api();

// A gated site: the Authorization header holds the gate's credentials and the
// token arrives in the query. The opt-out runs first (as it would during
// WordPress's current-user lookup) to show it leaves the nonce unused, not
// because this route needs it: see the next block.
$cv_t_query_jwt = cv_t_token();
$cv_t_gated     = new WP_REST_Request( array( 'authorization' => 'Basic Z2F0ZTpzZWNyZXQ=' ), array( '_checkview_token' => $cv_t_query_jwt ) );
cv_t_request( array(), array( '_checkview_token' => $cv_t_query_jwt ) );
check( 'api: the opt-out accepts the query token first', false === cv_t_filter() );
$cv_t_result = $cv_t_api->checkview_get_items_permissions_check( $cv_t_gated );
check( 'api: a Basic header with a fresh query token is let in', is_array( $cv_t_result ) && 'jwt_auth_valid_token' === $cv_t_result['code'] );
check( 'api: the query token\'s nonce is recorded as used', 1 === count( $GLOBALS['wpdb']->used ) );
cv_t_request();
$cv_t_result = $cv_t_api->checkview_get_items_permissions_check( $cv_t_gated );
check( 'api: the same query token is refused the second time', is_wp_error( $cv_t_result ) && in_array( 'This nonce has already been used.', Checkview_Admin_Logs::$lines, true ) );

cv_t_request();
$cv_t_result = $cv_t_api->checkview_get_items_permissions_check( new WP_REST_Request( array( 'authorization' => 'Basic Z2F0ZTpzZWNyZXQ=' ), array() ) );
check( 'api: a Basic header with no query token is refused', is_wp_error( $cv_t_result ) );

cv_t_request();
$cv_t_result = $cv_t_api->checkview_get_items_permissions_check( new WP_REST_Request( array( 'authorization' => 'Basic Z2F0ZTpzZWNyZXQ=' ), array( '_checkview_token' => cv_t_token( array(), $cv_t_other_private ) ) ) );
check( 'api: a query token signed with another key is refused', is_wp_error( $cv_t_result ) );

cv_t_request();
$cv_t_result = $cv_t_api->checkview_get_items_permissions_check( new WP_REST_Request( array( 'authorization' => 'Bearer ' . cv_t_token() ), array( '_checkview_token' => 'not-a-jwt' ) ) );
check( 'api: a Bearer header still wins over the query token', is_array( $cv_t_result ) );

cv_t_request();
$cv_t_result = $cv_t_api->checkview_get_items_permissions_check( new WP_REST_Request( array( 'authorization' => 'Bearer ' . cv_t_token( array(), $cv_t_other_private ) ), array( '_checkview_token' => cv_t_token() ) ) );
check( 'api: a bad Bearer header is not rescued by a good query token', is_wp_error( $cv_t_result ) );

/* ---- Cases: the 401 on CheckView's own routes ------------------------- */

// Without the opt-out, a failed application password login leaves WordPress
// with a 401 on every REST route. On CheckView's routes the helper's own
// rest_authentication_errors hook discards it for a logged-out request; that
// is what lets the server calls in. If that hook is narrowed, these fail.
$GLOBALS['wp']   = new stdClass();
$cv_t_app_pw_401 = new WP_Error( 'invalid_username', 'Unknown username.', array( 'status' => 401 ) );

$GLOBALS['wp']->query_vars = array( 'rest_route' => '/checkview/v1/forms' );
check( 'bypass: the 401 is discarded on a CheckView route for a logged-out request', null === Checkview_Admin::bypass_rest_authentication( $cv_t_app_pw_401 ) );

$GLOBALS['cv_t_logged_in'] = true;
check( 'bypass: the 401 is kept on a CheckView route when a user is logged in', $cv_t_app_pw_401 === Checkview_Admin::bypass_rest_authentication( $cv_t_app_pw_401 ) );
$GLOBALS['cv_t_logged_in'] = false;

// Other plugins' routes keep the 401, which is why the opt-out exists.
$GLOBALS['wp']->query_vars = array( 'rest_route' => '/contact-form-7/v1/contact-forms/5/feedback' );
check( 'bypass: the 401 is kept on another plugin\'s route', $cv_t_app_pw_401 === Checkview_Admin::bypass_rest_authentication( $cv_t_app_pw_401 ) );

$GLOBALS['cv_t_done'] = true;
echo "\n$cv_t_count checks, $cv_t_failures failed\n";
exit( $cv_t_failures ? 1 : 0 );
