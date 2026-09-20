<?php
declare(strict_types=1);

/**
 * 管理ユーザーの管理（主催者のみ）。
 *
 * 企業担当者アカウントは企業に紐づけ、その企業のアンケートと集計だけを見られる。
 * 総合受付アカウントは景品交換の照会だけができる。
 */

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';
require_once dirname(__DIR__, 2) . '/src/admin_view.php';

$user = require_organizer();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_valid_csrf();
    $action = (string) (post_string('action') ?? '');
    $minLen = config()['min_password_length'];

    if ($action === 'create') {
        $username = trim_ja((string) (post_string('username') ?? ''));
        $display  = trim_ja((string) (post_string('display_name') ?? ''));
        $password = (string) (post_string('password') ?? '');
        $role     = (string) (post_string('role') ?? 'company');
        $companyId = (int) (post_string('company_id') ?? '0');

        if (!in_array($role, ['organizer', 'company', 'reception'], true)) {
            $role = 'company';
        }
        $company = $companyId > 0 ? find_company($companyId) : null;

        if (preg_match('/\A[A-Za-z0-9_.-]{3,64}\z/', $username) !== 1) {
            flash_set('error', 'ユーザー名は英数字・ドット・ハイフン・アンダースコアで3〜64文字にしてください。');
        } elseif ($display === '') {
            flash_set('error', '表示名を入力してください。');
        } elseif (mb_strlen($password) < $minLen) {
            flash_set('error', 'パスワードは' . $minLen . '文字以上にしてください。');
        } elseif ($role === 'company' && $company === null) {
            flash_set('error', '企業担当者アカウントには担当する企業を指定してください。');
        } elseif (find_admin_by_username($username) !== null) {
            flash_set('error', 'そのユーザー名はすでに使われています。');
        } else {
            create_admin_user($username, $password, mb_substr($display, 0, 100), $role, $role === 'company' ? $companyId : null);
            flash_set('success', 'アカウントを作成しました。ユーザー名と初期パスワードを本人にお伝えください。');
        }
    } elseif ($action === 'toggle') {
        $targetId = (int) (post_string('user_id') ?? '0');
        $target   = find_admin($targetId);
        if ($target === null) {
            abort(404, 'ページが見つかりません。');
        }
        if ($targetId === (int) $user['id']) {
            flash_set('error', '自分自身は無効化できません。');
        } else {
            set_admin_active($targetId, (int) $target['is_active'] !== 1);
            flash_set('success', 'アカウントの状態を変更しました。');
        }
    } elseif ($action === 'reset_password') {
        $targetId = (int) (post_string('user_id') ?? '0');
        $target   = find_admin($targetId);
        $password = (string) (post_string('password') ?? '');
        if ($target === null) {
            abort(404, 'ページが見つかりません。');
        }
        if (mb_strlen($password) < $minLen) {
            flash_set('error', 'パスワードは' . $minLen . '文字以上にしてください。');
        } else {
            update_admin_password($targetId, password_hash($password, PASSWORD_DEFAULT));
            flash_set('success', 'パスワードを変更しました。');
        }
    }

    redirect('users.php');
}

$users     = all_admin_users();
$events    = all_events();
$companies = [];
foreach ($events as $event) {
    foreach (companies_for_event((int) $event['id'], true) as $company) {
        $companies[] = ['id' => (int) $company['id'], 'label' => (string) $event['name'] . '／' . (string) $company['name']];
    }
}

admin_page_header($user, 'ユーザー', 'users.php');
render_alert(flash_take());

echo '<h1>管理ユーザー</h1>';

echo '<div class="card"><h2 style="margin-top:0">アカウントを追加する</h2>';
echo '<form method="post">' . csrf_field();
echo '<input type="hidden" name="action" value="create">';
echo '<label class="field" for="username">ユーザー名<span class="hint">半角英数字3〜64文字</span></label>';
echo '<input type="text" id="username" name="username" required>';
echo '<label class="field" for="display_name">表示名<span class="hint">交換履歴の「対応」欄にも残ります。</span></label>';
echo '<input type="text" id="display_name" name="display_name" required>';
echo '<label class="field" for="password">初期パスワード<span class="hint">'
    . config()['min_password_length'] . '文字以上</span></label>';
echo '<input type="password" id="password" name="password" autocomplete="new-password" required>';
echo '<label class="field" for="role">役割</label>';
echo '<select id="role" name="role">';
foreach (['company', 'reception', 'organizer'] as $role) {
    echo '<option value="' . $role . '">' . e(role_label($role)) . '</option>';
}
echo '</select>';
echo '<label class="field" for="company_id">担当企業<span class="hint">企業担当者のときのみ使用します。</span></label>';
echo '<select id="company_id" name="company_id"><option value="0">（指定しない）</option>';
foreach ($companies as $company) {
    echo '<option value="' . $company['id'] . '">' . e($company['label']) . '</option>';
}
echo '</select>';
echo '<div class="btn-row"><button type="submit" class="btn btn-primary">作成する</button></div>';
echo '</form></div>';

echo '<h2>登録済みのアカウント（' . count($users) . '件）</h2>';
echo '<div class="card"><div class="table-scroll"><table>';
echo '<thead><tr><th>ユーザー名</th><th>表示名</th><th>役割</th><th>担当企業</th><th>状態</th><th>操作</th></tr></thead><tbody>';

foreach ($users as $row) {
    $rowId = (int) $row['id'];
    echo '<tr>';
    echo '<td class="mono">' . e((string) $row['username']) . '</td>';
    echo '<td>' . e((string) $row['display_name']) . '</td>';
    echo '<td>' . e(role_label((string) $row['role'])) . '</td>';
    echo '<td>' . e((string) ($row['company_name'] ?? '')) . '</td>';
    echo '<td>' . ((int) $row['is_active'] === 1 ? '<span class="badge badge-good">有効</span>' : '<span class="badge badge-optional">無効</span>') . '</td>';
    echo '<td>';

    echo '<form method="post" class="inline-form" onsubmit="return confirm(\'このアカウントの状態を変更します。よろしいですか？\');">' . csrf_field();
    echo '<input type="hidden" name="action" value="toggle">';
    echo '<input type="hidden" name="user_id" value="' . $rowId . '">';
    echo '<button type="submit" class="btn btn-small' . ((int) $row['is_active'] === 1 ? ' btn-danger' : '') . '">'
        . ((int) $row['is_active'] === 1 ? '無効にする' : '有効にする') . '</button>';
    echo '</form> ';

    echo '<details style="display:inline-block"><summary class="btn btn-small">パスワード変更</summary>';
    echo '<form method="post">' . csrf_field();
    echo '<input type="hidden" name="action" value="reset_password">';
    echo '<input type="hidden" name="user_id" value="' . $rowId . '">';
    echo '<input type="password" name="password" autocomplete="new-password" placeholder="新しいパスワード" required>';
    echo '<div class="btn-row"><button type="submit" class="btn btn-small btn-primary">変更する</button></div>';
    echo '</form></details>';

    echo '</td></tr>';
}
echo '</tbody></table></div></div>';

echo '<p class="muted">企業担当者は、自社のアンケート編集・集計・回答一覧・CSVのみ利用できます。';
echo '他社のURLを直接開いても「ページが見つかりません」と表示されます。</p>';

page_footer();
