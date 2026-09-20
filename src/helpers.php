<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

/** HTML出力用エスケープ（XSS対策。出力時は必ずこれを通す） */
function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** 'Y-m-d H:i:s' を日本語表記に整形する */
function format_datetime_ja(?string $sqlDatetime): string
{
    if ($sqlDatetime === null || $sqlDatetime === '') {
        return '';
    }
    try {
        $dt = new DateTimeImmutable($sqlDatetime);
    } catch (Exception) {
        return $sqlDatetime;
    }
    $weekdays = ['日', '月', '火', '水', '木', '金', '土'];

    return $dt->format('Y年n月j日') . '(' . $weekdays[(int) $dt->format('w')] . ') ' . $dt->format('H:i');
}

/** 'Y-m-d' を日本語表記に整形する */
function format_date_ja(?string $sqlDate): string
{
    if ($sqlDate === null || $sqlDate === '') {
        return '';
    }
    try {
        return (new DateTimeImmutable($sqlDate))->format('Y年n月j日');
    } catch (Exception) {
        return $sqlDate;
    }
}

/** JSONを返して終了する（APIレスポンス） */
function json_response(array $payload, int $statusCode = 200): never
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

/** リダイレクトして終了する */
function redirect(string $location): never
{
    header('Location: ' . $location);
    exit;
}

/** エラーページを出して終了する（存在しないURL・非公開アンケートなど） */
function abort(int $statusCode, string $message): never
{
    http_response_code($statusCode);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><html lang="ja"><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>' . $statusCode . '</title>';
    echo '<link rel="stylesheet" href="/assets/style.css">';
    echo '<main class="wrap"><div class="card"><h1>ページを表示できません</h1><p>' . e($message) . '</p></div></main>';
    exit;
}

/** POSTされた文字列を1つ取り出す（配列などが来たら null） */
function post_string(string $key): ?string
{
    $value = $_POST[$key] ?? null;

    return is_string($value) ? $value : null;
}

/** GETの文字列を1つ取り出す（配列などが来たら null） */
function get_string(string $key): ?string
{
    $value = $_GET[$key] ?? null;

    return is_string($value) ? $value : null;
}

/**
 * 入力がUTF-8として妥当か。
 *
 * 画面はUTF-8で配信しているため通常のブラウザからは常に妥当だが、
 * 他の文字コードで送られてきた場合に文字化けしたまま保存しないよう検証する。
 */
function is_valid_utf8(mixed $value): bool
{
    if ($value === null) {
        return true;
    }
    if (is_string($value)) {
        return mb_check_encoding($value, 'UTF-8');
    }
    if (is_array($value)) {
        foreach ($value as $key => $item) {
            if (!is_valid_utf8($key) || !is_valid_utf8($item)) {
                return false;
            }
        }
        return true;
    }
    return true;
}

/** 前後の空白（全角スペース含む）を除去する */
function trim_ja(string $value): string
{
    $trimmed = preg_replace('/\A[\s\x{3000}]+|[\s\x{3000}]+\z/u', '', $value);

    // 不正なUTF-8で preg_replace が失敗した場合は通常の trim にフォールバックする
    return $trimmed ?? trim($value);
}

/** 制御文字を除去する（改行・タブは残す） */
function strip_control_chars(string $value): string
{
    $cleaned = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value);

    return $cleaned ?? $value;
}

/** URL用のスラグを作る（推測困難な英数字） */
function random_slug(int $bytes = 8): string
{
    return bin2hex(random_bytes($bytes));
}

/**
 * 交換コードを作る（読み間違えにくい文字のみ、XXXX-XXXX形式）。
 *
 * 0/O、1/I/l など紙やスマホ画面で取り違えやすい文字は使わない。
 */
function random_claim_code(): string
{
    $alphabet = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
    $code     = '';
    for ($i = 0; $i < 8; $i++) {
        $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }

    return substr($code, 0, 4) . '-' . substr($code, 4);
}

/** 入力された交換コードを正規化する（小文字・空白・区切りの揺れを吸収） */
function normalize_claim_code(string $input): string
{
    $upper = strtoupper(trim_ja($input));
    $upper = (string) preg_replace('/[^0-9A-Z]/', '', $upper);
    if (strlen($upper) !== 8) {
        return $upper;
    }

    return substr($upper, 0, 4) . '-' . substr($upper, 4);
}

/** メールアドレスとして妥当か（任意入力なので空文字は呼び出し側で判定する） */
function is_valid_email(string $email): bool
{
    return strlen($email) <= 255 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/** 企業アンケートの公開URL */
function survey_url(string $eventSlug, string $companySlug): string
{
    if (config()['pretty_urls']) {
        return base_url() . '/s/' . rawurlencode($eventSlug) . '/' . rawurlencode($companySlug);
    }

    return base_url() . '/s.php?e=' . rawurlencode($eventSlug) . '&c=' . rawurlencode($companySlug);
}

/** 全体アンケートの公開URL（来場者ごとのトークン付き） */
function overall_url(string $token): string
{
    if (config()['pretty_urls']) {
        return base_url() . '/o/' . rawurlencode($token);
    }

    return base_url() . '/o.php?t=' . rawurlencode($token);
}

/** 数値を「12件」のように整形する */
function count_label(int $n, string $unit = '件'): string
{
    return number_format($n) . $unit;
}

/** 割合（0除算を避ける） */
function percentage(int $part, int $total): float
{
    if ($total <= 0) {
        return 0.0;
    }

    return round($part * 100 / $total, 1);
}

/** 自由記述の最大文字数 */
const TEXT_ANSWER_MAX_LENGTH = 2000;

/**
 * 回答フォームのエラーメッセージ（コード → 日本語文言）。
 *
 * JS無効時は submit.php からコードだけをクエリで渡し、文言はここで引く。
 * クエリの文字列をそのまま画面に出さないことで、任意のメッセージを
 * 表示させるリンクを作られないようにする。
 */
function submit_error_message(?string $code): ?string
{
    $messages = [
        'required'   => '必須の設問に未回答があります。印の付いた設問をご確認ください。',
        'invalid'    => '入力内容に誤りがあります。選択肢や文字数をご確認ください。',
        'length'     => '自由記述は' . TEXT_ANSWER_MAX_LENGTH . '文字以内で入力してください。',
        'email'      => 'メールアドレスの形式が正しくありません。',
        'encoding'   => '入力の文字コードが不正です（UTF-8で送信してください）。',
        'closed'     => 'このアンケートは現在受け付けていません。',
        'system'     => '送信できませんでした。通信状況をご確認のうえ、もう一度お試しください。',
    ];

    return $code !== null ? ($messages[$code] ?? null) : null;
}
