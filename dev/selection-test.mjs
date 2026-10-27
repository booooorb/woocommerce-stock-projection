import assert from 'node:assert/strict';
import { writeFile } from 'node:fs/promises';
import { dashboard } from './dashboard-harness.mjs';

let checks = 0;
const passed = label => { checks++; console.log(`PASS: ${label}`); };
const setup = () => {
  const h = dashboard();
  h.connection.saveData = true; // Test foreground reads without optional background traffic.
  h.state.selected = 1; h.state.detail = h.detail(1);
  return h;
};
const graph = /id="vpa-graph"/;

let h = setup(), release;
h.reply = () => new Promise(resolve => { release = resolve; });
let loading = h.loadDetail(2);
assert.match(h.root.innerHTML, /Loading stock history for Product 2/);
assert.doesNotMatch(h.root.innerHTML, graph);
assert.doesNotMatch(h.root.innerHTML, /id="vpa-selected-title">Product 1/);
await h.advance(0); release(h.detail(2)); await loading;
assert.equal(h.state.detail.product.id, 2); assert.match(h.root.innerHTML, graph);
passed('An uncached selection immediately removes the old chart and names the product being loaded');

h = setup(); h.buffer.put('products/2?period=90', h.detail(2));
loading = h.loadDetail(2);
assert.equal(h.state.detail.product.id, 2);
assert.match(h.root.innerHTML, /id="vpa-selected-title">Product 2/);
assert.match(h.root.innerHTML, graph); await loading;
assert.equal(h.calls.length, 0);
assert.ok(h.renders.every(html => !html.includes('id="vpa-selected-title">Product 1')));
passed('A valid warm graph displays atomically without an old-graph flash or another request');

h = setup(); h.reply = () => { throw new Error('network unavailable'); };
await h.loadDetail(2);
assert.equal(h.state.selected, 2); assert.equal(h.state.detail, null);
assert.match(h.root.innerHTML, /Could not load stock history for Product 2/);
assert.match(h.root.innerHTML, /data-action="retry-detail"/);
assert.doesNotMatch(h.root.innerHTML, graph);
assert.equal(h.calls.length, 2);
h.reply = () => h.detail(2);
await h.emit('root', 'click', { target: { closest: selector => selector === 'button' ? { dataset: { action: 'retry-detail' } } : null } });
assert.equal(h.state.detail.product.id, 2); assert.match(h.root.innerHTML, graph);
passed('A failed selection gets one fallback request, stays selected, and offers a working retry');

h = setup(); h.buffer.put('products/2?period=90', h.detail(1));
assert.equal(h.buffer.peek('products/2?period=90'), null);
h.buffer.version('a'); h.buffer.put('products/2?period=90', null);
await h.loadDetail(2);
assert.equal(h.state.detail.product.id, 2); assert.equal(h.calls.length, 1);
passed('A buffered payload for the wrong product is discarded and replaced by a foreground read');

h = setup(); h.buffer.put('products/2?period=90', h.detail(2, '30'));
await h.loadDetail(2);
assert.equal(h.state.detail.period, '90'); assert.equal(h.calls.length, 1);
passed('A buffered graph from another history period cannot be reused');

h = setup(); h.reply = () => h.detail(1);
await h.loadDetail(2);
assert.equal(h.state.selected, 2); assert.equal(h.state.detail, null);
assert.doesNotMatch(h.root.innerHTML, graph);
assert.equal(h.buffer.peek('products/2?period=90'), null);
passed('A mismatched server response is neither shown nor counted as a ready graph');

h = setup();
let finishOld;
const normal = h.reply;
h.reply = path => path === 'products/2?period=90' ? new Promise(resolve => { finishOld = resolve; }) : normal(path);
const old = h.loadDetail(2); await h.advance(0);
await h.loadDetail(3); finishOld(h.detail(2)); await old; await h.advance(0);
assert.equal(h.state.selected, 3); assert.equal(h.state.detail.product.id, 3);
assert.match(h.root.innerHTML, /id="vpa-selected-title">Product 3/);
assert.ok(h.renders.filter(html => graph.test(html)).every(html => html.includes('id="vpa-selected-title">Product 3')));
passed('A slow older selection can never replace the latest product after rapid clicks');

h = setup(); h.state.period = '30';
let finishReport;
const reportReply = h.reply;
h.reply = path => path.startsWith('report?') ? new Promise(resolve => { finishReport = resolve; }) : reportReply(path);
const report = h.loadReport();
assert.doesNotMatch(h.root.innerHTML, graph);
await h.advance(0);
finishReport({ ...h.state.report, revision: 'a' }); await report;
assert.equal(h.state.detail.period, '30'); assert.match(h.root.innerHTML, graph);
passed('Changing history periods hides the prior-period chart while report and detail load');

h = setup(); h.reply = () => new Promise(() => {});
loading = h.loadDetail(2); await h.advance(31000); await loading;
assert.equal(h.state.selected, 2); assert.equal(h.state.detailLoading, false);
assert.match(h.root.innerHTML, /data-action="retry-detail"/);
assert.doesNotMatch(h.root.innerHTML, graph);
passed('A stalled selected graph times out into an explicit retry state instead of lingering forever');

h = setup(); h.state.selected = 2; h.state.detail = h.detail(1);
assert.doesNotMatch(h.overview(), graph);
passed('The rendering boundary itself refuses a chart belonging to a different selected product');

h = setup(); h.state.selected = 2; h.state.detail = h.detail(2);
const staleHost = { isConnected: true, dataset: { product: '1', period: '90' }, innerHTML: '<svg>old product</svg>' };
h.drawChart(staleHost);
assert.equal(staleHost.innerHTML, '');
passed('A late resize callback cannot leave an incorrectly owned SVG visible');

h = setup(); h.buffer.put('products/2?period=90', h.detail(2));
h.buffer.version('new');
await h.loadDetail(2);
assert.equal(h.calls.length, 1);
assert.equal(h.state.detail.product.id, 2);
passed('A cached response from an older data revision falls back to a new foreground read');

h = setup(); h.state.period = '30'; h.reply = () => { throw new Error('report unavailable'); };
await h.loadReport();
assert.doesNotMatch(h.root.innerHTML, graph);
assert.match(h.root.innerHTML, /data-action="retry-detail"/);
passed('A failed report refresh cannot reveal the old-period chart or an endless loading state');

h = setup();
let failPreload, reads = 0;
h.reply = () => ++reads === 1 ? new Promise((_, reject) => { failPreload = reject; }) : h.detail(2);
const preload = h.buffer.get('products/2?period=90', { background: true }).catch(() => {});
await h.advance(0);
loading = h.loadDetail(2);
assert.doesNotMatch(h.root.innerHTML, graph);
failPreload(new Error('preload transport failed')); await Promise.all([preload, loading]);
assert.equal(h.state.detail.product.id, 2); assert.equal(reads, 2);
assert.match(h.root.innerHTML, /id="vpa-selected-title">Product 2/);
passed('Selecting a failing in-flight preload falls back to a fresh request for that same product');

const summary = `ALL SELECTION TESTS PASSED (${checks} checks)\n`;
await writeFile(new URL('../.local/selection-test-results.txt', import.meta.url), summary);
console.log(summary);
