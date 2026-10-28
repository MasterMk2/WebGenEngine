<?php
// Differential test, step 2: replay gen.mjs cases in PHP and report differences from JS.
// Usage: php check.php <cases.json>

declare(strict_types=1);

require dirname(__DIR__) . '/php/SourceFormat.php';
require dirname(__DIR__) . '/php/Renderer.php';

use WebGenEngine\Source\EditError;
use WebGenEngine\Source\Renderer;
use WebGenEngine\Source\SourceError;
use WebGenEngine\Source\SourceFormat;

$fmt = SourceFormat::fromSchemaFile(dirname(__DIR__) . '/schema.json');
$renderer = Renderer::fromFiles(dirname(__DIR__));

function canon(mixed $v): string
{
    if ($v instanceof stdClass) {
        $v = get_object_vars($v);
        if ($v === []) {
            return '{}';
        }
    }
    if (is_array($v)) {
        if (array_is_list($v)) {
            return '[' . implode(',', array_map('canon', $v)) . ']';
        }
        ksort($v, SORT_STRING);
        $parts = [];
        foreach ($v as $k => $x) {
            $parts[] = json_encode((string) $k, JSON_UNESCAPED_UNICODE) . ':' . canon($x);
        }
        return '{' . implode(',', $parts) . '}';
    }
    return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function toArrays(mixed $v): mixed
{
    if ($v instanceof stdClass) {
        $v = get_object_vars($v);
    }
    return is_array($v) ? array_map('toArrays', $v) : $v;
}

function apply(SourceFormat $fmt, $doc, array $op): void
{
    match ($op['op']) {
        'setProp' => $fmt->setProp($doc, $op['path'], $op['name'], $op['value']),
        'insert' => $fmt->insert($doc, $op['parent'], $op['index'], $op['node']),
        'remove' => $fmt->remove($doc, $op['path']),
        'move' => $fmt->move($doc, $op['from'], $op['parent'], $op['index']),
        'setInline' => $fmt->setInline($doc, $op['path'], $op['children']),
        default => $fmt->setRaw($doc, $op['path'], $op['raw']),
    };
}

function result(SourceFormat $fmt, Renderer $renderer, string $source, ?array $ops): array
{
    try {
        $doc = $fmt->parse($source);
    } catch (SourceError $e) {
        return ['error' => [$e->errorCode, $e->sourceLine, $e->sourceColumn]];
    }
    $res = ['tree' => $fmt->toJSON($doc), 'canonical' => $fmt->serializeCanonical($doc), 'html' => $renderer->renderFragment($doc)];
    if ($ops !== null) {
        $res['editErrors'] = array_map(static function (array $op) use ($fmt, $doc): ?string {
            try {
                apply($fmt, $doc, $op);
                return null;
            } catch (EditError $e) {
                return $e->errorCode;
            }
        }, $ops);
        $res['output'] = $fmt->serialize($doc);
        $res['htmlAfter'] = $renderer->renderFragment($doc);
    }
    return $res;
}

$cases = json_decode((string) file_get_contents($argv[1]), false, 512, JSON_THROW_ON_ERROR);
$diffs = 0;
foreach ($cases as $i => $c) {
    $ops = $c->ops === null ? null : toArrays($c->ops);
    $php = result($fmt, $renderer, $c->source, $ops);
    if (canon($php) !== canon($c->js)) {
        if (++$diffs <= 5) {
            echo "DIFF case {$i}\n  source: " . json_encode($c->source, JSON_UNESCAPED_UNICODE) . "\n";
            if ($ops !== null) {
                echo '  ops:    ' . json_encode($ops, JSON_UNESCAPED_UNICODE) . "\n";
            }
            echo '  js:     ' . canon($c->js) . "\n  php:    " . canon($php) . "\n";
        }
    }
}
echo 'php ' . PHP_VERSION . ': ' . count($cases) . " cases, {$diffs} differences\n";
exit($diffs === 0 ? 0 : 1);
