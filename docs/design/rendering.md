# 公開用 HTML と CSS の書き出し（v0 試作）

- 状態: 試作。ページのツリー（`source-format.md`）から、公開ページの HTML と、テーマから CSS を作る
- 実装: `packages/source/`
  - `schema.json` の各部品の `render`: どの HTML タグとクラスにするかの対応表。JS と PHP はこの表を解釈するだけにして、対応を 1 か所にまとめる
  - `theme.default.json`: 既定のテーマ（余白、文字の大きさ、幅、色、書体）
  - `render/base.css`: 手で書く固定の CSS
  - `js/render.mjs`、`php/Renderer.php`: 書き出し器
  - `fixtures/render/`: 共通テストデータ。`default.css` は既定のテーマから作る CSS の正解
- 公開ページは静的ファイルとして書き出す想定（要件 §3.3）。サーバーでは PHP 版、エディタのプレビューでは JS 版を使う

## 1. 例

```html
<Stack align="center" gap="md">
  <Heading level="1">ようこそ</Heading>
  <Button href="/contact">お問い合わせ</Button>
</Stack>
```

は次のようになる。

```html
<div class="wge-stack wge-align-center wge-gap-md">
  <h1 class="wge-heading">ようこそ</h1>
  <a class="wge-button" href="/contact">お問い合わせ</a>
</div>
```

## 2. 対応表（`render`）

| 項目 | 意味 |
|---|---|
| `tag` | HTML のタグ。`h{level}` のように属性の値を埋め込める |
| `class` | 常に付けるクラス |
| `classes` | 属性からクラスを作る。`gap` なら `wge-gap-<値>`。`align:text-align` のように書くと、接頭辞を `text-align` にする。値の英数字以外は `-` にする（`4:3` → `wge-ratio-4-3`） |
| `attrs` | そのまま HTML の属性にする属性（`href`、`src`、`alt`） |
| `fixed` | 常に付ける属性（Image の `loading="lazy"` `decoding="async"`） |

- クラスは、`class` の次に、`classes` に書いた順で並べる。ソースに書いた順ではない
- コメントは書き出さない。Html の中身は、既定では無害化して出す（§7）。そのまま出すのは、生の HTML を書いてよい人（管理者）が書いたものとして呼び出したときだけ
- 字下げは空白 2 つ。ブロックの部品は 1 行に 1 つ。文の中身は 1 行
- 文字は `&` `<` `>` を、属性の値はさらに `"` を文字参照にする

## 3. 要件との関係

- R4「絶対座標や根拠のない数値が出てこない」: HTML には `style` 属性を出さない。位置や余白はすべてクラスで表し、数値は CSS 変数（テーマ）にだけ現れる
- R2「PC で作ったレイアウトがスマートフォンでも崩れない」: 画面幅がテーマの `breakpoint`（既定 40rem）以下になると、Grid は 1 列になる。横並びの Stack は既定で折り返す。長い英単語や URL も `overflow-wrap: anywhere` で折り返す
- 色付きの背景（`primary`、`inverse`）の中では、文字とリンクの色を背景に合わせて変える。`primary` の背景の上の `primary` のボタンは、色を入れ替えて見えるようにする

## 4. テーマ

`theme.default.json` と同じ形にする。項目を足すことも欠かすこともできない。値は次の形だけを受け付け、外れたら `RenderError`（コード `E_THEME`、`key` は `space.md` など）にする。CSS に任意の文字列を差し込まれないようにするため。

| 項目 | 形 |
|---|---|
| `space`、`fontSize`、`width`、`radius` | `0`、または数と単位（`rem` `em` `px` `%`）。`calc()` などは不可 |
| `breakpoint` | 数と単位（`rem` `em` `px`） |
| `color` | `#rgb`、`#rrggbb`、`#rrggbbaa` |
| `font.body` | 英数字、空白、`,` `"` `-` だけ |

