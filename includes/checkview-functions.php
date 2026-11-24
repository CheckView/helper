<?php
/**
 * Common CheckView Functions
 *
 * Various common functions used throughout CheckView.
 *
 * @since 1.0.0
 *
 * @package Checkview
 * @subpackage Checkview/includes
 */

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die( 'Direct access not Allowed.' );
}

if ( ! function_exists( 'checkview_dbdelta_or_query' ) ) {
	/**
	 * Runs a CREATE TABLE statement via dbDelta() when safe, otherwise falls
	 * back to a direct $wpdb->query().
	 *
	 * WordPress core ships two files that both unconditionally declare
	 * maybe_create_table() and maybe_add_column(): wp-admin/install-helper.php
	 * and wp-admin/includes/upgrade.php. If another plugin (or a core upgrade
	 * flow) has already loaded install-helper.php, our own require_once of
	 * upgrade.php triggers a fatal "Cannot redeclare maybe_create_table()".
	 *
	 * Detect the conflict by checking for maybe_create_table() — not dbDelta()
	 * — and fall back to a direct query so the SQL still runs.
	 *
	 * Pass literal SQL only. The fallback path skips $wpdb->prepare(); user
	 * input must not reach $sql.
	 *
	 * The fallback skips dbDelta's schema-diff, so ALTER-style migrations
	 * (adding columns / keys to an existing table) must be written as
	 * explicit ALTER TABLE statements instead of relying on this helper.
	 *
	 * Callers may pass `CREATE TABLE IF NOT EXISTS` for race-safety in the
	 * raw fallback path; the helper strips `IF NOT EXISTS` before handing the
	 * SQL to dbDelta(), because dbDelta's table-name regex captures the first
	 * whitespace-delimited token after `CREATE TABLE` and would otherwise
	 * extract the literal string "IF", silently bypassing its schema-diff.
	 *
	 * @since 2.1.0
	 *
	 * @param string $sql CREATE TABLE statement.
	 * @return mixed dbDelta() result array, or $wpdb->query() return value.
	 */
	function checkview_dbdelta_or_query( $sql ) {
		global $wpdb;
		// Strip IF NOT EXISTS for the dbDelta path only. See docblock above
		// — dbDelta's regex would otherwise treat "IF" as the table name.
		$dbdelta_sql = preg_replace(
			'/^(\s*CREATE\s+TABLE\s+)IF\s+NOT\s+EXISTS\s+/i',
			'$1',
			$sql
		);
		if ( function_exists( 'dbDelta' ) ) {
			return dbDelta( $dbdelta_sql );
		}
		if ( ! function_exists( 'maybe_create_table' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			return dbDelta( $dbdelta_sql );
		}
		// install-helper.php was loaded first by another plugin or core
		// upgrade routine; requiring upgrade.php now would fatal. Keep
		// IF NOT EXISTS in the raw $sql so the fallback stays race-safe.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$result = $wpdb->query( $sql );
		if ( false === $result && ! empty( $wpdb->last_error ) ) {
			// MySQL errors can contain newlines (multi-line diagnostics);
			// flatten so a single log entry stays on one line.
			$flat = preg_replace( '/[\r\n\0]+/', ' ', (string) $wpdb->last_error );
			$msg  = 'checkview_dbdelta_or_query fallback failed: ' . $flat;
			if ( class_exists( 'Checkview_Admin_Logs', false ) ) {
				Checkview_Admin_Logs::add( 'api-logs', $msg );
			} else {
				// Checkview_Admin_Logs isn't loaded yet (e.g. during
				// activation). Surface to PHP's error log so the failure
				// isn't silently swallowed.
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Desired production debug logging.
				error_log( '[CheckView] ' . $msg );
			}
		}
		return $result;
	}
}

if ( ! function_exists( 'checkview_ensure_trailing_slash' ) ) {
	/**
	 * Ensures a string ends with a trailing slash.
	 *
	 * @since 2.0.13
	 *
	 * @param string $value String to ensure trailing slash.
	 * @return string
	 */
	function checkview_ensure_trailing_slash( $value ) {
		return rtrim( $value, '/' ) . '/';
	}
}

if ( ! function_exists( 'checkview_validate_jwt_token' ) ) {
	/**
	 * Validates a JWT.
	 *
	 * When a valid JWT is given, a nonce is returned. If a bad token is given,
	 * a `WP_Error` will be returned. If there is an issue with the determined
	 * token, returns false.
	 *
	 * @since 1.0.0
	 *
	 * @param string $token JWT to valiate.
	 * @param bool   $log   Whether to write a log line when the token is refused.
	 *                      Since 2.6.3. A replacement of this function must
	 *                      honour it: the application password opt-out passes
	 *                      false so made-up tokens cannot flood the log.
	 * @return string|bool|WP_Error
	 */
	function checkview_validate_jwt_token( $token, $log = true ) {

		$key = checkview_get_publickey();
		// Ensure the header is present.
		if ( ! $token ) {
			if ( $log ) {
				Checkview_Admin_Logs::add( 'api-logs', 'Authorization header not found.' );
			}
			return new WP_Error(
				'no_auth_header',
				'There was a technical error while processing your request.',
				array( 'status' => 401 )
			);
		}

		// Check if it contains a Bearer token.
		if ( strpos( $token, 'Bearer ' ) !== 0 ) {
			if ( $log ) {
				Checkview_Admin_Logs::add( 'api-logs', 'Authorization header must start with "Bearer "' );
			}
			return new WP_Error(
				'invalid_auth_header',
				'There was a technical error while processing your request.',
				array( 'status' => 401 )
			);
		}

		// Remove "Bearer " prefix.
		$token = substr( $token, 7 );

		// Attempt decoding.
		try {
			/**
			 * Filter: Leeway, in seconds, for JWT tokens.
			 *
			 * @since 2.0.24
			 */
			$leeway = apply_filters( 'checkview_jwt_leeway', 5 );

			JWT::$leeway = $leeway;
			$decoded     = JWT::decode( $token, new Key( $key, 'RS256' ) );
		} catch ( \Throwable $e ) {
			if ( $log ) {
				Checkview_Admin_Logs::add( 'api-logs', esc_html( $e->getMessage() ) );
			}
			return new WP_Error(
				'invalid_auth_header',
				'There was a technical error while processing your request.',
				array( 'status' => 401 )
			);
		}
		$jwt              = (array) $decoded;
		$default_site_url = checkview_ensure_trailing_slash( get_bloginfo( 'url' ) );

		/**
		 * Filter: Allows sites with custom URL structures to modify the URL the JWT is checked against.
		 *
		 * @since 2.0.21
		 *
		 * @param string $default_site_url Default site URL.
		 */
		$site_url = apply_filters( 'checkview_site_url', $default_site_url );

		// If a URL mismatch, return false.
		if ( false === strpos( $site_url, checkview_ensure_trailing_slash( $jwt['websiteUrl'] ) ) ) {
			if ( $log ) {
				Checkview_Admin_Logs::add( 'api-logs', 'Invalid site url.' );
			}
			return false;
		}

		// If token expired, return false.
		if ( $jwt['exp'] < time() ) {
			if ( $log ) {
				Checkview_Admin_Logs::add( 'api-logs', 'Token Expired.' );
			}
			return false;
		}

		// Reject tokens without a nonce.
		if ( empty( $jwt['_checkview_nonce'] ) ) {
			if ( $log ) {
				Checkview_Admin_Logs::add( 'api-logs', 'Nonce Absent.' );
			}
			return false;
		}

		// Return determined token.
		return $jwt['_checkview_nonce'];
	}
}

if ( ! function_exists( 'checkview_get_test_id' ) ) {
	/**
	 * Gets a test ID from the request.
	 *
	 * Determine a test ID from the `$_REQUEST` superglobal, or from the
	 * referrer. Returns `false` if the test ID is determined to be invalid.
	 *
	 * @return string|false Test ID, or `false` on failure.
	 */
	function checkview_get_test_id() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only CheckView test param from SaaS request; no nonce possible.
		$cv_test_id = isset( $_REQUEST[ CheckView::PARAM_TEST_ID ] ) ? sanitize_text_field( wp_unslash( $_REQUEST[ CheckView::PARAM_TEST_ID ] ) ) : '';

		if ( ! empty( $cv_test_id ) ) {
			if ( ! checkview_is_valid_uuid( $cv_test_id ) ) {
				return false;
			}
			return $cv_test_id;
		}

		// Fallback: check referer URL for test ID (covers AJAX submissions).
		$ref_test_id = CheckView::get_referrer_test_id();
		if ( ! empty( $ref_test_id ) ) {
			return $ref_test_id;
		}

		// Fallback: check cookie for test ID (covers multi-step forms where
		// page transitions lose the query parameter from the URL).
		// This cookie is set server-side by WordPress in checkview_init_current_test.
		if ( isset( $_COOKIE[ CheckView::PARAM_TEST_ID ] ) ) {
			$cookie_test_id = sanitize_text_field( wp_unslash( $_COOKIE[ CheckView::PARAM_TEST_ID ] ) );
			if ( ! empty( $cookie_test_id ) && checkview_is_valid_uuid( $cookie_test_id ) ) {
				return $cookie_test_id;
			}
		}

		return false;
	}
}

if ( ! function_exists( 'checkview_complete_test' ) ) {
	/**
	 * Concludes a test.
	 *
	 * Given a test ID, deletes the test's options/flags, and deletes test
	 * session data from the database. Clears test cookies.
	 *
	 * @param string $checkview_test_id Test ID.
	 * @return void
	 */
	function checkview_complete_test( string $checkview_test_id = '' ) {
		global $wpdb;

		Checkview_Admin_Logs::add( 'ip-logs', 'Completing test...' );

		if ( ! defined( 'CHECKVIEW_TEST_ID' ) ) {
			define( 'CHECKVIEW_TEST_ID', $checkview_test_id );
		}

		$session_table = $wpdb->prefix . 'cv_session';
		$visitor_ip    = checkview_get_visitor_ip();
		$cv_session    = checkview_get_cv_session( $visitor_ip, CHECKVIEW_TEST_ID );

		// Stop if session not found.
		if ( ! empty( $cv_session ) ) {
			$test_key = $cv_session[0]['test_key'];

			checkview_delete_option( $test_key );
		}

		$result = $wpdb->delete(
			$session_table,
			array(
				'visitor_ip' => $visitor_ip,
				'test_id'    => $checkview_test_id,
			)
		);

		// $wpdb->delete returns false on real DB error, 0 if no matching rows
		// (already-cleaned-up case, e.g. shutdown handler firing after a sync
		// caller already cleaned up). Only log false (the actual failure mode).
		if ( false === $result ) {
			Checkview_Admin_Logs::add( 'ip-logs', 'Failed to delete rows from session table [' . $session_table . '].' );
		}

		$entry_id = get_option( $checkview_test_id . '_wsf_entry_id', '' );
		$form_id  = get_option( $checkview_test_id . '_wsf_frm_id', '' );

		// Skip WS Form db_delete when running in shutdown context — WS Form's
		// plugin state (DB layer, caches) may already be torn down at shutdown
		// and db_delete can fail unsafely. Sync form-helper callers (which run
		// during their submission hooks, not shutdown) still execute this
		// branch. WS Form cleanup is irrelevant for Woo-flow shutdown calls
		// anyway since Woo orders aren't WS Form entries.
		//
		// Wrapped in try/catch so a throw inside WS Form's internals (rare,
		// but possible during plugin update windows or HPOS migration) does
		// NOT short-circuit the per-test-option deletes below at lines 250-252.
		// Without this catch, the disable_*_<uuid> options would leak.
		if ( ! empty( $form_id ) && ! empty( $entry_id ) && ! doing_action( 'shutdown' ) ) {
			if ( class_exists( 'WS_Form_Submit' ) ) {
				try {
					$ws_form_submit          = new WS_Form_Submit();
					$ws_form_submit->id      = $entry_id;
					$ws_form_submit->form_id = $form_id;
					$ws_form_submit->db_delete( true, true, true );
				} catch ( \Throwable $e ) {
					Checkview_Admin_Logs::add(
						'ip-logs',
						'WS_Form_Submit db_delete threw — continuing cleanup. Message: ' . $e->getMessage()
					);
				}
			} else {
				Checkview_Admin_Logs::add( 'ip-logs', 'WS_Form_Submit class does not exist.' );
			}
		}

		checkview_delete_option( $checkview_test_id . '_wsf_entry_id' );
		checkview_delete_option( $checkview_test_id . '_wsf_frm_id' );

		// setcookie() silently fails when headers have been sent (which is the
		// norm at shutdown time, the new deferred caller). Form-helper sync
		// callers run during their submission hooks where headers aren't yet
		// flushed, so the guard lets cookie cleanup work for them while
		// avoiding silent failures at shutdown.
		if ( ! headers_sent() ) {
			setcookie( CheckView::PARAM_TEST_ID, '', time() - 6600, COOKIEPATH, COOKIE_DOMAIN );
			setcookie( CheckView::PARAM_TEST_ID . $checkview_test_id, '', time() - 6600, COOKIEPATH, COOKIE_DOMAIN );
			setcookie( CheckView::PARAM_TEST_TYPE, '', time() - 6600, COOKIEPATH, COOKIE_DOMAIN );
		}

		checkview_delete_option( 'disable_actions_' . $checkview_test_id );
		checkview_delete_option( 'disable_email_receipt_' . $checkview_test_id );
		checkview_delete_option( 'disable_webhooks_' . $checkview_test_id );
		checkview_delete_option( 'allow_original_recipients_' . $checkview_test_id );
		checkview_delete_option( 'allow_original_recipients_set_at_' . $checkview_test_id );

		// Legacy cleanup: remove global options from before per-test scoping.
		checkview_delete_option( 'disable_actions' );
		checkview_delete_option( 'disable_email_receipt' );
		checkview_delete_option( 'disable_webhooks' );
		checkview_delete_option( 'allow_original_recipients' );
		checkview_delete_option( 'allow_original_recipients_set_at' );

		// Legacy cleanup: remove orphaned captcha backup keys from old code.
		checkview_delete_option( 'checkview_wpforms_turnstile-site-key' );
		checkview_delete_option( 'checkview_wpforms_turnstile-secret-key' );

		Checkview_Admin_Logs::add( 'ip-logs', 'Test complete.' );
	}
}

