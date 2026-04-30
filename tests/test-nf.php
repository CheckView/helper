<?php

class Checkview_Ninja_Forms_Helper_Test extends WP_UnitTestCase {

	private $helper;

	public function setUp(): void {
		parent::setUp();
		if ( is_plugin_active( 'ninja-forms/ninja-forms.php' ) ) {
			require_once CHECKVIEW_INC_DIR . 'formhelpers/class-checkview-ninja-forms-helper.php';
		}
		if ( ! defined( 'CHECKVIEW_EMAIL' ) ) {
			define( 'CHECKVIEW_EMAIL', 'verify@test-mail.checkview.io' );
		}
		$send_to = CHECKVIEW_EMAIL;
		if ( isset( $test_form['send_to'] ) && '' !== $test_form['send_to'] ) {
			$send_to = $test_form['send_to'];
		}

		if ( ! defined( 'TEST_EMAIL' ) ) {
			define( 'TEST_EMAIL', $send_to );
		}
		$this->helper = new Checkview_Ninja_Forms_Helper();
	}

	public function test_constructor() {
		$this->assertInstanceOf( 'Checkview_Loader', $this->helper->loader );
		$this->assertEquals( 99, has_action( 'ninja_forms_after_submission', array( $this->helper, 'checkview_clone_entry' ) ) );
		$this->assertEquals( 20, has_filter( 'ninja_forms_display_fields', array( $this->helper, 'checkview_maybe_remove_v2_field' ) ) );
	}

	public function test_checkview_clone_entry() {
		global $wpdb;
		$form_data = array(
			'form_id' => 1,
			'actions' => array(
				'ave' => array(
					'ub_id' => 1,
				),
			),
		);
		$this->helper->checkview_clone_entry( $form_data );
		$entry_table = $wpdb->prefix . 'cv_entry';
		$this->assertNotEmpty( $wpdb->get_results( "SELECT * FROM $entry_table WHERE form_id = 1" ) );
	}

	public function test_checkview_maybe_remove_v2_field() {
		$fields = array(
			array(
				'type' => 'ecaptcha',
			),
			array(
				'type' => 'text',
			),
		);
		$result = $this->helper->checkview_maybe_remove_v2_field( $fields );
		$this->assertCount( 2, $result );
		$this->assertEquals( 'ecaptcha', $result[0]['type'] );
	}

	public function test_checkview_inject_email() {
		$sent            = true;
		$action_settings = array(
			'email_subject' => 'Test Subject',
		);
		$message         = 'Test Message';
		$headers         = array();
		$attachments     = array();
		$result          = $this->helper->checkview_inject_email( $sent, $action_settings, $message, $headers, $attachments );
		$this->assertTrue( $result );
	}

	/**
	 * Without disable_actions option set, the gate must return form_cache_actions
	 * unchanged (allowing all actions including third-party integrations to fire).
	 */
	public function test_disable_form_actions_passes_through_when_option_unset() {
		$test_id = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';
		$_REQUEST['checkview_test_id'] = $test_id;
		delete_option( 'disable_actions_' . $test_id );

		$actions = array(
			array( 'settings' => array( 'type' => 'mailchimp', 'active' => 1 ) ),
			array( 'settings' => array( 'type' => 'webhooks', 'active' => 1 ) ),
			array( 'settings' => array( 'type' => 'email', 'active' => 1 ) ),
		);

		$result = $this->helper->checkview_disable_form_actions( $actions, array(), array() );

		// Active flag must remain 1 for all (gate passed through).
		$this->assertEquals( 1, $result[0]['settings']['active'], 'Mailchimp action should pass through when option unset.' );
		$this->assertEquals( 1, $result[1]['settings']['active'], 'Webhooks action should pass through when option unset.' );
		$this->assertEquals( 1, $result[2]['settings']['active'], 'Email action should always be active.' );

		unset( $_REQUEST['checkview_test_id'] );
	}

	/**
	 * When disable_actions option is set to 'true', non-essential actions
	 * (mailchimp, webhooks, etc.) must be deactivated; allowlist actions
	 * (email, successmessage, save) must remain active.
	 */
	public function test_disable_form_actions_suppresses_when_option_set() {
		$test_id = 'dddddddd-dddd-4ddd-8ddd-dddddddddddd';
		$_REQUEST['checkview_test_id'] = $test_id;
		update_option( 'disable_actions_' . $test_id, 'true' );

		$actions = array(
			array( 'settings' => array( 'type' => 'mailchimp', 'active' => 1 ) ),
			array( 'settings' => array( 'type' => 'webhooks', 'active' => 1 ) ),
			array( 'settings' => array( 'type' => 'email', 'active' => 1 ) ),
			array( 'settings' => array( 'type' => 'successmessage', 'active' => 1 ) ),
			array( 'settings' => array( 'type' => 'save', 'active' => 1 ) ),
		);

		$result = $this->helper->checkview_disable_form_actions( $actions, array(), array() );

		$this->assertEquals( 0, $result[0]['settings']['active'], 'Mailchimp must be suppressed.' );
		$this->assertEquals( 0, $result[1]['settings']['active'], 'Webhooks must be suppressed.' );
		$this->assertEquals( 1, $result[2]['settings']['active'], 'Email must remain active (allowlist).' );
		$this->assertEquals( 1, $result[3]['settings']['active'], 'Successmessage must remain active (allowlist).' );
		$this->assertEquals( 1, $result[4]['settings']['active'], 'Save must remain active (allowlist).' );

		// Cleanup.
		delete_option( 'disable_actions_' . $test_id );
		unset( $_REQUEST['checkview_test_id'] );
	}
}
