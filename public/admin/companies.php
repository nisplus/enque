<?php
declare(strict_types=1);

/** 出展企業とQRコードの管理（主催者） */

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

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_valid_csrf();
    $action = (string) (post_string('action') ?? '');

    if ($action === 'create') {
        $name  = trim_ja((string) (post_string('name') ?? ''));
        $booth = trim_ja((string) (post_string('booth_no') ?? ''));
        $color = trim_ja((string) (post_string('color') ?? ''));
        $logo  = trim_ja((string) (post_string('logo_url') ?? ''));

        if ($name === '') {
            flash_set('error', '企業名を入力してください。');
        } else {
            $companyId = create_company(
                $eventId,
                mb_substr($name, 0, 255),
                $booth !== '' ? mb_substr($booth, 0, 32) : null,
                preg_match('/\A#[0-9a-fA-F]{6}\z/', $color) === 1 ? $color : null,
                $logo !== '' ? mb_substr($logo, 0, 500) : null
            );
            // 企業を作ったらアンケートの器も用意する（設問は後から登録）
            $company = find_company($companyId);
            if ($company !== null) {
                ensure_company_survey($company);
            }
            flash_set('success', '企業を登録しました。アンケートの設問を登録してください。');
        }
    } elseif ($action === 'update') {
        $companyId = (int) (post_string('company_id') ?? '0');
        $company   = find_company($companyId);
        if ($company === null || (int) $company['event_id'] !== $eventId) {
            abort(404, 'ページが見つかりません。');
        }
        $name  = trim_ja((string) (post_string('name') ?? ''));
        $booth = trim_ja((string) (post_string('booth_no') ?? ''));
        $color = trim_ja((string) (post_string('color') ?? ''));
        $logo  = trim_ja((string) (post_string('logo_url') ?? ''));
        $order = (int) (post_string('sort_order') ?? '0');

        if ($name === '') {
            flash_set('error', '企業名を入力してください。');
        } else {
            update_company(
                $companyId,
                mb_substr($name, 0, 255),
                $booth !== '' ? mb_substr($booth, 0, 32) : null,
                preg_match('/\A#[0-9a-fA-F]{6}\z/', $color) === 1 ? $color : null,
                $logo !== '' ? mb_substr($logo, 0, 500) : null,
                $order
            );
            flash_set('success', '企業情報を保存しました。');
        }
    } elseif ($action === 'toggle') {
        $companyId = (int) (post_string('company_id') ?? '0');
        $company   = find_company($companyId);
        if ($company === null || (int) $company['event_id'] !== $eventId) {
            abort(404, 'ページが見つかりません。');
        }
        $active = (int) $company['is_active'] !== 1;
        set_company_active($companyId, $active);
        flash_set('success', $active ? '企業を再開しました。' : '企業を停止しました（回答データは残ります）。');
    } elseif ($action === 'reslug') {
        $companyId = (int) (post_string('company_id') ?? '0');
        $company   = find_company($companyId);
        if ($company === null || (int) $company['event_id'] !== $eventId) {
            abort(404, 'ページが見つかりません。');
        }
        regenerate_company_slug($companyId);
        flash_set('warn', 'QRコードのURLを再発行しました。印刷済みのQRコードは使えなくなるため、貼り替えてください。');
    }

    redirect('companies.php?event=' . $eventId);
}

$companies = companies_for_event($eventId, true);

admin_page_header($user, '企業・QR', 'companies.php');
render_alert(flash_take());

echo '<h1>企業・QRコードの管理</h1>';
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

echo '<div class="btn-row">';
echo '<a class="btn" href="qr_print.php?event=' . $eventId . '">QRコードを印刷する（全社）</a>';
echo '<a class="btn" href="index.php?event=' . $eventId . '">ダッシュボードへ</a>';
echo '</div>';

echo '<div class="card"><h2 style="margin-top:0">企業を追加する</h2>';
echo '<form method="post">' . csrf_field();
echo '<input type="hidden" name="action" value="create">';
echo '<input type="hidden" name="event_id" value="' . $eventId . '">';
echo '<label class="field" for="name">企業名</label>';
echo '<input type="text" id="name" name="name" required>';
echo '<label class="field" for="booth_no">ブース番号（任意）</label>';
echo '<input type="text" id="booth_no" name="booth_no">';
echo '<label class="field" for="color">企業カラー（任意）<span class="hint">#RRGGBB 形式。回答画面の見出しに使います。</span></label>';
echo '<input type="text" id="color" name="color" placeholder="#2a78d6">';
echo '<label class="field" for="logo_url">ロゴ画像URL（任意）</label>';
echo '<input type="text" id="logo_url" name="logo_url" placeholder="https://...">';
echo '<div class="btn-row"><button type="submit" class="btn btn-primary">登録する</button></div>';
echo '</form></div>';

