<?php
/**
 * Checkview_Fatal_Capture class
 *
 * @since 2.4.1
 *
 * @package Checkview
 * @subpackage Checkview/includes
 */

if ( ! defined( 'WPINC' ) ) {
	die( 'Direct access not Allowed.' );
}

if ( ! class_exists( 'Checkview_Fatal_Capture' ) ) {
	/**
	 * Records PHP fatal errors raised during a CheckView test.
	 *
	 * Writes the fatal into the plugin's own `fatal-logs` channel, which the
	 * SaaS already reads over the signed get-logs endpoint, so a failing test
	 * can be diagnosed without server log access. Armed only from
	 * checkview_before_init_current_test, i.e. only on requests that passed
	 * Checkview::is_bot().
	 *
	 * Two paths. Core's fatal handler runs first and its own work can raise
	 * warnings that overwrite error_get_last(), so the primary path takes the
	 * error core already read from the wp_php_error_args filter. That filter
	 * only fires when no output has been sent yet; a shutdown function covers
	 * fatals raised after output began, and that path can be masked.
	 *
	 * Known blind spots:
	 * - Fatals before checkview_before_init_current_test fires on
	 *   plugins_loaded: another plugin's file scope, mu-plugins, wp-config.
	 * - Out of memory: core's handler runs first under the exhausted limit.
	 * - Segfaults and killed workers, where no shutdown code runs.
	 * - A php-error.php drop-in or wp_php_error_args filter that exits.
	 * - 500s produced by the web server, a WAF or a proxy rather than PHP.
	 *
	 * @package Checkview
	 * @subpackage Checkview/includes
	 * @author Check View <support@checkview.io>
	 */
	class Checkview_Fatal_Capture {

		/**
		 * Log channel these errors are written to.
		 */
		const LOG_HANDLE = 'fatal-logs';

		/**
		 * Longest error message recorded, in bytes. Traces can run to hundreds
		 * of kilobytes.
		 */
		const MAX_MESSAGE_LENGTH = 8192;

		/**
		 * Whether init() has run for this request.
		 *
		 * @var bool
		 */
		private static $armed = false;

		/**
		 * Whether a fatal has already been written for this request.
		 *
		 * @var bool
		 */
		private static $recorded = false;

		/**
		 * Whether PHP accepted the request to drop argument values from traces.
		 *
		 * @var bool
		 */
		private static $args_stripped = false;

		/**
		 * Arms both capture paths.
		 *
		 * @since 2.4.1
		 *
		 * @return void
		 */
		public static function init() {
			if ( self::$armed ) {
				return;
			}

			self::$armed = true;

			// Traces keep their frames but lose argument values. The engine
			// default is off unless a php.ini turns it on, and the default
			// parameter length of 15 characters fits a whole API key.
			if ( function_exists( 'ini_set' ) ) {
				self::$args_stripped = false !== ini_set( 'zend.exception_ignore_args', '1' );
			}

			add_filter( 'wp_php_error_args', array( __CLASS__, 'record_from_core' ), 10, 2 );

			register_shutdown_function( array( __CLASS__, 'record_on_shutdown' ) );
		}

		/**
		 * Records the fatal core is about to render, then hands its args back.
		 *
		 * @since 2.4.1
		 *
		 * @param array $args  Arguments core will pass to wp_die().
		 * @param array $error The error, as core read it from error_get_last().
		 * @return array Unchanged.
		 */
		public static function record_from_core( $args, $error ) {
			self::record( $error, 'core handler' );

			return $args;
		}

		/**
		 * Fallback for fatals raised after output began.
		 *
		 * @since 2.4.1
		 *
		 * @return void
		 */
		public static function record_on_shutdown() {
			self::record( error_get_last(), 'shutdown' );
		}

		/**
		 * Writes one fatal to the log, at most once per request.
		 *
		 * @param array|null $error Value in the shape of error_get_last().
		 * @param string     $via   Which path delivered it.
		 * @return void
		 */
		private static function record( $error, $via ) {
			if ( self::$recorded || ! self::is_fatal( $error ) || ! class_exists( 'Checkview_Admin_Logs' ) ) {
				return;
			}

			self::$recorded = true;

			$message = isset( $error['message'] ) ? (string) $error['message'] : 'unknown error';

			if ( strlen( $message ) > self::MAX_MESSAGE_LENGTH ) {
				$message = ( function_exists( 'mb_strcut' ) ? mb_strcut( $message, 0, self::MAX_MESSAGE_LENGTH ) : substr( $message, 0, self::MAX_MESSAGE_LENGTH ) ) . ' [truncated]';
			}

			$message = self::redact( $message );

			if ( ! self::$args_stripped ) {
				$message .= ' [trace argument values not stripped: ini_set unavailable]';
			}

			// Whatever raised the fatal may have left the database unusable,
			// and a Throwable from the logger or a checkview_log_add listener
			// must not become a second error on top of the first.
			try {
				Checkview_Admin_Logs::add(
					self::LOG_HANDLE,
					sprintf(
						'FATAL during test [%s] on [%s] (via %s): %s in %s:%d',
						self::test_id(),
						self::current_url(),
						$via,
						$message,
						isset( $error['file'] ) ? (string) $error['file'] : 'unknown file',
						isset( $error['line'] ) ? (int) $error['line'] : 0
					)
				);
			} catch ( Throwable $e ) {
				return;
			}
		}

		/**
		 * Masks common secret and personal-data shapes in an error message.
		 *
		 * The trace already has its argument values dropped; this covers the
		 * message line, which integrations routinely build from credentials
		 * ("Invalid API Key provided: sk_live_...", "Access denied for user
		 * 'x'@'host'"). A pattern list is not a guarantee, so the readme says
		 * the message text leaves the site.
		 *
		 * @param string $text Error message.
		 * @return string
		 */
		private static function redact( $text ) {
			$patterns = array(
				'/\b([sr]k|pk)_(live|test)_[A-Za-z0-9]+/' => '$1_$2_[redacted]',
				'/\bAKIA[0-9A-Z]{16}\b/'                  => 'AKIA[redacted]',
				'/(Bearer\s+)[A-Za-z0-9._~+\/-]+=*/i'     => '$1[redacted]',
				'/((?:password|passwd|pwd|secret|token|api[_-]?key)\s*[=:]\s*)[^\s,;)]+/i' => '$1[redacted]',
				"/for user '[^']*'@'[^']*'/"              => "for user '[redacted]'@'[redacted]'",
				'/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}\b/' => '[email]',
				'/\b[A-Fa-f0-9]{32,}\b/'                  => '[hex]',
			);

			$redacted = preg_replace( array_keys( $patterns ), array_values( $patterns ), $text );

			return null === $redacted ? $text : $redacted;
		}

		/**
		 * Whether an error entry is one core's own handler treats as fatal.
		 *
		 * @param mixed $error Value in the shape of error_get_last().
		 * @return bool
		 */
		private static function is_fatal( $error ) {
			if ( ! is_array( $error ) || ! isset( $error['type'] ) ) {
				return false;
			}

			$fatal_types = array(
				E_ERROR,
				E_PARSE,
				E_USER_ERROR,
				E_COMPILE_ERROR,
				E_RECOVERABLE_ERROR,
			);

			return in_array( (int) $error['type'], $fatal_types, true );
		}

		/**
		 * The test this request belongs to, for the log line.
		 *
		 * @return string
		 */
		private static function test_id() {
			if ( defined( 'CV_TEST_ID' ) ) {
				return (string) CV_TEST_ID;
			}

			if ( function_exists( 'get_checkview_test_id' ) ) {
				$test_id = get_checkview_test_id();

				if ( $test_id ) {
					return (string) $test_id;
				}
			}

			return 'unknown';
		}

		/**
		 * Host and path of the current request, for the log line.
		 *
		 * The query string is dropped: a GET form submission carries the
		 * submitted values in it, and the test id is logged separately.
		 *
		 * @return string
		 */
		private static function current_url() {
			$host = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
			$uri  = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
			$path = strtok( $uri, '?' );

			if ( '' === $host && ( false === $path || '' === $path ) ) {
				return 'unknown url';
			}

			return substr( $host . ( false === $path ? '' : $path ), 0, 500 );
		}
	}

	add_action( 'checkview_before_init_current_test', array( 'Checkview_Fatal_Capture', 'init' ) );
}
