<?php
declare(strict_types=1);

/**
 * 回答者の傾向（主催者）。
 *
 * 周回時間・周回企業数・回った順番・動線など、1人あたりの動きを集計して見せる。
 * 企業担当者には他社を含む動きが見えるため、主催者のみが開ける。
 */

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';
require_once dirname(__DIR__, 2) . '/src/admin_view.php';
require_once dirname(__DIR__, 2) . '/src/insights.php';

$user   = require_organizer();
$events = all_events();

$eventId = (int) (get_string('event') ?? '0');
$event   = $eventId > 0 ? find_event($eventId) : ($events[0] ?? null);
if ($event === null) {
    flash_set('error', '先にイベントを作成してください。');
    redirect('index.php');
}
$eventId = (int) $event['id'];

$laps         = visitor_laps($eventId);
$lapTime      = lap_time_stats($laps);
$lapCompanies = lap_company_stats($laps);
$order        = company_visit_order($eventId);
$transitions  = company_transitions($eventId, 20);
$moves        = move_interval_stats($eventId);
$hourly       = hourly_unique_visitors($eventId);
$satisfaction = satisfaction_by_lap_count($eventId, $laps);
$rates        = participation_rates($eventId);

admin_page_header($user, '回答者傾向', 'insights.php');

echo '<h1>回答者の傾向</h1>';
echo '<p class="muted">' . e((string) $event['name']) . '</p>';

if (count($events) > 1) {
    echo '<form method="get" class="card" style="padding:10px 12px">';
    echo '<label class="field" for="event" style="margin:0">イベント</label>';
    echo '<select id="event" name="event" onchange="this.form.submit()">';
    foreach ($events as $row) {
        $selected = (int) $row['id'] === $eventId ? ' selected' : '';
        echo '<option value="' . (int) $row['id'] . '"' . $selected . '>' . e((string) $row['name']) . '</option>';
    }
    echo '</select><noscript><div class="btn-row"><button class="btn btn-small">表示</button></div></noscript></form>';
}

if ($laps === []) {
    echo '<div class="card"><p class="muted">まだ回答がありません。回答が集まると、ここに周回の傾向が表示されます。</p></div>';
    page_footer();
    exit;
}

// ---------------------------------------------------------------- サマリー
echo '<div class="stat-grid">';
render_stat('回答した来場者', count_label(count($laps), '人'));
render_stat('平均 周回企業数', (string) $lapCompanies['summary']['avg'] . '社',
    '最大 ' . (int) $lapCompanies['summary']['max'] . '社');
render_stat('平均 周回時間', format_duration($lapTime['summary']['avg']),
    $lapTime['summary']['count'] . '人（2社以上）');
render_stat('周回時間の中央値', format_duration($lapTime['summary']['median']));
render_stat('最長 / 最短 周回時間',
    format_duration($lapTime['summary']['max']) . ' / ' . format_duration($lapTime['summary']['min']));
render_stat('ブース間の移動間隔', format_duration($moves['median']), '中央値');
echo '</div>';

echo '<div class="alert alert-info">「周回時間」は<strong>最初の回答から最後の回答まで</strong>の間隔です。';
echo '入場から1社目まで、最後のブースから退場までは含みません。';
echo '1社だけ回った方（' . $lapTime['single_company_visitors'] . '人）は時間が0になるため、時間の集計から除いています。</div>';

// ---------------------------------------------------------------- 来場人数
$party = party_size_stats($eventId);
if ($party['configured']) {
    echo '<h2>来場人数（何人で来られたか）</h2>';
    echo '<div class="card">';
    if ($party['answered'] === 0) {
        echo '<p class="muted">まだ人数の回答がありません。</p>';
    } else {
        echo '<div class="stat-grid">';
        render_stat('のべ来場者', count_label($party['total_visits'], '人'), '各回答の人数の合計');
        render_stat('実来場者（推計）', count_label($party['unique_people'], '人'), '1人1回として数えた人数');
        render_stat('平均人数', (string) $party['average'] . '人', '1回答あたり');
        render_stat('回答率', $party['answer_rate'] . '%',
            $party['answered'] . ' / ' . $party['responses'] . '件');
        echo '</div>';
        echo svg_bar_chart($party['distribution'], $party['answered']);
        echo '<p class="muted">人数は任意回答のため、未回答のぶんは';
        echo '<strong>同じ来場者が別の企業で答えた人数</strong>、それも無ければ';
        echo '<strong>回答があったぶんの平均（' . $party['average'] . '人）</strong>を当てはめて計算しています。';
        echo '選択肢の上限「' . party_size_max() . '人以上」は、' . party_size_max() . '人として数えています'
            . '（多めには見積もりません）。</p>';
    }
    echo '</div>';
}

// ---------------------------------------------------------------- 周回企業数
echo '<h2>1人あたりの周回企業数</h2>';
echo '<div class="card">';
echo '<div class="stat-grid">';
render_stat('平均', (string) $lapCompanies['summary']['avg'] . '社');
render_stat('中央値', (string) $lapCompanies['summary']['median'] . '社');
render_stat('最大', (string) (int) $lapCompanies['summary']['max'] . '社');
render_stat('最小', (string) (int) $lapCompanies['summary']['min'] . '社');
echo '</div>';
echo svg_bar_chart($lapCompanies['distribution'], count($laps));
echo '<p class="muted">棒は「その社数を回った人が何人いたか」です。</p>';
echo '</div>';

