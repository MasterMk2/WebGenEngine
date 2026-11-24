// Page source format: parser, serializers and edit operations.
// Spec: docs/design/source-format.md. Behaviour must match php/SourceFormat.php;
// both are checked against ../fixtures by their run-fixtures scripts.

export class SourceError extends Error {
  constructor(code, line, column, message) {
    super(`${code} (${line}:${column}) ${message}`);
    this.code = code;
    this.line = line;
    this.column = column;
  }
}

export class EditError extends Error {
  constructor(code, message) {
    super(`${code} ${message}`);
    this.code = code;
  }
}

const BLOCK_KINDS = new Set(['container', 'text', 'void', 'raw']);
const INLINE_KINDS = new Set(['inline', 'inline-void']);
const NAME_START = /[A-Za-z]/;
const NAME_CHAR = /[A-Za-z0-9]/;
const ATTR_CHAR = /[A-Za-z0-9-]/;
const NAMED_ENTITIES = { amp: '&', lt: '<', gt: '>', quot: '"', apos: "'" };
const LONE_CR = /\r(?!\n)/;

function isWs(ch) {
  return ch === ' ' || ch === '\t' || ch === '\n' || ch === '\r';
}

// Wide characters around which a line break disappears (spec §6).
function isWide(cp) {
  return (cp >= 0x1100 && cp <= 0x115f) || (cp >= 0x2e80 && cp <= 0xa4cf) ||
    (cp >= 0xac00 && cp <= 0xd7a3) || (cp >= 0xf900 && cp <= 0xfaff) ||
    (cp >= 0xfe30 && cp <= 0xfe4f) || (cp >= 0xff00 && cp <= 0xffef) ||
    (cp >= 0x20000 && cp <= 0x3fffd);
}

function normalizeWhitespace(raw) {
  return raw.replace(/[ \t\r\n]+/g, (run, offset) => {
    if (!run.includes('\n')) return ' ';
    const before = offset > 0 ? raw.codePointAt(offset - 1 - (isLowSurrogate(raw, offset - 1) ? 1 : 0)) : undefined;
    const after = raw.codePointAt(offset + run.length);
    return (before !== undefined && isWide(before)) || (after !== undefined && isWide(after)) ? '' : ' ';
  });
}

function isLowSurrogate(str, i) {
  const c = str.charCodeAt(i);
  return c >= 0xdc00 && c <= 0xdfff && i > 0;
}

function trimEdges(children) {
  const first = children[0];
  if (first && first.type === 'text') first.value = first.value.replace(/^ +/, '');
  const last = children[children.length - 1];
  if (last && last.type === 'text') last.value = last.value.replace(/ +$/, '');
  return children.filter((c) => c.type !== 'text' || c.value !== '');
}

