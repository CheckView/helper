<?php
/**
 * Tests that the recipient filters leave an email with no recipients alone.
 *
 * During a test the helper points each form plugin's notification (and
 * WooCommerce's admin order emails) at the CheckView test inbox. When the
 * site resolves an email to nobody, for example Forminator routing rules that
 * match no address, a real submission sends nothing. The filters used to put
 * the test inbox in anyway, so the test passed for an email no visitor gets.
 *
 * For each filter this checks three things, in replace mode, with the
 * `disable_email_receipt_<test id>` option set (form plugins only), and in
 * the "deliver to original recipients" append mode:
 *  - no recipients at all: the email stays unaddressed (no test inbox);
 *  - an empty To but a Cc or Bcc (CF7, Formidable, Elementor and Ninja Forms
 *    still send that): still redirected to the test inbox, as before;
 *  - a normal recipient: redirected (replace) or appended (append), as before.
 *
 * It also checks the shared wp_mail backstop, which must not address a To
 * that holds only separators or blanks (two CF7 mail-tags that both rendered
 * empty give ","), since the form filter left it that way on purpose.
 *
 * Self-contained: WordPress and the form plugins are stubbed below, so this
 * runs with plain PHP (8.0+) from the plugin root:
 *
 *     php tests/empty-recipient-test.php
 *
 * Exits non-zero when any assertion fails.
 *
 * @package CheckView
 */

// phpcs:disable -- Test script with WordPress stubs; not shipped (see .distignore).

if ( 'cli' !== PHP_SAPI ) {
	http_response_code( 404 );
	exit( 1 );
}

define( 'ABSPATH', __DIR__ . '/' );
define( 'WPINC', 'wp-includes' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'CHECKVIEW_INC_DIR', dirname( __DIR__ ) . '/includes/' );
define( 'CHECKVIEW_TEST_EMAIL', 'run123@test-mail.checkview.io' );
const TEST_ID = '3f2b6a4e-1c9d-4e8f-9a7b-2d5c6e8f0a1b';

register_shutdown_function(
	function () {
		if ( empty( $GLOBALS['cv_t_done'] ) ) {
			echo "\nFAIL the script stopped before the summary\n";
			exit( 1 );
		}
	}
);

/* ---- WordPress and helper stubs ----------------------------------------- */

$GLOBALS['cv_t_options'] = array();
$GLOBALS['cv_t_append']  = false;
$GLOBALS['cv_t_posts']   = array();
$GLOBALS['cv_t_mail']    = array();

class Checkview_Admin_Logs {
	public static $lines = array();
	public static function add( $type, $line ) {
		self::$lines[] = $line;
	}
}
class Checkview_Loader {
	public function __call( $name, $args ) {}
}
class CheckView {
	const PARAM_TEST_ID = 'checkview_test_id';
	public static function may_be_test() {
		return true;
	}
	public static function get_referrer_test_id() {
		return '';
	}
}
function add_action( ...$a ) {}
function add_filter( ...$a ) {}
function remove_filter( ...$a ) {
	return false;
}
function remove_action( ...$a ) {
	return false;
}
function has_filter( ...$a ) {
	return false;
}
function has_action( ...$a ) {
	return false;
}
function apply_filters( $hook, $value, ...$a ) {
	return $value;
}
function do_action( ...$a ) {}
// True only so the WPForms constructor skips including wp-admin/includes/plugin.php;
// none of the filters under test read it.
function is_admin() {
	return true;
}
function wp_doing_ajax() {
	return false;
}
function get_option( $name, $default = false ) {
	return $GLOBALS['cv_t_options'][ $name ] ?? $default;
}
function wp_json_encode( $v ) {
	return json_encode( $v );
}
function is_email( $e ) {
	return (bool) filter_var( trim( (string) $e ), FILTER_VALIDATE_EMAIL );
}
function maybe_unserialize( $v ) {
	return is_string( $v ) && false !== @unserialize( $v ) ? unserialize( $v ) : $v;
}
function get_post( $id ) {
	return $GLOBALS['cv_t_posts'][ $id ] ?? null;
}
function wp_mail( $to, ...$rest ) {
	$GLOBALS['cv_t_mail'][] = $to;
	return true;
}
function wp_strip_all_tags( $s ) {
	return strip_tags( (string) $s );
}
function did_action( ...$a ) {
	return false;
}
function wpforms( ...$a ) {
	return new class() {
		public function get( $k ) {
			return null;
		}
	};
}
// Test context: these two decide replace vs append mode.
function checkview_get_test_id() {
	return TEST_ID;
}
function checkview_should_allow_original_recipients() {
	return $GLOBALS['cv_t_append'];
}

