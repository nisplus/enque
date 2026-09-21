<?php
declare(strict_types=1);

/**
 * 初回セットアップ。主催者アカウントを1つ作る。
 *
 * 管理ユーザーが1人でも登録されていれば、この画面は使えない。
 */

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';
require_once dirname(__DIR__, 2) . '/src/view.php';

if (admin_user_count() > 0) {
    redirect('login.php');
}

$error = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_valid_csrf();

    $username = trim_ja((string) (post_string('username') ?? ''));
    $display  = trim_ja((string) (post_string('display_name') ?? ''));
    $password = (string) (post_string('password') ?? '');
    $confirm  = (string) (post_string('password_confirm') ?? '');
    $minLen   = config()['min_password_length'];

    if (preg_match('/\A[A-Za-z0-9_.-]{3,64}\z/', $username) !== 1) {
        $error = 'ユーザー名は英数字・ドット・ハイフン・アンダースコアで3〜64文字にしてください。';
    } elseif ($display === '') {
        $error = '表示名を入力してください。';
    } elseif (mb_strlen($password) < $minLen) {
        $error = 'パスワードは' . $minLen . '文字以上にしてください。';
    } elseif ($password !== $confirm) {
        $error = 'パスワードが一致しません。';
    } else {
        $id = create_admin_user($username, $password, mb_substr($display, 0, 100), 'organizer', null);
        admin_session_start($id);
        flash_set('success', '主催者アカウントを作成しました。まずイベントを登録してください。');
        redirect('index.php');
    }
}

page_header('初回セットアップ｜' . admin_title(), ['brand' => admin_title()]);

echo '<h1>初回セットアップ</h1>';
echo '<p>主催者（全体管理者）のアカウントを作成します。この画面は最初の1回だけ使えます。</p>';

if ($error !== null) {
    echo '<div class="alert alert-error">' . e($error) . '</div>';
}

echo '<form method="post" class="card">';
echo csrf_field();
echo '<label class="field" for="username">ユーザー名<span class="hint">半角英数字（ログインに使います）</span></label>';
echo '<input type="text" id="username" name="username" required autofocus>';
echo '<label class="field" for="display_name">表示名<span class="hint">例：イベント事務局</span></label>';
echo '<input type="text" id="display_name" name="display_name" required>';
echo '<label class="field" for="password">パスワード<span class="hint">'
    . config()['min_password_length'] . '文字以上</span></label>';
echo '<input type="password" id="password" name="password" autocomplete="new-password" required>';
echo '<label class="field" for="password_confirm">パスワード（確認）</label>';
echo '<input type="password" id="password_confirm" name="password_confirm" autocomplete="new-password" required>';
echo '<div class="btn-row"><button type="submit" class="btn btn-primary btn-block">作成する</button></div>';
echo '</form>';

page_footer();