echo '<h2>登録済みの企業（' . count($companies) . '社）</h2>';

foreach ($companies as $company) {
    $companyId = (int) $company['id'];
    $survey    = survey_for_company($companyId);
    $url       = survey_url((string) $event['slug'], (string) $company['qr_slug']);
    $responses = $survey === null ? 0 : count_responses((int) $survey['id']);

    echo '<div class="card">';
    echo '<div class="card-head"><h3 style="margin:0">' . e((string) $company['name']);
    if ((int) $company['is_active'] !== 1) {
        echo ' <span class="badge badge-optional">停止中</span>';
    }
    if ($survey !== null && (int) $survey['is_published'] === 1) {
        echo ' <span class="badge badge-good">公開中</span>';
    } else {
        echo ' <span class="badge badge-warn">未公開</span>';
    }
    echo '</h3><span class="muted">回答 ' . count_label($responses) . '</span></div>';

    echo '<p class="mono muted" style="word-break:break-all">' . e($url) . '</p>';

    echo '<div class="btn-row">';
    if ($survey !== null) {
        echo '<a class="btn btn-small" href="survey_edit.php?survey=' . (int) $survey['id'] . '">アンケート編集</a>';
        echo '<a class="btn btn-small" href="company_stats.php?company=' . $companyId . '">集計</a>';
        echo '<a class="btn btn-small" href="responses.php?company=' . $companyId . '">回答一覧</a>';
    }
    echo '<a class="btn btn-small" href="qr_print.php?company=' . $companyId . '">QR</a>';
    echo '<a class="btn btn-small" href="qr.php?company=' . $companyId . '&format=svg" download>QR(SVG)</a>';
    echo '</div>';

    echo '<details><summary>編集する</summary>';
    echo '<form method="post">' . csrf_field();
    echo '<input type="hidden" name="action" value="update">';
    echo '<input type="hidden" name="event_id" value="' . $eventId . '">';
    echo '<input type="hidden" name="company_id" value="' . $companyId . '">';
    echo '<label class="field">企業名<input type="text" name="name" value="' . e((string) $company['name']) . '" required></label>';
    echo '<label class="field">ブース番号<input type="text" name="booth_no" value="' . e((string) ($company['booth_no'] ?? '')) . '"></label>';
    echo '<label class="field">企業カラー<input type="text" name="color" value="' . e((string) ($company['color'] ?? '')) . '" placeholder="#2a78d6"></label>';
    echo '<label class="field">ロゴ画像URL<input type="text" name="logo_url" value="' . e((string) ($company['logo_url'] ?? '')) . '"></label>';
    echo '<label class="field">並び順<input type="number" name="sort_order" value="' . (int) $company['sort_order'] . '"></label>';
    echo '<div class="btn-row"><button type="submit" class="btn btn-primary btn-small">保存</button></div>';
    echo '</form>';

    echo '<form method="post" class="inline-form" onsubmit="return confirm(\''
        . ((int) $company['is_active'] === 1 ? 'この企業のアンケートを停止します。よろしいですか？' : 'この企業を再開します。よろしいですか？')
        . '\');">' . csrf_field();
    echo '<input type="hidden" name="action" value="toggle">';
    echo '<input type="hidden" name="event_id" value="' . $eventId . '">';
    echo '<input type="hidden" name="company_id" value="' . $companyId . '">';
    echo '<button type="submit" class="btn btn-small' . ((int) $company['is_active'] === 1 ? ' btn-danger' : '') . '">'
        . ((int) $company['is_active'] === 1 ? '停止する' : '再開する') . '</button>';
    echo '</form> ';

    echo '<form method="post" class="inline-form" onsubmit="return confirm(\'QRコードのURLを作り直します。印刷済みのQRコードは使えなくなります。よろしいですか？\');">' . csrf_field();
    echo '<input type="hidden" name="action" value="reslug">';
    echo '<input type="hidden" name="event_id" value="' . $eventId . '">';
    echo '<input type="hidden" name="company_id" value="' . $companyId . '">';
    echo '<button type="submit" class="btn btn-small btn-danger">URLを再発行</button>';
    echo '</form>';
    echo '</details>';

    echo '</div>';
}

if ($companies === []) {
    echo '<div class="card"><p class="muted">まだ企業が登録されていません。</p></div>';
}

page_footer();
