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
require __DIR__ . '/Site.php';
require __DIR__ . '/StaticFiles.php';
require __DIR__ . '/SvcAuth.php';
require __DIR__ . '/Front.php';

use WebGenEngine\Server\Api;
use WebGenEngine\Server\Blog;
use WebGenEngine\Server\Front;
use WebGenEngine\Server\PageStore;
use WebGenEngine\Server\Site;
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
[$status, $body] = $post('/api/pages/top/delete', []);
check($status === 409 && $body['reason'] === 'top', 'the site\'s first page cannot be deleted');

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
$blogApi = new Api($blogDb, $site, $format, $renderer, siteUrl: 'https://site.example/blog/', blog: new Blog('合成のお知らせ', $format, $renderer, 'https://site.example/blog/'));
$bpost = static fn (string $path, array $body) => $blogApi->handle('POST', $path, json_encode($body), $json);
$post = "<Section padding=\"lg\" width=\"narrow\">\n  <Heading level=\"1\">合成の記事</Heading>\n</Section>\n";

[$status, $body] = $blogApi->handle('GET', '/api/ping', '', []);
check($status === 200 && ($body['blog'] ?? null) === true && $body['blogTitle'] === '合成のお知らせ', 'ping says the site is a blog');
[$status] = $bpost('/api/pages/top/load', ['initialSource' => $post]);
check($status === 404, 'with a blog, "top" is its contents page and cannot be edited');
[$status, $body] = $blogApi->handle('GET', '/api/pages', '', []);
check($status === 200 && $body['pages'] === [], 'no pages at first');

