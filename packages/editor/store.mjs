// Where a page's source is kept: one draft, the published version and the list of
// versions. The methods have the shape the server's save API will have (docs/design/
// editor-ux.md §3.3), so the editor talks to this the way it will talk to PHP; for now the
// data lives in a Web Storage object (localStorage in the browser, a Map-backed stand-in
// in the tests). Every call reads the stored record again, so a save from another tab is
// seen as a conflict instead of being overwritten.
//
// - saveDraft carries the revision the editor started from (baseRevision); a mismatch is
//   refused as a conflict.
// - publish names the draft revision the user looked at; a draft saved since is refused,
//   so only what was seen goes live.
// - Each publish keeps a version. Autosaves keep a version at most every
//   `checkpointEvery` ms, and only the newest `keepAuto` of those are kept.

export function createPageStore(storage, pageId, { now = () => Date.now(), checkpointEvery = 10 * 60_000, keepAuto = 30 } = {}) {
  const key = `wge-page:${pageId}`;

  function read() {
    const text = storage.getItem(key);
    return text === null ? null : JSON.parse(text);
  }

  // Returns false when the storage refuses the write (full, or blocked).
  function write(record) {
    try {
      storage.setItem(key, JSON.stringify(record));
      return true;
    } catch {
      return false;
    }
  }

  const sourceOf = (record, id) => record.versions.find((v) => v.id === id)?.source ?? null;
  const summary = ({ id, kind, at, revision }) => ({ id, kind, at, revision });

  function state(record) {
    const live = record.published === null ? null : record.versions.find((v) => v.id === record.published);
    return {
      draft: { ...record.draft },
      published: live ? summary(live) : null,
      // Whether the draft differs from what the public site shows.
      unpublishedChanges: live ? live.source !== record.draft.source : true,
    };
  }

  return {
    // Opens the page, creating it from `initialSource` the first time.
    async load(initialSource) {
      let record = read();
      if (!record) {
        record = { draft: { source: initialSource, revision: 0, savedAt: null }, published: null, versions: [], nextId: 1 };
        write(record);
      }
      return state(record);
    },

    async saveDraft(source, baseRevision) {
      const record = read();
      if (record.draft.revision !== baseRevision) return { ok: false, reason: 'conflict', draft: { ...record.draft } };
      const at = now();
      record.draft = { source, revision: baseRevision + 1, savedAt: at };
      const lastAuto = record.versions.findLast((v) => v.kind === 'auto');
      const lastSource = record.versions.at(-1)?.source;
      if ((!lastAuto || at - lastAuto.at >= checkpointEvery) && source !== lastSource) {
        record.versions.push({ id: record.nextId++, kind: 'auto', at, revision: record.draft.revision, source });
        const autos = record.versions.filter((v) => v.kind === 'auto');
        const drop = new Set(autos.slice(0, Math.max(0, autos.length - keepAuto)).map((v) => v.id));
        record.versions = record.versions.filter((v) => !drop.has(v.id));
      }
      if (!write(record)) return { ok: false, reason: 'storage' };
      return { ok: true, revision: record.draft.revision, savedAt: at, ...state(record) };
    },

    async publish(revision) {
      const record = read();
      if (record.draft.revision !== revision) return { ok: false, reason: 'stale', draft: { ...record.draft } };
      const version = { id: record.nextId++, kind: 'publish', at: now(), revision, source: record.draft.source, previous: record.published };
      record.versions.push(version);
      record.published = version.id;
      if (!write(record)) return { ok: false, reason: 'storage' };
      return { ok: true, version: summary(version), ...state(record) };
    },

    // "公開を取り消す": the public site goes back to what it showed before that publish.
    // Only the latest publish can be taken back this way; older ones go through the history.
    async undoPublish(versionId) {
      const record = read();
      const version = record.versions.find((v) => v.id === versionId);
      if (!version || version.kind !== 'publish' || record.published !== versionId) return { ok: false, reason: 'not-live' };
      record.published = version.previous;
      if (!write(record)) return { ok: false, reason: 'storage' };
      return { ok: true, ...state(record) };
    },

    // Newest first, without the sources; `live` marks the published one.
    async listVersions() {
      const record = read();
      return record.versions.map((v) => ({ ...summary(v), live: v.id === record.published })).reverse();
    },

    async getVersion(id) {
      return sourceOf(read(), id);
    },
  };
}
