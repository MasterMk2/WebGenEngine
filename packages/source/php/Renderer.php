<?php
// Publishing: page tree -> HTML, theme -> CSS.
// Spec: docs/design/rendering.md. Behaviour must match js/render.mjs; both are
// checked against ../fixtures/render by their run-fixtures scripts.

declare(strict_types=1);

namespace WebGenEngine\Source;

require_once __DIR__ . '/SourceFormat.php';
require_once __DIR__ . '/HtmlSanitizer.php';

final class RenderError extends \RuntimeException
{
    public function __construct(public readonly string $errorCode, public readonly string $key, string $message)
    {
        parent::__construct("{$errorCode} {$key}: {$message}");
    }
}

final class Renderer
{
    // D: without it PHP's $ also matches before a trailing "\n", unlike JS.
    private const LENGTH = '/^(0|[0-9]+(\.[0-9]+)?(rem|em|px|%))$/D';
    private const BREAKPOINT = '/^[0-9]+(\.[0-9]+)?(rem|em|px)$/D';
    private const COLOR = '/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/D';
    private const FONT = '/^[A-Za-z0-9 ,"-]{1,200}$/D';
    private const LANG = '/^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8})*$/D';
    private const ABSOLUTE_URL = '#^https?://[^\s"<>]+$#D';
    /** A font's stylesheet (theme.font.stylesheet): https only, and no quote, space or angle bracket. */
    private const FONT_STYLESHEET = '{^https://[a-z0-9.-]+(:[0-9]+)?/[A-Za-z0-9._~:/?#@!$&()*+,;=%-]*$}D';
    private const HEAD_KEYS = ['description', 'url', 'image', 'type', 'siteName'];

    /** @var array<string, array> */
    private array $components = [];

    /** @var array<string, HtmlSanitizer> by raw component name */
    private array $sanitizers = [];

    public function __construct(private readonly array $schema, private readonly array $theme, private readonly string $baseCss)
    {
        self::validateTheme($schema, $theme);
        foreach ($schema['components'] as $c) {
            $this->components[$c['name']] = $c;
            if ($c['kind'] === 'raw') {
                $this->sanitizers[$c['name']] = new HtmlSanitizer($c['sanitize'], $schema['urlSchemes']);
            }
        }
    }

    public static function fromFiles(string $root, ?array $theme = null): self
    {
        $read = static fn (string $p): array => json_decode((string) file_get_contents($root . $p), true, 512, JSON_THROW_ON_ERROR);
        return new self($read('/schema.json'), $theme ?? $read('/theme.default.json'), (string) file_get_contents($root . '/render/base.css'));
    }

