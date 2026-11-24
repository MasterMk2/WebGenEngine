<?php
// Page source format: parser, serializers and edit operations.
// Spec: docs/design/source-format.md. Behaviour must match js/source-format.mjs;
// both are checked against ../fixtures by their run-fixtures scripts.
// Targets PHP 8.3 (D7) and needs no extensions beyond PCRE.

declare(strict_types=1);

namespace WebGenEngine\Source;

// Exception already owns $line (the PHP line), hence sourceLine / sourceColumn.
final class SourceError extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly int $sourceLine,
        public readonly int $sourceColumn,
        string $message,
    ) {
        parent::__construct("{$errorCode} ({$sourceLine}:{$sourceColumn}) {$message}");
    }
}

final class EditError extends \RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct("{$errorCode} {$message}");
    }
}

class Node
{
    public array $props = [];
    /** @var Node[]|null */
    public ?array $children = null;
    public ?string $raw = null;
    public ?string $value = null;
    /** @var array{0:int,1:int}|null byte offsets into the source */
    public ?array $span = null;
    public int $openEnd = 0;
    public ?int $closeStart = null;
    public bool $selfClosing = false;
    public ?string $leading = null;
    public ?int $origIndex = null;
    public ?int $origCount = null;
    public ?string $innerTrailing = null;
    public bool $dirty = false;
    public bool $childrenDirty = false;
    public bool $contentDirty = false;

    public function __construct(public string $type)
    {
    }
}

final class Document extends Node
{
    public function __construct(public string $source, public string $newline)
    {
        parent::__construct('document');
        $this->children = [];
    }
}

final class SourceFormat
{
    private const BLOCK_KINDS = ['container', 'text', 'void', 'raw'];
    private const INLINE_KINDS = ['inline', 'inline-void'];
    private const NAMED_ENTITIES = ['amp' => '&', 'lt' => '<', 'gt' => '>', 'quot' => '"', 'apos' => "'"];
    private const LONE_CR = '/\r(?!\n)/';

    /** @var array<string, array> */
    private array $components = [];
    private array $spaceTokens;
    private array $urlSchemes;
    /** @var list<string> where an Embed may load from: address prefixes, and "/" for this site's pages */
    private array $embedSources;

    // Parser state.
    private string $src = '';
    private int $pos = 0;
    private int $len = 0;
    private int $bom = 0;

    public function __construct(private readonly array $schema)
    {
        foreach ($schema['components'] as $c) {
            $this->components[$c['name']] = $c;
        }
        $this->spaceTokens = $schema['tokens']['space'];
        $this->urlSchemes = $schema['urlSchemes'];
        $this->embedSources = $schema['embedSources'] ?? [];
    }

    // Where a block may stand (source-format.md §4.1): a container may list the children it takes
    // ("children"), and a component the parents it goes in ("parents"). The page is "document".
    private function placementOk(string $parentType, array $comp): bool
    {
        $parent = $this->components[$parentType] ?? null;
        if ($parent !== null && isset($parent['children']) && !in_array($comp['name'], $parent['children'], true)) {
            return false;
        }
        return !isset($comp['parents']) || in_array($parentType, $comp['parents'], true);
    }

    // An Embed's address starts with one of the listed prefixes; "/" means a page of this site,
    // never "//" (another host). A backslash is refused before this: browsers read it as "/".
    private function embedAllowed(string $value): bool
    {
        foreach ($this->embedSources as $source) {
            if ($source === '/' ? str_starts_with($value, '/') && !str_starts_with($value, '//') : str_starts_with($value, $source)) {
                return true;
            }
        }
        return false;
    }

