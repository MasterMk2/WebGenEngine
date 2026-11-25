// The list of pages on the server: blog posts (docs/design/blog.md) or a site's pages with its
// header and footer (docs/design/site.md). Open one in the editor, start a new one, delete a draft
// and restore it. Needs the save API (packages/server); the editor opens a page with
// index.html#p=<id>.

import { cleanPath, cleanTitle, dateLabel, FOOTER, HEADER, newPostId, pageIdFor, postTemplate, today } from './posts-core.mjs';

const $ = (id) => document.getElementById(id);
const headers = { 'content-type': 'application/json', 'x-wge-request': '1' };

// The words that differ between a blog and a site of pages.
const WORDS = {
  blog: { page: '記事', list: '記事の一覧', empty: 'まだ記事はありません。上で題名を入れて書き始めてください。' },
  site: { page: 'ページ', list: 'ページの一覧', empty: 'まだページはありません。上で題名と書き出し先を入れて作ってください。' },
};
let words = WORDS.blog;
let siteMode = false;
// A site with a blog (site.md §9): the blog's posts and the site's pages, each in a list of its own.
let withBlog = false;
// Ids in use (pages, deleted pages and the parts), so a new page never takes another's.
let taken = new Set([HEADER, FOOTER]);

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

// A site's page: where it is written now, and where it goes when next published.
function pageState(page) {
  if (!siteMode || page.kind === 'post') return [dateLabel(page.date), page.published ? '公開中' : '下書き'].filter(Boolean).join('・');
  if (!page.published) return '下書き';
  if (page.livePath && page.livePath !== page.path) return `公開中（${page.livePath}）・次の公開で移ります`;
  return '公開中';
}

function render(pages, list = $('post-list'), empty = words.empty) {
  list.replaceChildren();
  if (pages.length === 0) {
    const li = document.createElement('li');
    li.className = 'empty';
    li.textContent = empty;
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
    if (siteMode && page.kind !== 'post') {
      const path = document.createElement('code');
      path.className = 'post-path';
      path.textContent = page.path;
      side.append(path);
    }
    const meta = document.createElement('span');
    meta.className = 'post-meta';
    meta.textContent = pageState(page);
    side.append(meta);
    // A published page is taken back in the editor first, so deleting never changes the site.
    // A site's first page stays (the editor opens it by default).
    if (!page.published && !(siteMode && page.id === 'top')) side.append(rowButton('削除', `「${page.title}」を削除`, 'danger', () => remove(page)));
    li.append(a, side);
    if (page.published) li.classList.add('live');
    list.append(li);
  }
}

// The header and footer, each opened in the editor like a page.
function renderParts(parts) {
  const list = $('part-list');
  list.replaceChildren();
  const names = { [HEADER]: 'ヘッダー', [FOOTER]: 'フッター' };
  for (const part of parts) {
    const li = document.createElement('li');
    const a = document.createElement('a');
    a.href = `index.html#p=${part.id}`;
    a.textContent = names[part.id] ?? part.id;
    const meta = document.createElement('span');
    meta.className = 'post-meta';
    meta.textContent = !part.loaded ? 'まだありません（開くと作ります）' : part.published ? '公開中' : '下書き（どのページにも出ていません）';
    li.append(a, meta);
    if (part.published) li.classList.add('live');
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
  if (listed.ok) {
    render(listed.pages);
    if (withBlog) render(listed.sitePages ?? [], $('page-list'), WORDS.site.empty);
    if (Array.isArray(listed.parts)) renderParts(listed.parts);
  } else {
    status(said(listed, `${words.list}を読み込めませんでした。`), 'error');
  }
  const gone = [...(deleted.pages ?? []), ...(deleted.sitePages ?? [])].sort((a, b) => b.deletedAt - a.deletedAt);
  if (deleted.ok) renderDeleted(gone);
  taken = new Set([HEADER, FOOTER, ...(listed.pages ?? []).map((p) => p.id), ...(listed.sitePages ?? []).map((p) => p.id), ...gone.map((p) => p.id)]);
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
    published: `この${words.page}は公開中のため削除できません。エディタで公開を取り消してから削除してください。`,
    deleted: `この${words.page}はすでに削除されています。`,
  }[res.reason];
  await refresh();
  status(why ?? said(res, '削除できませんでした。もう一度押してください。'), 'error');
}

async function restore(page) {
  const res = await call(`/pages/${page.id}/restore`, {});
  await refresh();
  if (res.ok) status(`「${page.title}」を元に戻しました。下書きとして一覧にあります。`);
  else status(res.reason === 'not-deleted' ? `この${words.page}はすでに一覧に戻っています。` : said(res, '元に戻せませんでした。もう一度押してください。'), 'error');
}

// A site of pages: the form takes a path instead of a date, and the header and footer are listed.
function useSiteWords(siteName) {
  siteMode = true;
  words = WORDS.site;
  $('site-name').textContent = siteName;
  document.title = `${siteName} – ${words.list}`;
  $('new-heading').textContent = '新しいページ';
  $('create').textContent = '作る';
  $('new-date-field').hidden = true;
  $('new-date').disabled = true;
  $('new-path-field').hidden = false;
  $('new-path').disabled = false;
  $('new-path').required = true;
  $('new-path-hint').hidden = false;
  $('list-title').textContent = 'ページ';
  $('list-hint').textContent = '下書きのページは「削除」で一覧から外せます。公開中のページは、エディタで公開を取り消してから削除します。書き出し先を変えたページは、次に公開したときに移ります。';
  $('parts').hidden = false;
  $('trash-label').textContent = '削除したページ';
}