    private static function escapeText(string $s): string
    {
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $s);
    }

    private static function escapeAttr(string $s): string
    {
        return str_replace('"', '&quot;', self::escapeText($s));
    }

    private static function slug(string $value): string
    {
        return (string) preg_replace('/[^a-z0-9]+/', '-', strtolower($value));
    }

    // json_decode gives [] for both {} and []; a theme group must not be a list with entries.
    private static function isObject(mixed $v): bool
    {
        return is_array($v) && ($v === [] || !array_is_list($v));
    }

    /** @param array<string, string> $optional keys that may be left out, each with its own pattern */
    private static function checkGroup(array $theme, string $group, array $keys, string $re, array $optional = []): void
    {
        $values = $theme[$group] ?? null;
        if (!self::isObject($values)) {
            throw new RenderError('E_THEME', $group, 'オブジェクトで指定してください');
        }
        foreach ($keys as $k) {
            if (!array_key_exists($k, $values)) {
                throw new RenderError('E_THEME', "{$group}.{$k}", '値がありません');
            }
            if (!is_string($values[$k]) || preg_match($re, $values[$k]) !== 1) {
                throw new RenderError('E_THEME', "{$group}.{$k}", '使えない値です');
            }
        }
        foreach ($optional as $k => $okRe) {
            if (!array_key_exists($k, $values)) {
                continue;
            }
            if (!is_string($values[$k]) || preg_match($okRe, $values[$k]) !== 1) {
                throw new RenderError('E_THEME', "{$group}.{$k}", '使えない値です');
            }
        }
        foreach (array_keys($values) as $k) {
            if (!in_array((string) $k, $keys, true) && !array_key_exists((string) $k, $optional)) {
                throw new RenderError('E_THEME', "{$group}.{$k}", '使えない項目です');
            }
        }
    }

    private static function checkScalar(array $theme, string $key, string $re): void
    {
        if (!is_string($theme[$key] ?? null) || preg_match($re, $theme[$key]) !== 1) {
            throw new RenderError('E_THEME', $key, '使えない値です');
        }
    }

    // Throws RenderError on the first problem, in a fixed order shared with JS.
    public static function validateTheme(array $schema, mixed $theme): void
    {
        if (!self::isObject($theme)) {
            throw new RenderError('E_THEME', '', 'テーマはオブジェクトで指定してください');
        }
        self::checkGroup($theme, 'space', $schema['tokens']['space'], self::LENGTH);
        self::checkGroup($theme, 'fontSize', $schema['theme']['fontSize'], self::LENGTH);
        self::checkGroup($theme, 'width', $schema['theme']['width'], self::LENGTH);
        self::checkScalar($theme, 'radius', self::LENGTH);
        self::checkScalar($theme, 'breakpoint', self::BREAKPOINT);
        self::checkGroup($theme, 'color', $schema['theme']['color'], self::COLOR);
        self::checkGroup($theme, 'font', ['body'], self::FONT, ['stylesheet' => self::FONT_STYLESHEET]);
        $known = ['space', 'fontSize', 'width', 'radius', 'breakpoint', 'color', 'font'];
        foreach (array_keys($theme) as $k) {
            if (!in_array((string) $k, $known, true)) {
                throw new RenderError('E_THEME', (string) $k, '使えない項目です');
            }
        }
    }

    private function classesOf(array $comp, array $props): array
    {
        $r = $comp['render'];
        $list = isset($r['class']) ? [$r['class']] : [];
        foreach ($r['classes'] ?? [] as $entry) {
            $parts = explode(':', $entry);
            $attr = $parts[0];
            $prefix = $parts[1] ?? $attr;
            if (array_key_exists($attr, $props)) {
                $list[] = 'wge-' . $prefix . '-' . self::slug($props[$attr]);
            }
        }
        return $list;
    }

    /** @return array{0:string,1:string} [tag, start tag] */
    private function openTag(array $comp, Node $node): array
    {
        $r = $comp['render'];
        $tag = (string) preg_replace_callback('/\{([a-z]+)\}/', static fn (array $m): string => $node->props[$m[1]], $r['tag']);
        $s = '<' . $tag;
        $classes = $this->classesOf($comp, $node->props);
        if ($classes !== []) {
            $s .= ' class="' . implode(' ', $classes) . '"';
        }
        foreach ($r['attrs'] ?? [] as $attr) {
            if (array_key_exists($attr, $node->props)) {
                $s .= ' ' . $attr . '="' . self::escapeAttr($node->props[$attr]) . '"';
            }
        }
        foreach ($r['fixed'] ?? [] as [$k, $v]) {
            $s .= ' ' . $k . '="' . self::escapeAttr($v) . '"';
        }
        return [$tag, $s . '>'];
    }

    /** @param Node[] $children */
    private function inline(array $children): string
    {
        $out = '';
        foreach ($children as $c) {
            $out .= $c->type === 'text' ? self::escapeText($c->value) : $this->renderNode($c, '', false);
        }
        return $out;
    }

    // Returns null for nodes that are not published (comments).
    // Html content is sanitized unless $trustRaw says its author may publish raw HTML (§7).
    private function renderNode(Node $node, string $indent, bool $trustRaw): ?string
    {
        if ($node->type === 'comment') {
            return null;
        }
        $comp = $this->components[$node->type];
        if ($comp['kind'] === 'raw') {
            return $trustRaw ? $node->raw : $this->sanitizers[$node->type]->sanitize($node->raw);
        }
        [$tag, $open] = $this->openTag($comp, $node);
        if ($comp['kind'] === 'void' || $comp['kind'] === 'inline-void') {
            // An element that HTML does not leave open (iframe) still gets its end tag.
            return ($comp['render']['close'] ?? false) ? $open . '</' . $tag . '>' : $open;
        }
        if ($comp['kind'] === 'container') {
            // "inner" wraps the children in one more element (a table's rows in <tbody>, which
            // browsers add anyway), so the page's elements are the ones written.
            $wrap = $comp['render']['inner'] ?? null;
            $inner = $indent . ($wrap !== null ? '    ' : '  ');
            $kids = [];
            foreach ($node->children as $c) {
                $s = $this->renderNode($c, $inner, $trustRaw);
                if ($s !== null) {
                    $kids[] = $s;
                }
            }
            if ($kids === []) {
                return $wrap !== null ? $open . "<{$wrap}></{$wrap}></{$tag}>" : $open . '</' . $tag . '>';
            }
            $out = $wrap !== null ? $open . "\n" . $indent . "  <{$wrap}>" : $open;
            foreach ($kids as $k) {
                $out .= "\n" . $inner . $k;
            }
            if ($wrap !== null) {
                $out .= "\n" . $indent . "  </{$wrap}>";
            }
            return $out . "\n" . $indent . '</' . $tag . '>';
        }
        return $open . $this->inline($node->children) . '</' . $tag . '>';
    }

    public function renderFragment(Document $doc, string $indent = '', bool $trustRaw = false): string
    {
        $out = '';
        foreach ($doc->children as $c) {
            $s = $this->renderNode($c, $indent, $trustRaw);
            if ($s !== null) {
                $out .= $indent . $s . "\n";
            }
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $head the description and the page's address for search and
     *   sharing (rendering.md §5): description, url and image (absolute), type ("website" or
     *   "article"), siteName
     * @param Document|null $header,$footer the site's header and footer (site.md §5), around <main>
     */
    public function renderPage(Document $doc, string $title, string $lang = 'ja', string $stylesheetHref = 'style.css', bool $trustRaw = false, array $head = [], ?Document $header = null, ?Document $footer = null): string
    {
        if (preg_match(self::LANG, $lang) !== 1) {
            throw new RenderError('E_LANG', 'lang', '使えない言語コードです');
        }
        $part = fn (string $tag, string $class, ?Document $d): array => $d === null ? [] : ['<' . $tag . ' class="' . $class . '">', $this->renderFragment($d, '  ', $trustRaw) . '</' . $tag . '>'];
        return implode("\n", [
            '<!doctype html>',
            '<html lang="' . $lang . '">',
            '<head>',
            '<meta charset="utf-8">',
            '<meta name="viewport" content="width=device-width, initial-scale=1">',
            '<title>' . self::escapeText($title) . '</title>',
            ...$this->headLines($title, $head),
            // The theme's font, before the site's own stylesheet (rendering.md §4).
            ...(isset($this->theme['font']['stylesheet']) ? ['<link rel="stylesheet" href="' . self::escapeAttr($this->theme['font']['stylesheet']) . '">'] : []),
            '<link rel="stylesheet" href="' . self::escapeAttr($stylesheetHref) . '">',
            '</head>',
            '<body>',
            ...$part('header', 'wge-site-header', $header),
            '<main class="wge-page">',
            $this->renderFragment($doc, '  ', $trustRaw) . '</main>',
            ...$part('footer', 'wge-site-footer', $footer),
            '</body>',
            '</html>',
            '',
        ]);
    }

    /** @return list<string> */
    private function headLines(string $title, array $head): array
    {
        $has = static fn (string $k): bool => array_key_exists($k, $head) && $head[$k] !== null;
        foreach (array_merge(self::HEAD_KEYS, array_keys($head)) as $k) {
            if (!in_array($k, self::HEAD_KEYS, true)) {
                throw new RenderError('E_HEAD', "head.{$k}", '知らない項目です');
            }
            if (!$has($k)) {
                continue;
            }
            $v = $head[$k];
            $ok = match ($k) {
                'url', 'image' => is_string($v) && preg_match(self::ABSOLUTE_URL, $v) === 1,
                'type' => $v === 'website' || $v === 'article',
                default => is_string($v),
            };
            if (!$ok) {
                throw new RenderError('E_HEAD', "head.{$k}", '使えない値です');
            }
        }
        $meta = static fn (string $attr, string $name, string $content): string => '<meta ' . $attr . '="' . $name . '" content="' . self::escapeAttr($content) . '">';
        $description = $has('description') ? $head['description'] : '';
        $lines = [];
        if ($description !== '') {
            $lines[] = $meta('name', 'description', $description);
        }
        if (!$has('url')) {
            return $lines;
        }
        $lines[] = '<link rel="canonical" href="' . self::escapeAttr($head['url']) . '">';
        $lines[] = $meta('property', 'og:type', $has('type') ? $head['type'] : 'website');
        $lines[] = $meta('property', 'og:title', $title);
        $lines[] = $meta('property', 'og:url', $head['url']);
        if ($has('siteName') && $head['siteName'] !== '') {
            $lines[] = $meta('property', 'og:site_name', $head['siteName']);
        }
        if ($description !== '') {
            $lines[] = $meta('property', 'og:description', $description);
        }
        if ($has('image')) {
            $lines[] = $meta('property', 'og:image', $head['image']);
        }
        $lines[] = $meta('name', 'twitter:card', $has('image') ? 'summary_large_image' : 'summary');
        return $lines;
    }

    public function stylesheet(): string
    {
        $t = $this->theme;
        $s = $this->schema;
        $lines = ['/* WebGenEngine stylesheet. Generated from the theme; edit the theme instead. */', ':root {'];
        foreach ($s['tokens']['space'] as $k) {
            $lines[] = "  --wge-space-{$k}: {$t['space'][$k]};";
        }
        foreach ($s['theme']['fontSize'] as $k) {
            $lines[] = "  --wge-font-size-{$k}: {$t['fontSize'][$k]};";
        }
        foreach ($s['theme']['width'] as $k) {
            $lines[] = "  --wge-width-{$k}: {$t['width'][$k]};";
        }
        $lines[] = "  --wge-radius: {$t['radius']};";
        foreach ($s['theme']['color'] as $k) {
            $lines[] = "  --wge-color-{$k}: {$t['color'][$k]};";
        }
        $lines[] = "  --wge-font-body: {$t['font']['body']};";
        $lines[] = '}';
        $lines[] = rtrim(str_replace("\r\n", "\n", $this->baseCss), "\n") . "\n";
        foreach ($s['tokens']['space'] as $k) {
            $lines[] = '.wge-gap-' . self::slug($k) . " { gap: var(--wge-space-{$k}); }";
        }
        foreach ($s['tokens']['space'] as $k) {
            $lines[] = '.wge-padding-' . self::slug($k) . " { padding-block: var(--wge-space-{$k}); }";
        }
        array_push($lines, '', "@media (max-width: {$t['breakpoint']}) {", '  .wge-grid { grid-template-columns: minmax(0, 1fr); }', '}', '');
        return implode("\n", $lines);
    }
}
