<?php
declare(strict_types=1);

/**
 * CSVエクスポート。
 *
 *   ?survey=<id>   1アンケート分（1行＝1回答、設問が列）
 *   ?company=<id>  同上（企業から辿る）
 *   ?event=<id>    イベント全社分（1行＝1設問の回答。企業ごとに設問が違うため縦持ち）
 *
 * メールアドレスは出力しない（案内送付以外の目的に使わないため）。
 * 来場者は匿名IDのみを出力し、同一来場者の回答を企業横断で突き合わせられるようにする。
 */

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';
require_once dirname(__DIR__, 2) . '/src/admin_view.php';

$user              = require_admin();
$includeDuplicates = (get_string('dup') ?? '') === '1';

$eventId   = (int) (get_string('event') ?? '0');
$companyId = (int) (get_string('company') ?? '0');
$surveyId  = (int) (get_string('survey') ?? '0');

// ---------------------------------------------------------------- イベント全社分
if ($eventId > 0) {
    require_organizer();
    $event = find_event($eventId);
    if ($event === null) {
        abort(404, 'ページが見つかりません。');
    }

    $rows = [['企業名', '回答ID', '来場者ID', '送信日時', '重複', '設問', '回答']];

    foreach (companies_for_event($eventId, true) as $company) {
        $survey = survey_for_company((int) $company['id']);
        if ($survey === null) {
            continue;
        }
        $questions = questions_for_survey((int) $survey['id']);
        $responses = responses_for_survey((int) $survey['id'], $includeDuplicates);
        $answers   = answers_for_responses(array_map(static fn(array $r): int => (int) $r['id'], $responses));

        foreach ($responses as $response) {
            $rid = (int) $response['id'];
            foreach ($questions as $question) {
                $value = $answers[$rid][(int) $question['id']] ?? null;
                $rows[] = [
                    (string) $company['name'],
                    $rid,
                    (int) $response['visitor_id'],
                    (string) $response['submitted_at'],
                    (int) $response['is_duplicate'] === 1 ? '重複' : '',
                    (string) $question['label'],
                    $value === null ? '' : format_answer_value($question, $value),
                ];
            }
        }
    }

    csv_download(csv_safe_filename((string) $event['name']) . '_all_' . date('Ymd_His') . '.csv', $rows);
}

// ---------------------------------------------------------------- 1アンケート分
if ($companyId > 0) {
    $company = find_company($companyId);
    if ($company === null) {
        abort(404, 'ページが見つかりません。');
    }
    assert_company_access($user, $companyId);
    $survey = ensure_company_survey($company);
} else {
    $survey = $surveyId > 0 ? find_survey($surveyId) : null;
    if ($survey === null) {
        abort(404, 'ページが見つかりません。');
    }
    $company = $survey['company_id'] === null ? null : find_company((int) $survey['company_id']);
    if ($company !== null) {
        assert_company_access($user, (int) $company['id']);
    } elseif ((string) $user['role'] !== 'organizer') {
        abort(404, 'ページが見つかりません。');
    }
}

$surveyId  = (int) $survey['id'];
$questions = questions_for_survey($surveyId);
$responses = responses_for_survey($surveyId, $includeDuplicates);
$answers   = answers_for_responses(array_map(static fn(array $r): int => (int) $r['id'], $responses));

$header = ['回答ID', '来場者ID', '送信日時', '重複'];
foreach ($questions as $question) {
    $header[] = (string) $question['label'];
}
$rows = [$header];

foreach ($responses as $response) {
    $rid = (int) $response['id'];
    $row = [
        $rid,
        (int) $response['visitor_id'],
        (string) $response['submitted_at'],
        (int) $response['is_duplicate'] === 1 ? '重複' : '',
    ];
    foreach ($questions as $question) {
        $value = $answers[$rid][(int) $question['id']] ?? null;
        $row[] = $value === null ? '' : format_answer_value($question, $value);
    }
    $rows[] = $row;
}

$name = (string) ($company['name'] ?? $survey['title']);
csv_download(csv_safe_filename($name) . '_' . date('Ymd_His') . '.csv', $rows);
