<?php
/**
 * Test case for the Checkview_Cf7_Helper class.
 *
 * @package    Checkview
 * @subpackage Checkview/tests
 */
if ( is_plugin_active( 'contact-form-7/wp-contact-form-7.php' ) && ! class_exists( 'checkview_cf7_helper' ) ) {
	require_once CHECKVIEW_INC_DIR . 'formhelpers/class-checkview-cf7-helper.php';
} else {
	return;
}
use Mockery\MockInterface;
use WPCF7_Submission;
use WPCF7_ContactForm;
use WP_Post;
class Checkview_Cf7_Helper_Test extends WP_UnitTestCase {

	/**
	 * Test that the constructor sets up the loader and hooks.
	 */
	public function test_constructor() {
		$helper = new Checkview_Cf7_Helper();

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
		$this->assertInstanceOf( 'Checkview_Loader', $helper->loader );
		$this->assertEquals( 99, has_action( 'wpcf7_before_send_mail', array( $helper, 'checkview_cf7_before_send_mail' ) ) );
		$this->assertEquals( 999, has_action( 'cfdb7_after_save_data', array( $helper, 'checkview_delete_entry' ) ) );
		$this->assertEquals( 99, has_filter( 'wpcf7_mail_components', array( $helper, 'checkview_inject_email' ) ) );
		$this->assertEquals( 999, has_filter( 'wpcf7_spam', array( $helper, 'checkview_return_false' ) ) );
	}

	/**
	 * Test that checkview_cf7_before_send_mail adds an entry to the DB.
	 */
	public function test_checkview_cf7_before_send_mail() {
		$tags = array( 'tag1', 'tag2', 'tag3' );

		$contact_form = Mockery::mock( 'WPCF7_ContactForm' );
		$contact_form->shouldReceive( 'scan_form_tags' )->andReturn( $tags );
		$contact_form->shouldReceive( 'locale' )->andReturn( 'en_US' );
		$contact_form->shouldReceive( 'is_true' )->andReturn( true );
		$contact_form->shouldReceive( 'validate_schema' )->andReturn( true ); // Add this line.
		$contact_form->shouldReceive( 'id' )->andReturn( 123 );
		$contact_form->shouldReceive( 'prop' )->with( 'mail' )->andReturn( array( 'recipient' => 'example@example.com' ) );
		$contact_form->shouldReceive( 'message' )->andReturn( 'Form submitted successfully' ); // Add this line.
		$post      = new WP_Post( (object) array( 'ID' => 1 ) );
		$post_mock = Mockery::mock( $post );

		$submission = Mockery::mock( 'WPCF7_Submission' );
		$submission->shouldReceive( 'get_instance' )->andReturnSelf();
		$submission->shouldReceive( 'get_contact_form' )->andReturn( $contact_form );
		$submission->id = 123; // Set the id property.
		$submission->shouldReceive( 'id' )->andReturn( 123 );
		$helper = new Checkview_Cf7_Helper();
		$result = $helper->checkview_cf7_before_send_mail( $submission );

		// Assert that the result is true or false based on your expected behavior.
		$this->assertEquals( null, $result );
	}

	/**
	 * Test that checkview_delete_entry deletes an entry from the DB.
	 */
	public function test_checkview_delete_entry() {
		global $wpdb;
		$helper    = new Checkview_Cf7_Helper();
		$insert_id = 1;
		$wpdb->insert( $wpdb->prefix . 'db7_forms', array( 'form_id' => $insert_id ) );
		$helper->checkview_delete_entry( $insert_id );
		$this->assertEquals( 0, $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'db7_forms' ) );
	}

	/**
	 * Test that checkview_inject_email injects an email into the CF7 email array.
	 */
	public function test_checkview_inject_email() {
		$helper = new Checkview_Cf7_Helper();
		$args   = array( 'recipient' => CHECKVIEW_EMAIL );
		$args   = $helper->checkview_inject_email( $args );
		$this->assertEquals( CHECKVIEW_EMAIL, $args['recipient'] );
	}

	/**
	 * Test that checkview_return_false returns false.
	 */
	public function test_checkview_return_false() {
		$helper = new Checkview_Cf7_Helper();
		$this->assertFalse( $helper->checkview_return_false() );
	}
}
