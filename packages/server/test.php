<?php
// Tests for the PHP save API. Usage: php packages/server/test.php
// Part 1 runs the scenarios in fixtures/store.json, shared with the editor's store.mjs.
// Part 2 calls Api::handle with requests as the editor sends them.

declare(strict_types=1);

require __DIR__ . '/../source/php/SourceFormat.php';
require __DIR__ . '/../source/php/Renderer.php';
require __DIR__ . '/PageStore.php';
require __DIR__ . '/Api.php';
require __DIR__ . '/Blog.php';
require __DIR__ . '/StaticFiles.php';
require __DIR__ . '/SvcAuth.php';
require __DIR__ . '/Front.php';

use WebGenEngine\Server\Api;
use WebGenEngine\Server\Blog;
use WebGenEngine\Server\Front;
use WebGenEngine\Server\PageStore;
use WebGenEngine\Server\SvcAuth;
use WebGenEngine\Source\Renderer;
use WebGenEngine\Source\SourceFormat;

$failures = [];
$passed = 0;

function check(bool $ok, string $what): void
{
    global $failures, $passed;
    if ($ok) {
        $passed++;
    } else {
        $failures[] = $what;
    }
}

// Listed keys must be equal; lists must have the listed length (as in store.test.mjs).
function subset(mixed $actual, mixed $expected, string $where): ?string
{
    if (!is_array($expected)) {
        return $actual === $expected ? null : "{$where}: " . json_encode($actual, JSON_UNESCAPED_UNICODE) . ' !== ' . json_encode($expected, JSON_UNESCAPED_UNICODE);
    }
    if (!is_array($actual)) {
        return "{$where}: not an array";
    }
    if (array_is_list($expected) && $expected !== []) {
        if (!array_is_list($actual) || count($actual) !== count($expected)) {
            return "{$where}: length " . count($actual) . ' !== ' . count($expected);
        }
    }
    foreach ($expected as $k => $e) {
        if (!array_key_exists($k, $actual)) {
            return "{$where}.{$k}: missing";
        }
        if (($problem = subset($actual[$k], $e, "{$where}.{$k}")) !== null) {
            return $problem;
        }
    }
    return null;
}

// ------------------------------------------------- part 1: shared scenarios

$fixture = json_decode((string) file_get_contents(__DIR__ . '/fixtures/store.json'), true, 512, JSON_THROW_ON_ERROR);
foreach ($fixture['scenarios'] as $s) {
    $clock = 1000;
    $options = $s['options'] ?? [];
    $store = new PageStore(
        PageStore::openDatabase('sqlite::memory:'),
        'top',
        static function () use (&$clock): int {
            return $clock;
        },
        $options['checkpointEvery'] ?? 600_000,
        $options['keepAuto'] ?? 30,
    );
    foreach ($s['steps'] as $i => $step) {
        if (isset($step['tick'])) {
            $clock += $step['tick'];
            continue;
        }
        $result = $store->{$step['call']}(...$step['args']);
        if (array_key_exists('expect', $step)) {
            $problem = subset($result, $step['expect'], "step {$i} {$step['call']}");
            check($problem === null, "{$s['name']}: {$problem}");
        }
    }
}

// ------------------------------------------------------- part 2: the API

$packages = dirname(__DIR__);
$site = sys_get_temp_dir() . '/wge-test-site-' . bin2hex(random_bytes(4));
$api = new Api(
    PageStore::openDatabase('sqlite::memory:'),
    $site,
    SourceFormat::fromSchemaFile($packages . '/source/schema.json'),
    Renderer::fromFiles($packages . '/source'),
);
$json = ['x-wge-request' => '1', 'content-type' => 'application/json'];
$post = static fn (string $path, array $body, array $headers = []) => $api->handle('POST', $path, json_encode($body), $headers + $json);
$page = "<Section>\n  <Heading level=\"1\">喫茶 こもれび</Heading>\n</Section>\n";

[$status, $body] = $api->handle('GET', '/api/ping', '', []);
check($status === 200 && $body['wge'] === 1, 'ping answers with the wge marker');

