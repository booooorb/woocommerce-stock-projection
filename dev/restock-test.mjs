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
assert.ok(points.every(point => point.time >= plan.arrival_at));
assert.equal(points.filter(point => point.time === plan.arrival_at).length, 2);
assert.equal(points.find(point => point.time === plan.stockout_at).stock, 0);
assert.equal(points.at(-1).time, plan.stockout_at);
const paths = h.projectionPoints({ stock: 100, rate: 5 }, plan, now, now + 115 * day);
assert.equal(paths.current.at(-1).time, plan.arrival_at);
assert.equal(paths.current.at(-1).stock, 0);
assert.ok(paths.current.some(point => point.time === now + 20 * day && point.stock === 0));
assert.ok(paths.current.at(-1).time <= paths.restock[0].time);
const past = h.projectionPoints({ stock: 50, rate: 5 }, { ...plan, arrival_at: now - day }, now, now + 115 * day);
assert.equal(past.current.length, 0);
assert.ok(past.restock.every(point => point.time >= now));
const far = h.projectionPoints({ stock: 100, rate: 5 }, plan, now, now + 10 * day);
assert.equal(far.restock.length, 0); assert.equal(far.current.at(-1).time, now + 10 * day);
passed('Green ends at delivery; blue begins there and ends at depletion, including late and past arrivals');

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

const alwaysLow = { start: now - 30 * day, today: now, reconstructed: [], recorded: [{ time: now - 30 * day, stock: 80 }, { time: now - 15 * day, stock: 60 }, { time: now, stock: 40 }] };
overdue = h.overdueInfo(p, now, alwaysLow);
assert.equal(overdue.time, alwaysLow.start); assert.equal(overdue.label, '30 days overdue');
assert.equal(overdue.from_start, true);
const priorHigh = { ...alwaysLow, recorded: [{ time: alwaysLow.start, stock: 150 }, ...alwaysLow.recorded.slice(1)] };
assert.equal(h.overdueInfo(p, now, priorHigh).time, now - 13 * day);
const oldCrossing = h.overdueInfo({ ...p, reorder_in: -150 }, now, priorHigh);
assert.equal(oldCrossing.time, priorHigh.start); assert.equal(oldCrossing.label, '30 days overdue');
const wider = { ...alwaysLow, start: now - 90 * day, reconstructed: [{ time: now - 90 * day, stock: 100 }] };
assert.equal(h.overdueInfo(p, now, wider).label, '90 days overdue');
const oldHigh = { ...wider, reconstructed: [{ time: wider.start, stock: 200 }] };
assert.equal(h.overdueInfo(p, now, oldHigh).time, now - 13 * day);
assert.equal(h.overdueInfo(p, now, { ...alwaysLow, recorded: alwaysLow.recorded.slice(1) }).time, now - 13 * day);
assert.equal(p.reorder_in, -13);
passed('An entirely overdue history uses the graph start for its date and duration, including period changes');
h.state.detail.stock_history.recorded[0].stock = 200;

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

const originalHistory = h.state.detail.stock_history;
h.state.detail.stock_history = { ...originalHistory, recorded: [{ time: originalHistory.start, stock: 80 }, { time: now, stock: 40 }] };
host.dataset.renderKey = ''; h.drawChart(host);
const band = host.innerHTML.match(/class="vpa-overdue-period" x="([^"]+)" y="[^"]+" width="([^"]+)"/);
assert.equal(Number(band[1]), 52);
const expectedWidth = (now - originalHistory.start) / (now + h.chartHorizon(p) * day - originalHistory.start) * (800 - 52 - 16);
assert.ok(Math.abs(Number(band[2]) - expectedWidth) < 0.000001);
assert.match(host.innerHTML, /class="vpa-chart-target vpa-overdue-marker"[^>]*aria-label="90 days overdue; reorder date Jul 29, 2026, beginning of displayed history"/);
assert.match(h.detailView(), /<dt>Reorder<\/dt><dd>Jul 29, 2026<\/dd>/);
assert.equal(p.reorder_in, -13);
h.state.detail.stock_history = originalHistory;
passed('The full-history band, marker tooltip, day count and Reorder date all use the same graph start');

h.state.planningOpen = false; h.state.planningDraft = null;
p.planning = { ...p.planning, quantity: 400, confirmed: true, on_order: true, arrival: '2026-11-03', projection: h.buildRestockProjection(40, 5, 400, now, now + 7 * day) };
host.dataset.renderKey = ''; h.drawChart(host);
assert.match(host.innerHTML, /vpa-restock-ordered/); assert.match(host.innerHTML, /stroke="#2271b1"/);
assert.doesNotMatch(host.innerHTML, /vpa-overdue-period|vpa-overdue-marker/);
assert.doesNotMatch(host.innerHTML, /class="vpa-chart-target vpa-stockout-marker/);
assert.match(h.overview(), /Restock Planning/); assert.doesNotMatch(h.overview(), /vpa-fact-overdue/);
passed('Confirmed plans draw dark blue and remove overdue shading, labels and warning styles');

for (const x of [60, 400, 740]) {
  const position = h.historyPopoverPosition(800, 500, 280, 160, x, 250);
  assert.ok(x + 20 <= position.left || x - 20 >= position.left + 280 || position.top >= 500);
}
const docked = h.historyPopoverPosition(278, 260, 262, 160, 130, 120);
assert.ok(docked.top > 260);
passed('Order details leave a cursor gap on desktop and dock below narrow plots');

const form = dashboard(); form.connection.saveData = true;
form.state.selected = 1; form.state.detail = form.detail(1);
const fp = form.state.detail.product;
fp.stockout_date = '2026-11-16'; fp.days_remaining = 20;
const openPlan = target => target.emit('root', 'click', { target: { closest: selector => selector === 'button' ? { dataset: { action: 'open-planning' } } : null } });
await openPlan(form);
assert.equal(form.state.planningDraft.arrival, '2026-11-16');
assert.match(form.planningForm(), /name="arrival"[^>]*value="2026-11-16"/);
assert.equal(form.calls.length, 0); assert.equal(fp.planning.on_order, false);
form.state.planningDraft.arrival = '2026-11-12';
assert.match(form.planningForm(), /name="arrival"[^>]*value="2026-11-12"/);
fp.planning.arrival = '2026-11-09'; await openPlan(form);
assert.equal(form.state.planningDraft.arrival, '2026-11-09');
fp.planning.arrival = ''; fp.stockout_date = null; fp.days_remaining = null;
await openPlan(form); assert.equal(form.state.planningDraft.arrival, '');
fp.stock = 0; await openPlan(form); assert.equal(form.state.planningDraft.arrival, '2026-10-27');
const timezoneForm = dashboard({ timezone: '+05:30' }); timezoneForm.connection.saveData = true;
timezoneForm.state.selected = 1; timezoneForm.state.detail = timezoneForm.detail(1);
timezoneForm.state.detail.product.days_remaining = 0.9;
await openPlan(timezoneForm); assert.equal(timezoneForm.state.planningDraft.arrival, '2026-10-28');
passed('Opening a plan defaults to its stock-out date, preserves saved/custom arrivals and handles no forecast or depleted stock');

const summary = `ALL RESTOCK TESTS PASSED (${checks} checks)\n`;
await writeFile(new URL('../.local/restock-test-results.txt', import.meta.url), summary); console.log(summary);
