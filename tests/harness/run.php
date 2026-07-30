<?php
/**
 * Runs every verification case and reports one tally.
 *
 * Usage:
 *   php tests/harness/run.php            # all cases
 *   php tests/harness/run.php formidable # only cases whose name matches
 *
 * Each case runs in its own process, so cases are free to redeclare the same
 * WordPress stubs without colliding.
 *
 * Exit code is 1 if any assertion failed. Skips do NOT fail the run — a fixture
 * may be absent, or a case may target a branch that is not merged yet — but they
 * are always listed, so a green run can never hide an unrun case.
 *
 * @package Checkview
 * @subpackage Checkview/tests/harness
 */

/** Must match CV_EXIT_SKIP in bootstrap.php. */
define( 'CV_RUNNER_SKIP', 2 );

$filter = $argv[1] ?? '';
$cases  = glob( __DIR__ . '/cases/*.php' );
sort( $cases );

if ( '' !== $filter ) {
	$cases = array_values(
		array_filter(
			$cases,
			static function ( $case ) use ( $filter ) {
				return false !== strpos( basename( $case ), $filter );
			}
		)
	);
}

if ( empty( $cases ) ) {
	fwrite( STDERR, "No cases matched.\n" );
	exit( 1 );
}

$total_asserts = 0;
$total_fails   = 0;
$ran            = 0;
$skipped        = array();
$failed_cases   = array();
$php            = PHP_BINARY;

foreach ( $cases as $case ) {
	$name = basename( $case, '.php' );

	$output   = array();
	$exit_code = 0;
	exec( escapeshellarg( $php ) . ' ' . escapeshellarg( $case ) . ' 2>&1', $output, $exit_code );
	$text = implode( "\n", $output );

	if ( CV_RUNNER_SKIP === $exit_code ) {
		$reason = '';
		if ( preg_match( '/^SKIP: (.+)$/m', $text, $m ) ) {
			$reason = $m[1];
		}
		$skipped[] = array( $name, $reason );
		printf( "  %-46s SKIP  %s\n", $name, $reason );
		continue;
	}

	$asserts = 0;
	$fails   = 0;
	if ( preg_match( '/^(\d+) assertions, (\d+) failed$/m', $text, $m ) ) {
		$asserts = (int) $m[1];
		$fails   = (int) $m[2];
	} else {
		// No tally line means the case died before finishing — a hard error, not
		// a skip. Surface the output rather than silently counting zero.
		echo "  {$name}  DID NOT COMPLETE\n";
		echo preg_replace( '/^/m', '      ', $text ) . "\n";
		$failed_cases[] = $name;
		$total_fails   += 1;
		continue;
	}

	++$ran;
	$total_asserts += $asserts;
	$total_fails   += $fails;

	if ( $fails > 0 ) {
		$failed_cases[] = $name;
		printf( "  %-46s %d assertions, %d FAILED\n", $name, $asserts, $fails );
		foreach ( preg_grep( '/^\s+FAIL: /', $output ) as $line ) {
			echo "    {$line}\n";
		}
		continue;
	}

	printf( "  %-46s %d assertions, ok\n", $name, $asserts );
}

echo "\n";
echo str_repeat( '-', 72 ) . "\n";
printf( "%d cases run, %d skipped — %d assertions, %d failed\n", $ran, count( $skipped ), $total_asserts, $total_fails );

if ( ! empty( $skipped ) ) {
	echo "\nSkipped:\n";
	foreach ( $skipped as $skip ) {
		printf( "  %-46s %s\n", $skip[0], $skip[1] );
	}
}

if ( ! empty( $failed_cases ) ) {
	echo "\nFailed: " . implode( ', ', $failed_cases ) . "\n";
	exit( 1 );
}

exit( 0 );
