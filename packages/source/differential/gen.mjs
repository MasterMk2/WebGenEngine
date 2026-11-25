// Differential test, step 1: generate random sources and edits, record the JS results.
// Usage: node gen.mjs <out.json> [count] [seed]
// Step 2 (check.php) replays the same cases in PHP and reports every difference.
import { readFileSync, readdirSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { createSourceFormat, SourceError, EditError } from '../js/source-format.mjs';
import { createRenderer } from '../js/render.mjs';
import { createSanitizer } from '../js/sanitize-html.mjs';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const schema = JSON.parse(readFileSync(join(root, 'schema.json'), 'utf8'));
const fmt = createSourceFormat(schema);
const renderer = createRenderer(schema, JSON.parse(readFileSync(join(root, 'theme.default.json'), 'utf8')), readFileSync(join(root, 'render', 'base.css'), 'utf8'));
const css = renderer.stylesheet();
const [out, count = '2000', seedArg = '1'] = process.argv.slice(2);

let seed = Number(seedArg) >>> 0;
function rand() {
  seed = (seed + 0x6d2b79f5) >>> 0;
  let t = seed;
  t = Math.imul(t ^ (t >>> 15), t | 1);
  t ^= t + Math.imul(t ^ (t >>> 7), t | 61);
  return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
}
const pick = (a) => a[Math.floor(rand() * a.length)];
const chance = (p) => rand() < p;

const comps = schema.components;
const byKind = (kinds) => comps.filter((c) => kinds.includes(c.kind));
const WORDS = ['ようこそ', '日本語の文', 'Hello', 'world', '𠮷野家', '🍣', '「引用」', 'A&amp;B', '&lt;x&gt;', '&#x1F363;', '&#32;', 'カタカナ', 'ｶﾀｶﾅ', '한국어', 'x'];
const WS = [' ', '  ', '\n', '\n  ', '\n    ', '\t', '\r\n', '\r\n  ', ''];
const BAD_VALUES = ['13px', 'javascript:x', '', '0', '7', 'middle', 'a b', 'DATA:x', 'a\rb', 'a\r\nb'];
// Pieces of Html content, including what the sanitizer has to remove (rendering.md §7).
const RAW = [
  '<b>x</b>', '\n<iframe></iframe>\n', '', '<p title="t">段落</p>', '<br>', '　', 'a < b > c &amp; &nbsp;',
  '<script>alert(1)</script>', '<SCRIPT src=//x>', '</script >', '<style>p{}</style>', '<textarea><b>t</b></textarea>',
  '<img src=x onerror=alert(1)>', '<img/src="/a.png"/onload="x()"/alt=\'a\'>', '<p　onclick=x>全角</p>',
  '<a href="javascript:alert(1)" onclick="x()">j</a>', '<a href="jav&#x09;ascript:x">t</a>', '<a href="&#106;avascript:x">n</a>',
  '<a href=" JaVaScRiPt:x">s</a>', '<a href="https://example.com/?a=1&amp;b=2" title="&quot;q&quot; &copy;">ok</a>',
  '<a href=/x title=t>u</a>', '<img src="data:image/png;base64,AA" alt="&#x1F363;&#0;&#xD800;&#1114112;">',
  '<svg onload=alert(1)><circle/></svg>', '<math><mi>x</mi></math>', '<!-- c -->', '<!-->', '<!DOCTYPE html>', '<?x y?>',
  '<scr<script>ipt>', '<p>未閉じ<b>太字', '</div></main>', '<b>x</i></b>', '<a href="/x"><a href="/y">n</a></a>',
  '<table><tr><td colspan=2 style="x">セル</td></tr></table>', '<P TITLE=\'x"y\' title=z>q</P>', '<img src="/a.png" alt="',
];

function attrValue(attr) {
  if (chance(0.04)) return pick(BAD_VALUES);
  switch (attr.type) {
    case 'space': return pick(schema.tokens.space);
    case 'enum': return pick(attr.values);
    case 'int': return String(attr.min + Math.floor(rand() * (attr.max - attr.min + 1)));
    case 'url': return pick(['/', '/a/b', '#top', 'https://example.com/?a=1&amp;b=2', 'mailto:x@example.com', '../x']);
    // Allowed places, and places an Embed must refuse (another host, a backslash, a lookalike).
    case 'embed': return pick([
      'https://www.google.com/maps/embed?pb=1&amp;z=2', 'https://maps.google.com/maps?q=x&amp;output=embed', '/map/viewer.html?v=1', '/',
      '//evil.example/x', '/\\evil.example', 'https://evil.example/x', 'https://www.google.com/maps/embedded', 'HTTPS://www.google.com/maps/embed?x',
      'map/x.html', 'javascript:x', 'https://www.google.com.evil.example/maps/embed?x',
    ]);
    default: return pick(['', '説明', '"q"'.replace(/"/g, '&quot;'), 'a&amp;b']);
  }
}

function attrs(comp) {
  let s = '';
  const list = comp.attributes.filter((a) => a.required || chance(0.5));
  for (const a of chance(0.3) ? list.reverse() : list) s += pick([' ', '  ', '\n  ']) + `${a.name}="${attrValue(a)}"`;
  return s;
}

function inline(depth, excluded) {
  let s = '';
  const n = Math.floor(rand() * 4);
  for (let i = 0; i < n; i++) {
    if (depth < 3 && chance(0.3)) {
      const comp = pick(byKind(['inline', 'inline-void']).filter((c) => !excluded.includes(c.name)));
      if (comp.kind === 'inline-void') s += `<${comp.name}${attrs(comp)}${pick(['/>', ' />'])}`;
      else s += `<${comp.name}${attrs(comp)}>${inline(depth + 1, excluded.concat(comp.excludes ?? []))}</${comp.name}>`;
    } else {
      s += pick(WORDS) + pick(WS);
    }
  }
  return s;
}

// A table as its rules want it (rows of header cells and cells), with the spacing varied.
function table(depth) {
  const indent = '  '.repeat(depth + 1);
  let s = `<Table${attrs(components.get('Table'))}>`;
  const rows = Math.floor(rand() * 3);
  for (let r = 0; r < rows; r++) {
    s += pick(['\n' + indent, '\n', ' ']) + '<Row>';
    const cells = Math.floor(rand() * 3);
    for (let c = 0; c < cells; c++) {
      const comp = chance(0.4) ? components.get('HeaderCell') : components.get('Cell');
      s += pick(['\n' + indent + '  ', '']) + (chance(0.1) ? `<${comp.name}${attrs(comp)} />` : `<${comp.name}${attrs(comp)}>${inline(0, [])}</${comp.name}>`);
    }
    s += pick(['\n' + indent, '']) + '</Row>';
  }
  return s + pick(['\n' + '  '.repeat(depth), '']) + '</Table>';
}
// A menu as its rules want it (items only, each with its words), with the spacing varied.
function nav(depth) {
  const indent = '  '.repeat(depth + 1);
  const item = components.get('NavLink');
  let s = `<Nav${attrs(components.get('Nav'))}>`;
  const items = Math.floor(rand() * 4);
  for (let i = 0; i < items; i++) {
    s += pick(['\n' + indent, '\n', ' ', '']) + (chance(0.1) ? `<NavLink${attrs(item)} />` : `<NavLink${attrs(item)}>${inline(0, item.excludes ?? [])}</NavLink>`);
  }
  return s + pick(['\n' + '  '.repeat(depth), '']) + '</Nav>';
}
const components = new Map(comps.map((c) => [c.name, c]));

function block(depth) {
  if (chance(0.08)) return `<!--${pick([' メモ ', '', 'a\nb'])}-->`;
  let comp = pick(byKind(depth > 3 ? ['text', 'void', 'raw'] : ['container', 'text', 'void', 'raw']));
  // Parts that stand only in a table appear out of place now and then (to see them refused).
  while (comp.parents && chance(0.85)) comp = pick(byKind(['container', 'text', 'void', 'raw']));
  if (comp.name === 'Table' && chance(0.85)) return table(depth);
  if (comp.name === 'Nav' && chance(0.85)) return nav(depth);
  const open = `<${comp.name}${attrs(comp)}`;
  if (comp.kind === 'void') return open + pick(['/>', ' />']);
  if (comp.kind === 'raw') return `${open}>${pick(RAW)}${chance(0.5) ? pick(RAW) + pick(RAW) : ''}</Html>`;
  if (chance(0.1)) return open + ' />';
  if (comp.kind === 'text') return `${open}>${pick(WS)}${inline(0, comp.excludes ?? [])}</${comp.name}>`;
  const indent = '  '.repeat(depth + 1);
  let s = `${open}>`;
  const n = Math.floor(rand() * 4);
  for (let i = 0; i < n; i++) s += pick(['\n' + indent, '\n', ' ', '\r\n' + indent]) + block(depth + 1);
  return s + pick(['\n' + '  '.repeat(depth), '']) + `</${comp.name}>`;
}

function documentSource() {
  let s = chance(0.05) ? '﻿' : '';
  const n = Math.floor(rand() * 4);
  for (let i = 0; i < n; i++) s += block(0) + pick(['\n', '\n\n', '\r\n', '']);
  return s;
}

function mutate(s) {
  const cps = Array.from(s);
  const k = 1 + Math.floor(rand() * 3);
  for (let i = 0; i < k && cps.length > 0; i++) {
    const at = Math.floor(rand() * cps.length);
    const r = rand();
    if (r < 0.4) cps.splice(at, 1);
    else if (r < 0.8) cps.splice(at, 0, pick(['<', '>', '/', '&', '"', '=', ' ', '\n', '\r', 'a', 'あ', '-', '!']));
    else cps[at] = pick(['<', '>', '"', 'x', '\r']);
  }
  return cps.join('');
}

// Random edits addressed by paths into the parsed tree.
function paths(node, prefix, outList) {
  (node.children ?? []).forEach((c, i) => {
    const p = prefix.concat(i);
    outList.push({ path: p, node: c });
    paths(c, p, outList);
  });
  return outList;
}

function randomNode() {
  return pick([
    { type: 'Text', props: { size: 'lg' }, children: [{ type: 'text', value: '追加 の\n文' }] },
    { type: 'Stack', props: { gap: 'sm' }, children: [{ type: 'Heading', props: { level: '2' }, children: [] }] },
    { type: 'Image', props: { src: '/n.png', alt: '<新>' } },
    { type: 'Embed', props: { src: pick(['/map/viewer.html?v=1', '//evil.example/x', 'https://www.google.com/maps/embed?pb=1']), title: '地図' } },
    { type: 'Row', props: {}, children: [{ type: 'Cell', props: {}, children: [{ type: 'text', value: 'セル' }] }] },
    { type: 'Cell', props: {}, children: [{ type: 'text', value: '新しいセル' }] },
    { type: 'HeaderCell', props: { scope: 'row' }, children: [] },
    { type: 'Table', props: {}, children: [{ type: 'Row', props: {}, children: [] }] },
    { type: 'Nav', props: { 'aria-label': 'メニュー' }, children: [{ type: 'NavLink', props: { href: '/', 'aria-current': 'page' }, children: [{ type: 'text', value: 'トップ' }] }] },
    { type: 'NavLink', props: { href: '/blog/' }, children: [{ type: 'text', value: '<記事>' }] },
    { type: 'comment', value: ' new ' },
    { type: 'comment', value: pick(['-', 'a\rb', 'a\r\nb']) },
    { type: 'Html', props: {}, raw: '<hr>' },
    { type: 'Strong', props: {}, children: [] },
    { type: 'Grid', props: {}, children: [] },
  ]);
}

function randomOps(doc) {
  const ops = [];
  const n = 1 + Math.floor(rand() * 4);
  for (let i = 0; i < n; i++) {
    const all = paths(doc, [], [{ path: [], node: doc }]);
    const t = pick(all);
    const r = rand();
    const len = (t.node.children ?? []).length;
    let op;
    if (r < 0.25) {
      const comp = comps.find((c) => c.name === t.node.type);
      const attr = comp && comp.attributes.length ? pick(comp.attributes) : { name: 'gap', type: 'space' };
      op = { op: 'setProp', path: t.path, name: attr.name, value: chance(0.2) ? null : attrValue(attr) };
    } else if (r < 0.45) {
      op = { op: 'insert', parent: t.path, index: Math.floor(rand() * (len + 2)), node: randomNode() };
    } else if (r < 0.6) {
      op = { op: 'remove', path: t.path };
    } else if (r < 0.75) {
      const to = pick(all);
      op = { op: 'move', from: t.path, parent: to.path, index: Math.floor(rand() * ((to.node.children ?? []).length + 1)) };
    } else if (r < 0.92) {
      op = { op: 'setInline', path: t.path, children: [{ type: 'text', value: pick(WORDS) + pick(WS) }, { type: 'Em', props: {}, children: [{ type: 'text', value: ' e ' }] }, { type: 'text', value: pick(WS) + 'z' }] };
    } else {
      op = { op: 'setRaw', path: t.path, raw: pick(['<p>r</p>', 'a\r\nb', 'x</Html>', 'a\rb', pick(RAW)]) };
    }
    ops.push(op);
    try {
      apply(doc, op);
    } catch (e) {
      if (!(e instanceof EditError)) throw e;
    }
  }
  return ops;
}

function apply(doc, op) {
  switch (op.op) {
    case 'setProp': return fmt.edit.setProp(doc, op.path, op.name, op.value);
    case 'insert': return fmt.edit.insert(doc, op.parent, op.index, op.node);
    case 'remove': return fmt.edit.remove(doc, op.path);
    case 'move': return fmt.edit.move(doc, op.from, op.parent, op.index);
    case 'setInline': return fmt.edit.setInline(doc, op.path, op.children);
    default: return fmt.edit.setRaw(doc, op.path, op.raw);
  }
}

// What both implementations must agree on for one case.
function result(source, ops) {
  let doc;
  try {
    doc = fmt.parse(source);
  } catch (e) {
    if (!(e instanceof SourceError)) throw e;
    return { error: [e.code, e.line, e.column] };
  }
  const res = { tree: fmt.toJSON(doc), canonical: fmt.serializeCanonical(doc), html: renderer.renderFragment(doc) };
  if (ops) {
    res.editErrors = ops.map((op) => {
      try {
        apply(doc, op);
        return null;
      } catch (e) {
        if (!(e instanceof EditError)) throw e;
        return e.code;
      }
    });
    res.output = fmt.serialize(doc);
    res.htmlAfter = renderer.renderFragment(doc);
  }
  return res;
}

// R4 on the published HTML: layout lives only in classes (no style attribute or
// other attributes outside a fixed set), and every class has a CSS rule.
// Raw Html blocks are the author's own markup, so they are left out of the check.
const ALLOWED_ATTRS = new Set(['class', 'href', 'src', 'alt', 'width', 'height', 'loading', 'decoding', 'title', 'scope', 'aria-label', 'aria-current']);
// An <iframe> loads only from the places schema.embedSources lists, and is always closed.
const EMBED_SOURCES = schema.embedSources ?? [];
const decodeAttr = (v) => v.replace(/&quot;/g, '"').replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&amp;/g, '&');
const embedPlaceOk = (src) => !/[\x00-\x20\x7f\\]/.test(src) && EMBED_SOURCES.some((s) => (s === '/' ? src.startsWith('/') && !src.startsWith('//') : src.startsWith(s)));
function htmlProblem(doc) {
  const html = renderer.renderFragment(stripRaw(doc));
  if ((html.match(/<iframe[\s>]/g) ?? []).length !== (html.match(/<\/iframe>/g) ?? []).length) return 'an <iframe> left open';
  for (const tag of html.matchAll(/<([a-z0-9]+)((?:\s[a-z-]+="[^"]*")*)>/g)) {
    for (const [, name, value] of tag[2].matchAll(/\s([a-z-]+)="([^"]*)"/g)) {
      if (!ALLOWED_ATTRS.has(name)) return `attribute ${name} on <${tag[1]}>`;
      if (tag[1] === 'iframe' && name === 'src' && !embedPlaceOk(decodeAttr(value))) return `an <iframe> loads from ${value}`;
      if (name !== 'class') continue;
      for (const cls of value.split(' ')) {
        if (!new RegExp(`\\.${cls}(?![a-z0-9-])`).test(css)) return `class .${cls} has no CSS rule`;
      }
    }
  }
  // Every start tag must have been matched by the pattern above.
  const starts = (html.match(/<[a-z]/g) ?? []).length;
  const matched = [...html.matchAll(/<([a-z0-9]+)((?:\s[a-z-]+="[^"]*")*)>/g)].length;
  if (starts !== matched) return `unexpected tag syntax in ${html}`;
  return null;
}

function stripRaw(node) {
  if (!node.children) return node;
  return { ...node, children: node.children.filter((c) => c.type !== 'Html').map(stripRaw) };
}

// What sanitizing promises for every Html block (rendering.md §7): only allowed elements
// and attributes, URLs that pass the `url` rule, balanced elements, every `<` and `>` part
// of such a tag, and nothing more to change when sanitized again.
const policy = comps.find((c) => c.kind === 'raw').sanitize;
const sanitize = createSanitizer(policy, schema.urlSchemes);
function rawProblem(node) {
  if (node.type === 'Html') {
    const html = sanitize(node.raw);
    const where = `in ${JSON.stringify(html)}`;
    if (sanitize(html) !== html) return `sanitizing again changes the result ${where}`;
    const open = [];
    let tags = 0;
    for (const [, closing, name, list] of html.matchAll(/<(\/?)([a-z0-9]+)((?: [a-z]+="[^"<>]*")*)>/g)) {
      tags++;
      if (!Object.hasOwn(policy.elements, name)) return `element ${name} ${where}`;
      if (closing) {
        if (open.pop() !== name) return `unbalanced </${name}> ${where}`;
        continue;
      }
      for (const [, attr, value] of list.matchAll(/ ([a-z]+)="([^"]*)"/g)) {
        if (!policy.elements[name].includes(attr) && !policy.globalAttributes.includes(attr)) return `attribute ${attr} on ${name} ${where}`;
        if (!policy.urlAttributes.includes(attr)) continue;
        const url = value.replace(/&quot;/g, '"').replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&amp;/g, '&');
        const scheme = /^([A-Za-z][A-Za-z0-9+.-]*):/.exec(url);
        if (url === '' || /[\x00-\x20\x7f]/.test(url) || (scheme && !schema.urlSchemes.includes(scheme[1].toLowerCase()))) return `unsafe ${attr} ${where}`;
      }
      if (!policy.void.includes(name)) open.push(name);
    }
    if (open.length > 0) return `unclosed ${open.join(' ')} ${where}`;
    if ((html.match(/</g) ?? []).length !== tags || (html.match(/>/g) ?? []).length !== tags) return `markup outside allowed tags ${where}`;
    return null;
  }
  for (const c of node.children ?? []) {
    const problem = rawProblem(c);
    if (problem) return problem;
  }
  return null;
}

const fixtureSources = readdirSync(join(root, 'fixtures')).filter((f) => f.endsWith('.json'))
  .flatMap((f) => JSON.parse(readFileSync(join(root, 'fixtures', f), 'utf8')).map((c) => c.source));

// Agreement between JS and PHP says nothing about bugs they share, so check the
// same invariants as the fixture runners on every case. Returns a violation or null.
function invariant(source, ops) {
  let doc;
  try {
    doc = fmt.parse(source);
  } catch (e) {
    if (e instanceof SourceError) return null;
    throw e;
  }
  if (fmt.serialize(doc) !== source) return 'untouched serialize differs from the source';
  const before = htmlProblem(doc) ?? rawProblem(doc);
  if (before) return before;
  const canonical = fmt.serializeCanonical(doc);
  const again = fmt.parse(canonical);
  if (fmt.serializeCanonical(again) !== canonical) return 'canonical is not stable';
  if (JSON.stringify(fmt.toJSON(again)) !== JSON.stringify(fmt.toJSON(doc))) return 'canonical changes the tree';
  for (const op of ops) {
    try {
      apply(doc, op);
    } catch (e) {
      if (!(e instanceof EditError)) throw e;
    }
  }
  const after = htmlProblem(doc) ?? rawProblem(doc);
  if (after) return after;
  const output = fmt.serialize(doc);
  try {
    if (JSON.stringify(fmt.toJSON(fmt.parse(output))) !== JSON.stringify(fmt.toJSON(doc))) return 'edited output reads back as a different tree';
  } catch (e) {
    if (e instanceof SourceError) return `edited output does not parse: ${e.message}`;
    throw e;
  }
  return null;
}

const cases = [];
const violations = [];
for (let i = 0; i < Number(count); i++) {
  let source = chance(0.2) ? pick(fixtureSources) : documentSource();
  if (chance(0.35)) source = mutate(source);
  let ops = null;
  try {
    ops = randomOps(fmt.parse(source));
  } catch (e) {
    if (!(e instanceof SourceError)) throw e;
  }
  cases.push({ source, ops, js: result(source, ops) });
  const v = ops ? invariant(source, ops) : null;
  if (v) violations.push({ i, v, source, ops });
}
writeFileSync(out, JSON.stringify(cases));
const errorsSeen = new Set(cases.filter((c) => c.js.error).map((c) => c.js.error[0]));
const edited = cases.filter((c) => c.ops && c.js.output !== c.source).length;
console.log(`generated ${cases.length} cases (${cases.filter((c) => c.js.error).length} parse errors, ${edited} changed by edits, codes: ${[...errorsSeen].sort().join(' ')})`);
for (const x of violations.slice(0, 3)) console.log(`VIOLATION case ${x.i}: ${x.v}\n  source: ${JSON.stringify(x.source)}\n  ops:    ${JSON.stringify(x.ops)}`);
console.log(`invariants: ${violations.length} violations`);
if (violations.length > 0) process.exitCode = 1;
