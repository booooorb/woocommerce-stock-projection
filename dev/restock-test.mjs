import assert from 'node:assert/strict';
import { writeFile } from 'node:fs/promises';
import { dashboard } from './dashboard-harness.mjs';
let checks = 0;
const passed = label => { checks++; console.log(`PASS: ${label}`); };
const day = 86400, now = Date.UTC(2026, 9, 27) / 1000;
const h = dashboard(); h.connection.saveData = true;
h.state.selected = 1; h.state.detail = h.detail(1);

let plan = h.buildRestockProjection(100, 5, 400, now, now + 7 * day);
assert.equal(plan.before, 65); assert.equal(plan.after, 465); assert.equal(plan.coverage_days, 93);
assert.equal(plan.stockout_at, now + 100 * day);
passed('A delivery before depletion adds only purchased units to the remaining stock');

plan = h.buildRestockProjection(100, 5, 400, now, now + 30 * day);
assert.equal(plan.before, 0); assert.equal(plan.after, 400); assert.equal(plan.coverage_days, 80);
const points = h.restockPoints(plan, now - day, now + 115 * day);
assert.ok(points.some(point => point.time === now + 20 * day && point.stock === 0));
assert.equal(points.filter(point => point.time === plan.arrival_at).length, 2);
assert.equal(points.find(point => point.time === plan.stockout_at).stock, 0);
passed('Late deliveries preserve the zero-stock gap and the vertical arrival jump');

const zero = h.buildRestockProjection(100, 0, 20, now, now + day);
assert.equal(zero.after, 120); assert.equal(zero.stockout_at, null);
const deficit = h.buildRestockProjection(-10, 5, 15, now, now + day);
assert.equal(deficit.after, 5); assert.equal(deficit.coverage_days, 1);
const insufficient = h.buildRestockProjection(-10, 5, 5, now, now + day);
assert.equal(insufficient.after, -5); assert.equal(insufficient.stockout_at, null);
assert.equal(h.buildRestockProjection(null, 1, 5, now, now + day), null);
passed('Zero depletion, untracked stock and existing deficits do not create fabricated coverage');

assert.equal(h.storeDateTimestamp('2026-11-03'), Date.UTC(2026, 10, 3) / 1000);
assert.equal(h.storeDateTimestamp('2026-02-31'), null);
assert.equal(dashboard({ timezone: 'America/New_York' }).storeDateTimestamp('2026-11-03'), Date.UTC(2026, 10, 3, 5) / 1000);
assert.equal(dashboard({ timezone: 'America/New_York' }).storeDateTimestamp('2026-07-03'), Date.UTC(2026, 6, 3, 4) / 1000);
assert.equal(dashboard({ timezone: '+05:30' }).storeDateTimestamp('2026-11-03'), Date.UTC(2026, 10, 2, 18, 30) / 1000);
passed('Arrival parsing validates calendar dates, daylight saving and fixed-offset store timezones');

const p = h.state.detail.product;
p.stock = 40; p.rate = 5; p.reorder_in = -13; p.reorder_point = 105;
let overdue = h.overdueInfo(p, now);
assert.equal(overdue.time, now - 13 * day); assert.equal(overdue.label, '13 days overdue');
p.planning.on_order = true; assert.equal(h.overdueInfo(p, now), null);
p.planning.on_order = false;
passed('Overdue intervals use the projected reorder crossing and disappear for ordered stock');

h.state.planningOpen = true;
h.state.planningDraft = { quantity: 400, arrival: '2026-11-03', placed_at: now };
assert.equal(h.restockProjection(p).confirmed, false);
assert.equal(p.planning.on_order, false);
h.state.horizon = 'plan'; assert.ok(h.chartHorizon(p) > 30);
assert.match(h.planningForm(), /Confirm order/); assert.doesNotMatch(h.planningForm(), /<h[1-6]|section-kicker/);
passed('Valid form input previews the plan without confirming or suppressing warnings');

const panel = { children: [], style: { minHeight: '560px', paddingTop: '14px', paddingBottom: '0px' } };
const host = { isConnected: true, dataset: { product: '1', period: '90' }, clientWidth: 800, closest: () => panel, innerHTML: '' };
panel.children = [host]; h.drawChart(host);
assert.match(host.innerHTML, /vpa-overdue-period/); assert.match(host.innerHTML, /13 days overdue/);
assert.match(host.innerHTML, /vpa-restock-draft/); assert.match(host.innerHTML, /stroke="#8cbfe8"/);
assert.match(host.innerHTML, /vpa-arrival-marker/); assert.match(host.innerHTML, /vpa-planned-stockout-marker/);
passed('The preview draws light-blue dotted stock, arrival and stock-out markers alongside the overdue interval');

h.state.planningOpen = false; h.state.planningDraft = null;
p.planning = { ...p.planning, quantity: 400, confirmed: true, on_order: true, arrival: '2026-11-03', projection: h.buildRestockProjection(40, 5, 400, now, now + 7 * day) };
host.dataset.renderKey = ''; h.drawChart(host);
assert.match(host.innerHTML, /vpa-restock-ordered/); assert.match(host.innerHTML, /stroke="#2271b1"/);
assert.doesNotMatch(host.innerHTML, /vpa-overdue-period|vpa-overdue-marker/);
assert.match(host.innerHTML, /vpa-stockout-acknowledged/);
assert.match(h.overview(), /Restock Planning/); assert.doesNotMatch(h.overview(), /vpa-fact-overdue/);
passed('Confirmed plans draw dark blue and remove overdue shading, labels and warning styles');

for (const x of [60, 400, 740]) {
  const position = h.historyPopoverPosition(800, 500, 280, 160, x, 250);
  assert.ok(x + 20 <= position.left || x - 20 >= position.left + 280 || position.top >= 500);
}
const docked = h.historyPopoverPosition(278, 260, 262, 160, 130, 120);
assert.ok(docked.top > 260);
passed('Order details leave a cursor gap on desktop and dock below narrow plots');

const summary = `ALL RESTOCK TESTS PASSED (${checks} checks)\n`;
await writeFile(new URL('../.local/restock-test-results.txt', import.meta.url), summary); console.log(summary);
