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

// --- 読み取りだけで完結させる（USBバーコードリーダー運用） --------------------
// リーダーは「QRの中身＋Enter」をキーボードとして打ち込む。読み取り欄に入ると
// この POST が飛ぶので、交換済みの記録までこの1回で済ませる。
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && (post_string('action') ?? '') === 'scan') {
    require_valid_csrf();
    $prizeId = (int) (post_string('prize_id') ?? '0');
    $auto    = (post_string('auto') ?? '') === '1';

    $target = $code !== '' ? find_claim_by_code($code) : null;
    if ($target === null) {
        flash_set('error', '交換コードが見つかりません（読み取り：' . mb_substr($code, 0, 20) . '）。もう一度読み取ってください。');
        redirect('claim.php');
    }

    $eventPrizes = prizes_for_event((int) $target['event_id']);
    $prize       = $prizeId > 0 ? find_prize($prizeId) : null;

    if (($target['claimed_at'] ?? null) !== null) {
        // すでに渡している。記録は変えず、受付に知らせるだけ
        flash_set('warn', 'このコードはすでに交換済みです（'
            . format_datetime_ja((string) $target['claimed_at']) . '）。内容を下でご確認ください。');
    } elseif (!$auto) {
        flash_set('info', '未交換です。内容を確認して「交換済みにする」を押してください。');
    } elseif ($eventPrizes !== [] && ($prize === null || (int) $prize['event_id'] !== (int) $target['event_id'])) {
        flash_set('error', '渡す景品が選ばれていません。上の「渡す景品」を選んでから読み取ってください。');
    } elseif (mark_claimed((int) $target['id'], (string) $user['display_name'], null, $prizeId > 0 ? $prizeId : null)) {
        $remaining = null;
        foreach (prize_stock((int) $target['event_id']) as $row) {
            if ($row['id'] === $prizeId) {
                $remaining = $row['remaining'];
            }
        }
        flash_set('success', '交換済みにしました。'
            . ($prize === null ? '' : '【' . (string) $prize['name'] . '】')
            . ($remaining === null ? '' : ' 残り' . $remaining . '個')
            . '　そのまま次の方を読み取れます。');
        // 直前の記録を戻せるようにコードを渡す
        redirect('claim.php?undo=' . rawurlencode((string) $target['claim_code']));
    } else {
        flash_set('warn', 'このコードはすでに交換済みとして記録されています。');
    }

    redirect('claim.php?code=' . rawurlencode($code));
}

// 直前の記録の取り消し（読み取り間違いを戻す）
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && (post_string('action') ?? '') === 'undo') {
    require_valid_csrf();
    $target = $code !== '' ? find_claim_by_code($code) : null;

    if ($target !== null && unmark_claimed((int) $target['id'])) {
        flash_set('success', '直前の記録を取り消しました（' . e((string) $target['claim_code']) . '）。もう一度読み取れます。');
    } else {
        flash_set('error', '取り消せませんでした。画面を開き直してご確認ください。');
    }

    redirect('claim.php');
}

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

// 読み取り欄に出す景品の選択肢。照会中のコードがあればそのイベント、無ければ最新のイベント
$scanEvent  = $claim !== null ? find_event((int) $claim['event_id']) : (all_events()[0] ?? null);
$scanPrizes = $scanEvent === null ? [] : prize_stock((int) $scanEvent['id'], false);
$undoCode   = normalize_claim_code((string) (get_string('undo') ?? ''));

// 直前に記録したぶんを戻せるようにする（読み取り間違い用）
if ($undoCode !== '') {
    echo '<form method="post" class="card" onsubmit="return confirm(\'直前の記録を取り消します。よろしいですか？\');">';
    echo csrf_field();
    echo '<input type="hidden" name="action" value="undo">';
    echo '<input type="hidden" name="code" value="' . e($undoCode) . '">';
    echo '<p class="muted" style="margin:0 0 8px">読み取り間違いだった場合は、ここで戻せます（' . e($undoCode) . '）。</p>';
    echo '<div class="btn-row"><button type="submit" class="btn btn-small btn-danger">直前の記録を取り消す</button></div>';
    echo '</form>';
}

