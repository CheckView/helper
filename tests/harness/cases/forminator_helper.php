<?php
/**
 * Behavioural verification for the Forminator helper.
 *
 * Drives the REAL methods. Critically, the entry id is supplied the way FORMINATOR
 * supplies it — via the entry object on the pre-save hook — not by hand-feeding
 * $response['entry_id'], which only exists for leads forms
 * (front-action.php:1809-1810) and whose absence is what broke the earlier
 * revision.
 */

require_once __DIR__ . '/../bootstrap.php';

cv_need_change( 'includes/formhelpers/class-checkview-forminator-helper.php', 'checkview_capture_entry_id', 'PR #238' );

define( 'WPINC', 1 );
define( 'TEST_EMAIL', 'test@test-mail.checkview.io' );

$GLOBALS['cv_log']      = array();
$GLOBALS['cv_filters']  = array();
$GLOBALS['cv_actions']  = array();
$GLOBALS['cv_opts']     = array();
$GLOBALS['cv_complete'] = 0;
$GLOBALS['cv_deleted']  = array();

function add_filter( $tag, $cb, $priority = 10, $args = 1 ) {
	$GLOBALS['cv_filters'][ $tag ][] = array( 'cb' => $cb, 'priority' => $priority );
}
function add_action( $tag, $cb, $priority = 10, $args = 1 ) {
	$GLOBALS['cv_actions'][ $tag ][] = array( 'cb' => $cb, 'priority' => $priority );
}
function apply_filters( $tag, $value ) {
	$extra = array_slice( func_get_args(), 2 );
	if ( empty( $GLOBALS['cv_filters'][ $tag ] ) ) { return $value; }
	$cbs = $GLOBALS['cv_filters'][ $tag ];
	usort( $cbs, fn( $a, $b ) => $a['priority'] <=> $b['priority'] );
	foreach ( $cbs as $c ) { $value = call_user_func_array( $c['cb'], array_merge( array( $value ), $extra ) ); }
	return $value;
}
function __return_false() { return false; }
function __return_true() { return true; }
function __return_null() { return null; }
function get_option( $k, $d = false ) { return $GLOBALS['cv_opts'][ $k ] ?? $d; }
function wp_json_encode( $v ) { return json_encode( $v ); }
function current_time( $t ) { return '2026-01-01 00:00:00'; }
function sanitize_url( $u ) { return $u; }
function wp_unslash( $v ) { return $v; }
function maybe_serialize( $v ) { return is_array( $v ) || is_object( $v ) ? serialize( $v ) : $v; }
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
class Forminator_Form_Entry_Model {
	public static $rows = array();
	public $entry_id = 0;
	public $form_id  = 0;
	public $status    = 'active';
	public $meta_data = array();
	public function __construct( $entry_id = 0 ) {
		if ( isset( self::$rows[ $entry_id ] ) ) {
			$this->entry_id  = $entry_id;
			$this->form_id   = self::$rows[ $entry_id ]['form_id'] ?? 7;
			$this->status    = self::$rows[ $entry_id ]['status'];
			$this->meta_data = self::$rows[ $entry_id ]['meta'];
		}
	}
	public static function delete_by_entry( $id ) { $GLOBALS['cv_deleted'][] = $id; }
}
class Fake_WPDB {
	public $prefix = 'wp_';
	public $last_error = '';
	public $inserts = array();
	public function insert( $t, $d ) { $this->inserts[ $t ][] = $d; return 1; }
}

$_SERVER['HTTP_REFERER'] = 'https://example.test/contact/';

require_once CV_PLUGIN_DIR . '/includes/formhelpers/class-checkview-forminator-helper.php';


$h = new Checkview_Forminator_Helper();

echo "\n=== hook wiring ===\n";
cv_ok( ! empty( $GLOBALS['cv_actions']['forminator_custom_form_submit_before_set_fields'] ), 'capture hook registered' );
cv_ok( 1 === $GLOBALS['cv_actions']['forminator_custom_form_submit_before_set_fields'][0]['priority'], 'capture at priority 1' );
cv_ok( ! empty( $GLOBALS['cv_actions']['forminator_form_after_save_entry'] ), 'AJAX act hook registered' );
cv_ok( ! empty( $GLOBALS['cv_actions']['forminator_form_after_handle_submit'] ), 'NON-AJAX act hook registered (forms POST unless AJAX is enabled)' );
// The original guarantee here was "nothing is deferred to shutdown" — the clone,
// delete and completion all run inline on the post-save hooks. A shutdown hook
// now exists, but it is diagnostic only, so assert the intent rather than the
// mere absence of the hook.
// NB: the helper file instantiates itself at the bottom, and this harness then
// constructs it again — so EVERY hook appears twice in this registry. Assert on
// callback identity, not on count, or the artifact reads as a defect.
$shutdown_cbs = $GLOBALS['cv_actions']['shutdown'] ?? array();
cv_ok( ! empty( $shutdown_cbs ), 'a shutdown callback is registered' );
$shutdown_methods = array_unique(
	array_map(
		fn( $c ) => is_array( $c['cb'] ) ? $c['cb'][1] : (string) $c['cb'],
		$shutdown_cbs
	)
);
cv_ok(
	array( 'checkview_log_unfinished_submission' ) === array_values( $shutdown_methods ),
	'and the ONLY thing on shutdown is the diagnostic, never the clone handler (got: ' . implode( ', ', $shutdown_methods ) . ')'
);
foreach ( array( 'forminator_form_after_save_entry', 'forminator_form_after_handle_submit' ) as $act_tag ) {
	$m = is_array( $GLOBALS['cv_actions'][ $act_tag ][0]['cb'] ?? null ) ? $GLOBALS['cv_actions'][ $act_tag ][0]['cb'][1] : '';
	cv_ok( 'checkview_log_form_test_entry' === $m, "clone/complete still runs inline on {$act_tag}, not deferred" );
}

