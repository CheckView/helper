<?php
/**
 * Tests that a CheckView test request stamps only its own checkout order,
 * not other orders some plugin saves or creates during the same request.
 *
 * Self-contained: WordPress and WooCommerce are stubbed below, so this runs
 * with plain PHP (8.0+) from the plugin root:
 *
 *     php tests/order-stamp-ownership-test.php
 *
 * Exits non-zero when any assertion fails.
 *
 * @package CheckView
 */

// phpcs:disable -- Test script with WordPress stubs; not shipped (see .distignore).

define( 'ABSPATH', __DIR__ . '/' );
define( 'WPINC', 'wp-includes' );
define( 'CHECKVIEW_INC_DIR', dirname( __DIR__ ) . '/includes/' );

const TEST_UUID  = '1b4e28ba-2fa1-4d3b-8c5e-6a1f0b3c9d2e';
const OTHER_UUID = '7c9e6679-7425-40de-944b-e07fc1f90ae7';
const TEST_EMAIL = TEST_UUID . '@test-mail.checkview.io';

define( 'CHECKVIEW_TEST_ID', TEST_UUID );

register_shutdown_function(
	function () {
		if ( empty( $GLOBALS['cv_t_done'] ) ) {
			echo "\nFAIL the script stopped before the summary\n";
			exit( 1 );
		}
	}
);

/* ---- WordPress / WooCommerce stubs -------------------------------------- */

class Checkview_Admin_Logs {
	public static $lines = array();
	public static function add( $type, $line ) {
		self::$lines[] = $line;
	}
}
function add_action( $hook, $cb, $prio = 10, $args = 1 ) {
	return true;
}
function add_filter( $hook, $cb, $prio = 10, $args = 1 ) {
	return true;
}
function doing_action( $hook = null ) {
	return false;
}

/** Saved orders, by id: their stored data. */
$GLOBALS['cv_t_db']      = array();
$GLOBALS['cv_t_next_id'] = 100;
/** Order id => the test id stored when woocommerce_new_order fired. */
$GLOBALS['cv_t_meta_at_new_order'] = array();
/** Order id => number of writes. */
$GLOBALS['cv_t_writes'] = array();

class WC_Order {}

/**
 * Enough of WC_Order for the stamper. save() runs the same hooks, in the same
 * order, as WC_Abstract_Order::save() with the helper's registrations; both
 * order stores write the object's meta inside create(), before
 * woocommerce_new_order fires.
 */
