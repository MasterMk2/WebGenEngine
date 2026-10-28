<?php
/*
 * WebGenEngine サーバー診断スクリプト
 *
 * レンタルサーバーが WebGenEngine の動作要件を満たすかを調べる。
 * 調べる項目は docs/research/rental-servers-2026-10.md の §5 に対応する。
 *
 * 使い方
 *   1. 下の ACCESS_KEY を、16 文字以上のランダムな文字列に書き換える
 *   2. 調べたいサイトの公開ディレクトリに、FTP などでアップロードする
 *   3. ブラウザでこのファイルを開き、ACCESS_KEY を入力する
 *   4. cron も確かめる場合は、画面の「cron に登録するコマンド」をサーバーの管理画面で登録し、
 *      10 分ほど待ってから再表示する
 *   5. 「共有用の結果」をコピーして保存する。パス、ホスト名、IP アドレスは伏せてある
 *   6. 終わったら「後片付け」で、テスト用ファイルとこのスクリプトを削除する
 *
 * 置いたディレクトリの下に「wge-diag-（英数字）」というテスト用ディレクトリを作る。
 * 外への通信の確認で、OUTBOUND_TEST_URL に 1 ページの表示につき最大 2 回アクセスする。
 * 古い PHP が選ばれているサイトでも結果を出せるよう、PHP 7.0 で動く書き方にしてある。
 */

// 16 文字以上のランダムな文字列に書き換える。空のままでは動かない。
const ACCESS_KEY = '';

// 外への HTTPS 通信の確認先。PHP の最新版の情報（JSON）を返す。
const OUTBOUND_TEST_URL = 'https://www.php.net/releases/index.php?json&version=8';

const TOOL_VERSION = '1';
const MIN_PHP_VERSION = '8.3.0';
const MIN_SQLITE_VERSION = '3.26.0';
const COOKIE_NAME = 'wge_diag';
// テスト用の PHP ファイルが実行されたときだけ応答に出る文字列。そのファイルのソースには、この形では現れない。
const PROBE_MARKER = 'WGE-PROBE-EXECUTED-42';

error_reporting(E_ALL);
// PHP 8 では、無効化された関数を呼ぶと止まる。以下でも無効化されうる関数は function_exists で確かめる
if (function_exists('ini_set')) {
    ini_set('display_errors', '0');
}

if (wge_is_command_line()) {
    wge_run_cron_mode();
    exit(0);
}

$wge_action = isset($_GET['action']) && is_string($_GET['action']) ? $_GET['action'] : '';
if ($wge_action === 'nonce') {
    wge_handle_nonce();
    exit;
}

wge_send_security_headers();

if (!wge_key_configured()) {
    wge_render_setup_page();
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    wge_handle_post();
    exit;
}
if (!wge_is_authed()) {
    wge_render_login_page('');
    exit;
}
wge_render_report_page();
exit;


/* ---------- 起動と認証 ---------- */

function wge_is_command_line()
{
    // ロリポップの cron は CGI 版の PHP で動かすので、SAPI 名だけでは判定できない
    return PHP_SAPI === 'cli' || !isset($_SERVER['REQUEST_METHOD']);
}

function wge_key_configured()
{
    return strlen(ACCESS_KEY) >= 16;
}

function wge_id()
{
    return substr(hash('sha256', ACCESS_KEY . '|' . __FILE__), 0, 16);
}

function wge_work_dir_name()
{
    return 'wge-diag-' . wge_id();
}

function wge_work_dir()
{
    return __DIR__ . '/' . wge_work_dir_name();
}

function wge_auth_token($expires)
{
    return $expires . '.' . hash_hmac('sha256', 'auth|' . wge_id() . '|' . $expires, ACCESS_KEY);
}

function wge_csrf_value()
{
    return hash_hmac('sha256', 'csrf|' . wge_id(), ACCESS_KEY);
}

function wge_is_authed()
{
    $v = isset($_COOKIE[COOKIE_NAME]) && is_string($_COOKIE[COOKIE_NAME]) ? $_COOKIE[COOKIE_NAME] : '';
    if (!preg_match('/^([0-9]{1,12})\./', $v, $m) || (int)$m[1] < time()) {
        return false;
    }
    return hash_equals(wge_auth_token((int)$m[1]), $v);
}

function wge_valid_csrf()
{
    $t = isset($_POST['csrf']) && is_string($_POST['csrf']) ? $_POST['csrf'] : '';
    return hash_equals(wge_csrf_value(), $t);
}

function wge_self_path()
{
    $p = isset($_SERVER['SCRIPT_NAME']) ? (string)$_SERVER['SCRIPT_NAME'] : '';
    return preg_match('#^/[A-Za-z0-9/._~-]*$#', $p) ? $p : '';
}

function wge_request_is_https()
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
        return true;
    }
    return isset($_SERVER['SERVER_PORT']) && (string)$_SERVER['SERVER_PORT'] === '443';
}

function wge_set_cookie($value, $maxAge)
{
    // SameSite を付けるため、PHP 7.3 未満でも使える Set-Cookie ヘッダーを直接書く
    $parts = array(COOKIE_NAME . '=' . $value, 'Max-Age=' . (int)$maxAge, 'HttpOnly', 'SameSite=Strict');
    $path = wge_self_path();
    if ($path !== '') {
        $parts[] = 'Path=' . $path;
    }
    if (wge_request_is_https()) {
        $parts[] = 'Secure';
    }
    header('Set-Cookie: ' . implode('; ', $parts), false);
}

function wge_redirect_self()
{
    header('Location: ' . rawurlencode(basename(__FILE__)), true, 303);
}

function wge_send_security_headers()
{
    header('Cache-Control: no-store, private');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header('X-Robots-Tag: noindex, nofollow');
}

function wge_handle_post()
{
    $op = isset($_POST['op']) && is_string($_POST['op']) ? $_POST['op'] : '';

    if ($op === 'login') {
        $key = isset($_POST['key']) && is_string($_POST['key']) ? $_POST['key'] : '';
        if (hash_equals(ACCESS_KEY, $key)) {
            wge_set_cookie(wge_auth_token(time() + 3600), 3600);
            wge_redirect_self();
            return;
        }
        sleep(1);
        wge_render_login_page('キーが違います。');
        return;
    }

    if (!wge_is_authed() || !wge_valid_csrf()) {
        http_response_code(403);
        wge_render_login_page('もう一度キーを入力してください。');
        return;
    }

    if ($op === 'logout') {
        wge_set_cookie('', 0);
        wge_redirect_self();
        return;
    }
    if ($op === 'cleanup') {
        wge_remove_tree(wge_work_dir());
        wge_render_message_page('テスト用ファイルを削除しました。', true);
        return;
    }
    if ($op === 'selfdelete') {
        wge_remove_tree(wge_work_dir());
        wge_set_cookie('', 0);
        $deleted = @unlink(__FILE__);
        wge_render_message_page(
            $deleted
                ? 'テスト用ファイルとこのスクリプトを削除しました。'
                : 'テスト用ファイルは削除しましたが、このスクリプトは削除できませんでした。FTP で削除してください。',
            false
        );
        return;
    }
    wge_redirect_self();
}

// 前段キャッシュの確認用。認証なしで、毎回違う値を返す。
function wge_handle_nonce()
{
    $plain = isset($_GET['mode']) && $_GET['mode'] === 'plain';
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('X-Content-Type-Options: nosniff');
    if (!$plain) {
        header('Cache-Control: no-store, private');
    }
    echo json_encode(array(
        'nonce' => bin2hex(random_bytes(8)),
        'mode' => $plain ? 'plain' : 'nostore',
    ));
}

// cron から呼ばれたときは、動いた記録だけを残して終わる
function wge_run_cron_mode()
{
    if (!wge_key_configured()) {
        return;
    }
    $dir = wge_work_dir() . '/deny';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        return;
    }
    if (!is_file($dir . '/.htaccess')) {
        @file_put_contents($dir . '/.htaccess', "Require all denied\n");
    }
    $state = array(
        'time' => time(),
        'php_version' => PHP_VERSION,
        'sapi' => PHP_SAPI,
        'binary' => defined('PHP_BINARY') ? PHP_BINARY : '',
    );
    @file_put_contents($dir . '/cron.json', json_encode($state), LOCK_EX);
}


