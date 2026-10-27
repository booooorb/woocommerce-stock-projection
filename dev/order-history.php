<?php
use VeryShop\ProductAnalysis\Repository;
use VeryShop\ProductAnalysis\StockHistory;
use VeryShop\ProductAnalysis\Reports;

function vpa_events( int $id, array $args ) {
    $request = new WP_REST_Request( 'GET', '/veryshop/v1/products/' . $id . '/events' );
    foreach ( $args as $key => $value ) { $request->set_param( $key, $value ); }
    return rest_do_request( $request );
}
function vpa_latest_observation( int $id ): array {
    global $wpdb;
    return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . StockHistory::table() . ' WHERE stock_owner_id = %d ORDER BY recorded_at DESC, id DESC LIMIT 1', $id ), ARRAY_A );
}

$hover_product = vpa_product( 'Order hover fixture', 100 );
$hover_order = vpa_order( array( array( $hover_product, 3 ) ), 0, 'pending' );
$hover_order->set_billing_first_name( 'Avery' ); $hover_order->set_billing_last_name( 'Chen' );
$hover_order->set_billing_email( 'private-customer@example.test' ); $hover_order->set_billing_address_1( 'Private test address' ); $hover_order->save();
$initial = vpa_latest_observation( $hover_product->get_id() );
remove_filter( 'woocommerce_can_reduce_order_stock', '__return_false' );
wc_reduce_stock_levels( $hover_order );
add_filter( 'woocommerce_can_reduce_order_stock', '__return_false' );
$drop = vpa_latest_observation( $hover_product->get_id() );
vpa_check( (int) $drop['order_id'] === $hover_order->get_id() && (int) $drop['order_item_id'] === array_key_first( $hover_order->get_items() ), 'Real WooCommerce stock reduction is linked to its exact order and line item' );
vpa_check( vpa_near( $drop['previous_quantity'], 100 ) && vpa_near( $drop['quantity'], 97 ) && vpa_near( $drop['depleted'], 3 ), 'Order attribution preserves stock quantities and counts depletion exactly once' );
$args = array( 'kind' => 'recorded', 'start' => strtotime( $initial['recorded_at'] . ' UTC' ), 'end' => time(), 'start_id' => (int) $initial['id'], 'end_id' => (int) $drop['id'], 'page' => 1 );
$response = vpa_events( $hover_product->get_id(), $args ); $body = $response->get_data();
vpa_check( 200 === $response->get_status() && 1 === count( $body['events'] ) && vpa_near( $body['events'][0]['delta'], -3 ) && $body['events'][0]['order']['number'] === (string) $hover_order->get_order_number(), 'Hover endpoint returns the correct order, exact before/after quantities and delta' );
vpa_check( str_contains( $body['events'][0]['order']['url'], 'action=edit' ) && ! str_contains( wp_json_encode( $body ), 'billing' ) && ! str_contains( wp_json_encode( $body ), 'customer_id' ) && ! str_contains( wp_json_encode( $body ), 'private-customer@example.test' ) && ! str_contains( wp_json_encode( $body ), 'Private test address' ), 'Hover returns an HPOS-aware edit link without customer contact details' );
vpa_check( 'Avery Chen' === $body['events'][0]['order']['customer_name'], 'A named guest order displays the customer billing name in hover details' );
$read_revision = Reports::revision(); $source_order = wp_json_encode( wc_get_order( $hover_order->get_id() )->get_data() );
vpa_events( $hover_product->get_id(), $args );
vpa_check( $read_revision === Reports::revision() && $source_order === wp_json_encode( wc_get_order( $hover_order->get_id() )->get_data() ), 'Hover reads do not mutate the source order or invalidate graph caches' );
wc_update_product_stock( $hover_product->get_id(), 2, 'decrease' ); $manual = vpa_latest_observation( $hover_product->get_id() );
vpa_check( 0 === (int) $manual['order_id'] && vpa_near( $manual['previous_quantity'], 97 ) && vpa_near( $manual['quantity'], 95 ), 'A manual decrease is never falsely attributed to a previous order' );
$ambiguous_product = vpa_product( 'Ambiguous stock fixture', 100 );
$ambiguous_order = vpa_order( array( array( $ambiguous_product, 3 ) ), 0, 'pending' );
wc_update_product_stock( $ambiguous_product, 3, 'decrease' ); wc_update_product_stock( $ambiguous_product, 3, 'increase' ); wc_update_product_stock( $ambiguous_product, 3, 'decrease' );
$ambiguous_items = $ambiguous_order->get_items(); StockHistory::link_order( reset( $ambiguous_items ), $ambiguous_order, 100, 97, 'order' );
vpa_check( 0 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . StockHistory::table() . ' WHERE order_id = %d', $ambiguous_order->get_id() ) ), 'Ambiguous repeated stock transitions are left unlinked rather than guessed' );
$shared_order = vpa_order( array( array( $variations[0], 2 ), array( $variations[1], 1 ) ), 0, 'pending' );
remove_filter( 'woocommerce_can_reduce_order_stock', '__return_false' ); wc_reduce_stock_levels( $shared_order ); add_filter( 'woocommerce_can_reduce_order_stock', '__return_false' );
$linked = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . StockHistory::table() . ' WHERE order_id = %d ORDER BY id', $shared_order->get_id() ), ARRAY_A );
vpa_check( 2 === count( $linked ) && (int) $linked[0]['stock_owner_id'] === $parent->get_id() && (int) $linked[1]['stock_owner_id'] === $parent->get_id(), 'Shared-stock variations retain separate exact order-item associations in the parent stock pool' );
wc_increase_stock_levels( $hover_order ); $restored = vpa_latest_observation( $hover_product->get_id() );
vpa_check( vpa_near( $restored['quantity'], 98 ) && vpa_near( $restored['previous_quantity'], 95 ) && ( version_compare( WC_VERSION, '9.1', '<' ) || ( (int) $restored['order_id'] === $hover_order->get_id() && 'order_restore' === $restored['source'] ) ), 'Restoration remains a stock increase and supported WooCommerce versions link its order' );
$error_product = vpa_product( 'Order-link failure fixture', 20 ); $error_order = vpa_order( array( array( $error_product, 2 ) ), 0, 'pending' );
$break_link = static function ( $sql ) { if ( preg_match( '/^UPDATE.*veryshop_stock_history.*order_id/i', $sql ) ) { throw new RuntimeException( 'Simulated order-link failure' ); } return $sql; };
remove_filter( 'woocommerce_can_reduce_order_stock', '__return_false' ); add_filter( 'query', $break_link ); wc_reduce_stock_levels( $error_order ); remove_filter( 'query', $break_link ); add_filter( 'woocommerce_can_reduce_order_stock', '__return_false' );
vpa_check( vpa_near( wc_get_product( $error_product->get_id() )->get_stock_quantity(), 18 ), 'Order-linking failure cannot interrupt or duplicate WooCommerce stock reduction' );
$args_estimated = array( 'kind' => 'estimated', 'start' => ( new DateTimeImmutable( 'today', wp_timezone() ) )->getTimestamp(), 'end' => time(), 'page' => 1 );
$estimated = vpa_events( $constant->get_id(), $args_estimated )->get_data();
vpa_check( 1 === count( $estimated['events'] ) && vpa_near( $estimated['events'][0]['units'], 5 ) && ! isset( $estimated['events'][0]['before'] ), 'Estimated dips expose contributing indexed orders without pretending to know recorded stock levels' );
vpa_check( 'Guest customer' === $estimated['events'][0]['order']['customer_name'], 'Orders without a stored customer name have a clear guest fallback' );
$unrelated = vpa_events( $hover_product->get_id(), array_merge( $args, array( 'start_id' => 0, 'end_id' => (int) $linked[1]['id'] ) ) )->get_data();
vpa_check( ! array_filter( $unrelated['events'], static function ( $event ) use ( $shared_order ) { return ( $event['order']['number'] ?? '' ) === (string) $shared_order->get_order_number(); } ), 'Hover queries cannot leak another stock pool into the selected product interval' );
for ( $i = 0; $i < 23; $i++ ) { StockHistory::store( $hover_product->get_id(), 80 - $i, time(), 'fixture' ); }
$last_hover = vpa_latest_observation( $hover_product->get_id() );
$paged_args = array_merge( $args, array( 'end' => time(), 'end_id' => (int) $last_hover['id'] ) );
$paged = vpa_events( $hover_product->get_id(), $paged_args )->get_data();
vpa_check( 20 === count( $paged['events'] ) && $paged['has_more'] && count( vpa_events( $hover_product->get_id(), array_merge( $paged_args, array( 'page' => 2 ) ) )->get_data()['events'] ) > 0, 'Dense hover intervals are paginated to twenty changes per request' );
wp_set_current_user( 0 ); vpa_check( 401 === vpa_events( $hover_product->get_id(), $args )->get_status(), 'Anonymous requests cannot inspect order hover data' );
wp_set_current_user( 1 );
$deny_orders = static function ( $caps ) { $caps['edit_shop_orders'] = false; return $caps; };
add_filter( 'user_has_cap', $deny_orders ); $limited = vpa_events( $hover_product->get_id(), $args ); remove_filter( 'user_has_cap', $deny_orders );
vpa_check( 403 === $limited->get_status(), 'Graph access alone does not grant permission to inspect order details' );
vpa_check( 400 === vpa_events( $hover_product->get_id(), array_merge( $args, array( 'page' => 10001 ) ) )->get_status() && 400 === vpa_events( $hover_product->get_id(), array_merge( $args, array( 'kind' => 'invalid' ) ) )->get_status(), 'Hover page bounds and history kind are validated' );

