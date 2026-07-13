<?php
if ( is_plugin_active( 'formidable/formidable.php' ) ) {
	require_once CHECKVIEW_INC_DIR . 'formhelpers/class-checkview-formidable-helper.php';
}
class Test_Checkview_Formidable_Helper extends WP_UnitTestCase {

	public function test_checkview_inject_email() {
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
		$checkview_formidable_helper = new Checkview_Formidable_Helper();
		$email                       = 'old@email.com';
		$result                      = $checkview_formidable_helper->checkview_inject_email( $email );
		$this->assertEquals( TEST_EMAIL, $result );
	}

	public function test_get_form_fields() {
		$checkview_formidable_helper = new Checkview_Formidable_Helper();
		$form_id                     = rand( 1, 100 );

		// Initialize a variable to keep track of the next available ID
		$next_id = 1;

		// Test data.
		$test_data = array(
			array(
				'id'        => rand( 1, 100 ),
				'form_id'   => $form_id,
				'name'      => 'Test Field',
				'type'      => 'text',
				'field_key' => 'test_field',
			),
			array(
				'id'      => rand( 1, 100 ),
				'form_id' => $form_id,
				'name'    => 'Test Field 2',
				'type'    => 'recaptcha',
			),
		);

		// Insert test data into the database.
		global $wpdb;
		foreach ( $test_data as $data ) {
			$wpdb->insert( $wpdb->prefix . 'frm_fields', $data );
		}

		// Test the function.
		$fields = $checkview_formidable_helper->get_form_fields( $form_id );

		// Check if the data was returned correctly.
		$this->assertNotEmpty( $fields );
		// $this->assertCount( 2, $fields );

		// Clean up.
		foreach ( $test_data as $data ) {
			$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . $wpdb->prefix . 'frm_fields WHERE id=%d', $data['id'] ) );
		}
	}

	public function test_disable_form_actions_keeps_email_action_when_flag_set() {
		$test_id                         = 'aaaaaaaa-bbbb-4ccc-9ddd-eeeeeeeeeeee';
		$_REQUEST['checkview_test_id']   = $test_id;
		$_REQUEST[CheckView::PARAM_TEST_TYPE] = 'form';
		update_option( 'disable_actions_' . $test_id, 'true', false );

		$helper = new Checkview_Formidable_Helper();
		$action = (object) array( 'post_excerpt' => 'email' );
		$result = $helper->checkview_disable_form_actions( true, $action, null, null, 'create' );
		$this->assertFalse( $result, 'Email action must run (false = do not skip) even when disable_actions is set.' );

		delete_option( 'disable_actions_' . $test_id );
		unset( $_REQUEST['checkview_test_id'], $_REQUEST[CheckView::PARAM_TEST_TYPE] );
	}

	public function test_disable_form_actions_keeps_register_action_when_flag_set() {
		$test_id                         = 'aaaaaaaa-bbbb-4ccc-9ddd-eeeeeeeeeeee';
		$_REQUEST['checkview_test_id']   = $test_id;
		$_REQUEST[CheckView::PARAM_TEST_TYPE] = 'form';
		update_option( 'disable_actions_' . $test_id, 'true', false );

		$helper = new Checkview_Formidable_Helper();
		$action = (object) array( 'post_excerpt' => 'register' );
		$result = $helper->checkview_disable_form_actions( true, $action, null, null, 'create' );
		$this->assertFalse( $result );

		delete_option( 'disable_actions_' . $test_id );
		unset( $_REQUEST['checkview_test_id'], $_REQUEST[CheckView::PARAM_TEST_TYPE] );
	}

	public function test_disable_form_actions_keeps_on_submit_action_when_flag_set() {
		$test_id                         = 'aaaaaaaa-bbbb-4ccc-9ddd-eeeeeeeeeeee';
		$_REQUEST['checkview_test_id']   = $test_id;
		$_REQUEST[CheckView::PARAM_TEST_TYPE] = 'form';
		update_option( 'disable_actions_' . $test_id, 'true', false );

		$helper = new Checkview_Formidable_Helper();
		$action = (object) array( 'post_excerpt' => 'on_submit' );
		$result = $helper->checkview_disable_form_actions( true, $action, null, null, 'create' );
		$this->assertFalse( $result );

		delete_option( 'disable_actions_' . $test_id );
		unset( $_REQUEST['checkview_test_id'], $_REQUEST[CheckView::PARAM_TEST_TYPE] );
	}

	public function test_disable_form_actions_skips_third_party_when_flag_set() {
		$test_id                         = 'aaaaaaaa-bbbb-4ccc-9ddd-eeeeeeeeeeee';
		$_REQUEST['checkview_test_id']   = $test_id;
		$_REQUEST[CheckView::PARAM_TEST_TYPE] = 'form';
		update_option( 'disable_actions_' . $test_id, 'true', false );

		$helper = new Checkview_Formidable_Helper();
		$action = (object) array( 'post_excerpt' => 'mailchimp' );
		$result = $helper->checkview_disable_form_actions( false, $action, null, null, 'create' );
		$this->assertTrue( $result, 'Third-party action must be skipped (true = skip) when disable_actions is set.' );

		delete_option( 'disable_actions_' . $test_id );
		unset( $_REQUEST['checkview_test_id'], $_REQUEST[CheckView::PARAM_TEST_TYPE] );
	}

	public function test_disable_form_actions_third_party_runs_without_flag() {
		$helper = new Checkview_Formidable_Helper();
		$action = (object) array( 'post_excerpt' => 'mailchimp' );
		$result = $helper->checkview_disable_form_actions( false, $action, null, null, 'create' );
		$this->assertFalse( $result, 'Third-party action runs (false = do not skip) when disable_actions is unset.' );
	}

	public function test_remove_recaptcha_field_from_list() {
		$checkview_formidable_helper = new Checkview_Formidable_Helper();

		// Test data.
		$test_data = array(
			array(
				'id'      => rand( 1, 100 ),
				'form_id' => rand( 1, 100 ),
				'name'    => 'Test Field',
				'type'    => 'recaptcha',
			),
		);

		// Insert test data into the database.
		global $wpdb;
		foreach ( $test_data as $field ) {
			$wpdb->insert( $wpdb->prefix . 'frm_fields', $field );
		}

		// Test the function.
		$fields = $checkview_formidable_helper->remove_recaptcha_field_from_list( $test_data, null );

		// Check if the recaptcha field was removed correctly.
		$this->assertEquals( 0, count( $fields ) );

		// Clean up.
		foreach ( $test_data as $field ) {
			$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . $wpdb->prefix . 'frm_fields WHERE id=%d', $field['id'] ) );
		}
	}
}
