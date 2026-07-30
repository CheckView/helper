<?php
/**
 * Shared bootstrap for the standalone verification harness.
 *
 * Every case file is executed in its OWN php process by run.php, so each is free
 * to declare `add_action()`, `Checkview_Admin_Logs` and friends without
 * colliding with any other case. Nothing here emulates WordPress; each case
 * stubs exactly what it needs.
 *
 * @package Checkview
 * @subpackage Checkview/tests/harness
 */

/** Plugin root, derived rather than hardcoded so the harness is portable. */
define( 'CV_PLUGIN_DIR', dirname( __DIR__, 2 ) );

/** Third-party plugin sources. Not committed — see fetch-fixtures.sh. */
define( 'CV_FIXTURES', __DIR__ . '/fixtures' );

/** A WordPress checkout, for the few cases that assert against core itself. */
define( 'CV_WP', CV_PLUGIN_DIR . '/wordpress' );

/** Exit code run.php reads as "skipped", distinct from pass (0) and fail (1). */
define( 'CV_EXIT_SKIP', 2 );

$GLOBALS['cv_asserts'] = 0;
$GLOBALS['cv_fails']   = 0;

/**
 * Records one assertion.
 *
 * @param bool   $condition Result under test.
 * @param string $label     What is being asserted.
 * @return void
 */
function cv_ok( bool $condition, string $label ): void {
	++$GLOBALS['cv_asserts'];
	if ( ! $condition ) {
		++$GLOBALS['cv_fails'];
		echo "  FAIL: {$label}\n";
		return;
	}
	echo "  ok:   {$label}\n";
}

/**
 * Ends the case without running it, reporting why.
 *
 * A skip is not a pass. run.php counts skips separately and names them, so an
 * absent fixture or an unmerged branch can never be mistaken for green.
 *
 * @param string $reason Why this case cannot run here.
 * @return void
 */
function cv_skip( string $reason ): void {
	echo "SKIP: {$reason}\n";
	exit( CV_EXIT_SKIP );
}

/**
 * Requires a third-party plugin fixture, skipping the case when it is absent.
 *
 * @param string $slug Fixture directory name, e.g. 'forminator'.
 * @return string Absolute path to the fixture.
 */
function cv_need_fixture( string $slug ): string {
	$path = CV_FIXTURES . '/' . $slug;
	if ( ! is_dir( $path ) ) {
		cv_skip( "fixture '{$slug}' not present — run tests/harness/fetch-fixtures.sh" );
	}
	return $path;
}

/**
 * Requires a WordPress checkout, skipping the case when it is absent.
 *
 * @return string Absolute path to the WordPress root.
 */
function cv_need_wordpress(): string {
	if ( ! is_file( CV_WP . '/wp-includes/shortcodes.php' ) ) {
		cv_skip( 'no WordPress checkout at ./wordpress — run tests/harness/fetch-fixtures.sh' );
	}
	return CV_WP;
}

/**
 * Requires a plugin file, skipping when it is absent.
 *
 * @param string $relative Path relative to the plugin root.
 * @return string Absolute path.
 */
function cv_plugin_file( string $relative ): string {
	$path = CV_PLUGIN_DIR . '/' . ltrim( $relative, '/' );
	if ( ! is_file( $path ) ) {
		cv_skip( "plugin file missing: {$relative}" );
	}
	return $path;
}

/**
 * Requires that the change under test is actually present in the tree.
 *
 * These cases were written against branches that are not all merged. Running one
 * against a tree that predates its fix would report a failure that is really
 * just "not merged yet", so the case skips instead and names the PR.
 *
 * @param string $relative Plugin file to look in.
 * @param string $needle   Literal that only exists once the change has landed.
 * @param string $pr       Which PR introduces it, for the skip message.
 * @return string The file's contents, since the caller almost always wants them.
 */
function cv_need_change( string $relative, string $needle, string $pr ): string {
	$src = (string) file_get_contents( cv_plugin_file( $relative ) );
	if ( false === strpos( $src, $needle ) ) {
		cv_skip( "{$pr} is not in this tree yet ({$relative} has no '{$needle}')" );
	}
	return $src;
}

/**
 * Emulates SQL LIKE. `%` matches any run, `_` a single character.
 *
 * Used by the discovery cases, which assert against the same patterns the API
 * hands to `$wpdb->prepare()`.
 *
 * @param string $pattern LIKE pattern.
 * @param string $subject Text to match.
 * @return bool
 */
function cv_like( string $pattern, string $subject ): bool {
	$regex  = '';
	$length = strlen( $pattern );
	for ( $i = 0; $i < $length; $i++ ) {
		$char = $pattern[ $i ];
		if ( '%' === $char ) {
			$regex .= '.*';
		} elseif ( '_' === $char ) {
			$regex .= '.';
		} else {
			$regex .= preg_quote( $char, '#' );
		}
	}
	return (bool) preg_match( '#^' . $regex . '$#s', $subject );
}

/**
 * Prints the tally run.php parses, and exits with the right code.
 *
 * @return void
 */
function cv_finish(): void {
	echo "\n{$GLOBALS['cv_asserts']} assertions, {$GLOBALS['cv_fails']} failed\n";
	exit( $GLOBALS['cv_fails'] > 0 ? 1 : 0 );
}
