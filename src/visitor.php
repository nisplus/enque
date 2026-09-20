<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/repository.php';

/**
 * 来場者の匿名セッション。
 *
 * 氏名などは一切受け取らず、ランダムなトークンをCookieに入れるだけで
 * 「同じ端末からの回答」を束ねる。PHPセッションは使わない
 * （来場者側でサーバーにセッションファイルを作らない）。
 */

const VISITOR_COOKIE      = 'enque_vid';
const VISITOR_COOKIE_DAYS = 60;

/** Cookie のトークンを取得する（無ければ発行してCookieに載せる） */
function visitor_token(): string
{
    static $token = null;
    if (is_string($token)) {
        return $token;
    }

    $existing = $_COOKIE[VISITOR_COOKIE] ?? null;
    if (is_string($existing) && preg_match('/\A[0-9a-f]{64}\z/', $existing) === 1) {
        $token = $existing;
        return $token;
    }

    $token = bin2hex(random_bytes(32));
    $_COOKIE[VISITOR_COOKIE] = $token;

    // QRから来た直後の遷移でも維持されるよう SameSite=Lax にする
    if (!headers_sent()) {
        setcookie(VISITOR_COOKIE, $token, [
            'expires'  => time() + VISITOR_COOKIE_DAYS * 86400,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => config()['session_secure'],
        ]);
    }

    return $token;
}

/** このイベントにおける来場者行を返す（無ければ作る） */
function current_visitor(int $eventId): array
{
    static $cache = [];
    if (isset($cache[$eventId])) {
        return $cache[$eventId];
    }

    $cache[$eventId] = find_or_create_visitor($eventId, visitor_token());

    return $cache[$eventId];
}

/** Cookie が無効な端末かどうか（判定できたときだけ true） */
function visitor_cookie_missing(): bool
{
    return !isset($_COOKIE[VISITOR_COOKIE]);
}
