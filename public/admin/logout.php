<?php
declare(strict_types=1);

/** ログアウト（GETでは確認画面、POSTで実行） */

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';
require_once dirname(__DIR__, 2) . '/src/view.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_valid_csrf();
    admin_logout();
    redirect('login.php');
}

$user = require_admin();

page_header('ログアウト｜' . admin_title(), ['brand' => admin_title()]);
echo '<h1>ログアウトしますか？</h1>';
echo '<form method="post" class="card">';
echo csrf_field();
echo '<p>' . e((string) $user['display_name']) . ' としてログイン中です。</p>';
echo '<div class="btn-row">';
echo '<button type="submit" class="btn btn-primary">ログアウトする</button>';
echo '<a class="btn" href="index.php">戻る</a>';
echo '</div></form>';
page_footer();
