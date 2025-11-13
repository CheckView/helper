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

if ( ! function_exists( 'checkview_ensure_trailing_slash' ) ) {
	/**
	 * Ensures a string ends with a trailing slash.
	 *
	 * @since 2.0.13
	 *
	 * @param string $string String to ensure trailing slash.
	 * @return string
	 */
	function checkview_ensure_trailing_slash( $string ) {
		return rtrim( $string, '/' ) . '/';
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
	 * @return string|bool|WP_Error
	 */
	function checkview_validate_jwt_token( $token ) {

		$key = checkview_get_publickey();
		// Ensure the header is present.
		if ( ! $token ) {
			Checkview_Admin_Logs::add( 'api-logs', 'Authorization header not found.' );
			return new WP_Error(
				'no_auth_header',
				'There was a technical error while processing your request.',
				array( 'status' => 401 )
			);
		}

		// Check if it contains a Bearer token.
		if ( strpos( $token, 'Bearer ' ) !== 0 ) {
			Checkview_Admin_Logs::add( 'api-logs', 'Authorization header must start with "Bearer "' );
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
			// Allow one-second leeway for JWT tokens
			JWT::$leeway = 5;
			$decoded = JWT::decode( $token, new Key( $key, 'RS256' ) );
		} catch ( Exception $e ) {
			Checkview_Admin_Logs::add( 'api-logs', esc_html( $e->getMessage() ) );
			return new WP_Error(
				'invalid_auth_header',
				'There was a technical error while processing your request.',
				array( 'status' => 401 )
			);
		}
		$jwt = (array) $decoded;
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
			Checkview_Admin_Logs::add( 'api-logs', 'Invalid site url.' );
			return false;
		}

		// If token expired, return false.
		if ( $jwt['exp'] < time() ) {
			Checkview_Admin_Logs::add( 'api-logs', 'Token Expired.' );
			return false;
		}

		// If empty, return false.
		if ( empty( $jwt['_checkview_nonce'] ) ) {
			Checkview_Admin_Logs::add( 'api-logs', 'Nonce Absent.' );
			return false;
		}

		// Return determined token.
		return $jwt['_checkview_nonce'];
	}
}
if ( ! function_exists( 'get_checkview_test_id' ) ) {
	/**
	 * Gets a test ID from the request.
	 *
	 * Determine a test ID from the `$_REQUEST` superglobal, or from the
	 * referrer. Returns `false` if the test ID is determined to be invalid.
	 *
	 * @return string|false Test ID, or `false` on failure.
	 */
	function get_checkview_test_id() {
		$cv_test_id = isset( $_REQUEST['checkview_test_id'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['checkview_test_id'] ) ) : '';

		if ( ! empty( $cv_test_id ) ) {
			if ( ! checkview_is_valid_uuid( $cv_test_id ) ) {
				return false;
			}
			return $cv_test_id;
		} else {
			$referer_url = isset( $_SERVER['HTTP_REFERER'] ) ? sanitize_url( sanitize_text_field( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) ) : '';
			$referer_url = wp_parse_url( $referer_url, PHP_URL_QUERY );
			$qry_str     = array();
			if ( $referer_url ) {
				parse_str( $referer_url, $qry_str );
			}
			if ( ! empty( $qry_str['checkview_test_id'] ) && ! checkview_is_valid_uuid( $qry_str['checkview_test_id'] ) ) {
				return false;
			}
			if ( isset( $qry_str['checkview_test_id'] ) ) {
				return $qry_str['checkview_test_id'];
			}
		}
	}
}

