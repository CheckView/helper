<?php
/**
 * Verification for the WP Armour unhooks in the CF7, WPForms and Elementor helpers.
 *
 * Both sides are read from the shipped files: WP Armour's own registration from
 * honeypot/includes/integration/*.php, and the helper's removal from the plugin
 * source. If either drifts — WP Armour changes a priority, or someone edits the
 * helper — the correspondence assertions fail rather than silently no-opping.
 */

require_once __DIR__ . '/../bootstrap.php';

cv_need_fixture( 'honeypot' );
cv_need_change( 'includes/formhelpers/class-checkview-cf7-helper.php', 'checkview_unhook_wp_armour', 'PR #242' );

$HELPER = CV_PLUGIN_DIR . '/includes/formhelpers/';
$ARMOUR = CV_FIXTURES . '/honeypot/includes/integration/';

$GLOBALS['cv_log']   = array();
$GLOBALS['wp_hooks'] = array();

function _id( $cb ) {
	if ( is_array( $cb ) ) {
		return ( is_object( $cb[0] ) ? spl_object_hash( $cb[0] ) : (string) $cb[0] ) . '::' . $cb[1];
	}
	return is_object( $cb ) ? spl_object_hash( $cb ) : (string) $cb;
}
function add_action( $tag, $cb, $priority = 10, $args = 1 ) {
	$GLOBALS['wp_hooks'][ $tag ][ $priority ][ _id( $cb ) ] = $cb;
}
function add_filter( $tag, $cb, $priority = 10, $args = 1 ) { add_action( $tag, $cb, $priority, $args ); }
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

class Checkview_Admin_Logs {
	public static function add( $c, $m ) { $GLOBALS['cv_log'][] = $m; }
}


/** WP Armour's real registration for one integration file. */
function armour_registration( string $file ): ?array {
	$src = @file_get_contents( $file );
	if ( false === $src ) { return null; }
	if ( ! preg_match( "/add_(?:filter|action)\(\s*'([^']+)'\s*,\s*'(wpa[a-z0-9_]+)'\s*(?:,\s*(\d+))?/i", $src, $m ) ) {
		return null;
	}
	return array( 'tag' => $m[1], 'cb' => $m[2], 'priority' => isset( $m[3] ) ? (int) $m[3] : 10 );
}

/** The helper's real removal, extracted from the shipped file. */
function helper_removal( string $file ): ?array {
	$src = @file_get_contents( $file );
	if ( false === $src ) { return null; }
	if ( ! preg_match( "/remove_(?:filter|action)\(\s*'([^']+)'\s*,\s*'(wpa[a-z0-9_]+)'\s*,\s*(\d+)\s*\)/i", $src, $m ) ) {
		return null;
	}
	return array( 'tag' => $m[1], 'cb' => $m[2], 'priority' => (int) $m[3] );
}

/** Extracts the real unhook method and evals it as a standalone function. */
function load_unhook( string $file, string $as ): bool {
	$src = @file_get_contents( $file );
	if ( false === $src ) { return false; }
	if ( ! preg_match( '/public function checkview_unhook_wp_armour\(\) \{.*?\n\t\t\}/s', $src, $m ) ) {
		return false;
	}
	$fn = preg_replace(
		'/public function checkview_unhook_wp_armour\(\)/',
		'function ' . $as . '()',
		$m[0],
		1
	);
	eval( $fn );
	return true;
}

$cases = array(
	'cf7'       => array( 'helper' => 'class-checkview-cf7-helper.php',       'armour' => 'wpa_contactform7.php' ),
	'wpforms'   => array( 'helper' => 'class-checkview-wpforms-helper.php',   'armour' => 'wpa_wpforms.php' ),
	'elementor' => array( 'helper' => 'class-checkview-elementor-helper.php', 'armour' => 'wpa_elementor.php' ),
);

foreach ( $cases as $slug => $c ) {
	echo "\n=== $slug ===\n";
	$hfile = $HELPER . $c['helper'];
	$reg   = armour_registration( $ARMOUR . $c['armour'] );
	$rem   = helper_removal( $hfile );

	cv_ok( null !== $reg, "read WP Armour's own registration from {$c['armour']}" );
	cv_ok( null !== $rem, "read the helper's removal from {$c['helper']}" );
	if ( null === $reg || null === $rem ) { continue; }

	// Drift guards: the helper must target exactly what WP Armour registers.
	cv_ok( $rem['tag'] === $reg['tag'], "hook tag matches WP Armour ({$reg['tag']})" );
	cv_ok( $rem['cb'] === $reg['cb'], "callback matches WP Armour ({$reg['cb']})" );
	cv_ok(
		$rem['priority'] === $reg['priority'],
		"priority matches WP Armour ({$reg['priority']}) — remove_* is a no-op otherwise"
	);

	// The unhook must be registered on init at PHP_INT_MAX.
	$hsrc = file_get_contents( $hfile );
	cv_ok(
		(bool) preg_match(
			"/add_action\(\s*'init',\s*array\(\s*\\\$this,\s*'checkview_unhook_wp_armour'\s*\),\s*PHP_INT_MAX\s*\)/s",
			$hsrc
		),
		'registered on init at PHP_INT_MAX (after WP Armour loads its integrations)'
	);

	cv_ok( load_unhook( $hfile, 'cv_unhook_' . $slug ), 'extracted the real unhook method' );

	// Present: WP Armour registered exactly as it does in its own source.
	$GLOBALS['wp_hooks'] = array();
	$GLOBALS['cv_log']   = array();
	add_filter( $reg['tag'], $reg['cb'], $reg['priority'] );
	cv_ok( false !== has_action( $reg['tag'], $reg['cb'] ), 'precondition: WP Armour is hooked' );

	call_user_func( 'cv_unhook_' . $slug );
	cv_ok( false === has_action( $reg['tag'], $reg['cb'] ), 'WP Armour is unhooked' );
	cv_ok(
		1 === count( array_filter( $GLOBALS['cv_log'], fn( $l ) => false !== strpos( $l, 'Unhooked WP Armour' ) ) ),
		'logged the removal exactly once'
	);

	// Absent: no plugin, no function — must be a silent no-op, not a warning.
	$GLOBALS['wp_hooks'] = array();
	$GLOBALS['cv_log']   = array();
	call_user_func( 'cv_unhook_' . $slug );
	cv_ok( array() === $GLOBALS['cv_log'], 'WP Armour absent -> silent no-op' );

	// Present but moved: the function exists yet the priority changed, so the
	// removal fails. That must be surfaced, not swallowed.
	$GLOBALS['wp_hooks'] = array();
	$GLOBALS['cv_log']   = array();
	eval( 'if ( ! function_exists( "' . $reg['cb'] . '" ) ) { function ' . $reg['cb'] . '() {} }' );
	add_filter( $reg['tag'], $reg['cb'], $reg['priority'] + 5 );
	call_user_func( 'cv_unhook_' . $slug );
	cv_ok(
		1 === count( array_filter( $GLOBALS['cv_log'], fn( $l ) => false !== strpos( $l, 'not removable' ) ) ),
		'priority drift is logged as still-at-risk rather than silently ignored'
	);
	cv_ok( false !== has_action( $reg['tag'], $reg['cb'] ), 'and the callback is honestly still hooked' );
}

cv_finish();
