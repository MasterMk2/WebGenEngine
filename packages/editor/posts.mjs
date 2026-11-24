// The list of pages (blog posts) on the server: open one in the editor, or start a new one.
// Needs the save API (packages/server); the editor opens a page with index.html#p=<id>.
// Spec: docs/design/blog.md.

import { cleanTitle, dateLabel, newPostId, postTemplate, today } from './posts-core.mjs';

const $ = (id) => document.getElementById(id);
const headers = { 'content-type': 'application/json', 'x-wge-request': '1' };

// A status line, with an optional button after the text (such as "元に戻す").
function status(text, kind = '', action = null) {
  const el = $('status');
  el.replaceChildren(text);
  el.className = `posts-status ${kind}`;
  if (action) {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'link-button';
    button.textContent = action.label;
    button.addEventListener('click', action.run, { once: true });
    el.append(' ', button);
  }
}

// The API's answer, or a refusal in the same form (network, or a page that is not the API's).
async function call(path, body) {
  try {
    const res = await fetch(`../api${path}`, body === undefined ? { cache: 'no-store' } : { method: 'POST', cache: 'no-store', headers, body: JSON.stringify(body) });
    const data = await res.json().catch(() => null);
    return data?.wge === 1 ? data : { ok: false, reason: 'blocked' };
  } catch {
    return { ok: false, reason: 'network' };
  }
}

const said = (res, fallback) => (typeof res.message === 'string' && res.message ? res.message : fallback);

function render(pages) {
  const list = $('post-list');
  list.replaceChildren();
  if (pages.length === 0) {
    const li = document.createElement('li');
    li.className = 'empty';
    li.textContent = 'まだ記事はありません。上で題名を入れて書き始めてください。';
    list.append(li);
    return;
  }
  for (const page of pages) {
    const li = document.createElement('li');
    const a = document.createElement('a');
    a.href = `index.html#p=${page.id}`;
    a.textContent = page.title;
    const meta = document.createElement('span');
    meta.className = 'post-meta';
    meta.textContent = [dateLabel(page.date), page.published ? '公開中' : '下書き'].filter(Boolean).join('・');
    const remove = document.createElement('button');
    remove.type = 'button';
    remove.className = 'row-action';
    remove.textContent = '削除';
    remove.setAttribute('aria-label', `「${page.title}」を削除`);
    remove.addEventListener('click', () => removePost(page));
    const side = document.createElement('span');
    side.className = 'post-side';
    side.append(meta, remove);
    li.append(a, side);
    if (page.published) li.classList.add('live');
    list.append(li);
  }
}

function renderTrash(pages) {
  $('trash').hidden = pages.length === 0;
  $('trash-title').textContent = `ゴミ箱（${pages.length} 件）`;
  const list = $('trash-list');
  list.replaceChildren();
  for (const page of pages) {
    const li = document.createElement('li');
    const name = document.createElement('span');
    name.className = 'trash-name';
    name.textContent = page.title;
    const back = document.createElement('button');
    back.type = 'button';
    back.className = 'row-action';
    back.textContent = '戻す';
    back.setAttribute('aria-label', `「${page.title}」を一覧に戻す`);
    back.addEventListener('click', () => restorePost(page));
    const side = document.createElement('span');
    side.className = 'post-side';
    const meta = document.createElement('span');
    meta.className = 'post-meta';
    meta.textContent = dateLabel(page.date);
    side.append(meta, back);
    li.append(name, side);
    list.append(li);
  }
}

async function refresh() {
  const [listed, trash] = await Promise.all([call('/pages'), call('/trash')]);
  if (listed.ok) render(listed.pages);
  else status(said(listed, '記事の一覧を読み込めませんでした。'), 'error');
  if (trash.ok) renderTrash(trash.pages);
}

// Deleting moves the post to the trash, so it can be undone; a published one leaves the site
// at once, so that one is asked first.
async function removePost(page) {
  if (page.published && !confirm(`「${page.title}」は公開中です。削除するとサイトから消えます。ゴミ箱から戻すと下書きに戻ります（もう一度公開が要ります）。削除しますか？`)) return;
  const res = await call(`/pages/${page.id}/delete`, {});
  if (!res.ok) {
    status(said(res, '削除できませんでした。もう一度押してください。'), 'error');
    return;
  }
  await refresh();
  status(`「${page.title}」をゴミ箱に移しました。`, '', { label: '元に戻す', run: () => restorePost(page) });
}

async function restorePost(page) {
  const res = await call(`/pages/${page.id}/restore`, {});
  if (!res.ok && res.reason !== 'not-deleted') {
    status(said(res, '戻せませんでした。もう一度押してください。'), 'error');
    return;
  }
  await refresh();
  status(`「${page.title}」を下書きとして一覧に戻しました。`);
}

const ping = await call('/ping');
if (ping.ok === false) {
  status(said(ping, 'サーバーにつながりません。しばらくしてから開き直してください。'), 'error');
} else {
  if (typeof ping.blogTitle === 'string') {
    $('site-name').textContent = ping.blogTitle;
    document.title = `${ping.blogTitle} – 記事の一覧`;
  }
  if (typeof ping.siteUrl === 'string' && /^https?:\/\//.test(ping.siteUrl)) {
    $('view-site').href = ping.siteUrl;
    $('view-site').hidden = false;
  }
  $('new-date').value = today();
  await refresh();
}

$('new-post').addEventListener('submit', async (event) => {
  event.preventDefault();
  const title = cleanTitle($('new-title').value);
  const date = $('new-date').value;
  if (!title) {
    status('題名は 1 行で、120 文字までにしてください。', 'error');
    return;
  }
  if (!/^\d{4}-\d{2}-\d{2}$/.test(date)) {
    status('日付を入れてください。', 'error');
    return;
  }
  $('create').disabled = true;
  const id = newPostId(date);
  const res = await call(`/pages/${id}/load`, { initialSource: postTemplate(title), meta: { title, date } });
  $('create').disabled = false;
  if (!res.ok) {
    status(said(res, '記事を作れませんでした。もう一度押してください。'), 'error');
    return;
  }
  location.href = `index.html#p=${id}`;
});
