// Editor prototype: preview, property panel and source side by side (R4, R5).
// The source text is the single state. Every GUI action parses it, applies one edit
// operation, saves with the lossless serializer and parses the result again.
// Spec: docs/design/editor.md.
import { createSourceFormat, SourceError, EditError } from '../source/js/source-format.mjs';
import { createRenderer } from '../source/js/render.mjs';
import { slotIndex, slotGuide, nearEdge, moveArgs } from './slots.mjs';
import { inlineFromDom } from './inline-dom.mjs';
import { createPageStore } from './store.mjs';
import { createHttpStore } from './http-store.mjs';
import { addressOf, cleanPath, cleanTitle, embedSrcFrom, fitWithin, FOOTER, HEADER, isPart, PAGE_ID, pageHref, partTemplate, postTemplate } from './posts-core.mjs';

const fetchText = (p) => fetch(p).then((r) => {
  if (!r.ok) throw new Error(`${p}: ${r.status}`);
  return r.text();
});
const [schema, theme, baseCss, sample] = await Promise.all([
  fetchText('../source/schema.json').then(JSON.parse),
  // The site's theme when the server has one (rendering.md §4); serve.mjs has only the default.
  fetchText('../source/theme.json').catch(() => fetchText('../source/theme.default.json')).then(JSON.parse),
  fetchText('../source/render/base.css'),
  fetchText('sample/page.txt'),
]);
const sf = createSourceFormat(schema);
const renderer = createRenderer(schema, theme, baseCss);
const components = new Map(schema.components.map((c) => [c.name, c]));

const NAMES = {
  Section: '区画', Stack: '並び', Grid: '格子', Heading: '見出し', Text: '文章', Button: 'ボタン', Image: '画像', Embed: '地図・埋め込み',
  Table: '表', Row: '行', HeaderCell: '見出しのセル', Cell: 'セル', Html: 'HTML', document: 'ページ',
};
const ATTRS = {
  padding: '上下の余白', background: '背景', width: '中身の幅', direction: '向き', align: '揃え', justify: '並びの配置',
  gap: '間隔', wrap: '折り返し', columns: '列の数', level: '見出しの段階', size: '文字の大きさ', href: 'リンク先',
  variant: '種類', src: '画像の場所', alt: '代わりの文', ratio: '縦横の比', surface: '囲み', scope: '何の見出しか',
};
// Where one name means something else on a component (a picture's own size, not a layout width).
const ATTRS_OF = { Image: { width: '元の幅（px）', height: '元の高さ（px）' }, Embed: { src: '埋め込む場所', title: '中身の説明（読み上げ用）' } };
const VALUES = {
  vertical: '縦', horizontal: '横', start: '先頭側', center: '中央', end: '末尾側', stretch: '伸ばす', between: '両端',
  true: 'する', false: 'しない', none: 'なし', subtle: '薄い色', primary: '主な色', inverse: '反転', secondary: '控えめ',
  narrow: '狭い', normal: '標準', wide: '広い', full: '全幅', auto: '元のまま', sm: '小', md: '中', lg: '大',
  card: 'カード', notice: 'お知らせ枠', row: 'この行の見出し', col: 'この列の見出し',
};
const TEMPLATES = {
  Section: { type: 'Section', props: { padding: 'lg' }, children: [] },
  Stack: { type: 'Stack', props: { gap: 'md' }, children: [] },
  Grid: { type: 'Grid', props: { columns: '2', gap: 'md' }, children: [] },
  Heading: { type: 'Heading', props: { level: '2' }, children: [{ type: 'text', value: '見出し' }] },
  Text: { type: 'Text', props: {}, children: [{ type: 'text', value: '文章を入れてください。' }] },
  Button: { type: 'Button', props: { href: '/' }, children: [{ type: 'text', value: 'ボタン' }] },
  Image: { type: 'Image', props: { src: 'sample/coffee.svg', alt: '' } },
  // Stacks drawn as boxes, offered in the menu under names of their own.
  Card: { type: 'Stack', props: { gap: 'sm', surface: 'card' }, children: [
    { type: 'Heading', props: { level: '3' }, children: [{ type: 'text', value: '見出し' }] },
    { type: 'Text', props: {}, children: [{ type: 'text', value: '文章を入れてください。' }] },
  ] },
  Notice: { type: 'Stack', props: { gap: 'sm', surface: 'notice' }, children: [
    { type: 'Text', props: {}, children: [{ type: 'text', value: 'お知らせの文を入れてください。' }] },
  ] },
};
TEMPLATES.Embed = { type: 'Embed', props: { src: 'https://www.google.com/maps/embed?pb=', title: '地図' } };
// A table starts as two rows of a header cell and a cell (the most common: an outline of facts).
const headed = (head, body) => ({ type: 'Row', props: {}, children: [
  { type: 'HeaderCell', props: { scope: 'row' }, children: [{ type: 'text', value: head }] },
  { type: 'Cell', props: {}, children: [{ type: 'text', value: body }] },
] });
TEMPLATES.Table = { type: 'Table', props: {}, children: [headed('項目', '内容'), headed('項目', '内容')] };
TEMPLATES.Row = headed('項目', '内容'); // replaced by a row shaped like the table's last (rowLike)
TEMPLATES.HeaderCell = { type: 'HeaderCell', props: { scope: 'row' }, children: [{ type: 'text', value: '項目' }] };
TEMPLATES.Cell = { type: 'Cell', props: {}, children: [{ type: 'text', value: '内容' }] };
// The opening of a page (a hero): the page's title and what matters most on the main colour,
// with two buttons. Only parts there already are; the theme's colour does the rest.
TEMPLATES.Hero = { type: 'Section', props: { padding: 'lg', background: 'primary' }, children: [
  { type: 'Stack', props: { gap: 'md' }, children: [
    { type: 'Heading', props: { level: '1' }, children: [{ type: 'text', value: 'ページの題名' }] },
    { type: 'Text', props: {}, children: [{ type: 'text', value: 'いちばん伝えたいこと（日時・場所など）を書きます。' }] },
    { type: 'Stack', props: { direction: 'horizontal', gap: 'sm' }, children: [
      { type: 'Button', props: { href: '/' }, children: [{ type: 'text', value: 'くわしく見る' }] },
      { type: 'Button', props: { href: '/', variant: 'secondary' }, children: [{ type: 'text', value: 'ほかの案内' }] },
    ] },
  ] },
] };
const PRESET_NAMES = { Card: 'カード', Notice: 'お知らせ枠', Hero: 'ページの冒頭' };
const LEGACY_KEY = 'wge-editor-source'; // the single slot the prototype used before the page store
const PREVIEW_CSS = `
[data-wge-path] { cursor: grab; }
.wge-edit-raw { padding: 12px; border: 1px dashed currentColor; font: 12px ui-monospace, monospace; opacity: 0.7; }
.wge-section:empty, .wge-stack:empty, .wge-grid:empty { min-height: 48px; outline: 1px dashed #9aa3ad; outline-offset: -4px; }
.wge-heading, .wge-text, .wge-button { cursor: text; }
[contenteditable] { outline: none; cursor: text; }
.wge-text:empty, .wge-heading:empty { min-height: 1.5em; }
/* An empty row or cell keeps room to click on. */
.wge-table tr:empty { height: 2em; outline: 1px dashed #9aa3ad; outline-offset: -2px; }
.wge-table :is(th, td):empty { min-width: 3em; height: 1.7em; }
/* The site's header and footer around a page (site.md §6): shown as they are published, faded,
   and not chosen by a click (they have no data-wge-path); they are edited on pages of their own. */
[data-wge-part] { position: relative; }
[data-wge-part] > * { opacity: 0.55; }
[data-wge-part]::after { content: attr(data-wge-part); position: absolute; top: 4px; right: 6px; padding: 1px 6px; border-radius: 4px; background: #5f6368; color: #fff; font: 11px/1.6 system-ui, sans-serif; }
/* Room above the first block for the toolbar of the selected block (placeBlockbar): the toolbar
   is about 35px tall and stands 6px off, 4px from the edge. A section at the very top needs it all. */
body { padding-top: 48px; }
`;

const $ = (id) => document.getElementById(id);
const frame = $('preview');
const frameWrap = $('frame-wrap');
const overlay = $('overlay');
const textarea = $('source');
const backdrop = $('backdrop');

const state = {
  source: '',
  doc: null,
  sourceError: null,
  selected: null,
  hover: null,
  insertAt: null, // { parent, index, guide } of the insertion point under the pointer
  editing: null, // { path, el } while a block is edited in place in the preview
  undo: [],
  redo: [],
};

// ------------------------------------------------------------------ helpers

const pathKey = (p) => p.join('.');
const parsePath = (s) => (s === '' ? [] : s.split('.').map(Number));
const samePath = (a, b) => a && b && a.length === b.length && a.every((v, i) => v === b[i]);
const isPrefix = (prefix, p) => prefix.length <= p.length && prefix.every((v, i) => p[i] === v);

function nodeAt(doc, path) {
  let n = doc;
  for (const i of path) {
    if (!n?.children || i >= n.children.length) return null;
    n = n.children[i];
  }
  return n;
}
const kindOf = (node) => (node.type === 'document' ? 'container' : components.get(node.type)?.kind);
const axisOf = (node) => (node.type === 'Grid' || node.type === 'Row' || (node.type === 'Stack' && node.props.direction === 'horizontal') ? 'flow' : 'vertical');
const range = (from, to) => Array.from({ length: Math.max(0, to - from) }, (_, i) => from + i);
// A block's own children in the preview: a table's rows stand in the <tbody> the renderer writes.
const childBlocks = (el) => [...(el.matches('table') && el.tBodies[0] ? el.tBodies[0] : el).children].filter((c) => c.matches('[data-wge-path]'));

// `actions` ({ label, run } or a list of them) adds buttons after the message, such as "元に戻す".
function status(message, tone = '', actions = []) {
  const el = $('status');
  el.textContent = message;
  el.className = `status ${tone}`;
  $('status-actions').replaceChildren(...[actions].flat().map((a) => button(a.label, () => {
    status('');
    a.run();
  })));
}

function describeError(e) {
  if (e instanceof SourceError) return `${e.line} 行 ${e.column} 列: ${e.message.replace(/^\S+ \(\d+:\d+\) /, '')}`;
  if (e instanceof EditError) return e.message.replace(/^E_\w+ /, '');
  return String(e?.message ?? e);
}

// Lines removed and added between two sources, as a line diff would count them,
// for the "1 change = a few lines" check (R4). LCS over the part that differs.
function changedLines(before, after) {
  const a = before.split('\n');
  const b = after.split('\n');
  let p = 0;
  while (p < a.length && p < b.length && a[p] === b[p]) p++;
  let s = 0;
  while (s < a.length - p && s < b.length - p && a[a.length - 1 - s] === b[b.length - 1 - s]) s++;
  const x = a.slice(p, a.length - s);
  const y = b.slice(p, b.length - s);
  let common = 0;
  if (x.length * y.length <= 4_000_000) {
    let prev = new Array(y.length + 1).fill(0);
    for (let i = 1; i <= x.length; i++) {
      const row = new Array(y.length + 1).fill(0);
      for (let j = 1; j <= y.length; j++) row[j] = x[i - 1] === y[j - 1] ? prev[j - 1] + 1 : Math.max(prev[j], row[j - 1]);
      prev = row;
    }
    common = prev[y.length];
  }
  return { first: p + 1, removed: x.length - common, added: y.length - common };
}

function showDiff(before, after) {
  const el = $('diff');
  if (before === after) {
    el.textContent = '';
    return;
  }
  const d = changedLines(before, after);
  el.textContent = `前の操作の差分: −${d.removed} 行 +${d.added} 行（${d.first} 行目から）`;
}

// ------------------------------------------------------------------- state

// Replaces the source. `fromGui` also rewrites the textarea; typing in it does not.
function commit(source, { fromGui = false, select } = {}) {
  const before = state.source;
  if (source === before) return;
  state.undo.push(before);
  state.redo = [];
  setSource(source, { fromGui, select });
  showDiff(before, source);
}

