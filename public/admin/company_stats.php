<?php
declare(strict_types=1);

/**
 * アンケートの集計（主催者・その企業の担当者）。
 *
 *   ?company=<id>  1社ぶんの集計
 *   ?survey=<id>   アンケートを直接指定（総合アンケートはこちらで開く）
 *
 * 総合アンケートは企業にひも付かないため、survey 指定でも開けるようにしている。
 * 総合アンケートのときは、全体の読み解きに役立つ集計（回答率・周回企業数との関係・
 * 来場人数との関係）もあわせて出す。
 *
 * 「グループの構成」は、区分ごとの人数を聞く設問（metric = group_member）が
 * あるときだけ出す。総合アンケートに限らず、どのアンケートでも読める。
 */

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';
require_once dirname(__DIR__, 2) . '/src/admin_view.php';
require_once dirname(__DIR__, 2) . '/src/insights.php';

$user      = require_admin();
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
        // 総合アンケート・共通設問テンプレートは主催者だけが見られる
        abort(404, 'ページが見つかりません。');
    }
}

$surveyId  = (int) $survey['id'];
$companyId = $company === null ? 0 : (int) $company['id'];
$type      = (string) $survey['type'];
$isOverall = $type === 'overall';
$event     = find_event((int) $survey['event_id']);
$questions = questions_for_survey($surveyId);

$includeDuplicates = (get_string('dup') ?? '') === '1';
$selfUrl = $companyId > 0 ? 'company_stats.php?company=' . $companyId : 'company_stats.php?survey=' . $surveyId;
$title   = $company !== null
    ? (string) $company['name'] . ' の集計'
    : ($isOverall ? overall_label() . 'の集計' : (string) $survey['title'] . ' の集計');

admin_page_header($user, $title, 'index.php');

echo '<h1>' . e($title) . '</h1>';
echo '<p class="muted">' . e((string) ($event['name'] ?? '')) . '</p>';

$responses = count_responses($surveyId, $includeDuplicates);
$edited    = count_edited_responses($surveyId);

echo '<div class="stat-grid">';
render_stat('回答数', count_label($responses), $includeDuplicates ? '重複を含む' : '重複を除く');
if ($isOverall && $event !== null) {
    // 回答した来場者のうち、どれだけが総合アンケートにも答えたか
    $summary = event_summary((int) $event['id']);
    render_stat('回答率', percentage($responses, $summary['responding_visitors']) . '%',
        '回答した来場者 ' . count_label($summary['responding_visitors'], '人') . 'のうち');
}
render_stat('修正された回答', count_label($edited), '来場者があとから書き直したぶん');
render_stat('設問数', count_label(count($questions), '問'));
echo '</div>';

echo '<div class="btn-row">';
echo '<a class="btn" href="responses.php?survey=' . $surveyId . '">回答一覧</a>';
echo '<a class="btn" href="export_csv.php?survey=' . $surveyId . '">CSVダウンロード</a>';
echo '<a class="btn" href="survey_edit.php?survey=' . $surveyId . '">アンケート編集</a>';
echo '<a class="btn" href="' . e($selfUrl . ($includeDuplicates ? '' : '&dup=1')) . '">'
    . ($includeDuplicates ? '重複を除いて表示' : '重複を含めて表示') . '</a>';
echo '</div>';

if ($questions === []) {
    echo '<div class="card"><p class="muted">設問がまだ登録されていません。';
    echo '<a href="survey_edit.php?survey=' . $surveyId . '">アンケート編集</a>から登録してください。</p></div>';
    page_footer();
    exit;
}

echo '<h2>時間帯別の回答数</h2>';
echo '<div class="card">' . svg_line_chart(responses_by_hour($surveyId)) . '</div>';

echo '<h2>設問別の集計</h2>';
render_survey_stats($surveyId, $questions, $includeDuplicates);

