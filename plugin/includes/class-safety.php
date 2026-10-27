<?php
namespace VeryShop\ProductAnalysis;

defined( 'ABSPATH' ) || exit;

/** Analytics errors must not escape into a checkout or product-save request. */
final class Safety {
    public static function run( string $context, callable $operation, $fallback = null ) {
        global $wpdb;
        $previous = $wpdb->suppress_errors( true );
        try {
            return $operation();
        } catch ( \Throwable $error ) {
            self::record( $context );
            return $fallback;
        } finally {
            $wpdb->suppress_errors( $previous );
        }
    }

    public static function record( string $context ): void {
        try {
            update_option( 'vpa_runtime_error', array( 'context' => sanitize_key( $context ), 'at' => time() ), false );
        } catch ( \Throwable $ignored ) {
            // Even diagnostics must not interrupt the host application.
        }
    }
}