if ( ! function_exists( 'checkview_is_suppressible_test_order' ) ) {
	/**
	 * Determines whether webhooks/actions on a WooCommerce order should be
	 * suppressed because the order belongs to an active CheckView test.
	 *
	 * Implements the safety invariant: suppression engages only when BOTH
	 *   (1) the order has a valid `checkview_test_id` UUID meta (set by
	 *       `checkview_stamp_order_meta` at order creation time when
	 *       `CHECKVIEW_TEST_ID` was defined), AND
	 *   (2) a per-test option (`disable_webhooks_<uuid>` or
	 *       `disable_actions_<uuid>`) is still set to `'true'` (test is still
	 *       active in our system; cleanup happens at request end via
	 *       `checkview_complete_test`).
	 *
	 * Either condition missing → fall through, deliver the webhook / fire the
	 * action. Default-on safety, not default-off.
	 *
	 * Reusable by:
	 *   - the WooCommerce webhook filter (`checkview_filter_webhooks`)
	 *   - the Mailchimp per-order gate
	 *     (`checkview_mailchimp_should_push_order`, hooked to MC for WC's
	 *     `mailchimp_should_push_order` filter in `mailchimp_handle_or_queue()`)
	 *   - any future addon gate that needs the same invariant
	 *
	 * Includes a kill-switch short-circuit at the top: if the
	 * `cv_suppression_kill_switch` option is set to `'true'` (via WP-CLI for
	 * incident response), this function returns false immediately and no
	 * suppression engages anywhere.
	 *
	 * @param mixed $order WC_Order, order ID (int), or numeric string.
	 *                     Webhook delivery callers pass `$arg` which can be
	 *                     either int or string depending on serialisation.
	 *
	 * @return bool True if suppression should engage, false otherwise.
	 */
	function checkview_is_suppressible_test_order( $order ) {
		// H8 kill switch — incident response escape hatch.
		if ( get_option( 'cv_suppression_kill_switch' ) === 'true' ) {
			return false;
		}

		// Coerce $order if int OR numeric string (WC webhook delivery passes
		// $arg as either, depending on caller — JSON-decoded order IDs can
		// arrive as strings under certain serialisation paths).
		if ( ! is_object( $order ) ) {
			if ( is_numeric( $order ) ) {
				$order = wc_get_order( $order );
			} else {
				return false; // Not an order ID we can resolve.
			}
		}
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_meta' ) ) {
			return false;
		}

		// Invariant 1: order has a valid checkview_test_id UUID meta.
		$test_id = $order->get_meta( CheckView::PARAM_TEST_ID );
		if ( ! $test_id || ! checkview_is_valid_uuid( $test_id ) ) {
			return false;
		}

		// Invariant 2: per-test option still active (either flag triggers
		// suppression — matches the SaaS-side single-toggle design where one
		// flag sends both query params and both options get set).
		$suppress = ( get_option( 'disable_webhooks_' . $test_id ) === 'true' )
				|| ( get_option( 'disable_actions_' . $test_id ) === 'true' );

		$order_id = (int) $order->get_id();

		if ( ! $suppress ) {
			// Gate-failed log (deduped per order per request) — helps debug
			// "I configured Disable Actions but it's not suppressing"
			// support tickets without spamming on real-customer orders that
			// won't have the meta in the first place.
			static $logged_failures = array();
			if ( ! isset( $logged_failures[ $order_id ] ) ) {
				Checkview_Admin_Logs::add(
					'ip-logs',
					"Suppress gate failed for order #{$order_id} (test {$test_id}): no active suppression option found"
				);
				$logged_failures[ $order_id ] = true;
			}
			return false;
		}

		// Suppression approved. Log as intentional (compliance audit angle:
		// distinguishable from a delivery failure). Deduped per order
		// per request.
		static $logged_suppress = array();
		if ( ! isset( $logged_suppress[ $order_id ] ) ) {
			Checkview_Admin_Logs::add(
				'ip-logs',
				"SUPPRESS for test order #{$order_id} (test {$test_id}) — intentional, per Disable Actions setting"
			);
			$logged_suppress[ $order_id ] = true;
		}
		return true;
	}
}

if ( ! function_exists( 'checkview_acquire_fetch_lock' ) ) {
	/**
	 * Takes a short lock so only one request at a time fetches from the SaaS.
	 *
	 * A single-row insert that only one request can win. `add_option()` is not
	 * a lock: it reports success to a second request whenever the stored
	 * value differs. A lock older than `$ttl` seconds is taken over, so a
	 * request killed mid-fetch cannot hold it.
	 *
	 * @since 2.6.2
	 *
	 * @param string $name Lock name.
	 * @param int    $ttl  Seconds after which the lock is considered abandoned.
	 * @return string|false A token to release the lock with, `''` when the
	 *                      database failed (fetch anyway, without a lock), or
	 *                      false when another request holds it.
	 */
	function checkview_acquire_fetch_lock( $name, $ttl ) {
		global $wpdb;

		$option = 'checkview_fetch_lock_' . $name;
		// Whole seconds first, so CAST(... AS UNSIGNED) reads the age the same
		// way on MySQL and MariaDB; the microseconds keep tokens unique.
		$now   = microtime( true );
		$token = sprintf( '%d-%06d', (int) $now, (int) ( ( $now - (int) $now ) * 1000000 ) );

		// A live lock is the common case while a fetch runs; spot it with a read
		// so the requests that lose do not write.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Lock row; never read through the options API.
		$held = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $option ) );
		if ( null !== $held && (int) $held >= time() - $ttl ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic lock; never read through the options API.
		$inserted = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $option, $token ) );
		if ( false === $inserted ) {
			Checkview_Admin_Logs::add( 'ip-logs', 'Could not take the fetch lock [' . $name . ']: database error. Fetching without it.' );
			return '';
		}
		if ( 1 === (int) $inserted ) {
			return $token;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic takeover of an abandoned lock.
		$taken = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND CAST(option_value AS UNSIGNED) < %d", $token, $option, time() - $ttl ) );
		if ( false === $taken ) {
			Checkview_Admin_Logs::add( 'ip-logs', 'Could not take the fetch lock [' . $name . ']: database error. Fetching without it.' );
			return '';
		}
		return 1 === (int) $taken ? $token : false;
	}
}
if ( ! function_exists( 'checkview_release_fetch_lock' ) ) {
	/**
	 * Releases a lock taken with `checkview_acquire_fetch_lock()`, only if this
	 * request still holds it (another may have taken it over).
	 *
	 * @since 2.6.2
	 *
	 * @param string $name  Lock name.
	 * @param string $token Token returned by `checkview_acquire_fetch_lock()`.
	 * @return void
	 */
	function checkview_release_fetch_lock( $name, $token ) {
		global $wpdb;
		if ( '' === $token ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Releases this request's lock row.
		$wpdb->delete(
			$wpdb->options,
			array(
				'option_name'  => 'checkview_fetch_lock_' . $name,
				'option_value' => $token,
			)
		);
	}
}
if ( ! function_exists( 'checkview_last_good_copy' ) ) {
	/**
	 * Reads or writes the last value fetched successfully from the SaaS.
	 *
	 * Kept in an autoloaded option, so reading it costs no query. A copy older
	 * than seven days is no longer returned: a site that cannot reach the SaaS
	 * for that long stops trusting what it last saw.
	 *
	 * @since 2.6.2
	 *
	 * @param string   $name       Copy name (`ip_address` or `pk`).
	 * @param mixed    $value      Value to store, or null to read.
	 * @param int|null $fetched_at When the value was fetched (default now).
	 * @return mixed The stored value, or null if there is none or it is too old.
	 */
	function checkview_last_good_copy( $name, $value = null, $fetched_at = null ) {
		$option = 'checkview_saas_' . $name . '_last_good';
		if ( null !== $value ) {
			update_option(
				$option,
				array(
					'value'      => $value,
					'fetched_at' => null === $fetched_at ? time() : (int) $fetched_at,
				),
				true
			);
			// Once a copy has been written, a warm cache never seeds it again.
			update_option( 'checkview_saas_' . $name . '_seeded', 1, true );
			return $value;
		}

		$copy = get_option( $option, null );
		if ( null === $copy ) {
			return null;
		}
		if ( ! is_array( $copy ) || ! isset( $copy['value'], $copy['fetched_at'] ) ) {
			// Not in this format (an earlier development build): drop it so a
			// warm cache seeds a proper copy.
			delete_option( $option );
			delete_option( 'checkview_saas_' . $name . '_seeded' );
			return null;
		}
		if ( (int) $copy['fetched_at'] < time() - 7 * DAY_IN_SECONDS ) {
			return null;
		}
		return $copy['value'];
	}
}
if ( ! function_exists( 'checkview_seed_last_good_copy' ) ) {
	/**
	 * Seeds the last good copy from a still-warm cache, once per site, so
	 * sites updated from an older helper have a fallback before their first
	 * fetch. Its age is taken as 12 hours, the longest the cache can be.
	 *
	 * @since 2.6.2
	 *
	 * @param string $name  Copy name (`ip_address` or `pk`).
	 * @param mixed  $value Cached value.
	 * @return void
	 */
	function checkview_seed_last_good_copy( $name, $value ) {
		if ( get_option( 'checkview_saas_' . $name . '_seeded' ) ) {
			$copy = get_option( 'checkview_saas_' . $name . '_last_good', null );
			// Missing (withdrawn on purpose), or current and not expired: done.
			if ( null === $copy || ( is_array( $copy ) && isset( $copy['value'], $copy['fetched_at'] ) && (int) $copy['fetched_at'] >= time() - 7 * DAY_IN_SECONDS ) ) {
				return;
			}
			// Expired (e.g. after a downgrade and re-upgrade) or from an
			// earlier development build: replace it below.
		}
		checkview_last_good_copy( $name, $value, time() - 12 * HOUR_IN_SECONDS );
	}
}
if ( ! function_exists( 'checkview_get_publickey' ) ) {
	/**
	 * Gets the SaaS' public key.
	 *
	 * Cached for 12 hours. When the cache is empty, one request at a time asks
	 * the SaaS, with a short timeout. A failed request is not retried for a
	 * minute, and meanwhile the last key fetched successfully (up to seven days
	 * old) is used, so a slow or unreachable SaaS cannot stall the requests
	 * that verify a signature.
	 *
	 * @since 1.0.0
	 *
	 * @return string The public key, or an empty string if none is known.
	 */
	function checkview_get_publickey() {
		$public_key = get_transient( 'checkview_saas_pk' );
		if ( is_string( $public_key ) && '' !== $public_key ) {
			checkview_seed_last_good_copy( 'pk', $public_key );
			return $public_key;
		}

		$last_good = (string) checkview_last_good_copy( 'pk' );
		if ( get_transient( 'checkview_saas_pk_fetch_failed' ) ) {
			return $last_good;
		}
		$lock = checkview_acquire_fetch_lock( 'pk', 45 );
		if ( false === $lock ) {
			return $last_good;
		}
		// Another request may have finished meanwhile. Where transients are
		// options, forget this request's remembered misses so the re-read
		// reaches the database.
		if ( ! wp_using_ext_object_cache() ) {
			wp_cache_delete( 'notoptions', 'options' );
		}
		$public_key = get_transient( 'checkview_saas_pk' );
		if ( get_transient( 'checkview_saas_pk_fetch_failed' ) || ( is_string( $public_key ) && '' !== $public_key ) ) {
			checkview_release_fetch_lock( 'pk', $lock );
			return is_string( $public_key ) && '' !== $public_key ? $public_key : $last_good;
		}

		$response   = wp_safe_remote_get(
			'https://app.checkview.io/api/helper/public_key',
			array(
				'method'              => 'GET',
				'timeout'             => 5,
				'redirection'         => 1,
				'limit_response_size' => 256 * KB_IN_BYTES,
			)
		);
		$public_key = is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response )
			? ''
			: (string) wp_remote_retrieve_body( $response );

		if ( false === strpos( $public_key, 'BEGIN PUBLIC KEY' ) ) {
			set_transient( 'checkview_saas_pk_fetch_failed', 1, MINUTE_IN_SECONDS );
			checkview_release_fetch_lock( 'pk', $lock );
			Checkview_Admin_Logs::add( 'api-logs', 'Could not fetch the CheckView public key; ' . ( '' !== $last_good ? 'using the last one fetched.' : 'none known.' ) );
			return $last_good;
		}

		set_transient( 'checkview_saas_pk', $public_key, 12 * HOUR_IN_SECONDS );
		checkview_last_good_copy( 'pk', $public_key );
		checkview_release_fetch_lock( 'pk', $lock );
		return $public_key;
	}
}
if ( ! function_exists( 'checkview_get_api_ip' ) ) {
	/**
	 * Gets the SaaS' IP addresses.
	 *
	 * Cached for 12 hours. When the cache is empty, one request at a time asks
	 * the SaaS, with a short timeout. A failed or invalid response is not
	 * retried for five minutes, and meanwhile the last list fetched
	 * successfully (up to seven days old) is used. It runs in test requests and
	 * in an hourly WP-Cron event (ordinary visitors' requests skip it), so a
	 * slow or unreachable SaaS must never make a request wait long on it.
	 *
	 * @since 1.0.0
	 *
	 * @return string[]|null The IPs plus the IPv6 loopback, or `null` if no
	 *                       list is known (or the SaaS lists none).
	 */
	function checkview_get_api_ip() {
		$ip_address = get_transient( 'checkview_saas_ip_address' );

		if ( false === $ip_address ) {
			$ip_address = checkview_fetch_api_ip();
		} elseif ( ! empty( $ip_address ) ) {
			checkview_seed_last_good_copy( 'ip_address', array_values( (array) $ip_address ) );
		}

		if ( empty( $ip_address ) ) {
			return null;
		}

		$ip_address = (array) $ip_address;

		// Loopback only. Do NOT hardcode public IPs here. This allowlist is
		// matched against client-supplied forwarding headers (X-Forwarded-For,
		// etc.) in checkview_get_visitor_ip(), so any hardcoded public IP
		// becomes a permanent, unauthenticated bypass of CheckView::is_bot()
		// — and therefore of WooCommerce "test mode" and its $0 checkout
		// gateway. Trusted bot IPs must come only from the authenticated SaaS
		// whitelist (verify.checkview.io).
		$ip_address[] = '::1';

		return $ip_address;
	}
}
if ( ! function_exists( 'checkview_refresh_saas_ip_list' ) ) {
	/**
	 * Hourly WP-Cron refresh of the SaaS IP allowlist.
	 *
	 * Ordinary visitors' requests no longer read the allowlist, so without this
	 * only test requests would refresh it. Reading it here downloads a new list
	 * when the 12-hour copy has run out and keeps the last good copy recent, so
	 * a test still has a list if the download fails at that moment. Runs as a
	 * normal request, so the copy lands in the same store (options table or
	 * persistent object cache) that test requests read.
	 *
	 * @since 2.6.2
	 *
	 * @return void
	 */
	function checkview_refresh_saas_ip_list() {
		checkview_get_api_ip();
	}
}
if ( ! function_exists( 'checkview_fetch_api_ip' ) ) {
	/**
	 * Requests the SaaS' IP addresses, unless a request failed in the last
	 * five minutes or another request is already fetching them. Called only
	 * on a cache miss; the outcome is reused for the rest of the request.
	 *
	 * On success the list is cached for 12 hours and kept as the last good
	 * list. A well-formed empty list (`"ipAddresses": []`) withdraws every IP:
	 * it is cached for five minutes, so an accidental empty answer heals
	 * quickly, and the last good list is dropped. Anything else that is not a
	 * list of valid IPs is a failure: the last good list is returned instead
	 * (empty if there is none).
	 *
	 * @since 2.6.2
	 *
	 * @return string[]
	 */
	function checkview_fetch_api_ip() {
		// The outcome is reused for a minute (most callers run in one request),
		// then fetched again, so a long-running process (WP-CLI, Action
		// Scheduler) is not stuck with it. checkview_reset_cache() clears it.
		// Kept per blog, since a multisite loop may switch_to_blog().
		$blog = get_current_blog_id();
		$memo = isset( $GLOBALS['checkview_api_ip_fetch_memo'][ $blog ] ) ? $GLOBALS['checkview_api_ip_fetch_memo'][ $blog ] : null;
		if ( is_array( $memo ) && isset( $memo['at'], $memo['ips'] ) && $memo['at'] > time() - MINUTE_IN_SECONDS ) {
			return $memo['ips'];
		}
		$remember = function ( $ips ) use ( $blog ) {
			$GLOBALS['checkview_api_ip_fetch_memo'][ $blog ] = array(
				'at'  => time(),
				'ips' => $ips,
			);
			return $ips;
		};

		$last_good = checkview_last_good_copy( 'ip_address' );
		$last_good = is_array( $last_good ) ? $last_good : array();

		if ( get_transient( 'checkview_saas_ip_fetch_failed' ) ) {
			return $remember( $last_good );
		}
		$lock = checkview_acquire_fetch_lock( 'ip_address', 45 );
		if ( false === $lock ) {
			return $remember( $last_good );
		}
		// Another request may have finished meanwhile. Where transients are
		// options, forget this request's remembered misses so the re-read
		// reaches the database.
		if ( ! wp_using_ext_object_cache() ) {
			wp_cache_delete( 'notoptions', 'options' );
		}
		$cached = get_transient( 'checkview_saas_ip_address' );
		if ( get_transient( 'checkview_saas_ip_fetch_failed' ) || false !== $cached ) {
			checkview_release_fetch_lock( 'ip_address', $lock );
			return $remember( false !== $cached ? array_values( (array) $cached ) : $last_good );
		}

		Checkview_Admin_Logs::add( 'ip-logs', 'Bot IP address list transient empty, requesting new IP addresses.' );

		$request = wp_safe_remote_get(
			'https://verify.checkview.io/whitelist.json',
			array(
				'method'              => 'GET',
				'timeout'             => 5,
				'redirection'         => 1,
				'limit_response_size' => 256 * KB_IN_BYTES,
			)
		);

		$ip_address = null;
		if ( is_wp_error( $request ) ) {
			Checkview_Admin_Logs::add( 'ip-logs', 'Request for new IP addresses failed with code [' . $request->get_error_code() . ']. Message: ' . $request->get_error_message() );
		} elseif ( 200 !== wp_remote_retrieve_response_code( $request ) ) {
			Checkview_Admin_Logs::add( 'ip-logs', 'Request for new IP addresses answered HTTP ' . wp_remote_retrieve_response_code( $request ) . '.' );
		} else {
			// Decoded without `assoc`, a JSON object stays an object, so `{}` is
			// never mistaken for an empty list.
			$data   = json_decode( wp_remote_retrieve_body( $request ) );
			$fields = is_object( $data ) ? get_object_vars( $data ) : array();
			$list   = array_key_exists( 'ipAddresses', $fields ) ? $fields['ipAddresses'] : null;
			// A single IP may come as a string; anything else must be a list.
			if ( is_string( $list ) ) {
				$list = array( $list );
			}
			if ( ! is_array( $list ) ) {
				Checkview_Admin_Logs::add( 'ip-logs', 'IP addresses not found in request for new IP addresses.' );
			} else {
				$ip_address = $list;
				foreach ( $ip_address as $ip ) {
					if ( ! is_string( $ip ) || ! checkview_validate_ip( $ip ) ) {
						Checkview_Admin_Logs::add( 'ip-logs', 'Rejected the IP address list: it contains an invalid entry.' );
						$ip_address = null;
						break;
					}
				}
			}
		}

		if ( null === $ip_address ) {
			set_transient( 'checkview_saas_ip_fetch_failed', 1, 5 * MINUTE_IN_SECONDS );
			checkview_release_fetch_lock( 'ip_address', $lock );
			if ( array() !== $last_good ) {
				Checkview_Admin_Logs::add( 'ip-logs', 'Using the last IP address list fetched successfully until the next attempt.' );
			}
			return $remember( $last_good );
		}

		if ( array() === $ip_address ) {
			set_transient( 'checkview_saas_ip_address', array(), 5 * MINUTE_IN_SECONDS );
			delete_option( 'checkview_saas_ip_address_last_good' );
			update_option( 'checkview_saas_ip_address_seeded', 1, true );
			Checkview_Admin_Logs::add( 'ip-logs', 'WARNING: the SaaS lists no IP addresses; none are trusted. Asking again in five minutes.' );
		} else {
			set_transient( 'checkview_saas_ip_address', $ip_address, 12 * HOUR_IN_SECONDS );
			checkview_last_good_copy( 'ip_address', $ip_address );
			Checkview_Admin_Logs::add( 'ip-logs', 'Set bot IP address list transient to response data [' . wp_json_encode( $ip_address ) . ']' );
		}
		checkview_release_fetch_lock( 'ip_address', $lock );

		return $remember( $ip_address );
	}
}
if ( ! function_exists( 'checkview_get_custom_header_keys_for_ip' ) ) {
	/**
	 * Sends custom header keys.
	 *
	 * @since 2.0.8
	 *
	 * @return array
	 */
	function checkview_get_custom_header_keys_for_ip() {
		return array(
			'HTTP_CLIENT_IP',
			'HTTP_CF_CONNECTING_IP',
			'HTTP_X_FORWARDED_FOR',
			'HTTP_X_FORWARDED',
			'HTTP_X_CLUSTER_CLIENT_IP',
			'HTTP_X_REAL_IP',
			'HTTP_FORWARDED_FOR',
			'HTTP_FORWARDED',
			'REMOTE_ADDR',
		);
	}
}

