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
		if ( isset( $_REQUEST['checkview_test_id'] ) || 'checkview-saas' === get_option( $ip ) ) {
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

if ( ! function_exists( 'remove_gravityforms_recaptcha_addon' ) ) {
	/**
	 * Removes ReCAPTCHA from GravityForms during CheckView tests.
	 *
	 * @return void
	 */
	function remove_gravityforms_recaptcha_addon() {
		// Make sure the class exists before trying to remove the action.
		if ( class_exists( 'GF_RECAPTCHA_Bootstrap' ) && isset( $_REQUEST['checkview_test_id'] ) ) {
			remove_action( 'gform_loaded', array( 'GF_RECAPTCHA_Bootstrap', 'load_addon' ), 5 );
		}
	}
}
// Use a hook with a priority higher than 5 to ensure the action is removed after it is added.
add_action( 'gform_loaded', 'remove_gravityforms_recaptcha_addon', 1 );

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
		if ( false == get_option( 'disable_actions', false ) ) {
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
		if ( false == get_option( 'disable_actions', false ) ) {
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
