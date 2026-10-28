// node --test packages/editor/slots.test.mjs
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { slotIndex, nearEdge, moveArgs } from './slots.mjs';
import { createSourceFormat } from '../source/js/source-format.mjs';

const box = (left, top, w, h) => ({ left, top, right: left + w, bottom: top + h });

test('vertical: compares with each child midpoint', () => {
  const rects = [box(0, 0, 100, 40), box(0, 50, 100, 40), box(0, 100, 100, 40)];
  assert.equal(slotIndex('vertical', rects, { x: 50, y: 5 }), 0);
  assert.equal(slotIndex('vertical', rects, { x: 50, y: 30 }), 1);
  assert.equal(slotIndex('vertical', rects, { x: 50, y: 75 }), 2);
  assert.equal(slotIndex('vertical', rects, { x: 50, y: 500 }), 3);
  assert.equal(slotIndex('vertical', [], { x: 0, y: 0 }), 0);
});

test('flow: picks the row, then compares x within it', () => {
  // A 2-column grid with three items: row 0 = [0, 1], row 1 = [2].
  const rects = [box(0, 0, 100, 50), box(110, 0, 100, 50), box(0, 60, 100, 30)];
  assert.equal(slotIndex('flow', rects, { x: 10, y: 20 }), 0);
  assert.equal(slotIndex('flow', rects, { x: 80, y: 20 }), 1);
  assert.equal(slotIndex('flow', rects, { x: 200, y: 20 }), 2);
  assert.equal(slotIndex('flow', rects, { x: 80, y: 70 }), 3);
  assert.equal(slotIndex('flow', rects, { x: 10, y: 300 }), 2);
});

test('flow: items of different heights stay on one row', () => {
  const rects = [box(0, 0, 50, 100), box(60, 40, 50, 20)];
  assert.equal(slotIndex('flow', rects, { x: 70, y: 10 }), 1);
});

test('nearEdge: bands follow the parent axis and shrink for small boxes', () => {
  const card = box(100, 0, 200, 300);
  assert.equal(nearEdge('flow', card, { x: 110, y: 150 }), true);
  assert.equal(nearEdge('flow', card, { x: 200, y: 5 }), false);
  assert.equal(nearEdge('flow', card, { x: 290, y: 150 }), true);
  assert.equal(nearEdge('vertical', card, { x: 101, y: 150 }), false);
  assert.equal(nearEdge('vertical', card, { x: 200, y: 290 }), true);
  const thin = box(0, 0, 100, 20);
  assert.equal(nearEdge('vertical', thin, { x: 50, y: 10 }), false);
  assert.equal(nearEdge('vertical', thin, { x: 50, y: 2 }), true);
});

test('moveArgs: same parent, forward and backward', () => {
  assert.deepEqual(moveArgs([0], [], 3), { parent: [], index: 2 });
  assert.deepEqual(moveArgs([2], [], 0), { parent: [], index: 0 });
  assert.deepEqual(moveArgs([1], [], 1), { noop: true });
  assert.deepEqual(moveArgs([1], [], 2), { noop: true });
});

test('moveArgs: target parent shifts when an earlier sibling is detached', () => {
  assert.deepEqual(moveArgs([0, 1], [0, 3], 0), { parent: [0, 2], index: 0 });
  assert.deepEqual(moveArgs([0, 3], [0, 1], 0), { parent: [0, 1], index: 0 });
  assert.deepEqual(moveArgs([1], [2, 0], 1), { parent: [1, 0], index: 1 });
});

test('moveArgs: cannot drop into itself', () => {
  assert.ok(moveArgs([1], [1, 0], 0).error);
  assert.ok(moveArgs([1], [1], 0).error);
});

// Every slot the editor can offer, applied with edit.move, must equal the naive
// "remove then insert at the visual slot" result.
test('moveArgs agrees with edit.move on every slot of a small tree', () => {
  const schema = JSON.parse(readFileSync(new URL('../source/schema.json', import.meta.url), 'utf8'));
  const sf = createSourceFormat(schema);
  const src = '<Stack>\n  <Text>a</Text>\n  <Stack><Text>b</Text><Text>c</Text></Stack>\n  <Text>d</Text>\n</Stack>\n<Text>e</Text>\n';
  const containers = [[], [0], [0, 1]];
  const blocks = [[0], [0, 0], [0, 1], [0, 1, 0], [0, 1, 1], [0, 2], [1]];
  const label = (n) => (n.type === 'Text' ? n.children[0].value : `${n.type}(${n.children.map(label).join(',')})`);
  const at = (json, path) => path.reduce((n, i) => n.children[i], json);
  for (const from of blocks) {
    for (const parent of containers) {
      const before = sf.toJSON(sf.parse(src));
      const size = at(before, parent).children.length;
      for (let index = 0; index <= size; index++) {
        const args = moveArgs(from, parent, index);
        if (args.error) continue;
        // Expected: take the node out of a copy, then insert it before the same visual neighbour.
        const expected = structuredClone(before);
        const target = at(expected, parent);
        const anchor = target.children[index] ?? null;
        const fromParent = at(expected, from.slice(0, -1));
        const [moving] = fromParent.children.splice(from[from.length - 1], 1);
        if (anchor === moving) fromParent.children.splice(from[from.length - 1], 0, moving);
        else target.children.splice(anchor ? target.children.indexOf(anchor) : target.children.length, 0, moving);
        const doc = sf.parse(src);
        if (!args.noop) sf.edit.move(doc, from, args.parent, args.index);
        const got = sf.toJSON(sf.parse(sf.serialize(doc)));
        assert.equal(expected.children.map(label).join('|'), got.children.map(label).join('|'), `${from} -> ${parent}@${index}`);
      }
    }
  }
});