$local = new Api(PageStore::openDatabase('sqlite::memory:'), $site, SourceFormat::fromSchemaFile($packages . '/source/schema.json'), Renderer::fromFiles($packages . '/source'), hosts: ['127.0.0.1:8766', 'localhost:8766']);
[$status] = $local->handle('GET', '/api/ping', '', ['host' => 'LOCALHOST:8766']);
check($status === 200, 'a listed Host is accepted, in any case');
[$status] = $local->handle('POST', '/api/pages/top/load', json_encode(['initialSource' => '']), $json + ['host' => 'evil.example:8766']);
check($status === 403, 'another Host is refused (DNS rebinding)');
[$status] = $local->handle('GET', '/api/ping', '', []);
check($status === 403, 'a request without a Host is refused when hosts are listed');

[$status, $body] = $post('/api/pages/top/draft', ['source' => $page, 'baseRevision' => 0]);
check($status === 404 && $body['reason'] === 'not-loaded', 'a page must be loaded before saving');

[$status] = $post('/api/pages/top/load', ['initialSource' => $page], ['x-wge-request' => '']);
check($status === 403, 'a POST without the custom header is refused (CSRF)');
[$status] = $post('/api/pages/top/load', ['initialSource' => $page], ['content-type' => 'application/x-www-form-urlencoded']);
check($status === 403, 'a form-encoded POST is refused (CSRF)');
[$status] = $api->handle('POST', '/api/pages/top/load', 'not json', $json);
check($status === 400, 'a body that is not JSON is a bad request');
[$status] = $post('/api/pages/Top!/load', ['initialSource' => $page]);
check($status === 404, 'page ids are limited to lower-case letters, digits and hyphens');

[$status, $body] = $post('/api/pages/top/load', ['initialSource' => $page]);
check($status === 200 && $body['draft']['revision'] === 0 && $body['published'] === null, 'load creates the page');

[$status, $body] = $post('/api/pages/top/draft', ['source' => "<Section>\n", 'baseRevision' => 0]);
check($status === 422 && $body['code'] === 'E_UNCLOSED_TAG' && $body['line'] === 1, 'a draft that does not read back as a page is refused with its line');
[$status] = $post('/api/pages/top/draft', ['source' => $page, 'baseRevision' => '0']);
check($status === 400, 'baseRevision must be a number');

$edited = str_replace('喫茶 こもれび', '喫茶 こもれび 本店', $page);
[$status, $body] = $post('/api/pages/top/draft', ['source' => $edited, 'baseRevision' => 0]);
check($status === 200 && $body['revision'] === 1, 'a draft is saved');
[$status, $body] = $post('/api/pages/top/draft', ['source' => $page, 'baseRevision' => 0]);
check($status === 409 && $body['reason'] === 'conflict' && $body['draft']['source'] === $edited, 'a stale save is a conflict and returns the saved draft');

[$status, $body] = $post('/api/pages/top/publish', ['revision' => 1]);
$index = @file_get_contents($site . '/index.html');
check($status === 200 && $body['version']['id'] === 2, 'publish answers with the new version');
check(is_string($index) && str_contains($index, '喫茶 こもれび 本店') && str_contains($index, 'href="style.css"'), 'publish writes the public page');
check(is_file($site . '/style.css'), 'publish writes the stylesheet');
check(is_string($index) && !str_contains($index, 'data-wge-path'), 'the public page has no editor annotations');

[$status, $body] = $api->handle('GET', '/api/pages/top/versions', '', []);
check($status === 200 && count($body['versions']) === 2 && $body['versions'][0]['live'] === true, 'versions are listed newest first');
[$status, $body] = $api->handle('GET', '/api/pages/top/versions/2', '', []);
check($status === 200 && $body['source'] === $edited, 'a version is read back by id');
[$status] = $api->handle('GET', '/api/pages/top/versions/99', '', []);
check($status === 404, 'an unknown version is a 404');

[$status, $body] = $post('/api/pages/top/undo-publish', ['versionId' => 2]);
check($status === 200 && $body['published'] === null && !is_file($site . '/index.html'), 'taking back the only publish removes the public page');
[$status, $body] = $post('/api/pages/top/undo-publish', ['versionId' => 2]);
check($status === 409 && $body['reason'] === 'not-live', 'a publish that is not live cannot be taken back');

[$status, $body] = $post('/api/pages/menu/load', ['initialSource' => $page]);
[$status] = $post('/api/pages/menu/publish', ['revision' => 0]);
check($status === 200 && is_file($site . '/menu/index.html') && str_contains((string) file_get_contents($site . '/menu/index.html'), 'href="../style.css"'), 'other pages are written to their own folder');

