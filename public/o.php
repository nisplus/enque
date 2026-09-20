<?php
declare(strict_types=1);

/**
 * 全体アンケートの回答画面（イベント終了後にメールで案内するURL）。
 *
 *   /o/<トークン>      （mod_rewrite 経由）
 *   /o.php?t=<トークン>
 */

require_once dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/render_survey.php';

$token  = (string) (get_string('t') ?? '');
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

$survey    = overall_survey((int) $event['id']);
$questions = $survey === null ? [] : questions_for_survey((int) $survey['id']);

if ($survey === null || (int) $survey['is_published'] !== 1 || $questions === []) {
    page_header('全体アンケート｜' . (string) $event['name'], ['brand' => (string) $event['name']]);
    echo '<h1>全体アンケート</h1>';
    echo '<div class="alert alert-info">全体アンケートは現在公開されていません。しばらくしてからお試しください。</div>';
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
    'footer_note' => 'ご回答後、オリジナルのスマホ壁紙をダウンロードいただけます。',
]);
