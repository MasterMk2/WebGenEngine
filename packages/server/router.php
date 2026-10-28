<?php
// Development server for the editor with the PHP save API, using PHP's built-in server:
//   php -S 0.0.0.0:8080 -t packages packages/server/router.php
// (run-docker.sh starts it in Docker, published on 127.0.0.1 only). It serves the editor
// and the source packages as static files, the API under /api/, and the published site
// under /site/. Nothing else is served, and no other PHP file is ever run from a URL.
//
// Not for a public server: there are no accounts yet, and the Html box writes its content
// to the public page as it is (rendering.md §7). Keep it on 127.0.0.1.

declare(strict_types=1);

require __DIR__ . '/../source/php/SourceFormat.php';
require __DIR__ . '/../source/php/Renderer.php';
require __DIR__ . '/PageStore.php';
require __DIR__ . '/Api.php';

use WebGenEngine\Server\Api;
use WebGenEngine\Server\PageStore;
use WebGenEngine\Source\Renderer;
use WebGenEngine\Source\SourceFormat;

$packages = dirname(__DIR__);
$data = getenv('WGE_DATA') ?: __DIR__ . '/data';
$site = $data . '/site';
$path = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));

const TYPES = [
    'html' => 'text/html; charset=utf-8',
    'mjs' => 'text/javascript; charset=utf-8',
    'js' => 'text/javascript; charset=utf-8',
    'css' => 'text/css; charset=utf-8',
    'json' => 'application/json; charset=utf-8',
    'txt' => 'text/plain; charset=utf-8',
    'svg' => 'image/svg+xml',
];

function sendFile(string $root, string $relative): void
{
    if (str_ends_with($relative, '/') || $relative === '') {
        $relative .= 'index.html';
    }
    $type = TYPES[pathinfo($relative, PATHINFO_EXTENSION)] ?? null;
    // No dot segments or hidden files, and only the listed types (never .php).
    if ($type === null || preg_match('#(^|/)\.#', $relative) === 1 || !is_file($root . '/' . $relative)) {
        http_response_code(404);
        return;
    }
    header('Content-Type: ' . $type);
    header('Cache-Control: no-store');
    readfile($root . '/' . $relative);
}

if ($path === '/') {
    header('Location: /editor/', true, 302);
    return true;
}

if (str_starts_with($path, '/api/')) {
    if (!is_dir($data)) {
        mkdir($data, 0775, true);
    }
    $db = PageStore::openDatabase('sqlite:' . $data . '/wge.sqlite');
    $api = new Api($db, $site, SourceFormat::fromSchemaFile($packages . '/source/schema.json'), Renderer::fromFiles($packages . '/source'));
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
        sendFile($packages . '/editor', $m[1]);
    } else {
        sendFile($site, $m[1]);
    }
    return true;
}

if (preg_match('#^/(editor|source)/(.*)$#D', $path, $m) === 1) {
    sendFile($packages . '/' . $m[1], $m[2]);
    return true;
}

http_response_code(404);
return true;