require CHECKVIEW_INC_DIR . 'checkview-functions.php';
foreach ( array( 'forminator', 'formidable', 'cf7', 'elementor', 'ninja-forms', 'wpforms' ) as $cv_t_slug ) {
	require CHECKVIEW_INC_DIR . "formhelpers/class-checkview-$cv_t_slug-helper.php";
}
require CHECKVIEW_INC_DIR . 'woocommercehelper/class-checkview-woo-automated-testing.php';
require CHECKVIEW_INC_DIR . 'class-checkview-mail-redirect.php';

function cv_t_obj( $class ) {
	return ( new ReflectionClass( $class ) )->newInstanceWithoutConstructor();
}

/* ---- Assertions --------------------------------------------------------- */

$GLOBALS['cv_t_fail'] = 0;
$GLOBALS['cv_t_pass'] = 0;
function cv_t_check( $label, $ok ) {
	if ( $ok ) {
		++$GLOBALS['cv_t_pass'];
		echo "ok   $label\n";
	} else {
		++$GLOBALS['cv_t_fail'];
		echo "FAIL $label\n";
	}
}
function cv_t_has_test( $v ) {
	return false !== strpos( json_encode( $v ), CHECKVIEW_TEST_EMAIL );
}
// Three modes: replace (default), the `disable_email_receipt_<test id>`
// option (keep the real recipients and add the test inbox), and append
// (allow original recipients).
// WooCommerce's filter has no receipt-option branch (it reads a constant
// nothing defines), so its block passes $with_receipt = false.
function cv_t_modes( callable $fn, $with_receipt = true ) {
	$modes = array(
		'replace' => array( false, false ),
		'receipt' => array( false, 'true' ),
		'append'  => array( true, false ),
	);
	if ( ! $with_receipt ) {
		unset( $modes['receipt'] );
	}
	foreach ( $modes as $mode => list( $append, $receipt ) ) {
		$GLOBALS['cv_t_append'] = $append;
		$GLOBALS['cv_t_options'][ 'disable_email_receipt_' . TEST_ID ] = $receipt;
		$fn( $mode );
	}
	$GLOBALS['cv_t_append']  = false;
	$GLOBALS['cv_t_options'] = array();
}
function cv_t_logged_no_recipients() {
	$hit = false;
	foreach ( Checkview_Admin_Logs::$lines as $line ) {
		$hit = $hit || false !== strpos( $line, 'has no recipients' );
	}
	Checkview_Admin_Logs::$lines = array();
	return $hit;
}

/* ---- Shared helpers ----------------------------------------------------- */

if ( function_exists( 'checkview_recipients_are_empty' ) ) {
	cv_t_check( 'recipients_are_empty: empty string', checkview_recipients_are_empty( '' ) );
	cv_t_check( 'recipients_are_empty: separators and spaces', checkview_recipients_are_empty( ' , ; ' ) );
	cv_t_check( 'recipients_are_empty: empty array and blank entries', checkview_recipients_are_empty( array(), array( '', ' ', null ) ) );
	cv_t_check( 'recipients_are_empty: an address in a later list counts', ! checkview_recipients_are_empty( '', 'cc@example.com' ) );
	cv_t_check( 'recipients_are_empty: a non-string entry counts', ! checkview_recipients_are_empty( array( array( 'x' ) ) ) );
	cv_t_check( 'headers_have_copy_recipients: Cc line', checkview_headers_have_copy_recipients( "From: a@b.co\nCc: c@d.co" ) );
	cv_t_check( 'headers_have_copy_recipients: empty Bcc line is not one', ! checkview_headers_have_copy_recipients( array( 'From: a@b.co', 'Bcc: ' ) ) );
	cv_t_check( 'headers_have_copy_recipients: same header match as the Cc/Bcc strips', ! checkview_headers_have_copy_recipients( 'Cc : c@d.co' ) );
	cv_t_check( 'recipients_are_empty: an object counts and does not fatal', ! checkview_recipients_are_empty( new stdClass() ) );
}

/* ---- Forminator ---------------------------------------------------------- */

$fmn = cv_t_obj( 'Checkview_Forminator_Helper' );
cv_t_modes(
	function ( $m ) use ( $fmn ) {
		cv_t_check( "Forminator $m: empty list stays empty", array() === $fmn->checkview_inject_email( array() ) );
		cv_t_check( "Forminator $m: no-recipient log line written", cv_t_logged_no_recipients() );
		cv_t_check( "Forminator $m: list of one blank entry stays unaddressed", ! cv_t_has_test( $fmn->checkview_inject_email( array( '' ) ) ) );
		cv_t_check( "Forminator $m: empty string stays empty", '' === $fmn->checkview_inject_email( '' ) );
		cv_t_check( "Forminator $m: a recipient is redirected", cv_t_has_test( $fmn->checkview_inject_email( array( 'owner@example.com' ) ) ) );
	}
);

/* ---- Formidable ---------------------------------------------------------- */