[$status, $body] = $bpost('/api/pages/20261123-a1b2/load', ['initialSource' => $post, 'meta' => ['title' => '合成の記事 <b>&"', 'date' => '2026-11-23']]);
check($status === 200 && $body['meta'] === ['title' => '合成の記事 <b>&"', 'date' => '2026-11-23', 'description' => ''], 'a new post comes with its title and date');
foreach ([[['title' => '', 'date' => '2026-11-23'], 'invalid-title'], [['title' => "二\n行", 'date' => '2026-11-23'], 'invalid-title'], [['title' => str_repeat('長', 121), 'date' => '2026-11-23'], 'invalid-title'], [['title' => '題', 'date' => '2026-02-30'], 'invalid-date'], [['title' => '題', 'date' => '23/11/2026'], 'invalid-date']] as [$meta, $reason]) {
    [$status, $body] = $bpost('/api/pages/20261123-a1b2/meta', $meta);
    check($status === 422 && $body['reason'] === $reason, "a bad title or date is refused: {$reason} " . json_encode($meta, JSON_UNESCAPED_UNICODE));
}
foreach (["二
行", str_repeat('長', 301), 3] as $bad) {
    [$status, $body] = $bpost('/api/pages/20261123-a1b2/meta', ['title' => '題', 'date' => '2026-11-23', 'description' => $bad]);
    check($status === 422 && $body['reason'] === 'invalid-description', 'a bad description is refused: ' . json_encode($bad, JSON_UNESCAPED_UNICODE));
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

// A description, and the tags for sharing (blog.md §5).
check(str_contains($contents, '<link rel="canonical" href="https://site.example/blog/">') && str_contains($contents, '<meta property="og:site_name" content="合成のお知らせ">') && str_contains($contents, '<meta property="og:type" content="website">'), 'the contents page has its address for sharing');
$withImage = "<Section padding=\"lg\" width=\"narrow\">
  <Heading level=\"1\">合成の記事</Heading>
  <Image src=\"../media/0123456789abcdef.png\" alt=\"合成\" />
  <Image src=\"https://other.example/b.png\" alt=\"二枚目\" />
</Section>
";
[, $body] = $bpost('/api/pages/20261123-a1b2/draft', ['source' => $withImage, 'baseRevision' => 0]);
[$status, $body] = $bpost('/api/pages/20261123-a1b2/meta', ['title' => '合成の記事 <b>&"', 'date' => '2026-11-23', 'description' => '  合成の説明 <i>&"  ']);
check($status === 200 && $body['meta']['description'] === '合成の説明 <i>&"', 'a description is kept, trimmed');
[$status] = $bpost('/api/pages/20261123-a1b2/publish', ['revision' => 1]);
$page = (string) @file_get_contents($site . '/20261123-a1b2/index.html');
check(str_contains($page, '<meta name="description" content="合成の説明 &lt;i&gt;&amp;&quot;">') && str_contains($page, '<meta property="og:description" content="合成の説明 &lt;i&gt;&amp;&quot;">'), 'a post has its description, escaped');
check(str_contains($page, '<link rel="canonical" href="https://site.example/blog/20261123-a1b2/">') && str_contains($page, '<meta property="og:type" content="article">') && str_contains($page, '<meta property="og:image" content="https://site.example/blog/media/0123456789abcdef.png">') && str_contains($page, 'summary_large_image'), "a post's address and first picture are written for sharing");
// The look of the blog (blog.md §7): a list with dates, descriptions and pictures, and a frame around each post.
$contents = (string) @file_get_contents($site . '/index.html');
check(str_contains($contents, "<main class=\"wge-page\">\n  <section class=\"wge-section wge-padding-md wge-bg-subtle wge-width-narrow\">\n    <div class=\"wge-stack\">\n      <h1 class=\"wge-heading\">合成のお知らせ</h1>"), "the contents page opens with the blog's name on a pale band");
check(str_contains($contents, '<time class="wge-posts-date" datetime="2026-11-23">2026年11月23日</time>') && str_contains($contents, '<h2 class="wge-posts-title"><a href="20261123-a1b2/">合成の記事 &lt;b&gt;&amp;&quot;</a></h2>'), 'each post in the list has its date and its title, escaped');
check(str_contains($contents, '<p class="wge-posts-description">合成の説明 &lt;i&gt;&amp;&quot;</p>') && str_contains($contents, '<img class="wge-posts-thumb" src="media/0123456789abcdef.png" alt="" loading="lazy" decoding="async">'), 'a post in the list has its description and its first picture');
check(substr_count($contents, 'class="wge-posts-thumb"') === 1 && substr_count($contents, 'class="wge-posts-description"') === 1 && substr_count($contents, 'class="wge-posts-item"') === 2, 'a post without a picture or a description shows none');
check(str_contains($contents, '<a href="feed.xml">') && str_contains($contents, "</ol>\n  <p class=\"wge-posts-feed\">") && str_contains($contents, "</div>\n</main>\n"), 'the list and the link to the feed are the last part of <main>');
$page = (string) @file_get_contents($site . '/20261123-a1b2/index.html');
check(str_contains($page, "<main class=\"wge-page\">\n<div class=\"wge-post-head\">\n  <div>\n    <p class=\"wge-post-crumb\"><a href=\"../\">‹ 合成のお知らせ</a></p>\n    <time class=\"wge-post-date\" datetime=\"2026-11-23\">2026年11月23日</time>"), 'a post opens with the way back to the contents and its date');
check(str_contains($page, "<div class=\"wge-post-foot\">\n  <div><a class=\"wge-post-back\" href=\"../\">‹ 記事の一覧へ</a></div>\n</div>\n</main>\n") && strpos($page, 'wge-post-head') < strpos($page, '<h1 class="wge-heading">合成の記事</h1>') && strpos($page, '<h1 class="wge-heading">合成の記事</h1>') < strpos($page, 'wge-post-foot'), 'the post comes between its frame, all inside <main>');
$framed = (new Blog('合成', $format, $renderer))->framePost("<body>\n<main class=\"wge-page\">\n<pre></main>\n<main class=\"wge-page\">\n</pre>\n</main>\n</body>\n", '2026-11-23');
check(preg_match('#^<body>\n<main class="wge-page">\n<div class="wge-post-head">.*</div>\n</div>\n<pre></main>\n<main class="wge-page">\n</pre>\n<div class="wge-post-foot">.*</div>\n</main>\n</body>\n$#sD', $framed) === 1, "the frame goes after the first <main> and before the last </main>, whatever the post's text holds");
check((new Blog('合成', $format, $renderer))->framePost("<p>no main</p>\n", '2026-11-23') === "<p>no main</p>\n", 'a page without <main> is left as it is');
foreach ([['../media/0123456789abcdef.webp', 'media/0123456789abcdef.webp'], ['https://cdn.example/a.png', 'https://cdn.example/a.png'], ['../media/0123456789abcdef.svg', null], ['../media/x.png', null], ['/media/0123456789abcdef.png', null], ['media/0123456789abcdef.png', null], ['//evil.example/a.png', null], ['javascript:alert(1)', null], ['https://cdn.example/a b.png', null], ['https://cdn.example/a".png', null]] as [$src, $want]) {
    check(Api::contentsImageOf($src) === $want, "a post's picture in the contents page: {$src}");
}
// A new theme: rebuildSite writes every published page and style.css again, drafts untouched.
$themed = Renderer::fromFiles($packages . '/source', array_replace_recursive(json_decode((string) file_get_contents($packages . '/source/theme.default.json'), true), ['color' => ['primary' => '#1b5e20']]));
$themedApi = new Api($blogDb, $site, $format, $themed, siteUrl: 'https://site.example/blog/', blog: new Blog('合成のお知らせ', $format, $themed, 'https://site.example/blog/'));
@unlink($site . '/20261101-c3d4/index.html');
$beforeDraft = $blogDb->query("SELECT draft_source, draft_revision FROM pages WHERE id = '20261123-a1b2'")->fetch(PDO::FETCH_ASSOC);
$rebuilt = $themedApi->rebuildSite();
check($rebuilt === ['20261123-a1b2', '20261101-c3d4'], 'a rebuild writes the published posts again: ' . json_encode($rebuilt));
check(!is_file($site . '/sitemap.xml'), 'a blog alone writes no sitemap (its pages are as before)');
check(str_contains((string) @file_get_contents($site . '/style.css'), '--wge-color-primary: #1b5e20;') && str_contains((string) @file_get_contents($site . '/20261101-c3d4/index.html'), 'wge-post-head'), 'a rebuild writes style.css from the new theme and brings back a missing post');
check($blogDb->query("SELECT draft_source, draft_revision FROM pages WHERE id = '20261123-a1b2'")->fetch(PDO::FETCH_ASSOC) === $beforeDraft && str_contains((string) @file_get_contents($site . '/index.html'), 'wge-posts-thumb'), 'a rebuild changes no draft, and the contents page keeps its pictures');
[$status, $body] = $bpost('/api/pages/20261123-a1b2/meta', ['title' => '合成の記事 <b>&"', 'date' => '2026-11-23']);
check($status === 200 && $body['meta']['description'] === '合成の説明 <i>&"', 'a title change without a description keeps it');
$feed = (string) @file_get_contents($site . '/feed.xml');
check(str_contains($feed, '<description>合成の説明 &lt;i&gt;&amp;&quot;</description></item>'), 'the feed has the description');
[, $body] = $blogApi->handle('GET', '/api/pages', '', []);
check(array_column($body['pages'], 'description', 'id')['20261123-a1b2'] === '合成の説明 <i>&"', 'the list has the description');
foreach ([['https://s.example/a/b/', '../media/x.png', 'https://s.example/a/media/x.png'], ['https://s.example/a/b/', '/m/x.png?v=1', 'https://s.example/m/x.png?v=1'], ['https://s.example/', 'x.png', 'https://s.example/x.png'], ['https://s.example/', '../../x.png', 'https://s.example/x.png'], ['https://s.example/a/', 'https://cdn.example/x.png', 'https://cdn.example/x.png'], ['https://s.example/a/', 'javascript:alert(1)', null], ['https://s.example/a/', '//evil.example/x.png', null], ['https://s.example/a/', 'a b.png', null]] as [$base, $ref, $want]) {
    check(Api::resolveUrl($base, $ref) === $want, "a picture's address is resolved: {$ref}");
}
// A database made before the description gets the column, keeping its rows.
$oldFile = sys_get_temp_dir() . '/wge-test-old-' . bin2hex(random_bytes(4)) . '.sqlite';
$old = new PDO('sqlite:' . $oldFile);
$old->exec("CREATE TABLE pages (id TEXT PRIMARY KEY, draft_source TEXT NOT NULL, draft_revision INTEGER NOT NULL, draft_saved_at INTEGER, published INTEGER, next_id INTEGER NOT NULL)");
$old->exec("CREATE TABLE page_meta (id TEXT PRIMARY KEY REFERENCES pages(id), title TEXT NOT NULL, date TEXT NOT NULL)");
$old->exec("INSERT INTO pages VALUES ('20261101-0000', '', 0, NULL, NULL, 1)");
$old->exec("INSERT INTO page_meta VALUES ('20261101-0000', '合成の古い題', '2026-11-01')");
$old = null;
$oldApi = new Api(PageStore::openDatabase('sqlite:' . $oldFile), $site, $format, $renderer, blog: new Blog('合成', $format, $renderer));
[, $body] = $oldApi->handle('GET', '/api/pages', '', []);
check(($body['pages'][0]['title'] ?? null) === '合成の古い題' && $body['pages'][0]['description'] === '', 'an older database gets the description column');
$oldApi = null;
gc_collect_cycles();
@unlink($oldFile);
// Back to the post's first published version, as before the description.
[, $versions] = $blogApi->handle('GET', '/api/pages/20261123-a1b2/versions', '', []);
[$status] = $bpost('/api/pages/20261123-a1b2/undo-publish', ['versionId' => array_values(array_filter($versions['versions'], static fn (array $v) => $v['live']))[0]['id']]);
check($status === 200, 'the second publish is taken back');
[$status, $body] = $bpost('/api/pages/20261101-c3d4/meta', ['title' => '合成の改めた題', 'date' => '2026-12-01']);
$contents = (string) @file_get_contents($site . '/index.html');
check($status === 200 && str_contains($contents, '合成の改めた題') && strpos($contents, '20261101-c3d4/') < strpos($contents, '20261123-a1b2/'), 'a new title or date rewrites the contents page');
check(str_contains((string) @file_get_contents($site . '/20261101-c3d4/index.html'), '<title>合成の改めた題</title>'), 'a new title rewrites the published post');
[$status, $body] = $blogApi->handle('GET', '/api/pages', '', []);
check(array_column($body['pages'], 'id') === ['20261101-c3d4', '20261123-a1b2'] && $body['pages'][0]['published'] === true, 'the list has the pages, newest date first');
[$status, $body] = $bpost('/api/pages/20261123-a1b2/delete', []);
check($status === 409 && $body['reason'] === 'published' && is_file($site . '/20261123-a1b2/index.html'), 'a published post cannot be deleted, and stays on the site');

$liveOf = static function (string $id) use ($blogApi): int {
    [, $body] = $blogApi->handle('GET', "/api/pages/{$id}/versions", '', []);
    return array_values(array_filter($body['versions'], static fn (array $v) => $v['live'] ?? false))[0]['id'];
};
[$status] = $bpost('/api/pages/20261101-c3d4/undo-publish', ['versionId' => $liveOf('20261101-c3d4')]);
[$status] = $bpost('/api/pages/20261123-a1b2/undo-publish', ['versionId' => $liveOf('20261123-a1b2')]);
$contents = (string) @file_get_contents($site . '/index.html');
check(str_contains($contents, 'まだ記事はありません') && !is_file($site . '/20261123-a1b2/index.html'), 'taking every post back leaves an empty contents page');

// Deleting: a post that is not published leaves the list, keeps its rows, and can be restored.
[$status, $body] = $bpost('/api/pages/20261101-c3d4/delete', []);
check($status === 200 && is_int($body['deletedAt'] ?? null), 'a post that is not published is deleted');
[, $body] = $blogApi->handle('GET', '/api/pages', '', []);
check(array_column($body['pages'], 'id') === ['20261123-a1b2'], 'a deleted post leaves the list');
[$status, $body] = $blogApi->handle('GET', '/api/deleted-pages', '', []);
check($status === 200 && count($body['pages']) === 1 && $body['pages'][0]['id'] === '20261101-c3d4' && $body['pages'][0]['title'] === '合成の改めた題' && $body['pages'][0]['date'] === '2026-12-01', 'the deleted posts are listed with their titles and dates');
$deletedCalls = [
    ['POST', '/api/pages/20261101-c3d4/load', ['initialSource' => $post]],
    ['POST', '/api/pages/20261101-c3d4/draft', ['source' => $post, 'baseRevision' => 0]],
    ['POST', '/api/pages/20261101-c3d4/publish', ['revision' => 0]],
    ['POST', '/api/pages/20261101-c3d4/meta', ['title' => '題', 'date' => '2026-11-23']],
    ['POST', '/api/pages/20261101-c3d4/delete', []],
    ['GET', '/api/pages/20261101-c3d4/versions', null],
    ['GET', '/api/pages/20261101-c3d4/versions/1', null],
];
foreach ($deletedCalls as [$method, $path, $input]) {
    [$status, $body] = $method === 'GET' ? $blogApi->handle('GET', $path, '', []) : $bpost($path, $input);
    check($status === 404 && $body['reason'] === 'deleted', "a deleted post answers nothing but its restore: {$method} {$path}");
}
check(!is_file($site . '/20261101-c3d4/index.html') && str_contains((string) @file_get_contents($site . '/index.html'), 'まだ記事はありません'), 'publishing a deleted post writes nothing');
[$status, $body] = $bpost('/api/pages/20261101-c3d4/restore', []);
check($status === 200 && $body['published'] === null && $body['draft']['revision'] === 0, 'a deleted post is restored as it was');
[, $body] = $blogApi->handle('GET', '/api/pages', '', []);
[, $deleted] = $blogApi->handle('GET', '/api/deleted-pages', '', []);
check(array_column($body['pages'], 'id') === ['20261101-c3d4', '20261123-a1b2'] && $body['pages'][0]['title'] === '合成の改めた題' && $deleted['pages'] === [], 'a restored post is back in the list with its title');
[$status, $body] = $bpost('/api/pages/20261101-c3d4/restore', []);
check($status === 409 && $body['reason'] === 'not-deleted', 'a post that is not deleted is not restored');
foreach (['delete', 'restore'] as $action) {
    [$status, $body] = $bpost("/api/pages/missing/{$action}", []);
    check($status === 404 && $body['reason'] === 'not-loaded', "{$action} needs a page that was loaded");
}

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
// A PNG whose header says 640×360 (a header only, made here; no real photo).
$ihdr = 'IHDR' . pack('NNCCCCC', 640, 360, 8, 2, 0, 0, 0);
$sized = "\x89PNG\r\n\x1A\n" . pack('N', 13) . $ihdr . pack('N', crc32($ihdr));
[$status, $body] = $bpost('/api/media', ['data' => base64_encode($sized)]);
check($status === 200 && ($body['width'] ?? null) === 640 && ($body['height'] ?? null) === 360, 'a saved image comes back with its width and height');
[, $body] = $bpost('/api/media', ['data' => base64_encode($png)]);
check(!array_key_exists('width', $body) && !array_key_exists('height', $body), 'an image whose size cannot be read comes back without one');
check(Api::imageType('RIFF' . "\0\0\0\0" . 'WEBPVP8 ') === 'webp' && Api::imageType("GIF89a\0") === 'gif' && Api::imageType("\xFF\xD8\xFF\xE0") === 'jpg', 'WebP, GIF and JPEG are told by their bytes');

// PDFs, for a button to open (rendering.md §7.4): asked for as a document, told by their bytes.
$pdf = "%PDF-1.7\n1 0 obj << >> endobj\ntrailer << >>\n%%EOF\n";
[$status, $body] = $bpost('/api/media', ['data' => base64_encode($pdf), 'kind' => 'document']);
check($status === 200 && preg_match('#^\.\./media/[0-9a-f]{16}\.pdf$#D', $body['src']) === 1 && is_file($site . '/media/' . basename($body['src'])), 'a PDF is saved under media/');
check(!array_key_exists('width', $body) && !array_key_exists('height', $body), 'a PDF comes back without a size');
$pdfSrc = $body['src'];
[$status, $body] = $bpost('/api/media', ['data' => base64_encode($pdf), 'kind' => 'document']);
check($status === 200 && $body['src'] === $pdfSrc, 'the same PDF is kept once');
[$status, $body] = $bpost('/api/media', ['data' => base64_encode($pdf)]);
check($status === 415 && $body['reason'] === 'not-image', 'a PDF is not taken as an image');
foreach ([$png, '<html><script>alert(1)</script></html>', "%PDF-1.7\n1 0 obj", "%PDF-x\n%%EOF", " %PDF-1.7\n%%EOF"] as $bytes) {
    [$status, $body] = $bpost('/api/media', ['data' => base64_encode($bytes), 'kind' => 'document']);
    check($status === 415 && $body['reason'] === 'not-pdf', 'only a whole PDF is taken as a document: ' . json_encode(substr($bytes, 0, 12)));
}
[$status, $body] = $bpost('/api/media', ['data' => base64_encode('%PDF-1.7' . str_repeat('a', Api::MAX_IMAGE) . '%%EOF'), 'kind' => 'document']);
check($status === 413 && $body['reason'] === 'too-large', 'a PDF over the limit is refused');
foreach (['video', '', 1, ['document']] as $kind) {
    [$status] = $bpost('/api/media', ['data' => base64_encode($pdf), 'kind' => $kind]);
    check($status === 400, 'an unknown kind is a bad request: ' . json_encode($kind));
}
check(Api::documentType("%PDF-2.0\n" . str_repeat('x', 5000) . "\n%%EOF\r\n") === 'pdf' && Api::documentType("%PDF-1.4\n%%EOF\n" . str_repeat('x', 2000)) === null, 'the end-of-file mark is looked for near the end');

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
// The editor's preview reads the site's theme as source/theme.json (rendering.md §4).
[$status, $headers, $out] = $frontCall('/source/theme.json');
check($status === 200 && $headers['Content-Type'] === 'application/json; charset=utf-8' && json_decode($out, true) === json_decode((string) file_get_contents($packages . '/source/theme.default.json'), true), 'without a theme of its own, the front gives the default theme to the preview');
$siteTheme = array_replace_recursive(json_decode((string) file_get_contents($packages . '/source/theme.default.json'), true), ['color' => ['primary' => '#1b5e20']]);
$themeFront = new Front(new SvcAuth($key, 'front-app', $blogDb, static function () use (&$clock): int {
    return $clock;
}), $blogApi, $packages, $site, theme: $siteTheme);
[$status, , $out] = $themeFront->handle('GET', '/source/theme.json', '', $signed('GET', '/source/theme.json', ''));
check($status === 200 && json_decode($out, true) === $siteTheme, "the front gives the site's theme to the preview");
[$status] = $themeFront->handle('GET', '/source/theme.json', '', []);
check($status === 403, 'the theme is served only to the front');

array_map('unlink', glob($site . '/*/*') ?: []);
array_map('rmdir', glob($site . '/*', GLOB_ONLYDIR) ?: []);
array_map('unlink', glob($site . '/*') ?: []);
rmdir($site);

// ------------------------------------------------------- part 6: a site of pages (site.md)

function removeTree(string $dir): void
{
    foreach (scandir($dir) ?: [] as $name) {
        if ($name !== '.' && $name !== '..') {
            is_dir("{$dir}/{$name}") ? removeTree("{$dir}/{$name}") : unlink("{$dir}/{$name}");
        }
    }
    rmdir($dir);
}

$schema = json_decode((string) file_get_contents($packages . '/source/schema.json'), true, 512, JSON_THROW_ON_ERROR);
$siteDir = sys_get_temp_dir() . '/wge-test-pages-' . bin2hex(random_bytes(4));
mkdir($siteDir);
$siteDb = PageStore::openDatabase('sqlite::memory:');
$siteApi = new Api($siteDb, $siteDir, $format, $renderer, siteUrl: 'https://fest.example/', site: new Site('合成の祭', $schema));
$spost = static fn (string $path, array $body) => $siteApi->handle('POST', $path, json_encode($body), $json);
// Another site: a sitemap.xml it did not write, then none and no site URL.
$mapDir = sys_get_temp_dir() . '/wge-test-map-' . bin2hex(random_bytes(4));
mkdir($mapDir);
file_put_contents($mapDir . '/sitemap.xml', 'not written by WGE');
$mapApi = new Api(PageStore::openDatabase('sqlite::memory:'), $mapDir, $format, $renderer, siteUrl: 'https://fest.example/', site: new Site('合成の祭', $schema));
$mapApi->handle('POST', '/api/pages/top/load', json_encode(['initialSource' => "<Text>合成</Text>\n", 'meta' => ['title' => '合成', 'path' => 'index.html']]), $json);
[$status] = $mapApi->handle('POST', '/api/pages/top/publish', json_encode(['revision' => 0]), $json);
check($status === 200 && is_file($mapDir . '/index.html') && file_get_contents($mapDir . '/sitemap.xml') === 'not written by WGE', 'a sitemap.xml WGE did not write stays, and does not stop a publish');
[, $mapBody] = $mapApi->handle('GET', '/api/pages/top/versions', '', []);
$mapApi->handle('POST', '/api/pages/top/undo-publish', json_encode(['versionId' => $mapBody['versions'][0]['id']]), $json);
check(file_get_contents($mapDir . '/sitemap.xml') === 'not written by WGE', 'nor when nothing is published');
removeTree($mapDir);
$bareDir = sys_get_temp_dir() . '/wge-test-bare-' . bin2hex(random_bytes(4));
mkdir($bareDir);
$bareApi = new Api(PageStore::openDatabase('sqlite::memory:'), $bareDir, $format, $renderer, site: new Site('合成の祭', $schema));
$bareApi->handle('POST', '/api/pages/top/load', json_encode(['initialSource' => "<Text>合成</Text>\n", 'meta' => ['title' => '合成', 'path' => 'index.html']]), $json);
[$status] = $bareApi->handle('POST', '/api/pages/top/publish', json_encode(['revision' => 0]), $json);
check($status === 200 && is_file($bareDir . '/index.html') && !is_file($bareDir . '/sitemap.xml'), 'without a site URL there is no sitemap');
removeTree($bareDir);
$sread = static fn (string $path): string => (string) @file_get_contents($siteDir . '/' . $path);
$links = "<Section>\n  <Heading level=\"1\">合成の祭</Heading>\n  <Text><Link href=\"/exhibitors.html\">出展</Link>・<Link href=\"/\">トップ</Link>・<Link href=\"/#access\">アクセス</Link>・<Link href=\"https://other.example/a\">よそ</Link>・<Link href=\"//cdn.example/x\">cdn</Link>・<Link href=\"notes.html\">相対</Link></Text>\n  <Image src=\"../media/0123456789abcdef.png\" alt=\"会場\" />\n  <Button href=\"/en/\">English</Button>\n</Section>\n";

[$status, $body] = $siteApi->handle('GET', '/api/ping', '', []);
check($status === 200 && ($body['site'] ?? null) === true && $body['siteName'] === '合成の祭' && !isset($body['blog']), 'ping says the site is a site of pages');
foreach (['', '/a.html', 'a/../b.html', 'A.html', 'a.htm', 'a//b.html', 'media/a.html', 'blog/index.html', 'a/b/c/d/e.html', 'a b.html', '.html', 'a.html/', "a.html\n", 3, null] as $bad) {
    [$status, $body] = $spost('/api/pages/bad/load', ['initialSource' => $links, 'meta' => ['title' => '題', 'path' => $bad]]);
    check($status === 422 && $body['reason'] === 'invalid-path', 'a bad path is refused: ' . json_encode($bad));
}
[$status, $body] = $siteApi->handle('GET', '/api/pages', '', []);
check($body['pages'] === [], 'refused paths create no page');

// Pages at depth 0, 1 and 2, with no date.
[$status, $body] = $spost('/api/pages/top/load', ['initialSource' => $links, 'meta' => ['title' => '合成の祭 トップ', 'path' => 'index.html']]);
check($status === 200 && $body['meta'] === ['title' => '合成の祭 トップ', 'date' => '', 'description' => '', 'path' => 'index.html', 'livePath' => null], "a site's page needs no date, and keeps its path");
[$status] = $spost('/api/pages/en-top/load', ['initialSource' => $links, 'meta' => ['title' => 'Fest', 'path' => 'en/index.html']]);
[$status2] = $spost('/api/pages/deep/load', ['initialSource' => $links, 'meta' => ['title' => '深い', 'path' => 'a/b/deep.html', 'date' => '2026-11-24']]);
check($status === 200 && $status2 === 200, 'pages one and two folders down are made');
[$status, $body] = $spost('/api/pages/dated/load', ['initialSource' => $links, 'meta' => ['title' => '日付', 'path' => 'dated.html', 'date' => '2026-02-30']]);
check($status === 422 && $body['reason'] === 'invalid-date', "a site's page may have a date, but a real one");
[$status, $body] = $spost('/api/pages/other/load', ['initialSource' => $links, 'meta' => ['title' => '重複', 'path' => 'en/index.html']]);
check($status === 409 && $body['reason'] === 'path-taken', "another page's path is refused");
[$status, $body] = $spost('/api/pages/en-top/load', ['initialSource' => '', 'meta' => ['title' => '別の題', 'path' => 'x.html']]);
check($status === 409 && $body['reason'] === 'exists', 'making a page with the id of another is refused');
[$status, $body] = $spost('/api/pages/en-top/load', ['initialSource' => '']);
check($status === 200 && $body['draft']['source'] === $links && $body['meta']['title'] === 'Fest', 'opening a page without meta loads it as it is');

foreach (['top', 'en-top', 'deep'] as $id) {
    $spost("/api/pages/{$id}/publish", ['revision' => 0]);
}
$top = $sread('index.html');
$en = $sread('en/index.html');
$deep = $sread('a/b/deep.html');
check(str_contains($top, 'href="exhibitors.html"') && str_contains($top, 'href="./"') && str_contains($top, 'href="./#access"') && str_contains($top, 'src="media/0123456789abcdef.png"') && str_contains($top, 'href="en/"') && str_contains($top, 'href="style.css"'), 'at depth 0, addresses from the root become relative');
check(str_contains($en, 'href="../exhibitors.html"') && str_contains($en, 'href="../"') && str_contains($en, 'href="../#access"') && str_contains($en, 'src="../media/0123456789abcdef.png"') && str_contains($en, 'href="../style.css"'), 'at depth 1, they go one folder up');
check(str_contains($deep, 'href="../../exhibitors.html"') && str_contains($deep, 'src="../../media/0123456789abcdef.png"') && str_contains($deep, 'href="../../style.css"'), 'at depth 2, two folders up');
check(str_contains($deep, 'href="https://other.example/a"') && str_contains($deep, 'href="//cdn.example/x"') && str_contains($deep, 'href="notes.html"'), 'other addresses stay as written');
check(preg_match('#(?:href|src)="/(?!/)#', $top . $en . $deep) === 0, 'no address from the root is left on a page');
check(str_contains($top, '<link rel="canonical" href="https://fest.example/">') && str_contains($en, '<link rel="canonical" href="https://fest.example/en/">') && str_contains($deep, '<link rel="canonical" href="https://fest.example/a/b/deep.html">'), 'the address leaves off a last index.html');
check(str_contains($top, '<meta property="og:site_name" content="合成の祭">') && str_contains($en, '<meta property="og:image" content="https://fest.example/media/0123456789abcdef.png">') && str_contains($deep, '<meta property="og:image" content="https://fest.example/media/0123456789abcdef.png">'), "the site's name and the first picture's address are written for sharing");
[, $body] = $siteApi->handle('GET', '/api/pages', '', []);
check(array_column($body['pages'], 'id') === ['top', 'deep', 'en-top'] && array_column($body['pages'], 'livePath', 'id') === ['top' => 'index.html', 'deep' => 'a/b/deep.html', 'en-top' => 'en/index.html'], 'the list has the top page first, then by path, with where each is written');

// The site's map for search engines (site.md §4.1): the published pages at their addresses, the first page first.
$sitemap = static fn (array $urls): string => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n" . implode('', array_map(static fn (string $u): string => "  <url><loc>{$u}</loc></url>\n", $urls)) . "</urlset>\n";
check($sread('sitemap.xml') === $sitemap(['https://fest.example/', 'https://fest.example/a/b/deep.html', 'https://fest.example/en/']), 'the sitemap lists the published pages at their addresses: ' . $sread('sitemap.xml'));
check($siteDb->query("SELECT page FROM site_files WHERE path = 'sitemap.xml'")->fetchColumn() === '', 'the sitemap is in the list of files WGE wrote, as no page\'s');

// The list of files WGE wrote (site.md §4).
file_put_contents($siteDir . '/exhibitors.html', 'not written by WGE');
mkdir($siteDir . '/css');
file_put_contents($siteDir . '/css/style.css', 'not written by WGE');
[$status] = $spost('/api/pages/exh/load', ['initialSource' => $links, 'meta' => ['title' => '出展', 'path' => 'exhibitors.html']]);
[$status, $body] = $spost('/api/pages/exh/publish', ['revision' => 0]);
check($status === 409 && $body['reason'] === 'path-occupied' && $body['path'] === 'exhibitors.html' && $sread('exhibitors.html') === 'not written by WGE', 'a file WGE did not write is never published over');
[, $body] = $siteApi->handle('GET', '/api/pages/exh/versions', '', []);
check($body['versions'] === [], 'and the refused page is not published');
[$status, $body] = $spost('/api/pages/exh/meta', ['title' => '出展', 'path' => 'exhibitors-2026.html']);
check($status === 200 && $body['meta']['path'] === 'exhibitors-2026.html', 'the path can be changed');
[$status] = $spost('/api/pages/exh/publish', ['revision' => 0]);
check($status === 200 && str_contains($sread('exhibitors-2026.html'), '<title>出展</title>'), 'and the page is published there');
mkdir($siteDir . '/x');
file_put_contents($siteDir . '/notdir', 'a file');
[$status] = $spost('/api/pages/under/load', ['initialSource' => $links, 'meta' => ['title' => '下', 'path' => 'notdir/a.html']]);
[$status, $body] = $spost('/api/pages/under/publish', ['revision' => 0]);
check($status === 409 && $body['reason'] === 'path-occupied', 'a file where a folder of the path would be stops the publish');
// A rebuild (blog.md §7) writes the pages WGE wrote, where they are, and nothing else.
$filesBefore = $siteDb->query('SELECT path, page FROM site_files ORDER BY path')->fetchAll(PDO::FETCH_ASSOC);
@unlink($siteDir . '/en/index.html');
$rebuilt = $siteApi->rebuildSite();
check(in_array('en-top', $rebuilt, true) && !in_array('under', $rebuilt, true) && is_file($siteDir . '/en/index.html') && $siteDb->query('SELECT path, page FROM site_files ORDER BY path')->fetchAll(PDO::FETCH_ASSOC) === $filesBefore, "a site's rebuild writes its published pages where they are, and its list of files stays: " . json_encode($rebuilt));

// A new path takes effect on the next publish only.
[$status, $body] = $spost('/api/pages/en-top/meta', ['title' => 'Fest!', 'path' => 'en/top.html']);
check($status === 200 && str_contains($sread('en/index.html'), '<title>Fest!</title>') && !is_file($siteDir . '/en/top.html'), 'changing the path rewrites the published page where it is');
check($body['meta']['path'] === 'en/top.html' && $body['meta']['livePath'] === 'en/index.html', 'the answer tells the next path from the one written now');
[, $body] = $siteApi->handle('GET', '/api/pages', '', []);
$enRow = array_values(array_filter($body['pages'], static fn (array $p) => $p['id'] === 'en-top'))[0];
check($enRow['path'] === 'en/top.html' && $enRow['livePath'] === 'en/index.html', 'the list tells the next path from the one written now');
[$status] = $spost('/api/pages/en-top/publish', ['revision' => 0]);
check($status === 200 && is_file($siteDir . '/en/top.html') && !is_file($siteDir . '/en/index.html'), 'publishing moves the page, removing its old file');
check(str_contains($sread('sitemap.xml'), '<loc>https://fest.example/en/top.html</loc>') && !str_contains($sread('sitemap.xml'), '<loc>https://fest.example/en/</loc>'), 'the sitemap follows the move');
[$status, $body] = $spost('/api/pages/top/meta', ['title' => 't', 'path' => 'en/top.html']);
check($status === 409 && $body['reason'] === 'path-taken', 'a path in use is refused when changed');

// Taking a publish back, and the folders left empty.
$liveIn = static function (string $id) use ($siteApi): int {
    [, $body] = $siteApi->handle('GET', "/api/pages/{$id}/versions", '', []);
    return array_values(array_filter($body['versions'], static fn (array $v) => $v['live'] ?? false))[0]['id'];
};
[$status] = $spost('/api/pages/deep/undo-publish', ['versionId' => $liveIn('deep')]);
check($status === 200 && !is_file($siteDir . '/a/b/deep.html') && !is_dir($siteDir . '/a'), 'taking the only publish back removes the file and its empty folders');
check(!str_contains($sread('sitemap.xml'), 'deep.html') && str_contains($sread('sitemap.xml'), '<loc>https://fest.example/</loc>'), 'a page taken back leaves the sitemap');
check($sread('exhibitors.html') === 'not written by WGE' && $sread('css/style.css') === 'not written by WGE' && is_dir($siteDir . '/x'), 'files and folders WGE did not write stay');
[, $body] = $siteApi->handle('GET', '/api/pages', '', []);
check(array_column($body['pages'], 'livePath', 'id')['deep'] === null, 'a page taken back is written nowhere');
[$status] = $spost('/api/pages/deep/delete', []);
[$status, $body] = $spost('/api/pages/again/load', ['initialSource' => $links, 'meta' => ['title' => '同じ場所', 'path' => 'a/b/deep.html']]);
check($status === 409 && $body['reason'] === 'path-taken', 'a deleted page keeps its path, for when it is restored');

// The header and footer (site.md §5).
[, $body] = $siteApi->handle('GET', '/api/pages', '', []);
check(($body['parts'] ?? null) === [['id' => 'part-header', 'loaded' => false, 'published' => false, 'savedAt' => null, 'source' => null], ['id' => 'part-footer', 'loaded' => false, 'published' => false, 'savedAt' => null, 'source' => null]], 'the list has the header and footer apart, not loaded at first');
[$status] = $spost('/api/pages/part-menu/load', ['initialSource' => '']);
check($status === 404, 'no other part exists');
[$status, $body] = $spost('/api/pages/part-header/load', ['initialSource' => '', 'meta' => ['title' => 'ヘッダー', 'path' => 'header.html']]);
check($status === 409 && $body['reason'] === 'part', 'a part has no title nor path');
$header = "<Section padding=\"sm\">\n  <Stack direction=\"horizontal\">\n    <Text><Link href=\"/\">合成の祭</Link></Text>\n  </Stack>\n  <Image src=\"../media/fedcba9876543210.png\" alt=\"ロゴ\" />\n  <Text><Link href=\"/exhibitors-2026.html\">出展</Link></Text>\n</Section>\n";
[$status, $body] = $spost('/api/pages/part-header/load', ['initialSource' => $header]);
check($status === 200 && $body['meta'] === null, 'the header is loaded as a page without meta');
[$status, $body] = $spost('/api/pages/part-footer/load', ['initialSource' => "<Link href=\"/\">x</Link>\n"]);
check($status === 422 && $body['reason'] === 'invalid', 'a part must read back as a page, as any page');
check(!str_contains($sread('index.html'), 'wge-site-header'), 'a header not published is on no page');
[$status] = $spost('/api/pages/part-header/publish', ['revision' => 0]);
$top = $sread('index.html');
$enTop = $sread('en/top.html');
check($status === 200 && str_contains($top, "<body>\n<header class=\"wge-site-header\">") && str_contains($sread('exhibitors-2026.html'), 'wge-site-header') && str_contains($enTop, 'wge-site-header'), 'publishing the header writes it into every published page');
check(str_contains($top, '<a href="./">合成の祭</a>') && str_contains($top, 'src="media/fedcba9876543210.png"') && str_contains($top, 'href="exhibitors-2026.html">出展'), "the header's addresses are made relative at depth 0");
check(str_contains($enTop, '<a href="../">合成の祭</a>') && str_contains($enTop, 'src="../media/fedcba9876543210.png"') && str_contains($enTop, 'href="../exhibitors-2026.html">出展'), 'and one folder up at depth 1');
check(!str_contains($top, 'fedcba9876543210.png" content') && str_contains($top, 'og:image" content="https://fest.example/media/0123456789abcdef.png"'), "the picture for sharing is the page's own, not the header's");
check(!is_file($siteDir . '/part-header/index.html') && !is_dir($siteDir . '/part-header'), 'the header has no file of its own');
[$status] = $spost('/api/pages/part-header/draft', ['source' => "<Text>合成の下書き</Text>\n", 'baseRevision' => 0]);
check($status === 200 && !str_contains($sread('index.html'), '合成の下書き'), "the header's draft is on no page");
[$status] = $spost('/api/pages/part-footer/load', ['initialSource' => "<Text size=\"sm\">© 合成の祭</Text>\n"]);
[$status] = $spost('/api/pages/part-footer/publish', ['revision' => 0]);
check($status === 200 && str_contains($sread('index.html'), "</main>\n<footer class=\"wge-site-footer\">\n  <p class=\"wge-text wge-size-sm\">© 合成の祭</p>\n</footer>"), 'the footer goes after the page');
[$status, $body] = $spost('/api/pages/part-footer/meta', ['title' => '題']);
check($status === 409 && $body['reason'] === 'part', 'a part takes no title');
[$status, $body] = $spost('/api/pages/part-footer/delete', []);
check($status === 409 && $body['reason'] === 'part', 'a part is not deleted');
[, $body] = $siteApi->handle('GET', '/api/pages', '', []);
check(!in_array('part-header', array_column($body['pages'], 'id'), true) && $body['parts'][0]['loaded'] === true && $body['parts'][0]['published'] === true && is_int($body['parts'][0]['savedAt']), 'the parts are listed apart, loaded and published');
check(is_string($body['parts'][0]['source']) && str_contains($body['parts'][0]['source'], '合成の祭') && !str_contains($body['parts'][0]['source'], '合成の下書き') && $body['parts'][1]['source'] === "<Text size=\"sm\">© 合成の祭</Text>\n", 'the parts come with their published source, never the draft');
[$status] = $spost('/api/pages/noimg/load', ['initialSource' => "<Text>絵なし</Text>\n", 'meta' => ['title' => '絵なし', 'path' => 'plain.html']]);
[$status] = $spost('/api/pages/noimg/publish', ['revision' => 0]);
$plain = $sread('plain.html');
check($status === 200 && str_contains($plain, 'wge-site-header') && str_contains($plain, 'wge-site-footer') && !str_contains($plain, 'og:image'), 'a page published later gets the parts, and no picture for sharing from the header');
[$status] = $spost('/api/pages/part-header/undo-publish', ['versionId' => $liveIn('part-header')]);
check($status === 200 && !str_contains($sread('index.html'), 'wge-site-header') && !str_contains($sread('plain.html'), 'wge-site-header') && str_contains($sread('plain.html'), 'wge-site-footer'), 'taking the header back removes it from every page');

// A stylesheet WGE did not write is not replaced.
$plainDir = sys_get_temp_dir() . '/wge-test-pages-' . bin2hex(random_bytes(4));
mkdir($plainDir);
file_put_contents($plainDir . '/style.css', 'theirs');
$plainApi = new Api(PageStore::openDatabase('sqlite::memory:'), $plainDir, $format, $renderer, site: new Site('合成', $schema));
[, $body] = $plainApi->handle('POST', '/api/pages/top/load', json_encode(['initialSource' => $links]), $json);
check($body['meta'] === ['title' => 'トップページ', 'date' => '', 'description' => '', 'path' => 'index.html', 'livePath' => null], 'a page opened without its title gets the fields as it is written now');
[$status, $body] = $plainApi->handle('POST', '/api/pages/top/publish', json_encode(['revision' => 0]), $json);
check($status === 409 && $body['path'] === 'style.css' && file_get_contents($plainDir . '/style.css') === 'theirs' && !is_file($plainDir . '/index.html'), 'a stylesheet WGE did not write stops the publish');
[, $body] = $plainApi->handle('GET', '/api/pages', '', []);
check($body['pages'][0]['path'] === 'index.html' && $body['pages'][0]['livePath'] === null, '"top" is written to index.html when no path is given');
[$status, $body] = $plainApi->handle('POST', '/api/pages/top/meta', json_encode(['title' => '題']), $json);
check($status === 200 && $body['meta']['path'] === 'index.html', 'a title change without a path keeps it');
$plainApi = null;
removeTree($plainDir);

// A database made before paths gets the column and the list of files.
$oldFile = sys_get_temp_dir() . '/wge-test-old-' . bin2hex(random_bytes(4)) . '.sqlite';
$old = new PDO('sqlite:' . $oldFile);
$old->exec("CREATE TABLE pages (id TEXT PRIMARY KEY, draft_source TEXT NOT NULL, draft_revision INTEGER NOT NULL, draft_saved_at INTEGER, published INTEGER, next_id INTEGER NOT NULL)");
$old->exec("CREATE TABLE page_meta (id TEXT PRIMARY KEY REFERENCES pages(id), title TEXT NOT NULL, date TEXT NOT NULL, description TEXT NOT NULL DEFAULT '')");
$old->exec("INSERT INTO pages VALUES ('top', '', 0, NULL, NULL, 1)");
$old->exec("INSERT INTO page_meta VALUES ('top', '合成の古いトップ', '', '')");
$old = null;
$oldDb = PageStore::openDatabase('sqlite:' . $oldFile);
$columns = array_column($oldDb->query('PRAGMA table_info(page_meta)')->fetchAll(PDO::FETCH_ASSOC), 'name');
$tables = $oldDb->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN);
$kept = $oldDb->query("SELECT title, path FROM page_meta WHERE id = 'top'")->fetch(PDO::FETCH_ASSOC);
check(in_array('path', $columns, true) && in_array('site_files', $tables, true) && $kept === ['title' => '合成の古いトップ', 'path' => null], 'an older database gets the path column and the list of files, keeping its rows');
$oldDb = null;
gc_collect_cycles();
@unlink($oldFile);

// An Embed of a page of this site is made relative at each depth, keeping its query (rendering.md §7.2).
$embedSource = "<Section>\n  <Embed src=\"/map/viewer.html?v=1\" title=\"全体マップ\" ratio=\"3:4\" />\n  <Embed src=\"https://www.google.com/maps/embed?pb=1&amp;z=2\" title=\"地図\" />\n</Section>\n";
foreach (['emb0' => ['embed.html', 'map/viewer.html?v=1'], 'emb1' => ['x/embed.html', '../map/viewer.html?v=1'], 'emb2' => ['x/y/embed.html', '../../map/viewer.html?v=1']] as $id => [$path, $want]) {
    $spost("/api/pages/{$id}/load", ['initialSource' => $embedSource, 'meta' => ['title' => '埋め込み', 'path' => $path]]);
    [$status] = $spost("/api/pages/{$id}/publish", ['revision' => 0]);
    $html = $sread($path);
    check($status === 200 && str_contains($html, '<iframe class="wge-embed wge-embed-ratio-3-4" src="' . $want . '" title="全体マップ" loading="lazy"></iframe>') && str_contains($html, 'src="https://www.google.com/maps/embed?pb=1&amp;z=2"'), "an embedded page of this site is relative at {$path}; a map stays as written");
}

$siteApi = null;
removeTree($siteDir);

// ------------------------------------------------------- part 7: a blog's header and footer (blog.md §8)

$partsDir = sys_get_temp_dir() . '/wge-test-blog-parts-' . bin2hex(random_bytes(4));
$partsDb = PageStore::openDatabase('sqlite::memory:');
$partsApi = new Api($partsDb, $partsDir, $format, $renderer, siteUrl: 'https://fest.example/blog/', blog: new Blog('合成のお知らせ', $format, $renderer, 'https://fest.example/blog/'));
$ppost = static fn (string $path, array $body) => $partsApi->handle('POST', $path, json_encode($body), $json);
$pread = static fn (string $path): string => (string) @file_get_contents($partsDir . '/' . $path);
$livePart = static function (string $id) use ($partsApi): int {
    [, $body] = $partsApi->handle('GET', "/api/pages/{$id}/versions", '', []);
    return array_values(array_filter($body['versions'], static fn (array $v) => $v['live']))[0]['id'];
};

[$status, $body] = $partsApi->handle('GET', '/api/ping', '', []);
check($status === 200 && ($body['parts'] ?? null) === true && ($body['blog'] ?? null) === true && !isset($body['site']), 'ping says a blog has a header and footer too');
[, $body] = (new Api(PageStore::openDatabase('sqlite::memory:'), $partsDir . '-plain', $format, $renderer))->handle('GET', '/api/ping', '', []);
check(!isset($body['parts']), 'a single page has no parts');

$ppost('/api/pages/20261120-aaaa/load', ['initialSource' => $post, 'meta' => ['title' => '合成の記事', 'date' => '2026-11-20']]);
$ppost('/api/pages/20261120-aaaa/publish', ['revision' => 0]);
$ppost('/api/pages/20261121-bbbb/load', ['initialSource' => $post, 'meta' => ['title' => '合成の下書き', 'date' => '2026-11-21']]);
$beforeContents = $pread('index.html');
$beforePost = $pread('20261120-aaaa/index.html');
$beforeFeed = $pread('feed.xml');

[$status, $body] = $partsApi->handle('GET', '/api/pages', '', []);
check($status === 200 && array_column($body['parts'] ?? [], 'id') === ['part-header', 'part-footer'] && !$body['parts'][0]['loaded'] && $body['parts'][0]['source'] === null, 'the list has the header and footer apart, not made yet');
foreach (['part-other', 'part-x1'] as $bad) {
    [$status] = $ppost("/api/pages/{$bad}/load", ['initialSource' => $post]);
    check($status === 404, "only the header and footer are parts: {$bad}");
}
[$status, $body] = $ppost('/api/pages/part-header/load', ['initialSource' => $post, 'meta' => ['title' => '題', 'date' => '2026-11-20']]);
check($status === 409 && $body['reason'] === 'part', 'a part has no title nor date');

$header = "<Section background=\"primary\" padding=\"sm\" width=\"wide\">\n  <Stack direction=\"horizontal\" justify=\"between\" align=\"center\">\n    <Image src=\"../media/0123456789abcdef.png\" alt=\"合成の祭\" width=\"120\" height=\"40\" />\n    <Text><Link href=\"/\">ホーム</Link>・<Link href=\"/exhibitors.html\">出展</Link>・<Link href=\"./\">ブログ</Link>・<Link href=\"https://other.example/a\">よそ</Link></Text>\n  </Stack>\n</Section>\n";
$footer = "<Section background=\"inverse\" padding=\"md\">\n  <Text size=\"sm\">合成の祭 実行委員会</Text>\n</Section>\n";
[$status, $body] = $ppost('/api/pages/part-header/load', ['initialSource' => $header]);
check($status === 200 && $body['meta'] === null, 'the header opens without a title');
[$status, $body] = $ppost('/api/pages/part-header/meta', ['title' => '題', 'date' => '2026-11-20']);
check($status === 409 && $body['reason'] === 'part', 'nor can one be given later');
[$status, $body] = $ppost('/api/pages/part-header/delete', []);
check($status === 409 && $body['reason'] === 'part', 'a part is not deleted');
$partsApi->rebuildSite();
check($pread('index.html') === $beforeContents && $pread('20261120-aaaa/index.html') === $beforePost && $pread('feed.xml') === $beforeFeed, 'a header only drafted changes no byte of the blog');
check(!is_dir($partsDir . '/part-header'), 'nor writes a folder for it');

[$status] = $ppost('/api/pages/part-header/publish', ['revision' => 0]);
$contents = $pread('index.html');
$page = $pread('20261120-aaaa/index.html');
check($status === 200 && !is_dir($partsDir . '/part-header'), 'publishing the header writes no page of its own');
check(str_contains($page, '<header class="wge-site-header">') && strpos($page, '<header class="wge-site-header">') < strpos($page, '<main class="wge-page">'), 'a published post gets the header before its text');
check(str_contains($contents, '<header class="wge-site-header">') && strpos($contents, '</header>') < strpos($contents, '<main class="wge-page">'), 'so does the contents page');
check(str_contains($page, 'src="../media/0123456789abcdef.png"') && str_contains($contents, 'src="media/0123456789abcdef.png"'), "the header's picture is found from a post and from the contents page");
check(str_contains($page, 'href="/exhibitors.html"') && str_contains($contents, 'href="/exhibitors.html"') && str_contains($page, 'href="/"') && str_contains($contents, 'href="./"') && str_contains($page, 'href="https://other.example/a"'), "the header's links from the root of the larger site stay as written");
check(str_contains($page, '<p class="wge-post-crumb">') && strpos($page, '<p class="wge-post-crumb">') > strpos($page, '<main class="wge-page">'), "a post's frame stays inside its main");
check(!is_file($partsDir . '/20261121-bbbb/index.html'), 'a draft post is not written by it');
[, $body] = $partsApi->handle('GET', '/api/pages', '', []);
check(array_column($body['pages'], 'id') === ['20261121-bbbb', '20261120-aaaa'] && $body['parts'][0]['published'] && $body['parts'][0]['source'] === $header, 'the parts never show among the posts; the published header is given to the editor');
check(!str_contains($pread('feed.xml'), 'part-') && !str_contains($contents, 'part-header'), 'nor in the feed or the contents page');

$ppost('/api/pages/part-header/draft', ['source' => "<Section>\n  <Text>合成の書きかけ</Text>\n</Section>\n", 'baseRevision' => 0]);
$ppost('/api/pages/20261120-aaaa/draft', ['source' => $post . "<Section>\n  <Text>二版</Text>\n</Section>\n", 'baseRevision' => 0]);
$ppost('/api/pages/20261120-aaaa/publish', ['revision' => 1]);
check(!str_contains($pread('20261120-aaaa/index.html'), '合成の書きかけ') && str_contains($pread('20261120-aaaa/index.html'), '二版') && str_contains($pread('20261120-aaaa/index.html'), 'href="/exhibitors.html"'), "a header's draft is never in a page; its published version is");

$ppost('/api/pages/part-footer/load', ['initialSource' => $footer]);
$ppost('/api/pages/part-footer/publish', ['revision' => 0]);
$page = $pread('20261120-aaaa/index.html');
check(str_contains($page, '<footer class="wge-site-footer">') && strpos($page, '</main>') < strpos($page, '<footer class="wge-site-footer">') && str_contains($pread('index.html'), '合成の祭 実行委員会'), 'the footer goes after main, in the posts and the contents page');

$partsApi->rebuildSite();
check(str_contains($pread('index.html'), 'src="media/0123456789abcdef.png"') && str_contains($pread('20261120-aaaa/index.html'), '<footer class="wge-site-footer">'), 'writing the blog again keeps its parts');

[$status, $body] = $ppost('/api/pages/part-header/undo-publish', ['versionId' => $livePart('part-header')]);
check($status === 200 && $body['published'] === null && !str_contains($pread('20261120-aaaa/index.html'), 'wge-site-header') && !str_contains($pread('index.html'), 'wge-site-header'), 'taking the header back takes it out of every page');
$ppost('/api/pages/part-footer/undo-publish', ['versionId' => $livePart('part-footer')]);
check($pread('index.html') === $beforeContents, 'with neither part published, the contents page is as before, to the byte');

$partsApi = null;
removeTree($partsDir);

// ------------------------------------------------------- part 8: a site with a blog (site.md §9)

// A blog as it was run before (blog.md): its folder is the site's blog/, with a post that has a picture.
$sbDir = sys_get_temp_dir() . '/wge-test-siteblog-' . bin2hex(random_bytes(4));
mkdir($sbDir . '/blog', 0775, true);
mkdir($sbDir . '/css');
file_put_contents($sbDir . '/index.html', 'not written by WGE');
file_put_contents($sbDir . '/css/style.css', 'not written by WGE');
$sbFile = sys_get_temp_dir() . '/wge-test-siteblog-' . bin2hex(random_bytes(4)) . '.sqlite';
$sbDb = PageStore::openDatabase('sqlite:' . $sbFile);
$asBlog = new Api($sbDb, $sbDir . '/blog', $format, $renderer, siteUrl: 'https://fest.example/blog/', blog: new Blog('合成のブログ', $format, $renderer, 'https://fest.example/blog/'));
$bp = static fn (Api $api, string $path, array $body) => $api->handle('POST', $path, json_encode($body), $json);
[, $media] = $bp($asBlog, '/api/media', ['data' => base64_encode("\x89PNG\r\n\x1A\n" . str_repeat("\1", 40))]);
$pic = basename($media['src']);
$postSource = "<Section>\n  <Text>合成の記事<Link href=\"/about.html\">について</Link></Text>\n  <Image src=\"../media/{$pic}\" alt=\"合成\" />\n</Section>\n";
$bp($asBlog, '/api/pages/20261120-aaaa/load', ['initialSource' => $postSource, 'meta' => ['title' => '合成の記事', 'date' => '2026-11-20']]);
$bp($asBlog, '/api/pages/20261120-aaaa/publish', ['revision' => 0]);
$bp($asBlog, '/api/pages/20261121-bbbb/load', ['initialSource' => "<Text>下書き</Text>\n"]);
check(is_file($sbDir . '/blog/20261120-aaaa/index.html') && is_file($sbDir . '/blog/media/' . $pic), 'the blog as it was: a post and its picture under its own folder');
$asBlog = null;

// The same data as a site with a blog: the pictures move to the site's media/ (as the move to it does).
mkdir($sbDir . '/media');
copy($sbDir . '/blog/media/' . $pic, $sbDir . '/media/' . $pic);
$sb = new Api($sbDb, $sbDir, $format, $renderer, siteUrl: 'https://fest.example/', blog: new Blog('合成のブログ', $format, $renderer, 'https://fest.example/blog/'), site: new Site('合成の祭', $schema));
$sbp = static fn (string $path, array $body) => $sb->handle('POST', $path, json_encode($body), $json);
$sbr = static fn (string $path): string => (string) @file_get_contents($sbDir . '/' . $path);
[, $body] = $sb->handle('GET', '/api/ping', '', []);
check(($body['site'] ?? null) === true && ($body['blog'] ?? null) === true && $body['siteUrl'] === 'https://fest.example/', 'ping says a site with a blog');
$rebuilt = $sb->rebuildSite();
check($rebuilt === ['20261120-aaaa'], 'a rebuild writes the published post again: ' . json_encode($rebuilt));
$sbPost = $sbr('blog/20261120-aaaa/index.html');
check(str_contains($sbPost, 'src="../../media/' . $pic . '"') && str_contains($sbPost, 'href="../../about.html"') && str_contains($sbPost, 'href="../style.css"'), "a post's picture and links are made relative to blog/<id>/");
check(str_contains($sbPost, '<meta property="og:url" content="https://fest.example/blog/20261120-aaaa/">') && str_contains($sbPost, '<meta property="og:image" content="https://fest.example/media/' . $pic . '">') && str_contains($sbPost, 'og:site_name" content="合成のブログ"'), "a post's address and picture for sharing are where they are seen");
check(str_contains($sbPost, 'class="wge-post-head"') && str_contains($sbPost, '<a href="../">‹ 合成のブログ</a>'), 'a post keeps its frame');
$sbContents = $sbr('blog/index.html');
check(str_contains($sbContents, 'href="20261120-aaaa/"') && str_contains($sbContents, 'src="../media/' . $pic . '"'), "the contents page lists the post, its picture from the site's media/");
check(str_contains($sbr('blog/feed.xml'), '<link>https://fest.example/blog/20261120-aaaa/</link>') && is_file($sbDir . '/blog/style.css'), 'the feed links the post where it is seen');
check($sbr('index.html') === 'not written by WGE' && $sbr('css/style.css') === 'not written by WGE' && !is_file($sbDir . '/style.css'), 'files WGE did not write stay, and no page of the site is written yet');
check($sbr('sitemap.xml') === $sitemap(['https://fest.example/blog/', 'https://fest.example/blog/20261120-aaaa/']), 'the sitemap has the blog and its post');

// The list: "pages" stay the blog's posts, as a front made for the blog reads them; the site's pages come apart.
[, $body] = $sb->handle('GET', '/api/pages', '', []);
check(array_column($body['pages'], 'id') === ['20261120-aaaa', '20261121-bbbb'] && array_unique(array_column($body['pages'], 'kind')) === ['post'] && !array_key_exists('path', $body['pages'][0]), 'the posts are the pages of the list, with no path');
check(($body['sitePages'] ?? null) === [] && count($body['parts']) === 2, "the site's pages and parts come apart");

// A page of the site, and a post: told by the path given when they are made.
[$status, $body] = $sbp('/api/pages/about/load', ['initialSource' => "<Section>\n  <Heading level=\"1\">について</Heading>\n</Section>\n", 'meta' => ['title' => 'について', 'path' => 'about.html']]);
check($status === 200 && $body['meta']['kind'] === 'page' && $body['meta']['path'] === 'about.html', 'a new page with a path is a page of the site');
[$status, $body] = $sbp('/api/pages/20261122-cccc/load', ['initialSource' => "<Text>新しい記事</Text>\n", 'meta' => ['title' => '新しい記事', 'date' => '2026-11-22']]);
check($status === 200 && $body['meta'] === ['title' => '新しい記事', 'date' => '2026-11-22', 'description' => '', 'kind' => 'post'], 'a new page with a date and no path is a post');
[$status, $body] = $sbp('/api/pages/20261123-dddd/load', ['initialSource' => '', 'meta' => ['title' => '日付なし']]);
check($status === 422 && $body['reason'] === 'invalid-date', 'a post needs its date');
[$status, $body] = $sbp('/api/pages/20261122-cccc/meta', ['title' => '新しい記事', 'date' => '2026-11-22', 'path' => 'post.html']);
check($status === 422 && $body['reason'] === 'invalid-path', 'a post is given no path afterwards');
[$status, $body] = $sbp('/api/pages/top/load', ['initialSource' => "<Text>合成のトップ</Text>\n"]);
check($status === 200 && $body['meta']['kind'] === 'page' && $body['meta']['path'] === 'index.html', 'the top page is the first page of the site, not the contents of the blog');
[$status, $body] = $sbp('/api/pages/top/publish', ['revision' => 0]);
check($status === 409 && $body['reason'] === 'path-occupied' && $sbr('index.html') === 'not written by WGE', 'the site\'s first page is not written over a file WGE did not write');
[$status] = $sbp('/api/pages/about/publish', ['revision' => 0]);
[$status2] = $sbp('/api/pages/20261122-cccc/publish', ['revision' => 0]);
check($status === 200 && $status2 === 200 && str_contains($sbr('about.html'), '<title>について</title>') && str_contains($sbr('blog/20261122-cccc/index.html'), '新しい記事') && str_contains($sbr('blog/index.html'), 'href="20261122-cccc/"'), 'a page goes where its path says, a post under blog/');
check(!is_file($sbDir . '/20261122-cccc.html') && is_file($sbDir . '/style.css'), 'a post is not written as a page of the site');
check($sbr('sitemap.xml') === $sitemap(['https://fest.example/about.html', 'https://fest.example/blog/', 'https://fest.example/blog/20261122-cccc/', 'https://fest.example/blog/20261120-aaaa/']), 'the sitemap has the pages, then the blog and its posts, newest first');
[, $body] = $sb->handle('GET', '/api/pages', '', []);
check(array_column($body['pages'], 'id') === ['20261122-cccc', '20261120-aaaa', '20261121-bbbb'] && array_column($body['sitePages'], 'id') === ['top', 'about'] && $body['sitePages'][1]['livePath'] === 'about.html' && $body['sitePages'][1]['kind'] === 'page', 'the list has the posts by date and the pages by path');

// The header goes on the pages and the posts, made relative to each.
$sbp('/api/pages/part-header/load', ['initialSource' => "<Text><Link href=\"/\">合成の祭</Link></Text>\n"]);
[$status] = $sbp('/api/pages/part-header/publish', ['revision' => 0]);
check($status === 200 && str_contains($sbr('about.html'), '<a href="./">合成の祭</a>') && str_contains($sbr('blog/20261120-aaaa/index.html'), "<header class=\"wge-site-header\">\n  <p class=\"wge-text\"><a href=\"../../\">合成の祭</a></p>") && str_contains($sbr('blog/index.html'), '<a href="../">合成の祭</a>'), 'the header is on the pages, the posts and the contents page, relative to each');
check($sbr('index.html') === 'not written by WGE', 'and not on a file WGE did not write');

// Taking a post back, and deleting one: the posts stay apart from the pages.
[, $versions] = $sb->handle('GET', '/api/pages/20261120-aaaa/versions', '', []);
[$status] = $sbp('/api/pages/20261120-aaaa/undo-publish', ['versionId' => $versions['versions'][0]['id']]);
check($status === 200 && !is_dir($sbDir . '/blog/20261120-aaaa') && !str_contains($sbr('blog/index.html'), '20261120-aaaa') && !str_contains($sbr('sitemap.xml'), '20261120-aaaa'), 'a post taken back leaves the blog, its contents and the sitemap');
[$status] = $sbp('/api/pages/20261121-bbbb/delete', []);
[, $body] = $sb->handle('GET', '/api/deleted-pages', '', []);
check($status === 200 && array_column($body['pages'], 'id') === ['20261121-bbbb'] && $body['sitePages'] === [], 'a deleted post is listed with the posts');
[$status] = $sbp('/api/pages/about/meta', ['title' => 'について', 'path' => '20261122-cccc.html']);
check($status === 200, "a page may take a path like a post's id: posts have no path to take");

$sb = null;
$sbDb = null;
gc_collect_cycles();
@unlink($sbFile);
removeTree($sbDir);


foreach ($failures as $f) {
    echo "FAIL {$f}\n";
}
echo 'php ' . PHP_VERSION . ": {$passed} passed, " . count($failures) . " failed\n";
exit($failures === [] ? 0 : 1);
