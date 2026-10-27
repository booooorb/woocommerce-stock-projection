<?php
namespace VeryShop\ProductAnalysis;

defined( 'ABSPATH' ) || exit;

final class Alerts {
    /** Lightweight hourly wake-up; the saved interval gates actual email digests. */
    public static function schedule(): void {
        if ( ! get_option( 'vpa_email_schedule_migrated', false ) ) {
            if ( function_exists( 'as_unschedule_all_actions' ) ) {
                as_unschedule_all_actions( 'vpa_daily_alerts', null, Plugin::GROUP );
            }
            wp_clear_scheduled_hook( 'vpa_daily_alerts' );
            update_option( 'vpa_email_schedule_migrated', true, false );
        }
        $enabled = Reports::settings()['email_enabled'];
        if ( function_exists( 'as_has_scheduled_action' ) ) {
            if ( wp_next_scheduled( 'vpa_email_checks' ) ) { wp_clear_scheduled_hook( 'vpa_email_checks' ); }
            if ( ! $enabled ) {
                if ( as_has_scheduled_action( 'vpa_email_checks', array(), Plugin::GROUP ) ) {
                    as_unschedule_all_actions( 'vpa_email_checks', null, Plugin::GROUP );
                }
            } elseif ( ! as_has_scheduled_action( 'vpa_email_checks', array(), Plugin::GROUP ) ) {
                as_schedule_recurring_action( time() + HOUR_IN_SECONDS, HOUR_IN_SECONDS, 'vpa_email_checks', array(), Plugin::GROUP, true );
            }
        } elseif ( ! $enabled ) {
            wp_clear_scheduled_hook( 'vpa_email_checks' );
        } elseif ( ! wp_next_scheduled( 'vpa_email_checks' ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'vpa_email_checks' );
        }
        if ( $enabled ) { add_option( 'vpa_email_anchor', time(), '', false ); }
    }

    public static function next_due(): ?int {
        $settings = Reports::settings();
        if ( ! $settings['email_enabled'] ) { return null; }
        $anchor = (int) get_option( 'vpa_email_anchor', 0 );
        return $anchor ? $anchor + (int) $settings['email_frequency_days'] * DAY_IN_SECONDS : null;
    }

    public static function candidates(): array {
        $settings = Reports::settings();
        $rows = Reports::rows( $settings['alert_period'] )['rows'];
        $candidates = array();
        foreach ( $rows as $row ) {
            if ( isset( $candidates[ $row['stock_owner_id'] ] ) || null === $row['stock'] ) { continue; }
            $reason = '';
            if ( $row['stock'] <= 0 ) {
                $reason = 'out';
            } elseif ( ! $row['planning']['on_order'] && null !== $row['reorder_in'] && $row['reorder_in'] <= 0 ) {
                $reason = 'overdue';
            }
            if ( $reason ) {
                $row['reason'] = $reason;
                $candidates[ $row['stock_owner_id'] ] = $row;
            }
        }
        return array_values( $candidates );
    }

