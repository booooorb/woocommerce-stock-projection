<?php
/** Inventory fixtures for the isolated demo only; never shipped with the plugin. */
use VeryShop\ProductAnalysis\Repository;
use VeryShop\ProductAnalysis\Reports;
use VeryShop\ProductAnalysis\StockHistory;

if ( get_option( 'vpa_demo_stock_seeded' ) ) { require __DIR__ . '/seed-order-links.php'; return; }
$extra = array( 'Canvas Apron', 'Wool Desk Mat', 'Coaster Set', 'Storage Basket', 'Travel Pouch', 'Kitchen Brush', 'Cotton Napkins', 'Oak Wall Hook', 'Small Planter', 'Reusable Lunch Bag', 'Glass Storage Jar', 'Reading Light' );
foreach ( $extra as $i => $name ) {
    $sku = 'VS-EXTRA-' . ( $i + 1 );
    if ( wc_get_product_id_by_sku( $sku ) ) { continue; }
    $product = new WC_Product_Simple();
    $product->set_name( $name ); $product->set_sku( $sku ); $product->set_status( 'publish' );
    $product->set_regular_price( 20 + $i ); $product->set_manage_stock( true ); $product->set_stock_quantity( 15 + $i * 7 ); $product->save();
}
$today = new DateTimeImmutable( 'today', wp_timezone() );
$products = Reports::rows( '90' )['rows'];
foreach ( $products as $index => $row ) {
    if ( ! str_starts_with( $row['sku'], 'VS-' ) || null === $row['stock'] ) { continue; }
    $daily_rate = $row['rate'] > 0 ? $row['rate'] : ( str_starts_with( $row['sku'], 'VS-EXTRA-' ) ? 0.5 + ( $index % 5 ) / 2 : 0 );
    $outflow = array();
    for ( $day = 1; $day <= 120; $day++ ) { $outflow[ $day ] = max( 0, (int) round( $daily_rate * ( 1 + 0.12 * sin( $day / 7 ) ) ) ); }
    $restock = $row['stock'] >= 6 * $daily_rate ? (int) round( array_sum( $outflow ) / 4 ) : 0;
    $quantity = $row['stock'] + array_sum( $outflow ) - 4 * $restock;
    StockHistory::store( $row['stock_owner_id'], $quantity, $today->modify( '-120 days' )->setTime( 8, 0 )->getTimestamp(), 'demo' );
    for ( $day = 1; $day <= 120; $day++ ) {
        $at = $day === 120 ? time() - 2 : $today->modify( '-' . ( 120 - $day ) . ' days' )->setTime( 10, 0 )->getTimestamp();
        $quantity -= $outflow[ $day ];
        StockHistory::store( $row['stock_owner_id'], $quantity, $at, 'demo' );
        if ( in_array( $day, array( 5, 35, 65, 95 ), true ) && $restock > 0 ) {
            $quantity += $restock;
            StockHistory::store( $row['stock_owner_id'], $quantity, $at + HOUR_IN_SECONDS, 'demo' );
        }
    }
    StockHistory::capture( wc_get_product( $row['stock_owner_id'] ) );
}
update_option( 'vpa_demo_stock_seeded', true, false );
Repository::invalidate();
echo "Demo stock history and product pages ready.\n";
require __DIR__ . '/seed-order-links.php';
