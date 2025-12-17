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
	 * Suppresses admin emails.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      bool/class    $suppress_email    The hooks loader of this plugin.
	 */
	private $suppress_email;

	/**
	 * Suppresses webhooks.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      bool/class    $suppress_webhook    The hooks loader of this plugin.
	 */
	private $suppress_webhook;

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
		$this->plugin_name      = $plugin_name;
		$this->version          = $version;
		$this->loader           = $loader;
		$this->suppress_email   = get_option( 'disable_email_receipt', false );
		$this->suppress_webhook = get_option( 'disable_webhooks', false );

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
				'status' => array( 'trash', 'publish' ),
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

		$cookie = CheckView::has_cookie();

		if ( $cookie !== 'woo_checkout' ) {
			return;
		}

		Checkview_Admin_Logs::add( 'ip-logs', 'Running Woo test mode hooks, visitor is bot and cookie value equals [' . $cookie . '].' );

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

			$this->loader->add_action(
				'woocommerce_order_status_changed',
				$this,
				'checkview_add_custom_fields_after_purchase',
				10,
				3
			);
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
		if ( ( isset( $_REQUEST['checkview_test_id'] ) || ( is_array( $cv_bot_ip ) && in_array( $visitor_ip, $cv_bot_ip ) ) ) || ( 'checkview' === $payment_method || 'checkview' === $payment_made_by ) ) {
			if ( get_option( 'disable_email_receipt' ) == true || get_option( 'disable_email_receipt' ) == 'true' || defined( 'CV_DISABLE_EMAIL_RECEIPT' ) || $this->suppress_email ) {
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
	 * Stops delivery of webhooks for CheckView orders.
	 *
	 * @param bool   $should_deliver Delivery status.
	 * @param object $webhook_object Webhook object.
	 * @param array  $arg Args to support.
	 * @return bool
	 */
	public function checkview_filter_webhooks( $should_deliver, $webhook_object, $arg ) {

		$topic = $webhook_object->get_topic();

		if ( ! empty( $topic ) && ! empty( $arg ) && 'order.' === substr( $topic, 0, 6 ) ) {

			$order = wc_get_order( $arg );

			if ( ! empty( $order ) ) {
				$payment_method  = ( \is_object( $order ) && \method_exists( $order, 'get_payment_method' ) ) ? $order->get_payment_method() : false;
				$payment_made_by = $order->get_meta( 'payment_made_by' );
				if ( ( $payment_method && 'checkview' === $payment_method && ( true === $this->suppress_webhook || 'true' === $this->suppress_webhook ) ) || ( 'checkview' === $payment_made_by && ( true === $this->suppress_webhook || 'true' === $this->suppress_webhook ) ) ) {
					return false;
				}
			}
		} elseif ( ! empty( $topic ) && ! empty( $arg ) && 'subscription.' === substr( $topic, 0, 13 ) ) {

			$order = wc_get_order( $arg );

			if ( ! empty( $order ) ) {
				$payment_method  = ( \is_object( $order ) && \method_exists( $order, 'get_payment_method' ) ) ? $order->get_payment_method() : false;
				$payment_made_by = is_object( $order ) ? $order->get_meta( 'payment_made_by' ) : '';
				if ( ( $payment_method && 'checkview' === $payment_method && ( true === $this->suppress_webhook || 'true' === $this->suppress_webhook ) ) || ( 'checkview' === $payment_made_by && ( true === $this->suppress_webhook || 'true' === $this->suppress_webhook ) ) ) {
					return false;
				}
			}
		}

		return $should_deliver;
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
	 * Adds custom meta to CheckView test orders.
	 *
	 * @param int    $order_id Order ID.
	 * @param string $old_status Order's old status.
	 * @param string $new_status Order's new status.
	 * @return void
	 */
	public function checkview_add_custom_fields_after_purchase( $order_id, $old_status, $new_status ) {
		if ( isset( $_COOKIE['checkview_test_id'] ) && '' !== $_COOKIE['checkview_test_id'] && checkview_is_valid_uuid( sanitize_text_field( wp_unslash( $_COOKIE['checkview_test_id'] ) ) ) ) {
			$order = new WC_Order( $order_id );
			$order->update_meta_data( 'payment_made_by', 'checkview' );
			$order->update_meta_data( 'checkview_test_id', sanitize_text_field( wp_unslash( $_COOKIE['checkview_test_id'] ) ) );

			Checkview_Admin_Logs::add( 'ip-logs', 'Added meta data to test order.' );

			complete_checkview_test( sanitize_text_field( wp_unslash( $_COOKIE['checkview_test_id'] ) ) );

			$order->save();

			Checkview_Admin_Logs::add( 'ip-logs', 'Saved new order [' . $order->get_id() . '].' );

			unset( $_COOKIE['checkview_test_id'] );
			setcookie( 'checkview_test_id', '', time() - 6600, COOKIEPATH, COOKIE_DOMAIN );
			checkview_schedule_delete_orders( $order_id );
		}
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
