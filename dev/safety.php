<?php
use VeryShop\ProductAnalysis\Plugin;
use VeryShop\ProductAnalysis\Repository;
use VeryShop\ProductAnalysis\StockHistory;
use VeryShop\ProductAnalysis\Reports;
use VeryShop\ProductAnalysis\Alerts;

require_once ABSPATH . 'wp-admin/includes/plugin.php';
function vpas_check( $ok, $label ) { if ( ! $ok ) { throw new RuntimeException( $label ); } echo "PASS: $label\n"; }
function vpas_tables() {
    global $wpdb;
    $tables = array( $wpdb->posts, $wpdb->postmeta, $wpdb->users, $wpdb->usermeta, $wpdb->comments, $wpdb->commentmeta, $wpdb->terms, $wpdb->termmeta, $wpdb->term_taxonomy, $wpdb->term_relationships );
    foreach ( array( 'woocommerce_order_items', 'woocommerce_order_itemmeta', 'wc_orders', 'wc_order_addresses', 'wc_order_operational_data', 'wc_orders_meta', 'woocommerce_tax_rates', 'woocommerce_tax_rate_locations', 'woocommerce_attribute_taxonomies' ) as $name ) { $tables[] = $wpdb->prefix . $name; }
    return array_values( array_filter( $tables, static function ( $table ) use ( $wpdb ) { return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ); } ) );
}
function vpas_hash_rows( $rows ) { $values = array_map( 'wp_json_encode', $rows ); sort( $values, SORT_STRING ); return hash( 'sha256', implode( "\n", $values ) ); }
function vpas_snapshot() {
    global $wpdb;
    $result = array();
    foreach ( vpas_tables() as $table ) { $result[ $table ] = vpas_hash_rows( $wpdb->get_results( "SELECT * FROM $table", ARRAY_A ) ); }
    $result['store_settings'] = vpas_hash_rows( $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'woocommerce_%' OR option_name IN ('permalink_structure','template','stylesheet','blogname','blogdescription','home','siteurl','users_can_register','{$wpdb->prefix}user_roles')", ARRAY_A ) );
    foreach ( array( ABSPATH . 'wp-config.php', ABSPATH . '.htaccess' ) as $file ) { $result[ $file ] = is_file( $file ) ? hash_file( 'sha256', $file ) : 'absent'; }
    $theme = get_stylesheet_directory();
    if ( is_dir( $theme ) ) {
        foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $theme, FilesystemIterator::SKIP_DOTS ) ) as $file ) {
            if ( $file->isFile() ) { $result[ $file->getPathname() ] = hash_file( 'sha256', $file->getPathname() ); }
        }
    }
    ksort( $result ); return $result;
}
function vpas_request( $method, $path, $body = null ) {
    $request = new WP_REST_Request( $method, '/veryshop/v1/' . $path );
    if ( null !== $body ) { $request->set_header( 'content-type', 'application/json' ); $request->set_body( wp_json_encode( $body ) ); }
    return rest_do_request( $request );
}
try {
    add_filter( 'action_scheduler_allow_async_request_runner', '__return_false' );
    $plugin = 'veryshop-product-analysis/veryshop-product-analysis.php';
    if ( 'disable' === VPA_SAFETY_PHASE ) {
        deactivate_plugins( $plugin );
        echo "DONE\n";
    } elseif ( 'seed' === VPA_SAFETY_PHASE ) {
        global $wpdb;
        vpas_check( ! class_exists( Plugin::class ), 'Pre-existing store fixtures are created with the analysis plugin inactive' );
        update_option( 'woocommerce_custom_orders_table_enabled', VPA_SAFETY_MODE );
        update_option( 'woocommerce_custom_orders_table_data_sync_enabled', 'no' );
        foreach ( array( 'veryshop_sales', 'veryshop_stock_history' ) as $name ) { $wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . $name ); }
        $keys = array_filter( $wpdb->get_col( "SELECT option_name FROM {$wpdb->options}" ), static function ( $key ) { return 0 === strpos( $key, 'vpa_' ) || 0 === strpos( $key, '_transient_vpa_' ) || 0 === strpos( $key, '_transient_timeout_vpa_' ); } );
        foreach ( $keys as $key ) { delete_option( $key ); }
        $product = new WC_Product_Simple(); $product->set_name( 'Safety existing product' ); $product->set_status( 'publish' ); $product->set_regular_price( 12 ); $product->set_manage_stock( true ); $product->set_stock_quantity( 100 ); $product->save();
        $parent = new WC_Product_Variable(); $parent->set_name( 'Safety shared stock' ); $parent->set_status( 'publish' ); $parent->set_manage_stock( true ); $parent->set_stock_quantity( 20 ); $parent->save();
        $variant = new WC_Product_Variation(); $variant->set_parent_id( $parent->get_id() ); $variant->set_regular_price( 15 ); $variant->set_status( 'publish' ); $variant->set_manage_stock( false ); $variant->save();
        $customer = wp_create_user( 'safety_' . strtolower( wp_generate_password( 10, false ) ), wp_generate_password( 24 ), 'fixture-' . $product->get_id() . '@example.test' );
        wp_update_user( array( 'ID' => $customer, 'role' => 'customer', 'display_name' => 'Existing safety customer' ) );
        $order = wc_create_order( array( 'customer_id' => $customer ) ); $order->add_product( $product, 2 ); $order->calculate_totals(); $order->set_status( 'completed' ); $order->save();
        $coupon = new WC_Coupon(); $coupon->set_code( 'safety-' . $product->get_id() ); $coupon->set_discount_type( 'fixed_cart' ); $coupon->set_amount( 3 ); $coupon->save();
        foreach ( array( 'shop' => '', 'cart' => '[woocommerce_cart]', 'checkout' => '[woocommerce_checkout]' ) as $page => $content ) {
            $id = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Safety ' . $page, 'post_content' => $content ) );
            update_option( 'woocommerce_' . $page . '_page_id', $id );
        }
        as_schedule_single_action( time() + DAY_IN_SECONDS, 'safety_unrelated_job', array(), 'safety-unrelated' );
        wp_schedule_single_event( time() + DAY_IN_SECONDS, 'safety_unrelated_cron' );
        $expires = time() + HOUR_IN_SECONDS; $token = WP_Session_Tokens::get_instance( 1 )->create( $expires );
        $cookie = wp_generate_auth_cookie( 1, $expires, 'logged_in', $token ); $_COOKIE[ LOGGED_IN_COOKIE ] = $cookie; wp_set_current_user( 1 );
        update_option( 'vpa_safety_auth', array( 'cookie' => LOGGED_IN_COOKIE . '=' . $cookie, 'nonce' => wp_create_nonce( 'wp_rest' ), 'url' => wp_make_link_relative( rest_url( 'veryshop/v1/settings' ) ) ), false );
        update_option( 'vpa_safety_ids', array( 'product' => $product->get_id(), 'parent' => $parent->get_id(), 'variant' => $variant->get_id(), 'order' => $order->get_id(), 'customer' => $customer ), false );
        update_option( 'vpa_safety_before', vpas_snapshot(), false );
        echo "SEEDED\n";
    } elseif ( 'activate' === VPA_SAFETY_PHASE ) {
        // Capture immediately before activation, after normal inactive-site requests have settled.
        update_option( 'vpa_safety_before', vpas_snapshot(), false );
        $result = activate_plugin( $plugin );
        vpas_check( ! is_wp_error( $result ) && Repository::ready(), 'Real WordPress activation creates and verifies only the plugin analytics schema' );
        $before = get_option( 'vpa_safety_before' ); $after = vpas_snapshot();
        $changed = array_keys( array_diff_assoc( $before, $after ) );
        vpas_check( ! $changed, 'Activation preserves all existing store records and protected files' . ( $changed ? ': changed ' . implode( ', ', $changed ) : '' ) );
    } elseif ( 'audit' === VPA_SAFETY_PHASE ) {
        global $wpdb;
        $protected = vpas_tables(); $writes = array(); $transactions = array();
        $watch = static function ( $sql ) use ( &$writes, &$transactions, $protected ) {
            if ( preg_match( '/^\\s*(START\\s+TRANSACTION|BEGIN|COMMIT|ROLLBACK)\\b/i', $sql ) ) { $transactions[] = $sql; }
            if ( preg_match( '/^\\s*(?:INSERT(?:\\s+IGNORE)?\\s+INTO|REPLACE(?:\\s+INTO)?|UPDATE|DELETE\\s+FROM|ALTER\\s+TABLE|DROP\\s+TABLE)\\s+[`"]?([a-zA-Z0-9_]+)/i', $sql, $match ) && in_array( $match[1], $protected, true ) ) { $writes[] = $match[1]; }
            return $sql;
        };
        add_filter( 'query', $watch );
        wp_set_current_user( 1 );
        vpas_check( is_plugin_active( $plugin ) && has_action( 'init', array( Plugin::class, 'init' ) ), 'The audit exercises a fresh request with the installed plugin hooks active' );
        $ids = get_option( 'vpa_safety_ids' ); Plugin::init();
        for ( $i = 0; $i < 20 && ! Repository::state()['complete']; $i++ ) { Plugin::background_import(); }
        vpas_check( Repository::state()['complete'], 'Existing orders import successfully without saving those orders' );
        vpas_check( 2.0 === (float) ( Repository::totals( '2000-01-01', wp_date( 'Y-m-d' ) )[ $ids['product'] ]['units'] ?? 0 ), 'The newly imported order quantities match the pre-existing source order' );
        for ( $i = 0; $i < 20 && ! StockHistory::baseline_batch(); $i++ ) {}
        foreach ( Reports::PERIODS as $period ) { Reports::report( $period ); Reports::detail( $ids['product'], $period ); Reports::detail( $ids['variant'], $period ); }
        vpas_check( count( Reports::report( '90' )['rows'] ) >= 2, 'Reports and shared-stock graphs read the pre-existing catalog' );
        vpas_check( ! Reports::settings()['email_enabled'] && 0 === Alerts::run( true )['sent'], 'A fresh installation cannot send stock emails before explicit enablement' );
        vpas_request( 'POST', 'settings', array( 'reorder_days' => 45, 'email_frequency_days' => 7 ) );
        $save_events = 0; $save_watch = static function () use ( &$save_events ) { $save_events++; };
        add_action( 'woocommerce_before_product_object_save', $save_watch );
        $planning = vpas_request( 'POST', 'products/' . $ids['variant'] . '/planning', array( 'arrival' => wp_date( 'Y-m-d', time() + WEEK_IN_SECONDS ) ) );
        remove_action( 'woocommerce_before_product_object_save', $save_watch );
        vpas_check( 200 === $planning->get_status() && 0 === $save_events && Reports::planning( wc_get_product( $ids['parent'] ) )['on_order'], 'Restock planning writes plugin-only storage and triggers no WooCommerce product saves' );
        Repository::restart(); do { Repository::import_batch(); } while ( ! Repository::state()['complete'] );
        vpas_check( ! $transactions, 'Import and reports issue no START/COMMIT/ROLLBACK transaction commands' );
        vpas_check( ! $writes, 'Activation, imports, reports, settings, and planning issue zero writes to protected store tables' );
        $before = get_option( 'vpa_safety_before' ); $after = vpas_snapshot(); $changed = array_keys( array_diff_assoc( $before, $after ) );
        vpas_check( ! $changed, 'All existing products, orders, customers, notes, coupons, store settings, config and theme hashes are unchanged' . ( $changed ? ': changed ' . implode( ', ', $changed ) : '' ) );
        wp_set_current_user( $ids['customer'] );
        foreach ( array( array( 'GET', 'report', null ), array( 'GET', 'settings', null ), array( 'POST', 'settings', array( 'reorder_days' => 1 ) ), array( 'POST', 'sync', array( 'restart' => true ) ), array( 'POST', 'alerts/run', array( 'force' => true ) ), array( 'POST', 'products/' . $ids['product'] . '/planning', array( 'arrival' => '' ) ) ) as $request ) {
            vpas_check( 403 === vpas_request( $request[0], $request[1], $request[2] )->get_status(), 'A customer cannot access ' . $request[0] . ' ' . $request[1] );
        }
        wp_set_current_user( 1 ); Plugin::assets( 'index.php' );
        vpas_check( ! wp_script_is( 'vpa-admin', 'enqueued' ) && ! wp_style_is( 'vpa-admin', 'enqueued' ), 'Dashboard scripts and styles are not enqueued on other pages' );
        remove_filter( 'query', $watch );
        $before_order = wc_get_order( $ids['order'] )->get_data();
        $fail_index = static function ( $sql ) { if ( preg_match( '/^REPLACE\\s+INTO.*veryshop_sales/i', $sql ) ) { throw new RuntimeException( 'Simulated analytics outage' ); } return $sql; };
        add_filter( 'query', $fail_index ); Plugin::sync_order( $ids['order'] ); remove_filter( 'query', $fail_index );
        vpas_check( wp_json_encode( $before_order ) === wp_json_encode( wc_get_order( $ids['order'] )->get_data() ), 'An analytics indexing exception is contained and leaves the source order unchanged' );
        $stock = (float) wc_get_product( $ids['product'] )->get_stock_quantity();
        $fail_stock = static function ( $sql ) { if ( preg_match( '/FROM.*veryshop_stock_history/i', $sql ) ) { throw new RuntimeException( 'Simulated observation outage' ); } return $sql; };
        add_filter( 'query', $fail_stock ); $updated = wc_update_product_stock( $ids['product'], 1, 'decrease' ); remove_filter( 'query', $fail_stock );
        vpas_check( (float) $updated === $stock - 1 && (float) wc_get_product( $ids['product'] )->get_stock_quantity() === $stock - 1, 'WooCommerce stock reduction completes exactly once while analytics recording throws' );
        $wpdb->query( 'BEGIN' ); $wpdb->query( "INSERT INTO {$wpdb->options} (option_name,option_value,autoload) VALUES ('vpa_safety_transaction_probe','temporary','no')" );
        Repository::sync_order( $ids['order'], false ); $wpdb->query( 'ROLLBACK' );
        vpas_check( null === $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name='vpa_safety_transaction_probe'" ), 'Indexing preserves the surrounding transaction so unrelated work can still roll back' );
        $normal_settings = get_option( 'vpa_settings' ); update_option( 'vpa_settings', 'corrupted', false );
        vpas_check( ! Reports::settings()['email_enabled'] && 7 === Reports::settings()['email_frequency_days'], 'Malformed plugin settings fall back safely with email disabled' );
        update_option( 'vpa_settings', $normal_settings, false );
        $wpdb->query( 'DROP TABLE ' . StockHistory::table() ); delete_option( 'vpa_db_version' );
        $deny_schema = static function ( $sql ) { return preg_match( '/^(CREATE|ALTER)\\s+TABLE.*veryshop_/i', $sql ) ? 'SELECT 1' : $sql; };
        add_filter( 'query', $deny_schema ); $ready = \VeryShop\ProductAnalysis\Safety::run( 'simulated_schema_failure', array( Repository::class, 'install' ), false ); remove_filter( 'query', $deny_schema );
        vpas_check( ! $ready && ! Repository::ready() && get_option( 'vpa_schema_error' ), 'Denied analytics table creation pauses analysis without marking a broken schema ready' );
        $stock = (float) wc_get_product( $ids['product'] )->get_stock_quantity(); wc_update_product_stock( $ids['product'], 1, 'decrease' );
        vpas_check( (float) wc_get_product( $ids['product'] )->get_stock_quantity() === $stock - 1 && 503 === vpas_request( 'GET', 'report' )->get_status(), 'Store stock operations continue while unavailable analytics endpoints return a controlled 503' );
        $index_before = vpas_hash_rows( $wpdb->get_results( 'SELECT * FROM ' . Repository::table(), ARRAY_A ) );
        delete_option( 'vpa_schema_owner' );
        $ready = Repository::install();
        vpas_check( ! $ready && $index_before === vpas_hash_rows( $wpdb->get_results( 'SELECT * FROM ' . Repository::table(), ARRAY_A ) ), 'Unowned pre-existing analytics table names are refused without altering their contents' );
        update_option( 'vpa_schema_owner', 'veryshop-product-analysis', false ); delete_option( 'vpa_schema_retry_after' );
        vpas_check( Repository::install() && Repository::ready(), 'Analytics can recover after database access is restored' );
        $before_deactivate = vpas_snapshot(); deactivate_plugins( $plugin );
        vpas_check( $before_deactivate === vpas_snapshot(), 'Deactivation leaves existing store data and files unchanged' );
        vpas_check( as_has_scheduled_action( 'safety_unrelated_job', array(), 'safety-unrelated' ) && wp_next_scheduled( 'safety_unrelated_cron' ), 'Deactivation preserves scheduled work owned by other plugins' );
        vpas_check( ! as_has_scheduled_action( 'vpa_import_batch', array(), Plugin::GROUP ) && ! as_has_scheduled_action( 'vpa_stock_baseline', array(), Plugin::GROUP ), 'Deactivation cancels only this plugin scheduled work' );
        delete_option( 'vpa_runtime_error' );
        echo 'Runtime: WP ' . get_bloginfo( 'version' ) . ' / WooCommerce ' . WC_VERSION . ' / PHP ' . PHP_VERSION . "\nSAFETY AUDIT PASSED\n";
    } elseif ( 'missing_dependency' === VPA_SAFETY_PHASE ) {
        $result = activate_plugin( $plugin );
        vpas_check( ! class_exists( 'WooCommerce' ) && ( is_wp_error( $result ) || class_exists( Plugin::class ) ) && ! has_action( 'init', array( Plugin::class, 'init' ) ), 'Without WooCommerce activation is refused or analytics remains safely disabled' );
        echo "DEPENDENCY AUDIT PASSED\n";
    } elseif ( 'missing_dependency_loaded' === VPA_SAFETY_PHASE ) {
        vpas_check( ! class_exists( 'WooCommerce' ) && ! has_action( 'init', array( Plugin::class, 'init' ) ), 'A fresh request without WooCommerce registers no analytics initialization' );
        echo "DEPENDENCY AUDIT PASSED\n";
    }
} catch (Throwable $error) { echo 'FAILED: ' . $error->getMessage() . "\n" . $error->getTraceAsString() . "\n"; exit(1); }
