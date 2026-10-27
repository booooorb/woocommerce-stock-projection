<?php
/**
 * Plugin Name: VeryShop Product Analysis
 * Description: Stock history, depletion projections, replenishment planning, and product navigation for WooCommerce.
 * Version: 1.7.2
 * Requires at least: 6.6
 * Requires PHP: 8.0
 * Requires Plugins: woocommerce
 * WC requires at least: 9.0
 * Author: VeryShop
 * License: GPL-2.0-or-later
 * Text Domain: veryshop-product-analysis
 */

defined( 'ABSPATH' ) || exit;

define( 'VPA_VERSION', '1.7.2' );
define( 'VPA_FILE', __FILE__ );

require_once __DIR__ . '/includes/class-model.php';
require_once __DIR__ . '/includes/class-safety.php';
require_once __DIR__ . '/includes/class-stock-history.php';
require_once __DIR__ . '/includes/class-repository.php';
require_once __DIR__ . '/includes/class-reports.php';
require_once __DIR__ . '/includes/class-history-events.php';
require_once __DIR__ . '/includes/class-alerts.php';
require_once __DIR__ . '/includes/class-plugin.php';

add_action( 'before_woocommerce_init', static function () {
    if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', VPA_FILE, true );
    }
} );

register_activation_hook( __FILE__, array( '\VeryShop\ProductAnalysis\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( '\VeryShop\ProductAnalysis\Plugin', 'deactivate' ) );
add_action( 'plugins_loaded', array( '\VeryShop\ProductAnalysis\Plugin', 'boot' ), 20 );
