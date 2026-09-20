<?php
declare(strict_types=1);

/**
 * 景品の登録（主催者）。
 *
 * ここで登録した景品を、総合受付が claim.php の交換時に選ぶ。
 * 数量を入れておくと残数が出る（数量を入れなければ数量管理しない景品として扱う）。
 */

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';
require_once dirname(__DIR__, 2) . '/src/admin_view.php';

$user   = require_organizer();
$events = all_events();

$eventId = (int) (get_string('event') ?? (post_string('event_id') ?? '0'));
$event   = $eventId > 0 ? find_event($eventId) : ($events[0] ?? null);
if ($event === null) {
    flash_set('error', '先にイベントを作成してください。');
    redirect('index.php');
}
$eventId = (int) $event['id'];

/** 入力された数量を読む（空欄なら数量管理しない＝null） */
function posted_quantity(): ?int
{
    $raw = trim_ja((string) (post_string('total_qty') ?? ''));
    if ($raw === '') {
        return null;
    }

    return max(0, (int) $raw);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_valid_csrf();
    $action = (string) (post_string('action') ?? '');

    if ($action === 'create') {
        $name = trim_ja((string) (post_string('name') ?? ''));
        $note = trim_ja(strip_control_chars((string) (post_string('note') ?? '')));

        if ($name === '') {
            flash_set('error', '景品名を入力してください。');
        } else {
            create_prize($eventId, mb_substr($name, 0, 255), posted_quantity(), $note !== '' ? mb_substr($note, 0, 255) : null);
            flash_set('success', '景品を登録しました。');
        }
    } elseif ($action === 'update') {
        $prizeId = (int) (post_string('prize_id') ?? '0');
        $prize   = find_prize($prizeId);
        if ($prize === null || (int) $prize['event_id'] !== $eventId) {
            abort(404, 'ページが見つかりません。');
        }
        $name  = trim_ja((string) (post_string('name') ?? ''));
        $note  = trim_ja(strip_control_chars((string) (post_string('note') ?? '')));
        $order = (int) (post_string('sort_order') ?? '0');

        if ($name === '') {
            flash_set('error', '景品名を入力してください。');
        } else {
            update_prize(
                $prizeId,
                mb_substr($name, 0, 255),
                posted_quantity(),
                $note !== '' ? mb_substr($note, 0, 255) : null,
                $order
            );
            flash_set('success', '景品を保存しました。');
        }
    } elseif ($action === 'toggle') {
        $prizeId = (int) (post_string('prize_id') ?? '0');
        $prize   = find_prize($prizeId);
        if ($prize === null || (int) $prize['event_id'] !== $eventId) {
            abort(404, 'ページが見つかりません。');
        }
        $active = (int) $prize['is_active'] !== 1;
        set_prize_active($prizeId, $active);
        flash_set('success', $active ? '景品の取り扱いを再開しました。' : '景品の取り扱いを止めました（交換記録は残ります）。');
    }

    redirect('prizes.php?event=' . $eventId);
}

$stock     = prize_stock($eventId);
$noPrize   = claims_without_prize($eventId);
$summary   = event_summary($eventId);

admin_page_header($user, '景品', 'prizes.php');
render_alert(flash_take());

echo '<h1>景品の登録</h1>';
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

echo '<div class="stat-grid">';
render_stat('交換済み', count_label($summary['claimed']), 'コード発行 ' . $summary['claims'] . '件');
$totalRemaining = 0;
$hasCounted     = false;
foreach ($stock as $row) {
    if ($row['remaining'] !== null) {
        $totalRemaining += $row['remaining'];
        $hasCounted = true;
    }
}
render_stat('残数の合計', $hasCounted ? count_label($totalRemaining, '個') : '—', '数量を登録した景品のみ');
echo '</div>';

echo '<div class="card"><h2 style="margin-top:0">景品を追加する</h2>';
echo '<form method="post">' . csrf_field();
echo '<input type="hidden" name="action" value="create">';
echo '<input type="hidden" name="event_id" value="' . $eventId . '">';
echo '<label class="field" for="name">景品名</label>';
echo '<input type="text" id="name" name="name" required placeholder="例：オリジナルトートバッグ">';
echo '<label class="field" for="total_qty">用意した数量<span class="hint">';
echo '空欄にすると数量を管理しません（数えないノベルティなど）。</span></label>';
echo '<input type="number" id="total_qty" name="total_qty" min="0" step="1" inputmode="numeric">';
echo '<label class="field" for="note">メモ（任意）<span class="hint">受付の画面にも表示します（例：先着100名）。</span></label>';
echo '<input type="text" id="note" name="note" maxlength="255">';
echo '<div class="btn-row"><button type="submit" class="btn btn-primary">登録する</button></div>';
echo '</form></div>';