if ( ! function_exists( 'complete_checkview_test' ) ) {
	/**
	 * Concludes a test.
	 *
	 * Given a test ID, deletes the test's options/flags, and deletes test
	 * session data from the database. Clears test cookies.
	 *
	 * @param string $checkview_test_id Test ID.
	 * @return void
	 */
	function complete_checkview_test( string $checkview_test_id = '' ) {
		global $wpdb;

		Checkview_Admin_Logs::add( 'ip-logs', 'Completing test...' );

		if ( ! defined( 'CV_TEST_ID' ) ) {
			define( 'CV_TEST_ID', $checkview_test_id );
		}

		$session_table = $wpdb->prefix . 'cv_session';
		$visitor_ip = checkview_get_visitor_ip();
		$cv_session = checkview_get_cv_session( $visitor_ip, CV_TEST_ID );

		// Stop if session not found.
		if ( ! empty( $cv_session ) ) {
			$test_key = $cv_session[0]['test_key'];

			cv_delete_option( $test_key );
		}

		$result = $wpdb->delete(
			$session_table,
			array(
				'visitor_ip' => $visitor_ip,
				'test_id' => $checkview_test_id,
			)
		);

		if ( false === $result ) {
			Checkview_Admin_Logs::add( 'ip-logs', 'Failed to delete rows from session table [' . $session_table . '].' );
		}

		$entry_id = get_option( $checkview_test_id . '_wsf_entry_id', '' );
		$form_id  = get_option( $checkview_test_id . '_wsf_frm_id', '' );

		if ( ! empty( $form_id ) && ! empty( $entry_id ) ) {
			if ( class_exists( 'WS_Form_Submit' ) ) {
				$ws_form_submit = new WS_Form_Submit();
				$ws_form_submit->id = $entry_id;
				$ws_form_submit->form_id = $form_id;
				$ws_form_submit->db_delete( true, true, true );
			} else {
				Checkview_Admin_Logs::add( 'ip-logs', 'WS_Form_Submit class does not exist.' );
			}
		}

		cv_delete_option( $checkview_test_id . '_wsf_entry_id' );
		cv_delete_option( $checkview_test_id . '_wsf_frm_id' );
		cv_delete_option( $visitor_ip );

		setcookie( 'checkview_test_id', '', time() - 6600, COOKIEPATH, COOKIE_DOMAIN );
		setcookie( 'checkview_test_id' . $checkview_test_id, '', time() - 6600, COOKIEPATH, COOKIE_DOMAIN );

		cv_delete_option( 'disable_email_receipt' );
		cv_delete_option( 'disable_webhooks' );

		Checkview_Admin_Logs::add( 'ip-logs', 'Test complete.' );
	}
}

