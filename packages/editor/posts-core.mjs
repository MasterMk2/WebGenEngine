// Small pure helpers for the list of pages (posts.mjs) and the editor: page IDs, a new post's
// first source, titles, and making a photo smaller before it is sent. Spec: docs/design/blog.md.

export const PAGE_ID = /^[a-z0-9][a-z0-9-]{0,63}$/;
export const MAX_TITLE = 120;

/** A new post's ID: its date and four random hex digits, such as 20261123-a3f9. */
export function newPostId(date, random = crypto.getRandomValues(new Uint8Array(2))) {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(date)) throw new Error('date must be YYYY-MM-DD');
  return `${date.replaceAll('-', '')}-${[...random].map((b) => b.toString(16).padStart(2, '0')).join('')}`;
}

/** Today's date in the given time zone, as YYYY-MM-DD. */
export function today(now = new Date(), timeZone = 'Asia/Tokyo') {
  const parts = Object.fromEntries(new Intl.DateTimeFormat('en', { timeZone, year: 'numeric', month: '2-digit', day: '2-digit' }).formatToParts(now).map((p) => [p.type, p.value]));
  return `${parts.year}-${parts.month}-${parts.day}`;
}

/** Text and attribute values of the source format: & < > and " as character references. */
export function sourceText(s) {
  return String(s).replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;');
}

/** A new post's first source: its title as the heading, and a paragraph to write over. */
export function postTemplate(title) {
  return [
    '<Section padding="lg" width="narrow">',
    '  <Stack gap="md">',
    `    <Heading level="1">${sourceText(title)}</Heading>`,
    '    <Text>ここに本文を書きます。</Text>',
    '  </Stack>',
    '</Section>',
    '',
  ].join('\n');
}

/** A title the server takes (one line, 1–120 characters), trimmed; null otherwise. */
export function cleanTitle(value) {
  const title = String(value ?? '').trim();
  // eslint-disable-next-line no-control-regex
  return title !== '' && [...title].length <= MAX_TITLE && !/[\u0000-\u001f\u007f]/.test(title) ? title : null;
}

/** The size to draw a photo at so its longer side is at most `max` (never larger than it is). */
export function fitWithin(width, height, max) {
  const scale = Math.min(1, max / Math.max(width, height));
  return { width: Math.max(1, Math.round(width * scale)), height: Math.max(1, Math.round(height * scale)) };
}

// ------------------------------------------------ a site of pages (docs/design/site.md)

/** A site's page path as the server takes it: lower-case names joined by "/", ending in .html. */
export const SITE_PATH = /^(?:[a-z0-9][a-z0-9-]{0,63}\/){0,3}[a-z0-9][a-z0-9-]{0,63}\.html$/;
const RESERVED_FOLDERS = ['media', 'blog'];
export const HEADER = 'part-header';
export const FOOTER = 'part-footer';
export const isPart = (id) => id === HEADER || id === FOOTER;

/**
 * A path typed by a person, made the way the server keeps it, or null: "/Exhibitors" →
 * "exhibitors.html", "en/" → "en/index.html".
 */
export function cleanPath(value) {
  let path = String(value ?? '').trim().toLowerCase().replace(/^\/+/, '');
  if (path === '' || path.endsWith('/')) path += 'index.html';
  if (!path.endsWith('.html')) path += '.html';
  return SITE_PATH.test(path) && !RESERVED_FOLDERS.includes(path.split('/')[0]) ? path : null;
}

/** The page's address under the site: a last index.html is left off (en/index.html → en/). */
export function addressOf(path) {
  return String(path).replace(/(^|\/)index\.html$/, '$1');
}

/** A link's address for a page of the site, from the site's root (site.md §3.1): publishing makes
 *  it relative to the page that links. index.html → /, en/index.html → /en/, about.html → /about.html. */
export function pageHref(path) {
  return `/${addressOf(path)}`;
}

/** A new page's id from its path, not one of `taken`: index.html → top, en/index.html → en-index. */
export function pageIdFor(path, taken = new Set()) {
  let base = path.replace(/\.html$/, '').replaceAll('/', '-').slice(0, 56).replace(/-+$/, '');
  if (base === 'index') base = 'top';
  // part-… are the site's header and footer.
  if (base.startsWith('part-')) base = `page-${base}`.slice(0, 56);
  let id = base;
  for (let n = 2; taken.has(id); n++) id = `${base}-${n}`;
  return id;
}

/** The first source of the site's header or footer: the site's name, linking to the top page. */
export function partTemplate(id, siteName) {
  const name = sourceText(siteName);
  if (id === HEADER) {
    return ['<Section padding="sm">', '  <Stack direction="horizontal" justify="between" align="center">', `    <Text size="lg"><Link href="/">${name}</Link></Text>`, '  </Stack>', '</Section>', ''].join('\n');
  }
  return ['<Section padding="sm">', `  <Text size="sm">${name}</Text>`, '</Section>', ''].join('\n');
}

/**
 * An Embed's place from what was typed or pasted: a map service gives a whole <iframe …>, so its
 * src is taken out (with its &amp; read back as &). Anything else is the place as it was typed.
 */
export function embedSrcFrom(value) {
  const text = String(value ?? '').trim();
  const m = /<iframe\b[^>]*?\ssrc\s*=\s*(?:"([^"]*)"|'([^']*)')/i.exec(text);
  if (!m) return text;
  return (m[1] ?? m[2]).trim().replace(/&quot;/g, '"').replace(/&#39;/g, "'").replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&amp;/g, '&');
}

/** "2026-11-23" → "2026年11月23日" */
export function dateLabel(date) {
  const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(date ?? '');
  return m ? `${Number(m[1])}年${Number(m[2])}月${Number(m[3])}日` : '';
}