if ( ! function_exists( 'checkview_get_server_value' ) ) {
	/**
	 * Get any value from the $_SERVER
	 *
	 * @since 2.0
	 *
	 * @param string $value value.
	 *
	 * @return string
	 */
	function checkview_get_server_value( $value ) {
		return isset( $_SERVER[ $value ] ) ? wp_strip_all_tags( wp_unslash( $_SERVER[ $value ] ) ) : '';
	}
}

if ( ! function_exists( 'checkview_get_visitor_ip' ) ) {
	/**
	 * Determines the IP address of current visitor.
	 *
	 * If present, the IP will be pulled from conventional HTTP headers used
	 * by proxies to determine the source IP address.
	 *
	 * @since 1.0.0
	 *
	 * @return string IP address of visitor, or `false` if determined to be
	 *                an invalid IP.
	 */
	function checkview_get_visitor_ip() {
		// Check view Bot IP.
		$cv_bot_ip  = checkview_get_api_ip();
		$ip_options = checkview_get_custom_header_keys_for_ip();
		$ip         = '';

		foreach ( $ip_options as $key ) {
			if ( ! isset( $_SERVER[ $key ] ) ) {
				continue;
			}

			$key = checkview_get_server_value( $key );

			foreach ( explode( ',', $key ) as $ip ) {
				$ip = trim( $ip );
				$ip = preg_replace( '/:\d{1,5}$/', '', $ip );

				if ( null !== $ip && checkview_validate_ip( $ip ) && is_array( $cv_bot_ip ) && in_array( $ip, $cv_bot_ip, true ) ) {
					return sanitize_text_field( $ip );
				}
			}
		}

		return sanitize_text_field( $ip );
	}
}

