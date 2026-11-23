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
// Without a usable key and client ID nothing is served: it never falls back to router.php's
// open behaviour.

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

header_remove('X-Powered-By');

$refuse = static function (string $why): bool {
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

$front = new Front(
    new SvcAuth($key, $client, $db),
    new Api($db, $site, SourceFormat::fromSchemaFile($packages . '/source/schema.json'), Renderer::fromFiles($packages . '/source'), siteUrl: $siteUrl),
    $packages,
    $site,
    $data . '/audit.jsonl',
);
$headers = array_change_key_case(function_exists('getallheaders') ? getallheaders() : [], CASE_LOWER);
[$status, $replyHeaders, $out] = $front->handle($_SERVER['REQUEST_METHOD'] ?? 'GET', $_SERVER['REQUEST_URI'] ?? '/', (string) file_get_contents('php://input'), $headers);
http_response_code($status);
foreach ($replyHeaders as $name => $value) {
    header($name . ': ' . $value);
}
echo $out;
return true;
