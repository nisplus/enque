<?php
declare(strict_types=1);

/** 回答一覧（主催者・その企業の担当者） */

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';
require_once dirname(__DIR__, 2) . '/src/admin_view.php';

$user = require_admin();

$companyId = (int) (get_string('company') ?? '0');
$surveyId  = (int) (get_string('survey') ?? '0');

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
$event     = find_event((int) $survey['event_id']);
$questions = questions_for_survey($surveyId);

$includeDuplicates = (get_string('dup') ?? '') === '1';
$page    = max(1, (int) (get_string('page') ?? '1'));
$perPage = 50;
$total   = count_responses($surveyId, $includeDuplicates);

$responses = responses_for_survey($surveyId, $includeDuplicates, $perPage, ($page - 1) * $perPage);
$answers   = answers_for_responses(array_map(static fn(array $r): int => (int) $r['id'], $responses));

admin_page_header($user, '回答一覧', 'index.php');

echo '<h1>回答一覧</h1>';
echo '<p class="muted">' . e((string) ($company['name'] ?? (string) $survey['title'])) . '／'
    . e((string) ($event['name'] ?? '')) . '</p>';

echo '<div class="btn-row">';
echo '<a class="btn" href="export_csv.php?survey=' . $surveyId . ($includeDuplicates ? '&dup=1' : '') . '">CSVダウンロード</a>';
echo '<a class="btn" href="responses.php?survey=' . $surveyId . ($includeDuplicates ? '' : '&dup=1') . '">'
    . ($includeDuplicates ? '重複を除いて表示' : '重複を含めて表示') . '</a>';
if ($company !== null) {
    echo '<a class="btn" href="company_stats.php?company=' . (int) $company['id'] . '">集計へ</a>';
}
echo '</div>';

echo '<p class="muted">全' . count_label($total) . '（' . $page . 'ページ目 / 最大' . max(1, (int) ceil($total / $perPage)) . 'ページ）</p>';

echo '<div class="card"><div class="table-scroll"><table>';
echo '<thead><tr><th class="nowrap">送信日時</th><th>回答</th></tr></thead><tbody>';

foreach ($responses as $response) {
    $rid = (int) $response['id'];
    echo '<tr' . ((int) $response['is_duplicate'] === 1 ? ' class="is-duplicate"' : '') . '>';
    echo '<td class="nowrap">' . e(format_datetime_ja((string) $response['submitted_at']));
    if ((int) $response['is_duplicate'] === 1) {
        echo '<br><span class="badge badge-optional">重複</span>';
    }
    echo '</td><td>';
    foreach ($questions as $question) {
        $qid   = (int) $question['id'];
        $value = $answers[$rid][$qid] ?? null;
        echo '<div><span class="muted">' . e(mb_strimwidth((string) $question['label'], 0, 40, '…')) . '：</span>';
        echo $value === null ? '<span class="muted">（未回答）</span>' : nl2br(e(format_answer_value($question, $value)));
        echo '</div>';
    }
    echo '</td></tr>';
}
if ($responses === []) {
    echo '<tr><td colspan="2" class="muted">回答がありません。</td></tr>';
}
echo '</tbody></table></div>';

echo '<div class="btn-row">';
if ($page > 1) {
    echo '<a class="btn btn-small" href="responses.php?survey=' . $surveyId . '&page=' . ($page - 1)
        . ($includeDuplicates ? '&dup=1' : '') . '">前のページ</a>';
}
if ($page * $perPage < $total) {
    echo '<a class="btn btn-small" href="responses.php?survey=' . $surveyId . '&page=' . ($page + 1)
        . ($includeDuplicates ? '&dup=1' : '') . '">次のページ</a>';
}
echo '</div></div>';

page_footer();
