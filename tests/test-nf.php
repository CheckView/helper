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
}
