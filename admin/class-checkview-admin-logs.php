<?php
/**
 * Checkview_Admin_Logs class
 *
 * @since 1.0.0
 *
 * @package CheckView
 * @subpackage CheckView/admin/
 */

/**
 * Handles admin logs.
 * 
 * Reads, writes, and clears admin logs. Supports writing to differnt log
 * files within the logs folder, which is useful for splitting logs depending
 * on their purpose.
 *
 * @author CheckView
 * @category Incldues
 * @package CheckView/admin/
 * @version 1.0.0
 */
class Checkview_Admin_Logs {

	/**
	 * Base name of the logs folder, kept as the prefix of the current one.
	 *
	 * @var string
	 */
	const LEGACY_FOLDER_NAME = 'checkview-logs';

	/**
	 * Option holding this site's random logs folder suffix.
	 *
	 * @var string
	 */
	const DIR_KEY_OPTION = 'checkview_logs_dir_key';

	/**
	 * Handles/file names for log files.
	 *
	 * @var array
	 * @access private
	 */
	private static $_handles;

	/**
	 * Cached logs folder path, resolved once per request.
	 *
	 * @var string|null
	 */
	private static $resolved_folder = null;

	/**
	 * Constructor.
	 * 
	 * Defines log handles property as an empty array.
	 */
	public function __construct() {
		self::$_handles = array();
	}

	/**
	 * Destructor.
	 * 
	 * Closes file pointers when this class is destroyed.
	 */
	public function __destruct() {
		foreach ( self::$_handles as $handle ) {
			if ( is_resource( $handle ) ) {
				@fclose( $handle );
			}
		}
	}

	/**
	 * Gets the WordPress uploads folder's path.
	 *
	 * @return string
	 */
	public static function get_uploads_folder() {

		$uploads = wp_upload_dir( null, false );

		return isset( $uploads['basedir'] ) && $uploads['basedir'] ? $uploads['basedir'] : '';
	}

