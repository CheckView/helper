<?php
/**
 * Checkview_Woo_Automated_Testing class
 *
 * @since 1.0.0
 *
 * @package CheckView
 * @subpackage CheckView/includes/woocommercehelper
 */

/**
 * Sets up WooCommerce for CheckView automated testing.
 *
 * Modifies hooks, manages testing product, manages customer account,
 * handles email recipients, etc.
 */
class Checkview_Woo_Automated_Testing {
	/**
	 * Priority at which `checkview_stamp_order_meta` is registered on
	 * `woocommerce_new_order` (and via the adapter on
	 * `woocommerce_after_order_object_save`).
	 *
	 * Why strictly less than 200: Mailchimp for WooCommerce registers
	 * `MailChimp_Service::handleOrderCreate` on
	 * `woocommerce_new_order @ priority 200`. For other addons (Shippo's
	 * WC webhooks, Mailchimp's order-meta readers, etc) to see the
	 * `checkview_test_id` meta when they run on the same hook, our
	 * stamping function MUST run first. This priority is also the
	 * "early enough" requirement for `woocommerce_after_order_object_save`
	 * so the meta lands before WC enqueues any `order.updated` webhook
	 * for the same save.
	 *
	 * DO NOT change to a value ≥ 200 without first auditing every addon
	 * registered on `woocommerce_new_order` for downstream meta reads.
	 *
	 * @since 2.0.34
	 *
	 * @var int
	 */
	const STAMP_PRIORITY = 1;

	/**
	 * Plugin name.
	 *
	 * @since 1.0.0
	 * @access private
	 *
	 * @var string $plugin_name The ID of this plugin.
	 */
	private $plugin_name;

	/**
	 * Plugin version.
	 *
	 * @since 1.0.0
	 * @access private
	 *
	 * @var string $version The current version of this plugin.
	 */
	private $version;

	/**
	 * Loader.
	 *
	 * @since 1.0.0
	 * @access private
	 *
	 * @var bool/class $loader The hooks loader of this plugin.
	 */
	private $loader;

	/**
	 * Constructor.
	 *
	 * Initiates class properties, adds hooks.
	 *
	 * @since 1.0.0
	 *
	 * @param string $plugin_name The name of this plugin.
	 * @param string $version The version of this plugin.
	 * @param Checkview_Loader $loader Loads the hooks.
	 */
	public function __construct( $plugin_name, $version, $loader ) {
		$this->plugin_name = $plugin_name;
		$this->version     = $version;
		$this->loader      = $loader;

		if ( $this->loader ) {
			$this->loader->add_action(
				'admin_init',
				$this,
				'checkview_create_test_product',
				200
			);

			$this->loader->add_action(
				'trashed_post',
				$this,
				'checkview_trash_product_option',
				20
			);

			// Hook into after_delete_post to delete the option when the product is permanently deleted.
			$this->loader->add_action(
				'after_delete_post',
				$this,
				'checkview_after_delete_product'
			);
			$this->loader->add_action(
				'template_redirect',
				$this,
				'checkview_empty_woocommerce_cart_if_parameter',
			);

			$this->loader->add_action(
				'wp_head',
				$this,
				'checkview_no_index_for_test_product',
			);

			$this->loader->add_filter(
				'query_loop_block_query_vars',
				$this,
				'checkview_hide_test_product_from_block_query',
			);

			$this->loader->add_filter(
				'qi_addons_for_elementor_filter_query_params',
				$this,
				'checkview_hide_test_product_from_qi_addons_query',
				10,
				2
			);

			$this->loader->add_filter(
				'wpseo_exclude_from_sitemap_by_post_ids',
				$this,
				'checkview_seo_hide_product_from_sitemap',
			);

			$this->loader->add_filter(
				'wp_sitemaps_posts_query_args',
				$this,
				'checkview_hide_product_from_sitemap',
			);

			$this->loader->add_filter(
				'publicize_should_publicize_published_post',
				$this,
				'checkview_seo_hide_product_from_jetpack',
			);

			$this->loader->add_filter(
				'woocommerce_webhook_should_deliver',
				$this,
				'checkview_filter_webhooks',
				10,
				3
			);

			// Mailchimp for WooCommerce DOES expose a per-order suppression
			// filter: `mailchimp_handle_or_queue()` gates EVERY Single_Order
			// enqueue on `mailchimp_should_push_order` (bootstrap.php, present
			// in 6.1 — verified against the wp.org 6.1 zip AND a live 6.1
			// install; PR #223 concluded otherwise from a grep against the
			// wrong plugin folder name, `mailchimp-woocommerce` instead of
			// `mailchimp-for-woocommerce`). This single choke point covers the
			// classic checkout, the Blocks/Store API checkout, REST/admin
			// order saves, and the catch-up sync — including the
			// Blocks-checkout enqueue that the init-time hook sweep in
			// `checkview_mailchimp_killswitch` cannot reach (static callbacks
			// registered on `woocommerce_blocks_loaded`; Freshdesk #24669
			// follow-up). Registered always-on, like the webhook filter above,
			// because the enqueue can also happen outside the test request;
			// the callback engages only for stamped test orders whose
			// `disable_*_<uuid>` option is still active.
			$this->loader->add_filter(
				'mailchimp_should_push_order',
				$this,
				'checkview_mailchimp_should_push_order'
			);

			// Hide CheckView test orders from the WooCommerce REST API so
			// pull-based integrations (Shippo et al.) that poll
			// `GET /wc/v3/orders` with store API keys never ingest them. The
			// webhook filter above only covers push deliveries; nothing else
			// hid test orders from a REST pull, and the cleanup cron runs up
			// to an hour later, so a poll in between would pick up the
			// synthetic order. Registered always-on (NOT behind the is_bot()
			// gate in checkview_test_mode) because a Shippo poll is a
			// separate, non-bot request.
			//
			// Hook: the WC orders REST controller fires
			// `woocommerce_rest_orders_prepare_object_query` in
			// prepare_objects_query() (verified in WC source, since WC 4.5) —
			// this is the load-bearing one. We ALSO register the generic
			// `woocommerce_rest_shop_order_object_query` as a cross-version
			// fallback; whichever fires, the callback only appends to
			// post__not_in (idempotent), so registering both is safe.
			$this->loader->add_filter(
				'woocommerce_rest_orders_prepare_object_query',
				$this,
				'checkview_exclude_test_orders_from_rest',
				10,
				2
			);
			$this->loader->add_filter(
				'woocommerce_rest_shop_order_object_query',
				$this,
				'checkview_exclude_test_orders_from_rest',
				10,
				2
			);

			$this->loader->add_filter(
				'woocommerce_email_recipient_new_order',
				$this,
				'checkview_filter_admin_emails',
				10,
				3
			);

			$this->loader->add_filter(
				'woocommerce_email_recipient_failed_order',
				$this,
				'checkview_filter_admin_emails',
				10,
				3
			);
			$this->loader->add_action(
				'checkview_delete_orders_action',
				$this,
				'checkview_delete_orders',
				10,
				1
			);

			$this->loader->add_filter(
				'woocommerce_can_reduce_order_stock',
				$this,
				'checkview_maybe_not_reduce_stock',
				10,
				2
			);

			$this->loader->add_filter(
				'woocommerce_prevent_adjust_line_item_product_stock',
				$this,
				'checkview_woocommerce_prevent_adjust_line_item_product_stock',
				10,
				3
			);
		}

		$this->checkview_test_mode();
	}


