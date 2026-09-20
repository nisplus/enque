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
    $prizeId = (int) (post_string('prize_id') ?? '0');

    $target = $code !== '' ? find_claim_by_code($code) : null;
    $prize  = $prizeId > 0 ? find_prize($prizeId) : null;

    if ($target === null || (int) $target['id'] !== $claimId) {
        flash_set('error', '交換コードが見つかりませんでした。');
    } elseif ($prizeId > 0 && ($prize === null || (int) $prize['event_id'] !== (int) $target['event_id'])) {
        flash_set('error', '選択された景品が見つかりませんでした。');
    } elseif ($prizeId === 0 && prizes_for_event((int) $target['event_id']) !== []) {
        // 景品が登録されている場合は、どれを渡したかを必ず残す
        flash_set('error', '渡した景品を選んでください。');
    } elseif (mark_claimed(
        $claimId,
        (string) $user['display_name'],
        $note !== '' ? mb_substr($note, 0, 255) : null,
        $prizeId > 0 ? $prizeId : null
    )) {
        $stock     = $prize === null ? null : prize_stock((int) $target['event_id']);
        $remaining = null;
        foreach ($stock ?? [] as $row) {
            if ($row['id'] === $prizeId) {
                $remaining = $row['remaining'];
            }
        }
        flash_set(
            'success',
            '交換済みとして記録しました。'
            . ($prize === null ? '' : '（' . (string) $prize['name'] . '）')
            . ($remaining === null ? '' : ' 残数 ' . $remaining . '個')
        );
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

    // 渡した景品を選ぶ（登録されている景品のみ。残数も選択肢に出す）
    $claimStock = prize_stock((int) $claim['event_id'], false);

    if ($claimed && ($claim['prize_id'] ?? null) !== null) {
        $claimedPrize = find_prize((int) $claim['prize_id']);
        if ($claimedPrize !== null) {
            echo '<p class="muted">渡した景品：' . e((string) $claimedPrize['name']) . '</p>';
        }
    }

    echo '<form method="post" onsubmit="return confirm(\'このコードを交換済みとして記録します。よろしいですか？\');">';
    echo csrf_field();
    echo '<input type="hidden" name="action" value="mark">';
    echo '<input type="hidden" name="code" value="' . e((string) $claim['claim_code']) . '">';
    echo '<input type="hidden" name="claim_id" value="' . (int) $claim['id'] . '">';

    if ($claimStock === []) {
        echo '<div class="alert alert-info">景品が登録されていません。';
        echo '<a href="prizes.php">景品を登録</a>すると、ここで選べるようになり残数が分かります。</div>';
    } else {
        echo '<label class="field" for="prize_id">渡す景品</label>';
        echo '<select id="prize_id" name="prize_id"' . ($claimed ? '' : ' required') . '>';
        echo '<option value="">選んでください</option>';
        foreach ($claimStock as $row) {
            $soldOut = $row['remaining'] !== null && $row['remaining'] === 0;
            $label   = $row['name'];
            if ($row['remaining'] === null) {
                $label .= '（残数の管理なし）';
            } else {
                $label .= '（残り' . $row['remaining'] . '個）';
            }
            if ($soldOut) {
                $label .= '※在庫なし';
            }
            echo '<option value="' . $row['id'] . '">' . e($label) . '</option>';
        }
        echo '</select>';
        echo '<p class="muted">在庫が無い景品も選べます（実際に渡したものを記録できるようにするため）。'
            . '記録が用意した数を超えた場合は、景品一覧に超過数を表示します。</p>';
    }

    echo '<label class="field" for="note">メモ（任意）<span class="hint">交換時の申し送りなど</span></label>';
    echo '<input type="text" id="note" name="note" maxlength="255">';
    echo '<div class="btn-row"><button type="submit" class="btn btn-primary"'
        . ($claimed ? ' disabled aria-disabled="true"' : '') . '>交換済みにする</button></div>';
    echo '</form>';
    echo '</div>';
}

// 景品の残数と直近の交換履歴（照会したコードのイベントを優先する）
$events = all_events();
$event  = $claim !== null ? find_event((int) $claim['event_id']) : ($events[0] ?? null);
if ($event !== null) {
    $history = claim_history((int) $event['id'], 30);
    $summary = event_summary((int) $event['id']);
    $stock   = prize_stock((int) $event['id']);

    echo '<h2>交換の状況（' . e((string) $event['name']) . '）</h2>';
    echo '<div class="stat-grid">';
    render_stat('交換済み', count_label($summary['claimed']));
    render_stat('コード発行数', count_label($summary['claims']));
    echo '</div>';

    echo '<div class="card"><h3 style="margin-top:0">景品の残数</h3>';
    if ($stock === []) {
        echo '<p class="muted">景品が登録されていません。';
        if ((string) $user['role'] === 'organizer') {
            echo '<a href="prizes.php">景品の登録</a>から追加できます。';
        } else {
            echo '主催者にご確認ください。';
        }
        echo '</p>';
    } else {
        echo '<div class="table-scroll"><table>';
        echo '<thead><tr><th>景品</th><th class="num">用意</th><th class="num">交換済み</th><th class="num">残り</th></tr></thead><tbody>';
        foreach ($stock as $row) {
            $soldOut = $row['remaining'] !== null && $row['remaining'] === 0;
            echo '<tr>';
            echo '<td>' . e($row['name']);
            if ($row['is_active'] === 0) {
                echo ' <span class="badge badge-optional">停止中</span>';
            } elseif ($soldOut) {
                echo ' <span class="badge badge-warn">在庫なし</span>';
            }
            if ($row['note'] !== null && $row['note'] !== '') {
                echo '<br><span class="muted">' . e($row['note']) . '</span>';
            }
            echo '</td>';
            echo '<td class="num">' . ($row['total_qty'] === null ? '—' : (int) $row['total_qty']) . '</td>';
            echo '<td class="num">' . $row['claimed'] . '</td>';
            echo '<td class="num">' . ($row['remaining'] === null ? '—' : $row['remaining']);
            if ($row['over'] > 0) {
                echo '<br><span class="muted">超過' . $row['over'] . '</span>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table></div>';

        $noPrize = claims_without_prize((int) $event['id']);
        if ($noPrize > 0) {
            echo '<p class="muted">景品を選ばずに記録された交換が' . $noPrize . '件あります（残数の計算には含まれません）。</p>';
        }
    }
    echo '</div>';

    echo '<div class="card"><h3 style="margin-top:0">直近の交換履歴</h3>';
    echo '<div class="table-scroll"><table><thead><tr><th>コード</th><th class="nowrap">交換日時</th><th>景品</th><th>対応</th><th>メモ</th></tr></thead><tbody>';
    foreach ($history as $row) {
        echo '<tr><td class="mono">' . e((string) $row['claim_code']) . '</td>';
        echo '<td class="nowrap">' . e(format_datetime_ja((string) $row['claimed_at'])) . '</td>';
        echo '<td>' . e((string) ($row['prize_name'] ?? '')) . '</td>';
        echo '<td>' . e((string) ($row['claimed_by'] ?? '')) . '</td>';
        echo '<td>' . e((string) ($row['note'] ?? '')) . '</td></tr>';
    }
    if ($history === []) {
        echo '<tr><td colspan="5" class="muted">まだ交換の記録がありません。</td></tr>';
    }
    echo '</tbody></table></div></div>';
}

page_footer();
