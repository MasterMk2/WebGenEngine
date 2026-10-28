// Turning a drag into an intent: the drop becomes a slot (parent, index) in the tree,
// never a coordinate. Pure functions of rectangles and paths, so they run in node.
// Spec: docs/design/editor.md §3.

// Groups rects that share a line (wrapping horizontal Stack, Grid rows).
function rows(rects) {
  const out = [];
  rects.forEach((r, i) => {
    const row = out[out.length - 1];
    if (row && r.top < row.bottom) {
      row.items.push(i);
      row.bottom = Math.max(row.bottom, r.bottom);
    } else {
      out.push({ top: r.top, bottom: r.bottom, items: [i] });
    }
  });
  return out;
}

// Returns k in 0..rects.length: insert before the k-th child.
// axis: 'vertical' (Section, vertical Stack, the page) or 'flow' (horizontal Stack, Grid).
export function slotIndex(axis, rects, point) {
  if (rects.length === 0) return 0;
  if (axis === 'vertical') {
    const k = rects.findIndex((r) => point.y < (r.top + r.bottom) / 2);
    return k < 0 ? rects.length : k;
  }
  const lines = rows(rects);
  const line = lines.find((l) => point.y < l.bottom) ?? lines[lines.length - 1];
  for (const i of line.items) {
    if (point.x < (rects[i].left + rects[i].right) / 2) return i;
  }
  return line.items[line.items.length - 1] + 1;
}

// Near the leading or trailing edge of a container (along its parent's axis) the drop
// means "beside it", so the editor looks at the parent instead of nesting inside.
export function nearEdge(parentAxis, rect, point, maxBand = 24) {
  if (parentAxis === 'vertical') {
    const band = Math.min(maxBand, (rect.bottom - rect.top) * 0.25);
    return point.y < rect.top + band || point.y > rect.bottom - band;
  }
  const band = Math.min(maxBand, (rect.right - rect.left) * 0.25);
  return point.x < rect.left + band || point.x > rect.right - band;
}

// Where to draw the insertion line for slot k, in the same coordinates as the rects.
export function slotGuide(axis, container, rects, k) {
  if (rects.length === 0) {
    return { x1: container.left, y1: container.top + 4, x2: container.right, y2: container.top + 4 };
  }
  if (axis === 'vertical') {
    const y = k === 0 ? rects[0].top - 2 : k === rects.length ? rects[k - 1].bottom + 2 : (rects[k - 1].bottom + rects[k].top) / 2;
    return { x1: container.left, y1: y, x2: container.right, y2: y };
  }
  const r = k < rects.length ? rects[k] : rects[k - 1];
  const x = k < rects.length ? r.left - 2 : r.right + 2;
  return { x1: x, y1: r.top, x2: x, y2: r.bottom };
}

const startsWith = (path, prefix) => prefix.length <= path.length && prefix.every((v, i) => path[i] === v);

// Converts "drop `from` into `parent` before tree index `index`" (counted in the current tree)
// into the arguments of edit.move, whose parent and index are counted after `from` is detached.
// Returns { noop: true } when nothing would change, or { error } when the drop is impossible.
export function moveArgs(from, parent, index) {
  if (startsWith(parent, from)) return { error: '部品を自分の中には動かせません' };
  const depth = from.length - 1;
  const fromIndex = from[depth];
  const fromParent = from.slice(0, depth);
  const to = [...parent];
  if (startsWith(to, fromParent) && to.length > depth && to[depth] > fromIndex) to[depth]--;
  let at = index;
  const sameParent = to.length === fromParent.length && startsWith(to, fromParent);
  if (sameParent) {
    if (index > fromIndex) at--;
    if (at === fromIndex) return { noop: true };
  }
  return { parent: to, index: at };
}