if ( ! function_exists( 'checkview_get_publickey' ) ) {
	/**
	 * Gets the SaaS' public key.
	 *
	 * Firstly, retrieve the public key from cache. If no cached key is found,
	 * request the public key from the SaaS.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	function checkview_get_publickey() {
		$public_key = get_transient( 'checkview_saas_pk' );
		if ( null === $public_key || '' === $public_key || empty( $public_key ) ) {
			$response   = wp_safe_remote_get(
				'https://app.checkview.io/api/helper/public_key',
				array(
					'method'  => 'GET',
					'timeout' => 500,
				)
			);
			$public_key = $response['body'];
			set_transient( 'checkview_saas_pk', $public_key, 12 * HOUR_IN_SECONDS );
		}
		return $public_key;
	}
}
if ( ! function_exists( 'checkview_get_api_ip' ) ) {
	/**
	 * Gets the SaaS' IP addressees.
	 *
	 * Firstly, checks to see if the IP addresses are cached. If none are found
	 * in the cache, requests the IPs from the SaaS' API and caches the results.
	 * If there is an issue retrieving the IPs from the SaaS, returns `null`. If
	 * there is an issue validating the IPs, returns `false`.
	 *
	 * @since 1.0.0
	 *
	 * @return string[]|null|false
	 */
	function checkview_get_api_ip() {
		$ip_address = get_transient( 'checkview_saas_ip_address' ) ? get_transient( 'checkview_saas_ip_address' ) : array();

		if ( empty( $ip_address ) ) {
			Checkview_Admin_Logs::add( 'ip-logs', 'Bot IP address list transient empty, requesting new IP addresses.' );

			$request = wp_remote_get(
				'https://verify.checkview.io/whitelist.json',
				array(
					'method'  => 'GET',
					'timeout' => 500,
				)
			);

			if ( is_wp_error( $request ) ) {
				$code = $request->get_error_code();
				$message = $request->get_error_message();

				Checkview_Admin_Logs::add( 'ip-logs', 'Request for new IP addresses failed with code [' . $code . ']. Message: ' . $message );

				return null;
			}

			$body = wp_remote_retrieve_body( $request );
			$data = json_decode( $body, true );

			if ( ! empty( $data ) && ! empty( $data['ipAddresses'] ) ) {
				$ip_address = $data['ipAddresses'];

				if ( ! empty( $ip_address ) && is_array( $ip_address ) ) {
					foreach ( $ip_address as $ip ) {
						// If validation fails, handle the error appropriately.
						if ( ! checkview_validate_ip( $ip ) ) {
							return false;
						}
					}
				} elseif ( ! checkview_validate_ip( $ip_address ) ) {
					return false;
				}

				set_transient( 'checkview_saas_ip_address', $ip_address, 12 * HOUR_IN_SECONDS );

				Checkview_Admin_Logs::add( 'ip-logs', 'Set bot IP address list transient to response data [' . wp_json_encode( $ip_address ) . ']' );
			} else {
				Checkview_Admin_Logs::add( 'ip-logs', 'IP addresses not found in request for new IP addresses.' );
			}
		}

		if ( ! is_array( $ip_address ) ) {
			$ip_address = (array) $ip_address;
		}

		if ( is_array( $ip_address ) ) {
			$ip_address[] = '::1';
			$ip_address[] = '188.251.23.194';
			$ip_address[] = '2001:8a0:e5d0:a900:70a5:138a:d159:5054';
		}

		return $ip_address;
	}
}
if ( ! defined( 'checkview_get_custom_header_keys_for_ip' ) ) {
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

if ( ! defined( 'checkview_get_server_value' ) ) {
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
		$ip = '';

		foreach ( $ip_options as $key ) {
			if ( ! isset( $_SERVER[ $key ] ) ) {
				continue;
			}

			$key = checkview_get_server_value( $key );

			foreach ( explode( ',', $key ) as $ip ) {
				$ip = trim( $ip );
				$ip = preg_replace('/:\d{1,5}$/', '', $ip);

				if ( $ip !== null && checkview_validate_ip( $ip ) && is_array( $cv_bot_ip ) && in_array( $ip, $cv_bot_ip ) ) {
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
		$api_url = "https://api.cleantalk.org/?method_name=private_list_get&user_token=$user_token&service_type=" . $service_type . '&product_id=1&service_id=' . $service_id;

		$response = wp_remote_get( $api_url, array(
			'timeout' => 20,
		) );

		if ( is_wp_error( $response ) ) {
			error_log( 'Error fetching whitelisted IPs: ' . $response->get_error_message() );
			Checkview_Admin_Logs::add( 'ip-logs', esc_html__( 'Error fetching whitelisted IPs: ' . $response->get_error_message(), 'checkview' ) );

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

		if ( ! isset($apbct->data['service_id'] ) ) {
			error_log( 'CleanTalk service ID could not be found.' );
		}

		$service_id = $apbct->data['service_id'];
		$spbc_data  = get_option( 'cleantalk_data', array() );
		$user_token = $spbc_data['user_token'];

		if ( empty( $user_token ) || ( function_exists('apbct_api_key__is_correct') && ! apbct_api_key__is_correct() ) ) {
			return null;
		}

		$current_ip = checkview_get_visitor_ip();
		$api_ip     = checkview_get_api_ip();

		if ( is_array( $api_ip ) && in_array( $current_ip, $api_ip ) ) {
			$home_url = parse_url( home_url() ); // Returns `false`, `null`, or an assosiative array.

			if ( $home_url === false || $home_url === null ) {
				error_log( sprintf( 'Error parsing the home url [%1$s].', home_url() ) );
				Checkview_Admin_Logs::add( 'ip-logs', sprintf( 'Error parsing the home url [%1$s].', home_url() ) );

				return null;
			}

			if ( is_array( $home_url ) && ! isset( $home_url[ 'host' ] ) ) {
				error_log( sprintf( 'Cannot determine host when parsing URL [%1$s].', json_encode( $home_url ) ) );
				Checkview_Admin_Logs::add( 'ip-logs', sprintf( 'Cannot determine host when parsing URL [%1$s].', json_encode( $home_url ) ) );

				return null;
			}

			$host_name = $home_url['host'];

			// Check antispam whitelist.
			$antispam_ips = checkview_get_cleantalk_whitelisted_ips( 'antispam', $service_id );

			if ( is_array( $antispam_ips ) ) {
				if ( ! isset( $antispam_ips[ $host_name ] ) ) {
					error_log( 'No host name found in Antispam IP list.' );

					// If the host name is not in the whitelist, request to add our IPs/hosts.
					checkview_add_to_cleantalk( $user_token, 'antispam', $current_ip, 1, $service_id );
					checkview_add_to_cleantalk( $user_token, 'antispam', 'checkview.io', 4, $service_id );
					checkview_add_to_cleantalk( $user_token, 'antispam', 'test-mail.checkview.io', 4, $service_id );
				} else {
					// Otherwise, check for our IPs/hosts individually, and request to add them if needed.
					if ( is_array( $antispam_ips[ $host_name ] ) ) {
						$has_current_ip = array_find( $antispam_ips[ $host_name ], function( $value, $key ) use ($current_ip) {
							$position = strpos( $value, $current_ip );

							return $position === false ? false : true;
						});

						if ( ! $has_current_ip ) {
							checkview_add_to_cleantalk( $user_token, 'antispam', $current_ip, 1, $service_id );
						}

						$has_checkview_hostname = array_find( $antispam_ips[ $host_name ], function( $value, $key ) {
							$position = strpos( $value, 'checkview.io' );

							// Skip test-mail domain
							if ($value === 'test-mail.checkview.io') {
								return false;
							}

							return $position === false ? false : true;
						});

						if ( ! $has_checkview_hostname ) {
							checkview_add_to_cleantalk( $user_token, 'antispam', 'checkview.io', 4, $service_id );
						}

						$has_mail_hostname = array_find( $antispam_ips[ $host_name ], function( $value, $key ) {
							$position = strpos( $value, 'test-mail.checkview.io' );

							return $position === false ? false : true;
						});

						if ( ! $has_mail_hostname ) {
							checkview_add_to_cleantalk( $user_token, 'antispam', 'test-mail.checkview.io', 4, $service_id );
						}
					} else {
						error_log( sprintf( 'The value for antispam IPs at hostname [%1$s] is not an array.', $host_name ) );
						Checkview_Admin_Logs::add( 'ip-logs', sprintf( 'The value for antispam IPs at hostname [%1$s] is not an array.', $host_name ) );
					}
				}
			}

			// Check spamfirewall whitelist.
			$spamfirewall_ips = checkview_get_cleantalk_whitelisted_ips( 'spamfirewall', $service_id );

			if ( is_array( $spamfirewall_ips ) ) {
				if ( ! isset( $spamfirewall_ips[ $host_name ] ) ) {
					error_log( 'No host name found in Spam Firewall IP list.' );

					// If host name is not set, request to add it.
					checkview_add_to_cleantalk( $user_token, 'spamfirewall', $current_ip . '/32', 6, $service_id );
				} else {
					if ( is_array( $spamfirewall_ips[ $host_name ] ) ) {
						$has_current_ip = array_find( $spamfirewall_ips[ $host_name ], function( $value, $key ) use ($current_ip) {
							$position = strpos( $value, $current_ip );

							return $position === false ? false : true;
						});

						if ( ! $has_current_ip ) {
							checkview_add_to_cleantalk( $user_token, 'spamfirewall', $current_ip, 6, $service_id );
						}
					} else {
						error_log( sprintf( 'Value for spam firewall IPs at hostname [%1$s] is unexpected type [%2$s], expected array.', $host_name, gettype( $antispam_ips[ $host_name ] ) ) );
						Checkview_Admin_Logs::add( 'ip-logs', sprintf( 'Value for spam firewall IPs at hostname [%1$s] is unexpected type [%2$s], expected array.', $host_name, gettype( $antispam_ips[ $host_name ] ) ) );
					}
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
		error_log( sprintf(
			'Adding record [%1$s] with type [%2$s] and service type [%3$s] to CleanTalk\'s API with service id [%4$s]',
			$record,
			$record_type,
			$service_type,
			$service_id,
		) );

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

		delete_transient('checkview_whitelisted_ips_' . $service_type);

		if ( is_wp_error( $response ) ) {
			error_log( 'Request failed: ' . $response->get_error_message() );
			return null;
		}

		return json_decode( wp_remote_retrieve_body( $response ), true );
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
	 * @since 1.0.0
	 *
	 * @param string $ip IP address of the SAAS.
	 * @param int    $test_id Test ID to be executed.
	 * @return void|boolean
	 */
	function checkview_create_cv_session( $ip, $test_id ) {
		global $wp, $wpdb;
		if ( ! checkview_is_valid_uuid( $test_id ) ) {
			return;
		}

		// Return if already saved.
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
			// remove subdirectory from home url.
			$current_url = str_replace( '/' . $is_sub_directory[1], '', $current_url );
		}

		// Add WP's redirect URL string.
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_url( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		if ( ! empty( $request_uri ) && ! filter_var( $request_uri, FILTER_SANITIZE_URL ) ) {
			// If validation fails, handle the error appropriately.
			// Log the detailed error for internal use.
			Checkview_Admin_Logs::add( 'ip-logs', esc_html__( 'Invalid URL.', 'checkview' ) );
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

		// Retrieve the current post's ID based on its URL.
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
		$wpdb->insert( $session_table, $session_data );
	}
}
if ( ! function_exists( 'checkview_get_cv_session' ) ) {
	/**
	 * Retrieves a CheckView session from the database.
	 *
	 * @since 1.0.0
	 *
	 * @param int $ip IP address of the visitor.
	 * @param int $test_id Test ID to be conducted.
	 * @return array Array of results form DB.
	 */
	function checkview_get_cv_session( $ip, $test_id ) {
		global $wpdb;

		$session_table = $wpdb->prefix . 'cv_session';
		// WPDBPREPARE.
		$result = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$session_table}
				WHERE visitor_ip = %s
				AND test_id = %s
				LIMIT 1",
				$ip,
				$test_id
			),
			ARRAY_A
		);
		return $result;
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
		// WPDBPREPARE.
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}posts WHERE 1=1 AND (post_content LIKE %s) AND post_status=%s AND post_type NOT IN ('kadence_wootemplate', 'revision')",
				'%wp:block {\"ref\":' . $block_id . '}%',
				'publish'
			)
		);
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
		delete_transient( 'checkview_saas_pk' );
		delete_transient( 'checkview_saas_ip_address' );
		delete_transient( 'checkview_forms_list_transient' );
		delete_transient( 'checkview_forms_test_transient' );
		delete_transient( 'checkview_store_orders_transient' );
		delete_transient( 'checkview_store_products_transient' );
		delete_transient( 'checkview_store_shipping_transient' );
		delete_transient( 'checkview_whitelisted_ips_spamfirewall' );
		delete_transient( 'checkview_whitelisted_ips_antispam' );
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

		// Delete all entries from 'cv_entry' table.
		$table_entry = esc_sql( $wpdb->prefix . 'cv_entry' );
		$wpdb->query( "DELETE FROM $table_entry" );

		// Delete all entries from 'cv_entry_meta' table.
		$table_entry_meta = esc_sql( $wpdb->prefix . 'cv_entry_meta' );
		$wpdb->query( "DELETE FROM $table_entry_meta" );
	}

	// Attach the function to the cron event.
	add_action( 'checkview_delete_table_cron_hook', 'checkview_delete_tables_data' );
}
add_action(
	'wp_ajax_checkview_get_status',
	'checkview_get_option_data_handler'
);
add_action(
	'wp_ajax_nopriv_checkview_get_status',
	'checkview_get_option_data_handler'
);
if ( ! function_exists( 'checkview_get_option_data_handler' ) ) {
	/**
	 * WP AJAX callback that determines if the plugin is loaded.
	 *
	 * Handles WP AJAX requests that query whether the helper plugin is loaded or
	 * not. The plugin is determined to be loaded if the request is coming from
	 * the SaaS and the request includes a valid nonce.
	 *
	 * @return void
	 */
	function checkview_get_option_data_handler() {
		if ( empty( $_POST['_checkview_token'] ) ) {
			Checkview_Admin_Logs::add( 'api-logs', 'Token absent.' );
			wp_send_json_error( esc_html__( 'There was a technical error while processing your request.', 'checkview' ) );
			wp_die();
		}
		// Current Vsitor IP.
		$visitor_ip = checkview_get_visitor_ip();
		$api_ip     = checkview_get_api_ip();
		if ( ! is_array( $api_ip ) || ! in_array( $visitor_ip, $api_ip ) ) {
			Checkview_Admin_Logs::add( 'api-logs', 'Not SaaS.' );
			wp_send_json_error( esc_html__( 'There was a technical error while processing your request.', 'checkview' ) );
			wp_die();
		}

		$token       = sanitize_text_field( wp_unslash( $_POST['_checkview_token'] ) );
		$nonce_token = checkview_validate_jwt_token( $token );

		// Checking for JWT token.
		if ( empty( $nonce_token ) || is_wp_error( $nonce_token ) ) {
			Checkview_Admin_Logs::add( 'api-logs', 'Invalid token.' );
			wp_send_json_error( esc_html__( 'There was a technical error while processing your request.', 'checkview' ) );
			wp_die();
		}

		// Handle invalid nonce UUIDs.
		if ( ! checkview_is_valid_uuid( $nonce_token ) ) {
			// Log the detailed error for internal use.
			Checkview_Admin_Logs::add( 'api-logs', 'Invalid nonce format.' );
			wp_send_json_error( esc_html__( 'There was a technical error while processing your request.', 'checkview' ) );
			wp_die();
		}
		global $wpdb;
		$cv_used_nonces = $wpdb->prefix . 'cv_used_nonces';
		// Query to check if the table exists.
		$table_exists = $wpdb->get_var(
			$wpdb->prepare(
				'SHOW TABLES LIKE %s',
				$cv_used_nonces
			)
		);
		if ( $table_exists !== $cv_used_nonces ) {
			// Log the detailed error for internal use.
			Checkview_Admin_Logs::add( 'api-logs', 'Nonce table absent.' );
			wp_send_json_error( esc_html__( 'There was a technical error while processing your request.', 'checkview' ) );
			wp_die();
		}
		// Check if the nonce exists.
		$nonce_exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM $cv_used_nonces WHERE nonce = %s",
				$nonce_token
			)
		);

		// Nonce already used, return an error response.
		if ( $nonce_exists ) {
			// Log the detailed error for internal use.
			Checkview_Admin_Logs::add( 'api-logs', 'This nonce has already been used.' );
			wp_send_json_error( esc_html__( 'There was a technical error while processing your request.', 'checkview' ) );
			wp_die();
		} else {
			// Store the nonce in the database.
			$response = $wpdb->insert( $cv_used_nonces, array( 'nonce' => $nonce_token ) );
			if ( is_wp_error( $response ) ) {
				Checkview_Admin_Logs::add( 'api-logs', 'Not able to add nonce.' );
				wp_send_json_error( esc_html__( 'There was a technical error while processing your request.', 'checkview' ) );
			}
		}

		if ( 'checkview-saas' === get_option( $visitor_ip ) ) {
			// Send the option value as a JSON response.
			wp_send_json_success(
				array(
					'helper_loaded' => true,
				)
			);

			wp_die(); // Required to terminate properly in WordPress AJAX.
		} else {
			wp_send_json_error( esc_html__( 'Helper not loaded.', 'checkview' ) );
			wp_die();
		}
	}
}
if ( ! defined( 'checkview_update_woocommerce_product_status' ) ) {
	/**
	 * Update status of test product.
	 *
	 * @param [int]    $product_id test product id.
	 * @param [string] $status status to set.
	 * @return bool/WP_ERROR
	 */
	function checkview_update_woocommerce_product_status( $product_id, $status ) {
		// Check if the product ID is valid and status is either 'publish' or 'draft'.
		$updated = 0;
		if ( get_post_type( $product_id ) === 'product' && in_array( $status, array( 'publish', 'draft' ) ) ) {
			// Update the post status.
			$updated = wp_update_post(
				array(
					'ID'          => $product_id,
					'post_status' => $status,
				)
			);
		}
		return $updated;
	}
}

if (!function_exists('array_find')) {
    /**
     * Finds the first element in the array that satisfies the callback condition.
		 * 
		 * Essentially polyfills `array_find`, which was introduced in PHP v8.4.0.
     *
     * @param array $array The array to search.
     * @param callable $callback The callback function to test each element.
     * @return mixed The first matching element, or null if none found.
     */
    function array_find(array $array, callable $callback) {
        foreach ($array as $key => $value) {
            if ($callback($value, $key)) {
                return $value;
            }
        }

        return null;
    }
}

if ( ! function_exists( 'cv_update_option' ) ) {
	/**
	 * Updates an option in WordPress.
	 *
	 * Wrapper for update_option() that also includes logging. Suppresses logs from
	 * failures due to option already existing with the same value.
	 *
	 * @see update_option()
	 * @see get_option()
	 *
	 * @param $option string Name of the option to update.
	 * @param $value mixed Option value.
	 * @param $autoload boolean|null Optional. Whether to load the option when WordPress starts up.
	 *
	 * @return boolean True if the value was updated, false otherwise.
	 */
	function cv_update_option( $option, $value, $autoload = null ) {
		$old_option = get_option( $option );
		$result = update_option( $option, $value, $autoload );

		if ( $result ) {
			Checkview_Admin_Logs::add( 'ip-logs', 'Updated option [' . $option . '] with value [' . print_r( $value, true ) . '].' );
		} else {
			if ($old_option !== false && $old_option !== $value && maybe_serialize( $old_option ) !== maybe_serialize( $value ) ) {
				Checkview_Admin_Logs::add( 'ip-logs', 'Failed updating option [' . $option . '] with value [' . print_r( $value, true ) . '].' );
			}
		}

		return $result;
	}
}

if ( ! function_exists( 'cv_delete_option' ) ) {
	/**
	 * Deletes an option in WordPress.
	 *
	 * Wrapper for delete_option() that also includes logging. Only attempts
	 * deletion if the option is found in the database.
	 *
	 * @see delete_option()
	 * @see get_option()
	 *
	 * @param $option string Name of the option to delete.
	 *
	 * @return boolean True if the value was deleted, false otherwise.
	 */
	function cv_delete_option( $option ) {
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