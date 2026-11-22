<?php
/**
 * Plugin Name: InkOS for WooCommerce
 * Description: Quote print configurations and send accepted WooCommerce orders to InkOS.
 * Version: 1.0.0
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * WC requires at least: 8.8
 * WC tested up to: 11.3.1
 * Author: InkOS
 */
namespace InkOS\Woo;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/includes/api.php';
require_once __DIR__ . '/includes/admin.php';
require_once __DIR__ . '/includes/cart.php';
require_once __DIR__ . '/includes/catalog.php';
require_once __DIR__ . '/includes/orders.php';

add_action( 'before_woocommerce_init', static function () {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
	}
} );

add_action( 'plugins_loaded', static function () {
	if ( class_exists( 'WooCommerce' ) ) {
		Admin::hooks();
		Cart::hooks();
		Catalog::hooks();
		Orders::hooks();
	}
} );