function setSource(source, { fromGui = true, select } = {}) {
  if (fromGui) cancelTyping();
  state.source = source;
  state.doc = sf.parse(source);
  state.sourceError = null;
  if (select !== undefined) state.selected = select;
  if (state.selected && !nodeAt(state.doc, state.selected)) state.selected = null;
  if (fromGui) textarea.value = source;
  if (source !== saving.savedSource) scheduleSave();
  render();
}

// Runs one edit operation on a fresh parse of the current source.
function apply(op, select) {
  // Typing not yet read (within the debounce) goes in first, so the GUI never overwrites it.
  if (textarea.value !== state.source) flushTyping();
  if (state.sourceError) {
    status('ソースにエラーがあります。直してから操作してください', 'error');
    return false;
  }
  try {
    const doc = sf.parse(state.source);
    op(doc);
    const expected = JSON.stringify(sf.toJSON(doc));
    const out = sf.serialize(doc);
    // The saved text must read back as the edited tree; stop loudly if it does not.
    if (JSON.stringify(sf.toJSON(sf.parse(out))) !== expected) throw new Error('保存したソースを読み直すと内容が変わりました（不具合です）');
    commit(out, { fromGui: true, select });
    status('');
    return true;
  } catch (e) {
    status(describeError(e), 'error');
    return false;
  }
}

function undo() {
  if (state.undo.length === 0) return;
  const prev = state.undo.pop();
  state.redo.push(state.source);
  showDiff(state.source, prev);
  setSource(prev);
}

function redo() {
  if (state.redo.length === 0) return;
  const next = state.redo.pop();
  state.undo.push(state.source);
  showDiff(state.source, next);
  setSource(next);
}

// ----------------------------------------------------------------- preview

let previewScroll = 0;

function render() {
  // Rendering replaces the preview document; anything still being typed there is gone.
  state.editing = null;
  overlay.classList.remove('editing');
  frameWrap.classList.toggle('stale', Boolean(state.sourceError));
  previewScroll = frame.contentWindow?.scrollY ?? previewScroll;
  // Inert: an embed or Html in the header or footer is a frame here, never loaded (rendering.md §7.2).
  const part = (tag, cls, doc, label) => (doc ? `<${tag} class="${cls}" data-wge-part="${label}">\n${renderer.renderFragment(doc, '  ', { inert: true })}</${tag}>` : '');
  frame.srcdoc = [
    '<!doctype html><html lang="ja"><head><meta charset="utf-8">',
    `<style>${renderer.stylesheet()}${PREVIEW_CSS}</style></head><body>`,
    part('header', 'wge-site-header', siteParts.header, `ヘッダー（全ページ共通。${siteMode ? 'ページ' : '記事'}の一覧から直します）`),
    '<main class="wge-page" data-wge-path="">',
    renderer.renderFragment(state.doc, '  ', { annotate: true }),
    '</main>',
    part('footer', 'wge-site-footer', siteParts.footer, `フッター（全ページ共通。${siteMode ? 'ページ' : '記事'}の一覧から直します）`),
    '</body></html>',
  ].join('\n');
  renderProps();
  renderBackdrop();
  $('undo').disabled = state.undo.length === 0;
  $('redo').disabled = state.redo.length === 0;
}

// The site's published header and footer, drawn around a page in the preview as every page
// carries them (site.md §5). Read once when the editor opens; none for the parts themselves.
let siteParts = { header: null, footer: null };

async function loadSiteParts() {
  const res = await fetch('../api/pages', { cache: 'no-store' }).then((r) => r.json()).catch(() => null);
  const of = (id) => {
    const source = res?.parts?.find?.((p) => p.id === id)?.source;
    if (typeof source !== 'string') return null;
    try {
      return sf.parse(source);
    } catch {
      return null;
    }
  };
  siteParts = { header: of(HEADER), footer: of(FOOTER) };
  // Never while writing: rendering would drop what is being typed. The next render draws them.
  if ((siteParts.header || siteParts.footer) && !state.editing) render();
}

// Runs once after the next preview load, to continue an action on the new document.
let afterLoad = null;

frame.addEventListener('load', () => {
  frame.contentWindow.scrollTo(0, previewScroll);
  watchPreview(previewDoc());
  drawOverlay();
  const next = afterLoad;
  afterLoad = null;
  next?.();
});

const previewDoc = () => frame.contentDocument;
const elementFor = (path) => previewDoc()?.querySelector(`[data-wge-path="${pathKey(path)}"]`) ?? null;

function addBox(cls, rect, text) {
  const el = document.createElement('div');
  el.className = `box ${cls}`;
  Object.assign(el.style, { left: `${rect.left}px`, top: `${rect.top}px`, width: `${rect.width}px`, height: `${rect.height}px` });
  if (text) el.textContent = text;
  overlay.append(el);
  return el;
}

function addGuide(g, cls = '') {
  const line = document.createElement('div');
  line.className = `guide ${cls}`;
  const vertical = g.x1 === g.x2;
  Object.assign(line.style, vertical
    ? { left: `${g.x1 - 1.5}px`, top: `${g.y1}px`, width: '3px', height: `${g.y2 - g.y1}px` }
    : { left: `${g.x1}px`, top: `${g.y1 - 1.5}px`, width: `${g.x2 - g.x1}px`, height: '3px' });
  overlay.append(line);
}

function drawOverlay(drag = null) {
  overlay.replaceChildren();
  placeBlockbar(drag ? null : state.selected && elementFor(state.selected));
  placeInsertPoint(drag || state.editing ? null : state.insertAt);
  if (drag) {
    if (drag.target) {
      addBox('target', drag.target.rect);
      addGuide(drag.target.guide);
    }
    const ghost = document.createElement('div');
    ghost.className = 'ghost';
    ghost.textContent = drag.target ? `${NAMES[drag.type]} → ${NAMES[drag.target.type]}の ${drag.target.slot + 1} 番目` : `${NAMES[drag.type]}（ここには置けません）`;
    Object.assign(ghost.style, { left: `${drag.point.x}px`, top: `${drag.point.y}px` });
    overlay.append(ghost);
    return;
  }
  if (state.hover && !samePath(state.hover, state.selected)) {
    const el = elementFor(state.hover);
    if (el) addBox('hover', el.getBoundingClientRect());
  }
  if (state.selected) {
    const el = elementFor(state.selected);
    if (el) addBox('selected', el.getBoundingClientRect());
  }
  if (state.insertAt && !state.editing) addGuide(state.insertAt.guide, 'insert');
}

// -------------------------------------------------- pointer: select and drag

function hitPath(point) {
  const el = previewDoc()?.elementFromPoint(point.x, point.y)?.closest('[data-wge-path]');
  return el ? parsePath(el.dataset.wgePath) : null;
}

// The deepest container under the pointer that can take `from`, with the slot to insert at.
function dropTarget(from, point) {
  const stack = previewDoc().elementsFromPoint(point.x, point.y);
  let containerEl = stack.find((el) => {
    if (!el.matches('[data-wge-path]')) return false;
    const path = parsePath(el.dataset.wgePath);
    const node = nodeAt(state.doc, path);
    if (!node || kindOf(node) !== 'container' || isPrefix(from, path)) return false;
    // A dragged block goes only where it may stand (a cell in a row, a row in a table).
    if (from !== NO_SOURCE && !placeable(node.type, nodeAt(state.doc, from)?.type)) return false;
    if (path.length === 0) return true;
    return !nearEdge(axisOf(nodeAt(state.doc, path.slice(0, -1))), el.getBoundingClientRect(), point);
  });
  containerEl ??= previewDoc().querySelector('main');
  const parent = parsePath(containerEl.dataset.wgePath);
  const node = nodeAt(state.doc, parent);
  const kids = childBlocks(containerEl);
  const rects = kids.map((el) => el.getBoundingClientRect());
  const axis = axisOf(node);
  const k = slotIndex(axis, rects, point);
  const index = k < kids.length ? parsePath(kids[k].dataset.wgePath).at(-1) : node.children.length;
  const rect = containerEl.getBoundingClientRect();
  return { parent, index, slot: k, type: node.type, rect, guide: slotGuide(axis, rect, rects, k) };
}

let pointer = null;

// Pointer position in preview coordinates; the overlay covers the iframe exactly.
function overlayPoint(e) {
  const r = overlay.getBoundingClientRect();
  return { x: e.clientX - r.left, y: e.clientY - r.top };
}

overlay.addEventListener('pointerdown', (e) => {
  if (e.button !== 0 || state.sourceError) return;
  const point = overlayPoint(e);
  const path = hitPath(point);
  const node = path && nodeAt(state.doc, path);
  // Text is written where it is clicked (U1, plan A): the caret goes down at once and a
  // drag selects text. Text blocks move only by the handle of the block toolbar.
  if (node && kindOf(node) === 'text') {
    e.preventDefault(); // keeps the focus that startEditing puts in the preview
    select(path, point, { holdOverlay: true });
    if (state.editing) {
      pointer = { textSelect: true, id: e.pointerId };
      overlay.setPointerCapture(e.pointerId);
    }
    return;
  }
  // Dragging inside the selected block moves that block, so a whole card can be
  // picked up after choosing it in the trail; otherwise the innermost block under the pointer moves.
  const drag = state.selected && path && isPrefix(state.selected, path) ? state.selected : path;
  pointer = { start: point, path, drag, dragging: false, id: e.pointerId };
  overlay.setPointerCapture(e.pointerId);
});

overlay.addEventListener('pointermove', (e) => {
  const point = overlayPoint(e);
  if (!pointer) {
    const path = hitPath(point);
    const at = insertPointAt(point);
    if (!samePath(path, state.hover) || !sameInsert(at, state.insertAt)) {
      state.hover = path && path.length > 0 ? path : null;
      state.insertAt = at;
      drawOverlay();
    }
    return;
  }
  if (pointer.textSelect) {
    extendTextSelection(point);
    return;
  }
  if (!previewDoc()?.querySelector('main')) return; // the preview is loading again
  if (!pointer.dragging) {
    const moved = Math.hypot(point.x - pointer.start.x, point.y - pointer.start.y);
    if (moved < 5 || !pointer.drag || pointer.drag.length === 0) return;
    pointer.dragging = true;
    state.selected = pointer.drag;
    renderProps();
    renderBackdrop();
    overlay.classList.add('dragging');
  }
  const type = nodeAt(state.doc, pointer.drag).type;
  pointer.target = dropTarget(pointer.drag, point);
  drawOverlay({ type, point, target: pointer.target });
});

overlay.addEventListener('pointerup', (e) => {
  if (!pointer) return;
  const p = pointer;
  pointer = null;
  overlay.classList.remove('dragging');
  if (p.textSelect) {
    if (state.editing) overlay.classList.add('editing');
    return;
  }
  if (!p.dragging) {
    // A press on the handle that did not move leaves the selection as it is.
    if (!p.fromHandle) select(p.path, p.start);
    drawOverlay();
    return;
  }
  const args = p.target ? moveArgs(p.drag, p.target.parent, p.target.index) : { error: 'ここには置けません' };
  if (args.error) status(args.error, 'error');
  else if (!args.noop) apply((doc) => sf.edit.move(doc, p.drag, args.parent, args.index), [...args.parent, args.index]);
  drawOverlay();
});

overlay.addEventListener('pointercancel', () => {
  // A cancelled text selection still leaves the text being written, so step aside as on pointerup.
  if (pointer?.textSelect && state.editing) overlay.classList.add('editing');
  pointer = null;
  overlay.classList.remove('dragging');
  drawOverlay();
});

overlay.addEventListener('pointerleave', (e) => {
  if (pointer) return;
  // Moving onto the insertion point keeps it; it sits above the overlay.
  if (e.relatedTarget === insertPoint) return;
  state.hover = null;
  state.insertAt = null;
  drawOverlay();
});

// The overlay sits on top of the iframe, so pass wheel scrolling through to it.
overlay.addEventListener('wheel', (e) => {
  e.preventDefault();
  frame.contentWindow.scrollBy(e.deltaX, e.deltaY);
  drawOverlay();
}, { passive: false });