// There are no accounts yet, so nobody may publish raw HTML (rendering.md §7).
$raw = "<Html><script>alert(1)</script><img src=\"x\" onerror=\"alert(1)\"><a href=\"javascript:alert(1)\">a</a><p>本文</p></Html>\n";
[$status] = $post('/api/pages/news/load', ['initialSource' => $raw]);
[$status] = $post('/api/pages/news/publish', ['revision' => 0]);
$news = (string) @file_get_contents($site . '/news/index.html');
check($status === 200 && str_contains($news, '<img src="x"><a>a</a><p>本文</p>') && !str_contains($news, '<script') && !str_contains($news, 'onerror') && !str_contains($news, 'javascript:'), 'publish sanitizes the Html block');

array_map('unlink', glob($site . '/*/*') ?: []);
array_map('rmdir', glob($site . '/*', GLOB_ONLYDIR) ?: []);
array_map('unlink', glob($site . '/*') ?: []);
rmdir($site);

// ------------------------------------------------------- part 3: requests signed by a trusted front

$vectors = json_decode((string) file_get_contents(__DIR__ . '/fixtures/svc-auth.json'), true, 512, JSON_THROW_ON_ERROR);
$key = (string) hex2bin($vectors['keyHex']);
foreach ($vectors['cases'] as $c) {
    check(SvcAuth::canonical($c['client'], $c['method'], $c['path'], $c['timestamp'], $c['nonce'], $c['actor'], $c['body']) === $c['canonical'], "svc-auth canonical string as the front makes it: {$c['name']}");
    check(SvcAuth::sign($key, $c['client'], $c['method'], $c['path'], $c['timestamp'], $c['nonce'], $c['actor'], $c['body']) === $c['signature'], "svc-auth signature as the front makes it: {$c['name']}");
}

check(SvcAuth::parseKey($vectors['keyHex']) === $key, 'a hex key is read');
check(SvcAuth::parseKey(rtrim(strtr(base64_encode($key), '+/', '-_'), '=') . "\n") === $key, 'a base64url key is read');
check(SvcAuth::parseKey(bin2hex(random_bytes(16))) === null, 'a key under 32 bytes is refused');
check(SvcAuth::parseKey(str_repeat('ab', 32)) === null, 'a key of one repeated byte is refused');
check(SvcAuth::parseKey('not a key!') === null, 'text that is neither hex nor base64 is refused');

$clock = 1_795_431_331_287;
$authDb = PageStore::openDatabase('sqlite::memory:');
$auth = new SvcAuth($key, 'front-app', $authDb, static function () use (&$clock): int {
    return $clock;
});
$signed = static function (string $method, string $path, string $body, string $actor = '21000002', array $change = []) use ($key, &$clock): array {
    $h = ['x-svc-client' => 'front-app', 'x-svc-timestamp' => (string) $clock, 'x-svc-nonce' => rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '='), 'x-svc-actor' => $actor];
    $h['x-svc-signature'] = SvcAuth::sign($key, $h['x-svc-client'], $method, $path, $h['x-svc-timestamp'], $h['x-svc-nonce'], $actor, $body);
    if ($actor === '') {
        unset($h['x-svc-actor']);
    }
    return $change + $h;
};

