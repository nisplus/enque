<?php
declare(strict_types=1);

/**
 * 管理ダッシュボード。
 *
 *   主催者   … イベント全体の集計・企業別ランキング
 *   企業担当 … 自社アンケートの集計
 *   総合受付 … 景品照会へ
 */

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';
require_once dirname(__DIR__, 2) . '/src/admin_view.php';
require_once dirname(__DIR__, 2) . '/src/insights.php';

$user = require_admin();
$role = (string) $user['role'];

if ($role === 'reception') {
    redirect('claim.php');
}

// ---------------------------------------------------------------- 企業担当者
if ($role === 'company') {
    $company = find_company((int) $user['company_id']);
    if ($company === null) {
        abort(404, 'ページが見つかりません。');
    }
    $event   = find_event((int) $company['event_id']);
    $survey  = ensure_company_survey($company);
    $surveyId = (int) $survey['id'];
    $questions = questions_for_survey($surveyId);

    admin_page_header($user, (string) $company['name'], 'index.php');
    render_alert(flash_take());

    echo '<h1>' . e((string) $company['name']) . '</h1>';
    echo '<p class="muted">' . e((string) ($event['name'] ?? '')) . '（'
        . e(event_status_label((string) ($event['status'] ?? ''))) . '）</p>';

    if ((int) $survey['is_published'] !== 1) {
        echo '<div class="alert alert-warn">このアンケートは未公開です。公開するとQRコードから回答できるようになります。</div>';
    }

    $responses  = count_responses($surveyId);
    $duplicates = count_duplicate_responses($surveyId);

    echo '<div class="stat-grid">';
    render_stat('回答数', count_label($responses), '重複を除く');
    render_stat('重複送信', count_label($duplicates), '同じ端末からの再送信');
    render_stat('設問数', count_label(count($questions), '問'));
    echo '</div>';

    echo '<div class="btn-row">';
    echo '<a class="btn" href="survey_edit.php?survey=' . $surveyId . '">アンケートを編集</a>';
    echo '<a class="btn" href="responses.php?survey=' . $surveyId . '">回答一覧</a>';
    echo '<a class="btn" href="export_csv.php?survey=' . $surveyId . '">CSVダウンロード</a>';
    echo '<a class="btn" href="qr_print.php?company=' . (int) $company['id'] . '">QRコード</a>';
    echo '</div>';

    echo '<h2>時間帯別の回答数</h2>';
    echo '<div class="card">' . svg_line_chart(responses_by_hour($surveyId)) . '</div>';

    echo '<h2>設問別の集計</h2>';
    render_survey_stats($surveyId, $questions);

    page_footer();
    exit;
}

// ---------------------------------------------------------------- 主催者
$events = all_events();

// イベントの新規作成
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && (post_string('action') ?? '') === 'create_event') {
    require_valid_csrf();
    $name  = trim_ja((string) (post_string('name') ?? ''));
    $start = trim_ja((string) (post_string('start_date') ?? ''));
    $end   = trim_ja((string) (post_string('end_date') ?? ''));

    if ($name === '') {
        flash_set('error', 'イベント名を入力してください。');
    } else {
        $id = create_event(
            mb_substr($name, 0, 255),
            $start !== '' ? $start : null,
            $end !== '' ? $end : null,
            'draft'
        );
        flash_set('success', 'イベントを作成しました。企業を登録してください。');
        redirect('index.php?event=' . $id);
    }
    redirect('index.php');
}

// イベント設定の更新
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && (post_string('action') ?? '') === 'update_event') {
    require_valid_csrf();
    $eventId = (int) (post_string('event_id') ?? '0');
    $target  = find_event($eventId);
    if ($target === null) {
        abort(404, 'ページが見つかりません。');
    }
    $name   = trim_ja((string) (post_string('name') ?? ''));
    $start  = trim_ja((string) (post_string('start_date') ?? ''));
    $end    = trim_ja((string) (post_string('end_date') ?? ''));
    $status = (string) (post_string('status') ?? 'draft');
    if (!in_array($status, ['draft', 'open', 'closed'], true)) {
        $status = 'draft';
    }
    if ($name === '') {
        flash_set('error', 'イベント名を入力してください。');
    } else {
        update_event($eventId, mb_substr($name, 0, 255), $start !== '' ? $start : null, $end !== '' ? $end : null, $status);
        flash_set('success', 'イベント設定を保存しました。');
    }
    redirect('index.php?event=' . $eventId);
}

