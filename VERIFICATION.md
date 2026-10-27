# Verification: VeryShop Product Analysis 1.6.0

**Story:** An administrator views inventory history and depletion on a large stock graph, distinguishes Today from future projection, and searches or pages through products in a sidebar while keeping the selected graph visible. WooCommerce stock hooks record deductions and replenishments; authenticated REST endpoints return history and the fitted inventory function. A numeric field above the graph sets N days before projected stockout; the saved global value drives the graph threshold, reorder dates, statuses, and email checks.

## Integration results

**Version 1.6.0: 454 checks passed — 250 WordPress integration checks, 64 JavaScript checks, and 140 store-preservation checks across four configurations.** Runtime: WordPress 7.1.3, WooCommerce 11.2.1, PHP 8.3.33, using the official WordPress Playground PHP/SQLite runtime. Current logs are `.local/test-results.txt`, `.local/buffer-test-results.txt`, `.local/preload-test-results.txt`, `.local/selection-test-results.txt`, `.local/freshness-test-results.txt`, and `.local/hover-test-results.txt`. The safety matrix was rerun for 1.6.0 on current and minimum supported platform branches, each with HPOS and legacy order storage (35 checks each). Logs are `.local/safety-*-results.txt`; SAFETY-AUDIT.md retains the historical review and the current update. Tests use Playground PHP/SQLite; native production MySQL/MariaDB and the merchant’s actual extensions remain untested.

| Boundary | Result | Evidence |
| --- | --- | --- |
| WooCommerce → stock history | Passed | Stock deductions through `wc_update_product_stock` and manual product edits both updated the recorded inventory quantity. Unchanged quantities did not create duplicate observations. |
| Inventory observations → function | Passed | A fixture consuming 5 units daily, including a separate 25-unit replenishment, fitted a 5/day outgoing rate and gave 10 days of coverage for 50 current units. Positive replenishments were excluded from depletion. |
| Partial inventory history → function | Passed | A product without older order history used its 36 available observation days when a year was selected, giving a rate near 5/day instead of an invented flat projection. |
| Older orders → reconstruction | Passed | Missing older stock history was explicitly labeled as reconstructed and anchored to measured stock. Shared-stock reconstruction included both variations. Untracked products did not acquire invented stock levels. |
| Long history → plotted points | Passed | Time-bucket sampling retained both sides of replenishment jumps. Current quantities remained the endpoint at Today. |
| Order data → inventory fallback | Passed | Order quantities, refunds, excluded statuses, changed/deleted orders, date windows, and idempotent indexing were checked in both storage modes. |
| Server graph cache | Passed | A cold graph scanned recorded stock history; its repeated read made zero history scans. Today was reanchored at read time. Order changes, settings saves, and parent stock updates invalidated cached graphs. |
| Browser buffer correctness | Passed | Graph reuse, period separation, bounded LRU eviction, promotion of in-flight reads, background cancellation, failure retry, expiry/renewal, and late-response revision races passed. |
| Saved reorder trigger → graph/API/email | Passed | N=10 gave 50 units and reorder in 10 days for 100 units at 5/day; N=20 made the reorder due immediately and eligible for email. Shared-stock variations used the same N, and old product overrides did not affect it. Email previews contained the saved days and quantity. |
| Settings persistence and edge cases | Passed | Legacy store lead + buffer totals migrated to N. Zero days was retained; invalid, negative, fractional, and over-limit values were rejected. Saving N preserved other email settings. Zero usage and untracked stock retained null projection dates. |
| Permissions and input validation | Passed | Unauthenticated reads, invalid periods/page sizes, malformed planning dates, invalid reorder-day values, and invalid email settings were rejected. |
| Email and scheduling | Passed | Seven-day default, saved frequency/next due date, exact interval boundaries, one complete digest per cycle, no interim escalation emails, manual send, empty-cycle handling, failed handoff retry, migration cleanup, disabling/deactivation, and shared-stock deduplication passed in both storage modes. All test email remained in the development sink. |

## Browser verification

- The final 320px mobile check retained a 320px page width and 278px graph width with the buffer indicator visible; browser warnings/errors were empty.
- The background indicator reached 20 / 20 ready for the current and next product page without changing the Everyday Tote graph.
- Two-session freshness check: the main view held 20 buffered graphs at N=21. Saving N=20 in a separate local view automatically updated the original view to a 160.39-unit threshold, with no manual refresh or browser error.
- Selecting the warmed Ceramic Travel Cup immediately showed its 38-unit graph with aria-busy=false. The prefetched second product page loaded while preserving that graph.
- Selecting Glass Storage Jar on page 2 and saving N=10 replaced its buffered threshold with 9.99 units while preserving page 2 and the selected product. The trigger was restored to 21 afterward.

