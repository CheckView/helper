<?php
/**
 * Verification for the Formidable form-list filter.
 *
 * The WHERE clause is EXECUTED against a real (SQLite) frm_forms table holding
 * the row shapes Formidable actually produces, rather than pattern-matched. The
 * clause itself is extracted from the shipped plugin file, so it cannot drift.
 */

require_once __DIR__ . '/../bootstrap.php';

cv_need_fixture( 'formidable' );
cv_need_change( 'includes/API/class-checkview-api.php', 'get_published_forms', 'PR #244' );
if ( ! in_array( 'sqlite', PDO::getAvailableDrivers(), true ) ) {
	cv_skip( 'pdo_sqlite is not available — this case executes the WHERE clause against a real table' );
}

$API = CV_PLUGIN_DIR . '/includes/API/class-checkview-api.php';
$FRM = CV_FIXTURES . '/formidable/classes/models/FrmForm.php';


$db = new PDO( 'sqlite::memory:' );
$db->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
$db->exec(
	'CREATE TABLE frm_forms (
		id INTEGER PRIMARY KEY, name TEXT, status TEXT,
		is_template INTEGER DEFAULT 0, parent_form_id INTEGER DEFAULT 0
	)'
);

/**
 * The row shapes Formidable actually produces.
 * Fixture => [status, is_template, parent_form_id, should_be_listed]
 */
$rows = array(
	'ordinary published form'      => array( 'published', 0, 0, true ),
	'form with NULL status'        => array( null, 0, 0, true ),
	'form with empty status'       => array( '', 0, 0, true ),
	'form with NULL parent column' => array( 'published', 0, null, true ),
	'a TEMPLATE'                   => array( 'published', 1, 0, false ),
	'a template, NULL status'      => array( null, 1, 0, false ),
	'a CHILD form (repeater)'      => array( 'published', 0, 7, false ),
	'a trashed form'               => array( 'trash', 0, 0, false ),
	'a draft form'                 => array( 'draft', 0, 0, false ),
);
$i = 0;
foreach ( $rows as $label => $r ) {
	$st = $db->prepare( 'INSERT INTO frm_forms (id,name,status,is_template,parent_form_id) VALUES (?,?,?,?,?)' );
	$st->execute( array( ++$i, $label, $r[0], $r[1], $r[2] ) );
}

echo "\n=== the clause is Formidable's own (read from FrmForm.php) ===\n";
$frm = file_get_contents( $FRM );
preg_match( '/public static function get_published_forms\(.*?\n\t\}/s', $frm, $gm );
$gp = $gm[0] ?? '';
cv_ok( '' !== $gp, 'extracted FrmForm::get_published_forms()' );
cv_ok( false !== strpos( $gp, "\$query['is_template'] = 0;" ), 'upstream excludes templates' );
cv_ok(
	(bool) preg_match( "/\\\$query\['status'\]\s*=\s*array\(\s*null,\s*'',\s*'published'\s*\)/", $gp ),
	"upstream treats NULL and '' as published"
);
cv_ok(
	(bool) preg_match( "/\\\$query\['parent_form_id'\]\s*=\s*array\(\s*null,\s*0\s*\)/", $gp ),
	'upstream excludes child forms (NULL or 0 parent)'
);

echo "\n=== extract the shipped WHERE clause and run it ===\n";
$api = file_get_contents( $API );
preg_match(
	"/'SELECT \* FROM ' \. \\\$tablename \. \"(.*?)\",\s*0,\s*'published',\s*0/s",
	$api,
	$m
);
cv_ok( ! empty( $m[1] ), 'extracted the fallback WHERE clause from the shipped file' );

$clause = $m[1] ?? '';
// Substitute wpdb placeholders positionally: %d, %s, %d -> 0, 'published', 0.
$sql_new = 'SELECT id, name FROM frm_forms ' . $clause;
$sql_new = preg_replace( '/%d/', '0', $sql_new, 1 );
$sql_new = preg_replace( '/%s/', "'published'", $sql_new, 1 );
$sql_new = preg_replace( '/%d/', '0', $sql_new, 1 );

$got = array();
foreach ( $db->query( $sql_new )->fetchAll( PDO::FETCH_ASSOC ) as $r ) { $got[] = $r['name']; }

foreach ( $rows as $label => $r ) {
	$listed = in_array( $label, $got, true );
	cv_ok( $listed === $r[3], ( $r[3] ? 'lists' : 'excludes' ) . ": $label" );
}

echo "\n=== the OLD clause is what caused it ===\n";
$old = array();
foreach ( $db->query( "SELECT id, name FROM frm_forms WHERE 1=1 AND status='published'" )->fetchAll( PDO::FETCH_ASSOC ) as $r ) {
	$old[] = $r['name'];
}
cv_ok( in_array( 'a TEMPLATE', $old, true ), 'old query listed templates as testable' );
cv_ok( in_array( 'a CHILD form (repeater)', $old, true ), 'old query listed child forms as testable' );
cv_ok( ! in_array( 'form with NULL status', $old, true ), 'old query MISSED forms with NULL status' );
cv_ok( ! in_array( 'form with empty status', $old, true ), "old query MISSED forms with '' status" );
// This one the old query got right (it is published); the point is that adding
// the parent_form_id condition must not start excluding it, since a NULL parent
// means top-level exactly as 0 does.
cv_ok( in_array( 'form with NULL parent column', $old, true ), 'old query listed NULL-parent forms, and the new clause still does' );

echo "\n=== prefers Formidable's own API when it is loadable ===\n";
cv_ok(
	(bool) preg_match( "/is_callable\(\s*array\(\s*'FrmForm',\s*'get_published_forms'\s*\)\s*\)/", $api ),
	'guards on is_callable() rather than assuming the class is loaded'
);
cv_ok(
	(bool) preg_match( '/FrmForm::get_published_forms\(\s*array\(\),\s*(\d+)\s*\)/', $api, $lm ),
	'calls the API with an explicit limit'
);
cv_ok(
	isset( $lm[1] ) && (int) $lm[1] > 999,
	'and that limit exceeds the 999 default, so large sites are not truncated (got ' . ( $lm[1] ?? '?' ) . ')'
);

cv_finish();
