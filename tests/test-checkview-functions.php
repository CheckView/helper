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

	/**
	 * Confirms the FF deferred-delete cron handler is registered with the
	 * 'checkview_ff_deferred_entry_delete' action. Regression guard for the
	 * Fluent Forms async-feed fix.
	 */
	public function test_ff_deferred_delete_handler_is_registered() {
		$priority = has_action( 'checkview_ff_deferred_entry_delete', 'checkview_ff_run_deferred_entry_delete' );
		$this->assertNotFalse( $priority, 'checkview_ff_run_deferred_entry_delete must be registered on checkview_ff_deferred_entry_delete.' );
	}

	/**
	 * checkview_ff_should_defer_delete() defaults to true (the fix is enabled).
	 * Constant-based opt-out is tested in tests/test-ff-legacy-mode.php.
	 */
	public function test_ff_should_defer_delete_default_is_true() {
		$this->assertTrue( checkview_ff_should_defer_delete() );
	}

	/**
	 * Confirms the NF deferred-delete cron handler is registered with the
	 * 'checkview_nf_deferred_entry_delete' action. Regression guard for the
	 * Ninja Forms defense-in-depth fix.
	 */
	public function test_nf_deferred_delete_handler_is_registered() {
		$priority = has_action( 'checkview_nf_deferred_entry_delete', 'checkview_nf_run_deferred_entry_delete' );
		$this->assertNotFalse( $priority, 'checkview_nf_run_deferred_entry_delete must be registered on checkview_nf_deferred_entry_delete.' );
	}

	/**
	 * checkview_nf_should_defer_delete() defaults to true (the fix is enabled).
	 * Constant-based opt-out is tested in tests/test-nf-legacy-mode.php.
	 */
	public function test_nf_should_defer_delete_default_is_true() {
		$this->assertTrue( checkview_nf_should_defer_delete() );
	}
}