if ( ! function_exists( 'checkview_get_cleantalk_whitelisted_ips' ) ) {
	/**
	 * Gathers a list of whitelisted IPs from CleanTalk.
	 *
	 * @param string $service_type Service type.
	 * @param string $service_id Service ID.
	 * @return array|false List of whitelisted IPs, false on error.
	 */
	function checkview_get_cleantalk_whitelisted_ips( $service_type = 'antispam', $service_id = 'all' ) {
		$ip_array = get_transient( 'checkview_whitelisted_ips_' . $service_type );

		if ( ! empty( $ip_array ) && is_array( $ip_array ) ) {
			return $ip_array;
		}

		$spbc_data  = get_option( 'cleantalk_data', array() );
		$user_token = $spbc_data['user_token'];
		$api_url    = "https://api.cleantalk.org/?method_name=private_list_get&user_token=$user_token&service_type=" . $service_type . '&product_id=1&service_id=' . $service_id;

		$response = wp_remote_get(
			$api_url,
			array(
				'timeout' => 20,
			)
		);

		if ( is_wp_error( $response ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Desired production debug logging.
			error_log( 'Error fetching whitelisted IPs: ' . $response->get_error_message() );
			Checkview_Admin_Logs::add( 'ip-logs', esc_html( 'Error fetching whitelisted IPs: ' . $response->get_error_message() ) );

			return false;
		}

		// Get the response body.
		$body = wp_remote_retrieve_body( $response );

		// Decode the JSON response.
		$whitelisted_ips = json_decode( $body, true );

		// Initialize an empty array to store IP addresses.
		$ip_array = array();

		// Check if we have valid data.
		if ( isset( $whitelisted_ips['data'] ) && is_array( $whitelisted_ips['data'] ) && ! empty( $whitelisted_ips['data'] ) ) {
			// Loop through and add IPs to the array.
			foreach ( $whitelisted_ips['data'] as $entry ) {
				// Add the IP address (from the 'record' key) to the array.
				if ( ! empty( $entry['hostname'] ) ) {
					$ip_array[ $entry['hostname'] ][] = $entry['record'];
				}
			}
		}

		set_transient( 'checkview_whitelisted_ips_' . $service_type, $ip_array, 12 * HOUR_IN_SECONDS );

		return $ip_array;
	}
}

if ( ! function_exists( 'checkview_whitelist_api_ip' ) ) {
	/**
	 * Whitelists CheckView in a CleanTalk account via their API.
	 *
	 * @since 1.0.0
	 *
	 * @return null
	 */
	function checkview_whitelist_api_ip() {
		global $apbct;

		if ( ! isset( $apbct->data['service_id'] ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Desired production debug logging.
			error_log( 'CleanTalk service ID could not be found.' );
		}

		$service_id = $apbct->data['service_id'];
		$spbc_data  = get_option( 'cleantalk_data', array() );
		$user_token = $spbc_data['user_token'];

		if ( empty( $user_token ) || ( function_exists( 'apbct_api_key__is_correct' ) && ! apbct_api_key__is_correct() ) ) {
			return null;
		}

		$current_ip = checkview_get_visitor_ip();
		$api_ip     = checkview_get_api_ip();

		if ( is_array( $api_ip ) && in_array( $current_ip, $api_ip, true ) ) {
			$home_url = wp_parse_url( home_url() ); // Returns `false`, `null`, or an assosiative array.

			if ( false === $home_url || null === $home_url ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Desired production debug logging.
				error_log( sprintf( 'Error parsing the home url [%1$s].', home_url() ) );
				Checkview_Admin_Logs::add( 'ip-logs', sprintf( 'Error parsing the home url [%1$s].', home_url() ) );

				return null;
			}

			if ( is_array( $home_url ) && ! isset( $home_url['host'] ) ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Desired production debug logging.
				error_log( sprintf( 'Cannot determine host when parsing URL [%1$s].', wp_json_encode( $home_url ) ) );
				Checkview_Admin_Logs::add( 'ip-logs', sprintf( 'Cannot determine host when parsing URL [%1$s].', wp_json_encode( $home_url ) ) );

				return null;
			}

			$host_name = $home_url['host'];

			// Check antispam whitelist.
			$antispam_ips = checkview_get_cleantalk_whitelisted_ips( 'antispam', $service_id );

			if ( is_array( $antispam_ips ) ) {
				if ( ! isset( $antispam_ips[ $host_name ] ) ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Desired production debug logging.
					error_log( 'No host name found in Antispam IP list.' );

					// If the host name is not in the whitelist, request to add our IPs/hosts.
					checkview_add_to_cleantalk( $user_token, 'antispam', $current_ip, 1, $service_id );
					checkview_add_to_cleantalk( $user_token, 'antispam', 'checkview.io', 4, $service_id );
					checkview_add_to_cleantalk( $user_token, 'antispam', 'test-mail.checkview.io', 4, $service_id );
				} elseif ( is_array( $antispam_ips[ $host_name ] ) ) {
					// Otherwise, check for our IPs/hosts individually, and request to add them if needed.
					$has_current_ip = array_find(
						$antispam_ips[ $host_name ],
						function ( $value ) use ( $current_ip ) {
							$position = strpos( $value, $current_ip );

							return false !== $position;
						}
					);

					if ( ! $has_current_ip ) {
						checkview_add_to_cleantalk( $user_token, 'antispam', $current_ip, 1, $service_id );
					}

					$has_checkview_hostname = array_find(
						$antispam_ips[ $host_name ],
						function ( $value ) {
							$position = strpos( $value, 'checkview.io' );

							if ( 'test-mail.checkview.io' === $value ) {
								return false;
							}

							return false !== $position;
						}
					);

					if ( ! $has_checkview_hostname ) {
						checkview_add_to_cleantalk( $user_token, 'antispam', 'checkview.io', 4, $service_id );
					}

					$has_mail_hostname = array_find(
						$antispam_ips[ $host_name ],
						function ( $value ) {
							$position = strpos( $value, 'test-mail.checkview.io' );

							return false !== $position;
						}
					);

					if ( ! $has_mail_hostname ) {
						checkview_add_to_cleantalk( $user_token, 'antispam', 'test-mail.checkview.io', 4, $service_id );
					}
				} else {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Desired production debug logging.
					error_log( sprintf( 'The value for antispam IPs at hostname [%1$s] is not an array.', $host_name ) );
					Checkview_Admin_Logs::add( 'ip-logs', sprintf( 'The value for antispam IPs at hostname [%1$s] is not an array.', $host_name ) );
				}
			}

			// Check spamfirewall whitelist.
			$spamfirewall_ips = checkview_get_cleantalk_whitelisted_ips( 'spamfirewall', $service_id );

			if ( is_array( $spamfirewall_ips ) ) {
				if ( ! isset( $spamfirewall_ips[ $host_name ] ) ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Desired production debug logging.
					error_log( 'No host name found in Spam Firewall IP list.' );

					// If host name is not set, request to add it.
					checkview_add_to_cleantalk( $user_token, 'spamfirewall', $current_ip . '/32', 6, $service_id );
				} elseif ( is_array( $spamfirewall_ips[ $host_name ] ) ) {
					$has_current_ip = array_find(
						$spamfirewall_ips[ $host_name ],
						function ( $value ) use ( $current_ip ) {
							$position = strpos( $value, $current_ip );

							return false !== $position;
						}
					);

					if ( ! $has_current_ip ) {
						checkview_add_to_cleantalk( $user_token, 'spamfirewall', $current_ip, 6, $service_id );
					}
				} else {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Desired production debug logging.
					error_log( sprintf( 'Value for spam firewall IPs at hostname [%1$s] is unexpected type [%2$s], expected array.', $host_name, gettype( $spamfirewall_ips[ $host_name ] ) ) );
					Checkview_Admin_Logs::add( 'ip-logs', sprintf( 'Value for spam firewall IPs at hostname [%1$s] is unexpected type [%2$s], expected array.', $host_name, gettype( $spamfirewall_ips[ $host_name ] ) ) );
				}
			}
		}

		return null;
	}
}
if ( ! function_exists( 'checkview_add_to_cleantalk' ) ) {
	/**
	 * Adds an IP address to CleanTalk's whitelist.
	 *
	 * @since 2.0.13
	 *
	 * @param string $user_token User token.
	 * @param string $service_type Service type.
	 * @param string $record Record.
	 * @param string $record_type Record type.
	 * @param string $service_id Service ID.
	 * @return mixed
	 */
	function checkview_add_to_cleantalk( $user_token, $service_type, $record, $record_type, $service_id ) {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Desired production debug logging.
		error_log(
			sprintf(
				'Adding record [%1$s] with type [%2$s] and service type [%3$s] to CleanTalk\'s API with service id [%4$s]',
				$record,
				$record_type,
				$service_type,
				$service_id,
			)
		);

		$response = wp_remote_get(
			'https://api.cleantalk.org/?method_name=private_list_add&user_token=' . $user_token .
			'&service_id=' . $service_id . '&service_type=' . $service_type .
			'&product_id=1&record_type=' . $record_type .
			'&status=allow&note=Checkview Bot&records=' . $record,
			array(
				'method'  => 'POST',
				'timeout' => 20,
			)
		);

		delete_transient( 'checkview_whitelisted_ips_' . $service_type );

		if ( is_wp_error( $response ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Desired production debug logging.
			error_log( 'Request failed: ' . $response->get_error_message() );
			return null;
		}

		return json_decode( wp_remote_retrieve_body( $response ), true );
	}
}

if ( ! function_exists( 'checkview_is_local_environment' ) ) {
	/**
	 * Determine if environment is a local development environment.
	 *
	 * Decision is made based on WP_ENVIRONMENT_TYPE constant.
	 *
	 * @since 2.4.0
	 *
	 * @return bool
	 */
	function checkview_is_local_environment(): bool {
		return defined( 'WP_ENVIRONMENT_TYPE' ) && WP_ENVIRONMENT_TYPE === 'local';
	}
}

if ( ! function_exists( 'checkview_must_ssl_url' ) ) {
	/**
	 * Replaces `http:` with `https:`.
	 *
	 * @since 1.0.0
	 *
	 * @param string $url URL to sanitize.
	 * @return string SSL version of `$url`.
	 */
	function checkview_must_ssl_url( $url ) {
		if ( checkview_is_local_environment() ) {
			return $url;
		}

		$url = str_replace( 'http:', 'https:', $url );
		return $url;
	}
}

if ( ! function_exists( 'checkview_create_cv_session' ) ) {
	/**
	 * Creates a CheckView session.
	 *
	 * To create a session, the function must know the IP address of the session,
	 * as well as the test ID of the test to be used by the session.
	 *
	 * @param string $ip IP address of the SAAS.
	 * @param string $test_id Test ID to be executed.
	 *
	 * @return void|boolean
	 * @since 1.0.0
	 */
	function checkview_create_cv_session( $ip, $test_id ) {
		global $wp, $wpdb;

		if ( ! checkview_is_valid_uuid( $test_id ) ) {
			return;
		}

		$already_have = checkview_get_cv_session( $ip, $test_id );
		if ( ! empty( $already_have ) ) {
			return;
		}

		$current_url = '';
		if ( ! empty( $wp->request ) ) {
			$current_url = home_url( add_query_arg( array(), $wp->request ) );
		}

		$is_sub_directory = explode( '/', str_replace( '//', '|', $current_url ) );
		if ( count( $is_sub_directory ) > 1 ) {
			$current_url = str_replace( '/' . $is_sub_directory[1], '', $current_url );
		}

		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_url( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		if ( ! empty( $request_uri ) && ! filter_var( $request_uri, FILTER_SANITIZE_URL ) ) {
			Checkview_Admin_Logs::add( 'ip-logs', 'Session skip: invalid URL.' );

			return false;
		}

		if ( count( $is_sub_directory ) > 1 ) {
			$current_url = $current_url . $request_uri;
		} else {
			$current_url = $request_uri;
		}

		$url         = explode( '?', $current_url );
		$current_url = $url[0];
		$page_id     = '';

		if ( ! $current_url ) {
			global $post;
			if ( $post instanceof WP_Post ) {
				$page_id = $post->ID;
			}
		} else {
			$page = get_page_by_path( $current_url );
			if ( $page instanceof WP_Post ) {
				$page_id = $page->ID;
			} else {
				global $post;
				if ( $post instanceof WP_Post ) {
					$page_id = $post->ID;
				} else {
					$page_id = get_the_ID();
				}
			}
		}

		$session_table = $wpdb->prefix . 'cv_session';
		$test_key      = 'CF_TEST_' . $page_id;
		$session_data  = array(
			'visitor_ip' => $ip,
			'test_key'   => $test_key,
			'test_id'    => $test_id,
		);

		$result    = $wpdb->insert( $session_table, $session_data );
		$safe_ip   = preg_replace( '/[^0-9a-fA-F.:,]/', '', substr( $ip, 0, 45 ) );
		$safe_test = substr( $test_id, 0, 8 );

		if ( false === $result ) {
			Checkview_Admin_Logs::add(
				'ip-logs',
				sprintf(
					'Session INSERT FAILED: IP=[%s], test=[%s...], page=[%d], err=[%s].',
					$safe_ip,
					$safe_test,
					(int) $page_id,
					$wpdb->last_error ? substr( $wpdb->last_error, 0, 80 ) : 'none'
				)
			);
		} else {
			Checkview_Admin_Logs::add(
				'ip-logs',
				sprintf(
					'Session CREATED: IP=[%s], test=[%s...], page=[%d].',
					$safe_ip,
					$safe_test,
					(int) $page_id
				)
			);
		}
	}
}

if ( ! function_exists( 'checkview_get_cv_session' ) ) {
	/**
	 * Retrieves a CheckView session from the database.
	 *
	 * @param string $ip IP address of the visitor.
	 * @param string $test_id Test ID to be conducted.
	 *
	 * @return array Array of results from DB.
	 * @since 1.0.0
	 */
	function checkview_get_cv_session( $ip, $test_id ) {
		global $wpdb;
		static $logged_this_request = array();

		$session_table = $wpdb->prefix . 'cv_session';

		$result = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i
				WHERE visitor_ip = %s
				AND test_id = %s
				LIMIT 1',
				$session_table,
				$ip,
				$test_id
			),
			ARRAY_A
		);

		// Log once per IP+test combo per request, only for valid UUIDs.
		$log_key = $ip . '|' . $test_id;
		if ( checkview_is_valid_uuid( $test_id ) && ! isset( $logged_this_request[ $log_key ] ) ) {
			$logged_this_request[ $log_key ] = true;

			$safe_ip   = preg_replace( '/[^0-9a-fA-F.:,]/', '', substr( $ip, 0, 45 ) );
			$safe_test = substr( $test_id, 0, 8 );
			$method    = isset( $_SERVER['REQUEST_METHOD'] )
				? strtoupper( substr( preg_replace( '/[^A-Za-z]/', '', sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ), 0, 7 ) )
				: '?';

			if ( ! empty( $result ) ) {
				Checkview_Admin_Logs::add(
					'ip-logs',
					sprintf(
						'Session FOUND [%s]: IP=[%s], test=[%s...].',
						$method,
						$safe_ip,
						$safe_test
					)
				);
			} else {
				Checkview_Admin_Logs::add(
					'ip-logs',
					sprintf(
						'Session NOT FOUND [%s]: IP=[%s], test=[%s...]%s.',
						$method,
						$safe_ip,
						$safe_test,
						$wpdb->last_error ? ', err' : ''
					)
				);
			}
		}

		return $result;
	}
}

if ( ! function_exists( 'checkview_get_form_page_post_types' ) ) {
	/**
	 * Post types searched when locating the pages a form is embedded on.
	 *
	 * These lookups match on `post_content LIKE '%...%'`, which no index can
	 * satisfy. Naming the post types at least lets MySQL narrow the scan with
	 * `type_status_date` instead of reading every row. Stores that keep orders
	 * in `wp_posts` (HPOS off) or run a REST/debug logger CPT push that table
	 * into the hundreds of MB, where the unscoped scan exceeds the host's
	 * gateway timeout and the form list never returns.
	 *
	 * @since 1.0.0
	 *
	 * @return string[] Post type slugs.
	 */
	function checkview_get_form_page_post_types() {
		$post_types = get_post_types( array( 'public' => true ), 'names' );

		// Reusable blocks are not public, but forms are commonly embedded in them.
		$post_types['wp_block'] = 'wp_block';

		// Attachments never embed a form and carry no useful permalink here.
		unset( $post_types['attachment'] );

		/**
		 * Filters the post types searched for embedded forms.
		 *
		 * Lets a site add a non-public post type that genuinely renders forms.
		 *
		 * @since 1.0.0
		 *
		 * @param string[] $post_types Post type slugs.
		 */
		$post_types = apply_filters( 'checkview_form_page_post_types', array_values( $post_types ) );

		$post_types = array_values( array_unique( array_filter( (array) $post_types, 'is_string' ) ) );

		// Never hand back an empty list; `IN ()` is a syntax error.
		if ( empty( $post_types ) ) {
			return array( 'post', 'page', 'wp_block' );
		}

		return $post_types;
	}
}

if ( ! function_exists( 'checkview_get_wp_block_pages' ) ) {
	/**
	 * Retrieves a list of pages that contain at least one Gutenberg block.
	 *
	 * @since 1.0.0
	 *
	 * @param int $block_id ID of GB block.
	 * @return WPDB Object from WPDB.
	 */
	function checkview_get_wp_block_pages( $block_id ) {
		global $wpdb;
		$post_types = checkview_get_form_page_post_types();
		// WPDBPREPARE.
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}posts WHERE 1=1 AND (post_content LIKE %s) AND post_status=%s AND post_type IN ( " . implode( ', ', array_fill( 0, count( $post_types ), '%s' ) ) . ' )',
				array_merge(
					array(
						'%wp:block {\"ref\":' . $block_id . '}%',
						'publish',
					),
					$post_types
				)
			)
		);
	}
}

if ( ! function_exists( 'checkview_get_elementor_form_widgets' ) ) {
	/**
	 * Recursively collects Elementor Pro form widgets from an Elementor data tree.
	 *
	 * Elementor stores page content as a nested element tree in the
	 * `_elementor_data` post meta. Form widgets are nodes with
	 * `widgetType === 'form'`; a form's identifier is the element `id` (the same
	 * value Elementor renders as the hidden `form_id` input and that
	 * `Form_Record::get_form_settings( 'id' )` returns during submission).
	 *
	 * A form saved as a Global Widget lives in an `elementor_library` post and
	 * each page holds only a `global` widget node pointing at it via
	 * `templateID`. Those references are resolved so the form is reported on
	 * every page that renders it; see checkview_get_elementor_global_widget_form()
	 * for which id the resolved node carries.
	 *
	 * A whole saved template can also be embedded, with the Template widget
	 * (`settings.template_id`) or the `[elementor-template id="N"]` shortcode in
	 * a Shortcode or Text Editor widget. Those are resolved too, keeping each
	 * form's own id; see checkview_get_elementor_template_forms().
	 *
	 * @since 1.0.0
	 *
	 * @param array $elements Decoded Elementor element tree (or a subtree).
	 * @return array List of form-widget element arrays.
	 */
	function checkview_get_elementor_form_widgets( $elements ) {
		$scan  = checkview_scan_elementor_elements( $elements );
		$found = $scan['forms'];

		foreach ( array_unique( $scan['template_ids'] ) as $template_id ) {
			$found = array_merge( $found, checkview_get_elementor_template_forms( $template_id ) );
		}

		return $found;
	}
}

if ( ! function_exists( 'checkview_scan_elementor_elements' ) ) {
	/**
	 * Walks one Elementor element tree without following embedded templates.
	 *
	 * Returns the form widgets placed in the tree itself (Global Widgets
	 * resolved) and the ids of the templates it embeds, so callers decide how
	 * far to follow them; see checkview_get_elementor_template_forms().
	 *
	 * @since 1.0.0
	 *
	 * @param array $elements Decoded Elementor element tree (or a subtree).
	 * @return array {
	 *     @type array $forms        Form-widget element arrays.
	 *     @type int[] $template_ids Embedded template ids, possibly repeated.
	 * }
	 */
	function checkview_scan_elementor_elements( $elements ) {
		$scan = array(
			'forms'        => array(),
			'template_ids' => array(),
		);

		if ( ! is_array( $elements ) ) {
			return $scan;
		}

		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			$widget_type = isset( $element['widgetType'] ) ? $element['widgetType'] : '';

			if ( 'form' === $widget_type ) {
				$scan['forms'][] = $element;
			} elseif ( 'global' === $widget_type ) {
				$global_form = checkview_get_elementor_global_widget_form( $element );
				if ( null !== $global_form ) {
					$scan['forms'][] = $global_form;
				}
			} elseif ( 'template' === $widget_type ) {
				$template_id = isset( $element['settings']['template_id'] ) ? $element['settings']['template_id'] : 0;
				if ( is_numeric( $template_id ) && (int) $template_id > 0 ) {
					$scan['template_ids'][] = (int) $template_id;
				}
			} elseif ( 'shortcode' === $widget_type || 'text-editor' === $widget_type ) {
				// The HTML widget is left out on purpose: it does not run shortcodes.
				$setting = ( 'shortcode' === $widget_type ) ? 'shortcode' : 'editor';
				$content = isset( $element['settings'][ $setting ] ) ? $element['settings'][ $setting ] : '';

				$scan['template_ids'] = array_merge(
					$scan['template_ids'],
					checkview_get_elementor_template_shortcode_ids( $content )
				);
			}

			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$child_scan = checkview_scan_elementor_elements( $element['elements'] );

				$scan['forms']        = array_merge( $scan['forms'], $child_scan['forms'] );
				$scan['template_ids'] = array_merge( $scan['template_ids'], $child_scan['template_ids'] );
			}
		}

		return $scan;
	}
}

