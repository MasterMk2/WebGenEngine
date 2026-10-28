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
- コメントは書き出さない。Html は中身をそのまま出す（GUI で中身を編集しない箱。管理者だけが書ける想定）
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

PHP の `renderPage($doc, $title, $lang = 'ja', $stylesheetHref = 'style.css')`、JS の `renderPage(doc, { title, lang = 'ja', stylesheetHref = 'style.css' })` は、`<!doctype html>` から `</html>` までを返す。本文は `<main class="wge-page">` に入れる。`lang` が言語コードの形でなければ `RenderError`（`E_LANG`）。

JS の `renderFragment(doc, indent, { annotate: true })` は、エディタのプレビュー専用の書き出し（`editor.md` §4）。各部品に `data-wge-path` を付け、Html の中身を出さずに枠にする。PHP 版にはない。公開ページでは使わない。

## 6. 確かめていること

- 共通テストデータ（`fixtures/render/cases.json`）を、JS と PHP の両方で通す
- スキーマから作られうるクラスのすべてに、CSS の規則があることを確かめる（`base.css` の書き漏れを防ぐ）
- 無作為テスト（`differential/`）では、HTML が JS と PHP で一致することに加えて、次の 2 点を確かめる。Html の箱の中身は利用者が書いたものなので除く
  - 出る属性が `class` `href` `src` `alt` `loading` `decoding` だけであること
  - 使ったクラスのすべてに CSS の規則があること

## 7. まだ決めていないこと

- **未実装のセキュリティ要件**: Html の箱の中身は、誰が書いたものでも公開ページにそのまま出る。スクリプトも出る。要件 §3.4 では「生の HTML は管理者だけが書け、それ以外の役割では無害化する」としている
  - スキーマの `trusted` の印は、まだどこからも使っていない
  - ユーザーと役割の仕組みを作るときに、保存時に役割を確かめる処理と、無害化の処理を入れる
  - エディタを公開する前に必ず済ませる

- 画像の幅と高さ（`width` `height` 属性）。レイアウトのずれ（CLS）を防ぐには要るが、アップロード時に寸法を記録する仕組みが先に要る
- SEO 用の `<meta>`（説明文、OGP）と構造化データ
- 見出しの大きさのトークン（いまはブラウザの既定のまま）
- テーマを GUI で変える画面
