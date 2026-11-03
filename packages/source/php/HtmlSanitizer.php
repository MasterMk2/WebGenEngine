<?php
// Sanitizing the content of an Html block for publishing (docs/design/rendering.md §7).
// Behaviour must match js/sanitize-html.mjs: both are checked against ../fixtures/render,
// and against each other by ../differential.
//
// The input is read once, left to right, and the output is written only from what was
// read: allowed tags again from their lower-cased name and allowed attributes, text with
// `<` and `>` escaped. Everything else is dropped, so nothing of the input can reach the
// output as markup. Only ASCII characters have a meaning here (no `\s`, no `/u`), so that
// bytes in PHP and UTF-16 in JS give the same result.

declare(strict_types=1);

namespace WebGenEngine\Source;

final class HtmlSanitizer
{
    private const WS = "\t\n\f\r ";
    private const NAMED = ['amp' => '&', 'lt' => '<', 'gt' => '>', 'quot' => '"', 'apos' => "'"];
    // The character references the source format knows: the five named ones and numeric ones.
    private const REF = '/&(?:#[xX]([0-9A-Fa-f]+);?|#([0-9]+);?|(amp|lt|gt|quot|apos);)/';

    /** @param array $policy the `sanitize` entry of a raw component in schema.json */
    public function __construct(private readonly array $policy, private readonly array $urlSchemes)
    {
    }

    private static function isLetter(string $c): bool
    {
        $o = $c === '' ? 0 : ord($c);
        return ($o >= 0x41 && $o <= 0x5A) || ($o >= 0x61 && $o <= 0x7A);
    }

    private static function escapeText(string $s): string
    {
        return str_replace(['<', '>'], ['&lt;', '&gt;'], $s);
    }

