import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import vm from 'node:vm';

const bufferSource = await readFile(new URL('../plugin/assets/read-buffer.js', import.meta.url), 'utf8');
const adminSource = await readFile(new URL('../plugin/assets/admin.js', import.meta.url), 'utf8');

// Exercise the shipped dashboard controller, with rendering and the network replaced
// by deterministic fixtures. No browser, real store or live stock writes are used.
export function dashboard() {
  let now = 0, timerId = 0;
  const timers = new Map(), handlers = new Map(), calls = [];
  const on = name => (type, callback) => {
    const key = `${name}:${type}`;
    handlers.set(key, [...(handlers.get(key) || []), callback]);
  };
  const root = { addEventListener: on('root') };
  const planning = { id: 'vpa-planning', open: false, showModal() { this.open = true; }, close() { this.open = false; } };
  const status = { textContent: '' };
  const connection = { saveData: false, effectiveType: '4g', addEventListener: on('connection') };
  const navigator = { connection, onLine: true };
  const document = { visibilityState: 'visible', addEventListener: on('document'), getElementById: id => ({ 'veryshop-analysis': root, 'vpa-planning': planning, 'vpa-buffer-status': status })[id] || null };
  const window = { VeryShopAnalysis: { api: '/api/', nonce: 'test', locale: 'en', timezone: 'UTC' }, addEventListener: on('window') };
  const product = id => ({ id, name: `Product ${id}`, sku: `SKU-${id}`, rank: id, stock: id * 10, rate: id, days_remaining: 10, reorder_in: 3, reorder_point: id * 7, status: 'good', planning: { reorder_days: 7, on_order: false } });
  const detail = (id, period = '90') => ({ product: product(id), period, revision: 'a', window: { start: '2026-07-29', end: '2026-10-26', days: 90 }, stock_history: { today: 1793059200, start: 1785283200, recorded_from: 1785283200, recorded: [{ time: 1785283200, stock: id * 100 }, { time: 1793059200, stock: id * 10 }], reconstructed: [] } });
  const reports = page => ({ page, pages: 2, total: 4, window: { start: '2026-07-29', end: '2026-10-26' }, rows: (page === 1 ? [1, 2] : [3, 4]).map(product), sync: { complete: true }, revision: 'a' });
  const env = {
    calls, document, navigator, connection, planning, status, root, detail, revision: 'a', renders: [],
    reply: path => path === 'settings' ? { settings: { reorder_days: 7 } } : { ...(path === 'sync' ? { complete: true } : path.startsWith('report?') ? reports(Number(new URLSearchParams(path.split('?')[1]).get('page'))) : detail(Number(path.match(/^products\/(\d+)/)?.[1]), new URLSearchParams(path.split('?')[1]).get('period'))), revision: env.revision },
    async emit(name, type, event = {}) { for (const callback of handlers.get(`${name}:${type}`) || []) await callback(event); await settle(); },
    async advance(ms) {
      const end = now + ms;
      await settle();
      while (true) {
        const next = [...timers].filter(([, timer]) => timer.at <= end).sort((a, b) => a[1].at - b[1].at)[0];
        if (!next) break;
        timers.delete(next[0]); now = next[1].at; next[1].callback(); await settle();
      }
      now = end; await settle();
    },
  };
  async function settle() { for (let i = 0; i < 25; i++) await Promise.resolve(); }
  const scope = {
    document, window, navigator, AbortController, DOMException, URLSearchParams, snapshots: env.renders,
    Date: class extends Date { static now() { return Date.UTC(2026, 9, 27) + now; } },
    getComputedStyle: element => element.style || {},
    setTimeout: (callback, delay) => { const id = ++timerId; timers.set(id, { at: now + delay, callback }); return id; },
    clearTimeout: id => timers.delete(id), setInterval: () => {},
    fetch: async (url, options) => {
      const path = url.slice('/api/'.length); calls.push({ path, signal: options.signal });
      const value = await env.reply(path, options);
      return { ok: true, json: async () => value };
    },
  };
  vm.runInNewContext(bufferSource, scope);
  const instrumented = adminSource.replace('  init();\n})();', `  render = () => { root.innerHTML = overview(); snapshots.push(root.innerHTML); };
  globalThis.controller = { state, buffer, startPreload, checkFreshness, pausePreload, loadDetail, loadReport, refreshData, overview, detailView, drawChart };
})();`);
  assert.notEqual(instrumented, adminSource, 'Dashboard test hook must match the entry point');
  vm.runInNewContext(instrumented, scope);
  Object.assign(scope.controller.state, { loading: false, detailLoading: false, report: reports(1) });
  return Object.assign(env, scope.controller);
}