    public static function fromSchemaFile(string $path): self
    {
        return new self(json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR));
    }

    // ------------------------------------------------------------ helpers

    private static function isWs(string $ch): bool
    {
        return $ch === ' ' || $ch === "\t" || $ch === "\n" || $ch === "\r";
    }

    // Wide characters around which a line break disappears (spec §6).
    private static function isWide(int $cp): bool
    {
        return ($cp >= 0x1100 && $cp <= 0x115F) || ($cp >= 0x2E80 && $cp <= 0xA4CF)
            || ($cp >= 0xAC00 && $cp <= 0xD7A3) || ($cp >= 0xF900 && $cp <= 0xFAFF)
            || ($cp >= 0xFE30 && $cp <= 0xFE4F) || ($cp >= 0xFF00 && $cp <= 0xFFEF)
            || ($cp >= 0x20000 && $cp <= 0x3FFFD);
    }

    private static function decodeAt(string $s, int $i): int
    {
        $b = ord($s[$i]);
        if ($b < 0x80) {
            return $b;
        }
        $n = $b >= 0xF0 ? 3 : ($b >= 0xE0 ? 2 : 1);
        $cp = $b & (0x3F >> $n);
        for ($k = 1; $k <= $n; $k++) {
            $cp = ($cp << 6) | (ord($s[$i + $k]) & 0x3F);
        }
        return $cp;
    }

    private static function codePointBefore(string $s, int $offset): ?int
    {
        if ($offset <= 0) {
            return null;
        }
        $i = $offset - 1;
        while ($i > 0 && (ord($s[$i]) & 0xC0) === 0x80) {
            $i--;
        }
        return self::decodeAt($s, $i);
    }

    private static function utf8(int $cp): string
    {
        if ($cp < 0x80) {
            return chr($cp);
        }
        if ($cp < 0x800) {
            return chr(0xC0 | ($cp >> 6)) . chr(0x80 | ($cp & 0x3F));
        }
        if ($cp < 0x10000) {
            return chr(0xE0 | ($cp >> 12)) . chr(0x80 | (($cp >> 6) & 0x3F)) . chr(0x80 | ($cp & 0x3F));
        }
        return chr(0xF0 | ($cp >> 18)) . chr(0x80 | (($cp >> 12) & 0x3F))
            . chr(0x80 | (($cp >> 6) & 0x3F)) . chr(0x80 | ($cp & 0x3F));
    }

    private static function normalizeWhitespace(string $raw): string
    {
        return preg_replace_callback('/[ \t\r\n]+/', static function (array $m) use ($raw): string {
            [$run, $offset] = $m[0];
            if (!str_contains($run, "\n")) {
                return ' ';
            }
            $before = self::codePointBefore($raw, $offset);
            $end = $offset + strlen($run);
            $after = $end < strlen($raw) ? self::decodeAt($raw, $end) : null;
            return ($before !== null && self::isWide($before)) || ($after !== null && self::isWide($after)) ? '' : ' ';
        }, $raw, -1, $count, PREG_OFFSET_CAPTURE);
    }

    /** @param Node[] $children */
    private static function trimEdges(array $children): array
    {
        $first = $children[0] ?? null;
        if ($first !== null && $first->type === 'text') {
            $first->value = ltrim($first->value, ' ');
        }
        $last = $children[count($children) - 1] ?? null;
        if ($last !== null && $last->type === 'text') {
            $last->value = rtrim($last->value, ' ');
        }
        return self::dropEmptyText($children);
    }

    /** @param Node[] $children */
    private static function dropEmptyText(array $children): array
    {
        return array_values(array_filter($children, static fn (Node $c): bool => $c->type !== 'text' || $c->value !== ''));
    }

    private static function escapeText(string $s): string
    {
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $s);
    }

    private static function escapeAttr(string $s): string
    {
        return str_replace('"', '&quot;', self::escapeText($s));
    }

    private static function textNode(string $value): Node
    {
        $n = new Node('text');
        $n->value = $value;
        return $n;
    }

    private function kind(string $type): ?string
    {
        return $this->components[$type]['kind'] ?? null;
    }

    private static function hasChildren(string $kind): bool
    {
        return $kind === 'container' || $kind === 'text' || $kind === 'inline';
    }

    private static function findAttr(array $comp, string $name): ?array
    {
        foreach ($comp['attributes'] as $a) {
            if ($a['name'] === $name) {
                return $a;
            }
        }
        return null;
    }

    /** @return array{0:string,1:string}|null [code, message] when invalid */
    private function checkValue(array $attr, string $value): ?array
    {
        if (preg_match(self::LONE_CR, $value) === 1) {
            return ['E_LONE_CR', '改行は \n か \r\n にしてください'];
        }
        $name = $attr['name'];
        switch ($attr['type']) {
            case 'space':
                return in_array($value, $this->spaceTokens, true) ? null
                    : ['E_INVALID_VALUE', "{$name} には " . implode(' / ', $this->spaceTokens) . ' のどれかを指定してください'];
            case 'enum':
                return in_array($value, $attr['values'], true) ? null
                    : ['E_INVALID_VALUE', "{$name} には " . implode(' / ', $attr['values']) . ' のどれかを指定してください'];
            case 'int':
                // D: without it PHP's $ also matches before a trailing "\n", unlike JS.
                $ok = preg_match('/^(0|[1-9][0-9]*)$/D', $value) === 1 && (int) $value >= $attr['min'] && (int) $value <= $attr['max'];
                return $ok ? null : ['E_INVALID_VALUE', "{$name} には {$attr['min']} から {$attr['max']} の整数を指定してください"];
            case 'url':
                if ($value === '' || preg_match('/[\x00-\x20\x7f]/', $value) === 1) {
                    return ['E_INVALID_VALUE', "{$name} に空白や制御文字は使えません"];
                }
                if (preg_match('/^([A-Za-z][A-Za-z0-9+.\-]*):/', $value, $m) === 1 && !in_array(strtolower($m[1]), $this->urlSchemes, true)) {
                    return ['E_UNSAFE_URL', "{$name} に {$m[1]}: は使えません"];
                }
                return null;
            case 'embed':
                if ($value === '' || preg_match('/[\x00-\x20\x7f\\\\]/', $value) === 1) {
                    return ['E_INVALID_VALUE', "{$name} に空白・制御文字・\\ は使えません"];
                }
                return $this->embedAllowed($value) ? null : ['E_UNSAFE_URL', "{$name} には、決まった地図のサービスか、このサイトのページだけを埋め込めます"];
            default:
                return null;
        }
    }

    private static function orderProps(array $comp, array $props): array
    {
        $ordered = [];
        foreach ($comp['attributes'] as $a) {
            if (array_key_exists($a['name'], $props)) {
                $ordered[$a['name']] = $props[$a['name']];
            }
        }
        return $ordered;
    }

    // ------------------------------------------------------------- parser

    public function parse(string $src): Document
    {
        $this->src = $src;
        $this->len = strlen($src);
        $this->bom = str_starts_with($src, "\xEF\xBB\xBF") ? 3 : 0;
        $this->pos = $this->bom;

        if (preg_match('//u', $src) !== 1) {
            throw new SourceError('E_ENCODING', 1, 1, 'UTF-8 で保存してください');
        }
        if (preg_match(self::LONE_CR, $src, $m, PREG_OFFSET_CAPTURE) === 1) {
            $this->fail('E_LONE_CR', $m[0][1], '改行は \n か \r\n で書いてください');
        }
        $newline = preg_match('/\r?\n/', $src, $nl) === 1 ? $nl[0] : "\n";
        $doc = new Document($src, $newline);
        $this->parseBlockChildren($doc, null, 0);
        return $doc;
    }

    private function fail(string $code, int $offset, string $message): never
    {
        $line = 1;
        $lineStart = $this->bom;
        for ($i = $this->bom; $i < $offset; $i++) {
            if ($this->src[$i] === "\n") {
                $line++;
                $lineStart = $i + 1;
            }
        }
        $column = 1;
        for ($i = $lineStart; $i < $offset; $i++) {
            if ((ord($this->src[$i]) & 0xC0) !== 0x80) {
                $column++;
            }
        }
        throw new SourceError($code, $line, $column, $message);
    }

    private function startsWith(string $s): bool
    {
        return substr($this->src, $this->pos, strlen($s)) === $s;
    }

    private function ch(): string
    {
        return $this->pos < $this->len ? $this->src[$this->pos] : '';
    }

    private function readName(string $class): string
    {
        $start = $this->pos;
        while ($this->pos < $this->len && preg_match($class, $this->src[$this->pos]) === 1) {
            $this->pos++;
        }
        return substr($this->src, $start, $this->pos - $start);
    }

    private function skipWs(): void
    {
        while ($this->pos < $this->len && self::isWs($this->src[$this->pos])) {
            $this->pos++;
        }
    }

    private function decodeEntity(): string
    {
        $start = $this->pos;
        if (preg_match('/^&(?:([a-z]+)|#([0-9]{1,7})|#x([0-9a-fA-F]{1,6}));/', substr($this->src, $this->pos, 12), $m) !== 1) {
            $this->fail('E_BAD_ENTITY', $start, '& は &amp; と書いてください');
        }
        if (($m[1] ?? '') !== '') {
            $ch = self::NAMED_ENTITIES[$m[1]] ?? null;
            if ($ch === null) {
                $this->fail('E_BAD_ENTITY', $start, "&{$m[1]}; は使えません");
            }
        } else {
            $cp = ($m[2] ?? '') !== '' ? (int) $m[2] : hexdec($m[3]);
            if ($cp === 0 || $cp > 0x10FFFF || ($cp >= 0xD800 && $cp <= 0xDFFF)) {
                $this->fail('E_BAD_ENTITY', $start, '使えない文字番号です');
            }
            $ch = self::utf8($cp);
        }
        $this->pos += strlen($m[0]);
        return $ch;
    }

    private function parseComment(): Node
    {
        $start = $this->pos;
        $end = strpos($this->src, '-->', $this->pos + 4);
        if ($end === false) {
            $this->fail('E_UNCLOSED_COMMENT', $start, 'コメントが --> で閉じられていません');
        }
        $this->pos = $end + 3;
        $n = new Node('comment');
        $n->value = str_replace("\r\n", "\n", substr($this->src, $start + 4, $end - $start - 4));
        $n->span = [$start, $this->pos];
        return $n;
    }

    private function parseClose(?string $name): int
    {
        $start = $this->pos;
        $this->pos += 2;
        $closeName = $this->readName('/[A-Za-z0-9]/');
        $this->skipWs();
        if ($this->ch() !== '>') {
            $this->fail('E_UNEXPECTED_CHAR', $this->pos, '終了タグは </名前> と書いてください');
        }
        if ($closeName !== $name) {
            if ($name === null) {
                $this->fail('E_UNEXPECTED_CLOSE', $start, "対応する <{$closeName}> がありません");
            }
            $this->fail('E_MISMATCHED_CLOSE', $start, "</{$name}> が来るはずの場所に </{$closeName}> があります");
        }
        $this->pos++;
        return $start;
    }

    private function parseBlockChildren(Node $parent, ?string $closeName, int $parentStart): ?int
    {
        $index = 0;
        for (;;) {
            $wsStart = $this->pos;
            $this->skipWs();
            $leading = substr($this->src, $wsStart, $this->pos - $wsStart);
            if ($this->pos >= $this->len) {
                if ($closeName !== null) {
                    $this->fail('E_UNCLOSED_TAG', $parentStart, "<{$closeName}> が閉じられていません");
                }
                $parent->innerTrailing = $leading;
                $parent->origCount = $index;
                return null;
            }
            if ($this->startsWith('</')) {
                if ($closeName === null) {
                    $this->parseClose(null);
                }
                $parent->innerTrailing = $leading;
                $parent->origCount = $index;
                return $this->parseClose($closeName);
            }
            if ($this->startsWith('<!--')) {
                $node = $this->parseComment();
            } elseif ($this->ch() === '<') {
                $node = $this->parseElement('block', [], $parent->type);
            } else {
                $this->fail('E_TEXT_IN_BLOCK', $this->pos, '文字は Text などの部品の中に書いてください');
            }
            $node->leading = $leading;
            $node->origIndex = $index++;
            $parent->children[] = $node;
        }
    }

    /** @return array{0:Node[],1:int} */
    private function parseInline(Node $el, string $closeName, array $excluded): array
    {
        $parts = [];
        for (;;) {
            if ($this->pos >= $this->len) {
                $this->fail('E_UNCLOSED_TAG', $el->span[0], "<{$closeName}> が閉じられていません");
            }
            if ($this->startsWith('</')) {
                return [$parts, $this->parseClose($closeName)];
            }
            if ($this->startsWith('<!--')) {
                $this->fail('E_COMMENT_IN_INLINE', $this->pos, '文の途中にはコメントを書けません');
            }
            if ($this->ch() === '<') {
                $parts[] = $this->parseElement('inline', $excluded);
                continue;
            }
            $value = '';
            while ($this->pos < $this->len && $this->src[$this->pos] !== '<') {
                if ($this->src[$this->pos] === '&') {
                    $value .= $this->decodeEntity();
                } else {
                    $value .= $this->src[$this->pos++];
                }
            }
            $parts[] = self::textNode(self::normalizeWhitespace($value));
        }
    }

    // $parentType is the type of the block this one stands in (blocks only).
    private function parseElement(string $context, array $excluded, ?string $parentType = null): Node
    {
        $start = $this->pos;
        $this->pos++;
        if (preg_match('/[A-Za-z]/', $this->ch()) !== 1) {
            $this->fail('E_UNEXPECTED_CHAR', $this->pos, '< の後には部品の名前を書いてください');
        }
        $name = $this->readName('/[A-Za-z0-9]/');
        $comp = $this->components[$name] ?? null;
        if ($comp === null) {
            $hint = preg_match('/^[a-z]/', $name) === 1 ? '（HTML のタグはそのままでは使えません。部品の名前は大文字で始まります）' : '';
            $this->fail('E_UNKNOWN_COMPONENT', $start, "<{$name}> という部品はありません{$hint}");
        }
        $allowed = $context === 'block' ? self::BLOCK_KINDS : self::INLINE_KINDS;
        if (!in_array($comp['kind'], $allowed, true) || in_array($name, $excluded, true)) {
            $this->fail('E_NOT_ALLOWED_HERE', $start, "<{$name}> はここには置けません");
        }
        if ($context === 'block' && !$this->placementOk((string) $parentType, $comp)) {
            $where = $parentType === 'document' ? 'ページの直下' : "<{$parentType}> の中";
            $this->fail('E_NOT_ALLOWED_HERE', $start, "<{$name}> は {$where}には置けません");
        }

        $props = [];
        $selfClosing = false;
        for (;;) {
            $beforeWs = $this->pos;
            $this->skipWs();
            if ($this->pos >= $this->len) {
                $this->fail('E_EOF_IN_TAG', $start, "<{$name}> のタグが > で閉じられていません");
            }
            if ($this->startsWith('/>')) {
                $this->pos += 2;
                $selfClosing = true;
                break;
            }
            if ($this->ch() === '>') {
                $this->pos++;
                break;
            }
            if ($beforeWs === $this->pos || preg_match('/[A-Za-z]/', $this->ch()) !== 1) {
                $this->fail('E_UNEXPECTED_CHAR', $this->pos, 'タグの書き方が正しくありません');
            }
            $nameStart = $this->pos;
            $attrName = $this->readName('/[A-Za-z0-9-]/');
            $attr = self::findAttr($comp, $attrName);
            if ($attr === null) {
                $this->fail('E_UNKNOWN_ATTRIBUTE', $nameStart, "<{$name}> に {$attrName} という属性はありません");
            }
            if (array_key_exists($attrName, $props)) {
                $this->fail('E_DUPLICATE_ATTRIBUTE', $nameStart, "{$attrName} が 2 回書かれています");
            }
            if ($this->ch() !== '=') {
                $this->fail('E_UNQUOTED_ATTRIBUTE', $this->pos, "{$attrName}=\"値\" の形で書いてください");
            }
            $this->pos++;
            if ($this->ch() !== '"') {
                $this->fail('E_UNQUOTED_ATTRIBUTE', $this->pos, '値は " で囲んでください');
            }
            $valueStart = $this->pos;
            $this->pos++;
            $value = '';
            for (;;) {
                if ($this->pos >= $this->len) {
                    $this->fail('E_EOF_IN_TAG', $start, "<{$name}> のタグが > で閉じられていません");
                }
                $c = $this->src[$this->pos];
                if ($c === '"') {
                    break;
                }
                if ($c === '&') {
                    $value .= $this->decodeEntity();
                } else {
                    $value .= $c;
                    $this->pos++;
                }
            }
            $this->pos++;
            $problem = $this->checkValue($attr, $value);
            if ($problem !== null) {
                $this->fail($problem[0], $valueStart, $problem[1]);
            }
            $props[$attrName] = $value;
        }
        foreach ($comp['attributes'] as $a) {
            if (($a['required'] ?? false) && !array_key_exists($a['name'], $props)) {
                $this->fail('E_MISSING_ATTRIBUTE', $start, "<{$name}> には {$a['name']} が必要です");
            }
        }
        $kind = $comp['kind'];
        if (($kind === 'void' || $kind === 'inline-void') && !$selfClosing) {
            $this->fail('E_VOID_NOT_SELF_CLOSED', $start, "<{$name} … /> のように /> で閉じてください");
        }

        $el = new Node($name);
        $el->props = self::orderProps($comp, $props);
        $el->span = [$start, 0];
        $el->openEnd = $this->pos;
        $el->selfClosing = $selfClosing;
        if (self::hasChildren($kind)) {
            $el->children = [];
        }
        if ($kind === 'raw') {
            $el->raw = '';
        }

        if (!$selfClosing) {
            if ($kind === 'container') {
                $el->closeStart = $this->parseBlockChildren($el, $name, $start);
            } elseif ($kind === 'raw') {
                $close = strpos($this->src, '</Html>', $this->pos);
                if ($close === false) {
                    $this->fail('E_UNCLOSED_RAW', $start, '<Html> が </Html> で閉じられていません');
                }
                $el->raw = str_replace("\r\n", "\n", substr($this->src, $this->pos, $close - $this->pos));
                $el->closeStart = $close;
                $this->pos = $close + strlen('</Html>');
            } else {
                $inner = array_values(array_unique(array_merge($excluded, $comp['excludes'] ?? [])));
                [$parts, $closeStart] = $this->parseInline($el, $name, $inner);
                $el->children = $kind === 'text' ? self::trimEdges($parts) : self::dropEmptyText($parts);
                $el->closeStart = $closeStart;
            }
        } elseif ($kind === 'container') {
            $el->origCount = 0;
        }
        $el->span[1] = $this->pos;
        return $el;
    }

    // ---------------------------------------------------------- serialize

    private static function openTag(Node $node, bool $selfClose): string
    {
        $s = '<' . $node->type;
        foreach ($node->props as $k => $v) {
            $s .= ' ' . $k . '="' . self::escapeAttr($v) . '"';
        }
        return $s . ($selfClose ? ' />' : '>');
    }

    /** @param Node[] $children */
    private function inlineContent(array $children): string
    {
        $out = '';
        foreach ($children as $c) {
            $out .= $c->type === 'text' ? self::escapeText($c->value) : $this->canonicalNode($c, '', "\n");
        }
        return $out;
    }

    private function canonicalNode(Node $node, string $indent, string $nl): string
    {
        if ($node->type === 'comment') {
            return '<!--' . str_replace("\n", $nl, $node->value) . '-->';
        }
        $kind = $this->kind($node->type);
        if ($kind === 'void' || $kind === 'inline-void') {
            return self::openTag($node, true);
        }
        if ($kind === 'raw') {
            return self::openTag($node, false) . str_replace("\n", $nl, $node->raw) . '</Html>';
        }
        if (count($node->children) === 0) {
            return self::openTag($node, true);
        }
        if ($kind === 'container') {
            $inner = $indent . '  ';
            $s = self::openTag($node, false);
            foreach ($node->children as $c) {
                $s .= $nl . $inner . $this->canonicalNode($c, $inner, $nl);
            }
            return $s . $nl . $indent . '</' . $node->type . '>';
        }
        return self::openTag($node, false) . $this->inlineContent($node->children) . '</' . $node->type . '>';
    }

    public function serializeCanonical(Document $doc): string
    {
        $out = '';
        foreach ($doc->children as $c) {
            $out .= $this->canonicalNode($c, '', "\n") . "\n";
        }
        return $out;
    }

    // Leading whitespace for a child that has none of its own (inserted or moved).
    private static function leadingFor(Node $parent, int $i, string $parentIndent, string $nl): string
    {
        $isDoc = $parent->type === 'document';
        if ($isDoc && $i === 0) {
            return '';
        }
        $kids = $parent->children;
        $candidates = array_merge([$kids[$i - 1] ?? null, $kids[$i + 1] ?? null], $kids);
        foreach ($candidates as $c) {
            if ($c !== null && $c->leading !== null && str_contains($c->leading, "\n")) {
                return $c->leading;
            }
        }
        return $nl . ($isDoc ? '' : $parentIndent . '  ');
    }

    private static function indentOf(string $lead, string $fallback): string
    {
        $i = strrpos($lead, "\n");
        return $i === false ? $fallback : substr($lead, $i + 1);
    }

    public function serialize(Document $doc): string
    {
        if (!$doc->childrenDirty) {
            return $doc->source;
        }
        $body = $this->serializeChildren($doc, $doc, '');
        if (count($doc->children) > 0 && $doc->origCount === 0) {
            return $body . $doc->newline;
        }
        return $body . ($doc->innerTrailing ?? '');
    }

    private function serializeChildren(Document $doc, Node $parent, string $indent): string
    {
        $isDoc = $parent->type === 'document';
        $out = '';
        foreach ($parent->children as $i => $c) {
            $lead = $c->leading;
            if ($isDoc && $i === 0 && $c->origIndex !== 0) {
                $lead = '';
            } elseif ($lead === null || ($isDoc && $i > 0 && $lead === '' && $c->origIndex === 0)) {
                $lead = self::leadingFor($parent, $i, $indent, $doc->newline);
            }
            $out .= $lead . $this->serializeNode($doc, $c, self::indentOf($lead, $isDoc ? '' : $indent . '  '));
        }
        return $out;
    }

    private function serializeNode(Document $doc, Node $n, string $indent): string
    {
        $src = $doc->source;
        $nl = $doc->newline;
        if ($n->span === null) {
            return $this->canonicalNode($n, $indent, $nl);
        }
        if (!$n->dirty && !$n->childrenDirty && !$n->contentDirty) {
            return substr($src, $n->span[0], $n->span[1] - $n->span[0]);
        }
        $kind = $this->kind($n->type);
        if ($kind === 'void' || $kind === 'inline-void') {
            return self::openTag($n, true);
        }
        $empty = $kind === 'raw' ? false : count($n->children) === 0;
        if ($n->selfClosing && $empty) {
            return self::openTag($n, true);
        }
        $open = $n->dirty || $n->selfClosing ? self::openTag($n, false) : substr($src, $n->span[0], $n->openEnd - $n->span[0]);
        $close = $n->selfClosing ? '</' . $n->type . '>' : substr($src, $n->closeStart, $n->span[1] - $n->closeStart);
        if ($kind === 'container') {
            if ($empty) {
                $inner = '';
            } else {
                $trailing = $n->origCount === 0 || $n->innerTrailing === null ? $nl . $indent : $n->innerTrailing;
                $inner = $this->serializeChildren($doc, $n, $indent) . $trailing;
            }
        } elseif ($kind === 'raw') {
            $inner = $n->contentDirty ? str_replace("\n", $nl, $n->raw) : substr($src, $n->openEnd, $n->closeStart - $n->openEnd);
        } else {
            // childrenDirty: an inline descendant (Link, Strong…) was edited.
            $inner = $n->contentDirty || $n->childrenDirty || $n->selfClosing
                ? $this->inlineContent($n->children)
                : substr($src, $n->openEnd, $n->closeStart - $n->openEnd);
        }
        return $open . $inner . $close;
    }

    // ----------------------------------------------------------- JSON tree

    public function toJSON(Node $n): array
    {
        if ($n->type === 'document') {
            return ['type' => 'document', 'children' => array_map([$this, 'toJSON'], $n->children)];
        }
        if ($n->type === 'text' || $n->type === 'comment') {
            return ['type' => $n->type, 'value' => $n->value];
        }
        $out = ['type' => $n->type, 'props' => (object) $n->props];
        if ($n->children !== null) {
            $out['children'] = array_map([$this, 'toJSON'], $n->children);
        }
        if ($n->raw !== null) {
            $out['raw'] = $n->raw;
        }
        return $out;
    }

    // $parentType is the type of the block a new block goes in (blocks only).
    private function fromJSON(mixed $json, string $context, array $excluded, ?string $parentType = null): Node
    {
        if (!is_array($json) || !isset($json['type']) || !is_string($json['type'])) {
            throw new EditError('E_INVALID_NODE', 'ノードはオブジェクトで渡してください');
        }
        if ($json['type'] === 'comment') {
            if ($context !== 'block') {
                throw new EditError('E_COMMENT_IN_INLINE', '文の途中にはコメントを置けません');
            }
            $value = $json['value'] ?? null;
            // A trailing "-" would read back as part of the closing "-->".
            if (!is_string($value) || str_contains($value, '-->') || str_ends_with($value, '-')) {
                throw new EditError('E_INVALID_VALUE', 'コメントに --> は含められず、- で終われません');
            }
            if (preg_match(self::LONE_CR, $value) === 1) {
                throw new EditError('E_LONE_CR', '改行は \n か \r\n にしてください');
            }
            $n = new Node('comment');
            $n->value = str_replace("\r\n", "\n", $value);
            return $n;
        }
        if ($json['type'] === 'text') {
            if ($context !== 'inline') {
                throw new EditError('E_TEXT_IN_BLOCK', '文字は Text などの部品の中に置いてください');
            }
            if (!is_string($json['value'] ?? null)) {
                throw new EditError('E_INVALID_VALUE', 'value は文字列で渡してください');
            }
            return self::textNode(self::normalizeWhitespace($json['value']));
        }
        $comp = $this->components[$json['type']] ?? null;
        if ($comp === null) {
            throw new EditError('E_UNKNOWN_COMPONENT', "{$json['type']} という部品はありません");
        }
        $allowed = $context === 'block' ? self::BLOCK_KINDS : self::INLINE_KINDS;
        if (!in_array($comp['kind'], $allowed, true) || in_array($comp['name'], $excluded, true)) {
            throw new EditError('E_NOT_ALLOWED_HERE', "{$comp['name']} はここには置けません");
        }
        if ($context === 'block' && !$this->placementOk((string) $parentType, $comp)) {
            throw new EditError('E_NOT_ALLOWED_HERE', "{$comp['name']} は {$parentType} の中には置けません");
        }
        $props = $json['props'] ?? [];
        if (!is_array($props)) {
            throw new EditError('E_INVALID_NODE', 'props はオブジェクトで渡してください');
        }
        foreach ($props as $k => $v) {
            $attr = self::findAttr($comp, (string) $k);
            if ($attr === null) {
                throw new EditError('E_UNKNOWN_ATTRIBUTE', "{$comp['name']} に {$k} という属性はありません");
            }
            if (!is_string($v)) {
                throw new EditError('E_INVALID_VALUE', "{$k} は文字列で渡してください");
            }
            $problem = $this->checkValue($attr, $v);
            if ($problem !== null) {
                throw new EditError($problem[0], $problem[1]);
            }
        }
        foreach ($comp['attributes'] as $a) {
            if (($a['required'] ?? false) && !array_key_exists($a['name'], $props)) {
                throw new EditError('E_MISSING_ATTRIBUTE', "{$comp['name']} には {$a['name']} が必要です");
            }
        }
        $node = new Node($comp['name']);
        $node->props = self::orderProps($comp, $props);
        if ($comp['kind'] === 'raw') {
            $node->raw = self::checkRaw($json['raw'] ?? '');
        }
        if ($comp['kind'] === 'container') {
            $node->children = array_map(fn ($c) => $this->fromJSON($c, 'block', [], $comp['name']), self::listOf($json['children'] ?? []));
        }
        if ($comp['kind'] === 'text' || $comp['kind'] === 'inline') {
            $node->children = $this->inlineFromJSON($comp, $json['children'] ?? [], $excluded);
        }
        return $node;
    }

    private static function listOf(mixed $list): array
    {
        if (!is_array($list) || !array_is_list($list)) {
            throw new EditError('E_INVALID_NODE', 'children は配列で渡してください');
        }
        return $list;
    }

    private function inlineFromJSON(array $comp, mixed $list, array $excluded): array
    {
        $inner = array_values(array_unique(array_merge($excluded, $comp['excludes'] ?? [])));
        // Adjacent text would merge on reparse, so merge it here to keep the tree stable.
        $kids = [];
        foreach (self::listOf($list) as $x) {
            $c = $this->fromJSON($x, 'inline', $inner);
            $prev = $kids[count($kids) - 1] ?? null;
            if ($c->type === 'text' && $prev !== null && $prev->type === 'text') {
                $prev->value = self::normalizeWhitespace($prev->value . $c->value);
            } else {
                $kids[] = $c;
            }
        }
        $nonEmpty = self::dropEmptyText($kids);
        return $comp['kind'] === 'text' ? self::trimEdges($nonEmpty) : $nonEmpty;
    }

    private static function checkRaw(mixed $raw): string
    {
        if (!is_string($raw) || str_contains($raw, '</Html>')) {
            throw new EditError('E_INVALID_VALUE', 'Html の中身に </Html> は含められません');
        }
        if (preg_match(self::LONE_CR, $raw) === 1) {
            throw new EditError('E_LONE_CR', '改行は \n か \r\n にしてください');
        }
        return str_replace("\r\n", "\n", $raw);
    }

    // --------------------------------------------------------------- edits

    /** @return Node[] the nodes from the document down to the target */
    private static function walk(Document $doc, array $path): array
    {
        $chain = [$doc];
        $n = $doc;
        foreach ($path as $i) {
            if ($n->children === null || !is_int($i) || $i < 0 || $i >= count($n->children)) {
                throw new EditError('E_BAD_PATH', '位置 ' . json_encode($path) . ' に部品がありません');
            }
            $n = $n->children[$i];
            $chain[] = $n;
        }
        return $chain;
    }

    /** @param Node[] $chain */
    private static function markAncestors(array $chain): void
    {
        foreach ($chain as $n) {
            $n->childrenDirty = true;
        }
    }

    private function blockParent(Document $doc, array $path): array
    {
        $chain = self::walk($doc, $path);
        $parent = $chain[count($chain) - 1];
        if ($parent->type !== 'document' && $this->kind($parent->type) !== 'container') {
            throw new EditError('E_NOT_ALLOWED_HERE', "{$parent->type} には子を置けません");
        }
        return $chain;
    }

    /** @return array{0:Node[],1:Node,2:array} */
    private function elementAt(Document $doc, array $path): array
    {
        $chain = self::walk($doc, $path);
        $n = $chain[count($chain) - 1];
        $comp = $this->components[$n->type] ?? null;
        if ($comp === null) {
            throw new EditError('E_BAD_PATH', '位置 ' . json_encode($path) . ' は部品ではありません');
        }
        return [$chain, $n, $comp];
    }

    // Excludes inherited from inline ancestors, so setInline cannot nest Link in Link.
    private function inheritedExcludes(array $chain): array
    {
        $set = [];
        foreach ($chain as $n) {
            foreach ($this->components[$n->type]['excludes'] ?? [] as $x) {
                $set[] = $x;
            }
        }
        return array_values(array_unique($set));
    }

    private static function checkIndex(mixed $index, int $max): void
    {
        if (!is_int($index) || $index < 0 || $index > $max) {
            throw new EditError('E_BAD_PATH', '位置 ' . json_encode($index) . ' には入れられません');
        }
    }

    public function setProp(Document $doc, array $path, string $name, mixed $value): void
    {
        [$chain, $n, $comp] = $this->elementAt($doc, $path);
        $attr = self::findAttr($comp, $name);
        if ($attr === null) {
            throw new EditError('E_UNKNOWN_ATTRIBUTE', "{$comp['name']} に {$name} という属性はありません");
        }
        if ($value === null) {
            if ($attr['required'] ?? false) {
                throw new EditError('E_MISSING_ATTRIBUTE', "{$name} は消せません");
            }
            unset($n->props[$name]);
        } else {
            if (!is_string($value)) {
                throw new EditError('E_INVALID_VALUE', "{$name} は文字列で渡してください");
            }
            $problem = $this->checkValue($attr, $value);
            if ($problem !== null) {
                throw new EditError($problem[0], $problem[1]);
            }
            $n->props = self::orderProps($comp, array_merge($n->props, [$name => $value]));
        }
        $n->dirty = true;
        self::markAncestors(array_slice($chain, 0, -1));
    }

    public function insert(Document $doc, array $parentPath, mixed $index, mixed $json): void
    {
        $chain = $this->blockParent($doc, $parentPath);
        $parent = $chain[count($chain) - 1];
        self::checkIndex($index, count($parent->children));
        array_splice($parent->children, $index, 0, [$this->fromJSON($json, 'block', [], $parent->type)]);
        self::markAncestors($chain);
    }

    public function remove(Document $doc, array $path): void
    {
        if ($path === []) {
            throw new EditError('E_BAD_PATH', 'ページそのものは消せません');
        }
        $chain = $this->blockParent($doc, array_slice($path, 0, -1));
        self::walk($doc, $path);
        array_splice($chain[count($chain) - 1]->children, $path[count($path) - 1], 1);
        self::markAncestors($chain);
    }

    public function move(Document $doc, array $fromPath, array $parentPath, mixed $index): void
    {
        if ($fromPath === []) {
            throw new EditError('E_BAD_PATH', 'ページそのものは動かせません');
        }
        $fromChain = $this->blockParent($doc, array_slice($fromPath, 0, -1));
        $moving = self::walk($doc, $fromPath)[count($fromPath)];
        $fromParent = $fromChain[count($fromChain) - 1];
        $fromIndex = $fromPath[count($fromPath) - 1];
        array_splice($fromParent->children, $fromIndex, 1);
        try {
            $toChain = $this->blockParent($doc, $parentPath);
            if (in_array($moving, $toChain, true)) {
                throw new EditError('E_BAD_PATH', '部品を自分の中には動かせません');
            }
            $to = $toChain[count($toChain) - 1];
            self::checkIndex($index, count($to->children));
            $movingComp = $this->components[$moving->type] ?? null;
            if ($movingComp !== null && !$this->placementOk($to->type, $movingComp)) {
                throw new EditError('E_NOT_ALLOWED_HERE', "{$moving->type} は {$to->type} の中には置けません");
            }
            $moving->leading = null;
            array_splice($to->children, $index, 0, [$moving]);
        } catch (EditError $e) {
            array_splice($fromParent->children, $fromIndex, 0, [$moving]);
            throw $e;
        }
        self::markAncestors($fromChain);
        self::markAncestors($toChain);
    }

    public function setInline(Document $doc, array $path, mixed $list): void
    {
        [$chain, $n, $comp] = $this->elementAt($doc, $path);
        if ($comp['kind'] !== 'text' && $comp['kind'] !== 'inline') {
            throw new EditError('E_NOT_ALLOWED_HERE', "{$comp['name']} は文を持てません");
        }
        $n->children = $this->inlineFromJSON($comp, $list, $this->inheritedExcludes(array_slice($chain, 0, -1)));
        $n->contentDirty = true;
        self::markAncestors(array_slice($chain, 0, -1));
    }

    public function setRaw(Document $doc, array $path, mixed $raw): void
    {
        [$chain, $n, $comp] = $this->elementAt($doc, $path);
        if ($comp['kind'] !== 'raw') {
            throw new EditError('E_NOT_ALLOWED_HERE', "{$comp['name']} は Html ではありません");
        }
        $n->raw = self::checkRaw($raw);
        $n->contentDirty = true;
        self::markAncestors(array_slice($chain, 0, -1));
    }
}