// ---------------------------------------------------------------- グループの構成
$eventId = (int) $survey['event_id'];
$laps    = $responses > 0 ? visitor_laps($eventId) : [];
$group   = $responses > 0
    ? group_member_stats($eventId, $surveyId, $laps)
    : ['configured' => false, 'groups' => 0];

if ($group['configured'] && $group['groups'] > 0) {
    echo '<h2>グループの構成</h2>';

    echo '<div class="stat-grid">';
    render_stat('内訳を答えたグループ', count_label($group['groups'], '組'),
        '回答 ' . count_label($group['responses']) . 'の ' . $group['answer_rate'] . '%');
    render_stat('内訳の合計人数', count_label($group['people'], '人'), 'のべ来場者には足していません');
    render_stat('1組あたりの人数', $group['avg_size'] . '人', '内訳の合計の平均');
    echo '</div>';

    echo '<div class="card">';
    echo '<h3 style="margin-top:0">区分別の人数</h3>';
    echo svg_stacked_bar(array_column($group['categories'], 'people', 'label'), $group['people'], 'series', '人');
    echo '<div class="table-scroll"><table>';
    echo '<thead><tr><th>区分</th><th class="num">合計人数</th><th class="num">構成比</th>'
        . '<th class="num">いた組</th><th class="num">1組あたり</th></tr></thead><tbody>';
    foreach ($group['categories'] as $row) {
        echo '<tr><td>' . e($row['label']) . '</td>';
        echo '<td class="num">' . count_label($row['people'], '人') . '</td>';
        echo '<td class="num">' . $row['share'] . '%</td>';
        echo '<td class="num">' . count_label($row['groups'], '組') . '（' . $row['group_share'] . '%）</td>';
        echo '<td class="num">' . $row['avg'] . '人</td></tr>';
    }
    echo '</tbody></table></div>';
    echo '<p class="muted">構成比は内訳の合計人数に対する割合です。';
    echo '「いた組」はその区分が1人以上いた組の数（カッコ内は内訳を答えた組に対する割合）。</p>';
    echo '</div>';

    echo '<div class="card">';
    echo '<h3 style="margin-top:0">よくあるグループの型</h3>';
    echo svg_stacked_bar(array_column($group['patterns'], 'groups', 'label'), $group['groups'], 'series', '組');
    echo '<div class="table-scroll"><table>';
    echo '<thead><tr><th>型</th><th class="num">組数</th><th class="num">割合</th></tr></thead><tbody>';
    foreach ($group['patterns'] as $row) {
        echo '<tr><td>' . e($row['label']) . '</td>';
        echo '<td class="num">' . count_label($row['groups'], '組') . '</td>';
        echo '<td class="num">' . $row['share'] . '%</td></tr>';
    }
    echo '</tbody></table></div>';
    echo '<p class="muted">0人の区分は書いていません。多い順に' . GROUP_PATTERN_LIMIT . '通りまで並べています。</p>';
    echo '</div>';

    if ($group['segments'] !== []) {
        echo '<div class="card">';
        echo '<h3 style="margin-top:0">区分ごとの傾向</h3>';
        echo '<div class="table-scroll"><table>';
        echo '<thead><tr><th>グループ</th><th class="num">組数</th><th class="num">平均周回企業数</th>'
            . '<th class="num">平均評価</th></tr></thead><tbody>';
        foreach ($group['segments'] as $row) {
            echo '<tr><td>' . e($row['label']) . '</td>';
            echo '<td class="num">' . count_label($row['groups'], '組') . '（' . $row['share'] . '%）</td>';
            echo '<td class="num">' . ($row['laps_avg'] === null ? '—' : $row['laps_avg'] . '社') . '</td>';
            echo '<td class="num">' . ($row['rating_avg'] === null ? '—' : $row['rating_avg'] . ' / ' . RATING_MAX) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
        echo '<p class="muted">「◯◯を含む」は重なって数えます（大人と小学生がいる組は両方の行に入ります）。';
        echo '「◯◯のみ」はその区分だけの組で、' . GROUP_ONLY_MIN . '組以上あるときだけ出します。';
        echo '差が出ても<strong>相関であって因果ではありません</strong>。</p>';
        echo '</div>';
    }

    if ($group['check']['compared'] > 0) {
        echo '<div class="card">';
        echo '<h3 style="margin-top:0">来場人数との照合</h3>';
        echo '<p>' . count_label($group['check']['compared'], '組') . '中'
            . count_label($group['check']['matched'], '組')
            . '（' . $group['check']['match_rate'] . '%）で、来場人数と内訳の合計が一致しています。</p>';
        echo svg_stacked_bar($group['check']['distribution'], $group['check']['compared'], 'series', '組');
        echo '<div class="table-scroll"><table>';
        echo '<thead><tr><th>来場人数との差</th><th class="num">組数</th></tr></thead><tbody>';
        foreach ($group['check']['distribution'] as $label => $count) {
            echo '<tr><td>' . e((string) $label) . '</td>';
            echo '<td class="num">' . count_label($count, '組') . '</td></tr>';
        }
        echo '</tbody></table></div>';
        echo '<p class="muted">来場人数は企業アンケートでの申告（同じ端末の新しい回答を優先）です。';
        echo '差が出るのは珍しくありません（本人を数えたかどうか、あとから合流した方がいるなど）。';
        echo '人数の集計そのものは来場人数のほうを使っています。</p>';
        echo '</div>';
    }
}

// ---------------------------------------------------------------- 総合アンケートの読み解き
if ($isOverall && $event !== null && $responses > 0) {
    $byLap = satisfaction_by_lap_count($eventId, $laps, 'overall');

    echo '<h2>周回企業数との関係</h2>';
    echo '<div class="card">';
    echo '<div class="table-scroll"><table>';
    echo '<thead><tr><th>周回企業数</th><th class="num">人数</th><th class="num">平均評価</th>'
        . '<th class="num">NPS</th><th class="num">評価の回答数</th></tr></thead><tbody>';
    foreach ($byLap as $row) {
        echo '<tr><td>' . e($row['label']) . '</td>';
        echo '<td class="num">' . $row['visitors'] . '人</td>';
        echo '<td class="num">' . ($row['rating_avg'] === null ? '—' : $row['rating_avg'] . ' / ' . RATING_MAX) . '</td>';
        echo '<td class="num">' . ($row['nps_score'] === null ? '—' : $row['nps_score']) . '</td>';
        echo '<td class="num muted">' . $row['answers'] . '</td></tr>';
    }
    echo '</tbody></table></div>';
    echo '<p class="muted">' . e(overall_label()) . 'の星評価・NPSを、その方が回った企業数の区分ごとに平均したものです。';
    echo '差が出ても<strong>相関であって因果ではありません</strong>';
    echo '（もともと関心の高い方がたくさん回っている、とも読めます）。</p>';
    echo '</div>';

    $byParty = rating_by_party_size($eventId);
    if ($byParty !== []) {
        echo '<h2>来場人数との関係</h2>';
        echo '<div class="card">';
        echo '<div class="table-scroll"><table>';
        echo '<thead><tr><th>来場人数</th><th class="num">人数</th><th class="num">平均評価</th>'
            . '<th class="num">評価の回答数</th></tr></thead><tbody>';
        foreach ($byParty as $row) {
            echo '<tr><td>' . e($row['label']) . '</td>';
            echo '<td class="num">' . $row['visitors'] . '人</td>';
            echo '<td class="num">' . ($row['rating_avg'] === null ? '—' : $row['rating_avg'] . ' / ' . RATING_MAX) . '</td>';
            echo '<td class="num muted">' . $row['answers'] . '</td></tr>';
        }
        echo '</tbody></table></div>';
        echo '<p class="muted">「何人で来られましたか」の回答で分けています。';
        echo '人数を答えていない方は数えていません。</p>';
        echo '</div>';
    }
}

page_footer();
