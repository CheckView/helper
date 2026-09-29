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
	 */
	const LEGACY_FOLDER_NAME = 'checkview-logs';

	/**
	 * Logs folder suffix per blog, resolved once per request.
	 *
	 * @var array<int,string>
	 */
	private static $dir_key = array();

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
	 * Inside the WordPress uploads directory, under a per-site unguessable
	 * name. The old fixed name was protected only by an .htaccess, which
	 * nginx ignores.
	 *
	 * @return string
	 */
	public static function get_logs_folder() {

		$path = trailingslashit( self::get_uploads_folder() ) . self::LEGACY_FOLDER_NAME . '-' . self::get_dir_key() . '/';

		return apply_filters( 'checkview_get_logs_folder', $path );
	}

	/**
	 * Gets this site's logs folder suffix.
	 *
	 * Derived from the auth salt rather than stored: first use needs no
	 * database write, so nothing can race on it, and the name holds through
	 * a database outage. Rotating the salts renames the folder; the daily
	 * cron's bootstrap_folder() pass carries the logs across.
	 *
	 * @since 2.4.1
	 *
	 * @return string 16 hex characters.
	 */
	public static function get_dir_key() {

		$blog_id = get_current_blog_id();

		if ( ! isset( self::$dir_key[ $blog_id ] ) ) {
			self::$dir_key[ $blog_id ] = substr( hash_hmac( 'sha256', self::LEGACY_FOLDER_NAME . $blog_id, wp_salt( 'auth' ) ), 0, 16 );
		}

		return self::$dir_key[ $blog_id ];
	}

	/**
	 * Moves logs out of any folder that is not the current one.
	 *
	 * Sources are the old fixed-name folder and any suffixed folder whose key
	 * is no longer the current one: a pre-release build stored a random key
	 * in the options table, and rotating the salts changes the derived one.
	 * Never deletes a log file. Runs from the once-per-version init hook and
	 * from the daily logs cron.
	 *
	 * @since 2.4.1
	 *
	 * @return bool True when nothing of ours remains outside the current
	 *              folder, so the caller can stop retrying.
	 */
	public static function bootstrap_folder() {

		$target = trailingslashit( self::get_logs_folder() );
		$prefix = trailingslashit( self::get_uploads_folder() ) . self::LEGACY_FOLDER_NAME;
		$done   = true;

		// System cron often runs WP-CLI as root. A rename keeps ownership, but
		// creating the folder here would leave it root-owned and unwritable
		// by the web user. Let a web request create it first.
		if ( defined( 'WP_CLI' ) && WP_CLI && ! is_dir( $target ) ) {
			return false;
		}

		foreach ( (array) glob( $prefix . '*', GLOB_ONLYDIR ) as $dir ) {
			if ( ! is_string( $dir ) || is_link( $dir ) ) {
				continue;
			}

			$source = trailingslashit( $dir );

			if ( $source === $target || 1 !== preg_match( '#/' . self::LEGACY_FOLDER_NAME . '(-[a-f0-9]{16})?/$#', $source ) ) {
				continue;
			}

			// realpath() is only answerable while the folder still exists, and
			// the admin viewer stores realpath()ed selections.
			$source_real = realpath( $dir );

			if ( ! is_dir( $target ) && @rename( $dir, untrailingslashit( $target ) ) ) {
				self::create_logs_folder();
				self::repoint_stored_log_path( $source, $source_real, $target );
				continue;
			}

			$done = self::merge_folder( $source, $target ) && $done;
			self::repoint_stored_log_path( $source, $source_real, $target );
		}

		return $done;
	}

	/**
	 * Moves each log file from one folder into the current one.
	 *
	 * @param string $source Old folder, trailing slashed.
	 * @param string $target Current folder, trailing slashed.
	 * @return bool True when every log file left the source.
	 */
	private static function merge_folder( $source, $target ) {

		if ( ! is_dir( $target ) ) {
			self::create_logs_folder();
		}

		$remaining = 0;

		foreach ( (array) glob( $source . '*.log' ) as $file ) {
			if ( ! is_string( $file ) ) {
				continue;
			}

			// Not ours to move. The leftover check below keeps the folder and
			// its .htaccess in place because of it.
			if ( is_link( $file ) ) {
				continue;
			}

			$destination = $target . basename( $file );

			if ( ! file_exists( $destination ) && @rename( $file, $destination ) ) {
				continue;
			}

			// Same day-file on both sides, or a rename the filesystem refused:
			// copy the bytes across, verify them, and only then drop the copy.
			if ( ! self::append_file( $file, $destination ) || ! @unlink( $file ) ) {
				++$remaining;
			}
		}

		if ( $remaining > 0 ) {
			self::add( 'ip-logs', sprintf( 'Could not move %d log file(s) out of %s; check permissions.', $remaining, $source ) );

			return false;
		}

		// Only remove the folder's own protection once nothing but that
		// protection is left. A foreign file (a rotated .log.gz, a host
		// marker) keeps the folder, and its .htaccess, in place.
		$entries = @scandir( untrailingslashit( $source ) );

		if ( false === $entries ) {
			return true;
		}

		$leftover = array_diff( $entries, array( '.', '..', '.htaccess', 'index.html' ) );

		if ( ! empty( $leftover ) ) {
			self::add( 'ip-logs', sprintf( 'Left %s in place: it holds %d file(s) that are not CheckView logs.', $source, count( $leftover ) ) );

			return true;
		}

		@unlink( $source . '.htaccess' );
		@unlink( $source . 'index.html' );
		@rmdir( untrailingslashit( $source ) );

		return true;
	}

	/**
	 * Appends one file to another, verifying the byte count.
	 *
	 * Streamed, so a 15 MB day-file is not read into memory. A short write is
	 * truncated back off the destination so a retry does not duplicate it.
	 *
	 * @param string $file        Source path.
	 * @param string $destination Destination path, created if missing.
	 * @return bool
	 */
	private static function append_file( $file, $destination ) {

		$expected = @filesize( $file );
		$in       = @fopen( $file, 'rb' );
		$out      = $in ? @fopen( $destination, 'ab' ) : false;

		if ( false === $expected || ! $in || ! $out ) {
			if ( $in ) {
				fclose( $in );
			}

			return false;
		}

		@flock( $out, LOCK_EX );

		$stat  = fstat( $out );
		$start = isset( $stat['size'] ) ? (int) $stat['size'] : 0;
		$ok    = true;

		while ( ! feof( $in ) ) {
			$chunk = fread( $in, 65536 );

			if ( false === $chunk ) {
				$ok = false;
				break;
			}

			if ( '' === $chunk ) {
				break;
			}

			if ( fwrite( $out, $chunk ) !== strlen( $chunk ) ) {
				$ok = false;
				break;
			}
		}

		if ( $ok ) {
			fflush( $out );
			$stat = fstat( $out );
			$ok   = isset( $stat['size'] ) && ( (int) $stat['size'] - $start ) === (int) $expected;
		}

		if ( ! $ok ) {
			@ftruncate( $out, $start );
		}

		@flock( $out, LOCK_UN );
		fclose( $out );
		fclose( $in );

		return $ok;
	}

	/**
	 * Rewrites the admin log viewer's saved file path after a move.
	 *
	 * @param string       $source      Old folder, trailing slashed.
	 * @param string|false $source_real realpath() of the old folder, taken before the move.
	 * @param string       $target      New folder, trailing slashed.
	 * @return void
	 */
	private static function repoint_stored_log_path( $source, $source_real, $target ) {

		$options = get_option( 'checkview_log_options', array() );

		if ( empty( $options['checkview_log_select'] ) || ! is_string( $options['checkview_log_select'] ) ) {
			return;
		}

		$stored   = wp_normalize_path( $options['checkview_log_select'] );
		$prefixes = array( wp_normalize_path( $source ) );

		if ( $source_real ) {
			$prefixes[] = trailingslashit( wp_normalize_path( $source_real ) );
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
	 * Creates the logs folder with its protection files.
	 *
	 * @return void
	 */
	public static function create_logs_folder() {

		$folder = self::get_logs_folder();

		wp_mkdir_p( $folder );

		// Apache 2.4 syntax first; `deny from all` only works there with
		// mod_access_compat. Rewritten when a folder still carries the
		// one-line form. The path is left out of the error_log lines because
		// the folder name is now a secret.
		$rules    = "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tDeny from all\n</IfModule>\n";
		$htaccess = $folder . '.htaccess';

		if ( ! file_exists( $htaccess ) || false === strpos( (string) @file_get_contents( $htaccess ), 'Require all denied' ) ) {
			if ( false === @file_put_contents( $htaccess, $rules, LOCK_EX ) ) {
				error_log( 'CheckView: Could not write the .htaccess file in the logs folder.' );
			}
		}

		$index = $folder . 'index.html';

		if ( ! file_exists( $index ) && false === @file_put_contents( $index, '' ) ) {
			error_log( 'CheckView: Could not write the index.html file in the logs folder.' );
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
	 * Reads the tail of a log file with bounded memory.
	 *
	 * get-logs used to file_get_contents() every file whole, so one noisy day
	 * (Woo checkout sites reach 15 MB a day) pulled tens of MB into memory and
	 * shipped it, only for the SaaS client to trim to the last few thousand
	 * lines on arrival. This seeks the trailing bytes instead, so the read is
	 * capped no matter how large the file grew inside the retention window.
	 *
	 * @since 1.6.0
	 *
	 * @param string  $file      Absolute path to the log file.
	 * @param integer $max_lines Return at most this many trailing lines.
	 * @param integer $max_bytes Read at most this many trailing bytes.
	 * @return string
	 */
	public static function tail_file( $file, $max_lines = 5000, $max_bytes = 2097152 ) {

		$size = @filesize( $file );

		if ( false === $size || 0 === $size ) {
			return '';
		}

		$handle = @fopen( $file, 'rb' );

		if ( ! $handle ) {
			return '';
		}

		// Read only the trailing window so a multi-MB day cannot be pulled in
		// whole; drop the partial line the offset lands inside.
		if ( $size > $max_bytes ) {
			fseek( $handle, $size - $max_bytes );
			fgets( $handle );
		}

		$data = stream_get_contents( $handle );

		fclose( $handle );

		if ( false === $data || '' === $data ) {
			return '';
		}

		$lines = explode( "\n", $data );

		if ( count( $lines ) > $max_lines ) {
			$lines = array_slice( $lines, -$max_lines );
		}

		return implode( "\n", $lines );
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
