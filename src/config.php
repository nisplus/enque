<?php
declare(strict_types=1);

/**
 * アプリケーション設定。
 *
 * DB接続情報・SMTP認証情報はソースにハードコードせず、環境変数から読み込む。
 * 環境変数の供給元は次のいずれか（先に見つかったものを使う）：
 *   1. Apache の SetEnv（$_SERVER に入る）
 *   2. プロセスの環境変数（getenv）
 *   3. プロジェクトルートの .env ファイル
 *
 * このファイルは DocumentRoot（public/）の外に置くこと。
 */

/** プロジェクトルート（public/ の1つ上） */
function project_root(): string
{
    return dirname(__DIR__);
}

/**
 * .env を読み込んで $_ENV に反映する（既に定義済みの環境変数は上書きしない）。
 */
function env_load_file(string $path): void
{
    static $loaded = [];
    if (isset($loaded[$path]) || !is_readable($path)) {
        return;
    }
    $loaded[$path] = true;

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        $pos = strpos($line, '=');
        if ($pos === false) {
            continue;
        }
        $key = trim(substr($line, 0, $pos));
        $val = trim(substr($line, $pos + 1));
        // 前後のクォートを外す
        if (strlen($val) >= 2
            && ($val[0] === '"' || $val[0] === "'")
            && $val[strlen($val) - 1] === $val[0]
        ) {
            $val = substr($val, 1, -1);
        }
        if ($key !== '' && !isset($_SERVER[$key]) && !isset($_ENV[$key]) && getenv($key) === false) {
            $_ENV[$key] = $val;
        }
    }
}

/** 環境変数を1つ取得する */
function env(string $key, ?string $default = null): ?string
{
    env_load_file(project_root() . '/.env');

    $value = $_SERVER[$key] ?? $_ENV[$key] ?? getenv($key);
    if ($value === false || $value === null || $value === '') {
        return $default;
    }
    return (string) $value;
}

/** 真偽値の環境変数（"1"/"true"/"on"/"yes" を真とする） */
function env_bool(string $key, bool $default = false): bool
{
    $value = env($key);
    if ($value === null) {
        return $default;
    }
    return in_array(strtolower($value), ['1', 'true', 'on', 'yes'], true);
}

/**
 * 設定値をまとめて返す。
 *
 * @return array{
 *   db: array{dsn: string, user: string, pass: string},
 *   base_url: string,
 *   pretty_urls: bool,
 *   session_secure: bool,
 *   display_errors: bool,
 *   storage_dir: string,
 *   login_max_attempts: int,
 *   login_window_seconds: int,
 *   min_password_length: int,
 *   mail: array{transport: string, sendmail_path: string, from: string, from_name: string, host: string,
 *               port: int, secure: string, user: string, pass: string, throttle_ms: int}
 * }
 */
function config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $host = env('DB_HOST', '127.0.0.1');
    $port = env('DB_PORT', '3306');
    $name = env('DB_NAME', 'enque');

    $config = [
        'db' => [
            // 文字コードは DSN で utf8mb4 を明示する
            'dsn'  => sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $name),
            'user' => (string) env('DB_USER', 'root'),
            'pass' => (string) env('DB_PASS', ''),
        ],
        // 管理画面のヘッダーとタイトルに出す名前（イベントや主催者に合わせて変えられる）
        'admin_title'          => (string) env('ADMIN_TITLE', '周遊アンケート管理'),
        // QR・メールに埋め込む公開URL（未設定ならリクエストから推定する）
        'base_url'             => rtrim((string) env('BASE_URL', ''), '/'),
        'pretty_urls'          => env_bool('PRETTY_URLS', true),
        // HTTPS運用時は SESSION_SECURE=1
        'session_secure'       => env_bool('SESSION_SECURE', false),
        // 本番は DISPLAY_ERRORS=0（ログのみ）
        'display_errors'       => env_bool('DISPLAY_ERRORS', false),
        // 壁紙の実体を置くディレクトリ（DocumentRoot の外）
        'storage_dir'          => project_root() . '/storage/wallpapers',
        // レート制限：直近 login_window_seconds 秒間に login_max_attempts 回失敗したらロック
        'login_max_attempts'   => 5,
        'login_window_seconds' => 60,
        'min_password_length'  => 8,
        'mail' => [
            // 送信方式：postfix（ローカルのsendmail経由で中継）／ smtp（外部SMTPに直接接続）／ log（送信せずログ）
            'transport'     => strtolower((string) env('MAIL_TRANSPORT', 'postfix')),
            'sendmail_path' => (string) env('SENDMAIL_PATH', '/usr/sbin/sendmail'),
            'from'          => (string) env('MAIL_FROM', 'no-reply@example.jp'),
            'from_name'     => (string) env('MAIL_FROM_NAME', 'イベント事務局'),
            'host'          => (string) env('SMTP_HOST', ''),
            'port'          => (int) env('SMTP_PORT', '587'),
            'secure'        => strtolower((string) env('SMTP_SECURE', 'tls')),
            'user'          => (string) env('SMTP_USER', ''),
            'pass'          => (string) env('SMTP_PASS', ''),
            'throttle_ms'   => (int) env('SMTP_THROTTLE_MS', '200'),
        ],
    ];

    return $config;
}

/** 管理画面のヘッダーとタイトルに出す名前（.env の ADMIN_TITLE で変えられる） */
function admin_title(): string
{
    return config()['admin_title'];
}

/**
 * 公開URLの基点を返す。
 *
 * BASE_URL が設定されていればそれを使う（cron からの実行時はこれが必須）。
 * 未設定のときだけ、現在のリクエストから組み立てる。
 */
function base_url(): string
{
    $configured = config()['base_url'];
    if ($configured !== '') {
        return $configured;
    }

    $https  = ($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off';
    $scheme = $https ? 'https' : 'http';
    $host   = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');

    return $scheme . '://' . $host;
}
