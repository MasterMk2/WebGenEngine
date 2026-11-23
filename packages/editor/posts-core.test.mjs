import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createSourceFormat } from '../source/js/source-format.mjs';
import { cleanTitle, dateLabel, fitWithin, newPostId, PAGE_ID, postTemplate, today } from './posts-core.mjs';

test('a new post ID is its date and four hex digits, and a valid page ID', () => {
  const id = newPostId('2026-11-23', new Uint8Array([0xa3, 0x0f]));
  assert.equal(id, '20261123-a30f');
  assert.match(id, PAGE_ID);
  assert.match(newPostId('2026-01-02'), /^20260102-[0-9a-f]{4}$/);
  assert.throws(() => newPostId('23/11/2026'));
});

test("today is the date in the site's time zone", () => {
  // 2026-11-23 16:00 UTC is already the 24th in Tokyo.
  assert.equal(today(new Date('2026-11-23T16:00:00Z')), '2026-11-24');
  assert.equal(today(new Date('2026-11-23T14:59:59Z')), '2026-11-23');
});

test('a new post reads back as a page, its title as the heading', () => {
  const schema = JSON.parse(readFileSync(new URL('../source/schema.json', import.meta.url), 'utf8'));
  const sf = createSourceFormat(schema);
  const source = postTemplate('合成の記事 <b> & "引用"');
  const doc = sf.parse(source);
  assert.ok(doc);
  assert.ok(source.includes('<Heading level="1">合成の記事 &lt;b&gt; &amp; &quot;引用&quot;</Heading>'));
});

test('titles are one line of 1 to 120 characters', () => {
  assert.equal(cleanTitle('  合成の記事  '), '合成の記事');
  assert.equal(cleanTitle(''), null);
  assert.equal(cleanTitle('   '), null);
  assert.equal(cleanTitle('二\n行'), null);
  assert.equal(cleanTitle('長'.repeat(120)), '長'.repeat(120));
  assert.equal(cleanTitle('長'.repeat(121)), null);
});

test('a photo is drawn with its longer side at most the limit, never enlarged', () => {
  assert.deepEqual(fitWithin(4000, 3000, 1600), { width: 1600, height: 1200 });
  assert.deepEqual(fitWithin(3000, 4000, 1600), { width: 1200, height: 1600 });
  assert.deepEqual(fitWithin(800, 600, 1600), { width: 800, height: 600 });
});

test('dates are shown in Japanese', () => {
  assert.equal(dateLabel('2026-11-03'), '2026年11月3日');
  assert.equal(dateLabel(null), '');
});
