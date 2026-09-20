<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

/**
 * メール送信。送信方式は .env の MAIL_TRANSPORT で選ぶ。
 *
 *   postfix … ローカルの sendmail コマンド（postfix）に渡し、中継はサーバー側に任せる（既定）
 *   smtp    … 外部SMTPサーバーに直接接続する（最小限のSMTPクライアントを内蔵）
 *   log     … 送信せず logs/mail-dryrun.log に書き出す（開発・受け入れテスト用）
 *
 * Composer を使わない構成のため PHPMailer は入れず、必要な手順
 * （EHLO / STARTTLS / AUTH / MAIL FROM / RCPT TO / DATA）だけを実装している。
 *
 * postfix が動いているサーバーでは、ローカルの 127.0.0.1:25 に smtp で繋ぐ方式でも中継できる
 * （MAIL_TRANSPORT=smtp / SMTP_HOST=127.0.0.1 / SMTP_PORT=25 / SMTP_SECURE=none）。
 * sendmail コマンドが使えない環境ではこちらを選ぶ。
 */

class SmtpException extends RuntimeException
{
}

/**
 * 1通送る。失敗時は SmtpException を投げる。
 */
function send_mail(string $toEmail, string $subject, string $body): void
{
    $mail    = config()['mail'];
    $message = build_mail_message($mail['from'], $mail['from_name'], $toEmail, $subject, $body);

    switch (mail_transport()) {
        case 'log':
            mail_dry_run($toEmail, $subject, $body);
            return;

        case 'postfix':
            send_via_sendmail($mail['sendmail_path'], $mail['from'], $message);
            return;

        default:
            send_via_smtp($mail, $toEmail, $message);
    }
}

/**
 * 実際に使う送信方式を返す。
 *
 * 設定が足りない場合（postfix なのに sendmail が無い、smtp なのにホスト未設定）は
 * log にフォールバックし、なぜ送信しなかったかをエラーログに残す。
 * 黙って失敗させないための保険で、本番では設定を直すこと。
 */
function mail_transport(): string
{
    $mail      = config()['mail'];
    $transport = $mail['transport'];

    if ($transport === 'log') {
        return 'log';
    }

    if ($transport === 'smtp') {
        if ($mail['host'] === '') {
            error_log('MAIL_TRANSPORT=smtp ですが SMTP_HOST が未設定です。送信せずログに書き出します。');
            return 'log';
        }
        return 'smtp';
    }

    // 既定は postfix（ローカル中継）
    if (!is_executable($mail['sendmail_path'])) {
        error_log('sendmail コマンドが見つかりません（' . $mail['sendmail_path'] . '）。送信せずログに書き出します。');
        return 'log';
    }

    return 'postfix';
}

/**
 * ローカルの sendmail（postfix）にメッセージを渡す。
 *
 * -t  宛先をヘッダの To: から読む
 * -i  行頭のドットを終端として扱わない
 * -f  エンベロープの送信者（バウンス先）を指定する
 */
function send_via_sendmail(string $sendmailPath, string $from, string $message): void
{
    // パスに空白が含まれても壊れないよう、コマンド名も引数として引用する
    $command = escapeshellarg($sendmailPath) . ' -t -i -f ' . escapeshellarg($from);

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = @proc_open($command, $descriptors, $pipes);
    if (!is_resource($process)) {
        throw new SmtpException('sendmail を起動できません：' . $sendmailPath);
    }

    fwrite($pipes[0], $message . "\r\n");
    fclose($pipes[0]);

    $stdout = stream_get_contents($pipes[1]) ?: '';
    $stderr = stream_get_contents($pipes[2]) ?: '';
    fclose($pipes[1]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);
    if ($exitCode !== 0) {
        throw new SmtpException('sendmail が異常終了しました（終了コード ' . $exitCode . '）：' . trim($stderr . ' ' . $stdout));
    }
}

/**
 * 外部SMTPサーバーへ直接送る。
 *
 * @param array<string,mixed> $mail config()['mail']
 */
function send_via_smtp(array $mail, string $toEmail, string $message): void
{
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

/** 実際に送信できる設定になっているか（管理画面での注意表示に使う） */
function mail_is_configured(): bool
{
    return mail_transport() !== 'log';
}

/** 管理画面に出す、現在の送信方式の説明 */
function mail_transport_label(): string
{
    $mail = config()['mail'];

    return match (mail_transport()) {
        'postfix' => 'ローカルのpostfix経由（' . $mail['sendmail_path'] . '）',
        'smtp'    => '外部SMTP直結（' . $mail['host'] . ':' . $mail['port'] . '）',
        default   => '送信しない（logs/mail-dryrun.log に書き出し）',
    };
}
