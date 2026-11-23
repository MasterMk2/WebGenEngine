<?php
// Tests for the PHP save API. Usage: php packages/server/test.php
// Part 1 runs the scenarios in fixtures/store.json, shared with the editor's store.mjs.
// Part 2 calls Api::handle with requests as the editor sends them.

declare(strict_types=1);

require __DIR__ . '/../source/php/SourceFormat.php';
require __DIR__ . '/../source/php/Renderer.php';
require __DIR__ . '/PageStore.php';
require __DIR__ . '/Api.php';
require __DIR__ . '/StaticFiles.php';
require __DIR__ . '/SvcAuth.php';
require __DIR__ . '/Front.php';

use WebGenEngine\Server\Api;
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

foreach ($failures as $f) {
    echo "FAIL {$f}\n";
}
echo 'php ' . PHP_VERSION . ": {$passed} passed, " . count($failures) . " failed\n";
exit($failures === [] ? 0 : 1);
