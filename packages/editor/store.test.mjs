// node --test packages/editor/store.test.mjs
import test from 'node:test';
import assert from 'node:assert/strict';
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

function setup(opts = {}) {
  let clock = 1_000;
  const storage = memoryStorage();
  const store = createPageStore(storage, 'top', { now: () => clock, ...opts });
  return { storage, store, tick: (ms) => { clock += ms; } };
}

test('load creates the draft once and keeps it afterwards', async () => {
  const { storage, store } = setup();
  const first = await store.load('A');
  assert.deepEqual(first.draft, { source: 'A', revision: 0, savedAt: null });
  assert.equal(first.published, null);
  assert.equal(first.unpublishedChanges, true);
  await store.saveDraft('B', 0);
  const again = await createPageStore(storage, 'top').load('ignored');
  assert.equal(again.draft.source, 'B');
  assert.equal(again.draft.revision, 1);
});

test('saveDraft refuses a stale base revision as a conflict', async () => {
  const { store } = setup();
  await store.load('A');
  assert.equal((await store.saveDraft('B', 0)).revision, 1);
  // A second tab that also started from revision 0.
  const res = await store.saveDraft('C', 0);
  assert.equal(res.ok, false);
  assert.equal(res.reason, 'conflict');
  assert.deepEqual(res.draft, { source: 'B', revision: 1, savedAt: 1_000 });
  // Choosing to overwrite: save again on top of the revision seen in the conflict.
  assert.equal((await store.saveDraft('C', res.draft.revision)).revision, 2);
});

test('publish takes only the revision that was seen, and marks it live', async () => {
  const { store } = setup();
  await store.load('A');
  await store.saveDraft('B', 0);
  assert.equal((await store.publish(0)).reason, 'stale');
  const res = await store.publish(1);
  assert.equal(res.ok, true);
  assert.equal(res.unpublishedChanges, false);
  assert.equal(res.published.id, res.version.id);
  assert.equal(await store.getVersion(res.version.id), 'B');
  const after = await store.saveDraft('C', 1);
  assert.equal(after.unpublishedChanges, true, 'editing after publishing leaves the public page as it was');
  assert.equal(after.published.id, res.version.id);
});

test('undoPublish goes back to the page that was live before, or to none', async () => {
  const { store } = setup();
  await store.load('A');
  const first = await store.publish(0);
  await store.saveDraft('B', 0);
  const second = await store.publish(1);
  const undone = await store.undoPublish(second.version.id);
  assert.equal(undone.published.id, first.version.id);
  assert.equal(undone.unpublishedChanges, true);
  // Only the live publish can be taken back.
  assert.equal((await store.undoPublish(second.version.id)).reason, 'not-live');
  const none = await store.undoPublish(first.version.id);
  assert.equal(none.published, null);
});

test('autosaves keep a version at most every checkpoint interval', async () => {
  const { store, tick } = setup({ checkpointEvery: 600_000 });
  await store.load('A');
  let rev = 0;
  for (const s of ['B', 'C', 'D']) {
    rev = (await store.saveDraft(s, rev)).revision;
    tick(60_000);
  }
  tick(600_000);
  rev = (await store.saveDraft('E', rev)).revision;
  const versions = await store.listVersions();
  assert.deepEqual(versions.map((v) => [v.kind, v.revision]), [['auto', 4], ['auto', 1]]);
  assert.equal(await store.getVersion(versions[0].id), 'E');
  assert.equal(versions[0].source, undefined, 'the list carries no sources');
});

test('only the newest autosave versions are kept; publishes stay', async () => {
  const { store, tick } = setup({ checkpointEvery: 0, keepAuto: 2 });
  await store.load('A');
  await store.publish(0);
  let rev = 0;
  for (const s of ['B', 'C', 'D', 'E']) {
    rev = (await store.saveDraft(s, rev)).revision;
    tick(1);
  }
  const versions = await store.listVersions();
  assert.deepEqual(versions.map((v) => v.kind), ['auto', 'auto', 'publish']);
  assert.equal(versions[2].live, true);
});

test('a refused write reports a storage failure and leaves the draft as it was', async () => {
  const { storage, store } = setup();
  await store.load('A');
  storage.full = true;
  assert.deepEqual(await store.saveDraft('B', 0), { ok: false, reason: 'storage' });
  storage.full = false;
  assert.equal((await store.load('x')).draft.source, 'A');
});
