# Safety audit: VeryShop Product Analysis 1.5.0

**Historical audit:** The original sections below record version 1.5.0. The 1.7.0 update at the end records the latest preservation checks. VERIFICATION.md records feature validation; the original release identity identifies the audited 1.5.0 archive.

Reviewed on October 26, 2026. **365 checks passed.** This is a code review and isolated test result, not a guarantee for an untested production website.

## Assessment

The hardened release observes WooCommerce data and stores its analysis separately. In the tested installations, activation, importing/reindexing, reports/graph reads, changing plugin settings, saving replenishment planning, and deactivation left existing products, orders, customer records, notes, coupons, protected store settings, configuration files and active-theme files unchanged. SQL observation recorded no writes to the protected store tables during those analytics operations.

**Zero database writes is not a promise this plugin can make.** It needs its own storage, caches, locks and scheduled jobs. Normal WooCommerce actions continue to change store data in the usual way. The plugin does not perform those stock/order/product changes itself.

## Issues found and fixed

1. **Planning used WooCommerce product saves.** The prior planning endpoint called the product `save()` method for a plugin delivery-date field. That could update product timestamps and invoke unrelated save hooks. Planning now writes only `vpa_planning_<stock-owner-id>` options. Older `_vpa_arrival` metadata is read for compatibility and left untouched. Tests confirmed planning invoked zero product-save events.
2. **Indexing controlled database transactions.** Its START/COMMIT/ROLLBACK calls could interfere with a caller's transaction in a synchronous hook path. Indexing now upserts/removes rows only in its own index without transaction-control commands. A rollback test confirmed unrelated work in an outer transaction remained rollbackable.
3. **Analytics exceptions could escape critical hooks.** Stock/order/index scheduling callbacks now contain exceptions and suppress their own database-error output while restoring the prior error-display state. Simulated failures left the source order unchanged and allowed a normal WooCommerce stock decrement to complete exactly once. This does not make third-party code or every possible PHP/hosting failure harmless.
4. **Schema readiness was assumed.** Setup now verifies the required analytics columns before marking the schema ready. It refuses unowned table-name collisions, pauses analysis if storage is unavailable, returns a controlled 503 for analytics endpoints in that state, and throttles setup retries. A separate schema version avoids reapplying DDL for cosmetic releases.
5. **Packaging was broad.** The release builder now includes an explicit allowlist of 12 production files. Fixtures, dummy products/orders, local email interception, dependencies, configuration files and test tools are excluded.

## Writes and side effects that remain

| Area | Scope |
| --- | --- |
| Analytics tables | `<WordPress prefix>veryshop_sales` and `<WordPress prefix>veryshop_stock_history`; order/product identifiers, dates, quantities, line totals and stock observations. No copied customer contact fields. |
| Plugin options | Names beginning `vpa_`, including settings, planning, progress, schema markers, locks, email-cycle state and generic diagnostics. |
| Report caches | Plugin transients beginning `vpa_rows_` / `vpa_detail_`, stored through WordPress cache/options APIs. |
| Scheduled work | Plugin hooks in Action Scheduler's `veryshop-product-analysis` group, or their WP-Cron fallback entries. Shared scheduler logs/queues and WordPress plugin-activation records change normally. |
| Mail | Fresh installations default to email disabled. After explicit enablement, inventory digests use WordPress mail and the saved frequency. No external analytics/forecast service is called by production code. |
| Files | Production code contains no file-write/delete operations. Scripts/styles are enqueued only on this plugin's admin page. |
| Deactivation/deletion | Deactivation cancels this plugin's jobs, preserving other jobs and store data. Analytics/settings are retained; deleting plugin files does not purge those records. |