    private static function escapeAttr(string $s): string
    {
        return str_replace(['&', '<', '>', '"'], ['&amp;', '&lt;', '&gt;', '&quot;'], $s);
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

    // Every `&` left after this is escaped again on output, so the browser sees exactly the
    // value that was checked.
    private static function decodeValue(string $v): string
    {
        return (string) preg_replace_callback(self::REF, static function (array $m): string {
            if (($m[3] ?? '') !== '') {
                return self::NAMED[$m[3]];
            }
            $hex = $m[1] !== '';
            $digits = ltrim($hex ? $m[1] : $m[2], '0');
            $cp = strlen($digits) > 8 ? 0x110000 : ($hex ? (int) hexdec($digits === '' ? '0' : $digits) : (int) $digits);
            return $cp === 0 || $cp > 0x10FFFF || ($cp >= 0xD800 && $cp <= 0xDFFF) ? "\u{FFFD}" : self::utf8($cp);
        }, $v);
    }

    /**
     * Reads a tag whose name starts at $i (after `<` or `</`). Returns null when the input
     * ends inside the tag; browsers drop such a tag.
     *
     * @return array{name:string,attrs:list<array{0:string,1:string}>,end:int}|null
     */
    private static function readTag(string $s, int $i): ?array
    {
        $len = strlen($s);
        $nameEnd = $i + strcspn($s, self::WS . '/>', $i);
        $name = strtolower(substr($s, $i, $nameEnd - $i));
        $attrs = [];
        $i = $nameEnd;
        for (;;) {
            $i += strspn($s, self::WS . '/', $i);
            if ($i >= $len) {
                return null;
            }
            if ($s[$i] === '>') {
                return ['name' => $name, 'attrs' => $attrs, 'end' => $i + 1];
            }
            // The first character may be `=`, as in browsers.
            $attrEnd = $i + 1 + strcspn($s, self::WS . '/>=', $i + 1);
            $attr = strtolower(substr($s, $i, $attrEnd - $i));
            $i = $attrEnd + strspn($s, self::WS, $attrEnd);
            $value = '';
            if ($i < $len && $s[$i] === '=') {
                $i++;
                $i += strspn($s, self::WS, $i);
                $q = $i < $len ? $s[$i] : '';
                if ($q === '"' || $q === "'") {
                    $close = strpos($s, $q, $i + 1);
                    if ($close === false) {
                        return null;
                    }
                    $value = substr($s, $i + 1, $close - $i - 1);
                    $i = $close + 1;
                } else {
                    $valueEnd = $i + strcspn($s, self::WS . '>', $i);
                    $value = substr($s, $i, $valueEnd - $i);
                    $i = $valueEnd;
                }
            }
            $attrs[] = [$attr, $value];
        }
    }

    // The same rule as attributes of type `url` in the source format, after trimming the
    // spaces that browsers trim too.
    private function safeUrl(string $v): ?string
    {
        $t = trim($v, self::WS);
        if ($t === '' || preg_match('/[\x00-\x20\x7f]/', $t) === 1) {
            return null;
        }
        if (preg_match('/^([A-Za-z][A-Za-z0-9+.\-]*):/', $t, $m) === 1 && !in_array(strtolower($m[1]), $this->urlSchemes, true)) {
            return null;
        }
        return $t;
    }

    /** @param array{name:string,attrs:list<array{0:string,1:string}>,end:int} $tag */
    private function startTag(array $tag): string
    {
        $allowed = $this->policy['elements'][$tag['name']];
        $seen = [];
        $out = '<' . $tag['name'];
        foreach ($tag['attrs'] as [$name, $raw]) {
            // Browsers keep the first of repeated attributes.
            if (isset($seen[$name])) {
                continue;
            }
            $seen[$name] = true;
            if (!in_array($name, $allowed, true) && !in_array($name, $this->policy['globalAttributes'], true)) {
                continue;
            }
            $value = self::decodeValue($raw);
            if (in_array($name, $this->policy['urlAttributes'], true)) {
                $value = $this->safeUrl($value);
                if ($value === null) {
                    continue;
                }
            }
            $out .= ' ' . $name . '="' . self::escapeAttr($value) . '"';
        }
        return $out . '>';
    }

    // Where the end tag of an element whose content is dropped starts, or the end of input.
    private static function contentEnd(string $low, string $name, int $from): int
    {
        $needle = '</' . $name;
        for ($at = strpos($low, $needle, $from); $at !== false; $at = strpos($low, $needle, $at + 1)) {
            $next = $low[$at + strlen($needle)] ?? '';
            if ($next === '' || str_contains(self::WS . '/>', $next)) {
                return $at;
            }
        }
        return strlen($low);
    }

    public function sanitize(string $s): string
    {
        $low = strtolower($s);
        $len = strlen($s);
        $open = [];
        $out = '';
        $i = 0;
        while ($i < $len) {
            $lt = strpos($s, '<', $i);
            if ($lt === false) {
                $out .= self::escapeText(substr($s, $i));
                break;
            }
            $out .= self::escapeText(substr($s, $i, $lt - $i));
            $i = $lt;
            $next = $s[$i + 1] ?? '';
            if (substr($s, $i, 4) === '<!--') {
                // From $i + 2, so that `<!-->` and `<!--->` end at once, as in browsers.
                $end = strpos($s, '-->', $i + 2);
                $i = $end === false ? $len : $end + 3;
            } elseif ($next === '!' || $next === '?') {
                $end = strpos($s, '>', $i + 2);
                $i = $end === false ? $len : $end + 1;
            } elseif (self::isLetter($next) || ($next === '/' && self::isLetter($s[$i + 2] ?? ''))) {
                $closing = $next === '/';
                $tag = self::readTag($s, $i + ($closing ? 2 : 1));
                if ($tag === null) {
                    break;
                }
                $i = $tag['end'];
                if ($closing) {
                    $at = array_search($tag['name'], array_reverse($open, true), true);
                    if ($at !== false) {
                        while (count($open) > $at) {
                            $out .= '</' . array_pop($open) . '>';
                        }
                    }
                } elseif (in_array($tag['name'], $this->policy['dropContent'], true)) {
                    $i = self::contentEnd($low, $tag['name'], $i);
                } elseif (array_key_exists($tag['name'], $this->policy['elements'])) {
                    $out .= $this->startTag($tag);
                    if (!in_array($tag['name'], $this->policy['void'], true)) {
                        $open[] = $tag['name'];
                    }
                }
            } else {
                $out .= '&lt;';
                $i++;
            }
        }
        while ($open !== []) {
            $out .= '</' . array_pop($open) . '>';
        }
        return $out;
    }
}
