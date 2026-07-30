<?php
/**
 * Verification for the WP Armour unhooks in the GF and Fluent helpers.
 *
 * Drives the REAL methods against an emulation of remove_action/has_action, and
 * reproduces WP Armour's own registration from
 * honeypot/includes/integration/wpa_{gravityforms,fluentform}.php.
 */

require_once __DIR__ . '/../bootstrap.php';

cv_need_fixture( 'honeypot' );
cv_need_change( 'includes/formhelpers/class-checkview-gforms-helper.php', 'checkview_unhook_wp_armour', 'PR #240' );

define( 'WPINC', 1 );
define( 'TEST_EMAIL', 'test@test-mail.checkview.io' );

$GLOBALS['cv_log']    = array();
$GLOBALS['wp_hooks']  = array();

function add_action( $tag, $cb, $priority = 10, $args = 1 ) {
	$GLOBALS['wp_hooks'][ $tag ][ $priority ][ _id( $cb ) ] = $cb;
}
function add_filter( $tag, $cb, $priority = 10, $args = 1 ) { add_action( $tag, $cb, $priority, $args ); }
function _id( $cb ) {
	if ( is_array( $cb ) ) {
		return ( is_object( $cb[0] ) ? spl_object_hash( $cb[0] ) : (string) $cb[0] ) . '::' . $cb[1];
	}
	if ( is_object( $cb ) ) {        // closures, as WP does
		return spl_object_hash( $cb );
	}
	return (string) $cb;
}
function remove_action( $tag, $cb, $priority = 10 ) {
	$id = _id( $cb );
	if ( isset( $GLOBALS['wp_hooks'][ $tag ][ $priority ][ $id ] ) ) {
		unset( $GLOBALS['wp_hooks'][ $tag ][ $priority ][ $id ] );
		return true;
	}
	return false;
}
function remove_filter( $tag, $cb, $priority = 10 ) { return remove_action( $tag, $cb, $priority ); }
function has_action( $tag, $cb ) {
	foreach ( $GLOBALS['wp_hooks'][ $tag ] ?? array() as $p => $cbs ) {
		if ( isset( $cbs[ _id( $cb ) ] ) ) { return $p; }
	}
	return false;
}
function apply_filters( $t, $v ) { return $v; }
function __return_false() { return false; }
function __return_true() { return true; }
function __return_null() { return null; }
function __return_empty_string() { return ''; }
function get_option( $k, $d = false ) { return $d; }
function wp_json_encode( $v ) { return json_encode( $v ); }
function get_checkview_test_id() { return 'cv-test-123'; }
function complete_checkview_test( $id ) {}
function checkview_truncate_meta_key( $k ) { return $k; }
function checkview_truncate_for_cv_entry( $r ) { return $r; }
function checkview_ff_should_defer_delete() { return true; }
function checkview_gf_should_defer_delete() { return true; }
function current_time( $t ) { return '2026-01-01 00:00:00'; }
function sanitize_url( $u ) { return $u; }
function wp_unslash( $v ) { return $v; }
function maybe_serialize( $v ) { return is_array( $v ) ? serialize( $v ) : $v; }
function maybe_unserialize( $v ) { return $v; }
function esc_html__( $s, $d = '' ) { return $s; }
function rgar( $a, $k ) { return $a[ $k ] ?? ''; }
function cv_should_allow_original_recipients() { return false; }
function cv_append_test_email_array( $r ) { return (array) $r; }
function cv_append_test_email_string( $r ) { return (string) $r; }
function cv_inject_reply_to_header( $h ) { return $h; }

class Checkview_Loader {}
class Checkview_Admin_Logs {
	public static function add( $c, $m ) { $GLOBALS['cv_log'][] = $m; }
}


$base = CV_PLUGIN_DIR . '/includes/formhelpers/';

// ---------------------------------------------------------------- GRAVITY FORMS
echo "\n=== Gravity Forms ===\n";
$GLOBALS['wp_hooks'] = array();
require_once $base . 'class-checkview-gforms-helper.php';
$gf = ( new ReflectionClass( 'Checkview_Gforms_Helper' ) )->newInstanceWithoutConstructor();

cv_ok( method_exists( $gf, 'checkview_unhook_wp_armour' ), 'GF helper exposes the unhook' );

// WP Armour's own registration (wpa_gravityforms.php:6).
function wpa_gravityforms_extra_validation( $validation_result ) {
	$validation_result['is_valid'] = false;
	return $validation_result;
}
add_action( 'gform_validation', 'wpa_gravityforms_extra_validation' );
// CheckView's own bypass sits on the same hook and must survive.
add_filter( 'gform_validation', array( $gf, 'checkview_bypass_captcha_validation' ), PHP_INT_MAX );

