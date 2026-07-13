<?php
class TestCheckviewWpformsHelper extends WP_UnitTestCase {

	protected $helper;

	public function setUp(): void {
		parent::setUp();
		if ( is_plugin_active( 'wpforms/wpforms.php' ) || is_plugin_active( 'wpforms-lite/wpforms.php' ) ) {
			require_once CHECKVIEW_INC_DIR . 'formhelpers/class-checkview-wpforms-helper.php';
		}
		$this->helper = new Checkview_Wpforms_Helper();
	}

	public function testCheckviewInjectEmail() {
		$email  = array(
			'address' => array( 'old@example.com' ),
		);
		if ( ! defined( 'CHECKVIEW_EMAIL' ) ) {
			define( 'CHECKVIEW_EMAIL', 'verify@test-mail.checkview.io' );
		}
		$result = $this->helper->checkview_inject_email( $email );
		$this->assertEquals( CHECKVIEW_EMAIL, $result['address'][0] );
	}

	public function testCheckviewLogWpformTestEntry() {
		global $wpdb;

		// Set up the necessary data for the test
		$form_fields = array(
			array(
				'id'    => rand( 2, 100 ),
				'value' => 'Test Value',
			),
		);
		$entry       = array(
			'id' => rand( 2, 100 ),
		);
		$form_data   = array(
			'id' => rand( 2, 100 ),
		);
		$entry_id    = rand( 2, 100 );

		// Call the method to test
		$this->helper->checkview_log_wpform_test_entry( $form_fields, $entry, $form_data, $entry_id );

		// Check if the entry was inserted into the database
		$results = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}cv_entry WHERE form_id = {$form_data['id']}" );
		$this->assertNotEmpty( $results );
	}

	public function test_disable_addons_action_registered() {
		$this->assertEquals(
			1,
			has_action(
				'wpforms_process',
				array( $this->helper, 'checkview_wpforms_disable_addons' )
			)
		);
	}

	public function test_disable_addons_no_test_id_leaves_listeners_intact() {
		$dummy = new stdClass();
		add_action( 'wpforms_process_complete', array( $dummy, 'fake_listener' ), 5, 4 );

		$this->helper->checkview_wpforms_disable_addons( array(), array(), array( 'id' => 7 ) );

		$this->assertNotFalse(
			has_action( 'wpforms_process_complete', array( $dummy, 'fake_listener' ) ),
			'Third-party listener must remain when no test ID is set.'
		);

		remove_action( 'wpforms_process_complete', array( $dummy, 'fake_listener' ), 5 );
	}

	public function test_disable_addons_option_unset_leaves_listeners_intact() {
		$test_id                         = 'aaaaaaaa-bbbb-4ccc-9ddd-eeeeeeeeeeee';
		$_REQUEST['checkview_test_id']   = $test_id;
		$_REQUEST[CheckView::PARAM_TEST_TYPE] = 'form';
		delete_option( 'disable_actions_' . $test_id );

		$dummy = new stdClass();
		add_action( 'wpforms_process_complete', array( $dummy, 'fake_listener' ), 5, 4 );

		$this->helper->checkview_wpforms_disable_addons( array(), array(), array( 'id' => 7 ) );

		$this->assertNotFalse(
			has_action( 'wpforms_process_complete', array( $dummy, 'fake_listener' ) ),
			'Third-party listener must remain when disable_actions option is unset.'
		);

		remove_action( 'wpforms_process_complete', array( $dummy, 'fake_listener' ), 5 );
		unset( $_REQUEST['checkview_test_id'], $_REQUEST[CheckView::PARAM_TEST_TYPE] );
	}

	public function test_disable_addons_removes_third_party_listeners_when_flag_set() {
		$test_id                         = 'aaaaaaaa-bbbb-4ccc-9ddd-eeeeeeeeeeee';
		$_REQUEST['checkview_test_id']   = $test_id;
		$_REQUEST[CheckView::PARAM_TEST_TYPE] = 'form';
		update_option( 'disable_actions_' . $test_id, 'true', false );

		$mailchimp = new stdClass();
		$webhook   = new stdClass();
		$registration = new stdClass();
		add_action( 'wpforms_process_complete', array( $mailchimp, 'process_entry' ), 5, 4 );
		add_action( 'wpforms_process_complete', array( $webhook, 'send_payload' ), 10, 4 );
		add_action( 'wpforms_process_complete', array( $registration, 'create_user' ), 15, 4 );

		$this->helper->checkview_wpforms_disable_addons( array(), array(), array( 'id' => 7 ) );

		$this->assertFalse(
			has_action( 'wpforms_process_complete', array( $mailchimp, 'process_entry' ) ),
			'Mailchimp-style provider listener must be removed.'
		);
		$this->assertFalse(
			has_action( 'wpforms_process_complete', array( $webhook, 'send_payload' ) ),
			'Webhook listener must be removed.'
		);
		$this->assertFalse(
			has_action( 'wpforms_process_complete', array( $registration, 'create_user' ) ),
			'User Registration listener must be removed.'
		);

		delete_option( 'disable_actions_' . $test_id );
		unset( $_REQUEST['checkview_test_id'], $_REQUEST[CheckView::PARAM_TEST_TYPE] );
	}

	public function test_disable_addons_whitelists_by_class_instance_not_priority() {
		$test_id                         = 'aaaaaaaa-bbbb-4ccc-9ddd-eeeeeeeeeeee';
		$_REQUEST['checkview_test_id']   = $test_id;
		$_REQUEST[CheckView::PARAM_TEST_TYPE] = 'form';
		update_option( 'disable_actions_' . $test_id, 'true', false );

		// Sanity: the constructor registers our cloning callback at priority 99.
		$this->assertEquals(
			99,
			has_action(
				'wpforms_process_complete',
				array( $this->helper, 'checkview_log_wpform_test_entry' )
			)
		);

		// Decoupling test: a non-helper callback AT the same priority 99 must
		// still be removed, AND a helper callback at a non-99 priority must
		// survive — proving the whitelist gates on instance, not priority.
		$third_party_at_99 = new stdClass();
		add_action( 'wpforms_process_complete', array( $third_party_at_99, 'fire' ), 99, 4 );

		// Register the helper's own method at a different priority too.
		add_action( 'wpforms_process_complete', array( $this->helper, 'checkview_log_wpform_test_entry' ), 50, 4 );

		// And a low-priority third-party for completeness.
		$third_party_at_5 = new stdClass();
		add_action( 'wpforms_process_complete', array( $third_party_at_5, 'fire' ), 5, 4 );

		$this->helper->checkview_wpforms_disable_addons( array(), array(), array( 'id' => 7 ) );

		$this->assertEquals(
			99,
			has_action(
				'wpforms_process_complete',
				array( $this->helper, 'checkview_log_wpform_test_entry' )
			),
			'Own cloning callback at priority 99 must survive the purge.'
		);
		$this->assertNotFalse(
			has_action( 'wpforms_process_complete', array( $this->helper, 'checkview_log_wpform_test_entry' ) ),
			'Helper callback at priority 50 must also survive (whitelist by instance, not priority).'
		);
		$this->assertFalse(
			has_action( 'wpforms_process_complete', array( $third_party_at_99, 'fire' ) ),
			'Third-party listener at priority 99 must be removed despite sharing priority with helper.'
		);
		$this->assertFalse(
			has_action( 'wpforms_process_complete', array( $third_party_at_5, 'fire' ) ),
			'Third-party listener at priority 5 must be removed.'
		);

		// Clean up the manually-added priority-50 helper registration.
		remove_action( 'wpforms_process_complete', array( $this->helper, 'checkview_log_wpform_test_entry' ), 50 );

		delete_option( 'disable_actions_' . $test_id );
		unset( $_REQUEST['checkview_test_id'], $_REQUEST[CheckView::PARAM_TEST_TYPE] );
	}

	public function test_disable_addons_removes_string_function_callback() {
		$test_id                         = 'aaaaaaaa-bbbb-4ccc-9ddd-eeeeeeeeeeee';
		$_REQUEST['checkview_test_id']   = $test_id;
		$_REQUEST[CheckView::PARAM_TEST_TYPE] = 'form';
		update_option( 'disable_actions_' . $test_id, 'true', false );

		// Plain string function name — `is_array($cb['function'])` is false,
		// so the whitelist guard short-circuits to unset.
		add_action( 'wpforms_process_complete', '__return_true', 10, 4 );

		$this->helper->checkview_wpforms_disable_addons( array(), array(), array( 'id' => 7 ) );

		$this->assertFalse(
			has_action( 'wpforms_process_complete', '__return_true' ),
			'Plain function-name callbacks must be removed.'
		);

		delete_option( 'disable_actions_' . $test_id );
		unset( $_REQUEST['checkview_test_id'], $_REQUEST[CheckView::PARAM_TEST_TYPE] );
	}

	public function test_disable_addons_removes_closure_callback() {
		$test_id                         = 'aaaaaaaa-bbbb-4ccc-9ddd-eeeeeeeeeeee';
		$_REQUEST['checkview_test_id']   = $test_id;
		$_REQUEST[CheckView::PARAM_TEST_TYPE] = 'form';
		update_option( 'disable_actions_' . $test_id, 'true', false );

		$closure = function () {};
		add_action( 'wpforms_process_complete', $closure, 10, 4 );

		$this->helper->checkview_wpforms_disable_addons( array(), array(), array( 'id' => 7 ) );

		$this->assertFalse(
			has_action( 'wpforms_process_complete', $closure ),
			'Closure callbacks must be removed.'
		);

		delete_option( 'disable_actions_' . $test_id );
		unset( $_REQUEST['checkview_test_id'], $_REQUEST[CheckView::PARAM_TEST_TYPE] );
	}

	public function test_disable_addons_removes_static_method_callback() {
		$test_id                         = 'aaaaaaaa-bbbb-4ccc-9ddd-eeeeeeeeeeee';
		$_REQUEST['checkview_test_id']   = $test_id;
		$_REQUEST[CheckView::PARAM_TEST_TYPE] = 'form';
		update_option( 'disable_actions_' . $test_id, 'true', false );

		// Static method form: ['ClassName', 'method'] — the [0] slot is a string,
		// not an object, so `is_object` guard short-circuits to unset.
		add_action( 'wpforms_process_complete', array( 'WP_Error', 'get_error_codes' ), 10, 4 );

		$this->helper->checkview_wpforms_disable_addons( array(), array(), array( 'id' => 7 ) );

		$this->assertFalse(
			has_action( 'wpforms_process_complete', array( 'WP_Error', 'get_error_codes' ) ),
			'Static-method-array callbacks must be removed.'
		);

		delete_option( 'disable_actions_' . $test_id );
		unset( $_REQUEST['checkview_test_id'], $_REQUEST[CheckView::PARAM_TEST_TYPE] );
	}

	public function test_disable_addons_clears_form_id_scoped_hook() {
		$test_id                         = 'aaaaaaaa-bbbb-4ccc-9ddd-eeeeeeeeeeee';
		$_REQUEST['checkview_test_id']   = $test_id;
		$_REQUEST[CheckView::PARAM_TEST_TYPE] = 'form';
		update_option( 'disable_actions_' . $test_id, 'true', false );

		$dummy = new stdClass();
		add_action( 'wpforms_process_complete_42', array( $dummy, 'fire' ), 10, 4 );

		$this->helper->checkview_wpforms_disable_addons( array(), array(), array( 'id' => 42 ) );

		$this->assertFalse(
			has_action( 'wpforms_process_complete_42', array( $dummy, 'fire' ) ),
			'Form-id-scoped variant must also be cleared.'
		);

		delete_option( 'disable_actions_' . $test_id );
		unset( $_REQUEST['checkview_test_id'], $_REQUEST[CheckView::PARAM_TEST_TYPE] );
	}
}
