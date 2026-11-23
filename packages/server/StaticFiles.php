<?php
// Files of the editor and the source packages, for router.php (development) and Front.php.
// Only the listed types are given out (never .php), and no dot segments or hidden files.

declare(strict_types=1);

namespace WebGenEngine\Server;

final class StaticFiles
{
    public const TYPES = [
        'html' => 'text/html; charset=utf-8',
        'mjs' => 'text/javascript; charset=utf-8',
        'js' => 'text/javascript; charset=utf-8',
        'css' => 'text/css; charset=utf-8',
        'json' => 'application/json; charset=utf-8',
        'txt' => 'text/plain; charset=utf-8',
        'svg' => 'image/svg+xml',
        'jpg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
    ];

    // $root/$relative ("" or a path ending in "/" means its index.html) as [type, content], or null.
    public static function read(string $root, string $relative): ?array
    {
        if (str_ends_with($relative, '/') || $relative === '') {
            $relative .= 'index.html';
        }
        $type = self::TYPES[pathinfo($relative, PATHINFO_EXTENSION)] ?? null;
        if ($type === null || preg_match('#(^|/)\.#', $relative) === 1 || !is_file($root . '/' . $relative)) {
            return null;
        }
        return [$type, (string) file_get_contents($root . '/' . $relative)];
    }

    // Sends the file, or answers 404.
    public static function send(string $root, string $relative): void
    {
        $file = self::read($root, $relative);
        if ($file === null) {
            http_response_code(404);
            return;
        }
        header('Content-Type: ' . $file[0]);
        header('Cache-Control: no-store');
        echo $file[1];
    }
}
