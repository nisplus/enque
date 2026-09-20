<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

/**
 * PDO接続を返す（プロセス内で使い回す）。
 *
 * - エラーは例外（不整合を握り潰さない）
 * - エミュレーションを無効化し、真のプリペアドステートメントを使う
 * - 文字コードは DSN の charset=utf8mb4 で指定済み
 */
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $db = config()['db'];
    $pdo = new PDO($db['dsn'], $db['user'], $db['pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_STRINGIFY_FETCHES  => false,
    ]);

    return $pdo;
}

/**
 * クロージャをトランザクションで囲む。
 *
 * 回答の登録（responses と answers）のように、途中で失敗したら
 * 何も残ってはいけない処理に使う。
 *
 * @template T
 * @param callable(): T $fn
 * @return T
 */
function db_transaction(callable $fn): mixed
{
    $pdo = db();
    // 既にトランザクション中ならネストせずそのまま実行する
    if ($pdo->inTransaction()) {
        return $fn();
    }

    $pdo->beginTransaction();
    try {
        $result = $fn();
        $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}
