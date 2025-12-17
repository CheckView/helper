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
		$nonce  = isset( $_POST['checkview_admin_logs_settings'] ) ? sanitize_text_field( wp_unslash( $_POST['checkview_admin_logs_settings'] ) ) : '';
		$action = 'checkview_admin_logs_settings';
		if ( isset( $_POST['checkview_see_log'] ) && wp_verify_nonce( $nonce, $action ) ) {
			$checkview_options = array();
			$log_path          = isset( $_POST['checkview_log_select'] ) ? sanitize_text_field( wp_unslash( $_POST['checkview_log_select'] ) ) : '';
			$uploads           = 'false';
			if ( $log_path && '' !== $log_path ) {
				$log_path                                  = checkview_deslash( $log_path );
				$checkview_options['checkview_log_select'] = $log_path;
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
	 * @return string
	 */
	public static function get_logs_folder() {

		$path = apply_filters( 'checkview_get_logs_folder', self::get_uploads_folder() . '/checkview-logs/' );

		return $path;
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
