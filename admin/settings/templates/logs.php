<?php
/**
 * CheckView Logs Settings page content
 *
 * @package Logs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$wp_filesystem_direct = new WP_Filesystem_Direct( array() );
$pad_spaces           = 45;
$checkview_options    = get_option( 'checkview_log_options', array() );

$logs_list        = glob( Checkview_Admin_Logs::get_logs_folder() . '*.log' );
$file             = ! empty( $checkview_options['checkview_log_select'] ) ? $checkview_options['checkview_log_select'] : '';
$real_logs_folder = realpath( Checkview_Admin_Logs::get_logs_folder() );
// Normalize paths for cross-platform compatibility and append separator to prevent prefix bypass.
$real_logs_folder = $real_logs_folder ? trailingslashit( wp_normalize_path( $real_logs_folder ) ) : false;
$real_file        = $file ? realpath( $file ) : false;
$real_file        = $real_file ? wp_normalize_path( $real_file ) : false;
// Only read the file if it exists within the logs directory (prevent path traversal).
$contents = $real_file && $real_logs_folder && 0 === strpos( $real_file, $real_logs_folder )
	? $wp_filesystem_direct->get_contents( $real_file )
	: '--';

?>

<div id="checkview-general-options" class="card">
	<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="POST">
		<div class="d-flex align-items-center section-header">
			<input type="hidden" name="action" value="checkview_admin_logs_settings">
			<?php wp_nonce_field( 'checkview_admin_logs_settings', 'checkview_admin_logs_settings' ); ?>
			<?php echo str_pad( esc_html__( 'Logs Directory', 'checkview' ) . ':', $pad_spaces ); ?><?php echo $wp_filesystem_direct->is_writable( Checkview_Admin_Logs::get_logs_folder() ) ? 'Writable' : 'Not Writable' . "\n"; ?>
			<div class="alignright ml-auto">
				<?php do_action( 'checkview_logs_before_select' ); ?>
				<select name="checkview_log_select" style="" class="mr-3">
					<option><?php esc_html_e( 'Select a Log File', 'checkview' ); ?></option>
					<?php foreach ( $logs_list as $file_path ) : ?>
					<?php
					$real_option_path = realpath( $file_path );
					$real_option_path = $real_option_path ? wp_normalize_path( $real_option_path ) : '';
					?>
					<option value="<?php echo esc_attr( $file_path ); ?>" <?php selected( $file === $file_path || $file === $real_option_path ); ?>><?php echo esc_attr( $file_path ); ?></option>
					<?php endforeach; ?>
				</select>
				<button class="button-primary" id="checkview_see_log" name="checkview_see_log" value="see" type="submit"><?php esc_html_e( 'See Log File', 'checkview' ); ?></button>
				<?php do_action( 'checkview_logs_after_button' ); ?>
			</div>
		</div>
	</form>

	<textarea  onclick="this.focus();this.select()" readonly="readonly" wrap="off" style="width: 100%; height: 600px; font-family: monospace;"><?php echo esc_attr( $contents ); ?></textarea>
	<?php do_action( 'checkview_logs_after_textarea' ); ?>
</div>
<?php