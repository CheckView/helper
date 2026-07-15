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
| Status | Published |

The product ID is stored in `wp_options` as `checkview_woo_product_id`.

## Creation

`checkview_create_test_product()` runs on `admin_init` at priority 200. It:

1. Checks `checkview_woo_product_id` option for an existing product.
2. Falls back to a `WP_Query` by name ("CheckView Testing Product").
3. If found in trash, auto-untrashes it.
4. If not found at all, creates a new `WC_Product` with the properties above.

## Deletion protection

- `trashed_post` (priority 20): immediately calls `wp_untrash_post()` if the trashed ID matches the stored product ID.
- `after_delete_post`: if the product is permanently deleted, removes the `checkview_woo_product_id` option so it can be recreated on next `admin_init`.

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

Inside `checkview_test_mode()`, the product is forced visible via the `woocommerce_product_is_visible` filter so the test runner can add it to cart and complete checkout.

## API endpoints

- **Get test product**: returns the product permalink (used by the SaaS to navigate the test runner to the product page).
- **Update product status**: toggles between `publish` and `draft` via `checkview_update_woocommerce_product_status()`.