	/**
	 * Deletes the stored Woo Product ID option.
	 *
	 * @param int $post_id The ID of the post being deleted.
	 */
	public function checkview_after_delete_product( $post_id ) {
		$product_id = get_option( 'checkview_woo_product_id' );

		if ( $product_id && $post_id == $product_id ) {
			// Delete the option storing the product ID if the deleted post is the test product.
			delete_option( 'checkview_woo_product_id' );
		}
	}

	/**
	 * Untrashes CheckView Test product if it was accidentally trashed.
	 *
	 * @param int $post_id The ID of the post being trashed.
	 */
	public function checkview_trash_product_option( $post_id ) {
		// Check if the trashed post is the test product.
		$product_id = get_option( 'checkview_woo_product_id' );

		if ( $post_id == $product_id ) {
			// If the product being trashed matches the stored product ID, untrash it.
			wp_untrash_post( $product_id );
		}
	}
	/**
	 * Clears the WooCommerce cart.
	 *
	 * Test-only behaviour: the CheckView bot appends `?checkview_empty_cart=true`
	 * to reset its cart between checkout runs. This handler is hooked on
	 * `template_redirect` for every front-end request, so it must be gated on
	 * `is_bot()` — otherwise any shopper who follows a crafted product/shop link
	 * carrying the parameter has their cart silently emptied.
	 *
	 * @return void
	 */
	public function checkview_empty_woocommerce_cart_if_parameter() {
		if ( ! CheckView::is_bot() ) {
			return;
		}

		// Check if WooCommerce is active.
		if ( class_exists( 'WooCommerce' ) ) {
			// Check if the parameter exists in the URL.
			if ( isset( $_GET['checkview_empty_cart'] ) && 'true' === $_GET['checkview_empty_cart'] && ( is_product() || is_shop() ) ) {
				// Get WooCommerce cart instance.
				$woocommerce_instance = WC();
				// Check if the cart is not empty.
				if ( ! $woocommerce_instance->cart->is_empty() ) {
					// Clear the cart.
					$woocommerce_instance->cart->empty_cart();
				}
			}
		}
	}
	/**
	 * Retrieves active/enabled payment gateways.
	 *
	 * @return array
	 */
	public static function get_active_payment_gateways() {
		$active_gateways  = array();
		$payment_gateways = WC_Payment_Gateways::instance()->payment_gateways();
		foreach ( $payment_gateways as $gateway ) {
			if ( 'yes' === $gateway->settings['enabled'] ) {
				$active_gateways[ $gateway->id ] = $gateway->title;
			}

			if ( 'yes' === $gateway->enabled ) {
				$active_gateways[ $gateway->id ] = $gateway->title;
			}
		}
		return $active_gateways;
	}

	/**
	 * Gets the CheckView test product.
	 *
	 * If the testing product is trashed, it untrash it, then return it.
	 *
	 * @return WC_Product/bool
	 */
	public static function checkview_get_test_product() {
		$product_id = get_option( 'checkview_woo_product_id' );
		if ( $product_id ) {
			try {
				$product = new WC_Product( $product_id );

				// In case WC_Product returns a new customer with an ID of 0 if
				// one could not be found with the given ID.
				if ( is_a( $product, 'WC_Product' ) && 0 !== $product->get_id() ) {
					return $product;
				}
			// phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			} catch ( \Exception $e ) {
				// Check if any product with the title "CheckView Testing Product" exists.
				// The given test product was not valid, so we should fallback to the
				// default response if one was not found in the first place.
			}
		}

		$existing_product = wc_get_products(
			array(
				'name'   => 'CheckView Testing Product',
				'status' => array( 'trash', 'publish', 'draft' ),
				'limit'  => 1,
				'return' => 'objects',
			)
		);

		if ( ! empty( $existing_product ) ) {
			// If the product already exists (published or trashed), save its ID to options and return it.
			$product = $existing_product[0];

			// If the product is in the trash, restore it.
			if ( $product->get_status() === 'trash' ) {
				wp_untrash_post( $product->get_id() );
			}

			update_option( 'checkview_woo_product_id', $product->get_id(), true );
			return $product;
		}
		return false;
	}

	/**
	 * Creates the CheckView testing product.
	 *
	 * If a testing product exists, return it.
	 *
	 * @return WC_Product
	 */
	public function checkview_create_test_product() {
		$product = $this->checkview_get_test_product();
		if ( ! $product ) {
			$product = new WC_Product();
			$product->set_status( 'publish' );
			$product->set_name( 'CheckView Testing Product' );
			$product->set_short_description( 'An example product for automated testing.' );
			$product->set_description( 'This is a placeholder product used for automatically testing your WooCommerce store. It\'s designed to be hidden from all customers.' );
			$product->set_regular_price( '1.00' );
			$product->set_price( '1.00' );
			$product->set_stock_status( 'instock' );
			$product->set_stock_quantity( 5 );
			$product->set_catalog_visibility( 'hidden' );
			// Set weight and dimensions.
			$product->set_weight( '1' ); // 1 ounce in pounds.
			$product->set_length( '1' ); // Length in store units (e.g., inches, cm).
			$product->set_width( '1' ); // Width in store units (e.g., inches, cm).
			$product->set_height( '1' ); // Height in store units (e.g., inches, cm).
			// This filter is added here to prevent the WCAT test product from being publicized on creation.
			add_filter( 'publicize_should_publicize_published_post', '__return_false' );

			$product_id = $product->save();
			update_option( 'checkview_woo_product_id', $product_id, true );
		}

		return $product;
	}

	/**
	 * Hides testing product from sitemap.
	 *
	 * @param array $excluded_posts_ids Post IDs to be excluded.
	 *
	 * @return array[]
	 */
	public function checkview_seo_hide_product_from_sitemap( $excluded_posts_ids = array() ) {
		$product_id = get_option( 'checkview_woo_product_id' );

		if ( $product_id ) {
			array_push( $excluded_posts_ids, $product_id );
		}

		return $excluded_posts_ids;
	}

	/**
	 * Hides testing product from sitemap.
	 *
	 * @param array $args Query args.
	 *
	 * @return array
	 */
	public function checkview_hide_product_from_sitemap( $args ) {
		$product_id = get_option( 'checkview_woo_product_id' );

		if ( $product_id ) {
			$args['post__not_in']   = isset( $args['post__not_in'] ) ? $args['post__not_in'] : array();
			$args['post__not_in'][] = $product_id;
		}

		return $args;
	}

	/**
	 * Hides testing product from Jetpack.
	 *
	 * @param bool     $should_publicize Publicized or not.
	 * @param \WP_Post $post WordPress post object.
	 *
	 * @return bool|array
	 */
	public function checkview_seo_hide_product_from_jetpack( $should_publicize, $post ) {
		if ( $post ) {
			$product_id = get_option( 'checkview_woo_product_id' );

			if ( $product_id === $post->ID ) {
				return false;
			}
		}

		return $should_publicize;
	}

	/**
	 * Excludes the CheckView test product from Query Loop block queries.
	 *
	 * The editor preview is not affected by this filter.
	 *
	 * @param array $query_args WP_Query arguments for the Query Loop block.
	 * @return array
	 */
	public function checkview_hide_test_product_from_block_query( $query_args ) {
		$product_id = get_option( 'checkview_woo_product_id' );
		if ( empty( $product_id ) ) {
			return $query_args;
		}

		$existing = $query_args['post__not_in'] ?? array();
		$query_args['post__not_in'] = wp_parse_id_list( $existing );
		$query_args['post__not_in'][] = (int) $product_id;

		return $query_args;
	}

