# CheckView Test Product

CheckView creates a hidden WooCommerce product so automated checkout tests have something to purchase.

## Properties

| Field | Value |
|---|---|
| Name | CheckView Testing Product |
| Regular price | $1.00 |
| Stock status | In stock (qty 5) |
| Catalog visibility | Hidden |
| Weight | 1 oz |
| Dimensions | 1x1x1 |
| Status | Created as a draft; CheckView publishes it before its first Woo setup or run, and it stays published after that |
| Slug | `checkview-testing-product` (set explicitly so the URL works while it is a draft) |

The product ID is stored in `wp_options` as `checkview_woo_product_id`.

## Creation

The product is created on demand, only when CheckView needs it: `checkview_ensure_test_product()` runs from the products list (`/store/products`), `/store/getstoretestproduct` and `/set-status` REST routes. Nothing creates it on `admin_init`, so a store that never runs Woo tests never gets one. It:

1. Checks `checkview_woo_product_id` option for an existing product.
2. Falls back to `wc_get_products()` by name ("CheckView Testing Product"), including drafts and trashed products.
3. If found in trash, auto-untrashes it.
4. If not found at all, creates a new `WC_Product` with the properties above, as a draft. The option `checkview_test_product_creating` stops parallel requests from each creating one; the others wait up to 5 seconds for the first to finish.

`checkview_test_product_permalink()` returns the product's normal permalink even while it is a draft (WordPress would otherwise give `/?post_type=product&p=ID`), because CheckView stores and visits the published URL.

## Publishing for a run

The SaaS calls `/set-status` with `publish` before each Woo flow setup and test run (`add_to_cart` and `full_checkout` flows). The helper records the time in `checkview_test_product_published_at`. Because the SaaS ignores a failed `/set-status` call, a signed `full_checkout` / `woo_checkout` test request also publishes a drafted product on `wp_loaded` (`checkview_publish_test_product_for_run()`); it never creates one.

## Drafting the product older helpers published

Helpers before 2.6.2 created the product published, on every WooCommerce site. `checkview_maybe_draft_legacy_test_product()` runs on `admin_init` (priority 200) and drafts it once:

- It looks only for a published product, so a product the store owner trashed is not untrashed.
- If `checkview_test_product_published_at` is missing (a run started under an older helper), it records the time and waits. It drafts only once 30 minutes have passed since CheckView last published it, so a run in progress keeps the product.
- When done, or when there is no published product, it sets `checkview_test_product_draft_done`.

## Deletion protection

- `trashed_post` (priority 20): immediately calls `wp_untrash_post()` if the trashed ID matches the stored product ID.
- `after_delete_post`: if the product is permanently deleted, removes the `checkview_woo_product_id` option so it is recreated (as a draft) the next time CheckView asks for it.

## Visibility suppression

The product is set to `catalog_visibility: hidden`, which WooCommerce respects in its own shop/category queries. Additional suppression handles cases WooCommerce doesn't cover:

| Surface | Hook | Effect |
|---|---|---|
| Query Loop blocks | `query_loop_block_query_vars` | Adds product to `post__not_in` |
| Yoast sitemap | `wpseo_exclude_from_sitemap_by_post_ids` | Excludes from XML sitemap |
| WP core sitemap | `wp_sitemaps_posts_query_args` | Adds product to `post__not_in` |
| Jetpack Publicize | `publicize_should_publicize_published_post` | Returns false for test product |
| Search engines | `wp_head` | Outputs `noindex, nofollow` meta on product page |
| WC product visibility | `woocommerce_product_is_visible` (priority 9999) | Forces visible only during active test checkout, hidden otherwise |

## During test checkout

Inside `checkview_test_mode()`, the product is forced visible via the `woocommerce_product_is_visible` filter so the test runner can add it to cart and complete checkout, and a drafted product is published (see "Publishing for a run").

## API endpoints

- **Get test product** (`/store/getstoretestproduct`): returns the product permalink, creating the product as a draft if missing.
- **Update product status** (`/set-status`): toggles between `publish` and `draft` via `checkview_update_woocommerce_product_status()`, creating the product as a draft if missing. A `publish` records `checkview_test_product_published_at`.
- **Products list** (`/store/products`): includes the test product, creating it as a draft if missing.
