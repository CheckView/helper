<?php

class Test_Checkview_Gforms_Helper extends WP_UnitTestCase {

	private $helper;

	public function setUp(): void {
		parent::setUp();
		if ( is_plugin_active( 'gravityforms/gravityforms.php' ) ) {
			require_once CHECKVIEW_INC_DIR . 'formhelpers/class-checkview-gforms-helper.php';
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
		$this->helper = new Checkview_Gforms_Helper();
	}

	public function test_construct() {
		$this->assertInstanceOf( 'Checkview_Gforms_Helper', $this->helper );
	}

	public function test_checkview_clone_entry() {
		$entry    = array(
			'id'      => 1,
			'form_id' => 1,
		);
		$form     = new stdClass();
		$form->id = 1;

		// Mock the get_checkview_test_id function to return a test ID
		$this->mock_get_checkview_test_id( 'test_id' );

		// Mock the checkview_clone_gf_entry method to return true
		$this->mock_checkview_clone_gf_entry( true );

		// Mock the GFAPI::delete_entry method to return true
		$this->mock_GFAPIDeleteEntry( true );
		$this->mock_complete_checkview_test();
		// Call the method under test
		// $this->helper->checkview_clone_entry( $entry, $form );

		// Assert that the entry was cloned and deleted
		$this->assertTrue( $this->checkview_clone_gf_entry_called );
		$this->assertTrue( $this->GFAPIDeleteEntry_called );

		// Assert that the test was completed and sessions were cleared
		$this->assertTrue( $this->complete_checkview_test_called );
	}

	private function mock_get_checkview_test_id( $return_value ) {
		$this->checkview_test_id = $return_value;
	}

	private function mock_checkview_clone_gf_entry( $return_value ) {
		$this->checkview_clone_gf_entry_called = true;
		return $return_value;
	}

	private function mock_GFAPIDeleteEntry( $return_value ) {
		$this->GFAPIDeleteEntry_called = true;
		return $return_value;
	}

	private function mock_complete_checkview_test() {
		$this->complete_checkview_test_called = true;
		return true;
	}

	public function test_checkview_inject_email() {
		$email = array( 'to' => 'original@example.com' );
		$this->assertEquals( array( 'to' => TEST_EMAIL ), $this->helper->checkview_inject_email( $email ) );
	}



	public function test_checkview_disable_zero_spam_addon() {
		$form_id      = rand( 1, 100 );
		$should_check = true;
		$form         = (object) array( 'id' => rand( 1, 100 ) );
		$entry        = array( 'id' => rand( 1, 100 ) );
		$this->assertFalse( $this->helper->checkview_disable_zero_spam_addon( $form_id, $should_check, $form, $entry ) );
	}

	public function test_checkview_disable_pdf_addon() {
		$settings  = array( 'notification' => 'original' );
		$form_id   = rand( 1, 100 );
		$settings1 = array(
			'notification'       => '',
			'conditional'        => 1,
			'enable_conditional' => 'Yes',
			'conditionalLogic'   => array(
				'actionType' => 'hide',
				'logicType'  => 'all',
				'rules'      => array(
					array(
						'fieldId'  => 1,
						'operator' => 'isnot',
						'value'    => esc_html__( 'Check Form Helper', 'checkview' ),
					),
				),
			),
		);
		$this->assertEquals(
			$settings1,
			$this->helper->checkview_disable_pdf_addon( $settings, $form_id )
		);
	}

	public function test_checkview_disable_addons_feed() {
		$feeds     = array( array( 'meta' => array( 'feed_condition_conditional_logic' => false ) ) );
		$entry     = array( 'id' => rand( 1, 100 ) );
		$form      = (object) array( 'id' => rand( 1, 100 ) );
		$settings1 = array(
			array(
				'meta' => array(
					'feed_condition_conditional_logic' => 1,
					'feed_condition_conditional_logic_object' => array(
						'conditionalLogic' => array(
							'actionType' => 'show',
							'logicType'  => 'all',
							'rules'      => array(
								array(
									'fieldId'  => 1,
									'operator' => 'is',
									'value'    => 'Check Form Helper',
								),
							),
						),
					),
				),
			),
		);
		
		$this->assertEquals(
			$settings1,
			$this->helper->checkview_disable_addons_feed( $feeds, $entry, $form )
		);
	}
}
