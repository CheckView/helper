<?php
/**
 * Tests for global functions in includes/checkview-functions.php.
 *
 * This file is intentionally ungated (no is_plugin_active() check) so it runs
 * on CI, where form plugins like Gravity Forms are not installed. Tests here
 * should only exercise pure functions that don't require GF/etc. to be loaded.
 */
class TestCheckviewFunctions extends WP_UnitTestCase {

	/**
	 * Confirms the deferred-delete cron handler is registered with the
	 * 'checkview_gf_deferred_entry_delete' action. This is the primary
	 * regression guard for the GF async-feed fix; if a future refactor
	 * accidentally removes the registration, this test catches it on CI.
	 */
	public function test_deferred_delete_handler_is_registered() {
		$priority = has_action( 'checkview_gf_deferred_entry_delete', 'checkview_gf_run_deferred_entry_delete' );
		$this->assertNotFalse( $priority, 'checkview_gf_run_deferred_entry_delete must be registered on checkview_gf_deferred_entry_delete.' );
	}

	/**
	 * checkview_gf_should_defer_delete() defaults to true (the fix is enabled).
	 * Constant-based opt-out is tested in tests/test-gf-legacy-mode.php, which
	 * lives in a separate file because define() is irreversible per process.
	 */
	public function test_should_defer_delete_default_is_true() {
		$this->assertTrue( checkview_gf_should_defer_delete() );
	}
}
