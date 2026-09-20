<?php
declare(strict_types=1);

/** 管理画面での壁紙プレビュー（ログイン必須） */

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

require_organizer();

$wallpaper = find_wallpaper((int) (get_string('id') ?? '0'));
if ($wallpaper === null) {
    abort(404, '画像が見つかりません。');
}

$path = config()['storage_dir'] . '/' . basename((string) $wallpaper['file_name']);
if (!is_file($path) || !is_readable($path)) {
    abort(404, '画像が見つかりません。');
}

header('Content-Type: ' . (string) $wallpaper['mime_type']);
header('Content-Length: ' . (string) filesize($path));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=300');

readfile($path);