- The top-level Reorder before stockout field saved 10 days and updated the Everyday Tote graph from 168.41 to 80.19 units; the reorder date moved from November 4 to November 15 with projected stockout on November 25.
- Saving 40 days raised the threshold to 320.77 units and changed the status to Reorder overdue. Reloading retained 40, and the email preview used the same trigger and quantity. The preview was restored to the default 21 days afterward.
- Email settings and restock planning no longer contained competing lead/buffer controls. The expected-delivery date remains available.
- The new number field and Save button fit a 320px viewport. The page remained 320px wide and the graph occupied its 278px container. At an observed 1176px desktop viewport, the left graph and right product browser remained side by side.

- The main graph showed recorded inventory levels with visible replenishment jumps, a Today marker, a shaded future area, and a dashed projection. Sales graph controls were absent.
- The preview used 21 sample products over three pages. Moving from page 1 to page 2 kept Ceramic Travel Cup selected and the complete stock SVG unchanged.
- Selecting Glass Storage Jar on page 2 updated the main graph to its 85-unit current quantity. Changing projection to 90 days updated the graph description and future period while preserving page 2.
- Direct navigation to page 3 displayed the last product, Wool Desk Mat, and disabled Next. Previous returned to page 2.
- Searching for Cup returned one product while preserving the selected graph; clearing the search restored the product page. Sidebar scroll position and expanded model details are retained during same-page redraws.
- At the observed 1414px desktop viewport, the graph occupied 872px and the product sidebar 300px. The layout used the left side predominantly for the graph.
- At a temporary 320 × 784 viewport, the graph occupied its full 278px container without horizontal overflow; Today remained visible and paging remained available below the graph. The normal browser viewport was restored afterward.
- Browser console checks found no plugin warnings or errors during the verified interactions.

## Release verification

JavaScript syntax and production file integrity checks passed. The release ZIP was checked for integrity and byte-for-byte correspondence with the production sources. It includes the inventory-history class and excludes development fixtures, sample data, runtime dependencies, and the local email interceptor.

Final browser screenshot: `.local/buffered-stock-workspace.png`.

## Practical limits

- Existing WooCommerce order-note stock values are not imported by this release. Historical deliveries and manual adjustments before recording began are not recoverable from orders alone. That graph portion is labeled Reconstructed when order quantities can supply an approximation; unavailable history remains unrecorded.
- Inventory recording observes WooCommerce stock/product API changes. Events occurring outside those APIs or while recording is inactive cannot be recovered reliably.
- A production MySQL/MariaDB host, the minimum advertised platform versions, and the merchant's installed extensions were not available for this run. External inbox delivery was intentionally not exercised.
- Production scheduled timing depends on the host running WordPress scheduled tasks. The local demo disables automatic cron and provides immediate reindex and alert controls.

## UI update 1.3.2

The graph now marks the reorder-level/projection intersection with a date label. The previous stockout point/text, Units in stock label, and status badges were removed. The stockout date remains in the facts below the graph.

JavaScript syntax and browser checks passed. Everyday Tote showed November 4, 2026, at 168.41 units; the plotted point was exactly on the reorder line and within 0.003 pixels of the projection segment after SVG rounding. Ceramic Travel Cup, already past its reorder date, had no future crossing marker. Cotton Throw had none in a 30-day projection and displayed December 24, 2026, after changing to 90 days. At 320px, the label stayed inside the graph and the page had no horizontal overflow. Browser warnings/errors were empty. These were targeted UI checks; the previously recorded core integration suite was not rerun for this presentation change.

Screenshot: `.local/reorder-point-workspace.png`.

## Email update 1.4.0

Stock-email frequency is now a separate saved number of days (default 7). Automatic digests include all products at or past their modeled reorder date, plus out-of-stock stock pools. The first interval starts on enable; completed sends and empty cycles start the next interval. Failed handoffs stay due for retry. Hourly scheduler wake-ups check the stored due time, and a manual Send email now control explicitly bypasses only the cadence gate. The saved reorder-before-stockout variable is preserved when email preferences change.