/* ---------- テスト用ファイル ---------- */

function wge_prepare_work_dir()
{
    $probe = "<?php echo 'WGE-PROBE-' . 'EXECUTED-' . (6 * 7);\n";
    $files = array(
        'plain/secret.txt' => "WGE-PLAIN-OK\n",
        'deny/.htaccess' => "Require all denied\n",
        'deny/secret.txt' => "WGE-DENY-LEAKED\n",
        'exec/probe.php' => $probe,
        'nophp/.htaccess' => '<FilesMatch "(?i)\.(php[0-9]*|phtml|phar)$">' . "\n    Require all denied\n</FilesMatch>\n",
        'nophp/probe.php' => $probe,
        'nophp/ok.html' => "<!doctype html><title>ok</title><p>WGE-STATIC-OK</p>\n",
        'rewrite/.htaccess' => "RewriteEngine On\nRewriteRule ^virtual$ target.txt [L]\n",
        'rewrite/target.txt' => "WGE-REWRITE-OK\n",
        'opts/.htaccess' => "Options -Indexes\n",
        'opts/ok.txt' => "WGE-OPTIONS-OK\n",
    );
    $failed = array();
    foreach ($files as $rel => $content) {
        $path = wge_work_dir() . '/' . $rel;
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            $failed[] = $rel;
            continue;
        }
        if (@file_put_contents($path, $content) === false) {
            $failed[] = $rel;
            continue;
        }
        if (function_exists('chmod')) {
            // umask によっては Web サーバーが読めない権限になるので、公開ファイルの標準に揃える
            @chmod($path, 0644);
            @chmod($dir, 0755);
        }
    }
    if (function_exists('chmod')) {
        @chmod(wge_work_dir(), 0755);
    }
    return $failed;
}

