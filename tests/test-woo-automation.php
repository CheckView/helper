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

	public function test_checkview_hide_test_product_from_block_query() {
		$result = $this->instance->checkview_hide_test_product_from_block_query( array() );
		$this->assertIsArray( $result );
		$this->assertContains( (int) get_option( 'checkview_woo_product_id' ), $result['post__not_in'] );
	}

	public function test_checkview_hide_test_product_from_block_query_preserves_existing() {
		$result = $this->instance->checkview_hide_test_product_from_block_query(
			array( 'post__not_in' => array( 4242 ) )
		);
		$this->assertContains( 4242, $result['post__not_in'] );
		$this->assertContains( (int) get_option( 'checkview_woo_product_id' ), $result['post__not_in'] );
	}

	public function test_checkview_hide_test_product_from_qi_addons_query() {
		$result = $this->instance->checkview_hide_test_product_from_qi_addons_query(
			array( 'post_type' => 'product' ),
			array()
		);
		$this->assertContains( (int) get_option( 'checkview_woo_product_id' ), $result['post__not_in'] );
	}

	public function test_checkview_hide_test_product_from_qi_addons_query_preserves_existing() {
		$result = $this->instance->checkview_hide_test_product_from_qi_addons_query(
			array(
				'post_type'    => 'product',
				'post__not_in' => array( 4242 ),
			),
			array()
		);
		$this->assertContains( 4242, $result['post__not_in'] );
		$this->assertContains( (int) get_option( 'checkview_woo_product_id' ), $result['post__not_in'] );
	}

	public function test_checkview_hide_test_product_from_qi_addons_query_ignores_non_product() {
		$args   = array( 'post_type' => 'post' );
		$result = $this->instance->checkview_hide_test_product_from_qi_addons_query( $args, array() );
		$this->assertSame( $args, $result );
		$this->assertArrayNotHasKey( 'post__not_in', $result );
	}

	public function test_checkview_exclude_test_product_from_store_api_query() {
		$query = new WP_Query();
		$query->set( 'post_type', 'product' );
		$this->instance->checkview_exclude_test_product_from_store_api_query( $query );
		$this->assertContains(
			(int) get_option( 'checkview_woo_product_id' ),
			$query->get( 'post__not_in' )
		);
	}

	public function test_checkview_exclude_test_product_from_store_api_query_preserves_existing() {
		$query = new WP_Query();
		$query->set( 'post_type', 'product' );
		// Store API maps its own `exclude` request param into post__not_in, so
		// the guard must append rather than clobber it.
		$query->set( 'post__not_in', array( 4242 ) );
		$this->instance->checkview_exclude_test_product_from_store_api_query( $query );
		$excluded = $query->get( 'post__not_in' );
		$this->assertContains( 4242, $excluded );
		$this->assertContains( (int) get_option( 'checkview_woo_product_id' ), $excluded );
	}

	public function test_checkview_exclude_test_product_from_store_api_query_is_idempotent() {
		$query = new WP_Query();
		$query->set( 'post_type', 'product' );
		// collection-data runs several WP_Query instances per request, so the
		// callback fires repeatedly and must not accumulate duplicates.
		$this->instance->checkview_exclude_test_product_from_store_api_query( $query );
		$this->instance->checkview_exclude_test_product_from_store_api_query( $query );
		$excluded = $query->get( 'post__not_in' );
		$this->assertSame( array_values( array_unique( $excluded ) ), $excluded );
	}

	public function test_checkview_exclude_test_product_from_store_api_query_ignores_non_product() {
		$query = new WP_Query();
		$query->set( 'post_type', 'post' );
		$this->instance->checkview_exclude_test_product_from_store_api_query( $query );
		$this->assertEmpty( $query->get( 'post__not_in' ) );
	}

	public function test_checkview_maybe_hide_test_product_from_store_api_matches_only_listing_routes() {
		$callback = array( $this->instance, 'checkview_exclude_test_product_from_store_api_query' );

		// Removes only our own callback between cases. remove_all_actions()
		// would strip WordPress's and other plugins' pre_get_posts callbacks
		// for the rest of the process, which can break later tests in the run.
		$hooked = function ( $route ) use ( $callback ) {
			remove_action( 'pre_get_posts', $callback );
			$this->instance->checkview_maybe_hide_test_product_from_store_api(
				null,
				null,
				new WP_REST_Request( 'GET', $route )
			);
			$result = has_action( 'pre_get_posts', $callback );
			remove_action( 'pre_get_posts', $callback );
			return $result;
		};

		$this->assertNotFalse( $hooked( '/wc/store/v1/products' ), 'products collection' );
		$this->assertNotFalse( $hooked( '/wc/store/v1/products/collection-data' ), 'collection-data' );
		$this->assertNotFalse( $hooked( '/wc/store/v2/products' ), 'future store api version' );
		$this->assertFalse( $hooked( '/wc/store/v1/products/123' ), 'single product by id' );
		$this->assertFalse( $hooked( '/wc/store/v1/products/categories' ), 'product categories' );
		$this->assertFalse( $hooked( '/wc/store/v1/cart' ), 'unrelated store route' );
		$this->assertFalse( $hooked( '/wc/v3/products' ), 'legacy wc rest products' );
		$this->assertFalse( $hooked( '/wp/v2/posts' ), 'unrelated core route' );
	}

	public function test_checkview_maybe_hide_test_product_from_store_api_returns_result_untouched() {
		$request = new WP_REST_Request( 'GET', '/wc/store/v1/products' );
		$result  = $this->instance->checkview_maybe_hide_test_product_from_store_api(
			'sentinel',
			null,
			$request
		);
		$this->assertSame( 'sentinel', $result );
	}

	public function test_checkview_test_mode() {
		// Set up the test mode
		$_GET[CheckView::PARAM_TEST_ID] = 'test_id';
		$this->instance->checkview_test_mode();
		// Assert that the test mode is enabled
		$this->assertTrue( isset( $_GET[CheckView::PARAM_TEST_ID] ) ); // Add a more specific assertion here
	}
}