$eventId = (int) (get_string('event') ?? '0');
$event   = $eventId > 0 ? find_event($eventId) : ($events[0] ?? null);

admin_page_header($user, 'ダッシュボード', 'index.php');
render_alert(flash_take());

echo '<h1>ダッシュボード</h1>';

if ($event === null) {
    echo '<div class="card">';
    echo '<h2 style="margin-top:0">イベントを作成する</h2>';
    echo '<form method="post">' . csrf_field();
    echo '<input type="hidden" name="action" value="create_event">';
    echo '<label class="field" for="name">イベント名</label>';
    echo '<input type="text" id="name" name="name" required>';
    echo '<label class="field" for="start_date">開催日（開始）</label>';
    echo '<input type="date" id="start_date" name="start_date">';
    echo '<label class="field" for="end_date">開催日（終了）</label>';
    echo '<input type="date" id="end_date" name="end_date">';
    echo '<div class="btn-row"><button type="submit" class="btn btn-primary">作成する</button></div>';
    echo '</form></div>';
    page_footer();
    exit;
}

$eventId = (int) $event['id'];

// イベント切り替え
if (count($events) > 1) {
    echo '<form method="get" class="card" style="padding:10px 12px">';
    echo '<label class="field" for="event" style="margin:0">表示するイベント</label>';
    echo '<select id="event" name="event" onchange="this.form.submit()">';
    foreach ($events as $row) {
        $selected = (int) $row['id'] === $eventId ? ' selected' : '';
        echo '<option value="' . (int) $row['id'] . '"' . $selected . '>' . e((string) $row['name']) . '</option>';
    }
    echo '</select>';
    echo '<noscript><div class="btn-row"><button type="submit" class="btn btn-small">表示</button></div></noscript>';
    echo '</form>';
}

$summary  = event_summary($eventId);
$ranking  = company_response_ranking($eventId);
$overall  = overall_survey($eventId);
$template = template_survey($eventId);
$invites  = invite_stats($eventId);

echo '<h2>' . e((string) $event['name']) . '<span class="badge badge-'
    . ((string) $event['status'] === 'open' ? 'good' : 'optional') . '">'
    . e(event_status_label((string) $event['status'])) . '</span></h2>';

echo '<div class="stat-grid">';
render_stat('ユニーク来場者', count_label($summary['visitors'], '人'), 'アンケートを開いた端末数');
render_stat('回答した来場者', count_label($summary['responding_visitors'], '人'));
render_stat('総回答数', count_label($summary['responses']), '重複' . $summary['duplicates'] . '件を除く');
render_stat('平均訪問企業数', (string) $summary['avg_companies'] . '社', '1人あたり');
render_stat('メール登録', count_label($summary['emails'], '人'), '全体アンケートの案内先');
render_stat('交換コード', count_label($summary['claims']), '交換済み ' . $summary['claimed'] . '件');
echo '</div>';

echo '<h2>時間帯別の回答数（全社）</h2>';
echo '<div class="card">' . svg_line_chart(event_responses_by_hour($eventId)) . '</div>';

// 周回の傾向はさわりだけ出し、詳しくは専用ページへ誘導する
$laps    = visitor_laps($eventId);
$lapTime = lap_time_stats($laps);
echo '<h2>回答者の傾向</h2>';
echo '<div class="card">';
echo '<div class="stat-grid">';
render_stat('平均 周回時間', format_duration($lapTime['summary']['avg']),
    $lapTime['summary']['count'] . '人（2社以上回った方）');
render_stat('周回時間の中央値', format_duration($lapTime['summary']['median']));
render_stat('最長 周回時間', format_duration($lapTime['summary']['max']));
echo '</div>';
echo '<p class="muted">周回企業数の分布、まわる順番、よくある動線、回答率などは「回答者傾向」で見られます。</p>';
echo '<div class="btn-row"><a class="btn" href="insights.php?event=' . $eventId . '">回答者傾向を見る</a></div>';
echo '</div>';