    public static function message( array $rows ): string {
        $settings = Reports::settings();
        $lines = array(
            'VeryShop inventory update', '',
            'Products at or past their reorder point, including out-of-stock inventory.',
            'Reorder trigger: ' . $settings['reorder_days'] . ' days before projected stock-out.',
            'Email frequency: every ' . $settings['email_frequency_days'] . ' days.',
            'Depletion model: a linear fit over ' . ( 'all' === $settings['alert_period'] ? 'all recorded history.' : 'the last ' . $settings['alert_period'] . ' days.' ),
            'Projection: stock(t) = current stock - modeled daily depletion × days.', '',
        );
        $labels = array( 'out' => 'OUT OF STOCK', 'overdue' => 'REORDER DUE' );
        foreach ( $rows as $row ) {
            $lines[] = $labels[ $row['reason'] ] . ': ' . html_entity_decode( $row['stock_owner_name'], ENT_QUOTES, 'UTF-8' );
            $lines[] = 'Stock: ' . round( $row['stock'], 2 ) . ' units | Modeled depletion: ' . round( $row['rate'], 2 ) . ' units/day';
            if ( null !== $row['days_remaining'] ) {
                $lines[] = 'Projected coverage: ' . round( $row['days_remaining'], 1 ) . ' days | Stock-out: ' . ( $row['stock'] <= 0 ? 'out now' : ( $row['stockout_date'] ?: 'beyond 100 years' ) );
                $lines[] = 'Reorder date: ' . ( $row['reorder_date'] ?: 'outside display range' ) . ' | Reorder level: ' . round( $row['reorder_point'], 2 ) . ' units (' . $row['planning']['reorder_days'] . ' days of stock)';
            } else {
                $lines[] = 'Reorder date: due now; inventory is already out of stock and no depletion date can be modeled for this period.';
            }
            if ( $row['planning']['on_order'] ) {
                $lines[] = 'Replenishment marked as ordered; expected arrival: ' . $row['planning']['arrival'];
            }
            $lines[] = '';
        }
        if ( ! $rows ) { $lines[] = 'No products have reached the saved reorder point.'; $lines[] = ''; }
        $lines[] = 'View analysis: ' . admin_url( 'admin.php?page=veryshop-product-analysis' );
        return implode( "\n", $lines );
    }

    public static function preview(): array {
        $rows = self::candidates();
        return array( 'products' => count( $rows ), 'message' => self::message( $rows ), 'index_complete' => Repository::state()['complete'] );
    }

    /** A single digest includes every currently due stock pool, with a store-wide send interval. */
    public static function run( bool $force = false ): array {
        if ( ! Repository::ready() ) { return array( 'sent' => 0, 'message' => 'Analytics storage is unavailable; no email was sent.' ); }
        $settings = Reports::settings();
        if ( ! $settings['email_enabled'] ) {
            return array( 'sent' => 0, 'message' => 'Email alerts are switched off. Enable them in Settings first.' );
        }
        if ( ! Repository::state()['complete'] ) {
            return array( 'sent' => 0, 'message' => 'Finish indexing order history before sending alerts.' );
        }
        if ( ! Repository::lock( 'alerts', 300 ) ) {
            return array( 'sent' => 0, 'message' => 'An email check is already running.' );
        }
        try {
            add_option( 'vpa_email_anchor', time(), '', false );
            $due = self::next_due();
            if ( ! $force && $due && time() < $due ) {
                return array( 'sent' => 0, 'next_email_at' => $due, 'message' => 'The next stock email is due on ' . wp_date( 'Y-m-d H:i', $due ) . '. The saved email frequency is ' . $settings['email_frequency_days'] . ' days.' );
            }
            $rows = self::candidates();
            $result = array( 'sent' => 0, 'message' => 'No products have reached the saved reorder point. No email was sent.' );
            if ( $rows ) {
                $sent = wp_mail(
                    explode( ',', $settings['recipients'] ),
                    sprintf( '[VeryShop] %d product%s due for reorder', count( $rows ), 1 === count( $rows ) ? '' : 's' ),
                    self::message( $rows ),
                    array( 'Content-Type: text/plain; charset=UTF-8' )
                );
                if ( $sent ) {
                    $result = array( 'sent' => count( $rows ), 'message' => 'One email digest was handed to WordPress for ' . count( $rows ) . ' product(s). Inbox delivery depends on your mail provider.' );
                } else {
                    $result = array( 'sent' => 0, 'failed' => true, 'message' => 'WordPress could not hand off the email. The next scheduled check can retry; check your mail configuration.' );
                }
            }
            // Empty scheduled checks count as a cycle; failed mail remains due for retry.
            if ( empty( $result['failed'] ) ) { update_option( 'vpa_email_anchor', time(), false ); }
            $result['next_email_at'] = self::next_due();
            update_option( 'vpa_last_alert_run', array_merge( $result, array( 'at' => gmdate( 'c' ) ) ), false );
            return $result;
        } finally {
            Repository::unlock( 'alerts' );
        }
    }
}
