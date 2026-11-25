<?php
// The save API: JSON over HTTP in front of PageStore, plus writing the public page when
// a version goes live. Publishing writes static HTML and CSS, so the public site runs no
// PHP (R6). handle() takes the request as plain values and returns [status, body], so
// the tests call it without a web server. Spec: docs/design/server.md, and for the list of
// pages, titles, images and the blog's contents page docs/design/blog.md.

declare(strict_types=1);

namespace WebGenEngine\Server;

use PDO;
use WebGenEngine\Source\Document;
use WebGenEngine\Source\Node;
use WebGenEngine\Source\RenderError;
use WebGenEngine\Source\Renderer;
use WebGenEngine\Source\SourceError;
use WebGenEngine\Source\SourceFormat;

final class Api
{
    private const PAGE_ID = '[a-z0-9][a-z0-9-]{0,63}';
    private const MAX_SOURCE = 2_000_000;
    private const MAX_TITLE = 120;
    /** A description for search results and sharing; longer ones are cut there anyway. */
    private const MAX_DESCRIPTION = 300;
    /** An image's or a PDF's bytes at most; the editor makes photos smaller before it sends them.
     *  As base64 in JSON this is about 3.4 MB, so it passes a relay in front that allows 4 MB. */
    public const MAX_IMAGE = 2_500_000;

    /** A site's map for search engines, at the site's root (site.md §4.1). */
    private const SITEMAP = 'sitemap.xml';
    private const TITLES = ['top' => 'トップページ'];
    /** Rewrites a blog's parts' pictures for its contents page (relink), made when first needed. */
    private ?Site $blogLinks = null;

    public function __construct(
        private readonly PDO $db,
        private readonly string $siteDir,
        private readonly SourceFormat $format,
        private readonly Renderer $renderer,
        private readonly ?\Closure $now = null,
        /** @var list<string>|null Host headers to accept, such as "127.0.0.1:8766"; null accepts any */
        private readonly ?array $hosts = null,
        /** Where the published site is seen, when it is not under ../site/ (Front.php, server.md §6) */
        private readonly ?string $siteUrl = null,
        /** With a blog, "top" is its contents page, made from the published posts (blog.md) */
        private readonly ?Blog $blog = null,
        /** With a site, each page is written where its path says, and only files WGE wrote are touched (site.md) */
        private readonly ?Site $site = null,
    ) {
    }

    /** The header and footer (site.md §5): a site's, and since 2026-11-25 a blog's too (blog.md §8). */
    private function hasParts(): bool
    {
        return $this->site !== null || $this->blog !== null;
    }

    // A blog's parts are written in pages one folder down (its posts) and in its contents page;
    // only their pictures are rewritten for the contents page (Site::relink, blog.md §8).
    private function relink(Node $doc, int $depth): void
    {
        if ($this->site !== null) {
            $this->site->relink($doc, $depth);
            return;
        }
        $this->blogLinks ??= new Site($this->blog->title(), $this->format->schema());
        $this->blogLinks->relink($doc, $depth, false);
    }

    /**
     * @param array<string, string> $headers lower-case names
     * @return array{0: int, 1: array<string, mixed>}
     */
    public function handle(string $method, string $path, string $body, array $headers): array
    {
        try {
            return $this->route($method, $path, $body, $headers);
        } catch (PageDeleted) {
            // Restored from the list of deleted pages, it opens again (blog.md §2).
            return self::reply(404, ['ok' => false, 'reason' => 'deleted']);
        } catch (PageNotLoaded) {
            // Every page is created by load first.
            return self::reply(404, ['ok' => false, 'reason' => 'not-loaded']);
        } catch (\PDOException) {
            return self::reply(500, ['ok' => false, 'reason' => 'storage']);
        }
    }