	/**
	 * Excludes the CheckView test product from Qi Addons for Elementor queries
	 * (Product Slider, Product List, etc.).
	 *
	 * @param array $args  WP_Query arguments built by qi_addons_for_elementor_get_query_params().
	 * @param array $atts  Widget shortcode attributes.
	 * @return array
	 */
	public function checkview_hide_test_product_from_qi_addons_query( $args, $atts ) {
		if ( ! isset( $args['post_type'] ) || 'product' !== $args['post_type'] ) {
			return $args;
		}

		$product_id = get_option( 'checkview_woo_product_id' );
		if ( empty( $product_id ) ) {
			return $args;
		}

		$existing = $args['post__not_in'] ?? array();
		$args['post__not_in'] = wp_parse_id_list( $existing );
		$args['post__not_in'][] = (int) $product_id;

		return $args;
	}

	/**
	 * Adds no index meta tag for test product.
	 */
	public function checkview_no_index_for_test_product() {
		$product_id = get_option( 'checkview_woo_product_id' );
		if ( ! empty( $product_id ) && 0 !== $product_id && is_single( $product_id ) ) {
			echo '<meta name="robots" content="noindex, nofollow"/>';
		}
	}

	/**
	 * Sets up additional hooks for CheckView test submissions.
	 *
	 * @return void
	 */
	public function checkview_test_mode() {
		$is_bot = CheckView::is_bot();

		if ( ! $is_bot ) {
			return;
		}

		$test_type = CheckView::test_type();
		$woo_checkout_types = [ 'full_checkout', 'woo_checkout' ];

		if ( ! in_array( $test_type, $woo_checkout_types, true ) ) {
			return;
		}

		Checkview_Admin_Logs::add( 'ip-logs', 'Running Woo checkout hooks, detected test type [' . $test_type . '].' );

		if ( ! is_admin() && class_exists( 'WooCommerce' ) ) {
			// Always use Stripe test mode when on dev or staging.
			add_filter(
				'option_woocommerce_stripe_settings',
				function ( $value ) {
					Checkview_Admin_Logs::add( 'ip-logs', 'Setting Woo test mode to true for hook [option_woocommerce_stripe_settings].' );

					$value['testmode'] = 'yes';

					return $value;
				}
			);

			// Turn test mode on for stripe payments.
			add_filter(
				'wc_stripe_mode',
				function ( $mode ) {
					Checkview_Admin_Logs::add( 'ip-logs', 'Setting Woo test mode to true for hook [wc_stripe_mode].' );

					$mode = 'test';

					return $mode;
				}
			);

			// Load payment gateway.
			require_once CHECKVIEW_INC_DIR . 'woocommercehelper/class-checkview-payment-gateway.php';

			// Add fake payment gateway for checkview tests.
			$this->loader->add_filter(
				'woocommerce_payment_gateways',
				$this,
				'checkview_add_payment_gateway',
				11,
				1
			);

			// Registers WooCommerce Blocks integration.
			$this->loader->add_action(
				'woocommerce_blocks_loaded',
				$this,
				'checkview_woocommerce_block_support',
			);

			add_filter(
				'cfturnstile_whitelisted',
				'__return_true',
				999
			);

			// Bypass Simple Cloudflare Turnstile for block checkout.
			// The plugin checks for a token and throws before calling cfturnstile_check()
			// where our cfturnstile_whitelisted filter lives. Adding 'checkview' to the
			// skipped payment methods list makes turnstile skip validation entirely.
			// @since 2.0.30
			add_filter(
				'option_cfturnstile_selected_payment_methods',
				function ( $methods ) {
					if ( ! is_array( $methods ) ) {
						$methods = array();
					}
					if ( ! in_array( 'checkview', $methods, true ) ) {
						$methods[] = 'checkview';
						Checkview_Admin_Logs::add( 'ip-logs', 'Added checkview to turnstile skipped payment methods list.' );
					}
					return $methods;
				}
			);

			// Make the test product visible in the catalog.
			add_filter(
				'woocommerce_product_is_visible',
				function ( bool $visible, $product_id ) {
					$product = $this->checkview_get_test_product();

					if ( ! $product ) {
						return false;
					}

					$is_visible = $product_id === $product->get_id() ? true : $visible;

					if ($is_visible) {
						Checkview_Admin_Logs::add( 'ip-logs', 'Setting Woo test product visibility to true.' );

						return true;
					}

					return false;
				},
				9999,
				2
			);

			// H1 split (replaces the old combined `checkview_add_custom_fields_after_purchase`):
			// - `checkview_stamp_order_meta` runs early on `woocommerce_new_order @ priority STAMP_PRIORITY`
			//   so the order meta is in place BEFORE any addon's hook on the same event fires
			//   (e.g. Mailchimp for WooCommerce hooks `handleOrderCreate` at priority 200, so we
			//   stamp at priority 1).
			// - `checkview_stamp_order_meta_from_save` runs on every order save
			//   (`woocommerce_after_order_object_save @ STAMP_PRIORITY`) so WC Block Checkout
			//   drafts get their meta BEFORE any `order.updated@checkout-draft` webhook fires.
			// - `checkview_schedule_order_cleanup` keeps the existing `woocommerce_order_status_changed`
			//   registration so order deletion is scheduled after the order has its final status.
			// - `checkview_complete_test_deferred` runs at `shutdown` so per-test options stay alive
			//   for the entire request — addons firing later in the request (Mailchimp's filter,
			//   any `woocommerce_webhook_should_deliver` filter) can still read the option to
			//   decide whether to suppress.
			$this->loader->add_action(
				'woocommerce_new_order',
				$this,
				'checkview_stamp_order_meta',
				self::STAMP_PRIORITY,
				1
			);

			// Also stamp on every order save so WC Block Checkout drafts get
			// their `checkview_test_id` meta BEFORE any
			// `order.updated@checkout-draft` webhook is enqueued for Shippo
			// et al. `woocommerce_after_order_object_save` fires from
			// `WC_Abstract_Order::save()` on EVERY save and resolves per
			// `$object_type`, so it covers WC_Order (not refunds) on both
			// HPOS and legacy stores. Reentrancy from our own `$order->save()`
			// call inside the stamper is bounded by the static `$in_progress`
			// guard and the existing first-wins meta check.
			$this->loader->add_action(
				'woocommerce_after_order_object_save',
				$this,
				'checkview_stamp_order_meta_from_save',
				self::STAMP_PRIORITY,
				1
			);

			$this->loader->add_action(
				'woocommerce_order_status_changed',
				$this,
				'checkview_schedule_order_cleanup',
				10,
				3
			);

			$this->loader->add_action(
				'shutdown',
				$this,
				'checkview_complete_test_deferred',
				10,
				0
			);

			// Mailchimp kill-switch: strips MC's hook callbacks for the
			// current test request (service instances AND the Blocks-checkout
			// integration classes) so its order/cart/customer handlers never
			// fire. Works alongside the always-on
			// `mailchimp_should_push_order` gate registered above, which
			// blocks any Single_Order enqueue the sweep can't reach.
			//
			// Hooked on `init @ 99` — runs AFTER
			// `checkview_init_current_test` (`init @ 10`) defines CV_TEST_ID
			// and writes the `disable_*_<uuid>` options, AFTER the Blocks
			// integration registers its static callbacks
			// (`woocommerce_blocks_loaded`, fires during WooCommerce's
			// `plugins_loaded`), and BEFORE any WC order event
			// (`woocommerce_init`, `woocommerce_new_order`, etc).
			if ( class_exists( 'MailChimp_WooCommerce' ) ) {
				$this->loader->add_action(
					'init',
					$this,
					'checkview_mailchimp_killswitch',
					99,
					0
				);
			}
		} else {
			Checkview_Admin_Logs::add( 'ip-logs', 'No Woo hooks were ran (WooCommerce was not found or client is requesting admin area).' );
		}
	}

