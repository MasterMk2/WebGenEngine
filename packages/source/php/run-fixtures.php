<?php
// Runs ../fixtures/*.json against the PHP implementation. Usage: php run-fixtures.php

declare(strict_types=1);

require __DIR__ . '/SourceFormat.php';
require __DIR__ . '/Renderer.php';

use WebGenEngine\Source\EditError;
use WebGenEngine\Source\RenderError;
use WebGenEngine\Source\Renderer;
use WebGenEngine\Source\SourceError;
use WebGenEngine\Source\SourceFormat;

$root = dirname(__DIR__);
$fmt = SourceFormat::fromSchemaFile($root . '/schema.json');

// Key-sorted JSON, so both runners compare trees the same way.
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

// Fixture ops are decoded as objects (to keep {} distinct from []); edits take arrays.
function toArrays(mixed $v): mixed
{
    if ($v instanceof stdClass) {
        $v = get_object_vars($v);
    }
    return is_array($v) ? array_map('toArrays', $v) : $v;
}

function applyOp(SourceFormat $fmt, $doc, array $op): void
{
    match ($op['op']) {
        'setProp' => $fmt->setProp($doc, $op['path'], $op['name'], $op['value']),
        'insert' => $fmt->insert($doc, $op['parent'], $op['index'], $op['node']),
        'remove' => $fmt->remove($doc, $op['path']),
        'move' => $fmt->move($doc, $op['from'], $op['parent'], $op['index']),
        'setInline' => $fmt->setInline($doc, $op['path'], $op['children']),
        'setRaw' => $fmt->setRaw($doc, $op['path'], $op['raw']),
    };
}

function runCase(SourceFormat $fmt, stdClass $c): array
{
    $errors = [];
    $check = static function (bool $ok, string $what, mixed $actual, mixed $expected) use (&$errors): void {
        if (!$ok) {
            $errors[] = $what . "\n      actual:   " . canon($actual) . "\n      expected: " . canon($expected);
        }
    };

    try {
        $doc = $fmt->parse($c->source);
    } catch (SourceError $e) {
        if (isset($c->error)) {
            $got = (object) ['code' => $e->errorCode, 'line' => $e->sourceLine, 'column' => $e->sourceColumn];
            $check(canon($got) === canon($c->error), 'error', $got, $c->error);
        } else {
            $errors[] = 'unexpected error: ' . $e->getMessage();
        }
        return $errors;
    }
    if (isset($c->error)) {
        return ['expected error ' . canon($c->error) . ' but parsed'];
    }

    if (isset($c->tree)) {
        $check(canon($fmt->toJSON($doc)) === canon($c->tree), 'tree', $fmt->toJSON($doc), $c->tree);
    }
    $check($fmt->serialize($doc) === $c->source, 'untouched serialize must return the source', $fmt->serialize($doc), $c->source);
    $canonical = $fmt->serializeCanonical($doc);
    if (isset($c->canonical)) {
        $check($canonical === $c->canonical, 'canonical', $canonical, $c->canonical);
    }
    $again = $fmt->parse($canonical);
    $check($fmt->serializeCanonical($again) === $canonical, 'canonical must be stable', $fmt->serializeCanonical($again), $canonical);
    $check(canon($fmt->toJSON($again)) === canon($fmt->toJSON($doc)), 'canonical must keep the tree', $fmt->toJSON($again), $fmt->toJSON($doc));

    if (isset($c->edits)) {
        foreach ($c->edits as $opObj) {
            $op = toArrays($opObj);
            try {
                applyOp($fmt, $doc, $op);
                if (isset($op['expectError'])) {
                    $errors[] = "op {$op['op']} should fail with {$op['expectError']}";
                }
            } catch (EditError $e) {
                if (($op['expectError'] ?? null) !== $e->errorCode) {
                    $errors[] = "op {$op['op']} failed: " . $e->getMessage();
                }
            }
        }
        $out = $fmt->serialize($doc);
        if (isset($c->output)) {
            $check($out === $c->output, 'output after edits', $out, $c->output);
        }
        // The preserved output must read back as the edited tree.
        $reread = $fmt->parse($out);
        $check(canon($fmt->toJSON($reread)) === canon($fmt->toJSON($doc)), 'output must reparse to the edited tree', $fmt->toJSON($reread), $fmt->toJSON($doc));
        if (isset($c->canonicalAfter)) {
            $check($fmt->serializeCanonical($doc) === $c->canonicalAfter, 'canonical after edits', $fmt->serializeCanonical($doc), $c->canonicalAfter);
        }
    }
    return $errors;
}

// -------------------------------------------------------------- render

$schema = json_decode((string) file_get_contents($root . '/schema.json'), true, 512, JSON_THROW_ON_ERROR);
$defaultTheme = json_decode((string) file_get_contents($root . '/theme.default.json'), true, 512, JSON_THROW_ON_ERROR);
$baseCss = (string) file_get_contents($root . '/render/base.css');

