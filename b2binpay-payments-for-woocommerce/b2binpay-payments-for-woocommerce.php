<?php
/**
 * Plugin Name: B2BinPay Payments for WooCommerce
 * Plugin URI: https://wordpress.org/plugin/b2binpay-payments-for-woocommerce
 * Description: Accept Bitcoin, Bitcoin Cash, Litecoin, Ethereum and other CryptoCurrencies via B2BinPay (https://www.b2binpay.com).
 * Version: 1.0.0
 * Author: B2BinPay
 * Author URI: https://www.b2binpay.com
 * License: MIT
 * License URI: https://github.com/b2binpay/woocommerce/blob/master/LICENSE
 * Text Domain: b2binpay-payments-for-woocommerce
 * Domain Path: /languages
 * Github Plugin URI: https://github.com/b2binpay/woocommerce/
 * WC tested up to: 3.5
 * WC requires at least: 3.0
 *
 * @package B2Binpay
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

define( 'B2BINPAY_DIR', plugin_dir_path( __FILE__ ) );
define( 'B2BINPAY_URL', plugins_url( basename( B2BINPAY_DIR ), basename( __FILE__ ) ) . '/' );
define( 'B2BINPAY_ICON', B2BINPAY_URL . 'assets/b2binpay.png' );

/**
 * Initialize plugin
 */
function init_b2binpay() {
	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		return;
	};

	// Include the main B2BinPay Gateway class.
	if ( ! class_exists( 'WC_Gateway_B2Binpay' ) ) {
		// Require Composer Autoloader.
		require B2BINPAY_DIR . 'includes/autoload.php';

		// Require Main B2BinPay Gateway class.
		require B2BINPAY_DIR . 'class-wc-gateway-b2binpay.php';
	}

	/**
	 * Register B2BinPay in WooCommerce Payment Gateways.
	 *
	 * @param array $methods Methods array.
	 *
	 * @return array
	 */
	function add_b2binpay_gateway( $methods ) {
		$methods[] = 'WC_Gateway_B2Binpay';

		return $methods;
	}

	add_filter( 'woocommerce_payment_gateways', 'add_b2binpay_gateway' );
}

add_action( 'plugins_loaded', 'init_b2binpay' );
