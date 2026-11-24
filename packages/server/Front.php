<?php
// The editor and the save API behind a trusted front: an app that signs its users in, decides
// what each may do, and calls this server with a svc-auth/v1 signature (SvcAuth.php). Every
// request must carry a good signature; the front sends only the paths it allows. The published
// site is not served here: it is served on its own origin, apart from the front's (server.md §6).
// handle() takes the request as plain values and returns [status, headers, body], so the tests
// call it without a web server; trusted-front.php is the entry for PHP's built-in server.

declare(strict_types=1);

namespace WebGenEngine\Server;

final class Front
{
    private const NOT_FOUND = [404, ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-store'], "not found\n"];

    /** @var \Closure(): int */
    private \Closure $now;

    public function __construct(
        private readonly SvcAuth $auth,
        private readonly Api $api,
        private readonly string $packages,
        private readonly string $siteDir,
        /** One JSON line per change (time, actor, path, status); null keeps none */
        private readonly ?string $auditFile = null,
        ?\Closure $now = null,
        /** The editor's sample images beside published pages (not for a blog, whose posts start without them) */
        private readonly bool $copySamples = true,
        /** The site's theme, which the editor's preview reads as source/theme.json; null is the default one */
        private readonly ?array $theme = null,
    ) {
        $this->now = $now ?? static fn (): int => (int) floor(microtime(true) * 1000);
    }

    /**
     * @param string $target the request target as received (path and query, not decoded)
     * @param array<string, string> $headers lower-case names
     * @return array{0: int, 1: array<string, string>, 2: string}
     */
    public function handle(string $method, string $target, string $body, array $headers): array
    {
        $check = $this->auth->verify($method, $target, $body, $headers);
        if (!$check['ok']) {
            // The reason goes to the log only; the person never sees this answer (the front sent nothing wrong).
            error_log('wge: refused a request not signed by the front (' . $check['reason'] . ')');
            return self::json(403, ['ok' => false, 'reason' => 'forbidden']);
        }
        // The front sends plain paths: no query and no escapes.
        if (preg_match('#^/[A-Za-z0-9._/-]*$#D', $target) !== 1 || str_contains($target, '..') || str_contains($target, '//')) {
            return self::NOT_FOUND;
        }
        if (str_starts_with($target, '/api/')) {
            [$status, $reply] = $this->api->handle($method, $target, $body, $headers);
            if ($method === 'POST') {
                $this->record($check['actor'], $target, $status);
                if ($this->copySamples && $status === 200 && preg_match('#^/api/pages/[a-z0-9-]+/(publish|undo-publish)$#D', $target) === 1) {
                    $this->copySampleImages();
                }
            }
            return [$status, ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store'], json_encode($reply, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)];
        }
        // Images of the pages, for the editor's preview (posts reach them as ../media/…). Images only: never SVG.
        if ($method === 'GET' && preg_match('#^/media/([0-9a-f]{16}\.(?:jpg|png|gif|webp))$#D', $target, $m) === 1) {
            $file = StaticFiles::read($this->siteDir . '/media', $m[1]);
            return $file === null ? self::NOT_FOUND : [200, ['Content-Type' => $file[0], 'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff'], $file[1]];
        }
        if ($method === 'GET' && $target === '/source/theme.json') {
            $theme = $this->theme ?? json_decode((string) file_get_contents($this->packages . '/source/theme.default.json'), true, 512, JSON_THROW_ON_ERROR);
            return [200, ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store'], json_encode($theme, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)];
        }
        if ($method === 'GET' && preg_match('#^/(editor|source)/(.*)$#D', $target, $m) === 1) {
            $file = StaticFiles::read($this->packages . '/' . $m[1], $m[2]);
            return $file === null ? self::NOT_FOUND : [200, ['Content-Type' => $file[0], 'Cache-Control' => 'no-store'], $file[1]];
        }
        return self::NOT_FOUND;
    }

    private static function json(int $status, array $body): array
    {
        return [$status, ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store'], json_encode(['wge' => 1] + $body, JSON_THROW_ON_ERROR)];
    }

    // Who changed what, kept beside the data (not in the server's log, which others may read).
    private function record(string $actor, string $path, int $status): void
    {
        if ($this->auditFile === null) {
            return;
        }
        $line = json_encode(['at' => gmdate('Y-m-d\TH:i:s\Z', intdiv(($this->now)(), 1000)), 'actor' => $actor, 'path' => $path, 'status' => $status], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        file_put_contents($this->auditFile, $line . "\n", FILE_APPEND | LOCK_EX);
    }

    // Until there is a media library, the sample page's images come from the editor's sample folder
    // (router.php serves them from there; here the site is served elsewhere, so they are copied).
    private function copySampleImages(): void
    {
        $dir = $this->siteDir . '/sample';
        foreach (glob($this->packages . '/editor/sample/*.svg') ?: [] as $image) {
            $copy = $dir . '/' . basename($image);
            if (!is_file($copy)) {
                if (!is_dir($dir)) {
                    mkdir($dir, 0775, true);
                }
                copy($image, $copy);
            }
        }
    }
}
