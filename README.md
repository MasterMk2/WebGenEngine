# WebGenEngine

WordPress の上位互換を目指す CMS です。**試作の段階で、まだ実際のサイトの運用には使えません。**

目指しているもの（詳しくは [docs/requirements.md](docs/requirements.md)）：

- 専門知識のない人が、GUI だけでサイトを作って運用できる
- GUI で作ったページが、人の読めるソースになる。ソースを直すと GUI に戻る（双方向）
- 国内のレンタルサーバー（PHP）に置ける。公開するページは静的な HTML として書き出し、表示に PHP を使わない
- WordPress から内容を移し、テーマを変換できる

## いまできること

| 部品 | 場所 | 内容 |
|---|---|---|
| ソース形式 | `packages/source/` | ページのソースの読み書き（JS と PHP の 2 つの実装）と、公開用の HTML・CSS の書き出し。仕様は [docs/design/source-format.md](docs/design/source-format.md)・[rendering.md](docs/design/rendering.md) |
| エディタ | `packages/editor/` | ブラウザで動く編集画面。プレビューの上で書く・部品を動かす・下書き・公開・版の履歴。仕様は [docs/design/editor.md](docs/design/editor.md)・[editor-ux.md](docs/design/editor-ux.md) |
| 保存 API | `packages/server/` | PHP と SQLite の保存先。公開すると静的な HTML を書き出す。仕様は [docs/design/server.md](docs/design/server.md) |

**保存 API にはまだログインがありません。** 開いた人は誰でも保存・公開できるので、`127.0.0.1` の外に出さないでください。

利用者のログインと権限を持つアプリの後ろに置く形（前段が許した要求だけを署名付きで中継する）は使えます。入口は `packages/server/trusted-front.php`、コンテナは `Dockerfile` です。仕組みと設定は [docs/design/server.md](docs/design/server.md) §6 にあります。

ブログとしても使えます（`WGE_BLOG_TITLE`）。記事の一覧から書き始め、題名・日付・画像をエディタで直し、公開のたびに目次とフィード（RSS）を作り直します。仕組みは [docs/design/blog.md](docs/design/blog.md) にあります。

## 動かし方

エディタだけ（保存はブラウザの中）：

```
node packages/editor/serve.mjs
```

`http://127.0.0.1:8765/editor/` を開きます。Node.js 22 で確かめています。

保存 API と一緒に動かすには、Docker と `php:8.3-apache` のイメージが要ります。

```
sh packages/server/run-dev.sh
```

`http://127.0.0.1:8766/editor/` を開きます。

## テスト

```
node packages/source/js/run-fixtures.mjs
node --test packages/editor/*.test.mjs
php packages/source/php/run-fixtures.php
php packages/server/test.php
```

PHP は 8.3 以上（`mbstring` と `pdo_sqlite` が要ります）。CI では PHP 8.3・8.4・8.5 で回しています。JS と PHP の実装が同じ結果を返すことは、共通のテストデータ（`packages/source/fixtures/`）と、無作為の入力で食い違いを探すテスト（`packages/source/differential/`）で確かめています。

## ライセンス

[MIT](LICENSE)

脆弱性の知らせ方は [SECURITY.md](SECURITY.md) にあります。