// Exercise an actual old-table migration in separate plugin-only tables.
$saved_prefix = $wpdb->prefix; $saved_version = get_option( 'vpa_db_version' );
try {
    $wpdb->prefix = $saved_prefix . 'vpa_upgrade_test_'; $old_table = StockHistory::table();
    $wpdb->query( "CREATE TABLE $old_table (id bigint(20) unsigned NOT NULL AUTO_INCREMENT, stock_owner_id bigint(20) unsigned NOT NULL, recorded_at datetime NOT NULL, recorded_on date NOT NULL, quantity decimal(18,4) NOT NULL DEFAULT 0, depleted decimal(18,4) NOT NULL DEFAULT 0, source varchar(20) NOT NULL DEFAULT 'recorded', PRIMARY KEY (id))" );
    $wpdb->insert( $old_table, array( 'stock_owner_id' => 123, 'recorded_at' => gmdate( 'Y-m-d H:i:s' ), 'recorded_on' => gmdate( 'Y-m-d' ), 'quantity' => 97, 'depleted' => 3, 'source' => 'change' ) );
    update_option( 'vpa_db_version', '1' ); $upgraded = Repository::install();
    $old = $wpdb->get_row( "SELECT * FROM $old_table LIMIT 1", ARRAY_A );
    vpa_check( $upgraded && Repository::ready() && vpa_near( $old['quantity'], 97 ) && vpa_near( $old['depleted'], 3 ) && null === $old['previous_quantity'] && 0 === (int) $old['order_id'], 'Schema upgrade preserves old stock observations and leaves unknown order associations empty' );
} finally {
    $wpdb->query( 'DROP TABLE IF EXISTS ' . StockHistory::table() ); $wpdb->query( 'DROP TABLE IF EXISTS ' . Repository::table() );
    $wpdb->prefix = $saved_prefix; update_option( 'vpa_db_version', $saved_version );
}
