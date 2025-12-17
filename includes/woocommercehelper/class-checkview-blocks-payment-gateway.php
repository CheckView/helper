<?php
/**
 * Checkview_Blocks_Payment_Gateway class
 *
 * @since 1.0.0
 *
 * @package CheckView
 * @subpackage CheckView/includes/woocommercehelper
 */

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * Creates CheckView Blocks payment gateway.
 *
 * @since 1.0.3
 */
final class Checkview_Blocks_Payment_Gateway extends AbstractPaymentMethodType {

	/**
	 * Payment gateway.
	 *
	 * @var Checkview_Blocks_Payment_Gateway
	 */
	private $gateway;

	/**
	 * Payment gateway name.
	 *
	 * @var string
	 */
	protected $name = 'checkview';

	/**
	 * Payment gateway compatibilities.
	 *
	 * @var array
	 */
	protected $supports = array( 'checkview' );

	/**
	 * Initializes the payment gateway.
	 */
	public function initialize() {
		$this->settings = get_option( 'woocommerce_checkview_settings', array() );
		$gateways       = WC()->payment_gateways->payment_gateways();
		$this->gateway  = isset( $gateways[ $this->name ] ) ? $gateways[ $this->name ] : null;
	}

	/**
	 * Returns true.
	 *
	 * @return boolean
	 */
	public function is_active() {
		return true;
	}

	/**
	 * Registers scripts for our payment method.
	 *
	 * @return array
	 */
	public function get_payment_method_script_handles() {
		$script_path       = '/assets/js/frontend/blocks.js';
		$script_asset_path = CHECKVIEW_PLUGIN_DIR . '/js/frontend/blocks.asset.php';
		$script_asset      = file_exists( $script_asset_path )
			? require $script_asset_path
			: array(
				'dependencies' => array(),
				'version'      => '5',
			);
		$script_url        = CHECKVIEW_URI . $script_path;

		wp_register_script(
			'wc-checkview-payments-blocks',
			$script_url,
			$script_asset['dependencies'],
			$script_asset['version'],
			true
		);

		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( 'wc-checkview-payments-blocks', 'woocommerce-gateway-checkview', CHECKVIEW_PLUGIN_DIR . '/languages/' );
		}

		return array( 'wc-checkview-payments-blocks' );
	}

	/**
	 * Gets data from our payment method.
	 *
	 * @return array
	 */
	public function get_payment_method_data() {
		return array(
			'title'       => $this->get_setting( 'title' ),
			'description' => $this->get_setting( 'description' ),
			'supports'    => isset( $this->gateway->supports ) ? array_filter( $this->gateway->supports, array( $this->gateway, 'supports' ) ) : array(),
		);
	}
}