- 問題は決まった順番（`space` → `fontSize` → `width` → `radius` → `breakpoint` → `color` → `font` → 未知の項目）で探し、最初の 1 件を返す
- `space` の項目は `schema.json` の `tokens.space`、`color` などの項目は `schema.json` の `theme` で決める

## 5. ページ全体

PHP の `renderPage($doc, $title, $lang = 'ja', $stylesheetHref = 'style.css', $trustRaw = false, $head = [])`、JS の `renderPage(doc, { title, lang = 'ja', stylesheetHref = 'style.css', trustRaw = false, head = {} })` は、`<!doctype html>` から `</html>` までを返す。本文は `<main class="wge-page">` に入れる。`lang` が言語コードの形でなければ `RenderError`（`E_LANG`）。

`head`（2026-11-24）は、検索と共有のための項目で、`<title>` のすぐ後ろに決まった順で書く。`head` を渡さなければ、出力は前と 1 バイトも変わらない。

| 項目 | 値 | 書くもの |
|---|---|---|
| `description` | 文字列（空なら書かない） | `<meta name="description">`。`url` があれば `og:description` も |
| `url` | `http(s)://` の絶対 URL | `<link rel="canonical">`、`og:type`・`og:title`（ページの題名）・`og:url`、`twitter:card`。**`url` が無ければ共有用の印は 1 つも書かない**（OGP は絶対 URL が要るため） |
| `image` | `http(s)://` の絶対 URL | `og:image`。`twitter:card` を `summary_large_image` にする（無ければ `summary`） |
| `type` | `website`（既定）か `article` | `og:type` |
| `siteName` | 文字列（空なら書かない） | `og:site_name` |

値の誤りと知らない項目は `RenderError`（`E_HEAD`、key は `head.<項目>`）。調べる順は表の順、そのあと知らない項目。`null` は渡さなかったのと同じ。

`renderFragment` も同じ `trustRaw` を受け取る（PHP は `renderFragment($doc, $indent = '', $trustRaw = false)`、JS は `renderFragment(doc, indent, { trustRaw })`）。`trustRaw` が真のときだけ Html の中身をそのまま出し、それ以外は無害化する（§7）。

JS の `renderFragment(doc, indent, { annotate: true })` は、エディタのプレビュー専用の書き出し（`editor.md` §4）。各部品に `data-wge-path` を付け、Html の中身を出さずに枠にする。PHP 版にはない。公開ページでは使わない。

## 6. 確かめていること

- 共通テストデータ（`fixtures/render/cases.json`）を、JS と PHP の両方で通す
- スキーマから作られうるクラスのすべてに、CSS の規則があることを確かめる（`base.css` の書き漏れを防ぐ）
- 無作為テスト（`differential/`）では、HTML が JS と PHP で一致することに加えて、次の 2 点を確かめる。Html の箱の中身は利用者が書いたものなので除く
  - 出る属性が `class` `href` `src` `alt` `width` `height` `loading` `decoding` だけであること（`width` `height` は 2026-11-24 に足した）
  - 使ったクラスのすべてに CSS の規則があること
- Html の無害化（§7）
  - 共通テストデータで、スクリプト、イベントの属性（`on…`）、`javascript:` などの URL、`svg` や `iframe` が消えることと、許した書き方が元のまま残ることを確かめる
  - 無作為テストでは、無害化した HTML が JS と PHP で一致することに加えて、Html の箱ごとに次を確かめる。許した要素と属性だけであること、URL の属性が §7 の規則を満たすこと、要素がすべて閉じていること、`<` と `>` がすべて許したタグの一部であること、もう一度無害化しても変わらないこと

## 7. Html の無害化

要件 §3.4 では「生の HTML は管理者だけが書け、それ以外の役割では無害化する」としている。