cv_ok( 10 === has_action( 'gform_validation', 'wpa_gravityforms_extra_validation' ), 'precondition: WP Armour at priority 10' );

$GLOBALS['cv_log'] = array();
$gf->checkview_unhook_wp_armour();

cv_ok( false === has_action( 'gform_validation', 'wpa_gravityforms_extra_validation' ), "WP Armour's GF callback removed" );
cv_ok( PHP_INT_MAX === has_action( 'gform_validation', array( $gf, 'checkview_bypass_captcha_validation' ) ), "CheckView's own gform_validation bypass NOT removed" );
cv_ok( false !== strpos( implode( ' | ', $GLOBALS['cv_log'] ), 'Unhooked WP Armour' ), 'logs the removal' );

$GLOBALS['cv_log'] = array();
$gf->checkview_unhook_wp_armour();
cv_ok( false !== strpos( implode( ' | ', $GLOBALS['cv_log'] ), 'not removable' ), 'second run reports drift (function present, nothing hooked)' );

echo "\n-- why a marker fallback would NOT have worked --\n";
// The failure lands on an arbitrary field with an operator-configurable message.
$markers = array( 'captcha', 'recaptcha', 'are you human', 'looks like spam' );
$wpa_msg = strtolower( ' Spamming or your Javascript is disabled !!' );
$hit = false;
foreach ( $markers as $mk ) { if ( false !== strpos( $wpa_msg, $mk ) ) { $hit = true; } }
cv_ok( ! $hit, "WP Armour's default message matches none of the anti-bot markers" );

// ---------------------------------------------------------------- FLUENT FORMS
echo "\n=== Fluent Forms ===\n";
$GLOBALS['wp_hooks'] = array();
require_once $base . 'class-checkview-fluent-forms-helper.php';
$ff = ( new ReflectionClass( 'Checkview_Fluent_Forms_Helper' ) )->newInstanceWithoutConstructor();

cv_ok( method_exists( $ff, 'checkview_unhook_wp_armour' ), 'Fluent helper exposes the unhook' );

function wpa_fluent_form_extra_validation( $insertData, $data, $form ) {}
add_action( 'fluentform/before_insert_submission', 'wpa_fluent_form_extra_validation', 10, 3 );
add_action( 'fluentform_before_insert_submission', 'wpa_fluent_form_extra_validation', 10, 3 );
// A Fluent core callback on the same hook must survive.
$core = new class { public function insert() {} };
add_action( 'fluentform/before_insert_submission', array( $core, 'insert' ), 20 );

$GLOBALS['cv_log'] = array();
$ff->checkview_unhook_wp_armour();

cv_ok( false === has_action( 'fluentform/before_insert_submission', 'wpa_fluent_form_extra_validation' ), 'slash-style hook cleared' );
cv_ok( false === has_action( 'fluentform_before_insert_submission', 'wpa_fluent_form_extra_validation' ), 'legacy underscore hook cleared too' );
cv_ok( 20 === has_action( 'fluentform/before_insert_submission', array( $core, 'insert' ) ), "Fluent's own callback on the same hook untouched" );
cv_ok( false !== strpos( implode( ' | ', $GLOBALS['cv_log'] ), 'Unhooked WP Armour from [2]' ), 'logs both removals' );

$GLOBALS['cv_log'] = array();
$ff->checkview_unhook_wp_armour();
cv_ok( false !== strpos( implode( ' | ', $GLOBALS['cv_log'] ), 'not removable' ), 'second run reports drift' );

echo "\n-- why nothing but an unhook could work here --\n";
$src = file_get_contents( '/private/tmp/claude-501/-Users-schwartzen-Local-Sites-checkview-helper-helper/c4849e8e-5036-4b73-99c9-57d3cd37be5f/scratchpad/honeypot/includes/integration/wpa_fluentform.php' );
cv_ok( false !== strpos( $src, 'wp_send_json_error' ) && false !== strpos( $src, 'wp_die' ), 'WP Armour terminates the request rather than returning a verdict' );

echo "\n=== absent plugin is silent ===\n";
$GLOBALS['wp_hooks'] = array();
$GLOBALS['cv_log']   = array();
// Functions still exist in this process, so the drift branch is expected here;
// what matters is that nothing fatals and no hook is touched.
$gf->checkview_unhook_wp_armour();
$ff->checkview_unhook_wp_armour();
cv_ok( array() === ( $GLOBALS['wp_hooks'] ?? array() ), 'no hooks were added or mutated' );

cv_finish();
