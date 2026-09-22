<?php
declare(strict_types=1);

/**
 * 総合アンケートの案内メールを一括送信する（cron から実行する想定）。
 *
 *   php bin/send_overall_invites.php --event=1 [--limit=200] [--prepare] [--dry-run]
 *
 *   --event    対象イベントID（省略時は status=closed のイベントすべて）
 *   --limit    1回の実行で送る最大件数（既定200）
 *   --prepare  送信対象（overall_invites）を先に作成・更新する
 *   --dry-run  送信せず、対象件数だけを表示する
 *
 * cron 例（5分おきに200件ずつ送る）：
 *   *\/5 * * * * cd /var/www/enque && php bin/send_overall_invites.php --prepare >> logs/invites.log 2>&1
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("このスクリプトはコマンドラインからのみ実行できます。\n");
}

require_once dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/invites.php';

/** @return array<string,string> */
function parse_args(array $argv): array
{
    $args = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (!str_starts_with($arg, '--')) {
            continue;
        }
        $pair = explode('=', substr($arg, 2), 2);
        $args[$pair[0]] = $pair[1] ?? '1';
    }

    return $args;
}

$args    = parse_args($argv);
$limit   = max(1, (int) ($args['limit'] ?? '200'));
$prepare = isset($args['prepare']);
$dryRun  = isset($args['dry-run']);

$eventIds = [];
if (isset($args['event'])) {
    $eventIds[] = (int) $args['event'];
} else {
    foreach (all_events() as $event) {
        if ((string) $event['status'] === 'closed') {
            $eventIds[] = (int) $event['id'];
        }
    }
}

if ($eventIds === []) {
    echo date('Y-m-d H:i:s') . " 対象のイベントがありません（status=closed のイベントか --event= を指定してください）。\n";
    exit(0);
}

$log = static function (string $message): void {
    echo date('Y-m-d H:i:s') . ' ' . $message . "\n";
};

foreach ($eventIds as $eventId) {
    $event = find_event($eventId);
    if ($event === null) {
        $log("イベントが見つかりません：{$eventId}");
        continue;
    }

    if ($prepare) {
        $added = create_pending_invites($eventId);
        $log("[{$event['name']}] 送信対象を{$added}件追加しました。");
    }

    $stats = invite_stats($eventId);
    if ($dryRun) {
        $log("[{$event['name']}] 未送信 {$stats['pending']}件／失敗 {$stats['failed']}件（dry-run のため送信しません）");
        continue;
    }

    try {
        $result = send_pending_invites($eventId, $limit, $log);
        $log("[{$event['name']}] 送信完了：成功 {$result['sent']}件／失敗 {$result['failed']}件");
    } catch (Throwable $e) {
        $log("[{$event['name']}] 送信できません：" . $e->getMessage());
    }
}