$GLOBALS['cv_t_posts'][501] = (object) array( 'post_content' => json_encode( array( 'cc' => '', 'bcc' => '' ) ) );
$GLOBALS['cv_t_posts'][502] = (object) array( 'post_content' => json_encode( array( 'cc' => 'boss@example.com', 'bcc' => '' ) ) );
// An older action stored PHP-serialized.
$GLOBALS['cv_t_posts'][503] = (object) array( 'post_content' => serialize( array( 'cc' => '', 'bcc' => '' ) ) );
$frm = cv_t_obj( 'Checkview_Formidable_Helper' );
cv_t_modes(
	function ( $m ) use ( $frm ) {
		cv_t_check( "Formidable $m: no To, Cc or Bcc stays empty", ! cv_t_has_test( $frm->checkview_inject_email( array(), array(), 1, array( 'email_key' => 501 ) ) ) );
		cv_t_check( "Formidable $m: no To but a Cc is redirected", cv_t_has_test( $frm->checkview_inject_email( array(), array(), 1, array( 'email_key' => 502 ) ) ) );
		cv_t_check( "Formidable $m: unreadable action keeps old behaviour", cv_t_has_test( $frm->checkview_inject_email( array(), array(), 1, array( 'email_key' => 999 ) ) ) );
		cv_t_check( "Formidable $m: called with one arg keeps old behaviour", cv_t_has_test( $frm->checkview_inject_email( array() ) ) );
		cv_t_check( "Formidable $m: serialized action with no Cc or Bcc stays empty", ! cv_t_has_test( $frm->checkview_inject_email( array(), array(), 1, array( 'email_key' => 503 ) ) ) );
		cv_t_check( "Formidable $m: a recipient is redirected", cv_t_has_test( $frm->checkview_inject_email( array( 'owner@example.com' ), array(), 1, array( 'email_key' => 501 ) ) ) );
	}
);

/* ---- Contact Form 7 ------------------------------------------------------ */

$cf7 = cv_t_obj( 'Checkview_Cf7_Helper' );
cv_t_modes(
	function ( $m ) use ( $cf7 ) {
		$r = $cf7->checkview_inject_email( array( 'recipient' => '', 'additional_headers' => 'Reply-To: a@b.co' ) );
		cv_t_check( "CF7 $m: no recipient stays empty", '' === $r['recipient'] );
		$r = $cf7->checkview_inject_email( array( 'recipient' => '', 'additional_headers' => "Reply-To: a@b.co\nCc: boss@example.com" ) );
		cv_t_check( "CF7 $m: no To but a Cc is redirected", cv_t_has_test( $r['recipient'] ) );
		$r = $cf7->checkview_inject_email( array( 'recipient' => 'owner@example.com', 'additional_headers' => '' ) );
		cv_t_check( "CF7 $m: a recipient is redirected", cv_t_has_test( $r['recipient'] ) );
	}
);

/* ---- Elementor ----------------------------------------------------------- */

$el = cv_t_obj( 'Checkview_Elementor_Helper' );
cv_t_modes(
	function ( $m ) use ( $el ) {
		$base = array( 'email_to' => '', 'email_to_cc' => '', 'email_to_bcc' => '' );
		cv_t_check( "Elementor $m: no To, Cc or Bcc stays empty", '' === $el->checkview_override_mail_fields( $base, null )['email_to'] );
		cv_t_check( "Elementor $m: no To but a Bcc is redirected", cv_t_has_test( $el->checkview_override_mail_fields( array_merge( $base, array( 'email_to_bcc' => 'boss@example.com' ) ), null )['email_to'] ) );
		cv_t_check( "Elementor $m: a recipient is redirected", cv_t_has_test( $el->checkview_override_mail_fields( array_merge( $base, array( 'email_to' => 'owner@example.com' ) ), null )['email_to'] ) );
	}
);

/* ---- Ninja Forms (replace mode sends itself; append passes through) ----- */

