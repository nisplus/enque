<?php
declare(strict_types=1);

/**
 * sql/schema.sql を実行してデータベースとテーブルを作成する（CLI専用）。
 *
 *   php bin/init_db.php
 *
 * 接続情報は .env（または環境変数）から読み込む。
 * DB_USER に CREATE DATABASE 権限が無い場合は、あらかじめ管理者が
 * データベースを作成しておけば、テーブル作成だけが実行される。
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("このスクリプトはコマンドラインからのみ実行できます。\n");
}

require_once dirname(__DIR__) . '/src/config.php';

$host = env('DB_HOST', '127.0.0.1');
$port = env('DB_PORT', '3306');
$name = (string) env('DB_NAME', 'enque');
$user = (string) env('DB_USER', 'root');
$pass = (string) env('DB_PASS', '');

if (preg_match('/\A[A-Za-z0-9_]+\z/', $name) !== 1) {
    exit("DB_NAME には英数字とアンダースコアのみ指定できます：{$name}\n");
}

$sqlPath = project_root() . '/sql/schema.sql';
$sql = file_get_contents($sqlPath);
if ($sql === false) {
    exit("schema.sql を読み込めませんでした：{$sqlPath}\n");
}

// スキーマ側の固定DB名を、設定されたDB名に置き換える
$sql = str_replace('enque', $name, $sql);

// コメント行を除去し、文単位に分割する
$statements = [];
$buffer = '';
foreach (preg_split('/\R/u', $sql) ?: [] as $line) {
    $trimmed = trim($line);
    if ($trimmed === '' || str_starts_with($trimmed, '--')) {
        continue;
    }
    $buffer .= $line . "\n";
    if (str_ends_with($trimmed, ';')) {
        $statements[] = $buffer;
        $buffer = '';
    }
}

try {
    // dbname を指定せずに接続する（CREATE DATABASE を実行するため）
    $dsn = sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $host, $port);
    $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    foreach ($statements as $statement) {
        $pdo->exec($statement);
    }
} catch (PDOException $e) {
    exit('DBの初期化に失敗しました：' . $e->getMessage() . "\n");
}

echo "データベース「{$name}」を初期化しました（" . count($statements) . " 文を実行）。\n";
echo "次に /admin/setup.php を開いて主催者アカウントを作成してください。\n";
