<?php
namespace VeryShop\ProductAnalysis;

defined( 'ABSPATH' ) || exit;

final class Plugin {
    private static $page_hook;
    public const GROUP = 'veryshop-product-analysis';

    public static function boot(): void {
        if ( ! class_exists( 'WooCommerce' ) ) {
            add_action( 'admin_notices', static function () {
                if ( current_user_can( 'activate_plugins' ) ) {
                    echo '<div class="notice notice-warning"><p>VeryShop Product Analysis requires WooCommerce to be installed and active.</p></div>';
                }
            } );
            return;
        }
        add_action( 'init', array( __CLASS__, 'init' ), 20 );
        add_action( 'admin_menu', array( __CLASS__, 'menu' ), 30 );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
        add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
        add_action( 'vpa_import_batch', array( __CLASS__, 'background_import' ) );
        add_action( 'vpa_stock_baseline', array( __CLASS__, 'background_stock' ) );
        add_action( 'vpa_sync_order', array( __CLASS__, 'sync_order' ) );
        add_action( 'vpa_email_checks', array( __CLASS__, 'background_alerts' ) );
        foreach ( array( 'woocommerce_new_order', 'woocommerce_update_order', 'woocommerce_saved_order_items', 'woocommerce_order_status_changed', 'woocommerce_order_refunded' ) as $hook ) {
            add_action( $hook, array( __CLASS__, 'queue_order' ), 20, 1 );
        }
        add_action( 'woocommerce_refund_deleted', static function ( $refund_id, $order_id ) {
            self::queue_order( $order_id );
        }, 20, 2 );
        foreach ( array( 'woocommerce_before_delete_order', 'woocommerce_before_trash_order' ) as $hook ) {
            add_action( $hook, static function ( $id ) { Safety::run( 'remove_order_index', static function () use ( $id ) { if ( Repository::ready() ) { Repository::remove_order( $id ); } } ); } );
        }
        foreach ( array( 'woocommerce_product_set_stock', 'woocommerce_variation_set_stock', 'woocommerce_update_product', 'woocommerce_update_product_variation', 'woocommerce_new_product', 'woocommerce_delete_product' ) as $hook ) {
            add_action( $hook, static function () { Safety::run( 'invalidate', array( Repository::class, 'invalidate' ) ); } );
        }
        foreach ( array( 'woocommerce_product_before_set_stock', 'woocommerce_variation_before_set_stock', 'woocommerce_product_set_stock', 'woocommerce_variation_set_stock', 'woocommerce_new_product', 'woocommerce_new_product_variation' ) as $hook ) {
            add_action( $hook, static function ( $product ) { Safety::run( 'stock_capture', static function () use ( $product ) { StockHistory::capture( $product ); } ); }, 20 );
        }
        $before_save = static function ( $product ) { Safety::run( 'stock_before_save', static function () use ( $product ) { StockHistory::before_save( $product ); } ); };
        add_action( 'woocommerce_before_product_object_save', $before_save );
        add_action( 'woocommerce_before_product_variation_object_save', $before_save );
        add_action( 'woocommerce_reduce_order_item_stock', static function ( $item, $change, $order ) {
            Safety::run( 'order_stock_link', static function () use ( $item, $change, $order ) {
                if ( isset( $change['from'], $change['to'] ) ) { StockHistory::link_order( $item, $order, (float) $change['from'], (float) $change['to'], 'order' ); }
            } );
        }, 20, 3 );
        add_action( 'woocommerce_restore_order_item_stock', static function ( $item, $to, $from, $order ) {
            Safety::run( 'restore_stock_link', static function () use ( $item, $to, $from, $order ) { StockHistory::link_order( $item, $order, (float) $from, (float) $to, 'order_restore' ); } );
        }, 20, 4 );
        add_filter( 'woocommerce_prevent_adjust_line_item_product_stock', static function ( $prevent, $item, $quantity ) {
            if ( ! $prevent ) { Safety::run( 'begin_item_stock_link', static function () use ( $item, $quantity ) { StockHistory::begin_admin_adjustment( $item, $quantity ); } ); }
            return $prevent;
        }, PHP_INT_MAX, 3 );
        add_filter( 'woocommerce_can_restock_refunded_items', static function ( $allowed, $order, $items ) {
            if ( $allowed ) { Safety::run( 'begin_refund_stock_link', static function () use ( $order, $items ) { StockHistory::begin_refund( $order, $items ); } ); }
            return $allowed;
        }, PHP_INT_MAX, 3 );
        add_action( 'woocommerce_after_order_item_object_save', static function ( $item ) {
            Safety::run( 'finish_item_stock_link', static function () use ( $item ) { StockHistory::finish_item_adjustment( $item ); } );
        }, 20 );
        add_action( 'admin_notices', static function () {
            if ( self::allowed() && get_option( 'vpa_schema_error' ) ) {
                echo '<div class="notice notice-warning"><p>VeryShop Product Analysis is paused because its own analytics storage could not be initialized. Check database permissions before using analysis.</p></div>';
            }
        } );
    }

