<?php
namespace VeryShop\ProductAnalysis;

defined( 'ABSPATH' ) || exit;

final class Reports {
    public const PERIODS = array( '30', '90', '180', '365', 'all' );

    /** Cheap freshness token; changes with inventory/orders/settings and store-local midnight. */
    public static function revision(): string {
        return md5( VPA_VERSION . get_option( 'vpa_data_revision', 'initial' ) . wp_json_encode( self::settings() ) . wp_date( 'Y-m-d' ) . wp_timezone_string() );
    }

    public static function settings(): array {
        $saved = get_option( 'vpa_settings', array() );
        $saved = is_array( $saved ) ? $saved : array();
        // Preserve the previous store-wide lead + buffer total when upgrading.
        if ( ! array_key_exists( 'reorder_days', $saved ) ) {
            $saved['reorder_days'] = max( 0, min( 3650, (int) ( $saved['lead_days'] ?? 14 ) + (int) ( $saved['buffer_days'] ?? 7 ) ) );
        }
        unset( $saved['lead_days'], $saved['buffer_days'], $saved['reminder_days'] );
        $settings = wp_parse_args( $saved, array(
            'reorder_days' => 21,
            'email_enabled' => false,
            'recipients' => get_option( 'admin_email' ),
            'email_frequency_days' => 7,
            'alert_period' => '90',
        ) );
        foreach ( array( 'reorder_days' => array( 21, 0 ), 'email_frequency_days' => array( 7, 1 ) ) as $field => $rule ) {
            $value = $settings[ $field ];
            $settings[ $field ] = ( is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) ) ) && (int) $value >= $rule[1] && (int) $value <= 3650 ? (int) $value : $rule[0];
        }
        $settings['alert_period'] = in_array( $settings['alert_period'], self::PERIODS, true ) ? $settings['alert_period'] : '90';
        $settings['email_enabled'] = true === $settings['email_enabled'];
        if ( ! is_string( $settings['recipients'] ) || ! $settings['recipients'] ) {
            $settings['recipients'] = (string) get_option( 'admin_email', '' );
            $settings['email_enabled'] = false;
        }
        foreach ( explode( ',', $settings['recipients'] ) as $recipient ) {
            if ( ! is_email( trim( $recipient ) ) ) { $settings['email_enabled'] = false; }
        }
        return $settings;
    }

    public static function window( string $period ): array {
        $today = new \DateTimeImmutable( 'today', wp_timezone() );
        $first = 'all' === $period ? array_filter( array( Repository::first_sale(), StockHistory::first_date() ) ) : array();
        $start = 'all' === $period ? new \DateTimeImmutable( $first ? min( $first ) : $today->format( 'Y-m-d' ), wp_timezone() ) : $today->modify( '-' . ( (int) $period - 1 ) . ' days' );
        return array( 'start' => $start->format( 'Y-m-d' ), 'end' => $today->format( 'Y-m-d' ), 'days' => (int) $start->diff( $today )->days + 1 );
    }

    public static function daily_values( array $by_date, array $window ): array {
        $values = array();
        $date = new \DateTimeImmutable( $window['start'], wp_timezone() );
        for ( $i = 0; $i < $window['days']; $i++ ) {
            $values[] = (float) ( $by_date[ $date->format( 'Y-m-d' ) ] ?? 0 );
            $date = $date->modify( '+1 day' );
        }
        return $values;
    }

    public static function stock_owner( $product ) {
        return wc_get_product( $product->get_stock_managed_by_id() ) ?: $product;
    }

    public static function planning( $owner ): array {
        $settings = self::settings();
        $stored = get_option( 'vpa_planning_' . $owner->get_id(), null );
        // Read older plugin metadata without saving the WooCommerce product again.
        $arrival = is_array( $stored ) ? (string) ( $stored['arrival'] ?? '' ) : (string) $owner->get_meta( '_vpa_arrival', true );
        return array(
            'reorder_days' => (int) $settings['reorder_days'],
            'arrival' => $arrival,
            'on_order' => $arrival && $arrival >= wp_date( 'Y-m-d' ),
        );
    }

    public static function rows( string $period ): array {
        $revision = self::revision();
        $key = 'vpa_rows_' . md5( VPA_VERSION . $period );
        $cached = get_transient( $key );
        if ( false !== $cached && ( $cached['revision'] ?? '' ) === $revision ) {
            return $cached;
        }
        $window = self::window( $period );
        $catalog = Repository::catalog();
        if ( function_exists( 'wp_prime_option_caches' ) ) {
            $planning_keys = array();
            foreach ( $catalog as $product ) { $planning_keys[] = 'vpa_planning_' . $product->get_stock_managed_by_id(); }
            foreach ( array_chunk( array_unique( $planning_keys ), 100 ) as $keys ) { wp_prime_option_caches( $keys ); }
        }
        $totals = Repository::totals( $window['start'], $window['end'] );
        $product_sums = Repository::model_sums( $window['start'], $window['end'], $window['days'] );
        $owner_sums = array();
        $stock_models = StockHistory::models( $window );
        foreach ( $product_sums as $sum ) {
            $id = (int) $sum['product_id'];
            if ( ! isset( $catalog[ $id ] ) ) {
                continue;
            }
            $owner_id = $catalog[ $id ]->get_stock_managed_by_id();
            if ( ! isset( $owner_sums[ $owner_id ] ) ) {
                $owner_sums[ $owner_id ] = array( 'total' => 0.0, 'sum_y' => 0.0, 'sum_xy' => 0.0 );
            }
            foreach ( array( 'total', 'sum_y', 'sum_xy' ) as $field ) {
                $owner_sums[ $owner_id ][ $field ] += (float) $sum[ $field ];
            }
        }

        $rows = array();
        foreach ( $catalog as $id => $product ) {
            $owner = self::stock_owner( $product );
            $owner_id = $owner->get_id();
            $sums = $owner_sums[ $owner_id ] ?? array( 'total' => 0, 'sum_y' => 0, 'sum_xy' => 0 );
            $use_stock = isset( $stock_models[ $owner_id ] ) && ( $stock_models[ $owner_id ]['complete'] || (float) $sums['total'] <= 0 );
            $model = $use_stock ? $stock_models[ $owner_id ] : Model::from_sums( $window['days'], (float) $sums['total'], (float) $sums['sum_y'], (float) $sums['sum_xy'] );
            $stock = $owner->managing_stock() && null !== $owner->get_stock_quantity() ? (float) $owner->get_stock_quantity() : null;
            $planning = self::planning( $owner );
            $inventory = Model::inventory( $stock, $model['rate'], $planning['reorder_days'] );
            $low_amount = $owner->get_low_stock_amount();
            $low_amount = '' === $low_amount ? (float) get_option( 'woocommerce_notify_low_stock_amount', 2 ) : (float) $low_amount;
            $status = 'healthy';
            if ( null === $stock ) {
                $status = 'untracked';
            } elseif ( $stock <= 0 ) {
                $status = 'out';
            } elseif ( null !== $inventory['reorder_in'] && $inventory['reorder_in'] <= 0 ) {
                $status = $planning['on_order'] ? 'ordered' : 'overdue';
            } elseif ( $stock <= $low_amount ) {
                $status = 'low';
            } elseif ( $model['rate'] <= 0.00000001 ) {
                $status = 'no-demand';
            } elseif ( $planning['on_order'] ) {
                $status = 'ordered';
            }
            $rows[] = array_merge( array(
                'id' => (int) $id,
                'name' => $product->get_name(),
                'sku' => $product->get_sku(),
                'image' => wp_get_attachment_image_url( $product->get_image_id(), 'thumbnail' ) ?: null,
                'units' => $totals[ $id ]['units'] ?? 0,
                'revenue' => $totals[ $id ]['revenue'] ?? 0,
                'stock' => $stock,
                'stock_owner_id' => $owner_id,
                'stock_owner_name' => $owner->get_name(),
                'shared_stock' => $owner_id !== $id,
                'rate' => $model['rate'],
                'rate_source' => $use_stock ? 'stock_changes' : 'orders',
                'rate_history_days' => $model['days'],
                'status' => $status,
                'low_stock' => null !== $stock && $stock <= $low_amount,
                'planning' => $planning,
                'stockout_date' => self::date_offset( $inventory['days_remaining'] ),
                'reorder_date' => self::date_offset( $inventory['reorder_in'] ),
                'edit_url' => get_edit_post_link( $product->is_type( 'variation' ) ? $product->get_parent_id() : $id, 'raw' ),
            ), $inventory );
        }
        usort( $rows, static function ( $a, $b ) {
            return ( $b['units'] <=> $a['units'] ) ?: ( strnatcasecmp( $a['name'], $b['name'] ) ?: ( $a['id'] <=> $b['id'] ) );
        } );
        foreach ( $rows as $index => &$row ) {
            $row['rank'] = $index + 1;
        }
        unset( $row );
        $result = array( 'rows' => $rows, 'window' => $window, 'revision' => $revision );
        set_transient( $key, $result, 60 );
        return $result;
    }

    public static function date_offset( ?float $days ): ?string {
        if ( null === $days || ! is_finite( $days ) || abs( $days ) > 36500 ) {
            return null;
        }
        return wp_date( 'Y-m-d', time() + (int) round( $days * DAY_IN_SECONDS ), wp_timezone() );
    }

    public static function report( string $period, string $search = '', int $page = 1, int $per_page = 20 ): array {
        $result = self::rows( $period );
        $rows = $result['rows'];
        $summary = array( 'units' => 0, 'products' => 0, 'overdue' => 0, 'low' => 0 );
        $owners = array();
        foreach ( $rows as $row ) {
            $summary['units'] += $row['units'];
            $summary['products'] += $row['units'] > 0 ? 1 : 0;
            if ( ! isset( $owners[ $row['stock_owner_id'] ] ) ) {
                $summary['overdue'] += in_array( $row['status'], array( 'overdue', 'out' ), true ) ? 1 : 0;
                $summary['low'] += $row['low_stock'] ? 1 : 0;
                $owners[ $row['stock_owner_id'] ] = true;
            }
        }
        if ( '' !== $search ) {
            $rows = array_values( array_filter( $rows, static function ( $row ) use ( $search ) {
                return false !== stripos( $row['name'] . ' ' . $row['sku'], $search );
            } ) );
        }
        $total = count( $rows );
        $per_page = max( 1, min( 50, $per_page ) );
        $pages = max( 1, (int) ceil( $total / $per_page ) );
        $page = max( 1, min( $page, $pages ) );
        return array(
            'rows' => array_slice( $rows, ( $page - 1 ) * $per_page, $per_page ),
            'summary' => $summary, 'window' => $result['window'],
            'total' => $total, 'page' => $page, 'pages' => $pages, 'per_page' => $per_page,
            'sync' => Repository::state(), 'alert_period' => self::settings()['alert_period'],
            'revision' => $result['revision'],
        );
    }

    public static function detail( int $id, string $period ) {
        // Capture the baseline before choosing a cache revision, including shared-stock owners.
        $product = wc_get_product( $id );
        if ( $product ) { StockHistory::capture( $product ); }
        $key = 'vpa_detail_' . md5( VPA_VERSION . '|' . $id . '|' . $period );
        $cached = get_transient( $key );
        if ( false !== $cached && ( $cached['revision'] ?? '' ) === self::revision() ) {
            return self::current_detail( $cached );
        }
        $result = self::rows( $period );
        $row = null;
        foreach ( $result['rows'] as $candidate ) {
            if ( $candidate['id'] === $id ) {
                $row = $candidate;
                break;
            }
        }
        if ( ! $row ) {
            return new \WP_Error( 'vpa_product_missing', 'This product is unavailable.', array( 'status' => 404 ) );
        }
        $window = $result['window'];
        $daily = self::daily_values( Repository::daily( $window['start'], $window['end'], $id ), $window );
        $model = Model::fit( $daily );
        $history = array();
        $total = 0;
        $step = max( 1, (int) ceil( $window['days'] / 100 ) );
        $start = new \DateTimeImmutable( $window['start'], wp_timezone() );
        $history[] = array( 'date' => $start->modify( '-1 day' )->format( 'Y-m-d' ), 'units' => 0, 'cumulative' => 0, 'fitted' => $model['intercept'], 't' => 0 );
        $bucket = 0;
        foreach ( $daily as $i => $quantity ) {
            $total += $quantity;
            $bucket += $quantity;
            if ( 0 === ( $i + 1 ) % $step || $i === count( $daily ) - 1 ) {
                $history[] = array(
                    'date' => $start->modify( '+' . $i . ' days' )->format( 'Y-m-d' ),
                    'units' => $bucket, 'cumulative' => $total,
                    'fitted' => $model['intercept'] + $model['rate'] * ( $i + 1 ), 't' => $i + 1,
                );
                $bucket = 0;
            }
        }
        $detail = array( 'product' => $row, 'history' => $history, 'model' => $model, 'window' => $window, 'bucket_days' => $step,
            'period' => $period, 'stock_history' => self::stock_history( $row, $result['rows'], $window ),
            'revision' => $result['revision'],
        );
        // One reusable key per product/window; revisions replace entries instead of accumulating keys.
        set_transient( $key, $detail, 5 * MINUTE_IN_SECONDS );
        return self::current_detail( $detail );
    }

    private static function current_detail( array $detail ): array {
        $detail['stock_history']['today'] = time();
        $last = count( $detail['stock_history']['recorded'] ) - 1;
        if ( $last >= 0 ) { $detail['stock_history']['recorded'][ $last ]['time'] = time(); }
        $detail['product']['stockout_date'] = self::date_offset( $detail['product']['days_remaining'] );
        $detail['product']['reorder_date'] = self::date_offset( $detail['product']['reorder_in'] );
        return $detail;
    }

    private static function stock_history( array $product, array $rows, array $window ): array {
        $now = time();
        $start = ( new \DateTimeImmutable( $window['start'], wp_timezone() ) )->getTimestamp();
        $history = array( 'recorded' => array(), 'reconstructed' => array(), 'start' => $start, 'today' => $now, 'mode' => 'untracked', 'recorded_from' => null );
        if ( null === $product['stock'] ) { return $history; }
        $recorded = StockHistory::read( $product['stock_owner_id'], $start, $now );
        if ( ! $recorded ) {
            $recorded[] = array( 'time' => $now, 'stock' => $product['stock'] );
        }
        $anchor = $recorded[0];
        $history['recorded_from'] = $anchor['time'];
        // Reconstruct only the period before recording, anchored to its first measured stock.
        $before = ( new \DateTimeImmutable( '@' . $anchor['time'] ) )->setTimezone( wp_timezone() )->modify( '-1 day' )->format( 'Y-m-d' );
        $members = array_column( array_filter( $rows, static function ( $row ) use ( $product ) {
            return $row['stock_owner_id'] === $product['stock_owner_id'];
        } ), 'id' );
        $daily = $before >= $window['start'] ? Repository::daily_products( $members, $window['start'], $before ) : array();
        if ( array_sum( $daily ) > 0 && $anchor['time'] > $start ) {
            $quantity = $anchor['stock'] + array_sum( $daily );
            $points = array( array( 'time' => $start, 'stock' => $quantity ) );
            $day = new \DateTimeImmutable( $window['start'], wp_timezone() );
            while ( $day->format( 'Y-m-d' ) <= $before ) {
                $quantity -= (float) ( $daily[ $day->format( 'Y-m-d' ) ] ?? 0 );
                $points[] = array( 'time' => $day->setTime( 23, 59, 59 )->getTimestamp(), 'stock' => $quantity );
                $day = $day->modify( '+1 day' );
            }
            $points[] = array( 'time' => $anchor['time'], 'stock' => $anchor['stock'] );
            $stride = max( 1, (int) ceil( count( $points ) / 100 ) );
            foreach ( $points as $i => $point ) {
                if ( 0 === $i % $stride || $i === count( $points ) - 1 ) { $history['reconstructed'][] = $point; }
            }
        }
        $recorded[] = array( 'time' => $now, 'stock' => $product['stock'] );
        $history['recorded'] = $recorded;
        $history['mode'] = $history['reconstructed'] ? ( $anchor['time'] < $now - 1 ? 'mixed' : 'reconstructed' ) : 'recorded';
        return $history;
    }
}
