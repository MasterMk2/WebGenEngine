// Editor prototype: preview, property panel and source side by side (R4, R5).
// The source text is the single state. Every GUI action parses it, applies one edit
// operation, saves with the lossless serializer and parses the result again.
// Spec: docs/design/editor.md.
import { createSourceFormat, SourceError, EditError } from '../source/js/source-format.mjs';
import { createRenderer } from '../source/js/render.mjs';
import { slotIndex, slotGuide, nearEdge, moveArgs } from './slots.mjs';
import { inlineFromDom } from './inline-dom.mjs';

const fetchText = (p) => fetch(p).then((r) => {
  if (!r.ok) throw new Error(`${p}: ${r.status}`);
  return r.text();
});
const [schema, theme, baseCss, sample] = await Promise.all([
  fetchText('../source/schema.json').then(JSON.parse),
  fetchText('../source/theme.default.json').then(JSON.parse),
  fetchText('../source/render/base.css'),
  fetchText('sample/page.txt'),
]);
const sf = createSourceFormat(schema);
const renderer = createRenderer(schema, theme, baseCss);
const components = new Map(schema.components.map((c) => [c.name, c]));

const NAMES = { Section: '区画', Stack: '並び', Grid: '格子', Heading: '見出し', Text: '文章', Button: 'ボタン', Image: '画像', Html: 'HTML', document: 'ページ' };
const ATTRS = {
  padding: '上下の余白', background: '背景', width: '中身の幅', direction: '向き', align: '揃え', justify: '並びの配置',
  gap: '間隔', wrap: '折り返し', columns: '列の数', level: '見出しの段階', size: '文字の大きさ', href: 'リンク先',
  variant: '種類', src: '画像の場所', alt: '代わりの文', ratio: '縦横の比',
};
const VALUES = {
  vertical: '縦', horizontal: '横', start: '先頭側', center: '中央', end: '末尾側', stretch: '伸ばす', between: '両端',
  true: 'する', false: 'しない', none: 'なし', subtle: '薄い色', primary: '主な色', inverse: '反転', secondary: '控えめ',
  narrow: '狭い', normal: '標準', wide: '広い', full: '全幅', auto: '元のまま', sm: '小', md: '中', lg: '大',
};
const TEMPLATES = {
  Section: { type: 'Section', props: { padding: 'lg' }, children: [] },
  Stack: { type: 'Stack', props: { gap: 'md' }, children: [] },
  Grid: { type: 'Grid', props: { columns: '2', gap: 'md' }, children: [] },
  Heading: { type: 'Heading', props: { level: '2' }, children: [{ type: 'text', value: '見出し' }] },
  Text: { type: 'Text', props: {}, children: [{ type: 'text', value: '文章を入れてください。' }] },
  Button: { type: 'Button', props: { href: '/' }, children: [{ type: 'text', value: 'ボタン' }] },
  Image: { type: 'Image', props: { src: 'sample/coffee.svg', alt: '' } },
};
const STORAGE_KEY = 'wge-editor-source';
const PREVIEW_CSS = `
[data-wge-path] { cursor: grab; }
.wge-edit-raw { padding: 12px; border: 1px dashed currentColor; font: 12px ui-monospace, monospace; opacity: 0.7; }
.wge-section:empty, .wge-stack:empty, .wge-grid:empty { min-height: 48px; outline: 1px dashed #9aa3ad; outline-offset: -4px; }
.wge-heading, .wge-text, .wge-button { cursor: text; }
[contenteditable] { outline: none; cursor: text; }
.wge-text:empty, .wge-heading:empty { min-height: 1.5em; }
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
const axisOf = (node) => (node.type === 'Grid' || (node.type === 'Stack' && node.props.direction === 'horizontal') ? 'flow' : 'vertical');

function status(message, tone = '') {
  const el = $('status');
  el.textContent = message;
  el.className = `status ${tone}`;
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

function save() {
  try {
    localStorage.setItem(STORAGE_KEY, state.source);
  } catch {
    // Storage can be unavailable (private mode); the editor works without it.
  }
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
  save();
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
  frame.srcdoc = [
    '<!doctype html><html lang="ja"><head><meta charset="utf-8">',
    `<style>${renderer.stylesheet()}${PREVIEW_CSS}</style></head><body>`,
    '<main class="wge-page" data-wge-path="">',
    renderer.renderFragment(state.doc, '  ', { annotate: true }),
    '</main></body></html>',
  ].join('\n');
  renderProps();
  renderBackdrop();
  $('undo').disabled = state.undo.length === 0;
  $('redo').disabled = state.redo.length === 0;
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

function addLabel(rect, text) {
  const el = addBox('label', { left: rect.left, top: Math.max(0, rect.top - 18), width: 'auto', height: 18 }, text);
  el.style.width = '';
  return el;
}

function drawOverlay(drag = null) {
  overlay.replaceChildren();
  if (drag) {
    if (drag.target) {
      addBox('target', drag.target.rect);
      const g = drag.target.guide;
      const line = document.createElement('div');
      line.className = 'guide';
      const vertical = g.x1 === g.x2;
      Object.assign(line.style, vertical
        ? { left: `${g.x1 - 1.5}px`, top: `${g.y1}px`, width: '3px', height: `${g.y2 - g.y1}px` }
        : { left: `${g.x1}px`, top: `${g.y1 - 1.5}px`, width: `${g.x2 - g.x1}px`, height: '3px' });
      overlay.append(line);
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
    if (el) {
      const r = el.getBoundingClientRect();
      addBox('selected', r);
      const name = NAMES[nodeAt(state.doc, state.selected).type];
      addLabel(r, state.editing ? `${name}（書いています。Esc で終わる）` : name);
    }
  }
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
    if (path.length === 0) return true;
    return !nearEdge(axisOf(nodeAt(state.doc, path.slice(0, -1))), el.getBoundingClientRect(), point);
  });
  containerEl ??= previewDoc().querySelector('main');
  const parent = parsePath(containerEl.dataset.wgePath);
  const node = nodeAt(state.doc, parent);
  const kids = [...containerEl.children].filter((el) => el.matches('[data-wge-path]'));
  const rects = kids.map((el) => el.getBoundingClientRect());
  const axis = axisOf(node);
  const k = slotIndex(axis, rects, point);
  const index = k < kids.length ? parsePath(kids[k].dataset.wgePath).at(-1) : node.children.length;
  const rect = containerEl.getBoundingClientRect();
  return { parent, index, slot: k, type: node.type, rect, guide: slotGuide(axis, rect, rects, k) };
}

let pointer = null;

overlay.addEventListener('pointerdown', (e) => {
  if (e.button !== 0 || state.sourceError) return;
  const point = { x: e.offsetX, y: e.offsetY };
  const path = hitPath(point);
  // Dragging inside the selected block moves that block, so a whole card can be
  // picked up after "親を選ぶ"; otherwise the innermost block under the pointer moves.
  const drag = state.selected && path && isPrefix(state.selected, path) ? state.selected : path;
  pointer = { start: point, path, drag, dragging: false, id: e.pointerId };
  overlay.setPointerCapture(e.pointerId);
});

overlay.addEventListener('pointermove', (e) => {
  const point = { x: e.offsetX, y: e.offsetY };
  if (!pointer) {
    const path = hitPath(point);
    if (!samePath(path, state.hover)) {
      state.hover = path && path.length > 0 ? path : null;
      drawOverlay();
    }
    return;
  }
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
  if (!p.dragging) {
    select(p.path, p.start);
    return;
  }
  const args = p.target ? moveArgs(p.drag, p.target.parent, p.target.index) : { error: 'ここには置けません' };
  if (args.error) status(args.error, 'error');
  else if (!args.noop) apply((doc) => sf.edit.move(doc, p.drag, args.parent, args.index), [...args.parent, args.index]);
  drawOverlay();
});

overlay.addEventListener('pointercancel', () => {
  pointer = null;
  overlay.classList.remove('dragging');
  drawOverlay();
});

overlay.addEventListener('pointerleave', () => {
  if (pointer) return;
  state.hover = null;
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
function select(path, point = null) {
  state.selected = path && path.length > 0 ? path : null;
  renderProps();
  renderBackdrop(true);
  drawOverlay();
  const node = state.selected && nodeAt(state.doc, state.selected);
  if (node && kindOf(node) === 'text') startEditing(state.selected, point);
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
// or to the start when `atStart` (the second half of a paragraph split by Enter).
function startEditing(path, point, { atStart = false } = {}) {
  const el = elementFor(path);
  if (!el || state.sourceError) return;
  state.editing = { path, el, composing: false };
  el.contentEditable = 'true';
  overlay.classList.add('editing');
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
    else sel.collapseToEnd();
  }
  drawOverlay();
}

function stopEditing() {
  const ed = state.editing;
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
      drawOverlay();
      return 'same';
    }
  } catch (e) {
    status(describeError(e), 'error');
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
    return;
  }
  if (range.collapsed) return;
  const w = d.createElement(tag);
  w.append(range.extractContents());
  range.insertNode(w);
  sel.selectAllChildren(w);
}

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
  }
}

function watchPreview(d) {
  d.addEventListener('keydown', onEditKey);
  d.addEventListener('compositionstart', () => { if (state.editing) state.editing.composing = true; });
  d.addEventListener('compositionend', () => { if (state.editing) state.editing.composing = false; });
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
  if (state.editing && e.target !== overlay) finishEditing();
}, true);
// Typing not yet saved would be lost on reload or close.
window.addEventListener('pagehide', () => finishEditing());

// ---------------------------------------------------------- property panel

function field(labelText, attrName, control) {
  const row = document.createElement('div');
  row.className = 'field';
  const label = document.createElement('label');
  const id = `f-${attrName}`;
  label.htmlFor = id;
  label.textContent = `${labelText} `;
  const code = document.createElement('code');
  code.textContent = attrName;
  label.append(code);
  control.id = id;
  row.append(label, control);
  return row;
}

function attrControl(attr, value, onChange) {
  let options = null;
  if (attr.type === 'enum') options = attr.values;
  else if (attr.type === 'space') options = schema.tokens.space;
  else if (attr.type === 'int') options = Array.from({ length: attr.max - attr.min + 1 }, (_, i) => String(attr.min + i));
  if (options) {
    const select = document.createElement('select');
    if (!attr.required) select.add(new Option('（指定なし）', ''));
    for (const v of options) select.add(new Option(VALUES[v] && attr.type !== 'space' ? `${VALUES[v]} (${v})` : v, v));
    select.value = value ?? '';
    select.addEventListener('change', () => onChange(select.value === '' ? null : select.value));
    return select;
  }
  const input = document.createElement('input');
  input.type = 'text';
  input.value = value ?? '';
  input.addEventListener('change', () => onChange(input.value === '' && !attr.required ? null : input.value));
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
  const panel = $('props');
  const path = state.selected;
  const node = path && nodeAt(state.doc, path);
  // Ending an edit re-renders on pointerdown, before the click that ended it lands.
  // Rebuilding an unchanged panel would remove the control under that click.
  const key = JSON.stringify([path, node?.type, node?.props]);
  if (key === propsKey) return;
  propsKey = key;
  panel.replaceChildren();
  if (!node) {
    const p = document.createElement('p');
    p.className = 'empty';
    p.textContent = 'プレビューの部品をクリックすると、ここで設定を変えられます。見出しや文章はクリックした場所からそのまま書けます。ドラッグで並べ替えられます。選んだ部品の中からドラッグすると、その部品ごと動きます。';
    panel.append(p);
    return;
  }
  const comp = components.get(node.type);
  const h = document.createElement('h2');
  h.textContent = NAMES[node.type] ?? node.type;
  const code = document.createElement('code');
  code.textContent = `<${node.type}>`;
  h.append(code);
  const actions = document.createElement('div');
  actions.className = 'actions';
  const parentButton = button('親を選ぶ', () => {
    state.selected = path.slice(0, -1);
    renderProps();
    renderBackdrop(true);
    drawOverlay();
  });
  parentButton.disabled = path.length < 2;
  actions.append(
    parentButton,
    button('↑ 前へ', () => moveBy(-1)),
    button('↓ 後ろへ', () => moveBy(1)),
    button('削除', () => apply((doc) => sf.edit.remove(doc, path), null), 'danger'),
  );
  panel.append(h, actions);

  for (const attr of comp.attributes) {
    const control = attrControl(attr, node.props[attr.name], (value) => apply((doc) => sf.edit.setProp(doc, path, attr.name, value), path));
    panel.append(field(ATTRS[attr.name] ?? attr.name, attr.name, control));
  }

  if (comp.kind === 'text') {
    const note = document.createElement('p');
    note.className = 'note';
    note.textContent = '文字はプレビューの上でそのまま書けます。Ctrl+B で太字、Ctrl+I で斜体、Shift+Enter で改行、Esc で書き終わります。リンクの追加はまだソースで行います。';
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

// ---------------------------------------------------------------- toolbar

for (const name of Object.keys(TEMPLATES)) {
  $('palette').append(button(`＋${NAMES[name]}`, () => {
    // Into the selected container, else right after the selected block, else at the end of the page.
    const sel = state.selected;
    const selNode = sel && nodeAt(state.doc, sel);
    let parent;
    let index;
    if (selNode && kindOf(selNode) === 'container') {
      parent = sel;
      index = selNode.children.length;
    } else if (sel) {
      parent = sel.slice(0, -1);
      index = sel.at(-1) + 1;
    } else {
      parent = [];
      index = state.doc.children.length;
    }
    apply((doc) => sf.edit.insert(doc, parent, index, structuredClone(TEMPLATES[name])), [...parent, index]);
  }));
}

$('undo').addEventListener('click', undo);
$('redo').addEventListener('click', redo);
$('reset').addEventListener('click', () => {
  // Keep readable typing in the history so "元に戻す" can bring it back.
  if (textarea.value !== state.source) flushTyping();
  if (state.sourceError) {
    state.sourceError = null;
    textarea.value = state.source;
  }
  commit(sample, { fromGui: true, select: null });
  status('サンプルに戻しました。「元に戻す」で取り消せます', 'ok');
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
  if (mod && e.key.toLowerCase() === 'z' && !e.shiftKey) {
    e.preventDefault();
    undo();
  } else if (mod && (e.key.toLowerCase() === 'y' || (e.key.toLowerCase() === 'z' && e.shiftKey))) {
    e.preventDefault();
    redo();
  } else if ((e.key === 'Delete' || e.key === 'Backspace') && state.selected) {
    e.preventDefault();
    apply((doc) => sf.edit.remove(doc, state.selected), null);
  } else if (e.key === 'Escape') {
    // After Esc ends editing, the next Esc climbs to the parent, then clears the selection.
    // Assigned directly: going through select() would start editing again.
    state.selected = state.selected && state.selected.length > 1 ? state.selected.slice(0, -1) : null;
    renderProps();
    renderBackdrop();
    drawOverlay();
  }
}

// ------------------------------------------------------------------- start

let initial = sample;
try {
  const stored = localStorage.getItem(STORAGE_KEY);
  if (stored !== null) {
    sf.parse(stored);
    initial = stored;
  }
} catch {
  initial = sample;
}
setSource(initial);