    public static function activate(): void {
        if ( class_exists( 'WooCommerce' ) ) { Safety::run( 'activation', array( Repository::class, 'install' ), false ); }
    }

    public static function init(): void {
        Safety::run( 'initialization', array( __CLASS__, 'initialize' ) );
    }

    public static function initialize(): void {
        if ( ! Repository::ready() ) {
            if ( (int) get_option( 'vpa_schema_retry_after', 0 ) > time() || ! Repository::install() ) { return; }
        }
        Alerts::schedule();
        self::schedule_import();
        $baseline = get_option( 'vpa_stock_baseline', array() );
        if ( empty( $baseline['complete'] ) ) {
            if ( function_exists( 'as_has_scheduled_action' ) ) {
                if ( ! as_has_scheduled_action( 'vpa_stock_baseline', array(), self::GROUP ) ) {
                    as_schedule_single_action( time() + 5, 'vpa_stock_baseline', array(), self::GROUP );
                }
            } elseif ( ! wp_next_scheduled( 'vpa_stock_baseline' ) ) {
                wp_schedule_single_event( time() + 5, 'vpa_stock_baseline' );
            }
        }
    }

    public static function background_stock(): void {
        Safety::run( 'stock_baseline', array( __CLASS__, 'run_stock_baseline' ) );
    }

    public static function run_stock_baseline(): void {
        if ( ! Repository::ready() ) { return; }
        if ( ! StockHistory::baseline_batch() ) {
            if ( function_exists( 'as_schedule_single_action' ) ) {
                as_schedule_single_action( time() + 5, 'vpa_stock_baseline', array(), self::GROUP );
            } else {
                wp_schedule_single_event( time() + 5, 'vpa_stock_baseline' );
            }
        }
    }

    private static function schedule_import(): void {
        if ( Repository::state()['complete'] ) {
            return;
        }
        if ( function_exists( 'as_has_scheduled_action' ) ) {
            if ( ! as_next_scheduled_action( 'vpa_import_batch', array(), self::GROUP ) ) {
                as_schedule_single_action( time() + 5, 'vpa_import_batch', array(), self::GROUP );
            }
        } elseif ( ! wp_next_scheduled( 'vpa_import_batch' ) ) {
            wp_schedule_single_event( time() + 5, 'vpa_import_batch' );
        }
    }

    public static function background_import(): void {
        Safety::run( 'order_import', array( __CLASS__, 'run_import' ) );
    }

    public static function run_import(): void {
        if ( ! Repository::ready() ) { return; }
        Repository::import_batch();
        if ( ! Repository::state()['complete'] ) {
            if ( function_exists( 'as_schedule_single_action' ) ) {
                as_schedule_single_action( time() + 5, 'vpa_import_batch', array(), self::GROUP );
            } else {
                wp_schedule_single_event( time() + 5, 'vpa_import_batch' );
            }
        }
    }

    public static function queue_order( $id ): void {
        Safety::run( 'queue_order', static function () use ( $id ) {
            if ( ! Repository::ready() || (int) $id <= 0 ) { return; }
            if ( function_exists( 'as_enqueue_async_action' ) && did_action( 'init' ) ) {
                as_enqueue_async_action( 'vpa_sync_order', array( (int) $id ), self::GROUP, true );
            } else {
                self::sync_order( $id );
            }
        } );
    }

