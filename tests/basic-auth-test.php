<?php
/**
 * Tests the two changes that let CheckView reach a site behind HTTP basic auth:
 * the API permission check reading the token from `_checkview_token` when the
 * `Authorization` header holds the gate's credentials, and the filter that
 * keeps WordPress from reading those credentials as an application password
 * login on a verified CheckView request.
 *
 * Self-contained: WordPress is stubbed below, so this runs with plain PHP
 * (8.0+) from the plugin root:
 *
 *     php tests/basic-auth-test.php
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
 */
$GLOBALS['cv_t_key_reads']    = 0;
$GLOBALS['cv_t_during_check'] = null;
function get_transient( $name ) {
	if ( 'checkview_saas_pk' === $name ) {
		++$GLOBALS['cv_t_key_reads'];
		if ( is_callable( $GLOBALS['cv_t_during_check'] ) ) {
			call_user_func( $GLOBALS['cv_t_during_check'] );
		}
		return $GLOBALS['cv_t_public'];
	}
	return false;
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

/* ---- Code under test ---------------------------------------------------- */

require CHECKVIEW_INC_DIR . 'vendor/autoload.php';
require CHECKVIEW_INC_DIR . 'checkview-functions.php';

use Firebase\JWT\JWT;

$cv_t_key    = openssl_pkey_new( array( 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA ) );
$cv_t_public = openssl_pkey_get_details( $cv_t_key )['key'];
openssl_pkey_export( $cv_t_key, $cv_t_private );

$cv_t_other_key = openssl_pkey_new( array( 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA ) );
openssl_pkey_export( $cv_t_other_key, $cv_t_other_private );

/** A token CheckView would send, signed with the key the site trusts unless told otherwise. */
function cv_t_token( $overrides = array(), $private_key = null ) {
	return JWT::encode(
		array_merge(
			array(
				'websiteUrl'       => SITE_URL,
				'exp'              => time() + 60,
				'_checkview_nonce' => 'nonce-' . mt_rand(),
			),
			$overrides
		),
		$private_key ?? $GLOBALS['cv_t_private'],
		'RS256'
	);
}

/** A fresh request: no headers, no query, outside any filter. */
function cv_t_request( $server = array(), $get = array(), $doing = 'determine_current_user' ) {
	$_SERVER                      = $server;
	$_GET                         = $get;
	$GLOBALS['cv_t_doing']        = $doing;
	$GLOBALS['cv_t_key_reads']    = 0;
	$GLOBALS['cv_t_during_check'] = null;
	Checkview_Admin_Logs::$lines  = array();
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

$cv_t_reused = cv_t_token();
cv_t_request( array( 'HTTP_X_CHECKVIEW_SIGNATURE' => $cv_t_reused ) );
$cv_t_first = cv_t_filter();
cv_t_request( array( 'HTTP_X_CHECKVIEW_SIGNATURE' => $cv_t_reused ) );
check( 'opt-out: the nonce is not consumed, so the same token still validates', false === $cv_t_first && false === cv_t_filter() );

// Something asks for the current user again while the token is being
// validated: the nested call must return its input without validating again.
cv_t_request( array( 'HTTP_X_CHECKVIEW_SIGNATURE' => cv_t_token() ) );
$cv_t_nested = 'not run';
$GLOBALS['cv_t_during_check'] = function () use ( &$cv_t_nested ) {
	$GLOBALS['cv_t_during_check'] = null; // Only the first read re-enters.
	$cv_t_nested                  = cv_t_filter();
};
$cv_t_outer = cv_t_filter();
check( 'opt-out: a nested call during validation returns its input', true === $cv_t_nested );
check( 'opt-out: a nested call during validation does not validate again', 1 === $GLOBALS['cv_t_key_reads'] );
check( 'opt-out: the outer call still turns application passwords off', false === $cv_t_outer );

// The re-entry guard is released afterwards, even when validation fails.
cv_t_request( array( 'HTTP_X_CHECKVIEW_SIGNATURE' => 'not-a-jwt' ) );
cv_t_filter();
cv_t_request( array( 'HTTP_X_CHECKVIEW_SIGNATURE' => cv_t_token() ) );
check( 'opt-out: the guard is released after a failed validation', false === cv_t_filter() );

$GLOBALS['cv_t_done'] = true;
echo "\n$cv_t_count checks, $cv_t_failures failed\n";
exit( $cv_t_failures ? 1 : 0 );