// ------------------------------------------------- editing text in place
// A click on a heading, text or button selects it and puts the caret where it was
// clicked (docs/design/editor-ux.md §3.1). The element becomes contenteditable inside
// the preview; the overlay steps aside so the pointer can select text. Typing stays in
// the preview DOM until editing ends (Esc, Enter, a click elsewhere); then the DOM is
// read back with inlineFromDom and written as one setInline edit, one undo step.

// Selects a block; a text block also starts editing at `point` (preview coordinates).
function select(path, point = null, opts = {}) {
  state.selected = path && path.length > 0 ? path : null;
  renderProps();
  renderBackdrop(true);
  drawOverlay();
  const node = state.selected && nodeAt(state.doc, state.selected);
  if (node && kindOf(node) === 'text') startEditing(state.selected, point, opts);
}

// Selects a block without starting to write in it (the trail, Esc, the source caret).
function selectOnly(path) {
  state.selected = path && path.length > 0 ? path : null;
  renderProps();
  renderBackdrop(true);
  drawOverlay();
}

// While the button is held after a press on text, moving selects text from the caret.
function extendTextSelection(point) {
  const ed = state.editing;
  if (!ed) return;
  const range = caretRange(previewDoc(), point);
  if (range && ed.el.contains(range.startContainer)) previewDoc().getSelection().extend(range.startContainer, range.startOffset);
}

function caretRange(d, { x, y }) {
  if (d.caretPositionFromPoint) {
    const pos = d.caretPositionFromPoint(x, y);
    if (!pos) return null;
    const range = d.createRange();
    range.setStart(pos.offsetNode, pos.offset);
    return range;
  }
  return d.caretRangeFromPoint?.(x, y) ?? null;
}

// `point` places the caret where the pointer was; without it the caret goes to the end,
// to the start when `atStart` (the second half of a paragraph split by Enter), or the
// whole text is selected when `selectAll` (a block just added, so typing replaces it).
// `holdOverlay` keeps the overlay catching the pointer until the button is released.
function startEditing(path, point, { atStart = false, selectAll = false, holdOverlay = false } = {}) {
  const el = elementFor(path);
  if (!el || state.sourceError) return;
  state.editing = { path, el, composing: false };
  state.insertAt = null;
  el.contentEditable = 'true';
  if (!holdOverlay) overlay.classList.add('editing');
  el.focus({ preventScroll: true });
  const d = previewDoc();
  const sel = d.getSelection();
  const range = point && caretRange(d, point);
  if (range && el.contains(range.startContainer)) {
    sel.removeAllRanges();
    sel.addRange(range);
  } else {
    sel.selectAllChildren(el);
    if (atStart) sel.collapseToStart();
    else if (!selectAll) sel.collapseToEnd();
  }
  drawOverlay();
}

function stopEditing() {
  const ed = state.editing;
  closeLinkBox(false);
  state.editing = null;
  overlay.classList.remove('editing');
  ed.el.removeAttribute('contenteditable');
  return ed;
}

// Ends editing and saves what was typed. Returns 'same', 'changed' or 'removed';
// on 'changed' and 'removed' the preview is rendered again.
function finishEditing() {
  if (!state.editing) return 'same';
  const ed = stopEditing();
  const node = nodeAt(state.doc, ed.path);
  const list = inlineFromDom(ed.el);
  if (node.type === 'Text' && list.length === 0) {
    apply((doc) => sf.edit.remove(doc, ed.path), null);
    return 'removed';
  }
  // Compare after the source format's own normalization, so that only real changes
  // rewrite the line (an untouched block keeps its hand-written form).
  try {
    const probe = sf.parse(state.source);
    sf.edit.setInline(probe, ed.path, list);
    if (JSON.stringify(sf.toJSON(nodeAt(probe, ed.path))) === JSON.stringify(sf.toJSON(node))) {
      showSaveState(restingSaveState());
      drawOverlay();
      return 'same';
    }
  } catch (e) {
    status(describeError(e), 'error');
    showSaveState(restingSaveState());
    render();
    return 'changed';
  }
  if (!apply((doc) => sf.edit.setInline(doc, ed.path, list), ed.path)) render();
  return 'changed';
}

// Wraps the selected text in <strong>/<em>, or unwraps it when it is already inside one.
// The browser's own bold command is not used: it writes <b> or <span style> by its own rules.
function toggleWrap(tag) {
  const d = previewDoc();
  const sel = d.getSelection();
  if (!sel.rangeCount) return;
  const range = sel.getRangeAt(0);
  const el = state.editing.el;
  let wrapper = range.commonAncestorContainer;
  if (wrapper.nodeType === 3) wrapper = wrapper.parentNode;
  wrapper = wrapper.closest(tag);
  if (wrapper && el.contains(wrapper) && wrapper !== el) {
    wrapper.replaceWith(...wrapper.childNodes);
    markTyping();
    return;
  }
  if (range.collapsed) return;
  const w = d.createElement(tag);
  w.append(range.extractContents());
  range.insertNode(w);
  sel.selectAllChildren(w);
  markTyping();
}

// ------------------------------------------------------- links in text
// Words chosen while writing become a link (editor-ux.md §3.1, rendering.md §7.5). The box asks
// for the address: typed, one of the site's pages, or a PDF sent from here. The address is
// checked before the <a> goes in, since a refused edit would end the writing and lose it.

const linkBox = $('link-box');
let linkTarget = null;

// Only blocks of words that do not exclude a link (a button is a link already).
function linkAllowedIn(type) {
  const comp = components.get(type);
  return comp?.kind === 'text' && !(comp.excludes ?? []).includes('Link');
}

// The <a> the range is in, or null.
function linkAround(range, el) {
  let n = range.commonAncestorContainer;
  if (n.nodeType === 3) n = n.parentNode;
  const a = n.closest?.('a');
  return a && el.contains(a) ? a : null;
}

// Why `href` cannot be a link in the block at `path`, or null.
function linkProblem(path, href) {
  if (href === '') return 'リンク先を入れてください';
  try {
    const probe = sf.parse(state.source);
    sf.edit.setInline(probe, path, [{ type: 'Link', props: { href }, children: [{ type: 'text', value: 'x' }] }]);
    return null;
  } catch (e) {
    if (e.code === 'E_UNSAFE_URL') return 'この形式のリンクは使えません。使えるのは https:// などで始まるアドレス、/ で始まるこのサイトのページ、mailto:、tel: です';
    if (e.code === 'E_INVALID_VALUE') return 'リンク先に空白は使えません';
    return describeError(e);
  }
}

function openLinkBox() {
  const ed = state.editing;
  if (!ed) {
    status('リンクにする文字を、プレビューで選んでから押してください（Ctrl+K でも付けられます）', 'error');
    return;
  }
  const node = nodeAt(state.doc, ed.path);
  if (!linkAllowedIn(node?.type)) {
    status(node?.type === 'Button' ? 'ボタンの文字の中にはリンクを置けません。ボタンのリンク先は、右の「リンク先」で変えます' : 'この部品の文字にはリンクを付けられません', 'error');
    return;
  }
  const sel = previewDoc().getSelection();
  if (!sel.rangeCount || !ed.el.contains(sel.getRangeAt(0).commonAncestorContainer)) return;
  const range = sel.getRangeAt(0).cloneRange();
  const anchor = linkAround(range, ed.el);
  if (!anchor) {
    if (range.collapsed) {
      status('リンクにする文字を選んでから押してください', 'error');
      return;
    }
    if (range.cloneContents().querySelector('a')) {
      status('選んだ文字に、もうリンクが入っています。そのリンクの中を選ぶと、変えたり外したりできます', 'error');
      return;
    }
  }
  linkTarget = { range, anchor, path: ed.path };
  fillLinkBox(anchor?.getAttribute('href') ?? '');
  linkBox.hidden = false;
  // Under the chosen words, or above them when there is no room below.
  const wrap = frameWrap.getBoundingClientRect();
  const f = $('preview').getBoundingClientRect();
  const r = (anchor ?? range).getBoundingClientRect();
  const w = linkBox.offsetWidth;
  const h = linkBox.offsetHeight;
  let top = f.top - wrap.top + r.bottom + 6;
  if (top + h > wrap.height && f.top - wrap.top + r.top - h - 6 >= 0) top = f.top - wrap.top + r.top - h - 6;
  const left = Math.max(4, Math.min(f.left - wrap.left + r.left, wrap.width - w - 4));
  Object.assign(linkBox.style, { left: `${left}px`, top: `${Math.max(4, top)}px` });
  const input = $('link-href');
  input.focus();
  input.select();
}

// `restore` puts the caret back on the chosen words, as when the box is left with Esc.
function closeLinkBox(restore) {
  if (linkBox.hidden) return;
  linkBox.hidden = true;
  const target = linkTarget;
  linkTarget = null;
  const ed = state.editing;
  if (restore && ed && target) {
    ed.el.focus({ preventScroll: true });
    const sel = previewDoc().getSelection();
    sel.removeAllRanges();
    sel.addRange(target.range);
  }
}

function fillLinkBox(href) {
  const existing = Boolean(linkTarget.anchor);
  const label = document.createElement('label');
  label.htmlFor = 'link-href';
  label.textContent = 'リンク先';
  const input = document.createElement('input');
  Object.assign(input, { id: 'link-href', type: 'text', value: href, autocomplete: 'off', spellcheck: false });
  input.setAttribute('inputmode', 'url');
  const hint = document.createElement('p');
  hint.className = 'note';
  hint.textContent = siteMode ? 'https:// で始まるアドレスか、このサイトのページ（入れると候補が出ます）。メールは mailto:、電話は tel: で始めます' : 'https:// で始まるアドレス。メールは mailto:、電話は tel: で始めます';
  const problem = document.createElement('p');
  problem.className = 'problem';
  problem.setAttribute('role', 'alert');
  problem.hidden = true;
  const actions = document.createElement('div');
  actions.className = 'link-actions';
  const ok = button(existing ? 'リンク先を変える' : 'リンクにする', () => applyLink(input.value.trim(), problem), 'primary');
  actions.append(ok);
  if (serverMode) {
    const file = document.createElement('input');
    Object.assign(file, { type: 'file', accept: 'application/pdf,.pdf', hidden: true });
    file.addEventListener('change', async () => {
      const chosen = file.files?.[0];
      file.value = '';
      if (!chosen) return;
      const res = await sendPdf(chosen);
      if (!res) return;
      input.value = res.src;
      status('PDF を送りました。「' + ok.textContent + '」で付けます', 'ok');
      input.focus();
    });
    actions.append(button('PDF を選ぶ…', () => file.click()), file);
  }
  if (existing) actions.append(button('リンクを外す', removeLink));
  actions.append(button('やめる', () => closeLinkBox(true)));
  linkBox.replaceChildren(label, input, hint, problem, actions);
  input.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && !e.isComposing && e.keyCode !== 229) {
      e.preventDefault();
      applyLink(input.value.trim(), problem);
    }
  });
  if (siteMode) fillPageChoices(input);
}

// The site's pages as choices for the address (the header and footer are not pages to open).
async function fillPageChoices(input) {
  const res = await fetch('../api/pages', { cache: 'no-store' }).then((r) => r.json()).catch(() => null);
  if (!res?.ok || !Array.isArray(res.pages) || linkBox.hidden) return;
  const list = document.createElement('datalist');
  list.id = 'link-pages';
  for (const p of res.pages) {
    if (isPart(p.id) || typeof p.path !== 'string') continue;
    const o = document.createElement('option');
    o.value = pageHref(p.path);
    o.label = p.title || p.path;
    list.append(o);
  }
  linkBox.append(list);
  input.setAttribute('list', 'link-pages');
}