echo '<h2>登録済みの景品（' . count($stock) . '件）</h2>';

if ($stock === []) {
    echo '<div class="card"><p class="muted">まだ登録されていません。';
    echo '景品を登録しなくても交換の記録はできますが、登録しておくと受付で選べるようになり、残数が分かります。</p></div>';
}

foreach ($stock as $row) {
    $prizeId = $row['id'];

    echo '<div class="card">';
    echo '<div class="card-head"><h3 style="margin:0">' . e($row['name']);
    if ($row['is_active'] === 0) {
        echo ' <span class="badge badge-optional">取り扱い停止</span>';
    } elseif ($row['remaining'] !== null && $row['remaining'] === 0) {
        echo ' <span class="badge badge-warn">在庫なし</span>';
    }
    echo '</h3></div>';

    echo '<div class="stat-grid">';
    render_stat('残数', $row['remaining'] === null ? '—' : count_label($row['remaining'], '個'),
        $row['total_qty'] === null ? '数量を管理しない' : '用意 ' . $row['total_qty'] . '個');
    render_stat('交換済み', count_label($row['claimed'], '個'),
        $row['over'] > 0 ? '用意した数を' . $row['over'] . '個超えています' : null);
    echo '</div>';

    if ($row['note'] !== null && $row['note'] !== '') {
        echo '<p class="muted">' . e($row['note']) . '</p>';
    }

    echo '<details><summary>編集する</summary>';
    echo '<form method="post">' . csrf_field();
    echo '<input type="hidden" name="action" value="update">';
    echo '<input type="hidden" name="event_id" value="' . $eventId . '">';
    echo '<input type="hidden" name="prize_id" value="' . $prizeId . '">';
    echo '<label class="field">景品名<input type="text" name="name" value="' . e($row['name']) . '" required></label>';
    echo '<label class="field">用意した数量<span class="hint">空欄にすると数量を管理しません。</span>';
    echo '<input type="number" name="total_qty" min="0" step="1" value="'
        . ($row['total_qty'] === null ? '' : (int) $row['total_qty']) . '"></label>';
    echo '<label class="field">メモ<input type="text" name="note" value="' . e((string) ($row['note'] ?? '')) . '" maxlength="255"></label>';
    echo '<label class="field">並び順<input type="number" name="sort_order" value="' . $row['sort_order'] . '"></label>';
    echo '<div class="btn-row"><button type="submit" class="btn btn-primary btn-small">保存</button></div>';
    echo '</form>';

    echo '<form method="post" class="inline-form" onsubmit="return confirm(\''
        . ($row['is_active'] === 1 ? 'この景品の取り扱いを止めます。受付の選択肢から外れます。よろしいですか？' : 'この景品の取り扱いを再開します。よろしいですか？')
        . '\');">' . csrf_field();
    echo '<input type="hidden" name="action" value="toggle">';
    echo '<input type="hidden" name="event_id" value="' . $eventId . '">';
    echo '<input type="hidden" name="prize_id" value="' . $prizeId . '">';
    echo '<button type="submit" class="btn btn-small' . ($row['is_active'] === 1 ? ' btn-danger' : '') . '">'
        . ($row['is_active'] === 1 ? '取り扱いを止める' : '再開する') . '</button>';
    echo '</form>';
    echo '</details>';

    echo '</div>';
}

if ($noPrize > 0) {
    echo '<div class="alert alert-info">景品を選ばずに記録された交換が' . $noPrize . '件あります';
    echo '（景品を登録する前に交換したぶんです）。残数の計算には含まれません。</div>';
}

echo '<div class="btn-row">';
echo '<a class="btn" href="claim.php">景品交換の照会へ</a>';
echo '<a class="btn" href="index.php?event=' . $eventId . '">ダッシュボードへ</a>';
echo '</div>';

page_footer();
