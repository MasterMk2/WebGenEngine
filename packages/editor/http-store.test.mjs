// node --test packages/editor/http-store.test.mjs
import test from 'node:test';
import assert from 'node:assert/strict';
import { createHttpStore, StoreError } from './http-store.mjs';

// Records each request and answers with `reply(url, init)`: { status, body } or a throw.
function fakeFetch(reply) {
  const calls = [];
  const fetch = async (url, init = {}) => {
    calls.push({ url, init });
    const r = reply(url, init);
    return {
      status: r.status,
      ok: r.status < 300,
      json: async () => (typeof r.body === 'string' ? JSON.parse(r.body) : r.body),
    };
  };
  return { fetch, calls };
}

test('posts JSON with the custom header and drops the marker from the answer', async () => {
  const { fetch, calls } = fakeFetch(() => ({ status: 200, body: { wge: 1, ok: true, revision: 3, savedAt: 5 } }));
  const store = createHttpStore('../api', 'top', { fetch });
  assert.deepEqual(await store.saveDraft('A', 2), { ok: true, revision: 3, savedAt: 5 });
  const { url, init } = calls[0];
  assert.equal(url, '../api/pages/top/draft');
  assert.equal(init.method, 'POST');
  assert.equal(init.headers['x-wge-request'], '1');
  assert.equal(init.headers['content-type'], 'application/json');
  assert.deepEqual(JSON.parse(init.body), { source: 'A', baseRevision: 2 });
  assert.equal(init.keepalive, true);
});

test('a conflict from the API comes back as the API sent it', async () => {
  const draft = { source: 'B', revision: 4, savedAt: 9 };
  const { fetch } = fakeFetch(() => ({ status: 409, body: { wge: 1, ok: false, reason: 'conflict', draft } }));
  const store = createHttpStore('../api', 'top', { fetch });
  assert.deepEqual(await store.saveDraft('A', 2), { ok: false, reason: 'conflict', draft });
});

test('an answer without the marker is a block, such as a WAF page', async () => {
  const waf = fakeFetch(() => ({ status: 403, body: '<html>Forbidden</html>' }));
  assert.deepEqual(await createHttpStore('../api', 'top', waf).saveDraft('A', 0), { ok: false, reason: 'blocked', status: 403 });
  const otherJson = fakeFetch(() => ({ status: 200, body: { ok: true } }));
  assert.equal((await createHttpStore('../api', 'top', otherJson).publish(1)).reason, 'blocked');
});

test('no answer is a network failure', async () => {
  const { fetch } = fakeFetch(() => {
    throw new TypeError('Failed to fetch');
  });
  assert.deepEqual(await createHttpStore('../api', 'top', { fetch }).publish(1), { ok: false, reason: 'network' });
});

test('large drafts are sent without keepalive, which browsers cap', async () => {
  const { fetch, calls } = fakeFetch(() => ({ status: 200, body: { wge: 1, ok: true } }));
  await createHttpStore('../api', 'top', { fetch }).saveDraft('x'.repeat(70_000), 0);
  assert.equal(calls[0].init.keepalive, false);
});

test('reads versions with GET, and load throws when the page cannot be opened', async () => {
  const { fetch, calls } = fakeFetch((url) => {
    if (url.endsWith('/versions')) return { status: 200, body: { wge: 1, ok: true, versions: [{ id: 1 }] } };
    if (url.endsWith('/versions/1')) return { status: 200, body: { wge: 1, ok: true, source: 'A' } };
    if (url.endsWith('/versions/2')) return { status: 404, body: { wge: 1, ok: false, reason: 'not-found' } };
    return { status: 500, body: { wge: 1, ok: false, reason: 'storage' } };
  });
  const store = createHttpStore('../api', 'top', { fetch });
  assert.deepEqual(await store.listVersions(), [{ id: 1 }]);
  assert.equal(calls[0].init.method, undefined);
  assert.equal(await store.getVersion(1), 'A');
  assert.equal(await store.getVersion(2), null);
  await assert.rejects(store.load('A'), (e) => e instanceof StoreError && e.reason === 'storage');
});
