<?php

class Test_Checkview_WSF_Helper extends WP_UnitTestCase {

	private $helper;

	public function setUp(): void {
		parent::setUp();
		if ( is_plugin_active( 'ws-form/ws-form.php' ) || is_plugin_active( 'ws-form-pro/ws-form.php' ) ) {
			require_once CHECKVIEW_INC_DIR . 'formhelpers/class-checkview-wsf-helper.php';
		}
		$this->helper = new Checkview_WSF_Helper();
	}

	public function test_construct() {
		$this->assertInstanceOf( 'Checkview_WSF_Helper', $this->helper );
		$this->assertInstanceOf( 'Checkview_Loader', $this->helper->loader );
	}

	public function test_disable_addons_feed_keeps_database_when_flag_set() {
		$test_id                         = 'aaaaaaaa-bbbb-4ccc-9ddd-eeeeeeeeeeee';
		$_REQUEST['checkview_test_id']   = $test_id;
		$_REQUEST[CheckView::PARAM_TEST_TYPE] = 'form';
		update_option( 'disable_actions_' . $test_id, 'true', false );

		// Pass $run=false to prove the allowlist FORCES true regardless of input.
		$config = array( 'id' => 'database' );
		$result = $this->helper->checkview_disable_addons_feed( false, null, null, null, false, $config );
		$this->assertTrue( $result, 'Database action must run even when disable_actions is set and $run=false.' );

		delete_option( 'disable_actions_' . $test_id );
		unset( $_REQUEST['checkview_test_id'], $_REQUEST[CheckView::PARAM_TEST_TYPE] );
	}

	public function test_disable_addons_feed_keeps_message_when_flag_set() {
		$test_id                         = 'aaaaaaaa-bbbb-4ccc-9ddd-eeeeeeeeeeee';
		$_REQUEST['checkview_test_id']   = $test_id;
		$_REQUEST[CheckView::PARAM_TEST_TYPE] = 'form';
		update_option( 'disable_actions_' . $test_id, 'true', false );

		$config = array( 'id' => 'message' );
		$result = $this->helper->checkview_disable_addons_feed( false, null, null, null, false, $config );
		$this->assertTrue( $result );

		delete_option( 'disable_actions_' . $test_id );
		unset( $_REQUEST['checkview_test_id'], $_REQUEST[CheckView::PARAM_TEST_TYPE] );
	}

	public function test_disable_addons_feed_keeps_email_when_flag_set() {
		$test_id                         = 'aaaaaaaa-bbbb-4ccc-9ddd-eeeeeeeeeeee';
		$_REQUEST['checkview_test_id']   = $test_id;
		$_REQUEST[CheckView::PARAM_TEST_TYPE] = 'form';
		update_option( 'disable_actions_' . $test_id, 'true', false );

		$config = array( 'id' => 'email' );
		$result = $this->helper->checkview_disable_addons_feed( false, null, null, null, false, $config );
		$this->assertTrue( $result );

		delete_option( 'disable_actions_' . $test_id );
		unset( $_REQUEST['checkview_test_id'], $_REQUEST[CheckView::PARAM_TEST_TYPE] );
	}

	public function test_disable_addons_feed_blocks_third_party_when_flag_set() {
		$test_id                         = 'aaaaaaaa-bbbb-4ccc-9ddd-eeeeeeeeeeee';
		$_REQUEST['checkview_test_id']   = $test_id;
		$_REQUEST[CheckView::PARAM_TEST_TYPE] = 'form';
		update_option( 'disable_actions_' . $test_id, 'true', false );

		$config = array( 'id' => 'mailchimp' );
		$result = $this->helper->checkview_disable_addons_feed( true, null, null, null, false, $config );
		$this->assertFalse( $result, 'Third-party action must be blocked when disable_actions is set.' );

		delete_option( 'disable_actions_' . $test_id );
		unset( $_REQUEST['checkview_test_id'], $_REQUEST[CheckView::PARAM_TEST_TYPE] );
	}

	public function test_disable_addons_feed_third_party_runs_without_flag() {
		$config = array( 'id' => 'mailchimp' );
		$result = $this->helper->checkview_disable_addons_feed( true, null, null, null, false, $config );
		$this->assertTrue( $result, 'Third-party action passes through original $run when disable_actions is unset.' );
	}

	public function test_disable_addons_feed_passes_through_run_value() {
		$config = array( 'id' => 'mailchimp' );
		$result = $this->helper->checkview_disable_addons_feed( false, null, null, null, false, $config );
		$this->assertFalse( $result, 'Original $run=false must be preserved when no override applies.' );
	}
}