    public static function sync_order( $id ): void {
        Safety::run( 'order_sync', static function () use ( $id ) { if ( Repository::ready() && (int) $id > 0 ) { Repository::sync_order( $id ); } } );
    }

    public static function background_alerts(): void {
        Safety::run( 'email_check', static function () { if ( Repository::ready() ) { Alerts::run(); } } );
    }

    public static function deactivate(): void {
        if ( function_exists( 'as_unschedule_all_actions' ) ) {
            foreach ( array( 'vpa_import_batch', 'vpa_stock_baseline', 'vpa_daily_alerts', 'vpa_email_checks', 'vpa_sync_order' ) as $hook ) {
                as_unschedule_all_actions( $hook, null, self::GROUP );
            }
        }
        wp_clear_scheduled_hook( 'vpa_import_batch' );
        wp_clear_scheduled_hook( 'vpa_stock_baseline' );
        wp_clear_scheduled_hook( 'vpa_daily_alerts' );
        wp_clear_scheduled_hook( 'vpa_email_checks' );
    }

    public static function menu(): void {
        self::$page_hook = add_submenu_page( 'woocommerce', 'Product Analysis', 'Product Analysis', 'manage_woocommerce', 'veryshop-product-analysis', array( __CLASS__, 'page' ) );
    }

    public static function page(): void {
        if ( ! self::allowed() ) {
            wp_die( esc_html__( 'You cannot access this page.', 'veryshop-product-analysis' ) );
        }
        echo '<div id="veryshop-analysis" class="vpa"><div class="vpa-initial" role="status">Loading stock analysis…</div><noscript>This dashboard needs JavaScript to display interactive stock graphs.</noscript></div>';
    }

