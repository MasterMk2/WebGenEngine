// node --test packages/editor/store.test.mjs
// The scenarios in packages/server/fixtures/store.json are shared with the PHP store
// (packages/server/test.php), so both keep the same rules.
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createPageStore } from './store.mjs';

// A Web Storage stand-in. `full` makes every write throw, as a full localStorage does.
function memoryStorage() {
  const map = new Map();
  return {
    full: false,
    getItem: (k) => (map.has(k) ? map.get(k) : null),
    setItem(k, v) {
      if (this.full) throw new Error('QuotaExceededError');
      map.set(k, String(v));
    },
  };
}

// Listed keys must be equal; arrays must have the listed length.
function assertSubset(actual, expected, where) {
  if (expected === null || typeof expected !== 'object') {
    assert.deepEqual(actual, expected, where);
    return;
  }
  if (Array.isArray(expected)) {
    assert.ok(Array.isArray(actual), `${where}: not a list`);
    assert.equal(actual.length, expected.length, `${where}: length`);
    expected.forEach((e, i) => assertSubset(actual[i], e, `${where}[${i}]`));
    return;
  }
  assert.ok(actual && typeof actual === 'object', `${where}: not an object`);
  for (const [k, e] of Object.entries(expected)) assertSubset(actual[k], e, `${where}.${k}`);
}

const { scenarios } = JSON.parse(readFileSync(new URL('../server/fixtures/store.json', import.meta.url), 'utf8'));

for (const s of scenarios) {
  test(s.name, async () => {
    let clock = 1_000;
    const store = createPageStore(memoryStorage(), 'top', { now: () => clock, ...s.options });
    for (const [i, step] of s.steps.entries()) {
      if (step.tick !== undefined) {
        clock += step.tick;
        continue;
      }
      const result = await store[step.call](...step.args);
      if ('expect' in step) assertSubset(result, step.expect, `step ${i} ${step.call}`);
    }
  });
}

test('the version list carries no sources', async () => {
  const store = createPageStore(memoryStorage(), 'top');
  await store.load('A');
  await store.saveDraft('B', 0);
  assert.equal((await store.listVersions())[0].source, undefined);
});

test('a refused write reports a storage failure and leaves the draft as it was', async () => {
  const storage = memoryStorage();
  const store = createPageStore(storage, 'top');
  await store.load('A');
  storage.full = true;
  assert.deepEqual(await store.saveDraft('B', 0), { ok: false, reason: 'storage' });
  storage.full = false;
  assert.equal((await store.load('x')).draft.source, 'A');
});