class CV_T_Order extends WC_Order {
	public $id = 0;
	public $data;
	public function __construct( array $data = array(), $id = 0 ) {
		$this->id   = $id;
		$this->data = $data + array(
			'payment_method' => 'stripe',
			'billing_email'  => 'shopper@example.com',
			'created_via'    => 'checkout',
			'status'         => 'processing',
			'parent_id'      => 0,
			'meta'           => array(),
		);
	}
	public function get_id() {
		return $this->id;
	}
	public function get_payment_method( $context = 'view' ) {
		return $this->data['payment_method'];
	}
	public function get_billing_email( $context = 'view' ) {
		return $this->data['billing_email'];
	}
	public function get_created_via( $context = 'view' ) {
		return $this->data['created_via'];
	}
	public function get_parent_id( $context = 'view' ) {
		return $this->data['parent_id'];
	}
	public function get_status( $context = 'view' ) {
		return $this->data['status'];
	}
	public function get_meta( $key ) {
		return $this->data['meta'][ $key ] ?? '';
	}
	public function update_meta_data( $key, $value ) {
		$this->data['meta'][ $key ] = $value;
	}
	public function save() {
		$woo = $GLOBALS['cv_t_woo'];
		$woo->checkview_stamp_new_order_object( $this ); // woocommerce_before_order_object_save
		if ( ! empty( $GLOBALS['cv_t_abort_next_write'] ) ) {
			// create() threw: WC catches it, skips after-save and writes nothing.
			$GLOBALS['cv_t_abort_next_write'] = false;
			return 0;
		}
		$is_new    = ! $this->id;
		$was_draft = ! $is_new && 'checkout-draft' === ( $GLOBALS['cv_t_db'][ $this->id ]['status'] ?? '' );
		if ( $is_new ) {
			$this->id = $GLOBALS['cv_t_next_id']++;
		}
		$GLOBALS['cv_t_db'][ $this->id ] = $this->data;
		$GLOBALS['cv_t_writes'][ $this->id ] = ( $GLOBALS['cv_t_writes'][ $this->id ] ?? 0 ) + 1;
		// woocommerce_new_order: on creation (except drafts) and when a draft
		// becomes a real order.
		$is_draft = 'checkout-draft' === $this->data['status'];
		if ( ( $is_new && ! $is_draft ) || ( $was_draft && ! $is_draft ) ) {
			$GLOBALS['cv_t_meta_at_new_order'][ $this->id ] = $GLOBALS['cv_t_db'][ $this->id ]['meta']['checkview_test_id'] ?? null;
			$woo->checkview_stamp_order_meta( $this->id );
			if ( ! empty( $GLOBALS['cv_t_on_new_order'] ) ) {
				$cb                          = $GLOBALS['cv_t_on_new_order'];
				$GLOBALS['cv_t_on_new_order'] = null;
				$cb( $this );
			}
		}
		$woo->checkview_stamp_order_meta_from_save( $this ); // woocommerce_after_order_object_save
		return $this->id;
	}
}
function wc_get_order( $id ) {
	$GLOBALS['cv_t_loads'][ $id ] = ( $GLOBALS['cv_t_loads'][ $id ] ?? 0 ) + 1;
	if ( ! isset( $GLOBALS['cv_t_db'][ $id ] ) ) {
		return false;
	}
	return new CV_T_Order( $GLOBALS['cv_t_db'][ $id ], $id );
}

class CV_T_Session {
	public $data = array();
	public function get( $key, $default = null ) {
		return $this->data[ $key ] ?? $default;
	}
}
class CV_T_WC {
	public $session;
}
$GLOBALS['cv_t_wc']          = new CV_T_WC();
$GLOBALS['cv_t_wc']->session = new CV_T_Session();
function WC() {
	return $GLOBALS['cv_t_wc'];
}

/* ---- Code under test ---------------------------------------------------- */

class CheckView {
	const PARAM_TEST_ID = 'checkview_test_id';
}
require CHECKVIEW_INC_DIR . 'woocommercehelper/class-checkview-woo-automated-testing.php';

// The constructor runs request-time setup the stamper does not need.
$GLOBALS['cv_t_woo'] = ( new ReflectionClass( 'Checkview_Woo_Automated_Testing' ) )->newInstanceWithoutConstructor();

/* ---- Helpers ------------------------------------------------------------ */

$cv_t_failures = 0;
$cv_t_count    = 0;
function cv_t_check( $label, $ok ) {
	global $cv_t_failures, $cv_t_count;
	++$cv_t_count;
	if ( $ok ) {
		echo "ok   $label\n";
	} else {
		++$cv_t_failures;
		echo "FAIL $label\n";
	}
}
function cv_t_stamp_of( $id ) {
	return $GLOBALS['cv_t_db'][ $id ]['meta']['checkview_test_id'] ?? null;
}
/** An order that already exists, saved before this request. */
function cv_t_existing( array $data ) {
	$id                         = $GLOBALS['cv_t_next_id']++;
	$GLOBALS['cv_t_db'][ $id ] = ( new CV_T_Order( $data ) )->data;
	return $id;
}
/** Each case starts with an empty Woo session. */
function cv_t_case() {
	WC()->session->data = array();
}

/* ---- The bot's own orders are stamped ----------------------------------- */

