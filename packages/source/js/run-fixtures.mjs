// Runs ../fixtures/*.json against the JS implementation. Usage: node run-fixtures.mjs
import { readFileSync, readdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { createSourceFormat, SourceError, EditError } from './source-format.mjs';
import { createRenderer, RenderError } from './render.mjs';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const fmt = createSourceFormat(JSON.parse(readFileSync(join(root, 'schema.json'), 'utf8')));

// Key-sorted JSON, so both runners compare trees the same way.
function canon(v) {
  if (Array.isArray(v)) return `[${v.map(canon).join(',')}]`;
  if (v !== null && typeof v === 'object') return `{${Object.keys(v).sort().map((k) => `${JSON.stringify(k)}:${canon(v[k])}`).join(',')}}`;
  return JSON.stringify(v);
}

function applyOp(doc, op) {
  switch (op.op) {
    case 'setProp': return fmt.edit.setProp(doc, op.path, op.name, op.value);
    case 'insert': return fmt.edit.insert(doc, op.parent, op.index, op.node);
    case 'remove': return fmt.edit.remove(doc, op.path);
    case 'move': return fmt.edit.move(doc, op.from, op.parent, op.index);
    case 'setInline': return fmt.edit.setInline(doc, op.path, op.children);
    case 'setRaw': return fmt.edit.setRaw(doc, op.path, op.raw);
    default: throw new Error(`unknown op ${op.op}`);
  }
}

function runCase(c) {
  const errors = [];
  const check = (ok, what, actual, expected) => {
    if (!ok) errors.push(`${what}\n      actual:   ${JSON.stringify(actual)}\n      expected: ${JSON.stringify(expected)}`);
  };

  let doc;
  try {
    doc = fmt.parse(c.source);
  } catch (e) {
    if (!(e instanceof SourceError)) throw e;
    if (c.error) {
      const got = { code: e.code, line: e.line, column: e.column };
      check(canon(got) === canon(c.error), 'error', got, c.error);
    } else {
      errors.push(`unexpected error: ${e.message}`);
    }
    return errors;
  }
  if (c.error) return [`expected error ${canon(c.error)} but parsed`];

  if (c.tree) check(canon(fmt.toJSON(doc)) === canon(c.tree), 'tree', fmt.toJSON(doc), c.tree);
  check(fmt.serialize(doc) === c.source, 'untouched serialize must return the source', fmt.serialize(doc), c.source);
  const canonical = fmt.serializeCanonical(doc);
  if (c.canonical !== undefined) check(canonical === c.canonical, 'canonical', canonical, c.canonical);
  const again = fmt.parse(canonical);
  check(fmt.serializeCanonical(again) === canonical, 'canonical must be stable', fmt.serializeCanonical(again), canonical);
  check(canon(fmt.toJSON(again)) === canon(fmt.toJSON(doc)), 'canonical must keep the tree', fmt.toJSON(again), fmt.toJSON(doc));

  if (c.edits) {
    for (const op of c.edits) {
      try {
        applyOp(doc, op);
        if (op.expectError) errors.push(`op ${op.op} should fail with ${op.expectError}`);
      } catch (e) {
        if (!(e instanceof EditError)) throw e;
        if (op.expectError !== e.code) errors.push(`op ${op.op} failed: ${e.message}`);
      }
    }
    const out = fmt.serialize(doc);
    if (c.output !== undefined) check(out === c.output, 'output after edits', out, c.output);
    // The preserved output must read back as the edited tree.
    const reread = fmt.parse(out);
    check(canon(fmt.toJSON(reread)) === canon(fmt.toJSON(doc)), 'output must reparse to the edited tree', fmt.toJSON(reread), fmt.toJSON(doc));
    if (c.canonicalAfter !== undefined) check(fmt.serializeCanonical(doc) === c.canonicalAfter, 'canonical after edits', fmt.serializeCanonical(doc), c.canonicalAfter);
  }
  return errors;
}

// ---------------------------------------------------------------- render

const schema = JSON.parse(readFileSync(join(root, 'schema.json'), 'utf8'));
const defaultTheme = JSON.parse(readFileSync(join(root, 'theme.default.json'), 'utf8'));
const baseCss = readFileSync(join(root, 'render', 'base.css'), 'utf8');

// Deep merge where null deletes a key.
function patch(base, p) {
  const out = { ...base };
  for (const [k, v] of Object.entries(p)) {
    if (v === null) delete out[k];
    else if (v && typeof v === 'object' && !Array.isArray(v) && out[k] && typeof out[k] === 'object') out[k] = patch(out[k], v);
    else out[k] = v;
  }
  return out;
}

// Every class name the schema can put on an element.
function producibleClasses() {
  const list = [];
  for (const comp of schema.components) {
    if (!comp.render) continue;
    if (comp.render.class) list.push(comp.render.class);
    for (const entry of comp.render.classes ?? []) {
      const [name, prefix = name] = entry.split(':');
      const attr = comp.attributes.find((a) => a.name === name);
      const values = attr.type === 'space' ? schema.tokens.space
        : attr.type === 'enum' ? attr.values
          : Array.from({ length: attr.max - attr.min + 1 }, (_, i) => String(attr.min + i));
      for (const v of values) list.push(`wge-${prefix}-${v.toLowerCase().replace(/[^a-z0-9]+/g, '-')}`);
    }
  }
  return list;
}

function runRenderCase(c) {
  const errors = [];
  const check = (ok, what, actual, expected) => {
    if (!ok) errors.push(`${what}\n      actual:   ${JSON.stringify(actual)}\n      expected: ${JSON.stringify(expected)}`);
  };
  try {
    const renderer = createRenderer(schema, c.themePatch ? patch(defaultTheme, c.themePatch) : defaultTheme, baseCss);
    if (c.stylesheet) check(renderer.stylesheet() === readFileSync(join(root, 'fixtures', 'render', c.stylesheet), 'utf8'), 'stylesheet', renderer.stylesheet(), c.stylesheet);
    if (c.classCoverage) {
      const css = renderer.stylesheet();
      for (const cls of producibleClasses()) {
        if (!new RegExp(`\\.${cls}(?![a-z0-9-])`).test(css)) errors.push(`no CSS rule for .${cls}`);
      }
    }
    if (c.source !== undefined) {
      const doc = fmt.parse(c.source);
      // `trustRaw` marks a case rendered for an author who may publish raw HTML.
      const trustRaw = c.trustRaw === true;
      if (c.html !== undefined) {
        const html = renderer.renderFragment(doc, '', { trustRaw });
        check(html === c.html, 'html', html, c.html);
      }
      if (c.page) {
        const page = renderer.renderPage(doc, { ...c.page, trustRaw });
        if (c.pageHtml !== undefined) check(page === c.pageHtml, 'page', page, c.pageHtml);
      }
    }
    if (c.renderError) errors.push(`expected ${canon(c.renderError)} but rendered`);
  } catch (e) {
    if (!(e instanceof RenderError)) throw e;
    const got = { code: e.code, key: e.key };
    if (c.renderError) check(canon(got) === canon(c.renderError), 'render error', got, c.renderError);
    else errors.push(`unexpected error: ${e.message}`);
  }
  return errors;
}

let passed = 0;
let failed = 0;
for (const c of JSON.parse(readFileSync(join(root, 'fixtures', 'render', 'cases.json'), 'utf8'))) {
  const errors = runRenderCase(c);
  if (errors.length === 0) {
    passed++;
  } else {
    failed++;
    console.log(`FAIL render/cases.json :: ${c.name}`);
    for (const e of errors) console.log(`    ${e}`);
  }
}

for (const file of readdirSync(join(root, 'fixtures')).filter((f) => f.endsWith('.json')).sort()) {
  for (const c of JSON.parse(readFileSync(join(root, 'fixtures', file), 'utf8'))) {
    const errors = runCase(c);
    if (errors.length === 0) {
      passed++;
    } else {
      failed++;
      console.log(`FAIL ${file} :: ${c.name}`);
      for (const e of errors) console.log(`    ${e}`);
    }
  }
}
console.log(`js: ${passed} passed, ${failed} failed`);
process.exit(failed === 0 ? 0 : 1);
