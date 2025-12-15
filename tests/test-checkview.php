<?php

class Test_Checkview_Bootstrap extends WP_UnitTestCase {

	/**
	 * Test that the plugin version is defined
	 */
	public function test_plugin_version_defined() {
		$this->assertTrue( defined( 'CHECKVIEW_VERSION' ) );
		$this->assertEquals( '2.0.24', CHECKVIEW_VERSION );
	}

	/**
	 * Test that the plugin directories are defined
	 */
	public function test_plugin_directories_defined() {
		$this->assertTrue( defined( 'CHECKVIEW_BASE_DIR' ) );
		$this->assertTrue( defined( 'CHECKVIEW_PLUGIN_DIR' ) );
		$this->assertTrue( defined( 'CHECKVIEW_INC_DIR' ) );
		$this->assertTrue( defined( 'CHECKVIEW_PUBLIC_DIR' ) );
		$this->assertTrue( defined( 'CHECKVIEW_ADMIN_DIR' ) );
		$this->assertTrue( defined( 'CHECKVIEW_ADMIN_ASSETS' ) );
		$this->assertTrue( defined( 'CHECKVIEW_PUBLIC_ASSETS' ) );
		$this->assertTrue( defined( 'CHECKVIEW_URI' ) );
		$this->assertTrue( defined( 'CHECKVIEW_EMAIL' ) );
	}

	/**
	 * Test that the activation and deactivation functions are registered
	 */
	public function test_activation_deactivation_functions_registered() {
		$this->assertTrue( has_action( 'activate_' . CHECKVIEW_BASE_DIR ) );
		$this->assertTrue( has_action( 'deactivate_' . CHECKVIEW_BASE_DIR ) );

		$this->assertEquals( 10, has_action( 'activate_' . CHECKVIEW_BASE_DIR, 'activate_checkview' ) );
		$this->assertEquals( 10, has_action( 'deactivate_' . CHECKVIEW_BASE_DIR, 'deactivate_checkview' ) );
	}

	/**
	 * Test that the run_checkview function is registered
	 */
	public function test_run_checkview_function_registered() {
		$this->assertEquals( 10, has_action( 'plugins_loaded', 'run_checkview' ) );
		$this->assertTrue( has_action( 'plugins_loaded' ) );
	}
}
