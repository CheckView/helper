<?php
class TestCheckviewAdmin extends WP_UnitTestCase {

	protected $admin;

	public function setUp(): void {
		parent::setUp();
		$this->admin = new Checkview_Admin( 'checkview', '1.0.0' );
	}

	public function test_enqueue_styles() {
		$this->admin->enqueue_styles();
		$this->assertfalse( wp_style_is( 'checkview', 'enqueued' ) );
		$this->assertfalse( wp_style_is( 'checkviewexternal', 'enqueued' ) );
		$this->assertfalse( wp_style_is( 'checkview-swal', 'enqueued' ) );
	}

	public function test_enqueue_scripts() {
		$this->admin->enqueue_scripts();
		$this->assertfalse( wp_script_is( 'checkview', 'enqueued' ) );
		$this->assertfalse( wp_script_is( 'checkview-sweetalert2.js', 'enqueued' ) );
	}
	
	public function testCheckviewInitCurrentTestVisitorIpNotEqualCvBotIp() {
		$admin                  = new Checkview_Admin( 'checkview', '1.0.0' );
		$visitor_ip             = '192.168.1.1';
		$cv_bot_ip              = '8.8.8.8';
		$_SERVER['REMOTE_ADDR'] = $visitor_ip;
		$this->assertEmpty( $admin->checkview_init_current_test() );
	}

	public function testCheckviewInitCurrentTestVisitorIpEqualCvBotIp() {
		$admin                  = new Checkview_Admin( 'checkview', '1.0.0' );
		$visitor_ip             = '192.168.1.1';
		$cv_bot_ip              = '192.168.1.1';
		$_SERVER['REMOTE_ADDR'] = $visitor_ip;
		$this->assertEmpty( $admin->checkview_init_current_test() );
	}

	public function testCheckviewInitCurrentTestCleanTalkPluginActive() {
		$admin                  = new Checkview_Admin( 'checkview', '1.0.0' );
		$visitor_ip             = '192.168.1.1';
		$cv_bot_ip              = '192.168.1.1';
		$_SERVER['REMOTE_ADDR'] = $visitor_ip;
		// $this->activate_plugin('cleantalk-spam-protect/cleantalk.php');
		$this->assertEmpty( $admin->checkview_init_current_test() );
	}

	public function testCheckviewInitCurrentTestAjaxSubmission() {
		$admin                  = new Checkview_Admin( 'checkview', '1.0.0' );
		$visitor_ip             = '192.168.1.1';
		$cv_bot_ip              = '192.168.1.1';
		$_SERVER['REMOTE_ADDR'] = $visitor_ip;
		$_SERVER['REQUEST_URI'] = 'admin-ajax.php';
		$this->assertEmpty( $admin->checkview_init_current_test() );
	}

	public function testCheckviewInitCurrentTestGetRequest() {
		$admin                     = new Checkview_Admin( 'checkview', '1.0.0' );
		$visitor_ip                = '192.168.1.1';
		$cv_bot_ip                 = '192.168.1.1';
		$_SERVER['REMOTE_ADDR']    = $visitor_ip;
		$_GET['checkview_test_id'] = '12345';
		$this->assertEmpty( $admin->checkview_init_current_test() );
	}

	public function testCheckviewInitCurrentTestSetCookie() {
		$admin                     = new Checkview_Admin( 'checkview', '1.0.0' );
		$visitor_ip                = '192.168.1.1';
		$cv_bot_ip                 = '192.168.1.1';
		$_SERVER['REMOTE_ADDR']    = $visitor_ip;
		$_GET['checkview_test_id'] = '12345';
		$this->assertEmpty( $admin->checkview_init_current_test() );
		// $this->assertCookieSet('checkview_test_id', '12345');
	}

	public function testCheckviewInitCurrentTestGetCvSession() {
		$admin                     = new Checkview_Admin( 'checkview', '1.0.0' );
		$visitor_ip                = '192.168.1.1';
		$cv_bot_ip                 = '192.168.1.1';
		$_SERVER['REMOTE_ADDR']    = $visitor_ip;
		$_GET['checkview_test_id'] = '12345';
		$this->assertEmpty( $admin->checkview_init_current_test() );
		$cv_session = checkview_get_cv_session( $visitor_ip, '12345' );
		$this->assertEmpty( $cv_session );
	}

	public function testCheckviewInitCurrentTestDefineConstants() {
		$admin                     = new Checkview_Admin( 'checkview', '1.0.0' );
		$visitor_ip                = '192.168.1.1';
		$cv_bot_ip                 = '192.168.1.1';
		$_SERVER['REMOTE_ADDR']    = $visitor_ip;
		$_GET['checkview_test_id'] = '12345';
		$this->assertEmpty( $admin->checkview_init_current_test() );
		$this->assertFalse( defined( 'TEST_EMAIL' ) );
		$this->assertFALSE( defined( 'CV_TEST_ID' ) );
	}
}
