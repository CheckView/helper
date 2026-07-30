<?php
/**
 * Verification for the Gravity Forms plural-tag and single-quote patterns.
 *
 * The pattern-building block is EXTRACTED FROM THE SHIPPED FILE and executed, so
 * the assertions run against the real construction rather than a copy of it.
 */

require_once __DIR__ . '/../bootstrap.php';

cv_need_wordpress();
cv_need_change( 'includes/API/class-checkview-api.php', 'gf_patterns', 'PR #246' );

$API = CV_PLUGIN_DIR . '/includes/API/class-checkview-api.php';
$WP  = CV_WP . '/wp-includes/shortcodes.php';



echo "\n=== WordPress itself treats all three quoting forms alike ===\n";
require_once $WP;
$parsed = array();
foreach ( array( 'id="7"', "id='7'", 'id=7' ) as $t ) {
	$a = shortcode_parse_atts( $t );
	$parsed[ $t ] = $a['id'] ?? null;
}
cv_ok( '7' === $parsed['id="7"'], 'double-quoted id parses to 7' );
cv_ok( '7' === $parsed["id='7'"], "single-quoted id parses to 7 — so it is a working embed" );
cv_ok( '7' === $parsed['id=7'], 'unquoted id parses to 7' );

echo "\n=== run the shipped pattern construction ===\n";
$api = file_get_contents( $API );
preg_match( '/(\$gf_patterns = array\(.*?\n\t\t\t\t\t\})/s', $api, $m );
cv_ok( ! empty( $m[1] ), 'extracted the pattern-building block from the shipped file' );

$row = (object) array( 'id' => 7 );
eval( $m[1] );

cv_ok( 9 === count( $gf_patterns ), 'builds 9 patterns: 1 block + 4 per spelling (got ' . count( $gf_patterns ) . ')' );
cv_ok( count( $gf_patterns ) === count( array_unique( $gf_patterns ) ), 'no duplicate patterns' );

// The placeholder count must equal the pattern count or prepare() misbinds.
preg_match( '/(\$gf_where = implode\(.*?\);)/s', $api, $wm );
cv_ok( ! empty( $wm[1] ), 'extracted the WHERE construction' );
eval( $wm[1] );
cv_ok(
	substr_count( $gf_where, '%s' ) === count( $gf_patterns ),
	'placeholder count matches pattern count (' . substr_count( $gf_where, '%s' ) . ' vs ' . count( $gf_patterns ) . ')'
);

$matches = function ( string $content ) use ( $gf_patterns ): bool {
	foreach ( $gf_patterns as $p ) {
		if ( cv_like( $p, $content ) ) { return true; }
	}
	return false;
};

echo "\n=== finds every working embed of form 7 ===\n";
$should_find = array(
	'[gravityform id="7" title="false"]'   => 'singular, double-quoted',
	"[gravityform id='7' title='false']"   => 'singular, single-quoted',
	'[gravityform id=7]'                   => 'singular, unquoted, terminated',
	'[gravityform id=7 title="false"]'     => 'singular, unquoted, more attributes',
	'[gravityforms id="7" title="false"]'  => 'PLURAL, double-quoted',
	"[gravityforms id='7']"                => 'PLURAL, single-quoted',
	'[gravityforms id=7]'                  => 'PLURAL, unquoted, terminated',
	'[gravityforms id=7 ajax="true"]'      => 'PLURAL, unquoted, more attributes',
	'<!-- wp:gravityforms/form {"formId":"7"} /-->' => 'block editor',
);
foreach ( $should_find as $content => $label ) {
	cv_ok( $matches( $content ), "finds: $label" );
}

echo "\n=== does not steal another form's pages ===\n";
$should_not = array(
	'[gravityform id=77]'      => 'id=77',
	'[gravityform id=7123]'    => 'id=7123',
	'[gravityforms id=70]'     => 'plural id=70',
	'[gravityform id="77"]'    => 'quoted id=77',
	"[gravityforms id='77']"   => 'plural single-quoted id=77',
	'<!-- wp:gravityforms/form {"formId":"77"} /-->' => 'block formId 77',
);
foreach ( $should_not as $content => $label ) {
	cv_ok( ! $matches( $content ), "excludes: $label" );
}

echo "\n=== the two spellings stay disjoint ===\n";
$sing = array_values( array_filter( $gf_patterns, fn( $p ) => false !== strpos( $p, '[gravityform id' ) ) );
$plur = array_values( array_filter( $gf_patterns, fn( $p ) => false !== strpos( $p, '[gravityforms id' ) ) );
cv_ok( 4 === count( $sing ), 'four singular patterns (got ' . count( $sing ) . ')' );
cv_ok( 4 === count( $plur ), 'four plural patterns (got ' . count( $plur ) . ')' );
$sing_hits_plural = false;
foreach ( $sing as $p ) {
	if ( cv_like( $p, '[gravityforms id=7]' ) || cv_like( $p, '[gravityforms id="7"]' ) ) { $sing_hits_plural = true; }
}
cv_ok( ! $sing_hits_plural, 'singular patterns do not match plural content (the `s` blocks them)' );

cv_finish();