$first = $vectors['cases'][0];
$fromVector = ['x-svc-client' => $first['client'], 'x-svc-timestamp' => $first['timestamp'], 'x-svc-nonce' => $first['nonce'], 'x-svc-actor' => $first['actor'], 'x-svc-signature' => $first['signature']];
$result = $auth->verify($first['method'], $first['path'], $first['body'], $fromVector);
check($result === ['ok' => true, 'actor' => '21000002'], 'a request signed by the front is accepted with its actor');
check(($auth->verify($first['method'], $first['path'], $first['body'], $fromVector)['reason'] ?? null) === 'replay', 'the same nonce is refused the second time');
$h = $signed('GET', '/api/ping', '', '');
check($auth->verify('GET', '/api/ping', '', $h) === ['ok' => true, 'actor' => ''], 'a request without an actor is accepted');
$h = $signed('POST', '/api/pages/top/draft', '{"a":1}');
check(($auth->verify('POST', '/api/pages/top/draft', '{"a":2}', $h)['reason'] ?? '') === 'signature', 'a changed body is refused');
check(($auth->verify('POST', '/api/pages/top/publish', '{"a":1}', $h)['reason'] ?? '') === 'signature', 'a changed path is refused');
check(($auth->verify('GET', '/api/pages/top/draft', '{"a":1}', $h)['reason'] ?? '') === 'signature', 'a changed method is refused');
check(($auth->verify('POST', '/api/pages/top/draft', '{"a":1}', ['x-svc-actor' => 'someone-else'] + $h)['reason'] ?? '') === 'signature', 'a changed actor is refused');
check(($auth->verify('POST', '/api/pages/top/draft', '{"a":1}', $h)['ok'] ?? false) === true, 'a refused request does not use up its nonce');
check(($auth->verify('GET', '/api/ping', '', $signed('GET', '/api/ping', '', '21000002', ['x-svc-client' => 'other-app']))['reason'] ?? '') === 'client', 'another client ID is refused');
$h = $signed('GET', '/api/ping', '');
$clock += 30_001;
check(($auth->verify('GET', '/api/ping', '', $h)['reason'] ?? '') === 'timestamp', 'a request more than 30 s old is refused');
$clock -= 60_002;
check(($auth->verify('GET', '/api/ping', '', $h)['reason'] ?? '') === 'timestamp', 'a request more than 30 s ahead is refused');
$clock += 30_001;
check(($auth->verify('GET', '/api/ping', '', ['x-svc-signature' => strtoupper($h['x-svc-signature'])] + $h)['reason'] ?? '') === 'signature', 'only a lower-case hex signature is read');
check(($auth->verify('GET', '/api/ping', '', [])['reason'] ?? '') === 'client', 'an unsigned request is refused');

// ------------------------------------------------------- part 4: the editor behind the front

$site = sys_get_temp_dir() . '/wge-test-front-' . bin2hex(random_bytes(4));
$audit = $site . '-audit.jsonl';
$frontDb = PageStore::openDatabase('sqlite::memory:');
$front = new Front(
    new SvcAuth($key, 'front-app', $frontDb, static function () use (&$clock): int {
        return $clock;
    }),
    new Api($frontDb, $site, SourceFormat::fromSchemaFile($packages . '/source/schema.json'), Renderer::fromFiles($packages . '/source'), siteUrl: 'https://site.example/wge/'),
    $packages,
    $site,
    $audit,
    static function () use (&$clock): int {
        return $clock;
    },
);
$call = static function (string $method, string $path, string $body = '', array $extra = []) use ($front, $signed): array {
    return $front->handle($method, $path, $body, $extra + $signed($method, $path, $body));
};

[$status, , $out] = $front->handle('GET', '/editor/', '', []);
check($status === 403 && json_decode($out, true) === ['wge' => 1, 'ok' => false, 'reason' => 'forbidden'], 'the front entry refuses an unsigned request');
[$status, $headers, $out] = $call('GET', '/editor/');
check($status === 200 && str_starts_with($headers['Content-Type'], 'text/html') && str_contains($out, 'editor.mjs'), 'a signed request opens the editor');
[$status, $headers] = $call('GET', '/source/js/render.mjs');
check($status === 200 && str_starts_with($headers['Content-Type'], 'text/javascript'), 'the source packages are served for the editor');
foreach (['/server/Api.php', '/source/php/Renderer.php', '/editor/../server/Api.php', '/editor/editor.mjs?x=1', '/editor/%2e%2e/server/Api.php', '/site/index.html', '/'] as $path) {
    [$status] = $call('GET', $path);
    check($status === 404, "the front entry serves nothing else: {$path}");
}
[$status, , $out] = $call('GET', '/api/ping');
check($status === 200 && json_decode($out, true) === ['wge' => 1, 'ok' => true, 'siteUrl' => 'https://site.example/wge/'], 'ping says where the published site is seen');
$json = ['x-wge-request' => '1', 'content-type' => 'application/json'];
[$status] = $call('POST', '/api/pages/top/load', json_encode(['initialSource' => $page]), $json);
[$status, , $out] = $call('POST', '/api/pages/top/publish', json_encode(['revision' => 0]), $json);
check($status === 200 && is_file($site . '/index.html') && is_file($site . '/sample/coffee.svg'), 'publish writes the page, with the sample images beside it');
$lines = array_map(static fn (string $l) => json_decode($l, true), file($audit, FILE_IGNORE_NEW_LINES) ?: []);
check(count($lines) === 2 && $lines[1]['actor'] === '21000002' && $lines[1]['path'] === '/api/pages/top/publish' && $lines[1]['status'] === 200, 'each change is recorded with who made it');

