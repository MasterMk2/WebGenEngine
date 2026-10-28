# ページのソース形式（v0 試作）

- 状態: 試作。要件定義 R4（可読ソース・双方向。D2）と §3.4（パーサーを JS と PHP の両方に持つ）を確かめるためのもの
- 実装: `packages/source/`
  - `schema.json`: 部品の定義。JS と PHP の両方がこれを読む。部品や属性を足すときはここだけを直す
  - `js/source-format.mjs`、`php/SourceFormat.php`: パーサー、シリアライザー、編集操作
  - `fixtures/*.json`: 両方の実装で同じ結果になることを確かめる共通のテストデータ
  - `differential/`: 無作為に作ったソースと編集を JS と PHP の両方に通し、結果の食い違いを探す
- 対象外（後で作る）: ドラッグ位置からの意図の推定と、ずれの検出（R5）。HTML への書き出しは `rendering.md`

動かし方（`packages/source` で実行する）:

```bash
node js/run-fixtures.mjs
```

```bash
sh php/run-docker.sh php:8.3-apache php:8.5-cli-alpine
```

食い違いの検査は、`node differential/gen.mjs <出力.json> <件数> <乱数の種>` でケースを作り、`php differential/check.php <出力.json>` で照合する。Docker と node が同じ環境にあれば `sh differential/run.sh` で両方を続けて実行できる。

## 1. 例

```html
<Section padding="lg">
  <Stack align="center" gap="md">
    <Heading level="1">ようこそ</Heading>
    <Text>小さなお店の<Strong>公式サイト</Strong>です。</Text>
    <Button href="/contact">お問い合わせ</Button>
  </Stack>
</Section>
```

## 2. 文字と行

- 文字コードは UTF-8。先頭の BOM は読み飛ばす。正規形の出力には付けない
- 改行は `\n` と `\r\n` のどちらも 1 つの改行として数える。単独の `\r` はエラー（`E_LONE_CR`）
- エラーの位置は 1 始まりの行と列で示す。列はコードポイント単位で数える（`𠮷` も絵文字も 1 列）

## 3. 書き方

- 部品は `<名前 属性="値">…</名前>` または `<名前 属性="値" />` と書く。名前は大文字小文字を区別する。`<section>` のような小文字の HTML は使えない（`E_UNKNOWN_COMPONENT`）
- 属性の値は必ず `"` で囲む。値のない属性や、`'` で囲んだ値は書けない（`E_UNQUOTED_ATTRIBUTE`）。`=` の前後に空白は置けない
- 同じ属性を 2 回書けない（`E_DUPLICATE_ATTRIBUTE`）。スキーマにない属性は書けない（`E_UNKNOWN_ATTRIBUTE`）
- 文字参照は `&amp;` `&lt;` `&gt;` `&quot;` `&apos;` と、`&#10進;` `&#x16進;` だけを使える。それ以外の `&` はエラー（`E_BAD_ENTITY`）
- コメント `<!-- … -->` は、ブロックを並べる場所にだけ書ける。文の途中には書けない（`E_COMMENT_IN_INLINE`）

## 4. 部品の種類

| 種類 | 部品 | 中身 |
|---|---|---|
| `container` | Section、Stack、Grid | ブロックの部品とコメント。空白以外の文字は書けない（`E_TEXT_IN_BLOCK`） |
| `text` | Heading、Text、Button | 文（文字とインラインの部品） |
| `void` | Image | なし。必ず `/>` で閉じる（`E_VOID_NOT_SELF_CLOSED`） |
| `raw` | Html | `</Html>` までの文字をそのまま持つ。中身は解釈しない（R4 の「GUI では中身を編集しない箱」）。スキーマで `trusted` を付けてあり、将来は管理者だけが書けるようにする（§3.4） |
| `inline` | Link、Strong、Em | 文 |
| `inline-void` | Br | なし |

- ページの最上位は `container` と同じ規則で、ブロックの部品を並べる
- ブロックの場所にインラインの部品を、文の中にブロックの部品を置くとエラー（`E_NOT_ALLOWED_HERE`）。スキーマの `excludes` に挙げた部品も中に置けない（Link の中の Link、Button の中の Link）
- 中身のない部品は `/>` で閉じてもよい（`<Stack gap="md" />`）

## 5. 属性の値

| 型 | 規則 |
|---|---|
| `space` | `tokens.space` のどれか（`none` `xs` `sm` `md` `lg` `xl` `2xl`） |
| `enum` | `values` のどれか |
| `int` | 先頭に 0 を付けない 10 進数で、`min` 以上 `max` 以下。ツリーには文字列のまま持つ |
| `url` | 空でない。空白と制御文字を含まない。スキーム（`xxx:`）があるなら `urlSchemes` のどれか。`javascript:` などは `E_UNSAFE_URL` |
| `string` | 何でもよい |

- 型に合わない値は `E_INVALID_VALUE`。必須の属性がないと `E_MISSING_ATTRIBUTE`

## 6. 文の中の空白

日本語の文を途中で折り返して書いても、変な空白が入らないようにする。

1. 空白（空白、タブ、改行）の並びは、次のように 1 つにまとめる
   - 改行を含み、すぐ前かすぐ後の文字が全角の文字（漢字、かな、全角の記号など）なら、何も残さない
   - それ以外は空白 1 つにする