// A site with a blog: the form makes a post or a page, the blog's posts are listed first (written
// most often), then the site's pages and the parts.
function useSiteWithBlog(siteName) {
  siteMode = true;
  withBlog = true;
  $('site-name').textContent = siteName;
  document.title = `${siteName} – 一覧`;
  $('new-kind').hidden = false;
  $('list-title').textContent = 'ブログの記事';
  $('site-pages').hidden = false;
  $('parts').hidden = false;
  $('trash-label').textContent = '削除した記事とページ';
  for (const radio of document.querySelectorAll('input[name="kind"]')) radio.addEventListener('change', showKind);
  showKind();
}

const kindChosen = () => document.querySelector('input[name="kind"]:checked')?.value ?? 'post';

// The fields of what is made: a post's date, or a page's path.
function showKind() {
  const page = kindChosen() === 'page';
  words = page ? WORDS.site : WORDS.blog;
  $('new-heading').textContent = page ? '新しいページ' : '新しい記事';
  $('create').textContent = page ? '作る' : '書き始める';
  $('new-date-field').hidden = page;
  $('new-date').disabled = page;
  $('new-path-field').hidden = !page;
  $('new-path').disabled = !page;
  $('new-path').required = page;
  $('new-path-hint').hidden = !page;
}

const ping = await call('/ping');
if (ping.ok === false) {
  status(said(ping, 'サーバーにつながりません。しばらくしてから開き直してください。'), 'error');
} else {
  if (ping.site === true && ping.blog === true && typeof ping.siteName === 'string') {
    useSiteWithBlog(ping.siteName);
  } else if (ping.site === true && typeof ping.siteName === 'string') {
    useSiteWords(ping.siteName);
  } else if (typeof ping.blogTitle === 'string') {
    $('site-name').textContent = ping.blogTitle;
    document.title = `${ping.blogTitle} – 記事の一覧`;
  }
  // A blog has a header and footer too (blog.md §8): in its contents page and every post.
  if (ping.parts === true && !siteMode) {
    $('parts').hidden = false;
    $('parts-hint').textContent = 'ヘッダーとフッターは、記事の一覧とすべての記事に入ります。公開すると、公開中の記事と一覧がまとめて書き直されます。';
  }
  if (typeof ping.siteUrl === 'string' && /^https?:\/\//.test(ping.siteUrl)) {
    $('view-site').href = ping.siteUrl;
    $('view-site').hidden = false;
  }
  $('new-date').value = today();
  await refresh();
}

// A new site page: its id comes from its path; another page with the same id is never opened.
async function createPage(title) {
  const path = cleanPath($('new-path').value);
  if (!path) {
    status('書き出し先は、英小文字・数字・ハイフンの名前を「/」でつないで書いてください（例: exhibitors.html、en/index.html）。フォルダは 3 段までです。', 'error');
    $('new-path').focus();
    return;
  }
  let id = pageIdFor(path, taken);
  let res = await call(`/pages/${id}/load`, { initialSource: postTemplate(title), meta: { title, path } });
  // Someone else made a page with this id a moment ago: take the next one.
  if (!res.ok && res.reason === 'exists') {
    await refresh();
    id = pageIdFor(path, taken);
    res = await call(`/pages/${id}/load`, { initialSource: postTemplate(title), meta: { title, path } });
  }
  if (!res.ok) {
    const why = {
      'path-taken': `書き出し先「${path}」は、ほかのページが使っています（削除したページを含みます）。別の場所にしてください。`,
      'invalid-path': '書き出し先に使えない名前です。英小文字・数字・ハイフンで書いてください。',
    }[res.reason];
    status(why ?? said(res, 'ページを作れませんでした。もう一度押してください。'), 'error');
    if (why) $('new-path').focus();
    return;
  }
  location.href = `index.html#p=${id}`;
}

$('new-post').addEventListener('submit', async (event) => {
  event.preventDefault();
  const title = cleanTitle($('new-title').value);
  if (!title) {
    status('題名は 1 行で、120 文字までにしてください。', 'error');
    return;
  }
  $('create').disabled = true;
  try {
    if (siteMode && (!withBlog || kindChosen() === 'page')) {
      await createPage(title);
      return;
    }
    const date = $('new-date').value;
    if (!/^\d{4}-\d{2}-\d{2}$/.test(date)) {
      status('日付を入れてください。', 'error');
      return;
    }
    const id = newPostId(date);
    const res = await call(`/pages/${id}/load`, { initialSource: postTemplate(title), meta: { title, date } });
    if (!res.ok) {
      status(said(res, '記事を作れませんでした。もう一度押してください。'), 'error');
      return;
    }
    location.href = `index.html#p=${id}`;
  } finally {
    $('create').disabled = false;
  }
});
