import assert from 'node:assert/strict';
import { readFile, writeFile } from 'node:fs/promises';
import vm from 'node:vm';

const source = await readFile(new URL('../plugin/assets/read-buffer.js', import.meta.url), 'utf8');
const scope = { AbortController, DOMException, setTimeout, clearTimeout };
vm.runInNewContext(source, scope);
const Buffer = scope.VeryShopReadBuffer;
let checks = 0;
const passed = label => { checks++; console.log(`PASS: ${label}`); };
const product = (id, period = '90') => `products/${id}?period=${period}`;
const page = n => `report?period=90&page=${n}`;

let time = 0, calls = 0;
const cache = new Buffer(async path => { calls++; return { revision: 'a', path }; }, { now: () => time, ttl: 100, limits: { detail: 2, report: 1 } });
await cache.get(product(1)); await cache.get(product(1));
assert.equal(calls, 1); passed('A warmed graph is reused without another request');
await cache.get(product(1, '30'));
assert.equal(calls, 2); passed('Different history windows never share graph entries');
time = 100;
assert.equal(cache.peek(product(1)), null);
await cache.get(product(1));
assert.equal(calls, 3); passed('Expired inventory is fetched again instead of served indefinitely');
await cache.get(product(2)); cache.peek(product(1)); await cache.get(product(3));
assert.equal(cache.peek(product(2)), null);
assert.ok(cache.peek(product(1)) && cache.peek(product(3)));
passed('The graph buffer evicts the least recently used graph at its limit');
await cache.get(page(1)); await cache.get(page(2));
assert.equal(cache.peek(page(1)), null);
assert.ok(cache.peek(product(1)) && cache.peek(page(2)));
passed('List pages and graphs have independent memory limits');

let resolveRead, signalRead, pendingCalls = 0;
const pending = new Buffer((path, signal) => { pendingCalls++; signalRead = signal; return new Promise(resolve => { resolveRead = resolve; }); });
const warming = pending.get(product(1), { background: true });
await Promise.resolve();
const selected = pending.get(product(1));
pending.pause();
assert.equal(signalRead.aborted, false);
resolveRead({ revision: 'a' });
await Promise.all([warming, selected]);
assert.equal(pendingCalls, 1);
passed('Selecting an in-flight graph promotes it and reuses the same request');

const cancelled = pending.get(product(2), { background: true });
const rejected = assert.rejects(cancelled, { name: 'AbortError' });
await Promise.resolve(); pending.pause();
assert.equal(signalRead.aborted, true);
resolveRead({ revision: 'a' }); await rejected;
assert.equal(pending.peek(product(2)), null);
passed('Foreground work cancels other background reads and discards their late replies');

const obsolete = pending.get(product(3));
const obsoleteRejected = assert.rejects(obsolete, { name: 'AbortError' });
await Promise.resolve(); pending.clear(); resolveRead({ revision: 'old' }); await obsoleteRejected;
assert.equal(pending.peek(product(3)), null);
passed('Clearing after a settings save prevents an old response from repopulating the buffer');

let revision = 'a', changes = 0;
const revised = new Buffer(async () => ({ revision }), { onRevision: () => { changes++; } });
await revised.get(product(1));
revision = 'b'; await revised.get(product(2));
assert.equal(revised.peek(product(1)), null);
assert.equal(revised.peek(product(2)).revision, 'b');
assert.equal(changes, 1);
passed('A changed server revision drops older graphs while retaining the new response');
assert.equal(revised.version('b'), false);
assert.equal(changes, 1);
assert.equal(revised.version('midnight'), true);
assert.equal(revised.peek(product(2)), null);
passed('Freshness checks invalidate changed data and leave matching revisions alone');

time = 0;
const renewed = new Buffer(async () => ({ revision: 'same' }), { now: () => time, ttl: 100 });
await renewed.get(product(1));
time = 150; renewed.confirm('same');
assert.ok(renewed.peek(product(1)));
renewed.confirm('changed');
assert.equal(renewed.peek(product(1)), null);
passed('An unchanged revision renews graph freshness without refetching; a changed one clears it');

let attempts = 0;
const failed = new Buffer(async () => { if (++attempts === 1) throw new Error('offline'); return { revision: 'a' }; });
await assert.rejects(failed.get(product(1)), /offline/);
assert.equal(failed.peek(product(1)), null);
assert.equal((await failed.get(product(1))).revision, 'a');
passed('Failed preloads are not cached and a selected product can retry normally');

let releaseOld, releaseNew;
const raced = new Buffer(path => new Promise(resolve => { if (path === product(1)) releaseOld = resolve; else releaseNew = resolve; }));
raced.version('a');
const old = raced.get(product(1), { background: true });
const oldRejected = assert.rejects(old, { name: 'AbortError' });
const fresh = raced.get(product(2));
await Promise.resolve(); releaseNew({ revision: 'b' }); await fresh;
releaseOld({ revision: 'a' }); await oldRejected;
assert.equal(raced.revision, 'b');
assert.equal(raced.peek(product(1)), null);
passed('An older request finishing last cannot roll the buffer back to an old revision');

const timers = new Map(); let timerId = 0, finishSelected;
const timedScope = { AbortController, DOMException, setTimeout: callback => { const id = ++timerId; timers.set(id, callback); return id; }, clearTimeout: id => timers.delete(id) };
vm.runInNewContext(source, timedScope);
const timed = new timedScope.VeryShopReadBuffer(() => new Promise(resolve => { finishSelected = resolve; }));
const stalled = timed.get(product(1), { background: true });
const timedOut = assert.rejects(stalled, { name: 'AbortError' });
await Promise.resolve();
timers.values().next().value(); await timedOut;
assert.equal(timed.pending.size, 0); assert.equal(timed.peek(product(1)), null);
passed('A timed-out background read releases its pending slot even if transport never settles');

const promoted = timed.get(product(2), { background: true });
await Promise.resolve();
const foreground = timed.get(product(2));
assert.equal(timers.size, 0);
finishSelected({ revision: 'a' }); await Promise.all([promoted, foreground]);
assert.ok(timed.peek(product(2)));
passed('Selecting a background read removes its optional preload deadline and keeps the same request');

const abandoned = timed.get(product(3), { background: true });
const abandonedRejected = assert.rejects(abandoned, { name: 'AbortError' });
await Promise.resolve(); timed.cancel(product(3)); await abandonedRejected;
assert.equal(timers.size, 0); assert.equal(timed.pending.size, 0);
passed('Cancelling a stalled transport settles immediately and clears its timeout');
const summary = `ALL BUFFER TESTS PASSED (${checks} checks)\n`;
await writeFile(new URL('../.local/buffer-test-results.txt', import.meta.url), summary);
console.log(summary);
