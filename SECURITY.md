# セキュリティ

## 脆弱性の知らせ方

脆弱性を見つけたときは、**公開の Issue や Pull Request に書かないでください。**
このリポジトリの「Security」タブから「Report a vulnerability」を選び、非公開で報告してください（private vulnerability reporting）。

書いてほしいこと：

- どの部品か（`packages/source/`・`packages/editor/`・`packages/server/` と、ファイルや API の名前）
- 再現の手順
- 起こりうる影響

## 対応

- 試作の段階で、個人が保守しています。返事が遅れることがあります。
- 直すのは `main` ブランチの最新の版だけです。

## 報告の前に

次のことは、いまの試作では意図した動き（まだ無いもの）です。

- 保存 API（`packages/server/`）にログインがない。誰でも保存・公開できるので、`127.0.0.1` の外に出さない前提です（[docs/design/server.md](docs/design/server.md) §5）。開発用のサーバーは `Host` が `127.0.0.1` か `localhost` のときだけ答えます。
- 管理者が書いた HTML をそのまま出す経路（`trustRaw`）は、ログインと役割ができるまでどこからも使っていません。公開する HTML の中身は既定で無害化します（[docs/design/rendering.md](docs/design/rendering.md) §7）。

この前提を越えて書き込める・スクリプトを動かせる、という報告を歓迎します。
