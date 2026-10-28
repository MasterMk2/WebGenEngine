# 国内レンタルサーバーの標準環境調査（2026-10-28）

要件定義（`docs/requirements.md`）の Q11「対応するレンタルサーバーと最低 PHP バージョン」を詰めるための調査メモ。

## 0. 調べ方と確度の表し方

- 対象: エックスサーバー、シン・レンタルサーバー、ConoHa WING、ロリポップ！、さくらのレンタルサーバ、mixhost の 6 社。各社の「WordPress が使える最安プラン」と「標準的なプラン」を見た
- 方法: 各社の公式サイト（仕様、マニュアル、FAQ、お知らせ）を Web 検索と取得ツールで読んだ。実機での確認はしていない
- 取得ツールは小型モデルの要約を返すため、原文の逐語確認ではない。設計判断に直結する点は、原文を直接取得して確かめ直した

| 印 | 確度 |
|---|---|
| ◎ | 公式ページの本文を直接取得して確認した |
| ○ | 公式ページに基づく（調査用サブエージェントの要約経由） |
| △ | 公式ページの検索抜粋だけ（本文は取得できなかった） |
| 推論 | 公式の記述から読み取ったもので、明記はない |
| 非公式 | 第三者の記事や投稿 |

## 1. 結論（Q11 への回答。要件定義の D6・D7 で決定済み）

1. **対応サーバーの候補は 6 社。** 対象プランは次のとおり
   - エックスサーバー（スタンダード以上）、シン・レンタルサーバー（全プラン）、ConoHa WING（ベーシック以上）、ロリポップ（ライト以上）、さくら（スタンダード以上）、mixhost（全プラン）
   - 対象外: さくらのライト（PHP は CGI のみ、cron・データベース・SSH なし、公式に CMS 不可）、ロリポップのエコノミー（WordPress・MySQL 不可）
2. **最低 PHP バージョンは、さくらを含めるなら 8.3、外すなら 8.4 にできる。** さくらで選べる最新は 8.3 ◎（2026-10-26 時点）。他の 5 社は 8.5 まで選べる ○。さくらを含めて 8.3 に決定した（2026-10-28、要件定義 D7）
3. **SQLite は「公式に利用可」でも、PHP から使えるかは実機で確かめるしかない。** 5 社が「SQLite 利用可」と書くが、PHP の pdo_sqlite 拡張が有効と明記した社はない。インストーラーで確かめ、使えなければ MySQL/MariaDB に切り替える
4. **`.htaccess` は全社で使えるが、書き方に共通の制約がある。** `php_value` / `php_flag` に頼らず、目印で囲んだ区画だけを書き換える（§3）
5. **cron は全対象プランで使えるが、5 分間隔・1 件を前提にする。** ロリポップのライトが最短 5 分・5 件まで ◎
6. **公開ディレクトリの外にデータを置けない社がある。** ロリポップは FTP のルートが初期ドメインの公開領域 △。どの社でも、PHP から公開ディレクトリの外へ書けるかは公式に書かれていない

## 2. 比較表

### 2.1 PHP

| サーバー | 選べる版 | 新規の既定 | 実行方式 |
|---|---|---|---|
| エックスサーバー | 5.1.6〜8.5（Xアクセラレータ対応は 7.2〜8.5）○ | 新規ドメイン追加時は 8.3（2025-07-28 以降）○ | FastCGI ○ |
| シン・レンタルサーバー | 7.2〜8.5 ○ | 同上 8.3 ○ | FastCGI ○ |
| ConoHa WING | 7.0〜8.5（機能一覧は 8.4 までの記載で、古い可能性）○ | 未確認 | LiteSpeed LSAPI ○ |
| ロリポップ | 新形式サーバーは 8.3〜8.5（旧番号サーバーは 7.4、8.0〜8.5）○ | 未確認 | CGI（8.4 はモジュール版も）、ハイスピードは LiteSpeed ○ |
| さくら | 8.3 / 8.2 / 8.1 / 8.0 / 7.4 / 5.x ◎。8.3 の追加は 2024-03-05 で、8.4 以降の記載なし ◎ | 未確認（契約時期で異なる）○ | CGI / モジュール ○ |
| mixhost | 5.6〜8.5 ○ | 公式情報が食い違い、未確定（8.3 / 8.4 / 8.5）○ | LSAPI ○ |

PHP 本体のサポート期限 ◎（php.net、2026-10-27 時点）:

| 版 | セキュリティ修正の期限 |
|---|---|
| 8.2 | 2026-12-31 |
| 8.3 | 2027-12-31 |
| 8.4 | 2028-12-31 |
| 8.5 | 2029-12-31 |

### 2.2 SQLite、Web サーバー、`.htaccess`

