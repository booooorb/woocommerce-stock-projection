import assert from 'node:assert/strict';
import { writeFile } from 'node:fs/promises';
import { dashboard } from './dashboard-harness.mjs';

let checks = 0;
const passed = label => { checks++; console.log(`PASS: ${label}`); };
const setup = async () => {
  const h = dashboard(); h.connection.saveData = true;
  await h.loadDetail(1);
  h.calls.length = 0; h.renders.length = 0;
  return h;
};

let h = await setup();
for (const [id, revision] of [[2, 'b'], [3, 'c'], [4, 'd']]) {
  h.revision = revision;
  await h.buffer.get(`products/${id}?period=90`, { background: true });
  await h.advance(1000);
}
assert.equal(h.calls.filter(call => call.path === 'products/1?period=90').length, 0);
assert.equal(h.calls.filter(call => call.path === 'settings').length, 0);
assert.equal(h.renders.length, 0);
assert.match(h.overview(), /id="vpa-graph"/);
assert.equal(h.state.updatesAvailable, true);
passed('Successive preload revisions never reload or blank the selected graph');

await h.refreshData();
assert.equal(h.calls.filter(call => call.path === 'products/1?period=90').length, 1);
assert.equal(h.state.detail.revision, 'd');
assert.equal(h.state.updatesAvailable, false);
h.revision = 'e'; h.buffer.clear(); h.state.updatesAvailable = true;
await h.loadReport(true);
assert.equal(h.state.detail.revision, 'e'); assert.equal(h.state.updatesAvailable, false);
passed('Explicit refresh adopts the latest graph and clears the updates indicator');

h = await setup();
for (let i = 0; i < 10; i++) await h.loadDetail(1);
assert.equal(h.calls.length, 0); assert.equal(h.renders.length, 0);
passed('Repeated clicks on the current product cause no reads or redraws');

h = await setup();
for (let i = 0; i < 20; i++) await h.emit('window', 'focus');
assert.equal(h.calls.filter(call => call.path === 'sync').length, 1);
await h.advance(30000); await h.checkFreshness();
assert.equal(h.calls.filter(call => call.path === 'sync').length, 2);
assert.equal(h.renders.length, 0);
passed('Focus events share the freshness interval and unchanged checks leave the graph untouched');

h = await setup();
let releaseStatus;
const normal = h.reply;
h.reply = path => path === 'sync' ? new Promise(resolve => { releaseStatus = resolve; }) : normal(path);
const oldCheck = h.checkFreshness(); await h.advance(0);
h.revision = 'b'; await h.buffer.get('products/2?period=90', { background: true });
releaseStatus({ revision: 'a', complete: true }); await oldCheck;
assert.equal(h.buffer.revision, 'b'); assert.equal(h.state.detail.revision, 'a');
assert.match(h.overview(), /id="vpa-graph"/);
passed('A late freshness response cannot roll a newer cache revision backward');

h = await setup();
let releaseSettings;
const direct = h.reply;
h.reply = path => path === 'settings' ? new Promise(resolve => { releaseSettings = resolve; }) : direct(path);
const refreshes = [h.refreshData(), h.refreshData(), h.refreshData()];
await h.advance(0);
assert.equal(h.calls.filter(call => call.path === 'settings').length, 1);
releaseSettings({ settings: { reorder_days: 7 } }); await Promise.all(refreshes);
assert.equal(h.calls.filter(call => call.path.startsWith('report?')).length, 1);
assert.equal(h.calls.filter(call => call.path === 'products/1?period=90').length, 1);
passed('Concurrent refresh actions share a single settings/report/graph read');

h = await setup(); h.connection.saveData = false;
h.startPreload(); await h.advance(5000);
for (let i = 0; i < 40; i++) { await h.advance(30000); await h.checkFreshness(); }
assert.equal(h.calls.filter(call => call.path === 'products/1?period=90').length, 0);
assert.equal(h.calls.filter(call => call.path === 'settings').length, 0);
assert.equal(h.renders.length, 0);
assert.match(h.overview(), /id="vpa-graph"/);
assert.equal(h.calls.filter(call => call.path === 'products/2?period=90').length, 1);
passed('Twenty minutes of idle checks reuse nearby graphs without refreshing or redrawing the selected graph');

h = await setup(); h.connection.saveData = false;
h.state.detail = h.detail(3); h.state.selected = 3;
h.startPreload(); await h.advance(5000);
assert.equal(h.calls.filter(call => call.path === 'products/3?period=90').length, 0);
assert.ok(h.buffer.peek('products/4?period=90'));
passed('A selected graph on the next ranking page is also excluded from redundant warming');

h = await setup();
let finishDetail;
h.reply = () => new Promise(resolve => { finishDetail = resolve; });
const selects = [h.loadDetail(2), h.loadDetail(2), h.loadDetail(2)];
await h.advance(0); assert.equal(h.calls.length, 1); assert.equal(h.renders.length, 1);
finishDetail(h.detail(2)); await Promise.all(selects);
assert.equal(h.renders.length, 2); assert.equal(h.state.selected, 2);
passed('Repeated clicks on an in-flight selection share its request and loading render');

h = await setup(); h.reply = () => { throw new Error('offline'); };
await h.checkFreshness();
for (let i = 0; i < 10; i++) await h.emit('window', 'focus');
assert.equal(h.calls.length, 1); assert.equal(h.renders.length, 0);
await h.advance(15000); await h.checkFreshness(); assert.equal(h.calls.length, 2);
assert.match(h.overview(), /id="vpa-graph"/);
passed('Failed checks back off and preserve the displayed snapshot for offline use');

h = await setup();
let svgWrites = 0, svg = '';
const panel = { children: [], style: { minHeight: '500px', paddingTop: '14px', paddingBottom: '0px' } };
const host = { isConnected: true, dataset: { product: '1', period: '90' }, clientWidth: 800, closest: () => panel, get innerHTML() { return svg; }, set innerHTML(value) { svgWrites++; svg = value; } };
panel.children = [host];
for (let i = 0; i < 100; i++) h.drawChart(host);
assert.equal(svgWrites, 1); assert.match(svg, /<svg/);
h.state.horizon = 90; h.drawChart(host); assert.equal(svgWrites, 2);
host.clientWidth = 600; h.drawChart(host); assert.equal(svgWrites, 3);
passed('Repeated resize notifications write one SVG; changed horizon or dimensions redraw correctly');

const summary = `ALL FRESHNESS TESTS PASSED (${checks} checks)\n`;
await writeFile(new URL('../.local/freshness-test-results.txt', import.meta.url), summary);
console.log(summary);
