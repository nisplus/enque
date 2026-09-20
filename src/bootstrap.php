<?php
declare(strict_types=1);

/**
 * 全エントリポイント共通の初期化。
 * public/ 配下の各PHPと bin/ のスクリプトは、最初にこのファイルを require すること。
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/survey.php';
require_once __DIR__ . '/repository.php';
require_once __DIR__ . '/visitor.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/csv.php';
require_once __DIR__ . '/chart.php';

mb_internal_encoding('UTF-8');
date_default_timezone_set('Asia/Tokyo');

// 本番（DISPLAY_ERRORS=0）では画面に出さず、ログにのみ残す
ini_set('display_errors', config()['display_errors'] ? '1' : '0');
ini_set('log_errors', '1');

$logDir = project_root() . '/logs';
if (is_dir($logDir) && is_writable($logDir)) {
    ini_set('error_log', $logDir . '/app-error.log');
}