function applyLink(href, problem) {
  const ed = state.editing;
  const target = linkTarget;
  if (!ed || !target || JSON.stringify(ed.path) !== JSON.stringify(target.path)) {
    closeLinkBox(false);
    return;
  }
  const why = linkProblem(ed.path, href);
  if (why) {
    problem.textContent = why;
    problem.hidden = false;
    $('link-href').focus();
    return;
  }
  const d = previewDoc();
  let a = target.anchor;
  if (a) {
    a.setAttribute('href', href);
  } else {
    a = d.createElement('a');
    a.setAttribute('href', href);
    a.append(target.range.extractContents());
    target.range.insertNode(a);
  }
  markTyping();
  // The caret goes after the link, so typing goes on outside it.
  const after = d.createRange();
  after.setStartAfter(a);
  after.collapse(true);
  target.range = after;
  closeLinkBox(true);
  status(target.anchor ? 'リンク先を変えました' : 'リンクを付けました', 'ok');
}

function removeLink() {
  const target = linkTarget;
  if (!state.editing || !target?.anchor) return;
  const d = previewDoc();
  const range = d.createRange();
  range.setStartBefore(target.anchor);
  range.setEndAfter(target.anchor);
  const kids = [...target.anchor.childNodes];
  target.anchor.replaceWith(...kids);
  if (kids.length > 0) {
    range.setStartBefore(kids[0]);
    range.setEndAfter(kids.at(-1));
  }
  target.range = range;
  markTyping();
  closeLinkBox(true);
  status('リンクを外しました', 'ok');
}

linkBox.addEventListener('keydown', (e) => {
  // Keys typed here are the box's: the page's shortcuts (Delete removes a block) stay out.
  e.stopPropagation();
  if (e.key === 'Escape') {
    e.preventDefault();
    closeLinkBox(true);
  }
});

// Enter in a paragraph splits it at the caret, as in a document (editor-ux.md §4.2):
// the text after the caret moves into a new paragraph, written as one edit.
function splitParagraph() {
  const d = previewDoc();
  const { el } = state.editing;
  const sel = d.getSelection();
  if (!sel.rangeCount) return;
  const range = sel.getRangeAt(0);
  range.deleteContents();
  const tail = d.createRange();
  tail.setStart(range.endContainer, range.endOffset);
  tail.setEnd(el, el.childNodes.length);
  const holder = d.createElement('p');
  holder.append(tail.extractContents());
  const after = inlineFromDom(holder);
  const before = inlineFromDom(el);
  if (before.length === 0 && after.length === 0) {
    finishEditing(); // Enter on an empty paragraph just leaves it (and removes it)
    return;
  }
  const { path } = stopEditing();
  const parent = path.slice(0, -1);
  const next = [...parent, path.at(-1) + 1];
  // The new paragraph keeps the size and alignment of the one it was split from.
  const props = { ...nodeAt(state.doc, path).props };
  const ok = apply((doc) => {
    sf.edit.setInline(doc, path, before);
    sf.edit.insert(doc, parent, next.at(-1), { type: 'Text', props, children: after });
  }, next);
  if (ok) afterLoad = () => startEditing(next, null, { atStart: true });
  else render();
}

function onEditKey(e) {
  const ed = state.editing;
  // Focus stays in the preview after editing ends; its keys are the editor's shortcuts then.
  if (!ed) {
    onKey(e);
    return;
  }
  // While the IME is composing, Enter and Esc belong to the IME (R2).
  if (e.isComposing || ed.composing || e.keyCode === 229) return;
  const mod = e.ctrlKey || e.metaKey;
  if (e.key === 'Escape') {
    e.preventDefault();
    finishEditing();
    frame.blur();
    window.focus();
  } else if (e.key === 'Enter' && !e.shiftKey) {
    e.preventDefault();
    if (nodeAt(state.doc, ed.path).type === 'Text') splitParagraph();
    else finishEditing();
  } else if (mod && (e.key.toLowerCase() === 'z' || e.key.toLowerCase() === 'y')) {
    // The browser's own undo does not know about toggleWrap's changes, so Ctrl+Z and
    // Ctrl+Y end editing and use the editor's history: one editing session is one step.
    e.preventDefault();
    finishEditing();
    if (e.key.toLowerCase() === 'y' || e.shiftKey) redo();
    else undo();
  } else if (mod && (e.key.toLowerCase() === 'b' || e.key.toLowerCase() === 'i')) {
    e.preventDefault();
    toggleWrap(e.key.toLowerCase() === 'b' ? 'strong' : 'em');
  } else if (mod && e.key.toLowerCase() === 'k') {
    e.preventDefault();
    openLinkBox();
  } else if (mod && (e.key.toLowerCase() === 'd' || e.key === '/')) {
    // Duplicate and the source toggle act on the block, so they end editing first.
    e.preventDefault();
    finishEditing();
    onKey(e);
  }
}

function watchPreview(d) {
  d.addEventListener('keydown', onEditKey);
  d.addEventListener('compositionstart', () => { if (state.editing) state.editing.composing = true; });
  d.addEventListener('compositionend', () => { if (state.editing) state.editing.composing = false; });
  d.addEventListener('input', markTyping);
  d.addEventListener('beforeinput', (e) => {
    // Formatting, paragraphs and undo are handled above. Dragging text would delete it
    // here and drop foreign markup there, so drags inside the preview are refused.
    if (/^format|^history|^insertParagraph$|^insertFromDrop$|^deleteByDrag$/.test(e.inputType)) e.preventDefault();
  });
  d.addEventListener('paste', (e) => {
    if (!state.editing) return;
    e.preventDefault();
    const text = e.clipboardData.getData('text/plain').replace(/\r?\n/g, ' ');
    d.execCommand('insertText', false, text);
  });
  // The overlay lets the pointer through only while editing, so this sees clicks then.
  d.addEventListener('pointerdown', (e) => {
    // A click in the preview chooses other words or another block: the link box was for the old ones.
    closeLinkBox(false);
    const ed = state.editing;
    if (!ed || ed.el.contains(e.target)) return;
    e.preventDefault();
    const hit = e.target.closest?.('[data-wge-path]');
    const path = hit ? parsePath(hit.dataset.wgePath) : null;
    const point = { x: e.clientX, y: e.clientY };
    const result = finishEditing();
    if (result === 'same') select(path, point);
    else if (result === 'changed') afterLoad = () => select(path, point);
    else afterLoad = () => select(null);
  }, true);
  d.defaultView.addEventListener('scroll', () => drawOverlay());
}

// Clicks on the editor's own controls end editing first, so they act on saved text.
document.addEventListener('pointerdown', (e) => {
  const keeps = e.target.closest?.('#link-box, [data-keeps-editing]');
  if (!keeps) closeLinkBox(false);
  if (state.editing && e.target !== overlay && !keeps) finishEditing();
}, true);
// Typing not yet saved would be lost on reload or close. The store writes synchronously
// before its first await, so the save completes while the page is still alive.
window.addEventListener('pagehide', () => {
  finishEditing();
  saveNow();
});

// ------------------------------------------------------- block toolbar
// Shown next to the selected block (editor-ux.md §3.1): the handle that drags it, the trail
// of its parents, moving, adding after it, duplicating and removing. Built only when the
// selection changes; drawOverlay only moves it, so a pressed button is never replaced between
// pointerdown and click. Buttons read state.selected when clicked: a click while writing
// saves the text first, which can change the block or remove it.

const blockbar = $('blockbar');
const insertPoint = $('insert-point');
const insertMenu = $('insert-menu');
let blockbarKey = null;

const trailTypes = (path) => path.map((_, i) => nodeAt(state.doc, path.slice(0, i + 1))?.type);

function renderBlockbar() {
  const path = state.selected;
  const key = path ? JSON.stringify([path, trailTypes(path)]) : null;
  if (key === blockbarKey) return;
  blockbarKey = key;
  blockbar.replaceChildren();
  if (!path) return;
  const handle = button('⠿', null, 'handle');
  handle.title = 'ドラッグで動かす（Alt+↑↓ でも動かせます）';
  handle.setAttribute('aria-label', '動かす');
  handle.addEventListener('pointerdown', startHandleDrag);

  // The trail replaces "select the parent": each step selects that block. Long trails
  // keep the first step and the last two, and the table a cell or a row is in (for its buttons).
  const crumbs = document.createElement('span');
  crumbs.className = 'crumbs';
  const types = trailTypes(path);
  const table = types.lastIndexOf('Table');
  const tail = table > 0 && table < types.length - 2 ? [table, ...range(table + 1, types.length)] : [types.length - 2, types.length - 1];
  const shown = types.length > 4 ? [0, ...(tail[0] > 1 ? [null] : []), ...tail] : types.map((_, i) => i);
  shown.forEach((i, n) => {
    if (n > 0) {
      const sep = document.createElement('span');
      sep.textContent = i === null ? '…' : '›';
      crumbs.append(sep);
    }
    if (i === null) return;
    const depth = i + 1;
    const name = NAMES[types[i]] ?? types[i];
    const b = button(name, () => {
      if (state.selected && depth < state.selected.length) selectOnly(state.selected.slice(0, depth));
    });
    if (depth === path.length) b.setAttribute('aria-current', 'true');
    else b.title = `外側の${name}を選ぶ`;
    crumbs.append(b);
  });

  const sep = () => Object.assign(document.createElement('span'), { className: 'sep' });
  // A link goes on words chosen while writing, so pressing this keeps the writing (and the
  // focus, by refusing mousedown's default) instead of ending it as other buttons do.
  const node = nodeAt(state.doc, path);
  const link = linkAllowedIn(node?.type) ? button('リンク', () => openLinkBox()) : null;
  if (link) {
    link.title = '選んだ文字をリンクにする (Ctrl+K)';
    link.dataset.keepsEditing = '';
    link.addEventListener('mousedown', (e) => e.preventDefault());
  }
  const up = button('↑', () => moveBy(-1));
  up.title = '前へ (Alt+↑)';
  up.setAttribute('aria-label', '前へ');
  const down = button('↓', () => moveBy(1));
  down.title = '後ろへ (Alt+↓)';
  down.setAttribute('aria-label', '後ろへ');
  const add = button('＋', (e) => {
    const sel = state.selected;
    if (sel) openInsertMenu({ parent: sel.slice(0, -1), index: sel.at(-1) + 1 }, e.currentTarget);
  });
  add.title = 'この後ろに部品を足す';
  add.setAttribute('aria-label', 'この後ろに部品を足す');
  const dup = button('複製', duplicateSelected);
  dup.title = '複製 (Ctrl+D)';
  const del = button('削除', removeSelected, 'danger');
  del.title = '削除 (Delete)';
  blockbar.append(handle, crumbs, ...(link ? [sep(), link] : []), sep(), up, down, sep(), add, dup, del);
}

// Above the block when there is room, else below it, so the toolbar never covers what is selected
// (a title being typed, at the top of the page). Only a block taller than the view, with no room
// above nor below, gets it just inside its top edge. A row or a cell is short and has neighbours
// right above it, so its toolbar goes by the whole table instead and never covers another cell.
function placeBlockbar(el) {
  if (!el) {
    blockbar.hidden = true;
    return;
  }
  const r = (el.closest('table') ?? el).getBoundingClientRect();
  const height = overlay.clientHeight;
  if (r.bottom < 0 || r.top > height) {
    blockbar.hidden = true;
    return;
  }
  blockbar.hidden = false;
  const h = blockbar.offsetHeight;
  let top = r.top - h - 6;
  if (top < 4) {
    const below = r.bottom + 6;
    top = below + h <= height - 4 ? below : Math.max(4, r.top + 4);
  }
  top = Math.min(top, height - h - 4);
  const left = Math.max(4, Math.min(r.left, overlay.clientWidth - blockbar.offsetWidth - 4));
  Object.assign(blockbar.style, { left: `${left}px`, top: `${top}px` });
}

function startHandleDrag(e) {
  if (e.button !== 0 || !state.selected || state.sourceError) return;
  e.preventDefault();
  pointer = { start: overlayPoint(e), path: state.selected, drag: state.selected, dragging: false, fromHandle: true, id: e.pointerId };
  overlay.setPointerCapture(e.pointerId);
}

function duplicateSelected() {
  const path = state.selected;
  if (!path) return;
  const parent = path.slice(0, -1);
  const index = path.at(-1) + 1;
  apply((doc) => sf.edit.insert(doc, parent, index, sf.toJSON(nodeAt(doc, path))), [...parent, index]);
}

