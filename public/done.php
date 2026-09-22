<?php
declare(strict_types=1);

/**
 * 回答済み画面（来場者向け）。
 *
 * 交換コードを表示し、総合受付でそのまま提示できるようにする。
 * 何社まわったか、まだメールアドレスを登録していなければその入力欄も出す。
 *
 * URLに交換コードを付けて開ける（/done.php?e=<イベント>&c=<コード>）。
 * Cookieが消えたり別のブラウザで開いたりしても、ブックマークやスクリーンショットの
 * URLから同じコードに戻れるようにするため、Cookieで来場者が分かるときも
 * コード付きURLへ寄せる。
 */

require_once dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/view.php';
require_once dirname(__DIR__) . '/src/qrcode.php';

$event = find_event_by_slug((string) (get_string('e') ?? ''));
if ($event === null) {
    abort(404, 'ページが見つかりません。');
}

$eventSlug = (string) $event['slug'];
$codeParam = normalize_claim_code((string) (get_string('c') ?? (post_string('c') ?? '')));

/** 画面のどこにでも出す、同じ端末で回ってもらうための案内 */
function same_device_note(): string
{
    return 'ほかの参加企業のアンケートでも<strong>同じスマホ・同じブラウザ</strong>でQRコードを読み取ってください。'
        . '別の端末で読み取ると、別の交換コードになります。';
}

$visitor  = null;
$viaCode  = false;

if ($codeParam !== '') {
    // コード指定：Cookieが無くても（別の端末でも）この画面を開ける
    $claim = find_claim_by_code($codeParam);
    if ($claim === null || (int) $claim['event_id'] !== (int) $event['id']) {
        http_response_code(404);
        page_header('交換コードが見つかりません｜' . (string) $event['name'], ['brand' => (string) $event['name']]);
        echo '<h1>交換コードが見つかりません</h1>';
        echo '<div class="alert alert-warn">URLの交換コードが正しくないようです。';
        echo 'ブースのQRコードを読み取ってアンケートにご回答いただくと、新しい交換コードが表示されます。</div>';
        echo '<p class="muted">お困りのときは総合受付のスタッフにお声がけください。</p>';
        page_footer();
        exit;
    }
    $visitor = find_visitor((int) $claim['visitor_id']);
    $viaCode = true;
}

if ($visitor === null) {
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
}

$visited = visited_companies((int) $visitor['id']);

if ($visited === []) {
    page_header('回答状況｜' . (string) $event['name'], ['brand' => (string) $event['name']]);
    echo '<h1>まだ回答がありません</h1>';
    echo '<p>ブースのQRコードを読み取って、アンケートにご回答ください。</p>';
    echo '<p class="text-secondary">' . same_device_note() . '</p>';
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

// コード無しで開かれたときは、あとで戻ってこられるURLに置き換える
if (!$viaCode && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    redirect('/done.php?e=' . rawurlencode($eventSlug) . '&c=' . rawurlencode($code));
}

$pageUrl = base_url() . '/done.php?e=' . rawurlencode($eventSlug) . '&c=' . rawurlencode($code);

page_header('回答ありがとうございました｜' . (string) $event['name'], ['brand' => (string) $event['name']]);

echo '<h1>ご回答ありがとうございました</h1>';
echo '<div class="card">';
echo '<p class="center text-secondary">総合受付でこの画面（またはスクリーンショット）をご提示ください。</p>';
echo '<p class="claim-code">' . e($code) . '</p>';
// QRには交換コードのURLを入れる（受付がスマホで読み取ると照会画面が開く）
echo '<div class="claim-qr">' . qr_svg(claim_url($code), 4, 2) . '</div>';
echo '<p class="muted center">交換コード（受付でこのQRコードを読み取ります）</p>';
echo '<p class="muted center">この画面はスクリーンショットの保存、またはブックマークをおすすめします。';
echo 'このページのURLを開くと、いつでも同じ交換コードを表示できます。</p>';
echo '</div>';

echo '<div class="alert alert-info">' . same_device_note() . '</div>';

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
    echo '<p>イベント終了後に「総合アンケート」のご案内をお送りします。ご回答いただくと、';
    echo 'はいてくヒルズオリジナル壁紙をダウンロードできます。</p>';
    echo '<form method="post">';
    // コード付きで開いている場合も、送信先を同じ来場者に保つ
    echo '<input type="hidden" name="c" value="' . e($code) . '">';
    echo '<label class="field" for="email">メールアドレス（任意）';
    echo '<span class="hint">この用途以外には使用せず、案内の送付・集計が終わり次第削除します。</span></label>';
    echo '<input type="email" id="email" name="email" autocomplete="email" inputmode="email" placeholder="example@example.jp" required>';
    echo '<div class="btn-row"><button type="submit" class="btn btn-primary">登録する</button></div>';
    echo '</form>';
    echo '</div>';
} else {
    echo '<div class="alert alert-info">イベント終了後、ご登録のメールアドレス宛に総合アンケートのご案内をお送りします。</div>';
}

// スタッフがログインしていない状態でQRを読み取った場合の逃げ道
echo '<p class="muted">スタッフの方はこちら：<a href="/admin/claim.php?code=' . rawurlencode($code) . '">交換の照会画面</a></p>';

echo '<h2>ほかのブースもまわる</h2>';
echo '<p class="text-secondary">各ブースに掲示されているQRコードを読み取ると、そのブースのアンケートが開きます。';
echo 'この画面に戻るには、次のURLを開いてください。</p>';
echo '<p class="mono muted" style="word-break:break-all">' . e($pageUrl) . '</p>';

page_footer();