    public static function assets( $hook ): void {
        if ( $hook !== self::$page_hook ) {
            return;
        }
        $dev = defined( 'WP_DEBUG' ) && WP_DEBUG;
        $css_version = $dev ? VPA_VERSION . '.' . filemtime( dirname( VPA_FILE ) . '/assets/admin.css' ) : VPA_VERSION;
        $js_version = $dev ? VPA_VERSION . '.' . filemtime( dirname( VPA_FILE ) . '/assets/admin.js' ) : VPA_VERSION;
        $buffer_version = $dev ? VPA_VERSION . '.' . filemtime( dirname( VPA_FILE ) . '/assets/read-buffer.js' ) : VPA_VERSION;
        wp_enqueue_style( 'vpa-admin', plugins_url( 'assets/admin.css', VPA_FILE ), array( 'dashicons' ), $css_version );
        wp_enqueue_script( 'vpa-read-buffer', plugins_url( 'assets/read-buffer.js', VPA_FILE ), array(), $buffer_version, true );
        wp_enqueue_script( 'vpa-admin', plugins_url( 'assets/admin.js', VPA_FILE ), array( 'vpa-read-buffer' ), $js_version, true );
        wp_add_inline_script( 'vpa-admin', 'window.VeryShopAnalysis=' . wp_json_encode( array(
            'api' => rest_url( 'veryshop/v1/' ),
            'nonce' => wp_create_nonce( 'wp_rest' ),
            'currency' => get_woocommerce_currency(),
            'locale' => str_replace( '_', '-', get_locale() ),
            'timezone' => wp_timezone_string(),
        ), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . ';', 'before' );
    }

    public static function allowed(): bool {
        return current_user_can( 'manage_woocommerce' );
    }

    public static function routes(): void {
        $period = array( 'default' => '90', 'enum' => Reports::PERIODS, 'type' => 'string' );
        $routes = array(
            array( '/report', 'GET', static function ( $r ) { return Reports::report( $r['period'], (string) ( $r['search'] ?? '' ), (int) ( $r['page'] ?? 1 ), (int) $r['per_page'] ); }, array(
                'period' => $period, 'search' => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
                'page' => array( 'type' => 'integer', 'minimum' => 1, 'default' => 1 ),
                'per_page' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 20 ),
            ) ),
            array( '/products/(?P<id>\d+)', 'GET', static function ( $r ) { return Reports::detail( (int) $r['id'], $r['period'] ); }, array( 'period' => $period ) ),
            array( '/products/(?P<id>\d+)/events', 'GET', array( HistoryEvents::class, 'read' ), array(
                'kind' => array( 'type' => 'string', 'enum' => array( 'recorded', 'estimated' ), 'required' => true ),
                'start' => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 4102444800, 'required' => true ),
                'end' => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 4102444800, 'required' => true ),
                'start_id' => array( 'type' => 'integer', 'minimum' => 0, 'default' => 0 ),
                'end_id' => array( 'type' => 'integer', 'minimum' => 0, 'default' => 0 ),
                'page' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 10000, 'default' => 1 ),
            ) ),
            array( '/products/(?P<id>\d+)/planning', 'POST', array( __CLASS__, 'save_planning' ), array() ),
            array( '/sync', 'GET', static function () { return array_merge( Repository::state(), array( 'revision' => Reports::revision() ) ); }, array() ),
            array( '/sync', 'POST', static function ( $r ) {
                if ( $r->get_param( 'restart' ) ) {
                    if ( ! Repository::lock( 'import' ) ) {
                        return new \WP_Error( 'vpa_busy', 'An import batch is already running. Try again shortly.', array( 'status' => 409 ) );
                    }
                    try { Repository::restart(); } finally { Repository::unlock( 'import' ); }
                }
                return Repository::import_batch();
            }, array( 'restart' => array( 'type' => 'boolean', 'default' => false ) ) ),
            array( '/settings', 'GET', static function () {
                return array( 'settings' => Reports::settings(), 'last_alert_run' => get_option( 'vpa_last_alert_run', null ), 'next_email_at' => Alerts::next_due() );
            }, array() ),
            array( '/settings', 'POST', array( __CLASS__, 'save_settings' ), array() ),
            array( '/alerts/preview', 'GET', array( Alerts::class, 'preview' ), array() ),
            array( '/alerts/run', 'POST', static function ( $r ) { return Alerts::run( (bool) $r['force'] ); }, array( 'force' => array( 'type' => 'boolean', 'default' => false ) ) ),
        );
        foreach ( $routes as $route ) {
            register_rest_route( 'veryshop/v1', $route[0], array(
                'methods' => $route[1], 'callback' => static function ( $request ) use ( $route ) {
                    if ( ! Repository::ready() ) { return new \WP_Error( 'vpa_storage_unavailable', 'Analytics storage is unavailable. Check plugin setup and database permissions.', array( 'status' => 503 ) ); }
                    return call_user_func( $route[2], $request );
                },
                'permission_callback' => array( __CLASS__, 'allowed' ), 'args' => $route[3],
            ) );
        }
    }

    private static function whole_number( $value, int $max = 3650 ): bool {
        return ( is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) ) ) && (int) $value >= 0 && (int) $value <= $max;
    }

    public static function save_settings( \WP_REST_Request $request ) {
        $body = $request->get_json_params();
        if ( ! is_array( $body ) ) {
            return new \WP_Error( 'vpa_invalid', 'Supply settings as JSON.', array( 'status' => 400 ) );
        }
        $settings = Reports::settings();
        $previous = $settings;
        foreach ( array( 'reorder_days', 'email_frequency_days' ) as $field ) {
            if ( array_key_exists( $field, $body ) ) {
                if ( ! self::whole_number( $body[ $field ] ) || ( 'email_frequency_days' === $field && (int) $body[ $field ] < 1 ) ) {
                    return new \WP_Error( 'vpa_invalid', 'Reorder days must be whole numbers from 0 to 3650; email frequency must be 1 to 3650 days.', array( 'status' => 400 ) );
                }
                $settings[ $field ] = (int) $body[ $field ];
            }
        }
        if ( isset( $body['alert_period'] ) ) {
            if ( ! in_array( $body['alert_period'], Reports::PERIODS, true ) ) {
                return new \WP_Error( 'vpa_invalid', 'Choose a supported history period.', array( 'status' => 400 ) );
            }
            $settings['alert_period'] = $body['alert_period'];
        }
        if ( array_key_exists( 'email_enabled', $body ) ) {
            if ( ! is_bool( $body['email_enabled'] ) ) {
                return new \WP_Error( 'vpa_invalid', 'Email enabled must be true or false.', array( 'status' => 400 ) );
            }
            $settings['email_enabled'] = $body['email_enabled'];
        }
        if ( isset( $body['recipients'] ) ) {
            if ( ! is_string( $body['recipients'] ) ) {
                return new \WP_Error( 'vpa_invalid', 'Enter email addresses separated by commas.', array( 'status' => 400 ) );
            }
            $addresses = array_unique( array_map( 'trim', explode( ',', $body['recipients'] ) ) );
            foreach ( $addresses as $address ) {
                if ( ! is_email( $address ) ) {
                    return new \WP_Error( 'vpa_invalid', 'Every recipient must be a valid email address.', array( 'status' => 400 ) );
                }
            }
            $settings['recipients'] = implode( ',', $addresses );
        }
        update_option( 'vpa_settings', $settings, false );
        if ( $settings['email_enabled'] && ! $previous['email_enabled'] ) {
            update_option( 'vpa_email_anchor', time(), false );
        }
        Alerts::schedule();
        Repository::invalidate();
        return array( 'settings' => $settings );
    }

    public static function save_planning( \WP_REST_Request $request ) {
        $product = wc_get_product( (int) $request['id'] );
        if ( ! $product ) {
            return new \WP_Error( 'vpa_product_missing', 'This product is unavailable.', array( 'status' => 404 ) );
        }
        $body = $request->get_json_params();
        if ( ! is_array( $body ) ) {
            return new \WP_Error( 'vpa_invalid', 'Supply planning settings as JSON.', array( 'status' => 400 ) );
        }
        if ( isset( $body['arrival'] ) && ( ! is_string( $body['arrival'] ) || ( '' !== $body['arrival'] && ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $body['arrival'] ) || ! \DateTimeImmutable::createFromFormat( '!Y-m-d', $body['arrival'] ) || \DateTimeImmutable::createFromFormat( '!Y-m-d', $body['arrival'] )->format( 'Y-m-d' ) !== $body['arrival'] ) ) ) ) {
            return new \WP_Error( 'vpa_invalid', 'Expected arrival must be a valid YYYY-MM-DD date.', array( 'status' => 400 ) );
        }
        $owner = Reports::stock_owner( $product );
        if ( array_key_exists( 'clear', $body ) ) {
            if ( true !== $body['clear'] ) { return new \WP_Error( 'vpa_invalid', 'Clear must be true.', array( 'status' => 400 ) ); }
            update_option( 'vpa_planning_' . $owner->get_id(), array( 'arrival' => '', 'quantity' => 0, 'confirmed' => false ), false );
        } elseif ( array_key_exists( 'quantity', $body ) || array_key_exists( 'confirmed', $body ) ) {
            $quantity = $body['quantity'] ?? null;
            if ( is_bool( $quantity ) || ! ( is_int( $quantity ) || is_float( $quantity ) ) || ! is_finite( (float) $quantity ) || $quantity <= 0 || $quantity > 1000000000 || abs( round( $quantity, 4 ) - $quantity ) > 0.00000001 ) {
                return new \WP_Error( 'vpa_invalid', 'Enter a positive quantity with up to four decimal places.', array( 'status' => 400 ) );
            }
            if ( true !== ( $body['confirmed'] ?? false ) || empty( $body['arrival'] ) || $body['arrival'] < wp_date( 'Y-m-d' ) ) {
                return new \WP_Error( 'vpa_invalid', 'Confirm the order with an arrival date of today or later.', array( 'status' => 400 ) );
            }
            update_option( 'vpa_planning_' . $owner->get_id(), array( 'arrival' => $body['arrival'], 'quantity' => (float) $quantity, 'confirmed' => true,
                'placed_at' => time(), 'stock_at_order' => $owner->managing_stock() && null !== $owner->get_stock_quantity() ? (float) $owner->get_stock_quantity() : null ), false );
        } elseif ( isset( $body['arrival'] ) ) {
            update_option( 'vpa_planning_' . $owner->get_id(), array( 'arrival' => $body['arrival'] ), false );
        }
        Repository::invalidate();
        return array( 'planning' => Reports::planning( $owner ) );
    }
}