function removeSelected() {
  const path = state.selected;
  if (!path) return;
  const name = NAMES[nodeAt(state.doc, path).type];
  if (apply((doc) => sf.edit.remove(doc, path), null)) status(`${name}を削除しました`, '', { label: '元に戻す', run: undo });
}

// -------------------------------------------------- insertion points
// Hovering near the gap between two blocks shows a line and a "＋" there (editor-ux.md
// §4.2), so where a new block goes is visible before pressing. The gap is found the same
// way as a drop target, so adding and dragging agree on the slots.

const NO_SOURCE = [-1]; // dropTarget's `from`, for a block that is not in the tree yet
const sameInsert = (a, b) => (a && b ? samePath(a.parent, b.parent) && a.index === b.index : a === b);

function insertPointAt(point) {
  if (state.editing || state.sourceError || !insertMenu.hidden || !previewDoc()?.querySelector('main')) return null;
  const t = dropTarget(NO_SOURCE, point);
  const g = t.guide;
  const empty = !nodeAt(state.doc, t.parent).children.some((c) => c.type !== 'comment');
  if (!empty && (g.x1 === g.x2 ? Math.abs(point.x - g.x1) : Math.abs(point.y - g.y1)) > 12) return null;
  return { parent: t.parent, index: t.index, guide: g };
}

function placeInsertPoint(at) {
  insertPoint.hidden = !at;
  if (!at) return;
  const g = at.guide;
  Object.assign(insertPoint.style, { left: `${(g.x1 + g.x2) / 2}px`, top: `${(g.y1 + g.y2) / 2}px` });
}

insertPoint.addEventListener('click', () => {
  if (state.insertAt) openInsertMenu(state.insertAt, insertPoint);
});
insertPoint.addEventListener('pointerleave', (e) => {
  if (e.relatedTarget === overlay) return;
  state.insertAt = null;
  drawOverlay();
});

// The menu lists the everyday blocks first; the boxes for layout come after a rule.
// The parts of a table come last: in a table only a row fits, in a row only cells (the schema's rules).
const MENU = [['Heading', 'Text', 'Image', 'Button'], ['Card', 'Notice', 'Embed', 'Table'], ['Hero', 'Section', 'Stack', 'Grid'], ['Row', 'HeaderCell', 'Cell']];
let menuTarget = null;

// Only what may stand in the target is offered (source-format.md §4.1).
function fillInsertMenu(parentType) {
  insertMenu.replaceChildren();
  const groups = MENU.map((names) => names.filter((name) => placeable(parentType, TEMPLATES[name].type))).filter((g) => g.length > 0);
  groups.forEach((names, i) => {
    if (i > 0) insertMenu.append(document.createElement('hr'));
    for (const name of names) {
      const b = button(PRESET_NAMES[name] ?? NAMES[name], () => insertBlock(name));
      b.setAttribute('role', 'menuitem');
      insertMenu.append(b);
    }
  });
}

function openInsertMenu(target, anchor) {
  menuTarget = { parent: target.parent, index: target.index };
  state.insertAt = null;
  fillInsertMenu(nodeAt(state.doc, target.parent).type);
  insertMenu.hidden = false;
  const wrap = frameWrap.getBoundingClientRect();
  const a = anchor.getBoundingClientRect();
  const w = insertMenu.offsetWidth;
  const h = insertMenu.offsetHeight;
  let top = a.bottom - wrap.top + 4;
  if (top + h > wrap.height && a.top - wrap.top - h - 4 >= 0) top = a.top - wrap.top - h - 4;
  const left = Math.max(4, Math.min(a.left - wrap.left, wrap.width - w - 4));
  Object.assign(insertMenu.style, { left: `${left}px`, top: `${top}px` });
  insertMenu.querySelector('button').focus();
  drawOverlay();
}

function closeInsertMenu() {
  if (insertMenu.hidden) return;
  insertMenu.hidden = true;
  menuTarget = null;
}

function insertBlock(name) {
  const target = menuTarget;
  closeInsertMenu();
  if (!target) return;
  const path = [...target.parent, target.index];
  // A new row takes the shape of the table's last one.
  const node = name === 'Row' ? rowLike(nodeAt(state.doc, target.parent)) : structuredClone(TEMPLATES[name]);
  if (!apply((doc) => sf.edit.insert(doc, target.parent, target.index, node), path)) return;
  if (name === 'Hero' && countTitles(state.doc) > 1) status('ページの冒頭を足しました。ページの題名（見出し 1）が 2 つになったので、前からある方を消すか、見出し 2 にしてください', 'ok');
  // A new heading or paragraph starts with its placeholder selected, so typing replaces it.
  if (components.get(node.type).kind === 'text') afterLoad = () => startEditing(path, null, { selectAll: true });
  else if (node.type === 'Row' && node.children.length > 0) afterLoad = () => startEditing([...path, 0], null, { selectAll: true });
}

// Level-1 headings in the page: a page has one title.
function countTitles(node) {
  const own = node.type === 'Heading' && node.props?.level === '1' ? 1 : 0;
  return own + (node.children ?? []).reduce((n, c) => n + (c.children ? countTitles(c) : 0), 0);
}

// ------------------------------------------------------------------ tables

// Whether a block of `type` may stand in `parentType`: the schema's "children" and "parents".
function placeable(parentType, type) {
  const parent = components.get(parentType);
  const comp = components.get(type);
  if (!comp) return true;
  if (parent?.children && !parent.children.includes(type)) return false;
  return !comp.parents || comp.parents.includes(parentType);
}

const cellLike = (cell, i) => (cell?.type === 'HeaderCell'
  ? { type: 'HeaderCell', props: { ...cell.props }, children: [{ type: 'text', value: cell.props.scope === 'col' ? '見出し' : '項目' }] }
  : { type: 'Cell', props: {}, children: [{ type: 'text', value: i === 0 ? '項目' : '内容' }] });

// A new row shaped like the table's last row (its header cells stay header cells).
function rowLike(table) {
  const last = [...(table.children ?? [])].reverse().find((c) => c.type === 'Row');
  const cells = last?.children.filter((c) => c.type !== 'comment') ?? [];
  return { type: 'Row', props: {}, children: (cells.length > 0 ? cells : [null, null]).map((c, i) => cellLike(c?.type === 'HeaderCell' && c.props.scope === 'col' ? { ...c, type: 'Cell' } : c, i)) };
}

// Adds a row at the end, adds a cell to the end of every row, or takes the last cell off every row.
function tableEdit(path, what) {
  const table = nodeAt(state.doc, path);
  const rows = table.children.map((c, i) => [c, i]).filter(([c]) => c.type === 'Row');
  apply((doc) => {
    if (what === 'row') sf.edit.insert(doc, path, table.children.length, rowLike(table));
    for (const [row, i] of what === 'row' ? [] : rows) {
      const cells = row.children.filter((c) => c.type !== 'comment');
      if (what === 'column') {
        const allHeads = cells.length > 0 && cells.every((c) => c.type === 'HeaderCell' && c.props.scope === 'col');
        sf.edit.insert(doc, [...path, i], row.children.length, allHeads ? cellLike(cells[0], 1) : cellLike(null, 1));
      } else if (cells.length > 1) {
        sf.edit.remove(doc, [...path, i, row.children.lastIndexOf(cells.at(-1))]);
      }
    }
  }, path);
}

insertMenu.addEventListener('keydown', (e) => {
  const items = [...insertMenu.querySelectorAll('button')];
  const i = items.indexOf(document.activeElement);
  if (e.key === 'Escape') {
    e.preventDefault();
    e.stopPropagation();
    closeInsertMenu();
  } else if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
    e.preventDefault();
    items[(i + (e.key === 'ArrowDown' ? 1 : items.length - 1)) % items.length].focus();
  }
});
document.addEventListener('pointerdown', (e) => {
  if (!insertMenu.hidden && !insertMenu.contains(e.target)) closeInsertMenu();
}, true);

// ---------------------------------------------------------- property panel

function field(labelText, attrName, control) {
  const row = document.createElement('div');
  row.className = 'field';
  const label = document.createElement('label');
  const id = `f-${attrName}`;
  label.htmlFor = id;
  label.textContent = `${labelText} `;
  if (sourceShown()) {
    const code = document.createElement('code');
    code.textContent = attrName;
    label.append(code);
  }
  control.id = id;
  row.append(label, control);
  return row;
}

function attrControl(attr, value, onChange) {
  let options = null;
  if (attr.type === 'enum') options = attr.values;
  else if (attr.type === 'space') options = schema.tokens.space;
  // A short range is a list to pick from; a long one (a picture's size) is typed.
  else if (attr.type === 'int' && attr.max - attr.min < 24) options = Array.from({ length: attr.max - attr.min + 1 }, (_, i) => String(attr.min + i));
  if (attr.type === 'int' && !options) {
    const input = document.createElement('input');
    Object.assign(input, { type: 'number', min: String(attr.min), max: String(attr.max), step: '1', value: value ?? '' });
    input.inputMode = 'numeric';
    input.addEventListener('change', () => onChange(input.value === '' && !attr.required ? null : input.value));
    return input;
  }
  if (options) {
    const select = document.createElement('select');
    if (!attr.required) select.add(new Option('（指定なし）', ''));
    for (const v of options) {
      const label = VALUES[v] && attr.type !== 'space' ? VALUES[v] : v;
      select.add(new Option(sourceShown() && label !== v ? `${label} (${v})` : label, v));
    }
    select.value = value ?? '';
    select.addEventListener('change', () => onChange(select.value === '' ? null : select.value));
    return select;
  }
  const input = document.createElement('input');
  input.type = 'text';
  input.value = value ?? '';
  // An Embed's place may be pasted as the whole <iframe> a map service gives; its src is taken.
  const read = () => (attr.type === 'embed' ? embedSrcFrom(input.value) : input.value);
  input.addEventListener('change', () => onChange(read() === '' && !attr.required ? null : read()));
  return input;
}

function button(text, onClick, cls = '') {
  const b = document.createElement('button');
  b.type = 'button';
  b.textContent = text;
  if (cls) b.className = cls;
  b.addEventListener('click', onClick);
  return b;
}

function moveBy(delta) {
  const path = state.selected;
  if (!path) return;
  const parent = path.slice(0, -1);
  const i = path.at(-1);
  const siblings = nodeAt(state.doc, parent).children;
  // Skip comments so one click always passes one visible block.
  let j = i + delta;
  while (j >= 0 && j < siblings.length && siblings[j].type === 'comment') j += delta;
  if (j < 0 || j >= siblings.length) return;
  // Counted after detaching: index j lands after sibling j going forward, before it going back.
  apply((doc) => sf.edit.move(doc, path, parent, j), [...parent, j]);
}

let propsKey = null;