| サーバー | SQLite（公式の記載） | pdo_sqlite | Web サーバー | `.htaccess` | 確認できた制約 |
|---|---|---|---|---|---|
| エックスサーバー | 利用可。新サーバー（sv16001 以降）のライブラリは 3.26.x ◎（2024-06-05 のページ） | 未確認 | Apache 2.4 と nginx ○ | 可 ○ | `php_flag` はエラーになる ◎。Xアクセラレータがルートの `.htaccess` に行を足す ○ |
| シン・レンタルサーバー | 利用可、3.26.x ○ | 未確認 | Apache 2.4 と nginx ○ | 可 ○ | `php_value` / `php_flag` は 500 になる ○。保存文字コードの記載が EUC-JP（要確認）○ |
| ConoHa WING | 記載なし | 未確認 | Apache と nginx、CloudLinux ○ | 可 ○ | PHP の版を切り替えると `AddHandler` 行が自動で入る ○ |
| ロリポップ | 利用可 ○ | 未確認 | nginx と Apache（ハイスピードは LiteSpeed）○ | 可 ○ | `php_value` / `php_flag` は CGI 版で設定を on にした場合だけ効く ○ |
| さくら | 利用可 ○ | 未確認 | nginx と Apache 2.4、FreeBSD ○ | 可 ○ | `Options` の `All` と `FollowSymLinks` は指定できない ○ |
| mixhost | 利用可 ○ | 未確認 | LiteSpeed（前段のプロキシなし）、CloudLinux ○ | 可 ○ | mod_security が標準で有効 ○ |

### 2.3 cron と公開ディレクトリの外

| サーバー | cron | 件数・間隔 | cron で使う PHP | 公開ディレクトリの外（FTP） | PHP から外へ書けるか |
|---|---|---|---|---|---|
| エックスサーバー | 可 ○ | 記載なし。高頻度の cron は 500 の原因になると FAQ に記載 ◎ | コマンドラインの既定は 8.0 ◎。版付きのパスを指定する ○ | 可。`/home/サーバーID/ドメイン名/` に `public_html` と並ぶ。公式 FAQ も公開しないファイルは上に置くよう案内 ○ | 未確認 |
| シン・レンタルサーバー | 可 ○ | 記載なし ○ | コマンドラインの既定は 8.0 ○ | 可（同じ構成）○ | 未確認 |
| ConoHa WING | 可 ○ | 記載なし ○ | `/opt/alt/phpNN/usr/bin/php` ○ | 未確認（ホームとの関係の記載なし） | 未確認 |
| ロリポップ | 可 ○ | ライトは 5 件・最短 5 分、スタンダード以上は 10 件・最短 1 分 ◎ | 登録した時点の版で固定。版を変えたら登録し直す ◎ | 不可の見込み。FTP のルートが初期ドメインの公開領域で、独自ドメインの公開フォルダはその中に作る △、非公式 | 未確認 |
| さくら | スタンダード以上 ○ | 10 件まで。予告なく解除されることがある ○ | `/usr/local/bin/php` ○ | 可の見込み（ホームに接続し、その下に `www`）推論 | 未確認 |
| mixhost | 可 ○ | 件数は無制限、間隔の記載なし ○ | `/usr/bin/php` ○ | 可の見込み（ホームに接続し、その下に `public_html`）推論 | 未確認 |

### 2.4 その他

| サーバー | SSH | 確認できた PHP 拡張など |
|---|---|---|
| エックスサーバー | 可（公開鍵のみ）○ | sodium（7.4〜8.4）、mbstring ○ |
| シン・レンタルサーバー | 可（公開鍵のみ）○ | sodium（7.4〜8.4）、mbstring ○ |
| ConoHa WING | 可（鍵認証必須）○ | GD ○ |
| ロリポップ | スタンダード以上 ○ | GD、mbstring、ZIP ○ |
| さくら | スタンダード以上 ○ | 記載なし |
| mixhost | 可（公開鍵のみ）○ | GD、mbstring ○（更新日のない古いページ）。ファイル数上限はライト 100,000、スタンダード 200,000 ○ |

全社で公式に確認できなかったもの: `memory_limit` と `max_execution_time` の既定値、`open_basedir` の有無、intl と imagick の有無、サーバーから外への HTTPS 通信の可否。

## 3. 前段キャッシュ（リバースプロキシ）

エックスサーバーは公式に次のように書いている ◎。

- `.htaccess` で IP アドレス制限をかけたページでも、リバースプロキシにキャッシュされ、別の人に表示される可能性がある
- キャッシュされないのは、`Cache-Control` に `no-cache` / `no-store` / `private` / `must-validate` を含む応答、`Set-Cookie` を含む応答、ステータスが 200 と 404 以外の応答、WordPress の管理画面など特定の URI
- キャッシュの保存期間は 2 分（シンは 5 分 ○）

