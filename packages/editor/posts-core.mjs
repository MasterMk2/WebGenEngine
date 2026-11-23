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

/** "2026-11-23" → "2026年11月23日" */
export function dateLabel(date) {
  const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(date ?? '');
  return m ? `${Number(m[1])}年${Number(m[2])}月${Number(m[3])}日` : '';
}
