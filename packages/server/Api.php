<?php
// The save API: JSON over HTTP in front of PageStore, plus writing the public page when
// a version goes live. Publishing writes static HTML and CSS, so the public site runs no
// PHP (R6). handle() takes the request as plain values and returns [status, body], so
// the tests call it without a web server. Spec: docs/design/server.md.

declare(strict_types=1);

namespace WebGenEngine\Server;

use PDO;
use WebGenEngine\Source\RenderError;
use WebGenEngine\Source\Renderer;
use WebGenEngine\Source\SourceError;
use WebGenEngine\Source\SourceFormat;

final class Api
{
    private const PAGE_ID = '[a-z0-9][a-z0-9-]{0,63}';
    private const MAX_SOURCE = 2_000_000;
    private const TITLES = ['top' => 'トップページ'];

    public function __construct(
        private readonly PDO $db,
        private readonly string $siteDir,
        private readonly SourceFormat $format,
        private readonly Renderer $renderer,
        private readonly ?\Closure $now = null,
    ) {
    }

    /**
     * @param array<string, string> $headers lower-case names
     * @return array{0: int, 1: array<string, mixed>}
     */
    public function handle(string $method, string $path, string $body, array $headers): array
    {
        try {
            return $this->route($method, $path, $body, $headers);
        } catch (PageNotLoaded) {
            // Every page is created by load first.
            return self::reply(404, ['ok' => false, 'reason' => 'not-loaded']);
        } catch (\PDOException) {
            return self::reply(500, ['ok' => false, 'reason' => 'storage']);
        }
    }

    private function route(string $method, string $path, string $body, array $headers): array
    {
        if ($method === 'GET' && $path === '/api/ping') {
            return self::reply(200, ['ok' => true]);
        }
        if (preg_match('#^/api/pages/(' . self::PAGE_ID . ')/([a-z-]+)(?:/(\d{1,9}))?$#D', $path, $m) !== 1) {
            return self::reply(404, ['ok' => false, 'reason' => 'not-found']);
        }
        [, $pageId, $action] = $m;
        $versionId = isset($m[3]) ? (int) $m[3] : null;
        $store = new PageStore($this->db, $pageId, $this->now);

        if ($method === 'GET') {
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
        // A page on another site can send a plain form POST here, but not one with a
        // custom header or a JSON content type: those need a CORS preflight, which this
        // API never answers.
        if (($headers['x-wge-request'] ?? '') !== '1' || !str_starts_with($headers['content-type'] ?? '', 'application/json')) {
            return self::reply(403, ['ok' => false, 'reason' => 'forbidden']);
        }
        $input = json_decode($body, true);
        if (!is_array($input)) {
            return self::reply(400, ['ok' => false, 'reason' => 'bad-request']);
        }

        switch ($action) {
            case 'load':
                $source = $input['initialSource'] ?? null;
                if (($problem = $this->checkSource($source)) !== null) {
                    return $problem;
                }
                return self::reply(200, ['ok' => true] + $store->load($source));
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
            case 'publish':
                $revision = $input['revision'] ?? null;
                if (!is_int($revision)) {
                    return self::reply(400, ['ok' => false, 'reason' => 'bad-request']);
                }
                $result = $store->publish($revision);
                if ($result['ok']) {
                    $this->writeSite($pageId, $store->publishedSource());
                }
                return $this->result($result);
            case 'undo-publish':
                $id = $input['versionId'] ?? null;
                if (!is_int($id)) {
                    return self::reply(400, ['ok' => false, 'reason' => 'bad-request']);
                }
                $result = $store->undoPublish($id);
                if ($result['ok']) {
                    $this->writeSite($pageId, $store->publishedSource());
                }
                return $this->result($result);
        }
        return self::reply(404, ['ok' => false, 'reason' => 'not-found']);
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

    // Writes the public page for $pageId, or removes it when nothing is published.
    // Each file is written to a temporary name and renamed, so a reader never sees half a page.
    private function writeSite(string $pageId, ?string $source): void
    {
        $file = $this->siteDir . '/' . ($pageId === 'top' ? 'index.html' : "{$pageId}/index.html");
        if ($source === null) {
            if (is_file($file)) {
                unlink($file);
            }
            return;
        }
        try {
            $html = $this->renderer->renderPage($this->format->parse($source), self::TITLES[$pageId] ?? $pageId, 'ja', $pageId === 'top' ? 'style.css' : '../style.css');
        } catch (SourceError | RenderError $e) {
            // Drafts are checked on save, so this is a bug; the published version stays recorded.
            error_log('wge: cannot render ' . $pageId . ': ' . $e->getMessage());
            return;
        }
        self::writeAtomically($this->siteDir . '/style.css', $this->renderer->stylesheet());
        self::writeAtomically($file, $html);
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
