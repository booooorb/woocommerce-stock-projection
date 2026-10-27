<?php
/** Focused real-WooCommerce compatibility checks, independent of ranking/refund query fixtures. */
use VeryShop\ProductAnalysis\Plugin;
use VeryShop\ProductAnalysis\Repository;

require __DIR__ . '/test-helpers.php';
wp_set_current_user( 1 );
add_filter( 'woocommerce_can_reduce_order_stock', '__return_false' );
Plugin::init();
$parent = new WC_Product_Variable();
$parent->set_name( 'Test Shared Inventory' ); $parent->set_status( 'publish' ); $parent->set_manage_stock( true ); $parent->set_stock_quantity( 100 ); $parent->save();
$ids = get_option( 'vpa_test_products', array() ); $ids[] = $parent->get_id();
$variations = array();
foreach ( array( 'Small', 'Large' ) as $size ) {
    $variation = new WC_Product_Variation(); $variation->set_parent_id( $parent->get_id() ); $variation->set_regular_price( 20 );
    $variation->set_attributes( array( 'size' => $size ) ); $variation->set_status( 'publish' ); $variation->set_manage_stock( false ); $variation->save();
    $variations[] = $variation; $ids[] = $variation->get_id();
}
update_option( 'vpa_test_products', $ids, false );
$constant = vpa_product( 'Constant Demand', 100 );
$constant_order = vpa_order( array( array( $constant, 5 ) ) );
Repository::sync_order( $constant_order->get_id() );
require __DIR__ . '/order-history.php';
echo "\nALL TESTS PASSED ($checks checks)\n";
echo 'Runtime: WordPress ' . get_bloginfo( 'version' ) . ' / WooCommerce ' . WC_VERSION . ' / PHP ' . PHP_VERSION . "\n";
echo 'Order storage: ' . ( \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ? 'HPOS' : 'legacy' ) . "\n";
