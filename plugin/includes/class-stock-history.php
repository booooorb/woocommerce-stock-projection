<?php
namespace VeryShop\ProductAnalysis;

defined( 'ABSPATH' ) || exit;

/** Append-only inventory observations, separate from the order-derived demand index. */
final class StockHistory {
    private static $recent = array();
    private static $unlinked = array();
    public static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'veryshop_stock_history';
    }

    public static function install(): void {
        global $wpdb;
        $table = self::table();
        $charset = $wpdb->get_charset_collate();
        dbDelta( "CREATE TABLE $table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            stock_owner_id bigint(20) unsigned NOT NULL,
            recorded_at datetime NOT NULL,
            recorded_on date NOT NULL,
            quantity decimal(18,4) NOT NULL DEFAULT 0,
            depleted decimal(18,4) NOT NULL DEFAULT 0,
            source varchar(20) NOT NULL DEFAULT 'recorded',
            previous_quantity decimal(18,4) DEFAULT NULL,
            order_id bigint(20) unsigned NOT NULL DEFAULT 0,
            order_item_id bigint(20) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            KEY owner_time (stock_owner_id,recorded_at,id),
            KEY owner_day (stock_owner_id,recorded_on),
            KEY recorded_at (recorded_at),
            KEY recorded_on (recorded_on)
        ) $charset;" );
        add_option( 'vpa_stock_baseline', array( 'type' => 0, 'page' => 1, 'complete' => false ), '', false );
    }

    public static function capture( $product ): void {
        if ( ! Repository::ready() ) { return; }
        $product = is_numeric( $product ) ? wc_get_product( (int) $product ) : $product;
        if ( ! $product instanceof \WC_Product ) {
            return;
        }
        $owner = $product->get_stock_managed_by_id() === $product->get_id() ? $product : wc_get_product( $product->get_stock_managed_by_id() );
        if ( ! $owner || ! $owner->managing_stock() || null === $owner->get_stock_quantity() ) {
            return;
        }
        $lock = 'stock_' . $owner->get_id();
        if ( ! Repository::lock( $lock, 30 ) ) {
            self::$recent[ $owner->get_id() ] = array(); self::$unlinked[ $owner->get_id() ] = true;
            return;
        }
        try {
            $last = self::previous( $owner->get_id(), time() );
            $quantity = (float) $owner->get_stock_quantity();
            if ( $last && abs( (float) $last['quantity'] - $quantity ) < 0.00001 ) {
                return;
            }
            if ( ! self::store( $owner->get_id(), $quantity, time(), $last ? 'change' : 'initial', true ) ) {
                Safety::record( 'stock_recording' );
            }
        } catch ( \Throwable $error ) {
            self::$recent[ $owner->get_id() ] = array(); self::$unlinked[ $owner->get_id() ] = true;
            throw $error;
        } finally {
            Repository::unlock( $lock );
        }
    }

    public static function before_save( $product ): void {
        if ( $product instanceof \WC_Product && $product->get_id() && array_key_exists( 'stock_quantity', $product->get_changes() ) ) {
            self::capture( wc_get_product( $product->get_id() ) );
        }
    }

    private static function previous( int $id, int $at ): ?array {
        global $wpdb;
        $table = self::table();
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT id, recorded_at, quantity FROM $table WHERE stock_owner_id = %d AND recorded_at <= %s ORDER BY recorded_at DESC, id DESC LIMIT 1",
            $id, gmdate( 'Y-m-d H:i:s', $at )
        ), ARRAY_A ) ?: null;
    }

    /** Timestamp is explicit for controlled fixtures; normal capture records the current time. */
    public static function store( int $id, float $quantity, int $at, string $source = 'recorded', bool $linkable = false ): bool {
        global $wpdb;
        $previous = self::previous( $id, $at );
        $ok = false !== $wpdb->insert( self::table(), array(
            'stock_owner_id' => $id,
            'recorded_at' => gmdate( 'Y-m-d H:i:s', $at ),
            'recorded_on' => wp_date( 'Y-m-d', $at, wp_timezone() ),
            'quantity' => $quantity,
            'depleted' => $previous ? max( 0, (float) $previous['quantity'] - $quantity ) : 0,
            'source' => sanitize_key( $source ),
            'previous_quantity' => $previous ? (float) $previous['quantity'] : null,
        ), array( '%d', '%s', '%s', '%f', '%f', '%s', '%f' ) );
        if ( $ok ) {
            if ( $linkable && empty( self::$unlinked[ $id ] ) ) {
                self::$recent[ $id ][] = array( 'id' => (int) $wpdb->insert_id, 'from' => $previous ? (float) $previous['quantity'] : null, 'to' => $quantity );
                if ( count( self::$recent[ $id ] ) > 100 ) { self::$recent[ $id ] = array(); self::$unlinked[ $id ] = true; }
            }
            Repository::invalidate();
        } elseif ( $linkable ) { self::$recent[ $id ] = array(); self::$unlinked[ $id ] = true; }
        return $ok;
    }

    /** Attribute only an exact observation captured in this PHP request, never a timestamp guess. */
    public static function link_order( $item, $order, float $from, float $to, string $source ): void {
        if ( ! Repository::ready() || ! $item instanceof \WC_Order_Item_Product || ! $order instanceof \WC_Order || ! $item->get_id() || ! $order->get_id() ) { return; }
        $product = $item->get_product();
        if ( ! $product ) { return; }
        $owner = $product->get_stock_managed_by_id();
        if ( ! empty( self::$unlinked[ $owner ] ) ) { return; }
        $matches = array_values( array_filter( self::$recent[ $owner ] ?? array(), static function ( $row ) use ( $from, $to ) {
            return null !== $row['from'] && abs( $row['from'] - $from ) < 0.00001 && abs( $row['to'] - $to ) < 0.00001;
        } ) );
        if ( 1 !== count( $matches ) ) { return; }
        $recent = $matches[0];
        global $wpdb;
        $ok = $wpdb->update( self::table(), array( 'order_id' => $order->get_id(), 'order_item_id' => $item->get_id(), 'source' => $source ), array( 'id' => $recent['id'], 'stock_owner_id' => $owner, 'order_id' => 0 ), array( '%d', '%d', '%s' ), array( '%d', '%d', '%d' ) );
        self::$recent[ $owner ] = array_filter( self::$recent[ $owner ], static function ( $row ) use ( $recent ) { return $row['id'] !== $recent['id']; } );
        if ( false === $ok ) { Safety::record( 'order_stock_link' ); }
        if ( $ok ) { Repository::invalidate(); }
    }

    /** Seed existing catalogs in short batches without blocking activation on large stores. */
    public static function baseline_batch(): bool {
        if ( ! Repository::lock( 'stock_baseline' ) ) {
            return false;
        }
        try {
            $state = get_option( 'vpa_stock_baseline', array( 'type' => 0, 'page' => 1, 'complete' => false ) );
            if ( ! is_array( $state ) || ! isset( $state['type'], $state['page'], $state['complete'] ) || ! in_array( $state['type'], array( 0, 1 ), true ) || $state['page'] < 1 ) {
                if ( is_array( $state ) && ! empty( $state['complete'] ) ) { return true; }
                $state = array( 'type' => 0, 'page' => 1, 'complete' => false );
            }
            if ( $state['complete'] ) {
                return true;
            }
            $products = wc_get_products( array( 'type' => array( 'simple', 'variation' )[ $state['type'] ], 'status' => array( 'publish', 'private' ), 'limit' => 50, 'page' => $state['page'], 'orderby' => 'ID', 'order' => 'ASC' ) );
            foreach ( $products as $product ) {
                self::capture( $product );
            }
            $state['page']++;
            if ( count( $products ) < 50 ) {
                $state['type']++;
                $state['page'] = 1;
                $state['complete'] = $state['type'] > 1;
            }
            update_option( 'vpa_stock_baseline', $state, false );
            return $state['complete'];
        } finally {
            Repository::unlock( 'stock_baseline' );
        }
    }

    /** Return complete fits and available-history fits for products with no order-derived outflow. */
    public static function models( array $window ): array {
        global $wpdb;
        $table = self::table();
        $heads = $wpdb->get_results( "SELECT stock_owner_id, MIN(recorded_at) AS first_recorded FROM $table GROUP BY stock_owner_id", ARRAY_A );
        $eligible = array();
        foreach ( $heads as $head ) {
            $first = wp_date( 'Y-m-d', strtotime( $head['first_recorded'] . ' UTC' ), wp_timezone() );
            $complete = $first <= $window['start'];
            if ( ! $complete && $first >= $window['end'] ) { continue; }
            $offset = $complete ? 0 : (int) ( new \DateTimeImmutable( $window['start'] ) )->diff( new \DateTimeImmutable( $first ) )->days;
            $days = $window['days'] - $offset;
            $eligible[ (int) $head['stock_owner_id'] ] = array_merge( Model::from_sums( $days, 0, 0, 0 ), array( 'complete' => $complete, 'offset' => $offset ) );
        }
        if ( ! $eligible ) {
            return array();
        }
        $sum_x = $window['days'] * ( $window['days'] + 1 ) / 2;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT stock_owner_id, SUM(depleted) AS total,
                SUM(depleted * (DATEDIFF(%s, recorded_on) + 1)) AS sum_y,
                SUM(depleted * (%f - DATEDIFF(recorded_on, %s) * (DATEDIFF(recorded_on, %s) + 1) / 2.0)) AS sum_xy
            FROM $table WHERE recorded_on BETWEEN %s AND %s GROUP BY stock_owner_id",
            $window['end'], $sum_x, $window['start'], $window['start'], $window['start'], $window['end']
        ), ARRAY_A );
        foreach ( $rows as $row ) {
            $id = (int) $row['stock_owner_id'];
            if ( isset( $eligible[ $id ] ) ) {
                $candidate = $eligible[ $id ];
                $fit = Model::from_sums( $candidate['days'], (float) $row['total'], (float) $row['sum_y'], (float) $row['sum_xy'] - $candidate['offset'] * (float) $row['sum_y'] );
                $eligible[ $id ] = array_merge( $fit, array( 'complete' => $candidate['complete'], 'offset' => $candidate['offset'] ) );
            }
        }
        return $eligible;
    }

    public static function first_date(): ?string {
        global $wpdb;
        $table = self::table();
        $at = $wpdb->get_var( "SELECT MIN(recorded_at) FROM $table" );
        return $at ? wp_date( 'Y-m-d', strtotime( $at . ' UTC' ), wp_timezone() ) : null;
    }

    private static function flush_bucket( array $bucket, array &$points ): void {
        $unique = array();
        foreach ( $bucket as $point ) {
            $unique[ $point['id'] ] = $point;
        }
        usort( $unique, static function ( $a, $b ) { return ( $a['time'] <=> $b['time'] ) ?: ( $a['id'] <=> $b['id'] ); } );
        foreach ( $unique as $point ) {
            $points[] = $point;
        }
    }

    /** Keep first/min/max/last observations per time bucket, preserving replenishment peaks. */
    public static function read( int $id, int $start, int $end ): array {
        global $wpdb;
        $table = self::table();
        $points = array();
        $prior = self::previous( $id, $start );
        if ( $prior ) {
            $points[] = array( 'id' => (int) $prior['id'], 'time' => $start, 'stock' => (float) $prior['quantity'] );
        }
        $seconds = max( 1, (int) ceil( ( $end - $start ) / 100 ) );
        $cursor = gmdate( 'Y-m-d H:i:s', $start );
        $cursor_id = 0;
        $bucket_id = null;
        $bucket = array();
        do {
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, recorded_at, quantity FROM $table WHERE stock_owner_id = %d AND recorded_at <= %s
                AND (recorded_at > %s OR (recorded_at = %s AND id > %d)) ORDER BY recorded_at, id LIMIT 1000",
                $id, gmdate( 'Y-m-d H:i:s', $end ), $cursor, $cursor, $cursor_id
            ), ARRAY_A );
            foreach ( $rows as $row ) {
                $point = array( 'id' => (int) $row['id'], 'time' => strtotime( $row['recorded_at'] . ' UTC' ), 'stock' => (float) $row['quantity'] );
                $current_bucket = (int) floor( ( $point['time'] - $start ) / $seconds );
                if ( $current_bucket !== $bucket_id ) {
                    if ( $bucket ) { self::flush_bucket( $bucket, $points ); }
                    $bucket_id = $current_bucket;
                    $bucket = array( 'first' => $point, 'low' => $point, 'high' => $point, 'last' => $point );
                } else {
                    $bucket['last'] = $point;
                    if ( $point['stock'] < $bucket['low']['stock'] ) { $bucket['low'] = $point; }
                    if ( $point['stock'] > $bucket['high']['stock'] ) { $bucket['high'] = $point; }
                }
                $cursor = $row['recorded_at'];
                $cursor_id = (int) $row['id'];
            }
        } while ( count( $rows ) === 1000 );
        if ( $bucket ) { self::flush_bucket( $bucket, $points ); }
        return $points;
    }
}
