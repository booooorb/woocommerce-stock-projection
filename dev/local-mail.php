<?php
/** Local-development-only mu-plugin. This file is never included in the release ZIP. */
defined( 'ABSPATH' ) || exit;

add_filter( 'pre_wp_mail', static function ( $return, $attributes ) {
    $mail = get_option( 'veryshop_development_mail', array() );
    array_unshift( $mail, array( 'at' => gmdate( 'c' ), 'to' => $attributes['to'], 'subject' => $attributes['subject'], 'message' => $attributes['message'] ) );
    update_option( 'veryshop_development_mail', array_slice( $mail, 0, 50 ), false );
    return true;
}, 10, 2 );

add_filter( 'woocommerce_admin_disabled', '__return_true' );
add_filter( 'woocommerce_allow_marketplace_suggestions', '__return_false' );

add_action( 'admin_menu', static function () {
    add_management_page( 'Development inbox', 'Development inbox', 'manage_woocommerce', 'veryshop-development-inbox', static function () {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'You cannot view this inbox.' );
        }
        echo '<div class="wrap"><h1>Development inbox</h1><p>Local test emails only. No messages leave this site.</p>';
        $messages = get_option( 'veryshop_development_mail', array() );
        if ( ! $messages ) {
            echo '<p>No emails captured yet. Enable alerts and use “Send email now” in Product Analysis.</p>';
        }
        foreach ( $messages as $message ) {
            echo '<div style="background:white;border:1px solid #dcdcde;padding:20px;margin:16px 0;max-width:1000px"><h2>' . esc_html( $message['subject'] ) . '</h2><p>' . esc_html( $message['at'] ) . ' · To: ' . esc_html( is_array( $message['to'] ) ? implode( ', ', $message['to'] ) : $message['to'] ) . '</p><pre style="white-space:pre-wrap">' . esc_html( $message['message'] ) . '</pre></div>';
        }
        echo '</div>';
    } );
} );
