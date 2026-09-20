<?php
declare(strict_types=1);

/** 1社ぶんのアンケート集計（主催者・その企業の担当者） */

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';
require_once dirname(__DIR__, 2) . '/src/admin_view.php';

$user      = require_admin();
$companyId = (int) (get_string('company') ?? '0');
$company   = $companyId > 0 ? find_company($companyId) : null;
if ($company === null) {
    abort(404, 'ページが見つかりません。');
}
assert_company_access($user, $companyId);

$event     = find_event((int) $company['event_id']);
$survey    = ensure_company_survey($company);
$surveyId  = (int) $survey['id'];
$questions = questions_for_survey($surveyId);

$includeDuplicates = (get_string('dup') ?? '') === '1';

admin_page_header($user, (string) $company['name'] . ' の集計', 'index.php');

echo '<h1>' . e((string) $company['name']) . ' の集計</h1>';
echo '<p class="muted">' . e((string) ($event['name'] ?? '')) . '</p>';

$responses  = count_responses($surveyId, $includeDuplicates);
$duplicates = count_duplicate_responses($surveyId);

echo '<div class="stat-grid">';
render_stat('回答数', count_label($responses), $includeDuplicates ? '重複を含む' : '重複を除く');
render_stat('重複送信', count_label($duplicates), '同じ端末からの再送信');
render_stat('設問数', count_label(count($questions), '問'));
echo '</div>';

echo '<div class="btn-row">';
echo '<a class="btn" href="responses.php?company=' . $companyId . '">回答一覧</a>';
echo '<a class="btn" href="export_csv.php?survey=' . $surveyId . '">CSVダウンロード</a>';
echo '<a class="btn" href="survey_edit.php?survey=' . $surveyId . '">アンケート編集</a>';
echo '<a class="btn" href="company_stats.php?company=' . $companyId . ($includeDuplicates ? '' : '&dup=1') . '">'
    . ($includeDuplicates ? '重複を除いて表示' : '重複を含めて表示') . '</a>';
echo '</div>';

echo '<h2>時間帯別の回答数</h2>';
echo '<div class="card">' . svg_line_chart(responses_by_hour($surveyId)) . '</div>';

echo '<h2>設問別の集計</h2>';
render_survey_stats($surveyId, $questions, $includeDuplicates);

page_footer();
