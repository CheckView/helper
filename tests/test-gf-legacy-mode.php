<?php
/**
 * Tests for the CHECKVIEW_GF_DEFER_ENTRY_DELETE escape hatch constant.
 *
 * This file is intentionally separate from tests/test-gf.php because define()
 * is irreversible within a single PHP process, and the constant set in this
 * file would pollute other tests that depend on the default (deferred) behavior.
 *
 * Filename ordering: tests/test-checkview-functions.php sorts before
 * tests/test-gf-legacy-mode.php alphabetically, so the registration test in
 * test-checkview-functions runs first when both run on CI — meaning the
 * constant defined here cannot leak into the registration test.
 *
 * NO plugin gate — checkview_gf_should_defer_delete() is pure PHP (only checks
 * a constant) and doesn't require Gravity Forms to be loaded. Test runs on CI
 * for full coverage.
 *
 * If anyone adds a second test to this file later that depends on the constant
 * being unset, they'll need @runInSeparateProcess + @preserveGlobalState
 * disabled annotations on that test.
 */
class TestCheckviewGfLegacyMode extends WP_UnitTestCase {

	/**
	 * Defining CHECKVIEW_GF_DEFER_ENTRY_DELETE = false should opt out of the
	 * deferred-delete fix and revert to legacy synchronous deletion behavior.
	 */
	public function test_should_defer_delete_respects_constant() {
		// Default behavior (constant undefined) returns true.
		if ( ! defined( 'CHECKVIEW_GF_DEFER_ENTRY_DELETE' ) ) {
			$this->assertTrue( checkview_gf_should_defer_delete(), 'Default behavior should defer.' );
		}

		// Define the constant to opt out.
		define( 'CHECKVIEW_GF_DEFER_ENTRY_DELETE', false );

		$this->assertFalse(
			checkview_gf_should_defer_delete(),
			'CHECKVIEW_GF_DEFER_ENTRY_DELETE=false must disable deferred deletion (escape hatch).'
		);
	}
}