$nf = cv_t_obj( 'Checkview_Ninja_Forms_Helper' );
$GLOBALS['cv_t_mail'] = array();
$sent = $nf->checkview_inject_email( false, array( 'to' => '', 'cc' => '', 'bcc' => '', 'email_subject' => 's' ), 'm', array(), array() );
cv_t_check( 'Ninja Forms replace: no recipients sends nothing itself', false === $sent && array() === $GLOBALS['cv_t_mail'] );
$GLOBALS['cv_t_mail'] = array();
$nf->checkview_inject_email( false, array( 'to' => '', 'cc' => 'boss@example.com', 'bcc' => '', 'email_subject' => 's' ), 'm', array(), array() );
cv_t_check( 'Ninja Forms replace: no To but a Cc is sent to the test inbox', array( CHECKVIEW_TEST_EMAIL ) === $GLOBALS['cv_t_mail'] );
$GLOBALS['cv_t_mail'] = array();
$nf->checkview_inject_email( false, array( 'to' => 'owner@example.com', 'cc' => '', 'bcc' => '', 'email_subject' => 's' ), 'm', array(), array() );
cv_t_check( 'Ninja Forms replace: a recipient is sent to the test inbox', array( CHECKVIEW_TEST_EMAIL ) === $GLOBALS['cv_t_mail'] );
// Append mode hands every send back to Ninja Forms (the wp_mail backstop
// adds the test inbox), with or without recipients.
$GLOBALS['cv_t_append'] = true;
foreach ( array( 'a recipient' => 'owner@example.com', 'no recipients' => '' ) as $cv_t_what => $cv_t_to ) {
	$GLOBALS['cv_t_mail'] = array();
	$sent = $nf->checkview_inject_email( false, array( 'to' => $cv_t_to, 'cc' => '', 'bcc' => '', 'email_subject' => 's' ), 'm', array(), array() );
	cv_t_check( "Ninja Forms append: $cv_t_what is handed back unsent", false === $sent && array() === $GLOBALS['cv_t_mail'] );
}
$GLOBALS['cv_t_append'] = false;

/* ---- WPForms ------------------------------------------------------------- */

$wpf = cv_t_obj( 'Checkview_Wpforms_Helper' );
cv_t_modes(
	function ( $m ) use ( $wpf ) {
		cv_t_check( "WPForms $m: no Send To stays empty", ! cv_t_has_test( $wpf->checkview_inject_email( array( 'address' => array(), 'replyto' => '' ) )['address'] ) );
		cv_t_check( "WPForms $m: a smart tag that rendered empty stays empty", ! cv_t_has_test( $wpf->checkview_inject_email( array( 'address' => array( '' ), 'replyto' => '' ) )['address'] ) );
		cv_t_check( "WPForms $m: a recipient is redirected", cv_t_has_test( $wpf->checkview_inject_email( array( 'address' => array( 'owner@example.com' ), 'replyto' => '' ) )['address'] ) );
	}
);

/* ---- WooCommerce admin order emails ------------------------------------- */

$woo   = cv_t_obj( 'Checkview_Woo_Automated_Testing' );
$order = new class() {
	public function get_payment_method() {
		return 'checkview';
	}
	public function get_meta( $k ) {
		return '';
	}
};
$wce   = (object) array( 'id' => 'new_order' );
cv_t_modes(
	function ( $m ) use ( $woo, $order, $wce ) {
		cv_t_check( "WooCommerce $m: blank recipient stays blank", '' === $woo->checkview_filter_admin_emails( '', $order, $wce ) );
		cv_t_check( "WooCommerce $m: invalid recipient is left for WooCommerce to drop", 'orders@shop' === $woo->checkview_filter_admin_emails( 'orders@shop', $order, $wce ) );
		cv_t_check( "WooCommerce $m: a valid recipient is redirected", cv_t_has_test( $woo->checkview_filter_admin_emails( 'orders@shop.com', $order, $wce ) ) );
		Checkview_Admin_Logs::$lines = array();
		$woo->checkview_filter_admin_emails( '', $order, (object) array( 'id' => 'new_order', 'recipient' => 'orders@shop.com' ) );
		cv_t_check( "WooCommerce $m: log names a filter that blanked the recipient", false !== strpos( implode( ' ', Checkview_Admin_Logs::$lines ), 'another filter changed the recipient' ) );
	},
	false
);

/* ---- wp_mail backstop ---------------------------------------------------- */

$bs = cv_t_obj( 'Checkview_Mail_Redirect' );
cv_t_modes(
	function ( $m ) use ( $bs ) {
		$r = $bs->redirect_recipient( array( 'to' => ',', 'headers' => '' ) );
		cv_t_check( "backstop $m: a separator-only To stays unaddressed", ',' === $r['to'] );
		$r = $bs->redirect_recipient( array( 'to' => array( '' ), 'headers' => array() ) );
		cv_t_check( "backstop $m: a blank To list stays unaddressed", ! cv_t_has_test( $r['to'] ) );
		$r = $bs->redirect_recipient( array( 'to' => ', ', 'headers' => "Cc: boss@example.com" ) );
		cv_t_check( "backstop $m: no To but a Cc is redirected", cv_t_has_test( $r['to'] ) );
		$r = $bs->redirect_recipient( array( 'to' => 'owner@example.com', 'headers' => '' ) );
		cv_t_check( "backstop $m: a recipient is redirected", cv_t_has_test( $r['to'] ) );
	}
);

$GLOBALS['cv_t_done'] = true;
printf( "\n%d passed, %d failed\n", $GLOBALS['cv_t_pass'], $GLOBALS['cv_t_fail'] );
exit( $GLOBALS['cv_t_fail'] ? 1 : 0 );