if ( ! function_exists( 'checkview_get_elementor_global_widget_form' ) ) {
	/**
	 * Resolves a `global` widget node to the form widget it renders.
	 *
	 * Elementor Pro renders a Global Widget by instantiating the referenced
	 * template's widget under the *page* element's id: that id is what lands
	 * on the wrapper's `data-id` and in the hidden `form_id` input, and it is
	 * what the browser posts on submit (Ajax_Handler finds the posted id in the
	 * host page, then follows `templateID` for the form settings). The SaaS
	 * locates the form on the page by that same id, so the returned node keeps
	 * the page element's `id` and takes its settings (form name, fields) from
	 * the template. Each page placing the Global Widget yields its own form id.
	 *
	 * Templates are decoded once per request; a template that references
	 * itself through hand-edited meta resolves to null instead of recursing.
	 *
	 * @since 1.0.0
	 *
	 * @param array $element A `global` widget node from a page's element tree.
	 * @return array|null Form node keyed to the host page, or null when the
	 *                    template is missing, trashed, not a library post, or
	 *                    contains no form.
	 */
	function checkview_get_elementor_global_widget_form( $element ) {
		static $template_forms = array();

		if ( empty( $element['id'] ) || empty( $element['templateID'] ) ) {
			return null;
		}

		$template_id = (int) $element['templateID'];
		if ( $template_id <= 0 ) {
			return null;
		}

		if ( ! array_key_exists( $template_id, $template_forms ) ) {
			$template_forms[ $template_id ] = null;

			if ( 'elementor_library' === get_post_type( $template_id ) && 'trash' !== get_post_status( $template_id ) ) {
				$data = get_post_meta( $template_id, '_elementor_data', true );
				if ( is_string( $data ) && '' !== $data ) {
					$data = json_decode( $data, true );
				}

				/*
				 * Only forms placed in the template itself. A Template widget
				 * saved as a Global Widget renders its template as a separate
				 * document whose forms keep their own ids, so re-keying them to
				 * the host element below would report an id that is not on the page.
				 */
				$template_widgets = checkview_scan_elementor_elements( $data )['forms'];
				if ( ! empty( $template_widgets ) ) {
					$template_forms[ $template_id ] = $template_widgets[0];
				}
			}
		}

		if ( null === $template_forms[ $template_id ] ) {
			return null;
		}

		$form               = $template_forms[ $template_id ];
		$form['id']         = $element['id'];
		$form['templateID'] = $template_id;

		return $form;
	}
}

if ( ! function_exists( 'checkview_get_elementor_template_forms' ) ) {
	/**
	 * Collects the form widgets a saved Elementor template renders when embedded.
	 *
	 * Unlike a Global Widget, a template embedded with the Template widget or
	 * the `[elementor-template]` shortcode renders as its own document: each
	 * form keeps its element id, posts the template's id as `post_id`, and has
	 * the same form id on every page that embeds the template. So the nodes are
	 * returned unchanged.
	 *
	 * Only published templates count; the Template widget renders nothing for
	 * any other status. Templates nested in templates are followed breadth
	 * first with a visited set, so a template that embeds itself, directly or
	 * through a chain, is walked once and the result does not depend on which
	 * template was asked about first. Each template is decoded once per request.
	 *
	 * @since 1.0.0
	 *
	 * @param int|string $template_id Elementor library post id.
	 * @return array List of form-widget element arrays.
	 */
	function checkview_get_elementor_template_forms( $template_id ) {
		static $scans    = array();
		static $resolved = array();

		$template_id = (int) $template_id;
		if ( $template_id <= 0 ) {
			return array();
		}

		if ( isset( $resolved[ $template_id ] ) ) {
			return $resolved[ $template_id ];
		}

		$forms   = array();
		$queue   = array( $template_id );
		$visited = array( $template_id => true );

		while ( ! empty( $queue ) ) {
			$current_id = array_shift( $queue );

			if ( ! isset( $scans[ $current_id ] ) ) {
				$scans[ $current_id ] = checkview_scan_elementor_elements( array() );

				if ( 'elementor_library' === get_post_type( $current_id ) && 'publish' === get_post_status( $current_id ) ) {
					$data = get_post_meta( $current_id, '_elementor_data', true );
					if ( is_string( $data ) && '' !== $data ) {
						$data = json_decode( $data, true );
					}

					$scans[ $current_id ] = checkview_scan_elementor_elements( $data );
				}
			}

			$forms = array_merge( $forms, $scans[ $current_id ]['forms'] );

			foreach ( $scans[ $current_id ]['template_ids'] as $embedded_id ) {
				if ( ! isset( $visited[ $embedded_id ] ) ) {
					$visited[ $embedded_id ] = true;
					$queue[]                 = $embedded_id;
				}
			}
		}

		$resolved[ $template_id ] = $forms;

		return $forms;
	}
}

if ( ! function_exists( 'checkview_get_elementor_template_shortcode_ids' ) ) {
	/**
	 * Extracts the template ids of `[elementor-template id="N"]` shortcodes.
	 *
	 * @since 1.0.0
	 *
	 * @param string $content Text that may contain the shortcode.
	 * @return int[] Unique template ids.
	 */
	function checkview_get_elementor_template_shortcode_ids( $content ) {
		if ( ! is_string( $content ) || false === strpos( $content, '[elementor-template' ) ) {
			return array();
		}

		if ( ! preg_match_all( '/' . get_shortcode_regex( array( 'elementor-template' ) ) . '/', $content, $matches, PREG_SET_ORDER ) ) {
			return array();
		}

		$ids = array();
		foreach ( $matches as $match ) {
			// `[[elementor-template ...]]` is escaped and renders as literal text.
			if ( '[' === $match[1] && ']' === $match[6] ) {
				continue;
			}

			$atts = shortcode_parse_atts( $match[3] );
			if ( ! is_array( $atts ) || ! isset( $atts['id'] ) || ! is_numeric( $atts['id'] ) ) {
				continue;
			}

			$id = (int) $atts['id'];
			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}

		return array_values( array_unique( $ids ) );
	}
}

if ( ! function_exists( 'checkview_delete_transient_everywhere' ) ) {
	/**
	 * Deletes a CheckView transient from both places it can be stored.
	 *
	 * CheckView's REST requests turn off the persistent object cache (see
	 * `CheckView_Admin::bypass_rest_authentication()`), so on a site with Redis
	 * or Memcached they keep transients in the options table while the rest of
	 * the site keeps them in the object cache. `delete_transient()` only clears
	 * the store the current request uses, which left CheckView reading a stale
	 * product list for up to 12 hours after a stock change on such sites.
	 *
	 * @since 2.6.2
	 *
	 * @param string $transient Transient name.
	 * @return void
	 */
	function checkview_delete_transient_everywhere( $transient ) {
		delete_transient( $transient );

		// Then the store this request does not use. Not remembered per request:
		// a long import, or a switch to another site of a network, has to clear
		// it again.
		if ( wp_using_ext_object_cache() ) {
			// As core's delete_transient() does without an object cache: the
			// expiry row only when there was a value row.
			if ( delete_option( '_transient_' . $transient ) ) {
				delete_option( '_transient_timeout_' . $transient );
			}
		} else {
			wp_cache_delete( $transient, 'transient' );
		}
	}
}

if ( ! function_exists( 'checkview_reset_cache' ) ) {
	/**
	 * Deletes CheckView transients.
	 *
	 * @param bool $sync Hard sync or not.
	 * @return bool
	 */
	function checkview_reset_cache( $sync ) {
		checkview_delete_transient_everywhere( 'checkview_saas_pk' );
		checkview_delete_transient_everywhere( 'checkview_saas_ip_address' );
		checkview_delete_transient_everywhere( 'checkview_saas_pk_fetch_failed' );
		checkview_delete_transient_everywhere( 'checkview_saas_ip_fetch_failed' );
		unset( $GLOBALS['checkview_api_ip_fetch_memo'] );
		checkview_delete_transient_everywhere( 'checkview_forms_list_transient' );
		checkview_delete_transient_everywhere( 'checkview_forms_test_transient' );
		checkview_delete_transient_everywhere( 'checkview_store_orders_transient' );
		checkview_delete_transient_everywhere( 'checkview_store_products_transient' );
		checkview_delete_transient_everywhere( 'checkview_store_shipping_transient' );
		checkview_delete_transient_everywhere( 'checkview_whitelisted_ips_spamfirewall' );
		checkview_delete_transient_everywhere( 'checkview_whitelisted_ips_antispam' );
		$sync = true;
		return $sync;
	}
}

if ( ! function_exists( 'checkview_deslash' ) ) {
	/**
	 * Removes excess backslashes.
	 *
	 * Removes excess backslashes commonly used for escaping characters.
	 *
	 * @since 1.1.0
	 *
	 * @param string $content Content to delash.
	 * @return string
	 */
	function checkview_deslash( $content ) {
		// Note: \\\ inside a regex denotes a single backslash.

		/**
		 * Replace one or more backslashes followed by a single quote with
		 * a single quote.
		 */
		$content = preg_replace( "/\\\+'/", "'", $content );

		/**
		 * Replace one or more backslashes followed by a double quote with a
		 * double quote.
		 */
		$content = preg_replace( '/\\\+"/', '"', $content );

		/**
		 * Replace one or more backslashes with one backslash.
		 */
		$content = preg_replace( '/\\\+/', '\\', $content );

		return $content;
	}
}
if ( ! function_exists( 'checkview_whitelist_saas_ip_addresses' ) ) {
	/**
	 * Returns true if the remote request IP is a SaaS IP.
	 *
	 * @return bool|void
	 */
	function checkview_whitelist_saas_ip_addresses() {
		$api_ip     = checkview_get_api_ip();
		$visitor_ip = checkview_get_visitor_ip();
		if ( ! empty( $visitor_ip ) && ! filter_var( sanitize_text_field( wp_unslash( $visitor_ip ) ), FILTER_VALIDATE_IP ) ) {
			// Log the detailed error for internal use.
			Checkview_Admin_Logs::add( 'ip-logs', esc_html__( 'Invalid IP Address.', 'checkview' ) );
			return false;
		}
		if ( is_array( $api_ip ) && in_array( isset( $visitor_ip ) ? sanitize_text_field( wp_unslash( $visitor_ip ) ) : '', $api_ip, true ) ) {
			return true;
		}
	}
}
if ( ! function_exists( 'checkview_schedule_delete_orders' ) ) {
	/**
	 * Schedules removal of an order.
	 *
	 * @param integer $order_id WooCommerce order ID.
	 * @return void
	 */
	function checkview_schedule_delete_orders( $order_id ) {
		Checkview_Admin_Logs::add( 'ip-logs', 'Scheduling order deletion.' );

		wp_schedule_single_event( time() + 1 * HOUR_IN_SECONDS, 'checkview_delete_orders_action', array( $order_id ) );
	}
}


if ( ! function_exists( 'checkview_add_states_to_locations' ) ) {
	/**
	 * Given an array of countries, retrieves their states.
	 *
	 * @param array $locations Countries.
	 * @return array
	 */
	function checkview_add_states_to_locations( $locations ) {
		$locations_with_states = array();
		foreach ( $locations as $country_code => $country_name ) {
			// Get states for the country.
			$states = WC()->countries->get_states( $country_code );
			if ( ! empty( $states ) ) {
				// If states exist, add them under the country.
				$locations_with_states[ $country_code ] = array(
					'name'   => $country_name,
					'states' => $states,
				);
			} else {
				// If no states, just add the country name.
				$locations_with_states[ $country_code ] = array(
					'name'   => $country_name,
					'states' => new stdClass(), // Use stdClass to represent an empty object.
				);
			}
		}
		return $locations_with_states;
	}
}

if ( ! function_exists( 'checkview_is_plugin_request' ) ) {
	/**
	 * Determines if the incoming request is from the plugin itself.
	 *
	 * @return bool
	 */
	function checkview_is_plugin_request() {
		$current_route = rest_get_url_prefix() . '/checkview/v1/';
		if ( ! empty( $_SERVER['REQUEST_URI'] ) && ! filter_var( sanitize_url( wp_unslash( $_SERVER['REQUEST_URI'] ) ), FILTER_SANITIZE_URL ) ) {
			// If validation fails, handle the error appropriately.
			Checkview_Admin_Logs::add( 'ip-logs', esc_html__( 'Invalid IP Address.', 'checkview' ) );
			return false;
		}
		return strpos(
			isset( $_SERVER['REQUEST_URI'] ) ? sanitize_url( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '',
			$current_route
		) !== false;
	}
}

if ( ! function_exists( 'checkview_add_csp_header_for_plugin' ) ) {
	/**
	 * Adds CSP headers.
	 *
	 * @return void
	 */
	function checkview_add_csp_header_for_plugin() {
		// Check if the current request is related to your plugin.
		if ( checkview_is_plugin_request() ) {
			header( "Content-Security-Policy: default-src 'self'; script-src 'self' https://app.checkview.io; style-src 'self' https://app.checkview.io; connect-src 'self' https://app.checkview.io;" );
			header( "Content-Security-Policy: default-src 'self'; script-src 'self' https://storage.googleapis.com; style-src 'self' https://storage.googleapis.com; connect-src 'self' https://storage.googleapis.com;" );
		}
	}
}
add_action( 'send_headers', 'checkview_add_csp_header_for_plugin' );

if ( ! function_exists( 'checkview_is_valid_uuid' ) ) {
	/**
	 * Determines if a string is a valid UUID.
	 *
	 * @param string $uuid CheckView test ID.
	 * @return bool
	 */
	function checkview_is_valid_uuid( $uuid ) {
		if ( empty( $uuid ) || is_wp_error( $uuid ) ) {
			Checkview_Admin_Logs::add( 'ip-logs', 'Invalid UUID [' . $uuid . '].' );

			return false;
		}

		$matches = preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i', $uuid );

		if ( ! $matches ) {
			Checkview_Admin_Logs::add( 'ip-logs', 'Invalid UUID [' . $uuid . '].' );

			return false;
		}

		return true;
	}
}

if ( ! function_exists( 'checkview_schedule_weekly_cleanup' ) ) {
	/**
	 * Cleans up the database on a weekly basis.
	 *
	 * @return void
	 */
	function checkview_schedule_weekly_cleanup() {
		if ( ! wp_next_scheduled( 'checkview_delete_table_cron_hook' ) ) {
			wp_schedule_event( time(), 'weekly', 'checkview_delete_table_cron_hook' );
		}
	}

	// Hook to initialize the cron job when WordPress loads.
	add_action( 'init', 'checkview_schedule_weekly_cleanup' );
}

if ( ! function_exists( 'checkview_add_weekly_cron_schedule' ) ) {
	/**
	 * Filters cron schedules and defines a 'weekly' interval.
	 *
	 * @param array $schedules Schedules.
	 * @return array Modified schedules.
	 */
	function checkview_add_weekly_cron_schedule( $schedules ) {
		$schedules['weekly'] = array(
			'interval' => 604800, // 7 days in seconds
			'display'  => __( 'Once Weekly', 'checkview' ),
		);
		return $schedules;
	}
	add_filter( 'cron_schedules', 'checkview_add_weekly_cron_schedule' );
}

if ( ! function_exists( 'checkview_delete_tables_data' ) ) {
	/**
	 * Cleans the database of CV Entries and their meta data.
	 *
	 * @return void
	 */
	function checkview_delete_tables_data() {
		global $wpdb;

		Checkview_Admin_Logs::add( 'ip-logs', 'Running scheduled deletion of CheckView rows...' );

		$table_entry      = $wpdb->prefix . 'cv_entry';
		$table_entry_meta = $wpdb->prefix . 'cv_entry_meta';

		// Delete entries older than 1 day from 'cv_entry_meta' table.
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i
				WHERE entry_id IN (
					SELECT id FROM %i
					WHERE date_created < DATE_SUB(NOW(), INTERVAL 1 DAY)
				)',
				$table_entry_meta,
				$table_entry
			)
		);

		// Delete entries older than 1 day from 'cv_entry' table.
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i
				WHERE date_created < DATE_SUB(NOW(), INTERVAL 1 DAY)',
				$table_entry
			)
		);

		Checkview_Admin_Logs::add( 'ip-logs', 'Done.' );
	}

	// Attach the function to the cron event.
	add_action( 'checkview_delete_table_cron_hook', 'checkview_delete_tables_data' );
}