Browser verification: the initial next digest was November 2, 2026. Saving a three-day interval changed it to October 29 and retained the existing 90-day reorder trigger. Reloading retained that due date and the 90-day trigger. The interval was restored to seven days, moving the next digest back to November 2. The preview listed REORDER DUE products, their reorder dates/levels, the 90-day trigger, and the seven-day email frequency. Previewing sent no mail. Production inbox delivery was not exercised.

Final email settings screenshot: `.local/weekly-email-settings.png`.

## Solid stock history update 1.4.1

Recorded and reconstructed history now share the same solid green stroke, line width, and area fill, with one Stock history legend entry. The explanatory note and observation table still identify reconstructed quantities. The reorder threshold remains amber and the future projection remains dashed.

Browser verification used Everyday Tote with Year selected: both history paths had stroke `#176b50`, width `2.3`, no dash pattern, and fill `#e7f2eb`. History data & model confirmed that the 6.14-unit/day depletion estimate uses order-derived outflow over the selected period. Historical order depletion already participates in the model, so no forecasting change was needed. Browser warnings/errors were empty.

All 216 WordPress integration checks passed again (108 HPOS and 108 legacy), including exact order-derived depletion, shared stock, reconstructed history, and the recorded-stock fallback. JavaScript syntax and release ZIP integrity/source correspondence also passed. Transcript: `.local/solid-history-test-results.txt`. Screenshot: `.local/solid-stock-history.png`.

## Hover-label update 1.4.1

Restored the visible red stockout point alongside the reorder intersection. Exact dates and the reorder-line day label are hidden by default, shown on hover or keyboard focus, and dismissible with Escape. Labels do not capture pointer input. Touch users can focus a marker by tapping. The legend uses the static Reorder level label. The saved 90-day reorder setting was retained.

Targeted verification: JavaScript syntax passed. In the browser, Everyday Tote's stockout dot remained visible while its November 25, 2026 date stayed hidden until hovered and hid again when the pointer moved away. Cotton Napkins with a 180-day projection showed both points; hover/focus revealed November 10, 2026 for reorder and February 8, 2027 for stockout. Reorder-line hover revealed Reorder 90 days and hid when the pointer moved away. Keyboard focus revealed the stockout date and Escape dismissed it. These were presentation checks; the full core integration suite recorded above was not rerun.

Screenshot: `.local/hover-stock-workspace.png`.

## Safety review 1.5.0

See SAFETY-AUDIT.md for the full assessment and remaining limits. All 34 safety assertions passed in each of four combinations: WP 7.1.3 / WooCommerce 11.2.1 / PHP 8.3.33 and WP 6.6.10 / WooCommerce 9.0.0 / PHP 8.0.30, each with HPOS and legacy orders. Existing store records and protected files were unchanged during the audited analytics operations. Normal stock reductions still completed while simulated analytics failures were injected. Real HTTP requests confirmed cookie/nonce protection and normal storefront responses.

The review removed WooCommerce product saves from planning, removed transaction-control commands from indexing, isolated critical analytics callbacks, verified analytics schema initialization, guarded malformed settings, and made release packaging use an explicit production-file allowlist. These tests used the official PHP/SQLite Playground runtime; the actual merchant's MySQL/MariaDB server, theme, extensions, traffic, multisite configuration, and email transport remain untested.

## Estimated history indication 1.5.1

Estimated history now has a lighter green solid stroke (`#568570`) and fill (`#eff5f1`), while recorded history retains its dark green solid stroke (`#176b50`) and fill (`#e7f2eb`). An Estimated history label and range guide sit above the reconstructed segment; Recorded history labels the measured segment where space permits. The explanatory note gives the recording start time, and estimated table rows say Estimated from orders.

Targeted browser verification on Everyday Tote with Year selected confirmed both history lines remain solid, the estimated range ends at the first recorded stock observation, and the forecast remains dashed. Browser warnings/errors were empty. JavaScript syntax and rebuilt ZIP integrity/source correspondence passed. No forecasting logic changed; the integration suites were not rerun for this presentation update.

Screenshot: `.local/estimated-history-shade.png`.


## Preload recovery 1.5.2

Fixed an empty-target startup condition: if the first preload was paused (for example, because the dashboard loaded in a hidden tab), the freshness check previously required an existing missing target before restarting. A failed next-page read could also be skipped forever once current-page graphs were warm. Eligible resume/freshness events now rebuild the queue from the current report and skip cached entries without delays or refetches. Native planning-dialog close events, including Escape, and online/connection recovery now resume warming. Settings, hidden tabs, indexing, saves, offline status, data saving and 2G continue to pause it.