/**
 * Simulates Forminator: capture hook receives the entry object, then the act hook
 * fires with only ( $form_id, $response ) — and NO entry_id in $response, which is
 * the real shape for a non-leads form.
 */
function submit( $h, int $entry_id, string $status, array $meta, string $act = 'forminator_form_after_save_entry', bool $leads = false ) {
	global $wpdb;
	$wpdb = new Fake_WPDB();
	Forminator_Form_Entry_Model::$rows = $entry_id > 0
		? array( $entry_id => array( 'status' => $status, 'meta' => $meta, 'form_id' => 7 ) )
		: array();
	$GLOBALS['cv_log'] = array();
	$GLOBALS['cv_complete'] = 0;
	$GLOBALS['cv_deleted'] = array();

	// Pre-save hook: Forminator hands us the entry object.
	$entry = new stdClass();
	$entry->entry_id = $entry_id;   // 0 when prevent_store() skipped the save
	$h->checkview_capture_entry_id( $entry, 7, array() );

	// Act hook: $response has NO entry_id unless this is a leads form.
	$response = $leads ? array( 'entry_id' => $entry_id, 'success' => true ) : array( 'success' => true );
	$h->checkview_log_form_test_entry( 7, $response );

	return array( 'inserts' => $wpdb->inserts, 'complete' => $GLOBALS['cv_complete'], 'deleted' => $GLOBALS['cv_deleted'], 'log' => implode( ' | ', $GLOBALS['cv_log'] ) );
}
function cloned( array $inserts, string $key ) {
	foreach ( $inserts['wp_cv_entry_meta'] ?? array() as $row ) {
		if ( $key === $row['meta_key'] ) { return $row['meta_value']; }
	}
	return null;
}

// value != label throughout: the SaaS comparison lowercases, strips punctuation
// and falls back to substring containment, so value === label proves nothing.
$meta = array(
	'name-1'                    => array( 'id' => 1, 'value' => 'Alice' ),
	'select-1'                  => array( 'id' => 2, 'value' => 'Premium Plan' ),
	'checkbox-1'                => array( 'id' => 3, 'value' => array( 'Blue', 'Green' ) ),
	'address-1'                 => array( 'id' => 4, 'value' => array( 'street' => '1 High St', 'city' => 'Leeds' ) ),
	'_forminator_user_ip'       => array( 'id' => 5, 'value' => '203.0.113.9' ),
	'_forminator_choice_values' => array( 'id' => 6, 'value' => array( 'select-1' => 'opt_1', 'checkbox-1' => array( 'c_blue', 'c_green' ) ) ),
);

echo "\n=== THE BUG THAT WAS FIXED: a NON-LEADS form has no entry_id in \$response ===\n";
$r = submit( $h, 501, 'active', $meta );   // leads = false
cv_ok( ! empty( $r['inserts']['wp_cv_entry'] ), 'cv_entry row written even though $response carries no entry_id' );
cv_ok( array( 501 ) === $r['deleted'], 'the Forminator entry IS deleted' );
cv_ok( 1 === $r['complete'], 'test completed once' );
cv_ok( false === strpos( $r['log'], 'stored no entry' ), 'does NOT mistake a real submission for prevent_store' );

echo "\n=== non-AJAX path uses the same handler ===\n";
$r = submit( $h, 502, 'active', $meta, 'forminator_form_after_handle_submit' );
cv_ok( ! empty( $r['inserts']['wp_cv_entry'] ), 'non-AJAX submission cloned' );
cv_ok( 1 === $r['complete'], 'and completed' );