function wge_remove_tree($dir)
{
    if (!is_dir($dir) || is_link($dir)) {
        return;
    }
    $entries = @scandir($dir);
    if ($entries === false) {
        return;
    }
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir . '/' . $entry;
        if (is_dir($path) && !is_link($path)) {
            wge_remove_tree($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}


/* ---------- 小さな道具 ---------- */

function wge_h($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function wge_add(&$rows, $group, $item, $status, $value, $note = '', $sensitive = '')
{
    $rows[] = array(
        'group' => $group,
        'item' => $item,
        'status' => $status,
        'value' => (string)$value,
        'note' => (string)$note,
        'sensitive' => $sensitive,
    );
}

function wge_bytes($value)
{
    $value = trim((string)$value);
    if ($value === '') {
        return 0;
    }
    if ($value === '-1') {
        return -1;
    }
    $number = (float)$value;
    switch (strtolower(substr($value, -1))) {
        case 'g':
            $number *= 1024;
            // 下の単位の倍率も重ねる
        case 'm':
            $number *= 1024;
        case 'k':
            $number *= 1024;
    }
    return (int)$number;
}

function wge_human_bytes($bytes)
{
    $units = array('B', 'KB', 'MB', 'GB', 'TB');
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return round($bytes, 1) . ' ' . $units[$i];
}

function wge_human_age($seconds)
{
    if ($seconds < 120) {
        return $seconds . ' 秒';
    }
    if ($seconds < 7200) {
        return floor($seconds / 60) . ' 分';
    }
    return floor($seconds / 3600) . ' 時間';
}

function wge_last_error($fallback)
{
    $e = error_get_last();
    return ($e && isset($e['message'])) ? $e['message'] : $fallback;
}

function wge_is_inside($path, $base)
{
    $base = rtrim($base, '/') . '/';
    return strncmp(rtrim($path, '/') . '/', $base, strlen($base)) === 0;
}

function wge_docroot()
{
    $raw = isset($_SERVER['DOCUMENT_ROOT']) ? (string)$_SERVER['DOCUMENT_ROOT'] : '';
    if ($raw === '') {
        return '';
    }
    $real = @realpath($raw);
    return rtrim($real !== false ? $real : $raw, '/');
}

function wge_home_dir()
{
    $home = getenv('HOME');
    if ((!is_string($home) || $home === '') && function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
        $pw = @posix_getpwuid(posix_geteuid());
        if (is_array($pw) && !empty($pw['dir'])) {
            $home = $pw['dir'];
        }
    }
    return is_string($home) ? rtrim($home, '/') : '';
}

function wge_process_user_name()
{
    if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
        $pw = @posix_getpwuid(posix_geteuid());
        if (is_array($pw) && isset($pw['name'])) {
            return (string)$pw['name'];
        }
    }
    return function_exists('get_current_user') ? (string)get_current_user() : '';
}

function wge_shell_quote($s)
{
    return preg_match('#^[A-Za-z0-9/._~-]+$#', $s) ? $s : "'" . str_replace("'", "'\\''", $s) . "'";
}


/* ---------- サーバー側の確認 ---------- */

function wge_collect_server_checks($workFailed)
{
    $rows = array();
    $checks = array(
        'wge_check_php', 'wge_check_database', 'wge_check_extensions', 'wge_check_images',
        'wge_check_ini', 'wge_check_outbound', 'wge_check_request', 'wge_check_cron',
    );
    foreach ($checks as $check) {
        try {
            $check($rows);
        } catch (Throwable $e) {
            wge_add($rows, '診断の失敗', $check, 'ng', get_class($e) . ': ' . $e->getMessage());
        }
    }
    try {
        wge_check_files($rows, $workFailed);
    } catch (Throwable $e) {
        wge_add($rows, '診断の失敗', 'wge_check_files', 'ng', get_class($e) . ': ' . $e->getMessage());
    }
    return $rows;
}

function wge_check_php(&$rows)
{
    $g = 'PHP';
    $ok = version_compare(PHP_VERSION, MIN_PHP_VERSION, '>=');
    wge_add($rows, $g, 'バージョン', $ok ? 'ok' : 'ng', PHP_VERSION,
        $ok ? '' : '最低 ' . MIN_PHP_VERSION . '（要件 D7）。サーバーの管理画面で、このドメインの PHP を切り替える');
    wge_add($rows, $g, '実行方式（SAPI）', 'info', PHP_SAPI);

    $os = defined('PHP_OS_FAMILY') ? PHP_OS_FAMILY . ' / ' . PHP_OS : PHP_OS;
    if (function_exists('php_uname')) {
        $os .= ' / ' . php_uname('s') . ' ' . php_uname('r');
    }
    wge_add($rows, $g, 'OS', 'info', $os);
    wge_add($rows, $g, 'Web サーバー', 'info', isset($_SERVER['SERVER_SOFTWARE']) ? $_SERVER['SERVER_SOFTWARE'] : '（不明）');
    wge_add($rows, $g, 'PHP の実行ファイル', 'info', defined('PHP_BINARY') && PHP_BINARY !== '' ? PHP_BINARY : '（不明）');

    $opcache = ini_get('opcache.enable');
    $opcacheOn = $opcache === '1' || strtolower((string)$opcache) === 'on';
    wge_add($rows, $g, 'OPcache', 'info', $opcache === false ? '拡張なし' : ($opcacheOn ? '有効' : '無効'));
}

function wge_check_database(&$rows)
{
    $g = 'データベース';
    $drivers = class_exists('PDO') ? PDO::getAvailableDrivers() : array();
    $hasSqlite = in_array('sqlite', $drivers, true);
    $hasMysql = in_array('mysql', $drivers, true);
    wge_add($rows, $g, 'PDO のドライバー', 'info', $drivers ? implode(', ', $drivers) : '（なし）');

    if ($hasSqlite) {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $version = (string)$pdo->query('SELECT sqlite_version()')->fetchColumn();
        $versionOk = version_compare($version, MIN_SQLITE_VERSION, '>=');
        wge_add($rows, $g, 'pdo_sqlite', 'ok', '使える');
        wge_add($rows, $g, 'SQLite の版', $versionOk ? 'ok' : ($hasMysql ? 'warn' : 'ng'), $version,
            $versionOk ? '' : '最低 ' . MIN_SQLITE_VERSION . '。MySQL/MariaDB に切り替える');
        $features = array(
            'JSON 関数 ' . (wge_sqlite_try($pdo, "SELECT json_valid('{}')") ? 'あり' : 'なし'),
            'FTS5 ' . (wge_sqlite_try($pdo, 'CREATE VIRTUAL TABLE temp.wge_fts USING fts5(x)') ? 'あり' : 'なし'),
        );
        wge_add($rows, $g, 'SQLite の機能', 'info', implode(' / ', $features), 'サイト内検索（v2）で FTS5 を使えるかの判断材料');
    } else {
        wge_add($rows, $g, 'pdo_sqlite', $hasMysql ? 'warn' : 'ng', '使えない',
            $hasMysql ? 'MySQL/MariaDB に切り替える。設置時にデータベースの作成が要る' : '');
    }

    wge_add($rows, $g, 'pdo_mysql', $hasMysql ? 'ok' : ($hasSqlite ? 'info' : 'ng'), $hasMysql ? '使える' : '使えない',
        (!$hasMysql && !$hasSqlite) ? 'SQLite も MySQL も使えない' : '');

    if (class_exists('SQLite3')) {
        $v = SQLite3::version();
        wge_add($rows, $g, 'sqlite3 拡張', 'info', isset($v['versionString']) ? 'あり（' . $v['versionString'] . '）' : 'あり');
    } else {
        wge_add($rows, $g, 'sqlite3 拡張', 'info', 'なし');
    }
}

function wge_sqlite_try($pdo, $sql)
{
    try {
        $pdo->exec($sql);
        return true;
    } catch (Exception $e) {
        return false;
    }
}

function wge_check_extensions(&$rows)
{
    $g = '拡張';
    // 名前、ないときの判定、ないときのメモ
    $list = array(
        array('mbstring', 'ng', '日本語の処理に要る'),
        array('json', 'ng', ''),
        array('dom', 'ng', 'WordPress からの移行（WXR の読み込み）に要る'),
        array('xmlreader', 'ng', 'WordPress からの移行（大きな WXR の読み込み）に要る'),
        array('fileinfo', 'warn', 'アップロードされたファイルの種類の判定に使う。なければ自前で判定する'),
        array('sodium', 'warn', '更新の署名検証に使う。なければ同梱の純 PHP 実装で代替する'),
        array('openssl', 'warn', '外への HTTPS 通信に使う。なければ更新は FTP でのアップロードになる'),
        array('curl', 'info', '外への通信に使う。openssl があれば、なくても通信できる'),
        array('zip', 'warn', '更新パッケージの展開に使う。phar があれば代替できる'),
        array('phar', 'warn', '更新パッケージの展開に使う。zip があれば代替できる'),
        array('zlib', 'info', ''),
        array('intl', 'info', '文字の正規化などに使う（任意）'),
        array('exif', 'info', '写真の向きや位置情報の処理に使う（任意）'),
        array('iconv', 'info', ''),
    );
    foreach ($list as $ext) {
        $loaded = extension_loaded($ext[0]);
        $value = $loaded ? 'あり' : 'なし';
        if ($ext[0] === 'intl' && defined('INTL_ICU_VERSION')) {
            $value .= '（ICU ' . INTL_ICU_VERSION . '）';
        }
        if ($ext[0] === 'sodium' && defined('SODIUM_LIBRARY_VERSION')) {
            $value .= '（libsodium ' . SODIUM_LIBRARY_VERSION . '）';
        }
        if ($ext[0] === 'openssl' && defined('OPENSSL_VERSION_TEXT')) {
            $value .= '（' . OPENSSL_VERSION_TEXT . '）';
        }
        wge_add($rows, $g, $ext[0], $loaded ? 'ok' : $ext[1], $value, $loaded ? '' : $ext[2]);
    }
    if (!extension_loaded('zip') && !extension_loaded('phar')) {
        wge_add($rows, $g, 'アーカイブの展開', 'warn', '手段なし', '更新は、本体一式を FTP でアップロードする方式になる');
    }
}

function wge_check_images(&$rows)
{
    $g = '画像';
    if (function_exists('gd_info')) {
        $info = gd_info();
        wge_add($rows, $g, 'GD', 'ok', isset($info['GD Version']) ? $info['GD Version'] : 'あり');
        $formats = array();
        foreach (array('JPEG' => 'imagejpeg', 'PNG' => 'imagepng', 'WebP' => 'imagewebp', 'AVIF' => 'imageavif') as $name => $fn) {
            $formats[$name] = wge_gd_can_encode($fn);
        }
        $modern = $formats['WebP'] && $formats['AVIF'];
        wge_add($rows, $g, 'GD で書き出せる形式', $formats['WebP'] ? ($modern ? 'ok' : 'info') : 'warn', wge_format_flags($formats),
            $modern ? '' : '書き出せない形式は、管理画面（ブラウザ）側で変換する案で補う');
    } else {
        wge_add($rows, $g, 'GD', 'warn', 'なし', '画像の縮小や変換は、Imagick か管理画面（ブラウザ）側の変換で補う');
    }

    if (class_exists('Imagick')) {
        $version = Imagick::getVersion();
        $formats = array();
        foreach (array('WebP' => 'WEBP', 'AVIF' => 'AVIF') as $name => $pattern) {
            $formats[$name] = count(Imagick::queryFormats($pattern)) > 0;
        }
        wge_add($rows, $g, 'Imagick', 'info',
            (isset($version['versionString']) ? $version['versionString'] : 'あり') . ' / ' . wge_format_flags($formats));
    } else {
        wge_add($rows, $g, 'Imagick', 'info', 'なし');
    }
}

function wge_gd_can_encode($fn)
{
    if (!function_exists($fn) || !function_exists('imagecreatetruecolor')) {
        return false;
    }
    $image = @imagecreatetruecolor(4, 4);
    if (!$image) {
        return false;
    }
    ob_start();
    $ok = @$fn($image);
    $data = ob_get_clean();
    return $ok && strlen($data) > 0;
}

function wge_format_flags($formats)
{
    $parts = array();
    foreach ($formats as $name => $ok) {
        $parts[] = $name . ($ok ? ' ○' : ' ×');
    }
    return implode(' / ', $parts);
}

function wge_check_ini(&$rows)
{
    $g = 'PHP の設定';
    $mb = 1024 * 1024;

    $memory = (string)ini_get('memory_limit');
    $memoryBytes = wge_bytes($memory);
    $memoryLow = $memoryBytes >= 0 && $memoryBytes < 128 * $mb;
    wge_add($rows, $g, 'memory_limit', $memoryLow ? 'warn' : 'info', $memory,
        $memoryLow ? '128M 未満。大きな画像の処理や書き出しで足りなくなりうる' : '');

    $maxTime = (int)ini_get('max_execution_time');
    wge_add($rows, $g, 'max_execution_time', ($maxTime > 0 && $maxTime < 30) ? 'warn' : 'info',
        $maxTime === 0 ? '0（無制限）' : $maxTime . ' 秒', 'Web サーバー側の時間制限は別にあり、ここでは測れない');

    $upload = (string)ini_get('upload_max_filesize');
    $post = (string)ini_get('post_max_size');
    $postSmall = wge_bytes($post) >= 0 && wge_bytes($post) < wge_bytes($upload);
    wge_add($rows, $g, 'upload_max_filesize', 'info', $upload);
    wge_add($rows, $g, 'post_max_size', $postSmall ? 'warn' : 'info', $post,
        $postSmall ? 'upload_max_filesize より小さい。実際の上限はこちらになる' : '');
    wge_add($rows, $g, 'max_file_uploads', 'info', (string)ini_get('max_file_uploads'));

    $uploads = (string)ini_get('file_uploads');
    $uploadsOn = $uploads === '1' || strtolower($uploads) === 'on';
    wge_add($rows, $g, 'file_uploads', $uploadsOn ? 'ok' : 'ng', $uploadsOn ? '有効' : '無効', $uploadsOn ? '' : '画像をアップロードできない');

    $urlFopen = (bool)ini_get('allow_url_fopen');
    wge_add($rows, $g, 'allow_url_fopen', 'info', $urlFopen ? '有効' : '無効', $urlFopen ? '' : '外への通信は curl に頼る');

    $openBasedir = (string)ini_get('open_basedir');
    wge_add($rows, $g, 'open_basedir', 'info', $openBasedir === '' ? '（設定なし）' : $openBasedir);
    $disabled = trim((string)ini_get('disable_functions'));
    wge_add($rows, $g, 'disable_functions', 'info', $disabled === '' ? '（なし）' : $disabled);
    $progress = (string)ini_get('session.upload_progress.enabled');
    wge_add($rows, $g, 'session.upload_progress.enabled', 'info', ($progress === '1' || strtolower($progress) === 'on') ? '有効' : '無効',
        'アップロードの進捗表示はこれに頼らず、ブラウザ側で出す');
    $timezone = (string)ini_get('date.timezone');
    wge_add($rows, $g, 'date.timezone', 'info', $timezone === '' ? '（未設定。UTC になる）' : $timezone);

    // 処理を分けずに済む範囲を知るため、実行中に上限を上げられるかを試す
    if ($memoryBytes < 0 || $memoryBytes >= 256 * $mb) {
        wge_add($rows, $g, 'memory_limit を実行中に上げられるか', 'info', '確認不要（すでに 256M 以上か無制限）');
    } elseif (!function_exists('ini_set')) {
        wge_add($rows, $g, 'memory_limit を実行中に上げられるか', 'warn', 'ini_set が無効', '処理を細かく分ける');
    } else {
        $result = @ini_set('memory_limit', '256M');
        $raised = $result !== false && wge_bytes(ini_get('memory_limit')) >= 256 * $mb;
        @ini_set('memory_limit', $memory);
        wge_add($rows, $g, 'memory_limit を実行中に上げられるか', $raised ? 'ok' : 'warn',
            $raised ? '256M まで上げられた' : '上げられない', $raised ? '' : '処理を細かく分ける');
    }
    if (function_exists('set_time_limit')) {
        $result = @set_time_limit(60);
        $after = (int)ini_get('max_execution_time');
        $extended = $result !== false && $after === 60;
        wge_add($rows, $g, 'set_time_limit で延ばせるか', $extended ? 'ok' : 'warn',
            $extended ? '60 秒に延ばせた' : '延ばせない（' . $after . ' 秒のまま）', $extended ? '' : '処理を細かく分ける');
    } else {
        wge_add($rows, $g, 'set_time_limit で延ばせるか', 'warn', 'set_time_limit が無効', '処理を細かく分ける');
    }
}

function wge_check_files(&$rows, $workFailed)
{
    $g = 'ファイル';
    $docroot = wge_docroot();
    wge_add($rows, $g, '公開ディレクトリ（DOCUMENT_ROOT）', 'info', $docroot !== '' ? $docroot : '（不明）');
    $inside = $docroot !== '' && wge_is_inside(__DIR__, $docroot);
    wge_add($rows, $g, 'このスクリプトの場所', 'info', __DIR__,
        $inside || $docroot === '' ? '' : 'DOCUMENT_ROOT の外にある。公開ディレクトリの判定が当てにならない');
    wge_add($rows, $g, '公開ディレクトリの中に書けるか', $workFailed ? 'ng' : 'ok',
        $workFailed ? '書けない: ' . implode(', ', $workFailed) : '書ける（テスト用ディレクトリを作った）');

    // 権限を直す前の既定の値を見るため、毎回作り直す
    $modeFile = wge_work_dir() . '/plain/mode.txt';
    $modeDir = wge_work_dir() . '/plain/mode-dir';
    @unlink($modeFile);
    @rmdir($modeDir);
    if (@file_put_contents($modeFile, 'x') !== false && @mkdir($modeDir, 0755)) {
        wge_add($rows, $g, '作ったファイルの権限（既定）', 'info',
            'ファイル ' . substr(sprintf('%o', fileperms($modeFile)), -4)
            . ' / ディレクトリ ' . substr(sprintf('%o', fileperms($modeDir)), -4)
            . ' / umask ' . sprintf('%04o', umask()),
            'ディレクトリは mkdir(0755) で作った値。Web サーバーが読めない権限なら、本体が 0644/0755 に直す');
        $sameOwner = @fileowner($modeFile) === @fileowner(__FILE__);
        wge_add($rows, $g, 'PHP の実行ユーザー', 'info',
            wge_process_user_name() . ($sameOwner ? '（このスクリプトの所有者と同じ）' : '（このスクリプトの所有者と違う）'),
            $sameOwner ? '' : 'PHP が別のユーザーで動いている。PHP が作ったファイルを FTP で消せないことがある');
    }
    @unlink($modeFile);
    @rmdir($modeDir);

    $writableOutside = null;
    $candidates = wge_outside_candidates($docroot);
    if (!$candidates) {
        wge_add($rows, $g, '公開ディレクトリの外に書けるか', 'warn', '試せる場所が見つからない');
    }
    foreach ($candidates as $label => $dir) {
        $result = wge_try_write_dir($dir);
        if ($result === true) {
            if ($writableOutside === null) {
                $writableOutside = $dir;
            }
            wge_add($rows, $g, '外に書けるか（' . $label . '）', 'ok', $dir . ' に書ける');
        } else {
            wge_add($rows, $g, '外に書けるか（' . $label . '）', 'warn', $dir . ' に書けない（' . $result . '）',
                'データは公開ディレクトリの中に置き、アクセス拒否で守る');
        }
    }
    wge_add($rows, $g, '注意', 'info', '外に書けても安全とは限らない',
        'ロリポップのように、公開ディレクトリの外が別のドメインの公開領域に当たるサーバーがある。アクセス拒否の設定は省かない');

    wge_check_sqlite_file($rows, $writableOutside);

    if (function_exists('disk_free_space')) {
        $free = @disk_free_space(__DIR__);
        if ($free !== false) {
            wge_add($rows, $g, '空き容量（参考）', 'info', wge_human_bytes($free), '共用サーバーでは契約の容量と一致しないことがある');
        }
    }
}

function wge_outside_candidates($docroot)
{
    $candidates = array();
    if ($docroot !== '') {
        $parent = dirname($docroot);
        if ($parent !== $docroot && $parent !== '/' && $parent !== '.') {
            $candidates['公開ディレクトリの 1 つ上'] = $parent;
        }
    }
    $home = wge_home_dir();
    if ($home !== '' && $home !== '/' && !in_array($home, $candidates, true)
        && ($docroot === '' || !wge_is_inside($home, $docroot))) {
        $candidates['ホームディレクトリ'] = $home;
    }
    return $candidates;
}

function wge_outside_probe_dir($parent)
{
    return rtrim($parent, '/') . '/wge-diag-' . wge_id() . '-outside';
}

function wge_try_write_dir($parent)
{
    $dir = wge_outside_probe_dir($parent);
    error_clear_last();
    if (!@is_dir($dir) && !@mkdir($dir, 0755)) {
        return wge_last_error('ディレクトリを作れない');
    }
    $file = $dir . '/probe.txt';
    $ok = @file_put_contents($file, 'ok') === 2 && @file_get_contents($file) === 'ok';
    $error = $ok ? '' : wge_last_error('ファイルを書けない');
    @unlink($file);
    @rmdir($dir);
    return $ok ? true : $error;
}

function wge_check_sqlite_file(&$rows, $outsideParent)
{
    $g = 'データベース';
    if (!class_exists('PDO') || !in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        return;
    }
    if ($outsideParent !== null) {
        $dir = wge_outside_probe_dir($outsideParent);
        $where = '公開ディレクトリの外';
    } else {
        $dir = wge_work_dir() . '/deny';
        $where = 'テスト用ディレクトリ（アクセス拒否）';
    }
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        wge_add($rows, $g, 'SQLite のファイル', 'warn', $where . ' にディレクトリを作れない');
        return;
    }
    $file = $dir . '/test.sqlite';
    try {
        $pdo = new PDO('sqlite:' . $file);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $mode = strtolower((string)$pdo->query('PRAGMA journal_mode=WAL')->fetchColumn());
        $pdo->exec('CREATE TABLE IF NOT EXISTS t (id INTEGER PRIMARY KEY, v TEXT)');
        $pdo->exec("INSERT INTO t (v) VALUES ('ok')");
        $count = (int)$pdo->query('SELECT COUNT(*) FROM t')->fetchColumn();
        $pdo = null;
        $wal = $mode === 'wal';
        wge_add($rows, $g, 'SQLite のファイル', $count > 0 ? ($wal ? 'ok' : 'warn') : 'ng',
            '読み書きできる / ジャーナル方式: ' . $mode . '（' . $where . '）',
            $wal ? '' : 'WAL が使えない。既定のジャーナル方式で動かす');
    } catch (Exception $e) {
        wge_add($rows, $g, 'SQLite のファイル', 'ng', '読み書きできない: ' . $e->getMessage());
    }
    foreach (array('', '-wal', '-shm', '-journal') as $suffix) {
        @unlink($file . $suffix);
    }
    if ($outsideParent !== null) {
        @rmdir($dir);
    }
}

function wge_check_outbound(&$rows)
{
    $g = '外部通信';
    $host = (string)parse_url(OUTBOUND_TEST_URL, PHP_URL_HOST);
    $userAgent = 'WebGenEngine-diagnose/' . TOOL_VERSION;

    if (ini_get('allow_url_fopen') && extension_loaded('openssl')) {
        $context = stream_context_create(array(
            'http' => array('timeout' => 6, 'ignore_errors' => true, 'header' => 'User-Agent: ' . $userAgent . "\r\n"),
            'ssl' => array('verify_peer' => true, 'verify_peer_name' => true),
        ));
        error_clear_last();
        $start = microtime(true);
        $body = false;
        $headers = array();
        $fp = @fopen(OUTBOUND_TEST_URL, 'rb', false, $context);
        if ($fp) {
            // 応答ヘッダーは wrapper_data から読む（$http_response_header は PHP 8.5 で非推奨）
            $meta = stream_get_meta_data($fp);
            $headers = isset($meta['wrapper_data']) && is_array($meta['wrapper_data']) ? $meta['wrapper_data'] : array();
            $body = stream_get_contents($fp);
            fclose($fp);
        }
        $ms = (int)((microtime(true) - $start) * 1000);
        $status = wge_last_status_line($headers);
        if ($body !== false && strpos($status, ' 200') !== false) {
            $latest = wge_latest_php($body);
            wge_add($rows, $g, 'HTTPS（PHP のストリーム）', 'ok',
                $host . ' に接続できた（' . $ms . ' ms）' . ($latest !== '' ? ' / PHP 8 の最新版: ' . $latest : ''));
        } else {
            wge_add($rows, $g, 'HTTPS（PHP のストリーム）', 'warn',
                '接続できない' . ($status !== '' ? '（' . $status . '）' : '') . ' ' . wge_last_error(''),
                '自動更新は、本体一式の FTP アップロードで代替する');
        }
    } else {
        wge_add($rows, $g, 'HTTPS（PHP のストリーム）', 'warn', 'allow_url_fopen か openssl がない', 'curl があればそちらを使う');
    }

    if (function_exists('curl_init') && function_exists('curl_exec')) {
        $ch = curl_init(OUTBOUND_TEST_URL);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 6,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => $userAgent,
        ));
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        unset($ch);
        wge_add($rows, $g, 'HTTPS（curl）', ($body !== false && $code === 200) ? 'ok' : 'warn',
            ($body !== false && $code === 200) ? $host . ' に接続できた' : '接続できない（' . ($error !== '' ? $error : 'HTTP ' . $code) . '）');
    } else {
        wge_add($rows, $g, 'HTTPS（curl）', 'info', 'curl が使えない');
    }
}