    private function route(string $method, string $path, string $body, array $headers): array
    {
        // A site that points its own name at 127.0.0.1 (DNS rebinding) would be same-origin
        // with this API and could send the headers checked below; its Host header gives it away.
        if ($this->hosts !== null && !in_array(strtolower($headers['host'] ?? ''), $this->hosts, true)) {
            return self::reply(403, ['ok' => false, 'reason' => 'forbidden']);
        }
        if ($method === 'GET' && $path === '/api/ping') {
            return self::reply(200, ['ok' => true] + ($this->siteUrl !== null ? ['siteUrl' => $this->siteUrl] : []) + ($this->blog !== null ? ['blog' => true, 'blogTitle' => $this->blog->title()] : [])
                + ($this->site !== null ? ['site' => true, 'siteName' => $this->site->name()] : []) + ($this->hasParts() ? ['parts' => true] : []));
        }
        if ($method === 'GET' && $path === '/api/pages') {
            // The header and footer are listed apart from the pages (site.md §5, blog.md §8).
            if ($this->withBlog()) {
                return self::reply(200, ['ok' => true, 'pages' => $this->listPages(false, 'post'), 'sitePages' => $this->listPages(false, 'page'), 'parts' => $this->listParts()]);
            }
            return self::reply(200, ['ok' => true, 'pages' => $this->listPages(false)] + ($this->hasParts() ? ['parts' => $this->listParts()] : []));
        }
        if ($method === 'GET' && $path === '/api/deleted-pages') {
            if ($this->withBlog()) {
                $deleted = $this->listDeleted();
                return self::reply(200, ['ok' => true, 'pages' => array_values(array_filter($deleted, fn (array $p): bool => $this->isPost($p['id']))), 'sitePages' => array_values(array_filter($deleted, fn (array $p): bool => !$this->isPost($p['id'])))]);
            }
            return self::reply(200, ['ok' => true, 'pages' => $this->listDeleted()]);
        }
        if ($path === '/api/media') {
            if ($method !== 'POST') {
                return self::reply(405, ['ok' => false, 'reason' => 'method']);
            }
            [$problem, $input] = self::postInput($body, $headers);
            return $problem ?? $this->saveMedia($input);
        }
        if (preg_match('#^/api/pages/(' . self::PAGE_ID . ')/([a-z-]+)(?:/(\d{1,9}))?$#D', $path, $m) !== 1) {
            return self::reply(404, ['ok' => false, 'reason' => 'not-found']);
        }
        [, $pageId, $action] = $m;
        // With a blog, its contents page is made from the posts and never edited.
        if ($this->blog !== null && $this->site === null && $pageId === 'top') {
            return self::reply(404, ['ok' => false, 'reason' => 'not-found']);
        }
        // A site's or a blog's own parts are its header and footer only.
        $isPart = $this->hasParts() && Site::isPart($pageId);
        if ($this->hasParts() && !$isPart && str_starts_with($pageId, Site::PART_PREFIX)) {
            return self::reply(404, ['ok' => false, 'reason' => 'not-found']);
        }
        $versionId = isset($m[3]) ? (int) $m[3] : null;
        $store = new PageStore($this->db, $pageId, $this->now);

        if ($method === 'GET') {
            // Writes are refused inside PageStore's write lock; a read only needs this.
            if ($this->isDeleted($pageId)) {
                throw new PageDeleted("page {$pageId} is deleted");
            }
            if ($action === 'versions' && $versionId === null) {
                return self::reply(200, ['ok' => true, 'versions' => $store->listVersions()]);
            }
            if ($action === 'versions') {
                $source = $store->getVersion($versionId);
                return $source === null
                    ? self::reply(404, ['ok' => false, 'reason' => 'not-found'])
                    : self::reply(200, ['ok' => true, 'source' => $source]);
            }
            return self::reply(404, ['ok' => false, 'reason' => 'not-found']);
        }
        if ($method !== 'POST' || $versionId !== null) {
            return self::reply(405, ['ok' => false, 'reason' => 'method']);
        }
        [$problem, $input] = self::postInput($body, $headers);
        if ($problem !== null) {
            return $problem;
        }

        switch ($action) {
            case 'load':
                $source = $input['initialSource'] ?? null;
                if (($problem = $this->checkSource($source)) !== null) {
                    return $problem;
                }
                // A new page may come with its title and date (a new blog post), or a site's page with its path.
                $meta = $input['meta'] ?? null;
                // The header and footer have no title nor path.
                if ($isPart && $meta !== null) {
                    return self::reply(409, ['ok' => false, 'reason' => 'part']);
                }
                // In a site with a blog, a new page given no path is a post (site.md §9).
                $post = $this->withBlog() && is_array($meta) && !array_key_exists('path', $meta) && $pageId !== 'top';
                if ($meta !== null && ($problem = $this->checkMeta($meta, $post)) !== null) {
                    return $problem;
                }
                if ($meta !== null && $this->site !== null) {
                    // An id that has its title already is another page: never open it in its place.
                    if ($this->metaOf($pageId) !== null) {
                        return self::reply(409, ['ok' => false, 'reason' => 'exists']);
                    }
                    if (!$post && ($problem = $this->checkPathFree($pageId, $meta['path'] ?? null)) !== null) {
                        return $problem;
                    }
                }
                $state = $store->load($source);
                if ($meta !== null && $this->metaOf($pageId) === null) {
                    $this->setMeta($pageId, $meta['title'], $meta['date'] ?? '', $meta['description'] ?? '', $this->site !== null && !$post ? ($meta['path'] ?? null) : null);
                }
                // A site's page opened without its title yet (the top page, opened directly) gets the
                // fields filled as it is written now, so its title and path can be set in the editor.
                $kept = $this->metaOf($pageId);
                if ($kept === null && $this->site !== null && !$isPart && !$this->isPost($pageId)) {
                    $kept = ['title' => self::TITLES[$pageId] ?? $pageId, 'date' => '', 'description' => '', 'path' => $this->pathOf($pageId), 'livePath' => $this->livePath($pageId)] + ($this->withBlog() ? ['kind' => 'page'] : []);
                }
                return self::reply(200, ['ok' => true] + $state + ['meta' => $kept]);
            case 'draft':
                $source = $input['source'] ?? null;
                $base = $input['baseRevision'] ?? null;
                if (!is_int($base)) {
                    return self::reply(400, ['ok' => false, 'reason' => 'bad-request']);
                }
                if (($problem = $this->checkSource($source)) !== null) {
                    return $problem;
                }
                return $this->result($store->saveDraft($source, $base));
            case 'meta':
                if ($isPart) {
                    return self::reply(409, ['ok' => false, 'reason' => 'part']);
                }
                $post = $this->isPost($pageId);
                if (($problem = $this->checkMeta($input, $post)) !== null) {
                    return $problem;
                }
                $published = $store->publishedSource();
                $path = $this->site !== null && !$post ? ($input['path'] ?? null) : null;
                if (($problem = $this->checkPathFree($pageId, $path)) !== null) {
                    return $problem;
                }
                // Without "description" (or a site's "path"), the one kept stays.
                $this->setMeta($pageId, $input['title'], $input['date'] ?? '', $input['description'] ?? null, $path);
                // A new path takes effect when the page is next published (site.md §3), so a published
                // page is written again where it is now.
                if ($published !== null) {
                    $this->writeSite($pageId, $published);
                    // Its new title or date shows in the posts next to it, which may be others now.
                    if ($this->isBlogPost($pageId)) {
                        $this->rewriteBlogPosts();
                    }
                }
                $this->writeBlog();
                return self::reply(200, ['ok' => true, 'meta' => $this->metaOf($pageId)]);
            case 'publish':
                $revision = $input['revision'] ?? null;
                if (!is_int($revision)) {
                    return self::reply(400, ['ok' => false, 'reason' => 'bad-request']);
                }
                // A site's page is not published over a file WGE did not write (site.md §4).
                if ($this->site !== null && !$this->isPost($pageId) && ($problem = $this->checkWritable($pageId)) !== null) {
                    return $problem;
                }
                $result = $store->publish($revision);
                if ($result['ok']) {
                    $this->writeSite($pageId, $store->publishedSource(), true);
                    // The posts on either side now link to it (blog.md §10).
                    if ($this->isBlogPost($pageId)) {
                        $this->rewriteBlogPosts();
                    }
                    $this->writeBlog();
                }
                return $this->result($result);
            case 'undo-publish':
                $id = $input['versionId'] ?? null;
                // "unpublish": back to not published at all, not to the version before.
                $unpublish = $input['unpublish'] ?? false;
                if (!is_int($id) || !is_bool($unpublish)) {
                    return self::reply(400, ['ok' => false, 'reason' => 'bad-request']);
                }
                $result = $store->undoPublish($id, $unpublish);
                if ($result['ok']) {
                    $this->writeSite($pageId, $store->publishedSource());
                    if ($this->isBlogPost($pageId)) {
                        $this->rewriteBlogPosts();
                    }
                    $this->writeBlog();
                }
                return $this->result($result);
            case 'delete':
                // The site's own first page stays (without a blog, the editor opens it by default).
                if ($pageId === 'top') {
                    return self::reply(409, ['ok' => false, 'reason' => 'top']);
                }
                if ($isPart) {
                    return self::reply(409, ['ok' => false, 'reason' => 'part']);
                }
                // A published page is taken back first, so nothing on the site changes here.
                return $this->result($store->delete());
            case 'restore':
                return $this->result($store->restore());
        }
        return self::reply(404, ['ok' => false, 'reason' => 'not-found']);
    }

