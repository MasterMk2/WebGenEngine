<?php
// Development server for the editor with the PHP save API, using PHP's built-in server:
//   php -S 0.0.0.0:8080 -t packages packages/server/router.php
// (run-dev.sh starts it in Docker, published on 127.0.0.1 only). It serves the editor
// and the source packages as static files, the API under /api/, and the published site
// under /site/. Nothing else is served, and no other PHP file is ever run from a URL.
//
// Not for a public server: there are no accounts here, so anyone who can open it can save
// and publish (Html content is sanitized on publish, rendering.md §7). Keep it on 127.0.0.1.
// To run behind an app that signs its users in, use trusted-front.php (server.md §6).

declare(strict_types=1);

require __DIR__ . '/../source/php/SourceFormat.php';
require __DIR__ . '/../source/php/Renderer.php';
require __DIR__ . '/PageStore.php';
require __DIR__ . '/Api.php';
require __DIR__ . '/Blog.php';
require __DIR__ . '/Site.php';
require __DIR__ . '/StaticFiles.php';

use WebGenEngine\Server\Api;
use WebGenEngine\Server\Blog;
use WebGenEngine\Server\PageStore;
use WebGenEngine\Server\Site;
use WebGenEngine\Server\StaticFiles;
use WebGenEngine\Source\Renderer;
use WebGenEngine\Source\SourceFormat;

$packages = dirname(__DIR__);
$data = getenv('WGE_DATA') ?: __DIR__ . '/data';
$site = $data . '/site';
$path = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));

if ($path === '/') {
    header('Location: /editor/', true, 302);
    return true;
}

if (str_starts_with($path, '/api/')) {
    if (!is_dir($data)) {
        mkdir($data, 0775, true);
    }
    $db = PageStore::openDatabase('sqlite:' . $data . '/wge.sqlite');
    // The port the browser uses, which differs from the container's (run-dev.sh sets it).
    $port = getenv('WGE_PORT') ?: '8766';
    $format = SourceFormat::fromSchemaFile($packages . '/source/schema.json');
    $renderer = Renderer::fromFiles($packages . '/source');
    // WGE_BLOG_TITLE makes the site a blog, and WGE_SITE_NAME a site of pages, as with trusted-front.php.
    $blogTitle = trim((string) getenv('WGE_BLOG_TITLE'));
    $siteName = trim((string) getenv('WGE_SITE_NAME'));
    $api = new Api(
        $db,
        $site,
        $format,
        $renderer,
        hosts: ["127.0.0.1:{$port}", "localhost:{$port}"],
        blog: $blogTitle === '' ? null : new Blog($blogTitle, $format, $renderer),
        site: $siteName === '' || $blogTitle !== '' ? null : new Site($siteName, json_decode((string) file_get_contents($packages . '/source/schema.json'), true, 512, JSON_THROW_ON_ERROR)),
    );
    $headers = array_change_key_case(function_exists('getallheaders') ? getallheaders() : [], CASE_LOWER);
    [$status, $body] = $api->handle($_SERVER['REQUEST_METHOD'], $path, (string) file_get_contents('php://input'), $headers);
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    return true;
}

if (preg_match('#^/site/(.*)$#D', $path, $m) === 1) {
    // Until there is a media library, the sample page's images come from the editor's sample folder.
    if (str_starts_with($m[1], 'sample/')) {
        StaticFiles::send($packages . '/editor', $m[1]);
    } else {
        StaticFiles::send($site, $m[1]);
    }
    return true;
}

// Images saved from the editor (../media/… from the editor and from pages one folder down).
if (preg_match('#^/(?:site/)?media/([0-9a-f]{16}\.(?:jpg|png|gif|webp))$#D', $path, $m) === 1) {
    StaticFiles::send($site . '/media', $m[1]);
    return true;
}

if (preg_match('#^/(editor|source)/(.*)$#D', $path, $m) === 1) {
    StaticFiles::send($packages . '/' . $m[1], $m[2]);
    return true;
}

http_response_code(404);
return true;