Creating custom tables/options is standard WordPress plugin behavior: [WordPress activation hooks](https://developer.wordpress.org/plugins/plugin-basics/activation-deactivation-hooks/) and [table creation guidance](https://developer.wordpress.org/plugins/creating-tables-with-plugins/). Order reads use WooCommerce CRUD APIs in accordance with its [HPOS extension guidance](https://developer.woocommerce.com/docs/features/orders/high-performance-order-storage/recipe-book/).

## Verification performed

| Suite | Configuration | Passing assertions |
| --- | --- | ---: |
| Existing functional integration | WP 7.1.3 / WC 11.2.1 / PHP 8.3.33; HPOS and legacy | 216 |
| Browser-buffer behavior | Reuse, limits, expiry, cancellation, promotion, failure retry and revision races | 13 |
| Safety, current / HPOS | WP 7.1.3 / WC 11.2.1 / PHP 8.3.33 | 34 |
| Safety, current / legacy | WP 7.1.3 / WC 11.2.1 / PHP 8.3.33 | 34 |
| Safety, minimum branch / HPOS | WP 6.6.10 / WC 9.0.0 / PHP 8.0.30 | 34 |
| Safety, minimum branch / legacy | WP 6.6.10 / WC 9.0.0 / PHP 8.0.30 | 34 |

Safety fixtures were created with the plugin inactive, then activated through WordPress and exercised in fresh requests with its actual hooks registered. Hash comparisons covered authoritative post/meta, order/item, customer/user, note/comment, taxonomy and tax-setting tables, WooCommerce settings and selected core options, wp-config.php, .htaccess when present, and active-theme files. Existing data comparisons were taken around analytics operations. Separate failure tests deliberately requested normal WooCommerce stock changes and verified those intended changes happened once.

Additional checks covered public storefront responses, absence of plugin assets on unrelated pages, customer-role denial for reads/writes, real cookie-authenticated REST requests with missing/valid nonces, disabled-by-default mail, preserving unrelated scheduled jobs, safe loading without WooCommerce, malformed settings, denied schema creation, collision refusal, and recovery after restoring storage. Test mail stayed in the development-only sink.

Reproduce with `npm test` and `npm run test:safety`. Logs are `.local/test-results.txt`, `.local/buffer-test-results.txt`, and the four `.local/safety-*-results.txt` files. The safety profiles are disposable local test sites, separate from the demo; no real customer store was connected.

## Remaining limits and recommendation

- Tests used the official WordPress Playground PHP/SQLite runtime, **not your actual MySQL/MariaDB host**. The SQL is written for WordPress's MySQL-style APIs, but native production database behavior was not exercised here.
- Your exact theme, payment/shipping plugins, custom code, object cache, security rules, multisite setup, database permissions, traffic and large-catalog performance were not available. Minimum testing used WordPress 6.6.10, not every earlier 6.6 patch.
- Recording and indexing consume storage, queries and background-worker time. Stock history grows over time. No plugin that records history can promise zero load or zero conflict risk.
- Live email delivery and the host's cron reliability were not tested.

**Before installing on the live store, take a restorable backup and test this exact ZIP on a staging clone with your real theme and plugins.** Exercise checkout/payment, stock reduction/refund/restock, order editing, imports, reports, email opt-in, and deactivation there. The evidence supports the hardened release under the tested conditions; it cannot certify every existing website.

## Release identity

- ZIP: `dist/veryshop-product-analysis.zip`
- Version: 1.5.0
- Production files: 12
- SHA-256: `0dd594c92de1fd17250db5c15a832fd7497daf6c97da9f77e67273e74a00afec`


## Order-hover update 1.6.0 — October 27, 2026

All four preservation configurations were rerun: WP 7.1.3 / WooCommerce 11.2.1 / PHP 8.3.33 and WP 6.6.10 / WooCommerce 9.0.0 / PHP 8.0.30, each with HPOS and legacy orders. **35 checks passed per configuration (140 total).** The audit now exercises authenticated order-hover reads within protected-table SQL observation and whole-store hash comparison. Existing products, orders, users, notes, coupons, protected settings, configuration and theme files remained unchanged during the audited analytics operations.

The new schema adds three fields only to this plugin’s inventory-history table: previous_quantity, order_id and order_item_id. Integration tests migrated an old table and confirmed original quantities/depletion remained intact with unknown associations left empty. The new stock callback updates only a matching observation in that custom table; it does not save source orders, products or WooCommerce stock. A simulated annotation-write exception did not prevent or duplicate normal WooCommerce stock reduction. Stock-capture failures/lock contention and ambiguous transitions decline attribution.

Order details require both analytics and order-edit access. They expose order number/status/edit link and quantities without customer details. Hover reads do not change the data revision. The release adds no external forecasting/email service or new scheduled work. The native database/hosting/plugin compatibility limits and staging/backup recommendation above still apply; this is evidence under the tested conditions, not an absolute live-site guarantee.


## Customer-name display update 1.6.1

At the merchant’s request, the protected order-hover payload now adds the order’s stored billing full name. Earlier statements about no customer details describe 1.6.0. Access still requires analytics access and order-edit capability; email, address, phone and customer ID remain excluded. Names are escaped in the hover and held only in its bounded session cache, without copying names into analytics tables or graph preloads. The implementation uses an existing order getter and adds no source writes or schema changes. The HPOS/legacy integration suites and JavaScript suites passed again (319 checks), including contact-field exclusion, name fallback, read-only behavior and permission checks. The four-profile preservation audit above was not rerun for this read-only field addition.


## Confirmed restock planning update 1.7.0 — October 27, 2026

All four preservation profiles were rerun successfully: 35 assertions each (140 total), current WordPress 7.1.3 / WooCommerce 11.2.1 / PHP 8.3.33 and minimum WordPress 6.6.10 / WooCommerce 9.0.0 / PHP 8.0.30, each with HPOS and legacy orders. The planning request in the protected-store SQL audit now includes quantity and explicit confirmation. Existing authoritative store records, WooCommerce settings and protected files retained their hashes, and no protected-table writes or WooCommerce product-save events occurred during planning or the audited analytics operations.

The existing `vpa_planning_<stock-owner-id>` option additionally stores the purchased quantity, confirmed state, timestamp, and stock-at-confirmation snapshot. An unconfirmed preview exists only in the dashboard session; it triggers no planning save. Confirmation and Clear plan update only the plugin option and plugin cache revision. There is no new table migration and no incoming stock adjustment, product save or order mutation. The saved plan is a hypothetical projection, not a supplier purchase or inventory receipt.

Confirmed plans immediately suppress the stock pool's reorder, low-stock and out-of-stock analytics warnings and stock-email eligibility until the user clears the plan. Confirmation remains acknowledged after its entered arrival date. This is intentional user-requested behavior; the plugin does not automatically confirm delivery, receive units, expire the acknowledgement or order stock. Legacy date-only plans retain a date-bounded acknowledgement, without a fabricated quantity.

The 290 functional integration assertions and 74 JavaScript assertions also passed. New checks cover invalid quantities/dates, keeping an existing plan after invalid input, no stock changes on confirmation/clear, shared stock, warning/email suppression at zero inventory, projected arrival/coverage, overdue rendering and offset hover geometry. Browser checks verified preview, confirmation, persistence after reload and clearing with real inventory unchanged. Test plans were cleared afterward. All tests remain isolated Playground PHP/SQLite evidence; native production MySQL/MariaDB, live checkout with the merchant's extensions, and inbox delivery remain untested.

Release 1.7.0 archive: `dist/veryshop-product-analysis.zip`; 12 production files; SHA-256 `b6fb14f8c0a2b604d9761ecfc6c828b2896c683447204610a5c01de13d09adf7`.
