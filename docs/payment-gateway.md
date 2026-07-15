# CheckView Payment Gateway

CheckView registers a $0 dummy payment gateway so automated checkout tests complete without charging real payment processors.

## Architecture

Two gateway classes cover both WooCommerce checkout modes:

| Class | Checkout type | Extends |
|---|---|---|
| `Checkview_Payment_Gateway` | Classic (shortcode) checkout | `WC_Payment_Gateway` |
| `Checkview_Blocks_Payment_Gateway` | Block-based checkout | `AbstractPaymentMethodType` |

Both use gateway ID `checkview`.

## Registration

Gateways are only registered during active test sessions. The flow:

1. `Checkview_Woo_Automated_Testing::__construct()` calls `checkview_test_mode()`.
2. `checkview_test_mode()` checks `CheckView::is_bot()` and `test_type()` (must be `full_checkout` or `woo_checkout`). If either fails, it returns early and gateways are never registered.
3. If the checks pass:
   - Classic gateway: `class-checkview-payment-gateway.php` is `require`d, then added via `woocommerce_payment_gateways` filter at priority 11.
   - Blocks gateway: registered on `woocommerce_blocks_loaded` via `woocommerce_blocks_payment_method_type_registration`.

## Availability (defense-in-depth)

Even after registration, both gateways re-verify bot status on every availability check:

- **Classic**: `is_available()` calls `CheckView::is_bot()`. Returns false if not a bot, preventing the gateway from appearing on a real shopper's checkout.
- **Blocks**: `is_active()` calls `CheckView::is_bot()`. Same behavior.

This double-check exists because WooCommerce caches gateway objects and re-evaluates availability multiple times per request.

## Payment Processing (Classic)

`process_payment($order_id)`:

1. Creates `WC_Order` from the order ID.
2. Sets order status to `completed`.
3. Calls `$order->payment_complete()`.
4. Empties the cart.
5. Returns success with redirect to the thank-you page.

No actual payment processor is contacted.

## Blocks JS

The block checkout gateway requires a JS registration file at `assets/js/frontend/blocks.js`. This registers the `checkview` payment method with WooCommerce's block checkout React components. Asset metadata is in `assets/js/frontend/blocks.asset.php`.

## Stripe Test Mode

When the CheckView gateway is active, `checkview_test_mode()` also forces Stripe into test mode via two filters:

- `option_woocommerce_stripe_settings` -- sets `testmode` to `yes`
- `wc_stripe_mode` -- sets mode to `test`

This ensures that if Stripe is selected instead of the CheckView gateway (e.g., for Stripe-specific tests via `checkview_use_stripe`), it uses test keys.
