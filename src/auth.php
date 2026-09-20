<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/repository.php';

/**
 * 管理画面のセッション・ログイン・CSRF・権限。
 *
 * セッションは管理画面でのみ開始する（来場者側は匿名Cookieのみで、
 * PHPセッションを作らない）。
 *
 * 権限モデル：
 *   organizer … 主催者。全イベント・全企業を操作できる
 *   company   … 企業ブース担当者。自社のアンケートと集計のみ
 *   reception … 総合受付。景品交換の照会のみ
 *
 * ロール・所属企業・有効フラグはセッションに保存せず、毎リクエストDBから
 * 読み直す（アカウントを無効化したら次のリクエストで即座に効く）。
 */

/** セキュアな属性を指定してセッションを開始する */
function session_start_secure(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Strict',
        'secure'   => config()['session_secure'],
    ]);
    session_name('ENQUEADMINSESSID');
    session_start();
}

// ---------------------------------------------------------------- CSRF

/** CSRFトークンを取得する（無ければ発行する） */
function csrf_token(): string
{
    session_start_secure();
    if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

/** フォームに埋め込む hidden フィールド */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

/** 送信されたトークンを検証する */
function csrf_validate(mixed $token): bool
{
    session_start_secure();
    $expected = $_SESSION['csrf_token'] ?? null;

    return is_string($expected) && is_string($token) && hash_equals($expected, $token);
}

/** CSRFトークンが不正なら 400 で終了する */
function require_valid_csrf(): void
{
    if (!csrf_validate($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        header('Content-Type: text/html; charset=UTF-8');
        echo '<!doctype html><meta charset="utf-8"><p>不正なリクエストです（セッションが切れている可能性があります）。';
        echo '<a href="login.php">ログイン画面に戻る</a></p>';
        exit;
    }
}

// ---------------------------------------------------------------- ログイン状態

/**
 * ログイン中の管理ユーザーを返す（未ログインなら null）。
 *
 * セッションにはユーザーIDだけを入れ、権限は毎回DBから取り直す。
 */
function current_admin(): ?array
{
    session_start_secure();
    $id = $_SESSION['admin_id'] ?? null;
    if (!is_int($id)) {
        return null;
    }

    $user = find_admin($id);
    if ($user === null || (int) $user['is_active'] !== 1) {
        return null;
    }

    return $user;
}

/** 未ログインならログイン画面へ送る。ログイン済みならユーザーを返す */
function require_admin(): array
{
    $user = current_admin();
    if ($user === null) {
        redirect('login.php');
    }

    return $user;
}

/** 主催者のみ。企業担当者・受付は 404（存在を伏せる） */
function require_organizer(): array
{
    $user = require_admin();
    if ((string) $user['role'] !== 'organizer') {
        abort(404, 'ページが見つかりません。');
    }

    return $user;
}

/** 景品交換の照会ができるロールか（主催者・受付） */
function require_reception(): array
{
    $user = require_admin();
    if (!in_array((string) $user['role'], ['organizer', 'reception'], true)) {
        abort(404, 'ページが見つかりません。');
    }

    return $user;
}

/**
 * その企業を操作できるか検証する。
 *
 * 権限が無い場合は 403 ではなく 404 を返す（他社IDの存在を伏せるため）。
 */
function assert_company_access(array $user, int $companyId): void
{
    if ((string) $user['role'] === 'organizer') {
        return;
    }
    if ((string) $user['role'] === 'company' && (int) $user['company_id'] === $companyId) {
        return;
    }
    abort(404, 'ページが見つかりません。');
}

/** 企業担当者なら自社IDを、主催者なら null（全社）を返す */
function scope_company_id(array $user): ?int
{
    return (string) $user['role'] === 'company' ? (int) $user['company_id'] : null;
}

/** ログイン成功時の処理（セッション固定化対策を含む） */
function admin_session_start(int $adminId): void
{
    session_start_secure();
    session_regenerate_id(true);
    $_SESSION['admin_id']     = $adminId;
    $_SESSION['logged_in_at'] = time();
    // ログイン前のトークンは破棄し、新しいセッションで再発行させる
    unset($_SESSION['csrf_token']);
}

/** ログアウト（セッションを完全に破棄する） */
function admin_logout(): void
{
    session_start_secure();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $params['path'],
            'domain'   => $params['domain'],
            'secure'   => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'] ?? 'Strict',
        ]);
    }
    session_destroy();
}

// ---------------------------------------------------------------- レート制限

/** クライアントIP（取得できない場合は unknown） */
function client_ip(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';

    return is_string($ip) && $ip !== '' ? substr($ip, 0, 45) : 'unknown';
}

/** 同一IPからのログイン失敗が閾値に達していればロック中とみなす */
function login_is_locked(string $ip): bool
{
    $config = config();

    return recent_login_failures($ip, $config['login_window_seconds']) >= $config['login_max_attempts'];
}

/** ロック解除までの目安秒数 */
function login_lock_window_seconds(): int
{
    return config()['login_window_seconds'];
}

/**
 * ユーザー名とパスワードを照合する。
 *
 * 失敗理由（ユーザーが存在しない／パスワード違い／無効化済み）は
 * 呼び出し側に区別させない（アカウント列挙を防ぐ）。
 */
function admin_authenticate(string $username, string $password, string $ip): ?array
{
    $user = find_admin_by_username($username);
    if ($user === null || (int) $user['is_active'] !== 1) {
        // ユーザーが無い場合もハッシュ計算と同程度の時間を使い、応答時間で存在を推測させない
        password_verify($password, '$2y$10$usesomesillystringforsalttoavoidtimingleaksxxxxxxxxxxxxxxxxxxxxxx');
        record_login_failure($ip);
        return null;
    }

    if (!password_verify($password, (string) $user['password_hash'])) {
        record_login_failure($ip);
        return null;
    }

    // ハッシュのアルゴリズム・コストが古くなっていれば作り直す
    if (password_needs_rehash((string) $user['password_hash'], PASSWORD_DEFAULT)) {
        update_admin_password((int) $user['id'], password_hash($password, PASSWORD_DEFAULT));
    }
    clear_login_failures($ip);

    return $user;
}

// ---------------------------------------------------------------- フラッシュメッセージ

/** 次のリクエストで1度だけ表示するメッセージを積む */
function flash_set(string $type, string $message): void
{
    session_start_secure();
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

/**
 * 積まれたメッセージを取り出して消す。
 *
 * @return array{type: string, message: string}|null
 */
function flash_take(): ?array
{
    session_start_secure();
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);

    return is_array($flash) ? $flash : null;
}
