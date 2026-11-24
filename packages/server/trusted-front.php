<?php
// Entry for running the editor behind a trusted front (Front.php, docs/design/server.md §6), with
// PHP's built-in server:
//   php -S 0.0.0.0:8080 packages/server/trusted-front.php
// Settings, by environment:
//   WGE_SVC_KEY_FILE  the key shared with the front (hex or base64 of 32 random bytes or more)
//   WGE_SVC_CLIENT    the client ID the front signs with
//   WGE_DATA          folder for wge.sqlite and audit.jsonl (default: packages/server/data)
//   WGE_SITE_DIR      folder the published pages are written to (default: WGE_DATA/site)
//   WGE_SITE_URL      where the published site is seen (http:// or https://), for "サイトを見る"
//   WGE_BLOG_TITLE    when set, the site is a blog with this name: its contents page and feed
//                     are made from the published posts (docs/design/blog.md)
//   WGE_SITE_NAME     when set, the site is pages written where their paths say, with a header and
//                     footer shared by them all (docs/design/site.md). Not with WGE_BLOG_TITLE yet
// A theme of the site's own goes in WGE_DATA/theme.json (docs/design/rendering.md §4); without
// it the default theme is used. After a new theme or a new version of WGE, write the published
// pages again with the same settings: php packages/server/trusted-front.php --rebuild
// Without a usable key and client ID nothing is served: it never falls back to router.php's
// open behaviour.

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
use WebGenEngine\Source\RenderError;
use WebGenEngine\Source\Renderer;
use WebGenEngine\Source\SourceFormat;

header_remove('X-Powered-By');

$rebuild = PHP_SAPI === 'cli' && in_array('--rebuild', $argv ?? [], true);
$refuse = static function (string $why) use ($rebuild): bool {
    if ($rebuild) {
        fwrite(STDERR, 'wge: ' . $why . "\n");
        exit(1);
    }
    error_log('wge: ' . $why . '; nothing is served');
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo "not configured\n";
    return true;
};

$keyFile = (string) getenv('WGE_SVC_KEY_FILE');
$client = (string) getenv('WGE_SVC_CLIENT');
$key = $keyFile !== '' && is_file($keyFile) && is_readable($keyFile) && filesize($keyFile) <= 4096
    ? SvcAuth::parseKey((string) file_get_contents($keyFile))
    : null;
if ($key === null || preg_match('/^[\x21-\x7e]{1,64}$/D', $client) !== 1) {
    return $refuse('WGE_SVC_KEY_FILE (32 random bytes or more) and WGE_SVC_CLIENT are needed');
}
$siteUrl = getenv('WGE_SITE_URL') ?: null;
if ($siteUrl !== null && preg_match('#^https?://[^\s"<>]+$#D', $siteUrl) !== 1) {
    return $refuse('WGE_SITE_URL must be an http:// or https:// address');
}

$packages = dirname(__DIR__);
$data = getenv('WGE_DATA') ?: __DIR__ . '/data';
$site = getenv('WGE_SITE_DIR') ?: $data . '/site';
if (!is_dir($data)) {
    mkdir($data, 0775, true);
}
$db = PageStore::openDatabase('sqlite:' . $data . '/wge.sqlite');
// The built-in server may run several workers (PHP_CLI_SERVER_WORKERS); they wait for each other's writes.
$db->exec('PRAGMA busy_timeout = 5000');

$format = SourceFormat::fromSchemaFile($packages . '/source/schema.json');
$theme = null;
if (is_file($data . '/theme.json')) {
    $theme = json_decode((string) file_get_contents($data . '/theme.json'), true);
    if (!is_array($theme)) {
        return $refuse('theme.json is not a JSON object');
    }
}
try {
    $renderer = Renderer::fromFiles($packages . '/source', $theme);
} catch (RenderError $e) {
    return $refuse('theme.json: ' . $e->getMessage());
}
$blogTitle = trim((string) getenv('WGE_BLOG_TITLE'));
if ($blogTitle !== '' && (mb_strlen($blogTitle) > 120 || preg_match('/[\x00-\x1f\x7f]/', $blogTitle) === 1)) {
    return $refuse('WGE_BLOG_TITLE must be one line of 120 characters or fewer');
}
$blog = $blogTitle === '' ? null : new Blog($blogTitle, $format, $renderer, $siteUrl);
$siteName = trim((string) getenv('WGE_SITE_NAME'));
if ($siteName !== '' && !Site::validName($siteName)) {
    return $refuse('WGE_SITE_NAME must be one line of 120 characters or fewer');
}
if ($siteName !== '' && $blog !== null) {
    return $refuse('WGE_SITE_NAME and WGE_BLOG_TITLE cannot be used together yet (docs/design/site.md §2)');
}
$sitePages = $siteName === '' ? null : new Site($siteName, json_decode((string) file_get_contents($packages . '/source/schema.json'), true, 512, JSON_THROW_ON_ERROR));
$api = new Api($db, $site, $format, $renderer, siteUrl: $siteUrl, blog: $blog, site: $sitePages);
if ($rebuild) {
    $ids = $api->rebuildSite();
    fwrite(STDOUT, 'wge: wrote ' . count($ids) . " published pages again, with the contents page and style.css\n");
    exit(0);
}
$front = new Front(
    new SvcAuth($key, $client, $db),
    $api,
    $packages,
    $site,
    $data . '/audit.jsonl',
    copySamples: $blog === null && $sitePages === null,
    theme: $theme,
);
$headers = array_change_key_case(function_exists('getallheaders') ? getallheaders() : [], CASE_LOWER);
[$status, $replyHeaders, $out] = $front->handle($_SERVER['REQUEST_METHOD'] ?? 'GET', $_SERVER['REQUEST_URI'] ?? '/', (string) file_get_contents('php://input'), $headers);
http_response_code($status);
foreach ($replyHeaders as $name => $value) {
    header($name . ': ' . $value);
}
echo $out;
return true;