// Block Checkout: the draft is created on page load, before any billing email.
cv_t_case();
$draft = new CV_T_Order(
	array(
		'created_via'   => 'store-api',
		'status'        => 'checkout-draft',
		'billing_email' => '',
	)
);
$draft->save();
cv_t_check( 'block checkout draft is stamped when it is created', TEST_UUID === cv_t_stamp_of( $draft->get_id() ) );
cv_t_check( 'block checkout draft is marked payment_made_by=checkview', 'checkview' === ( $GLOBALS['cv_t_db'][ $draft->get_id() ]['meta']['payment_made_by'] ?? null ) );
cv_t_check( 'block checkout draft is stamped by its first write (no second save)', 1 === $GLOBALS['cv_t_writes'][ $draft->get_id() ] );

// Classic checkout with the generated steps' email.
cv_t_case();
$classic = new CV_T_Order( array( 'billing_email' => TEST_EMAIL ) );
$classic->save();
cv_t_check( 'classic checkout order is stamped', TEST_UUID === cv_t_stamp_of( $classic->get_id() ) );
cv_t_check( 'classic checkout order already carries the stamp when woocommerce_new_order fires', TEST_UUID === ( $GLOBALS['cv_t_meta_at_new_order'][ $classic->get_id() ] ?? null ) );
cv_t_check( 'classic checkout order is stamped by its first write (no second save)', 1 === $GLOBALS['cv_t_writes'][ $classic->get_id() ] );

// The same object saved again later in the request (a plugin re-saving it).
$classic->save();
cv_t_check( 'a re-save of the bot order keeps its stamp', TEST_UUID === cv_t_stamp_of( $classic->get_id() ) );

// Classic checkout where the customer changed the flow's email field.
cv_t_case();
$custom = new CV_T_Order( array( 'billing_email' => 'qa@client.example' ) );
$custom->save();
cv_t_check( 'classic checkout order with a custom email is stamped (created by checkout)', TEST_UUID === cv_t_stamp_of( $custom->get_id() ) );

// An existing order paid with the CheckView gateway.
cv_t_case();
$gw = cv_t_existing( array( 'payment_method' => 'checkview' ) );
wc_get_order( $gw )->save();
cv_t_check( 'existing order paid with the CheckView gateway is stamped', TEST_UUID === cv_t_stamp_of( $gw ) );

// An existing order with this test's email, in upper case.
cv_t_case();
$mail = cv_t_existing( array( 'billing_email' => strtoupper( TEST_EMAIL ) ) );
wc_get_order( $mail )->save();
cv_t_check( "existing order with this test's email (any case) is stamped", TEST_UUID === cv_t_stamp_of( $mail ) );

// The Woo session's Block Checkout draft, created in an earlier request.
cv_t_case();
$session_draft = cv_t_existing( array( 'created_via' => 'store-api', 'status' => 'checkout-draft', 'billing_email' => '' ) );
WC()->session->data['store_api_draft_order'] = $session_draft;
wc_get_order( $session_draft )->save();
cv_t_check( "the session's Block Checkout draft is stamped", TEST_UUID === cv_t_stamp_of( $session_draft ) );

// The classic checkout's pending order (failed-payment retry).
cv_t_case();
$pending = cv_t_existing( array( 'status' => 'pending', 'billing_email' => 'qa@client.example' ) );
WC()->session->data['order_awaiting_payment'] = (string) $pending;
wc_get_order( $pending )->save();
cv_t_check( "the session's pending classic order is stamped", TEST_UUID === cv_t_stamp_of( $pending ) );

// The bot's draft, created unstamped, becoming the real order.
cv_t_case();
$bot_draft = cv_t_existing( array( 'created_via' => 'store-api', 'status' => 'checkout-draft', 'billing_email' => 'qa@client.example' ) );
WC()->session->data['store_api_draft_order'] = $bot_draft;
$o                 = wc_get_order( $bot_draft );
$o->data['status'] = 'processing';
$o->save();
cv_t_check( "the bot's draft becoming the real order is stamped", TEST_UUID === cv_t_stamp_of( $bot_draft ) );

// A sub-order a marketplace plugin creates for the bot's order.
cv_t_case();
$child = new CV_T_Order( array( 'created_via' => 'dokan', 'billing_email' => 'qa@client.example', 'parent_id' => $classic->get_id() ) );
$child->save();
cv_t_check( "a sub-order of the bot's order is stamped", TEST_UUID === cv_t_stamp_of( $child->get_id() ) );