さくら、ConoHa、ロリポップも前段に nginx がある ○。キャッシュの扱いは確認していないが、同じ前提で設計する。

## 4. 2026 年 10 月の動き

- 2026-10-21〜22: PHP の重大な脆弱性への対応で、各社がパッチの適用や一部機能の一時停止を行った ○
- ロリポップは 10/24 に新規申し込み（無料お試しを含む）と WordPress 簡単引っ越しを、10/25 に WebDAV を停止した ◎
  - 理由はサイバー攻撃の増加を受けた予防措置で、不正アクセスの被害は確認されていないとしている ◎
  - 再開時期は未定 ◎
- ConoHa WING も 10/24 から新規申し込みを停止している（お知らせの表題だけ確認）○
- ロリポップは全バージョンで `session.upload_progress` を無効にした ○

影響: ロリポップと ConoHa では検証用の新規アカウントを今は取れない。WebDAV を使う配置手段は前提にできない。

## 5. 実機で確かめる項目（インストーラーの診断に入れる）

公式情報では埋まらなかったので、設置時に実機で調べる。

| 分類 | 項目 |
|---|---|
| PHP | 版（8.3 以上か）、実行方式（`php_sapi_name()`） |
| 拡張 | pdo_sqlite と SQLite の版（`sqlite_version()`）、pdo_mysql、mbstring、sodium、gd（WebP/AVIF に対応しているか）、imagick、intl、zip、phar、openssl |
| PHP の設定 | `memory_limit`、`max_execution_time`、`upload_max_filesize`、`post_max_size`、`open_basedir`、`disable_functions`、`allow_url_fopen` |
| ファイル | 公開ディレクトリの外に書けるか、中に書けるか、作ったファイルの権限 |
| HTTP で確かめる（利用者のブラウザから） | データディレクトリが拒否されるか、書き出し先の `.php` が拒否されるか、`.htaccess` の書き換え（rewrite）が効くか、HTTPS が使えるか、PHP の応答がキャッシュされないか |
| 外部通信 | 更新サーバーへの HTTPS 通信ができるか |
| cron | 登録した cron が実際に動いたか（定期的な記録で確かめる） |

### 5.1 診断スクリプト（`tools/diagnose.php`）

上の項目を 1 ファイルで調べる。使い方の詳細はファイル先頭のコメントにある。

1. ファイル先頭の `ACCESS_KEY` を 16 文字以上のランダムな文字列に書き換え、公開ディレクトリにアップロードする
2. ブラウザで開いてキーを入力する。サーバー側の確認と、ブラウザからテスト用ファイルを取得する確認が走る
3. cron も確かめるときは、画面に出る「cron に登録するコマンド」を 5 分間隔で登録し、10 分ほどして再表示する
4. 「共有用の結果」（パス、ホスト名、ユーザー名、IP を伏せた JSON）にサーバー会社とプランを書き添えて保存する
5. 「後片付け」で、テスト用ディレクトリとスクリプト自身を削除する

表の項目に加えて、判断材料として次も調べる。

- SQLite の JSON 関数、FTS5、WAL
- `memory_limit` と実行時間を実行中に延ばせるか
- PHP の実行ユーザーとファイルの所有者が同じか
- cron 用 PHP のパスの候補
- `Options -Indexes` で 500 にならないか

テスト用ディレクトリは、置いた場所の下にできる（`wge-diag-` + 英数字）。外部通信の確認先は、更新サーバーがまだないため `www.php.net` で代用している。

古い PHP が選ばれたドメインでも結果を出せるよう、PHP 7.0 で動く書き方にしてある。

確かめた範囲（2026-10-28、手元の Docker）:

- Apache 2.4 + PHP 8.3（`.htaccess` が効く設定）で、サーバー側の流れ、ブラウザ側の確認、伏せ方、後片付けを確かめた
- `.htaccess` を無視する設定では、データディレクトリの拒否、`.php` の拒否、書き換えが NG になることを確かめた
- PHP 7.0 と 8.5 で、構文チェック、cron モード、診断画面の表示を確かめた。警告は出なかった
- 次は未確認: レンタルサーバーの実機、LiteSpeed、前段キャッシュがある環境、HTTPS 環境

## 6. 次の一手

- （済）さくらを含めて、最低 PHP を 8.3 に決定した（要件定義 D7）
- （済）§5 の項目を調べる診断スクリプトを作った（§5.1）
- 実機検証の手段を用意する。既存アカウントを借りるか、新規受付が再開するのを待つ。各社で診断スクリプトを動かし、共有用の結果を集める
- さくらが PHP 8.4 以降を追加したかを、2027 年中に定期的に確かめる

