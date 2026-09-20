<?php
declare(strict_types=1);

/**
 * PHP組み込みサーバー（serve.cmd）用のルーター。
 *
 * 開発時も本番と同じ短いURL（/s/<イベント>/<企業>、/o/<トークン>）で
 * 動かすために、public/.htaccess の RewriteRule と同じ書き換えを行う。
 * 本番の Apache では使わない。
 */

$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
$root = dirname(__DIR__) . '/public';

if (preg_match('#^/s/([A-Za-z0-9_-]+)/([A-Za-z0-9_-]+)/?$#', $path, $m) === 1) {
    $_GET['e'] = $m[1];
    $_GET['c'] = $m[2];
    require $root . '/s.php';
    return true;
}

if (preg_match('#^/o/([A-Za-z0-9]+)/?$#', $path, $m) === 1) {
    $_GET['t'] = $m[1];
    require $root . '/o.php';
    return true;
}

if (preg_match('#^/c/([A-Za-z0-9-]+)/?$#', $path, $m) === 1) {
    $_GET['code'] = $m[1];
    require $root . '/c.php';
    return true;
}

// 実在するファイルは組み込みサーバーにそのまま処理させる
if ($path !== '/' && is_file($root . $path)) {
    return false;
}

if ($path === '/' || $path === '') {
    require $root . '/index.php';
    return true;
}

// ディレクトリなら index.php を探す
if (is_dir($root . $path) && is_file(rtrim($root . $path, '/') . '/index.php')) {
    require rtrim($root . $path, '/') . '/index.php';
    return true;
}

return false;
