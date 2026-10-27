<?php
use VeryShop\ProductAnalysis\Reports;

$checks = 0;
function vpa_check( $condition, string $label ): void {
    global $checks;
    if ( ! $condition ) {
        throw new RuntimeException( 'FAILED: ' . $label );
    }
    $checks++;
    echo "PASS: $label\n";
}
function vpa_near( $a, $b ): bool { return abs( $a - $b ) < 0.00001; }
function vpa_request( string $method, string $path, ?array $data = null ) {
    $request = new WP_REST_Request( $method, '/veryshop/v1/' . $path );
    if ( null !== $data ) {
        $request->set_header( 'content-type', 'application/json' );
        $request->set_body( wp_json_encode( $data ) );
    }
    return rest_do_request( $request );
}
function vpa_product( string $name, ?int $stock = 100 ): WC_Product_Simple {
    $product = new WC_Product_Simple();
    $product->set_name( 'Test ' . $name );
    $product->set_status( 'publish' );
    $product->set_regular_price( 10 );
    $product->set_manage_stock( null !== $stock );
    if ( null !== $stock ) { $product->set_stock_quantity( $stock ); }
    $product->save();
    $ids = get_option( 'vpa_test_products', array() ); $ids[] = $product->get_id(); update_option( 'vpa_test_products', $ids, false );
    return $product;
}
function vpa_order( array $items, int $days_ago = 0, string $status = 'completed' ): WC_Order {
    $order = wc_create_order( array( 'status' => 'pending' ) );
    $date = ( new DateTimeImmutable( 'today', wp_timezone() ) )->modify( "-$days_ago days" )->setTime( 10, 0 );
    $order->set_date_created( $date->getTimestamp() );
    if ( in_array( $status, array( 'processing', 'completed' ), true ) ) { $order->set_date_paid( $date->getTimestamp() ); }
    foreach ( $items as $item ) { $order->add_product( $item[0], $item[1] ); }
    $order->calculate_totals();
    $order->set_status( $status ); $order->save();
    $ids = get_option( 'vpa_test_orders', array() ); $ids[] = $order->get_id(); update_option( 'vpa_test_orders', $ids, false );
    return $order;
}
function vpa_row( int $id, string $period = '30' ): array {
    foreach ( Reports::rows( $period )['rows'] as $row ) { if ( $row['id'] === $id ) { return $row; } }
    throw new RuntimeException( "Missing product $id" );
}

