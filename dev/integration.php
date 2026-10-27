<?php
use VeryShop\ProductAnalysis\Model;
use VeryShop\ProductAnalysis\Repository;
use VeryShop\ProductAnalysis\Reports;
use VeryShop\ProductAnalysis\Alerts;
use VeryShop\ProductAnalysis\Plugin;
use VeryShop\ProductAnalysis\StockHistory;

require __DIR__ . '/test-helpers.php';

try {
    wp_set_current_user( 1 );
    add_filter( 'woocommerce_can_reduce_order_stock', '__return_false' );
    foreach ( get_option( 'vpa_test_orders', array() ) as $id ) { $order = wc_get_order( $id ); if ( $order ) { $order->delete( true ); } }
    foreach ( array_reverse( get_option( 'vpa_test_products', array() ) ) as $id ) { $product = wc_get_product( $id ); if ( $product ) { $product->delete( true ); } }
    update_option( 'vpa_test_orders', array(), false ); update_option( 'vpa_test_products', array(), false );
    delete_option( 'vpa_settings' ); delete_option( 'vpa_alert_history' ); delete_option( 'vpa_email_anchor' );
    Plugin::init();
    update_option( 'veryshop_development_mail', array(), false );

    vpa_check( Reports::settings()['email_frequency_days'] === 7, 'Stock emails default to every seven days' );
    vpa_check( ! as_has_scheduled_action( 'vpa_email_checks', array(), Plugin::GROUP ), 'Disabled stock emails have no scheduled email checks' );
    vpa_check( Reports::settings()['reorder_days'] === 21, 'New installs default to reordering 21 days before stockout' );
    update_option( 'vpa_settings', array( 'lead_days' => 10, 'buffer_days' => 3, 'reminder_days' => 3 ), false );
    vpa_check( Reports::settings()['reorder_days'] === 13, 'Existing store lead and buffer settings migrate to one reorder-day value' );
    vpa_check( Reports::settings()['email_frequency_days'] === 7, 'The old per-product cooldown does not replace the new seven-day email default' );
    delete_option( 'vpa_settings' );

    $fit = Model::fit( array_fill( 0, 30, 5 ) );
    vpa_check( vpa_near( $fit['rate'], 5 ) && vpa_near( $fit['intercept'], 0 ), 'Constant demand fits an exact 5-unit/day line' );
    $inventory = Model::inventory( 100, $fit['rate'], 13 );
    vpa_check( vpa_near( $inventory['days_remaining'], 20 ) && vpa_near( $inventory['reorder_in'], 7 ), '100 units at 5/day: stock-out in 20 days, reorder in 7' );
    vpa_check( vpa_near( Model::inventory( 40, 5, 13 )['reorder_in'], -5 ), '40 units at 5/day: reorder is 5 days overdue' );
    vpa_check( null === Model::inventory( 100, 0, 13 )['days_remaining'], 'Zero demand produces no fabricated stock-out date' );
    vpa_check( null === Model::inventory( null, 5, 13 )['reorder_in'], 'Untracked stock has no inventory projection' );
    vpa_check( 0.0 === Model::inventory( -10, 5, 13 )['days_remaining'], 'Negative stock is already depleted' );
    vpa_check( 0.0 === Model::fit( array() )['rate'] && 0.0 === Model::fit( array_fill( 0, 90, 0 ) )['rate'], 'Empty and zero-sale history is handled' );
    vpa_check( vpa_near( Model::fit( array( 0, 0, 0, 20 ) )['rate'], 4 ), 'Zero-sale days are included in the fitted function' );

    $constant = vpa_product( 'Constant Demand', 100 );
    $bulk = vpa_product( 'Bulk Purchase', 100 );
    $refund_product = vpa_product( 'Refunded Units', 100 );
    $zero = vpa_product( 'No Sales', 45 );
    $untracked = vpa_product( 'Untracked', null );
    $low = vpa_product( 'Low Stock', 2 );
    $out = vpa_product( 'Out of Stock', 0 );
    $parent = new WC_Product_Variable();
    $parent->set_name( 'Test Shared Inventory' ); $parent->set_status( 'publish' ); $parent->set_manage_stock( true ); $parent->set_stock_quantity( 100 ); $parent->save();
    $ids = get_option( 'vpa_test_products' ); $ids[] = $parent->get_id(); update_option( 'vpa_test_products', $ids, false );
    $variations = array();
    foreach ( array( 'Small', 'Large' ) as $size ) {
        $variation = new WC_Product_Variation(); $variation->set_parent_id( $parent->get_id() ); $variation->set_regular_price( 20 );
        $variation->set_attributes( array( 'size' => $size ) ); $variation->set_status( 'publish' ); $variation->set_manage_stock( false ); $variation->save();
        $variations[] = $variation; $ids[] = $variation->get_id();
    }
    update_option( 'vpa_test_products', $ids, false );
    for ( $i = 0; $i < 30; $i++ ) { vpa_order( array( array( $constant, 5 ), array( $variations[0], 3 ), array( $variations[1], 2 ) ), $i ); }
    $bulk_order = vpa_order( array( array( $bulk, 60 ) ) );
    vpa_order( array( array( $bulk, 10 ) ), 110 );
    vpa_order( array( array( $bulk, 10 ) ), 400 );
    vpa_order( array( array( $bulk, 1000 ) ), 0, 'cancelled' );
    vpa_order( array( array( $bulk, 1000 ) ), 0, 'pending' );
    $processing = vpa_order( array( array( $untracked, 3 ) ), 1, 'processing' );
    $refund_order = vpa_order( array( array( $refund_product, 7 ) ) );
    $item_id = array_key_first( $refund_order->get_items() );
    $refund = wc_create_refund( array( 'order_id' => $refund_order->get_id(), 'amount' => 20, 'line_items' => array( $item_id => array( 'qty' => 2, 'refund_total' => 20 ) ), 'refund_payment' => false, 'restock_items' => false ) );
    vpa_check( ! is_wp_error( $refund ), 'A real WooCommerce partial refund can be created' );
    for ( $i = 0; $i < 16; $i++ ) { vpa_product( 'Catalog ' . sprintf( '%02d', $i ), 20 ); }

    Repository::restart();
    vpa_check( ! Repository::state()['complete'], 'A new indexing pass starts incomplete' );
    vpa_check( 0 === Alerts::run()['sent'], 'Emails are disabled by default' );
    do { $sync = Repository::import_batch(); } while ( ! $sync['complete'] );
    vpa_check( $sync['processed'] >= 37, 'Historical orders are imported in bounded batches' );
    vpa_check( vpa_near( vpa_row( $constant->get_id() )['units'], 150 ), 'Product ranking counts units, not order count' );
    vpa_check( vpa_near( vpa_row( $bulk->get_id() )['units'], 60 ), 'Cancelled and pending orders do not count' );
    vpa_check( vpa_near( vpa_row( $bulk->get_id(), '180' )['units'], 70 ) && vpa_near( vpa_row( $bulk->get_id(), '365' )['units'], 70 ) && vpa_near( vpa_row( $bulk->get_id(), 'all' )['units'], 80 ), '30 / 180 / 365 / all-time windows include the correct history' );
    vpa_check( vpa_near( vpa_row( $refund_product->get_id() )['units'], 5 ), 'Refunded quantities are subtracted exactly once' );
    vpa_check( vpa_near( vpa_row( $untracked->get_id() )['units'], 3 ), 'Processing orders count as sales' );
    vpa_check( vpa_near( vpa_row( $constant->get_id() )['rate'], 5 ) && vpa_near( vpa_row( $constant->get_id() )['days_remaining'], 20 ), 'Database regression agrees with exact linear model' );
    $variant = vpa_row( $variations[0]->get_id() );
    vpa_check( $variant['shared_stock'] && vpa_near( $variant['rate'], 5 ) && vpa_near( $variant['days_remaining'], 20 ), 'Variations sharing parent inventory use combined demand' );
    vpa_check( vpa_row( $zero->get_id() )['status'] === 'no-demand' && vpa_row( $untracked->get_id() )['status'] === 'untracked', 'No-sales and untracked products have explicit states' );
    $report = Reports::report( '30' );
    vpa_check( $report['rows'][0]['id'] === $constant->get_id() && $report['rows'][0]['rank'] === 1, 'Products sort by highest quantity with numbered ranks' );
    vpa_check( count( $report['rows'] ) === 20 && Reports::report( '30', '', 2 )['rows'][0]['rank'] === 21, 'Pagination retains global ranks' );
    vpa_check( Reports::report( '30', 'Bulk Purchase' )['rows'][0]['rank'] === vpa_row( $bulk->get_id() )['rank'], 'Search preserves rank across the full catalog' );
    vpa_check( Reports::report( '30', 'nothing matches here' )['total'] === 0, 'Empty searches return an empty result' );
    $detail = Reports::detail( $constant->get_id(), '30' );
    vpa_check( count( $detail['history'] ) === 31 && vpa_near( end( $detail['history'] )['cumulative'], 150 ), 'Graph data includes a zero anchor and all daily history' );
    vpa_check( $detail['revision'] === Reports::report( '30' )['revision'], 'List pages and graph responses identify the same data revision' );
    delete_transient( 'vpa_detail_' . md5( VPA_VERSION . '|' . $constant->get_id() . '|30' ) );
    $history_scans = 0;
    $count_scans = static function ( $sql ) use ( &$history_scans ) {
        if ( false !== strpos( $sql, 'ORDER BY recorded_at, id LIMIT 1000' ) ) { $history_scans++; }
        return $sql;
    };
    add_filter( 'query', $count_scans );
    Reports::detail( $constant->get_id(), '30' );
    $cold_scans = $history_scans;
    $history_scans = 0;
    $warm_detail = Reports::detail( $constant->get_id(), '30' );
    remove_filter( 'query', $count_scans );
    vpa_check( $cold_scans > 0 && 0 === $history_scans, 'A repeated graph read reuses the server cache without scanning stock history again' );
    vpa_check( end( $warm_detail['stock_history']['recorded'] )['time'] === $warm_detail['stock_history']['today'] && abs( $warm_detail['stock_history']['today'] - time() ) <= 1, 'Cached history keeps Today anchored to the current read time' );
    $sparse = Reports::detail( $bulk->get_id(), 'all' );
    vpa_check( count( $sparse['history'] ) <= 102 && vpa_near( end( $sparse['history'] )['cumulative'], 80 ) && vpa_near( $sparse['model']['rate'], vpa_row( $bulk->get_id(), 'all' )['rate'] ), 'All-time chart buckets preserve totals and exact regression' );
    $stock_history = $detail['stock_history'];
    vpa_check( count( $stock_history['reconstructed'] ) > 1 && vpa_near( $stock_history['reconstructed'][0]['stock'], 245 ), 'Pre-recording stock history is explicitly reconstructed from earlier daily orders' );
    vpa_check( vpa_near( end( $stock_history['recorded'] )['stock'], 100 ) && end( $stock_history['recorded'] )['time'] === $stock_history['today'], 'The measured today point equals current inventory' );
    $shared_history = Reports::detail( $variations[0]->get_id(), '30' )['stock_history'];
    vpa_check( vpa_near( $shared_history['reconstructed'][0]['stock'], 245 ), 'Shared-inventory history uses consumption from both variations' );
    vpa_check( Reports::detail( $untracked->get_id(), '30' )['stock_history']['mode'] === 'untracked', 'Untracked quantities never produce fabricated stock history' );
    $small_page = Reports::report( '30', '', 2, 10 );
    vpa_check( count( $small_page['rows'] ) === 10 && $small_page['rows'][0]['rank'] === 11 && $small_page['per_page'] === 10, 'The product sidebar supports ten products per page with global ranks' );

    $tracked = vpa_product( 'Recorded Inventory', 50 );
    global $wpdb;
    $wpdb->delete( StockHistory::table(), array( 'stock_owner_id' => $tracked->get_id() ), array( '%d' ) );
    $base = new DateTimeImmutable( 'today', wp_timezone() );
    $quantity = 200;
    StockHistory::store( $tracked->get_id(), $quantity, $base->modify( '-35 days' )->setTime( 9, 0 )->getTimestamp(), 'fixture' );
    for ( $i = 1; $i <= 35; $i++ ) {
        $at = $i === 35 ? time() - 1 : $base->modify( '-' . ( 35 - $i ) . ' days' )->setTime( 10, 0 )->getTimestamp();
        $quantity -= 5;
        StockHistory::store( $tracked->get_id(), $quantity, $at, 'fixture' );
        if ( 20 === $i ) { $quantity += 25; StockHistory::store( $tracked->get_id(), $quantity, $at + HOUR_IN_SECONDS, 'fixture' ); }
    }
    $tracked_detail = Reports::detail( $tracked->get_id(), '30' );
    vpa_check( $tracked_detail['stock_history']['mode'] === 'recorded' && ! $tracked_detail['stock_history']['reconstructed'], 'Complete stock recording is shown as measured history without reconstruction' );
    vpa_check( $tracked_detail['product']['rate_source'] === 'stock_changes' && vpa_near( $tracked_detail['product']['rate'], 5 ), 'The inventory model uses actual five-unit daily decreases and excludes restocking' );
    vpa_check( vpa_near( $tracked_detail['product']['days_remaining'], 10 ), 'Recorded inventory depletion projects current stock coverage' );
    $partial = Reports::detail( $tracked->get_id(), '365' )['product'];
    vpa_check( $partial['rate_source'] === 'stock_changes' && $partial['rate_history_days'] === 36 && $partial['rate'] > 4.9 && $partial['rate'] < 5.1, 'Available inventory history still drives depletion when older order data does not exist' );
    $levels = array_column( $tracked_detail['stock_history']['recorded'], 'stock' );
    vpa_check( in_array( 100.0, $levels, true ) && in_array( 125.0, $levels, true ), 'History sampling preserves both sides of a restock jump' );
    wc_update_product_stock( $tracked, 7, 'decrease' );
    $tracked = wc_get_product( $tracked->get_id() );
    vpa_check( vpa_near( end( Reports::detail( $tracked->get_id(), '30' )['stock_history']['recorded'] )['stock'], 43 ), 'WooCommerce stock-decrease hooks record the new inventory quantity' );
    $tracked->set_stock_quantity( 80 ); $tracked->save();
    $changed = Reports::detail( $tracked->get_id(), '30' )['stock_history'];
    vpa_check( vpa_near( end( $changed['recorded'] )['stock'], 80 ), 'Manual stock adjustments and replenishments update recorded history' );
    $count_before = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . StockHistory::table() . ' WHERE stock_owner_id = %d', $tracked->get_id() ) );
    StockHistory::capture( $tracked ); StockHistory::capture( $tracked );
    vpa_check( $count_before === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . StockHistory::table() . ' WHERE stock_owner_id = %d', $tracked->get_id() ) ), 'Unchanged quantities do not create duplicate observations' );
    $recorded = StockHistory::read( $tracked->get_id(), $base->modify( '-30 days' )->getTimestamp(), time() );
    vpa_check( count( $recorded ) <= 405, 'Stock history is bounded for plotting while preserving time-bucket extrema' );
    Repository::sync_order( $bulk_order->get_id() ); Repository::sync_order( $bulk_order->get_id() );
    vpa_check( vpa_near( vpa_row( $bulk->get_id() )['units'], 60 ), 'Repeated imports are idempotent' );
    $bulk_order->set_status( 'cancelled' ); $bulk_order->save();
    do_action( 'vpa_sync_order', $bulk_order->get_id() );
    vpa_check( vpa_near( vpa_row( $bulk->get_id() )['units'], 0 ), 'Order status updates remove cancelled sales from the index' );
    $cancelled_detail = Reports::detail( $bulk->get_id(), 'all' );
    vpa_check( $cancelled_detail['revision'] !== $sparse['revision'] && vpa_near( end( $cancelled_detail['history'] )['cumulative'], 20 ), 'Changed orders invalidate previously cached product history' );
    $bulk_order->set_status( 'completed' ); $bulk_order->save(); do_action( 'vpa_sync_order', $bulk_order->get_id() );
    $processing->delete( true );
    vpa_check( vpa_near( vpa_row( $untracked->get_id() )['units'], 0 ), 'Deleted orders are removed from the sales index' );

    do_action( 'rest_api_init' );
    wp_set_current_user( 0 );
    vpa_check( vpa_request( 'GET', 'report' )->get_status() === 401, 'Unauthenticated requests cannot read sales data' );
    wp_set_current_user( 1 );
    vpa_check( vpa_request( 'GET', 'report' )->get_status() === 200, 'Store administrators can access the REST report' );
    vpa_check( vpa_request( 'GET', 'sync' )->get_data()['revision'] === Reports::revision(), 'The lightweight freshness endpoint exposes the current data revision' );
    $bad_period = new WP_REST_Request( 'GET', '/veryshop/v1/report' ); $bad_period->set_param( 'period', 'forever' );
    vpa_check( rest_do_request( $bad_period )->get_status() === 400, 'Unknown periods are rejected' );
    $bad_size = new WP_REST_Request( 'GET', '/veryshop/v1/report' ); $bad_size->set_param( 'per_page', 51 );
    vpa_check( rest_do_request( $bad_size )->get_status() === 400, 'Product page sizes are bounded and validated' );
    vpa_check( vpa_request( 'POST', 'settings', array( 'reorder_days' => -1 ) )->get_status() === 400, 'Negative reorder-day values are rejected' );
    vpa_check( vpa_request( 'POST', 'settings', array( 'recipients' => 'not an email' ) )->get_status() === 400, 'Invalid recipient addresses are rejected' );
    vpa_check( vpa_request( 'POST', 'settings', array( 'email_frequency_days' => 0 ) )->get_status() === 400, 'Email frequency must be at least one day' );
    vpa_check( vpa_request( 'POST', 'products/' . $constant->get_id() . '/planning', array( 'arrival' => '2026-02-31' ) )->get_status() === 400, 'Impossible delivery dates are rejected' );
    foreach ( array( 1.5, 3651, null, 'many', true ) as $invalid_days ) {
        vpa_check( vpa_request( 'POST', 'settings', array( 'reorder_days' => $invalid_days ) )->get_status() === 400, 'Invalid reorder-day value is rejected: ' . wp_json_encode( $invalid_days ) );
    }
    vpa_check( Reports::settings()['reorder_days'] === 21, 'Rejected input leaves the saved reorder trigger unchanged' );
    vpa_request( 'POST', 'settings', array( 'reorder_days' => 10 ) );
    $ten_day_revision = Reports::revision();
    $detail_request = new WP_REST_Request( 'GET', '/veryshop/v1/products/' . $constant->get_id() );
    $detail_request->set_param( 'period', '30' );
    $ten_days = rest_do_request( $detail_request )->get_data()['product'];
    vpa_check( vpa_near( $ten_days['reorder_point'], 50 ) && vpa_near( $ten_days['reorder_in'], 10 ) && $ten_days['status'] === 'healthy', 'Saving N=10 updates the product API to 50 units and reorder in 10 days' );
    vpa_check( ! array_filter( Alerts::candidates(), static function ( $row ) use ( $constant ) { return $row['id'] === $constant->get_id(); } ), 'Products with more than N days of stock do not trigger reorder email alerts' );
    $constant->update_meta_data( '_vpa_lead_days', 0 ); $constant->update_meta_data( '_vpa_buffer_days', 0 ); $constant->save();
    vpa_request( 'POST', 'settings', array( 'reorder_days' => 20, 'alert_period' => '30' ) );
    $twenty_days = vpa_row( $constant->get_id() );
    vpa_check( vpa_near( $twenty_days['reorder_point'], 100 ) && vpa_near( $twenty_days['reorder_in'], 0 ) && $twenty_days['status'] === 'overdue', 'Reorder is due exactly N days before depletion; old per-product settings cannot override N' );
    $changed_detail = Reports::detail( $constant->get_id(), '30' );
    vpa_check( $changed_detail['revision'] !== $ten_day_revision && vpa_near( $changed_detail['product']['reorder_point'], 100 ), 'Saving the reorder trigger invalidates a cached graph as well as list rows' );
    vpa_check( vpa_near( vpa_row( $variations[1]->get_id() )['reorder_point'], 100 ), 'The same global N applies to shared-stock variations' );
    $shared_before = Reports::detail( $variations[0]->get_id(), '30' );
    Reports::detail( $variations[1]->get_id(), '30' );
    $parent->set_stock_quantity( 90 ); $parent->save();
    $shared_after = Reports::detail( $variations[0]->get_id(), '30' );
    vpa_check( $shared_before['revision'] !== $shared_after['revision'] && vpa_near( $shared_after['product']['stock'], 90 ) && vpa_near( Reports::detail( $variations[1]->get_id(), '30' )['product']['stock'], 90 ), 'A parent stock change invalidates cached graphs for every shared-stock variation' );
    $parent->set_stock_quantity( 100 ); $parent->save();
    vpa_check( count( array_filter( Alerts::candidates(), static function ( $row ) use ( $constant ) { return $row['id'] === $constant->get_id() && $row['reason'] === 'overdue'; } ) ) === 1, 'Crossing the N-day trigger makes the product eligible for an email' );
    vpa_check( str_contains( Alerts::preview()['message'], 'Reorder trigger: 20 days before projected stock-out.' ) && str_contains( Alerts::preview()['message'], 'Reorder level: 100 units (20 days of stock)' ), 'Email preview uses the saved N-day trigger and modeled quantity' );
    vpa_request( 'POST', 'settings', array( 'reorder_days' => 0 ) );
    $zero_days = vpa_row( $constant->get_id() );
    vpa_check( Reports::settings()['reorder_days'] === 0 && vpa_near( $zero_days['reorder_point'], 0 ) && vpa_near( $zero_days['reorder_in'], 20 ) && vpa_row( $out->get_id() )['status'] === 'out', 'N=0 is retained, reorders at depletion, and preserves out-of-stock alerts' );
    vpa_check( null === vpa_row( $zero->get_id() )['reorder_in'] && null === vpa_row( $untracked->get_id() )['reorder_in'], 'Changing N never fabricates projections for zero usage or untracked stock' );
    vpa_check( ! Reports::settings()['email_enabled'] && Reports::settings()['email_frequency_days'] === 7, 'Saving only N preserves unrelated email preferences' );
    vpa_request( 'POST', 'settings', array( 'reorder_days' => 21 ) );
    vpa_check( vpa_request( 'GET', 'products/999999999' )->get_status() === 404, 'Missing products return a proper 404' );

    vpa_request( 'POST', 'settings', array( 'alert_period' => '30' ) );
    $candidates = Alerts::candidates();
    $shared = array_filter( $candidates, static function ( $row ) use ( $parent ) { return $row['stock_owner_id'] === $parent->get_id(); } );
    vpa_check( count( $shared ) === 1, 'Shared inventory creates one alert, not one per variation' );
    $arrival = wp_date( 'Y-m-d', time() + 7 * DAY_IN_SECONDS );
    vpa_request( 'POST', 'products/' . $variations[0]->get_id() . '/planning', array( 'arrival' => $arrival ) );
    $shared = array_filter( Alerts::candidates(), static function ( $row ) use ( $parent ) { return $row['stock_owner_id'] === $parent->get_id(); } );
    vpa_check( ! $shared && vpa_row( $variations[1]->get_id() )['status'] === 'ordered', 'An expected replenishment pauses overdue alerts for the shared stock pool' );
    vpa_check( ! array_filter( Alerts::candidates(), static function ( $row ) use ( $low ) { return $row['id'] === $low->get_id(); } ), 'A WooCommerce low-stock flag alone is excluded when no modeled reorder point is reached' );
    foreach ( array( -1, 1.5, 3651, null, true ) as $invalid_frequency ) {
        vpa_check( vpa_request( 'POST', 'settings', array( 'email_frequency_days' => $invalid_frequency ) )->get_status() === 400, 'Invalid email frequency is rejected: ' . wp_json_encode( $invalid_frequency ) );
    }
    vpa_request( 'POST', 'settings', array( 'email_enabled' => true, 'recipients' => 'admin@example.test', 'alert_period' => '30', 'email_frequency_days' => 7 ) );
    $anchor = (int) get_option( 'vpa_email_anchor' );
    $next = Alerts::next_due();
    vpa_check( $anchor > 0 && $next === $anchor + 7 * DAY_IN_SECONDS && vpa_request( 'GET', 'settings' )->get_data()['next_email_at'] === $next, 'Enabling stock emails starts a saved seven-day interval and returns its next date' );
    Plugin::init();
    vpa_check( Alerts::next_due() === $next, 'Opening the dashboard again does not restart the email interval' );
    as_schedule_recurring_action( time() + HOUR_IN_SECONDS, DAY_IN_SECONDS, 'vpa_daily_alerts', array(), Plugin::GROUP, true );
    delete_option( 'vpa_email_schedule_migrated' ); Plugin::init();
    vpa_check( ! as_has_scheduled_action( 'vpa_daily_alerts', array(), Plugin::GROUP ) && as_has_scheduled_action( 'vpa_email_checks', array(), Plugin::GROUP ), 'Upgrade removes the old daily-email job and registers the interval-gated checker' );
    Repository::restart();
    vpa_check( 0 === Alerts::run( true )['sent'], 'Even manual email checks wait for complete order indexing' );
    do { $sync = Repository::import_batch(); } while ( ! $sync['complete'] );
    $before = count( get_option( 'veryshop_development_mail', array() ) );
    vpa_check( 0 === Alerts::run()['sent'] && count( get_option( 'veryshop_development_mail' ) ) === $before, 'Automatic emails are not sent before the first seven-day interval' );
    update_option( 'vpa_email_anchor', time() - 7 * DAY_IN_SECONDS + 60, false );
    vpa_check( 0 === Alerts::run()['sent'], 'A digest is still blocked just before the selected interval elapses' );
    update_option( 'vpa_email_anchor', time() - 7 * DAY_IN_SECONDS, false );
    $due_count = count( Alerts::candidates() );
    $sent = Alerts::run();
    vpa_check( $due_count >= 2 && $sent['sent'] === $due_count && count( get_option( 'veryshop_development_mail' ) ) === $before + 1, 'At seven days one captured digest includes all products currently due for reorder' );
    vpa_check( str_contains( Alerts::preview()['message'], 'Email frequency: every 7 days.' ) && str_contains( Alerts::preview()['message'], 'Reorder date:' ) && ! str_contains( Alerts::preview()['message'], $low->get_name() ), 'The digest reports saved frequency and reorder dates and omits unrelated low-stock flags' );
    $low->set_stock_quantity( 0 ); $low->save();
    vpa_check( 0 === Alerts::run()['sent'] && count( get_option( 'veryshop_development_mail' ) ) === $before + 1, 'Newly depleted products do not cause extra automatic mail between chosen intervals' );
    update_option( 'vpa_email_anchor', time() - 4 * DAY_IN_SECONDS, false );
    $four_days_ago = (int) get_option( 'vpa_email_anchor' );
    vpa_request( 'POST', 'settings', array( 'email_frequency_days' => 3 ) );
    $saved = vpa_request( 'GET', 'settings' )->get_data();
    vpa_check( $saved['settings']['email_frequency_days'] === 3 && $saved['next_email_at'] === $four_days_ago + 3 * DAY_IN_SECONDS && $saved['settings']['reorder_days'] === 21, 'Changing email frequency persists it, preserves the reorder variable, and recalculates the next due date' );
    $due_count = count( Alerts::candidates() );
    vpa_check( Alerts::run()['sent'] === $due_count && $due_count >= 3, 'Shortening the interval makes an already-due full digest eligible immediately' );
    vpa_check( Alerts::next_due() >= time() + 3 * DAY_IN_SECONDS - 2, 'A successful digest starts the next saved interval' );
    $manual = vpa_request( 'POST', 'alerts/run', array( 'force' => true ) )->get_data();
    vpa_check( $manual['sent'] === count( Alerts::candidates() ), 'The explicit Send email now action can send immediately' );
    update_option( 'vpa_email_anchor', time() - 3 * DAY_IN_SECONDS, false );
    $retry_anchor = (int) get_option( 'vpa_email_anchor' );
    $fail_mail = static function () { return false; };
    add_filter( 'pre_wp_mail', $fail_mail, 99 );
    $failed = Alerts::run();
    vpa_check( ! empty( $failed['failed'] ) && (int) get_option( 'vpa_email_anchor' ) === $retry_anchor, 'A failed mail handoff leaves the digest due for retry' );
    remove_filter( 'pre_wp_mail', $fail_mail, 99 );
    vpa_check( Alerts::run()['sent'] === count( Alerts::candidates() ), 'The failed digest can be retried without waiting another interval' );
    $low->set_stock_quantity( 20 ); $low->save();
    $out->set_stock_quantity( 50 ); $out->save();
    vpa_request( 'POST', 'settings', array( 'reorder_days' => 0 ) );
    vpa_check( ! Alerts::candidates(), 'Recovered inventory and a zero-day trigger produce no unnecessary reorder email' );
    update_option( 'vpa_email_anchor', time() - 3 * DAY_IN_SECONDS, false );
    $before_empty = count( get_option( 'veryshop_development_mail' ) );
    vpa_check( 0 === Alerts::run()['sent'] && count( get_option( 'veryshop_development_mail' ) ) === $before_empty && Alerts::next_due() > time(), 'An empty due cycle sends no email and waits until the next interval' );
    vpa_request( 'POST', 'settings', array( 'reorder_days' => 21, 'email_enabled' => false ) );
    vpa_check( null === Alerts::next_due() && ! as_has_scheduled_action( 'vpa_email_checks', array(), Plugin::GROUP ) && 0 === Alerts::run( true )['sent'], 'Disabling stock emails removes the checker and prevents manual sends' );
    vpa_request( 'POST', 'settings', array( 'email_enabled' => true, 'email_frequency_days' => 7 ) );
    vpa_check( Reports::settings()['reorder_days'] === 21 && Alerts::next_due() >= time() + 7 * DAY_IN_SECONDS - 2, 'Reenabling starts a fresh seven-day interval and retains the saved reorder variable' );
    vpa_check( as_has_scheduled_action( 'vpa_email_checks', array(), Plugin::GROUP ), 'A recurring email checker is registered with Action Scheduler' );
    Plugin::deactivate();
    vpa_check( ! as_has_scheduled_action( 'vpa_email_checks', array(), Plugin::GROUP ), 'Deactivation removes recurring email jobs' );
    Plugin::init();
    require '/internal/vpa-dev/order-history.php';
    require '/internal/vpa-dev/restock.php';
    echo "\nALL TESTS PASSED ($checks checks)\n";
    echo 'Runtime: WordPress ' . get_bloginfo( 'version' ) . ' / WooCommerce ' . WC_VERSION . ' / PHP ' . PHP_VERSION . "\n";
    echo 'Order storage: ' . ( \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ? 'HPOS' : 'legacy' ) . "\n";
} catch (Throwable $error) {
    echo "\n" . $error->getMessage() . "\n" . $error->getTraceAsString() . "\n";
    exit( 1 );
}
