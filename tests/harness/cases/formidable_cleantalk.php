<?php
/**
 * Verification for the CleanTalk bypass in the Formidable helper.
 *
 * The bypass leans on an INTERNAL CleanTalk global, so every link in the chain
 * is asserted against CleanTalk's shipped source rather than described. If
 * CleanTalk moves the sentinel, changes the default `allow`, or changes the
 * caller's block condition, these fail instead of the bypass silently dying.
 */

require_once __DIR__ . '/../bootstrap.php';

cv_need_fixture( 'cleantalk-spam-protect' );
cv_need_change( 'includes/formhelpers/class-checkview-formidable-helper.php', 'cleantalk_executed', 'PR #243' );

$CT     = CV_FIXTURES . '/cleantalk-spam-protect/';
$HELPER = CV_PLUGIN_DIR . '/includes/formhelpers/class-checkview-formidable-helper.php';


$common      = file_get_contents( $CT . 'inc/cleantalk-common.php' );
$integr      = file_get_contents( $CT . 'inc/cleantalk-public-integrations.php' );
$ajax        = file_get_contents( $CT . 'inc/cleantalk-ajax.php' );
$main        = file_get_contents( $CT . 'cleantalk.php' );
$response    = file_get_contents( $CT . 'lib/Cleantalk/Antispam/CleantalkResponse.php' );
$helper_src  = file_get_contents( $HELPER );

echo "\n=== CleanTalk really does integrate with Formidable ===\n";
cv_ok(
	(bool) preg_match( "/add_filter\('frm_entries_before_create',\s*'apbct_form__formidable__testSpam',\s*(\d+)/", $main, $m1 ),
	'registers apbct_form__formidable__testSpam on frm_entries_before_create'
);
cv_ok( isset( $m1[1] ) && '999999' === $m1[1], 'at priority 999999, i.e. after everything (got ' . ( $m1[1] ?? '?' ) . ')' );
cv_ok(
	(bool) preg_match( "/add_action\('wp_ajax_nopriv_frm_entries_create',\s*'ct_ajax_hook',\s*(\d+)\)/", $ajax, $m2 ),
	'and ct_ajax_hook on wp_ajax_nopriv_frm_entries_create'
);
cv_ok( isset( $m2[1] ) && '1' === $m2[1], 'at priority 1, i.e. before Formidable handles it (got ' . ( $m2[1] ?? '?' ) . ')' );

echo "\n=== both paths funnel into the sentinel-guarded call ===\n";
cv_ok( false !== strpos( $integr, 'apbct_base_call(' ), 'the Formidable integration calls apbct_base_call()' );
cv_ok( false !== strpos( $ajax, 'apbct_base_call(' ), 'the ajax path calls apbct_base_call() too' );

// Nothing may terminate the ajax path before it reaches that call, or the
// sentinel would never be consulted.
$ajax_lines = explode( "\n", $ajax );
$hook_start = null;
$call_line  = null;
foreach ( $ajax_lines as $i => $line ) {
	if ( null === $hook_start && false !== strpos( $line, 'function ct_ajax_hook' ) ) { $hook_start = $i; }
	if ( null !== $hook_start && null === $call_line && false !== strpos( $line, 'apbct_base_call(' ) ) { $call_line = $i; }
}
cv_ok( null !== $hook_start && null !== $call_line, 'located ct_ajax_hook and its base_call' );
$between = implode( "\n", array_slice( $ajax_lines, $hook_start, $call_line - $hook_start ) );
cv_ok(
	0 === preg_match( '/\bct_die\(|\bwp_die\(|\bwp_send_json/', $between ),
	'no termination between ct_ajax_hook and that call, so the sentinel is always reached'
);

echo "\n=== the sentinel short-circuits to ALLOW, not to block ===\n";
cv_ok(
	(bool) preg_match( '/global \$cleantalk_executed;.*?if \(\s*\$cleantalk_executed\s*\)\s*\{.*?return array\(\s*\'ct_result\'\s*=>\s*new CleantalkResponse\(\)\s*\);/s', $common ),
	'apbct_base_call() returns a default CleantalkResponse when the sentinel is set'
);
cv_ok(
	(bool) preg_match( '/\$this->allow\s*=\s*isset\(\$obj->allow\)\s*\?\s*\$obj->allow\s*:\s*(\d+);/', $response, $m3 ),
	'CleantalkResponse defaults `allow`'
);
cv_ok(
	isset( $m3[1] ) && '1' === $m3[1],
	'and that default is 1 = allowed (got ' . ( $m3[1] ?? '?' ) . ') — 0 would BLOCK every submission'
);
cv_ok(
	(bool) preg_match( '/if\s*\(\s*\$ct_result->allow\s*==\s*0\s*\)/', $integr ),
	'the Formidable integration only blocks when allow == 0'
);

echo "\n=== the helper sets it ===\n";
cv_ok(
	(bool) preg_match( '/global \$cleantalk_executed;\s*\n\s*\$cleantalk_executed = true;/', $helper_src ),
	'the Formidable helper sets the sentinel'
);
// It must be in the constructor: set after the submission hooks have run is useless.
cv_ok(
	(bool) preg_match( '/public function __construct\(\).*?global \$cleantalk_executed;\s*\n\s*\$cleantalk_executed = true;.*?\n\t\t\}/s', $helper_src ),
	'inside the constructor, so it is set before any submission hook fires'
);

echo "\n=== executable: replay the real semantics ===\n";
// Faithful to inc/cleantalk-common.php:110-124 and CleantalkResponse.php:154.
class CleantalkResponse { public $allow = 1; public $comment = ''; }
function apbct_base_call( $params = array() ) {
	global $cleantalk_executed;
	if ( $cleantalk_executed ) {
		return array( 'ct_result' => new CleantalkResponse() );
	}
	$r          = new CleantalkResponse();
	$r->allow   = 0;                       // stand in for "the API called it spam"
	$r->comment = 'Blocked by CleanTalk';
	return array( 'ct_result' => $r );
}
/** The shape of apbct_form__formidable__testSpam's decision. */
function formidable_would_block(): bool {
	$res = apbct_base_call();
	return isset( $res['ct_result'] ) && 0 == $res['ct_result']->allow;
}

$GLOBALS['cleantalk_executed'] = false;
cv_ok( true === formidable_would_block(), 'precondition: without the bypass, a flagged submission is blocked' );

// What the helper's constructor does.
$GLOBALS['cleantalk_executed'] = true;
cv_ok( false === formidable_would_block(), 'with the sentinel set, the submission is allowed through' );

cv_finish();
