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
	 * Handles/file names for log files.
	 *
	 * @var array
	 * @access private
	 */
	private static $_handles;

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
	 * Resolved logs folder per blog, cached for the request.
	 *
	 * @var array<int,string>
	 */
	private static $resolved_folder = array();

	/**
	 * Whether the folder key in use for this request is stored in the database.
	 *
	 * @var bool
	 */
	private static $key_persisted = false;

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
	 * The folder name carries a per-site random suffix. It used to be the
	 * fixed `checkview-logs`, guessable from outside and protected only by an
	 * `.htaccess`, which Apache and LiteSpeed honour and nginx ignores. On an
	 * nginx host that left the log files readable at a predictable URL. The
	 * `.htaccess` is still written as a second layer; the unguessable name is
	 * what protects sites where it is inert.
	 *
	 * @return string
	 */
	public static function get_logs_folder() {

		$blog_id = get_current_blog_id();

		if ( ! isset( self::$resolved_folder[ $blog_id ] ) ) {
			self::$resolved_folder[ $blog_id ] = trailingslashit( self::get_uploads_folder() )
				. self::LEGACY_FOLDER_NAME . '-' . self::get_dir_key() . '/';
		}

		return apply_filters( 'checkview_get_logs_folder', self::$resolved_folder[ $blog_id ] );
	}

	/**
	 * Gets this site's logs folder suffix, storing one on first use.
	 *
	 * Creation goes through INSERT IGNORE rather than add_option(). WordPress
	 * implements add_option() as INSERT ... ON DUPLICATE KEY UPDATE, so two
	 * requests racing on first use would both report success and the last
	 * writer's key would win, stranding whatever the other request had already
	 * written or migrated under its own key. INSERT IGNORE is the mutex core
	 * itself uses in WP_Upgrader::create_lock(): exactly one caller inserts,
	 * and every caller then reads back the row that won.
	 *
	 * @return string 16 hex characters.
	 */
	public static function get_dir_key() {

		$key = get_option( self::DIR_KEY_OPTION );

		if ( self::is_valid_dir_key( $key ) ) {
			self::$key_persisted = true;

			return $key;
		}

		global $wpdb;

		$candidate = self::generate_dir_key();

		if ( false === $key ) {
			$wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'yes')",
					self::DIR_KEY_OPTION,
					$candidate
				)
			);
		} else {
			// A stored value that fails validation was edited by hand. Repair it.
			update_option( self::DIR_KEY_OPTION, $candidate, true );
		}

		// Bypassing add_option() means bypassing its cache upkeep: the miss
		// above is cached in notoptions and the autoloaded set is now stale.
		wp_cache_delete( self::DIR_KEY_OPTION, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		wp_cache_delete( 'alloptions', 'options' );

		$key = get_option( self::DIR_KEY_OPTION );

		if ( self::is_valid_dir_key( $key ) ) {
			self::$key_persisted = true;

			return $key;
		}

		// The database refused the write. Derive a stable, unguessable name
		// from the site's salts so logging keeps working through the outage
		// without minting a fresh folder on every request.
		self::$key_persisted = false;

		return substr( hash_hmac( 'sha256', self::LEGACY_FOLDER_NAME, wp_salt( 'auth' ) ), 0, 16 );
	}

	/**
	 * Whether a value is a well-formed logs folder suffix.
	 *
	 * @param mixed $key Candidate value.
	 * @return bool
	 */
	private static function is_valid_dir_key( $key ) {
		return is_string( $key ) && 1 === preg_match( '/^[a-f0-9]{16}$/', $key );
	}

	/**
	 * Generates a new logs folder suffix.
	 *
	 * @return string 16 hex characters.
	 */
	private static function generate_dir_key() {
		try {
			return bin2hex( random_bytes( 8 ) );
		} catch ( Throwable $e ) {
			// No CSPRNG available. The name only needs to be unguessable from
			// outside, not cryptographic.
			return substr( md5( uniqid( (string) wp_rand(), true ) ), 0, 16 );
		}
	}

	/**
	 * Moves logs out of the old, publicly guessable folder.
	 *
	 * Runs from the once-per-version upgrade hook in checkview.php, so
	 * get_logs_folder() stays a plain read. Safe to repeat: a pass with nothing
	 * left to move is a no-op.
	 *
	 * Never deletes a log file. Where the new folder already exists (a
	 * downgrade wrote to the old folder, then the site upgraded again) each
	 * file is moved across on its own and a same-day clash is appended, so
	 * logs written during the rollback survive and stay readable through
	 * get-logs.
	 *
	 * @return void
	 */
	public static function bootstrap_folder() {

		$key = self::get_dir_key();

		if ( ! self::$key_persisted ) {
			// Moving history under a fallback name would strand it once the
			// database is back and a real key gets stored.
			return;
		}

		$base   = trailingslashit( self::get_uploads_folder() );
		$legacy = $base . self::LEGACY_FOLDER_NAME . '/';
		$target = $base . self::LEGACY_FOLDER_NAME . '-' . $key . '/';

		if ( ! is_dir( $legacy ) || is_link( untrailingslashit( $legacy ) ) ) {
			return;
		}

		// A site that pins the folder to the old path through the filter has
		// chosen it. Leave it alone.
		if ( untrailingslashit( self::get_logs_folder() ) === untrailingslashit( $legacy ) ) {
			return;
		}

		// The admin viewer stores realpath()ed selections, and realpath() is
		// only answerable while the old folder still exists.
		$legacy_real = realpath( untrailingslashit( $legacy ) );

		if ( ! is_dir( $target ) && @rename( untrailingslashit( $legacy ), untrailingslashit( $target ) ) ) {
			self::repoint_stored_log_path( $legacy, $legacy_real, $target );

			return;
		}

		self::merge_legacy_files( $legacy, $target );
		self::repoint_stored_log_path( $legacy, $legacy_real, $target );
	}

	/**
	 * Moves each log file from the old folder into the new one.
	 *
	 * @param string $legacy Old folder, trailing slashed.
	 * @param string $target New folder, trailing slashed.
	 * @return void
	 */
	private static function merge_legacy_files( $legacy, $target ) {

		wp_mkdir_p( $target );

		$remaining = 0;

		foreach ( (array) glob( $legacy . '*.log' ) as $file ) {
			if ( ! is_string( $file ) || is_link( $file ) ) {
				continue;
			}

			$destination = $target . basename( $file );

			if ( ! file_exists( $destination ) ) {
				if ( ! @rename( $file, $destination ) ) {
					++$remaining;
				}
				continue;
			}

			// Same day-file on both sides. Append, and drop the copy only once
			// every byte is confirmed on the other side.
			$contents = @file_get_contents( $file );

			if ( false === $contents ) {
				++$remaining;
				continue;
			}

			$written = @file_put_contents( $destination, $contents, FILE_APPEND | LOCK_EX );

			if ( strlen( $contents ) !== $written || ! @unlink( $file ) ) {
				++$remaining;
			}
		}

		if ( $remaining > 0 ) {
			self::add( 'ip-logs', sprintf( 'Could not move %d log file(s) out of %s; check permissions.', $remaining, $legacy ) );

			return;
		}

		foreach ( array( '.htaccess', 'index.html' ) as $stub ) {
			@unlink( $legacy . $stub );
		}

		@rmdir( untrailingslashit( $legacy ) );
	}

	/**
	 * Rewrites the admin log viewer's saved file path after a move.
	 *
	 * @param string       $legacy      Old folder, trailing slashed.
	 * @param string|false $legacy_real realpath() of the old folder, taken before the move.
	 * @param string       $target      New folder, trailing slashed.
	 * @return void
	 */
	private static function repoint_stored_log_path( $legacy, $legacy_real, $target ) {

		$options = get_option( 'checkview_log_options', array() );

		if ( empty( $options['checkview_log_select'] ) || ! is_string( $options['checkview_log_select'] ) ) {
			return;
		}

		$stored   = wp_normalize_path( $options['checkview_log_select'] );
		$prefixes = array( wp_normalize_path( $legacy ) );

		if ( $legacy_real ) {
			$prefixes[] = trailingslashit( wp_normalize_path( $legacy_real ) );
		}

		foreach ( $prefixes as $prefix ) {
			if ( 0 === strpos( $stored, $prefix ) ) {
				$options['checkview_log_select'] = wp_normalize_path( $target ) . substr( $stored, strlen( $prefix ) );
				update_option( 'checkview_log_options', $options );

				return;
			}
		}
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
				// Apache 2.4 syntax first; `deny from all` only works there
				// with mod_access_compat loaded.
				@fputs( $fp, "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tDeny from all\n</IfModule>\n" );

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
		/**
		 * Filters whether log lines are written at all.
		 *
		 * Returning false silences the logs on sites that cannot spare the
		 * disk, without having to disable the plugin.
		 *
		 * @param bool   $enabled Whether to write the line. Default true.
		 * @param string $handle  File handle being written to.
		 */
		if ( ! apply_filters( 'checkview_logging_enabled', true, $handle ) ) {
			return;
		}

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

	/**
	 * Deletes log files older than the retention window.
	 *
	 * Nothing pruned these before, so a long-lived site accumulates one file
	 * per handle per day indefinitely — Woo checkout sites reach 15 MB a day.
	 *
	 * The date is read from the filename rather than the file's mtime: a
	 * touched or restored file would otherwise survive forever.
	 *
	 * @return void
	 */
	public static function purge_expired_logs() {
		/**
		 * Filters how many days of logs to keep.
		 *
		 * Zero or less disables pruning.
		 *
		 * @param int $days Days of logs to retain. Default 30.
		 */
		$days = (int) apply_filters( 'checkview_log_retention_days', 30 );

		if ( $days < 1 ) {
			return;
		}

		// get_logs_folder() is filtered, and the rest of this class assumes
		// the filter returns a trailing slash. This method deletes rather
		// than writes, so it does not rely on that: without the slash the
		// glob below would reach sibling paths.
		$folder = trailingslashit( self::get_logs_folder() );

		if ( ! is_dir( $folder ) ) {
			return;
		}

		$files = glob( $folder . '*-log-*.log' );

		if ( empty( $files ) ) {
			return;
		}

		// add() names files with gmdate(), so comparing the ISO date out of
		// the filename needs no timezone or DST reasoning, and — unlike
		// mtime — a touched or restored file still ages out.
		$oldest_kept = gmdate( 'Y-m-d', time() - ( $days * DAY_IN_SECONDS ) );
		$purged      = 0;

		foreach ( $files as $file ) {
			if ( ! preg_match( '/-log-(\d{4}-\d{2}-\d{2})\.log$/', basename( $file ), $matches ) ) {
				continue;
			}

			if ( $matches[1] < $oldest_kept && @unlink( $file ) ) {
				++$purged;
			}
		}

		do_action( 'checkview_logs_purged', $purged, $days );
	}
}
