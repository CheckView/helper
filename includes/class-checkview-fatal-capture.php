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
	 * A fatal on a form page turns a test into an opaque "HTTP 500", and
	 * recovering the underlying error has meant asking the customer for
	 * server logs. That routinely fails: hosts write PHP errors where the
	 * customer cannot reach them, and the fatal does not always land in
	 * debug.log even with WP_DEBUG_LOG on. This records it into the plugin's
	 * own log, which the SaaS already reads over the signed
	 * /checkview/v1/get-logs endpoint.
	 *
	 * Armed only on requests Checkview::is_bot() has verified, so it costs
	 * ordinary visitors nothing and cannot be reached without a valid request
	 * signature.
	 *
	 * Two paths, in order of preference:
	 *
	 * 1. The wp_php_error_args filter. Core's fatal handler calls
	 *    error_get_last() first thing and hands that array to the filter, so
	 *    what arrives here is the original fatal even though core's own work
	 *    in between (loading translations, recovery mode, building the error
	 *    page) can raise warnings that overwrite error_get_last(). Core only
	 *    reaches the filter when headers have not been sent, which is the
	 *    fatal-before-output case that matters most.
	 * 2. A shutdown function, for fatals raised after output began, where
	 *    core skips the template and the filter never fires. This reads
	 *    error_get_last() itself, so a warning from core's handler can mask
	 *    the fatal on this path.
	 *
	 * error_get_last() is populated regardless of error_reporting,
	 * display_errors and log_errors, which is why this works on sites with
	 * debugging switched entirely off. Core's critical-error page relies on
	 * the same mechanism, and core's handler does not exit, so the shutdown
	 * function registered here still runs after it.
	 *
	 * Known blind spots:
	 * - Fatals before checkview_before_init_current_test fires on
	 *   plugins_loaded: another plugin's file scope, mu-plugins, wp-config.
	 * - Out of memory. Core's handler runs first under the exhausted limit
	 *   and can fail before either path here runs.
	 * - Segfaults and killed workers, where no shutdown code runs at all.
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
		 *
		 * Lands beside ip-logs and api-logs, so the get-logs endpoint returns
		 * it and the daily purge ages it out with the rest.
		 *
		 * @var string
		 */
		const LOG_HANDLE = 'fatal-logs';

		/**
		 * Longest error message recorded, in bytes.
		 *
		 * An uncaught Error's message carries its whole stack trace, which is
		 * the useful part, but a deep trace can run to hundreds of kilobytes.
		 *
		 * @var int
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
		 * Arms both capture paths.
		 *
		 * Hooked to checkview_before_init_current_test, which fires only after
		 * Checkview::is_bot() has verified the request signature.
		 *
		 * @return void
		 */
		public static function init() {
			if ( self::$armed ) {
				return;
			}

			self::$armed = true;

			// Stack traces keep their frames but lose argument values. PHP's
			// engine default for this is off (only php.ini-production turns
			// it on) and the default parameter length is 15 characters, long
			// enough to carry a whole API key into a log we then read
			// off-site.
			ini_set( 'zend.exception_ignore_args', '1' );

			add_filter( 'wp_php_error_args', array( __CLASS__, 'record_from_core' ), 10, 2 );

			register_shutdown_function( array( __CLASS__, 'record_on_shutdown' ) );
		}

		/**
		 * Records the fatal core is about to render, then hands its args back.
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
			if ( self::$recorded || ! self::is_fatal( $error ) ) {
				return;
			}

			self::$recorded = true;

			if ( ! class_exists( 'Checkview_Admin_Logs' ) ) {
				return;
			}

			$message = isset( $error['message'] ) ? (string) $error['message'] : 'unknown error';

			if ( strlen( $message ) > self::MAX_MESSAGE_LENGTH ) {
				$message = substr( $message, 0, self::MAX_MESSAGE_LENGTH ) . ' [truncated]';
			}

			// Whatever raised the fatal may have left the database unusable,
			// and a Throwable from the logger or from a checkview_log_add
			// listener must not become a second error on top of the first.
			// A second fatal is not catchable; nothing can be done about that.
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
		 * CV_TEST_ID is defined on init; a fatal before that falls back to the
		 * request's own test id.
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
		 * Best-effort URL of the current request, for the log line.
		 *
		 * @return string
		 */
		private static function current_url() {
			$host = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
			$uri  = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

			if ( '' === $host && '' === $uri ) {
				return 'unknown url';
			}

			return substr( $host . $uri, 0, 500 );
		}
	}

	add_action( 'checkview_before_init_current_test', array( 'Checkview_Fatal_Capture', 'init' ) );
}
