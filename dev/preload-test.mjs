import assert from 'node:assert/strict';
import { writeFile } from 'node:fs/promises';
import { dashboard } from './dashboard-harness.mjs';

let checks = 0;
const passed = label => { checks++; console.log(`PASS: ${label}`); };

let h = dashboard();
h.document.visibilityState = 'hidden'; h.startPreload();
await h.advance(1000);
assert.equal(h.calls.length, 0);
h.document.visibilityState = 'visible'; await h.emit('document', 'visibilitychange');
await h.advance(5000);
assert.ok(h.buffer.peek('products/4?period=90'));
passed('A dashboard first loaded in a hidden tab starts warming when it becomes visible');

h = dashboard(); h.startPreload();
await h.emit('root', 'click', { target: { closest: selector => selector === 'button' ? { dataset: { action: 'open-planning' } } : null } });
await h.advance(1000); assert.equal(h.calls.length, 0);
h.planning.open = false; await h.emit('root', 'close', { target: h.planning });
await h.advance(5000);
assert.ok(h.buffer.peek('products/4?period=90'));
passed('Native planning-dialog dismissal resumes warming without waiting for the freshness interval');

h = dashboard(); h.startPreload(); await h.advance(5000);
assert.equal(h.calls[0].path, 'products/1?period=90');
assert.equal(h.calls[1].path, 'products/2?period=90');
assert.ok(h.buffer.peek('products/4?period=90'));
passed('Visible ranked products warm before the next list page, then its graphs warm in order');

h = dashboard();
const reply = h.reply; let pageAttempts = 0;
h.reply = path => { if (path.startsWith('report?') && ++pageAttempts === 1) throw new Error('temporary failure'); return reply(path); };
h.startPreload(); await h.advance(5000);
assert.ok(h.buffer.peek('products/2?period=90'));
assert.equal(h.buffer.peek('products/4?period=90'), null);
await h.checkFreshness(); await h.advance(5000);
assert.equal(pageAttempts, 2);
assert.ok(h.buffer.peek('products/4?period=90'));
passed('A failed next-page preload retries even when every current-page graph is cached');

h = dashboard(); h.connection.saveData = true; h.startPreload();
await h.advance(1000); assert.equal(h.calls.length, 0);
h.connection.saveData = false; await h.emit('connection', 'change'); await h.advance(5000);
assert.ok(h.buffer.peek('products/4?period=90'));
passed('Leaving data-saving mode resumes a preload that never started');

h = dashboard();
const normal = h.reply;
h.reply = path => path === 'products/1?period=90' ? new Promise(() => {}) : normal(path);
h.startPreload(); await h.advance(20000);
assert.equal(h.calls.find(call => call.path === 'products/1?period=90').signal.aborted, true);
assert.ok(h.buffer.peek('products/2?period=90'));
assert.ok(h.buffer.peek('products/4?period=90'));
passed('A stalled background request times out so the rest of the graph queue can continue');

h = dashboard(); h.document.visibilityState = 'hidden'; h.startPreload();
h.document.visibilityState = 'visible'; h.reply = () => { throw new Error('sync temporarily unavailable'); };
await h.emit('window', 'focus');
h.reply = path => path === 'sync' ? { complete: true, revision: 'a' } : path.startsWith('report?') ? { page: 2, pages: 2, rows: [{ id: 3 }, { id: 4 }], revision: 'a' } : h.detail(Number(path.match(/^products\/(\d+)/)[1]));
await h.advance(5000);
assert.ok(h.buffer.peek('products/4?period=90'));
passed('A failed freshness check does not strand the queue after returning to the dashboard');

h = dashboard(); h.startPreload(); await h.advance(5000);
const warmedCalls = h.calls.length;
await h.checkFreshness(); await h.advance(5000);
assert.equal(h.calls.length, warmedCalls + 1);
assert.equal(h.status.textContent, '4 graphs ready');
passed('Resuming a fully warm queue performs only the freshness check without refetching graphs');

h = dashboard(); h.state.tab = 'settings'; h.startPreload();
await h.emit('window', 'focus'); await h.advance(5000);
assert.equal(h.calls.filter(call => call.path.startsWith('products/')).length, 0);
h.state.tab = 'overview'; h.connection.effectiveType = '2g'; h.startPreload();
await h.emit('window', 'online'); await h.advance(5000);
assert.equal(h.calls.filter(call => call.path.startsWith('products/')).length, 0);
h.connection.effectiveType = '4g'; await h.emit('connection', 'change'); await h.advance(5000);
assert.ok(h.buffer.peek('products/4?period=90'));
passed('Resume events respect the Settings view and slow connections, then recover when eligible');

h = dashboard(); h.document.visibilityState = 'hidden'; h.startPreload();
h.document.visibilityState = 'visible';
const quickReply = h.reply;
h.reply = (path, options) => path === 'sync' ? new Promise((_, reject) => options.signal.addEventListener('abort', () => reject(new DOMException('timeout', 'AbortError')), { once: true })) : quickReply(path);
const freshness = h.checkFreshness(); await h.advance(16000); await freshness;
assert.ok(h.buffer.peek('products/4?period=90'));
passed('A stalled freshness request times out and cannot leave first-time preloading locked');

h = dashboard(); h.document.visibilityState = 'hidden'; h.startPreload();
h.document.visibilityState = 'visible'; h.reply = () => ({ complete: true, revision: 'new' });
h.buffer.version('old'); await h.checkFreshness(); await h.advance(190);
assert.equal(h.calls.filter(call => call.path.startsWith('products/')).length, 0);
passed('A changed revision never starts an automatic foreground graph refresh');

h = dashboard(); h.navigator.onLine = false; h.startPreload();
await h.advance(1000); assert.equal(h.calls.length, 0);
h.navigator.onLine = true; await h.emit('window', 'online'); await h.advance(5000);
assert.ok(h.buffer.peek('products/4?period=90'));
passed('Coming back online starts warming even when the offline dashboard had no preload targets');

h = dashboard();
let finishOld;
const periodReply = h.reply;
h.reply = path => path === 'products/1?period=90' ? new Promise(resolve => { finishOld = resolve; }) : periodReply(path);
h.startPreload(); await h.advance(180);
h.state.period = '30'; h.startPreload(); await h.advance(5000);
finishOld({ revision: 'a' }); await h.advance(0);
assert.ok(h.buffer.peek('products/4?period=30'));
assert.equal(h.buffer.peek('products/1?period=90'), null);
assert.equal(h.calls.filter(call => call.path === 'products/2?period=90').length, 0);
assert.equal(h.status.textContent, '4 graphs ready');
passed('Changing the history period replaces an active queue and ignores its late cancelled response');

const summary = `ALL PRELOAD TESTS PASSED (${checks} checks)\n`;
await writeFile(new URL('../.local/preload-test-results.txt', import.meta.url), summary);
console.log(summary);
