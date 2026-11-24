// Reads the children of an element edited in place (contenteditable) back into the
// inline nodes of the source format. The browser adds markup of its own while editing
// (<b>, <span style>, a trailing <br>), so the DOM is never trusted: only the tags the
// renderer writes for Strong, Em, Small, Link and Br are kept, and everything else is unwrapped
// to its text. Uses only nodeType, nodeName, childNodes and getAttribute, so it runs on
// the preview's DOM and on plain objects in the tests. Spec: docs/design/editor-ux.md §3.2.
// Every inline component of the schema must be here, or editing its text in place loses it.

const TEXT_NODE = 3;
const ELEMENT_NODE = 1;
const WRAPPERS = { STRONG: 'Strong', B: 'Strong', EM: 'Em', I: 'Em', SMALL: 'Small' };

export function inlineFromDom(el) {
  const list = convert(el.childNodes);
  // A <br> at the very end draws nothing; browsers leave one behind when a block is emptied.
  if (list.at(-1)?.type === 'Br') list.pop();
  return list;
}

function convert(nodes) {
  const out = [];
  for (const node of nodes) {
    if (node.nodeType === TEXT_NODE) {
      // Typed runs of spaces arrive as no-break spaces.
      out.push({ type: 'text', value: node.nodeValue.replace(/ /g, ' ') });
      continue;
    }
    if (node.nodeType !== ELEMENT_NODE) continue;
    const name = node.nodeName.toUpperCase();
    if (name === 'BR') {
      out.push({ type: 'Br', props: {} });
    } else if (WRAPPERS[name]) {
      out.push({ type: WRAPPERS[name], props: {}, children: convert(node.childNodes) });
    } else if (name === 'A' && node.getAttribute('href') !== null) {
      out.push({ type: 'Link', props: { href: node.getAttribute('href') }, children: convert(node.childNodes) });
    } else {
      out.push(...convert(node.childNodes));
    }
  }
  // Wrappers left empty by deleting their text would come back as empty tags.
  return out.filter((n) => (n.type === 'text' ? n.value !== '' : n.type === 'Br' || n.children.length > 0));
}
