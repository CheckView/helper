<?php
/**
 * Checkview_Fatal_Capture class
 *
 * @since 2.3.3
 *
 * @package Checkview
 * @subpackage Checkview/includes
 */

if ( ! defined( 'WPINC' ) ) {
	die( 'Direct access not Allowed.' );
}

if ( ! class_exists( 'Checkview_Fatal_Capture' ) ) {
	/**
	 * Records PHP fatal errors that occur during a CheckView test.
	 *
	 * A fatal on a form page turns a test into an opaque "HTTP 500" with no
	 * further detail, and chasing the underlying error has meant asking the
	 * customer for server logs. That round trip routinely fails: hosts write
	 * PHP errors somewhere the customer cannot reach, security plugins block
	 * the log file, and even with `WP_DEBUG_LOG` enabled the fatal does not
	 * always land in `debug.log`.
	 *
	 * This captures the error ourselves via `error_get_last()` on shutdown and
	 * writes it to the plugin's own log, which the SaaS already reads over the
	 * signed `/checkview/v1/get-logs` endpoint. `error_get_last()` is populated
	 * by PHP regardless of `error_reporting`, `display_errors` or `log_errors`,
	 * so this works on sites with debugging switched entirely off. WordPress
	 * core relies on the same mechanism to render its "critical error" page.
	 *
	 * Scope: nothing is recorded for ordinary visitors. See `capture()` for the
	 * gate.
	 *
	 * Known blind spots, in rough order of likelihood:
	 * - A fatal raised before this plugin file loads (an earlier plugin in the
	 *   load order, an mu-plugin, or wp-config).
	 * - Out-of-memory, unless the reserve below is large enough to unwind.
	 * - Segfaults and killed workers, where no shutdown function runs at all.
	 * - 500s produced by the web server, a WAF or a proxy rather than by PHP.
	 *
	 * @package Checkview
	 * @subpackage Checkview/includes
	 * @author Check View <support@checkview.io>
	 */
	class Checkview_Fatal_Capture {

		/**
		 * Log channel these errors are written to.
		 *
		 * Named so it lands in the same folder as `ip-logs`/`api-logs` and is
		 * picked up by the existing get-logs endpoint and its 7 day pruning.
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
		 * Memory freed at shutdown so an out-of-memory fatal has room to log.
		 *
		 * @var string|null
		 */
		private static $reserve = null;

		/**
		 * Registers the shutdown handler for likely CheckView requests.
		 *
		 * Called as early as the plugin loads rather than on a hook, so a fatal
		 * during another plugin's `init` is still caught.
		 *
		 * @return void
		 */
		public static function init() {
			if ( ! self::is_probable_test_request() ) {
				return;
			}

			// Freed first thing in capture(), so an "Allowed memory size
			// exhausted" fatal still has headroom to format and write itself.
			self::$reserve = str_repeat( ' ', 512 * 1024 );

			register_shutdown_function( array( __CLASS__, 'capture' ) );
		}

		/**
		 * Writes the last error to the log if it was fatal.
		 *
		 * @return void
		 */
		public static function capture() {
			self::$reserve = null;

			// The real gate. CV_TEST_ID is defined by
			// checkview_init_current_test(), which only runs once
			// Checkview::is_bot() has verified the request signature, so a
			// visitor appending ?checkview_test_id=... to a URL can never
			// reach a write. Deliberately not re-running the signature check
			// here: checkview_validate_jwt_token() logs on every failure path,
			// which would hand anyone an unauthenticated way to grow a
			// customer's log files.
			if ( ! defined( 'CV_TEST_ID' ) ) {
				return;
			}

			$error = error_get_last();

			if ( ! self::is_fatal( $error ) ) {
				return;
			}

			// Logger lives in admin/, which is loaded by the main plugin class.
			// If we fataled before that, there is nowhere to write.
			if ( ! class_exists( 'Checkview_Admin_Logs' ) ) {
				return;
			}

			$message = isset( $error['message'] ) ? (string) $error['message'] : 'unknown error';

			if ( strlen( $message ) > self::MAX_MESSAGE_LENGTH ) {
				$message = substr( $message, 0, self::MAX_MESSAGE_LENGTH ) . ' [truncated]';
			}

			// We are already inside a shutdown handler after a fatal. If the
			// write itself fails (the logger reaches for an option, and the
			// original fatal may have been a dead database connection), let it
			// go quietly rather than raise a second error nobody will see.
			try {
				Checkview_Admin_Logs::add(
					self::LOG_HANDLE,
					sprintf(
						'FATAL during test [%s] on [%s]: %s in %s:%d',
						CV_TEST_ID,
						self::current_url(),
						$message,
						isset( $error['file'] ) ? $error['file'] : 'unknown file',
						isset( $error['line'] ) ? (int) $error['line'] : 0
					)
				);
			} catch ( Throwable $e ) {
				return;
			}
		}

		/**
		 * Whether an error entry represents a fatal error.
		 *
		 * @param array|null $error Value returned by error_get_last().
		 * @return bool
		 */
		private static function is_fatal( $error ) {
			if ( ! is_array( $error ) || ! isset( $error['type'] ) ) {
				return false;
			}

			$fatal_types = array(
				E_ERROR,
				E_PARSE,
				E_CORE_ERROR,
				E_COMPILE_ERROR,
				E_USER_ERROR,
				E_RECOVERABLE_ERROR,
			);

			return in_array( (int) $error['type'], $fatal_types, true );
		}

		/**
		 * Cheap guess at whether this request belongs to a CheckView test.
		 *
		 * Only decides whether to register the shutdown handler, so a false
		 * positive costs one no-op function call and a false negative only
		 * loses a log line. The authoritative check is CV_TEST_ID in
		 * capture(). Covers all four ways our traffic identifies itself: the
		 * navigation query string, a form POST whose referer carries it, the
		 * plugin's own cookie across redirects, and the signed header.
		 *
		 * @return bool
		 */
		private static function is_probable_test_request() {
			// phpcs:disable WordPress.Security.NonceVerification.Recommended
			if ( ! empty( $_REQUEST['checkview_test_id'] ) ) {
				return true;
			}
			// phpcs:enable WordPress.Security.NonceVerification.Recommended

			if ( ! empty( $_COOKIE['checkview_test_type'] ) ) {
				return true;
			}

			if ( ! empty( $_SERVER['HTTP_X_CHECKVIEW_SIGNATURE'] ) ) {
				return true;
			}

			if ( ! empty( $_SERVER['HTTP_REFERER'] )
				&& false !== strpos( (string) $_SERVER['HTTP_REFERER'], 'checkview_test_id=' ) ) {
				return true;
			}

			return false;
		}

		/**
		 * Best-effort URL of the current request, for the log line.
		 *
		 * Built from superglobals rather than WordPress helpers because this
		 * runs at shutdown, where a fatal may have left core half-loaded.
		 *
		 * @return string
		 */
		private static function current_url() {
			$host = isset( $_SERVER['HTTP_HOST'] ) ? (string) $_SERVER['HTTP_HOST'] : '';
			$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';

			if ( '' === $host && '' === $uri ) {
				return 'unknown url';
			}

			return substr( $host . $uri, 0, 500 );
		}
	}
}
