/* Bounded, session-only GET cache shared by foreground reads and the preload queue. */
(() => {
  'use strict';
  class ReadBuffer {
    constructor(request, { ttl = 60000, limits = { report: 6, detail: 40 }, now = Date.now, onRevision = () => {}, backgroundTimeout = 15000 } = {}) {
      this.request = request;
      this.ttl = ttl;
      this.limits = limits;
      this.now = now;
      this.onRevision = onRevision;
      this.backgroundTimeout = backgroundTimeout;
      this.cache = new Map();
      this.pending = new Map();
      this.generation = 0;
      this.revision = null;
    }

    kind(path) { return path.startsWith('report?') ? 'report' : 'detail'; }

    peek(path) {
      const entry = this.cache.get(path);
      if (!entry) return null;
      if (this.now() - entry.at >= this.ttl) { this.cache.delete(path); return null; }
      this.cache.delete(path);
      this.cache.set(path, entry);
      return entry.value;
    }

    put(path, value) {
      this.cache.delete(path);
      this.cache.set(path, { value, at: this.now() });
      const kind = this.kind(path);
      const keys = [...this.cache.keys()].filter(key => this.kind(key) === kind);
      while (keys.length > this.limits[kind]) this.cache.delete(keys.shift());
    }

    cancel(path) {
      const entry = this.pending.get(path);
      if (entry) { this.pending.delete(path); entry.controller.abort(); }
    }

    pause(except) {
      for (const [path, entry] of this.pending) {
        if (entry.background && path !== except) this.cancel(path);
      }
    }

    clear(resetRevision = true, except) {
      this.generation++;
      this.cache.clear();
      for (const [path, entry] of this.pending) if (entry !== except) this.cancel(path);
      if (except) except.generation = this.generation;
      if (resetRevision) this.revision = null;
    }

    version(revision, except) {
      if (!revision || revision === this.revision) return false;
      const previous = this.revision;
      this.revision = revision;
      if (previous !== null) {
        this.clear(false, except);
        this.onRevision();
      }
      return previous !== null;
    }

    confirm(revision) {
      const changed = this.version(revision);
      // A cheap server check can renew unchanged data without downloading every graph again.
      if (revision && !changed) for (const entry of this.cache.values()) entry.at = this.now();
      return changed;
    }

    get(path, { background = false } = {}) {
      const cached = this.peek(path);
      if (cached) return Promise.resolve(cached);
      const pending = this.pending.get(path);
      if (pending) {
        if (!background) { pending.background = false; clearTimeout(pending.timer); }
        return pending.promise;
      }
      const entry = { controller: new AbortController(), generation: this.generation, background };
      const cancelled = new Promise((_, reject) => {
        entry.controller.signal.addEventListener('abort', () => reject(new DOMException('Read cancelled', 'AbortError')), { once: true });
      });
      if (background && this.backgroundTimeout > 0) entry.timer = setTimeout(() => entry.controller.abort(), this.backgroundTimeout);
      entry.promise = Promise.race([Promise.resolve().then(() => this.request(path, entry.controller.signal)), cancelled]).then(value => {
        if (entry.controller.signal.aborted || entry.generation !== this.generation) throw new DOMException('Read cancelled', 'AbortError');
        this.version(value.revision, entry);
        this.put(path, value);
        return value;
      }).finally(() => {
        clearTimeout(entry.timer);
        if (this.pending.get(path) === entry) this.pending.delete(path);
      });
      this.pending.set(path, entry);
      return entry.promise;
    }
  }
  globalThis.VeryShopReadBuffer = ReadBuffer;
})();