Visible products warm before the next-page report so a slow report cannot delay them. Background reads have a 15-second deadline and settle cancellation even if the transport stalls. Selecting an in-flight graph removes its optional background deadline while reusing the request. Freshness checks have a ten-second deadline. Changed revisions wait for refreshed report data before warming again.

The hidden-startup regression failed against the prior code and passed after the fix. All 16 buffer checks and 13 dashboard-controller checks passed, covering recovery, retry, ordering, cancellation/timeout cleanup, foreground promotion, pause conditions, period switching, and stale responses. All 216 existing WordPress integration checks passed again (108 HPOS and 108 legacy); PHP parsing and JavaScript syntax passed. The four-profile safety matrix was not rerun because no analytics write path changed.

Targeted browser verification confirmed Escape resumed the queue (14 / 20 ready), page navigation preserved the selected graph, a preloaded Cotton Napkins graph opened correctly, Settings returned to the graph, and the nearby-page buffer completed (11 graphs ready on page 2; 20 on page 1). The saved 90-day reorder trigger was retained. Browser warning/error logs were empty. Screenshot: `.local/preloading-fixed.png`.


## Product-selection correctness 1.5.3

The prior controller highlighted the newly selected row while still rendering the old detail object. It drew the old graph during an uncached read and reverted to the previous selection after a failure. Even a cache hit rendered the old detail once before its resolved promise was applied. The prior preload suite replaced rendering with a no-op and did not cover this boundary.

The controller now removes the previous detail before switching. A matching cached payload is adopted synchronously before rendering; a miss displays a named loading panel without the prior SVG, quantities, dates, or planning controls. A failed read keeps the requested product selected and displays a Retry graph action. Transient network/server failures and mismatched payloads get at most one fresh foreground fallback. Authorization/not-found/rate-limit responses, cancellation and timeouts are not automatically retried. Selected reads have a 30-second deadline; selecting a pending preload replaces its 15-second optional deadline with a new foreground deadline and reuses the existing transport.

Buffer reads validate product identity, period, required chart structure, and revision. Invalid cached entries are discarded and do not count as ready. The render boundary independently requires matching selected identity/period/revision, and chart hosts carry product and period attributes checked before drawing or resizing. Sequence checks discard responses from older selections. No artificial waiting was added to valid cache hits, and no order/product/stock write path changed.

A new shared dashboard harness renders the real overview/detail/list markup against deterministic network fixtures. The regression reproduced the old Product 1 chart while Product 2 was selected before the fix. Fourteen selection checks now cover cold/warm rendering, zero extra requests for a cache hit, foreground fallback, manual retry, mismatched products/periods/revisions, rapid clicks, period/report failures, late resize callbacks, deadlines, and promotion of a failed preload. The existing 17 buffer and 13 preload checks also pass. All 216 WordPress checks passed again across HPOS and legacy storage; PHP parsing and JavaScript syntax passed. The historical four-profile safety matrix was not rerun for this frontend-only change.

Browser verification: switching from Everyday Tote to buffered Ceramic Travel Cup produced title Ceramic Travel Cup, selected product ID 11 and graph product ID 11. Navigating to page 3 retained the selected cup graph until Wool Desk Mat was selected. That switch produced selected/graph product ID 80 and different SVG path data. Browser checks cover real rendering; deterministic fixtures cover delayed/failing transports. The saved reorder trigger remained 90 days. Screenshot: `.local/product-selection-fixed.png`.


## Stable graph snapshots and explicit refresh 1.5.4

Reviewed October 27, 2026. Preload responses and freshness checks previously treated every changed global revision as a reason to clear the session buffer and automatically reload settings, rankings and the selected graph. Repeated stock/order changes or first stock observations could repeatedly trigger that path. An older in-flight freshness response could also rewind the cache token. The new regression reproduced three unnecessary selected-graph reloads after three background revision changes against 1.5.3, and zero after the fix.

The displayed graph is now an identified product/period snapshot. Background revisions invalidate reusable caches and show an Updates available indicator beside a labelled Refresh button; they do not clear, redraw or reload the displayed graph. Click Refresh to fetch current settings, list and graph. An explicit settings/planning save or reindex still reloads its resulting analysis. Switching products/history still uses the loading/error/identity safeguards introduced in 1.5.3. The indicator makes a newer available revision visible without interrupting graph inspection.

