<?php
declare(strict_types=1);

/**
 * 壁紙ダウンロード画面。
 *
 * 全体アンケートに回答した来場者だけがアクセスできる（トークンで判定する）。
 */

require_once dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/view.php';

$token  = (string) (get_string('t') ?? '');
$invite = $token === '' ? null : find_invite_by_token($token);
if ($invite === null) {
    abort(404, 'このURLは無効です。メールに記載されたリンクをもう一度お試しください。');
}

// 全体アンケートに回答していなければ、まず回答画面へ
if (($invite['responded_at'] ?? null) === null) {
    redirect('/o.php?t=' . rawurlencode($token));
}

$event = find_event((int) $invite['event_id']);
if ($event === null) {
    abort(404, 'このURLは無効です。');
}

$wallpapers = wallpapers_for_event((int) $event['id']);

// iOS Safari は download 属性で保存ダイアログを開けないため、案内文を出し分ける
$ua      = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
$isIos   = preg_match('/iPhone|iPad|iPod/i', $ua) === 1;
$isMobile = $isIos || preg_match('/Android|Mobile/i', $ua) === 1;

page_header('スマホ壁紙のダウンロード｜' . (string) $event['name'], ['brand' => (string) $event['name']]);

echo '<h1>ご回答ありがとうございました</h1>';
echo '<p>オリジナルのスマホ壁紙をダウンロードいただけます。</p>';

if ($wallpapers === []) {
    echo '<div class="alert alert-info">壁紙は現在準備中です。お手数ですが、しばらくしてからもう一度アクセスしてください。</div>';
    page_footer();
    exit;
}

if ($isIos) {
    echo '<div class="alert alert-info">iPhone・iPad をお使いの場合は、画像を<strong>長押し</strong>して';
    echo '「”写真”に追加」を選ぶと保存できます。</div>';
}

// 画面サイズに合いそうなものを先に出す（縦長＝スマホ向けを優先）
usort($wallpapers, static function (array $a, array $b) use ($isMobile): int {
    $aPortrait = (int) $a['height'] > (int) $a['width'] ? 0 : 1;
    $bPortrait = (int) $b['height'] > (int) $b['width'] ? 0 : 1;
    if ($isMobile && $aPortrait !== $bPortrait) {
        return $aPortrait <=> $bPortrait;
    }

    return (int) $a['sort_order'] <=> (int) $b['sort_order'];
});

echo '<div class="wallpaper-grid">';
foreach ($wallpapers as $wallpaper) {
    $src = '/wallpaper_file.php?t=' . rawurlencode($token) . '&id=' . (int) $wallpaper['id'];
    echo '<figure>';
    echo '<img src="' . e($src) . '" alt="' . e((string) $wallpaper['title']) . '" loading="lazy">';
    echo '<figcaption>' . e((string) $wallpaper['title']) . '<br>';
    echo (int) $wallpaper['width'] . '×' . (int) $wallpaper['height'] . '</figcaption>';
    echo '<div class="btn-row"><a class="btn btn-primary btn-block" href="' . e($src . '&dl=1') . '" download>';
    echo 'ダウンロード</a></div>';
    echo '</figure>';
}
echo '</div>';

echo '<p class="muted">保存できない場合は、画像を長押し（PCでは右クリック）して「画像を保存」をお選びください。</p>';

page_footer();
