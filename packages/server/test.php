<?php
// Tests for the PHP save API. Usage: php packages/server/test.php
// Part 1 runs the scenarios in fixtures/store.json, shared with the editor's store.mjs.
// Part 2 calls Api::handle with requests as the editor sends them.

declare(strict_types=1);

require __DIR__ . '/../source/php/SourceFormat.php';
require __DIR__ . '/../source/php/Renderer.php';
require __DIR__ . '/PageStore.php';
require __DIR__ . '/Api.php';

use WebGenEngine\Server\Api;
use WebGenEngine\Server\PageStore;
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

array_map('unlink', glob($site . '/*/*') ?: []);
array_map('rmdir', glob($site . '/*', GLOB_ONLYDIR) ?: []);
array_map('unlink', glob($site . '/*') ?: []);
rmdir($site);

foreach ($failures as $f) {
    echo "FAIL {$f}\n";
}
echo 'php ' . PHP_VERSION . ": {$passed} passed, " . count($failures) . " failed\n";
exit($failures === [] ? 0 : 1);
