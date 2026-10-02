<?php
declare(strict_types=1);

/**
 * 総合アンケートの回答画面（会期中に答えなかった人に、終了後のメールで案内するURL）。
 *
 * 会期中はブースのアンケートに混ぜて集めるため、この画面を開くのは
 * 会場で答えなかった人だけになる（答えていれば壁紙の画面へ送る）。
 *
 *   /o/<トークン>      （mod_rewrite 経由。会期後のメールから）
 *   /o.php?t=<トークン>
 *   /o.php?e=<イベント>  （会期中。回答済み画面からの導線で、同じ端末の来場者が回答・修正する）
 */

require_once dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/render_survey.php';

$token = (string) (get_string('t') ?? '');

// 管理画面のテスト送信で使うプレビュー（/o/preview-<イベントID>）。
// 来場者ごとのトークンを作らずに、案内メールのリンクから実際の画面を確認できるようにする。
$preview = preg_match('/\Apreview-(\d+)\z/', $token, $m) === 1;

if ($preview) {
    $admin = current_admin();
    if ($admin === null || (string) $admin['role'] !== 'organizer') {
        http_response_code(403);
        page_header('プレビュー｜総合アンケート');
        echo '<h1>プレビューの表示にはログインが必要です</h1>';
        echo '<div class="alert alert-info">このリンクは、管理画面の「テスト送信」で送られるスタッフ確認用のプレビューです。';
        echo '主催者としてログインした状態で開いてください。</div>';
        echo '<p><a class="btn" href="/admin/login.php">管理画面にログインする</a></p>';
        echo '<p class="muted">来場者に届く案内メールには、その方専用のURLが入ります。</p>';
        page_footer();
        exit;
    }

    $event  = find_event((int) $m[1]);
    $invite = null;
    if ($event === null) {
        abort(404, 'イベントが見つかりません。');
    }
} elseif ($token === '') {
    // 会期中、来場者が自分で回答・修正する（回答済み画面からの導線。トークンは使わない）
    $invite = null;
    $event  = find_event_by_slug((string) (get_string('e') ?? ''));
    if ($event === null) {
        abort(404, 'このURLは無効です。' . booth_label() . 'のQRコードをもう一度読み取ってください。');
    }
    if ((string) $event['status'] !== 'open' || visitor_cookie_missing()) {
        // 受付が終わっているか、端末を特定できないときは回答済み画面へ戻す
        redirect('/done.php?e=' . rawurlencode((string) $event['slug']));
    }
    $visitor = current_visitor((int) $event['id']);
} else {
    $invite = find_invite_by_token($token);
    if ($invite === null) {
        abort(404, 'このURLは無効です。メールに記載されたリンクをもう一度お試しください。');
    }

    $event = find_event((int) $invite['event_id']);
    if ($event === null) {
        abort(404, 'このURLは無効です。');
    }

    // 会期中にブースで答えた人も含め、回答済みなら壁紙ダウンロード画面へ
    if (invite_answered($invite)) {
        redirect('/wallpaper.php?t=' . rawurlencode($token));
    }
}

$survey    = overall_survey((int) $event['id']);
// どの画面でも任意回答に揃える（ブースの画面と必須・任意が入れ替わらないように）
$questions = $survey === null ? [] : as_soft_required_questions(questions_for_survey((int) $survey['id']));

if ($survey === null || $questions === [] || ((int) $survey['is_published'] !== 1 && !$preview)) {
    page_header('総合アンケート｜' . (string) $event['name'], ['brand' => (string) $event['name']]);
    echo '<h1>総合アンケート</h1>';
    if ($preview) {
        echo '<div class="alert alert-warn">総合アンケートに設問がまだありません。';
        echo '<a href="/admin/invites.php">総合アンケートの編集</a>から設問を登録してください。</div>';
    } else {
        echo '<div class="alert alert-info">総合アンケートは現在公開されていません。しばらくしてからお試しください。</div>';
    }
    page_footer();
    exit;
}

// 会期中の画面では、前に書いた内容を出して直せるようにする
$previous = [];
$answered = false;
if (!$preview && $token === '') {
    $response = find_response((int) $survey['id'], (int) $visitor['id']);
    if ($response !== null) {
        $previous = response_values((int) $response['id'], $questions);
        $answered = true;
    }
}

$footer = 'ご回答後、' . wallpaper_label() . 'をダウンロードいただけます。';
if ($preview) {
    $footer = 'これはスタッフ確認用のプレビューです。来場者には、その方専用のURLが記載されたメールが届きます。';
} elseif ($token === '') {
    $footer = $answered
        ? '書き直した内容で上書きします。イベント開催中は何度でも直せます。'
        : 'お帰りの前にご回答ください。送信後も、開催中であれば内容を直せます。';
}

render_survey_page([
    'event'       => $event,
    'company'     => null,
    'survey'      => $survey,
    'questions'   => $questions,
    'hidden'      => $token === ''
        ? ['survey_id' => (string) $survey['id']]
        : ['survey_id' => (string) $survey['id'], 't' => $token],
    'ask_email'   => false,
    'previous'    => $previous,
    'invalid'     => [],
    'error'       => submit_error_message(get_string('err')),
    'email_value' => '',
    'preview'     => $preview,
    'footer_note' => $footer,
]);
