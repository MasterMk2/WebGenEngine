// node --test packages/editor/inline-dom.test.mjs
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { inlineFromDom } from './inline-dom.mjs';
import { createSourceFormat } from '../source/js/source-format.mjs';

// The few DOM members inlineFromDom reads.
const t = (value) => ({ nodeType: 3, nodeValue: value });
const el = (name, children = [], attrs = {}) => ({
  nodeType: 1,
  nodeName: name,
  childNodes: children,
  getAttribute: (k) => (k in attrs ? attrs[k] : null),
});
const comment = { nodeType: 8, nodeValue: 'x' };

test('keeps the tags the renderer writes', () => {
  const p = el('P', [t('ほろ苦く、'), el('STRONG', [t('チョコ')]), t('の'), el('EM', [t('余韻')]), el('BR'), el('A', [t('地図')], { href: '/access' })]);
  assert.deepEqual(inlineFromDom(p), [
    { type: 'text', value: 'ほろ苦く、' },
    { type: 'Strong', props: {}, children: [{ type: 'text', value: 'チョコ' }] },
    { type: 'text', value: 'の' },
    { type: 'Em', props: {}, children: [{ type: 'text', value: '余韻' }] },
    { type: 'Br', props: {} },
    { type: 'Link', props: { href: '/access' }, children: [{ type: 'text', value: '地図' }] },
  ]);
});

test('maps the browser\'s own bold and italic, unwraps everything else', () => {
  const p = el('P', [el('B', [t('太')]), el('SPAN', [t('普'), el('I', [t('斜')])]), el('FONT', [t('通')]), comment, el('A', [t('名前だけ')])]);
  assert.deepEqual(inlineFromDom(p), [
    { type: 'Strong', props: {}, children: [{ type: 'text', value: '太' }] },
    { type: 'text', value: '普' },
    { type: 'Em', props: {}, children: [{ type: 'text', value: '斜' }] },
    { type: 'text', value: '通' },
    { type: 'text', value: '名前だけ' },
  ]);
});

test('drops the trailing <br>, empty wrappers and no-break spaces', () => {
  const p = el('P', [t('a  b'), el('STRONG', [t('')]), el('EM', []), el('BR')]);
  assert.deepEqual(inlineFromDom(p), [{ type: 'text', value: 'a  b' }]);
  assert.deepEqual(inlineFromDom(el('P', [el('BR')])), []);
  // A <br> in the middle is a line break the user typed (Shift+Enter).
  assert.deepEqual(inlineFromDom(el('P', [t('a'), el('BR'), t('b')])).map((n) => n.type), ['text', 'Br', 'text']);
});

test('keeps a small note, and every inline component of the schema has a tag kept here', () => {
  const p = el('P', [t('会場'), el('SMALL', [t('（'), el('A', [t('地図')], { href: '/map' }), t('）')])]);
  assert.deepEqual(inlineFromDom(p), [
    { type: 'text', value: '会場' },
    { type: 'Small', props: {}, children: [{ type: 'text', value: '（' }, { type: 'Link', props: { href: '/map' }, children: [{ type: 'text', value: '地図' }] }, { type: 'text', value: '）' }] },
  ]);
  // Editing a text in place reads it back from the DOM: an inline component missing here would be lost.
  const schema = JSON.parse(readFileSync(new URL('../source/schema.json', import.meta.url), 'utf8'));
  for (const c of schema.components.filter((c) => c.kind === 'inline' || c.kind === 'inline-void')) {
    const tag = c.render.tag.toUpperCase();
    const node = el(tag, c.kind === 'inline' ? [t('x')] : [], { href: '/x' });
    const read = inlineFromDom(el('P', [t('a'), node, t('b')]));
    assert.equal(read[1]?.type, c.name, `${c.name} (<${c.render.tag}>) is read back`);
  }
});

test('the result goes through setInline and changes only that line', () => {
  const sf = createSourceFormat(JSON.parse(readFileSync(new URL('../source/schema.json', import.meta.url), 'utf8')));
  const source = '<Section>\n    <Text>前の文</Text>\n    <Text>そのまま</Text>\n</Section>\n';
  const doc = sf.parse(source);
  const p = el('P', [t('新しい'), el('B', [t('太字')]), t('の文'), el('BR')]);
  sf.edit.setInline(doc, [0, 0], inlineFromDom(p));
  const out = sf.serialize(doc);
  assert.equal(out, '<Section>\n    <Text>新しい<Strong>太字</Strong>の文</Text>\n    <Text>そのまま</Text>\n</Section>\n');
  assert.deepEqual(sf.toJSON(sf.parse(out)), sf.toJSON(doc));
});
