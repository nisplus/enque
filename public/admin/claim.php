<?php
declare(strict_types=1);

/**
 * 景品交換の照会（総合受付・主催者）。
 *
 * 来場者の画面には「交換済みなので渡せません」とは出さない。この画面にだけ
 * 交換済みの日時を表示し、渡すかどうかはスタッフの判断に委ねる。
 */

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';
require_once dirname(__DIR__, 2) . '/src/admin_view.php';

$user = require_reception();

$code   = normalize_claim_code((string) (get_string('code') ?? (post_string('code') ?? '')));
$claim  = null;
$notice = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && (post_string('action') ?? '') === 'mark') {
    require_valid_csrf();
    $claimId = (int) (post_string('claim_id') ?? '0');
    $note    = trim_ja(strip_control_chars((string) (post_string('note') ?? '')));

    $target = $code !== '' ? find_claim_by_code($code) : null;
    if ($target === null || (int) $target['id'] !== $claimId) {
        flash_set('error', '交換コードが見つかりませんでした。');
    } elseif (mark_claimed($claimId, (string) $user['display_name'], $note !== '' ? mb_substr($note, 0, 255) : null)) {
        flash_set('success', '交換済みとして記録しました。');
    } else {
        flash_set('warn', 'このコードはすでに交換済みとして記録されています。');
    }
    redirect('claim.php?code=' . rawurlencode($code));
}

if ($code !== '') {
    $claim = find_claim_by_code($code);
}

admin_page_header($user, '景品交換の照会', 'claim.php');
render_alert(flash_take());

echo '<h1>景品交換の照会</h1>';
echo '<p class="muted">来場者の画面に表示されている交換コード（例：ABCD-2345）を入力してください。</p>';

echo '<form method="get" class="card">';
echo '<label class="field" for="code">交換コード</label>';
echo '<input type="text" id="code" name="code" value="' . e($code) . '" autocomplete="off" '
    . 'autocapitalize="characters" spellcheck="false" autofocus required>';
echo '<div class="btn-row"><button type="submit" class="btn btn-primary btn-block">照会する</button></div>';
echo '</form>';

if ($code !== '' && $claim === null) {
    echo '<div class="alert alert-error">該当する交換コードが見つかりません。入力内容をご確認ください。</div>';
}

if ($claim !== null) {
    $visitorId = (int) $claim['visitor_id'];
    $visited   = visited_company_count($visitorId);
    $claimed   = ($claim['claimed_at'] ?? null) !== null;

    echo '<div class="card">';
    echo '<p class="claim-code">' . e((string) $claim['claim_code']) . '</p>';

    if ($claimed) {
        echo '<div class="alert alert-warn">交換済み（' . e(format_datetime_ja((string) $claim['claimed_at'])) . '）';
        if (($claim['claimed_by'] ?? null) !== null) {
            echo '　対応：' . e((string) $claim['claimed_by']);
        }
        echo '</div>';
        if (($claim['note'] ?? null) !== null && (string) $claim['note'] !== '') {
            echo '<p class="muted">メモ：' . e((string) $claim['note']) . '</p>';
        }
    } else {
        echo '<div class="alert alert-success">未交換です。景品をお渡しできます。</div>';
    }

    echo '<div class="stat-grid">';
    render_stat('回答したブース', count_label($visited, '社'));
    render_stat('コード発行', e(format_datetime_ja((string) $claim['created_at'])));
    echo '</div>';

    echo '<form method="post" onsubmit="return confirm(\'このコードを交換済みとして記録します。よろしいですか？\');">';
    echo csrf_field();
    echo '<input type="hidden" name="action" value="mark">';
    echo '<input type="hidden" name="code" value="' . e((string) $claim['claim_code']) . '">';
    echo '<input type="hidden" name="claim_id" value="' . (int) $claim['id'] . '">';
    echo '<label class="field" for="note">メモ（任意）<span class="hint">渡した景品の種類など</span></label>';
    echo '<input type="text" id="note" name="note" maxlength="255">';
    echo '<div class="btn-row"><button type="submit" class="btn btn-primary"'
        . ($claimed ? ' disabled aria-disabled="true"' : '') . '>交換済みにする</button></div>';
    echo '</form>';
    echo '</div>';
}

// 直近の交換履歴（在庫の把握用）
$events = all_events();
$event  = $events[0] ?? null;
if ($event !== null) {
    $history = claim_history((int) $event['id'], 30);
    $summary = event_summary((int) $event['id']);

    echo '<h2>交換の状況（' . e((string) $event['name']) . '）</h2>';
    echo '<div class="stat-grid">';
    render_stat('交換済み', count_label($summary['claimed']));
    render_stat('コード発行数', count_label($summary['claims']));
    echo '</div>';

    echo '<div class="card"><h3 style="margin-top:0">直近の交換履歴</h3>';
    echo '<div class="table-scroll"><table><thead><tr><th>コード</th><th class="nowrap">交換日時</th><th>対応</th><th>メモ</th></tr></thead><tbody>';
    foreach ($history as $row) {
        echo '<tr><td class="mono">' . e((string) $row['claim_code']) . '</td>';
        echo '<td class="nowrap">' . e(format_datetime_ja((string) $row['claimed_at'])) . '</td>';
        echo '<td>' . e((string) ($row['claimed_by'] ?? '')) . '</td>';
        echo '<td>' . e((string) ($row['note'] ?? '')) . '</td></tr>';
    }
    if ($history === []) {
        echo '<tr><td colspan="4" class="muted">まだ交換の記録がありません。</td></tr>';
    }
    echo '</tbody></table></div></div>';
}

page_footer();
