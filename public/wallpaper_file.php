<?php
declare(strict_types=1);

/**
 * 壁紙画像の配信。
 *
 * 画像の実体は DocumentRoot の外（storage/wallpapers/）に置き、
 * 総合アンケートに回答済みのトークンを持つ人にだけ返す。
 * （Apache の静的配信にすると、URLさえ知っていれば誰でも取得できてしまうため）
 */

require_once dirname(__DIR__) . '/src/bootstrap.php';

$token  = (string) (get_string('t') ?? '');
$invite = $token === '' ? null : find_invite_by_token($token);
if ($invite === null || ($invite['responded_at'] ?? null) === null) {
    abort(404, 'この画像は表示できません。');
}

$wallpaper = find_wallpaper((int) (get_string('id') ?? '0'));
if ($wallpaper === null || (int) $wallpaper['event_id'] !== (int) $invite['event_id']) {
    abort(404, 'この画像は表示できません。');
}

// ファイル名はDBに保存した値のみを使い、パスの組み立てにリクエストの値を混ぜない
$path = config()['storage_dir'] . '/' . basename((string) $wallpaper['file_name']);
if (!is_file($path) || !is_readable($path)) {
    error_log('壁紙ファイルが見つかりません: ' . $path);
    abort(404, 'この画像は表示できません。');
}

$download = (get_string('dl') ?? '') === '1';
$fileName = preg_replace('/[^0-9A-Za-z._-]/', '_', (string) $wallpaper['file_name']) ?? 'wallpaper.png';

header('Content-Type: ' . (string) $wallpaper['mime_type']);
header('Content-Length: ' . (string) filesize($path));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=600');
header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="' . $fileName . '"');

readfile($path);
