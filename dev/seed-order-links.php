<?php
/** Replace stock-only demo fixtures with observations constructed from the existing demo orders. Never shipped. */
use VeryShop\ProductAnalysis\Repository;
use VeryShop\ProductAnalysis\StockHistory;

if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG || 'VeryShop · Local development' !== get_bloginfo( 'name' ) || ! get_option( 'vpa_demo_stock_seeded' ) || get_option( 'vpa_demo_order_links_seeded' ) ) { return; }
global $wpdb;
$table = StockHistory::table(); $sales = Repository::table();
$owners = $wpdb->get_results( "SELECT stock_owner_id, MIN(recorded_at) AS first_at, MAX(recorded_at) AS last_at FROM $table WHERE source = 'demo' GROUP BY stock_owner_id", ARRAY_A );
$orders = array(); $linked = 0; $stocks = array();
foreach ( $owners as $owner ) {
    $id = (int) $owner['stock_owner_id']; $product = wc_get_product( $id );
    if ( ! $product || ! str_starts_with( $product->get_sku(), 'VS-' ) ) { continue; }
    $stocks[ $id ] = $product->get_stock_quantity();
    $first = strtotime( $owner['first_at'] . ' UTC' ); $last = strtotime( $owner['last_at'] . ' UTC' );
    $end_stock = (float) $wpdb->get_var( $wpdb->prepare( "SELECT quantity FROM $table WHERE stock_owner_id = %d AND source = 'demo' ORDER BY recorded_at DESC,id DESC LIMIT 1", $id ) );
    $rows = $wpdb->get_results( $wpdb->prepare( "SELECT order_id,order_item_id,quantity FROM $sales WHERE stock_owner_id = %d AND quantity > 0 ORDER BY sold_on,order_id,order_item_id", $id ), ARRAY_A );
    $events = array();
    foreach ( $rows as $row ) {
        $order_id = (int) $row['order_id'];
        if ( ! array_key_exists( $order_id, $orders ) ) { $orders[ $order_id ] = wc_get_order( $order_id ); }
        $order = $orders[ $order_id ];
        if ( ! $order || 'veryshop-demo' !== $order->get_created_via() || ! $order->get_date_created() ) { continue; }
        $at = $order->get_date_created()->getTimestamp();
        if ( $at > $first && $at <= $last ) { $events[] = array_merge( $row, array( 'at' => $at ) ); }
    }
    usort( $events, static function ( $a, $b ) { return $a['at'] <=> $b['at']; } );
    $total = array_sum( array_column( $events, 'quantity' ) );
    $restock = count( $events ) >= 8 && $end_stock > 0 ? floor( $total / 5 ) : 0;
    $slots = $restock > 0 ? array_unique( array_map( static function ( $part ) use ( $events ) { return (int) floor( ( count( $events ) - 1 ) * $part / 4 ); }, array( 0, 1, 2, 3 ) ) ) : array();
    $quantity = $end_stock + $total - count( $slots ) * $restock;
    $wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE stock_owner_id = %d AND source IN ('demo','demo_baseline','demo_order','demo_restock')", $id ) );
    if ( ! StockHistory::store( $id, $quantity, $first, 'demo_baseline' ) ) { throw new RuntimeException( 'Unable to construct demo stock baseline.' ); }
    foreach ( $events as $index => $event ) {
        $quantity -= (float) $event['quantity'];
        if ( ! StockHistory::store( $id, $quantity, $event['at'], 'demo_order' ) ) { throw new RuntimeException( 'Unable to construct demo order observation.' ); }
        $observation = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE stock_owner_id = %d AND recorded_at = %s AND source = 'demo_order' ORDER BY id DESC LIMIT 1", $id, gmdate( 'Y-m-d H:i:s', $event['at'] ) ) );
        $wpdb->update( $table, array( 'order_id' => $event['order_id'], 'order_item_id' => $event['order_item_id'] ), array( 'id' => $observation ), array( '%d', '%d' ), array( '%d' ) );
        $linked++;
        if ( in_array( $index, $slots, true ) ) {
            $quantity += $restock;
            StockHistory::store( $id, $quantity, min( $last, $event['at'] + HOUR_IN_SECONDS ), 'demo_restock' );
        }
    }
    StockHistory::store( $id, $end_stock, $last, 'demo_baseline' );
    if ( abs( $quantity - $end_stock ) > 0.0001 || $stocks[ $id ] !== wc_get_product( $id )->get_stock_quantity() ) { throw new RuntimeException( 'Demo order fixture changed real stock or failed to reconcile.' ); }
}
update_option( 'vpa_demo_order_links_seeded', true, false );
Repository::invalidate();
echo "Order-backed demo stock history ready ($linked linked changes); WooCommerce stock unchanged.\n";
