<?php
/**
 * Tests for Checkview_Forminator_Helper.
 *
 * NOTE: the suite does not run from a normal checkout (tests/bootstrap.php
 * resolves WordPress via dirname( __DIR__, 4 )), so these have not been
 * executed. The behavioural coverage that has been executed lives in a
 * standalone assertion script — see the PR description.
 *
 * @package Checkview
 */

class Test_Checkview_Forminator_Helper extends WP_UnitTestCase {

	private $instance;

	protected function setUp(): void {
		parent::setUp();
		$this->instance = new Checkview_Forminator_Helper();
	}

	public function test_disable_captcha_field_type_adds_captcha() {
		$types = $this->instance->checkview_disable_captcha_field_type( array() );
		$this->assertContains( 'captcha', $types );
	}

	public function test_disable_captcha_field_type_preserves_forminator_own_entries() {
		// Forminator populates this filter itself with stripe/paypal when
		// payments are disabled; we must append, not replace.
		$types = $this->instance->checkview_disable_captcha_field_type( array( 'stripe', 'paypal' ) );
		$this->assertContains( 'stripe', $types );
		$this->assertContains( 'paypal', $types );
		$this->assertContains( 'captcha', $types );
	}

	public function test_disable_captcha_field_type_is_idempotent() {
		$once  = $this->instance->checkview_disable_captcha_field_type( array() );
		$twice = $this->instance->checkview_disable_captcha_field_type( $once );
		$this->assertSame( $once, $twice );
	}

	public function test_disable_captcha_field_type_handles_non_array() {
		$this->assertSame(
			array( 'captcha' ),
			$this->instance->checkview_disable_captcha_field_type( 'not-an-array' )
		);
	}

	public function test_remove_recaptcha_field_from_list_strips_captcha_and_reindexes() {
		$wrappers = array(
			'w1' => array(
				'fields' => array(
					array(
						'type' => 'text',
						'id'   => 'name-1',
					),
					array(
						'type' => 'captcha',
						'id'   => 'captcha-1',
					),
				),
			),
		);

		$result = $this->instance->remove_recaptcha_field_from_list( $wrappers, 7 );

		$this->assertCount( 1, $result['w1']['fields'] );
		$this->assertSame( 'name-1', $result['w1']['fields'][0]['id'] );
	}

	public function test_remove_recaptcha_field_from_list_drops_captcha_only_wrapper() {
		$wrappers = array(
			'w1' => array(
				'fields' => array(
					array(
						'type' => 'captcha',
						'id'   => 'captcha-1',
					),
				),
			),
			'w2' => array(
				'fields' => array(
					array(
						'type' => 'email',
						'id'   => 'email-1',
					),
				),
			),
		);

		$result = $this->instance->remove_recaptcha_field_from_list( $wrappers, 7 );

		$this->assertArrayNotHasKey( 'w1', $result );
		$this->assertArrayHasKey( 'w2', $result );
	}

	public function test_remove_email_header_strips_cc_and_bcc() {
		// Forminator emits 'Cc: ' / 'Bcc: ' (front-mail.php builds these into
		// the headers array before forminator_mailer_headers runs).
		$headers = $this->instance->checkview_remove_email_header(
			array(
				'From: Site <site@example.test>',
				'Cc: cc@example.test',
				'Bcc: bcc@example.test',
				'Content-Type: text/html',
			)
		);

		$joined = implode( "\n", $headers );
		$this->assertStringNotContainsStringIgnoringCase( 'Cc:', $joined );
		$this->assertStringNotContainsStringIgnoringCase( 'Bcc:', $joined );
		$this->assertStringContainsString( 'From:', $joined );
		$this->assertStringContainsString( 'Content-Type:', $joined );
	}

	public function test_disable_form_actions_honours_the_flag() {
		$test_id = get_checkview_test_id();

		update_option( 'disable_actions_' . $test_id, 'true' );
		$this->assertFalse( $this->instance->checkview_disable_form_actions( true ) );

		delete_option( 'disable_actions_' . $test_id );
		$this->assertTrue( $this->instance->checkview_disable_form_actions( true ) );
	}

	public function test_log_form_test_entry_handles_missing_entry_id() {
		// prevent_store forms and leads forms report entry_id 0. Must not fatal,
		// and must still complete the test rather than hang.
		$this->assertNull( $this->instance->checkview_log_form_test_entry( 7, array( 'entry_id' => 0 ) ) );
	}

	public function test_log_form_test_entry_handles_non_array_response() {
		$this->assertNull( $this->instance->checkview_log_form_test_entry( 7, 'not-an-array' ) );
	}

	public function test_it_hooks_the_post_save_action_not_the_pre_save_one() {
		// forminator_form_after_save_entry fires after handle_form() returns and
		// before wp_send_json_*; the unsuffixed tag also excludes drafts and
		// abandoned entries, which get _draft / _abandoned variants.
		$callback = array( $this->instance, 'checkview_log_form_test_entry' );
		$this->assertNotFalse( has_action( 'forminator_form_after_save_entry', $callback ) );
		$this->assertFalse( has_action( 'forminator_custom_form_submit_before_set_fields', $callback ) );
	}

	public function test_bypass_captcha_method_is_gone() {
		// Removed: no Forminator filter consumed it, and
		// forminator_disabled_fields covers its intent.
		$this->assertFalse( method_exists( $this->instance, 'checkview_bypass_captcha' ) );
	}
}