    // A page on another site can send a plain form POST here, but not one with a custom header
    // or a JSON content type: those need a CORS preflight, which this API never answers.
    private static function postInput(string $body, array $headers): array
    {
        if (($headers['x-wge-request'] ?? '') !== '1' || !str_starts_with($headers['content-type'] ?? '', 'application/json')) {
            return [self::reply(403, ['ok' => false, 'reason' => 'forbidden']), null];
        }
        $input = json_decode($body, true);
        if (!is_array($input)) {
            return [self::reply(400, ['ok' => false, 'reason' => 'bad-request']), null];
        }
        return [null, $input];
    }

    private function result(array $result): array
    {
        return self::reply($result['ok'] ? 200 : 409, $result);
    }

    // Drafts must read back as a page, so the public page can always be written from one.
    private function checkSource(mixed $source): ?array
    {
        if (!is_string($source) || strlen($source) > self::MAX_SOURCE || !mb_check_encoding($source, 'UTF-8')) {
            return self::reply(400, ['ok' => false, 'reason' => 'bad-request']);
        }
        try {
            $this->format->parse($source);
        } catch (SourceError $e) {
            return self::reply(422, ['ok' => false, 'reason' => 'invalid', 'code' => $e->errorCode, 'line' => $e->sourceLine, 'column' => $e->sourceColumn]);
        }
        return null;
    }

