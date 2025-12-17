<?php
/**
 * CheckView API Settings page content
 *
 * @package settings
 */

if ( ! defined( 'ABSPATH' ) ) {

	exit;
}
$checkview_options = get_option( 'checkview_api_options', array() );
$delete_all        = ! empty( $checkview_options['checkview_delete_data'] ) ? $checkview_options['checkview_delete_data'] : '';
$allow_dev         = ! empty( $checkview_options['checkview_allowed_extensions'] ) ? $checkview_options['checkview_allowed_extensions'] : '';

?>
<div id="checkview-general-options" class="card">
	<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="POST">
		<input type="hidden" name="action" value="checkview_admin_api_settings">
		<?php wp_nonce_field( 'checkview_admin_api_settings_action', 'checkview_admin_api_settings_action' ); ?>
		<table class="form-table">
			<tbody>
				<tr valign="top">
					<th scope="row" >
						<label for="checkview_get_forms">
							<?php esc_html_e( 'Get forms', 'checkview' ); ?>
						</label>
						<p class="make-lib-description"><?php esc_html_e( 'Endpoint for fetching available forms from supported form plugins.', 'checkview' ); ?></p>
					</th>
					<td class="checkview-make-library-box">
					<label  for="checkview_get_forms">
						<p class="make-lib-description"><?php echo esc_url_raw( get_rest_url() . 'checkview/v1/forms/formslist' ); ?></p>
					</label>
					</td>
				</tr>
				<tr valign="top">
					<th scope="row" >
						<label for="checkview_register_forms_test">
							<?php esc_html_e( 'Register test', 'checkview' ); ?>
						</label>
						<p class="make-lib-description"><?php esc_html_e( 'Endpoint to initiate the registration of a new form or checkout test.', 'checkview' ); ?></p>
					</th>
					<td class="checkview-make-library-box">
					<label  for="checkview_register_forms_test">
						<p class="make-lib-description"><?php echo esc_url_raw( get_rest_url() . 'checkview/v1/forms/registerformtest' ); ?></p>
					</label>
					</td>
				</tr>

				<tr valign="top">
					<th scope="row" >
						<label for="checkview_get_forms_test">
							<?php esc_html_e( 'Get test', 'checkview' ); ?>
						</label>
						<p class="make-lib-description"><?php esc_html_e( 'Endpoint for fetching the form test details.', 'checkview' ); ?></p>
					</th>
					<td class="checkview-make-library-box">
					<label  for="checkview_get_forms_test">
						<p class="make-lib-description"><?php echo esc_url_raw( get_rest_url() . 'checkview/v1/forms/formstestresults' ); ?></p>
					</label>
					</td>
				</tr>

				<tr valign="top">
					<th scope="row" >
						<label for="checkview_get_products">
							<?php esc_html_e( 'Get products', 'checkview' ); ?>
						</label>
						<p class="make-lib-description"><?php esc_html_e( 'Endpoint for fetching available Woo products.', 'checkview' ); ?></p>
					</th>
					<td class="checkview-make-library-box">
					<label  for="checkview_get_products">
						<p class="make-lib-description"><?php echo esc_url_raw( get_rest_url() . 'checkview/v1/store/products' ); ?></p>
					</label>
					</td>
				</tr>

				<tr valign="top">
					<th scope="row" >
						<label for="checkview_get_orders">
							<?php esc_html_e( 'Get orders', 'checkview' ); ?>
						</label>
						<p class="make-lib-description"><?php esc_html_e( 'Endpoint for fetching available test orders.', 'checkview' ); ?></p>
					</th>
					<td class="checkview-make-library-box">
					<label  for="checkview_get_orders">
						<p class="make-lib-description"><?php echo esc_url_raw( get_rest_url() . 'checkview/v1/store/orders' ); ?></p>
					</label>
					</td>
				</tr>

				<tr valign="top">
					<th scope="row" >
						<label for="checkview_get_order_details">
							<?php esc_html_e( 'Retrieves checkview order details from store', 'checkview' ); ?>
						</label>
						<p class="make-lib-description"><?php esc_html_e( 'Use this endpoint to get specific order details created by checkview.', 'checkview' ); ?></p>
					</th>
					<td class="checkview-make-library-box">
					<label  for="checkview_get_order_details">
						<p class="make-lib-description"><?php echo esc_url_raw( get_rest_url() . 'checkview/v1/store/order?checkview_order_id=x' ); ?></p>
					</label>
					</td>
				</tr>

				<tr valign="top">
					<th scope="row" >
						<label for="checkview_get_shipping_details">
							<?php esc_html_e( 'Get shipping details', 'checkview' ); ?>
						</label>
						<p class="make-lib-description"><?php esc_html_e( 'Endpoint for fetching the Woo store shipping details.', 'checkview' ); ?></p>
					</th>
					<td class="checkview-make-library-box">
					<label  for="checkview_get_shipping_details">
						<p class="make-lib-description"><?php echo esc_url_raw( get_rest_url() . 'checkview/v1/store/shippingdetails' ); ?></p>
					</label>
					</td>
				</tr>

				<tr valign="top">
					<th scope="row" >
						<label for="checkview_delete_orders">
							<?php esc_html_e( 'Delete test orders', 'checkview' ); ?>
						</label>
						<p class="make-lib-description"><?php esc_html_e( 'Endpoint for deleting the Woo store test orders.', 'checkview' ); ?></p>
					</th>
					<td class="checkview-make-library-box">
					<label  for="checkview_delete_orders">
						<p class="make-lib-description"><?php echo esc_url_raw( get_rest_url() . 'checkview/v1/store/deleteorders' ); ?></p>
					</label>
					</td>
				</tr>

				<tr valign="top">
					<th scope="row" >
						<label for="checkview_active_payment_gateways">
							<?php esc_html_e( 'Active payment gateways', 'checkview' ); ?>
						</label>
						<p class="make-lib-description"><?php esc_html_e( 'Use this endpoint to get WooCommerce active payment gateways.', 'checkview' ); ?></p>
					</th>
					<td class="checkview-make-library-box">
					<label  for="checkview_active_payment_gateways">
						<p class="make-lib-description"><?php echo esc_url_raw( get_rest_url() . 'checkview/v1/store/activegateways' ); ?></p>
					</label>
					</td>
				</tr>

				<tr valign="top">
					<th scope="row" >
						<label for="checkview_cart_details">
							<?php esc_html_e( 'Cart details', 'checkview' ); ?>
						</label>
						<p class="make-lib-description"><?php esc_html_e( 'Use this endpoint to get cart details.', 'checkview' ); ?></p>
					</th>
					<td class="checkview-make-library-box">
					<label  for="checkview_cart_details">
						<p class="make-lib-description"><?php echo esc_url_raw( get_rest_url() . 'checkview/v1/store/cartdetails' ); ?></p>
					</label>
					</td>
				</tr>

				<tr valign="top">
					<th scope="row" >
						<label for="checkview_create_customer">
							<?php esc_html_e( 'Create customer', 'checkview' ); ?>
						</label>
						<p class="make-lib-description"><?php esc_html_e( 'Use this endpoint to create test customer for checkview', 'checkview' ); ?></p>
					</th>
					<td class="checkview-make-library-box">
					<label  for="checkview_create_customer">
						<p class="make-lib-description"><?php echo esc_url_raw( get_rest_url() . 'checkview/v1/store/createtestcustomer' ); ?></p>
					</label>
					</td>
				</tr>

				<tr valign="top">
					<th scope="row" >
						<label for="checkview_get_customer">
							<?php esc_html_e( 'Get customer', 'checkview' ); ?>
						</label>
						<p class="make-lib-description"><?php esc_html_e( 'Use this endpoint to get credentials for the test customer created by SaaS.', 'checkview' ); ?></p>
					</th>
					<td class="checkview-make-library-box">
					<label  for="checkview_delete_orders">
						<p class="make-lib-description"><?php echo esc_url_raw( get_rest_url() . 'checkview/v1/store/gettestcustomer' ); ?></p>
					</label>
					</td>
				</tr>

				<tr valign="top">
					<th scope="row" >
						<label for="checkview_get_store_locations">
							<?php esc_html_e( 'Get store locations', 'checkview' ); ?>
						</label>
						<p class="make-lib-description"><?php esc_html_e( 'Use this endpoint to get store locations.', 'checkview' ); ?></p>
					</th>
					<td class="checkview-make-library-box">
					<label  for="checkview_get_store_locations">
						<p class="make-lib-description"><?php echo esc_url_raw( get_rest_url() . 'checkview/v1/store/getstorelocations' ); ?></p>
					</label>
					</td>
				</tr>

				<tr valign="top">
					<th scope="row" >
						<label for="checkview_get_site_info">
							<?php esc_html_e( 'Get site info', 'checkview' ); ?>
						</label>
						<p class="make-lib-description"><?php esc_html_e( 'Use this endpoint to get site info.', 'checkview' ); ?></p>
					</th>
					<td class="checkview-make-library-box">
					<label  for="checkview_get_site_info">
						<p class="make-lib-description"><?php echo esc_url_raw( get_rest_url() . 'checkview/v1/site-info' ); ?></p>
					</label>
					</td>
				</tr>

				<tr valign="top">
					<th scope="row" >
						<label for="checkview_get_plugin_version">
							<?php esc_html_e( 'Get plugin version', 'checkview' ); ?>
						</label>
						<p class="make-lib-description"><?php esc_html_e( 'Use this endpoint to get plugin version.', 'checkview' ); ?></p>
					</th>
					<td class="checkview-make-library-box">
					<label  for="checkview_get_plugin_version">
						<p class="make-lib-description"><?php echo esc_url_raw( get_rest_url() . 'checkview/v1/plugin-version' ); ?></p>
					</label>
					</td>
				</tr>

				<tr valign="top">
					<th scope="row" >
						<label for="checkview_get_helper_logs">
							<?php esc_html_e( 'Get helper logs', 'checkview' ); ?>
						</label>
						<p class="make-lib-description"><?php esc_html_e( 'Use this endpoint to get helper logs.', 'checkview' ); ?></p>
					</th>
					<td class="checkview-make-library-box">
					<label  for="checkview_get_helper_logs">
						<p class="make-lib-description"><?php echo esc_url_raw( get_rest_url() . 'checkview/v1/get-logs' ); ?></p>
					</label>
					</td>
				</tr>

				<tr valign="top">
					<th scope="row" >
						<label for="checkview_verify_test_user_credentials">
							<?php esc_html_e( 'Verify user', 'checkview' ); ?>
						</label>
						<p class="make-lib-description"><?php esc_html_e( 'Use this endpoint to verify test user by email.', 'checkview' ); ?></p>
					</th>
					<td class="checkview-make-library-box">
					<label  for="checkview_verify_test_user_credentials">
						<p class="make-lib-description"><?php echo esc_url_raw( get_rest_url() . 'checkview/v1/verifytestuser' ); ?></p>
					</label>
					</td>
				</tr>

				<tr valign="top">
					<th scope="row" >
						<label for="checkview_delete_test_user_credentials">
							<?php esc_html_e( 'Delete user', 'checkview' ); ?>
						</label>
						<p class="make-lib-description"><?php esc_html_e( 'Use this endpoint to delete test user.', 'checkview' ); ?></p>
					</th>
					<td class="checkview-make-library-box">
					<label  for="checkview_delete_test_user_credentials">
						<p class="make-lib-description"><?php echo esc_url_raw( get_rest_url() . 'checkview/v1/deletetestuser' ); ?></p>
					</label>
					</td>
				</tr>

				<tr valign="top">
					<th scope="row" >
						<label for="checkview_helper_status">
							<?php esc_html_e( 'Helper status', 'checkview' ); ?>
						</label>
						<p class="make-lib-description"><?php esc_html_e( 'Use this endpoint to set helper visibility status.', 'checkview' ); ?></p>
					</th>
					<td class="checkview-make-library-box">
					<label  for="checkview_helper_status">
						<p class="make-lib-description"><?php echo esc_url_raw( get_rest_url() . 'checkview/v1/checkview-status' ); ?></p>
					</label>
					</td>
				</tr>

				<tr valign="top">
					<th scope="row" >
						<label for="checkview_test_product_status">
							<?php esc_html_e( 'Test product status', 'checkview' ); ?>
						</label>
						<p class="make-lib-description"><?php esc_html_e( 'Use this endpoint to set test product visibility status.', 'checkview' ); ?></p>
					</th>
					<td class="checkview-make-library-box">
					<label  for="checkview_test_product_status">
						<p class="make-lib-description"><?php echo esc_url_raw( get_rest_url() . 'checkview/v1/set-status' ); ?></p>
					</label>
					</td>
				</tr>

				<?php do_action( 'checkview_api_settings', $checkview_options ); ?>
			</tbody>
		</table>
	</form>
</div>

<?php