// Deep merge where null deletes a key.
function patch(array $base, array $p): array
{
    foreach ($p as $k => $v) {
        if ($v === null) {
            unset($base[$k]);
        } elseif (is_array($v) && !array_is_list($v) && is_array($base[$k] ?? null)) {
            $base[$k] = patch($base[$k], $v);
        } else {
            $base[$k] = $v;
        }
    }
    return $base;
}

// Every class name the schema can put on an element.
function producibleClasses(array $schema): array
{
    $list = [];
    foreach ($schema['components'] as $comp) {
        if (!isset($comp['render'])) {
            continue;
        }
        if (isset($comp['render']['class'])) {
            $list[] = $comp['render']['class'];
        }
        foreach ($comp['render']['classes'] ?? [] as $entry) {
            $parts = explode(':', $entry);
            $prefix = $parts[1] ?? $parts[0];
            $attr = array_values(array_filter($comp['attributes'], static fn ($a) => $a['name'] === $parts[0]))[0];
            $values = match ($attr['type']) {
                'space' => $schema['tokens']['space'],
                'enum' => $attr['values'],
                default => array_map('strval', range($attr['min'], $attr['max'])),
            };
            foreach ($values as $v) {
                $list[] = 'wge-' . $prefix . '-' . preg_replace('/[^a-z0-9]+/', '-', strtolower($v));
            }
        }
    }
    return $list;
}

function runRenderCase(SourceFormat $fmt, array $schema, array $defaultTheme, string $baseCss, string $root, array $c): array
{
    $errors = [];
    $check = static function (bool $ok, string $what, mixed $actual, mixed $expected) use (&$errors): void {
        if (!$ok) {
            $errors[] = $what . "\n      actual:   " . canon($actual) . "\n      expected: " . canon($expected);
        }
    };
    try {
        $theme = isset($c['themePatch']) ? patch($defaultTheme, $c['themePatch']) : $defaultTheme;
        $renderer = new Renderer($schema, $theme, $baseCss);
        if (isset($c['stylesheet'])) {
            $expected = (string) file_get_contents($root . '/fixtures/render/' . $c['stylesheet']);
            $check($renderer->stylesheet() === $expected, 'stylesheet', $renderer->stylesheet(), $c['stylesheet']);
        }
        if (isset($c['classCoverage'])) {
            $css = $renderer->stylesheet();
            foreach (producibleClasses($schema) as $cls) {
                if (preg_match('/\.' . preg_quote($cls, '/') . '(?![a-z0-9-])/', $css) !== 1) {
                    $errors[] = "no CSS rule for .{$cls}";
                }
            }
        }
        if (array_key_exists('source', $c)) {
            $doc = $fmt->parse($c['source']);
            // `trustRaw` marks a case rendered for an author who may publish raw HTML.
            $trustRaw = ($c['trustRaw'] ?? false) === true;
            if (isset($c['html'])) {
                $html = $renderer->renderFragment($doc, '', $trustRaw);
                $check($html === $c['html'], 'html', $html, $c['html']);
            }
            if (isset($c['page'])) {
                $p = $c['page'];
                $page = $renderer->renderPage($doc, $p['title'], $p['lang'] ?? 'ja', $p['stylesheetHref'] ?? 'style.css', $trustRaw, $p['head'] ?? []);
                if (isset($c['pageHtml'])) {
                    $check($page === $c['pageHtml'], 'page', $page, $c['pageHtml']);
                }
            }
        }
        if (isset($c['renderError'])) {
            $errors[] = 'expected ' . canon($c['renderError']) . ' but rendered';
        }
    } catch (RenderError $e) {
        $got = ['code' => $e->errorCode, 'key' => $e->key];
        if (isset($c['renderError'])) {
            $check(canon($got) === canon($c['renderError']), 'render error', $got, $c['renderError']);
        } else {
            $errors[] = 'unexpected error: ' . $e->getMessage();
        }
    }
    return $errors;
}

$passed = 0;
$failed = 0;
foreach (json_decode((string) file_get_contents($root . '/fixtures/render/cases.json'), true, 512, JSON_THROW_ON_ERROR) as $c) {
    $errors = runRenderCase($fmt, $schema, $defaultTheme, $baseCss, $root, $c);
    if ($errors === []) {
        $passed++;
    } else {
        $failed++;
        echo 'FAIL render/cases.json :: ' . $c['name'] . "\n";
        foreach ($errors as $e) {
            echo "    {$e}\n";
        }
    }
}

$files = glob($root . '/fixtures/*.json');
sort($files);
foreach ($files as $file) {
    foreach (json_decode((string) file_get_contents($file), false, 512, JSON_THROW_ON_ERROR) as $c) {
        try {
            $errors = runCase($fmt, $c);
        } catch (Throwable $e) {
            $errors = ['crashed: ' . get_class($e) . ': ' . $e->getMessage() . ' @' . $e->getLine()];
        }
        if ($errors === []) {
            $passed++;
        } else {
            $failed++;
            echo 'FAIL ' . basename($file) . ' :: ' . $c->name . "\n";
            foreach ($errors as $e) {
                echo "    {$e}\n";
            }
        }
    }
}
echo 'php ' . PHP_VERSION . ": {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
