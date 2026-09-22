<?php
declare(strict_types=1);

require_once __DIR__ . '/repository.php';
require_once __DIR__ . '/mailer.php';

/**
 * 全体アンケートの案内メール送信。
 *
 * 管理画面（少量の送信）と cron（一括送信）の両方から呼ぶ。
 */

/**
 * 案内メールの件名と本文。
 *
 * @return array{subject: string, body: string}
 */
function build_invite_mail(array $event, string $token): array
{
    $url  = overall_url($token);
    $name = (string) $event['name'];

    $subject = '【' . $name . '】アンケートのお願い（スマホ壁紙プレゼント）';

    $body = <<<TEXT
    このたびは「{$name}」にご来場いただき、ありがとうございました。

    会場のブースでアンケートにご回答いただいた皆さまに、
    イベント全体についてのアンケートをお願いしております。

    ご回答いただくと、その場ではいてくヒルズオリジナル壁紙をダウンロードいただけます。

    ▼ 回答はこちら（所要3分ほど）
    {$url}

    ※ このURLはお客さま専用です。転送しないようお願いいたします。
    ※ お預かりしたメールアドレスは本アンケートのご案内にのみ使用し、
    　 送付・集計の完了後に削除します。
    ※ 本メールは送信専用です。ご返信いただいてもお答えできません。

    {$name} 事務局
    TEXT;

    // ヒアドキュメントのインデントを外す
    $body = preg_replace('/^[ \t]+/m', '', $body) ?? $body;

    return ['subject' => $subject, 'body' => $body];
}

/**
 * 未送信の案内メールを送る。
 *
 * @param callable(string):void|null $log 進捗の出力先（cron用）
 * @return array{sent: int, failed: int}
 */
function send_pending_invites(int $eventId, int $limit = 100, ?callable $log = null): array
{
    $event = find_event($eventId);
    if ($event === null) {
        throw new RuntimeException('イベントが見つかりません：' . $eventId);
    }

    $survey = overall_survey($eventId);
    if ($survey === null || (int) $survey['is_published'] !== 1) {
        throw new RuntimeException('全体アンケートが公開されていません。先に公開してください。');
    }

    $throttleUs = max(0, config()['mail']['throttle_ms']) * 1000;
    $sent       = 0;
    $failed     = 0;

    foreach (pending_invites($eventId, $limit) as $invite) {
        $email = (string) ($invite['email'] ?? '');
        if ($email === '' || !is_valid_email($email)) {
            mark_invite_failed((int) $invite['id'], 'メールアドレスが不正です');
            $failed++;
            continue;
        }

        $mail = build_invite_mail($event, (string) $invite['token']);
        try {
            send_mail($email, $mail['subject'], $mail['body']);
            mark_invite_sent((int) $invite['id']);
            $sent++;
            if ($log !== null) {
                $log('sent: invite#' . (int) $invite['id']);
            }
        } catch (Throwable $e) {
            mark_invite_failed((int) $invite['id'], $e->getMessage());
            $failed++;
            if ($log !== null) {
                $log('failed: invite#' . (int) $invite['id'] . ' ' . $e->getMessage());
            }
        }

        if ($throttleUs > 0) {
            usleep($throttleUs);
        }
    }

    return ['sent' => $sent, 'failed' => $failed];
}
