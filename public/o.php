<?php
declare(strict_types=1);

/**
 * 総合アンケートの回答画面（イベント終了後にメールで案内するURL）。
 *
 *   /o/<トークン>      （mod_rewrite 経由）
 *   /o.php?t=<トークン>
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
} else {
    $invite = $token === '' ? null : find_invite_by_token($token);
    if ($invite === null) {
        abort(404, 'このURLは無効です。メールに記載されたリンクをもう一度お試しください。');
    }

    $event = find_event((int) $invite['event_id']);
    if ($event === null) {
        abort(404, 'このURLは無効です。');
    }

    // 回答済みなら壁紙ダウンロード画面へ
    if (($invite['responded_at'] ?? null) !== null) {
        redirect('/wallpaper.php?t=' . rawurlencode($token));
    }
}

$survey    = overall_survey((int) $event['id']);
$questions = $survey === null ? [] : questions_for_survey((int) $survey['id']);

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

render_survey_page([
    'event'       => $event,
    'company'     => null,
    'survey'      => $survey,
    'questions'   => $questions,
    'hidden'      => ['survey_id' => (string) $survey['id'], 't' => $token],
    'ask_email'   => false,
    'previous'    => [],
    'invalid'     => [],
    'error'       => submit_error_message(get_string('err')),
    'email_value' => '',
    'preview'     => $preview,
    'footer_note' => $preview
        ? 'これはスタッフ確認用のプレビューです。来場者には、その方専用のURLが記載されたメールが届きます。'
        : 'ご回答後、' . wallpaper_label() . 'をダウンロードいただけます。',
]);
