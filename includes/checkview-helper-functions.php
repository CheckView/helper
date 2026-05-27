<?php
/**
 * CheckView Helper Functions
 *
 * Various helper functions used throughout CheckView.
 *
 * Some functions defined in this file are also attached to actions or filters.
 *
 * @since 1.0.0
 *
 * @package Checkview
 * @subpackage Checkview/includes
 */

if ( ! function_exists( 'checkview_validate_ip' ) ) {
	/**
	 * Validates an IP address.
	 *
	 * @param string $ip IP address.
	 * @return bool
	 */
	function checkview_validate_ip( string $ip ): bool {
		// Validate that the input is a valid IP address.
		if ( ! empty( $ip ) && ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return false;
		} elseif ( empty( $ip ) ) {
			return false;
		}
		return true;
	}
}
if ( ! function_exists( 'checkview_my_hcap_activate' ) ) {
	/**
	 * Disable hCaptcha for checkview tests.
	 *
	 * @param bool $activate Activate flag.
	 *
	 * @return bool
	 */
	function checkview_my_hcap_activate( $activate ) {
		$ip_options = array(
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
		foreach ( $ip_options as $key ) {
			if ( ! isset( $_SERVER[ $key ] ) ) {
				continue;
			}

			$key = isset( $_SERVER[ $key ] ) ? wp_strip_all_tags( wp_unslash( $_SERVER[ $key ] ) ) : '';
			foreach ( explode( ',', $key ) as $ip ) {
				// Just to be safe.
				$ip = trim( $ip );

				if ( checkview_validate_ip( $ip ) ) {
					$ip = sanitize_text_field( $ip );
				}
			}
		}
		// If validation fails, handle the error appropriately.
		if ( ! checkview_validate_ip( $ip ) ) {
			return $activate;
		}
		// Deactive for tests.
		if ( ( function_exists( 'checkview_get_visitor_ip' ) && CheckView::is_bot() ) || 'checkview-saas' === get_option( $ip ) ) {
			return false;
		}
		return $activate;
	}
}

add_filter( 'hcap_activate', 'checkview_my_hcap_activate' );

if ( ! function_exists( 'checkview_hcap_whitelist_ip' ) ) {
	/**
	 * Whitelists CheckView SaaS IPs in hCaptcha.
	 *
	 * @param bool   $whitelisted Whether IP is currently whitelisted.
	 * @param string $ip IP.
	 * @return bool
	 */
	function checkview_hcap_whitelist_ip( $whitelisted, $ip ) {

		// Whitelist local IPs.
		if ( false === $ip ) {
			return true;
		}

		// Get SaaS IPs.
		if ( function_exists( 'checkview_get_api_ip' ) ) {
			$cv_bot_ip = checkview_get_api_ip();
		} else {
			return $whitelisted;
		}

		// Whitelist our IPs.
		if ( is_array( $cv_bot_ip ) && in_array( $ip, $cv_bot_ip ) ) {
			return true;
		}

		return $whitelisted;
	}
	add_filter( 'hcap_whitelist_ip', 'checkview_hcap_whitelist_ip', 10, 2 );
}

if ( ! function_exists( 'checkview_request_has_test_credentials' ) ) {
	/**
	 * Returns true if the current request carries a valid CheckView test
	 * `test_id` + `test_type` pair in the URL query string.
	 *
	 * Used by `gform_loaded` bootstrap-time add-on removers that need to
	 * fire very early in the request lifecycle — before the helper plugin's
	 * normal init/cookie-based test detection has run. Only the initial GET
	 * to a tested page carries these in the query string; AJAX submissions
	 * resolve test context via cookie/referer instead (handled by
	 * `checkview_init_current_test` → `defined('TEST_EMAIL')` downstream).
	 *
	 * Gates on `$_GET` (not `$_REQUEST`) so a stale `checkview_test_id`
	 * cookie from a prior test run cannot trigger bootstrap-time add-on
	 * removal for unrelated subsequent requests from the same browser. The
	 * test runner always navigates with `?checkview_test_id=...&
	 * checkview_test_type=...` (browser context re-injects on every same-
	 * domain navigation), so `$_GET` is sufficient for legitimate traffic.
	 *
	 * Why a separate helper instead of reusing `checkview_is_valid_uuid()`:
	 * that function logs every invalid input to `ip-logs`, which would spam
	 * the log on every customer (non-test) request. This helper short-
	 * circuits silently when the test credentials aren't present.
	 *
	 * SYNC: UUID regex must match `checkview_is_valid_uuid()` in
	 * `checkview-functions.php`.
	 *
	 * @since 2.0.35
	 * @return bool
	 */
	function checkview_request_has_test_credentials() {
		if ( ! isset( $_GET['checkview_test_id'] )
			|| ! is_string( $_GET['checkview_test_id'] )
			|| empty( $_GET['checkview_test_type'] ) ) {
			return false;
		}
		$test_id = sanitize_text_field( wp_unslash( $_GET['checkview_test_id'] ) );
		return (bool) preg_match(
			'/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i',
			$test_id
		);
	}
}

if ( ! function_exists( 'remove_gravityforms_recaptcha_addon' ) ) {
	/**
	 * Removes ReCAPTCHA from GravityForms during CheckView tests.
	 *
	 * Fires on `gform_loaded` priority 1 (before the Add-On's priority-5
	 * bootstrap). Only acts on initial GETs that carry the test credentials
	 * in the URL query string; for AJAX form submissions the runtime
	 * `Checkview_Gforms_Helper::unhook_gf_recaptcha_addon()` handles
	 * removal via `remove_filter` on the live add-on instance.
	 *
	 * @return void
	 */
	function remove_gravityforms_recaptcha_addon() {
		if ( class_exists( 'GF_RECAPTCHA_Bootstrap' )
			&& checkview_request_has_test_credentials() ) {
			remove_action( 'gform_loaded', array( 'GF_RECAPTCHA_Bootstrap', 'load_addon' ), 5 );
		}
	}
}
// Use a hook with a priority higher than 5 to ensure the action is removed after it is added.
add_action( 'gform_loaded', 'remove_gravityforms_recaptcha_addon', 1 );

if ( ! function_exists( 'remove_gravityforms_turnstile_addon' ) ) {
	/**
	 * Removes the GF Cloudflare Turnstile Add-On from loading during CheckView
	 * tests. Sibling of `remove_gravityforms_recaptcha_addon()`.
	 *
	 * The add-on's slug is `gravityformscloudflareturnstile`; its bootstrap
	 * class is `GF_Turnstile_Bootstrap` and its loader method `load` is
	 * `public static` (registered on `gform_loaded` priority 5). Removing
	 * it before it runs prevents the add-on from registering the
	 * `turnstile` field type, so `GF_Field_Turnstile::validate` never
	 * executes for the test submission.
	 *
	 * Belt-and-braces: even without this early removal,
	 * `Checkview_Gforms_Helper::maybe_hide_recaptcha()` strips
	 * `turnstile`-typed fields on `gform_pre_validation`, and
	 * `is_anti_bot_failure()` covers the type in its allowlist. This
	 * function targets the initial-GET path (where `checkview_test_id`
	 * is in `$_REQUEST`); AJAX submissions resolve via the field-strip
	 * path instead.
	 *
	 * @since 2.0.35
	 * @return void
	 */
	function remove_gravityforms_turnstile_addon() {
		if ( class_exists( 'GF_Turnstile_Bootstrap' )
			&& checkview_request_has_test_credentials() ) {
			remove_action( 'gform_loaded', array( 'GF_Turnstile_Bootstrap', 'load' ), 5 );
		}
	}
}
// Use a hook with a priority higher than 5 to ensure the action is removed after it is added.
add_action( 'gform_loaded', 'remove_gravityforms_turnstile_addon', 1 );

add_filter(
	'wpforms_load_providers',
	'checkview_disable_addons_providers',
	10,
	1
);
if ( ! function_exists( 'checkview_disable_addons_providers' ) ) {
	/**
	 * Disbales addons for gravity forms.
	 *
	 * @param array $providers providers.
	 * @return array
	 */
	function checkview_disable_addons_providers( array $providers ): array {
		$cv_test_id = get_checkview_test_id();
		if ( ! $cv_test_id || 'true' != get_option( 'disable_actions_' . $cv_test_id, false ) ) {
			return $providers;
		}
		$providers = array();
		return $providers;
	}
}


add_filter(
	'wpforms_integrations_available',
	'checkview_disable_addons_feed',
	99,
	1
);
if ( ! function_exists( 'checkview_disable_addons_feed' ) ) {
	/**
	 * Disbale feeds.
	 *
	 * @param array $core_class_names classes avialable.
	 * @return array
	 */
	function checkview_disable_addons_feed( array $core_class_names ): array {
		$cv_test_id = get_checkview_test_id();
		if ( ! $cv_test_id || 'true' != get_option( 'disable_actions_' . $cv_test_id, false ) ) {
			return $core_class_names;
		}
		$core_class_names = array(
			'SMTP\Notifications',
			'WPCode\WPCode',
			'WPCode\RegisterLibrary',
			'Gutenberg\FormSelector',
			'WPMailSMTP\Notifications',
			'WPorg\Translations',
			'DefaultThemes\DefaultThemes',
			'Translations\Translations',
			'DefaultContent\DefaultContent',
			'PopupMaker\PopupMaker',
		);
		return $core_class_names;
	}
}

// Bypass Perfmatters.
add_filter(
	'perfmatters_rest_api_exceptions',
	function ( $exceptions ) {
		$exceptions[] = 'checkview';
		$exceptions[] = 'rest_route';
		return $exceptions;
	}
);
// Bypass WP Security.
if ( is_plugin_active( 'better-wp-security/better-wp-security.php' ) ) {
	add_filter(
		'itsec_white_ips',
		function ( $whitelisted_ips ) {
			if ( ! function_exists( 'checkview_get_visitor_ip' ) ) {
				return $whitelisted_ips;
			}
			$visitor_ip = checkview_get_visitor_ip();
			// Get SaaS IPs.
			if ( function_exists( 'checkview_get_api_ip' ) ) {
				$cv_bot_ip = checkview_get_api_ip();
			} else {
				return $whitelisted_ips;
			}

			// Whitelist our IPs.
			if ( is_array( $cv_bot_ip ) && in_array( $visitor_ip, $cv_bot_ip ) ) {
				return array_merge( $whitelisted_ips, (array) $visitor_ip );
			}
			return $whitelisted_ips;
		},
		0
	);

}
// Bypass WP Defender.
if ( is_plugin_active( 'defender-security/wp-defender.php' ) ) {
	add_filter(
		'ip_lockout_default_whitelist_ip',
		function ( $whitelisted_ips ) {
			if ( ! function_exists( 'checkview_get_visitor_ip' ) ) {
				return $whitelisted_ips;
			}
			$visitor_ip = checkview_get_visitor_ip();
			// Get SaaS IPs.
			if ( function_exists( 'checkview_get_api_ip' ) ) {
				$cv_bot_ip = checkview_get_api_ip();
			} else {
				return $whitelisted_ips;
			}

			// Whitelist our IPs.
			if ( is_array( $cv_bot_ip ) && in_array( $visitor_ip, $cv_bot_ip ) ) {
				return array_merge( $whitelisted_ips, (array) $visitor_ip );
			}
			return $whitelisted_ips;
		},
		10
	);
}
// Bypass WP Force Login.
if ( is_plugin_active( 'wp-force-login/wp-force-login.php' ) ) {
	add_filter(
		'v_forcelogin_bypass',
		function ( $bypass, $visited_url ) {
			if ( CheckView::is_bot() ) {
				return true;
			}
			return $bypass;
		},
		10,
		2
	);
}
