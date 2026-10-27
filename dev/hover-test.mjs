import assert from 'node:assert/strict';
import { writeFile } from 'node:fs/promises';
import { dashboard } from './dashboard-harness.mjs';
let checks = 0;
const passed = label => { checks++; console.log(`PASS: ${label}`); };
function fixture() {
  const h = dashboard(); h.connection.saveData = true;
  h.state.selected = 1; h.state.detail = h.detail(1);
  h.state.detail.stock_history.recorded.forEach((point, index) => { point.id = index + 1; });
  const content = { innerHTML: '' };
  const panel = { hidden: true, style: {}, offsetWidth: 300, offsetHeight: 180, querySelector: () => content };
  h.nodes['vpa-history-tooltip'] = panel;
  const host = { clientWidth: 800, clientHeight: 480 };
  const target = { dataset: { historyIndex: '0', x: '300', y: '200' }, closest: () => host };
  h.reply = () => ({ product_id: 1, kind: 'recorded', revision: 'a', page: 1, has_more: false, events: [{ before: 100, after: 10, delta: -90, time: 1793059200, source: 'order', order: { number: '123', customer_name: 'Avery Chen', url: 'https://example.test/wp-admin/post.php?post=123&action=edit', status: 'Completed' } }] });
  return Object.assign(h, { panel, content, target });
}

let h = fixture();
assert.equal(h.calls.length, 0);
h.scheduleHistoryHover(h.target); await h.advance(149); assert.equal(h.calls.length, 0);
await h.advance(1); assert.equal(h.calls.length, 1);
assert.match(h.content.innerHTML, /Order #123/); assert.match(h.content.innerHTML, /100 → 10 units/);
assert.match(h.content.innerHTML, /Customer: Avery Chen/);
passed('Order hydration is lazy, debounced and shows the order with its actual stock change');

h.closeHistoryHover(); h.showHistoryHover(h.target); await h.advance(0);
assert.equal(h.calls.length, 1); assert.equal(h.panel.hidden, false);
await h.advance(60001); h.closeHistoryHover(); h.showHistoryHover(h.target); await h.advance(0);
assert.equal(h.calls.length, 2);
passed('Repeat hover reuses its bounded cache, and expired entries are read again');

h = fixture(); h.scheduleHistoryHover(h.target); h.closeHistoryHover(); await h.advance(500);
assert.equal(h.calls.length, 0);
passed('Passing quickly over a dip or leaving it cancels the pending hover request');

h = fixture(); let release;
h.reply = () => new Promise(resolve => { release = resolve; });
h.showHistoryHover(h.target); await h.advance(0); h.document.visibilityState = 'hidden'; await h.emit('document', 'visibilitychange');
release({ product_id: 1, kind: 'recorded', revision: 'a', events: [] }); await h.advance(0);
assert.equal(h.panel.hidden, true); assert.doesNotMatch(h.content.innerHTML, /No linked changes/);
passed('A cancelled hover reply cannot reopen its panel or alter a newer graph');

h = fixture(); h.reply = () => ({ product_id: 1, kind: 'recorded', revision: 'new', events: [] });
h.showHistoryHover(h.target); await h.advance(0);
assert.match(h.content.innerHTML, /Data changed. Refresh/); assert.equal(h.state.updatesAvailable, true);
assert.equal(h.historyCache.size, 0); assert.equal(h.state.detail.product.id, 1);
passed('A newer revision requests explicit refresh instead of associating new orders with an old graph');

h = fixture(); h.reply = () => ({ product_id: 2, kind: 'recorded', revision: 'a', events: [] });
h.showHistoryHover(h.target); await h.advance(0);
assert.match(h.content.innerHTML, /did not match this graph/); assert.match(h.content.innerHTML, /Retry/);
passed('Mismatched order-detail replies are rejected and offer retry');

h = fixture();
const escaped = h.historyContent({ kind: 'estimated', page: 1, events: [{ date: '2026-10-27', units: 0.125, order: { number: '<img onerror=alert(1)>', customer_name: '<img src=x onerror=alert(1)>', url: 'javascript:alert(1)', status: '<script>x</script>' } }] });
assert.doesNotMatch(escaped, /<img|<script|href="javascript:/);
assert.match(escaped, /0.125 units ordered/); assert.doesNotMatch(escaped, /Orders contributing|Historical restocks|Recorded changes in/);
assert.match(escaped, /Customer: &lt;img/);
passed('Order details are escaped and preserve quantities without explanatory hover text');

h = fixture();
h.state.detail.stock_history.recorded = Array.from({ length: 27 }, (_, i) => ({ id: i + 1, time: 1793050000 + i * 10, stock: 100 - i }));
for (let i = 0; i < 25; i++) { h.target.dataset.historyIndex = String(i); h.showHistoryHover(h.target); await h.advance(0); }
assert.equal(h.historyCache.size, 24); assert.equal(h.calls.length, 25);
h.target.dataset.historyIndex = '0'; h.showHistoryHover(h.target); await h.advance(0);
assert.equal(h.calls.length, 26);
passed('Hover caches at most twenty-four intervals and evicts older entries');

h = fixture();
const segments = h.historySegments({ reconstructed: [{ time: 100, stock: 30 }, { time: 200, stock: 25 }], recorded: [{ id: 5, time: 200, stock: 25 }, { id: 6, time: 200, stock: 23 }, { id: 7, time: 250, stock: 35 }, { time: 300, stock: 35 }] });
assert.equal(segments.length, 3); assert.equal(segments[0].start, 101);
assert.equal(segments[1].start_id, 5); assert.equal(segments[1].end_id, 6);
assert.equal(segments[2].after, 35);
passed('Hit targets preserve same-second stock changes, estimated boundaries and restocking intervals');

const unlinked = h.historyContent({ kind: 'recorded', page: 1, events: [{ before: 10, after: 8, delta: -2, time: 1793059200, order: null }] });
assert.doesNotMatch(unlinked, /vpa-history-customer/); assert.match(unlinked, /No linked order/);
passed('An unlinked stock change does not invent a customer identity');

const summary = `ALL HOVER TESTS PASSED (${checks} checks)\n`;
await writeFile(new URL('../.local/hover-test-results.txt', import.meta.url), summary);
console.log(summary);