if ( ! function_exists( 'checkview_gf_should_defer_delete' ) ) {
	/**
	 * Whether to defer GF entry deletion via cron. Defaults to true (the fix).
	 *
	 * Customers can opt out via `define( 'CHECKVIEW_GF_DEFER_ENTRY_DELETE', false );`
	 * in wp-config.php as an emergency rollback to legacy synchronous deletion.
	 *
	 * @return bool
	 */
	function checkview_gf_should_defer_delete() {
		if ( defined( 'CHECKVIEW_GF_DEFER_ENTRY_DELETE' ) && false === CHECKVIEW_GF_DEFER_ENTRY_DELETE ) {
			return false;
		}
		return true;
	}
}

if ( ! function_exists( 'checkview_gf_run_deferred_entry_delete' ) ) {
	/**
	 * Cron handler: deletes a GF entry deferred from form submission.
	 *
	 * The deletion is deferred so GF async feed processing (GF_Background_Process)
	 * has time to load the entry and fire third-party integrations. Without this
	 * delay, GF_Feed_Processor::task() aborts with "entry not found".
	 *
	 * @param int $entry_id GF entry ID.
	 * @return void
	 */
	function checkview_gf_run_deferred_entry_delete( $entry_id ) {
		if ( ! class_exists( 'GFAPI' ) ) {
			return; // Gravity Forms not active anymore — nothing to delete.
		}
		$result = GFAPI::delete_entry( (int) $entry_id );
		// Log only real failures. Skip 'invalid_entry_id' — that's the expected
		// case when the entry was already cleaned up (admin manual delete, etc.)
		// and would otherwise spam ip-logs (logs file grows unbounded).
		if ( is_wp_error( $result )
			&& 'invalid_entry_id' !== $result->get_error_code()
			&& class_exists( 'Checkview_Admin_Logs' ) ) {
			Checkview_Admin_Logs::add(
				'ip-logs',
				'GF deferred entry delete failed for entry [' . (int) $entry_id . ']: ' . $result->get_error_message()
			);
		}
	}
	add_action( 'checkview_gf_deferred_entry_delete', 'checkview_gf_run_deferred_entry_delete' );
}

if ( ! function_exists( 'checkview_ff_should_defer_delete' ) ) {
	/**
	 * Whether to defer Fluent Forms entry deletion via cron. Defaults to true (the fix).
	 *
	 * Customers can opt out via `define( 'CHECKVIEW_FF_DEFER_ENTRY_DELETE', false );`
	 * in wp-config.php as an emergency rollback to legacy synchronous deletion.
	 *
	 * @return bool
	 */
	function checkview_ff_should_defer_delete() {
		if ( defined( 'CHECKVIEW_FF_DEFER_ENTRY_DELETE' ) && false === CHECKVIEW_FF_DEFER_ENTRY_DELETE ) {
			return false;
		}
		return true;
	}
}

if ( ! function_exists( 'checkview_ff_run_deferred_entry_delete' ) ) {
	/**
	 * Cron handler: deletes a Fluent Forms entry deferred from form submission.
	 *
	 * The deletion is deferred so Fluent Forms Pro async feed processing
	 * (Mailchimp, Webhooks, Slack, Zapier, HubSpot, Pipedrive, etc.) has time to
	 * load the submission row and fire third-party integrations. Without this
	 * delay, queued feed handlers re-fetch the submission ~seconds later and
	 * silently abort when the row is gone.
	 *
	 * @param int $entry_id Fluent Forms submission ID.
	 * @param int $form_id  Fluent Forms form ID.
	 * @return void
	 */
	function checkview_ff_run_deferred_entry_delete( $entry_id = 0, $form_id = 0 ) {
		if ( ! function_exists( 'wpFluent' ) ) {
			return; // Fluent Forms not active anymore — nothing to delete.
		}
		$entry_id = (int) $entry_id;
		$form_id  = (int) $form_id;

		// Defensive: refuse to run with degenerate IDs. FF auto-increments id from 1
		// and form_id is never zero for real submissions, so a 0/negative pair can
		// only come from coercion of garbage args (string/array/null) and would
		// otherwise execute WHERE id=0 AND form_id=0 — a no-op today, but a footgun
		// if FF ever seeds either column with 0.
		if ( $entry_id <= 0 || $form_id <= 0 ) {
			return;
		}

		wpFluent()->table( 'fluentform_submissions' )
			->where( 'form_id', $form_id )
			->where( 'id', '=', $entry_id )
			->delete();
		wpFluent()->table( 'fluentform_entry_details' )
			->where( 'form_id', $form_id )
			->where( 'submission_id', '=', $entry_id )
			->delete();
	}
	add_action( 'checkview_ff_deferred_entry_delete', 'checkview_ff_run_deferred_entry_delete', 10, 2 );
}

if ( ! function_exists( 'checkview_nf_should_defer_delete' ) ) {
	/**
	 * Whether to defer Ninja Forms entry deletion via cron. Defaults to true
	 * (the fix).
	 *
	 * Customers can opt out via `define( 'CHECKVIEW_NF_DEFER_ENTRY_DELETE', false );`
	 * in wp-config.php as an emergency rollback to legacy synchronous deletion.
	 *
	 * @return bool
	 */
	function checkview_nf_should_defer_delete() {
		if ( defined( 'CHECKVIEW_NF_DEFER_ENTRY_DELETE' ) && false === CHECKVIEW_NF_DEFER_ENTRY_DELETE ) {
			return false;
		}
		return true;
	}
}

if ( ! function_exists( 'checkview_nf_run_deferred_entry_delete' ) ) {
	/**
	 * Cron handler: deletes a Ninja Forms submission deferred from form
	 * submission. NF stores submissions as a custom post type (`nf_sub`), so
	 * deletion is via `wp_delete_post()`.
	 *
	 * Defense-in-depth: when `disable_actions=no` (integrations opted in),
	 * stock NF + Add-Ons Pack runs Mailchimp/Webhooks synchronously inside
	 * the actions pipeline before the priority-99 cleanup, so there is no
	 * known async-feed orphaning today. (When `disable_actions=true` —
	 * the default for tests — those add-ons are stripped from the actions
	 * list and never run, sync or async.) Deferring matches the FF/GF
	 * pattern so any future NF add-on that queues async work keyed on the
	 * `nf_sub` post ID won't silently fail.
	 *
	 * @param int $entry_id NF submission post ID.
	 * @return void
	 */
	function checkview_nf_run_deferred_entry_delete( $entry_id = 0 ) {
		$entry_id = (int) $entry_id;
		if ( $entry_id <= 0 ) {
			return;
		}
		// Post-type confinement: refuse to delete anything that isn't an NF
		// submission. wp_delete_post() is type-agnostic, so without this
		// guard a stale/poisoned cron event with an arbitrary post ID could
		// force-delete unrelated content (pages, products, attachments).
		// The FF/GF analogues are inherently scoped (FF to FF tables, GF via
		// GFAPI::delete_entry); NF needs an explicit check to match.
		if ( 'nf_sub' !== get_post_type( $entry_id ) ) {
			return;
		}
		// The post may already be gone (admin manual delete, etc.) —
		// wp_delete_post returns false in that case and is a no-op.
		wp_delete_post( $entry_id, true );
	}
	add_action( 'checkview_nf_deferred_entry_delete', 'checkview_nf_run_deferred_entry_delete' );
}

if ( ! function_exists( 'checkview_update_woocommerce_product_status' ) ) {
	/**
	 * Update status of test product.
	 *
	 * @param [int]    $product_id test product id.
	 * @param [string] $status status to set.
	 * @return bool/WP_ERROR
	 */
	function checkview_update_woocommerce_product_status( $product_id, $status ) {
		// Check if the product ID is valid and status is either 'publish' or 'draft'.
		$updated        = 0;
		$allowed_status = array( 'publish', 'draft' );

		Checkview_Admin_Logs::add( 'api-logs', 'Updating status of test product [' . $product_id . '] to [' . $status . ']...' );
		if ( get_post_type( $product_id ) === 'product' && in_array( $status, $allowed_status, true ) ) {
			// Update the post status.
			$updated = wp_update_post(
				array(
					'ID'          => $product_id,
					'post_status' => $status,
				)
			);
			checkview_delete_transient_everywhere( 'checkview_store_products_transient' );
			Checkview_Admin_Logs::add( 'api-logs', 'Updated status of test product and cleared product caches.' );
		} else {
			Checkview_Admin_Logs::add( 'api-logs', 'Called [checkview_update_woocommerce_product_status] on invalid post type or with invalid status (accepts ' . implode( ', ', $allowed_status ) . ', got ' . $status . ').' );
		}
		return $updated;
	}
}

if ( ! function_exists( 'array_find' ) ) {
	/**
	 * Finds the first element in the array that satisfies the callback condition.
	 *
	 * Essentially polyfills `array_find`, which was introduced in PHP v8.4.0.
	 *
	 * @param array    $array The array to search.
	 * @param callable $callback The callback function to test each element.
	 * @return mixed The first matching element, or null if none found.
	 */
	function array_find( array $array, callable $callback ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, Universal.NamingConventions.NoReservedKeywordParameterNames.arrayFound -- PHP parity.
		foreach ( $array as $key => $value ) {
			if ( $callback( $value, $key ) ) {
				return $value;
			}
		}

		return null;
	}
}