// ---------------------------------------------------------------- 周回時間
echo '<h2>1人あたりの周回時間</h2>';
echo '<div class="card">';
if ($lapTime['summary']['count'] === 0) {
    echo '<p class="muted">2社以上を回った方がまだいません。</p>';
} else {
    echo '<div class="stat-grid">';
    render_stat('平均', format_duration($lapTime['summary']['avg']));
    render_stat('中央値', format_duration($lapTime['summary']['median']));
    render_stat('最長', format_duration($lapTime['summary']['max']));
    render_stat('最短', format_duration($lapTime['summary']['min']));
    echo '</div>';
    echo svg_bar_chart($lapTime['distribution'], $lapTime['summary']['count']);
}
echo '</div>';

// ---------------------------------------------------------------- 周回順
echo '<h2>企業をまわる順番</h2>';
echo '<div class="card">';
echo '<div class="table-scroll"><table>';
echo '<thead><tr><th>企業</th><th class="num">平均 何番目</th><th class="num">最初に回られた</th><th class="num">回答数</th></tr></thead><tbody>';
foreach ($order as $row) {
    echo '<tr><td>' . e($row['name']) . '</td>';
    echo '<td class="num">' . $row['avg_position'] . '番目</td>';
    echo '<td class="num">' . $row['first_visits'] . '人</td>';
    echo '<td class="num">' . $row['visits'] . '</td></tr>';
}
echo '</tbody></table></div>';
echo '<p class="muted">「平均 何番目」が小さい企業ほど、来場者が先に立ち寄っています（入口に近い、目を引く等）。';
echo '「最初に回られた」は、その企業から周遊を始めた人数です。</p>';
echo '</div>';

// ---------------------------------------------------------------- 動線
echo '<h2>よくある動線（上位' . count($transitions) . '件）</h2>';
echo '<div class="card">';
if ($transitions === []) {
    echo '<p class="muted">2社以上を回った方がまだいないため、動線は集計できません。</p>';
} else {
    echo '<div class="table-scroll"><table>';
    echo '<thead><tr><th>前に回った企業</th><th>次に回った企業</th><th class="num">人数</th></tr></thead><tbody>';
    foreach ($transitions as $row) {
        echo '<tr><td>' . e($row['from']) . '</td><td>' . e($row['to']) . '</td>';
        echo '<td class="num">' . $row['count'] . '</td></tr>';
    }
    echo '</tbody></table></div>';
    echo '<p class="muted">隣り合って回られた組み合わせです。ブース配置や導線づくりの参考になります。</p>';
}
echo '</div>';

// ---------------------------------------------------------------- 時間帯
echo '<h2>時間帯別のユニーク来場者数</h2>';
echo '<div class="card">';
echo svg_line_chart($hourly);
echo '<p class="muted">その時間帯に回答した「人数」です（回答数ではありません）。</p>';
echo '</div>';

// ---------------------------------------------------------------- 周回数と評価
echo '<h2>周回企業数と評価の関係</h2>';
echo '<div class="card">';
echo '<div class="table-scroll"><table>';
echo '<thead><tr><th>周回企業数</th><th class="num">人数</th><th class="num">平均評価</th><th class="num">NPS</th><th class="num">評価の回答数</th></tr></thead><tbody>';
foreach ($satisfaction as $row) {
    echo '<tr><td>' . e($row['label']) . '</td>';
    echo '<td class="num">' . $row['visitors'] . '人</td>';
    echo '<td class="num">' . ($row['rating_avg'] === null ? '—' : $row['rating_avg'] . ' / ' . RATING_MAX) . '</td>';
    echo '<td class="num">' . ($row['nps_score'] === null ? '—' : $row['nps_score']) . '</td>';
    echo '<td class="num muted">' . $row['answers'] . '</td></tr>';
}
echo '</tbody></table></div>';
echo '<p class="muted">企業アンケートの星評価・NPSを、その方が回った企業数の区分ごとに平均したものです。';
echo '差が出ても<strong>相関であって因果ではありません</strong>（もともと関心の高い方がたくさん回っている、とも読めます）。</p>';
echo '</div>';

// ---------------------------------------------------------------- 各種の割合
echo '<h2>回答・登録の割合</h2>';
echo '<div class="stat-grid">';
render_stat('回答率', $rates['response_rate'] . '%',
    'アンケートを開いた ' . $rates['opened'] . '人中 ' . $rates['responded'] . '人が送信');
render_stat('重複送信の割合', $rates['duplicate_rate'] . '%', '同じ企業への送り直し');
render_stat('メール登録率', $rates['email_rate'] . '%', '回答者のうち');
render_stat('総合アンケート回答率', $rates['overall_rate'] . '%', '案内メールを送った人のうち');
render_stat('景品交換率', $rates['claim_rate'] . '%', 'コード発行のうち');
echo '</div>';

echo '<div class="alert alert-info">来場者の識別は端末の匿名Cookie単位です。';
echo '同じ方でも端末を変えて回答した場合は別の方として数えられます。</div>';

echo '<div class="btn-row">';
echo '<a class="btn" href="index.php?event=' . $eventId . '">ダッシュボードへ</a>';
echo '<a class="btn" href="export_csv.php?event=' . $eventId . '">回答データのCSV</a>';
echo '</div>';

page_footer();
