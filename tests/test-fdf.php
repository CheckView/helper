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

	// public function test_checkview_log_form_test_entry() {
	// global $wpdb;

	// $checkview_formidable_helper = new Checkview_Formidable_Helper();
	// $form_id = 123;
	// $entry_id = 456;

	// Test data.
	// $test_data = array(
	// 'form_id' => $form_id,
	// 'status' => 'publish',
	// 'source_url' => 'http://example.com',
	// 'date_created' => current_time( 'mysql' ),
	// 'date_updated' => current_time( 'mysql' ),
	// 'uid' => 'test_uid',
	// 'form_type' => 'Formidable',
	// );

	// Insert test data into the database.
	// $wpdb->insert( $wpdb->prefix . 'cv_entry', $test_data );
	// $inserted_entry_id = $wpdb->insert_id;

	// Test the function.
	// $checkview_formidable_helper->checkview_log_form_test_entry( $entry_id, $form_id );

	// Check if the data was inserted correctly.
	// $entry_data = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'cv_entry WHERE id=%d', $inserted_entry_id ), ARRAY_A );
	// $this->assertEquals( 'test_uid', $entry_data['uid'] );

	// Clean up.
	// $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . $wpdb->prefix . 'cv_entry WHERE id=%d', $inserted_entry_id ) );
	// }

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
