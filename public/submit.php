<?php
declare(strict_types=1);

/**
 * アンケート回答の受け付け（企業アンケート・全体アンケート共通）。
 *
 * fetch から呼ばれた場合はJSONを返し、通常のフォーム送信（JS無効）の場合は
 * 画面を描き直す／完了画面へリダイレクトする。
 */

require_once dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/render_survey.php';

/** fetch（XHR）からの送信か */
function wants_json(): bool
{
    $accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
    $xhr    = (string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '');

    return str_contains($accept, 'application/json') || $xhr !== '';
}

/**
 * エラーを返す（JSON または 元の画面の再表示）。
 *
 * @param list<int> $questionIds 印を付ける設問
 */
function fail(string $code, array $questionIds = [], ?array $view = null): never
{
    $message = submit_error_message($code) ?? submit_error_message('system');

    if (wants_json()) {
        json_response([
            'ok'        => false,
            'error'     => $code,
            'message'   => $message,
            'questions' => $questionIds,
        ], 400);
    }

    if ($view !== null) {
        http_response_code(400);
        $view['invalid'] = $questionIds;
        $view['error']   = $message;
        render_survey_page($view);
        exit;
    }

    abort(400, (string) $message);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    abort(405, '送信方法が正しくありません。');
}

// 別サイトのページからの送信を弾く（来場者側はセッションを作らないため、
// CSRFトークンの代わりに Origin/Referer と SameSite=Lax のCookieで守る）
$origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
if ($origin !== '') {
    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    if (parse_url($origin, PHP_URL_HOST) !== parse_url('http://' . $host, PHP_URL_HOST)) {
        fail('system');
    }
}

$surveyId = (int) (post_string('survey_id') ?? '0');
$survey   = $surveyId > 0 ? find_survey($surveyId) : null;
if ($survey === null) {
    fail('closed');
}

$event = find_event((int) $survey['event_id']);
if ($event === null) {
    fail('closed');
}

$questions = questions_for_survey($surveyId);
if ((int) $survey['is_published'] !== 1 || $questions === []) {
    fail('closed');
}

$type    = (string) $survey['type'];
$company = null;
$invite  = null;

if ($type === 'company') {
    if ((string) $event['status'] !== 'open') {
        fail('closed');
    }
    $company = find_company((int) $survey['company_id']);
    if ($company === null || (int) $company['is_active'] !== 1) {
        fail('closed');
    }
    $visitor = current_visitor((int) $event['id']);
} elseif ($type === 'overall') {
    // 全体アンケートはメールで配ったトークンでのみ回答できる
    $token  = post_string('t') ?? '';
    $invite = $token === '' ? null : find_invite_by_token($token);
    if ($invite === null || (int) $invite['event_id'] !== (int) $event['id']) {
        fail('closed');
    }
    $visitorRow = find_visitor((int) $invite['visitor_id']);
    if ($visitorRow === null) {
        fail('closed');
    }
    $visitor = $visitorRow;
} else {
    fail('closed'); // テンプレートは回答を受け付けない
}

// 再表示用のビュー（エラー時に入力内容を保持したまま描き直す）
$previous = [];
$posted   = $_POST['q'] ?? [];
if (is_array($posted)) {
    foreach ($posted as $qid => $value) {
        $previous[(int) $qid] = $value;
    }
}
$emailInput = trim_ja((string) (post_string('email') ?? ''));

$view = [
    'event'       => $event,
    'company'     => $company,
    'survey'      => $survey,
    'questions'   => $questions,
    'hidden'      => $type === 'overall'
        ? ['survey_id' => (string) $survey['id'], 't' => (string) ($invite['token'] ?? '')]
        : ['survey_id' => (string) $survey['id']],
    'ask_email'   => $type === 'company' && ($visitor['email'] ?? null) === null,
    'previous'    => $previous,
    'invalid'     => [],
    'error'       => null,
    'email_value' => $emailInput,
    'footer_note' => $type === 'company' ? '送信後、総合受付で提示できる交換コードが表示されます。' : null,
];

if (!is_valid_utf8($_POST)) {
    fail('encoding', [], $view);
}

// --- 入力検証 --------------------------------------------------------------
$answers  = [];
$invalid  = [];
$firstErr = null;

foreach ($questions as $question) {
    $qid    = (int) $question['id'];
    $result = validate_answer($question, $posted[$qid] ?? null);
    if ($result['ok'] === false) {
        $invalid[]  = $qid;
        $firstErr ??= $result['error'];
        continue;
    }
    $answers[$qid] = $result['value'];
}

if ($invalid !== []) {
    fail((string) $firstErr, $invalid, $view);
}

if ($emailInput !== '' && !is_valid_email($emailInput)) {
    fail('email', [], $view);
}

// --- 保存 ------------------------------------------------------------------
try {
    if ($emailInput !== '') {
        set_visitor_email((int) $visitor['id'], $emailInput);
    }

    $result = insert_response($surveyId, (int) $visitor['id'], $answers);

    if ($type === 'company') {
        // いずれか1社に回答した時点で交換コードを発行する
        find_or_create_claim((int) $visitor['id']);
        $redirect = '/done.php?e=' . rawurlencode((string) $event['slug']);
    } else {
        mark_invite_responded((int) $invite['id']);
        $redirect = '/wallpaper.php?t=' . rawurlencode((string) $invite['token']);
    }
} catch (Throwable $e) {
    error_log('回答の保存に失敗: ' . $e->getMessage());
    fail('system', [], $view);
}

if (wants_json()) {
    json_response(['ok' => true, 'redirect' => $redirect, 'duplicate' => $result['is_duplicate']]);
}

redirect($redirect);