function renderProps() {
  renderBlockbar();
  const panel = $('props');
  const path = state.selected;
  const node = path && nodeAt(state.doc, path);
  // Ending an edit re-renders on pointerdown, before the click that ended it lands.
  // Rebuilding an unchanged panel would remove the control under that click.
  const key = JSON.stringify([path, node?.type, node?.props, sourceShown()]);
  if (key === propsKey) return;
  propsKey = key;
  panel.replaceChildren();
  if (!node) {
    const p = document.createElement('p');
    p.className = 'empty';
    p.textContent = 'プレビューの部品をクリックすると、ここで設定を変えられます。見出しや文章はクリックした場所からそのまま書けます。部品は、選んだときに出る ⠿ をドラッグして動かします。部品のあいだにマウスを置くと出る ＋ で、そこに部品を足せます。';
    panel.append(p);
    return;
  }
  const comp = components.get(node.type);
  const h = document.createElement('h2');
  h.textContent = NAMES[node.type] ?? node.type;
  // Names from the source are shown only to those who read the source (editor-ux.md §7).
  if (sourceShown()) {
    const code = document.createElement('code');
    code.textContent = `<${node.type}>`;
    h.append(code);
  }
  panel.append(h);

  for (const attr of comp.attributes) {
    const control = attrControl(attr, node.props[attr.name], (value) => apply((doc) => sf.edit.setProp(doc, path, attr.name, value), path));
    panel.append(field(ATTRS_OF[node.type]?.[attr.name] ?? ATTRS[attr.name] ?? attr.name, attr.name, control));
  }
  if (node.type === 'Image' && serverMode) panel.append(uploadControl(path));
  if (node.type === 'Button' && serverMode) panel.append(documentControl(path));
  if (node.type === 'Table' || node.type === 'Row') {
    const tablePath = node.type === 'Table' ? path : path.slice(0, -1);
    const actions = document.createElement('div');
    actions.className = 'table-actions';
    actions.append(
      button('行を足す', () => tableEdit(tablePath, 'row')),
      button('列を足す', () => tableEdit(tablePath, 'column')),
      button('最後の列を消す', () => tableEdit(tablePath, 'drop-column')),
    );
    panel.append(actions);
    const note = document.createElement('p');
    note.className = 'note';
    note.textContent = 'セルの文字は、プレビューのセルをクリックしてそのまま書けます。行やセルを 1 つ足すときは、選んだときに出る ＋ を使います。表の中には行だけ、行の中にはセルだけを置けます。';
    panel.append(note);
  }
  if (node.type === 'Embed') {
    const note = document.createElement('p');
    note.className = 'note';
    note.textContent = 'Google マップの「共有」→「地図を埋め込む」でコピーした HTML を、そのまま「埋め込む場所」に貼れます。埋め込めるのは Google マップと、このサイトのページ（/ で始まる場所）だけです。プレビューでは中身を読み込まず、枠だけを出します。';
    panel.append(note);
  }
  if (node.type === 'Image') {
    const note = document.createElement('p');
    note.className = 'note';
    note.textContent = '元の幅と高さは、画像を選ぶと自動で入ります。読み込みの前から画像の場所を空けておくためのものです。画像そのものと違う値にすると、表示の大きさも変わります。';
    panel.append(note);
  }

  if (comp.kind === 'text') {
    const note = document.createElement('p');
    note.className = 'note';
    const linking = linkAllowedIn(node.type) ? '文字を選んで Ctrl+K（または上の帯の「リンク」）でリンクにできます。' : '';
    note.textContent = `文字はプレビューの上でそのまま書けます。Ctrl+B で太字、Ctrl+I で斜体、Enter で次の段落、Shift+Enter で改行、Esc で書き終わります。${linking}`;
    panel.append(note);
  }
  if (comp.kind === 'raw') {
    const ta = document.createElement('textarea');
    ta.value = node.raw;
    ta.addEventListener('change', () => apply((doc) => sf.edit.setRaw(doc, path, ta.value), path));
    panel.append(field('中身', 'raw', ta));
    const note = document.createElement('p');
    note.className = 'note';
    note.textContent = 'HTML の中身はプレビューでは動かしません。公開ページにはそのまま出ます（管理者だけが書ける想定。権限の仕組みは未実装）。';
    panel.append(note);
  }
}

// ------------------------------------------------------------ source pane

const escapeHtml = (s) => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

// Marks the selected block in a layer behind the textarea, since a textarea
// cannot show a selection while it has no focus.
function renderBackdrop(scroll = false) {
  const node = state.selected && nodeAt(state.doc, state.selected);
  const src = textarea.value;
  if (!node?.span || state.sourceError) {
    backdrop.innerHTML = `${escapeHtml(src)}\n`;
    return;
  }
  const [a, b] = node.span;
  backdrop.innerHTML = `${escapeHtml(src.slice(0, a))}<mark>${escapeHtml(src.slice(a, b))}</mark>${escapeHtml(src.slice(b))}\n`;
  if (scroll) {
    const mark = backdrop.querySelector('mark');
    const top = mark.offsetTop - 40;
    if (mark.offsetTop < textarea.scrollTop || mark.offsetTop > textarea.scrollTop + textarea.clientHeight - 20) textarea.scrollTop = Math.max(0, top);
  }
}

textarea.addEventListener('scroll', () => {
  backdrop.scrollTop = textarea.scrollTop;
  backdrop.scrollLeft = textarea.scrollLeft;
});

let typingTimer = null;

function cancelTyping() {
  clearTimeout(typingTimer);
  typingTimer = null;
}

// Reads the textarea into the state, or records the error and keeps the last good tree.
function flushTyping() {
  cancelTyping();
  const text = textarea.value;
  if (text === state.source) return;
  try {
    sf.parse(text);
  } catch (e) {
    state.sourceError = e;
    frameWrap.classList.add('stale');
    status(`ソースのエラー（${describeError(e)}）。プレビューは最後に読めた内容のままです`, 'error');
    return;
  }
  commit(text);
  status('ソースの変更をプレビューに反映しました', 'ok');
}

textarea.addEventListener('input', () => {
  renderBackdrop();
  cancelTyping();
  typingTimer = setTimeout(flushTyping, 300);
});

// Caret in the source selects the innermost block that contains it.
function selectFromCaret() {
  if (state.sourceError || textarea.value !== state.source) return;
  const at = textarea.selectionStart;
  let path = null;
  let n = state.doc;
  let current = [];
  for (;;) {
    const i = n.children?.findIndex((c) => c.span && c.type !== 'comment' && c.span[0] <= at && at < c.span[1]) ?? -1;
    if (i < 0) break;
    current = [...current, i];
    path = current;
    n = n.children[i];
    if (kindOf(n) !== 'container') break;
  }
  if (!samePath(path, state.selected)) {
    state.selected = path;
    renderProps();
    renderBackdrop();
    drawOverlay();
    elementFor(path ?? [])?.scrollIntoView({ block: 'nearest' });
  }
}
textarea.addEventListener('click', selectFromCaret);
textarea.addEventListener('keyup', (e) => {
  if (e.key.startsWith('Arrow') || e.key === 'Home' || e.key === 'End' || e.key.startsWith('Page')) selectFromCaret();
});

// ------------------------------------------- saving, publishing, history
// There is no save button (editor-ux.md §3.3): every change is saved as the draft about
// 1.5 s after it, and the header says whether it is saved. Publishing is the one filled
// button; right after it the status offers to take it back. Versions are kept on publish
// and as autosave checkpoints, and any of them can be brought back into the draft.

// localStorage can be missing or blocked (private mode, sandboxes); the editor then keeps
// the page in memory only.
function webStorage() {
  try {
    const s = window.localStorage;
    s.getItem(LEGACY_KEY);
    return s;
  } catch {
    const map = new Map();
    return { getItem: (k) => map.get(k) ?? null, setItem: (k, v) => map.set(k, String(v)) };
  }
}

const storage = webStorage();
// The page being edited: index.html#p=<id> (the list of posts opens them so, blog.md); else "top".
const PAGE = /^#p=([a-z0-9-]+)$/.exec(location.hash)?.[1] ?? 'top';
const pageId = PAGE_ID.test(PAGE) ? PAGE : 'top';
window.addEventListener('hashchange', () => location.reload());
// A site of pages (site.md): its pages have a path instead of a date, and its header and footer
// (part-header, part-footer) are edited here like a page. Set at start-up from /api/ping.
let siteMode = false;
// A site's or a blog's header and footer (site.md §5, blog.md §8): edited here like a page, and
// drawn around the other pages in the preview. Set at start-up from /api/ping.
let hasParts = false;
// Where a site's page goes when next published, and where it is written now (null: nowhere).
const sitePaths = { path: null, livePath: null };
// Replaced at start-up by the server's store when the editor is served with the PHP API
// (packages/server/router.php); otherwise the page stays in this browser.
let store = createPageStore(storage, pageId);
let serverMode = false;
const saving = {
  revision: 0, // draft revision the editor's source is based on
  savedSource: null,
  savedAt: null,
  publishedSource: null,
  failed: null, // why the last save failed: 'blocked' (a WAF, most likely), 'network', ...
  conflict: null, // the other tab's draft, until the user chooses
  timer: null,
  chain: Promise.resolve(),
};

const clock = new Intl.DateTimeFormat('ja-JP', { hour: '2-digit', minute: '2-digit' });
const dateClock = new Intl.DateTimeFormat('ja-JP', { month: 'numeric', day: 'numeric', hour: '2-digit', minute: '2-digit' });

function showSaveState(kind) {
  const el = $('save-state');
  const text = {
    pending: '未保存の変更があります',
    saving: '保存中…',
    saved: saving.savedAt ? `保存しました ${clock.format(saving.savedAt)}` : '',
    failed: '保存できませんでした。再試行しています',
    blocked: 'サーバーが保存を受け付けませんでした',
    conflict: '別の画面で保存された変更があります',
  }[kind];
  el.textContent = text;
  el.className = `save-state ${kind === 'failed' || kind === 'blocked' || kind === 'conflict' ? 'error' : ''}`;
  $('view-site').hidden = !serverMode || saving.publishedSource === null;
  // Text being written counts as a change, so "公開" can be pressed without leaving it first;
  // the press saves the text and then publishes it.
  // A site's page with a new path is published again to move it, even with the same content (site.md §3).
  const moving = siteMode && sitePaths.livePath !== null && sitePaths.path !== null && sitePaths.livePath !== sitePaths.path;
  const live = !moving && state.source === saving.publishedSource && !state.editing?.dirty;
  const pub = $('publish');
  pub.disabled = live;
  pub.textContent = live ? '公開済み' : '公開';
  pub.title = live ? '公開中のページと同じ内容です' : moving ? `公開して、書き出し先を ${sitePaths.path} へ移す` : 'いまの内容を公開する';
}

// The save state when nothing is running: what the header shows after a no-op.
function restingSaveState() {
  if (saving.conflict) return 'conflict';
  if (saving.failed) return saving.failed === 'blocked' || saving.failed === 'refused' ? 'blocked' : 'failed';
  return state.source !== saving.savedSource || state.editing?.dirty ? 'pending' : 'saved';
}

// The first change typed in the preview marks the text being written as unsaved.
function markTyping() {
  if (!state.editing || state.editing.dirty) return;
  state.editing.dirty = true;
  showSaveState(restingSaveState());
}

function scheduleSave() {
  clearTimeout(saving.timer);
  showSaveState(saving.conflict ? 'conflict' : 'pending');
  saving.timer = setTimeout(saveNow, 1500);
}

// Saves now, after any save already running. Resolves when the source is saved or refused.
function saveNow() {
  clearTimeout(saving.timer);
  saving.timer = null;
  saving.chain = saving.chain.then(saveDraft);
  return saving.chain;
}

async function saveDraft() {
  if (saving.conflict) {
    showConflict();
    return;
  }
  const source = state.source;
  if (source === saving.savedSource) return;
  showSaveState('saving');
  const res = await store.saveDraft(source, saving.revision);
  if (res.ok) {
    Object.assign(saving, { revision: res.revision, savedSource: source, savedAt: res.savedAt, failed: null });
    showSaveState(restingSaveState());
    renderHistory();
  } else if (res.reason === 'conflict') {
    saving.conflict = res.draft;
    showConflict();
  } else if (res.reason === 'blocked') {
    // The same text would be refused again, so there is no retry; the next change tries again.
    saving.failed = 'blocked';
    showSaveState('blocked');
    status(WAF_HELP, 'error');
  } else if (res.reason === 'forbidden' || res.reason === 'unauthenticated') {
    // The front that signs people in refused (server.md §6): a retry would be refused too.
    saving.failed = 'refused';
    showSaveState('blocked');
    status(serverMessage(res, '保存する権限がありません。書いた内容はこの画面に残っています'), 'error');
  } else if (res.reason === 'deleted') {
    // Deleted from the list of posts meanwhile: saves are refused until it is restored there.
    saving.failed = 'refused';
    showSaveState('blocked');
    status(`${DELETED_HELP}書いた内容はこの画面に残っていますが、保存されていません。`, 'error');
  } else {
    saving.failed = res.reason ?? 'unknown';
    showSaveState('failed');
    clearTimeout(saving.timer);
    saving.timer = setTimeout(saveNow, 5000);
  }
}

