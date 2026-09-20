<?php
declare(strict_types=1);

/** 管理画面のログイン */

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';
require_once dirname(__DIR__, 2) . '/src/view.php';

if (admin_user_count() === 0) {
    redirect('setup.php');
}

if (current_admin() !== null) {
    redirect('index.php');
}

$error = null;
$ip    = client_ip();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_valid_csrf();

    if (login_is_locked($ip)) {
        $error = 'ログインの試行が続いたため、しばらくお待ちください（約'
            . (int) (login_lock_window_seconds() / 60) . '分）。';
    } else {
        $username = trim_ja((string) (post_string('username') ?? ''));
        $password = (string) (post_string('password') ?? '');

        $user = $username === '' || $password === '' ? null : admin_authenticate($username, $password, $ip);
        if ($user === null) {
            $error = 'ユーザー名またはパスワードが正しくありません。';
        } else {
            admin_session_start((int) $user['id']);
            redirect('index.php');
        }
    }
}

page_header('ログイン｜管理画面');

echo '<h1>管理画面ログイン</h1>';
if ($error !== null) {
    echo '<div class="alert alert-error">' . e($error) . '</div>';
}

echo '<form method="post" class="card">';
echo csrf_field();
echo '<label class="field" for="username">ユーザー名</label>';
echo '<input type="text" id="username" name="username" autocomplete="username" required autofocus>';
echo '<label class="field" for="password">パスワード</label>';
echo '<input type="password" id="password" name="password" autocomplete="current-password" required>';
echo '<div class="btn-row"><button type="submit" class="btn btn-primary btn-block">ログイン</button></div>';
echo '</form>';

page_footer();
