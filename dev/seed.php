<?php
/** Deterministic demo history, used only in the dedicated local development site. */
if ( get_option( 'vpa_demo_seeded' ) ) {
    require __DIR__ . '/seed-stock.php';
    return;
}

use VeryShop\ProductAnalysis\Repository;

echo "Creating demo products and dated WooCommerce orders…\n";
$specs = array(
    array( 'Everyday Tote', 'VS-TOTE-01', 28, 240, 5.2 ),
    array( 'Ceramic Travel Cup', 'VS-CUP-02', 24, 38, 3.8 ),
    array( 'Linen Tea Towel', 'VS-LINEN-03', 16, 150, 2.8 ),
    array( 'Desk Notebook', 'VS-NOTE-04', 18, 64, 2.1 ),
    array( 'Pour-over Coffee Set', 'VS-COFFEE-05', 54, 18, 1.5 ),
    array( 'Cotton Throw', 'VS-THROW-06', 68, 80, 0.8 ),
    array( 'Glass Water Bottle', 'VS-BOTTLE-07', 32, 0, 0.7 ),
    array( 'Oak Desk Tray', 'VS-TRAY-08', 36, 2, 0.3 ),
    array( 'New Season Candle', 'VS-CANDLE-09', 22, 45, 0.0 ),
);
$products = array();
add_filter( 'woocommerce_can_reduce_order_stock', '__return_false' );
foreach ( $specs as $spec ) {
    $product = new WC_Product_Simple();
    $product->set_name( $spec[0] );
    $product->set_sku( $spec[1] );
    $product->set_status( 'publish' );
    $product->set_regular_price( $spec[2] );
    $product->set_manage_stock( true );
    $product->set_stock_quantity( $spec[3] );
    $product->save();
    $products[] = $product;
}
// One order per week, with quantities distributed across different selling patterns.
// Dated records cover more than a year so all-time and yearly filters differ.
$today = new DateTimeImmutable( 'today', wp_timezone() );
for ( $week = 0; $week < 60; $week++ ) {
    $date = $today->modify( '-' . ( $week * 7 ) . ' days' )->setTime( 10, 0 );
    $order = wc_create_order( array( 'status' => 'pending', 'created_via' => 'veryshop-demo' ) );
    $order->set_date_created( $date->getTimestamp() );
    $order->set_date_paid( $date->getTimestamp() );
    foreach ( $products as $i => $product ) {
        $season = 1 + 0.24 * sin( $week / 4 + $i );
        $recent = 1 + ( 59 - $week ) / 180;
        $quantity = (int) round( $specs[ $i ][4] * 7 * $season * $recent );
        if ( $quantity > 0 ) {
            $order->add_product( $product, $quantity );
        }
    }
    $order->calculate_totals();
    // Retain hand-entered current stock; this is historical fixture data.
    $order->set_status( 'completed' );
    $order->save();
    Repository::sync_order( $order->get_id() );
}
remove_filter( 'woocommerce_can_reduce_order_stock', '__return_false' );
Repository::restart();
do { $state = Repository::import_batch(); } while ( ! $state['complete'] );
update_option( 'vpa_demo_seeded', true, false );
echo "Demo history ready.\n";
require __DIR__ . '/seed-stock.php';
