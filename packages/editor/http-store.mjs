// The page store over the PHP save API (packages/server/Api.php): the same methods and
// results as store.mjs, so the editor uses either one. Two failures come from outside the
// API and are told apart here:
// - no answer at all becomes { ok: false, reason: 'network' }
// - an answer without the API's "wge" marker becomes { ok: false, reason: 'blocked' }:
//   a page that a server's WAF or error handler sent in its place (requirements §3.3)
// Spec: docs/design/server.md.

// keepalive lets a save started while the page closes finish, but browsers cap its body.
const KEEPALIVE_LIMIT = 60_000;

export class StoreError extends Error {
  constructor(reason) {
    super(`page store: ${reason}`);
    this.reason = reason;
  }
}

export function createHttpStore(base, pageId, { fetch = globalThis.fetch } = {}) {
  async function call(path, body) {
    const init = { cache: 'no-store' };
    if (body !== undefined) {
      const json = JSON.stringify(body);
      Object.assign(init, {
        method: 'POST',
        // The custom header and JSON type make a cross-site request need a preflight,
        // which the API never grants (CSRF).
        headers: { 'content-type': 'application/json', 'x-wge-request': '1' },
        body: json,
        keepalive: json.length < KEEPALIVE_LIMIT,
      });
    }
    let res;
    try {
      res = await fetch(`${base}/pages/${pageId}${path}`, init);
    } catch {
      return { ok: false, reason: 'network' };
    }
    let data = null;
    try {
      data = await res.json();
    } catch {
      // Not JSON: handled as "blocked" below.
    }
    if (data?.wge !== 1) return { ok: false, reason: 'blocked', status: res.status };
    delete data.wge;
    return data;
  }

  return {
    // Unlike the other methods, load throws: the editor cannot start without the page.
    async load(initialSource) {
      const res = await call('/load', { initialSource });
      if (!res.ok) throw new StoreError(res.reason);
      delete res.ok;
      return res;
    },
    saveDraft: (source, baseRevision) => call('/draft', { source, baseRevision }),
    publish: (revision) => call('/publish', { revision }),
    undoPublish: (versionId, unpublish = false) => call('/undo-publish', unpublish ? { versionId, unpublish: true } : { versionId }),
    async listVersions() {
      const res = await call('/versions');
      return res.ok ? res.versions : [];
    },
    async getVersion(id) {
      const res = await call(`/versions/${id}`);
      return res.ok ? res.source : null;
    },
  };
}
