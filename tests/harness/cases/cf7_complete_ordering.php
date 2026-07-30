<?php
/**
 * Verification for the CF7 completion-ordering fix.
 *
 * Reproduces CF7's real submit sequence — before_send_mail() then mail() —
 * and checks that the per-test option survives long enough for the email
 * filter to read it.
 *
 *   submission.php:111  $abort = ! $this->before_send_mail();   -> wpcf7_before_send_mail
 *   submission.php:123  } elseif ( $this->mail() ) {            -> wpcf7_mail_components (mail.php:239)
 *   contact-form.php:1089  do_action( 'wpcf7_submit', ... )
 */

require_once __DIR__ . '/../bootstrap.php';

cv_need_change( 'includes/formhelpers/class-checkview-cf7-helper.php', 'pending_test_id', 'PR #241' );

define( 'WPINC', 1 );
define( 'TEST_EMAIL', 'test@test-mail.checkview.io' );

$GLOBALS['cv_log']      = array();
$GLOBALS['cv_actions']  = array();
$GLOBALS['cv_filters']  = array();
$GLOBALS['cv_opts']     = array();
$GLOBALS['cv_complete'] = 0;

function add_action( $tag, $cb, $priority = 10, $args = 1 ) { $GLOBALS['cv_actions'][ $tag ][] = array( 'cb' => $cb, 'priority' => $priority ); }
function add_filter( $tag, $cb, $priority = 10, $args = 1 ) { $GLOBALS['cv_filters'][ $tag ][] = array( 'cb' => $cb, 'priority' => $priority ); }
function apply_filters( $tag, $value ) {
	foreach ( $GLOBALS['cv_filters'][ $tag ] ?? array() as $c ) { $value = call_user_func( $c['cb'], $value ); }
	return $value;
}
function do_action( $tag, ...$args ) {
	foreach ( $GLOBALS['cv_actions'][ $tag ] ?? array() as $c ) { call_user_func_array( $c['cb'], $args ); }
}
function __return_false() { return false; }
function __return_true() { return true; }
function __return_null() { return null; }
function get_option( $k, $d = false ) { return $GLOBALS['cv_opts'][ $k ] ?? $d; }
function wp_json_encode( $v ) { return json_encode( $v ); }
function get_checkview_test_id() { return 'cv-test-123'; }
function esc_html__( $s, $d = '' ) { return $s; }
function current_time( $t ) { return '2026-01-01 00:00:00'; }
function sanitize_url( $u ) { return $u; }
function wp_unslash( $v ) { return $v; }
function maybe_unserialize( $v ) { return $v; }
function checkview_truncate_meta_key( $k ) { return $k; }
function cv_should_allow_original_recipients() { return false; }
function cv_append_test_email_array( $r ) { return (array) $r; }
function cv_append_test_email_string( $r ) { return (string) $r; }
function cv_inject_reply_to_header( $h ) { return $h; }

/** The real thing deletes disable_email_receipt_<id> — that is the whole point. */
function complete_checkview_test( $id ) {
	$GLOBALS['cv_complete']++;
	unset( $GLOBALS['cv_opts'][ 'disable_email_receipt_' . $id ] );
}

class Checkview_Loader {}
class Checkview_Admin_Logs {
	public static function add( $c, $m ) { $GLOBALS['cv_log'][] = $m; }
}

require_once CV_PLUGIN_DIR . '/includes/formhelpers/class-checkview-cf7-helper.php';


$h = ( new ReflectionClass( 'Checkview_CF7_Helper' ) )->newInstanceWithoutConstructor();

echo "\n=== hook wiring ===\n";
// Re-run the constructor's registrations against our registry.
$GLOBALS['cv_actions'] = array();
$GLOBALS['cv_filters'] = array();
( new ReflectionMethod( 'Checkview_CF7_Helper', '__construct' ) )->invoke( $h );
cv_ok( ! empty( $GLOBALS['cv_actions']['wpcf7_submit'] ), 'completion now hooked on wpcf7_submit' );
cv_ok( ! empty( $GLOBALS['cv_actions']['wpcf7_before_send_mail'] ), 'clone still on wpcf7_before_send_mail' );

/**
 * Runs CF7's real order: completion hook fires BEFORE the email filter.
 * Returns what the email filter saw.
 */
function cf7_submit_sequence( $h, bool $keep_original ) {
	$GLOBALS['cv_opts']     = $keep_original ? array( 'disable_email_receipt_cv-test-123' => 'true' ) : array();
	$GLOBALS['cv_complete'] = 0;

	// submission.php:111 — before_send_mail. Stand in for the clone handler by
	// setting the pending id the way it does.
	$rp = new ReflectionProperty( 'Checkview_CF7_Helper', 'pending_test_id' );
	$rp->setAccessible( true );
	$rp->setValue( $h, 'cv-test-123' );

	// submission.php:123 -> mail.php:239 — the email filter.
	$args = $h->checkview_inject_email(
		array( 'recipient' => 'real@customer.test', 'additional_headers' => "Cc: cc@customer.test\nBcc: bcc@customer.test" )
	);

	// contact-form.php:1089 — wpcf7_submit.
	$h->checkview_cf7_complete_after_submit();

	return array( 'args' => $args, 'complete' => $GLOBALS['cv_complete'] );
}

echo "\n=== keep-original-recipient mode now actually works ===\n";
$r = cf7_submit_sequence( $h, true );
cv_ok( false !== strpos( $r['args']['recipient'], 'real@customer.test' ), "the customer's recipient is PRESERVED" );
cv_ok( false !== strpos( $r['args']['recipient'], TEST_EMAIL ), 'and the test inbox is appended' );
cv_ok( 1 === $r['complete'], 'test still completes' );

echo "\n=== replace mode unchanged ===\n";
$r = cf7_submit_sequence( $h, false );
cv_ok( TEST_EMAIL === $r['args']['recipient'], 'recipient replaced with the test inbox' );
cv_ok( false === stripos( $r['args']['additional_headers'], 'Cc:' ), 'Cc stripped' );
cv_ok( false === stripos( $r['args']['additional_headers'], 'Bcc:' ), 'Bcc stripped' );
cv_ok( 1 === $r['complete'], 'test completes' );

echo "\n=== the OLD order is what broke it ===\n";
$GLOBALS['cv_opts'] = array( 'disable_email_receipt_cv-test-123' => 'true' );
complete_checkview_test( 'cv-test-123' );          // what before_send_mail used to do
$args = $h->checkview_inject_email( array( 'recipient' => 'real@customer.test', 'additional_headers' => '' ) );
cv_ok( TEST_EMAIL === $args['recipient'], 'completing first makes keep-original UNREACHABLE (recipient replaced)' );
cv_ok( false === strpos( $args['recipient'], 'real@customer.test' ), "the customer's recipient was dropped" );

echo "\n=== completion is idempotent and does not fire without a pending id ===\n";
$GLOBALS['cv_complete'] = 0;
$rp = new ReflectionProperty( 'Checkview_CF7_Helper', 'pending_test_id' );
$rp->setAccessible( true );
$rp->setValue( $h, 'cv-test-123' );
$h->checkview_cf7_complete_after_submit();
$h->checkview_cf7_complete_after_submit();
cv_ok( 1 === $GLOBALS['cv_complete'], 'completes exactly once across two wpcf7_submit firings' );

$GLOBALS['cv_complete'] = 0;
$rp->setValue( $h, '' );
$h->checkview_cf7_complete_after_submit();
cv_ok( 0 === $GLOBALS['cv_complete'], 'no pending id (e.g. validation failed) -> does not complete' );

cv_finish();
