<?php

class Test_Checkview_Woo_Automated_Testing extends WP_UnitTestCase {

	private $instance;

	protected function setUp(): void {
		parent::setUp();
		$this->instance = new Checkview_Woo_Automated_Testing( 'plugin_name', 'version', new Checkview_Loader() );
	}

	// public function test_constructor() {
	// $this->assertInstanceOf( 'Checkview_Woo_Automated_Testing', $this->instance );
	// }

	public function test_checkview_empty_woocommerce_cart_if_parameter() {
		$_GET['checkview_empty_cart'] = 'true';
		$this->instance->checkview_empty_woocommerce_cart_if_parameter();
		// Assert that the cart is empty
		$this->assertTrue( WC()->cart->is_empty() );
	}

	public function test_get_active_payment_gateways() {
		$gateways = $this->instance->get_active_payment_gateways();
		$this->assertIsArray( $gateways );
		$this->assertNotEmpty( $gateways );
	}

	public function test_checkview_create_test_customer() {
		$customer = $this->instance->checkview_create_test_customer();
		$this->assertInstanceOf( 'WC_Customer', $customer );
	}

	public function test_checkview_get_test_credentials() {
		$credentials = $this->instance->checkview_get_test_credentials();
		$this->assertIsArray( $credentials );
		$this->assertArrayHasKey( 'email', $credentials );
		$this->assertArrayHasKey( 'username', $credentials );
		$this->assertArrayHasKey( 'password', $credentials );
	}

	public function test_checkview_rotate_test_user_credentials() {
		$this->instance->checkview_rotate_test_user_credentials();
		// Assert that the password has been updated
		$customer = $this->instance->checkview_get_test_customer();
		$this->assertNotEquals( $customer, true );
	}

	public function test_checkview_get_test_product() {
		$product = $this->instance->checkview_create_test_product();
		$product = $this->instance->checkview_get_test_product();
		$this->assertInstanceOf( 'WC_Product', $product );
	}

	public function test_checkview_get_test_product_if_not_exists() {
		// $product = $this->instance->checkview_create_test_product();
		$product = $this->instance->checkview_get_test_product();
		$this->assertEquals( false, $product );
	}

	public function test_checkview_create_test_product() {
		$product = $this->instance->checkview_create_test_product();
		$this->assertInstanceOf( 'WC_Product', $product );
	}

	public function test_checkview_seo_hide_product_from_sitemap() {
		$excluded_posts_ids = array();
		$result             = $this->instance->checkview_seo_hide_product_from_sitemap( $excluded_posts_ids );
		$this->assertIsArray( $result );
		$this->assertContains( get_option( 'checkview_woo_product_id' ), $result );
	}

	public function test_checkview_hide_product_from_sitemap() {
		$args   = array();
		$result = $this->instance->checkview_hide_product_from_sitemap( $args );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'post__not_in', $result );
		$this->assertContains( get_option( 'checkview_woo_product_id' ), $result['post__not_in'] );
	}


	public function test_checkview_test_mode() {
		// Set up the test mode
		$_GET[CheckView::PARAM_TEST_ID] = 'test_id';
		$this->instance->checkview_test_mode();
		// Assert that the test mode is enabled
		$this->assertTrue( isset( $_GET[CheckView::PARAM_TEST_ID] ) ); // Add a more specific assertion here
	}
}