	/**
	 * Returns false.
	 *
	 * @param bool $activate Wether to activate or not.
	 * @return bool
	 */
	public function checkview_return_false( $activate ) {
		$activate = false;
		return $activate;
	}
	/**
	 * Overwrites order email recipients.
	 *
	 * @param string   $recipient Recipient.
	 * @param WC_Order $order WooCommerce order.
	 * @param Email    $self WooCommerce Email object.
	 * @return string
	 */
	public function checkview_filter_admin_emails( $recipient, $order, $self ) {

		$payment_method  = ( \is_object( $order ) && \method_exists( $order, 'get_payment_method' ) ) ? $order->get_payment_method() : false;
		$payment_made_by = is_object( $order ) ? $order->get_meta( 'payment_made_by' ) : '';
		$visitor_ip      = checkview_get_visitor_ip();
		// Check view Bot IP.
		$cv_bot_ip = checkview_get_api_ip();
		if ( ( get_checkview_test_id() || ( is_array( $cv_bot_ip ) && in_array( $visitor_ip, $cv_bot_ip ) ) ) || ( 'checkview' === $payment_method || 'checkview' === $payment_made_by ) ) {
			if ( defined( 'CV_DISABLE_EMAIL_RECEIPT' ) ) {
				if ( defined( 'TEST_EMAIL' ) ) {
					$recipient = $recipient . ', ' . TEST_EMAIL;
				} else {
					$recipient = $recipient . ', ' . CHECKVIEW_EMAIL;
				}
			} elseif ( defined( 'TEST_EMAIL' ) ) {
				return TEST_EMAIL;
			} else {
				return CHECKVIEW_EMAIL;
			}
		}

		return $recipient;
	}


	/**
	 * Stops delivery of WooCommerce webhooks for active CheckView test orders.
	 *
	 * H3 rewrite: previously branched on `'order.'` and `'subscription.'`
	 * topic prefixes and gated on `payment_method/payment_made_by === 'checkview'`
	 * with `defined('CV_DISABLE_WEBHOOKS')`. The new design delegates to
	 * `cv_is_suppressible_test_order()` which implements the unified safety
	 * invariant (UUID order meta + active per-test option) — gateway-agnostic
	 * and topic-broadened (covers `order.*`, `subscription.*`, `coupon.*`,
	 * `product.*`).
	 *
	 * `customer.*` topics are explicitly excluded because they are user-scoped
	 * (not order-scoped) — `wc_get_order()` on a customer ID would either
	 * return false (typical) or coincidentally resolve to a different
	 * resource's order (extremely rare ID overlap), and either way the
	 * downstream gate would correctly fail. Excluding them upfront avoids
	 * any ambiguity and keeps the customer-scoped topic delivery path clean.
	 *
	 * @param bool   $should_deliver Delivery status.
	 * @param object $webhook_object Webhook object.
	 * @param mixed  $arg Resource ID for the webhook topic (typically an order
	 *                    ID for order/subscription topics; can arrive as int
	 *                    or numeric string depending on caller).
	 * @return bool
	 */
	public function checkview_filter_webhooks( $should_deliver, $webhook_object, $arg ) {
		$topic = $webhook_object->get_topic();

		// Order-scoped topics: `order.*`, `subscription.*`. $arg is an order
		// (or subscription, which WC stores as an order). The canonical
		// path: resolve order, check `cv_is_suppressible_test_order` for
		// the meta + options invariants.
		if ( ! empty( $arg ) && (
			0 === strpos( (string) $topic, 'order.' )
			|| 0 === strpos( (string) $topic, 'subscription.' )
		) ) {
			$order = wc_get_order( $arg );
			if ( $order && cv_is_suppressible_test_order( $order ) ) {
				return false;
			}

			// `order.deleted` topic: by the time WC dispatches this webhook,
			// `wc_get_order($arg)` returns false because the order has been
			// removed from the DB by `checkview_delete_orders`. Without this
			// branch the suppression check above falls through and the
			// deletion event reaches Shippo et al. for an order they never
			// got an `order.created` for (we suppressed that earlier).
			// `checkview_delete_orders` sets a 3-day transient before
			// deleting each of OUR test orders; if that transient is present,
			// the deletion was driven by us and should be suppressed
			// downstream (TTL covers WC's webhook-retry backoff which can
			// extend to multiple days on a failing endpoint).
			//
			// CRITICAL: scope this to the `order.deleted` topic specifically.
			// Other order.* topics (created/updated) can also have `! $order`
			// at AS-delivery time — e.g. when the SaaS-side
			// `/store/deleteorders` REST call wipes the order between WC
			// enqueueing `order.created` and AS dequeueing it. For toggle-OFF
			// tests we WANT those deliveries to attempt (WC will build a 404
			// payload, but at minimum Shippo's webhook receiver sees the
			// event fire). Suppressing them here silenced legitimate
			// toggle-OFF deliveries — a regression vs pre-PR baseline.
			if ( ! $order && 'order.deleted' === $topic ) {
				if ( get_transient( 'cv_deleted_test_order_' . (int) $arg ) ) {
					return false;
				}
			}

			return $should_deliver;
		}

		// Non-order-scoped topics (`customer.*`, `product.*`, `coupon.*`):
		// $arg is NOT an order ID, so the meta-based check above doesn't
		// apply. But these CAN fire during a CheckView test — e.g. WC fires
		// `customer.created` when the checkout creates a guest-account for
		// the test's synthetic email. If we just passed through (PR #203
		// did), those webhooks ship the test customer/product/coupon to
		// Shippo/Mailchimp/etc.
		//
		// Gate them on CV_TEST_ID being defined AND the same option pair
		// `cv_is_suppressible_test_order` uses. If the request is a
		// CheckView test with active suppression, drop the delivery.
		// Otherwise pass through (real-customer customer/product/coupon
		// events deliver normally — they don't have CV_TEST_ID defined).
		if ( defined( 'CV_TEST_ID' ) && CV_TEST_ID && (
			get_option( 'disable_webhooks_' . CV_TEST_ID ) === 'true'
			|| get_option( 'disable_actions_' . CV_TEST_ID ) === 'true'
		) ) {
			return false;
		}

		return $should_deliver;
	}

	/**
	 * Excludes CheckView test orders from WooCommerce REST API list queries,
	 * so pull-based store integrations (Shippo, and any consumer of
	 * `GET /wc/v3/orders` via REST API keys) never ingest them.
	 *
	 * Why this is needed: the webhook filter (`checkview_filter_webhooks`)
	 * only covers `woocommerce_webhook_should_deliver` (push) deliveries.
	 * Shippo's WooCommerce integration PULLS orders from the REST API on its
	 * own schedule, and the cleanup cron deletes the test order up to an hour
	 * later — so a poll in that window ingested the synthetic order
	 * (Freshdesk #24669 / nicholaslodge.com).
	 *
	 * Marker, NOT predicate: this matches the durable CheckView marker
	 * (`payment_method = 'checkview'` or `payment_made_by = 'checkview'` meta)
	 * rather than `cv_is_suppressible_test_order()`. That predicate also
	 * requires the per-test `disable_*_<uuid>` option, which
	 * `complete_checkview_test()` deletes at the END of the test request —
	 * long before an async REST poll (a separate, later request) arrives. The
	 * marker is stamped at order creation (`checkview_stamp_order_meta`,
	 * `woocommerce_new_order @ STAMP_PRIORITY`) and persists until the cleanup
	 * cron deletes the order, so it is the only reliable signal at poll time.
	 *
	 * Blast radius: only orders bearing the CheckView marker are ever
	 * excluded — real customer orders never match. CheckView's own order reads
	 * use the `checkview/v1/store/order` namespace (direct `wc_get_order`), not
	 * `wc/v3`, so this never affects test assertions. The incident kill switch
	 * (`cv_suppression_kill_switch`) disables it.
	 *
	 * ID-based exclusion (not a negative `meta_query`/`payment_method` filter)
	 * because `wc_get_orders()` exposes no negative match for those. Both
	 * `post__not_in` (legacy WP_Query path) and `exclude` (HPOS WC_Order_Query
	 * path) are set, since the controller uses a different query object per
	 * backend. The CheckView order set is tiny and short-lived, so the
	 * pre-query is cheap.
	 *
	 * @since 2.2.2
	 *
	 * @param array            $args    `wc_get_orders()` args built by the REST controller.
	 * @param \WP_REST_Request $request The REST request (unused).
	 * @return array Modified query args.
	 */
	public function checkview_exclude_test_orders_from_rest( $args, $request ) {
		if ( get_option( 'cv_suppression_kill_switch' ) === 'true' ) {
			return $args;
		}
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return $args;
		}