array_map('unlink', glob($site . '/*/*') ?: []);
array_map('rmdir', glob($site . '/*', GLOB_ONLYDIR) ?: []);
array_map('unlink', glob($site . '/*') ?: []);
rmdir($site);
unlink($audit);

// ------------------------------------------------------- part 5: a blog (blog.md)

$site = sys_get_temp_dir() . '/wge-test-blog-' . bin2hex(random_bytes(4));
$format = SourceFormat::fromSchemaFile($packages . '/source/schema.json');
$renderer = Renderer::fromFiles($packages . '/source');
$blogDb = PageStore::openDatabase('sqlite::memory:');
$blogApi = new Api($blogDb, $site, $format, $renderer, blog: new Blog('合成のお知らせ', $format, $renderer, 'https://site.example/blog/'));
$bpost = static fn (string $path, array $body) => $blogApi->handle('POST', $path, json_encode($body), $json);
$post = "<Section padding=\"lg\" width=\"narrow\">\n  <Heading level=\"1\">合成の記事</Heading>\n</Section>\n";

[$status, $body] = $blogApi->handle('GET', '/api/ping', '', []);
check($status === 200 && ($body['blog'] ?? null) === true && $body['blogTitle'] === '合成のお知らせ', 'ping says the site is a blog');
[$status] = $bpost('/api/pages/top/load', ['initialSource' => $post]);
check($status === 404, 'with a blog, "top" is its contents page and cannot be edited');
[$status, $body] = $blogApi->handle('GET', '/api/pages', '', []);
check($status === 200 && $body['pages'] === [], 'no pages at first');

[$status, $body] = $bpost('/api/pages/20261123-a1b2/load', ['initialSource' => $post, 'meta' => ['title' => '合成の記事 <b>&"', 'date' => '2026-11-23']]);
check($status === 200 && $body['meta'] === ['title' => '合成の記事 <b>&"', 'date' => '2026-11-23'], 'a new post comes with its title and date');
foreach ([[['title' => '', 'date' => '2026-11-23'], 'invalid-title'], [['title' => "二\n行", 'date' => '2026-11-23'], 'invalid-title'], [['title' => str_repeat('長', 121), 'date' => '2026-11-23'], 'invalid-title'], [['title' => '題', 'date' => '2026-02-30'], 'invalid-date'], [['title' => '題', 'date' => '23/11/2026'], 'invalid-date']] as [$meta, $reason]) {
    [$status, $body] = $bpost('/api/pages/20261123-a1b2/meta', $meta);
    check($status === 422 && $body['reason'] === $reason, "a bad title or date is refused: {$reason} " . json_encode($meta, JSON_UNESCAPED_UNICODE));
}
[$status] = $bpost('/api/pages/missing/meta', ['title' => '題', 'date' => '2026-11-23']);
check($status === 404, 'a title for a page not loaded is refused');

[$status] = $bpost('/api/pages/20261123-a1b2/publish', ['revision' => 0]);
$contents = (string) @file_get_contents($site . '/index.html');
$page = (string) @file_get_contents($site . '/20261123-a1b2/index.html');
check($status === 200 && str_contains($page, '<title>合成の記事 &lt;b&gt;&amp;"</title>'), 'a post is written with its title, escaped as text');
check(str_contains($contents, 'href="20261123-a1b2/"') && str_contains($contents, '2026年11月23日') && str_contains($contents, '合成の記事 &lt;b&gt;') && !str_contains($contents, '<b>'), 'the contents page lists the post, its title escaped');
check(!str_contains($contents, 'data-wge-path') && str_contains($contents, 'href="style.css"'), 'the contents page is a plain public page');
$feed = (string) @file_get_contents($site . '/feed.xml');
check(str_contains($feed, '<link>https://site.example/blog/20261123-a1b2/</link>') && str_contains($feed, '<title>合成の記事 &lt;b&gt;&amp;&quot;</title>') && str_contains($feed, '+0900'), 'the feed has the post, with its link and date');

