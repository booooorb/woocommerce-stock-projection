<?php
namespace VeryShop\ProductAnalysis;

/** Pure mathematics: no WordPress runtime or external forecasting service required. */
final class Model {
    /**
     * OLS line y = intercept + rate * t fitted to cumulative daily units.
     * An explicit day-zero anchor and zero-sale days are included in the fit.
     *
     * @param float[] $daily Ordered quantities, one entry per calendar day.
     */
    public static function fit( array $daily ): array {
        $days = count( $daily );
        if ( 0 === $days ) {
            return array( 'rate' => 0.0, 'intercept' => 0.0, 'total' => 0.0, 'days' => 0 );
        }

        $total = 0.0;
        $sum_y = 0.0;
        $sum_xy = 0.0;
        foreach ( array_values( $daily ) as $index => $quantity ) {
            $total += max( 0.0, (float) $quantity );
            $sum_y += $total;
            $sum_xy += ( $index + 1 ) * $total;
        }

        return self::from_sums( $days, $total, $sum_y, $sum_xy );
    }

    /** Equivalent OLS fit using sums, so all-time reports need not load every order or day. */
    public static function from_sums( int $days, float $total, float $sum_y, float $sum_xy ): array {
        if ( $days <= 0 ) {
            return array( 'rate' => 0.0, 'intercept' => 0.0, 'total' => 0.0, 'days' => 0 );
        }
        $n = $days + 1; // The zero anchor also contributes one observation.
        $sum_x = $days * ( $days + 1 ) / 2;
        $sum_xx = $days * ( $days + 1 ) * ( 2 * $days + 1 ) / 6;
        $denominator = $n * $sum_xx - $sum_x * $sum_x;
        $rate = $denominator > 0 ? max( 0.0, ( $n * $sum_xy - $sum_x * $sum_y ) / $denominator ) : 0.0;

        return array(
            'rate'      => $rate,
            'intercept' => ( $sum_y - $rate * $sum_x ) / $n,
            'total'     => $total,
            'days'      => $days,
        );
    }

    public static function inventory( ?float $stock, float $rate, int $reorder_days ): array {
        $coverage = null !== $stock && $rate > 0.00000001 ? max( 0.0, $stock ) / $rate : null;
        return array(
            'days_remaining' => $coverage,
            'reorder_in'     => null !== $coverage ? $coverage - $reorder_days : null,
            'reorder_point'  => $rate * $reorder_days,
        );
    }

    public static function restock( ?float $stock, float $rate, float $quantity, int $placed_at, int $arrival_at ): ?array {
        if ( null === $stock || ! is_finite( $stock ) || ! is_finite( $rate ) || ! is_finite( $quantity ) || $quantity <= 0 || $placed_at <= 0 ) { return null; }
        $rate = max( 0, $rate );
        $arrival_at = max( $placed_at, $arrival_at );
        $days = ( $arrival_at - $placed_at ) / 86400;
        $before = $stock <= 0 ? $stock : max( 0, $stock - $rate * $days );
        $after = $before + $quantity;
        $coverage = $after <= 0 ? 0.0 : ( $rate > 0.00000001 ? $after / $rate : null );
        $stockout_at = null !== $coverage && $coverage > 0 && $coverage <= 36500 ? $arrival_at + (int) round( $coverage * 86400 ) : null;
        return array( 'placed_at' => $placed_at, 'arrival_at' => $arrival_at, 'stock_at_order' => $stock, 'before' => $before, 'after' => $after,
            'quantity' => $quantity, 'rate' => $rate, 'coverage_days' => $coverage, 'stockout_at' => $stockout_at );
    }
}
