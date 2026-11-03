// Sanitizing the content of an Html block for publishing (docs/design/rendering.md §7).
// Behaviour must match php/HtmlSanitizer.php: both are checked against ../fixtures/render,
// and against each other by ../differential.
//
// The input is read once, left to right, and the output is written only from what was
// read: allowed tags again from their lower-cased name and allowed attributes, text with
// `<` and `>` escaped. Everything else is dropped, so nothing of the input can reach the
// output as markup. Only ASCII characters have a meaning here (no `\s`, no Unicode case
// mapping), so that UTF-16 in JS and bytes in PHP give the same result.

const WS = '\t\n\f\r ';
const NAMED = { amp: '&', lt: '<', gt: '>', quot: '"', apos: "'" };
// The character references the source format knows: the five named ones and numeric ones.
const REF = /&(?:#[xX]([0-9A-Fa-f]+);?|#([0-9]+);?|(amp|lt|gt|quot|apos);)/g;

const isLetter = (c) => c !== undefined && /^[A-Za-z]$/.test(c);
const lower = (s) => s.replace(/[A-Z]+/g, (m) => m.toLowerCase());
const skip = (s, i, set) => {
  while (i < s.length && set.includes(s[i])) i++;
  return i;
};
const until = (s, i, set) => {
  while (i < s.length && !set.includes(s[i])) i++;
  return i;
};
const escapeText = (s) => s.replace(/</g, '&lt;').replace(/>/g, '&gt;');
const escapeAttr = (s) => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

// Every `&` left after this is escaped again on output, so the browser sees exactly the
// value that was checked.
function decodeValue(v) {
  return v.replace(REF, (_, hex, dec, name) => {
    if (name) return NAMED[name];
    const digits = (hex ?? dec).replace(/^0+/, '');
    const cp = digits.length > 8 ? 0x110000 : parseInt(digits || '0', hex ? 16 : 10);
    return cp === 0 || cp > 0x10ffff || (cp >= 0xd800 && cp <= 0xdfff) ? '�' : String.fromCodePoint(cp);
  });
}

// Reads a tag whose name starts at `i` (after `<` or `</`). Returns null when the input
// ends inside the tag; browsers drop such a tag.
function readTag(s, i) {
  const nameEnd = until(s, i, `${WS}/>`);
  const name = lower(s.slice(i, nameEnd));
  const attrs = [];
  i = nameEnd;
  for (;;) {
    i = skip(s, i, `${WS}/`);
    if (i >= s.length) return null;
    if (s[i] === '>') return { name, attrs, end: i + 1 };
    // The first character may be `=`, as in browsers.
    const attrEnd = until(s, i + 1, `${WS}/>=`);
    const attr = lower(s.slice(i, attrEnd));
    i = skip(s, attrEnd, WS);
    let value = '';
    if (s[i] === '=') {
      i = skip(s, i + 1, WS);
      const q = s[i];
      if (q === '"' || q === "'") {
        const close = s.indexOf(q, i + 1);
        if (close < 0) return null;
        value = s.slice(i + 1, close);
        i = close + 1;
      } else {
        const valueEnd = until(s, i, `${WS}>`);
        value = s.slice(i, valueEnd);
        i = valueEnd;
      }
    }
    attrs.push([attr, value]);
  }
}

// `policy` is the `sanitize` entry of a raw component in schema.json.
export function createSanitizer(policy, urlSchemes) {
  const schemes = new Set(urlSchemes);

  // The same rule as attributes of type `url` in the source format, after trimming the
  // spaces that browsers trim too.
  function safeUrl(v) {
    const t = v.replace(/^[\t\n\f\r ]+|[\t\n\f\r ]+$/g, '');
    if (t === '' || /[\x00-\x20\x7f]/.test(t)) return null;
    const m = /^([A-Za-z][A-Za-z0-9+.-]*):/.exec(t);
    return m && !schemes.has(lower(m[1])) ? null : t;
  }

  function startTag(tag) {
    const allowed = policy.elements[tag.name];
    const seen = new Set();
    let out = `<${tag.name}`;
    for (const [name, raw] of tag.attrs) {
      // Browsers keep the first of repeated attributes.
      if (seen.has(name)) continue;
      seen.add(name);
      if (!allowed.includes(name) && !policy.globalAttributes.includes(name)) continue;
      let value = decodeValue(raw);
      if (policy.urlAttributes.includes(name)) {
        value = safeUrl(value);
        if (value === null) continue;
      }
      out += ` ${name}="${escapeAttr(value)}"`;
    }
    return `${out}>`;
  }

  // Where the end tag of an element whose content is dropped starts, or the end of input.
  function contentEnd(low, name, from) {
    const needle = `</${name}`;
    for (let at = low.indexOf(needle, from); at >= 0; at = low.indexOf(needle, at + 1)) {
      const next = low[at + needle.length];
      if (next === undefined || `${WS}/>`.includes(next)) return at;
    }
    return low.length;
  }

  return function sanitize(s) {
    const low = lower(s);
    const open = [];
    let out = '';
    let i = 0;
    while (i < s.length) {
      const lt = s.indexOf('<', i);
      if (lt < 0) {
        out += escapeText(s.slice(i));
        break;
      }
      out += escapeText(s.slice(i, lt));
      i = lt;
      if (s.startsWith('<!--', i)) {
        // From i + 2, so that `<!-->` and `<!--->` end at once, as in browsers.
        const end = s.indexOf('-->', i + 2);
        i = end < 0 ? s.length : end + 3;
      } else if (s[i + 1] === '!' || s[i + 1] === '?') {
        const end = s.indexOf('>', i + 2);
        i = end < 0 ? s.length : end + 1;
      } else if (isLetter(s[i + 1]) || (s[i + 1] === '/' && isLetter(s[i + 2]))) {
        const closing = s[i + 1] === '/';
        const tag = readTag(s, i + (closing ? 2 : 1));
        if (tag === null) break;
        i = tag.end;
        if (closing) {
          const at = open.lastIndexOf(tag.name);
          if (at >= 0) while (open.length > at) out += `</${open.pop()}>`;
        } else if (policy.dropContent.includes(tag.name)) {
          i = contentEnd(low, tag.name, i);
        } else if (Object.prototype.hasOwnProperty.call(policy.elements, tag.name)) {
          out += startTag(tag);
          if (!policy.void.includes(tag.name)) open.push(tag.name);
        }
      } else {
        out += '&lt;';
        i++;
      }
    }
    while (open.length > 0) out += `</${open.pop()}>`;
    return out;
  };
}
