<?php
/**
 * Verification that append-mode's flag is scoped per test id.
 *
 * The flag decides whether a customer's REAL recipients receive test email, so
 * two concurrent tests must not be able to read each other's setting.
 */

require_once __DIR__ . '/../bootstrap.php';

cv_need_change( 'includes/checkview-functions.php', 'cv_should_allow_original_recipients', 'PR #197' );

$GLOBALS['cv_opts']    = array();
$GLOBALS['cv_test_id'] = '';

function get_option( $k, $d = false ) { return $GLOBALS['cv_opts'][ $k ] ?? $d; }
function cv_update_option( $k, $v, $autoload = false ) { $GLOBALS['cv_opts'][ $k ] = $v; }
function cv_delete_option( $k ) { unset( $GLOBALS['cv_opts'][ $k ] ); }
function delete_option( $k ) { unset( $GLOBALS['cv_opts'][ $k ] ); }
function get_checkview_test_id() { return $GLOBALS['cv_test_id']; }

// Pull the real reader out of the shipped file so this cannot drift.
$src = file_get_contents( CV_PLUGIN_DIR . '/includes/checkview-functions.php' );
preg_match( '/\tfunction cv_should_allow_original_recipients\(\) \{.*?\n\t\}/s', $src, $m );
if ( empty( $m[0] ) ) {
	fwrite( STDERR, "could not extract cv_should_allow_original_recipients()\n" );
	exit( 1 );
}
eval( $m[0] );


/** What admin/class-checkview-admin.php now writes at test-init. */
function init_test( string $test_id, bool $append ) {
	if ( $append ) {
		cv_update_option( 'allow_original_recipients_' . $test_id, 'true', true );
		cv_update_option( 'allow_original_recipients_set_at_' . $test_id, (string) time(), true );
	}
}

echo "\n=== the flag is read per test id ===\n";
$GLOBALS['cv_opts'] = array();
init_test( 'test-A', true );

$GLOBALS['cv_test_id'] = 'test-A';
cv_ok( true === cv_should_allow_original_recipients(), 'test A, which asked for append-mode, gets it' );

$GLOBALS['cv_test_id'] = 'test-B';
cv_ok( false === cv_should_allow_original_recipients(), "test B does NOT inherit test A's append-mode" );

echo "\n=== concurrent tests do not interfere ===\n";
$GLOBALS['cv_opts'] = array();
init_test( 'test-A', true );
init_test( 'test-B', false );   // B did not ask for it

$GLOBALS['cv_test_id'] = 'test-A';
cv_ok( true === cv_should_allow_original_recipients(), 'A still append after B started' );
$GLOBALS['cv_test_id'] = 'test-B';
cv_ok( false === cv_should_allow_original_recipients(), 'B still replace-mode' );

echo "\n=== completing one test does not disarm the other ===\n";
// What complete_checkview_test() now deletes.
cv_delete_option( 'allow_original_recipients_test-B' );
cv_delete_option( 'allow_original_recipients_set_at_test-B' );
$GLOBALS['cv_test_id'] = 'test-A';
cv_ok( true === cv_should_allow_original_recipients(), "completing B leaves A's flag intact" );

cv_delete_option( 'allow_original_recipients_test-A' );
cv_delete_option( 'allow_original_recipients_set_at_test-A' );
cv_ok( false === cv_should_allow_original_recipients(), 'completing A clears it' );

echo "\n=== fails closed ===\n";
$GLOBALS['cv_opts'] = array();
init_test( 'test-A', true );
$GLOBALS['cv_test_id'] = '';
cv_ok( false === cv_should_allow_original_recipients(), 'no resolvable test id -> replace-mode, not append' );

$GLOBALS['cv_test_id'] = 'test-A';
$GLOBALS['cv_opts']['allow_original_recipients_set_at_test-A'] = (string) ( time() - 3000 );  // > 45 min
cv_ok( false === cv_should_allow_original_recipients(), 'stale timestamp still rejected (watchdog intact)' );