/* ---- Other orders saved during the bot's request are not ---------------- */

// A real shopper's order that a plugin re-saves (status sync, inline cron).
cv_t_case();
$real = cv_t_existing( array() );
wc_get_order( $real )->save();
wc_get_order( $real )->save();
cv_t_check( "a real order re-saved during the bot's request is NOT stamped", null === cv_t_stamp_of( $real ) );
cv_t_check(
	'a skipped order is logged once per request',
	1 === count( array_filter( Checkview_Admin_Logs::$lines, fn( $l ) => false !== strpos( $l, 'Not stamping order [' . $real . ']' ) ) )
);

// A real order whose email is ANOTHER test's inbox.
cv_t_case();
$other_test = cv_t_existing( array( 'billing_email' => OTHER_UUID . '@test-mail.checkview.io' ) );
wc_get_order( $other_test )->save();
cv_t_check( "an order with another test's email is NOT stamped by this test", null === cv_t_stamp_of( $other_test ) );

// A subscription renewal created during the bot's request.
cv_t_case();
$renewal = new CV_T_Order( array( 'created_via' => 'subscription' ) );
$renewal->save();
cv_t_check( "a renewal order created during the bot's request is NOT stamped", null === cv_t_stamp_of( $renewal->get_id() ) );

// A REST-created order during the bot's request.
cv_t_case();
$rest = new CV_T_Order( array( 'created_via' => 'rest-api' ) );
$rest->save();
cv_t_check( 'an order created over REST during the request is NOT stamped', null === cv_t_stamp_of( $rest->get_id() ) );

// wc_create_order() leaves created_via empty.
cv_t_case();
$blank = new CV_T_Order( array( 'created_via' => '' ) );
$blank->save();
cv_t_check( 'an order created with no created_via is NOT stamped', null === cv_t_stamp_of( $blank->get_id() ) );

// A shopper's abandoned Block Checkout draft that a plugin turns into a real
// order during the bot's request: woocommerce_new_order fires for it.
cv_t_case();
$shopper_draft     = cv_t_existing( array( 'created_via' => 'store-api', 'status' => 'checkout-draft' ) );
$o                 = wc_get_order( $shopper_draft );
$o->data['status'] = 'pending';
$o->save();
cv_t_check( "a shopper's draft that becomes a real order during the request is NOT stamped", null === cv_t_stamp_of( $shopper_draft ) );

// A sub-order of a real order.
cv_t_case();
$real_child = new CV_T_Order( array( 'created_via' => 'dokan', 'parent_id' => $real ) );
$real_child->save();
cv_t_check( 'a sub-order of a real order is NOT stamped', null === cv_t_stamp_of( $real_child->get_id() ) );

// A sub-order of an order stamped by ANOTHER test.
cv_t_case();
$other_parent = cv_t_existing( array( 'meta' => array( 'checkview_test_id' => OTHER_UUID, 'payment_made_by' => 'checkview' ) ) );
$other_child  = new CV_T_Order( array( 'created_via' => 'dokan', 'parent_id' => $other_parent ) );
$other_child->save();
cv_t_check( "a sub-order of another test's order is NOT stamped by this test", null === cv_t_stamp_of( $other_child->get_id() ) );

// A plugin re-saves the bot's new order from inside woocommerce_new_order.
cv_t_case();
$GLOBALS['cv_t_on_new_order'] = function ( $o ) {
	$o->save();
};
$nested = new CV_T_Order( array( 'billing_email' => 'qa@client.example' ) );
$nested->save();
cv_t_check( 'a nested save inside woocommerce_new_order keeps one stamp for this test', TEST_UUID === cv_t_stamp_of( $nested->get_id() ) );
cv_t_check( 'the nested save adds no helper write (insert + the plugin re-save only)', 2 === $GLOBALS['cv_t_writes'][ $nested->get_id() ] );

