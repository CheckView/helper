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

	public function tearDown(): void {
		// Clean up any scheduled deferred-delete events from individual tests.
		wp_clear_scheduled_hook( 'checkview_gf_deferred_entry_delete' );
		parent::tearDown();
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

	/**
	 * Asserts that checkview_clone_entry schedules the deferred-delete cron
	 * event with the entry id as args, using a delay >= 15 minutes.
	 *
	 * Requires a real GF form + entry to exist; skipped if GFAPI is unavailable
	 * (file-level gate) or if entry creation fails in the test fixture.
	 */
	public function test_clone_entry_schedules_deferred_delete() {
		if ( ! class_exists( 'GFAPI' ) ) {
			$this->markTestSkipped( 'GFAPI not available in test environment.' );
		}

		$form_id = GFAPI::add_form(
			array(
				'title'  => 'CV Test Form',
				'fields' => array(),
			)
		);
		if ( is_wp_error( $form_id ) ) {
			$this->markTestSkipped( 'Could not add test form.' );
		}

		$entry_id = GFAPI::add_entry(
			array(
				'form_id' => $form_id,
			)
		);
		if ( is_wp_error( $entry_id ) ) {
			$this->markTestSkipped( 'Could not add test entry.' );
		}

		$entry = array( 'id' => (int) $entry_id, 'form_id' => $form_id );
		$form  = array( 'id' => $form_id );

		$_REQUEST['checkview_test_id'] = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
		$this->helper->checkview_clone_entry( $entry, $form );

		$scheduled = wp_next_scheduled( 'checkview_gf_deferred_entry_delete', array( (int) $entry_id ) );
		$this->assertNotFalse( $scheduled, 'Deferred-delete event should be scheduled.' );
		$this->assertGreaterThanOrEqual( time() + ( 15 * MINUTE_IN_SECONDS ) - 60, $scheduled, 'Schedule should be at least ~15 min in the future.' );

		// Cleanup.
		unset( $_REQUEST['checkview_test_id'] );
		GFAPI::delete_entry( $entry_id );
		GFAPI::delete_form( $form_id );
	}

	/**
	 * Asserts that checkview_clone_entry does NOT delete the entry synchronously.
	 * Entry should still exist after clone_entry returns.
	 */
	public function test_clone_entry_does_not_delete_synchronously() {
		if ( ! class_exists( 'GFAPI' ) ) {
			$this->markTestSkipped( 'GFAPI not available in test environment.' );
		}

		$form_id = GFAPI::add_form(
			array(
				'title'  => 'CV Test Form 2',
				'fields' => array(),
			)
		);
		if ( is_wp_error( $form_id ) ) {
			$this->markTestSkipped( 'Could not add test form.' );
		}
		$entry_id = GFAPI::add_entry( array( 'form_id' => $form_id ) );
		if ( is_wp_error( $entry_id ) ) {
			$this->markTestSkipped( 'Could not add test entry.' );
		}

		$entry = array( 'id' => (int) $entry_id, 'form_id' => $form_id );
		$form  = array( 'id' => $form_id );

		$_REQUEST['checkview_test_id'] = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
		$this->helper->checkview_clone_entry( $entry, $form );

		$fetched = GFAPI::get_entry( $entry_id );
		$this->assertFalse( is_wp_error( $fetched ), 'Entry should still exist after clone_entry (deletion deferred).' );

		// Cleanup.
		unset( $_REQUEST['checkview_test_id'] );
		GFAPI::delete_entry( $entry_id );
		GFAPI::delete_form( $form_id );
	}

	/**
	 * Asserts that the deferred handler actually deletes the entry when run.
	 */
	public function test_deferred_handler_deletes_entry() {
		if ( ! class_exists( 'GFAPI' ) ) {
			$this->markTestSkipped( 'GFAPI not available in test environment.' );
		}

		$form_id = GFAPI::add_form(
			array(
				'title'  => 'CV Test Form 3',
				'fields' => array(),
			)
		);
		if ( is_wp_error( $form_id ) ) {
			$this->markTestSkipped( 'Could not add test form.' );
		}
		$entry_id = GFAPI::add_entry( array( 'form_id' => $form_id ) );
		if ( is_wp_error( $entry_id ) ) {
			$this->markTestSkipped( 'Could not add test entry.' );
		}

		// Sanity: entry exists before handler runs.
		$this->assertFalse( is_wp_error( GFAPI::get_entry( $entry_id ) ) );

		// Invoke the handler directly.
		checkview_gf_run_deferred_entry_delete( $entry_id );

		// Entry should be gone.
		$this->assertTrue( is_wp_error( GFAPI::get_entry( $entry_id ) ), 'Handler should have deleted the entry.' );

		GFAPI::delete_form( $form_id );
	}
}