[$status] = $bpost('/api/pages/20261101-c3d4/load', ['initialSource' => $post, 'meta' => ['title' => '合成の古い記事', 'date' => '2026-11-01']]);
[$status] = $bpost('/api/pages/20261101-c3d4/publish', ['revision' => 0]);
$contents = (string) @file_get_contents($site . '/index.html');
check(strpos($contents, '20261123-a1b2/') < strpos($contents, '20261101-c3d4/'), 'the contents page lists the newest post first');
[$status, $body] = $bpost('/api/pages/20261101-c3d4/meta', ['title' => '合成の改めた題', 'date' => '2026-12-01']);
$contents = (string) @file_get_contents($site . '/index.html');
check($status === 200 && str_contains($contents, '合成の改めた題') && strpos($contents, '20261101-c3d4/') < strpos($contents, '20261123-a1b2/'), 'a new title or date rewrites the contents page');
check(str_contains((string) @file_get_contents($site . '/20261101-c3d4/index.html'), '<title>合成の改めた題</title>'), 'a new title rewrites the published post');
[$status, $body] = $blogApi->handle('GET', '/api/pages', '', []);
check(array_column($body['pages'], 'id') === ['20261101-c3d4', '20261123-a1b2'] && $body['pages'][0]['published'] === true, 'the list has the pages, newest date first');

$liveOf = static function (string $id) use ($blogApi): int {
    [, $body] = $blogApi->handle('GET', "/api/pages/{$id}/versions", '', []);
    return array_values(array_filter($body['versions'], static fn (array $v) => $v['live'] ?? false))[0]['id'];
};
[$status] = $bpost('/api/pages/20261101-c3d4/undo-publish', ['versionId' => $liveOf('20261101-c3d4')]);
[$status] = $bpost('/api/pages/20261123-a1b2/undo-publish', ['versionId' => $liveOf('20261123-a1b2')]);
$contents = (string) @file_get_contents($site . '/index.html');
check(str_contains($contents, 'まだ記事はありません') && !is_file($site . '/20261123-a1b2/index.html'), 'taking every post back leaves an empty contents page');

// Images: told by their bytes, named by their content, never SVG.
$png = "\x89PNG\r\n\x1A\n" . str_repeat("\0", 32);
[$status, $body] = $bpost('/api/media', ['data' => base64_encode($png)]);
check($status === 200 && preg_match('#^\.\./media/[0-9a-f]{16}\.png$#D', $body['src']) === 1 && is_file($site . '/media/' . basename($body['src'])), 'a PNG is saved under media/');
$src = $body['src'];
[$status, $body] = $bpost('/api/media', ['data' => base64_encode($png)]);
check($status === 200 && $body['src'] === $src, 'the same image is kept once');
foreach (['<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'not an image', "GIF8"] as $bytes) {
    [$status, $body] = $bpost('/api/media', ['data' => base64_encode($bytes)]);
    check($status === 415 && $body['reason'] === 'not-image', 'only JPEG, PNG, GIF and WebP are taken: ' . substr($bytes, 0, 12));
}
[$status, $body] = $bpost('/api/media', ['data' => base64_encode("\xFF\xD8\xFF" . str_repeat('a', Api::MAX_IMAGE))]);
check($status === 413 && $body['reason'] === 'too-large', 'an image over the limit is refused');
[$status] = $bpost('/api/media', ['data' => '***']);
check($status === 400, 'data that is not base64 is a bad request');
check(Api::imageType('RIFF' . "\0\0\0\0" . 'WEBPVP8 ') === 'webp' && Api::imageType("GIF89a\0") === 'gif' && Api::imageType("\xFF\xD8\xFF\xE0") === 'jpg', 'WebP, GIF and JPEG are told by their bytes');

$mediaFront = new Front(new SvcAuth($key, 'front-app', $blogDb, static function () use (&$clock): int {
    return $clock;
}), $blogApi, $packages, $site);
$frontCall = static fn (string $path) => $mediaFront->handle('GET', $path, '', $signed('GET', $path, ''));
[$status, $headers, $out] = $frontCall('/media/' . basename($src));
check($status === 200 && $headers['Content-Type'] === 'image/png' && $headers['X-Content-Type-Options'] === 'nosniff' && $out === $png, 'the front serves a saved image for the preview');
foreach (['/media/../index.html', '/media/x.svg', '/media/' . str_repeat('0', 16) . '.png', '/index.html', '/feed.xml'] as $path) {
    [$status] = $frontCall($path);
    check($status === 404, "the front serves no other file of the site: {$path}");
}

array_map('unlink', glob($site . '/*/*') ?: []);
array_map('rmdir', glob($site . '/*', GLOB_ONLYDIR) ?: []);
array_map('unlink', glob($site . '/*') ?: []);
rmdir($site);

foreach ($failures as $f) {
    echo "FAIL {$f}\n";
}
echo 'php ' . PHP_VERSION . ": {$passed} passed, " . count($failures) . " failed\n";
exit($failures === [] ? 0 : 1);