// The insert fails once (WC swallows the exception), then the checkout retries.
cv_t_case();
$GLOBALS['cv_t_abort_next_write'] = true;
$retry = new CV_T_Order( array( 'created_via' => 'store-api', 'status' => 'checkout-draft', 'billing_email' => '' ) );
$retry->save();
cv_t_check( 'a failed insert writes nothing', 0 === $retry->get_id() );
$retry->save();
cv_t_check( 'the retried insert is stamped, in one write', TEST_UUID === cv_t_stamp_of( $retry->get_id() ) && 1 === $GLOBALS['cv_t_writes'][ $retry->get_id() ] );

// A new checkout order created in this request is this test's, whatever its email.
cv_t_case();
$foreign_mail = new CV_T_Order( array( 'billing_email' => OTHER_UUID . '@test-mail.checkview.io' ) );
$foreign_mail->save();
cv_t_check( 'a new checkout order in this request is stamped with THIS test id even with another test inbox', TEST_UUID === cv_t_stamp_of( $foreign_mail->get_id() ) );

// Many sub-orders re-saved in one request load their parent once.
cv_t_case();
$busy_parent = cv_t_existing( array() );
$kids        = array();
for ( $i = 0; $i < 5; $i++ ) {
	$kids[] = cv_t_existing( array( 'created_via' => 'dokan', 'parent_id' => $busy_parent ) );
}
foreach ( $kids as $kid ) {
	wc_get_order( $kid )->save();
}
cv_t_check( 'the parent of many re-saved sub-orders is loaded once per request', 1 === ( $GLOBALS['cv_t_loads'][ $busy_parent ] ?? 0 ) );
cv_t_check( 'none of those sub-orders is stamped', array() === array_filter( array_map( 'cv_t_stamp_of', $kids ) ) );

// A parent that this test stamps later in the request is not stuck as "not a test order".
cv_t_case();
$late_parent = cv_t_existing( array( 'created_via' => 'store-api', 'status' => 'checkout-draft', 'billing_email' => '' ) );
$late_child  = cv_t_existing( array( 'created_via' => 'dokan', 'parent_id' => $late_parent ) );
wc_get_order( $late_child )->save();
WC()->session->data['store_api_draft_order'] = $late_parent;
wc_get_order( $late_parent )->save();
wc_get_order( $late_child )->save();
cv_t_check( 'a sub-order is stamped once its parent is stamped later in the request', TEST_UUID === cv_t_stamp_of( $late_child ) );

// Round 3, scenario A: a sub-order is looked up while its parent has no stamp
// yet (CPT store, between the post insert and the meta write); the parent's
// stamp then lands outside the stamper, and the stamper sees it at first-wins.
cv_t_case();
$gap_parent = cv_t_existing( array( 'payment_method' => 'stripe', 'billing_email' => 'qa@client.example' ) );
$gap_child  = cv_t_existing( array( 'created_via' => 'dokan', 'parent_id' => $gap_parent ) );
wc_get_order( $gap_child )->save();
$GLOBALS['cv_t_db'][ $gap_parent ]['meta']['checkview_test_id'] = TEST_UUID;
wc_get_order( $gap_parent )->save();
wc_get_order( $gap_child )->save();
cv_t_check( 'a sub-order is stamped once its parent turns out to carry this test id (first-wins path)', TEST_UUID === cv_t_stamp_of( $gap_child ) );

/* ---- First wins --------------------------------------------------------- */

cv_t_case();
$prior = cv_t_existing( array( 'payment_method' => 'checkview', 'meta' => array( 'checkview_test_id' => OTHER_UUID ) ) );
wc_get_order( $prior )->save();
cv_t_check( "an order already stamped by another test keeps that test's id", OTHER_UUID === cv_t_stamp_of( $prior ) );

/* ---- Summary ------------------------------------------------------------ */

$GLOBALS['cv_t_done'] = true;
echo "\n$cv_t_count checks, $cv_t_failures failed\n";
exit( $cv_t_failures ? 1 : 0 );