		$ids = $this->checkview_get_test_order_ids();
		if ( empty( $ids ) ) {
			return $args;
		}

		// Set BOTH exclusion keys: the orders REST controller runs WP_Query on
		// legacy storage (honours `post__not_in`) but `new WC_Order_Query()` on
		// HPOS (honours the WC-native `exclude`; verified the controller branches
		// this way). Setting both makes the exclusion work on either backend —
		// the key the active backend ignores is harmless.
		foreach ( array( 'post__not_in', 'exclude' ) as $exclude_key ) {
			$existing            = ( isset( $args[ $exclude_key ] ) && is_array( $args[ $exclude_key ] ) ) ? $args[ $exclude_key ] : array();
			$args[ $exclude_key ] = array_values( array_unique( array_merge( $existing, $ids ) ) );
		}

		return $args;
	}

	/**
	 * Returns the IDs of CheckView test orders currently in the store,
	 * identified by the durable marker set at order creation. Mirrors the
	 * two-query include pattern in `checkview_delete_orders()` (WooCommerce
	 * offers no single query that ORs a meta key with the `payment_method`
	 * property). Both are indexed lookups returning the small, short-lived set
	 * of live test orders.
	 *
	 * @since 2.2.2
	 *
	 * @return int[] Order IDs (may be empty).
	 */
	private function checkview_get_test_order_ids() {
		// Bounded, not `limit => -1`: live test orders number a handful (each
		// deleted within ~15s–1h). A cap keeps this per-REST-request query cheap
		// and avoids an unbounded scan if the cleanup cron ever lags; any excess
		// beyond the cap would be stale garbage and is a tolerable degraded mode.
		$by_meta = wc_get_orders(
			array(
				'limit'        => 500,
				'type'         => 'shop_order',
				'meta_key'     => 'payment_made_by', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'   => 'checkview',       // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'meta_compare' => '=',
				'return'       => 'ids',
			)
		);
		if ( is_wp_error( $by_meta ) ) {
			Checkview_Admin_Logs::add( 'ip-logs', 'REST order exclusion: wc_get_orders(payment_made_by) failed: ' . $by_meta->get_error_message() );
			$by_meta = array();
		}

		$by_gateway = wc_get_orders(
			array(
				'limit'          => 500,
				'type'           => 'shop_order',
				'payment_method' => 'checkview',
				'return'         => 'ids',
			)
		);
		if ( is_wp_error( $by_gateway ) ) {
			Checkview_Admin_Logs::add( 'ip-logs', 'REST order exclusion: wc_get_orders(payment_method) failed: ' . $by_gateway->get_error_message() );
			$by_gateway = array();
		}

		$ids = array_map( 'intval', array_merge( (array) $by_meta, (array) $by_gateway ) );

		return array_values( array_unique( array_filter( $ids ) ) );
	}

	/**
	 * Mailchimp kill-switch — strips MC for WC's hook callbacks for the
	 * current CheckView test request.
	 *
	 * History: PR #203 gated Mailchimp solely via the
	 * `mailchimp_should_push_order` filter; PR #223 removed that filter after
	 * concluding it doesn't exist in MC 6.1 (a grep against the wrong plugin
	 * folder name — it exists, in `mailchimp_handle_or_queue()`) and replaced
	 * it with this hook sweep. The sweep alone still leaked (Freshdesk #24669
	 * follow-up): the WC Blocks / Store API checkout enqueues a
	 * `Single_Order` push from
	 * `Mailchimp_Woocommerce_Newsletter_Blocks_Integration::order_processed`,
	 * a STATIC callback on `woocommerce_store_api_checkout_update_order_from_request`
	 * — no `MailChimp_Service` instance involved, so the instance-only sweep
	 * never matched it and every Blocks-checkout test order synced to
	 * Mailchimp (~15s delayed Action Scheduler job). Current design: this
	 * sweep now also removes the Blocks integration classes' callbacks
	 * (instance and static), and the always-on
	 * `checkview_mailchimp_should_push_order` filter backstops any enqueue
	 * path the sweep can't see.
	 *
	 * We additionally filter `mailchimp_allowed_to_use_cookie => false`, which
	 * IS a real MC filter and blocks the browser-side tracking-cookie path.
	 *
	 * Option gate (mirrors `cv_is_suppressible_test_order`): only engage when
	 * the SaaS sent `disable_actions=true` or `disable_webhooks=true` for this
	 * test, so tests that intentionally exercise the integration are untouched.
	 * Honors the incident kill switch (`cv_suppression_kill_switch`). Only
	 * engages when CV_TEST_ID is defined — real customer requests never reach
	 * this branch.
	 *
	 * @since 2.0.34
	 *
	 * @return void
	 */
	public function checkview_mailchimp_killswitch() {
		if ( ! defined( 'CV_TEST_ID' ) || ! CV_TEST_ID ) {
			return;
		}
		if ( get_option( 'cv_suppression_kill_switch' ) === 'true' ) {
			return;
		}
		$suppress = ( get_option( 'disable_actions_' . CV_TEST_ID ) === 'true' )
				|| ( get_option( 'disable_webhooks_' . CV_TEST_ID ) === 'true' );
		if ( ! $suppress ) {
			return;
		}

		// Real MC filter — blocks the browser-side tracking-cookie path.
		add_filter( 'mailchimp_allowed_to_use_cookie', '__return_false', PHP_INT_MAX );

		// Order/cart/customer sync — remove MailChimp_Service's callbacks (the
		// `mailchimp_is_configured` / `mailchimp_carts_disabled` filters are
		// no-ops in MC for WC; see docblock).
		$this->checkview_remove_mailchimp_service_hooks();
	}

	/**
	 * Removes every registered hook callback whose object is a
	 * `MailChimp_Service` instance, neutralising Mailchimp for WooCommerce's
	 * order/cart/customer sync for the current (CheckView test) request.
	 *
	 * Storage- and option-agnostic: depends only on the public class name
	 * `MailChimp_Service`. Uses each discovered callback with the public
	 * `remove_action()` (correct internal bookkeeping; collect-then-remove so
	 * `$wp_filter` is never mutated mid-iteration). No-op — logged with a zero
	 * count — if the class is absent or renamed, so version drift surfaces in
	 * the logs instead of as a silent leak. Safe at `init @ 99`: none of the
	 * targeted hooks have fired yet (nesting level 0).
	 *
	 * @since 2.2.2
	 *
	 * @return void
	 */
	private function checkview_remove_mailchimp_service_hooks() {
		if ( ! class_exists( 'MailChimp_Service' ) ) {
			Checkview_Admin_Logs::add( 'ip-logs', 'Mailchimp kill-switch: MailChimp_Service class not found; nothing to remove.' );
			return;
		}

		// MC4WP routes order/cart/customer SYNC through MailChimp_Service, but
		// the checkout newsletter and SMS-consent opt-in capture run on separate
		// handler classes. Neutralise all three. A renamed/absent class simply
		// doesn't match (guarded by class_exists), which surfaces via the
		// removal count logged below rather than as a silent leak.
		$mc_newsletter = class_exists( 'MailChimp_Newsletter' );
		$mc_sms        = class_exists( 'MailChimp_Sms_Consent' );

		// The WC Blocks / Store API checkout path registers STATIC callbacks
		// (`array( 'Class', 'method' )`) on
		// `woocommerce_store_api_checkout_update_order_from_request` /
		// `woocommerce_store_api_checkout_order_processed` (blocks/newsletter.php,
		// on `woocommerce_blocks_loaded`) plus an instance callback for cart
		// capture — none of which are `MailChimp_Service` instances, so the
		// instance sweep below misses them. Match these classes by name for
		// both static and instance callbacks.
		$mc_blocks_classes = array(
			'Mailchimp_Woocommerce_Newsletter_Blocks_Integration',
			'Mailchimp_Woocommerce_Sms_Blocks_Integration',
		);

		global $wp_filter;
		$targets = array();

		foreach ( $wp_filter as $hook_name => $hook ) {
			if ( ! ( $hook instanceof WP_Hook ) ) {
				continue;
			}
			foreach ( $hook->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $cb ) {
					$fn  = isset( $cb['function'] ) ? $cb['function'] : null;
					$obj = ( is_array( $fn ) && isset( $fn[0] ) && is_object( $fn[0] ) ) ? $fn[0] : null;

					$is_target = $obj && (
						$obj instanceof MailChimp_Service
						|| ( $mc_newsletter && $obj instanceof MailChimp_Newsletter )
						|| ( $mc_sms && $obj instanceof MailChimp_Sms_Consent )
						|| in_array( get_class( $obj ), $mc_blocks_classes, true )
					);

					// Static `array( 'Class', 'method' )` callbacks.
					if ( ! $is_target && is_array( $fn ) && isset( $fn[0] ) && is_string( $fn[0] ) ) {
						$is_target = in_array( ltrim( $fn[0], '\\' ), $mc_blocks_classes, true );
					}

					// Static `'Class::method'` string callbacks.
					if ( ! $is_target && is_string( $fn ) && false !== strpos( $fn, '::' ) ) {
						$is_target = in_array( ltrim( strtok( $fn, ':' ), '\\' ), $mc_blocks_classes, true );
					}

					if ( $is_target ) {
						$targets[] = array( $hook_name, $fn, $priority );
					}
				}
			}
		}

		foreach ( $targets as $target ) {
			remove_action( $target[0], $target[1], $target[2] );
		}

		Checkview_Admin_Logs::add(
			'ip-logs',
			'Mailchimp kill-switch: removed ' . count( $targets ) . ' MailChimp_Service hook callback(s) for test [' . CV_TEST_ID . '].'
		);
	}

	/**
	 * Blocks Mailchimp for WooCommerce from enqueueing a `Single_Order` push
	 * job for CheckView test orders.
	 *
	 * Hooked always-on to `mailchimp_should_push_order`, which
	 * `mailchimp_handle_or_queue()` applies to EVERY `Single_Order` enqueue
	 * regardless of origin (classic checkout, Blocks/Store API checkout,
	 * REST/admin order save, catch-up sync). Returning `false` aborts the
	 * enqueue; any other value falls through unchanged.
	 *
	 * Per-order gating via `cv_is_suppressible_test_order()` — same invariant
	 * as the WooCommerce webhook filter: the order must carry a valid
	 * `checkview_test_id` stamp AND its per-test `disable_*_<uuid>` option
	 * must still be active. Real customer orders always fall through.
	 *
	 * @since 2.3.0
	 *
	 * @param mixed $order_id Order ID being considered for push, or false if
	 *                        another filter already blocked it.
	 * @return mixed False to block the push, the incoming value otherwise.
	 */
	public function checkview_mailchimp_should_push_order( $order_id ) {
		if ( false === $order_id || ! function_exists( 'cv_is_suppressible_test_order' ) ) {
			return $order_id;
		}

		if ( cv_is_suppressible_test_order( $order_id ) ) {
			Checkview_Admin_Logs::add(
				'ip-logs',
				'Mailchimp kill-switch: blocked Single_Order enqueue for test order [' . (int) $order_id . '].'
			);
			return false;
		}

		return $order_id;
	}

	/**
	 * Adds CheckView dummy payment gateway to Woo.
	 *
	 * @param string[] $methods Methods to add payments.
	 * @return string[]
	 */
	public function checkview_add_payment_gateway( $methods ) {
		$gateway = 'Checkview_Payment_Gateway';

		Checkview_Admin_Logs::add( 'ip-logs', 'Adding Woo payment gateway [' . $gateway . '].' );

		$methods[] = $gateway;

		return $methods;
	}

	/**
	 * Declares Block Payment Gateway compatibility.
	 *
	 * @return void
	 */
	public function checkview_woocommerce_block_support() {
		if ( class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
			// Load block payment gateway.
			require_once CHECKVIEW_INC_DIR . 'woocommercehelper/class-checkview-blocks-payment-gateway.php';
			add_action(
				'woocommerce_blocks_payment_method_type_registration',
				function ( Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry ) {
					Checkview_Admin_Logs::add( 'ip-logs', 'Added Woo Blocks payment gateway.' );

					$payment_method_registry->register( new Checkview_Blocks_Payment_Gateway() );
				}
			);
		}
	}


	/**
	 * Handles deleting orders from the backend.
	 *
	 * Doesn't run on AJAX requests.
	 *
	 * @return boolean
	 */
	public static function delete_orders_from_backend() {
		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			return false;
		}

		return self::checkview_delete_orders();
	}

	/**
	 * Deletes CheckView orders from the database.
	 *
	 * If `$order_id` is supplied (the wp-cron-scheduled case from
	 * `checkview_schedule_delete_orders`), delete only that one order +
	 * its customer. If empty (the legacy admin-init sweep + manual call
	 * sites), fall back to deleting ALL orders that carry the
	 * `payment_made_by=checkview` marker or `payment_method=checkview`.
	 *
	 * Before deleting each test order we set a 1h transient
	 * `cv_deleted_test_order_<id>=1`, which is read by
	 * `checkview_filter_webhooks` to suppress the resulting
	 * `order.deleted` webhook — otherwise Shippo et al. receive a
	 * deletion event for an order they never got `order.created` for
	 * (we suppressed that earlier during the test).
	 *
	 * @param integer $order_id Woocommerce Order ID (optional). When set,
	 *                          delete only this order. When empty, sweep
	 *                          all CheckView-marked orders.
	 * @return bool
	 */
	public static function checkview_delete_orders( $order_id = '' ) {
		Checkview_Admin_Logs::add( 'ip-logs', 'Deleting CheckView orders from the database...' );

		$orders = array();

		// Targeted deletion: if a specific $order_id was scheduled (the
		// per-order wp-cron path from checkview_schedule_delete_orders),
		// honor it. Without this, a 1h cron tick fired for Test A's order
		// would sweep ALL marked orders including any in-flight Test B
		// order from a concurrent test on the same site.
		if ( '' !== $order_id && (int) $order_id > 0 ) {
			$orders = array( (int) $order_id );
		} elseif ( function_exists( 'wc_get_orders' ) ) {
			$args = array(
				'limit'        => -1,
				'type'         => 'shop_order',
				'meta_key'     => 'payment_made_by', // Postmeta key field.
				'meta_value'   => 'checkview',       // Postmeta value field.
				'meta_compare' => '=',
				'return'       => 'ids',
			);
			$orders = wc_get_orders( $args );

			$orders_cv = wc_get_orders(
				array(
					'limit'          => -1,
					'type'           => 'shop_order',
					'payment_method' => 'checkview',
					'return'         => 'ids',
				)
			);

			$orders = array_unique( array_merge( $orders, $orders_cv ) );
		}

		Checkview_Admin_Logs::add( 'cron-logs', 'Found ' . count( $orders ) . ' CheckView orders to delete.' );

		// Delete orders.
		if ( ! empty( $orders ) ) {
			foreach ( $orders as $order ) {
				$order_object = wc_get_order( $order );

				// Delete order.
				try {
					if ( $order_object && method_exists( $order_object, 'get_customer_id' ) ) {
						if ( $order_object->get_meta( 'payment_made_by' ) !== 'checkview' && 'checkview' !== $order_object->get_payment_method() ) {
							continue;
						}

						$customer_id = $order_object->get_customer_id();

						// Mark this order as a CheckView-driven deletion so
						// the WC webhook filter can suppress the resulting
						// `order.deleted` event (the order is gone by then,
						// so the filter has no other way to identify it).
						// TTL is generous — WC's webhook retry schedule can
						// extend to multiple days on a failing endpoint, and
						// each retry re-runs `should_deliver`. Three days
						// covers the published WC AS backoff schedule.
						set_transient( 'cv_deleted_test_order_' . (int) $order, 1, 3 * DAY_IN_SECONDS );

						$order_object->delete( true );

						delete_transient( 'checkview_store_orders_transient' );

						$order_object = null;
						$current_user = get_user_by( 'id', $customer_id );

						// Delete customer if available.
						if ( $customer_id && isset( $current_user->roles ) && isset( $current_user->roles ) && ! in_array( 'administrator', $current_user->roles, true ) ) {
							$customer = new WC_Customer( $customer_id );

							if ( ! function_exists( 'wp_delete_user' ) ) {
								require_once ABSPATH . 'wp-admin/includes/user.php';
							}

							$res = $customer->delete( true );
							$customer = null;
						}
					}
				} catch ( \Exception $e ) {
					if ( ! class_exists( 'Checkview_Admin_Logs' ) ) {
						require_once CHECKVIEW_ADMIN_DIR . '/class-checkview-admin-logs.php';
					}

					if ($order_object) {
						Checkview_Admin_Logs::add( 'cron-logs', 'Failed to delete CheckView order [' . $order_object->get_id() . '] from the database.' );
					} else {
						Checkview_Admin_Logs::add( 'cron-logs', 'Failed to delete CheckView order from the database.' );
					}

				}
			}

			return true;
		}
	}

	/**
	 * Stamps `payment_made_by` and `checkview_test_id` meta onto a newly-created
	 * WooCommerce order if the current request is a CheckView test.
	 *
	 * H1 (round 7): split out from the original combined
	 * `checkview_add_custom_fields_after_purchase`. This function ONLY stamps
	 * meta; it does NOT call `complete_checkview_test()` or schedule cleanup.
	 *
	 * Hooked on TWO actions, both at STAMP_PRIORITY:
	 *   - `woocommerce_new_order` — the original H1 hook, fires when a Woo
	 *     order transitions out of draft (classic checkout, REST orders).
	 *   - `woocommerce_after_order_object_save` (via the adapter
	 *     `checkview_stamp_order_meta_from_save`) — fires on EVERY order
	 *     save including Block Checkout draft creation. Catches the
	 *     `order.updated@checkout-draft` webhook events that the
	 *     `woocommerce_new_order`-only registration missed.
	 *
	 * Priority MUST stay strictly less than 200 because Mailchimp for
	 * WooCommerce hooks `MailChimp_Service::handleOrderCreate @ 200` on
	 * `woocommerce_new_order` and reads the order's `checkview_test_id`
	 * meta during `onOrderSave`. If we stamped later, Mailchimp's check
	 * would see an unstamped order and silently push to its audience.
	 * (The kill-switch via `mailchimp_is_configured` filter on `init @ 99`
	 * also disables that handler when the suppress option is set, but the
	 * STAMP_PRIORITY invariant matters whenever the toggle is OFF and
	 * Mailchimp legitimately runs — it still needs to see the meta to
	 * write the correct attribution.)
	 *
	 * Round-7 hardening: the original function trusted `$_COOKIE['checkview_test_id']`
	 * alone — a real customer with a stale 110-min cookie would have had their
	 * order incorrectly stamped. This version uses the per-request `CV_TEST_ID`
	 * constant (defined by `checkview_init_current_test()` after `is_bot()`
	 * passes IP whitelist + query param validation), which cannot leak to a
	 * real customer's request.
	 *
	 * Round-9 hardening: includes a `wc_get_order` guard for sites where
	 * WC isn't loaded.
	 *
	 * Idempotent with first-wins policy: if the order already has a
	 * `checkview_test_id` meta from a prior request (e.g. failed-payment retry),
	 * we keep the original stamp instead of overwriting. Preserves the original
	 * test association.
	 *
	 * @since 2.0.34
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return void
	 */
	public function checkview_stamp_order_meta( $order_id ) {
		if ( ! defined( 'CV_TEST_ID' ) || ! CV_TEST_ID ) {
			return; // not a CheckView test request
		}
		if ( ! function_exists( 'wc_get_order' ) ) {
			return; // WC not loaded; bail safely
		}
		// Don't stamp orders that are being torn down by the cleanup cron —
		// `$order->delete()` fires save() hooks during the delete sequence,
		// which would re-stamp + re-save a half-deleted order.
		if ( doing_action( 'checkview_delete_orders_action' ) ) {
			return;
		}
		// Reentrancy guard — `$order->save()` below fires
		// `woocommerce_after_order_object_save` which re-enters via the
		// `checkview_stamp_order_meta_from_save` adapter. The meta-present
		// idempotency check catches the loop, but make the guard explicit
		// so future refactors can't reintroduce recursion. `try/finally`
		// guarantees release even if `wc_get_order()` or `$order->save()`
		// throws.
		static $in_progress = array();
		if ( isset( $in_progress[ $order_id ] ) ) {
			return;
		}
		$in_progress[ $order_id ] = true;
		try {

			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				return;
			}

			// Idempotency guard — first-wins on `checkview_test_id`. Keeps the
			// original test association on failed-payment retries where the
			// same order is touched twice.
			if ( $order->get_meta( CheckView::PARAM_TEST_ID ) ) {
				return;
			}

			$order->update_meta_data( 'payment_made_by', 'checkview' );
			$order->update_meta_data( CheckView::PARAM_TEST_ID, CV_TEST_ID );
			$order->save();

			Checkview_Admin_Logs::add( 'ip-logs', 'Stamped CheckView meta on order [' . $order->get_id() . '] for test [' . CV_TEST_ID . '].' );

		} finally {
			unset( $in_progress[ $order_id ] );
		}
	}

	/**
	 * Adapter for `woocommerce_after_order_object_save` — that hook passes
	 * the order object; our canonical stamper takes an order ID. Hook fires
	 * on every order save (HPOS + legacy), so this resolves to a no-op
	 * outside CheckView test requests via the stamper's CV_TEST_ID guard.
	 *
	 * The action name is `woocommerce_after_<object_type>_object_save` and
	 * resolves to `woocommerce_after_order_object_save` for WC_Order only —
	 * refunds (`object_type=order_refund`) fire a different action and are
	 * NOT stamped by this adapter.
	 *
	 * @param mixed $order Order object passed by WC. Should be a WC_Order
	 *                     instance, but we defensively check.
	 *
	 * @return void
	 */
	public function checkview_stamp_order_meta_from_save( $order ) {
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_id' ) ) {
			return;
		}
		$this->checkview_stamp_order_meta( $order->get_id() );
	}

	/**
	 * Schedules deletion of a CheckView test order at status-change time.
	 *
	 * H1 (round 7): split out from the original combined
	 * `checkview_add_custom_fields_after_purchase`. Preserves the existing
	 * `woocommerce_order_status_changed @ priority 10` registration so cleanup
	 * is scheduled after the order has its final status (matches pre-split
	 * behaviour for backwards compatibility).
	 *
	 * Verifies the order's `checkview_test_id` meta matches the current request's
	 * `CV_TEST_ID` to prevent incorrectly scheduling cleanup for orders that
	 * weren't stamped by THIS test (e.g. cross-test status changes during
	 * concurrent runs, or admin manual transitions on stale stamped orders).
	 *
	 * @since 2.0.34
	 *
	 * @param int    $order_id Order ID.
	 * @param string $old_status Order's old status.
	 * @param string $new_status Order's new status.
	 * @return void
	 */
	public function checkview_schedule_order_cleanup( $order_id, $old_status, $new_status ) {
		if ( ! defined( 'CV_TEST_ID' ) || ! CV_TEST_ID ) {
			return; // not a test request
		}
		if ( ! function_exists( 'wc_get_order' ) ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order || $order->get_meta( CheckView::PARAM_TEST_ID ) !== CV_TEST_ID ) {
			return; // not OUR test order
		}

		checkview_schedule_delete_orders( $order_id );
	}

	/**
	 * Calls `complete_checkview_test()` at shutdown of the request that
	 * actually completes a test order, cleaning up per-test options + session.
	 *
	 * Why gated on completion `did_action`:
	 *   The previous unconditional behavior fired `complete_checkview_test`
	 *   at the shutdown of EVERY test-bearing request — including the very
	 *   first navigation that wrote the `disable_webhooks_<uuid>` /
	 *   `disable_actions_<uuid>` options. That immediately deleted them.
	 *   Subsequent navigations re-wrote and re-deleted them, and the
	 *   order-creating `wc-ajax=checkout` / `/wc/store/v1/checkout` POST
	 *   (which does NOT carry the `?disable_*` query params — Playwright
	 *   only injects on `isNavigationRequest`) found no options to read,
	 *   so suppression no-op'd and webhooks fired to Shippo with the
	 *   404 "Invalid ID" payload (because `checkview_delete_orders` had
	 *   already wiped the order by the time WC built the webhook payload).
	 *
	 *   Gating on completion `did_action()` preserves the options across
	 *   intermediate test navigations, then cleans up only on the request
	 *   that placed the order.
	 *
	 * Form helpers (CF7, GF, NF, WPForms, FluentForms, WSF, Formidable,
	 * Forminator, Everest) call `complete_checkview_test()` synchronously
	 * inside their submission hooks and don't depend on this shutdown path
	 * — gating to Woo completion actions doesn't affect form-test cleanup.
	 *
	 * Known leak: tests that abort before any completion action fires
	 * (Playwright crash, early assert failure, payment/validation error
	 * before `woocommerce_new_order`) leave the per-test options behind.
	 * UUIDs are unique per run so leaked options never affect later
	 * tests; growth is bounded by abort rate. A follow-up should add an
	 * hourly wp-cron sweep keyed on a sibling `_t` timestamp option.
	 *
	 * Long-lived worker note: `did_action()` is request-scoped — it counts
	 * fires within the current PHP request. PHP-FPM / mod_php / Apache
	 * tear down between requests, so each test-bearing request has its
	 * own counter starting at zero. WP-CLI long-running commands or
	 * Roadrunner-style workers would see accumulated counts from prior
	 * iterations, but CheckView's test traffic only ever hits web-style
	 * request lifecycles.
	 *
	 * @since 2.0.34
	 *
	 * @return void
	 */
	public function checkview_complete_test_deferred() {
		if ( ! defined( 'CV_TEST_ID' ) || ! CV_TEST_ID ) {
			return; // not a test request — don't delete options with empty key
		}

		$completion_fired = did_action( 'woocommerce_new_order' )
			|| did_action( 'woocommerce_thankyou' )
			|| did_action( 'woocommerce_checkout_order_processed' )
			|| did_action( 'woocommerce_store_api_checkout_order_processed' )
			|| did_action( 'woocommerce_rest_insert_shop_order_object' );

		if ( ! $completion_fired ) {
			Checkview_Admin_Logs::add(
				'ip-logs',
				'Shutdown cleanup skipped for ' . CV_TEST_ID . ' — no order completion this request'
			);
			return;
		}

		complete_checkview_test( CV_TEST_ID );
	}

	/**
	 * Prevents reduction of stock for CheckView orders.
	 *
	 * @since 1.5.2
	 *
	 * @param bool     $reduce_stock Reduce stock or not.
	 * @param WP_Order $order WooCommerce order object.
	 * @return bool
	 */
	public static function checkview_maybe_not_reduce_stock( $reduce_stock, $order ) {
		if ( $reduce_stock && is_object( $order ) && $order->get_billing_email() ) {
			$billing_email = $order->get_billing_email();

			if ( preg_match( '/store[\+]guest[\-](\d+)[\@]checkview.io/', $billing_email ) || preg_match( '/store[\+](\d+)[\@]checkview.io/', $billing_email ) ) {
				$reduce_stock = false;
			}

			$payment_method  = ( \is_object( $order ) && \method_exists( $order, 'get_payment_method' ) ) ? $order->get_payment_method() : false;
			$payment_made_by = $order->get_meta( 'payment_made_by' );
			if ( ( $payment_method && 'checkview' === $payment_method ) || ( 'checkview' === $payment_made_by ) ) {
				$reduce_stock = false;
			}
		}

		return $reduce_stock;
	}

	/**
	 * Prevents adjustment of stock for CheckView orders.
	 *
	 * @param bool          $prevent Prevent adjustment of stock.
	 * @param WC_Order_Item $item Item in order.
	 * @param int           $quantity Quaniity of item.
	 */
	public function checkview_woocommerce_prevent_adjust_line_item_product_stock( $prevent, $item, $quantity ) {
		// Get order.
		$order         = $item->get_order();
		$billing_email = $order->get_billing_email();

		if ( preg_match( '/store[\+]guest[\-](\d+)[\@]checkview.io/', $billing_email ) || preg_match( '/store[\+](\d+)[\@]checkview.io/', $billing_email ) ) {
			$prevent = true;
		}

		$payment_method  = ( \is_object( $order ) && \method_exists( $order, 'get_payment_method' ) ) ? $order->get_payment_method() : false;
		$payment_made_by = $order->get_meta( 'payment_made_by' );
		if ( ( $payment_method && 'checkview' === $payment_method ) || ( 'checkview' === $payment_made_by ) ) {
			$prevent = true;
		}

		return $prevent;
	}

	/**
	 * Emails suppression for Woo orders.
	 *
	 * @param [array] $args mail args.
	 * @return array
	 */
	public function checkview_filter_wp_mail( $args ) {
		// Suppress all order-related notifications except for new orders.
		if ( strpos( $args['subject'], 'order' ) !== false && ! strpos( $args['subject'], 'New order' ) ) {
			$args['to'] = ''; // Return empty array to suppress email.
		}
		return $args;
	}//end checkview_filter_wp_mail()
}