// A refusal from the front that signs people in carries its own words (server.md §6).
function serverMessage(res, fallback) {
  return typeof res.message === 'string' && res.message !== '' ? res.message : fallback;
}

// A POST to the save API, answered in its own form, or as 'blocked' / 'network' like the store's.
async function postJson(url, body) {
  try {
    const res = await fetch(url, { method: 'POST', cache: 'no-store', headers: { 'content-type': 'application/json', 'x-wge-request': '1' }, body: JSON.stringify(body) });
    const data = await res.json().catch(() => null);
    return data?.wge === 1 ? data : { ok: false, reason: 'blocked' };
  } catch {
    return { ok: false, reason: 'network' };
  }
}

// The page's title and date (a blog post's): shown in the toolbar and saved when changed (blog.md).
function showMeta(meta) {
  $('page-name').hidden = true;
  $('page-meta').hidden = false;
  $('page-title').value = meta.title;
  $('page-date').value = meta.date;
  $('page-description').value = meta.description ?? '';
  if (siteMode) {
    $('page-date').hidden = true;
    $('page-path').hidden = false;
    sitePaths.path = meta.path ?? null;
    sitePaths.livePath = meta.livePath ?? null;
    $('page-path').value = sitePaths.path ?? '';
    showPathNote();
  }
  showDescriptionCount();
  document.title = `${meta.title} – エディタ`;
}

// "サイトを見る" opens where a site's page is written now (else where it will be).
function siteAddress() {
  SITE_URL = `${SITE_ROOT}${addressOf(sitePaths.livePath ?? sitePaths.path ?? 'index.html')}`;
  $('view-site').href = SITE_URL;
}

// A published page whose path was changed stays where it is until it is published again.
function showPathNote() {
  const moving = sitePaths.livePath !== null && sitePaths.livePath !== sitePaths.path;
  $('page-path-note').hidden = !moving;
  $('page-path-note').textContent = moving ? `公開中は ${sitePaths.livePath}。次の公開で移ります` : '';
}

// The description is one line on the server: line breaks typed here become spaces.
const descriptionValue = () => $('page-description').value.replace(/\s+/g, ' ').trim();

function showDescriptionCount() {
  const n = [...descriptionValue()].length;
  $('page-description-count').textContent = n === 0 ? 'まだ書いていません（書かないと、検索結果には本文の一部が出ます）' : `${n} / 300 文字`;
  $('page-description-open').classList.toggle('filled', n > 0);
}

async function saveSiteMeta() {
  const title = cleanTitle($('page-title').value);
  const path = cleanPath($('page-path').value);
  if (!title) {
    status('題名は 1 行で、120 文字までにしてください', 'error');
    return;
  }
  if (!path) {
    status('書き出し先は、英小文字・数字・ハイフンの名前を「/」でつないで書いてください（例: exhibitors.html、en/index.html）', 'error');
    return;
  }
  const res = await postJson(`../api/pages/${pageId}/meta`, { title, path, description: descriptionValue() });
  if (!res.ok) {
    const why = {
      'path-taken': `書き出し先「${path}」は、ほかのページが使っています（削除したページを含みます）`,
      'invalid-path': '書き出し先に使えない名前です',
      'invalid-description': '説明文は 300 文字までにしてください',
    }[res.reason];
    status(why ?? serverMessage(res, '題名と書き出し先を保存できませんでした'), 'error');
    return;
  }
  $('page-path').value = path;
  sitePaths.path = path;
  showPathNote();
  // A new path makes "公開" pressable even when the content is the one published.
  showSaveState(restingSaveState());
  document.title = `${title} – エディタ`;
  const moving = sitePaths.livePath !== null && sitePaths.livePath !== path;
  status(moving ? `保存しました。書き出し先は、次に公開したときに ${path} へ移ります` : saving.publishedSource !== null ? '題名・書き出し先・説明文を保存しました。公開中のページにも出ています' : '題名・書き出し先・説明文を保存しました', 'ok');
}

async function saveMeta() {
  if (siteMode) {
    await saveSiteMeta();
    return;
  }
  const title = cleanTitle($('page-title').value);
  const date = $('page-date').value;
  if (!title || !/^\d{4}-\d{2}-\d{2}$/.test(date)) {
    status('題名は 1 行で 120 文字まで、日付は必ず入れてください', 'error');
    return;
  }
  const res = await postJson(`../api/pages/${pageId}/meta`, { title, date, description: descriptionValue() });
  if (!res.ok) {
    status(res.reason === 'invalid-description' ? '説明文は 300 文字までにしてください' : serverMessage(res, '題名と日付を保存できませんでした'), 'error');
    return;
  }
  document.title = `${title} – エディタ`;
  status(saving.publishedSource !== null ? '題名・日付・説明文を保存しました。公開中のページにも出ています' : '題名・日付・説明文を保存しました', 'ok');
}
$('page-title').addEventListener('change', saveMeta);
$('page-date').addEventListener('change', saveMeta);
$('page-path').addEventListener('change', saveMeta);
$('page-description').addEventListener('input', showDescriptionCount);
$('page-description').addEventListener('change', saveMeta);

// A picture for an Image block (with the server only). A photo is made smaller first (1600 px,
// JPEG), so a phone's picture fits; a small PNG (a logo) and a GIF go as they are (blog.md).
const IMAGE_MAX = 2_500_000;

async function imageForUpload(file) {
  if (file.type === 'image/gif' || (file.type === 'image/png' && file.size <= 1_000_000)) return file;
  const bitmap = await createImageBitmap(file);
  const { width, height } = fitWithin(bitmap.width, bitmap.height, 1600);
  const canvas = document.createElement('canvas');
  canvas.width = width;
  canvas.height = height;
  const ctx = canvas.getContext('2d');
  // JPEG has no transparency: what was transparent becomes white, not black.
  ctx.fillStyle = '#fff';
  ctx.fillRect(0, 0, width, height);
  ctx.drawImage(bitmap, 0, 0, width, height);
  bitmap.close?.();
  return new Promise((resolve, reject) => canvas.toBlob((b) => (b ? resolve(b) : reject(new Error('encode'))), 'image/jpeg', 0.85));
}

function base64Of(blob) {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(String(reader.result).split(',')[1] ?? '');
    reader.onerror = () => reject(reader.error);
    reader.readAsDataURL(blob);
  });
}

function uploadControl(path) {
  const wrap = document.createElement('div');
  wrap.className = 'field upload';
  const input = document.createElement('input');
  input.type = 'file';
  input.accept = 'image/jpeg,image/png,image/webp,image/gif';
  input.hidden = true;
  input.addEventListener('change', async () => {
    const file = input.files?.[0];
    input.value = '';
    if (!file) return;
    status('画像を送っています…');
    let blob;
    try {
      blob = await imageForUpload(file);
    } catch {
      status('この画像は使えません。JPEG・PNG・WebP・GIF の画像を選んでください', 'error');
      return;
    }
    if (blob.size > IMAGE_MAX) {
      status('画像が大きすぎます（2.5MB まで）。小さくしてから選んでください', 'error');
      return;
    }
    const res = await postJson('../api/media', { data: await base64Of(blob) });
    if (!res.ok) {
      const why = { 'not-image': 'この画像は使えません。JPEG・PNG・WebP・GIF の画像を選んでください', 'too-large': '画像が大きすぎます（2.5MB まで）' }[res.reason];
      status(serverMessage(res, why ?? '画像を送れませんでした。もう一度選んでください'), 'error');
      return;
    }
    // The size the server read keeps room for the picture before it loads; a size left from
    // the picture this one replaces would be wrong, so it goes when none comes back.
    const size = (v) => (Number.isInteger(v) && v >= 1 && v <= 20000 ? String(v) : null);
    apply((doc) => {
      sf.edit.setProp(doc, path, 'src', res.src);
      sf.edit.setProp(doc, path, 'width', size(res.width));
      sf.edit.setProp(doc, path, 'height', size(res.height));
    }, path);
    status('画像を入れました。説明（alt）も書いてください', 'ok');
  });
  wrap.append(button('画像を選ぶ…', () => input.click()), input);
  return wrap;
}

// Sends a PDF; its answer ({ src }), or null after saying why it was not taken.
async function sendPdf(file) {
  if (file.size > IMAGE_MAX) {
    status('PDF が大きすぎます（2.5MB まで）。ページを分けるか、小さくしてから選んでください', 'error');
    return null;
  }
  status('PDF を送っています…');
  const res = await postJson('../api/media', { data: await base64Of(file), kind: 'document' });
  if (!res.ok) {
    const why = { 'not-pdf': 'この形式は使えません。PDF を選んでください', 'too-large': 'PDF が大きすぎます（2.5MB まで）' }[res.reason];
    status(serverMessage(res, why ?? 'PDF を送れませんでした。もう一度選んでください'), 'error');
    return null;
  }
  return res;
}

// A PDF for a button to open (rendering.md §7.4): sent as it is, up to the same size as an image.
// A button still saying the template's "ボタン" is named after the file, so it never reads as
// a bare "ボタン" on the page; any other wording is the writer's and stays.
function documentControl(path) {
  const wrap = document.createElement('div');
  wrap.className = 'field upload';
  const input = document.createElement('input');
  input.type = 'file';
  input.accept = 'application/pdf,.pdf';
  input.hidden = true;
  input.addEventListener('change', async () => {
    const file = input.files?.[0];
    input.value = '';
    if (!file) return;
    const res = await sendPdf(file);
    if (!res) return;
    // Read now: the panel is not rebuilt when only the words change, so a node kept from
    // when it was built could still say "ボタン" after the writer has retyped them.
    const node = nodeAt(state.doc, path);
    const plain = node?.type === 'Button' && node.children?.length === 1 && node.children[0].type === 'text' && node.children[0].value === 'ボタン';
    const label = file.name.replace(/\.pdf$/i, '').trim();
    apply((doc) => {
      sf.edit.setProp(doc, path, 'href', res.src);
      if (plain && label) sf.edit.setInline(doc, path, [{ type: 'text', value: `${label}（PDF）` }]);
    }, path);
    status(plain && label ? 'PDF をつなぎました。ボタンの文字は、ファイルの名前から付けています。プレビューで直せます' : 'PDF をつなぎました。ボタンを押すと、この PDF が開きます', 'ok');
  });
  wrap.append(button('PDF を選ぶ…', () => input.click()), input);
  // Below the row, not beside the button, so it reads as one paragraph.
  const note = document.createElement('p');
  note.className = 'note';
  note.textContent = '申請書などの PDF を、このボタンで開けるようにします（2.5MB まで）。送った PDF は、ページを公開する前でも、アドレスを知っていれば誰でも開けます。外に出してよいものだけを選んでください。';
  const all = document.createDocumentFragment();
  all.append(wrap, note);
  return all;
}

// Shown when an answer did not come from the API (requirements §3.3: a WAF on rental servers).
const WAF_HELP = 'サーバーの防御機能（WAF）が保存を止めた可能性があります。サーバーの管理画面で、この管理画面の場所を WAF の対象から外してください。書いた内容はこの画面に残っています。';
const DELETED_HELP = 'この記事は削除されています。記事の一覧の「削除した記事」から元に戻すと、また開いて保存できます。';

function showConflict() {
  showSaveState('conflict');
  status('このページは別の画面でも編集され、そちらが先に保存されました。どちらを残しますか', 'error', [
    { label: 'そちらを読み込む', run: takeTheirs },
    { label: 'こちらで上書きする', run: keepMine },
  ]);
}

// Loading theirs is an ordinary change here, so "元に戻す" brings this tab's text back.
function takeTheirs() {
  const theirs = saving.conflict;
  Object.assign(saving, { conflict: null, revision: theirs.revision, savedSource: theirs.source, savedAt: theirs.savedAt });
  replaceSource(theirs.source);
  showSaveState('saved');
  status('別の画面で保存された内容を読み込みました', 'ok', { label: '元に戻す', run: undo });
}

function keepMine() {
  saving.revision = saving.conflict.revision;
  saving.conflict = null;
  saveNow();
}

