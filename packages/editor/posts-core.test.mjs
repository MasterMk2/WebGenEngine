import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createSourceFormat } from '../source/js/source-format.mjs';
import { addressOf, cleanPath, cleanTitle, dateLabel, embedSrcFrom, fitWithin, FOOTER, HEADER, isPart, newPostId, PAGE_ID, pageHref, pageIdFor, partTemplate, postTemplate, today } from './posts-core.mjs';

test('the inert preview (the site\'s header and footer) draws an embed and Html as frames, with no paths', async () => {
  const { createRenderer } = await import('../source/js/render.mjs');
  const schema = JSON.parse(readFileSync(new URL('../source/schema.json', import.meta.url), 'utf8'));
  const sf = createSourceFormat(schema);
  const theme = JSON.parse(readFileSync(new URL('../source/theme.default.json', import.meta.url), 'utf8'));
  const r = createRenderer(schema, theme, '');
  const doc = sf.parse('<Stack>\n  <Embed src="/map/viewer.html" title="合成の地図" />\n  <Html><p>x</p></Html>\n  <Text>合成</Text>\n</Stack>\n');
  const inert = r.renderFragment(doc, '', { inert: true });
  assert.ok(!inert.includes('<iframe') && !inert.includes('data-wge-path'), inert);
  assert.match(inert, /<div class="wge-edit-raw wge-embed">合成の地図<\/div>/);
  assert.match(inert, /<div class="wge-edit-raw">Html<\/div>/);
  assert.ok(r.renderFragment(doc, '').includes('<iframe class="wge-embed" src="/map/viewer.html"'), 'published pages are unchanged');
});

test("a link to a page of the site is written from the site's root, without a last index.html", () => {
  assert.equal(pageHref('index.html'), '/');
  assert.equal(pageHref('en/index.html'), '/en/');
  assert.equal(pageHref('about.html'), '/about.html');
  assert.equal(pageHref('info/guide.html'), '/info/guide.html');
});

test("an embed's place is taken out of a pasted <iframe>, or kept as typed", () => {
  const pasted = '<iframe src="https://www.google.com/maps/embed?pb=!1m18&amp;z=2" width="600" height="450" style="border:0;" allowfullscreen="" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>';
  assert.equal(embedSrcFrom(pasted), 'https://www.google.com/maps/embed?pb=!1m18&z=2');
  assert.equal(embedSrcFrom("  <IFRAME title='x' SRC='/map/viewer.html?v=1'></IFRAME> "), '/map/viewer.html?v=1');
  assert.equal(embedSrcFrom(' /map/viewer.html '), '/map/viewer.html');
  assert.equal(embedSrcFrom('<iframe data-src="x"></iframe>'), '<iframe data-src="x"></iframe>');
});

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

test("a site's path is made the way the server keeps it, or refused", () => {
  assert.equal(cleanPath(' /Exhibitors '), 'exhibitors.html');
  assert.equal(cleanPath('en/'), 'en/index.html');
  assert.equal(cleanPath(''), 'index.html');
  assert.equal(cleanPath('en/timetable.html'), 'en/timetable.html');
  assert.equal(cleanPath('a/b/c/d.html'), 'a/b/c/d.html');
  for (const bad of ['a/b/c/d/e.html', 'media/x.html', 'blog/', '../x.html', 'a b.html', 'a_b.html', '出展.html', 'a//b.html']) assert.equal(cleanPath(bad), null, bad);
});

test("a page's address leaves off a last index.html", () => {
  assert.equal(addressOf('index.html'), '');
  assert.equal(addressOf('en/index.html'), 'en/');
  assert.equal(addressOf('exhibitors.html'), 'exhibitors.html');
  assert.equal(addressOf('myindex.html'), 'myindex.html');
});

test("a new page's id comes from its path and is never another's", () => {
  assert.equal(pageIdFor('index.html'), 'top');
  assert.equal(pageIdFor('en/index.html'), 'en-index');
  assert.equal(pageIdFor('exhibitors.html', new Set(['exhibitors', 'exhibitors-2'])), 'exhibitors-3');
  assert.equal(pageIdFor('part-header.html'), 'page-part-header');
  const long = pageIdFor(`${'a'.repeat(64)}/${'b'.repeat(64)}.html`);
  assert.match(long, PAGE_ID);
  assert.ok(!isPart(long) && isPart(HEADER) && isPart(FOOTER));
});

test("the header's and footer's first sources read back as pages", () => {
  const schema = JSON.parse(readFileSync(new URL('../source/schema.json', import.meta.url), 'utf8'));
  const sf = createSourceFormat(schema);
  for (const id of [HEADER, FOOTER]) {
    const source = partTemplate(id, '合成の祭 <&>');
    assert.ok(sf.parse(source));
    assert.ok(source.includes('合成の祭 &lt;&amp;&gt;'));
  }
  assert.ok(partTemplate(HEADER, 'x').includes('<Link href="/">x</Link>'));
  // A blog's header links to where the blog is seen, or to nothing (blog.md §8).
  assert.ok(partTemplate(HEADER, 'x', 'https://fest.example/blog/').includes('<Link href="https://fest.example/blog/">x</Link>'));
  assert.ok(partTemplate(HEADER, 'x', null).includes('<Text size="lg">x</Text>'));
});
