<?php
namespace VeryShop\ProductAnalysis;

defined( 'ABSPATH' ) || exit;

final class Repository {
    public const SCHEMA_VERSION = '1';

    public static function ready(): bool {
        return self::SCHEMA_VERSION === get_option( 'vpa_db_version' );
    }

    public static function table_exists( string $table ): bool {
        global $wpdb;
        return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
    }

    public static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'veryshop_sales';
    }

    public static function install(): bool {
        global $wpdb;
        // Refuse to alter pre-existing tables unless this plugin has an ownership marker.
        if ( ! get_option( 'vpa_db_version' ) && ! get_option( 'vpa_schema_owner' ) && ( self::table_exists( self::table() ) || self::table_exists( StockHistory::table() ) ) ) {
            update_option( 'vpa_schema_error', 'Existing table names could not be verified as belonging to this plugin.', false );
            update_option( 'vpa_schema_retry_after', time() + HOUR_IN_SECONDS, false );
            return false;
        }
        update_option( 'vpa_schema_owner', 'veryshop-product-analysis', false );
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::table();
        $charset = $wpdb->get_charset_collate();
        dbDelta( "CREATE TABLE $table (
            order_item_id bigint(20) unsigned NOT NULL,
            order_id bigint(20) unsigned NOT NULL,
            product_id bigint(20) unsigned NOT NULL,
            stock_owner_id bigint(20) unsigned NOT NULL,
            sold_on date NOT NULL,
            quantity decimal(18,4) NOT NULL DEFAULT 0,
            net_sales decimal(18,4) NOT NULL DEFAULT 0,
            PRIMARY KEY  (order_item_id),
            KEY order_id (order_id),
            KEY product_date (product_id,sold_on),
            KEY stock_date (stock_owner_id,sold_on),
            KEY sold_on (sold_on)
        ) $charset;" );
        StockHistory::install();
        foreach ( array( self::table() => array( 'order_item_id', 'order_id', 'product_id', 'stock_owner_id', 'sold_on', 'quantity', 'net_sales' ), StockHistory::table() => array( 'id', 'stock_owner_id', 'recorded_at', 'recorded_on', 'quantity', 'depleted', 'source' ) ) as $name => $required ) {
            $columns = self::table_exists( $name ) ? $wpdb->get_col( "DESCRIBE $name", 0 ) : array();
            if ( array_diff( $required, $columns ?: array() ) ) {
                update_option( 'vpa_schema_error', 'Analytics tables could not be created or verified. Store operations remain enabled.', false );
                update_option( 'vpa_schema_retry_after', time() + HOUR_IN_SECONDS, false );
                return false;
            }
        }
        update_option( 'vpa_db_version', self::SCHEMA_VERSION, false );
        delete_option( 'vpa_schema_error' );
        delete_option( 'vpa_schema_retry_after' );
        if ( false === get_option( 'vpa_import_state', false ) ) {
            self::restart();
        }
        return true;
    }

    public static function state(): array {
        $state = get_option( 'vpa_import_state', array() );
        return wp_parse_args( is_array( $state ) ? $state : array(), array(
            'page' => 1, 'processed' => 0, 'total' => 0, 'complete' => false, 'updated_at' => null,
        ) );
    }

    public static function restart(): array {
        $state = array( 'page' => 1, 'processed' => 0, 'total' => 0, 'complete' => false, 'updated_at' => null );
        update_option( 'vpa_import_state', $state, false );
        self::invalidate();
        return $state;
    }

    public static function invalidate(): void {
        update_option( 'vpa_data_revision', wp_generate_uuid4(), false );
    }

    /** A short, atomically acquired DB option lock shared by the UI and background worker. */
    public static function lock( string $name, int $ttl = 120 ): bool {
        $key = 'vpa_lock_' . $name;
        $existing = get_option( $key );
        if ( $existing && (int) $existing < time() - $ttl ) {
            delete_option( $key );
        }
        return add_option( $key, time(), '', false );
    }

    public static function unlock( string $name ): void {
        delete_option( 'vpa_lock_' . $name );
    }

    /** Read orders through WooCommerce CRUD, compatible with both HPOS and legacy storage. */
    public static function import_batch(): array {
        if ( ! self::lock( 'import' ) ) {
            return self::state();
        }
        try {
            $state = self::state();
            if ( $state['complete'] ) {
                return $state;
            }
            $result = wc_get_orders( array(
                'type' => 'shop_order', 'status' => array_keys( wc_get_order_statuses() ),
                'limit' => 50, 'page' => $state['page'], 'paginate' => true,
                'orderby' => 'ID', 'order' => 'ASC', 'return' => 'ids',
            ) );
            foreach ( $result->orders as $id ) {
                self::sync_order( (int) $id, false );
            }
            $state['total'] = (int) $result->total;
            $state['processed'] = min( $state['total'], $state['processed'] + count( $result->orders ) );
            $state['complete'] = $state['page'] >= $result->max_num_pages || empty( $result->orders );
            $state['page']++;
            $state['updated_at'] = gmdate( 'c' );
            update_option( 'vpa_import_state', $state, false );
            self::invalidate();
            return $state;
        } finally {
            self::unlock( 'import' );
        }
    }

    public static function remove_order( $id ): void {
        global $wpdb;
        $wpdb->delete( self::table(), array( 'order_id' => (int) $id ), array( '%d' ) );
        self::invalidate();
    }

    public static function sync_order( $id, bool $invalidate = true ): void {
        global $wpdb;
        $order = wc_get_order( (int) $id );
        if ( $order instanceof \WC_Order_Refund ) {
            self::sync_order( $order->get_parent_id(), $invalidate );
            return;
        }
        $rows = array();
        if ( $order && $order->has_status( array( 'processing', 'completed' ) ) ) {
            $date = $order->get_date_paid() ?: $order->get_date_created();
            foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
                $product = $item->get_product();
                if ( ! $product || ! $date ) {
                    continue;
                }
                $quantity = max( 0.0, (float) $item->get_quantity() + (float) $order->get_qty_refunded_for_item( $item_id ) );
                if ( $quantity <= 0 ) {
                    continue;
                }
                $rows[] = array(
                    'order_item_id' => $item_id,
                    'order_id' => $order->get_id(),
                    'product_id' => $product->get_id(),
                    'stock_owner_id' => $product->get_stock_managed_by_id(),
                    'sold_on' => wp_date( 'Y-m-d', $date->getTimestamp(), wp_timezone() ),
                    'quantity' => $quantity,
                    'net_sales' => max( 0, (float) $item->get_total() - (float) $order->get_total_refunded_for_item( $item_id ) ),
                );
            }
        }

        // Never begin/commit/roll back a transaction shared with a checkout or another plugin.
        // Upsert first; remove obsolete index rows only after replacement data was written.
        foreach ( $rows as $row ) {
            if ( false === $wpdb->replace( self::table(), $row, array( '%d', '%d', '%d', '%d', '%s', '%f', '%f' ) ) ) {
                throw new \RuntimeException( 'Unable to write the sales index.' );
            }
        }
        $table = self::table();
        $keep = array_column( $rows, 'order_item_id' );
        $sql = $wpdb->prepare( "DELETE FROM $table WHERE order_id = %d", (int) $id );
        if ( $keep ) {
            $sql .= ' AND order_item_id NOT IN (' . implode( ',', array_map( 'intval', $keep ) ) . ')';
        }
        if ( false === $wpdb->query( $sql ) ) {
            throw new \RuntimeException( 'Unable to update the sales index.' );
        }
        if ( $invalidate ) {
            self::invalidate();
        }
    }

    public static function first_sale(): ?string {
        global $wpdb;
        $table = self::table();
        return $wpdb->get_var( "SELECT MIN(sold_on) FROM $table" ) ?: null;
    }

    public static function totals( string $start, string $end ): array {
        global $wpdb;
        $table = self::table();
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT product_id, SUM(quantity) AS units, SUM(net_sales) AS revenue FROM $table WHERE sold_on BETWEEN %s AND %s GROUP BY product_id",
            $start, $end
        ), ARRAY_A );
        $result = array();
        foreach ( $rows as $row ) {
            $result[ (int) $row['product_id'] ] = array( 'units' => (float) $row['units'], 'revenue' => (float) $row['revenue'] );
        }
        return $result;
    }

    public static function daily( string $start, string $end, ?int $id = null, bool $stock_owner = false ): array {
        global $wpdb;
        $table = self::table();
        $where = '';
        $args = array( $start, $end );
        if ( null !== $id ) {
            $column = $stock_owner ? 'stock_owner_id' : 'product_id';
            $where = " AND $column = %d";
            $args[] = $id;
        }
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT sold_on, SUM(quantity) AS units FROM $table WHERE sold_on BETWEEN %s AND %s $where GROUP BY sold_on ORDER BY sold_on",
            $args
        ), ARRAY_A );
        return array_column( $rows, 'units', 'sold_on' );
    }

    public static function model_sums( string $start, string $end, int $days ): array {
        global $wpdb;
        $table = self::table();
        $sum_x = $days * ( $days + 1 ) / 2;
        // A sale on day k contributes to all cumulative observations k..days.
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT product_id, SUM(quantity) AS total,
                SUM(quantity * (DATEDIFF(%s, sold_on) + 1)) AS sum_y,
                SUM(quantity * (%f - DATEDIFF(sold_on, %s) * (DATEDIFF(sold_on, %s) + 1) / 2.0)) AS sum_xy
            FROM $table WHERE sold_on BETWEEN %s AND %s GROUP BY product_id",
            $end, $sum_x, $start, $start, $start, $end
        ), ARRAY_A );
    }

    public static function daily_products( array $ids, string $start, string $end ): array {
        global $wpdb;
        if ( ! $ids ) { return array(); }
        $table = self::table();
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT sold_on, SUM(quantity) AS units FROM $table WHERE sold_on BETWEEN %s AND %s AND product_id IN ($placeholders) GROUP BY sold_on ORDER BY sold_on",
            array_merge( array( $start, $end ), array_map( 'intval', $ids ) )
        ), ARRAY_A );
        return array_column( $rows, 'units', 'sold_on' );
    }

    /** Fetch published/private simple products and individual variants, without counting parents twice. */
    public static function catalog(): array {
        $catalog = array();
        foreach ( array( 'simple', 'variation' ) as $type ) {
            $page = 1;
            do {
                $products = wc_get_products( array(
                    'type' => $type, 'status' => array( 'publish', 'private' ),
                    'limit' => 100, 'page' => $page++, 'orderby' => 'ID', 'order' => 'ASC',
                ) );
                foreach ( $products as $product ) {
                    $catalog[ $product->get_id() ] = $product;
                }
            } while ( 100 === count( $products ) );
        }
        return $catalog;
    }
}