// Replaces the whole source as one undoable change (restoring a version, loading theirs, the sample).
function replaceSource(source) {
  // Readable typing goes into the history first, so "元に戻す" can bring it back.
  if (textarea.value !== state.source) flushTyping();
  if (state.sourceError) {
    state.sourceError = null;
    textarea.value = state.source;
  }
  commit(source, { fromGui: true, select: null });
}

async function publish() {
  if (state.sourceError) {
    status('ソースにエラーがあります。直してから公開してください', 'error');
    return;
  }
  await saveNow();
  if (saving.conflict || state.source !== saving.savedSource) {
    status('保存できていないため、公開できません', 'error');
    return;
  }
  const source = state.source;
  const res = await store.publish(saving.revision);
  if (!res.ok) {
    if (res.reason === 'blocked') status(WAF_HELP, 'error');
    else if (res.reason === 'network') status('サーバーにつながらないため、公開できませんでした。つながってからもう一度押してください', 'error');
    // A file this editor did not write is never replaced (site.md §4).
    else if (res.reason === 'path-occupied') status(`書き出し先（${res.path ?? ''}）に、このエディタで書いていないファイルがあるため公開できません。書き出し先を変えるか、サイトの管理者に相談してください`, 'error');
    else status(serverMessage(res, '公開できませんでした。もう一度押してください'), 'error');
    return;
  }
  saving.publishedSource = source;
  // A site's page is now written at its path (site.md §3).
  if (siteMode && !isPart(pageId) && sitePaths.path !== null) {
    sitePaths.livePath = sitePaths.path;
    showPathNote();
    siteAddress();
  }
  showSaveState(restingSaveState());
  renderHistory();
  const actions = [{ label: '公開を取り消す', run: () => undoPublish(res.version.id) }];
  if (serverMode) actions.push({ label: 'サイトを見る', run: () => window.open(SITE_URL, '_blank', 'noopener') });
  const what = hasParts && isPart(pageId) ? '公開しました。公開中のすべてのページに入りました' : '公開しました';
  status(`${what}（${clock.format(res.version.at)}）`, 'ok', actions);
}

async function undoPublish(versionId) {
  const res = await store.undoPublish(versionId);
  if (!res.ok) {
    status(res.reason === 'not-live' ? 'すでに別の版が公開されているため、取り消せません。履歴から戻してください' : serverMessage(res, '公開を取り消せませんでした'), 'error');
    return;
  }
  saving.publishedSource = res.published ? await store.getVersion(res.published.id) : null;
  if (siteMode && !isPart(pageId) && !res.published) {
    sitePaths.livePath = null;
    showPathNote();
    siteAddress();
  }
  showSaveState(restingSaveState());
  renderHistory();
  if (hasParts && isPart(pageId)) status(res.published ? '公開を取り消しました。すべてのページが前の内容に戻りました' : '公開を取り消しました。どのページにも入っていません', 'ok');
  else status(res.published ? '公開を取り消しました。公開中のページは前の内容に戻りました' : '公開を取り消しました。このページは公開されていません', 'ok');
}

$('publish').addEventListener('click', publish);

async function renderHistory() {
  const panel = $('history');
  if (panel.hidden) return;
  const versions = await store.listVersions();
  const h = document.createElement('h2');
  h.textContent = '履歴';
  const note = document.createElement('p');
  note.className = 'note';
  note.textContent = '公開した版と、10 分ごとの自動保存が残ります。戻しても、公開するまで公開中のページは変わりません。';
  const list = document.createElement('ol');
  for (const v of versions) {
    const li = document.createElement('li');
    const label = `${v.kind === 'publish' ? '公開' : '自動保存'} ${dateClock.format(v.at)}`;
    const text = document.createElement('span');
    text.textContent = label;
    if (v.live) {
      const live = document.createElement('span');
      live.className = 'live';
      live.textContent = '公開中';
      li.append(text, live);
    } else {
      li.append(text);
    }
    li.append(button('この版に戻す', () => restoreVersion(v.id, label)));
    list.append(li);
  }
  if (versions.length === 0) {
    const empty = document.createElement('p');
    empty.className = 'empty';
    empty.textContent = 'まだ版はありません。公開すると、ここに残ります。';
    panel.replaceChildren(h, empty);
    return;
  }
  panel.replaceChildren(h, note, list);
}

async function restoreVersion(id, label) {
  const source = await store.getVersion(id);
  if (source === null) return;
  if (source === state.source) {
    status('いまの内容と同じです');
    return;
  }
  replaceSource(source);
  status(`「${label}」の版に戻しました`, 'ok', { label: '元に戻す', run: undo });
}

$('history-toggle').addEventListener('click', () => {
  const panel = $('history');
  panel.hidden = !panel.hidden;
  $('history-toggle').setAttribute('aria-pressed', String(!panel.hidden));
  renderHistory();
});

// ---------------------------------------------------------------- toolbar

// The source is hidden unless asked for (U2), and the choice is remembered.
const SOURCE_KEY = 'wge-editor-show-source';
const workspace = $('workspace');
const sourceShown = () => !workspace.classList.contains('no-source');

function showSource(on) {
  if (!on && state.sourceError) {
    status('ソースのエラーを直してから隠してください', 'error');
    return;
  }
  workspace.classList.toggle('no-source', !on);
  $('source-toggle').setAttribute('aria-pressed', String(on));
  try {
    localStorage.setItem(SOURCE_KEY, on ? '1' : '0');
  } catch {
    // Not remembered without storage.
  }
  renderProps();
  renderBackdrop(true);
  requestAnimationFrame(() => drawOverlay());
}
$('source-toggle').addEventListener('click', () => showSource(!sourceShown()));
try {
  if (localStorage.getItem(SOURCE_KEY) === '1') showSource(true);
} catch {
  // Hidden by default.
}

$('undo').addEventListener('click', undo);
$('redo').addEventListener('click', redo);
$('reset').addEventListener('click', () => {
  replaceSource(sample);
  status('サンプルに戻しました', 'ok', { label: '元に戻す', run: undo });
});

function setWidth(mobile) {
  frameWrap.style.width = mobile ? '375px' : '';
  $('width-pc').setAttribute('aria-pressed', String(!mobile));
  $('width-sp').setAttribute('aria-pressed', String(mobile));
  requestAnimationFrame(() => drawOverlay());
}
$('width-pc').addEventListener('click', () => setWidth(false));
$('width-sp').addEventListener('click', () => setWidth(true));
window.addEventListener('resize', () => drawOverlay());

document.addEventListener('keydown', onKey);

function onKey(e) {
  const typing = e.target instanceof HTMLInputElement || e.target instanceof HTMLTextAreaElement || e.target instanceof HTMLSelectElement;
  if (typing) return;
  const mod = e.ctrlKey || e.metaKey;
  const key = e.key.toLowerCase();
  if (mod && key === '/') {
    e.preventDefault();
    showSource(!sourceShown());
  } else if (mod && key === 'z' && !e.shiftKey) {
    e.preventDefault();
    undo();
  } else if (mod && (key === 'y' || (key === 'z' && e.shiftKey))) {
    e.preventDefault();
    redo();
  } else if (mod && key === 'd' && state.selected) {
    e.preventDefault();
    duplicateSelected();
  } else if (e.altKey && (e.key === 'ArrowUp' || e.key === 'ArrowDown') && state.selected) {
    e.preventDefault();
    moveBy(e.key === 'ArrowUp' ? -1 : 1);
  } else if ((e.key === 'Delete' || e.key === 'Backspace') && state.selected) {
    e.preventDefault();
    removeSelected();
  } else if (e.key === 'Escape') {
    // After Esc ends editing, the next Esc climbs to the parent, then clears the selection.
    // selectOnly, since select() would start editing again.
    selectOnly(state.selected && state.selected.length > 1 ? state.selected.slice(0, -1) : null);
  }
}

// ------------------------------------------------------------------- start

const readable = (source) => {
  try {
    sf.parse(source);
    return true;
  } catch {
    return false;
  }
};

// Served with the PHP API, the page lives on the server; the first open there starts from
// the sample. Otherwise it lives in this browser, starting from what the earlier prototype
// kept, else the sample.
const ping = await fetch('../api/ping', { cache: 'no-store' }).then((r) => r.json()).catch(() => null);
if (ping?.wge === 1 && ping.ok === false) {
  // The front refused (signed out, or the server behind it is down): never fall back to this browser's copy.
  status(serverMessage(ping, 'サーバーが編集を受け付けませんでした'), 'error');
  throw new Error('the save API refused the editor');
}
// With a blog, "top" is the contents page the server makes: the list of posts opens instead.
if (ping?.wge === 1 && ping.blog === true && pageId === 'top') {
  location.replace('posts.html');
  await new Promise(() => {});
}
// Behind a front, the published site is served on its own origin and the API says where (server.md §6).
const SITE_ROOT = typeof ping?.siteUrl === 'string' && /^https?:\/\//.test(ping.siteUrl) ? ping.siteUrl.replace(/\/?$/, '/') : '../site/';
// A site's page is seen where it is written; its header and footer from the top page (site.md).
let SITE_URL = pageId === 'top' ? SITE_ROOT : `${SITE_ROOT}${pageId}/`;
siteMode = ping?.wge === 1 && ping.site === true;
hasParts = ping?.wge === 1 && (ping.parts === true || siteMode);
const siteName = siteMode && typeof ping.siteName === 'string' ? ping.siteName : '';
if (siteMode) $('posts-link').textContent = 'ページの一覧';
if (hasParts && isPart(pageId)) {
  SITE_URL = SITE_ROOT;
  const name = pageId === HEADER ? 'ヘッダー' : 'フッター';
  $('page-name').textContent = `${name}（すべてのページに共通）`;
  document.title = `${name} – エディタ`;
}
$('view-site').href = SITE_URL;
// The header, footer and a site's first page start from a short page of their own, not the sample.
// A blog is a folder of a larger site, so its name links to where the blog is seen.
const blogTitle = typeof ping?.blogTitle === 'string' ? ping.blogTitle : '';
const firstSource = hasParts && isPart(pageId)
  ? (siteMode ? partTemplate(pageId, siteName) : partTemplate(pageId, blogTitle || 'ブログ', SITE_ROOT.startsWith('http') ? SITE_ROOT : null))
  : !siteMode ? sample : postTemplate(siteName || 'トップページ');
let page;
if (ping?.wge === 1) {
  try {
    const remote = createHttpStore('../api', pageId);
    page = await remote.load(firstSource);
    store = remote;
    serverMode = true;
  } catch (e) {
    if (e.reason === 'deleted') {
      // Never fall back to this browser's copy: what is written there would not reach the post.
      status(DELETED_HELP, 'error');
      $('posts-link').hidden = false;
      $('page-name').hidden = true;
      // Nothing is loaded, so nothing here may act: only the way back to the list stays.
      for (const b of document.querySelectorAll('button')) b.disabled = true;
      await new Promise(() => {});
    }
    status(`サーバーの保存先を開けなかったため、このブラウザの中に保存します（${e.reason ?? e.message}）`, 'error');
  }
}
if (serverMode) {
  $('posts-link').hidden = false;
  // "サンプルに戻す" is for trying the editor alone; on the server it would replace a real page.
  $('reset').hidden = true;
  if (page.meta) showMeta(page.meta);
  if (siteMode && !isPart(pageId)) siteAddress();
}
if (!page) {
  const legacy = storage.getItem(LEGACY_KEY);
  page = await store.load(legacy !== null && readable(legacy) ? legacy : sample);
}
Object.assign(saving, { revision: page.draft.revision, savedAt: page.draft.savedAt });
saving.publishedSource = page.published ? await store.getVersion(page.published.id) : null;
if (readable(page.draft.source)) {
  saving.savedSource = page.draft.source;
  setSource(page.draft.source);
} else {
  // The sample is saved over the unreadable draft on the next autosave.
  setSource(sample);
  status('保存されていた下書きを読めなかったため、サンプルを開きました', 'error');
}
showSaveState(saving.savedSource === state.source ? 'saved' : 'pending');
if (serverMode && hasParts && !isPart(pageId)) loadSiteParts();
