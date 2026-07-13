<?php
use WP_Mock\Tools\TestCase;
class CheckviewTestIdTest extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		// $_REQUEST = array();
		// $_SERVER  = array();
	}

	public function testGetCheckviewTestIdFromRequest() {
		$_REQUEST[CheckView::PARAM_TEST_ID] = '12345';
		$this->assertSame( '12345', get_checkview_test_id() );
	}

	public function testGetCheckviewTestIdFromRefererWithQuery() {
		$_SERVER['HTTP_REFERER'] = 'http://example.com/testpage?checkview_test_id=67890';
		$this->assertSame( '67890', get_checkview_test_id() );
	}

	public function testGetCheckviewTestIdFromRefererWithoutQuery() {
		$_SERVER['HTTP_REFERER'] = 'http://example.com/testpage';
		$this->assertNull( get_checkview_test_id() );
	}

	public function testGetCheckviewTestIdEmptyRequestAndReferer() {
		$this->assertNull( get_checkview_test_id() );
	}

	public function testInvalidCheckviewTestIdSanitizedInRequest() {
		$_REQUEST[CheckView::PARAM_TEST_ID] = '<script>malicious_code()</script>123';
		$this->assertSame( '123', get_checkview_test_id() );  // Assuming sanitize_text_field strips out scripts
	}

	public function testRefererUrlWithInvalidQuerySanitized() {
		$_SERVER['HTTP_REFERER'] = 'http://example.com/testpage?checkview_test_id=<script>hack()</script>456';
		$this->assertSame( '456', get_checkview_test_id() );  // Assuming sanitize_url handles script tags
	}

	public function testGetCheckviewTestIdWithMultipleSources() {
		$_REQUEST[CheckView::PARAM_TEST_ID] = '12345';
		$_SERVER['HTTP_REFERER']       = 'http://example.com/testpage?checkview_test_id=67890';
		$this->assertSame( '12345', get_checkview_test_id() );
		//This tests that $_REQUEST has precedence over $_SERVER['HTTP_REFERER']
	}
}
