<?php
/**
 * Verification for the non-positive entry-id guard in the Formidable clone
 * handler.
 *
 * The child-entry cleanup queries `WHERE parent_item_id = %d`, and Formidable
 * stores 0 in that column for every TOP-LEVEL entry
 * (FrmMigrate.php:234 — `parent_item_id BIGINT(20) default 0`). So an entry_id of
 * 0 would select the customer's whole entry table and then delete it.
 */

require_once __DIR__ . '/../bootstrap.php';

cv_need_change( 'includes/formhelpers/class-checkview-formidable-helper.php', 'parent_item_id', 'PR #234' );

define( 'WPINC', 1 );
define( 'TEST_EMAIL', 'test@test-mail.checkview.io' );

$GLOBALS['cv_log']      = array();
$GLOBALS['cv_complete'] = 0;

function add_filter( ...$a ) {}
function add_action( ...$a ) {}
function apply_filters( $t, $v ) { return $v; }
function __return_false() { return false; }
function __return_true() { return true; }
function __return_null() { return null; }
function get_option( $k, $d = false ) { return $d; }
function wp_json_encode( $v ) { return json_encode( $v ); }
function current_time( $t ) { return '2026-01-01 00:00:00'; }
function sanitize_url( $u ) { return $u; }
function wp_unslash( $v ) { return $v; }
function maybe_unserialize( $v ) { return $v; }
function checkview_truncate_meta_key( $k ) { return $k; }
function get_checkview_test_id() { return 'cv-test-123'; }
function complete_checkview_test( $id ) { $GLOBALS['cv_complete']++; }
function esc_html__( $s, $d = '' ) { return $s; }
function cv_should_allow_original_recipients() { return false; }
function cv_append_test_email_array( $r ) { return (array) $r; }
function cv_append_test_email_string( $r ) { return (string) $r; }
function cv_inject_reply_to_header( $h ) { return $h; }

class Checkview_Loader {}
class Checkview_Admin_Logs {
	public static function add( $c, $m ) { $GLOBALS['cv_log'][] = $m; }
}
class FrmField {
	public static function get_field_type( $f ) { return 'text'; }
}

class Fake_WPDB {
	public $prefix = 'wp_';
	public $last_error = '';
	public $is_draft = 0;
	/** What a `WHERE parent_item_id = 0` query would return: every top-level entry. */
	public $child_ids = array( 950, 951, 952 );
	public $inserts = array();
	public $deletes = array();
	public function prepare( $sql, ...$args ) {
		foreach ( $args as $a ) {
			if ( is_array( $a ) ) {
				foreach ( $a as $v ) { $sql = preg_replace( '/%d/', (string) (int) $v, $sql, 1 ); }
				continue;
			}
			$sql = preg_replace( '/%[ds]/', is_numeric( $a ) ? (string) $a : "'" . $a . "'", $sql, 1 );
		}
		return $sql;
	}
	public function insert( $t, $d ) { $this->inserts[ $t ][] = $d; return 1; }
	public function get_var( $sql ) { return false !== strpos( $sql, 'is_draft' ) ? $this->is_draft : null; }
	public function get_results( $sql ) { return array(); }
	public function get_col( $sql ) { return false !== strpos( $sql, 'parent_item_id' ) ? $this->child_ids : array(); }
	public function query( $sql ) {
		if ( 0 === stripos( ltrim( $sql ), 'DELETE' ) ) { $this->deletes[] = preg_replace( '/\s+/', ' ', trim( $sql ) ); }
		return 1;
	}
}

require_once CV_PLUGIN_DIR . '/includes/formhelpers/class-checkview-formidable-helper.php';


$h = ( new ReflectionClass( 'Checkview_Formidable_Helper' ) )->newInstanceWithoutConstructor();

function run( $h, $entry_id ) {
	global $wpdb;
	$wpdb = new Fake_WPDB();
	$GLOBALS['cv_log'] = array();
	$GLOBALS['cv_complete'] = 0;
	$h->checkview_log_form_test_entry( $entry_id, 7, array() );
	return array( 'deletes' => $wpdb->deletes, 'inserts' => $wpdb->inserts, 'complete' => $GLOBALS['cv_complete'], 'log' => implode( ' | ', $GLOBALS['cv_log'] ) );
}

echo "\n=== non-positive entry ids must not reach the database ===\n";
foreach ( array( 0, -1, '0', 'not-a-number', null ) as $bad ) {
	$label = var_export( $bad, true );
	$r     = run( $h, $bad );
	cv_ok( empty( $r['deletes'] ), "entry_id $label: NO delete statements issued" );
	cv_ok( empty( $r['inserts'] ), "entry_id $label: nothing cloned" );
	cv_ok( 0 === $r['complete'], "entry_id $label: test not completed" );
}
cv_ok( false !== strpos( run( $h, 0 )['log'], 'non-positive entry id' ), 'logs the refusal' );

echo "\n=== the danger is real: what the unguarded query would have done ===\n";
$db = new Fake_WPDB();
$sql = $db->prepare( 'SELECT id FROM wp_frm_items WHERE parent_item_id=%d', 0 );
cv_ok( 'SELECT id FROM wp_frm_items WHERE parent_item_id=0' === $sql, 'entry_id 0 builds a parent_item_id=0 query' );
cv_ok( 3 === count( $db->get_col( $sql ) ), 'which matches every top-level entry (0 is the DEFAULT for those)' );

echo "\n=== a valid entry id still works ===\n";
$r = run( $h, 902 );
cv_ok( ! empty( $r['inserts']['wp_cv_entry'] ), 'cv_entry row written' );
cv_ok( ! empty( $r['deletes'] ), 'deletes issued' );
cv_ok( 1 === $r['complete'], 'test completed once' );
$joined = implode( ' ;; ', $r['deletes'] );
cv_ok( false !== strpos( $joined, 'parent_item_id=902' ), 'child cleanup scoped to the real parent id' );
cv_ok( false === strpos( $joined, 'parent_item_id=0' ), 'never emits parent_item_id=0' );

cv_finish();
