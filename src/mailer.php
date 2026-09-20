<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

/**
 * 外部SMTPサーバー経由のメール送信（最小限のSMTPクライアント）。
 *
 * Composer を使わない構成のため PHPMailer は入れず、必要な手順
 * （EHLO / STARTTLS / AUTH / MAIL FROM / RCPT TO / DATA）だけを実装する。
 *
 * SMTP_HOST が未設定のときは送信せず、logs/mail-dryrun.log に内容を書き出す
 * （開発・受け入れテスト用。接続情報が届く前でも全体の流れを確認できる）。
 */

class SmtpException extends RuntimeException
{
}

/**
 * 1通送る。失敗時は SmtpException を投げる。
 */
function send_mail(string $toEmail, string $subject, string $body): void
{
    $mail = config()['mail'];

    if ($mail['host'] === '') {
        mail_dry_run($toEmail, $subject, $body);
        return;
    }

    $message = build_mail_message($mail['from'], $mail['from_name'], $toEmail, $subject, $body);

    $secure  = $mail['secure'];
    $host    = ($secure === 'ssl' ? 'ssl://' : '') . $mail['host'];
    $errno   = 0;
    $errstr  = '';
    $socket  = @fsockopen($host, $mail['port'], $errno, $errstr, 15);
    if ($socket === false) {
        throw new SmtpException("SMTPサーバーに接続できません（{$mail['host']}:{$mail['port']}）: {$errstr}");
    }
    stream_set_timeout($socket, 15);

    try {
        smtp_expect($socket, 220);

        $ehloName = (string) (gethostname() ?: 'localhost');
        smtp_command($socket, 'EHLO ' . $ehloName, 250);

        if ($secure === 'tls') {
            smtp_command($socket, 'STARTTLS', 220);
            $ok = stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            if ($ok !== true) {
                throw new SmtpException('STARTTLS に失敗しました。');
            }
            smtp_command($socket, 'EHLO ' . $ehloName, 250);
        }

        if ($mail['user'] !== '') {
            smtp_command($socket, 'AUTH LOGIN', 334);
            smtp_command($socket, base64_encode($mail['user']), 334);
            smtp_command($socket, base64_encode($mail['pass']), 235);
        }

        smtp_command($socket, 'MAIL FROM:<' . $mail['from'] . '>', 250);
        smtp_command($socket, 'RCPT TO:<' . $toEmail . '>', 250);
        smtp_command($socket, 'DATA', 354);

        // 行頭のドットはエスケープする（SMTPのデータ終端と衝突するため）
        $data = preg_replace('/^\./m', '..', $message) ?? $message;
        fwrite($socket, $data . "\r\n.\r\n");
        smtp_expect($socket, 250);

        smtp_command($socket, 'QUIT', 221);
    } finally {
        fclose($socket);
    }
}

/** コマンドを送り、期待する応答コードを確認する */
function smtp_command($socket, string $command, int $expected): string
{
    fwrite($socket, $command . "\r\n");

    return smtp_expect($socket, $expected, $command);
}

/** 応答を読み、期待するコードでなければ例外にする */
function smtp_expect($socket, int $expected, string $context = ''): string
{
    $response = '';
    while (($line = fgets($socket, 1024)) !== false) {
        $response .= $line;
        // 複数行応答は "250-..." が続き、最後が "250 ..." になる
        if (strlen($line) >= 4 && $line[3] === ' ') {
            break;
        }
    }
    if ($response === '') {
        throw new SmtpException('SMTPサーバーから応答がありません' . ($context !== '' ? "（{$context}）" : ''));
    }
    $code = (int) substr($response, 0, 3);
    if ($code !== $expected) {
        // 認証情報は例外メッセージに含めない
        $safeContext = str_starts_with($context, 'AUTH') || $context === '' ? '' : "（{$context}）";
        throw new SmtpException('SMTPエラー' . $safeContext . '：' . trim($response));
    }

    return $response;
}

/** RFC 5322 のメッセージを組み立てる（本文はUTF-8のbase64） */
function build_mail_message(string $from, string $fromName, string $to, string $subject, string $body): string
{
    $headers = [
        'Date: ' . date('r'),
        'From: ' . mb_encode_mimeheader($fromName, 'UTF-8') . ' <' . $from . '>',
        'To: <' . $to . '>',
        'Subject: ' . mb_encode_mimeheader($subject, 'UTF-8'),
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: base64',
        'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . substr(strrchr($from, '@') ?: '@localhost', 1) . '>',
        'Auto-Submitted: auto-generated',
    ];

    $encoded = chunk_split(base64_encode(str_replace("\r\n", "\n", $body)), 76, "\r\n");

    return implode("\r\n", $headers) . "\r\n\r\n" . $encoded;
}

/** SMTP未設定時に内容をログへ書き出す（送信はしない） */
function mail_dry_run(string $to, string $subject, string $body): void
{
    $logDir = project_root() . '/logs';
    if (!is_dir($logDir)) {
        return;
    }
    $entry = sprintf(
        "----- %s -----\nTo: %s\nSubject: %s\n\n%s\n",
        date('Y-m-d H:i:s'),
        $to,
        $subject,
        $body
    );
    @file_put_contents($logDir . '/mail-dryrun.log', $entry, FILE_APPEND | LOCK_EX);
}

/** SMTPが設定されているか（管理画面での注意表示に使う） */
function mail_is_configured(): bool
{
    return config()['mail']['host'] !== '';
}
