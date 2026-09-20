<?php
declare(strict_types=1);

/**
 * 回答済み画面（来場者向け）。
 *
 * 交換コードを表示し、総合受付でそのまま提示できるようにする。
 * 何社まわったか、まだメールアドレスを登録していなければその入力欄も出す。
 */

require_once dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/view.php';
require_once dirname(__DIR__) . '/src/qrcode.php';

$event = find_event_by_slug((string) (get_string('e') ?? ''));
if ($event === null) {
    abort(404, 'ページが見つかりません。');
}

// Cookie が無い＝この端末からの回答が特定できない
if (visitor_cookie_missing()) {
    page_header('回答ありがとうございました｜' . (string) $event['name'], ['brand' => (string) $event['name']]);
    echo '<h1>ご回答ありがとうございました</h1>';
    echo '<div class="alert alert-warn">ブラウザの設定でCookieが無効になっているため、交換コードを表示できません。';
    echo '総合受付のスタッフにお声がけください。</div>';
    page_footer();
    exit;
}

$visitor = current_visitor((int) $event['id']);
$visited = visited_companies((int) $visitor['id']);

if ($visited === []) {
    page_header('回答状況｜' . (string) $event['name'], ['brand' => (string) $event['name']]);
    echo '<h1>まだ回答がありません</h1>';
    echo '<p>ブースのQRコードを読み取って、アンケートにご回答ください。</p>';
    page_footer();
    exit;
}

// メールアドレスの後追い登録
$saved = false;
$error = null;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $email = trim_ja((string) (post_string('email') ?? ''));
    if ($email === '' || !is_valid_email($email)) {
        $error = 'メールアドレスの形式が正しくありません。';
    } else {
        set_visitor_email((int) $visitor['id'], $email);
        $visitor['email'] = $email;
        $saved = true;
    }
}

$claim = find_or_create_claim((int) $visitor['id']);
$code  = (string) $claim['claim_code'];

page_header('回答ありがとうございました｜' . (string) $event['name'], ['brand' => (string) $event['name']]);

echo '<h1>ご回答ありがとうございました</h1>';
echo '<div class="card">';
echo '<p class="center text-secondary">総合受付でこの画面（またはスクリーンショット）をご提示ください。</p>';
echo '<p class="claim-code">' . e($code) . '</p>';
echo '<div class="claim-qr">' . qr_svg($code, 5, 2) . '</div>';
echo '<p class="muted center">交換コード</p>';
echo '</div>';

echo '<h2>回答済みのブース（' . count($visited) . '社）</h2>';
echo '<ul class="visited-list">';
foreach ($visited as $row) {
    echo '<li><span>' . e((string) $row['name']) . '</span>';
    echo '<span class="muted nowrap">' . e(format_datetime_ja((string) $row['first_submitted_at'])) . '</span></li>';
}
echo '</ul>';

if ($saved) {
    echo '<div class="alert alert-success">メールアドレスを登録しました。イベント終了後にご案内をお送りします。</div>';
}
if ($error !== null) {
    echo '<div class="alert alert-error">' . e($error) . '</div>';
}

if (($visitor['email'] ?? null) === null) {
    echo '<div class="card">';
    echo '<h2 style="margin-top:0">スマホ壁紙をご希望の方へ</h2>';
    echo '<p>イベント終了後に「全体アンケート」のご案内をお送りします。ご回答いただくと、';
    echo 'オリジナルのスマホ壁紙をダウンロードできます。</p>';
    echo '<form method="post">';
    echo '<label class="field" for="email">メールアドレス（任意）';
    echo '<span class="hint">この用途以外には使用せず、案内の送付・集計が終わり次第削除します。</span></label>';
    echo '<input type="email" id="email" name="email" autocomplete="email" inputmode="email" placeholder="example@example.jp" required>';
    echo '<div class="btn-row"><button type="submit" class="btn btn-primary">登録する</button></div>';
    echo '</form>';
    echo '</div>';
} else {
    echo '<div class="alert alert-info">イベント終了後、ご登録のメールアドレス宛に全体アンケートのご案内をお送りします。</div>';
}

echo '<h2>ほかのブースもまわる</h2>';
echo '<p class="text-secondary">各ブースに掲示されているQRコードを読み取ると、そのブースのアンケートが開きます。</p>';

page_footer();