- 実装: `js/sanitize-html.mjs`、`php/HtmlSanitizer.php`。許すものは `schema.json` の Html の `sanitize` に書き、JS と PHP はそれを読むだけにする
- 書き出し器は、Html の中身を既定で無害化する。`trustRaw` を真にしたときだけ、そのまま出す
  - `trustRaw` は管理者が書いた Html にだけ使う。ログインと役割がまだないので、いまはどこからも真にしていない。保存 API の公開（`server.md`）も無害化した HTML を書き出す
  - エディタのプレビュー（`annotate`）は今までどおり、中身を出さずに枠にする
- 無害化のしかた
  - 入力を先頭から 1 回だけ読み、読んだものから出力を作り直す。入力をそのまま出すのは文字の部分だけで、そこでも `<` と `>` は文字参照にする（`&` はそのまま出すので、`&nbsp;` や `&copy;` は使える）
  - 許した要素（`sanitize.elements`）は、小文字にした名前と、許した属性だけで書き直す。属性の値は必ず `"` で囲み、`&` `<` `>` `"` を文字参照にする。同じ属性が 2 つあれば、ブラウザと同じく最初のものだけを見る
  - 許していない要素は、タグだけを消して中身を残す。ただし `sanitize.dropContent` の要素（`script` `style` `iframe` `svg` `math` `template` など）は、閉じタグまで中身ごと消す。閉じタグがなければ最後まで消す
  - コメント、`<!DOCTYPE …>`、`<?…>` は消す。Html の箱の終わりで途切れたタグも消す
  - 閉じタグは、Html の中で開いた要素のものだけを出す。閉じていない要素は最後に閉じ、開いていない要素の閉じタグは消す
  - 属性の値の文字参照は、ソース形式と同じもの（`&amp;` `&lt;` `&gt;` `&quot;` `&apos;` と数値）だけを解く。残った `&` は `&amp;` にして出すので、ブラウザが読む値は確かめた値と同じになる。そのため、属性の値に書いた `&copy;` などは文字のまま表示される
  - URL の属性（`sanitize.urlAttributes`）は、前後の空白を除いたうえで、ソース形式の `url` と同じ規則で確かめ、外れたら属性ごと消す（空、空白や制御文字を含む、`urlSchemes` にない scheme）。文字参照を解いてから確かめるので、`jav&#x09;ascript:` や `&#106;avascript:` も消える
  - 意味を持つのは ASCII の文字だけにする（全角の空白はタグの中でも空白として扱わない）。JS と PHP で同じ結果にするため
- 許す要素: 文の飾り（`b` `strong` `em` `i` `u` `s` `mark` `small` `sub` `sup` `code` `kbd` `abbr` `cite` `q` `del` `ins`）、段落と見出し（`p` `h1`〜`h6` `br` `hr` `pre` `blockquote` `div` `span`）、一覧（`ul` `ol` `li` `dl` `dt` `dd`）、表（`table` `caption` `thead` `tbody` `tfoot` `tr` `th` `td`）、図（`figure` `figcaption` `img`）、リンク（`a`）
- 許す属性: すべての要素で `title`。`a` の `href`、`img` の `src` `alt` `width` `height`、`blockquote` `q` `del` `ins` の `cite`、`ol` の `start`、`td` `th` の `colspan` `rowspan`、`th` の `scope`。`class` `id` `style` と `on…` は許さない（見た目はクラスと CSS だけで決める。R4）
- まだのこと
  - 管理者が書いた Html だけを `trustRaw` で書き出す仕組み。基準は「Html の中身を書いた人」で、公開の操作をした人ではない。保存のときに、管理者でない人が `trusted` の印の付いた部品の中身を変えていないかを確かめる
  - 許す要素と属性の見直し（地図の埋め込みの `iframe` など。いまは `trustRaw` のときだけ出せる）

## 8. まだ決めていないこと

- 構造化データ（JSON-LD）。説明文と OGP は 2026-11-24 に §5 の `head` で入れた
- 見出しの大きさのトークン（いまはブラウザの既定のまま）
- テーマを GUI で変える画面
