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
		$_REQUEST[CheckView::PARAM_TEST_TYPE] = 'form';
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
		unset( $_REQUEST['checkview_test_id'], $_REQUEST[CheckView::PARAM_TEST_TYPE] );
	}

	public function test_disable_form_actions_integration_only_returns_empty() {
		$test_id = 'aaaaaaaa-bbbb-4ccc-9ddd-eeeeeeeeeeee';
		$_REQUEST['checkview_test_id']   = $test_id;
		$_REQUEST[CheckView::PARAM_TEST_TYPE] = 'form';
		update_option( 'disable_actions_' . $test_id, 'true', false );

		$input  = array( 'slack' => 'slack', 'webhook' => 'webhook' );
		$result = $this->helper->checkview_disable_form_actions( $input, 1 );
		$this->assertSame( array(), $result );

		delete_option( 'disable_actions_' . $test_id );
		unset( $_REQUEST['checkview_test_id'], $_REQUEST[CheckView::PARAM_TEST_TYPE] );
	}

	public function test_disable_form_actions_empty_array_returns_empty() {
		$test_id = 'aaaaaaaa-bbbb-4ccc-9ddd-eeeeeeeeeeee';
		$_REQUEST['checkview_test_id']   = $test_id;
		$_REQUEST[CheckView::PARAM_TEST_TYPE] = 'form';
		update_option( 'disable_actions_' . $test_id, 'true', false );

		$result = $this->helper->checkview_disable_form_actions( array(), 1 );
		$this->assertSame( array(), $result );

		delete_option( 'disable_actions_' . $test_id );
		unset( $_REQUEST['checkview_test_id'], $_REQUEST[CheckView::PARAM_TEST_TYPE] );
	}

	public function test_disable_form_actions_null_input_returns_unchanged() {
		$test_id = 'aaaaaaaa-bbbb-4ccc-9ddd-eeeeeeeeeeee';
		$_REQUEST['checkview_test_id']   = $test_id;
		$_REQUEST[CheckView::PARAM_TEST_TYPE] = 'form';
		update_option( 'disable_actions_' . $test_id, 'true', false );

		$result = $this->helper->checkview_disable_form_actions( null, 1 );
		$this->assertNull( $result );

		delete_option( 'disable_actions_' . $test_id );
		unset( $_REQUEST['checkview_test_id'], $_REQUEST[CheckView::PARAM_TEST_TYPE] );
	}

	public function test_disable_form_actions_non_array_input_returns_unchanged() {
		$test_id = 'aaaaaaaa-bbbb-4ccc-9ddd-eeeeeeeeeeee';
		$_REQUEST['checkview_test_id']   = $test_id;
		$_REQUEST[CheckView::PARAM_TEST_TYPE] = 'form';
		update_option( 'disable_actions_' . $test_id, 'true', false );

		$result = $this->helper->checkview_disable_form_actions( 'unexpected_string', 1 );
		$this->assertSame( 'unexpected_string', $result );

		delete_option( 'disable_actions_' . $test_id );
		unset( $_REQUEST['checkview_test_id'], $_REQUEST[CheckView::PARAM_TEST_TYPE] );
	}

	public function tearDown(): void {
		global $wpdb;
		// Clean up any scheduled deferred-delete events from individual tests.
		wp_clear_scheduled_hook( 'checkview_ff_deferred_entry_delete' );
		// Clean up FF rows our tests may have inserted (form_ids 7, 8, 9).
		// `wpFluent` may not be available in CI; fall back to direct $wpdb when it is missing.
		if ( function_exists( 'wpFluent' ) ) {
			wpFluent()->table( 'fluentform_submissions' )->whereIn( 'form_id', array( 7, 8, 9 ) )->delete();
			wpFluent()->table( 'fluentform_entry_details' )->whereIn( 'form_id', array( 7, 8, 9 ) )->delete();
		}
		parent::tearDown();
	}

	/**
	 * Asserts that checkview_clone_fluentform_entry schedules the deferred-delete
	 * cron event with the entry id + form id as args, using a delay >= 15 minutes.
	 *
	 * Skipped when the legacy-mode escape-hatch constant has been defined (e.g.
	 * after tests/test-ff-legacy-mode.php runs in the same process), since the
	 * schedule path is intentionally bypassed in that mode.
	 */
	public function test_clone_entry_schedules_deferred_delete() {
		if ( ! function_exists( 'wpFluent' ) ) {
			$this->markTestSkipped( 'wpFluent (Fluent Forms) not available in test environment.' );
		}
		if ( ! checkview_ff_should_defer_delete() ) {
			$this->markTestSkipped( 'Defer disabled via CHECKVIEW_FF_DEFER_ENTRY_DELETE — schedule path not exercised.' );
		}

		$entry_id = 4242;
		$form_id  = 7;
		$form     = new stdClass();
		$form->id = $form_id;

		$_REQUEST['checkview_test_id'] = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';
		$this->helper->checkview_clone_fluentform_entry( $entry_id, array(), $form );
		unset( $_REQUEST['checkview_test_id'] );

		$scheduled = wp_next_scheduled( 'checkview_ff_deferred_entry_delete', array( $entry_id, $form_id ) );
		$this->assertNotFalse( $scheduled, 'Deferred-delete event should be scheduled.' );
		$this->assertGreaterThanOrEqual( time() + ( 15 * MINUTE_IN_SECONDS ) - 60, $scheduled, 'Schedule should be at least ~15 min in the future.' );
	}

	/**
	 * Asserts that checkview_clone_fluentform_entry does NOT delete the FF
	 * submission row synchronously when the defer fix is active. The row should
	 * still exist after clone returns; the deferred cron is responsible for
	 * deletion ~15 min later.
	 *
	 * Skipped when the legacy-mode escape-hatch constant has been defined, since
	 * the synchronous-delete path is the expected behavior in that mode.
	 */
	public function test_clone_entry_does_not_delete_synchronously() {
		if ( ! function_exists( 'wpFluent' ) ) {
			$this->markTestSkipped( 'wpFluent (Fluent Forms) not available in test environment.' );
		}
		if ( ! checkview_ff_should_defer_delete() ) {
			$this->markTestSkipped( 'Defer disabled via CHECKVIEW_FF_DEFER_ENTRY_DELETE — synchronous delete is expected.' );
		}

		$form_id  = 8;
		$entry_id = wpFluent()->table( 'fluentform_submissions' )->insertGetId(
			array(
				'form_id'       => $form_id,
				'serial_number' => 1,
				'response'      => '{}',
				'status'        => 'unread',
				'source_url'    => 'https://example.test/',
				'user_id'       => 0,
				'browser'       => 'phpunit',
				'ip'            => '127.0.0.1',
				'created_at'    => current_time( 'mysql' ),
				'updated_at'    => current_time( 'mysql' ),
			)
		);
		if ( ! $entry_id ) {
			$this->markTestSkipped( 'Could not insert test FF submission.' );
		}

		$form     = new stdClass();
		$form->id = $form_id;

		$_REQUEST['checkview_test_id'] = 'dddddddd-dddd-4ddd-8ddd-dddddddddddd';
		$this->helper->checkview_clone_fluentform_entry( $entry_id, array(), $form );
		unset( $_REQUEST['checkview_test_id'] );

		$row = wpFluent()->table( 'fluentform_submissions' )
			->where( 'id', $entry_id )
			->first();
		$this->assertNotNull( $row, 'FF submission row should still exist after clone (deletion deferred).' );

		// Cleanup.
		wpFluent()->table( 'fluentform_submissions' )->where( 'id', $entry_id )->delete();
	}

	/**
	 * Asserts that the deferred handler actually deletes the FF submission +
	 * entry_details rows when run.
	 */
	public function test_deferred_handler_deletes_entry() {
		if ( ! function_exists( 'wpFluent' ) ) {
			$this->markTestSkipped( 'wpFluent (Fluent Forms) not available in test environment.' );
		}

		$form_id  = 9;
		$entry_id = wpFluent()->table( 'fluentform_submissions' )->insertGetId(
			array(
				'form_id'       => $form_id,
				'serial_number' => 1,
				'response'      => '{}',
				'status'        => 'unread',
				'source_url'    => 'https://example.test/',
				'user_id'       => 0,
				'browser'       => 'phpunit',
				'ip'            => '127.0.0.1',
				'created_at'    => current_time( 'mysql' ),
				'updated_at'    => current_time( 'mysql' ),
			)
		);
		if ( ! $entry_id ) {
			$this->markTestSkipped( 'Could not insert test FF submission.' );
		}
		wpFluent()->table( 'fluentform_entry_details' )->insert(
			array(
				'form_id'       => $form_id,
				'submission_id' => $entry_id,
				'field_name'    => 'email',
				'sub_field_name' => '',
				'field_value'   => 'phpunit@example.test',
			)
		);

		// Sanity: rows exist before handler runs.
		$this->assertNotNull(
			wpFluent()->table( 'fluentform_submissions' )->where( 'id', $entry_id )->first()
		);

		checkview_ff_run_deferred_entry_delete( $entry_id, $form_id );

		$this->assertNull(
			wpFluent()->table( 'fluentform_submissions' )->where( 'id', $entry_id )->first(),
			'Handler should have deleted the FF submission row.'
		);
		$this->assertNull(
			wpFluent()->table( 'fluentform_entry_details' )->where( 'submission_id', $entry_id )->first(),
			'Handler should have deleted the FF entry_details rows.'
		);
	}
}