echo "\n=== raw values, not labels ===\n";
$r = submit( $h, 503, 'active', $meta );
cv_ok( 'opt_1' === cloned( $r['inserts'], 'select-1' ), 'select sends raw opt_1, not the label' );
cv_ok( 'Premium Plan' !== cloned( $r['inserts'], 'select-1' ), 'the label is definitively not sent' );
cv_ok( 'c_blue, c_green' === cloned( $r['inserts'], 'checkbox-1' ), 'multi-value sends joined raw values' );
cv_ok( '1 High St, Leeds' === cloned( $r['inserts'], 'address-1' ), 'composite joined, not serialized' );
cv_ok( false === strpos( (string) cloned( $r['inserts'], 'address-1' ), 'a:2:' ), 'no serialized payload reaches the SaaS' );
cv_ok( is_string( cloned( $r['inserts'], 'checkbox-1' ) ), 'field_value is a string' );
$keys = array_column( $r['inserts']['wp_cv_entry_meta'], 'meta_key' );
cv_ok( ! in_array( '_forminator_user_ip', $keys, true ), 'user_ip skipped' );
cv_ok( ! in_array( '_forminator_choice_values', $keys, true ), 'choice_values skipped' );

echo "\n=== prevent_store: entry never saved, so entry_id is genuinely 0 ===\n";
$r = submit( $h, 0, 'active', array() );
cv_ok( empty( $r['inserts'] ), 'nothing cloned' );
cv_ok( empty( $r['deleted'] ), 'nothing deleted' );
cv_ok( 1 === $r['complete'], 'test STILL completed — the notification was sent' );
cv_ok( false !== strpos( $r['log'], 'stored no entry' ), 'logs why' );

echo "\n=== spam ===\n";
$r = submit( $h, 504, 'spam', $meta );
cv_ok( empty( $r['inserts'] ), 'not cloned' );
cv_ok( array( 504 ) === $r['deleted'], 'our own submission removed' );
cv_ok( 0 === $r['complete'], 'NOT completed — no notification was sent' );

echo "\n=== leads form still works (its \$response DOES carry entry_id) ===\n";
$r = submit( $h, 505, 'active', $meta, 'forminator_form_after_save_entry', true );
cv_ok( 1 === $r['complete'], 'leads submission completes' );

echo "\n=== stale id cannot leak between submissions ===\n";
global $wpdb;
$wpdb = new Fake_WPDB();
Forminator_Form_Entry_Model::$rows = array( 601 => array( 'status' => 'active', 'meta' => $meta, 'form_id' => 7 ) );
$GLOBALS['cv_complete'] = 0; $GLOBALS['cv_deleted'] = array();
$e = new stdClass(); $e->entry_id = 601;
$h->checkview_capture_entry_id( $e, 7, array() );
$h->checkview_log_form_test_entry( 7, array() );
$first = $GLOBALS['cv_deleted'];
// A second act WITHOUT a fresh capture must not re-use 601.
$GLOBALS['cv_deleted'] = array();
$h->checkview_log_form_test_entry( 7, array() );
cv_ok( array( 601 ) === $first, 'first submission deleted 601' );
cv_ok( empty( $GLOBALS['cv_deleted'] ), 'a later act with no capture does NOT re-delete 601 (id is cleared after use)' );

echo "\n=== idempotence ===\n";
$wpdb = new Fake_WPDB();
Forminator_Form_Entry_Model::$rows = array( 701 => array( 'status' => 'active', 'meta' => $meta, 'form_id' => 7 ) );
$GLOBALS['cv_complete'] = 0;
$e = new stdClass(); $e->entry_id = 701;
$h->checkview_capture_entry_id( $e, 7, array() );
$h->checkview_log_form_test_entry( 7, array() );
$h->checkview_capture_entry_id( $e, 7, array() );
$h->checkview_log_form_test_entry( 7, array() );
cv_ok( 1 === $GLOBALS['cv_complete'], 'duplicate submission completes only once' );
cv_ok( 1 === count( $wpdb->inserts['wp_cv_entry'] ?? array() ), 'only one cv_entry row' );

echo "\n=== hostile input ===\n";
$h->checkview_capture_entry_id( null, 7, array() );
$h->checkview_capture_entry_id( (object) array(), 7, array() );
$h->checkview_log_form_test_entry( 7, 'not-an-array' );
cv_ok( true, 'null entry, entry-less object and non-array response do not fatal' );

echo "\n=== captcha + email + addons (unchanged behaviour) ===\n";
$types = apply_filters( 'forminator_disabled_fields', array( 'stripe' ) );
cv_ok( in_array( 'captcha', $types, true ) && in_array( 'stripe', $types, true ), 'captcha appended, stripe preserved' );
cv_ok( false === apply_filters( 'hcap_activate', true ), 'hcap_activate false' );
$hdrs = $h->checkview_remove_email_header( array( 'From: a@b.test', 'Cc: c@d.test', 'Bcc: e@f.test', 'Content-Type: text/html' ) );
$j = implode( ' | ', $hdrs );
cv_ok( false === stripos( $j, 'Cc:' ) && false === stripos( $j, 'Bcc:' ), 'Cc/Bcc stripped' );
cv_ok( false !== strpos( $j, 'From:' ), 'From preserved' );
$GLOBALS['cv_opts'] = array( 'disable_actions_cv-test-123' => 'true' );
cv_ok( false === $h->checkview_disable_form_actions( true ), 'addons disabled when flagged' );

cv_finish();
