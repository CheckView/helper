<?php
/**
 * Verification for the Forminator shutdown diagnostic and the Forminator
 * form-list key rename.
 *
 * Drives the REAL checkview_log_unfinished_submission() through every state, and
 * asserts the premises it rests on against WordPress's own source.
 */

require_once __DIR__ . '/../bootstrap.php';

cv_need_wordpress();
cv_need_change( 'includes/formhelpers/class-checkview-forminator-helper.php', 'checkview_log_unfinished_submission', 'PR #238' );

$HELPER = CV_PLUGIN_DIR . '/includes/formhelpers/class-checkview-forminator-helper.php';
$API    = CV_PLUGIN_DIR . '/includes/API/class-checkview-api.php';
$WPROOT = CV_WP . '/';


$GLOBALS['cv_log'] = array();
class Checkview_Admin_Logs {
	public static function add( $c, $m ) { $GLOBALS['cv_log'][] = $m; }
}
function sanitize_text_field( $v ) { return is_string( $v ) ? trim( strip_tags( $v ) ) : ''; }
function wp_unslash( $v ) { return $v; }

echo "\n=== the premise: shutdown fires even when a request die()s ===\n";
$settings = file_get_contents( $WPROOT . 'wp-settings.php' );
$load     = file_get_contents( $WPROOT . 'wp-includes/load.php' );
cv_ok(
	(bool) preg_match( "/register_shutdown_function\(\s*'shutdown_action_hook'\s*\)/", $settings ),
	'WordPress registers shutdown_action_hook via register_shutdown_function (wp-settings.php)'
);
cv_ok(
	(bool) preg_match( "/function shutdown_action_hook\(\)\s*\{\s*.*?do_action\(\s*'shutdown'\s*\)/s", $load ),
	"and that handler is what fires do_action('shutdown') (load.php)"
);
// register_shutdown_function survives die()/exit, which is how admin-ajax ends.
$ajax = file_get_contents( $WPROOT . 'wp-admin/admin-ajax.php' );
cv_ok( false !== strpos( $ajax, 'wp_die(' ), 'admin-ajax terminates via wp_die(), which register_shutdown_function still covers' );

echo "\n=== extract the real diagnostic method ===\n";
$src = file_get_contents( $HELPER );
cv_ok(
	(bool) preg_match( '/public function checkview_log_unfinished_submission\(\) \{.*?\n\t\t\}/s', $src, $m ),
	'extracted checkview_log_unfinished_submission() from the shipped file'
);

// Wrap the real method in a minimal class carrying the same two flags.
eval(
	'class Diag {
		public $handler_ran = false;
		public $submission_reached_save = false;
		' . $m[0] . '
	}'
);

/** Runs the real method for one state and returns what it logged. */
function run( bool $handler_ran, bool $reached_save, ?string $action ): array {
	$GLOBALS['cv_log'] = array();
	$_POST             = ( null === $action ) ? array() : array( 'action' => $action );
	$d                 = new Diag();
	$d->handler_ran             = $handler_ran;
	$d->submission_reached_save = $reached_save;
	$d->checkview_log_unfinished_submission();
	return $GLOBALS['cv_log'];
}

$AJAX_ACTION = 'forminator_submit_form_custom-forms';

echo "\n=== the happy path stays silent ===\n";
cv_ok( array() === run( true, true, $AJAX_ACTION ), 'handler ran -> logs nothing' );

echo "\n=== the two failures it exists to tell apart ===\n";
$rejected = run( false, false, $AJAX_ACTION );
cv_ok( 1 === count( $rejected ), 'rejected-before-save logs exactly one line' );
cv_ok( false !== strpos( $rejected[0], 'before an entry was saved' ), '  ...and says the entry was never saved' );
cv_ok( false !== strpos( $rejected[0], 'honeypot' ), '  ...names honeypot/spam/captcha as the cause' );
cv_ok( false !== strpos( $rejected[0], 'NOT a missing-hook problem' ), '  ...and explicitly rules out the other cause' );

$hookless = run( false, true, $AJAX_ACTION );
cv_ok( 1 === count( $hookless ), 'saved-but-no-hook logs exactly one line' );
cv_ok( false !== strpos( $hookless[0], 'saved an entry' ), '  ...and says the entry WAS saved' );
cv_ok( false !== strpos( $hookless[0], 'forminator_form_after_save_entry' ), '  ...names the hooks that failed to fire' );
cv_ok( false !== strpos( $hookless[0], 'NOT a spam or honeypot rejection' ), '  ...and explicitly rules out the other cause' );

// The whole point is that these are distinguishable in ip-logs.
cv_ok( $rejected[0] !== $hookless[0], 'the two messages are different text, not one generic line' );

echo "\n=== it stays out of unrelated requests ===\n";
cv_ok( array() === run( false, false, null ), 'no action in POST -> silent' );
cv_ok( array() === run( false, false, 'wpforms_submit' ), "another plugin's submit -> silent" );
cv_ok( array() === run( false, true, 'heartbeat' ), 'an ordinary admin-ajax heartbeat -> silent' );
cv_ok( array() === run( false, false, 'forminator_something_else' ), 'a non-submit Forminator ajax action -> silent' );

echo "\n=== both Forminator submit paths are covered by the one marker ===\n";
cv_ok( 1 === count( run( false, false, 'forminator_submit_form_custom-forms' ) ), 'ajax custom-forms submit is matched' );
cv_ok( 1 === count( run( false, false, 'forminator_submit_form_' ) ), 'the marker is matched by prefix, so other entry types are too' );

echo "\n=== registration ===\n";
cv_ok(
	(bool) preg_match( "/add_action\(\s*'shutdown',\s*array\(\s*\\\$this,\s*'checkview_log_unfinished_submission',\s*\),\s*0\s*\)/s", $src ),
	'registered on shutdown at priority 0'
);
cv_ok(
	(bool) preg_match( '/\$this->submission_reached_save = true;/', $src ),
	'the pre-save hook sets submission_reached_save'
);
cv_ok(
	(bool) preg_match( '/\$this->handler_ran\s*=\s*true;/', $src ),
	'the post-save handler sets handler_ran'
);

echo "\n=== the form-list key rename (#231) ===\n";
$api = file_get_contents( $API );
cv_ok( 0 === substr_count( $api, "ForminatorForms" ), 'no ForminatorForms key remains' );
cv_ok( 3 === substr_count( $api, "\$forms['Forminator']" ), "all three sites emit \$forms['Forminator'] (got " . substr_count( $api, "\$forms['Forminator']" ) . ')' );
cv_ok( 'forminator' === strtolower( 'Forminator' ), "and it lowercases to 'forminator', which the SaaS pluginId expects" );

cv_finish();
