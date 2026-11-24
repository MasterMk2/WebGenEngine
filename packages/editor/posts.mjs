// The list of pages (blog posts) on the server: open one in the editor, start a new one, delete a
// draft and restore it. Needs the save API (packages/server); the editor opens a page with
// index.html#p=<id>. Spec: docs/design/blog.md.

import { cleanTitle, dateLabel, newPostId, postTemplate, today } from './posts-core.mjs';

const $ = (id) => document.getElementById(id);
const headers = { 'content-type': 'application/json', 'x-wge-request': '1' };

// The line under the form; `action` is a button after the text, such as 元に戻す.
function status(text, kind = '', action = null) {
  const el = $('status');
  el.replaceChildren(text);
  el.className = `posts-status ${kind}`;
  if (action) {
    const button = document.createElement('button');
    button.type = 'button';
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
    const side = document.createElement('span');
    side.className = 'post-side';
    const meta = document.createElement('span');
    meta.className = 'post-meta';
    meta.textContent = [dateLabel(page.date), page.published ? '公開中' : '下書き'].filter(Boolean).join('・');
    side.append(meta);
    // A published post is taken back in the editor first, so deleting never changes the site.
    if (!page.published) side.append(rowButton('削除', `「${page.title}」を削除`, 'danger', () => remove(page)));
    li.append(a, side);
    if (page.published) li.classList.add('live');
    list.append(li);
  }
}

function renderDeleted(pages) {
  $('trash').hidden = pages.length === 0;
  $('trash-count').textContent = String(pages.length);
  const list = $('trash-list');
  list.replaceChildren();
  for (const page of pages) {
    const li = document.createElement('li');
    const title = document.createElement('span');
    title.className = 'post-title';
    title.textContent = page.title;
    const side = document.createElement('span');
    side.className = 'post-side';
    const meta = document.createElement('span');
    meta.className = 'post-meta';
    meta.textContent = `${dateLabel(today(new Date(page.deletedAt)))}に削除`;
    side.append(meta, rowButton('元に戻す', `「${page.title}」を元に戻す`, '', () => restore(page)));
    li.append(title, side);
    list.append(li);
  }
}

function rowButton(text, label, className, run) {
  const button = document.createElement('button');
  button.type = 'button';
  button.textContent = text;
  button.className = className;
  button.setAttribute('aria-label', label);
  button.addEventListener('click', async () => {
    button.disabled = true;
    await run();
    button.disabled = false;
  });
  return button;
}

// Both lists, read again after a change (another person may have changed them too).
async function refresh() {
  const [listed, deleted] = await Promise.all([call('/pages'), call('/deleted-pages')]);
  if (listed.ok) render(listed.pages);
  else status(said(listed, '記事の一覧を読み込めませんでした。'), 'error');
  if (deleted.ok) renderDeleted(deleted.pages);
  return listed.ok;
}

// Deleting can be undone, so it asks nothing first: the line under the form offers 元に戻す.
async function remove(page) {
  const res = await call(`/pages/${page.id}/delete`, {});
  if (res.ok) {
    await refresh();
    status(`「${page.title}」を削除しました。`, '', { label: '元に戻す', run: () => restore(page) });
    return;
  }
  const why = {
    published: 'この記事は公開中のため削除できません。エディタで公開を取り消してから削除してください。',
    deleted: 'この記事はすでに削除されています。',
  }[res.reason];
  await refresh();
  status(why ?? said(res, '削除できませんでした。もう一度押してください。'), 'error');
}

async function restore(page) {
  const res = await call(`/pages/${page.id}/restore`, {});
  await refresh();
  if (res.ok) status(`「${page.title}」を元に戻しました。下書きとして一覧にあります。`);
  else status(res.reason === 'not-deleted' ? 'この記事はすでに一覧に戻っています。' : said(res, '元に戻せませんでした。もう一度押してください。'), 'error');
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
