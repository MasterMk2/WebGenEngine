<?php
// A page's draft, its published version and the list of versions, kept in SQLite (D13).
// The same rules as the editor's store.mjs, checked by the shared scenarios in
// fixtures/store.json: saves carry the revision they started from and a stale one is a
// conflict; publishing names the draft revision that was seen; autosaves keep a version
// at most every $checkpointEvery ms, and only the newest $keepAuto of those.
// Spec: docs/design/editor-ux.md §3.3, docs/design/server.md.

declare(strict_types=1);

namespace WebGenEngine\Server;

use PDO;

final class PageStore
{
    /** @var \Closure(): int */
    private \Closure $now;

    public function __construct(
        private readonly PDO $db,
        private readonly string $pageId,
        ?\Closure $now = null,
        private readonly int $checkpointEvery = 600_000,
        private readonly int $keepAuto = 30,
    ) {
        $this->now = $now ?? static fn (): int => (int) floor(microtime(true) * 1000);
    }

    // Opens (and creates when missing) the database. Times are milliseconds, as in JS.
    public static function openDatabase(string $dsn): PDO
    {
        $db = new PDO($dsn, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db->exec('PRAGMA foreign_keys = ON');
        $db->exec('CREATE TABLE IF NOT EXISTS pages (
            id TEXT PRIMARY KEY,
            draft_source TEXT NOT NULL,
            draft_revision INTEGER NOT NULL,
            draft_saved_at INTEGER,
            published INTEGER,
            next_id INTEGER NOT NULL
        )');
        $db->exec('CREATE TABLE IF NOT EXISTS versions (
            page TEXT NOT NULL REFERENCES pages(id),
            id INTEGER NOT NULL,
            kind TEXT NOT NULL,
            at INTEGER NOT NULL,
            revision INTEGER NOT NULL,
            source TEXT NOT NULL,
            previous INTEGER,
            PRIMARY KEY (page, id)
        )');
        // A page's title and date (a blog post's). Kept apart from the store's own rules (fixtures/store.json).
        $db->exec('CREATE TABLE IF NOT EXISTS page_meta (
            id TEXT PRIMARY KEY REFERENCES pages(id),
            title TEXT NOT NULL,
            date TEXT NOT NULL,
            description TEXT NOT NULL DEFAULT \'\'
        )');
        // Databases made before the description (2026-11-24) get the column.
        $columns = array_column($db->query('PRAGMA table_info(page_meta)')->fetchAll(PDO::FETCH_ASSOC), 'name');
        if (!in_array('description', $columns, true)) {
            $db->exec("ALTER TABLE page_meta ADD COLUMN description TEXT NOT NULL DEFAULT ''");
        }
        // Where a site's page is written (site.md §3); NULL is the place pages had before sites.
        if (!in_array('path', $columns, true)) {
            $db->exec('ALTER TABLE page_meta ADD COLUMN path TEXT');
        }
        // The files a site's publishing wrote (site.md §4): only these are ever replaced or removed.
        // "page" is the page's id, or "" for the stylesheet the pages share.
        $db->exec('CREATE TABLE IF NOT EXISTS site_files (
            path TEXT PRIMARY KEY,
            page TEXT NOT NULL
        )');
        // Pages taken off the list ("削除", blog.md §2). The page's rows stay, so it can be restored.
        $db->exec('CREATE TABLE IF NOT EXISTS deleted_pages (
            id TEXT PRIMARY KEY REFERENCES pages(id),
            at INTEGER NOT NULL
        )');
        return $db;
    }

    public function load(string $initialSource): array
    {
        $this->db->prepare('INSERT OR IGNORE INTO pages (id, draft_source, draft_revision, draft_saved_at, published, next_id) VALUES (?, ?, 0, NULL, NULL, 1)')
            ->execute([$this->pageId, $initialSource]);
        return $this->state($this->page());
    }

    public function saveDraft(string $source, int $baseRevision): array
    {
        return $this->write(function (array $page) use ($source, $baseRevision): array {
            if ($page['draft_revision'] !== $baseRevision) {
                return ['ok' => false, 'reason' => 'conflict', 'draft' => $this->draft($page)];
            }
            $at = ($this->now)();
            $revision = $baseRevision + 1;
            $this->run('UPDATE pages SET draft_source = ?, draft_revision = ?, draft_saved_at = ? WHERE id = ?', [$source, $revision, $at, $this->pageId]);
            $lastAuto = $this->value("SELECT at FROM versions WHERE page = ? AND kind = 'auto' ORDER BY id DESC LIMIT 1");
            $lastSource = $this->value('SELECT source FROM versions WHERE page = ? ORDER BY id DESC LIMIT 1');
            if (($lastAuto === null || $at - (int) $lastAuto >= $this->checkpointEvery) && $source !== $lastSource) {
                $this->addVersion('auto', $at, $revision, $source, null);
                $this->run("DELETE FROM versions WHERE page = ? AND kind = 'auto' AND id NOT IN
                    (SELECT id FROM versions WHERE page = ? AND kind = 'auto' ORDER BY id DESC LIMIT ?)", [$this->pageId, $this->pageId, $this->keepAuto]);
            }
            return ['ok' => true, 'revision' => $revision, 'savedAt' => $at] + $this->state($this->page());
        });
    }

    public function publish(int $revision): array
    {
        return $this->write(function (array $page) use ($revision): array {
            if ($page['draft_revision'] !== $revision) {
                return ['ok' => false, 'reason' => 'stale', 'draft' => $this->draft($page)];
            }
            $version = $this->addVersion('publish', ($this->now)(), $revision, $page['draft_source'], $page['published']);
            $this->run('UPDATE pages SET published = ? WHERE id = ?', [$version['id'], $this->pageId]);
            return ['ok' => true, 'version' => $version] + $this->state($this->page());
        });
    }

    // "公開を取り消す": only the version that is live can be taken back. With $unpublish
    // ("非公開に戻す"), the page goes back to not published at all, however many times it was
    // published before; the draft and the versions stay.
    public function undoPublish(int $versionId, bool $unpublish = false): array
    {
        return $this->write(function (array $page) use ($versionId, $unpublish): array {
            $row = $this->row('SELECT kind, previous FROM versions WHERE page = ? AND id = ?', [$this->pageId, $versionId]);
            if ($row === null || $row['kind'] !== 'publish' || $page['published'] !== $versionId) {
                return ['ok' => false, 'reason' => 'not-live'];
            }
            $this->run('UPDATE pages SET published = ? WHERE id = ?', [$unpublish ? null : $row['previous'], $this->pageId]);
            return ['ok' => true] + $this->state($this->page());
        });
    }

    // "削除" and its restore are the server's only (the editor's store.mjs keeps one page, so the
    // shared scenarios do not have them). Only a page that is not published can be deleted: the
    // check and the write share the write lock, so a publish cannot slip in between. A deleted page
    // answers every call with PageDeleted until it is restored.
    public function delete(): array
    {
        return $this->write(function (array $page): array {
            if ($page['published'] !== null) {
                return ['ok' => false, 'reason' => 'published'];
            }
            $at = ($this->now)();
            $this->run('INSERT INTO deleted_pages (id, at) VALUES (?, ?)', [$this->pageId, $at]);
            return ['ok' => true, 'deletedAt' => $at];
        });
    }

    public function restore(): array
    {
        $stmt = $this->db->prepare('DELETE FROM deleted_pages WHERE id = ?');
        $stmt->execute([$this->pageId]);
        if ($stmt->rowCount() === 0) {
            $this->page(); // a page never loaded is PageNotLoaded
            return ['ok' => false, 'reason' => 'not-deleted'];
        }
        return ['ok' => true] + $this->state($this->page());
    }

    // Newest first, without the sources.
    public function listVersions(): array
    {
        $page = $this->page();
        $stmt = $this->db->prepare('SELECT id, kind, at, revision FROM versions WHERE page = ? ORDER BY id DESC');
        $stmt->execute([$this->pageId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $v) {
            $id = (int) $v['id'];
            $out[] = ['id' => $id, 'kind' => $v['kind'], 'at' => (int) $v['at'], 'revision' => (int) $v['revision'], 'live' => $id === $page['published']];
        }
        return $out;
    }

    public function getVersion(int $id): ?string
    {
        $source = $this->value('SELECT source FROM versions WHERE page = ? AND id = ?', [$this->pageId, $id]);
        return $source === null ? null : (string) $source;
    }

    // The source of the live version, or null when the page is not published.
    public function publishedSource(): ?string
    {
        $page = $this->page();
        return $page['published'] === null ? null : $this->getVersion($page['published']);
    }

    // ------------------------------------------------------------ helpers

    // Runs $fn inside a write transaction on a fresh read of the page. BEGIN IMMEDIATE
    // takes the write lock first, so two saves cannot both pass the revision check.
    private function write(\Closure $fn): array
    {
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $page = $this->page();
            $result = $fn($page);
            $this->db->exec($result['ok'] ? 'COMMIT' : 'ROLLBACK');
            return $result;
        } catch (\Throwable $e) {
            $this->db->exec('ROLLBACK');
            throw $e;
        }
    }

    private function page(): array
    {
        $row = $this->row('SELECT p.*, d.at AS deleted_at FROM pages p LEFT JOIN deleted_pages d ON d.id = p.id WHERE p.id = ?', [$this->pageId]);
        if ($row === null) {
            throw new PageNotLoaded("page {$this->pageId} is not loaded");
        }
        if ($row['deleted_at'] !== null) {
            throw new PageDeleted("page {$this->pageId} is deleted");
        }
        unset($row['deleted_at']);
        foreach (['draft_revision', 'next_id'] as $k) {
            $row[$k] = (int) $row[$k];
        }
        foreach (['draft_saved_at', 'published'] as $k) {
            $row[$k] = $row[$k] === null ? null : (int) $row[$k];
        }
        return $row;
    }

    private function addVersion(string $kind, int $at, int $revision, string $source, ?int $previous): array
    {
        $id = (int) $this->value('SELECT next_id FROM pages WHERE id = ?', [$this->pageId]);
        $this->run('INSERT INTO versions (page, id, kind, at, revision, source, previous) VALUES (?, ?, ?, ?, ?, ?, ?)', [$this->pageId, $id, $kind, $at, $revision, $source, $previous]);
        $this->run('UPDATE pages SET next_id = ? WHERE id = ?', [$id + 1, $this->pageId]);
        return ['id' => $id, 'kind' => $kind, 'at' => $at, 'revision' => $revision];
    }

    private function draft(array $page): array
    {
        return ['source' => $page['draft_source'], 'revision' => $page['draft_revision'], 'savedAt' => $page['draft_saved_at']];
    }

    private function state(array $page): array
    {
        $live = $page['published'] === null ? null
            : $this->row('SELECT id, kind, at, revision, source FROM versions WHERE page = ? AND id = ?', [$this->pageId, $page['published']]);
        return [
            'draft' => $this->draft($page),
            'published' => $live === null ? null : ['id' => (int) $live['id'], 'kind' => $live['kind'], 'at' => (int) $live['at'], 'revision' => (int) $live['revision']],
            'unpublishedChanges' => $live === null || $live['source'] !== $page['draft_source'],
        ];
    }

    private function run(string $sql, array $params): void
    {
        $this->db->prepare($sql)->execute($params);
    }

    private function row(string $sql, array $params): ?array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    // The first column of the first row; the page id is the only parameter unless given.
    private function value(string $sql, ?array $params = null): mixed
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params ?? [$this->pageId]);
        $v = $stmt->fetchColumn();
        return $v === false ? null : $v;
    }
}

class PageNotLoaded extends \RuntimeException
{
}

final class PageDeleted extends PageNotLoaded
{
}