// --- 読み取り欄（バーコードリーダーはここに打ち込む） ------------------------
echo '<form method="post" class="card" id="scan-form">';
echo csrf_field();
echo '<input type="hidden" name="action" value="scan">';
echo '<label class="field" for="code">交換コードを読み取る';
echo '<span class="hint">この画面を開いたまま、バーコードリーダーで来場者のQRコードを読み取ってください'
    . '（URLのままでも照会できます）。手入力もできます（例：ABCD-2345）。</span></label>';
echo '<input type="text" id="code" name="code" class="scan-input" value="" autocomplete="off" '
    . 'autocapitalize="characters" spellcheck="false" autofocus required placeholder="読み取り待ち">';

if ($scanPrizes !== []) {
    // 景品が1種類ならそれを選んでおく（受付は読み取るだけで済む）
    $onlyPrize = count($scanPrizes) === 1;
    echo '<label class="field" for="prize_id">渡す景品';
    if ($onlyPrize) {
        echo '<span class="hint">景品が1種類なので、はじめから選ばれています。</span>';
    } else {
        echo '<span class="hint">読み取る前に選んでください。選んだ内容はこの端末で記憶します。</span>';
    }
    echo '</label>';
    echo '<select id="prize_id" name="prize_id" data-remember="enque-prize">';
    if (!$onlyPrize) {
        echo '<option value="">選んでください</option>';
    }
    foreach ($scanPrizes as $row) {
        $label = (string) $row['name']
            . ($row['remaining'] === null ? '（残数の管理なし）' : '（残り' . $row['remaining'] . '個）')
            . ($row['remaining'] !== null && $row['remaining'] === 0 ? '※在庫なし' : '');
        echo '<option value="' . (int) $row['id'] . '"' . ($onlyPrize ? ' selected' : '') . '>' . e($label) . '</option>';
    }
    echo '</select>';
}

echo '<label class="choice"><input type="checkbox" name="auto" value="1" id="scan-auto" '
    . 'data-remember="enque-auto" checked><span>読み取ったら<strong>そのまま交換済みにする</strong>'
    . '<span class="hint">外すと、内容を確認してからボタンで記録します。</span></span></label>';
echo '<div class="btn-row"><button type="submit" class="btn btn-block">照会する</button></div>';
echo '</form>';

// スマホのカメラでの読み取り（使える端末でのみボタンを出す。判定は scan.js が行う）
echo '<div class="card">';
echo '<p class="muted" style="margin:0 0 8px">スマートフォンで対応する場合はこちら。</p>';
echo '<div class="btn-row"><button type="button" class="btn btn-block" id="scan-open" hidden>';
echo 'カメラでQRコードを読み取る</button></div>';
echo '<p class="muted" id="scan-unsupported" hidden></p>';
echo '<div id="scan-panel" hidden>';
echo '<div class="scan-view"><video id="scan-video" playsinline muted></video><span class="scan-frame"></span></div>';
echo '<canvas id="scan-canvas" hidden></canvas>';
echo '<p class="muted" id="scan-status">カメラを起動しています…</p>';
echo '<div class="btn-row"><button type="button" class="btn btn-block" id="scan-close">閉じる</button></div>';
echo '</div>';
echo '</div>';

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
        $onlyOne = count($claimStock) === 1;
        echo '<select id="prize_id" name="prize_id"' . ($claimed ? '' : ' required') . '>';
        if (!$onlyOne) {
            echo '<option value="">選んでください</option>';
        }
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
            echo '<option value="' . $row['id'] . '"' . ($onlyOne ? ' selected' : '') . '>' . e($label) . '</option>';
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

    echo '<div class="btn-row"><a class="btn btn-small" href="claim.php">次の来場者を照会する</a></div>';
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

// jsQR は Barcode Detection API が無い端末（iOS Safari など）のための読み取り処理
page_footer(['/assets/vendor/jsqr.min.js', '/assets/scan.js', '/assets/reception.js']);