echo '<h2>企業別の回答数</h2>';
echo '<div class="card"><div class="table-scroll"><table>';
echo '<thead><tr><th>企業</th><th class="num">回答数</th><th class="num">回答者数</th><th class="num">重複</th><th>操作</th></tr></thead><tbody>';
foreach ($ranking as $row) {
    echo '<tr>';
    echo '<td>' . e($row['name']) . ($row['is_active'] === 0 ? ' <span class="badge badge-optional">停止中</span>' : '') . '</td>';
    echo '<td class="num">' . $row['responses'] . '</td>';
    echo '<td class="num">' . $row['visitors'] . '</td>';
    echo '<td class="num muted">' . $row['duplicates'] . '</td>';
    echo '<td class="nowrap"><a href="company_stats.php?company=' . $row['id'] . '">集計</a>';
    echo ' ／ <a href="responses.php?company=' . $row['id'] . '">回答一覧</a></td>';
    echo '</tr>';
}
if ($ranking === []) {
    echo '<tr><td colspan="5" class="muted">企業が登録されていません。</td></tr>';
}
echo '</tbody></table></div>';
echo '<div class="btn-row">';
echo '<a class="btn" href="companies.php?event=' . $eventId . '">企業・QRコードの管理</a>';
echo '<a class="btn" href="export_csv.php?event=' . $eventId . '">全企業分のCSV</a>';
echo '</div></div>';

echo '<h2>全体アンケート・壁紙</h2>';
echo '<div class="card">';
echo '<p>全体アンケート：';
if ($overall === null) {
    echo '<span class="badge badge-optional">未作成</span>';
} else {
    echo e((string) $overall['title']) . ' <span class="badge badge-'
        . ((int) $overall['is_published'] === 1 ? 'good' : 'optional') . '">'
        . ((int) $overall['is_published'] === 1 ? '公開中' : '非公開') . '</span>';
    echo '（回答 ' . count_label($summary['overall_responses']) . '）';
}
echo '</p>';
echo '<p>案内メール：送信済み ' . $invites['sent'] . '件／未送信 ' . $invites['pending']
    . '件／失敗 ' . $invites['failed'] . '件／回答 ' . $invites['responded'] . '件</p>';
echo '<p>共通設問テンプレート：' . ($template === null ? '未作成' : e((string) $template['title'])) . '</p>';
echo '<div class="btn-row">';
echo '<a class="btn" href="invites.php?event=' . $eventId . '">全体アンケートと案内メール</a>';
echo '<a class="btn" href="wallpapers.php?event=' . $eventId . '">壁紙の登録</a>';
echo '</div></div>';

echo '<h2>イベント設定</h2>';
echo '<div class="card"><form method="post">' . csrf_field();
echo '<input type="hidden" name="action" value="update_event">';
echo '<input type="hidden" name="event_id" value="' . $eventId . '">';
echo '<label class="field" for="ev-name">イベント名</label>';
echo '<input type="text" id="ev-name" name="name" value="' . e((string) $event['name']) . '" required>';
echo '<label class="field" for="ev-start">開催日（開始）</label>';
echo '<input type="date" id="ev-start" name="start_date" value="' . e((string) ($event['start_date'] ?? '')) . '">';
echo '<label class="field" for="ev-end">開催日（終了）</label>';
echo '<input type="date" id="ev-end" name="end_date" value="' . e((string) ($event['end_date'] ?? '')) . '">';
echo '<label class="field" for="ev-status">状態<span class="hint">';
echo '「開催中」の間だけブースのアンケートに回答できます。終了にすると回答を締め切り、全体アンケートの案内を送れます。';
echo '</span></label>';
echo '<select id="ev-status" name="status">';
foreach (['draft', 'open', 'closed'] as $status) {
    $selected = (string) $event['status'] === $status ? ' selected' : '';
    echo '<option value="' . $status . '"' . $selected . '>' . e(event_status_label($status)) . '</option>';
}
echo '</select>';
echo '<div class="btn-row"><button type="submit" class="btn btn-primary">保存する</button></div>';
echo '</form></div>';

echo '<div class="card"><h2 style="margin-top:0">新しいイベントを作成する</h2>';
echo '<form method="post">' . csrf_field();
echo '<input type="hidden" name="action" value="create_event">';
echo '<label class="field" for="new-name">イベント名</label>';
echo '<input type="text" id="new-name" name="name" required>';
echo '<div class="btn-row"><button type="submit" class="btn">作成する</button></div>';
echo '</form></div>';

page_footer();
