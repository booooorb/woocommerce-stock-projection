/* global VeryShopAnalysis, VeryShopReadBuffer */
(() => {
  'use strict';
  const root = document.getElementById('veryshop-analysis');
  if (!root || !window.VeryShopAnalysis) return;
  const cfg = window.VeryShopAnalysis;
  const periods = [['30', 'Last month'], ['90', '3 months'], ['180', '6 months'], ['365', 'Year'], ['all', 'All time']];
  const state = { period: '90', search: '', page: 1, report: null, detail: null, selected: null, detailTarget: null, detailError: '', planningOpen: false, planningDraft: null, updatesAvailable: false, refreshing: false, tab: 'overview', horizon: 30, detailLoading: false, loading: true, indexing: false, busy: false, settings: null, settingsDraft: null, reorderDraft: null, error: '', notice: '', preview: null };
  let reportSeq = 0, detailSeq = 0, searchTimer, observer;
  let activeReportPath, activeDetailPath, preloadTimer, preloadSeq = 0, preloadQueue = [], preloadTargets = [], preloadRunning = false, checkingFreshness = false;
  let refreshInFlight, detailInFlight, freshnessController, lastFreshnessAt = -Infinity;
  let historyHover = null, historyTimer, historyHideTimer, historyController, historySequence = 0;
  const historyCache = new Map();
  const buffer = new VeryShopReadBuffer((path, signal) => api(path, undefined, signal), { onRevision: markUpdatesAvailable, validate: matchesRead });
  const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const number = (value, digits = 0) => new Intl.NumberFormat(cfg.locale, { maximumFractionDigits: digits }).format(Number(value) || 0);
  const rate = value => number(value, value > 0 && value < 0.01 ? 4 : 2);
  const date = value => value ? new Intl.DateTimeFormat(cfg.locale, { month: 'short', day: 'numeric', year: 'numeric', timeZone: 'UTC' }).format(new Date(`${value}T12:00:00Z`)) : '—';
  const icon = name => `<span class="dashicons dashicons-${name}" aria-hidden="true"></span>`;
  const button = (action, text, primary = false, disabled = false) => `<button type="button" class="vpa-button${primary ? ' vpa-primary' : ''}" data-action="${action}"${disabled ? ' disabled' : ''}>${text}</button>`;

  async function api(path, body, signal) {
    const response = await fetch(`${cfg.api}${path}`, { credentials: 'same-origin', cache: 'no-store', signal, headers: { 'X-WP-Nonce': cfg.nonce, ...(body !== undefined ? { 'Content-Type': 'application/json' } : {}) }, method: body !== undefined ? 'POST' : 'GET', ...(body !== undefined ? { body: JSON.stringify(body) } : {}) });
    let payload;
    try { payload = await response.json(); } catch (_) { throw new Error('The server returned an unreadable response. Check the WordPress error log, then refresh.'); }
    if (!response.ok) {
      const error = new Error(payload.message || `Request failed (${response.status}).`);
      error.status = response.status;
      throw error;
    }
    return payload;
  }

  const reportPath = (page = state.page) => `report?${new URLSearchParams({ period: state.period, search: state.search, page: String(page), per_page: '10' })}`;
  const detailPath = id => `products/${id}?period=${state.period}`;
  function matchesDetail(detail, id, period) {
    return Number(detail?.product?.id) === Number(id) && detail?.period === period && !!detail?.revision && !!detail?.window && Array.isArray(detail?.stock_history?.recorded) && Array.isArray(detail?.stock_history?.reconstructed);
  }
  function matchesRead(path, value) {
    if (path.startsWith('report?')) return !!value?.revision && Array.isArray(value?.rows);
    const match = path.match(/^products\/(\d+)\?period=([^&]+)$/);
    return !!match && matchesDetail(value, match[1], match[2]);
  }
  function displayedDetail() {
    // A displayed graph is a snapshot. Cache invalidation must never replace it or blank it.
    return !state.detailLoading && !state.detailError && matchesDetail(state.detail, state.selected, state.period) ? state.detail : null;
  }
  const canPreload = () => !historyHover && state.tab === 'overview' && document.visibilityState === 'visible' && !state.planningOpen && state.report?.sync?.complete && !state.loading && !state.detailLoading && !state.refreshing && !state.indexing && !state.busy && navigator.onLine !== false && !navigator.connection?.saveData && !['slow-2g', '2g'].includes(navigator.connection?.effectiveType);

  function pausePreload(except) {
    preloadSeq++;
    clearTimeout(preloadTimer);
    preloadQueue = [];
    preloadRunning = false;
    buffer.pause(except);
  }

  function bufferStatus() {
    const ready = preloadTargets.filter(path => buffer.peek(path)).length;
    return preloadRunning || preloadQueue.length ? `Preloading graphs · ${ready} / ${preloadTargets.length} ready` : ready ? `${ready} ${ready === 1 ? 'graph' : 'graphs'} ready` : '';
  }

  function updateBufferStatus() {
    const label = document.getElementById('vpa-buffer-status');
    if (label) label.textContent = bufferStatus();
  }

  function startPreload() {
    pausePreload();
    if (!canPreload()) { updateBufferStatus(); return; }
    const sequence = preloadSeq;
    const period = state.period;
    const current = state.report.rows.map(row => `products/${row.id}?period=${period}`);
    preloadTargets = [...current];
    preloadQueue = current.filter(path => !displayedDetail() || path !== detailPath(state.selected));
    // Visible products must not wait for an uncached or slow next-page report.
    if (state.report.page < state.report.pages) preloadQueue.push(reportPath(state.report.page + 1));
    updateBufferStatus();
    preloadTimer = setTimeout(() => pumpPreload(sequence, period), 180);
  }

  function addPreloadPage(path, value, period) {
    if (!path.startsWith('report?')) return;
    const next = value.rows.map(row => `products/${row.id}?period=${period}`).filter(path => !preloadTargets.includes(path));
    preloadTargets.push(...next);
    preloadQueue.push(...next.filter(path => !displayedDetail() || path !== detailPath(state.selected)));
  }

  async function pumpPreload(sequence, period) {
    if (sequence !== preloadSeq || !canPreload()) return;
    let path;
    // Skip already-warm entries without delaying every graph again on resume.
    while ((path = preloadQueue.shift())) {
      const cached = buffer.peek(path);
      if (!cached) break;
      addPreloadPage(path, cached, period);
    }
    if (!path) { updateBufferStatus(); return; }
    preloadRunning = true;
    updateBufferStatus();
    try {
      const value = await buffer.get(path, { background: true });
      if (sequence !== preloadSeq) return;
      addPreloadPage(path, value, period);
    } catch (_) { /* Optional warming never blocks a selected product or raises an alert. */ }
    finally {
      if (sequence === preloadSeq) {
        preloadRunning = false;
        updateBufferStatus();
        if (preloadQueue.length) preloadTimer = setTimeout(() => pumpPreload(sequence, period), 180);
      }
    }
  }

  function markUpdatesAvailable() {
    if (!state.detail || state.refreshing) return;
    state.updatesAvailable = true;
    const label = document.getElementById('vpa-update-status');
    if (label) { label.hidden = false; label.textContent = 'Updates available'; }
  }

  function refreshData() {
    if (refreshInFlight) return refreshInFlight;
    state.refreshing = true;
    pausePreload(); freshnessController?.abort(); buffer.clear(); render();
    refreshInFlight = (async () => {
      let settingsRead = false;
      try { state.settings = await api('settings'); settingsRead = true; }
      catch (error) { state.error = error.message; }
      await loadReport(true);
      if (settingsRead && displayedDetail()?.revision === buffer.revision && state.report?.revision === buffer.revision) state.updatesAvailable = false;
    })().finally(() => {
      state.refreshing = false; refreshInFlight = null; render(); startPreload();
    });
    return refreshInFlight;
  }

  async function checkFreshness({ force = false } = {}) {
    if (checkingFreshness || (!force && Date.now() - lastFreshnessAt < 30000) || document.visibilityState !== 'visible' || state.planningOpen || state.refreshing || state.busy || state.indexing || state.loading || state.detailLoading) return;
    checkingFreshness = true;
    lastFreshnessAt = Date.now();
    let changed = false;
    const controller = new AbortController();
    freshnessController = controller;
    const generation = buffer.generation, revision = buffer.revision;
    const timeout = setTimeout(() => controller.abort(), 10000);
    try {
      const status = await api('sync', undefined, controller.signal);
      // A response started before a newer graph/read/refresh cannot rewind the cache.
      if (generation === buffer.generation && revision === buffer.revision) changed = buffer.confirm(status.revision);
    } catch (_) { lastFreshnessAt = Date.now() - 15000; /* Retry transient check failures after a short cooldown. */ }
    finally {
      clearTimeout(timeout);
      checkingFreshness = false;
      // Targets can be empty if the page initially loaded while hidden or paused.
      // Rebuild from the report so failed next-page reads also get another chance.
      if (!changed && !preloadRunning && canPreload()) startPreload();
    }
  }

  function render() {
    closeHistoryHover();
    const focused = root.contains(document.activeElement) ? document.activeElement.id : null;
    const selection = focused === 'vpa-search' ? document.activeElement.selectionStart : null;
    const oldList = root.querySelector('.vpa-list-rows');
    const listPosition = oldList ? { key: oldList.dataset.listKey, top: oldList.scrollTop } : null;
    const modelOpen = root.querySelector('.vpa-chart-data')?.open;
    observer?.disconnect();
    root.innerHTML = `
      <header class="vpa-header">
        <h1>Stock analysis</h1>
        <div class="vpa-header-actions"><span id="vpa-update-status" class="vpa-update-status" role="status" ${state.updatesAvailable ? '' : 'hidden'}>${state.updatesAvailable ? 'Updates available' : ''}</span>${button('refresh', icon('update') + `<span>${state.refreshing ? 'Refreshing…' : 'Refresh'}</span>`, false, state.refreshing || state.loading || state.indexing || state.busy)}<button id="vpa-tab-${state.tab === 'settings' ? 'overview' : 'settings'}" class="vpa-button" type="button" data-tab="${state.tab === 'settings' ? 'overview' : 'settings'}" ${state.tab === 'settings' ? '' : 'aria-label="Settings & emails"'}>${state.tab === 'settings' ? 'Back to graph' : 'Settings'}</button></div>
      </header>
      ${state.error ? `<div class="vpa-notice vpa-error" role="alert">${icon('warning')}<span>${esc(state.error)}</span>${button('dismiss-error', 'Dismiss')}</div>` : ''}
      ${state.notice ? `<div class="vpa-notice" role="status">${icon('yes-alt')}<span>${esc(state.notice)}</span>${button('dismiss-notice', 'Dismiss')}</div>` : ''}
      ${syncBanner()}
      ${state.tab === 'settings' ? settingsView() : overview()}
      `;
    const newList = root.querySelector('.vpa-list-rows');
    if (listPosition && newList?.dataset.listKey === listPosition.key) newList.scrollTop = listPosition.top;
    if (modelOpen && root.querySelector('.vpa-chart-data')) root.querySelector('.vpa-chart-data').open = true;
    if (focused) {
      const target = document.getElementById(focused);
      if (target) {
        target.focus({ preventScroll: true });
        if (selection !== null && target.setSelectionRange) target.setSelectionRange(selection, selection);
      }
    }
    const chart = root.querySelector('#vpa-graph');
    if (chart && displayedDetail()) {
      drawChart(chart);
      observer = new ResizeObserver(() => drawChart(chart));
      observer.observe(chart);
    }
  }

  function syncBanner() {
    const sync = state.report?.sync;
    if (!state.indexing && (!sync || sync.complete)) return '';
    const percent = sync.total ? Math.round(sync.processed / sync.total * 100) : 0;
    return `<div class="vpa-sync" role="status"><span>${icon('update')} Indexing order history <strong>${number(sync?.processed || 0)}${sync?.total ? ` / ${number(sync.total)}` : ''}</strong></span><span>Rankings update when indexing finishes.</span><progress max="100" value="${percent}" aria-label="Order history indexing"></progress></div>`;
  }

  function overview() {
    const report = state.report;
    if (!report) return `<div class="vpa-empty" role="status">${state.error ? 'Stock analysis could not be loaded.' : 'Loading inventory…'}${state.error ? button('refresh', 'Try again', true) : ''}</div>`;
    return `<div class="vpa-overview"><div id="vpa-controls" class="vpa-toolbar">
      <label class="vpa-period-control">History<select id="vpa-period-select" aria-label="Stock history period">${periods.map(([value, label]) => `<option value="${value}" ${state.period === value ? 'selected' : ''}>${label}</option>`).join('')}</select></label>
      <label class="vpa-period-control">Projection<select id="vpa-horizon-select" aria-label="Future projection period">${horizonOptions()}</select></label>
      <form id="vpa-reorder-form" class="vpa-reorder-control"><label for="vpa-reorder-days">Reorder before stockout<span class="vpa-reorder-input"><input id="vpa-reorder-days" name="reorder_days" type="number" min="0" max="3650" step="1" placeholder="Enter days" value="${esc(state.reorderDraft ?? state.settings?.settings.reorder_days ?? '')}" required ${state.busy || !state.settings ? 'disabled' : ''}><span>days</span></span></label><button type="submit" class="vpa-button" ${state.busy || !state.settings ? 'disabled' : ''}>Save</button></form>
      <span class="vpa-date-range">${date(report.window.start)} – ${date(report.window.end)}</span></div>
      <div class="vpa-workspace">${detailView()}${productListView()}</div></div>`;
  }

  function detailView() {
    const d = displayedDetail();
    if (!d) {
      const target = state.detailTarget || state.report?.rows.find(row => row.id === state.selected);
      const name = target?.name || (state.selected ? `Product #${state.selected}` : 'product');
      const empty = !state.selected && !state.report?.total;
      return `<section class="vpa-detail" id="vpa-detail" aria-busy="${!state.detailError && !empty}"><div class="vpa-panel vpa-chart-panel"><div class="vpa-stock-heading"><div><h2 id="vpa-selected-title">${esc(empty ? 'Stock history' : name)}</h2>${target?.sku ? `<span class="vpa-muted">${esc(target.sku)}</span>` : ''}</div></div><div class="vpa-graph-placeholder" role="${state.detailError ? 'alert' : 'status'}"><strong>${esc(empty ? 'No products to analyse yet.' : state.detailError ? `Could not load stock history for ${name}.` : `Loading stock history for ${name}…`)}</strong>${state.detailError ? `<p>${esc(state.detailError)}</p>${button('retry-detail', 'Retry graph', true)}` : empty ? '<p>Add products in WooCommerce, then refresh.</p>' : ''}</div></div></section>`;
    }
    const p = d.product, history = d.stock_history;
    const reordered = p.reorder_in !== null && p.reorder_in <= 0;
    const overdue = overdueInfo(p, history.today, history);
    const reconstructed = history.reconstructed.length > 0;
    const rows = [...history.reconstructed.map(point => ({ ...point, source: 'Estimated from orders' })), ...history.recorded.map(point => ({ ...point, source: 'Recorded' }))];
    const note = reconstructed ? `History before ${moment(history.recorded_from)} is estimated from orders, not recorded stock. Earlier restocks are unknown.` : history.recorded_from > history.start ? 'Stock recording started within this period. Earlier levels were not recorded.' : '';
    return `<section class="vpa-detail" id="vpa-detail" aria-busy="${state.detailLoading}"><div class="vpa-panel vpa-chart-panel">
      <div class="vpa-stock-heading"><div><h2 id="vpa-selected-title">${esc(p.name)}</h2><span class="vpa-muted">${esc(p.sku || 'No SKU')}${p.shared_stock ? ' · shared inventory' : ''}</span></div><div class="vpa-stock-actions"><button type="button" class="vpa-button vpa-plan-button" data-action="open-planning" ${p.stock === null ? 'disabled' : ''}>${icon('cart')} Restock Planning</button><div class="vpa-current-stock"><strong>${p.stock === null ? '—' : number(p.stock, 2)}</strong><span>units now</span></div></div></div>
      ${state.planningOpen ? planningForm() : ''}
      <div id="vpa-graph" class="vpa-graph" data-product="${p.id}" data-period="${d.period}"></div>
      <div class="vpa-chart-caption"><div class="vpa-legend"><span><i class="vpa-line-key"></i> Stock history</span><span><i class="vpa-line-key vpa-dashed-key"></i> Projection</span><span><i class="vpa-line-key vpa-reorder-key"></i> Reorder level</span><span id="vpa-restock-legend">${restockProjection(p) ? '<i class="vpa-line-key vpa-restock-key"></i> Restock' : ''}</span></div></div><div id="vpa-restock-summary">${restockSummary(p)}</div>
      ${note ? `<p class="vpa-history-note">${esc(note)}</p>` : ''}
      <dl class="vpa-inline-facts"><div><dt>Depletion</dt><dd>${rate(p.rate)} / day</dd></div><div><dt>Days left</dt><dd>${p.days_remaining === null ? '—' : number(p.days_remaining, 1)}</dd></div><div class="${reordered && !p.planning.on_order ? 'vpa-fact-overdue' : ''}"><dt>Reorder</dt><dd>${p.planning.on_order ? 'Ordered' : overdue ? date(offsetDate(0, overdue.time)) : date(p.reorder_date)}</dd></div><div><dt>Current stock-out</dt><dd>${p.stock !== null && p.stock <= 0 ? 'Out now' : date(p.stockout_date)}</dd></div></dl>
      <div class="vpa-detail-actions"><details class="vpa-chart-data"><summary>History data & model</summary><p>${p.stock === null ? 'Inventory quantities are required to calculate a stock projection.' : `<code>Stock(t) = ${number(p.stock, 2)} − ${rate(p.rate)}t</code>, where t is days from today.`} Depletion uses ${p.rate_source === 'stock_changes' ? `a linear fit to recorded stock decreases over ${number(p.rate_history_days || d.window.days)} days; incoming restocks do not count as depletion` : 'a linear fit to order-derived outflow until stock recording covers the full selected period'}.${p.shared_stock ? ` This includes all variations sharing inventory with ${esc(p.stock_owner_name)}.` : ''} Future stock is floored at zero; existing negative stock remains visible as a deficit. Incoming deliveries are reflected after stock quantities are updated.</p><p>Reorder ${number(p.planning.reorder_days)} days before projected stockout. Reorder level = ${rate(p.rate)} units/day × ${number(p.planning.reorder_days)} days = ${number(p.reorder_point, 2)} units.${p.planning.on_order ? ` Expected delivery: ${date(p.planning.arrival)}.` : ''} Historical reconstruction assumes no unknown earlier replenishments or adjustments. Store timezone: ${esc(cfg.timezone || 'UTC')}.</p><div class="vpa-scroll"><table><caption>Stock observations; long histories preserve first, lowest, highest and last levels per time interval.</caption><thead><tr><th>Time</th><th>Stock</th><th>Source</th></tr></thead><tbody>${rows.map(point => `<tr><td>${esc(moment(point.time))}</td><td>${number(point.stock, 2)}</td><td>${point.source}</td></tr>`).join('')}</tbody></table></div></details></div>
      </div></section>`;
  }

  function productListView() {
    const report = state.report;
    return `<aside class="vpa-panel vpa-product-browser" aria-labelledby="vpa-products-heading" aria-busy="${state.loading}"><div class="vpa-browser-header"><div><h2 id="vpa-products-heading">Products <span class="vpa-count">${number(report.total)}</span></h2><span id="vpa-buffer-status" class="vpa-muted" aria-live="off">${esc(bufferStatus())}</span></div><label class="vpa-search">${icon('search')}<span class="screen-reader-text">Search products or SKUs</span><input id="vpa-search" type="search" value="${esc(state.search)}" placeholder="Search products or SKU…" autocomplete="off"></label><div class="vpa-list-labels"><span>Product</span><span>Stock</span></div></div>
      <ul class="vpa-list-rows" data-list-key="${esc(`${state.period}|${state.search}|${report.page}`)}" aria-label="Products">${report.rows.length ? report.rows.map(row => `<li><button id="vpa-product-${row.id}" type="button" class="vpa-product-choice" data-product="${row.id}" aria-pressed="${row.id === state.selected}" aria-label="Show stock history for ${esc(row.name)}" ${state.loading ? 'disabled' : ''}><span class="vpa-product-rank">${number(row.rank)}</span><span class="vpa-product-name"><strong title="${esc(row.name)}">${esc(row.name)}</strong><small>${esc(row.sku || 'No SKU')}</small></span><span class="vpa-product-quantity vpa-quantity-${esc(row.status)}"><strong>${row.stock === null ? '—' : number(row.stock, 2)}</strong><small>${row.planning.on_order ? `Ordered · ${date(row.planning.arrival)}` : row.days_remaining === null ? (row.stock === null ? 'Untracked' : 'No usage') : `${number(row.days_remaining, 1)} days left`}</small></span></button></li>`).join('') : '<li class="vpa-no-results">No matching products.</li>'}</ul>
      <form id="vpa-page-form" class="vpa-list-pagination">${button('previous', '<span aria-hidden="true">←</span><span class="screen-reader-text">Previous products page</span>', false, report.page <= 1 || state.loading)}<label>Page <input id="vpa-page-input" aria-label="Products page" type="number" min="1" max="${report.pages}" value="${report.page}" required> of ${report.pages}</label><button type="submit" class="vpa-button" ${state.loading ? 'disabled' : ''}>Go</button>${button('next', '<span aria-hidden="true">→</span><span class="screen-reader-text">Next products page</span>', false, report.page >= report.pages || state.loading)}</form>
    </aside>`;
  }

  function moment(timestamp, short = false) {
    const options = { month: 'short', day: 'numeric', ...(short ? {} : { year: 'numeric', hour: 'numeric', minute: '2-digit' }), timeZone: cfg.timezone || 'UTC' };
    try { return new Intl.DateTimeFormat(cfg.locale, options).format(new Date(timestamp * 1000)); }
    catch (_) { return new Intl.DateTimeFormat('en', { ...options, timeZone: 'UTC' }).format(new Date(timestamp * 1000)); }
  }

  function offsetDate(days, now) {
    if (days === null || !Number.isFinite(days) || Math.abs(days) > 36500) return null;
    let timezone = cfg.timezone || 'UTC';
    let timestamp = now + days * 86400;
    const fixedOffset = timezone.match(/^([+-])(\d{2}):(\d{2})$/);
    if (fixedOffset) {
      timestamp += (fixedOffset[1] === '+' ? 1 : -1) * (Number(fixedOffset[2]) * 60 + Number(fixedOffset[3])) * 60;
      timezone = 'UTC';
    }
    const parts = new Intl.DateTimeFormat('en', { year: 'numeric', month: '2-digit', day: '2-digit', timeZone: timezone }).formatToParts(new Date(timestamp * 1000));
    const fields = Object.fromEntries(parts.map(part => [part.type, part.value]));
    return `${fields.year}-${fields.month}-${fields.day}`;
  }

  function settingsView() {
    if (!state.settings) return '<div class="vpa-empty">Loading settings…</div>';
    const s = { ...state.settings.settings, ...state.settingsDraft };
    return `<form id="vpa-settings-form" class="vpa-settings"><section class="vpa-panel vpa-settings-section"><div><div class="vpa-section-kicker">EMAIL NOTIFICATIONS</div><h2>Email alerts</h2><p>One email lists products at or past their saved reorder date, including out-of-stock products.</p></div><div class="vpa-fields"><label class="vpa-checkbox"><input name="email_enabled" type="checkbox" ${s.email_enabled ? 'checked' : ''}><span>Enable automatic stock emails</span></label><label>Recipients<input name="recipients" type="text" value="${esc(s.recipients)}" required placeholder="you@example.com, team@example.com"><small>Separate addresses with commas. Emails use your WordPress mail configuration.</small></label><div class="vpa-field-grid"><label>History used for alerts<select name="alert_period">${periods.map(([value, label]) => `<option value="${value}" ${s.alert_period === value ? 'selected' : ''}>${label}</option>`).join('')}</select></label><label>Send stock email every<span class="vpa-input-unit"><input name="email_frequency_days" type="number" min="1" max="3650" step="1" value="${s.email_frequency_days}" required><span>days</span></span></label></div><p class="vpa-helper">Reorder trigger: ${number(s.reorder_days)} days before stockout, saved above the graph. Confirmed restock orders pause stock warnings and emails until you clear the plan.</p></div></section>
      <div class="vpa-settings-actions"><button class="vpa-button vpa-primary" type="submit" ${state.busy ? 'disabled' : ''}>Save settings</button>${button('preview-alerts', 'Preview email', false, state.busy)}${button('run-alerts', 'Send email now', false, state.busy)}${button('reindex', 'Reindex orders', false, state.indexing || state.busy)}<span class="vpa-muted">Save changes before checking alerts.</span></div></form>
      ${s.email_enabled && state.settings.next_email_at ? `<p class="vpa-last-check">Next scheduled digest: ${esc(moment(state.settings.next_email_at))}${state.settings.next_email_at <= Date.now() / 1000 ? ' · due at the next scheduled check' : ''}</p>` : ''}
      ${state.settings.last_alert_run?.next_email_at ? `<p class="vpa-last-check">Last check: ${esc(state.settings.last_alert_run.message)}</p>` : ''}
      ${state.preview ? `<section class="vpa-panel vpa-email-preview"><div class="vpa-panel-heading"><div><div class="vpa-section-kicker">EMAIL PREVIEW · ${state.preview.products} PRODUCTS</div><h2>What the update will look like</h2></div>${button('close-preview', 'Close')}</div>${!state.preview.index_complete ? '<p class="vpa-attention">Order indexing is incomplete; this preview may be partial.</p>' : ''}<pre>${esc(state.preview.message)}</pre><p class="vpa-muted">Preview includes all products currently due. Automatic emails follow the saved frequency; Send email now starts a new interval.</p></section>` : ''}`;
  }

  function planningArrival(p) {
    if (!p) return '';
    if (p.planning?.arrival) return p.planning.arrival;
    if (p.stock === null) return '';
    const now = Date.now() / 1000;
    return p.stock <= 0 ? offsetDate(0, now) : p.stockout_date || offsetDate(p.days_remaining, now) || '';
  }

  function planningForm() {
    const p = state.detail?.product;
    if (!p) return '';
    const draft = state.planningDraft || { quantity: p.planning.quantity || '', arrival: planningArrival(p) };
    return `<form id="vpa-planning-form" class="vpa-inline-planning" aria-label="Restock Planning"><label>Quantity<input name="quantity" type="number" min="0.0001" max="1000000000" step="0.0001" value="${esc(draft.quantity)}" required ${state.busy ? 'disabled' : ''}></label><label>Arrival<input name="arrival" type="date" min="${offsetDate(0, Date.now() / 1000)}" value="${esc(draft.arrival)}" required ${state.busy ? 'disabled' : ''}></label><div class="vpa-planning-actions"><button type="submit" class="vpa-button vpa-plan-button" ${state.busy ? 'disabled' : ''}>Confirm order</button>${button('close-planning', 'Cancel', false, state.busy)}${p.planning.on_order ? button('clear-planning', 'Clear plan', false, state.busy) : ''}</div></form>`;
  }

  function storeDateTimestamp(value) {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(value || '')) return null;
    const target = Date.parse(`${value}T00:00:00Z`);
    if (!Number.isFinite(target) || new Date(target).toISOString().slice(0, 10) !== value) return null;
    const zone = cfg.timezone || 'UTC';
    const fixed = zone.match(/^([+-])(\d{2}):(\d{2})$/);
    if (fixed) return target / 1000 - (fixed[1] === '+' ? 1 : -1) * (Number(fixed[2]) * 60 + Number(fixed[3])) * 60;
    let result = target;
    for (let i = 0; i < 3; i++) {
      const parts = new Intl.DateTimeFormat('en', { timeZone: zone, year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23' }).formatToParts(new Date(result));
      const fields = Object.fromEntries(parts.map(part => [part.type, part.value]));
      const local = Date.UTC(Number(fields.year), Number(fields.month) - 1, Number(fields.day), Number(fields.hour) % 24, Number(fields.minute), Number(fields.second));
      result += target - local;
    }
    return result / 1000;
  }

  function buildRestockProjection(stock, rate, quantity, placedAt, arrivalAt) {
    if (stock === null || ![stock, rate, quantity, placedAt, arrivalAt].every(Number.isFinite) || quantity <= 0 || quantity > 1e9) return null;
    rate = Math.max(0, rate); arrivalAt = Math.max(placedAt, arrivalAt);
    const before = stock <= 0 ? stock : Math.max(0, stock - rate * (arrivalAt - placedAt) / 86400);
    const after = before + quantity;
    const coverage = after <= 0 ? 0 : rate > 0.00000001 ? after / rate : null;
    return { stock_at_order: stock, rate, quantity, placed_at: placedAt, arrival_at: arrivalAt, before, after, coverage_days: coverage,
      stockout_at: coverage !== null && coverage > 0 && coverage <= 36500 ? arrivalAt + Math.round(coverage * 86400) : null };
  }

  function restockProjection(p) {
    if (state.planningOpen && state.planningDraft) {
      const quantity = Number(state.planningDraft.quantity), arrivalAt = storeDateTimestamp(state.planningDraft.arrival);
      if (quantity > 0 && arrivalAt !== null) {
        const projection = buildRestockProjection(p.stock, p.rate, quantity, state.planningDraft.placed_at || Math.floor(Date.now() / 1000), arrivalAt);
        if (projection) return { ...projection, confirmed: false, arrival: state.planningDraft.arrival };
      }
    }
    return p.planning?.confirmed && p.planning.projection ? { ...p.planning.projection, confirmed: true, arrival: p.planning.arrival } : null;
  }

  function chartHorizon(p) {
    if (state.horizon !== 'plan') return Number(state.horizon) || 30;
    const plan = restockProjection(p);
    if (!plan) return 30;
    const end = plan.stockout_at || plan.arrival_at + 30 * 86400;
    return Math.min(36500, Math.max(30, Math.ceil((end - Date.now() / 1000) / 86400) + 5));
  }

  function horizonOptions() {
    const plan = state.detail && restockProjection(state.detail.product);
    return [30, 60, 90, 180].map(days => `<option value="${days}" ${state.horizon === days ? 'selected' : ''}>Next ${days} days</option>`).join('') + (plan ? `<option value="plan" ${state.horizon === 'plan' ? 'selected' : ''}>Restock timeline</option>` : '');
  }

  function restockSummary(p) {
    const plan = restockProjection(p);
    if (!plan) return '';
    const end = plan.stockout_at ? date(offsetDate((plan.stockout_at - Date.now() / 1000) / 86400, Date.now() / 1000)) : '—';
    return `<div class="vpa-restock-summary ${plan.confirmed ? 'vpa-restock-confirmed' : 'vpa-restock-preview'}"><span>${plan.confirmed ? 'Ordered' : 'Preview'}: ${number(plan.quantity, 4)} · ${date(plan.arrival)}</span><span>Coverage ${plan.coverage_days === null ? '—' : `${number(plan.coverage_days, 1)} days`} · Stock-out ${end}</span>${plan.confirmed && !state.planningOpen ? button('clear-planning', 'Clear plan', false, state.busy) : ''}</div>`;
  }

  function refreshPlanningPreview() {
    const p = displayedDetail()?.product;
    if (!p) return;
    const plan = restockProjection(p);
    state.horizon = plan ? 'plan' : state.horizon === 'plan' ? 30 : state.horizon;
    const select = document.getElementById('vpa-horizon-select'); if (select) select.innerHTML = horizonOptions();
    const summary = document.getElementById('vpa-restock-summary'); if (summary) summary.innerHTML = restockSummary(p);
    const legend = document.getElementById('vpa-restock-legend'); if (legend) legend.innerHTML = plan ? '<i class="vpa-line-key vpa-restock-key"></i> Restock' : '';
    const chart = document.getElementById('vpa-graph'); if (chart) drawChart(chart);
  }

  function restockPoints(plan, first, last) {
    const start = Math.max(first, plan.placed_at, plan.arrival_at);
    if (start > last) return [];
    const afterAt = time => plan.after <= 0 ? plan.after : Math.max(0, plan.after - plan.rate * (time - plan.arrival_at) / 86400);
    const end = plan.stockout_at ? Math.min(last, plan.stockout_at) : last;
    if (end < start) return [];
    const points = start === plan.arrival_at ? [{ time: start, stock: plan.before }, { time: start, stock: plan.after }] : [{ time: start, stock: afterAt(start) }];
    if (end > start) points.push({ time: end, stock: afterAt(end) });
    return points;
  }

  function projectionPoints(p, plan, now, last) {
    // One timeline: green up to delivery, blue from delivery onward.
    const end = plan ? Math.min(last, Math.max(now, plan.arrival_at)) : last;
    const current = [];
    if (end > now) {
      current.push({ time: now, stock: p.stock });
      const zero = p.rate > 0 && p.stock > 0 ? now + p.stock / p.rate * 86400 : null;
      if (zero && zero < end) current.push({ time: zero, stock: 0 });
      current.push({ time: end, stock: p.stock <= 0 ? p.stock : Math.max(0, p.stock - p.rate * (end - now) / 86400) });
    }
    return { current, current_end: end, restock: plan ? restockPoints(plan, now, last) : [] };
  }

  function overdueInfo(p, now, history = null) {
    if (p.planning.on_order || p.stock === null || p.rate <= 0 || !Number.isFinite(p.reorder_in) || p.reorder_in >= 0) return null;
    let time = now + p.reorder_in * 86400, fromStart = false;
    if (history && Number.isFinite(history.start) && history.start <= now) {
      const visible = [...history.reconstructed, ...history.recorded].filter(point => point.time >= history.start && point.time <= now);
      const throughout = visible.some(point => point.time === history.start) && visible.every(point => point.stock <= p.reorder_point + 0.00001);
      if (time < history.start || throughout) { time = history.start; fromStart = true; }
    }
    const whole = Math.floor((now - time) / 86400);
    return { time, stock: p.reorder_point, from_start: fromStart, label: whole < 1 ? 'Under 1 day overdue' : `${number(whole)} ${whole === 1 ? 'day' : 'days'} overdue` };
  }

  function drawChart(host) {
    const d = displayedDetail();
    if (!host.isConnected) return;
    if (!d || host.dataset.product !== String(d.product.id) || host.dataset.period !== d.period) { host.innerHTML = ''; return; }
    const p = d.product, history = d.stock_history;
    if (p.stock === null) {
      host.innerHTML = `<div class="vpa-chart-empty">${icon('chart-line')}<strong>Stock quantities are not tracked for this product.</strong><span>Enable inventory tracking in WooCommerce to view stock history.</span></div>`;
      return;
    }
    const plan = restockProjection(p), horizon = chartHorizon(p);
    const width = Math.max(1, host.clientWidth);
    const panel = host.closest('.vpa-chart-panel');
    const panelStyle = getComputedStyle(panel);
    const reserved = Array.from(panel.children).reduce((total, child) => {
      if (child === host) return total;
      const style = getComputedStyle(child);
      return total + child.getBoundingClientRect().height + (child.classList.contains('vpa-detail-actions') ? 0 : parseFloat(style.marginTop) || 0) + (parseFloat(style.marginBottom) || 0);
    }, (parseFloat(panelStyle.paddingTop) || 0) + (parseFloat(panelStyle.paddingBottom) || 0));
    const height = width < 480 ? 260 : Math.max(260, Math.min(560, (parseFloat(panelStyle.minHeight) || 0) - reserved));
    const renderKey = [p.id, d.period, d.revision, history.today, horizon, width, height, JSON.stringify(plan)].join('|');
    if (host.dataset.renderKey === renderKey) return;
    closeHistoryHover();
    const pad = { l: width < 480 ? 40 : 52, r: 16, t: 38, b: 32 }, w = width - pad.l - pad.r, h = height - pad.t - pad.b;
    const now = history.today, first = history.start, last = now + horizon * 86400;
    const projections = projectionPoints(p, plan, now, last), planned = projections.restock, overdue = overdueInfo(p, now, history);
    const values = [...history.recorded, ...history.reconstructed, ...planned].map(point => point.stock).concat([0, p.stock, p.reorder_point]);
    const rawMin = Math.min(0, ...values), rawMax = Math.max(1, ...values);
    const tickStep = niceStep((rawMax - rawMin) / 4);
    const minY = Math.floor(rawMin / tickStep) * tickStep, maxY = Math.ceil(rawMax / tickStep) * tickStep;
    const x = timestamp => pad.l + (timestamp - first) / Math.max(1, last - first) * w;
    const y = quantity => pad.t + h - (quantity - minY) / (maxY - minY || 1) * h;
    const path = (points, stepped = false) => points.map((point, i) => i ? stepped ? `H${x(point.time).toFixed(2)} V${y(point.stock).toFixed(2)}` : `L${x(point.time).toFixed(2)},${y(point.stock).toFixed(2)}` : `M${x(point.time).toFixed(2)},${y(point.stock).toFixed(2)}`).join(' ');
    const area = points => points.length ? `${path(points, true)} L${x(points[points.length - 1].time)},${y(0)} L${x(points[0].time)},${y(0)} Z` : '';
    const todayX = x(now);
    const tooltip = (label, pointX, pointY, stockout = false) => {
      const labelWidth = Math.min(w - 12, Math.max(78, label.length * 6 + 14));
      const labelX = Math.max(pad.l + 4, Math.min(width - pad.r - labelWidth - 4, pointX - labelWidth / 2));
      const labelY = pointY < pad.t + 34 ? pointY + 12 : pointY - 32;
      return `<g class="vpa-graph-tooltip" aria-hidden="true" fill="${stockout ? '#a34331' : '#946825'}"><rect x="${labelX}" y="${labelY}" width="${labelWidth}" height="22" rx="4" fill="${stockout ? '#fff4f0' : '#fffaf0'}" stroke="${stockout ? '#dbb1a6' : '#d8bf96'}"/><text x="${labelX + labelWidth / 2}" y="${labelY + 15}" text-anchor="middle" class="vpa-svg-label">${esc(label)}</text></g>`;
    };
    const pointMarker = (classes, timestamp, quantity, label, stockout = false) => `<g class="vpa-chart-target ${classes}" tabindex="0" role="img" aria-label="${esc(label)}" data-time="${timestamp}" data-stock="${quantity}"><circle class="vpa-hover-hit" cx="${x(timestamp)}" cy="${y(quantity)}" r="12" fill="transparent"/><circle class="vpa-marker-dot" cx="${x(timestamp)}" cy="${y(quantity)}" r="5" fill="${stockout ? '#a34331' : '#b4782b'}" stroke="white" stroke-width="2"/>${tooltip(date(offsetDate((timestamp - now) / 86400, now)), x(timestamp), y(quantity), stockout)}</g>`;
    let svg = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${width} ${height}" role="group" aria-labelledby="vpa-chart-title vpa-chart-desc"><title id="vpa-chart-title">Stock history and projection for ${esc(p.name)}</title><desc id="vpa-chart-desc">Current stock ${esc(number(p.stock, 2))} units. Modeled depletion ${esc(rate(p.rate))} units per day. Reorder level ${esc(number(p.reorder_point, 2))} units, ${p.planning.reorder_days} days before projected stockout. Today separates recorded or reconstructed history from the next ${number(horizon)} days of projection. Hover stock changes for order details; use left and right arrow keys to move between changes.</desc><rect x="${todayX}" y="${pad.t}" width="${x(last) - todayX}" height="${h}" fill="#edf6f1"/>`;
    for (let tick = minY; tick <= maxY + tickStep / 2; tick += tickStep) {
      svg += `<line x1="${pad.l}" x2="${width - pad.r}" y1="${y(tick)}" y2="${y(tick)}" stroke="#e6ece7"/><text x="${pad.l - 10}" y="${y(tick) + 4}" text-anchor="end" class="vpa-svg-label">${esc(number(tick, tickStep < 1 ? 1 : 0))}</text>`;
    }
    const ticks = width < 400 ? 3 : 5;
    for (let i = 0; i <= ticks; i++) {
      const timestamp = first + i / ticks * (last - first);
      svg += `<text x="${x(timestamp)}" y="${height - 8}" text-anchor="${i === 0 ? 'start' : i === ticks ? 'end' : 'middle'}" class="vpa-svg-label">${esc(moment(timestamp, true))}</text>`;
    }
    if (history.reconstructed.length) {
      const recordedX = x(history.recorded_from);
      const estimatedWidth = recordedX - pad.l;
      const label = estimatedWidth >= 100 ? 'Estimated history' : estimatedWidth >= 55 ? 'Estimated' : '';
      svg += `<g class="vpa-estimated-range"><title>History before ${esc(moment(history.recorded_from))} is estimated from orders, not recorded stock.</title><path d="M${pad.l},25 V30 H${recordedX} V25" fill="none" stroke="#94ad9f"/>${label ? `<text x="${pad.l}" y="18" class="vpa-svg-label">${label}</text>` : ''}</g>`;
      if (todayX - recordedX > 100) svg += `<text x="${recordedX + 8}" y="18" class="vpa-svg-label">Recorded history</text>`;
    } else {
      svg += `<text x="${pad.l}" y="18" class="vpa-svg-label">Recorded history</text>`;
    }
    if (width - pad.r - todayX > 70) svg += `<text x="${width - pad.r}" y="18" text-anchor="end" class="vpa-svg-label">Projection</text>`;
    if (history.reconstructed.length) svg += `<path d="${area(history.reconstructed)}" fill="#eff5f1"/><path d="${path(history.reconstructed, true)}" fill="none" stroke="#568570" stroke-width="2.3" stroke-linejoin="round"/>`;
    if (history.recorded.length) svg += `<path d="${area(history.recorded)}" fill="#e7f2eb"/><path d="${path(history.recorded, true)}" fill="none" stroke="#176b50" stroke-width="2.3" stroke-linejoin="round"/>`;
    if (overdue) svg += `<rect class="vpa-overdue-period" x="${x(Math.max(first, overdue.time))}" y="${pad.t}" width="${Math.max(0, todayX - x(Math.max(first, overdue.time)))}" height="${h}" fill="#ef9a94" opacity="0.2" pointer-events="none"/>`;
    historySegments(history).forEach((segment, index) => {
      svg += `<rect class="vpa-history-target" x="${x(segment.end) - 6}" y="${Math.min(y(segment.before), y(segment.after)) - 4}" width="12" height="${Math.max(12, Math.abs(y(segment.after) - y(segment.before)) + 8)}" data-history-index="${index}" data-x="${x(segment.end)}" data-y="${y(segment.after)}" tabindex="${index ? -1 : 0}" role="button" aria-label="${esc(`${segment.kind === 'estimated' ? 'Estimated dip' : 'Stock change'} ${moment(segment.end)}. Show orders and stock changes.`)}"/>`;
    });
    const depletionTime = p.rate > 0 && p.stock > 0 ? now + p.stock / p.rate * 86400 : null;
    if (projections.current.length) svg += `<path class="vpa-current-projection" d="${path(projections.current)}" fill="none" stroke="#176b50" stroke-width="2.5" stroke-dasharray="7 5"/>`;
    if (planned.length) {
      const blue = plan.confirmed ? '#2271b1' : '#8cbfe8';
      svg += `<path class="vpa-restock-path ${plan.confirmed ? 'vpa-restock-ordered' : 'vpa-restock-draft'}" d="${path(planned)}" fill="none" stroke="${blue}" stroke-width="2.8" stroke-dasharray="3 5" stroke-linejoin="round"/>`;
      if (plan.arrival_at >= first && plan.arrival_at <= last) svg += `<g class="vpa-chart-target vpa-arrival-marker" tabindex="0" role="img" aria-label="${esc(`${plan.confirmed ? 'Ordered' : 'Preview'} arrival ${date(plan.arrival)}: ${number(plan.quantity, 4)} units`)}"><circle cx="${x(plan.arrival_at)}" cy="${y(plan.after)}" r="12" fill="transparent"/><circle class="vpa-marker-dot" cx="${x(plan.arrival_at)}" cy="${y(plan.after)}" r="5" fill="${blue}" stroke="white" stroke-width="2"/>${tooltip(`${date(plan.arrival)} · +${number(plan.quantity, 4)}`, x(plan.arrival_at), y(plan.after))}</g>`;
      if (plan.stockout_at && plan.stockout_at >= first && plan.stockout_at <= last) svg += `<g class="vpa-chart-target vpa-planned-stockout-marker" tabindex="0" role="img" aria-label="${esc(`Stock-out after restock ${date(offsetDate((plan.stockout_at - now) / 86400, now))}`)}"><circle cx="${x(plan.stockout_at)}" cy="${y(0)}" r="12" fill="transparent"/><circle class="vpa-marker-dot" cx="${x(plan.stockout_at)}" cy="${y(0)}" r="5" fill="${blue}" stroke="white" stroke-width="2"/>${tooltip(date(offsetDate((plan.stockout_at - now) / 86400, now)), x(plan.stockout_at), y(0))}</g>`;
    }
    if (p.rate > 0) svg += `<g class="vpa-chart-target vpa-reorder-target" tabindex="0" role="img" aria-label="Reorder ${p.planning.reorder_days} days, at ${esc(number(p.reorder_point, 2))} units"><line class="vpa-reorder-line" x1="${pad.l}" x2="${width - pad.r}" y1="${y(p.reorder_point)}" y2="${y(p.reorder_point)}" stroke="#b18b4d" stroke-dasharray="3 5"/><line class="vpa-hover-hit" x1="${pad.l}" x2="${width - pad.r}" y1="${y(p.reorder_point)}" y2="${y(p.reorder_point)}" stroke="transparent" stroke-width="14" pointer-events="stroke"/>${tooltip(`Reorder ${p.planning.reorder_days} days`, pad.l + 65, y(p.reorder_point))}</g>`;
    svg += `<line x1="${todayX}" x2="${todayX}" y1="${pad.t - 5}" y2="${pad.t + h}" stroke="#5d7665" stroke-width="1.2" stroke-dasharray="4 4"/><rect x="${Math.min(width - pad.r - 46, Math.max(pad.l, todayX - 23))}" y="5" width="46" height="20" rx="4" fill="#176b50"/><text x="${Math.min(width - pad.r - 23, Math.max(pad.l + 23, todayX))}" y="19" text-anchor="middle" class="vpa-svg-today">Today</text><circle cx="${todayX}" cy="${y(p.stock)}" r="4.5" fill="#176b50" stroke="white" stroke-width="2"/>`;
    const reorderTime = p.rate > 0 && p.stock > 0 && Number.isFinite(p.reorder_in) && p.reorder_in >= 0 ? now + p.reorder_in * 86400 : null;
    const reorderVisible = reorderTime !== null && reorderTime <= projections.current_end;
    const stockoutVisible = depletionTime !== null && depletionTime <= projections.current_end;
    const samePoint = reorderVisible && stockoutVisible && Math.abs(reorderTime - depletionTime) < 1;
    if (reorderVisible && !samePoint) {
      svg += pointMarker('vpa-reorder-marker', reorderTime, p.reorder_point, `Reorder on ${date(offsetDate(p.reorder_in, now))}`);
    }
    if (overdue) {
      const markerTime = Math.max(first, overdue.time), markerX = x(markerTime), markerY = y(overdue.stock);
      const overdueDate = date(offsetDate(0, overdue.time));
      const labelWidth = Math.min(w - 8, overdue.label.length * 6 + 16), labelX = Math.max(pad.l + 4, Math.min(width - pad.r - labelWidth - 4, markerX - labelWidth / 2)), labelY = markerY < pad.t + 34 ? markerY + 12 : markerY - 30;
      svg += `<g class="vpa-chart-target vpa-overdue-marker" tabindex="0" role="img" aria-label="${esc(`${overdue.label}; reorder date ${overdueDate}${overdue.from_start ? ', beginning of displayed history' : ''}`)}"><circle cx="${markerX}" cy="${markerY}" r="12" fill="transparent"/><circle class="vpa-marker-dot" cx="${markerX}" cy="${markerY}" r="5" fill="#b7473b" stroke="white" stroke-width="2"/>${tooltip(overdueDate, markerX, markerY)}<g class="vpa-overdue-badge" pointer-events="none"><rect x="${labelX}" y="${labelY}" width="${labelWidth}" height="22" rx="4" fill="#fff1ef" stroke="#dfb0a9"/><text x="${labelX + labelWidth / 2}" y="${labelY + 15}" text-anchor="middle" class="vpa-svg-label vpa-svg-overdue">${esc(overdue.label)}</text></g></g>`;
    }
    if (stockoutVisible) {
      svg += pointMarker(`vpa-stockout-marker${p.planning.on_order ? ' vpa-stockout-acknowledged' : ''}${samePoint ? ' vpa-reorder-marker' : ''}`, depletionTime, 0, `${samePoint ? 'Reorder and stock-out' : 'Stock-out'} on ${date(offsetDate(p.days_remaining, now))}`, true);
    }
    host.innerHTML = `${svg}</svg><div id="vpa-history-tooltip" class="vpa-history-popover" role="region" aria-label="Orders and stock changes" hidden><div class="vpa-history-popover-heading"><button type="button" class="vpa-button" data-action="close-history" aria-label="Close order details">×</button></div><div class="vpa-history-content" aria-live="polite"></div></div>`;
    host.dataset.renderKey = renderKey;
  }

  function historySegments(history) {
    const segments = [];
    for (const [kind, points] of [['estimated', history.reconstructed], ['recorded', history.recorded]]) {
      points.forEach((point, index) => {
        const previous = points[index - 1];
        if (!previous || point.stock === previous.stock || (kind === 'recorded' && !point.id) || (kind === 'estimated' && point.stock >= previous.stock)) return;
        segments.push({ kind, start: previous.time + (kind === 'estimated' ? 1 : 0), end: point.time, start_id: previous.id || 0, end_id: point.id || 0, before: previous.stock, after: point.stock });
      });
    }
    return segments;
  }

  function closeHistoryHover(resume = false) {
    clearTimeout(historyTimer); clearTimeout(historyHideTimer);
    historyController?.abort(); historySequence++;
    const wasOpen = !!historyHover;
    historyHover?.target?.classList?.remove('vpa-history-selected');
    historyHover = null;
    const panel = document.getElementById('vpa-history-tooltip');
    if (panel) panel.hidden = true;
    if (resume && wasOpen) startPreload();
  }

  function positionHistoryHover(panel, target) {
    const host = target.closest('#vpa-graph');
    const height = host.querySelector?.('svg')?.getBoundingClientRect().height || host.clientHeight;
    const position = historyPopoverPosition(host.clientWidth, height, panel.offsetWidth, panel.offsetHeight, Number(target.dataset.x), Number(target.dataset.y));
    panel.classList?.toggle('vpa-history-docked', position.top > height);
    panel.style.left = `${position.left}px`;
    panel.style.top = `${position.top}px`;
  }

  function historyPopoverPosition(width, height, panelWidth, panelHeight, x, y) {
    const gap = 26;
    const clamp = (value, min, max) => Math.max(min, Math.min(Math.max(min, max), value));
    // Keep a gap around the inspected drop; do not center the popover on its pointer.
    if (x + gap + panelWidth <= width - 8) return { left: x + gap, top: clamp(y - panelHeight / 2, 8, height - panelHeight - 8) };
    if (x - gap - panelWidth >= 8) return { left: x - gap - panelWidth, top: clamp(y - panelHeight / 2, 8, height - panelHeight - 8) };
    // Narrow charts dock details below the SVG instead of covering the plot.
    return { left: clamp(x - panelWidth / 2, 8, width - panelWidth - 8), top: height + 8 };
  }

  function historyContent(data) {
    const estimated = data.kind === 'estimated';
    const rows = data.events.filter(event => event.order).map(event => {
      const order = event.order;
      const orderLabel = order ? `Order #${order.number}` : 'No linked order';
      const link = order?.url && /^https?:\/\//i.test(order.url) ? `<a href="${esc(order.url)}" target="_blank" rel="noopener noreferrer">${esc(orderLabel)} ↗</a>` : esc(orderLabel);
      const change = estimated ? `${number(event.units, 4)} units ordered · ${date(event.date)}` : `${event.before === null ? 'Unknown' : number(event.before, 4)} → ${number(event.after, 4)} units · ${moment(event.time)}`;
      const note = estimated ? (order?.status || '') : order ? (event.delta > 0 ? 'Stock restored for this order' : 'Recorded order stock reduction') : (event.delta > 0 ? 'Stock increase; order association not recorded' : 'Order association was not recorded for this change');
      return `<li><strong>${link}</strong>${order ? `<span class="vpa-history-customer">Customer: ${esc(order.customer_name || 'Customer name unavailable')}</span>` : ''}<span>${esc(change)}</span><small>${esc(note)}</small></li>`;
    }).join('');
    return `<ul>${rows || '<li>No linked orders for this change.</li>'}</ul>${data.page > 1 ? button('history-previous', 'Previous') : ''}${data.has_more ? button('history-next', 'More orders') : ''}`;
  }

  function showHistoryHover(target, page = 1) {
    clearTimeout(historyTimer); clearTimeout(historyHideTimer);
    const detail = displayedDetail(), panel = document.getElementById('vpa-history-tooltip');
    const segment = detail && historySegments(detail.stock_history)[Number(target.dataset.historyIndex)];
    if (!detail || !panel || !segment) return;
    const identity = `${detail.product.id}|${detail.period}|${detail.revision}|${target.dataset.historyIndex}|${page}`;
    if (historyHover?.identity === identity) return;
    const focusInside = !!document.activeElement?.closest?.('#vpa-history-tooltip');
    panel.style.minHeight = historyHover?.target === target ? `${Math.min(280, panel.offsetHeight)}px` : '';
    if (!historyHover) pausePreload();
    historyHover?.target?.classList?.remove('vpa-history-selected');
    target.classList?.add('vpa-history-selected');
    historyController?.abort(); const sequence = ++historySequence;
    historyHover = { identity, target, page };
    panel.hidden = false;
    const content = panel.querySelector('.vpa-history-content');
    content.innerHTML = '<p role="status">Loading order details…</p>';
    positionHistoryHover(panel, target);
    const params = new URLSearchParams({ kind: segment.kind, start: String(segment.start), end: String(segment.end), start_id: String(segment.start_id), end_id: String(segment.end_id), page: String(page) });
    const path = `products/${detail.product.id}/events?${params}`;
    const key = `${detail.revision}|${path}`;
    const cached = historyCache.get(key);
    const apply = data => {
      if (sequence !== historySequence || displayedDetail() !== detail) return;
      if (data.revision !== detail.revision) {
        markUpdatesAvailable(); content.innerHTML = '<p>Data changed. Refresh the graph to inspect this interval.</p>';
      } else content.innerHTML = historyContent(data);
      positionHistoryHover(panel, target);
      if (focusInside) panel.querySelector('[data-action="close-history"]')?.focus?.({ preventScroll: true });
    };
    if (cached && Date.now() - cached.at < 60000) { apply(cached.data); return; }
    const controller = new AbortController(); historyController = controller;
    const timeout = setTimeout(() => controller.abort(), 10000);
    api(path, undefined, controller.signal).then(data => {
      if (controller.signal.aborted || sequence !== historySequence) return;
      if (Number(data.product_id) !== detail.product.id || data.kind !== segment.kind || !Array.isArray(data.events)) throw new Error('Order details did not match this graph.');
      if (data.revision === detail.revision) {
        historyCache.delete(key); historyCache.set(key, { data, at: Date.now() });
        while (historyCache.size > 24) historyCache.delete(historyCache.keys().next().value);
      }
      apply(data);
    }).catch(error => {
      if (sequence !== historySequence) return;
      content.innerHTML = `<p>${esc(error.name === 'AbortError' ? 'Order details took too long to load.' : error.message)}</p>${button('history-retry', 'Retry')}`;
    }).finally(() => clearTimeout(timeout));
  }

  function scheduleHistoryHover(target, immediate = false) {
    clearTimeout(historyTimer); clearTimeout(historyHideTimer);
    if (immediate) showHistoryHover(target);
    else historyTimer = setTimeout(() => showHistoryHover(target), 150);
  }

  function niceStep(value) {
    const power = 10 ** Math.floor(Math.log10(Math.max(value, 0.0001)));
    return [1, 2, 2.5, 5, 10].map(n => n * power).find(step => step >= value) || power * 10;
  }

  async function loadReport(forceDetail = false) {
    const sequence = ++reportSeq;
    const path = reportPath();
    pausePreload(path);
    if (activeReportPath && activeReportPath !== path) buffer.cancel(activeReportPath);
    activeReportPath = path;
    if (forceDetail || (state.detail && state.detail.period !== state.period)) {
      detailSeq++;
      if (activeDetailPath) buffer.cancel(activeDetailPath);
      state.detailTarget = state.detail?.product || state.detailTarget;
      state.detail = null;
      state.detailError = '';
      state.detailLoading = true;
    }
    state.loading = !buffer.peek(path);
    render();
    try {
      const result = await buffer.get(path);
      if (sequence !== reportSeq) return;
      state.report = result;
      state.page = result.page;
      if (!state.selected && result.rows.length) state.selected = result.rows[0].id;
      if (state.selected && (forceDetail || !displayedDetail())) await loadDetail(state.selected, { force: forceDetail });
      if (forceDetail && displayedDetail()?.revision === result.revision && result.revision === buffer.revision) state.updatesAvailable = false;
    } catch (error) {
      if (sequence === reportSeq) {
        state.detailLoading = false;
        if (error.name !== 'AbortError') state.error = error.message;
        if (!state.detail && state.selected) state.detailError = 'The product list could not be refreshed. Retry the graph or refresh the page.';
      }
    }
    finally { if (sequence === reportSeq) { state.loading = false; render(); startPreload(); } }
  }

  function loadDetail(id, { force = false } = {}) {
    if (!force && Number(id) === state.selected) {
      if (state.detailLoading && activeDetailPath === detailPath(id) && detailInFlight) return detailInFlight;
      if (displayedDetail()) return Promise.resolve();
    }
    detailInFlight = readDetail(id);
    return detailInFlight;
  }

  async function readDetail(id) {
    const sequence = ++detailSeq;
    const period = state.period;
    const path = detailPath(id);
    state.planningOpen = false; state.planningDraft = null;
    pausePreload(path);
    if (activeDetailPath && activeDetailPath !== path) buffer.cancel(activeDetailPath);
    activeDetailPath = path;
    state.detailTarget = state.report?.rows.find(row => row.id === Number(id)) || (state.detail?.product.id === Number(id) ? state.detail.product : state.detailTarget?.id === Number(id) ? state.detailTarget : { id: Number(id), name: `Product #${id}` });
    state.selected = Number(id);
    state.detail = null;
    state.detailError = '';
    const cached = buffer.peek(path);
    state.detailLoading = !cached;
    if (cached) acceptDetail(cached);
    render();
    if (cached) { startPreload(); return; }
    try {
      let detail;
      for (let attempt = 0; attempt < 2; attempt++) {
        try { detail = await buffer.get(path); break; }
        catch (error) {
          if (sequence !== detailSeq || period !== state.period || attempt || ['AbortError', 'TimeoutError'].includes(error.name) || (error.status >= 400 && error.status < 500)) throw error;
        }
      }
      if (sequence !== detailSeq || period !== state.period || Number(id) !== state.selected) return;
      acceptDetail(detail);
      render(); startPreload();
    } catch (error) {
      if (sequence !== detailSeq || period !== state.period) return;
      state.detail = null;
      state.detailLoading = false;
      state.detailError = error.name === 'AbortError' ? 'The read was interrupted. Please retry the selected product.' : error.message;
      render(); startPreload();
    }
  }

  function acceptDetail(detail) {
    const now = Math.floor(Date.now() / 1000);
    const recorded = detail.stock_history.recorded.map((point, index, points) => index === points.length - 1 ? { ...point, time: now } : point);
    const product = { ...detail.product, stockout_date: offsetDate(detail.product.days_remaining, now), reorder_date: offsetDate(detail.product.reorder_in, now) };
    const planKey = `${product.id}|${product.planning.confirmed}|${product.planning.quantity}|${product.planning.arrival}|${product.planning.placed_at}`;
    if (state.planViewKey !== planKey) {
      state.planViewKey = planKey;
      if (product.planning.confirmed && product.planning.projection) state.horizon = 'plan';
      else if (state.horizon === 'plan') state.horizon = 30;
    }
    state.detail = { ...detail, product, stock_history: { ...detail.stock_history, today: now, recorded } };
    state.detailTarget = product;
    state.detailLoading = false;
    state.detailError = '';
  }

  async function indexOrders(restart) {
    if (state.indexing) return;
    pausePreload(); buffer.clear();
    state.indexing = true; state.error = ''; render();
    try {
      let sync = await api('sync', { restart });
      while (true) {
        if (state.report) state.report.sync = sync;
        render();
        if (sync.complete) break;
        await new Promise(resolve => setTimeout(resolve, 400));
        sync = await api('sync', {});
      }
      state.notice = `${number(sync.processed)} orders indexed. Product analysis is up to date.`;
      buffer.clear();
      await loadReport(true);
    } catch (error) { state.error = error.message; }
    finally { state.indexing = false; render(); startPreload(); }
  }

  function captureDraft(event) {
    const form = event.target.closest('#vpa-settings-form');
    if (form) {
      const data = new FormData(form);
      state.settingsDraft = { ...Object.fromEntries(data), email_enabled: data.has('email_enabled') };
    }
  }
  root.addEventListener('input', event => {
    captureDraft(event);
    if (event.target.id === 'vpa-reorder-days') state.reorderDraft = event.target.value;
    const planning = event.target.closest('#vpa-planning-form');
    if (planning && !state.busy) {
      state.planningDraft = { ...state.planningDraft, quantity: planning.querySelector('[name="quantity"]').value, arrival: planning.querySelector('[name="arrival"]').value };
      refreshPlanningPreview();
    }
    if (event.target.id === 'vpa-search') {
      pausePreload();
      state.search = event.target.value; state.page = 1;
      clearTimeout(searchTimer); searchTimer = setTimeout(loadReport, 300);
    }
  });
  root.addEventListener('change', async event => {
    captureDraft(event);
    const planning = event.target.closest('#vpa-planning-form');
    if (planning && !state.busy) {
      state.planningDraft = { ...state.planningDraft, quantity: planning.querySelector('[name="quantity"]').value, arrival: planning.querySelector('[name="arrival"]').value };
      refreshPlanningPreview();
    }
    if (event.target.id === 'vpa-period-select') { state.period = event.target.value; state.page = 1; await loadReport(); }
    if (event.target.id === 'vpa-horizon-select') { state.horizon = event.target.value === 'plan' ? 'plan' : Number(event.target.value); render(); startPreload(); }
  });
  root.addEventListener('click', async event => {
    const historyTarget = event.target.closest('.vpa-history-target');
    if (historyTarget) { historyTarget.focus(); scheduleHistoryHover(historyTarget, true); if (historyHover) historyHover.pinned = true; return; }
    if (!event.target.closest('#vpa-history-tooltip')) closeHistoryHover(true);
    const chartTarget = event.target.closest('.vpa-chart-target');
    if (chartTarget) { chartTarget.focus(); return; }
    const target = event.target.closest('button');
    if (!target || target.disabled) return;
    if (target.dataset.tab) { pausePreload(); state.tab = target.dataset.tab; render(); startPreload(); return; }
    if (target.dataset.product) {
      await loadDetail(Number(target.dataset.product));
      if (matchMedia('(max-width: 700px)').matches) document.getElementById('vpa-controls')?.scrollIntoView({ behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'instant' : 'smooth', block: 'start' });
      return;
    }
    const action = target.dataset.action;
    if (action === 'close-history') { closeHistoryHover(true); return; }
    if (['history-next', 'history-previous', 'history-retry'].includes(action) && historyHover) {
      const current = historyHover;
      const page = Math.max(1, current.page + (action === 'history-next' ? 1 : action === 'history-previous' ? -1 : 0));
      current.identity = null; showHistoryHover(current.target, page); return;
    }
    if (action === 'retry-detail') { await loadDetail(state.selected); return; }
    if (action === 'refresh') { state.error = ''; await refreshData(); if (state.report && !state.report.sync.complete) indexOrders(false); }
    if (action === 'reindex') await indexOrders(true);
    if (action === 'previous' || action === 'next') { state.page += action === 'next' ? 1 : -1; await loadReport(); }
    if (action === 'dismiss-error') { state.error = ''; render(); }
    if (action === 'dismiss-notice') { state.notice = ''; render(); }
    if (action === 'open-planning') {
      pausePreload(); state.planningOpen = true;
      const p = state.detail?.product;
      state.planningDraft = { quantity: p?.planning.quantity || '', arrival: planningArrival(p), placed_at: Math.floor(Date.now() / 1000) };
      render(); return;
    }
    if (action === 'close-planning') {
      state.planningOpen = false; state.planningDraft = null;
      if (state.horizon === 'plan' && !state.detail?.product.planning.projection) state.horizon = 30;
      render(); startPreload(); return;
    }
    if (action === 'clear-planning') {
      if (state.busy) return;
      pausePreload(); buffer.clear(); state.busy = true; state.error = ''; render();
      try {
        await api(`products/${state.selected}/planning`, { clear: true });
        buffer.clear(); state.planningOpen = false; state.planningDraft = null; await loadReport(true);
      } catch (error) { state.error = error.message; }
      finally { state.busy = false; render(); startPreload(); }
      return;
    }
    if (action === 'close-preview') { state.preview = null; render(); }
    if (action === 'preview-alerts' || action === 'run-alerts') {
      pausePreload();
      state.busy = true; state.error = ''; render();
      try {
        if (action === 'preview-alerts') state.preview = await api('alerts/preview');
        else {
          const result = await api('alerts/run', { force: true });
          state[result.failed ? 'error' : 'notice'] = result.message;
          state.settings = await api('settings');
        }
      } catch (error) { state.error = error.message; }
      finally { state.busy = false; render(); startPreload(); }
    }
  });

  root.addEventListener('keydown', event => {
    if (event.key === 'Escape') { event.target.closest('.vpa-chart-target')?.classList.add('vpa-tooltip-dismissed'); closeHistoryHover(true); }
    const target = event.target.closest('.vpa-history-target');
    if (target && ['Enter', ' '].includes(event.key)) { event.preventDefault(); scheduleHistoryHover(target, true); if (historyHover) historyHover.pinned = true; }
    if (target && ['ArrowLeft', 'ArrowRight'].includes(event.key)) {
      event.preventDefault();
      const targets = [...target.closest('#vpa-graph').querySelectorAll('.vpa-history-target')];
      const next = targets[Math.max(0, Math.min(targets.length - 1, targets.indexOf(target) + (event.key === 'ArrowRight' ? 1 : -1)))];
      target.setAttribute('tabindex', '-1'); next.setAttribute('tabindex', '0'); next.focus();
    }
  });
  for (const type of ['pointerover', 'focusin']) root.addEventListener(type, event => {
    const target = event.target.closest('.vpa-history-target');
    if (target && !target.contains(event.relatedTarget)) scheduleHistoryHover(target, type === 'focusin');
    if (event.target.closest('#vpa-history-tooltip')) clearTimeout(historyHideTimer);
  });
  for (const type of ['pointerout', 'focusout']) root.addEventListener(type, event => {
    if (historyHover?.pinned) return;
    if (event.target.closest('.vpa-history-target, #vpa-history-tooltip') && !event.relatedTarget?.closest?.('.vpa-history-target, #vpa-history-tooltip')) {
      clearTimeout(historyTimer); clearTimeout(historyHideTimer);
      historyHideTimer = setTimeout(() => closeHistoryHover(true), 200);
    }
  });
  // The native dialog close event also covers Escape; it does not bubble.
  root.addEventListener('close', event => {
    if (event.target.id === 'vpa-planning') { state.planningOpen = false; state.planningDraft = null; startPreload(); }
  }, true);
  for (const type of ['pointerover', 'focusin']) root.addEventListener(type, event => {
    const target = event.target.closest('.vpa-chart-target');
    if (target && !target.contains(event.relatedTarget)) target.classList.remove('vpa-tooltip-dismissed');
  });

  root.addEventListener('submit', async event => {
    event.preventDefault();
    const form = event.target;
    if (form.id === 'vpa-page-form') {
      if (!form.reportValidity() || state.loading) return;
      state.page = Number(form.querySelector('input').value); await loadReport(); return;
    }
    if (state.busy || !['vpa-settings-form', 'vpa-planning-form', 'vpa-reorder-form'].includes(form.id)) return;
    if (!form.reportValidity()) return;
    const data = new FormData(form);
    let path, body;
    if (form.id === 'vpa-reorder-form') {
      path = 'settings'; body = { reorder_days: Number(data.get('reorder_days')) };
    } else if (form.id === 'vpa-settings-form') {
      path = 'settings'; body = { email_enabled: data.has('email_enabled'), recipients: data.get('recipients'), email_frequency_days: Number(data.get('email_frequency_days')), alert_period: data.get('alert_period') };
    } else {
      path = `products/${state.selected}/planning`; body = { quantity: Number(data.get('quantity')), arrival: data.get('arrival'), confirmed: true };
    }
    pausePreload(); buffer.clear();
    state.busy = true; state.error = '';
    const submit = form.querySelector('[type="submit"]');
    if (submit) submit.disabled = true;
    if (form.id === 'vpa-reorder-form' || form.id === 'vpa-planning-form') render();
    try {
      await api(path, body);
      buffer.clear();
      if (form.id === 'vpa-settings-form') state.settingsDraft = null;
      if (form.id === 'vpa-reorder-form') state.reorderDraft = null;
      if (form.id === 'vpa-planning-form') { state.planningOpen = false; state.planningDraft = null; }
      state.settings = await api('settings');
      state.notice = form.id === 'vpa-reorder-form' ? `Reorder trigger saved for all products: ${body.reorder_days} days before projected stockout.` : form.id === 'vpa-settings-form' ? 'Settings saved.' : '';
      state.preview = null;
      await loadReport(true);
    } catch (error) { state.error = error.message; }
    finally { state.busy = false; render(); startPreload(); }
  });

  async function init() {
    render();
    try { state.settings = await api('settings'); } catch (error) { state.error = error.message; }
    await loadReport();
    if (state.report && !state.report.sync.complete) indexOrders(false);
  }
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') checkFreshness();
    else { closeHistoryHover(); pausePreload(); }
  });
  window.addEventListener('focus', checkFreshness);
  window.addEventListener('online', () => checkFreshness({ force: true }));
  window.addEventListener('offline', () => { pausePreload(); updateBufferStatus(); });
  navigator.connection?.addEventListener('change', () => {
    if (canPreload()) { if (!preloadRunning) startPreload(); }
    else { pausePreload(); updateBufferStatus(); }
  });
  setInterval(checkFreshness, 30000);
  init();
})();
