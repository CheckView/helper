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
	 * `woocommerce_new_order`.
	 *
	 * H1+H9 coupling: this priority MUST be strictly less than 200 because
	 * Mailchimp for WooCommerce registers `handleOrderCreate` on
	 * `woocommerce_new_order @ priority 200`, which then synchronously fires
	 * the `mailchimp_should_push_order` filter that H9 hooks. For our
	 * suppression filter to see the `checkview_test_id` order meta, the
	 * stamping function must run first.
	 *
	 * Test 21 in the helper test suite asserts this invariant — DO NOT change
	 * to a value ≥ 200 without also moving the test priority floor up.
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
			$this->loader->add_action(
				'checkview_rotate_user_credentials',
				$this,
				'checkview_rotate_test_user_credentials',
				10,
			);

			$this->loader->add_filter(
				'woocommerce_registration_errors',
				$this,
				'checkview_stop_registration_errors',
				15,
				3
			);

			// Delete orders on backend page load if crons are disabled.
			// if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			// $this->loader->add_action(
			// 'admin_init',
			// $this,
			// 'delete_orders_from_backend',
			// );
			// }

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
	 * @return void
	 */
	public function checkview_empty_woocommerce_cart_if_parameter() {
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
	 * Creates the CheckView test customer.
	 *
	 * If the customer already exists, just return it.
	 *
	 * @return WC_Customer
	 */
	public static function checkview_create_test_customer() {
		$customer = self::checkview_get_test_customer();
		$email    = CHECKVIEW_EMAIL;

		if ( false === $customer || empty( $customer ) ) {
			// Get user object by email.
			$customer = get_user_by( 'email', $email );
			if ( $customer ) {
				update_option( 'checkview_test_user', $customer->ID );
				return $customer;
			}
			$customer = new WC_Customer();
			$customer->set_username( uniqid( 'checkview_wc_automated_testing_' ) );
			$customer->set_password( wp_generate_password() );
			$customer->set_email( CHECKVIEW_EMAIL );
			$customer->set_display_name( 'CheckView WooCommerce Automated Testing User' );

			$customer_id = $customer->save();

			update_option( 'checkview_test_user', $customer_id );
		}

		return $customer;
	}


	/**
	 * Gets the test customer.
	 *
	 * If no customer was found, return `false`.
	 *
	 * @return WC_Customer|false
	 */
	public static function checkview_get_test_customer() {
		$customer_id = get_option( 'checkview_test_user', false );

		if ( $customer_id ) {
			$customer = new WC_Customer( $customer_id );

			if ( is_a( $customer, 'WC_Customer' ) && 0 !== $customer->get_id() ) {
				return $customer;
			}
		}

		return false;
	}

	/**
	 * Resets errors when registering CheckView testing customer.
	 *
	 * @param WP_Error $errors Registration errors.
	 * @param string   $username Username for the registration.
	 * @param string   $email Email for the registration.
	 *
	 * @return WP_Error
	 */
	public function checkview_stop_registration_errors( $errors, $username, $email ) {
		// Check for our WCAT username and email.
		if ( false !== strpos( $username, 'checkview_wc_automated_testing_' )
		&& false !== strpos( $email, CHECKVIEW_EMAIL ) ) {
			// The default value for this in WC is a WP_Error object, so just reset it.
			$errors = new WP_Error();
		}
		return $errors;
	}

	/**
	 * Sets credentials for the CheckView testing customer.
	 *
	 * @return string[] Credentials for the test user.
	 *
	 * @type string $email The test user's email address.
	 * @type string $username The test user's username.
	 * @type string $password The newly-generated password for the test user.
	 */
	public static function checkview_get_test_credentials() {
		add_filter( 'pre_wp_mail', '__return_false', PHP_INT_MAX );

		$password = wp_generate_password();
		$customer = self::checkview_get_test_customer();

		if ( ! $customer ) {
			$customer = self::checkview_create_test_customer();
		}

		$customer->set_password( $password );
		$customer->save();

		// Schedule the password to be rotated 15min from now.
		self::checkview_rotate_password_cron();

		return array(
			'email'    => $customer->get_email(),
			'username' => $customer->get_username(),
			'password' => $password,
		);
	}

	/**
	 * Generates and saves a new password for the CheckView test user.
	 */
	public function checkview_rotate_test_user_credentials() {
		add_filter( 'pre_wp_mail', '__return_false', PHP_INT_MAX );

		$customer = self::checkview_get_test_customer();

		if ( ! $customer ) {
			return false;
		}

		$customer->set_password( wp_generate_password() );
		$customer->save();
	}

	/**
	 * Rotate test user's password every 15 minutes.
	 *
	 * @return void
	 */
	public static function checkview_rotate_password_cron() {
		wp_schedule_single_event( time() + 15 * MINUTE_IN_SECONDS, 'checkview_rotate_user_credentials' );
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
			//   stamp at priority 1 — STAMP_PRIORITY < 200 is the load-bearing invariant for H9).
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

			// H9: gate Mailchimp for WooCommerce's order push behind the same
			// safety invariant. Mailchimp uses direct WC action hooks → AS
			// queue, bypassing WC's webhook system, so the H3 webhook filter
			// alone doesn't catch it. This filter fires synchronously inside
			// `mailchimp_handle_or_queue()` BEFORE the order is queued for
			// async push.
			if ( class_exists( 'MailChimp_WooCommerce' ) ) {
				$this->loader->add_filter(
					'mailchimp_should_push_order',
					$this,
					'checkview_filter_mailchimp_should_push_order',
					10,
					1
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

		// customer.* topics are user-scoped, not order-scoped — don't gate them.
		if ( ! empty( $topic ) && 0 === strpos( $topic, 'customer.' ) ) {
			return $should_deliver;
		}

		if ( ! empty( $arg ) ) {
			$order = wc_get_order( $arg );
			if ( $order && cv_is_suppressible_test_order( $order ) ) {
				return false;
			}
		}

		return $should_deliver;
	}

	/**
	 * Suppresses Mailchimp for WooCommerce order pushes for active CheckView
	 * test orders.
	 *
	 * H9: Mailchimp's WC plugin uses direct WC action hooks
	 * (`woocommerce_new_order @ priority 200`, `woocommerce_order_status_changed`,
	 * `woocommerce_update_order`) → Action Scheduler queue → asynchronous
	 * `wp_remote_post()` to api.mailchimp.com. It bypasses WC's webhook system
	 * entirely, so the H3 webhook filter alone doesn't catch it.
	 *
	 * Fortunately Mailchimp exposes a per-order pre-queue filter
	 * `mailchimp_should_push_order` invoked synchronously inside
	 * `mailchimp_handle_or_queue()`. Returning false short-circuits the queue
	 * write, preventing the deferred API call from ever being scheduled.
	 *
	 * For our return false to engage, the order must already carry the
	 * `checkview_test_id` meta when this filter fires — which is why
	 * `checkview_stamp_order_meta` MUST be registered at
	 * `woocommerce_new_order @ priority < 200` (see STAMP_PRIORITY).
	 *
	 * Filter signature confirmed via Mailchimp source: invoked with ONE
	 * argument (`$order_id`), and the call site checks `=== false` to skip
	 * queueing. Returning null pass-through preserves any other plugin's
	 * filter that may have legitimately returned false.
	 *
	 * @param mixed $order_id Order ID from Mailchimp's invocation. Note: the
	 *                        plugin passes this as `$job->id` from a
	 *                        `MailChimp_WooCommerce_Single_Order` job; verified
	 *                        to be the WC order ID (not an internal job ID).
	 *
	 * @return mixed `false` to suppress the Mailchimp push, `null` (pass-through)
	 *               otherwise.
	 */
	public function checkview_filter_mailchimp_should_push_order( $order_id ) {
		if ( ! $order_id ) {
			return null; // nothing to evaluate; pass through.
		}

		$order = wc_get_order( $order_id );
		if ( $order && cv_is_suppressible_test_order( $order ) ) {
			return false;
		}

		return null;
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
	 * @param integer $order_id Woocommerce Order ID.
	 * @return bool
	 */
	public static function checkview_delete_orders( $order_id = '' ) {
		Checkview_Admin_Logs::add( 'ip-logs', 'Deleting CheckView orders from the database...' );

		$orders = array();
		$args = array(
			'limit' => -1,
			'type' => 'shop_order',
			'meta_key' => 'payment_made_by', // Postmeta key field.
			'meta_value' => 'checkview', // Postmeta value field.
			'meta_compare' => '=',
			'return' => 'ids',
		);

		if ( function_exists( 'wc_get_orders' ) ) {
			$orders = wc_get_orders( $args );
		}

		$orders_cv = array();
		$args = array(
			'limit' => -1,
			'type' => 'shop_order',
			'payment_method' => 'checkview',
			'return' => 'ids',
		);

		if ( function_exists( 'wc_get_orders' ) ) {
			$orders_cv = wc_get_orders( $args );
		}

		$orders = array_unique( array_merge( $orders, $orders_cv ) );

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
	 * Hooked on `woocommerce_new_order @ priority STAMP_PRIORITY` so it runs
	 * BEFORE addons hooking the same event at higher priorities (e.g. Mailchimp
	 * at @ priority 200). H9's Mailchimp filter
	 * (`checkview_filter_mailchimp_should_push_order`) reads this meta to
	 * decide whether to suppress; if stamping ran later, the filter would see
	 * an unstamped order and silently fail to suppress.
	 *
	 * Round-7 hardening: the original function trusted `$_COOKIE['checkview_test_id']`
	 * alone — a real customer with a stale 110-min cookie would have had their
	 * order incorrectly stamped. This version uses the per-request `CV_TEST_ID`
	 * constant (defined by `checkview_init_current_test()` after `is_bot()`
	 * passes IP whitelist + query param validation), which cannot leak to a
	 * real customer's request.
	 *
	 * Round-9 hardening: includes a `wc_get_order` guard for sites where
	 * WC isn't loaded, and clears the `checkview_test_id` cookie here (during
	 * the request, before headers flush) — moved out of `complete_checkview_test()`
	 * which now runs at shutdown where `setcookie()` would silently fail.
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

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		// Idempotency guard — first-wins on `checkview_test_id`. Keeps the
		// original test association on failed-payment retries where the
		// same order is touched twice.
		if ( $order->get_meta( 'checkview_test_id' ) ) {
			return;
		}

		$order->update_meta_data( 'payment_made_by', 'checkview' );
		$order->update_meta_data( 'checkview_test_id', CV_TEST_ID );
		$order->save();

		Checkview_Admin_Logs::add( 'ip-logs', 'Stamped CheckView meta on order [' . $order->get_id() . '] for test [' . CV_TEST_ID . '].' );

		// Clear the test cookie HERE (during the request, before headers
		// flush) — guarded by headers_sent() to avoid silent failure if a
		// theme accidentally flushed early.
		if ( ! headers_sent() ) {
			unset( $_COOKIE['checkview_test_id'] );
			setcookie( 'checkview_test_id', '', time() - 6600, COOKIEPATH, COOKIE_DOMAIN );
		}
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
		if ( ! $order || $order->get_meta( 'checkview_test_id' ) !== CV_TEST_ID ) {
			return; // not OUR test order
		}

		checkview_schedule_delete_orders( $order_id );
	}

	/**
	 * Calls `complete_checkview_test()` at shutdown to clean up per-test
	 * options and session state.
	 *
	 * H1 (round 7): split out from the original combined
	 * `checkview_add_custom_fields_after_purchase`. Deferring cleanup to
	 * `shutdown` is critical for H9 — Mailchimp's
	 * `mailchimp_should_push_order` filter (and any other in-request
	 * suppression filter) reads the per-test options
	 * (`disable_webhooks_<id>`, `disable_actions_<id>`) to decide whether to
	 * suppress. If we cleaned up earlier (e.g. on `woocommerce_new_order`
	 * alongside stamping), Mailchimp would read deleted options and
	 * `cv_is_suppressible_test_order` would return false → silent suppression
	 * failure. Running at shutdown lets all in-request hooks see the options
	 * alive, then cleans up after the request completes.
	 *
	 * Empty-state guard: `shutdown` fires for every WP request (admin pages,
	 * front-end, REST, AJAX, cron, CLI), so without the guard we'd call
	 * `complete_checkview_test('')` on every non-CheckView request and try
	 * to delete options with empty-string keys. The guard makes this a no-op
	 * outside test requests.
	 *
	 * @since 2.0.34
	 *
	 * @return void
	 */
	public function checkview_complete_test_deferred() {
		if ( ! defined( 'CV_TEST_ID' ) || ! CV_TEST_ID ) {
			return; // not a test request — don't delete options with empty key
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
