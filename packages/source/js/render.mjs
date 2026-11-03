// Publishing: page tree -> HTML, theme -> CSS.
// Spec: docs/design/rendering.md. Behaviour must match php/Renderer.php; both are
// checked against ../fixtures/render by their run-fixtures scripts.

import { createSanitizer } from './sanitize-html.mjs';

export class RenderError extends Error {
  constructor(code, key, message) {
    super(`${code} ${key}: ${message}`);
    this.code = code;
    this.key = key;
  }
}

const LENGTH = /^(0|[0-9]+(\.[0-9]+)?(rem|em|px|%))$/;
const BREAKPOINT = /^[0-9]+(\.[0-9]+)?(rem|em|px)$/;
const COLOR = /^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/;
const FONT = /^[A-Za-z0-9 ,"-]{1,200}$/;
const LANG = /^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8})*$/;

function escapeText(s) {
  return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

function escapeAttr(s) {
  return escapeText(s).replace(/"/g, '&quot;');
}

function slug(value) {
  return value.toLowerCase().replace(/[^a-z0-9]+/g, '-');
}

// An empty array counts as an object, because PHP's json_decode cannot tell [] from {}.
const isObject = (v) => v !== null && typeof v === 'object' && (!Array.isArray(v) || v.length === 0);

function checkGroup(theme, group, keys, re) {
  const values = theme[group];
  if (!isObject(values)) throw new RenderError('E_THEME', group, 'オブジェクトで指定してください');
  for (const k of keys) {
    if (!Object.prototype.hasOwnProperty.call(values, k)) throw new RenderError('E_THEME', `${group}.${k}`, '値がありません');
    if (typeof values[k] !== 'string' || !re.test(values[k])) throw new RenderError('E_THEME', `${group}.${k}`, '使えない値です');
  }
  for (const k of Object.keys(values)) {
    if (!keys.includes(k)) throw new RenderError('E_THEME', `${group}.${k}`, '使えない項目です');
  }
}

function checkScalar(theme, key, re) {
  if (typeof theme[key] !== 'string' || !re.test(theme[key])) throw new RenderError('E_THEME', key, '使えない値です');
}

// Throws RenderError on the first problem, in a fixed order shared with PHP.
export function validateTheme(schema, theme) {
  if (!isObject(theme)) throw new RenderError('E_THEME', '', 'テーマはオブジェクトで指定してください');
  checkGroup(theme, 'space', schema.tokens.space, LENGTH);
  checkGroup(theme, 'fontSize', schema.theme.fontSize, LENGTH);
  checkGroup(theme, 'width', schema.theme.width, LENGTH);
  checkScalar(theme, 'radius', LENGTH);
  checkScalar(theme, 'breakpoint', BREAKPOINT);
  checkGroup(theme, 'color', schema.theme.color, COLOR);
  checkGroup(theme, 'font', ['body'], FONT);
  const known = ['space', 'fontSize', 'width', 'radius', 'breakpoint', 'color', 'font'];
  for (const k of Object.keys(theme)) {
    if (!known.includes(k)) throw new RenderError('E_THEME', k, '使えない項目です');
  }
}

export function createRenderer(schema, theme, baseCss) {
  validateTheme(schema, theme);
  const components = new Map(schema.components.map((c) => [c.name, c]));
  const sanitizers = new Map(schema.components.filter((c) => c.kind === 'raw').map((c) => [c.name, createSanitizer(c.sanitize, schema.urlSchemes)]));

  function classesOf(comp, props) {
    const r = comp.render;
    const list = r.class ? [r.class] : [];
    for (const entry of r.classes ?? []) {
      const [attr, prefix = attr] = entry.split(':');
      if (Object.prototype.hasOwnProperty.call(props, attr)) list.push(`wge-${prefix}-${slug(props[attr])}`);
    }
    return list;
  }

  function openTag(comp, node, path) {
    const r = comp.render;
    const tag = r.tag.replace(/\{([a-z]+)\}/g, (_, k) => node.props[k]);
    let s = `<${tag}`;
    if (path) s += ` data-wge-path="${path.join('.')}"`;
    const classes = classesOf(comp, node.props);
    if (classes.length > 0) s += ` class="${classes.join(' ')}"`;
    for (const attr of r.attrs ?? []) {
      if (Object.prototype.hasOwnProperty.call(node.props, attr)) s += ` ${attr}="${escapeAttr(node.props[attr])}"`;
    }
    for (const [k, v] of r.fixed ?? []) s += ` ${k}="${escapeAttr(v)}"`;
    return [tag, s + '>'];
  }

  function inline(children) {
    return children.map((c) => (c.type === 'text' ? escapeText(c.value) : renderNode(c, '', null, false))).join('');
  }

  // Returns null for nodes that are not published (comments).
  // `path` is set only in the editor preview: blocks get data-wge-path, and Html becomes
  // an inert placeholder so its content never runs inside the editor.
  // Otherwise Html content is sanitized unless `trustRaw` says its author may publish raw HTML (§7).
  function renderNode(node, indent, path, trustRaw) {
    if (node.type === 'comment') return null;
    const comp = components.get(node.type);
    if (comp.kind === 'raw') {
      if (path) return `<div data-wge-path="${path.join('.')}" class="wge-edit-raw">Html</div>`;
      return trustRaw ? node.raw : sanitizers.get(node.type)(node.raw);
    }
    const [tag, open] = openTag(comp, node, path);
    if (comp.kind === 'void' || comp.kind === 'inline-void') return open;
    if (comp.kind === 'container') {
      const inner = indent + '  ';
      const kids = node.children.map((c, i) => renderNode(c, inner, path && [...path, i], trustRaw)).filter((s) => s !== null);
      if (kids.length === 0) return `${open}</${tag}>`;
      return open + kids.map((k) => `\n${inner}${k}`).join('') + `\n${indent}</${tag}>`;
    }
    return `${open}${inline(node.children)}</${tag}>`;
  }

  // `annotate` is for the editor preview only; published pages never carry the extra attributes.
  function renderFragment(doc, indent = '', { annotate = false, trustRaw = false } = {}) {
    return doc.children
      .map((c, i) => renderNode(c, indent, annotate ? [i] : null, trustRaw))
      .filter((s) => s !== null)
      .map((s) => `${indent}${s}\n`)
      .join('');
  }

  function renderPage(doc, { title, lang = 'ja', stylesheetHref = 'style.css', trustRaw = false }) {
    if (!LANG.test(lang)) throw new RenderError('E_LANG', 'lang', '使えない言語コードです');
    return [
      '<!doctype html>',
      `<html lang="${lang}">`,
      '<head>',
      '<meta charset="utf-8">',
      '<meta name="viewport" content="width=device-width, initial-scale=1">',
      `<title>${escapeText(title)}</title>`,
      `<link rel="stylesheet" href="${escapeAttr(stylesheetHref)}">`,
      '</head>',
      '<body>',
      '<main class="wge-page">',
      renderFragment(doc, '  ', { trustRaw }) + '</main>',
      '</body>',
      '</html>',
      '',
    ].join('\n');
  }

  function stylesheet() {
    const vars = [];
    for (const k of schema.tokens.space) vars.push(`  --wge-space-${k}: ${theme.space[k]};`);
    for (const k of schema.theme.fontSize) vars.push(`  --wge-font-size-${k}: ${theme.fontSize[k]};`);
    for (const k of schema.theme.width) vars.push(`  --wge-width-${k}: ${theme.width[k]};`);
    vars.push(`  --wge-radius: ${theme.radius};`);
    for (const k of schema.theme.color) vars.push(`  --wge-color-${k}: ${theme.color[k]};`);
    vars.push(`  --wge-font-body: ${theme.font.body};`);
    const tokenRules = [];
    for (const k of schema.tokens.space) tokenRules.push(`.wge-gap-${slug(k)} { gap: var(--wge-space-${k}); }`);
    for (const k of schema.tokens.space) tokenRules.push(`.wge-padding-${slug(k)} { padding-block: var(--wge-space-${k}); }`);
    const base = baseCss.replace(/\r\n/g, '\n').replace(/\n*$/, '\n');
    return [
      '/* WebGenEngine stylesheet. Generated from the theme; edit the theme instead. */',
      ':root {',
      ...vars,
      '}',
      base,
      ...tokenRules,
      '',
      `@media (max-width: ${theme.breakpoint}) {`,
      '  .wge-grid { grid-template-columns: minmax(0, 1fr); }',
      '}',
      '',
    ].join('\n');
  }

  return { renderFragment, renderPage, stylesheet };
}
