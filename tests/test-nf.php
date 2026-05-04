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

	public function tearDown(): void {
		// Clean up any scheduled deferred-delete events from individual tests.
		wp_clear_scheduled_hook( 'checkview_nf_deferred_entry_delete' );
		parent::tearDown();
	}

	/**
	 * Asserts that checkview_clone_entry schedules the deferred-delete cron
	 * event with the entry id as args, using a delay >= 15 minutes.
	 *
	 * Skipped when the legacy-mode escape-hatch constant has been defined
	 * (e.g. after tests/test-nf-legacy-mode.php runs in the same process),
	 * since the schedule path is intentionally bypassed in that mode.
	 */
	public function test_clone_entry_schedules_deferred_delete() {
		if ( ! checkview_nf_should_defer_delete() ) {
			$this->markTestSkipped( 'Defer disabled via CHECKVIEW_NF_DEFER_ENTRY_DELETE — schedule path not exercised.' );
		}

		// Create a real `nf_sub` post so the deletion target is valid; clone
		// also reads $_POST['formData'], which we can leave empty for this test.
		$entry_id = wp_insert_post(
			array(
				'post_type'   => 'nf_sub',
				'post_status' => 'publish',
				'post_title'  => 'CV NF test sub',
			)
		);
		$this->assertNotInstanceOf( 'WP_Error', $entry_id );
		$this->assertGreaterThan( 0, $entry_id );

		$form_data = array(
			'form_id' => 5150,
			'actions' => array(
				'save' => array(
					'sub_id' => (int) $entry_id,
				),
			),
			'fields'  => array(),
		);

		$_REQUEST['checkview_test_id'] = 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee';
		$this->helper->checkview_clone_entry( $form_data );
		unset( $_REQUEST['checkview_test_id'] );

		$scheduled = wp_next_scheduled( 'checkview_nf_deferred_entry_delete', array( (int) $entry_id ) );
		$this->assertNotFalse( $scheduled, 'Deferred-delete event should be scheduled.' );
		$this->assertGreaterThanOrEqual( time() + ( 15 * MINUTE_IN_SECONDS ) - 60, $scheduled, 'Schedule should be at least ~15 min in the future.' );

		// The post should still exist (deletion is deferred, not synchronous).
		$this->assertEquals( 'nf_sub', get_post_type( $entry_id ), 'Source post should still exist after clone (deletion deferred).' );

		// Cleanup.
		wp_delete_post( $entry_id, true );
	}

	/**
	 * Asserts that the deferred handler actually deletes the `nf_sub` post
	 * when run.
	 */
	public function test_deferred_handler_deletes_entry() {
		$entry_id = wp_insert_post(
			array(
				'post_type'   => 'nf_sub',
				'post_status' => 'publish',
				'post_title'  => 'CV NF deferred handler test',
			)
		);
		$this->assertNotInstanceOf( 'WP_Error', $entry_id );
		$this->assertGreaterThan( 0, $entry_id );

		// Sanity: post exists before handler runs.
		$this->assertEquals( 'nf_sub', get_post_type( $entry_id ) );

		checkview_nf_run_deferred_entry_delete( $entry_id );

		$this->assertNull( get_post( $entry_id ), 'Handler should have deleted the nf_sub post.' );
	}

	/**
	 * Asserts that the deferred handler refuses to delete posts that are
	 * not of post type `nf_sub`. Defends against a poisoned/typo'd cron
	 * event force-deleting unrelated content (pages, products, attachments).
	 */
	public function test_deferred_handler_refuses_foreign_post_type() {
		$post_id = wp_insert_post(
			array(
				'post_type'   => 'post',
				'post_status' => 'publish',
				'post_title'  => 'CV NF foreign post-type guard test',
			)
		);
		$this->assertNotInstanceOf( 'WP_Error', $post_id );
		$this->assertGreaterThan( 0, $post_id );
		$this->assertEquals( 'post', get_post_type( $post_id ) );

		checkview_nf_run_deferred_entry_delete( $post_id );

		$this->assertNotNull( get_post( $post_id ), 'Handler must refuse to delete non-nf_sub posts.' );

		// Cleanup.
		wp_delete_post( $post_id, true );
	}
}