Refresh actions share one in-flight operation and disable the button while pending. Repeated clicks on a displayed or loading product reuse its view/read. Foreground snapshot paths are excluded from nearby warming on both current and next ranking pages. Focus and interval checks share a 30-second throttle; failed checks have a 15-second cooldown. Checks started against an older buffer generation/revision are ignored, and manual Refresh aborts the previous check. SVG drawing stores a key for data, horizon and dimensions, avoiding identical DOM writes from repeated ResizeObserver callbacks. Header actions wrap at constrained widths.

All 11 new stability checks passed, including successive revisions, explicit refresh and acknowledgement, same-product clicks, focus bursts, stale-response ordering, concurrent refreshes, 20 simulated idle minutes, next-page warming, in-flight clicks, failure cooldowns and 100 resize notifications producing one SVG write. Changing the horizon or width still redraws. The existing 44 JavaScript checks and all 216 WordPress integration checks passed again. PHP parsing, JavaScript syntax and ZIP integrity/source correspondence passed. The historical four-profile safety matrix was not rerun; no product/order/stock write path changed.

Real-browser verification temporarily changed the local reorder setting from 90 to 89 days in a second view. The main view retained exactly the same SVG render key and its 90-day snapshot while displaying Updates available. Clicking Refresh loaded the 89-day values and cleared the indicator. Restoring 90 in the second view and refreshing the first restored the original setting; the graph remained present, and browser warning/error logs were empty. The 20-minute test used a deterministic simulated clock, not a 20-minute production observation. Screenshot: `.local/stable-cache-update-notice.png`.


## Order details on stock changes 1.6.0

Hover a vertical stock-history change to inspect its recorded changes or contributing historical orders. The popover includes order number, quantity, date/time, and an edit-order link when available. It also opens by tapping or keyboard focus/Enter. Left/right keys move through changes, Escape/Close dismisses it, and dense intervals have twenty-row pages. Hover targets have nonzero hit areas; the chart is an accessible group with roving keyboard focus. Popovers remain hidden until requested.

Schema version 2 adds nullable previous quantity and default-zero order/item IDs to the existing plugin-owned stock-history table. Existing observations retain their quantities, depletion and unknown associations. New WooCommerce order-item stock reductions can annotate only an exact, unique before/after observation captured in that PHP request. Ambiguous, missing or failed captures stay unlinked. The standard per-item restore hook also links increases on WooCommerce 9.1+; unsupported/manual changes remain unlinked. The callbacks use the existing failure-isolation boundary and never save WooCommerce products, orders or stock. Checkout stock reduction still executes once if the annotation update fails.

The graph remains sampled and bounded. A hover interval queries all underlying observations between its timestamp/ID boundaries, including same-second events, rather than falsely assigning an aggregated dip to one order. Estimated history queries the existing order index for the shared stock pool and reports order-derived quantities by date. These are contributing orders for the estimated interval, not proof of recorded historical inventory or unknown restocks. Older recorded events without an order ID explicitly say no association was recorded; there is no time-based guessing.

The new endpoint requires analytics access plus order-edit permission, returns no customer names, email or addresses, and hydrates at most twenty changes/orders per page. It performs no source-order writes. Requests are deferred until inspection (150ms pointer debounce), cancelled on exit/product changes/hidden tabs, cached for sixty seconds with a twenty-four-interval bound, and prioritized over optional graph warming. Graph preloads contain no hydrated order details. Responses for the wrong product or a different graph revision are rejected or ask the user to Refresh, preserving the stable snapshot behavior from 1.5.4.

Seventeen additional integration checks ran in each order-storage mode, including real stock-reduction hooks, exact line associations, shared inventory, manual and ambiguous changes, restoration, annotation-write failure, read-only payloads, authorization, pagination, and a real old-table migration preserving prior rows. Nine new JavaScript checks cover lazy/cached/expired reads, cancellation, revision and identity safeguards, escaping, bounded memory, and interval boundaries. All prior JavaScript checks passed. The four preservation audits now include an order-hover read inside protected-table SQL observation and whole-store hashes; all passed.

Browser verification used Everyday Tote. A recorded dip displayed its exact 224 → 217 quantity change and an explicit unlinked notice. With Year selected, an estimated dip displayed Order #70, 40 units ordered on November 3, 2025, and its WooCommerce edit link. Keyboard Enter opened both panels and Escape dismissed them; browser warning/error logs were empty. The saved 90-day reorder trigger was retained. Screenshot: `.local/order-hover.png`.
