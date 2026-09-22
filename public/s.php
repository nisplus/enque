<?php
declare(strict_types=1);

/**
 * 企業ブースのアンケート回答画面（来場者向け）。
 *
 *   /s/<イベントスラグ>/<企業スラグ>   （mod_rewrite 経由）
 *   /s.php?e=<イベント>&c=<企業>       （書き換えなしの場合）
 */

require_once dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/render_survey.php';

$eventSlug   = (string) (get_string('e') ?? '');
$companySlug = (string) (get_string('c') ?? '');

$event = $eventSlug === '' ? null : find_event_by_slug($eventSlug);
if ($event === null) {
    abort(404, 'このURLのアンケートは見つかりませんでした。QRコードをもう一度読み取ってください。');
}

$company = find_company_by_slug((int) $event['id'], $companySlug);
if ($company === null || (int) $company['is_active'] !== 1) {
    abort(404, 'このURLのアンケートは見つかりませんでした。QRコードをもう一度読み取ってください。');
}

$survey    = survey_for_company((int) $company['id']);
$questions = $survey === null ? [] : questions_for_survey((int) $survey['id']);
$status    = (string) $event['status'];

$available = $survey !== null
    && (int) $survey['is_published'] === 1
    && $questions !== []
    && $status === 'open';

if (!$available) {
    page_header((string) $company['name'] . '｜' . (string) $event['name'], ['brand' => (string) $event['name']]);
    render_company_badge($company);
    echo '<h1>' . e((string) ($survey['title'] ?? 'アンケート')) . '</h1>';
    if ($status === 'closed') {
        echo '<div class="alert alert-info">このイベントのブースアンケートは受付を終了しました。ご協力ありがとうございました。</div>';
    } elseif ($status === 'draft') {
        echo '<div class="alert alert-info">このアンケートはまだ公開されていません。</div>';
    } else {
        echo '<div class="alert alert-info">このアンケートは現在受け付けていません。ブースの担当者にお知らせください。</div>';
    }
    page_footer();
    exit;
}

// 回答画面を開いた時点で来場者セッション（Cookie）を用意する
$visitor = current_visitor((int) $event['id']);

render_survey_page([
    'event'       => $event,
    'company'     => $company,
    'survey'      => $survey,
    'questions'   => $questions,
    'hidden'      => ['survey_id' => (string) $survey['id']],
    'ask_email'   => ($visitor['email'] ?? null) === null,
    'previous'    => [],
    'invalid'     => [],
    'error'       => submit_error_message(get_string('err')),
    'email_value' => '',
    'footer_note' => '送信後、総合受付で提示できる交換コードが表示されます。ほかの参加企業のアンケートでも同じスマホ・同じブラウザで読み取ると、1つの交換コードにまとまります。',
]);