## 7. 出典

印は本文の確度と同じ。◎ は直接確認したページ。

PHP 本体:
- ◎ [PHP: Supported Versions](https://www.php.net/supported-versions.php)

エックスサーバー:
- ◎ [新サーバー（sv16001 以降）の仕様](https://www.xserver.ne.jp/spec_sv16001.php)
- ◎ [Xアクセラレータ](https://www.xserver.ne.jp/manual/man_server_xaccelerator.php)
- ◎ [500 エラーの FAQ](https://www.xserver.ne.jp/support/faq/service_hp_trouble_500.php)
- ○ [PHP のバージョン切り替え](https://www.xserver.ne.jp/manual/man_program_php_ver.php)、[推奨 PHP を 8.3 へ（お知らせ）](https://www.xserver.ne.jp/news_detail.php?view_id=16195)、[サーバーの仕様](https://www.xserver.ne.jp/manual/man_server_spec.php)、[cron](https://www.xserver.ne.jp/manual/man_program_cron.php)、[フォルダ構成](https://www.xserver.ne.jp/manual/man_server_folder.php)、[public_html の上に置く FAQ](https://www.xserver.ne.jp/support/faq/service_ftp_public_html_folder.php)、[sodium の標準有効化（お知らせ）](https://www.xserver.ne.jp/news_detail.php?view_id=17456)

シン・レンタルサーバー:
- ○ [PHP のバージョン切り替え](https://www.shin-server.jp/support/manual/man_program_php_ver.php)、[サーバーの仕様](https://www.shin-server.jp/support/manual/man_server_spec.php)、[500 エラーの FAQ](https://www.shin-server.jp/support/faq/service_hp_trouble_500.php)、[cron](https://www.shin-server.jp/support/manual/man_program_cron.php)、[フォルダ構成](https://www.shin-server.jp/support/manual/man_server_folder.php)

ConoHa WING:
- ○ [機能一覧](https://www.conoha.jp/wing/function/)、[Web 設定の FAQ](https://support.conoha.jp/wing/faq/website-q/website-setting-q/)、[PHP 設定](https://support.conoha.jp/w/configphp/)、[ジョブスケジューラー](https://support.conoha.jp/w/jobscheduler/)、[FTP アカウント](https://support.conoha.jp/w/ftpaccount/)、[お知らせ一覧](https://www.conoha.jp/wing/news/)

ロリポップ！:
- ◎ [cron 設定](https://lolipop.jp/manual/user/cron/)、[新規申し込みなどの一時停止（お知らせ）](https://lolipop.jp/info/news/8246/)
- ○ [料金・プラン比較](https://lolipop.jp/pricing/)、[サーバーの仕様](https://lolipop.jp/service/server-spec/)、[CGI・SSI・PHP の仕様](https://lolipop.jp/manual/hp/cgi/)、[PHP 設定](https://lolipop.jp/manual/user/php-setting/)、[PHP 脆弱性への対応の経緯（お知らせ）](https://lolipop.jp/info/news/8239/)
- △ [公開フォルダの FAQ](https://support.lolipop.jp/hc/ja/articles/360048391094)
- 非公式 [独自ドメインの公開フォルダについての質問（WordPress 日本語フォーラム）](https://ja.wordpress.org/support/topic/%E3%83%AD%E3%83%AA%E3%83%9D%E3%83%83%E3%83%97%E3%81%A7%E3%81%AE%E7%8B%AC%E8%87%AA%E3%83%89%E3%83%A1%E3%82%A4%E3%83%B3%E8%A8%AD%E5%AE%9A%E3%81%AB%E3%81%A4%E3%81%84%E3%81%A6/)

さくらのレンタルサーバ:
- ◎ [PHP の脆弱性対応のお知らせ（選べる版の一覧を含む）](https://help.sakura.ad.jp/notification/n-2701)、[PHP の提供履歴](https://help.sakura.ad.jp/rs/2246/)
- ○ [基本仕様](https://help.sakura.ad.jp/rs/2251/)、[PHP のバージョン変更](https://help.sakura.ad.jp/rs/2241/)、[.htaccess](https://help.sakura.ad.jp/rs/2214/)、[cron](https://help.sakura.ad.jp/rs/2242/)

mixhost:
- ○ [プラン比較](https://mixhost.jp/hosting/web/)、[PHP のバージョン変更](https://help.mixhost.jp/articles/4407579533849)、[Web サーバーの仕様](https://help.mixhost.jp/articles/115003735831)、[データベースの仕様](https://help.mixhost.jp/articles/115003742232)、[cron](https://help.mixhost.jp/articles/115003740612)
