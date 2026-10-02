<?php
declare(strict_types=1);

/**
 * 回答済み画面（来場者向け）。
 *
 * 交換コードを表示し、総合受付でそのまま提示できるようにする。
 * 何社まわったかを見せ、メールアドレスが未登録なら入力欄を、登録済みなら
 * 伏せ字での確認と変更欄を出す（この画面は受付にQRを見せるため、そのままは出さない）。
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
        echo e(booth_label()) . 'のQRコードを読み取ってアンケートにご回答いただくと、新しい交換コードが表示されます。</div>';
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
        if ((get_string('from') ?? '') === 'desk') {
            // 総合受付の掲示から来た人。この端末での回答が見つからない場合
            echo '<h1>交換コードが見つかりません</h1>';
            echo '<div class="alert alert-warn">この端末からのご回答が確認できませんでした。';
            echo '<strong>回答したときと同じスマートフォン・同じブラウザ</strong>で読み取ってください。';
            echo 'それでも表示されないときは、総合受付のスタッフにお声がけください。</div>';
            echo '<p>まだご回答でない方は、' . e(booth_label()) . 'のQRコードを読み取ってアンケートにご回答ください。</p>';
        } else {
            echo '<h1>ご回答ありがとうございました</h1>';
            echo '<div class="alert alert-warn">ブラウザの設定でCookieが無効になっているため、交換コードを表示できません。';
            echo '総合受付のスタッフにお声がけください。</div>';
        }
        page_footer();
        exit;
    }

    $visitor = current_visitor((int) $event['id']);
}

$visited = visited_companies((int) $visitor['id']);

if ($visited === []) {
    page_header('回答状況｜' . (string) $event['name'], ['brand' => (string) $event['name']]);
    echo '<h1>まだ回答がありません</h1>';
    echo '<p>' . e(booth_label()) . 'のQRコードを読み取って、アンケートにご回答ください。</p>';
    if ((get_string('from') ?? '') === 'desk') {
        // 総合受付の掲示から来た人向け。回答済みのはずなのに出ない場合がある
        echo '<div class="alert alert-warn">すでにご回答いただいている場合は、';
        echo '<strong>回答したときと同じスマートフォン・同じブラウザ</strong>で読み取ってください。';
        echo 'それでも表示されないときは、総合受付のスタッフにお声がけください。</div>';
    }
    echo '<p class="text-secondary">' . same_device_note() . '</p>';
    page_footer();
    exit;
}

// メールアドレスの後追い登録
$saved   = false;
$changed = false;
$error   = null;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && collect_email()) {
    $email = trim_ja((string) (post_string('email') ?? ''));
    if ($email === '' || !is_valid_email($email)) {
        $error = 'メールアドレスの形式が正しくありません。';
    } else {
        $changed = ($visitor['email'] ?? null) !== null;
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

echo '<h2>回答済みの' . e(booth_label()) . '（' . count($visited) . '社）</h2>';
echo '<ul class="visited-list">';
foreach ($visited as $row) {
    echo '<li><span>' . e((string) $row['name']) . '</span>';
    echo '<span class="muted nowrap">' . e(format_datetime_ja((string) $row['first_submitted_at'])) . '</span></li>';
}
echo '</ul>';

// 総合アンケート（イベント全体について聞くもの）。開催中は、ここから回答・修正できる
$overall = overall_survey((int) $event['id']);
if ($overall !== null && (int) $overall['is_published'] === 1 && questions_for_survey((int) $overall['id']) !== []) {
    $overallAnswered = has_response((int) $overall['id'], (int) $visitor['id']);
    $overallOpen     = (string) $event['status'] === 'open';

    if ((get_string('ok') ?? '') === 'overall') {
        echo '<div class="alert alert-success">' . e(overall_label()) . 'にご回答ありがとうございました。</div>';
    }

    if ($overallOpen || $overallAnswered) {
        echo '<div class="card">';
        echo '<h2 style="margin-top:0">' . e(overall_label()) . '</h2>';
        if ($overallAnswered) {
            echo '<p>ご回答ありがとうございました。';
            echo $overallOpen ? '内容はお帰りまで何度でも直せます。</p>' : '</p>';
        } else {
            echo '<p>イベント全体についてお聞かせください。<strong>お帰りの際で結構です。</strong>';
            echo e(booth_label()) . 'のアンケート画面からでも、この画面からでもご回答いただけます。</p>';
        }
        if ($overallOpen) {
            echo '<div class="btn-row"><a class="btn' . ($overallAnswered ? '' : ' btn-primary')
                . '" href="/o.php?e=' . rawurlencode($eventSlug) . '">'
                . ($overallAnswered ? '回答を変更する' : '回答する') . '</a></div>';
        }
        echo '</div>';
    }
}

// 次に向かうための導線は、メールアドレス欄より前に置く（回答の直後に使うのはこちらのため）
echo '<h2>ほかの' . e(booth_label()) . 'もまわる</h2>';
echo '<div class="card">';
echo '<p class="text-secondary">下のボタンを押すと、この画面のままカメラでQRコードを読み取れます。';
echo 'ブラウザを閉じる必要はありません。</p>';
// カメラが使える端末でだけボタンを出す（判定は scan.js が行う）
echo '<div class="btn-row"><button type="button" class="btn btn-primary btn-block" id="scan-open"'
    . ' data-scan-mode="booth" hidden>次の' . e(booth_label()) . 'のQRコードを読み取る</button></div>';
echo '<p class="muted" id="scan-unsupported" hidden></p>';
echo '<div id="scan-panel" hidden>';
echo '<div class="scan-view"><video id="scan-video" playsinline muted></video><span class="scan-frame"></span></div>';
echo '<canvas id="scan-canvas" hidden></canvas>';
echo '<p class="muted" id="scan-status">カメラを起動しています…</p>';
echo '<div class="btn-row"><button type="button" class="btn btn-block" id="scan-close">閉じる</button></div>';
echo '</div>';
echo '<p class="muted">この画面はそのまま開いておくと便利です。';
echo '<strong>もし閉じてしまっても大丈夫です。</strong>総合受付に掲示されているQRコードを読み取るか、';
echo '次のURLを開けば、同じ交換コードをいつでも表示できます。</p>';
echo '<p class="mono muted" style="word-break:break-all">' . e($pageUrl) . '</p>';
echo '</div>';

if ($saved) {
    // 自分で入力した直後の1回だけは、そのまま出して確認してもらう
    echo '<div class="alert alert-success">メールアドレスを' . ($changed ? '変更' : '登録') . 'しました（<strong>'
        . e((string) $visitor['email']) . '</strong>）。イベント終了後にご案内をお送りします。</div>';
}
if ($error !== null) {
    echo '<div class="alert alert-error">' . e($error) . '</div>';
}

if (!collect_email()) {
    // メールアドレスを集めない運用（.env の COLLECT_EMAIL=0）では、入力欄も案内も出さない
} elseif (($visitor['email'] ?? null) === null) {
    echo '<div class="card">';
    echo '<h2 style="margin-top:0">スマホ壁紙をご希望の方へ</h2>';
    echo '<p>' . e(email_note()) . '</p>';
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
    // この画面は交換コードのQRを受付に見せる画面なので、アドレスは伏せ字で出す
    echo '<div class="alert alert-info">イベント終了後、<strong>' . e(mask_email((string) $visitor['email']))
        . '</strong> 宛に' . e(wallpaper_label()) . 'のご案内をお送りします。</div>';
    echo '<details class="card">';
    echo '<summary>登録したメールアドレスを変更する</summary>';
    echo '<p class="muted">新しいアドレスを入力して「変更する」を押してください。';
    echo '古いアドレスには案内をお送りしません。</p>';
    echo '<form method="post">';
    echo '<input type="hidden" name="c" value="' . e($code) . '">';
    echo '<label class="field" for="email">新しいメールアドレス</label>';
    echo '<input type="email" id="email" name="email" autocomplete="email" inputmode="email" '
        . 'placeholder="example@example.jp" required>';
    echo '<div class="btn-row"><button type="submit" class="btn">変更する</button></div>';
    echo '</form>';
    echo '</details>';
}

// スタッフがログインしていない状態でQRを読み取った場合の逃げ道
echo '<p class="muted">スタッフの方はこちら：<a href="/admin/claim.php?code=' . rawurlencode($code) . '">交換の照会画面</a></p>';

page_footer(['/assets/vendor/jsqr.min.js', '/assets/scan.js']);
