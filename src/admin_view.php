<?php
declare(strict_types=1);

require_once __DIR__ . '/view.php';
require_once __DIR__ . '/auth.php';

/**
 * 管理画面の共通部分。
 */

/** ロールの日本語表記 */
function role_label(string $role): string
{
    return match ($role) {
        'organizer' => '主催者',
        'company'   => '企業担当者',
        'reception' => '総合受付',
        default     => $role,
    };
}

/** イベントの状態の日本語表記 */
function event_status_label(string $status): string
{
    return match ($status) {
        'draft'  => '準備中',
        'open'   => '開催中（回答受付）',
        'closed' => '終了（全体アンケート送付）',
        default  => $status,
    };
}

/**
 * 管理画面のヘッダー。ロールに応じてナビゲーションを出し分ける。
 */
function admin_page_header(array $user, string $title, string $current = ''): void
{
    $role = (string) $user['role'];

    $nav = [];
    if ($role === 'organizer') {
        $nav = [
            ['href' => 'index.php',      'label' => 'ダッシュボード'],
            ['href' => 'companies.php',  'label' => '企業・QR'],
            ['href' => 'insights.php',   'label' => '回答者傾向'],
            ['href' => 'wallpapers.php', 'label' => '壁紙'],
            ['href' => 'invites.php',    'label' => '全体アンケート'],
            ['href' => 'prizes.php',     'label' => '景品'],
            ['href' => 'claim.php',      'label' => '景品照会'],
            ['href' => 'users.php',      'label' => 'ユーザー'],
        ];
    } elseif ($role === 'company') {
        $nav = [
            ['href' => 'index.php', 'label' => '自社ダッシュボード'],
        ];
    } else {
        $nav = [
            ['href' => 'claim.php', 'label' => '景品照会'],
        ];
    }

    foreach ($nav as $i => $item) {
        $nav[$i]['current'] = basename($item['href']) === $current;
    }
    $nav[] = ['href' => 'logout.php', 'label' => 'ログアウト（' . (string) $user['display_name'] . '）'];

    page_header($title . '｜' . admin_title(), [
        'brand' => admin_title(),
        'nav'   => $nav,
        'wide'  => true,
    ]);
}


/** 数値タイル */
function render_stat(string $label, string $value, ?string $note = null): void
{
    echo '<div class="stat"><div class="stat-label">' . e($label) . '</div>';
    echo '<div class="stat-value">' . e($value) . '</div>';
    if ($note !== null) {
        echo '<div class="stat-note">' . e($note) . '</div>';
    }
    echo '</div>';
}

/**
 * 設問ごとの集計を描く（グラフ＋自由記述の一覧）。
 *
 * @param list<array<string,mixed>> $questions
 */
function render_survey_stats(int $surveyId, array $questions, bool $includeDuplicates = false): void
{
    $total = count_responses($surveyId, $includeDuplicates);

    foreach ($questions as $question) {
        $type = QuestionType::tryFromString((string) $question['type']);
        echo '<div class="card">';
        echo '<h3 style="margin-top:0">' . e((string) $question['label']) . '</h3>';

        if ($type === QuestionType::Text) {
            $texts = text_answers($surveyId, (int) $question['id'], $includeDuplicates);
            echo '<p class="muted">自由記述（新しい順に最大200件）：' . count($texts) . '件</p>';
            if ($texts === []) {
                echo '<p class="muted">まだ回答がありません。</p>';
            } else {
                echo '<div class="table-scroll"><table><thead><tr><th>回答</th><th class="nowrap">日時</th></tr></thead><tbody>';
                foreach ($texts as $row) {
                    echo '<tr><td>' . nl2br(e((string) $row['value'])) . '</td>';
                    echo '<td class="nowrap muted">' . e(format_datetime_ja((string) $row['submitted_at'])) . '</td></tr>';
                }
                echo '</tbody></table></div>';
            }
            echo '</div>';
            continue;
        }

        $result = answer_counts($surveyId, $question, $includeDuplicates);
        $counts = $result['counts'];

        if ($type === QuestionType::Nps) {
            $nps = nps_breakdown($counts);
            echo '<div class="stat-grid">';
            render_stat('NPS', (string) $nps['score'], '推奨者' . $nps['promoters'] . '／中立' . $nps['passives'] . '／批判者' . $nps['detractors']);
            render_stat('回答数', count_label($nps['total']));
            echo '</div>';
        } elseif ($type === QuestionType::Rating) {
            echo '<div class="stat-grid">';
            render_stat('平均評価', (string) rating_average($counts) . ' / ' . RATING_MAX);
            render_stat('回答数', count_label($result['answered']));
            echo '</div>';
        } else {
            echo '<p class="muted">回答数：' . count_label($result['answered'])
                . ($type === QuestionType::Multi ? '（複数選択可）' : '') . '</p>';
        }

        echo svg_bar_chart($counts, $type === QuestionType::Multi ? $result['answered'] : max($result['answered'], 0));
        echo '</div>';
    }

    if ($questions === []) {
        echo '<div class="card"><p class="muted">設問が登録されていません。</p></div>';
    }
    unset($total);
}
