<?php
// A site of pages (2026-11-24): each page is written where its path says (exhibitors.html,
// en/index.html), WGE keeps a list of the files it wrote so it never removes or overwrites any
// other, and every page gets the site's header and footer. Spec: docs/design/site.md.

declare(strict_types=1);

namespace WebGenEngine\Server;

use WebGenEngine\Source\Node;

final class Site
{
    /** The site's header and footer: kept as pages, drafted and published the same way (site.md §5). */
    public const HEADER = 'part-header';
    public const FOOTER = 'part-footer';
    /** Ids starting so are the site's own parts; only the two above exist. */
    public const PART_PREFIX = 'part-';

    /** Lower-case names joined by "/", ending in .html; three folders at most (site.md §3). */
    private const PATH = '#^(?:[a-z0-9][a-z0-9-]{0,63}/){0,3}[a-z0-9][a-z0-9-]{0,63}\.html$#D';
    /** First folders WGE uses itself. */
    private const RESERVED = ['media', 'blog'];
    public const MAX_NAME = 120;

    /** @var array<string, list<string>> the attributes of type url, by component */
    private array $urlAttributes = [];

    public function __construct(private readonly string $name, array $schema)
    {
        foreach ($schema['components'] as $c) {
            foreach ($c['attributes'] as $a) {
                // An Embed's address of a page of this site (/map/viewer.html) is made relative too.
                if ($a['type'] === 'url' || $a['type'] === 'embed') {
                    $this->urlAttributes[$c['name']][] = $a['name'];
                }
            }
        }
    }

    public function name(): string
    {
        return $this->name;
    }

    public static function isPart(string $pageId): bool
    {
        return $pageId === self::HEADER || $pageId === self::FOOTER;
    }

    public static function validName(string $name): bool
    {
        return $name !== '' && mb_check_encoding($name, 'UTF-8') && mb_strlen($name) <= self::MAX_NAME && preg_match('/[\x00-\x1f\x7f]/', $name) !== 1;
    }

    public static function validPath(mixed $path): bool
    {
        return is_string($path) && preg_match(self::PATH, $path) === 1 && !in_array(explode('/', $path)[0], self::RESERVED, true);
    }

    /** Where a page goes when no path was given: as before sites (server.md §2.1). */
    public static function defaultPath(string $pageId): string
    {
        return $pageId === 'top' ? 'index.html' : "{$pageId}/index.html";
    }

    /** Folders below the site's root: index.html is 0, en/index.html is 1. */
    public static function depth(string $path): int
    {
        return substr_count($path, '/');
    }

    /** The page's address under the site's URL: a last index.html is left off (en/index.html → en/). */
    public static function addressOf(string $path): string
    {
        return (string) preg_replace('#(^|/)index\.html$#D', '$1', $path);
    }

    /**
     * Rewrites, in place, the url attributes written from the site's root (/x) and the pictures the
     * editor puts in (../media/x) as addresses relative to a page $depth folders down (site.md §3.1).
     * A blog is a folder of a larger site, so its /x mean that site's root and stay as they are;
     * only its pictures are rewritten there ($fromRoot false, blog.md §8).
     */
    public function relink(Node $node, int $depth, bool $fromRoot = true): void
    {
        $up = str_repeat('../', $depth);
        foreach ($this->urlAttributes[$node->type] ?? [] as $attr) {
            $value = $node->props[$attr] ?? null;
            if (is_string($value) && ($fromRoot || str_starts_with($value, '../media/'))) {
                $node->props[$attr] = self::relative($value, $up);
            }
        }
        foreach ($node->children ?? [] as $child) {
            $this->relink($child, $depth, $fromRoot);
        }
    }

    public static function relative(string $value, string $up): string
    {
        if (str_starts_with($value, '../media/')) {
            return $up . substr($value, 3);
        }
        if (!str_starts_with($value, '/') || str_starts_with($value, '//')) {
            return $value;
        }
        $rest = substr($value, 1);
        // At the root, "" or "#x" alone would mean this page, not the site's first one.
        if ($up === '' && ($rest === '' || $rest[0] === '#' || $rest[0] === '?')) {
            return './' . $rest;
        }
        return $up . $rest;
    }
}