2. `text` の部品（Heading、Text、Button）の中身は、最初と最後の空白を取り除く。Strong などインラインの部品の中では取り除かない
3. 文字参照で書いた空白（`&#32;` `&#10;` など）も、そのまま書いた空白と同じに扱う。改行を残したいときは `<Br />` を使う
4. 「すぐ前・すぐ後の文字」は同じ文字のかたまりの中だけで見る。部品の境目をまたいでは見ない
5. GUI から渡された文字（§9 の `setInline`）にも同じ規則を当てる。こうしておくと、正規形を読み直しても同じツリーに戻る

```html
<Text>
  日本語の文は
  ここで折り返しても続けて読まれる。
</Text>
```

は「日本語の文はここで折り返しても続けて読まれる。」になる。

## 7. 正規形

GUI で作った部品や、変更した部品は、この形で書き出す。同じツリーなら常に同じ文字列になる（R4 の「出力は決定的」）。

- 字下げは空白 2 つ。改行は `\n`。最上位の部品は 1 行ずつ並べ、最後に改行を 1 つ付ける
- 属性はスキーマに書いた順に並べ、`name="値"` を空白 1 つで区切る
- `container` は子を 1 つずつ行に分ける。子がなければ `<Stack gap="md" />`
- `text` と `inline` は中身を 1 行に書く。中身がなければ `/>` で閉じる
- 文字は `&` `<` `>` を、属性の値はさらに `"` を文字参照にする

## 8. 触っていない部分を変えずに保存する

R4 の「手で書き換えたソースを GUI で開いて保存しても、触っていない部分に差分が出ない」を満たすため、パーサーは各ノードに元のソースの範囲と、直前の空白を持たせる。保存では次のようにする。

- どこも変えていなければ、元のソースをそのまま返す
- 変えていない部品は、元のソースの文字列をそのまま使う（正規形でなくても直さない）
- 属性を変えた部品は、開始タグだけを正規形で書き直す。中身と終了タグは元のまま
- 子を足した・消した・動かした部品は、変えていない子をそれぞれ元の文字列と元の直前の空白で出す。新しく入った子の直前の空白は、隣の子の空白を写して字下げを合わせる
- 文の中身を変えた部品は、中身だけを正規形で書き直す。文の中の Link や Strong を変えた場合も、それを含む Heading・Text・Button の中身を書き直す
- 新しく書く部分の改行は、元のソースで最初に現れた改行（`\n` か `\r\n`）に合わせる

## 9. 編集操作

GUI からの変更は、次の操作だけで行う。操作はスキーマで検査し、違反は `EditError`（コードは §3〜§5 と同じ）にする。

| 操作 | 内容 |
|---|---|
| `setProp(path, name, value)` | 属性を変える。`value` が `null` なら消す（必須の属性は消せない） |
| `insert(parentPath, index, node)` | ページか `container` に子を足す |
| `remove(path)` | ページか `container` から子を消す |
| `move(fromPath, parentPath, index)` | 子を動かす。`parentPath` と `index` は、取り外した後のツリーで数える |
| `setInline(path, children)` | `text` または `inline` の部品の中身を差し替える |
| `setRaw(path, raw)` | Html の中身を差し替える。`</Html>` は含められない |

- `path` は、ページから子の番号（0 始まり）をたどった配列。コメントも子として数える
- 属性の値、Html の中身、コメントに単独の `\r` があれば `E_LONE_CR`。書き出したソースを読み直せなくなるため
- コメントは `-->` を含められず、`-` で終われない（`<!--…--->` は読み直すと末尾の `-` が消える）
- `node` と `children` は §10 のツリーの JSON で渡す

## 10. ツリーの JSON

```json
{ "type": "document", "children": [
  { "type": "Stack", "props": { "gap": "md" }, "children": [
    { "type": "Heading", "props": { "level": "1" }, "children": [ { "type": "text", "value": "ようこそ" } ] },
    { "type": "Image", "props": { "src": "/a.jpg", "alt": "" } },
    { "type": "Html", "raw": "<iframe src=\"…\"></iframe>" },
    { "type": "comment", "value": " メモ " }
  ] }
] }
```

- `void` と `inline-void` には `children` がない。Html は `children` の代わりに `raw` を持つ
- Html の `raw` とコメントの `value` では、`\r\n` を `\n` にして持つ

## 11. エラーの位置

| コード | 位置 |
|---|---|
| `E_UNKNOWN_COMPONENT`、`E_NOT_ALLOWED_HERE`、`E_MISSING_ATTRIBUTE`、`E_VOID_NOT_SELF_CLOSED`、`E_UNCLOSED_TAG`、`E_EOF_IN_TAG`、`E_UNCLOSED_RAW` | 部品の `<` |
| `E_UNKNOWN_ATTRIBUTE`、`E_DUPLICATE_ATTRIBUTE` | 属性名の先頭 |
| `E_UNQUOTED_ATTRIBUTE` | `=` の次の文字。`=` がなければ属性名の次の文字 |
| `E_INVALID_VALUE`、`E_UNSAFE_URL` | 値を囲む最初の `"` |
| `E_MISMATCHED_CLOSE`、`E_UNEXPECTED_CLOSE` | 終了タグの `<` |
| `E_UNCLOSED_COMMENT`、`E_COMMENT_IN_INLINE` | コメントの `<` |
| `E_TEXT_IN_BLOCK` | 空白以外の最初の文字 |
| `E_BAD_ENTITY` | `&` |
| `E_LONE_CR` | `\r` |
| `E_UNEXPECTED_CHAR` | その文字 |

- 最初のエラーで止め、1 件だけ返す
