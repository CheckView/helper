<?php

class Test_Checkview_Fluent_Forms_Helper extends WP_UnitTestCase {

	private $helper;

	public function setUp(): void {
		parent::setUp();
		if ( is_plugin_active( 'fluentform/fluentform.php' ) ) {
			require_once CHECKVIEW_INC_DIR . 'formhelpers/class-checkview-fluent-forms-helper.php';
		}
		if ( ! defined( 'CHECKVIEW_EMAIL' ) ) {
			define( 'CHECKVIEW_EMAIL', 'verify@test-mail.checkview.io' );
		}
		$this->helper = new Checkview_Fluent_Forms_Helper();
		$send_to      = CHECKVIEW_EMAIL;
		if ( isset( $test_form['send_to'] ) && '' !== $test_form['send_to'] ) {
			$send_to = $test_form['send_to'];
		}

		if ( ! defined( 'TEST_EMAIL' ) ) {
			define( 'TEST_EMAIL', $send_to );
		}
	}

	public function test_constructor() {
		$this->assertInstanceOf( 'Checkview_Fluent_Forms_Helper', $this->helper );
		$this->assertInstanceOf( 'Checkview_Loader', $this->helper->loader );
	}

	public function test_checkview_inject_email() {
		$address        = CHECKVIEW_EMAIL;
		$notification   = 'test notification';
		$submitted_data = array( 'key' => 'value' );
		$form           = new stdClass();
		$form->id       = 1;

		$result = $this->helper->checkview_inject_email( $address, $notification, $submitted_data, $form );
		$this->assertEquals( TEST_EMAIL, $result );
	}

	public function test_checkview_clone_fluentform_entry() {
		global $wpdb;

		$entry_id  = 1;
		$form_data = array( 'key' => 'value' );
		$form      = new stdClass();
		$form->id  = 1;

		$this->helper->checkview_clone_fluentform_entry( $entry_id, $form_data, $form );

		$table  = $wpdb->prefix . 'cv_entry';
		$result = $wpdb->get_results( "SELECT * FROM $table WHERE form_id = 1" );
		$this->assertNotEmpty( $result );

		$table  = $wpdb->prefix . 'cv_entry_meta';
		$result = $wpdb->get_results( "SELECT * FROM $table WHERE form_id = 1" );
		$this->assertNotEmpty( $result );
        // Test completed So Clear sessions.
	}

	public function test_filters() {
		$this->assertTrue( has_filter( 'fluentform_email_to' ) );
		$this->assertTrue( has_filter( 'fluentform/has_recaptcha' ) );
		$this->assertTrue( has_filter( 'fluentform/akismet_check_spam' ) );
		$this->assertTrue( has_filter( 'cfturnstile_whitelisted' ) );
	}

	public function test_actions() {
		$this->assertTrue( has_action( 'fluentform_submission_inserted' ) );
	}

	public function test_disable_form_actions_no_test_id_returns_unchanged() {
		$input  = array( 'notifications' => 'email_notifications', 'slack' => 'slack' );
		$result = $this->helper->checkview_disable_form_actions( $input, 1 );
		$this->assertSame( $input, $result );
	}

	public function test_disable_form_actions_strips_integrations_preserves_native_email() {
		$test_id = 'aaaaaaaa-bbbb-4ccc-9ddd-eeeeeeeeeeee';
		$_REQUEST['checkview_test_id']   = $test_id;
		$_REQUEST['checkview_test_type'] = 'form';
		update_option( 'disable_actions_' . $test_id, 'true', false );

		$input = array(
			'notifications'   => 'email_notifications',
			'slack'           => 'slack',
			'mailchimp_feeds' => 'mailchimp',
			'webhook'         => 'webhook',
		);

		$result = $this->helper->checkview_disable_form_actions( $input, 1 );
		$this->assertSame( array( 'notifications' => 'email_notifications' ), $result );

		delete_option( 'disable_actions_' . $test_id );
		unset( $_REQUEST['checkview_test_id'], $_REQUEST['checkview_test_type'] );
	}

	public function test_disable_form_actions_integration_only_returns_empty() {
		$test_id = 'aaaaaaaa-bbbb-4ccc-9ddd-eeeeeeeeeeee';
		$_REQUEST['checkview_test_id']   = $test_id;
		$_REQUEST['checkview_test_type'] = 'form';
		update_option( 'disable_actions_' . $test_id, 'true', false );

		$input  = array( 'slack' => 'slack', 'webhook' => 'webhook' );
		$result = $this->helper->checkview_disable_form_actions( $input, 1 );
		$this->assertSame( array(), $result );

		delete_option( 'disable_actions_' . $test_id );
		unset( $_REQUEST['checkview_test_id'], $_REQUEST['checkview_test_type'] );
	}

	public function test_disable_form_actions_empty_array_returns_empty() {
		$test_id = 'aaaaaaaa-bbbb-4ccc-9ddd-eeeeeeeeeeee';
		$_REQUEST['checkview_test_id']   = $test_id;
		$_REQUEST['checkview_test_type'] = 'form';
		update_option( 'disable_actions_' . $test_id, 'true', false );

		$result = $this->helper->checkview_disable_form_actions( array(), 1 );
		$this->assertSame( array(), $result );

		delete_option( 'disable_actions_' . $test_id );
		unset( $_REQUEST['checkview_test_id'], $_REQUEST['checkview_test_type'] );
	}

	public function test_disable_form_actions_null_input_returns_unchanged() {
		$test_id = 'aaaaaaaa-bbbb-4ccc-9ddd-eeeeeeeeeeee';
		$_REQUEST['checkview_test_id']   = $test_id;
		$_REQUEST['checkview_test_type'] = 'form';
		update_option( 'disable_actions_' . $test_id, 'true', false );

		$result = $this->helper->checkview_disable_form_actions( null, 1 );
		$this->assertNull( $result );

		delete_option( 'disable_actions_' . $test_id );
		unset( $_REQUEST['checkview_test_id'], $_REQUEST['checkview_test_type'] );
	}

	public function test_disable_form_actions_non_array_input_returns_unchanged() {
		$test_id = 'aaaaaaaa-bbbb-4ccc-9ddd-eeeeeeeeeeee';
		$_REQUEST['checkview_test_id']   = $test_id;
		$_REQUEST['checkview_test_type'] = 'form';
		update_option( 'disable_actions_' . $test_id, 'true', false );

		$result = $this->helper->checkview_disable_form_actions( 'unexpected_string', 1 );
		$this->assertSame( 'unexpected_string', $result );

		delete_option( 'disable_actions_' . $test_id );
		unset( $_REQUEST['checkview_test_id'], $_REQUEST['checkview_test_type'] );
	}
}