    // A title of one line (1–120 characters) and a real date written YYYY-MM-DD. A site's page needs
    // no date, and may have a path (site.md §3).
    private function checkMeta(mixed $meta, bool $post = false): ?array
    {
        $title = is_array($meta) ? ($meta['title'] ?? null) : null;
        $date = is_array($meta) ? ($meta['date'] ?? null) : null;
        if (!is_string($title) || !mb_check_encoding($title, 'UTF-8') || trim($title) === '' || mb_strlen($title) > self::MAX_TITLE || preg_match('/[\x00-\x1f\x7f]/', $title) === 1) {
            return self::reply(422, ['ok' => false, 'reason' => 'invalid-title']);
        }
        $noDate = $this->site !== null && !$post && ($date === null || $date === '');
        if (!$noDate && (!is_string($date) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $date, $d) !== 1 || !checkdate((int) $d[2], (int) $d[3], (int) $d[1]))) {
            return self::reply(422, ['ok' => false, 'reason' => 'invalid-date']);
        }
        if ($this->site !== null && array_key_exists('path', $meta) && ($post || !Site::validPath($meta['path']))) {
            return self::reply(422, ['ok' => false, 'reason' => 'invalid-path']);
        }
        // An optional description of one line, up to 300 characters; empty means none.
        $description = $meta['description'] ?? '';
        if (!is_string($description) || !mb_check_encoding($description, 'UTF-8') || mb_strlen($description) > self::MAX_DESCRIPTION || preg_match('/[\x00-\x1f\x7f]/', $description) === 1) {
            return self::reply(422, ['ok' => false, 'reason' => 'invalid-description']);
        }
        return null;
    }

    /** @return array{title: string, date: string, description: string, path?: string}|null a site's page has its path too */
    private function metaOf(string $pageId): ?array
    {
        $q = $this->db->prepare('SELECT title, date, description FROM page_meta WHERE id = ?');
        $q->execute([$pageId]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $meta = ['title' => (string) $row['title'], 'date' => (string) $row['date'], 'description' => (string) $row['description']];
        if ($this->site === null) {
            return $meta;
        }
        if ($this->isPost($pageId)) {
            return $meta + ['kind' => 'post'];
        }
        // A site's page: where it goes when next published, and where it is now (null: nowhere).
        return $meta + ['path' => $this->pathOf($pageId), 'livePath' => $this->livePath($pageId)] + ($this->withBlog() ? ['kind' => 'page'] : []);
    }

    // A null description (or path) keeps the one stored (or none, for a new row).
    private function setMeta(string $pageId, string $title, string $date, ?string $description, ?string $path = null): void
    {
        $description = $description === null ? null : trim($description);
        $this->db->prepare('INSERT INTO page_meta (id, title, date, description, path) VALUES (?, ?, ?, COALESCE(?, \'\'), ?)
            ON CONFLICT(id) DO UPDATE SET title = excluded.title, date = excluded.date, description = COALESCE(?, page_meta.description), path = COALESCE(?, page_meta.path)')
            ->execute([$pageId, trim($title), $date, $description, $path, $description, $path]);
    }

    // ------------------------------------------------------------ a site with a blog (site.md §9)

    private function withBlog(): bool
    {
        return $this->site !== null && $this->blog !== null;
    }

    /**
     * In a site with a blog, whether $pageId is one of the blog's posts: neither the top page nor a
     * part, and given no path (a site's page is given one when it is made, site.md §9). Decided
     * here only; the list and the meta tell it as "kind".
     */
    private function isPost(string $pageId): bool
    {
        if (!$this->withBlog() || $pageId === 'top' || Site::isPart($pageId)) {
            return false;
        }
        $q = $this->db->prepare('SELECT path FROM page_meta WHERE id = ?');
        $q->execute([$pageId]);
        $path = $q->fetchColumn();
        return !is_string($path) || $path === '';
    }

    // Where the blog's files are: the site folder for a blog alone, its blog/ folder in a site.
    private function blogDir(): string
    {
        return $this->withBlog() ? $this->siteDir . '/blog' : $this->siteDir;
    }

    // ------------------------------------------------------------ a site's paths (site.md §3, §4)

    /** Where a site's page is written when it is next published. */
    private function pathOf(string $pageId): string
    {
        $q = $this->db->prepare('SELECT path FROM page_meta WHERE id = ?');
        $q->execute([$pageId]);
        $path = $q->fetchColumn();
        return is_string($path) && $path !== '' ? $path : Site::defaultPath($pageId);
    }

    /** Where a site's page is written now, by the list of files WGE wrote; null when it is not. */
    private function livePath(string $pageId): ?string
    {
        $q = $this->db->prepare('SELECT path FROM site_files WHERE page = ? ORDER BY path LIMIT 1');
        $q->execute([$pageId]);
        $path = $q->fetchColumn();
        return is_string($path) ? $path : null;
    }

    // A path no other page has, deleted ones too (they may be restored). Null is no change.
    private function checkPathFree(string $pageId, ?string $path): ?array
    {
        if ($this->site === null || $path === null) {
            return null;
        }
        foreach ($this->db->query('SELECT id FROM pages')->fetchAll(PDO::FETCH_COLUMN) as $other) {
            if ((string) $other !== $pageId && !Site::isPart((string) $other) && !$this->isPost((string) $other) && $this->pathOf((string) $other) === $path) {
                return self::reply(409, ['ok' => false, 'reason' => 'path-taken']);
            }
        }
        return null;
    }

    // Before publishing: the page's path and the stylesheet are free, or files WGE wrote for them.
    // The header and footer have no file of their own; the pages they go in are written already.
    private function checkWritable(string $pageId): ?array
    {
        if (Site::isPart($pageId)) {
            return null;
        }
        foreach ([[$this->pathOf($pageId), $pageId], ['style.css', '']] as [$path, $owner]) {
            if (!$this->mayWrite($path, $owner)) {
                return self::reply(409, ['ok' => false, 'reason' => 'path-occupied', 'path' => $path]);
            }
        }
        return null;
    }

    // A file may be written when the list says it is $owner's, or when nothing is there (nor a file
    // where one of its folders would be).
    private function mayWrite(string $path, string $owner): bool
    {
        $q = $this->db->prepare('SELECT page FROM site_files WHERE path = ?');
        $q->execute([$path]);
        $listed = $q->fetchColumn();
        if ($listed !== false) {
            return (string) $listed === $owner;
        }
        $file = $this->siteDir . '/' . $path;
        if (file_exists($file) || is_link($file)) {
            return false;
        }
        for ($dir = dirname($path); $dir !== '.'; $dir = dirname($dir)) {
            if (file_exists($this->siteDir . '/' . $dir) && !is_dir($this->siteDir . '/' . $dir)) {
                return false;
            }
        }
        return true;
    }

    private function writeOwned(string $path, string $owner, string $content): bool
    {
        if (!$this->mayWrite($path, $owner)) {
            error_log('wge: not writing ' . $path . ': a file WGE did not write is there');
            return false;
        }
        self::writeAtomically($this->siteDir . '/' . $path, $content);
        $this->db->prepare('INSERT OR REPLACE INTO site_files (path, page) VALUES (?, ?)')->execute([$path, $owner]);
        return true;
    }

    // Removes a file WGE wrote (never another), then the folders it leaves empty, up to the site's root.
    private function removeOwned(string $path): void
    {
        $q = $this->db->prepare('DELETE FROM site_files WHERE path = ?');
        $q->execute([$path]);
        if ($q->rowCount() === 0) {
            return;
        }
        if (is_file($this->siteDir . '/' . $path)) {
            unlink($this->siteDir . '/' . $path);
        }
        for ($dir = dirname($path); $dir !== '.'; $dir = dirname($dir)) {
            $full = $this->siteDir . '/' . $dir;
            if (!is_dir($full) || (scandir($full) ?: []) !== ['.', '..']) {
                break;
            }
            rmdir($full);
        }
    }

    /**
     * The pages, newest date first (pages without a date last, by id).
     *
     * @return list<array{id: string, title: string, date: string|null, description: string, published: bool, savedAt: int|null}>
     */
    private function listPages(bool $publishedOnly, ?string $kind = null): array
    {
        $rows = $this->db->query('SELECT p.id, p.published, p.draft_saved_at, m.title, m.date, m.description FROM pages p LEFT JOIN page_meta m ON m.id = p.id
            WHERE p.id NOT IN (SELECT id FROM deleted_pages)')->fetchAll(PDO::FETCH_ASSOC);
        $pages = [];
        foreach ($rows as $r) {
            if (($this->blog !== null && $this->site === null && $r['id'] === 'top') || ($publishedOnly && $r['published'] === null) || ($this->hasParts() && Site::isPart((string) $r['id']))) {
                continue;
            }
            $post = $this->isPost((string) $r['id']);
            if ($kind !== null && ($kind === 'post') !== $post) {
                continue;
            }
            $page = [
                'id' => (string) $r['id'],
                'title' => $this->titleOf($r),
                'date' => $r['date'] !== null ? (string) $r['date'] : null,
                'description' => (string) ($r['description'] ?? ''),
                'published' => $r['published'] !== null,
                'savedAt' => $r['draft_saved_at'] !== null ? (int) $r['draft_saved_at'] : null,
            ];
            // A site's page: where it goes when next published, and where it is now (site.md §3).
            if ($post) {
                $pages[] = $page + ['kind' => 'post'];
            } else {
                $pages[] = $this->site !== null ? $page + ['path' => $this->pathOf($page['id']), 'livePath' => $this->livePath($page['id'])] + ($this->withBlog() ? ['kind' => 'page'] : []) : $page;
            }
        }
        if ($this->site !== null && $kind !== 'post') {
            usort($pages, static fn (array $a, array $b): int => [$a['id'] !== 'top', $a['path']] <=> [$b['id'] !== 'top', $b['path']]);
            return $pages;
        }
        usort($pages, static fn (array $a, array $b): int => [$b['date'] ?? '', $b['id']] <=> [$a['date'] ?? '', $a['id']]);
        return $pages;
    }

    /**
     * The deleted pages, the last deleted first.
     *
     * @return list<array{id: string, title: string, date: string|null, deletedAt: int}>
     */
    private function listDeleted(): array
    {
        $rows = $this->db->query('SELECT d.id, d.at, m.title, m.date FROM deleted_pages d LEFT JOIN page_meta m ON m.id = d.id ORDER BY d.at DESC, d.id')->fetchAll(PDO::FETCH_ASSOC);
        return array_map(fn (array $r): array => [
            'id' => (string) $r['id'],
            'title' => $this->titleOf($r),
            'date' => $r['date'] !== null ? (string) $r['date'] : null,
            'deletedAt' => (int) $r['at'],
        ], $rows);
    }

    private function titleOf(array $row): string
    {
        return $row['title'] !== null ? (string) $row['title'] : (self::TITLES[$row['id']] ?? (string) $row['id']);
    }

    private function isDeleted(string $pageId): bool
    {
        $q = $this->db->prepare('SELECT 1 FROM deleted_pages WHERE id = ?');
        $q->execute([$pageId]);
        return $q->fetchColumn() !== false;
    }

    // An image for the pages (JPEG, PNG, GIF or WebP, told by its bytes; never SVG, which can carry
    // script), or with "kind": "document" a PDF for a button to open (rendering.md §7.4). Named by
    // its content, so the same file is kept once. Pages one folder down (blog posts, and the
    // editor's preview) reach it as ../media/<name>.
    private function saveMedia(array $input): array
    {
        $kind = $input['kind'] ?? 'image';
        if ($kind !== 'image' && $kind !== 'document') {
            return self::reply(400, ['ok' => false, 'reason' => 'bad-request']);
        }
        $data = $input['data'] ?? null;
        if (!is_string($data) || strlen($data) > intdiv(self::MAX_IMAGE * 4, 3) + 8) {
            return self::reply(413, ['ok' => false, 'reason' => 'too-large']);
        }
        $bytes = base64_decode($data, true);
        if ($bytes === false || $bytes === '') {
            return self::reply(400, ['ok' => false, 'reason' => 'bad-request']);
        }
        if (strlen($bytes) > self::MAX_IMAGE) {
            return self::reply(413, ['ok' => false, 'reason' => 'too-large']);
        }
        $ext = $kind === 'image' ? self::imageType($bytes) : self::documentType($bytes);
        if ($ext === null) {
            return self::reply(415, ['ok' => false, 'reason' => $kind === 'image' ? 'not-image' : 'not-pdf']);
        }
        $name = substr(hash('sha256', $bytes), 0, 16) . '.' . $ext;
        $file = $this->siteDir . '/media/' . $name;
        if (!is_file($file)) {
            self::writeAtomically($file, $bytes);
        }
        if ($ext === 'pdf') {
            return self::reply(200, ['ok' => true, 'src' => '../media/' . $name]);
        }
        // The size, so the page keeps room for the picture before it loads (rendering.md §8 → schema).
        // Read from the header only; an image whose header cannot be read is kept without one.
        $size = @getimagesizefromstring($bytes);
        $dims = is_array($size) && $size[0] >= 1 && $size[1] >= 1 && $size[0] <= 20000 && $size[1] <= 20000
            ? ['width' => (int) $size[0], 'height' => (int) $size[1]] : [];
        return self::reply(200, ['ok' => true, 'src' => '../media/' . $name] + $dims);
    }

    public static function imageType(string $bytes): ?string
    {
        if (str_starts_with($bytes, "\xFF\xD8\xFF")) {
            return 'jpg';
        }
        if (str_starts_with($bytes, "\x89PNG\r\n\x1A\n")) {
            return 'png';
        }
        if (str_starts_with($bytes, 'GIF87a') || str_starts_with($bytes, 'GIF89a')) {
            return 'gif';
        }
        if (strlen($bytes) >= 12 && str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP') {
            return 'webp';
        }
        return null;
    }

    // A PDF, told by the header it starts with (%PDF-1.x or %PDF-2.x) and the end-of-file mark
    // near its end, so a file cut short in sending is not kept as one.
    public static function documentType(string $bytes): ?string
    {
        if (preg_match('/^%PDF-[12]\.\d/', $bytes) !== 1 || !str_contains(substr($bytes, -1024), '%%EOF')) {
            return null;
        }
        return 'pdf';
    }

    // Writes the public page for $pageId, or removes it when nothing is published.
    // Each file is written to a temporary name and renamed, so a reader never sees half a page.
    // A site's page goes to its path when $move (publishing), else where it is now (site.md §3).
    private function writeSite(string $pageId, ?string $source, bool $move = false): void
    {
        if ($this->site !== null) {
            if ($this->isPost($pageId)) {
                $this->writePost($pageId, $source);
            } else {
                $this->writeSitePage($pageId, $source, $move);
            }
            $this->writeSitemap();
            return;
        }
        // A blog's header or footer is in every post and in the contents page (blog.md §8), which
        // the caller writes after this; it has no file of its own.
        if ($this->blog !== null && Site::isPart($pageId)) {
            $this->writeBlogPosts();
            return;
        }
        $file = $this->siteDir . '/' . ($pageId === 'top' ? 'index.html' : "{$pageId}/index.html");
        if ($source === null) {
            if (is_file($file)) {
                unlink($file);
            }
            // The post's own folder goes too, when nothing else is in it.
            if ($pageId !== 'top' && is_dir(dirname($file)) && (scandir(dirname($file)) ?: []) === ['.', '..']) {
                rmdir(dirname($file));
            }
            return;
        }
        $meta = $this->metaOf($pageId);
        $title = $meta['title'] ?? (self::TITLES[$pageId] ?? $pageId);
        try {
            $doc = $this->format->parse($source);
            // Only a blog has parts here: its posts are one folder down (only the published versions go in).
            $depth = $pageId === 'top' ? 0 : 1;
            $html = $this->renderer->renderPage($doc, $title, 'ja', $pageId === 'top' ? 'style.css' : '../style.css', false, $this->pageHead($pageId, $doc, $meta['description'] ?? '', $this->siteUrl), $this->blog !== null ? $this->partOf(Site::HEADER, $depth) : null, $this->blog !== null ? $this->partOf(Site::FOOTER, $depth) : null);
            // A post carries its date and the way back to the contents (blog.md §7).
            if ($this->blog !== null && $pageId !== 'top' && is_string($meta['date'] ?? null)) {
                $html = $this->blog->framePost($html, $meta['date'], ...$this->neighboursOf($pageId));
            }
        } catch (SourceError | RenderError $e) {
            // Drafts are checked on save, so this is a bug; the published version stays recorded.
            error_log('wge: cannot render ' . $pageId . ': ' . $e->getMessage());
            return;
        }
        self::writeAtomically($this->siteDir . '/style.css', $this->renderer->stylesheet());
        self::writeAtomically($file, $html);
    }

    // A post of a site's blog (site.md §9), at blog/<id>/index.html: written as a blog writes its
    // posts, with the site's header and footer, and its addresses from the site's root and its
    // pictures (../media/…, the site's media) made relative to it, as a site's page two folders down.
    private function writePost(string $pageId, ?string $source): void
    {
        $file = $this->blogDir() . '/' . $pageId . '/index.html';
        if ($source === null) {
            if (is_file($file)) {
                unlink($file);
            }
            if (is_dir(dirname($file)) && (scandir(dirname($file)) ?: []) === ['.', '..']) {
                rmdir(dirname($file));
            }
            return;
        }
        $meta = $this->metaOf($pageId);
        $title = $meta['title'] ?? $pageId;
        try {
            $doc = $this->format->parse($source);
            $this->site->relink($doc, 2);
            $html = $this->renderer->renderPage($doc, $title, 'ja', '../style.css', false, $this->pageHead($pageId, $doc, $meta['description'] ?? '', $this->blogUrl()), $this->partOf(Site::HEADER, 2), $this->partOf(Site::FOOTER, 2));
            if (is_string($meta['date'] ?? null) && $meta['date'] !== '') {
                $html = $this->blog->framePost($html, $meta['date'], ...$this->neighboursOf($pageId));
            }
        } catch (SourceError | RenderError $e) {
            error_log('wge: cannot render ' . $pageId . ': ' . $e->getMessage());
            return;
        }
        self::writeAtomically($this->blogDir() . '/style.css', $this->renderer->stylesheet());
        self::writeAtomically($file, $html);
    }

    /** Whether $pageId is one of the blog's posts: a blog alone's pages but its contents and parts, or a site's blog's posts. */
    private function isBlogPost(string $pageId): bool
    {
        if ($this->blog === null || $pageId === 'top' || Site::isPart($pageId)) {
            return false;
        }
        return $this->site === null || $this->isPost($pageId);
    }

    /**
     * The published posts on either side of $pageId, newest first as the contents page lists them
     * (blog.md §10): [newer, older], each {id, title}, or null at an end or when $pageId is not published.
     *
     * @return array{0: array{id: string, title: string}|null, 1: array{id: string, title: string}|null}
     */
    private function neighboursOf(string $pageId): array
    {
        $posts = $this->listPages(true, $this->withBlog() ? 'post' : null);
        $at = array_search($pageId, array_column($posts, 'id'), true);
        if ($at === false) {
            return [null, null];
        }
        $pick = static fn (?array $p): ?array => $p === null ? null : ['id' => $p['id'], 'title' => $p['title']];
        return [$pick($at > 0 ? $posts[$at - 1] : null), $pick($posts[$at + 1] ?? null)];
    }

    // After a post is published, taken back or given a new title or date: every published post again,
    // since the posts next to it changed (blog.md §10). A blog has few posts, so this stays cheap.
    private function rewriteBlogPosts(): void
    {
        if ($this->withBlog()) {
            $this->rewritePosts();
        } else {
            $this->writeBlogPosts();
        }
    }

    // Every published post again (the header or footer changed, or a rebuild).
    private function rewritePosts(): void
    {
        foreach ($this->listPages(true, 'post') as $post) {
            $this->writePost($post['id'], (new PageStore($this->db, $post['id'], $this->now))->publishedSource());
        }
    }

    // Where the blog is seen: the site's URL for a blog alone, its blog/ in a site.
    private function blogUrl(): ?string
    {
        if ($this->siteUrl === null || !$this->withBlog()) {
            return $this->siteUrl;
        }
        return rtrim($this->siteUrl, '/') . '/blog/';
    }

    /**
     * The header and footer: whether each is loaded and published, and when its draft was saved.
     *
     * @return list<array{id: string, loaded: bool, published: bool, savedAt: int|null}>
     */
    private function listParts(): array
    {
        $parts = [];
        foreach ([Site::HEADER, Site::FOOTER] as $id) {
            $q = $this->db->prepare('SELECT published, draft_saved_at FROM pages WHERE id = ?');
            $q->execute([$id]);
            $row = $q->fetch(PDO::FETCH_ASSOC);
            $parts[] = [
                'id' => $id,
                'loaded' => $row !== false,
                'published' => $row !== false && $row['published'] !== null,
                'savedAt' => $row !== false && $row['draft_saved_at'] !== null ? (int) $row['draft_saved_at'] : null,
                // The published version, which every page carries; the editor draws it around a page.
                'source' => $row !== false && $row['published'] !== null ? (new PageStore($this->db, $id, $this->now))->publishedSource() : null,
            ];
        }
        return $parts;
    }

    // The published version of the header or footer, for a page $depth folders down; null when none.
    private function partOf(string $partId, int $depth): ?Document
    {
        try {
            $source = (new PageStore($this->db, $partId, $this->now))->publishedSource();
        } catch (PageNotLoaded) {
            return null;
        }
        if ($source === null) {
            return null;
        }
        $doc = $this->format->parse($source);
        $this->relink($doc, $depth);
        return $doc;
    }

    // Every published post again, after the blog's header or footer is published or taken back.
    private function writeBlogPosts(): void
    {
        foreach (array_column($this->listPages(true), 'id') as $id) {
            $this->writeSite($id, (new PageStore($this->db, $id, $this->now))->publishedSource());
        }
    }

    // After the header or footer is published or taken back: every page written, again where it is.
    private function rewriteAll(): void
    {
        foreach ($this->db->query("SELECT DISTINCT page FROM site_files WHERE page != '' ORDER BY page")->fetchAll(PDO::FETCH_COLUMN) as $id) {
            try {
                $source = (new PageStore($this->db, (string) $id, $this->now))->publishedSource();
            } catch (PageNotLoaded) {
                continue;
            }
            if ($source !== null) {
                $this->writeSitePage((string) $id, $source, false);
            }
        }
    }

    private function writeSitePage(string $pageId, ?string $source, bool $move): void
    {
        if (Site::isPart($pageId)) {
            $this->rewriteAll();
            if ($this->withBlog()) {
                $this->rewritePosts();
            }
            return;
        }
        $live = $this->livePath($pageId);
        if ($source === null) {
            if ($live !== null) {
                $this->removeOwned($live);
            }
            return;
        }
        $path = $move || $live === null ? $this->pathOf($pageId) : $live;
        $meta = $this->metaOf($pageId);
        $title = $meta['title'] ?? (self::TITLES[$pageId] ?? $pageId);
        $depth = Site::depth($path);
        try {
            $doc = $this->format->parse($source);
            // Addresses from the site's root and the editor's pictures, as seen from this page (site.md §3.1).
            $this->site->relink($doc, $depth);
            // Only the published versions of the header and footer go in: never their drafts.
            $html = $this->renderer->renderPage($doc, $title, 'ja', str_repeat('../', $depth) . 'style.css', false, $this->siteHead($path, $doc, $meta['description'] ?? ''), $this->partOf(Site::HEADER, $depth), $this->partOf(Site::FOOTER, $depth));
        } catch (SourceError | RenderError $e) {
            error_log('wge: cannot render ' . $pageId . ': ' . $e->getMessage());
            return;
        }
        if (!$this->writeOwned('style.css', '', $this->renderer->stylesheet()) || !$this->writeOwned($path, $pageId, $html)) {
            return;
        }
        if ($live !== null && $live !== $path) {
            $this->removeOwned($live);
        }
    }

    // The site's map for search engines (site.md §4.1): every page WGE has written, at the address
    // it is seen from WGE_SITE_URL, the first page first. The ledger is the list of what is published
    // where. Without a site URL there are no addresses to write, so nothing is; a sitemap.xml WGE did
    // not write is left alone (writeOwned); with no page published, the one WGE wrote goes.
    private function writeSitemap(): void
    {
        if ($this->siteUrl === null || preg_match('#^https?://[^\s"<>]+$#D', $this->siteUrl) !== 1) {
            return;
        }
        $paths = $this->db->query("SELECT path FROM site_files WHERE page != '' ORDER BY path")->fetchAll(PDO::FETCH_COLUMN);
        $paths = array_values(array_filter(array_map('strval', $paths), static fn (string $p): bool => str_ends_with($p, '.html')));
        usort($paths, static fn (string $a, string $b): int => [$a !== 'index.html', $a] <=> [$b !== 'index.html', $b]);
        if ($this->withBlog() && is_file($this->blogDir() . '/index.html')) {
            $paths[] = 'blog/index.html';
            foreach ($this->listPages(true, 'post') as $post) {
                $paths[] = 'blog/' . $post['id'] . '/index.html';
            }
        }
        if ($paths === []) {
            $this->removeOwned(self::SITEMAP);
            return;
        }
        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";
        foreach ($paths as $path) {
            $url = rtrim($this->siteUrl, '/') . '/' . Site::addressOf($path);
            $xml .= '  <url><loc>' . htmlspecialchars($url, ENT_XML1 | ENT_QUOTES, 'UTF-8') . "</loc></url>\n";
        }
        $this->writeOwned(self::SITEMAP, '', $xml . "</urlset>\n");
    }

    // As pageHead, for a site's page at $path: its address leaves off a last index.html.
    private function siteHead(string $path, Node $doc, string $description): array
    {
        $head = ['description' => $description];
        if ($this->siteUrl === null || preg_match('#^https?://[^\s"<>]+$#D', $this->siteUrl) !== 1) {
            return $head;
        }
        $url = rtrim($this->siteUrl, '/') . '/' . Site::addressOf($path);
        $head += ['url' => $url, 'type' => 'website', 'siteName' => $this->site->name()];
        $src = self::firstImage($doc);
        $image = $src === null ? null : self::resolveUrl($url, $src);
        if ($image !== null) {
            $head['image'] = $image;
        }
        return $head;
    }

    // The description, and where the page is seen for sharing (blog.md §5): the address and the
    // first picture on the page need the site's URL, so without one only the description is written.
    private function pageHead(string $pageId, Node $doc, string $description, ?string $base): array
    {
        $head = ['description' => $description];
        if ($base === null || preg_match('#^https?://[^\s"<>]+$#D', $base) !== 1) {
            return $head;
        }
        $url = rtrim($base, '/') . '/' . ($pageId === 'top' ? '' : "{$pageId}/");
        $head['url'] = $url;
        $head['type'] = $this->blog !== null ? 'article' : 'website';
        if ($this->blog !== null) {
            $head['siteName'] = $this->blog->title();
        }
        $src = self::firstImage($doc);
        $image = $src === null ? null : self::resolveUrl($url, $src);
        if ($image !== null) {
            $head['image'] = $image;
        }
        return $head;
    }

    // A published post's first picture as the contents page sees it (blog.md §7), or null.
    private function contentsImage(string $pageId): ?string
    {
        $source = (new PageStore($this->db, $pageId, $this->now))->publishedSource();
        try {
            $src = $source === null ? null : self::firstImage($this->format->parse($source));
        } catch (SourceError) {
            return null;
        }
        return $src === null ? null : self::contentsImageOf($src, $this->withBlog() ? '../' : '');
    }

    // A picture of a post, as written in it, seen from the contents page one folder up: one the
    // editor uploaded (../media/…) or an http(s) address. Anything else shows no picture.
    public static function contentsImageOf(string $src, string $up = ''): ?string
    {
        if (preg_match('#^\.\./(media/[0-9a-f]{16}\.(?:jpg|png|gif|webp))$#D', $src, $m) === 1) {
            return $up . $m[1];
        }
        return preg_match('#^https?://[^\s"<>]+$#D', $src) === 1 ? $src : null;
    }

    private static function firstImage(Node $node): ?string
    {
        if ($node->type === 'Image' && is_string($node->props['src'] ?? null)) {
            return $node->props['src'];
        }
        foreach ($node->children ?? [] as $child) {
            if (($src = self::firstImage($child)) !== null) {
                return $src;
            }
        }
        return null;
    }

    // $ref against the page's address $base (http or https, ending in "/"): absolute http(s) URLs
    // as they are, paths from the site root and relative paths resolved; anything else is null.
    public static function resolveUrl(string $base, string $ref): ?string
    {
        if (preg_match('#^https?://[^\s"<>]+$#D', $ref) === 1) {
            return $ref;
        }
        if ($ref === '' || preg_match('#^[a-zA-Z][a-zA-Z0-9+.-]*:|^//|[\s"<>\\\\]#', $ref) === 1 || preg_match('#^(https?://[^/]+)(/.*)$#D', $base, $b) !== 1) {
            return null;
        }
        [, $origin, $basePath] = $b;
        $suffix = '';
        if (preg_match('/^([^?#]*)([?#].*)$/sD', $ref, $parts) === 1) {
            [, $ref, $suffix] = $parts;
        }
        $segments = str_starts_with($ref, '/') ? [] : explode('/', substr($basePath, 1, (int) strrpos($basePath, '/')));
        array_pop($segments);
        foreach (explode('/', ltrim($ref, '/')) as $segment) {
            if ($segment === '..') {
                array_pop($segments);
            } elseif ($segment !== '.') {
                $segments[] = $segment;
            }
        }
        return $origin . '/' . implode('/', $segments) . $suffix;
    }

    /**
     * Writes every published page again, with the blog's contents page, its feed and style.css:
     * after a new version of WGE or a new theme, the site looks as the next publish would make it.
     * Drafts and the database are not changed. Returns the ids written (server.md §6).
     *
     * @return list<string>
     */
    public function rebuildSite(): array
    {
        // A site of pages: each page WGE wrote, again where it is, with style.css (only files in
        // the ledger are touched, site.md §4).
        if ($this->withBlog()) {
            $this->rewriteAll();
            $this->rewritePosts();
            $this->writeBlog();
            $this->writeSitemap();
            return array_merge(
                array_map('strval', $this->db->query("SELECT DISTINCT page FROM site_files WHERE page != '' ORDER BY page")->fetchAll(PDO::FETCH_COLUMN)),
                array_column($this->listPages(true, 'post'), 'id'),
            );
        }
        if ($this->site !== null) {
            $this->rewriteAll();
            $this->writeSitemap();
            return array_map('strval', $this->db->query("SELECT DISTINCT page FROM site_files WHERE page != '' ORDER BY page")->fetchAll(PDO::FETCH_COLUMN));
        }
        $ids = array_column($this->listPages(true), 'id');
        if ($this->blog === null && !in_array('top', $ids, true) && $this->db->query("SELECT 1 FROM pages WHERE id = 'top' AND published IS NOT NULL")->fetchColumn() !== false) {
            array_unshift($ids, 'top');
        }
        foreach ($ids as $id) {
            $this->writeSite($id, (new PageStore($this->db, $id, $this->now))->publishedSource());
        }
        $this->writeBlog();
        self::writeAtomically($this->siteDir . '/style.css', $this->renderer->stylesheet());
        return $ids;
    }

    // The blog's contents page and feed, from the published posts (blog.md).
    private function writeBlog(): void
    {
        if ($this->blog === null) {
            return;
        }
        $posts = array_map(
            fn (array $p): array => ['id' => $p['id'], 'title' => $p['title'], 'date' => $p['date'] ?? '1970-01-01', 'description' => $p['description'], 'image' => $this->contentsImage($p['id'])],
            $this->listPages(true, $this->withBlog() ? 'post' : null),
        );
        try {
            // A blog's contents page is its folder's index (depth 0); a site's blog is one folder down.
            $depth = $this->withBlog() ? 1 : 0;
            $files = $this->blog->files($posts, $this->partOf(Site::HEADER, $depth), $this->partOf(Site::FOOTER, $depth));
        } catch (SourceError | RenderError $e) {
            error_log('wge: cannot render the blog contents: ' . $e->getMessage());
            return;
        }
        $dir = $this->blogDir();
        self::writeAtomically($dir . '/style.css', $this->renderer->stylesheet());
        foreach ($files as $name => $content) {
            if ($content === null) {
                if (is_file($dir . '/' . $name)) {
                    unlink($dir . '/' . $name);
                }
            } else {
                self::writeAtomically($dir . '/' . $name, $content);
            }
        }
    }

    private static function writeAtomically(string $file, string $content): void
    {
        $dir = dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $tmp = $dir . '/.' . basename($file) . '.' . bin2hex(random_bytes(4));
        file_put_contents($tmp, $content);
        rename($tmp, $file);
    }

    // Every reply carries "wge", so the editor can tell this API's answers from pages a
    // server's WAF or error handler returns in their place (requirements §3.3).
    private static function reply(int $status, array $body): array
    {
        return [$status, ['wge' => 1] + $body];
    }
}
