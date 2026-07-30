<?php
/**
 * Verification for the legacy [contact-form] discovery patterns.
 *
 * The pattern templates are read from the shipped API file and evaluated with
 * real SQL LIKE semantics against content CF7 actually produces.
 */

require_once __DIR__ . '/../bootstrap.php';

cv_need_fixture( 'contact-form-7' );
cv_need_change( 'includes/API/class-checkview-api.php', '_old_cf7_unit_id', 'PR #245' );

$API = CV_PLUGIN_DIR . '/includes/API/class-checkview-api.php';
$CF7 = CV_FIXTURES . '/contact-form-7/';



echo "\n=== CF7 really does still register the legacy tag ===\n";
$load = file_get_contents( $CF7 . 'load.php' );
cv_ok(
	(bool) preg_match( "/add_shortcode\(\s*'contact-form-7',\s*'wpcf7_contact_form_tag_func'\s*\)/", $load ),
	'registers [contact-form-7]'
);
cv_ok(
	(bool) preg_match( "/add_shortcode\(\s*'contact-form',\s*'wpcf7_contact_form_tag_func'\s*\)/", $load ),
	'AND registers [contact-form] to the same callback'
);

echo "\n=== and it resolves that tag by _old_cf7_unit_id, not post ID ===\n";
$fns = file_get_contents( $CF7 . 'includes/contact-form-functions.php' );
cv_ok(
	(bool) preg_match( "/if \(\s*'contact-form-7' === \\\$code \)/", $fns ),
	'the callback branches on which tag was used'
);
cv_ok(
	(bool) preg_match( '/\$id = \(int\) array_shift\( \$atts \);\s*\n\s*\$contact_form = wpcf7_get_contact_form_by_old_id\( \$id \);/', $fns ),
	'the legacy branch takes the first positional att and resolves it by OLD id'
);
cv_ok(
	(bool) preg_match( "/'key' => '_old_cf7_unit_id'/", $fns ),
	'which is a lookup on the _old_cf7_unit_id post meta'
);

echo "\n=== the shipped patterns ===\n";
$api = file_get_contents( $API );
cv_ok(
	(bool) preg_match( "/get_post_meta\(\s*\\\$row->ID,\s*'_old_cf7_unit_id',\s*true\s*\)/", $api ),
	'the helper reads _old_cf7_unit_id (not the post ID) for these patterns'
);
cv_ok(
	(bool) preg_match( "/if \(\s*'' !== \\\$old_unit_id && is_numeric\( \\\$old_unit_id \)/", $api ),
	'and only adds patterns when that meta exists and is numeric'
);

preg_match_all( "/\\\$cf7_patterns\[\] = '%\[contact-form ' \. \\\$old_unit_id \. '(.*?)';/", $api, $pm );
cv_ok( 2 === count( $pm[1] ), 'found both legacy pattern templates (got ' . count( $pm[1] ) . ')' );

$old_id   = 1;
$patterns = array();
foreach ( $pm[1] as $tail ) {
	$patterns[] = '%[contact-form ' . $old_id . $tail;
}

$matches = function ( string $content ) use ( $patterns ): bool {
	foreach ( $patterns as $p ) {
		if ( cv_like( $p, $content ) ) { return true; }
	}
	return false;
};

echo "\n=== finds real legacy placements ===\n";
cv_ok( $matches( 'Intro [contact-form 1] outro' ), 'plain [contact-form 1]' );
cv_ok( $matches( '[contact-form 1 "Contact form 1"]' ), '[contact-form 1 "Title"] (the form CF7 itself emitted)' );
cv_ok( $matches( "<p>text</p>\n[contact-form 1]\n<p>more</p>" ), 'across newlines' );

echo "\n=== does not steal other forms' pages ===\n";
cv_ok( ! $matches( '[contact-form 12]' ), 'old id 1 does NOT match [contact-form 12]' );
cv_ok( ! $matches( '[contact-form 123 "x"]' ), 'old id 1 does NOT match [contact-form 123 "x"]' );
cv_ok( ! $matches( '[contact-form 10]' ), 'old id 1 does NOT match [contact-form 10]' );

echo "\n=== stays out of the modern tag's territory ===\n";
cv_ok( ! $matches( '[contact-form-7 id="1"]' ), 'does not match [contact-form-7 id="1"]' );
cv_ok( ! $matches( '[contact-form-7 id="abc1234" title="X"]' ), 'does not match a hash-id [contact-form-7]' );
cv_ok( ! $matches( '[contact-form-71]' ), 'does not match [contact-form-71]' );

echo "\n=== the unbounded form would have been wrong ===\n";
$unbounded = '%[contact-form ' . $old_id;
cv_ok( cv_like( $unbounded . '%', '[contact-form 12]' ), 'an unterminated pattern WOULD have matched [contact-form 12]' );
cv_ok( ! $matches( '[contact-form 12]' ), 'the shipped patterns are terminated, so they do not' );

cv_finish();
