<?php
use VeryShop\ProductAnalysis\Model;
use VeryShop\ProductAnalysis\Reports;
use VeryShop\ProductAnalysis\Alerts;

$planned_product = vpa_product( 'Confirmed restock fixture', 40 );
$saved_stock = $planned_product->get_stock_quantity(); $owner_id = $planned_product->get_id();
$arrival = wp_date( 'Y-m-d', time() + 7 * DAY_IN_SECONDS );
$plan_body = array( 'quantity' => 400, 'arrival' => $arrival, 'confirmed' => true );
$save_count = 0; $observe_save = static function () use ( &$save_count ) { $save_count++; };
add_action( 'woocommerce_before_product_object_save', $observe_save );
$plan_response = vpa_request( 'POST', 'products/' . $owner_id . '/planning', $plan_body );
remove_action( 'woocommerce_before_product_object_save', $observe_save );
$saved_plan = Reports::planning( wc_get_product( $owner_id ) );
vpa_check( 200 === $plan_response->get_status() && $saved_plan['confirmed'] && $saved_plan['on_order'] && 400.0 === $saved_plan['quantity'], 'Confirming a restock persists quantity, arrival and ordered state immediately' );
vpa_check( 0 === $save_count && $saved_stock === wc_get_product( $owner_id )->get_stock_quantity(), 'Planning does not save WooCommerce products or add purchased units to real stock' );
$held = get_option( 'vpa_planning_' . $owner_id );
foreach ( array( 0, -1, true, 'many', null, 0.00001, 1000000001 ) as $invalid_quantity ) {
    vpa_check( 400 === vpa_request( 'POST', 'products/' . $owner_id . '/planning', array_merge( $plan_body, array( 'quantity' => $invalid_quantity ) ) )->get_status(), 'Invalid planning quantity is rejected: ' . wp_json_encode( $invalid_quantity ) );
}
vpa_check( $held === get_option( 'vpa_planning_' . $owner_id ) && 400 === vpa_request( 'POST', 'products/' . $owner_id . '/planning', array_merge( $plan_body, array( 'confirmed' => false ) ) )->get_status(), 'Unconfirmed or invalid input cannot replace an existing ordered plan' );
vpa_check( 400 === vpa_request( 'POST', 'products/' . $owner_id . '/planning', array_merge( $plan_body, array( 'arrival' => wp_date( 'Y-m-d', time() - DAY_IN_SECONDS ) ) ) )->get_status(), 'New orders cannot have a past arrival date' );
$math = Model::restock( 100, 5, 400, 1000000, 1000000 + 7 * DAY_IN_SECONDS );
vpa_check( vpa_near( $math['before'], 65 ) && vpa_near( $math['after'], 465 ) && vpa_near( $math['coverage_days'], 93 ) && $math['stockout_at'] === 1000000 + 100 * DAY_IN_SECONDS, 'The replenishment model includes pre-arrival depletion and post-arrival coverage' );
$late = Model::restock( 100, 5, 400, 1000000, 1000000 + 30 * DAY_IN_SECONDS );
vpa_check( vpa_near( $late['before'], 0 ) && vpa_near( $late['coverage_days'], 80 ) && $late['stockout_at'] === 1000000 + 110 * DAY_IN_SECONDS, 'A delivery after depletion preserves the stock-out gap rather than inventing negative demand' );
vpa_check( null === Model::restock( 100, 0, 400, 1000000, 2000000 )['stockout_at'] && null === Model::restock( -10, 5, 5, 1000000, 2000000 )['stockout_at'], 'No rate or insufficient delivery does not fabricate a future stock-out marker' );
wc_update_product_stock( $owner_id, 0, 'set' );
vpa_check( 'ordered' === vpa_row( $owner_id )['status'] && ! array_filter( Alerts::candidates(), static function ( $row ) use ( $owner_id ) { return $row['stock_owner_id'] === $owner_id; } ), 'Confirmed orders suppress even out-of-stock warnings and email candidates' );
$held['arrival'] = wp_date( 'Y-m-d', time() - DAY_IN_SECONDS ); update_option( 'vpa_planning_' . $owner_id, $held, false );
vpa_check( Reports::planning( wc_get_product( $owner_id ) )['on_order'], 'Ordered plans remain acknowledged until the user clears them' );
vpa_request( 'POST', 'products/' . $owner_id . '/planning', array( 'clear' => true ) );
vpa_check( ! Reports::planning( wc_get_product( $owner_id ) )['on_order'] && 'out' === vpa_row( $owner_id )['status'] && count( array_filter( Alerts::candidates(), static function ( $row ) use ( $owner_id ) { return $row['stock_owner_id'] === $owner_id; } ) ) === 1, 'Clearing the plan removes its warning pause without changing stock' );
vpa_request( 'POST', 'products/' . $variations[0]->get_id() . '/planning', $plan_body );
vpa_check( Reports::planning( wc_get_product( $parent->get_id() ) )['quantity'] === 400.0 && vpa_row( $variations[1]->get_id() )['planning']['confirmed'], 'A shared-stock plan applies to every variation in its pool' );
vpa_request( 'POST', 'products/' . $parent->get_id() . '/planning', array( 'clear' => true ) );