$GLOBALS['cv_opts']['allow_original_recipients_set_at_test-A'] = (string) ( time() + 600 );   // future
cv_ok( false === cv_should_allow_original_recipients(), 'future timestamp still rejected' );

unset( $GLOBALS['cv_opts']['allow_original_recipients_set_at_test-A'] );
cv_ok( false === cv_should_allow_original_recipients(), 'flag without a timestamp rejected' );

echo "\n=== the OLD site-scoped shape is what allowed the leak ===\n";
$GLOBALS['cv_opts'] = array(
	'allow_original_recipients'         => 'true',
	'allow_original_recipients_set_at'  => (string) time(),
);
$GLOBALS['cv_test_id'] = 'test-B';
cv_ok( false === cv_should_allow_original_recipients(), 'an unscoped leftover no longer enables append for anyone' );

echo "\n=== completion really deletes the scoped keys (read from the shipped file) ===\n";
// The cases above hand-code what completion deletes, which would still pass if
// someone removed the deletes. Assert against the real function body instead.
preg_match( '/\tfunction complete_checkview_test\(.*?\n\t\}/s', $src, $cm );
cv_ok( ! empty( $cm[0] ), 'extracted complete_checkview_test() from the shipped file' );
$body = $cm[0] ?? '';
cv_ok(
	false !== strpos( $body, "cv_delete_option( 'allow_original_recipients_' . \$checkview_test_id )" ),
	'completion deletes the per-test flag'
);
cv_ok(
	false !== strpos( $body, "cv_delete_option( 'allow_original_recipients_set_at_' . \$checkview_test_id )" ),
	'completion deletes the per-test timestamp'
);
cv_ok(
	false !== strpos( $body, "cv_delete_option( 'allow_original_recipients' )" ),
	'completion still clears the legacy unscoped flag'
);
// A stale flag outliving its test is the leak this whole PR is about.
$scoped_deletes = preg_match_all( '/allow_original_recipients(?:_set_at)?_\' \. \$checkview_test_id/', $body );
cv_ok( 2 === $scoped_deletes, 'both scoped keys deleted, no more and no fewer (got ' . $scoped_deletes . ')' );

echo "\n=== the writes match the sibling per-test options (read from the shipped file) ===\n";
$admin = file_get_contents( CV_PLUGIN_DIR . '/admin/class-checkview-admin.php' );

// Orphaned keys are never cleaned up (a timed-out test never completes), so an
// autoloaded key pair per test grows wp_options autoload without bound.
preg_match_all(
	"/cv_update_option\(\s*'allow_original_recipients(?:_set_at)?_'\s*\.\s*\\\$cv_test_id\s*,[^,]+,\s*(true|false)\s*\)/",
	$admin,
	$am
);
cv_ok( 2 === count( $am[1] ), 'both append-mode keys are written at test-init (found ' . count( $am[1] ) . ')' );
cv_ok(
	array( 'false', 'false' ) === $am[1],
	'neither is autoloaded, matching disable_actions_<id> et al (got ' . implode( ', ', $am[1] ) . ')'
);

// Pin the sibling convention this is matching, so the comparison stays honest.
preg_match_all(
	"/cv_update_option\(\s*'(?:disable_actions|disable_email_receipt|disable_webhooks)_'\s*\.\s*\\\$cv_test_id\s*,[^,]+,\s*(true|false)\s*\)/",
	$admin,
	$sm
);
cv_ok( 3 === count( $sm[1] ), 'found the three sibling per-test writes (got ' . count( $sm[1] ) . ')' );
cv_ok(
	array( 'false', 'false', 'false' ) === $sm[1],
	'siblings are still non-autoloaded, so the convention above is the real one'
);

// The flag is only honoured inside a signed request: checkview_init_current_test
// is registered solely under is_bot().
$core = file_get_contents( CV_PLUGIN_DIR . '/includes/class-checkview.php' );
cv_ok(
	(bool) preg_match( "/if \(\s*self::is_bot\(\)\s*\)\s*\{(?:(?!\}).)*?'checkview_init_current_test'/s", $core ),
	'test-init (which reads $_REQUEST[allow_original_recipients]) stays behind is_bot()'
);

cv_finish();