function wge_last_status_line($headers)
{
    $status = '';
    foreach ($headers as $line) {
        if (strncmp($line, 'HTTP/', 5) === 0) {
            $status = $line;
        }
    }
    return $status;
}

function wge_latest_php($body)
{
    $data = json_decode((string)$body, true);
    return (is_array($data) && isset($data['version']) && is_string($data['version'])) ? $data['version'] : '';
}

function wge_check_request(&$rows)
{
    $g = 'HTTPS とアクセス元の判定';
    $https = wge_request_is_https();
    wge_add($rows, $g, 'PHP から見た HTTPS', $https ? 'ok' : 'info', $https ? 'HTTPS と判定' : 'HTTP と判定',
        '前段のプロキシがあると、ブラウザ側の判定（「ブラウザから確かめる」）と食い違うことがある');
    foreach (array('HTTPS', 'REQUEST_SCHEME', 'SERVER_PORT', 'HTTP_X_FORWARDED_PROTO', 'HTTP_X_FORWARDED_SSL', 'HTTP_X_FORWARDED_PORT') as $key) {
        if (isset($_SERVER[$key])) {
            wge_add($rows, $g, '$_SERVER[' . $key . ']', 'info', (string)$_SERVER[$key]);
        }
    }
    $ipKeys = array('REMOTE_ADDR', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP', 'HTTP_X_SAKURA_FORWARDED_FOR', 'HTTP_CF_CONNECTING_IP');
    foreach ($ipKeys as $key) {
        if (isset($_SERVER[$key])) {
            wge_add($rows, $g, '$_SERVER[' . $key . ']', 'info', (string)$_SERVER[$key],
                $key === 'REMOTE_ADDR' ? 'ログイン試行の制限で、どの値をアクセス元として信じるかの判断材料' : '', 'ip');
        }
    }
}

function wge_check_cron(&$rows)
{
    $g = 'cron';
    $file = wge_work_dir() . '/deny/cron.json';
    $state = is_file($file) ? json_decode((string)@file_get_contents($file), true) : null;
    if (!is_array($state) || !isset($state['time'])) {
        wge_add($rows, $g, '最後に動いた記録', 'info', 'まだない',
            '下の「cron に登録するコマンド」を登録し、10 分ほど待ってから再表示する');
        return;
    }
    $age = time() - (int)$state['time'];
    $version = isset($state['php_version']) ? (string)$state['php_version'] : '';
    $versionOk = $version !== '' && version_compare($version, MIN_PHP_VERSION, '>=');
    $status = $age <= 900 ? ($versionOk ? 'ok' : 'warn') : 'warn';
    $notes = array();
    if ($age > 900) {
        $notes[] = '15 分以上動いていない。cron の設定を確かめる';
    }
    if (!$versionOk) {
        $notes[] = 'cron で動いた PHP が ' . MIN_PHP_VERSION . ' 未満。コマンドに版付きのパスを使う';
    }
    wge_add($rows, $g, '最後に動いた記録', $status,
        date('Y-m-d H:i:s T', (int)$state['time']) . '（' . wge_human_age($age) . '前）/ PHP ' . $version
        . ' / ' . (isset($state['sapi']) ? $state['sapi'] : '?'),
        implode('。', $notes));
    if (!empty($state['binary'])) {
        wge_add($rows, $g, 'cron で動いた PHP の実行ファイル', 'info', (string)$state['binary']);
    }
}

function wge_cli_candidates()
{
    $mm = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
    $mmn = PHP_MAJOR_VERSION . PHP_MINOR_VERSION;
    $paths = array();
    // PHP を組み込んだときの bin ディレクトリ。共用サーバーでは版ごとに分かれていることが多い
    $paths[] = PHP_BINDIR . '/php';
    if (defined('PHP_BINARY') && PHP_BINARY !== '') {
        // Web 側の実行ファイル（php-cgi、lsphp、php-fcgi8.3 など）から、同じ場所のコマンドライン版を推測する
        $name = preg_replace(array('#-cgi$#', '#^lsphp$#', '#^php-fcgi#'), array('', 'php', 'php'), basename(PHP_BINARY));
        if (preg_match('#^php[0-9.]*$#', $name)) {
            $paths[] = dirname(PHP_BINARY) . '/' . $name;
        }
    }
    $paths[] = '/usr/bin/php' . $mm;
    $paths[] = '/usr/local/bin/php' . $mm;
    $paths[] = '/usr/local/php/' . $mm . '/bin/php';
    $paths[] = '/opt/alt/php' . $mmn . '/usr/bin/php';
    $paths[] = '/opt/cpanel/ea-php' . $mmn . '/root/usr/bin/php';
    $paths[] = '/usr/local/bin/php';
    $paths[] = '/usr/bin/php';

    $result = array();
    foreach (array_values(array_unique($paths)) as $path) {
        error_clear_last();
        $exists = @is_file($path) && @is_executable($path);
        $error = error_get_last();
        $blocked = !$exists && $error && strpos((string)$error['message'], 'open_basedir') !== false;
        $result[] = array('path' => $path, 'state' => $exists ? 'ある' : ($blocked ? '確かめられない（open_basedir）' : 'ない'));
    }
    return $result;
}


/* ---------- 共有用の結果（パスなどを伏せる） ---------- */

function wge_mask_map()
{
    $paths = array();
    $paths[__DIR__] = '<SCRIPT_DIR>';
    $home = wge_home_dir();
    if ($home !== '' && $home !== '/') {
        $paths[$home] = '<HOME>';
    }
    $raw = isset($_SERVER['DOCUMENT_ROOT']) ? rtrim((string)$_SERVER['DOCUMENT_ROOT'], '/') : '';
    foreach (array(wge_docroot(), $raw) as $docroot) {
        if ($docroot !== '') {
            $paths[$docroot] = '<DOCROOT>';
            $parent = dirname($docroot);
            if ($parent !== '/' && $parent !== '.') {
                $paths[$parent] = '<DOCROOT>/..';
            }
        }
    }
    uksort($paths, function ($a, $b) {
        return strlen($b) - strlen($a);
    });

    $words = array();
    $candidates = array(
        isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '',
        isset($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : '',
        wge_process_user_name(),
        function_exists('get_current_user') ? get_current_user() : '',
    );
    foreach ($candidates as $word) {
        $word = (string)$word;
        if (strlen($word) >= 3) {
            $words[$word] = true;
        }
    }
    return array('paths' => $paths, 'words' => array_keys($words));
}

function wge_mask_text($text, $map)
{
    $text = (string)$text;
    foreach ($map['paths'] as $from => $to) {
        $text = str_replace((string)$from, $to, $text);
    }
    // 上で拾えなかったホームディレクトリ（/home/アカウント名、ロリポップの /home/users/0/アカウント名）
    $text = preg_replace('#/home/(?:users/[^/\s]+/)?[^/\s"\'<>]+#', '/home/<USER>', $text);
    foreach ($map['words'] as $word) {
        $text = preg_replace('/(?<![A-Za-z0-9_.-])' . preg_quote($word, '/') . '(?![A-Za-z0-9_-])/', '<MASKED>', $text);
    }
    return preg_replace('/\b(?:\d{1,3}\.){3}\d{1,3}\b/', '<IP>', $text);
}

function wge_mask_rows($rows)
{
    $map = wge_mask_map();
    $masked = array();
    foreach ($rows as $row) {
        $masked[] = array(
            'group' => $row['group'],
            'item' => $row['item'],
            'status' => $row['status'],
            'value' => $row['sensitive'] === 'ip' ? '<IP>' : wge_mask_text($row['value'], $map),
            'note' => wge_mask_text($row['note'], $map),
        );
    }
    return $masked;
}


/* ---------- 画面 ---------- */

function wge_status_label($status)
{
    $labels = array('ok' => 'OK', 'warn' => '注意', 'ng' => 'NG', 'info' => '参考');
    return isset($labels[$status]) ? $labels[$status] : $status;
}

function wge_page_start($title, $nonce)
{
    header("Content-Security-Policy: default-src 'none'; script-src 'nonce-" . $nonce . "'; style-src 'nonce-" . $nonce
        . "'; connect-src 'self' https:; img-src 'self' data:; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
    header('Content-Type: text/html; charset=utf-8');
    ?><!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= wge_h($title) ?></title>
<style nonce="<?= wge_h($nonce) ?>">
:root { --bg: #fafaf8; --fg: #1d1d1b; --muted: #5f5f5a; --line: #deded8; --card: #ffffff;
  --ok: #1f7a3d; --warn: #9a5b00; --ng: #b3261e; --info: #4a5568; --accent: #2457a6; }
@media (prefers-color-scheme: dark) {
  :root { --bg: #161615; --fg: #ececea; --muted: #a5a59e; --line: #34342f; --card: #1f1f1d;
    --ok: #6fcf8e; --warn: #f0b45a; --ng: #ff8a80; --info: #b8c0cc; --accent: #8ab4f8; }
}
* { box-sizing: border-box; }
body { margin: 0; background: var(--bg); color: var(--fg); font: 15px/1.6 system-ui, -apple-system, "Hiragino Sans", "Yu Gothic UI", sans-serif; }
main { max-width: 1080px; margin: 0 auto; padding: 24px 16px 64px; }
h1 { font-size: 1.5rem; margin: 0 0 8px; }
h2 { font-size: 1.15rem; margin: 32px 0 8px; }
p { margin: 8px 0; }
.muted { color: var(--muted); }
.banner { border: 1px solid var(--warn); border-radius: 8px; padding: 10px 14px; margin: 16px 0; }
.summary { display: flex; flex-wrap: wrap; gap: 8px 24px; margin: 12px 0; }
.table-wrap { overflow-x: auto; border: 1px solid var(--line); border-radius: 8px; background: var(--card); }
table { width: 100%; border-collapse: collapse; }
th, td { text-align: left; vertical-align: top; padding: 8px 10px; border-bottom: 1px solid var(--line); }
tr:last-child td { border-bottom: 0; }
th { font-size: .85rem; color: var(--muted); font-weight: 600; }
td.value { font-family: ui-monospace, SFMono-Regular, Consolas, monospace; font-size: .85rem; word-break: break-all; }
td.note { color: var(--muted); font-size: .9rem; }
.badge { display: inline-block; min-width: 3.2em; text-align: center; border-radius: 999px; padding: 0 8px; font-size: .8rem; font-weight: 700; border: 1px solid currentColor; }
.badge.ok { color: var(--ok); } .badge.warn { color: var(--warn); } .badge.ng { color: var(--ng); } .badge.info { color: var(--info); }
code, textarea, input[type=text], input[type=password] { font-family: ui-monospace, SFMono-Regular, Consolas, monospace; }
ul.commands { padding-left: 1.2em; }
ul.commands code { word-break: break-all; }
textarea { width: 100%; min-height: 220px; font-size: .8rem; background: var(--card); color: var(--fg); border: 1px solid var(--line); border-radius: 8px; padding: 8px; }
input[type=text], input[type=password] { width: 100%; max-width: 420px; padding: 8px; font-size: 1rem; background: var(--card); color: var(--fg); border: 1px solid var(--line); border-radius: 6px; }
button, .button { font: inherit; padding: 8px 14px; border-radius: 6px; border: 1px solid var(--line); background: var(--card); color: var(--fg); cursor: pointer; text-decoration: none; display: inline-block; }
button.primary { background: var(--accent); border-color: var(--accent); color: #fff; }
button.danger { color: var(--ng); border-color: var(--ng); }
.actions { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin: 12px 0; }
.actions form { margin: 0; }
.error { color: var(--ng); }
[hidden] { display: none !important; }
</style>
</head>
<body>
<main>
<?php
}

function wge_page_end()
{
    echo "</main>\n</body>\n</html>\n";
}

function wge_render_setup_page()
{
    $nonce = base64_encode(random_bytes(16));
    wge_page_start('WebGenEngine サーバー診断: 設定が必要です', $nonce);
    ?>
<h1>設定が必要です</h1>
<p>このファイルの先頭にある <code>ACCESS_KEY</code> を、16 文字以上のランダムな文字列に書き換えてから、もう一度アップロードしてください。</p>
<p class="muted">例（この画面を開くたびに変わります）: <code><?= wge_h(bin2hex(random_bytes(16))) ?></code></p>
<?php
    wge_page_end();
}

function wge_render_login_page($error)
{
    $nonce = base64_encode(random_bytes(16));
    wge_page_start('WebGenEngine サーバー診断', $nonce);
    ?>
<h1>WebGenEngine サーバー診断</h1>
<p>このスクリプトに設定した <code>ACCESS_KEY</code> を入力してください。</p>
<?php if ($error !== '') { ?><p class="error"><?= wge_h($error) ?></p><?php } ?>
<form method="post" action="<?= wge_h(basename(__FILE__)) ?>">
  <input type="hidden" name="op" value="login">
  <p><input type="password" name="key" autocomplete="off" required aria-label="ACCESS_KEY"></p>
  <p><button type="submit" class="primary">診断を始める</button></p>
</form>
<?php
    wge_page_end();
}

function wge_render_message_page($message, $linkBack)
{
    $nonce = base64_encode(random_bytes(16));
    wge_page_start('WebGenEngine サーバー診断', $nonce);
    ?>
<h1>WebGenEngine サーバー診断</h1>
<p><?= wge_h($message) ?></p>
<?php if ($linkBack) { ?><p><a class="button" href="<?= wge_h(basename(__FILE__)) ?>">診断に戻る</a></p><?php } ?>
<?php
    wge_page_end();
}

function wge_render_report_page()
{
    $started = microtime(true);
    $workFailed = wge_prepare_work_dir();
    $rows = wge_collect_server_checks($workFailed);
    $elapsed = round(microtime(true) - $started, 2);

    $counts = array('ng' => 0, 'warn' => 0);
    foreach ($rows as $row) {
        if (isset($counts[$row['status']])) {
            $counts[$row['status']]++;
        }
    }

    $groups = array();
    foreach ($rows as $row) {
        $groups[$row['group']][] = $row;
    }

    $config = array(
        'work' => wge_work_dir_name() . '/',
        'marker' => PROBE_MARKER,
        'toolVersion' => TOOL_VERSION,
        'generatedAt' => gmdate('Y-m-d\TH:i:s\Z'),
        'server' => wge_mask_rows($rows),
    );
    $jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE;
    $jsonFlags |= defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : JSON_PARTIAL_OUTPUT_ON_ERROR;

    $nonce = base64_encode(random_bytes(16));
    $csrf = wge_csrf_value();
    $self = basename(__FILE__);
    wge_page_start('WebGenEngine サーバー診断', $nonce);
    ?>
<h1>WebGenEngine サーバー診断</h1>
<p class="muted">診断日時 <?= wge_h(date('Y-m-d H:i:s T')) ?>・サーバー側の確認 <?= wge_h($elapsed) ?> 秒・診断スクリプト v<?= wge_h(TOOL_VERSION) ?></p>
<div class="banner">このページはサーバーの内部情報を表示します。終わったら、下の「後片付け」でこのスクリプトを削除してください。</div>
<p id="wge-http-warning" class="banner" hidden>http で開いています。http から https への転送があると、ブラウザからの確認が正しく行えません。https で開き直してください。</p>

<div class="summary">
  <div>サーバー側: NG <strong><?= (int)$counts['ng'] ?></strong> 件、注意 <strong><?= (int)$counts['warn'] ?></strong> 件</div>
  <div id="wge-browser-summary">ブラウザ側: 確認中…</div>
</div>

<?php foreach ($groups as $name => $groupRows) { ?>
<h2><?= wge_h($name) ?></h2>
<div class="table-wrap"><table>
<thead><tr><th scope="col">項目</th><th scope="col">結果</th><th scope="col">値</th><th scope="col">メモ</th></tr></thead>
<tbody>
<?php foreach ($groupRows as $row) { ?>
<tr><td><?= wge_h($row['item']) ?></td><td><span class="badge <?= wge_h($row['status']) ?>"><?= wge_h(wge_status_label($row['status'])) ?></span></td><td class="value"><?= wge_h($row['value']) ?></td><td class="note"><?= wge_h($row['note']) ?></td></tr>
<?php } ?>
</tbody></table></div>
<?php } ?>

<h2>cron に登録するコマンド</h2>
<p>サーバーの管理画面で、次のどれかを 5 分間隔で 1 件だけ登録します。「ある」と出たパスを使ってください。</p>
<ul class="commands">
<?php foreach (wge_cli_candidates() as $candidate) { ?>
  <li><code><?= wge_h(wge_shell_quote($candidate['path']) . ' ' . wge_shell_quote(__FILE__)) ?></code> <span class="muted">（<?= wge_h($candidate['state']) ?>）</span></li>
<?php } ?>
</ul>
<p class="muted">ロリポップは、コマンドではなく実行するファイルを、FTP のトップからのパスで指定します（PHP の版は登録した時点のものに固定されます）。実行結果のメールが不要なら、通知先を空にしてください。</p>

<h2>ブラウザから確かめる</h2>
<p class="muted">このブラウザから、テスト用ファイルを実際に取得して確かめます。Cookie は送りません。</p>
<div class="table-wrap"><table>
<thead><tr><th scope="col">項目</th><th scope="col">結果</th><th scope="col">値</th><th scope="col">メモ</th></tr></thead>
<tbody id="wge-browser-rows"><tr><td colspan="4">確認中…</td></tr></tbody>
</table></div>

<h2>共有用の結果</h2>
<p class="muted">パス、ホスト名、ユーザー名、IP アドレスを伏せた JSON です。伏せ方は機械的なので、保存や共有の前に中身を確かめてください。サーバー会社とプランを書き添えてから、コピーして保存します。</p>
<p><input type="text" id="wge-host-label" placeholder="例: エックスサーバー スタンダード" aria-label="サーバー会社とプラン"></p>
<textarea id="wge-share" readonly aria-label="共有用の結果"></textarea>
<div class="actions"><button type="button" id="wge-copy">コピー</button><span id="wge-copy-status" class="muted"></span></div>

<h2>操作</h2>
<div class="actions">
  <a class="button" href="<?= wge_h($self) ?>">もう一度診断する</a>
  <form method="post" action="<?= wge_h($self) ?>"><input type="hidden" name="op" value="cleanup"><input type="hidden" name="csrf" value="<?= wge_h($csrf) ?>"><button type="submit">後片付け（テスト用ファイルを削除）</button></form>
  <form method="post" action="<?= wge_h($self) ?>" id="wge-selfdelete"><input type="hidden" name="op" value="selfdelete"><input type="hidden" name="csrf" value="<?= wge_h($csrf) ?>"><button type="submit" class="danger">後片付け（このスクリプトも削除）</button></form>
  <form method="post" action="<?= wge_h($self) ?>"><input type="hidden" name="op" value="logout"><input type="hidden" name="csrf" value="<?= wge_h($csrf) ?>"><button type="submit">ログアウト</button></form>
</div>

<script type="application/json" id="wge-config"><?= json_encode($config, $jsonFlags) ?></script>
<script nonce="<?= wge_h($nonce) ?>">
(function () {
  'use strict';
  var cfg = JSON.parse(document.getElementById('wge-config').textContent);
  var LABELS = { ok: 'OK', warn: '注意', ng: 'NG', info: '参考' };
  var GROUP = 'ブラウザから確かめる';
  var tbody = document.getElementById('wge-browser-rows');
  var results = [];

  function rand() { return Math.random().toString(36).slice(2) + Date.now().toString(36); }
  function sleep(ms) { return new Promise(function (resolve) { setTimeout(resolve, ms); }); }
  function has(r, text) { return r.text.indexOf(text) !== -1; }

  function cell(tr, text, className) {
    var td = document.createElement('td');
    td.textContent = text;
    if (className) { td.className = className; }
    tr.appendChild(td);
    return td;
  }

  function add(item, status, value, note) {
    results.push({ group: GROUP, item: item, status: status, value: value, note: note || '' });
    var tr = document.createElement('tr');
    cell(tr, item);
    var badge = document.createElement('span');
    badge.className = 'badge ' + status;
    badge.textContent = LABELS[status];
    cell(tr, '').appendChild(badge);
    cell(tr, value, 'value');
    cell(tr, note || '', 'note');
    tbody.appendChild(tr);
  }

  async function get(url, options) {
    var opts = Object.assign({ credentials: 'omit', cache: 'no-store' }, options || {});
    try {
      var response = await fetch(url, opts);
      var text = '';
      try { text = await response.text(); } catch (e) { text = ''; }
      return { status: response.status, text: text, headers: response.headers };
    } catch (e) {
      return { status: 0, text: '', headers: null, error: String((e && e.message) || e) };
    }
  }

  function describe(r) {
    return r.status ? 'HTTP ' + r.status : '接続できない' + (r.error ? '（' + r.error + '）' : '');
  }

  function probe(path) { return cfg.work + path + '?t=' + rand(); }

  function nonceOf(r) {
    if (r.status !== 200) { return null; }
    try { return JSON.parse(r.text).nonce || null; } catch (e) { return null; }
  }

  function cacheHeaders(r) {
    if (!r.headers) { return ''; }
    var names = ['age', 'x-cache', 'x-cache-status', 'x-proxy-cache', 'x-litespeed-cache', 'via', 'server'];
    var found = [];
    names.forEach(function (name) {
      var value = r.headers.get(name);
      if (value) { found.push(name + ': ' + value); }
    });
    return found.length ? '（' + found.join(', ') + '）' : '';
  }

  async function samePair(mode) {
    var url = location.pathname + '?action=nonce&mode=' + mode + '&run=' + rand();
    var first = await get(url, { cache: 'default' });
    await sleep(500);
    var second = await get(url, { cache: 'default' });
    var a = nonceOf(first);
    var b = nonceOf(second);
    if (!a || !b) { return { same: null, text: describe(first) + ' / ' + describe(second) }; }
    return { same: a === b, text: (a === b ? '同じ値が返った' : '毎回違う値が返った') + cacheHeaders(second) };
  }

  async function run() {
    tbody.textContent = '';
    if (location.protocol !== 'https:') { document.getElementById('wge-http-warning').hidden = false; }

    var r = await get(probe('plain/secret.txt'));
    var reachable = r.status === 200 && has(r, 'WGE-PLAIN-OK');
    add('対照: 保護していないファイル', reachable ? 'ok' : 'ng', describe(r),
      reachable ? '' : 'テスト用ファイルに届かない。以下の結果は当てにならない');

    r = await get(probe('deny/secret.txt'));
    var leaked = has(r, 'WGE-DENY-LEAKED');
    add('データディレクトリの拒否（Require all denied）', leaked ? 'ng' : 'ok', describe(r),
      leaked ? '拒否が効かない。データを公開ディレクトリの中に置けない' : '中身は取得できなかった');

    r = await get(probe('exec/probe.php'));
    var executes = has(r, cfg.marker);
    add('対照: サブディレクトリでの PHP の実行', executes ? 'ok' : 'warn', describe(r),
      executes ? '' : 'サブディレクトリで PHP が動かない。次の項目の判定が当てにならない');

    r = await get(probe('nophp/probe.php'));
    var ran = has(r, cfg.marker);
    // 開始タグを分けて書くのは、このファイル自体が PHP として読まれるため
    var source = has(r, '<' + '?php');
    add('書き出し先の .php の拒否', (ran || source) ? 'ng' : 'ok', describe(r),
      ran ? '拒否が効かず、PHP が実行された' : (source ? 'PHP のソースがそのまま見えた' : ''));

    r = await get(probe('nophp/ok.html'));
    var staticOk = r.status === 200 && has(r, 'WGE-STATIC-OK');
    add('書き出し先の静的ファイル', staticOk ? 'ok' : 'ng', describe(r),
      staticOk ? '' : '.php の拒否設定が、静的ファイルまで止めている');

    r = await get(probe('rewrite/virtual'));
    var rewrites = has(r, 'WGE-REWRITE-OK');
    add('.htaccess の書き換え（RewriteRule）', rewrites ? 'ok' : 'ng', describe(r),
      rewrites ? '' : 'mod_rewrite が使えないか、.htaccess で許可されていない');

    r = await get(probe('opts/ok.txt'));
    var options = has(r, 'WGE-OPTIONS-OK');
    // 拒否が 2 つとも効かないなら、.htaccess そのものが読まれていない
    var ignored = leaked && ran;
    add('.htaccess の Options -Indexes', options ? (ignored ? 'warn' : 'ok') : 'ng', describe(r),
      options ? (ignored ? '.htaccess が読まれていないようなので、確かめられていない' : '')
        : (r.status === 500 ? 'Options の指定が許可されていない' : ''));

    if (location.protocol === 'https:') {
      add('HTTPS', 'ok', 'https で表示している', '');
    } else {
      r = await get('https://' + location.host + location.pathname + '?action=nonce&mode=nostore&run=' + rand(), { mode: 'cors' });
      var httpsOk = r.status === 200;
      add('HTTPS', httpsOk ? 'warn' : 'ng', httpsOk ? 'https でも接続できる' : describe(r),
        httpsOk ? 'https で開き直して、もう一度確かめる' : 'HTTPS が使えない。サーバーの管理画面で無料 SSL を有効にする');
    }

    var noStore = await samePair('nostore');
    add('前段キャッシュ（no-store を付けた応答）', noStore.same === null ? 'warn' : (noStore.same ? 'ng' : 'ok'), noStore.text,
      noStore.same ? 'no-store を付けてもキャッシュされた' : '');

    var plain = await samePair('plain');
    add('前段キャッシュ（キャッシュ指定のない応答）', plain.same === null ? 'warn' : 'info', plain.text,
      plain.same ? 'PHP の応答も既定でキャッシュされる。no-store が必須'
        : 'この設定ではキャッシュされなかった。サーバーの高速化設定によって変わる');

    finish();
  }

  function finish() {
    var ng = 0;
    var warn = 0;
    results.forEach(function (row) {
      if (row.status === 'ng') { ng++; }
      if (row.status === 'warn') { warn++; }
    });
    document.getElementById('wge-browser-summary').textContent = 'ブラウザ側: NG ' + ng + ' 件、注意 ' + warn + ' 件';
    renderShare();
  }

  function renderShare() {
    document.getElementById('wge-share').value = JSON.stringify({
      tool: 'WebGenEngine diagnose',
      toolVersion: cfg.toolVersion,
      generatedAt: cfg.generatedAt,
      host: document.getElementById('wge-host-label').value,
      server: cfg.server,
      browser: results
    }, null, 2);
  }

  document.getElementById('wge-host-label').addEventListener('input', renderShare);
  document.getElementById('wge-copy').addEventListener('click', function () {
    var area = document.getElementById('wge-share');
    var status = document.getElementById('wge-copy-status');
    function fallback() {
      area.select();
      status.textContent = document.execCommand('copy') ? 'コピーしました' : '選択しました。手動でコピーしてください';
    }
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(area.value).then(function () { status.textContent = 'コピーしました'; }, fallback);
    } else {
      fallback();
    }
  });
  document.getElementById('wge-selfdelete').addEventListener('submit', function (event) {
    if (!window.confirm('テスト用ファイルとこのスクリプトを削除します。よろしいですか？')) { event.preventDefault(); }
  });

  run();
})();
</script>
<?php
    wge_page_end();
}