	/**
	 * Handles saving the admin logs options.
	 *
	 * @return void
	 */
	public function checkview_admin_logs_settings_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'checkview' ) );
		}

		$nonce  = isset( $_POST['checkview_admin_logs_settings'] ) ? sanitize_text_field( wp_unslash( $_POST['checkview_admin_logs_settings'] ) ) : '';
		$action = 'checkview_admin_logs_settings';
		if ( isset( $_POST['checkview_see_log'] ) && wp_verify_nonce( $nonce, $action ) ) {
			$checkview_options = array();
			$log_path          = isset( $_POST['checkview_log_select'] ) ? sanitize_text_field( wp_unslash( $_POST['checkview_log_select'] ) ) : '';
			$uploads           = 'false';
			if ( $log_path && '' !== $log_path ) {
				$log_path = checkview_deslash( $log_path );

				// Validate path is within the expected logs directory.
				$logs_folder      = self::get_logs_folder();
				$real_path        = realpath( $log_path );
				$real_logs_folder = realpath( $logs_folder );
				// Normalize paths for cross-platform compatibility (Windows uses \ separators).
				if ( false !== $real_path ) {
					$real_path = wp_normalize_path( $real_path );
				}
				if ( false !== $real_logs_folder ) {
					$real_logs_folder = trailingslashit( wp_normalize_path( $real_logs_folder ) );
				}
				if ( false === $real_path || false === $real_logs_folder || 0 !== strpos( $real_path, $real_logs_folder ) ) {
					wp_safe_redirect( add_query_arg( 'logs-settings-updated', 'false', isset( $_POST['_wp_http_referer'] ) ? sanitize_url( wp_unslash( $_POST['_wp_http_referer'] ) ) : '' ) );
					exit;
				}

				// Store the resolved path to prevent TOCTOU and filter-injection issues.
				$checkview_options['checkview_log_select'] = $real_path;
				$checkview_options                         = apply_filters( 'checkview_save_log_options', $checkview_options );
				update_option( 'checkview_log_options', $checkview_options );
				$uploads = 'true';

			}
			wp_safe_redirect( add_query_arg( 'logs-settings-updated', $uploads, isset( $_POST['_wp_http_referer'] ) ? sanitize_url( wp_unslash( $_POST['_wp_http_referer'] ) ) : '' ) );
			exit;
		}
	}

	/**
	 * Gets the path of the logs folder.
	 *
	 * Returns the path of the logs folder, which, by default, is located within
	 * the WordPress Uploads directory.
	 *
	 * The folder name carries a per-site random suffix. It used to be the fixed
	 * `checkview-logs`, guessable from outside and protected only by an
	 * `.htaccess` carrying `deny from all`. That works on Apache and LiteSpeed
	 * but nginx ignores `.htaccess` entirely, leaving the log files publicly
	 * readable at a predictable URL — confirmed in the field on a customer site
	 * in August 2026. The `.htaccess` is still written as defence in depth; the
	 * unguessable name is what protects sites where it is inert.
	 *
	 * @return string
	 */
	public static function get_logs_folder() {

		if ( null === self::$resolved_folder ) {
			$base = trailingslashit( self::get_uploads_folder() );
			$path = $base . self::LEGACY_FOLDER_NAME . '-' . self::get_dir_key() . '/';

			self::maybe_migrate_legacy_folder( $base, $path );

			self::$resolved_folder = $path;
		}

		return apply_filters( 'checkview_get_logs_folder', self::$resolved_folder );
	}

	/**
	 * Gets, creating if needed, this site's random logs folder suffix.
	 *
	 * @return string 16 hex characters.
	 */
	private static function get_dir_key() {

		$key = get_option( self::DIR_KEY_OPTION, '' );

		if ( is_string( $key ) && preg_match( '/^[a-f0-9]{16}$/', $key ) ) {
			return $key;
		}

		try {
			$key = bin2hex( random_bytes( 8 ) );
		} catch ( Throwable $e ) {
			// random_bytes throws when no CSPRNG is available. The name only
			// needs to be unguessable from outside, not cryptographic.
			$key = substr( md5( uniqid( (string) wp_rand(), true ) ), 0, 16 );
		}

		// Not autoloaded: read once per request, and only on requests that log.
		update_option( self::DIR_KEY_OPTION, $key, false );

		return $key;
	}

	/**
	 * Moves logs out of the old, publicly guessable folder.
	 *
	 * @param string $base       Uploads folder, trailing slashed.
	 * @param string $new_folder Destination folder, trailing slashed.
	 * @return void
	 */
	private static function maybe_migrate_legacy_folder( $base, $new_folder ) {

		$legacy = $base . self::LEGACY_FOLDER_NAME . '/';

		if ( $legacy === $new_folder || ! is_dir( $legacy ) ) {
			return;
		}

		if ( ! is_dir( $new_folder )
			&& @rename( untrailingslashit( $legacy ), untrailingslashit( $new_folder ) ) ) {

			self::repoint_stored_log_path( $legacy, $new_folder );

			return;
		}

		// Either the destination already exists or the rename was refused
		// (restrictive permissions, open handles, cross-device). Leaving the
		// old folder in place would defeat the purpose, since that is the path
		// being served publicly. These are our own logs, pruned after 7 days,
		// so nothing depends on them.
		foreach ( array( '*.log', '.htaccess', 'index.html' ) as $pattern ) {
			foreach ( (array) glob( $legacy . $pattern ) as $file ) {
				@unlink( $file );
			}
		}

		@rmdir( untrailingslashit( $legacy ) );
	}

	/**
	 * Rewrites the admin log viewer's saved file path after a migration.
	 *
	 * @param string $legacy     Old folder, trailing slashed.
	 * @param string $new_folder New folder, trailing slashed.
	 * @return void
	 */
	private static function repoint_stored_log_path( $legacy, $new_folder ) {

		$options = get_option( 'checkview_log_options', array() );

		if ( empty( $options['checkview_log_select'] ) || ! is_string( $options['checkview_log_select'] ) ) {
			return;
		}

		if ( 0 !== strpos( $options['checkview_log_select'], $legacy ) ) {
			return;
		}

		$options['checkview_log_select'] = $new_folder . substr( $options['checkview_log_select'], strlen( $legacy ) );

		update_option( 'checkview_log_options', $options );
	}

	/**
	 * Creates the logs folder.
	 *
	 * @return void
	 */
	public static function create_logs_folder() {

		// Creates the Folder.
		wp_mkdir_p( self::get_logs_folder() );

		// Creates htaccess.
		$htaccess = self::get_logs_folder() . '.htaccess';

		if ( ! file_exists( $htaccess ) ) {

			$fp = @fopen( $htaccess, 'w' );

			if ( ! $fp ) {
				error_log( 'CheckView: Could not create logs htaccess file: ' . $htaccess );
			} else {
				@fputs( $fp, 'deny from all' );

				@fclose( $fp );
			}

		}

		// Creates index.
		$index = self::get_logs_folder() . 'index.html';

		if ( ! file_exists( $index ) ) {

			$fp = @fopen( $index, 'w' );

			if ( ! $fp ) {
				error_log( 'CheckView: Could not create logs index.html file: ' . $index );
			} else {
				@fputs( $fp, '' );

				@fclose( $fp );
			}

		}
	}

	/**
	 * Reads a log file.
	 * 
	 * If given a `$lines`, this function will only return the last `$lines`
	 * lines of the chosen log file.
	 * 
	 * @since 1.6.0
	 * 
	 * @param string $handle File handle.
	 * @param integer $lines Number of line to limit.
	 * @return array
	 */
	public static function read_lines( $handle, $lines = 10 ) {

		$results = array();

		// Open the file for reading.
		if ( self::open( $handle, 'r' ) && is_resource( self::$_handles[ $handle ] ) ) {

			while ( ! feof( self::$_handles[ $handle ] ) ) {

				$line = fgets( self::$_handles[ $handle ], 4096 );

				array_push( $results, $line );

				if ( count( $results ) > $lines + 1 ) {

					array_shift( $results );

				}
			}
		}

		return array_filter( $results );
	}

	/**
	 * Tests opening a log file.
	 *
	 * @since 0.0.1
	 * @since 1.2.0 Checks if the directory exists
	 *
	 * @access private
	 * @param mixed $handle File handle.
	 * @param string $permission File permissions.
	 * @return bool True on success, false otherwise.
	 */
	private static function open( $handle, $permission = 'a' ) {

		// Get the path for our logs.
		$path = self::get_logs_folder();

		if ( ! is_dir( $path ) ) {
			self::create_logs_folder();

			return false;
		}
		self::$_handles[ $handle ] = @fopen( $path . $handle . '.log', $permission );
		if ( self::$_handles[ $handle ] ) {

			return true;
		}

		return false;
	}

	/**
	 * Writes to a log file.
	 * 
	 * Given a log file's `$handle`, append `$message` to it. Prepends each new
	 * message with the time the log was written.
	 *
	 * @param string $handle File handle.
	 * @param string $message Log to write.
	 */
	public static function add( $handle, $message ) {
		// Collapse C0 controls (CR/LF/NUL/etc.) and Unicode line/paragraph
		// terminators so callers can't forge log lines. strtr is byte-safe
		// — preg_replace with /u returns NULL on invalid UTF-8, which would
		// silently drop log entries containing raw bytes (e.g. wpdb errors
		// echoing offending Latin-1 sequences).
		if ( is_string( $message ) ) {
			static $sanitize_table = null;
			if ( null === $sanitize_table ) {
				$sanitize_table = array();
				for ( $i = 0; $i < 32; $i++ ) {
					$sanitize_table[ chr( $i ) ] = ' ';
				}
				// HTML/browser log viewers render these as line breaks.
				$sanitize_table["\xC2\x85"]     = ' '; // U+0085 NEL
				$sanitize_table["\xE2\x80\xA8"] = ' '; // U+2028 LINE SEPARATOR
				$sanitize_table["\xE2\x80\xA9"] = ' '; // U+2029 PARAGRAPH SEPARATOR
			}
			$message = strtr( $message, $sanitize_table );
		}
		$handle = $handle . '-log-' . gmdate( 'Y-m-d' );
		if ( self::open( $handle ) && is_resource( self::$_handles[ $handle ] ) ) {
			$time   = self::get_now()->format( 'm-d-Y @ H:i:s -' ); // Grab Time.
			$result = @fwrite( self::$_handles[ $handle ], $time . ' ' . $message . "\n" );
			@fclose( self::$_handles[ $handle ] );
		}

		do_action( 'checkview_log_add', $handle, $message );
	}

	/**
	 * Gets the current date-time.
	 *
	 * @since 1.5.1
	 * 
	 * @param string $type Type of date.
	 * @return mixed
	 */
	public static function get_now( $type = 'mysql' ) {

		return new DateTime( self::get_current_time( $type ) );
	}

	/**
	 * Gets the current timestamp.
	 *
	 * @param string $type Date type.
	 * @return date
	 */
	public static function get_current_time( $type = 'mysql' ) {
		if ( is_multisite() ) {

			switch_to_blog( get_current_site()->blog_id );

			$time = current_time( $type );

			restore_current_blog();
		} else {

			$time = current_time( $type );
		}

		return $time;
	}

	/**
	 * Clears a log file.
	 *
	 * @param mixed $handle File handle.
	 */
	public function clear( $handle ) {
		if ( self::open( $handle ) && is_resource( self::$_handles[ $handle ] ) ) {
			@ftruncate( self::$_handles[ $handle ], 0 );
		}

		do_action( 'checkview_log_clear', $handle );
	}
}
