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

	/**
	 * Store API guard: the exclusion is a WHERE clause, not post__not_in, because
	 * WP_Query resolves p / post__in / post__not_in as an if/elseif chain — any
	 * request carrying `include` would otherwise defeat it.
	 */
	private function store_api_where( $vars, $where = 'WHERE 1=1' ) {
		$query = new WP_Query();
		foreach ( $vars as $key => $value ) {
			$query->set( $key, $value );
		}
		return $this->instance->checkview_exclude_test_product_from_store_api_query( $where, $query );
	}

	private function test_product_clause() {
		global $wpdb;
		return $wpdb->posts . '.ID != ' . (int) get_option( 'checkview_woo_product_id' );
	}

	public function test_store_api_appends_exclusion_for_product_query() {
		update_option( 'checkview_woo_product_id', 4321 );
		$where = $this->store_api_where( array( 'post_type' => 'product' ) );
		$this->assertStringContainsString( $this->test_product_clause(), $where );
		$this->assertStringContainsString( 'WHERE 1=1', $where );
	}

	public function test_store_api_exclusion_survives_post__in() {
		// Store API maps ?include= straight into post__in (ProductQuery.php:32).
		update_option( 'checkview_woo_product_id', 4321 );
		$where = $this->store_api_where(
			array(
				'post_type' => 'product',
				'post__in'  => array( 1, 2, 4321 ),
			)
		);
		$this->assertStringContainsString( $this->test_product_clause(), $where );
	}

	public function test_store_api_exclusion_handles_array_post_type() {
		// A sku/slug param widens post_type to array( product, product_variation ).
		update_option( 'checkview_woo_product_id', 4321 );
		$where = $this->store_api_where( array( 'post_type' => array( 'product', 'product_variation' ) ) );
		$this->assertStringContainsString( $this->test_product_clause(), $where );
	}

	public function test_store_api_exclusion_is_idempotent() {
		update_option( 'checkview_woo_product_id', 4321 );
		$query = new WP_Query();
		$query->set( 'post_type', 'product' );

		$where = 'WHERE 1=1';
		for ( $i = 0; $i < 3; $i++ ) {
			$where = $this->instance->checkview_exclude_test_product_from_store_api_query( $where, $query );
		}

		$this->assertSame( 1, substr_count( $where, $this->test_product_clause() ) );
	}

	public function test_store_api_exclusion_ignores_non_product() {
		update_option( 'checkview_woo_product_id', 4321 );
		$this->assertSame( 'WHERE 1=1', $this->store_api_where( array( 'post_type' => 'post' ) ) );
		$this->assertSame( 'WHERE 1=1', $this->store_api_where( array() ) );
	}

	public function test_store_api_exclusion_no_ops_without_a_test_product() {
		delete_option( 'checkview_woo_product_id' );
		$this->assertSame( 'WHERE 1=1', $this->store_api_where( array( 'post_type' => 'product' ) ) );
	}

	public function test_store_api_matches_only_listing_routes() {
		update_option( 'checkview_woo_product_id', 4321 );
		$callback = array( $this->instance, 'checkview_exclude_test_product_from_store_api_query' );

		// Removes only our own callback between cases; remove_all_filters() would
		// strip other plugins' posts_where callbacks for the rest of the run.
		$hooked = function ( $route ) use ( $callback ) {
			remove_filter( 'posts_where', $callback, 10 );
			$this->instance->checkview_maybe_hide_test_product_from_store_api(
				null,
				null,
				new WP_REST_Request( 'GET', $route )
			);
			$result = has_filter( 'posts_where', $callback );
			remove_filter( 'posts_where', $callback, 10 );
			return $result;
		};

		// WooCommerce registers every Store API route under BOTH `wc/store/v1`
		// and the bare `wc/store` namespace (RoutesController.php:99-100), and WP
		// dispatches routes case-insensitively without normalising get_route().
		$this->assertNotFalse( $hooked( '/wc/store/v1/products' ), 'versioned' );
		$this->assertNotFalse( $hooked( '/wc/store/products' ), 'unversioned namespace' );
		$this->assertNotFalse( $hooked( '/wc/store/v1/Products' ), 'mixed case' );
		$this->assertNotFalse( $hooked( '/wc/store/v1/products/' ), 'trailing slash' );
		$this->assertNotFalse( $hooked( '/wc/store/v1/products/collection-data' ), 'collection-data' );

		$this->assertFalse( $hooked( '/wc/store/v1/products/123' ), 'single product by id' );
		$this->assertFalse( $hooked( '/wc/store/v1/products/categories' ), 'categories' );
		$this->assertFalse( $hooked( '/wc/store/v1/cart' ), 'cart' );
		$this->assertFalse( $hooked( '/wc/v3/products' ), 'legacy wc rest' );
		$this->assertFalse( $hooked( '/wp/v2/posts' ), 'core route' );
	}

	public function test_store_api_returns_result_untouched() {
		update_option( 'checkview_woo_product_id', 4321 );
		$request = new WP_REST_Request( 'GET', '/wc/store/v1/products' );
		$this->assertSame(
			'sentinel',
			$this->instance->checkview_maybe_hide_test_product_from_store_api( 'sentinel', null, $request )
		);
	}

	public function test_checkview_test_mode() {
		// Set up the test mode
		$_GET[CheckView::PARAM_TEST_ID] = 'test_id';
		$this->instance->checkview_test_mode();
		// Assert that the test mode is enabled
		$this->assertTrue( isset( $_GET[CheckView::PARAM_TEST_ID] ) ); // Add a more specific assertion here
	}
}
