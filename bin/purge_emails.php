<?php
declare(strict_types=1);

/**
 * 預かったメールアドレスを削除する（CLI専用）。
 *
 *   php bin/purge_emails.php --event=1 [--force]
 *   php bin/purge_emails.php --older-than=30 --force
 *
 *   --event       対象イベントID
 *   --older-than  終了（status=closed）から指定日数が過ぎたイベントをまとめて対象にする
 *   --force       確認なしで実行する
 *
 * 回答データ本体（個人情報を含まない）は削除しない。
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("このスクリプトはコマンドラインからのみ実行できます。\n");
}

require_once dirname(__DIR__) . '/src/bootstrap.php';

$args = [];
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--')) {
        $pair = explode('=', substr($arg, 2), 2);
        $args[$pair[0]] = $pair[1] ?? '1';
    }
}

$targets = [];
if (isset($args['event'])) {
    $event = find_event((int) $args['event']);
    if ($event === null) {
        exit("イベントが見つかりません：{$args['event']}\n");
    }
    $targets[] = $event;
} elseif (isset($args['older-than'])) {
    $days = max(0, (int) $args['older-than']);
    foreach (all_events() as $event) {
        if ((string) $event['status'] !== 'closed') {
            continue;
        }
        $endDate = (string) ($event['end_date'] ?? '');
        if ($endDate === '' || strtotime($endDate) === false) {
            continue;
        }
        if (strtotime($endDate) <= strtotime("-{$days} days")) {
            $targets[] = $event;
        }
    }
} else {
    exit("--event=<ID> または --older-than=<日数> を指定してください。\n");
}

if ($targets === []) {
    echo "対象のイベントはありません。\n";
    exit(0);
}

foreach ($targets as $event) {
    $stats = invite_stats((int) $event['id']);
    echo "[{$event['name']}] 未送信 {$stats['pending']}件／送信済み {$stats['sent']}件\n";

    if (!isset($args['force'])) {
        echo "  --force を付けると削除します（未送信ぶんは送れなくなります）。\n";
        continue;
    }

    $result = purge_emails((int) $event['id']);
    echo "  メールアドレスを削除しました：来場者 {$result['visitors']}件／案内 {$result['invites']}件\n";
}