if ( ! function_exists( 'checkview_update_option' ) ) {
	/**
	 * Updates an option in WordPress.
	 *
	 * Wrapper for update_option() that also includes logging. Suppresses logs from
	 * failures due to option already existing with the same value.
	 *
	 * @see update_option()
	 * @see get_option()
	 *
	 * @param string       $option Name of the option to update.
	 * @param mixed        $value Option value.
	 * @param boolean|null $autoload Optional. Whether to load the option when WordPress starts up.
	 *
	 * @return boolean True if the value was updated, false otherwise.
	 */
	function checkview_update_option( $option, $value, $autoload = null ) {
		$old_option = get_option( $option );
		$result     = update_option( $option, $value, $autoload );

		if ( $result ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r -- Desired production debug logging.
			Checkview_Admin_Logs::add( 'ip-logs', 'Updated option [' . $option . '] with value [' . print_r( $value, true ) . '].' );
		} elseif ( false !== $old_option && $old_option !== $value && maybe_serialize( $old_option ) !== maybe_serialize( $value ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r -- Desired production debug logging.
			Checkview_Admin_Logs::add( 'ip-logs', 'Failed updating option [' . $option . '] with value [' . print_r( $value, true ) . '].' );
		}

		return $result;
	}
}

if ( ! function_exists( 'checkview_delete_option' ) ) {
	/**
	 * Deletes an option in WordPress.
	 *
	 * Wrapper for delete_option() that also includes logging. Only attempts
	 * deletion if the option is found in the database.
	 *
	 * @see delete_option()
	 * @see get_option()
	 *
	 * @param string $option Name of the option to delete.
	 *
	 * @return boolean True if the value was deleted, false otherwise.
	 */
	function checkview_delete_option( $option ) {
		$current_option = get_option( $option );

		if ( false !== $current_option ) {
			$result = delete_option( $option );

			if ( $result ) {
				Checkview_Admin_Logs::add( 'ip-logs', 'Deleted option [' . $option . '].' );
			} else {
				Checkview_Admin_Logs::add( 'ip-logs', 'Failed deleting option [' . $option . '].' );
			}

			return $result;
		}

		return true;
	}
}

if ( ! function_exists( 'checkview_get_cv_entry_string_limits' ) ) {
	/**
	 * Returns the cv_entry string-column max-length map.
	 *
	 * Single source of truth mirroring the schema declared in
	 * Checkview_Activator::checkview_run_sql(). When the schema widens or
	 * narrows a varchar, update both places.
	 *
	 * Columns intentionally omitted:
	 *   - uid varchar(255): generated internally (UUID, ~36 chars).
	 *   - response longtext: no varchar limit.
	 *   - meta_key/meta_value: belong to cv_entry_meta, not cv_entry.
	 *
	 * @return array<string,int> Column name => max char length.
	 */
	function checkview_get_cv_entry_string_limits() {
		return array(
			'ip'             => 45,
			'source_url'     => 200,
			'user_agent'     => 250,
			'currency'       => 5,
			'payment_status' => 15,
			'payment_method' => 30,
			'transaction_id' => 50,
			'status'         => 20,
			'form_type'      => 20,
		);
	}
}

if ( ! function_exists( 'checkview_truncate_for_cv_entry' ) ) {
	/**
	 * Truncates string fields of a cv_entry-bound row to the schema's
	 * varchar limits.
	 *
	 * WordPress's wpdb::process_field_lengths() rejects inserts whose
	 * string values exceed the destination column's declared length,
	 * returning false from wpdb::insert() with last_error set to
	 * "Processing the value for the following field failed: <col>. The
	 * supplied value may be too long or contains invalid data." This
	 * helper pre-truncates so the insert succeeds and the prefix is
	 * preserved.
	 *
	 * Only mutates keys present in checkview_get_cv_entry_string_limits()
	 * and only when the value is a string. Other keys pass through.
	 *
	 * @param array $row Associative row about to be passed to wpdb::insert.
	 * @return array Row with overflowing string fields truncated.
	 */
	function checkview_truncate_for_cv_entry( array $row ) {
		$has_mb = function_exists( 'mb_substr' );
		foreach ( checkview_get_cv_entry_string_limits() as $col => $max ) {
			if ( isset( $row[ $col ] ) && is_string( $row[ $col ] ) ) {
				$row[ $col ] = $has_mb
					? mb_substr( $row[ $col ], 0, $max, 'UTF-8' )
					: substr( $row[ $col ], 0, $max );
			}
		}
		return $row;
	}
}

if ( ! function_exists( 'checkview_truncate_meta_key' ) ) {
	/**
	 * Truncates a cv_entry_meta meta_key to the schema's varchar(255) limit.
	 *
	 * Mirrors checkview_truncate_for_cv_entry() but targets the separate
	 * cv_entry_meta.meta_key column declared in
	 * Checkview_Activator::checkview_run_sql() (varchar(255)). Sources are
	 * unbounded plugin-side strings — CF7 raw POST keys, Fluent Forms
	 * field_name + sub_field_name, Forminator $field['name'], WS Form
	 * prefix + wsf_submit_meta.meta_key, Everest sanitize_key result —
	 * which can exceed 255 chars and silently fail the insert.
	 *
	 * Uses mb_substr (character-based) to match MySQL's utf8mb4 char-counting
	 * and avoid cutting mid-codepoint. Byte-based substr() would produce
	 * invalid UTF-8 that wpdb::process_field_lengths() then rejects with the
	 * same silent-failure mode this helper is meant to prevent.
	 *
	 * @param mixed $key The composed meta_key. Non-string values pass through unchanged.
	 * @return mixed Truncated string, or original value if not a string.
	 */
	function checkview_truncate_meta_key( $key ) {
		if ( ! is_string( $key ) ) {
			return $key;
		}
		return function_exists( 'mb_substr' )
			? mb_substr( $key, 0, 255, 'UTF-8' )
			: substr( $key, 0, 255 );
	}
}

if ( ! function_exists( 'checkview_should_allow_original_recipients' ) ) {
	/**
	 * Determines whether append-mode is active for the current request.
	 *
	 * Reads the per-test `allow_original_recipients_<test_id>` option (set during
	 * test-init when the SaaS sends `?allow_original_recipients=true`) and checks
	 * the companion `_set_at_<test_id>` timestamp.
	 *
	 * Keyed by test id, not site-scoped: the flag decides whether a customer's
	 * real recipients receive test email, so two concurrent tests must not be able
	 * to read each other's setting.
	 *
	 * Returns false on stale (>45min), missing, or future-dated timestamps to
	 * fail safe. The 45-minute window protects orphan flags BETWEEN tests
	 * (Cloud Run hard-caps a single test at 1200s/20min, so this window cannot
	 * be hit during a single test). Test-init reset is the deterministic
	 * cleanup path; this watchdog is the stale-flag rejection path.
	 *
	 * @return boolean True if append-mode should fire, false otherwise.
	 */
	function checkview_should_allow_original_recipients() {
		// Keyed by test id so two concurrent tests cannot read each other's
		// setting. Without a resolvable test id there is nothing to authorise
		// append-mode against, so fail closed.
		$cv_test_id = function_exists( 'checkview_get_test_id' ) ? checkview_get_test_id() : '';
		if ( empty( $cv_test_id ) ) {
			return false;
		}

		$flag = get_option( 'allow_original_recipients_' . $cv_test_id, false );
		if ( ! $flag ) {
			return false;
		}
		$set_at = (int) get_option( 'allow_original_recipients_set_at_' . $cv_test_id, 0 );
		if ( $set_at <= 0 || $set_at > time() || ( time() - $set_at ) > 2700 ) {
			return false;
		}
		return true;
	}
}

if ( ! function_exists( 'checkview_sanitize_crlf' ) ) {
	/**
	 * Strips CR and LF characters from a string to prevent email header
	 * injection.
	 *
	 * Defensive sanitizer applied to recipient and Reply-To values that flow
	 * through CheckView's append-mode paths into wp_mail. If a customer's
	 * form-configured recipient or Reply-To value contains embedded CRLF
	 * (whether by misconfiguration or a malicious form submission), an
	 * attacker could inject additional headers (e.g., a hidden Bcc) that
	 * route mail to external addresses. By stripping CRLF before we
	 * concatenate, we prevent this regardless of how `wp_mail` itself
	 * handles the value downstream.
	 *
	 * Returns non-string values unchanged (arrays, null, etc.).
	 *
	 * @param mixed $value Value to sanitize.
	 * @return mixed Sanitized value (string with CRLF removed, or input as-is).
	 */
	function checkview_sanitize_crlf( $value ) {
		if ( ! is_string( $value ) ) {
			return $value;
		}
		// CRLF stripping blocks RFC 5322 header injection. Null byte stripping
		// is defense-in-depth — legacy C-level mail extensions might treat \0
		// as a string terminator, truncating subsequent headers.
		return str_replace( array( "\r\n", "\r", "\n", "\0" ), '', $value );
	}
}

if ( ! function_exists( 'checkview_recipients_contain_test_email' ) ) {
	/**
	 * Checks whether CHECKVIEW_TEST_EMAIL is already present in a recipient list,
	 * using proper email-address parsing rather than naive substring match.
	 *
	 * Splits on commas (or semicolons), extracts the bare email address from
	 * each entry (handles "Name <addr>" RFC 2822 format and surrounding
	 * whitespace), and compares case-insensitively against CHECKVIEW_TEST_EMAIL.
	 *
	 * Avoids the substring-collision false positive where CHECKVIEW_TEST_EMAIL might
	 * appear within an unrelated address (e.g., `nottest_id@test-mail.checkview.io`
	 * incorrectly matching `test_id@test-mail.checkview.io`).
	 *
	 * @param array|string $recipients Recipients (array of strings, or comma-/semi-separated string).
	 *
	 * @return bool True if any entry's email matches CHECKVIEW_TEST_EMAIL, false otherwise.
	 */
	function checkview_recipients_contain_test_email( $recipients ) {
		if ( ! defined( 'CHECKVIEW_TEST_EMAIL' ) ) {
			return false;
		}

		if ( is_array( $recipients ) ) {
			$entries = $recipients;
		} else {
			$entries = preg_split( '/[,;]/', (string) $recipients );
		}

		$test_email_lower = strtolower( CHECKVIEW_TEST_EMAIL );

		foreach ( $entries as $entry ) {
			if ( ! is_string( $entry ) ) {
				continue;
			}
			$bare = strtolower( trim( $entry ) );

			// Extract email from "Name <addr>" RFC 2822 format. Anchor to the
			// LAST `<...>` so display names containing brackets (e.g.,
			// `<John> <john@x.com>`) resolve to the actual address, not the
			// display-name fragment.
			if ( preg_match( '/<([^<>]+)>\s*$/', $bare, $matches ) ) {
				$bare = trim( $matches[1] );
			}

			if ( $bare === $test_email_lower ) {
				return true;
			}
		}

		return false;
	}
}

if ( ! function_exists( 'checkview_append_test_email_string' ) ) {
	/**
	 * Appends CHECKVIEW_TEST_EMAIL to a comma-separated recipient string with dedup.
	 *
	 * Matches the existing replace-mode behavior's data shape: returns a string
	 * of comma-separated addresses. Skips append if CHECKVIEW_TEST_EMAIL is already
	 * present (defensive against double-firing filter chains and customer
	 * misconfiguration).
	 *
	 * @param string $recipients Existing comma-separated recipients (may be empty).
	 *
	 * @return string Recipients with CHECKVIEW_TEST_EMAIL appended (or unchanged if dedup hit).
	 */
	function checkview_append_test_email_string( $recipients ) {
		if ( ! defined( 'CHECKVIEW_TEST_EMAIL' ) ) {
			return $recipients;
		}
		if ( empty( $recipients ) ) {
			return CHECKVIEW_TEST_EMAIL;
		}
		if ( checkview_recipients_contain_test_email( $recipients ) ) {
			return checkview_sanitize_crlf( $recipients );
		}
		// Strip CRLF from existing recipient string before concatenation to
		// prevent header injection if the customer's form data contains
		// embedded line breaks.
		$sanitized = checkview_sanitize_crlf( $recipients );
		return rtrim( $sanitized, ", \t" ) . ',' . CHECKVIEW_TEST_EMAIL;
	}
}

if ( ! function_exists( 'checkview_append_test_email_array' ) ) {
	/**
	 * Appends CHECKVIEW_TEST_EMAIL to a recipient array with dedup.
	 *
	 * @param mixed $recipients Existing recipients (array or scalar — coerced to array).
	 *
	 * @return array Recipients with CHECKVIEW_TEST_EMAIL appended (or unchanged if dedup hit).
	 */
	function checkview_append_test_email_array( $recipients ) {
		if ( ! defined( 'CHECKVIEW_TEST_EMAIL' ) ) {
			return is_array( $recipients ) ? $recipients : (array) $recipients;
		}
		$list = is_array( $recipients ) ? $recipients : (array) $recipients;
		// Sanitize each entry — if a customer's form data contains embedded
		// CRLF in a single recipient entry, downstream wp_mail conversion
		// could inject headers when the array is joined into a comma string.
		$list = array_map( 'checkview_sanitize_crlf', $list );
		if ( checkview_recipients_contain_test_email( $list ) ) {
			return $list;
		}
		$list[] = CHECKVIEW_TEST_EMAIL;
		return $list;
	}
}

if ( ! function_exists( 'checkview_inject_reply_to_header' ) ) {
	/**
	 * Injects `Reply-To: CHECKVIEW_TEST_EMAIL` into a headers structure when
	 * append-mode is active. Appends to existing Reply-To rather than replacing.
	 *
	 * Defends against MTA variance: when the customer's mail server splits
	 * SMTP delivery per RCPT TO, Postmark may not see CHECKVIEW_TEST_EMAIL
	 * in the To header. Reply-To injection ensures Postmark's webhook payload
	 * always carries the test_id-bearing address, which the test-runner's regex
	 * fallback chain extracts.
	 *
	 * Handles both array (e.g. WC, Forminator, Fluent Forms) and string
	 * (e.g. CRLF-joined) header shapes per the calling helper's data model.
	 *
	 * @param mixed $headers Existing headers (array or CRLF-string).
	 *
	 * @return mixed Headers with Reply-To injected, in the same shape as input.
	 */
	function checkview_inject_reply_to_header( $headers ) {
		if ( ! checkview_should_allow_original_recipients() ) {
			return $headers;
		}
		if ( ! defined( 'CHECKVIEW_TEST_EMAIL' ) ) {
			return $headers;
		}

		// Defensive: some plugins may pass header objects. We only know how
		// to mutate arrays or strings — anything else passes through.
		if ( ! is_array( $headers ) && ! is_string( $headers ) && ! is_null( $headers ) ) {
			return $headers;
		}

		$is_array = is_array( $headers );

		// Split on any line-ending variant (\r\n, \n, \r) to be MTA-agnostic.
		// Some forms emit \n-only headers; bare \r is rare but defensive.
		$list = $is_array
			? $headers
			: ( empty( $headers ) ? array() : preg_split( '/\r\n|\r|\n/', $headers ) );

		$existing_index = null;
		foreach ( $list as $index => $header ) {
			// Tolerant Reply-To detector: matches `Reply-To:` and `Reply-To :`
			// (optional whitespace before colon). Case-insensitive.
			if ( is_string( $header ) && preg_match( '/^\s*Reply-To\s*:/i', $header ) ) {
				$existing_index = $index;
				break;
			}
		}

		$test_email_lower = strtolower( CHECKVIEW_TEST_EMAIL );

		if ( null === $existing_index ) {
			$list[] = 'Reply-To: ' . CHECKVIEW_TEST_EMAIL;
		} else {
			$existing = $list[ $existing_index ];

			// Sanitize CRLF unconditionally — neutralize any header injection
			// in the existing value before we touch it, regardless of whether
			// dedup hits or we append. Consistent with the other helpers
			// (checkview_append_test_email_string/array always sanitize).
			$sanitized               = checkview_sanitize_crlf( $existing );
			$list[ $existing_index ] = $sanitized;

			// Extract addresses already in this Reply-To and check via the
			// shared parser to avoid substring false positives.
			$value = trim( substr( $sanitized, strpos( $sanitized, ':' ) + 1 ) );

			if ( '' === $value ) {
				// Valueless Reply-To. Elementor Pro emits a bare `Reply-To:`
				// unconditionally when the form maps no reply-to field, and
				// appending to it would yield a malformed `Reply-To:, addr`.
				// Replace the whole header rather than append to nothing.
				$list[ $existing_index ] = 'Reply-To: ' . CHECKVIEW_TEST_EMAIL;
			} elseif ( ! checkview_recipients_contain_test_email( $value ) ) {
				$list[ $existing_index ] = rtrim( $sanitized, ", \t" ) . ', ' . CHECKVIEW_TEST_EMAIL;
			}
		}

		return $is_array ? $list : implode( "\r\n", $list );
	}
}

if ( ! function_exists( 'checkview_is_test_user_email' ) ) {
	/**
	 * Determines if an email address is a CheckView test inbox address.
	 *
	 * Exactly `<uuid>@test-mail.checkview.io`, where the uuid is a v4 test id.
	 * Plus-addressing, subdomains and look-alike domains do not match.
	 *
	 * @since 2.6.2
	 *
	 * @param string $email Email address.
	 * @return bool
	 */
	function checkview_is_test_user_email( $email ) {
		if ( ! is_string( $email ) || '' === $email ) {
			return false;
		}

		$email = strtolower( trim( $email ) );
		$at    = strrpos( $email, '@' );

		if ( false === $at || 'test-mail.checkview.io' !== substr( $email, $at + 1 ) ) {
			return false;
		}

		// Same pattern as `checkview_is_valid_uuid()`, without its log line:
		// the daily sweep checks every address on the test domain.
		return 1 === preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/', substr( $email, 0, $at ) );
	}
}

if ( ! function_exists( 'checkview_is_test_user' ) ) {
	/**
	 * Determines if a user was created by a CheckView test.
	 *
	 * True when the user carries the test id stamped at creation, or has a
	 * CheckView test inbox address. The address also covers users created by
	 * form tests (registration forms), which are never stamped.
	 *
	 * @since 2.6.2
	 *
	 * @param WP_User $user User.
	 * @return bool
	 */
	function checkview_is_test_user( $user ) {
		if ( ! $user instanceof WP_User || ! $user->exists() ) {
			return false;
		}

		if ( '' !== (string) get_user_meta( $user->ID, CheckView::META_TEST_USER, true ) ) {
			return true;
		}

		return checkview_is_test_user_email( $user->user_email );
	}
}

if ( ! function_exists( 'checkview_get_user_order_ids' ) ) {
	/**
	 * Returns the ids of every order a user placed, in any status, trash included.
	 *
	 * Empty when WooCommerce is not loaded.
	 *
	 * @since 2.6.2
	 *
	 * @param int $user_id User id.
	 * @return int[]
	 */
	function checkview_get_user_order_ids( $user_id ) {
		if ( ! function_exists( 'wc_get_orders' ) || ! function_exists( 'wc_get_order_statuses' ) ) {
			return array();
		}

		$statuses = array_unique(
			array_merge( array_keys( wc_get_order_statuses() ), array( 'wc-checkout-draft', 'trash' ) )
		);

		$order_ids = wc_get_orders(
			array(
				'customer_id' => (int) $user_id,
				'type'        => 'shop_order',
				'status'      => $statuses,
				'limit'       => -1,
				'return'      => 'ids',
			)
		);

		return is_array( $order_ids ) ? array_map( 'intval', $order_ids ) : array();
	}
}

if ( ! function_exists( 'checkview_skip_test_user' ) ) {
	/**
	 * Records that a test user must not be deleted, so the sweep stops retrying.
	 *
	 * @since 2.6.2
	 *
	 * @param int    $user_id User id.
	 * @param string $reason  Short reason code.
	 * @return void
	 */
	function checkview_skip_test_user( $user_id, $reason ) {
		update_user_meta( $user_id, 'checkview_test_user_skipped', $reason );
		Checkview_Admin_Logs::add( 'cron-logs', 'Kept CheckView test user [' . $user_id . '] (' . $reason . '); it will not be retried.' );
	}
}

if ( ! function_exists( 'checkview_record_test_user_failure' ) ) {
	/**
	 * Counts a failed clean-up attempt for a test user; gives up after three.
	 *
	 * @since 2.6.2
	 *
	 * @param int    $user_id User id.
	 * @param string $reason  Short reason code.
	 * @return void
	 */
	function checkview_record_test_user_failure( $user_id, $reason ) {
		$attempts = (int) get_user_meta( $user_id, 'checkview_test_user_attempts', true ) + 1;
		update_user_meta( $user_id, 'checkview_test_user_attempts', $attempts );
		Checkview_Admin_Logs::add( 'cron-logs', 'Could not clean up CheckView test user [' . $user_id . '] (' . $reason . ', attempt ' . $attempts . ').' );

		if ( $attempts >= 3 ) {
			checkview_skip_test_user( $user_id, $reason );
		}
	}
}

if ( ! function_exists( 'checkview_delete_test_customer' ) ) {
	/**
	 * Deletes a user created by a CheckView test.
	 *
	 * Refuses unless the user is a CheckView test user (see
	 * `checkview_is_test_user()`), holds only the roles a test creates, has no
	 * admin capabilities and has no orders left. On multisite the user is
	 * deleted from the network only when they belong to no other site.
	 *
	 * @since 2.6.2
	 *
	 * @param int $user_id User id.
	 * @return bool True when the user was deleted.
	 */
	function checkview_delete_test_customer( $user_id ) {
		$user_id = (int) $user_id;
		$user    = $user_id > 0 ? get_user_by( 'id', $user_id ) : false;

		if ( ! $user ) {
			return false;
		}

		// Not ours: a real account that a test happened to use. Leave it
		// untouched, including its meta.
		if ( ! checkview_is_test_user( $user ) ) {
			Checkview_Admin_Logs::add( 'cron-logs', 'Did not delete user [' . $user_id . ']: not a CheckView test user.' );
			return false;
		}

		if ( is_super_admin( $user_id ) || user_can( $user, 'manage_options' ) || user_can( $user, 'edit_users' ) ) {
			checkview_skip_test_user( $user_id, 'privileged' );
			return false;
		}

		/**
		 * Filters the roles a CheckView test user may hold and still be deleted.
		 *
		 * @since 2.6.2
		 *
		 * @param string[] $roles Role slugs.
		 */
		$allowed_roles = (array) apply_filters( 'checkview_test_user_roles', array( 'customer', 'subscriber' ) );
		$roles         = (array) $user->roles;

		if ( empty( $roles ) || array_diff( $roles, $allowed_roles ) ) {
			checkview_skip_test_user( $user_id, 'role:' . implode( ',', $roles ) );
			return false;
		}

		// Remaining orders are either another test's (the sweep deletes them
		// first) or real ones; either way the user stays for now.
		if ( checkview_get_user_order_ids( $user_id ) ) {
			Checkview_Admin_Logs::add( 'cron-logs', 'Did not delete CheckView test user [' . $user_id . '] yet: they still have orders.' );
			return false;
		}

		$test_id = (string) get_user_meta( $user_id, CheckView::META_TEST_USER, true );

		try {
			if ( is_multisite() ) {
				// Archived, spam and deleted sites included: the network delete
				// below removes the user's content on every site they belong to.
				$blogs = get_blogs_of_user( $user_id, true );

				if ( count( $blogs ) !== 1 || ! isset( $blogs[ get_current_blog_id() ] ) ) {
					remove_user_from_blog( $user_id, get_current_blog_id() );
					checkview_skip_test_user( $user_id, 'other_sites' );
					return false;
				}

				if ( ! function_exists( 'wpmu_delete_user' ) ) {
					require_once ABSPATH . 'wp-admin/includes/ms.php';
				}

				// WooCommerce cleans sessions, API keys and payment tokens on
				// `delete_user`, which `wpmu_delete_user()` does not fire.
				if ( function_exists( 'wc_delete_user_data' ) ) {
					wc_delete_user_data( $user_id );
				}

				$deleted = wpmu_delete_user( $user_id );
			} else {
				if ( ! function_exists( 'wp_delete_user' ) ) {
					require_once ABSPATH . 'wp-admin/includes/user.php';
				}

				$deleted = wp_delete_user( $user_id );
			}
		} catch ( \Throwable $e ) {
			Checkview_Admin_Logs::add( 'cron-logs', 'Deleting CheckView test user [' . $user_id . '] threw ' . get_class( $e ) . '.' );
			$deleted = false;
		}

		if ( $deleted ) {
			Checkview_Admin_Logs::add( 'cron-logs', 'Deleted CheckView test user [' . $user_id . '] for test [' . $test_id . '].' );
			return true;
		}

		checkview_record_test_user_failure( $user_id, 'delete_failed' );

		return false;
	}
}

if ( ! function_exists( 'checkview_sweep_test_users' ) ) {
	/**
	 * Daily clean-up of CheckView test users that no order clean-up removed.
	 *
	 * Catches users whose test order was never created (checkout failed after
	 * the account was made), users whose order clean-up never ran, and users
	 * created by form tests. Each candidate's CheckView test orders are
	 * deleted first; a candidate with any other order is kept for good.
	 *
	 * @since 2.6.2
	 *
	 * @return void
	 */
	function checkview_sweep_test_users() {
		$started = time();

		/**
		 * Filters how old a CheckView test user must be before the sweep deletes it.
		 *
		 * @since 2.6.2
		 *
		 * @param int $min_age Seconds since the user registered.
		 */
		$min_age = (int) apply_filters( 'checkview_test_user_min_age', DAY_IN_SECONDS );

		/**
		 * Filters how many test users one sweep run looks at.
		 *
		 * @since 2.6.2
		 *
		 * @param int $max_users Users per run.
		 */
		$max_users = (int) apply_filters( 'checkview_test_user_sweep_limit', 200 );
		$cutoff    = gmdate( 'Y-m-d H:i:s', time() - $min_age );

		$not_skipped = array(
			'key'     => 'checkview_test_user_skipped',
			'compare' => 'NOT EXISTS',
		);

		$queries = array(
			'stamped'    => array(
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Daily cron, capped.
					'relation' => 'AND',
					array(
						'key'     => CheckView::META_TEST_USER,
						'compare' => 'EXISTS',
					),
					$not_skipped,
				),
			),
			'email_only' => array(
				'search'         => '*@test-mail.checkview.io',
				'search_columns' => array( 'user_email' ),
				'meta_query'     => array( $not_skipped ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Daily cron, capped.
			),
		);

		$seen    = array();
		$checked = 0;
		$counts  = array(
			'stamped'    => 0,
			'email_only' => 0,
			'deleted'    => 0,
			'kept'       => 0,
		);

		foreach ( $queries as $label => $query ) {
			while ( $checked < $max_users && ( time() - $started ) < 20 ) {
				$user_ids = get_users(
					array_merge(
						$query,
						array(
							'fields'     => 'ID',
							'number'     => 100,
							'orderby'    => 'ID',
							'order'      => 'ASC',
							'exclude'    => $seen,
							'date_query' => array(
								array(
									'before' => $cutoff,
								),
							),
						)
					)
				);

				if ( empty( $user_ids ) ) {
					break;
				}

				foreach ( $user_ids as $user_id ) {
					$user_id = (int) $user_id;
					$seen[]  = $user_id;
					++$checked;
					$user = get_user_by( 'id', $user_id );

					// The email query is a suffix match; the exact check runs here.
					if ( ! checkview_is_test_user( $user ) ) {
						continue;
					}

					++$counts[ $label ];

					$has_real_orders = false;
					$order_failed    = false;

					foreach ( checkview_get_user_order_ids( $user_id ) as $order_id ) {
						$order = wc_get_order( $order_id );

						if ( ! $order ) {
							// Listed but will not load: unknown, so retry later.
							$order_failed = true;
						} elseif ( 'checkview' !== $order->get_meta( 'payment_made_by' ) && 'checkview' !== $order->get_payment_method() ) {
							$has_real_orders = true;
						} elseif ( ! class_exists( 'Checkview_Woo_Automated_Testing' ) || 0 === Checkview_Woo_Automated_Testing::checkview_delete_orders( $order_id, false ) ) {
							$order_failed = true;
						}
					}

					if ( $has_real_orders ) {
						checkview_skip_test_user( $user_id, 'has_real_orders' );
						++$counts['kept'];
					} elseif ( $order_failed ) {
						checkview_record_test_user_failure( $user_id, 'order_delete_failed' );
						++$counts['kept'];
					} elseif ( checkview_delete_test_customer( $user_id ) ) {
						++$counts['deleted'];
					} else {
						++$counts['kept'];
					}

					if ( $checked >= $max_users || ( time() - $started ) >= 20 ) {
						break;
					}
				}
			}
		}

		if ( $checked ) {
			Checkview_Admin_Logs::add(
				'cron-logs',
				sprintf(
					'Test user sweep: %d stamped, %d matched by email only, %d deleted, %d kept.',
					$counts['stamped'],
					$counts['email_only'],
					$counts['deleted'],
					$counts['kept']
				)
			);
		}
	}
}

if ( ! function_exists( 'checkview_auth_header_for_request' ) ) {
	/**
	 * Picks the token a CheckView API request authenticates with.
	 *
	 * The `Authorization` header comes first, so nothing changes for sites that
	 * work today. On a site behind HTTP basic auth that header carries the
	 * gate's own `Basic` credentials, and some Apache setups drop it before
	 * PHP, so CheckView also sends the same token as the `_checkview_token`
	 * parameter, in the query string. The token stays single use: the
	 * permission check consumes its nonce whichever way it arrived.
	 *
	 * @since 2.6.3
	 *
	 * @param string $auth_header Sanitized `Authorization` header, or ''.
	 * @param mixed  $query_token The `_checkview_token` request parameter, if any.
	 * @return string `Bearer <token>`, or the header unchanged.
	 */
	function checkview_auth_header_for_request( $auth_header, $query_token ) {
		if ( 0 === strpos( $auth_header, 'Bearer ' ) || ! is_string( $query_token ) ) {
			return $auth_header;
		}

		$query_token = trim( sanitize_text_field( $query_token ) );
		if ( '' === $query_token ) {
			return $auth_header;
		}

		return 0 === strpos( $query_token, 'Bearer ' ) ? $query_token : 'Bearer ' . $query_token;
	}
}

if ( ! function_exists( 'checkview_request_token_for_app_password_check' ) ) {
	/**
	 * The CheckView token a request carries, if any, as `Bearer <token>`.
	 *
	 * The test browser sends the `X-CheckView-Signature` header on every
	 * same-site request; CheckView's servers send `_checkview_token` in the
	 * query string (never in a POST body, so only the query string is read).
	 * When both are present the header is used, even if it later fails to
	 * validate. Presence only: the caller validates it.
	 *
	 * @since 2.6.3
	 *
	 * @return string `Bearer <token>`, or '' when the request carries none.
	 */
	function checkview_request_token_for_app_password_check() {
		$token = '';
		if ( ! empty( $_SERVER['HTTP_X_CHECKVIEW_SIGNATURE'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JWT; validated by checkview_validate_jwt_token().
			$token = trim( wp_unslash( $_SERVER['HTTP_X_CHECKVIEW_SIGNATURE'] ) );
		} elseif ( isset( $_GET['_checkview_token'] ) && is_string( $_GET['_checkview_token'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Signed token from CheckView, validated by the caller.
			$token = trim( sanitize_text_field( wp_unslash( $_GET['_checkview_token'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Signed token from CheckView, validated by the caller.
		}

		if ( '' === $token ) {
			return '';
		}

		return 0 === strpos( $token, 'Bearer ' ) ? $token : 'Bearer ' . $token;
	}
}

if ( ! function_exists( 'checkview_skip_application_password_for_checkview' ) ) {
	/**
	 * Stops WordPress reading a CheckView request's basic-auth credentials as
	 * an application password login.
	 *
	 * On a site behind HTTP basic auth every request carries the gate's
	 * `Authorization: Basic` header. If the site has ever created an
	 * application password and something asks for the current user during a
	 * REST request (WooCommerce does on every one), WordPress tries to log in
	 * with those credentials, fails, and answers 401.
	 *
	 * On CheckView's own routes that error is already discarded for a
	 * logged-out request (Checkview_Admin::bypass_rest_authentication()), so
	 * this filter is not what lets CheckView's helper calls in. It is for
	 * CheckView requests to other REST routes while they carry the gate's
	 * credentials: the site-info call to the REST index when a site is added,
	 * and the test browser's requests, for example a form that submits over
	 * the REST API. On CheckView's own routes it means a call no longer counts as a failed
	 * login, and never runs as a user whose application password happens to
	 * match the gate's credentials.
	 *
	 * This turns that login attempt off only for a request that carries a valid
	 * CheckView signature or token. Every other request is returned untouched,
	 * so the site's own application password users are unaffected. The filter
	 * never picks a user; at most it leaves this request anonymous. The token's
	 * nonce is not consumed here: the permission check does that.
	 *
	 * Anyone can send a Basic header with a made-up token, so a refused token
	 * writes at most one log line per 10 minutes, and a token is validated at
	 * most once per request.
	 *
	 * @since 2.6.3
	 *
	 * @param bool $is_api_request Whether WordPress treats this as an API request.
	 * @return bool
	 */
	function checkview_skip_application_password_for_checkview( $is_api_request ) {
		static $running    = false;
		static $last_check = null;
		static $last_valid = false;

		// Page views, and calls outside WordPress's own lookup of the current
		// user (for example a route that calls wp_authenticate() with posted
		// credentials), are left alone.
		if ( ! $is_api_request || ! doing_filter( 'determine_current_user' ) ) {
			return $is_api_request;
		}

		// Something asked for the current user again while this request's
		// CheckView token was being validated (for example a plugin hooked into
		// the public key download). Letting that nested login attempt run would
		// fail on the gate's credentials and bring back the 401, so turn it off
		// too. This can only leave the request anonymous.
		if ( $running ) {
			return false;
		}

		$token = checkview_request_token_for_app_password_check();
		if ( '' === $token ) {
			return $is_api_request;
		}

		// WordPress can look up the current user more than once per request.
		// The request's start time is part of the key so the answer never
		// carries over to a later request in runtimes that keep PHP running
		// between requests (worker modes).
		$request_time = isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ? (string) (float) $_SERVER['REQUEST_TIME_FLOAT'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Cast to float.
		$this_check   = $request_time . '|' . $token;
		if ( $this_check !== $last_check ) {
			$running = true;
			try {
				$nonce = checkview_validate_jwt_token( $token, false );
			} finally {
				$running = false;
			}
			$last_check = $this_check;
			$last_valid = is_string( $nonce ) && '' !== $nonce;

			if ( ! $last_valid && ! get_transient( 'checkview_app_password_skip_refused_logged' ) ) {
				set_transient( 'checkview_app_password_skip_refused_logged', 1, 10 * MINUTE_IN_SECONDS );
				Checkview_Admin_Logs::add( 'api-logs', 'A request with HTTP basic auth credentials carried a CheckView token that did not validate, so WordPress was left to check the credentials as an application password. Repeats are not logged for 10 minutes.' );
			}
		}

		return $last_valid ? false : $is_api_request;
	}
}
add_filter( 'application_password_is_api_request', 'checkview_skip_application_password_for_checkview' );