function escapeText(s) {
  return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

function escapeAttr(s) {
  return escapeText(s).replace(/"/g, '&quot;');
}

export function createSourceFormat(schema) {
  const components = new Map(schema.components.map((c) => [c.name, c]));
  const spaceTokens = new Set(schema.tokens.space);
  const urlSchemes = new Set(schema.urlSchemes);
  const embedSources = schema.embedSources ?? [];

  // An Embed's address starts with one of the listed prefixes; "/" means a page of this site,
  // never "//" (another host). A backslash is refused before this: browsers read it as "/".
  const embedAllowed = (value) => embedSources.some((s) => (s === '/' ? value.startsWith('/') && !value.startsWith('//') : value.startsWith(s)));

  // Returns null when valid, otherwise [code, message].
  function checkValue(attr, value) {
    if (LONE_CR.test(value)) return ['E_LONE_CR', '改行は \\n か \\r\\n にしてください'];
    switch (attr.type) {
      case 'space':
        return spaceTokens.has(value) ? null : ['E_INVALID_VALUE', `${attr.name} には ${schema.tokens.space.join(' / ')} のどれかを指定してください`];
      case 'enum':
        return attr.values.includes(value) ? null : ['E_INVALID_VALUE', `${attr.name} には ${attr.values.join(' / ')} のどれかを指定してください`];
      case 'int': {
        const ok = /^(0|[1-9][0-9]*)$/.test(value) && Number(value) >= attr.min && Number(value) <= attr.max;
        return ok ? null : ['E_INVALID_VALUE', `${attr.name} には ${attr.min} から ${attr.max} の整数を指定してください`];
      }
      case 'url': {
        if (value === '' || /[\x00-\x20\x7f]/.test(value)) return ['E_INVALID_VALUE', `${attr.name} に空白や制御文字は使えません`];
        const m = /^([A-Za-z][A-Za-z0-9+.-]*):/.exec(value);
        if (m && !urlSchemes.has(m[1].toLowerCase())) return ['E_UNSAFE_URL', `${attr.name} に ${m[1]}: は使えません`];
        return null;
      }
      case 'embed':
        if (value === '' || /[\x00-\x20\x7f\\]/.test(value)) return ['E_INVALID_VALUE', `${attr.name} に空白・制御文字・\\ は使えません`];
        return embedAllowed(value) ? null : ['E_UNSAFE_URL', `${attr.name} には、決まった地図のサービスか、このサイトのページだけを埋め込めます`];
      default:
        return null;
    }
  }

  function orderProps(comp, props) {
    const ordered = {};
    for (const attr of comp.attributes) {
      if (Object.prototype.hasOwnProperty.call(props, attr.name)) ordered[attr.name] = props[attr.name];
    }
    return ordered;
  }

  function hasChildren(kind) {
    return kind === 'container' || kind === 'text' || kind === 'inline';
  }

  // ---------------------------------------------------------------- parser

  function parse(src) {
    let pos = src.charCodeAt(0) === 0xfeff ? 1 : 0;
    const bom = pos;

    function lineCol(offset) {
      let line = 1;
      let lineStart = bom;
      for (let i = bom; i < offset; i++) {
        if (src.charCodeAt(i) === 10) {
          line++;
          lineStart = i + 1;
        }
      }
      let column = 1;
      for (let i = lineStart; i < offset; i++) {
        const c = src.charCodeAt(i);
        if (c < 0xdc00 || c > 0xdfff) column++;
      }
      return [line, column];
    }

    function fail(code, offset, message) {
      const [line, column] = lineCol(offset);
      throw new SourceError(code, line, column, message);
    }

    const lone = LONE_CR.exec(src);
    if (lone) fail('E_LONE_CR', lone.index, '改行は \\n か \\r\\n で書いてください');

    const nlMatch = /\r?\n/.exec(src);
    const doc = {
      type: 'document',
      children: [],
      newline: nlMatch ? nlMatch[0] : '\n',
      source: src,
    };

    const startsWith = (s) => src.startsWith(s, pos);

    function readName(re) {
      const start = pos;
      while (pos < src.length && re.test(src[pos])) pos++;
      return src.slice(start, pos);
    }

    function decodeEntity() {
      const start = pos;
      const m = /^&(?:([a-z]+)|#([0-9]{1,7})|#x([0-9a-fA-F]{1,6}));/.exec(src.slice(pos, pos + 12));
      if (!m) fail('E_BAD_ENTITY', start, '& は &amp; と書いてください');
      let ch;
      if (m[1] !== undefined) {
        ch = NAMED_ENTITIES[m[1]];
        if (ch === undefined) fail('E_BAD_ENTITY', start, `&${m[1]}; は使えません`);
      } else {
        const cp = m[2] !== undefined ? parseInt(m[2], 10) : parseInt(m[3], 16);
        if (cp === 0 || cp > 0x10ffff || (cp >= 0xd800 && cp <= 0xdfff)) fail('E_BAD_ENTITY', start, '使えない文字番号です');
        ch = String.fromCodePoint(cp);
      }
      pos += m[0].length;
      return ch;
    }

    function parseComment() {
      const start = pos;
      const end = src.indexOf('-->', pos + 4);
      if (end < 0) fail('E_UNCLOSED_COMMENT', start, 'コメントが --> で閉じられていません');
      pos = end + 3;
      return { type: 'comment', value: src.slice(start + 4, end).replace(/\r\n/g, '\n'), span: [start, pos] };
    }

    function parseClose(name) {
      const start = pos;
      pos += 2;
      const closeName = readName(NAME_CHAR);
      while (pos < src.length && isWs(src[pos])) pos++;
      if (src[pos] !== '>') fail('E_UNEXPECTED_CHAR', pos, '終了タグは </名前> と書いてください');
      if (closeName !== name) {
        fail(name === null ? 'E_UNEXPECTED_CLOSE' : 'E_MISMATCHED_CLOSE', start,
          name === null ? `対応する <${closeName}> がありません` : `</${name}> が来るはずの場所に </${closeName}> があります`);
      }
      pos++;
      return start;
    }

    // Parses block children into `parent` until `</closeName>` (or EOF for the document).
    function parseBlockChildren(parent, closeName, parentStart) {
      let index = 0;
      for (;;) {
        const wsStart = pos;
        while (pos < src.length && isWs(src[pos])) pos++;
        const leading = src.slice(wsStart, pos);
        if (pos >= src.length) {
          if (closeName !== null) fail('E_UNCLOSED_TAG', parentStart, `<${closeName}> が閉じられていません`);
          parent.innerTrailing = leading;
          parent.origCount = index;
          return null;
        }
        if (startsWith('</')) {
          if (closeName === null) parseClose(null);
          parent.innerTrailing = leading;
          parent.origCount = index;
          return parseClose(closeName);
        }
        let node;
        if (startsWith('<!--')) node = parseComment();
        else if (src[pos] === '<') node = parseElement('block', new Set());
        else fail('E_TEXT_IN_BLOCK', pos, '文字は Text などの部品の中に書いてください');
        node.leading = leading;
        node.origIndex = index++;
        parent.children.push(node);
      }
    }

    function parseInline(el, closeName, excluded) {
      const parts = [];
      for (;;) {
        if (pos >= src.length) fail('E_UNCLOSED_TAG', el.span[0], `<${closeName}> が閉じられていません`);
        if (startsWith('</')) {
          const closeStart = parseClose(closeName);
          return { parts, closeStart };
        }
        if (startsWith('<!--')) fail('E_COMMENT_IN_INLINE', pos, '文の途中にはコメントを書けません');
        if (src[pos] === '<') {
          parts.push(parseElement('inline', excluded));
          continue;
        }
        let value = '';
        while (pos < src.length && src[pos] !== '<') {
          if (src[pos] === '&') value += decodeEntity();
          else value += src[pos++];
        }
        parts.push({ type: 'text', value: normalizeWhitespace(value) });
      }
    }

    function parseElement(context, excluded) {
      const start = pos;
      pos++;
      if (!NAME_START.test(src[pos] ?? '')) fail('E_UNEXPECTED_CHAR', pos, '< の後には部品の名前を書いてください');
      const name = readName(NAME_CHAR);
      const comp = components.get(name);
      if (!comp) {
        const hint = /^[a-z]/.test(name) ? '（HTML のタグはそのままでは使えません。部品の名前は大文字で始まります）' : '';
        fail('E_UNKNOWN_COMPONENT', start, `<${name}> という部品はありません${hint}`);
      }
      const allowed = context === 'block' ? BLOCK_KINDS : INLINE_KINDS;
      if (!allowed.has(comp.kind) || excluded.has(name)) fail('E_NOT_ALLOWED_HERE', start, `<${name}> はここには置けません`);

      const attrs = new Map(comp.attributes.map((a) => [a.name, a]));
      const props = {};
      let selfClosing = false;
      for (;;) {
        const beforeWs = pos;
        while (pos < src.length && isWs(src[pos])) pos++;
        if (pos >= src.length) fail('E_EOF_IN_TAG', start, `<${name}> のタグが > で閉じられていません`);
        if (startsWith('/>')) {
          pos += 2;
          selfClosing = true;
          break;
        }
        if (src[pos] === '>') {
          pos++;
          break;
        }
        if (beforeWs === pos || !NAME_START.test(src[pos])) fail('E_UNEXPECTED_CHAR', pos, 'タグの書き方が正しくありません');
        const nameStart = pos;
        const attrName = readName(ATTR_CHAR);
        const attr = attrs.get(attrName);
        if (!attr) fail('E_UNKNOWN_ATTRIBUTE', nameStart, `<${name}> に ${attrName} という属性はありません`);
        if (Object.prototype.hasOwnProperty.call(props, attrName)) fail('E_DUPLICATE_ATTRIBUTE', nameStart, `${attrName} が 2 回書かれています`);
        if (src[pos] !== '=') fail('E_UNQUOTED_ATTRIBUTE', pos, `${attrName}="値" の形で書いてください`);
        pos++;
        if (src[pos] !== '"') fail('E_UNQUOTED_ATTRIBUTE', pos, `値は " で囲んでください`);
        const valueStart = pos;
        pos++;
        let value = '';
        for (;;) {
          if (pos >= src.length) fail('E_EOF_IN_TAG', start, `<${name}> のタグが > で閉じられていません`);
          if (src[pos] === '"') break;
          if (src[pos] === '&') value += decodeEntity();
          else value += src[pos++];
        }
        pos++;
        const problem = checkValue(attr, value);
        if (problem) fail(problem[0], valueStart, problem[1]);
        props[attrName] = value;
      }
      for (const attr of comp.attributes) {
        if (attr.required && !Object.prototype.hasOwnProperty.call(props, attr.name)) {
          fail('E_MISSING_ATTRIBUTE', start, `<${name}> には ${attr.name} が必要です`);
        }
      }
      const kind = comp.kind;
      if ((kind === 'void' || kind === 'inline-void') && !selfClosing) fail('E_VOID_NOT_SELF_CLOSED', start, `<${name} … /> のように /> で閉じてください`);

      const el = { type: name, props: orderProps(comp, props), span: [start, 0], openEnd: pos, selfClosing };
      if (hasChildren(kind)) el.children = [];
      if (kind === 'raw') el.raw = '';

      if (!selfClosing) {
        if (kind === 'container') {
          el.closeStart = parseBlockChildren(el, name, start);
        } else if (kind === 'raw') {
          const close = src.indexOf('</Html>', pos);
          if (close < 0) fail('E_UNCLOSED_RAW', start, '<Html> が </Html> で閉じられていません');
          el.raw = src.slice(pos, close).replace(/\r\n/g, '\n');
          el.closeStart = close;
          pos = close + '</Html>'.length;
        } else {
          const inner = new Set(excluded);
          for (const x of comp.excludes ?? []) inner.add(x);
          const { parts, closeStart } = parseInline(el, name, inner);
          el.children = kind === 'text' ? trimEdges(parts) : parts.filter((p) => p.type !== 'text' || p.value !== '');
          el.closeStart = closeStart;
        }
      } else if (kind === 'container') {
        el.origCount = 0;
      }
      el.span[1] = pos;
      return el;
    }

    parseBlockChildren(doc, null, 0);
    return doc;
  }

  // ------------------------------------------------------------- serialize

  function openTag(node, selfClose) {
    let s = `<${node.type}`;
    for (const [k, v] of Object.entries(node.props)) s += ` ${k}="${escapeAttr(v)}"`;
    return s + (selfClose ? ' />' : '>');
  }

  function inlineContent(children) {
    return children.map((c) => (c.type === 'text' ? escapeText(c.value) : canonicalNode(c, '', '\n'))).join('');
  }

  function canonicalNode(node, indent, nl) {
    if (node.type === 'comment') return `<!--${node.value.replace(/\n/g, nl)}-->`;
    const kind = components.get(node.type).kind;
    if (kind === 'void' || kind === 'inline-void') return openTag(node, true);
    if (kind === 'raw') return `${openTag(node, false)}${node.raw.replace(/\n/g, nl)}</Html>`;
    if (node.children.length === 0) return openTag(node, true);
    if (kind === 'container') {
      const inner = indent + '  ';
      return openTag(node, false) + node.children.map((c) => nl + inner + canonicalNode(c, inner, nl)).join('') + `${nl}${indent}</${node.type}>`;
    }
    return `${openTag(node, false)}${inlineContent(node.children)}</${node.type}>`;
  }

  function serializeCanonical(doc) {
    return doc.children.map((c) => canonicalNode(c, '', '\n') + '\n').join('');
  }

  // Leading whitespace for a child that has none of its own (inserted or moved).
  function leadingFor(parent, i, parentIndent, nl) {
    const isDoc = parent.type === 'document';
    if (isDoc && i === 0) return '';
    const kids = parent.children;
    const candidates = [kids[i - 1], kids[i + 1]].concat(kids);
    for (const c of candidates) {
      if (c && typeof c.leading === 'string' && c.leading.includes('\n')) return c.leading;
    }
    return nl + (isDoc ? '' : parentIndent + '  ');
  }

  function indentOf(lead, fallback) {
    const i = lead.lastIndexOf('\n');
    return i < 0 ? fallback : lead.slice(i + 1);
  }

  function serialize(doc) {
    const src = doc.source;
    if (typeof src !== 'string') return serializeCanonical(doc);
    const nl = doc.newline;

    function children(parent, indent) {
      const isDoc = parent.type === 'document';
      let out = '';
      parent.children.forEach((c, i) => {
        let lead = c.leading;
        if (isDoc && i === 0 && c.origIndex !== 0) {
          lead = '';
        } else if (typeof lead !== 'string' || (isDoc && i > 0 && lead === '' && c.origIndex === 0)) {
          lead = leadingFor(parent, i, indent, nl);
        }
        out += lead + node(c, indentOf(lead, isDoc ? '' : indent + '  '));
      });
      return out;
    }

    function trailing(parent, indent) {
      if (parent.children.length > 0 && (parent.origCount === 0 || typeof parent.innerTrailing !== 'string')) return nl + indent;
      return parent.innerTrailing ?? '';
    }

    function node(n, indent) {
      if (!n.span) return canonicalNode(n, indent, nl);
      if (!n.dirty && !n.childrenDirty && !n.contentDirty) return src.slice(n.span[0], n.span[1]);
      const kind = components.get(n.type).kind;
      if (kind === 'void' || kind === 'inline-void') return openTag(n, true);
      const empty = kind === 'raw' ? false : n.children.length === 0;
      if (n.selfClosing && empty) return openTag(n, true);
      const open = n.dirty || n.selfClosing ? openTag(n, false) : src.slice(n.span[0], n.openEnd);
      const close = n.selfClosing ? `</${n.type}>` : src.slice(n.closeStart, n.span[1]);
      let inner;
      if (kind === 'container') {
        inner = empty ? '' : children(n, indent) + trailing(n, indent);
      } else if (kind === 'raw') {
        inner = n.contentDirty ? n.raw.replace(/\n/g, nl) : src.slice(n.openEnd, n.closeStart);
      } else {
        // childrenDirty: an inline descendant (Link, Strong…) was edited.
        inner = n.contentDirty || n.childrenDirty || n.selfClosing ? inlineContent(n.children) : src.slice(n.openEnd, n.closeStart);
      }
      return open + inner + close;
    }

    if (!doc.childrenDirty) return src;
    const body = children(doc, '');
    if (doc.children.length > 0 && doc.origCount === 0) return body + nl;
    return body + (doc.innerTrailing ?? '');
  }

  // ------------------------------------------------------------- JSON tree

  function toJSON(n) {
    if (n.type === 'document') return { type: 'document', children: n.children.map(toJSON) };
    if (n.type === 'text' || n.type === 'comment') return { type: n.type, value: n.value };
    const out = { type: n.type, props: { ...n.props } };
    if (n.children) out.children = n.children.map(toJSON);
    if (n.raw !== undefined) out.raw = n.raw;
    return out;
  }

  function fromJSON(json, context, excluded) {
    if (json === null || typeof json !== 'object') throw new EditError('E_INVALID_NODE', 'ノードはオブジェクトで渡してください');
    if (json.type === 'comment') {
      if (context !== 'block') throw new EditError('E_COMMENT_IN_INLINE', '文の途中にはコメントを置けません');
      // A trailing "-" would read back as part of the closing "-->".
      if (typeof json.value !== 'string' || json.value.includes('-->') || json.value.endsWith('-')) {
        throw new EditError('E_INVALID_VALUE', 'コメントに --> は含められず、- で終われません');
      }
      if (LONE_CR.test(json.value)) throw new EditError('E_LONE_CR', '改行は \\n か \\r\\n にしてください');
      return { type: 'comment', value: json.value.replace(/\r\n/g, '\n') };
    }
    if (json.type === 'text') {
      if (context !== 'inline') throw new EditError('E_TEXT_IN_BLOCK', '文字は Text などの部品の中に置いてください');
      if (typeof json.value !== 'string') throw new EditError('E_INVALID_VALUE', 'value は文字列で渡してください');
      return { type: 'text', value: normalizeWhitespace(json.value) };
    }
    const comp = components.get(json.type);
    if (!comp) throw new EditError('E_UNKNOWN_COMPONENT', `${json.type} という部品はありません`);
    const allowed = context === 'block' ? BLOCK_KINDS : INLINE_KINDS;
    if (!allowed.has(comp.kind) || excluded.has(comp.name)) throw new EditError('E_NOT_ALLOWED_HERE', `${comp.name} はここには置けません`);
    const props = json.props ?? {};
    for (const [k, v] of Object.entries(props)) {
      const attr = comp.attributes.find((a) => a.name === k);
      if (!attr) throw new EditError('E_UNKNOWN_ATTRIBUTE', `${comp.name} に ${k} という属性はありません`);
      if (typeof v !== 'string') throw new EditError('E_INVALID_VALUE', `${k} は文字列で渡してください`);
      const problem = checkValue(attr, v);
      if (problem) throw new EditError(problem[0], problem[1]);
    }
    for (const attr of comp.attributes) {
      if (attr.required && !Object.prototype.hasOwnProperty.call(props, attr.name)) throw new EditError('E_MISSING_ATTRIBUTE', `${comp.name} には ${attr.name} が必要です`);
    }
    const node = { type: comp.name, props: orderProps(comp, props) };
    if (comp.kind === 'raw') node.raw = checkRaw(json.raw ?? '');
    if (comp.kind === 'container') node.children = (json.children ?? []).map((c) => fromJSON(c, 'block', new Set()));
    if (comp.kind === 'text' || comp.kind === 'inline') node.children = inlineFromJSON(comp, json.children ?? [], excluded);
    return node;
  }

  function inlineFromJSON(comp, list, excluded) {
    if (!Array.isArray(list)) throw new EditError('E_INVALID_NODE', 'children は配列で渡してください');
    const inner = new Set(excluded);
    for (const x of comp.excludes ?? []) inner.add(x);
    // Adjacent text would merge on reparse, so merge it here to keep the tree stable.
    const kids = [];
    for (const c of list.map((x) => fromJSON(x, 'inline', inner))) {
      const prev = kids[kids.length - 1];
      if (c.type === 'text' && prev && prev.type === 'text') prev.value = normalizeWhitespace(prev.value + c.value);
      else kids.push(c);
    }
    const nonEmpty = kids.filter((c) => c.type !== 'text' || c.value !== '');
    return comp.kind === 'text' ? trimEdges(nonEmpty) : nonEmpty;
  }

  function checkRaw(raw) {
    if (typeof raw !== 'string' || raw.includes('</Html>')) throw new EditError('E_INVALID_VALUE', 'Html の中身に </Html> は含められません');
    if (LONE_CR.test(raw)) throw new EditError('E_LONE_CR', '改行は \\n か \\r\\n にしてください');
    return raw.replace(/\r\n/g, '\n');
  }

  // ----------------------------------------------------------------- edits

  function walk(doc, path) {
    const chain = [doc];
    let n = doc;
    for (const i of path) {
      if (!n.children || !Number.isInteger(i) || i < 0 || i >= n.children.length) throw new EditError('E_BAD_PATH', `位置 ${JSON.stringify(path)} に部品がありません`);
      n = n.children[i];
      chain.push(n);
    }
    return chain;
  }

  function markAncestors(chain) {
    for (const n of chain) n.childrenDirty = true;
  }

  function blockParent(doc, path) {
    const chain = walk(doc, path);
    const parent = chain[chain.length - 1];
    if (parent.type !== 'document' && components.get(parent.type)?.kind !== 'container') {
      throw new EditError('E_NOT_ALLOWED_HERE', `${parent.type} には子を置けません`);
    }
    return chain;
  }

  function elementAt(doc, path) {
    const chain = walk(doc, path);
    const n = chain[chain.length - 1];
    const comp = components.get(n.type);
    if (!comp) throw new EditError('E_BAD_PATH', `位置 ${JSON.stringify(path)} は部品ではありません`);
    return { chain, n, comp };
  }

  // Excludes inherited from inline ancestors, so setInline cannot nest Link in Link.
  function inheritedExcludes(chain) {
    const set = new Set();
    for (const n of chain) {
      for (const x of components.get(n.type)?.excludes ?? []) set.add(x);
    }
    return set;
  }

  const edit = {
    setProp(doc, path, name, value) {
      const { chain, n, comp } = elementAt(doc, path);
      const attr = comp.attributes.find((a) => a.name === name);
      if (!attr) throw new EditError('E_UNKNOWN_ATTRIBUTE', `${comp.name} に ${name} という属性はありません`);
      if (value === null) {
        if (attr.required) throw new EditError('E_MISSING_ATTRIBUTE', `${name} は消せません`);
        delete n.props[name];
      } else {
        if (typeof value !== 'string') throw new EditError('E_INVALID_VALUE', `${name} は文字列で渡してください`);
        const problem = checkValue(attr, value);
        if (problem) throw new EditError(problem[0], problem[1]);
        n.props = orderProps(comp, { ...n.props, [name]: value });
      }
      n.dirty = true;
      markAncestors(chain.slice(0, -1));
    },
    insert(doc, parentPath, index, json) {
      const chain = blockParent(doc, parentPath);
      const parent = chain[chain.length - 1];
      if (!Number.isInteger(index) || index < 0 || index > parent.children.length) throw new EditError('E_BAD_PATH', `位置 ${index} には入れられません`);
      parent.children.splice(index, 0, fromJSON(json, 'block', new Set()));
      markAncestors(chain);
    },
    remove(doc, path) {
      if (path.length === 0) throw new EditError('E_BAD_PATH', 'ページそのものは消せません');
      const chain = blockParent(doc, path.slice(0, -1));
      walk(doc, path);
      chain[chain.length - 1].children.splice(path[path.length - 1], 1);
      markAncestors(chain);
    },
    move(doc, fromPath, parentPath, index) {
      if (fromPath.length === 0) throw new EditError('E_BAD_PATH', 'ページそのものは動かせません');
      const fromChain = blockParent(doc, fromPath.slice(0, -1));
      const moving = walk(doc, fromPath)[fromPath.length];
      const fromParent = fromChain[fromChain.length - 1];
      fromParent.children.splice(fromPath[fromPath.length - 1], 1);
      let toChain;
      try {
        toChain = blockParent(doc, parentPath);
        if (toChain.includes(moving)) throw new EditError('E_BAD_PATH', '部品を自分の中には動かせません');
        const to = toChain[toChain.length - 1];
        if (!Number.isInteger(index) || index < 0 || index > to.children.length) throw new EditError('E_BAD_PATH', `位置 ${index} には入れられません`);
        delete moving.leading;
        to.children.splice(index, 0, moving);
      } catch (e) {
        fromParent.children.splice(fromPath[fromPath.length - 1], 0, moving);
        throw e;
      }
      markAncestors(fromChain);
      markAncestors(toChain);
    },
    setInline(doc, path, list) {
      const { chain, n, comp } = elementAt(doc, path);
      if (comp.kind !== 'text' && comp.kind !== 'inline') throw new EditError('E_NOT_ALLOWED_HERE', `${comp.name} は文を持てません`);
      n.children = inlineFromJSON(comp, list, inheritedExcludes(chain.slice(0, -1)));
      n.contentDirty = true;
      markAncestors(chain.slice(0, -1));
    },
    setRaw(doc, path, raw) {
      const { chain, n, comp } = elementAt(doc, path);
      if (comp.kind !== 'raw') throw new EditError('E_NOT_ALLOWED_HERE', `${comp.name} は Html ではありません`);
      n.raw = checkRaw(raw);
      n.contentDirty = true;
      markAncestors(chain.slice(0, -1));
    },
  };

  return { parse, serialize, serializeCanonical, toJSON, edit };
}
