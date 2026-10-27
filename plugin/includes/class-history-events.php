<?php
namespace VeryShop\ProductAnalysis;

defined( 'ABSPATH' ) || exit;

/** Bounded, read-only order hydration on hover, never during graph preloading. */
final class HistoryEvents {
    public static function read( \WP_REST_Request $request ) {
        if ( ! current_user_can( 'edit_shop_orders' ) ) {
            return new \WP_Error( 'vpa_orders_forbidden', 'You do not have permission to view order details.', array( 'status' => 403 ) );
        }
        $product = wc_get_product( (int) $request['id'] );
        if ( ! $product ) { return new \WP_Error( 'vpa_product_missing', 'This product is unavailable.', array( 'status' => 404 ) ); }
        $start = (int) $request['start'];
        $end = min( time(), (int) $request['end'] );
        if ( $end < $start ) { return new \WP_Error( 'vpa_range', 'The stock-history interval is invalid.', array( 'status' => 400 ) ); }
        $owner = $product->get_stock_managed_by_id();
        $page = max( 1, (int) $request['page'] );
        $estimated = 'estimated' === $request['kind'];
        $rows = $estimated ? self::estimated( $owner, $start, $end, $page ) : self::recorded( $owner, $start, $end, (int) $request['start_id'], (int) $request['end_id'], $page );
        $more = count( $rows ) > 20;
        $orders = array(); $events = array();
        foreach ( array_slice( $rows, 0, 20 ) as $row ) {
            $order_id = (int) $row['order_id'];
            if ( $order_id && ! array_key_exists( $order_id, $orders ) ) { $orders[ $order_id ] = self::order( $order_id ); }
            if ( $estimated ) {
                $events[] = array( 'date' => $row['sold_on'], 'units' => (float) $row['units'], 'order' => $orders[ $order_id ] ?? null );
            } else {
                $after = (float) $row['quantity'];
                $before = null !== $row['previous_quantity'] ? (float) $row['previous_quantity'] : ( (float) $row['depleted'] > 0 ? $after + (float) $row['depleted'] : null );
                $events[] = array( 'time' => strtotime( $row['recorded_at'] . ' UTC' ), 'before' => $before, 'after' => $after,
                    'delta' => null === $before ? null : $after - $before, 'source' => $row['source'], 'order' => $orders[ $order_id ] ?? null );
            }
        }
        return array( 'product_id' => $product->get_id(), 'kind' => $request['kind'], 'start' => $start, 'end' => $end,
            'page' => $page, 'has_more' => $more, 'events' => $events, 'revision' => Reports::revision() );
    }

    private static function recorded( int $owner, int $start, int $end, int $start_id, int $end_id, int $page ): array {
        global $wpdb;
        $table = StockHistory::table();
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT recorded_at, quantity, previous_quantity, depleted, source, order_id FROM $table
             WHERE stock_owner_id = %d
             AND (recorded_at > %s OR (recorded_at = %s AND id > %d))
             AND (recorded_at < %s OR (recorded_at = %s AND id <= %d))
             ORDER BY recorded_at, id LIMIT 21 OFFSET %d",
            $owner, gmdate( 'Y-m-d H:i:s', $start ), gmdate( 'Y-m-d H:i:s', $start ), $start_id,
            gmdate( 'Y-m-d H:i:s', $end ), gmdate( 'Y-m-d H:i:s', $end ), $end_id, ( $page - 1 ) * 20
        ), ARRAY_A ) ?: array();
    }

    private static function estimated( int $owner, int $start, int $end, int $page ): array {
        global $wpdb;
        $table = Repository::table();
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT order_id, sold_on, SUM(quantity) AS units FROM $table WHERE stock_owner_id = %d AND sold_on BETWEEN %s AND %s
             GROUP BY order_id, sold_on ORDER BY sold_on, order_id LIMIT 21 OFFSET %d",
            $owner, wp_date( 'Y-m-d', $start ), wp_date( 'Y-m-d', $end ), ( $page - 1 ) * 20
        ), ARRAY_A ) ?: array();
    }

    private static function order( int $id ): array {
        $order = wc_get_order( $id );
        return array( 'number' => $order ? (string) $order->get_order_number() : (string) $id,
            'url' => $order ? esc_url_raw( $order->get_edit_order_url(), array( 'http', 'https' ) ) : null,
            'status' => $order ? wc_get_order_status_name( $order->get_status() ) : 'Order unavailable' );
    }
}
