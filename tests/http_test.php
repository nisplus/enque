<?php
declare(strict_types=1);

/**
 * 仕様書の要件をHTTP経由で検証するE2Eテスト。
 *
 *   1) serve.cmd で開発サーバーを起動する
 *   2) php tests/http_test.php --force [http://127.0.0.1:8080]
 *
 * 注意：テスト専用のイベント・企業・管理ユーザーをDBに作成し、最後に削除する。
 * 必ず開発用のデータベースに対して実行すること（--force が無いと実行しない）。
 *
 * 出力は Windows のコンソール（CP932）でも読めるよう英数字のみとする。
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/invites.php';
require_once dirname(__DIR__) . '/src/insights.php';

$argvValues = array_slice($argv, 1);
$force      = in_array('--force', $argvValues, true);
$urls       = array_values(array_filter($argvValues, static fn($a) => !str_starts_with($a, '--')));
$baseUrl    = rtrim($urls[0] ?? (getenv('TEST_BASE_URL') ?: 'http://127.0.0.1:8080'), '/');

if (!$force) {
    echo "This test creates and deletes its own event, companies and admin users.\n";
    echo "Run it against a development database only:\n";
    echo "  php tests/http_test.php --force [base-url]\n";
    exit(1);
}

$passed = 0;
$failed = 0;

function check(string $name, bool $condition, string $detail = ''): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "[PASS] {$name}\n";
        return;
    }
    $failed++;
    echo "[FAIL] {$name}" . ($detail !== '' ? " -- {$detail}" : '') . "\n";
}

// ---------------------------------------------------------------- HTTP helper

$jars = [];
function jar(string $name): string
{
    global $jars;
    if (!isset($jars[$name])) {
        $jars[$name] = tempnam(sys_get_temp_dir(), 'enque_' . $name . '_');
        register_shutdown_function(static function () use ($name): void {
            global $jars;
            @unlink($jars[$name]);
        });
    }

    return $jars[$name];
}

/**
 * フォーム本文を組み立てる。
 *
 * 値が配列のときは同じ名前を繰り返す（複数選択の q[12][] と同じ形にする）。
 * http_build_query は添字を付けてしまうため使わない。
 *
 * @param array<string, string|list<string>> $post
 */
function build_body(array $post): string
{
    $parts = [];
    foreach ($post as $name => $value) {
        foreach (is_array($value) ? $value : [$value] as $item) {
            $parts[] = rawurlencode($name) . '=' . rawurlencode((string) $item);
        }
    }

    return implode('&', $parts);
}

/**
 * @param array<string, string|list<string>>|null $post
 * @param list<string> $extraHeaders
 * @return array{status: int, body: string, location: ?string, headers: string}
 */
function request(string $method, string $path, ?array $post = null, string $cookieJar = 'visitor', array $extraHeaders = []): array
{
    global $baseUrl;

    $jarFile = jar($cookieJar);
    $ch = curl_init($baseUrl . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR      => $jarFile,
        CURLOPT_COOKIEFILE     => $jarFile,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $extraHeaders,
        CURLOPT_TIMEOUT        => 20,
    ]);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, build_body($post));
    }

    $raw = curl_exec($ch);
    if ($raw === false) {
        fwrite(STDERR, 'HTTP request failed: ' . curl_error($ch) . " ({$path})\n");
        fwrite(STDERR, "Is the dev server running? (serve.cmd)\n");
        exit(1);
    }
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $status     = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $headers = substr($raw, 0, $headerSize);
    $body    = substr($raw, $headerSize);
    $location = null;
    if (preg_match('/^Location:\s*(.+)$/mi', $headers, $m) === 1) {
        $location = trim($m[1]);
    }

    return ['status' => $status, 'body' => $body, 'location' => $location, 'headers' => $headers];
}

/** 管理画面のフォームからCSRFトークンを取り出す */
function csrf_from(string $html): string
{
    return preg_match('/name="csrf_token" value="([0-9a-f]+)"/', $html, $m) === 1 ? $m[1] : '';
}

// ---------------------------------------------------------------- Fixtures

$stamp     = date('His') . random_int(100, 999);
$password  = 'Test-Pass-2026';
$eventId   = create_event('TEST EVENT ' . $stamp, date('Y-m-d'), date('Y-m-d'), 'open');
$event     = find_event($eventId);
$eventSlug = (string) $event['slug'];

$companyAId = create_company($eventId, 'TEST CO A ' . $stamp, 'A-1', '#2a78d6', null);
$companyBId = create_company($eventId, 'TEST CO B ' . $stamp, 'B-1', null, null);
$companyA   = find_company($companyAId);
$companyB   = find_company($companyBId);

$surveyA = ensure_company_survey($companyA);
$surveyB = ensure_company_survey($companyB);
$surveyAId = (int) $surveyA['id'];
$surveyBId = (int) $surveyB['id'];

// XSSの検証用に、設問文へタグを混ぜておく
replace_questions($surveyAId, [
    ['id' => null, 'type' => 'single', 'label' => '<script>alert(1)</script>来場目的', 'options' => ['情報収集', '就職活動'], 'required' => true],
    ['id' => null, 'type' => 'multi',  'label' => '興味のある内容', 'options' => ['製品', '採用'], 'required' => false],
    ['id' => null, 'type' => 'rating', 'label' => '満足度', 'options' => [], 'required' => false],
    ['id' => null, 'type' => 'text',   'label' => '自由記述', 'options' => [], 'required' => false],
]);
replace_questions($surveyBId, [
    ['id' => null, 'type' => 'single', 'label' => 'B社の設問', 'options' => ['はい', 'いいえ'], 'required' => true],
]);
update_survey($surveyAId, 'TEST SURVEY A', 'テスト用', true);
update_survey($surveyBId, 'TEST SURVEY B', null, true);

$questionsA = questions_for_survey($surveyAId);
$qSingle    = (int) $questionsA[0]['id'];
$qMulti     = (int) $questionsA[1]['id'];
$qRating    = (int) $questionsA[2]['id'];
$qText      = (int) $questionsA[3]['id'];

$overallId = create_survey($eventId, null, 'overall', 'TEST OVERALL', null, true);
replace_questions($overallId, [
    ['id' => null, 'type' => 'rating', 'label' => '全体満足度', 'options' => [], 'required' => true],
]);
$overallQ = (int) questions_for_survey($overallId)[0]['id'];

$organizerName = 'test_org_' . $stamp;
$companyName   = 'test_co_' . $stamp;
$receptionName = 'test_rcp_' . $stamp;
$organizerId   = create_admin_user($organizerName, $password, 'TEST 主催者', 'organizer', null);
$companyUserId = create_admin_user($companyName, $password, 'TEST 企業担当', 'company', $companyAId);
$receptionId   = create_admin_user($receptionName, $password, 'TEST 受付', 'reception', null);

// 後片付け（テストが途中で落ちても消す）
register_shutdown_function(static function () use ($eventId, $organizerId, $companyUserId, $receptionId): void {
    try {
        $stmt = db()->prepare('DELETE FROM admin_users WHERE id IN (?, ?, ?)');
        $stmt->execute([$organizerId, $companyUserId, $receptionId]);
        // イベントを消すと企業・アンケート・回答も外部キーで消える
        $stmt = db()->prepare('DELETE FROM events WHERE id = ?');
        $stmt->execute([$eventId]);
        db()->exec('DELETE FROM admin_login_attempts');
    } catch (Throwable $e) {
        fwrite(STDERR, 'cleanup failed: ' . $e->getMessage() . "\n");
    }
});

echo "=== survey page (visitor) ===\n";

$surveyPath = '/s/' . $eventSlug . '/' . (string) $companyA['qr_slug'];
$res = request('GET', $surveyPath);
check('survey page returns 200', $res['status'] === 200, 'status=' . $res['status']);
check('survey page shows the survey title', str_contains($res['body'], 'TEST SURVEY A'));
check('survey page escapes script tags in labels',
    !str_contains($res['body'], '<script>alert(1)</script>') && str_contains($res['body'], '&lt;script&gt;'));
check('survey page marks required questions', str_contains($res['body'], '必須'));
check('survey page asks for an optional email', str_contains($res['body'], 'name="email"'));
check('visitor cookie is issued', str_contains($res['headers'], 'enque_vid='));

$res = request('GET', '/s/' . $eventSlug . '/deadbeefdeadbeef');
check('unknown company slug returns 404', $res['status'] === 404, 'status=' . $res['status']);

$res = request('GET', '/s.php?e=' . $eventSlug . '&c=' . (string) $companyA['qr_slug']);
check('query-string form of the URL also works', $res['status'] === 200);

echo "=== submit (visitor) ===\n";

$res = request('POST', '/submit.php', [
    'survey_id'      => (string) $surveyAId,
    'q[' . $qText . ']' => 'テキストのみ',
], 'visitor', ['Accept: application/json']);
$json = json_decode($res['body'], true);
check('missing required answer is rejected', $res['status'] === 400, 'status=' . $res['status']);
check('rejection names the required question',
    is_array($json) && in_array($qSingle, $json['questions'] ?? [], true));

$res = request('POST', '/submit.php', [
    'survey_id'          => (string) $surveyAId,
    'q[' . $qSingle . ']' => '存在しない選択肢',
], 'visitor', ['Accept: application/json']);
check('answer outside the option list is rejected', $res['status'] === 400);

$tricky = "改行\nと ' シングルクォート \" と <b>タグ</b> と 100% と ; DROP TABLE responses;";
$res = request('POST', '/submit.php', [
    'survey_id'           => (string) $surveyAId,
    'q[' . $qSingle . ']'  => '情報収集',
    'q[' . $qMulti . '][]' => ['製品', '採用'],
    'q[' . $qRating . ']'  => '4',
    'q[' . $qText . ']'    => $tricky,
    'email'                => 'visitor+' . $stamp . '@example.jp',
], 'visitor', ['Accept: application/json']);
$json = json_decode($res['body'], true);
check('valid submission succeeds', $res['status'] === 200 && ($json['ok'] ?? false) === true, 'status=' . $res['status']);
check('submission redirects to the done page', str_contains((string) ($json['redirect'] ?? ''), '/done.php'));
check('the redirect already carries the claim code', str_contains((string) ($json['redirect'] ?? ''), '&c='));
check('first submission is not marked as duplicate', ($json['duplicate'] ?? true) === false);

check('response is stored', count_responses($surveyAId) === 1);

$stored = db()->prepare('SELECT a.value FROM answers a JOIN responses r ON r.id = a.response_id
                         WHERE r.survey_id = ? AND a.question_id = ?');
$stored->execute([$surveyAId, $qText]);
check('special characters are stored verbatim', $stored->fetchColumn() === $tricky);

$stored = db()->prepare('SELECT a.value FROM answers a JOIN responses r ON r.id = a.response_id
                         WHERE r.survey_id = ? AND a.question_id = ?');
$stored->execute([$surveyAId, $qMulti]);
check('multi-select is stored as a JSON array', $stored->fetchColumn() === '["製品","採用"]');

$emailStmt = db()->prepare('SELECT COUNT(*) FROM visitors WHERE event_id = ? AND email IS NOT NULL');
$emailStmt->execute([$eventId]);
check('optional email is saved', (int) $emailStmt->fetchColumn() === 1);

// 2回目の送信（同じ端末・同じ企業）
$res = request('POST', '/submit.php', [
    'survey_id'          => (string) $surveyAId,
    'q[' . $qSingle . ']' => '就職活動',
], 'visitor', ['Accept: application/json']);
$json = json_decode($res['body'], true);
check('resubmission is accepted (not blocked)', $res['status'] === 200 && ($json['ok'] ?? false) === true);
check('resubmission is flagged as duplicate', ($json['duplicate'] ?? false) === true);
check('duplicate is excluded from the count', count_responses($surveyAId) === 1);
check('duplicate is counted separately', count_duplicate_responses($surveyAId) === 1);

// 別の端末（別のCookie）からの回答
$res = request('POST', '/submit.php', [
    'survey_id'          => (string) $surveyAId,
    'q[' . $qSingle . ']' => '情報収集',
], 'visitor2', ['Accept: application/json']);
check('another device counts as a separate response', count_responses($surveyAId) === 2);

echo "=== done page and claim code ===\n";

/**
 * 回答済み画面を開く。
 *
 * コード無しのURLは、交換コード付きのURL（ブックマーク用）へ1回転送されるので追いかける。
 *
 * @return array{status: int, body: string, location: ?string, headers: string}
 */
function done_page(string $jar = 'visitor'): array
{
    global $eventSlug;

    $res = request('GET', '/done.php?e=' . $eventSlug, null, $jar);
    if ($res['status'] === 302 && $res['location'] !== null) {
        $res = request('GET', (string) $res['location'], null, $jar);
    }

    return $res;
}

$res = request('GET', '/done.php?e=' . $eventSlug);
check('done page redirects to a bookmarkable url with the claim code',
    $res['status'] === 302 && str_contains((string) $res['location'], 'c='),
    'location=' . (string) $res['location']);

$res = done_page();
check('done page returns 200', $res['status'] === 200);
check('done page shows the visited booth', str_contains($res['body'], 'TEST CO A'));
check('done page shows a claim code',
    preg_match('/[2-9A-HJ-NP-Z]{4}-[2-9A-HJ-NP-Z]{4}/', strip_tags($res['body'])) === 1);
preg_match('/([2-9A-HJ-NP-Z]{4}-[2-9A-HJ-NP-Z]{4})/', strip_tags($res['body']), $codeMatch);
$claimCode = $codeMatch[1] ?? '';
check('done page tells the visitor to use the same phone', str_contains($res['body'], '同じスマホ'));
check('done page suggests a screenshot or bookmark',
    str_contains($res['body'], 'スクリーンショット') && str_contains($res['body'], 'ブックマーク'));
check('claim code is not re-issued on reload', str_contains(done_page()['body'], $claimCode));

// Cookie が無い端末でも、コード付きURLなら同じ画面に戻れる
$codeUrl = '/done.php?e=' . $eventSlug . '&c=' . rawurlencode($claimCode);
$res = request('GET', $codeUrl, null, 'nocookie');
check('the code url opens without the visitor cookie', $res['status'] === 200, 'status=' . $res['status']);
check('the code url shows the same claim code', str_contains($res['body'], $claimCode));
check('the code url lists the same booths', str_contains($res['body'], 'TEST CO A'));

$res = request('GET', '/done.php?e=' . $eventSlug . '&c=ZZZZ-ZZZZ', null, 'nocookie');
check('an unknown code is reported, not silently swapped',
    $res['status'] === 404 && str_contains($res['body'], '交換コードが見つかりません'), 'status=' . $res['status']);

// QRコードには交換コードのURLが入り、読み取った人で行き先が変わる。
// QRは画像（SVG）なのでURL文字列は本文に出ない。期待するSVGを作って突き合わせる。
require_once dirname(__DIR__) . '/src/qrcode.php';
check('the done page embeds the claim url in the qr code',
    str_contains(done_page()['body'], qr_svg(claim_url($claimCode), 4, 2)));
check('the claim url points at the scan entry point',
    str_contains(claim_url($claimCode), '/c/' . $claimCode));

$res = request('GET', '/c/' . rawurlencode($claimCode), null, 'nocookie');
check('a visitor scanning the qr lands on the done page',
    $res['status'] === 302 && str_contains((string) $res['location'], '/done.php'), 'location=' . (string) $res['location']);

$res = request('GET', '/c/ZZZZZZZZ', null, 'nocookie');
check('an unknown code in the qr url returns 404', $res['status'] === 404);

$res = request('GET', '/c.php?code=' . rawurlencode($claimCode), null, 'nocookie');
check('the query-string form of the qr url also works', $res['status'] === 302);

// 回答フォームにも「同じスマホで」の案内を出す
$res = request('GET', $surveyPath, null, 'visitor');
check('survey page tells the visitor to use the same phone', str_contains($res['body'], '同じスマホ'));

// 2社目のブースを回っても、交換コードは来場者ごとに1つのまま（ブースごとには発行しない）
$qB  = (int) questions_for_survey($surveyBId)[0]['id'];
$res = request('POST', '/submit.php', [
    'survey_id'      => (string) $surveyBId,
    'q[' . $qB . ']' => 'はい',
], 'visitor', ['Accept: application/json']);
check('answering a second booth succeeds', $res['status'] === 200);

$res = done_page();
check('the claim code stays the same after a second booth', str_contains($res['body'], $claimCode));
check('the done page now lists both booths',
    str_contains($res['body'], 'TEST CO A') && str_contains($res['body'], 'TEST CO B'));
$claimRow  = find_claim_by_code($claimCode);
$claimStmt = db()->prepare('SELECT COUNT(*) FROM prize_claims WHERE visitor_id = ?');
$claimStmt->execute([(int) $claimRow['visitor_id']]);
check('one claim code per visitor, not per booth', (int) $claimStmt->fetchColumn() === 1);
check('the visitor is counted as having visited two booths',
    visited_company_count((int) $claimRow['visitor_id']) === 2);

// ブースのQRスラグは企業ごとに固定で、回答しても変わらない
check('the booth url does not change after answering',
    (string) (find_company($companyAId)['qr_slug'] ?? '') === (string) $companyA['qr_slug']);

echo "=== admin access control ===\n";

$res = request('GET', '/admin/index.php', null, 'org');
check('admin requires login', $res['status'] === 302 && str_contains((string) $res['location'], 'login.php'));

$res = request('GET', '/admin/login.php', null, 'org');
$token = csrf_from($res['body']);
check('login form has a CSRF token', $token !== '');

$res = request('POST', '/admin/login.php', ['username' => $organizerName, 'password' => 'wrong'], 'org');
check('wrong password does not log in', !str_contains((string) ($res['location'] ?? ''), 'index.php'));

$res = request('POST', '/admin/login.php', ['csrf_token' => 'bogus', 'username' => $organizerName, 'password' => $password], 'org');
check('login without a valid CSRF token is rejected', $res['status'] === 400);

$res = request('GET', '/admin/login.php', null, 'org');
$token = csrf_from($res['body']);
$res = request('POST', '/admin/login.php', ['csrf_token' => $token, 'username' => $organizerName, 'password' => $password], 'org');
check('organizer can log in', $res['status'] === 302 && str_contains((string) $res['location'], 'index.php'),
    'status=' . $res['status'] . ' location=' . (string) $res['location']);

$res = request('GET', '/admin/index.php', null, 'org');
check('organizer dashboard shows the event', str_contains($res['body'], 'TEST EVENT ' . $stamp));
check('organizer dashboard shows the company ranking', str_contains($res['body'], 'TEST CO A'));
check('organizer dashboard shows the average booth count', str_contains($res['body'], '平均訪問企業数'));

// 企業担当者
$res = request('GET', '/admin/login.php', null, 'co');
$token = csrf_from($res['body']);
request('POST', '/admin/login.php', ['csrf_token' => $token, 'username' => $companyName, 'password' => $password], 'co');

$res = request('GET', '/admin/index.php', null, 'co');
check('company user sees only its own dashboard',
    str_contains($res['body'], 'TEST CO A') && !str_contains($res['body'], 'TEST CO B'));

$res = request('GET', '/admin/company_stats.php?company=' . $companyBId, null, 'co');
check('company user cannot open another company (404, not 403)', $res['status'] === 404, 'status=' . $res['status']);

$res = request('GET', '/admin/export_csv.php?company=' . $companyBId, null, 'co');
check('company user cannot export another company', $res['status'] === 404);

$res = request('GET', '/admin/export_csv.php?event=' . $eventId, null, 'co');
check('company user cannot export the whole event', $res['status'] === 404);

$res = request('GET', '/admin/users.php', null, 'co');
check('company user cannot manage users', $res['status'] === 404);

$res = request('GET', '/admin/company_stats.php?company=' . $companyAId, null, 'co');
check('company user can open its own stats', $res['status'] === 200);
check('stats page renders a chart', str_contains($res['body'], '<svg class="chart"'));

echo "=== csv ===\n";

$res = request('GET', '/admin/export_csv.php?company=' . $companyAId, null, 'co');
check('csv starts with a UTF-8 BOM', str_starts_with($res['body'], "\xEF\xBB\xBF"));
check('csv uses CRLF line endings', str_contains($res['body'], "\r\n"));
check('csv contains the answer text', str_contains($res['body'], '情報収集'));
check('csv does not contain the email address', !str_contains($res['body'], '@example.jp'));

$res = request('GET', '/admin/export_csv.php?event=' . $eventId, null, 'org');
check('organizer csv covers every company',
    str_contains($res['body'], 'TEST CO A') && str_contains($res['body'], '企業名'));

echo "=== prizes ===\n";

$res = request('GET', '/admin/prizes.php?event=' . $eventId, null, 'org');
check('prize page opens for the organizer', $res['status'] === 200);
$token = csrf_from($res['body']);

$res = request('POST', '/admin/prizes.php', [
    'csrf_token' => $token,
    'action'     => 'create',
    'event_id'   => (string) $eventId,
    'name'       => 'TEST PRIZE A',
    'total_qty'  => '2',
    'note'       => '先着2名',
], 'org');
check('a prize can be registered', $res['status'] === 302);

$res = request('POST', '/admin/prizes.php', [
    'csrf_token' => $token,
    'action'     => 'create',
    'event_id'   => (string) $eventId,
    'name'       => 'TEST PRIZE B',
    'total_qty'  => '',
], 'org');
check('a prize without a quantity is allowed', $res['status'] === 302);

$stock = prize_stock($eventId);
check('both prizes are stored', count($stock) === 2);
check('the quantity is kept', $stock[0]['total_qty'] === 2);
check('an unmanaged quantity stays null', $stock[1]['total_qty'] === null);
check('nothing is claimed yet', $stock[0]['claimed'] === 0 && $stock[0]['remaining'] === 2);

$prizeAId = (int) $stock[0]['id'];
$prizeBId = (int) $stock[1]['id'];

$res = request('GET', '/admin/prizes.php?event=' . $eventId, null, 'org');
check('the prize page shows the remaining count', str_contains($res['body'], 'TEST PRIZE A') && str_contains($res['body'], '残数'));

$res = request('GET', '/admin/prizes.php?event=' . $eventId, null, 'co');
check('a company user cannot register prizes', $res['status'] === 404, 'status=' . $res['status']);

echo "=== prize claim ===\n";

$res = request('GET', '/admin/login.php', null, 'rcp');
$token = csrf_from($res['body']);
request('POST', '/admin/login.php', ['csrf_token' => $token, 'username' => $receptionName, 'password' => $password], 'rcp');

$res = request('GET', '/admin/companies.php', null, 'rcp');
check('reception cannot open company management', $res['status'] === 404);

$res = request('GET', '/admin/prizes.php', null, 'rcp');
check('reception cannot register prizes either', $res['status'] === 404, 'status=' . $res['status']);

// 受付がQRを読み取ると、そのまま照会画面へ送られる
$res = request('GET', '/c/' . rawurlencode($claimCode), null, 'rcp');
check('reception scanning the qr goes straight to the lookup',
    $res['status'] === 302 && str_contains((string) $res['location'], '/admin/claim.php?code='),
    'location=' . (string) $res['location']);

$res = request('GET', '/admin/claim.php?code=' . rawurlencode($claimCode), null, 'rcp');
check('reception can look up a claim code', $res['status'] === 200 && str_contains($res['body'], $claimCode));
check('unclaimed code is shown as not yet exchanged', str_contains($res['body'], '未交換'));
check('the lookup page explains the qr scan', str_contains($res['body'], 'カメラで読み取る'));
check('the lookup page has the scan button and camera panel',
    str_contains($res['body'], 'id="scan-open"') && str_contains($res['body'], 'id="scan-video"'));
check('the lookup page loads the scanner scripts',
    str_contains($res['body'], '/assets/vendor/jsqr.min.js') && str_contains($res['body'], '/assets/scan.js'));
check('manual code entry is still available', str_contains($res['body'], 'name="code"'));

$res = request('GET', '/assets/vendor/jsqr.min.js', null, 'rcp');
check('the vendored jsqr is served', $res['status'] === 200 && str_contains($res['body'], 'jsQR'));
check('the jsqr license header is kept', str_contains($res['body'], 'Apache License 2.0'));

$res = request('GET', '/assets/scan.js', null, 'rcp');
check('the scanner script is served', $res['status'] === 200 && str_contains($res['body'], 'getUserMedia'));

// 以降の確認のため、照会画面を取り直す（上でアセットを取得して $res を上書きしたため）
$res = request('GET', '/admin/claim.php?code=' . rawurlencode($claimCode), null, 'rcp');
check('the claim form lists the registered prizes',
    str_contains($res['body'], 'TEST PRIZE A') && str_contains($res['body'], '残り2個'));
check('the reception page shows the stock table', str_contains($res['body'], '景品の残数'));

$token = csrf_from($res['body']);
$claim = find_claim_by_code($claimCode);

// 景品を選ばずに記録しようとすると差し戻される
$res = request('POST', '/admin/claim.php', [
    'csrf_token' => $token,
    'action'     => 'mark',
    'code'       => $claimCode,
    'claim_id'   => (string) $claim['id'],
    'note'       => '景品未選択',
], 'rcp');
check('marking without choosing a prize is refused', $res['status'] === 302);
check('the claim is still open', (find_claim_by_code($claimCode)['claimed_at'] ?? null) === null);

$res = request('GET', '/admin/claim.php?code=' . rawurlencode($claimCode), null, 'rcp');
check('the reason is shown to the staff', str_contains($res['body'], '渡した景品を選んでください'));
$token = csrf_from($res['body']);

$res = request('POST', '/admin/claim.php', [
    'csrf_token' => $token,
    'action'     => 'mark',
    'code'       => $claimCode,
    'claim_id'   => (string) $claim['id'],
    'prize_id'   => (string) $prizeAId,
    'note'       => 'テスト景品',
], 'rcp');
check('marking as claimed redirects back', $res['status'] === 302);

$res = request('GET', '/admin/claim.php?code=' . rawurlencode($claimCode), null, 'rcp');
check('claimed code shows the exchange time', str_contains($res['body'], '交換済み'));
check('claim history records the staff name', str_contains($res['body'], 'TEST 受付'));
check('claim history records the prize', str_contains($res['body'], 'TEST PRIZE A'));
check('the claim page shows which prize was handed over', str_contains($res['body'], '渡した景品'));

$claim = find_claim_by_code($claimCode);
check('claimed_at is stored once', ($claim['claimed_at'] ?? null) !== null);
check('the prize is stored on the claim', (int) $claim['prize_id'] === $prizeAId);
check('second claim attempt does not overwrite the record', !mark_claimed((int) $claim['id'], 'x', null));

$stock = prize_stock($eventId);
check('the stock goes down by one', $stock[0]['claimed'] === 1 && $stock[0]['remaining'] === 1);
check('the other prize is untouched', $stock[1]['claimed'] === 0);
check('a prize without a quantity has no remaining count', $stock[1]['remaining'] === null);

// 2人目に渡すと在庫が尽き、それ以上は超過として記録される
// このテストのイベント内で、まだ交換していないコードを1つ選ぶ
// （他のイベントのデータに手を出すと、開発DBの状態しだいで結果が変わってしまう）
$otherClaim = db()->prepare(
    'SELECT pc.claim_code FROM prize_claims pc
     JOIN visitors v ON v.id = pc.visitor_id
     WHERE v.event_id = ? AND pc.id <> ? AND pc.claimed_at IS NULL
     ORDER BY pc.id LIMIT 1'
);
$otherClaim->execute([$eventId, (int) $claim['id']]);
$otherCode = $otherClaim->fetchColumn();
$claim2Row = $otherCode === false ? null : find_claim_by_code((string) $otherCode);
if ($claim2Row !== null) {
    mark_claimed((int) $claim2Row['id'], 'TEST 受付', null, $prizeAId);
    $stock = prize_stock($eventId);
    check('the prize runs out at the registered quantity', $stock[0]['remaining'] === 0);

    // 在庫が無くても記録はできる（実際に渡したものを残せるようにするため）
    $extra = find_or_create_claim((int) find_or_create_visitor($eventId, str_repeat('f', 64))['id']);
    mark_claimed((int) $extra['id'], 'TEST 受付', null, $prizeAId);
    $stock = prize_stock($eventId);
    check('handing out past the quantity is recorded as an overage',
        $stock[0]['remaining'] === 0 && $stock[0]['over'] === 1 && $stock[0]['claimed'] === 3);
}

// 来場者側には「交換済み」と表示しない（コード付きURLで開いた場合も同じ）
$res = done_page();
check('visitor page does not reveal the claimed state',
    !str_contains($res['body'], '交換済み') && str_contains($res['body'], $claimCode));

$res = request('GET', '/done.php?e=' . $eventSlug . '&c=' . rawurlencode($claimCode), null, 'nocookie');
check('the code url does not reveal the claimed state either',
    !str_contains($res['body'], '交換済み') && str_contains($res['body'], $claimCode));

echo "=== qr code ===\n";

$res = request('GET', '/admin/qr.php?company=' . $companyAId . '&format=svg', null, 'org');
check('qr endpoint returns svg', str_contains($res['headers'], 'image/svg+xml') && str_contains($res['body'], '<svg'));

$res = request('GET', '/admin/qr_print.php?event=' . $eventId, null, 'org');
check('qr print sheet lists the companies', str_contains($res['body'], 'TEST CO A') && str_contains($res['body'], 'TEST CO B'));
check('qr print sheet embeds the survey url', str_contains($res['body'], (string) $companyA['qr_slug']));

echo "=== survey editor ===\n";

$res = request('GET', '/admin/survey_edit.php?survey=' . $surveyAId, null, 'org');
check('survey editor opens', $res['status'] === 200 && str_contains($res['body'], 'TEST SURVEY A'));
$token = csrf_from($res['body']);

// 既存4問はそのままに、5問目を追加する
$editPost = [
    'csrf_token'   => $token,
    'survey_id'    => (string) $surveyAId,
    'action'       => 'save',
    'title'        => 'TEST SURVEY A',
    'description'  => 'テスト用',
    'is_published' => '1',
];
foreach ($questionsA as $i => $question) {
    $editPost['q[' . $i . '][id]']       = (string) $question['id'];
    $editPost['q[' . $i . '][type]']     = (string) $question['type'];
    $editPost['q[' . $i . '][label]']    = (string) $question['label'];
    $editPost['q[' . $i . '][options]']  = implode("\n", question_options($question));
    $editPost['q[' . $i . '][sort]']     = (string) ($i + 1);
    if ((int) $question['required'] === 1) {
        $editPost['q[' . $i . '][required]'] = '1';
    }
}
$editPost['q[4][id]']      = '0';
$editPost['q[4][type]']    = 'nps';
$editPost['q[4][label]']   = '知人にすすめたいですか';
$editPost['q[4][options]'] = '';
$editPost['q[4][sort]']    = '5';

$res = request('POST', '/admin/survey_edit.php', $editPost, 'org');
check('saving the editor redirects', $res['status'] === 302);
check('the new question is stored', count(questions_for_survey($surveyAId)) === 5);
check('existing questions keep their ids',
    (int) questions_for_survey($surveyAId)[0]['id'] === $qSingle);
check('existing answers survive the edit', count_responses($surveyAId) === 2);

// 選択肢が1つしかない選択式は保存させない
$badPost = $editPost;
$badPost['q[4][type]']    = 'single';
$badPost['q[4][options]'] = 'ひとつだけ';
$res = request('POST', '/admin/survey_edit.php', $badPost, 'org');
check('a choice question with one option is rejected',
    $res['status'] === 200 && str_contains($res['body'], '選択肢を2つ以上'), 'status=' . $res['status']);
check('rejected save does not change the question type',
    (string) questions_for_survey($surveyAId)[4]['type'] === 'nps');

$res = request('POST', '/admin/survey_edit.php', array_merge($editPost, ['csrf_token' => 'bogus']), 'org');
check('editor rejects a missing CSRF token', $res['status'] === 400);

// 「保存して設問を追加」は、画面の先頭ではなく追加された設問へ戻す
$res = request('POST', '/admin/survey_edit.php', array_merge($editPost, ['action' => 'save_add']), 'org');
check('save-and-add jumps to the new question', str_ends_with((string) $res['location'], '#q-new'),
    'location=' . (string) $res['location']);

$res = request('GET', '/admin/survey_edit.php?survey=' . $surveyAId . '&add=1', null, 'org');
check('the new question block carries the anchor id', str_contains($res['body'], 'id="q-new"'));
check('the new question input takes focus', str_contains($res['body'], 'autofocus'));
check('existing questions are anchored too', str_contains($res['body'], 'id="q-1"'));

echo "=== mail transport ===\n";

check('transport falls back to log when nothing is configured', mail_transport() === 'log');
check('the label explains the current transport', str_contains(mail_transport_label(), 'mail-dryrun.log'));

$res = request('GET', '/admin/invites.php?event=' . $eventId, null, 'org');
check('the admin page warns that no transport is set', str_contains($res['body'], 'MAIL_TRANSPORT'));
$token = csrf_from($res['body']);

$logFile  = project_root() . '/logs/mail-dryrun.log';
$testMail = 'mailtest+' . $stamp . '@example.jp';
$res = request('POST', '/admin/invites.php', [
    'csrf_token' => $token,
    'action'     => 'test_mail',
    'event_id'   => (string) $eventId,
    'test_email' => $testMail,
], 'org');
check('test mail is accepted', $res['status'] === 302);

// テスト送信のリンクは、スタッフが実際に開けるプレビューであること
$previewPath = '/o/preview-' . $eventId;

$res = request('GET', $previewPath, null, 'org');
check('the preview link opens for the organizer',
    $res['status'] === 200 && str_contains($res['body'], 'TEST OVERALL'), 'status=' . $res['status']);
check('the preview is clearly marked', str_contains($res['body'], 'プレビュー'));
check('the preview cannot be submitted', str_contains($res['body'], 'プレビューのため送信できません'));

$res = request('GET', $previewPath, null, 'nocookie');
check('the preview asks a logged-out visitor to sign in',
    $res['status'] === 403 && str_contains($res['body'], 'ログインが必要'), 'status=' . $res['status']);

$res = request('GET', $previewPath, null, 'co');
check('a company user cannot open the preview', $res['status'] === 403);

$res = request('POST', '/submit.php', [
    'survey_id'            => (string) $overallId,
    't'                    => 'preview-' . $eventId,
    'q[' . $overallQ . ']' => '5',
], 'org', ['Accept: application/json']);
check('the preview token cannot be used to answer', $res['status'] === 400);

// filesize() は stat キャッシュに載るため、内容で確かめる
clearstatcache(true, $logFile);
$logBody = is_file($logFile) ? (string) file_get_contents($logFile) : '';
check('test mail is written to the dry-run log', str_contains($logBody, $testMail));
// ログは追記されるので、今回のイベント固有のURLで確認する（古い行で通らないように）
check('the dry-run log holds the preview link', str_contains($logBody, '/o/preview-' . $eventId));
check('the test mail says it is a test', str_contains($logBody, 'テスト送信'));

// メッセージの組み立て（postfix に渡す内容とSMTPで送る内容は同じ）
$message = build_mail_message('no-reply@example.jp', 'イベント事務局', 'to@example.jp', '件名テスト', "本文\n2行目");
check('message headers are MIME encoded',
    str_contains($message, 'Content-Type: text/plain; charset=UTF-8')
    && str_contains($message, 'Subject: =?UTF-8?B?'));
check('message body is base64 encoded',
    str_contains($message, base64_encode("本文\n2行目")));
check('message declares the envelope recipient in the To header',
    str_contains($message, 'To: <to@example.jp>'));

echo "=== wallpaper upload ===\n";

// 1x1 の PNG（アップロード検証用）
$pngPath = tempnam(sys_get_temp_dir(), 'enque_wp_') . '.png';
file_put_contents($pngPath, base64_decode(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
));
register_shutdown_function(static fn() => @unlink($pngPath));

$res = request('GET', '/admin/wallpapers.php?event=' . $eventId, null, 'org');
$token = csrf_from($res['body']);

$ch = curl_init($baseUrl . '/admin/wallpapers.php');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER         => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_COOKIEJAR      => jar('org'),
    CURLOPT_COOKIEFILE     => jar('org'),
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => [
        'csrf_token' => $token,
        'action'     => 'upload',
        'event_id'   => (string) $eventId,
        'title'      => 'TEST WALLPAPER',
        'company_id' => '0',
        'image'      => new CURLFile($pngPath, 'image/png', 'test.png'),
    ],
]);
$uploadRaw = curl_exec($ch);
$uploadStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
check('wallpaper upload redirects back', $uploadStatus === 302, 'status=' . $uploadStatus);

$wallpapers = wallpapers_for_event($eventId);
check('wallpaper row is stored', count($wallpapers) === 1);
$wallpaperId = (int) ($wallpapers[0]['id'] ?? 0);
check('wallpaper file is written outside the document root',
    $wallpaperId > 0 && is_file(config()['storage_dir'] . '/' . (string) $wallpapers[0]['file_name']));

register_shutdown_function(static function () use ($wallpapers): void {
    foreach ($wallpapers as $wallpaper) {
        @unlink(config()['storage_dir'] . '/' . (string) $wallpaper['file_name']);
    }
});

echo "=== overall survey and wallpaper ===\n";

$added = create_pending_invites($eventId);
check('invite row is created for the email address', $added === 1);

$inviteStmt = db()->prepare('SELECT * FROM overall_invites WHERE event_id = ? LIMIT 1');
$inviteStmt->execute([$eventId]);
$invite = $inviteStmt->fetch();
$inviteToken = (string) $invite['token'];

$res = request('GET', '/wallpaper.php?t=' . $inviteToken, null, 'visitor3');
check('wallpaper page redirects before answering',
    $res['status'] === 302 && str_contains((string) $res['location'], '/o.php'));

$res = request('GET', '/o/' . $inviteToken, null, 'visitor3');
check('overall survey opens from the emailed link', $res['status'] === 200 && str_contains($res['body'], 'TEST OVERALL'));

$res = request('POST', '/submit.php', [
    'survey_id'           => (string) $overallId,
    't'                   => $inviteToken,
    'q[' . $overallQ . ']' => '5',
], 'visitor3', ['Accept: application/json']);
$json = json_decode($res['body'], true);
check('overall survey submission succeeds', $res['status'] === 200 && ($json['ok'] ?? false) === true);
check('overall submission leads to the wallpaper page', str_contains((string) ($json['redirect'] ?? ''), '/wallpaper.php'));

$res = request('POST', '/submit.php', [
    'survey_id'           => (string) $overallId,
    't'                   => 'invalid-token',
    'q[' . $overallQ . ']' => '5',
], 'visitor3', ['Accept: application/json']);
check('overall survey rejects an unknown token', $res['status'] === 400);

$res = request('GET', '/wallpaper.php?t=' . $inviteToken, null, 'visitor3');
check('wallpaper page opens after answering', $res['status'] === 200);
check('wallpaper page lists the uploaded image', str_contains($res['body'], 'TEST WALLPAPER'));
check('wallpaper page explains long-press saving', str_contains($res['body'], '長押し'));

$res = request('GET', '/wallpaper_file.php?t=' . $inviteToken . '&id=' . $wallpaperId . '&dl=1', null, 'visitor3');
check('wallpaper downloads as an attachment',
    $res['status'] === 200 && str_contains($res['headers'], 'attachment') && str_contains($res['headers'], 'image/png'));
check('wallpaper bytes are served', str_starts_with($res['body'], "\x89PNG"));

$res = request('GET', '/wallpaper_file.php?t=nosuchtoken&id=' . $wallpaperId, null, 'visitor4');
check('wallpaper is not served without a valid token', $res['status'] === 404);

$res = request('GET', '/wallpaper_file.php?t=' . $inviteToken . '&id=999999', null, 'visitor3');
check('unknown wallpaper id returns 404', $res['status'] === 404);

$res = request('GET', '/o/' . $inviteToken, null, 'visitor3');
check('answered link goes straight to the wallpaper page',
    $res['status'] === 302 && str_contains((string) $res['location'], '/wallpaper.php'));

$stats = invite_stats($eventId);
check('invite is marked as responded', $stats['responded'] === 1);

echo "=== visitor insights ===\n";

// 集計の正しさを確かめるため、時刻まで決め打ちした回答を別イベントに作る
$insightEventId = create_event('TEST INSIGHT ' . $stamp, date('Y-m-d'), date('Y-m-d'), 'open');
$insightCompanies = [];
foreach (['I-A', 'I-B', 'I-C'] as $name) {
    $companyId = create_company($insightEventId, $name . ' ' . $stamp, null, null, null);
    $company   = find_company($companyId);
    $survey    = ensure_company_survey($company);
    replace_questions((int) $survey['id'], [
        ['id' => null, 'type' => 'rating', 'label' => '満足度', 'options' => [], 'required' => false],
    ]);
    update_survey((int) $survey['id'], $name, null, true);
    $insightCompanies[$name] = [
        'company_id' => $companyId,
        'survey_id'  => (int) $survey['id'],
        'question'   => (int) questions_for_survey((int) $survey['id'])[0]['id'],
    ];
}

/** 指定した時刻・評価で回答を1件作る */
$addResponse = static function (int $visitorId, string $company, string $at, ?string $rating) use ($insightCompanies): void {
    $surveyId = $insightCompanies[$company]['survey_id'];
    $answers  = $rating === null ? [] : [$insightCompanies[$company]['question'] => $rating];
    $result   = insert_response($surveyId, $visitorId, $answers);

    $stmt = db()->prepare('UPDATE responses SET submitted_at = ? WHERE id = ?');
    $stmt->execute([$at, $result['response_id']]);
};

$today = date('Y-m-d');
$v1 = (int) find_or_create_visitor($insightEventId, str_repeat('1', 64))['id'];
$v2 = (int) find_or_create_visitor($insightEventId, str_repeat('2', 64))['id'];
$v3 = (int) find_or_create_visitor($insightEventId, str_repeat('3', 64))['id'];

// v1: A(10:00) → B(10:30)    周回30分・2社
$addResponse($v1, 'I-A', $today . ' 10:00:00', '5');
$addResponse($v1, 'I-B', $today . ' 10:30:00', '5');
// v2: A(11:00) のみ          1社（時間の集計からは除外）
$addResponse($v2, 'I-A', $today . ' 11:00:00', '3');
// v3: B(12:00) → A(12:10) → C(12:40)   周回40分・3社
$addResponse($v3, 'I-B', $today . ' 12:00:00', '4');
$addResponse($v3, 'I-A', $today . ' 12:10:00', '4');
$addResponse($v3, 'I-C', $today . ' 12:40:00', '4');

$laps    = visitor_laps($insightEventId);
$lapTime = lap_time_stats($laps);
$lapCos  = lap_company_stats($laps);

check('every visitor appears once in the lap data', count($laps) === 3);
check('the single-company visitor is counted separately', $lapTime['single_company_visitors'] === 1);
check('lap time uses only visitors with two or more booths', $lapTime['summary']['count'] === 2);
check('the average lap time is correct', (int) $lapTime['summary']['avg'] === 2100, // (1800+2400)/2
    'avg=' . $lapTime['summary']['avg']);
check('the shortest lap time is correct', (int) $lapTime['summary']['min'] === 1800);
check('the longest lap time is correct', (int) $lapTime['summary']['max'] === 2400);
check('the median lap time is correct', (int) $lapTime['summary']['median'] === 2100);

check('the average booth count is correct', $lapCos['summary']['avg'] === 2.0, 'avg=' . $lapCos['summary']['avg']);
check('the largest booth count is correct', (int) $lapCos['summary']['max'] === 3);
check('the booth count distribution is correct',
    ($lapCos['distribution']['1社'] ?? -1) === 1
    && ($lapCos['distribution']['2社'] ?? -1) === 1
    && ($lapCos['distribution']['3社'] ?? -1) === 1);

$order = [];
foreach (company_visit_order($insightEventId) as $row) {
    $order[substr($row['name'], 0, 3)] = $row;
}
check('the first-visit count is correct', $order['I-A']['first_visits'] === 2 && $order['I-B']['first_visits'] === 1);
check('the average position is correct',
    $order['I-A']['avg_position'] === 1.33 && $order['I-C']['avg_position'] === 3.0,
    'A=' . $order['I-A']['avg_position'] . ' C=' . $order['I-C']['avg_position']);

$transitions = [];
foreach (company_transitions($insightEventId, 20) as $row) {
    $transitions[substr($row['from'], 0, 3) . '>' . substr($row['to'], 0, 3)] = $row['count'];
}
check('the a-to-b transition is counted', ($transitions['I-A>I-B'] ?? 0) === 1);
check('the reverse transition is counted separately', ($transitions['I-B>I-A'] ?? 0) === 1);
check('the later transition is counted', ($transitions['I-A>I-C'] ?? 0) === 1);
check('no transition is invented for the single-booth visitor', array_sum($transitions) === 3);

$moves = move_interval_stats($insightEventId);
check('move intervals are measured between consecutive answers', $moves['count'] === 3);
check('the median move interval is correct', (int) $moves['median'] === 1800, 'median=' . $moves['median']);

// 回答は6件あるが、時間帯ごとの「人数」は 10時=1人・11時=1人・12時=1人 の計3
$hourly = hourly_unique_visitors($insightEventId);
check('hourly unique visitors are counted per person, not per answer',
    array_sum($hourly) === 3 && max($hourly) === 1,
    'sum=' . array_sum($hourly) . ' max=' . max($hourly));

$satisfaction = satisfaction_by_lap_count($insightEventId, $laps);
check('satisfaction is grouped by booth count',
    $satisfaction[0]['visitors'] === 1 && $satisfaction[1]['visitors'] === 2);
check('the rating average is computed per group',
    $satisfaction[0]['rating_avg'] === 3.0, 'one-booth avg=' . var_export($satisfaction[0]['rating_avg'], true));

echo "=== insights page ===\n";

$res = request('GET', '/admin/insights.php?event=' . $insightEventId, null, 'org');
check('the insights page opens for the organizer', $res['status'] === 200, 'status=' . $res['status']);
check('the insights page shows the booth-count section', str_contains($res['body'], '1人あたりの周回企業数'));
check('the insights page shows the lap-time section', str_contains($res['body'], '1人あたりの周回時間'));
check('the insights page shows the visit order', str_contains($res['body'], '企業をまわる順番'));
check('the insights page shows the transitions', str_contains($res['body'], 'よくある動線'));
check('the insights page explains what lap time means', str_contains($res['body'], '滞在時間ではありません')
    || str_contains($res['body'], '含みません'));

$res = request('GET', '/admin/insights.php?event=' . $insightEventId, null, 'co');
check('a company user cannot open the insights page', $res['status'] === 404, 'status=' . $res['status']);

$res = request('GET', '/admin/insights.php?event=' . $insightEventId, null, 'rcp');
check('reception cannot open the insights page', $res['status'] === 404);

$res = request('GET', '/admin/index.php?event=' . $insightEventId, null, 'org');
check('the dashboard links to the insights page', str_contains($res['body'], 'insights.php'));
check('the dashboard shows the average lap time', str_contains($res['body'], '平均 周回時間'));

// 後片付け（このイベントぶんだけ消す）
$cleanup = db()->prepare('DELETE FROM events WHERE id = ?');
$cleanup->execute([$insightEventId]);

echo "=== closed event ===\n";

update_event($eventId, (string) $event['name'], (string) $event['start_date'], (string) $event['end_date'], 'closed');

$res = request('GET', $surveyPath);
check('closed event shows a closing message', str_contains($res['body'], '受付を終了'));

$res = request('POST', '/submit.php', [
    'survey_id'          => (string) $surveyAId,
    'q[' . $qSingle . ']' => '情報収集',
], 'visitor', ['Accept: application/json']);
check('closed event rejects new submissions', $res['status'] === 400);

echo "=== email purge ===\n";

$result = purge_emails($eventId);
check('emails are deleted after the campaign', $result['visitors'] === 1);
$emailStmt->execute([$eventId]);
check('no email remains for the event', (int) $emailStmt->fetchColumn() === 0);
check('responses survive the purge', count_responses($surveyAId) === 2);

echo "=== email registration from the code url ===\n";

// 別端末（Cookieなし）でコード付きURLを開いた人も、あとからメールを登録できる
$res = done_page('visitor2');
preg_match('/([2-9A-HJ-NP-Z]{4}-[2-9A-HJ-NP-Z]{4})/', strip_tags($res['body']), $codeMatch2);
$claimCode2 = $codeMatch2[1] ?? '';
check('the second visitor has its own claim code', $claimCode2 !== '' && $claimCode2 !== $claimCode);

$lateEmail = 'late+' . $stamp . '@example.jp';
$res = request('POST', '/done.php?e=' . $eventSlug . '&c=' . rawurlencode($claimCode2), [
    'c'     => $claimCode2,
    'email' => $lateEmail,
], 'nocookie');
check('email registered from the code url is accepted',
    $res['status'] === 200 && str_contains($res['body'], 'メールアドレスを登録しました'), 'status=' . $res['status']);

$claim2 = find_claim_by_code($claimCode2);
$visitor2Row = find_visitor((int) $claim2['visitor_id']);
check('the email lands on the right visitor', (string) ($visitor2Row['email'] ?? '') === $lateEmail);

echo "=== admin title ===\n";

$res = request('GET', '/admin/index.php', null, 'org');
check('the admin header shows the configured title', str_contains($res['body'], admin_title()));
check('the browser title uses the same name', str_contains($res['body'], '<title>') && str_contains($res['body'], admin_title()));

$res = request('GET', '/admin/login.php', null, 'nocookie');
check('the login page shows the configured title too', str_contains($res['body'], admin_title()));

echo "=== labels ===\n";

// 画面とメールの呼び名は .env（OVERALL_SURVEY_LABEL / WALLPAPER_LABEL）から来る
check('the survey label has a value', overall_label() !== '');
check('the wallpaper label has a value', wallpaper_label() !== '');

$mail = build_invite_mail($event, 'preview-' . $eventId);
check('the mail subject uses the survey label', str_contains($mail['subject'], overall_label()));
check('the mail subject uses the wallpaper label', str_contains($mail['subject'], wallpaper_label()));
check('the mail body uses both labels',
    str_contains($mail['body'], overall_label()) && str_contains($mail['body'], wallpaper_label()));

$res = done_page();
check('the done page uses the survey label', str_contains($res['body'], overall_label()));

$res = request('GET', '/admin/invites.php?event=' . $eventId, null, 'org');
check('the admin page uses the survey label', str_contains($res['body'], overall_label()));
check('the old wording is gone from the admin page', !str_contains($res['body'], '全体アンケート'));

echo "=== reset script ===\n";

// 初期化スクリプトは --event で範囲を限定できる。他のデータに触れないことを、
// このテスト専用のイベントで確かめる（開発DBの他のデータは消さない）。
$resetEventId = create_event('TEST RESET ' . $stamp, date('Y-m-d'), date('Y-m-d'), 'open');
$resetCompany = find_company(create_company($resetEventId, 'RESET CO ' . $stamp, null, null, null));
$resetSurvey  = ensure_company_survey($resetCompany);
replace_questions((int) $resetSurvey['id'], [
    ['id' => null, 'type' => 'single', 'label' => '設問', 'options' => ['はい', 'いいえ'], 'required' => false],
]);
update_survey((int) $resetSurvey['id'], 'RESET SURVEY', null, true);
$resetVisitor = find_or_create_visitor($resetEventId, str_repeat('a', 64));
insert_response((int) $resetSurvey['id'], (int) $resetVisitor['id'], []);
find_or_create_claim((int) $resetVisitor['id']);

$bash = trim((string) @shell_exec('bash --version 2>' . (DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null')));
if ($bash === '') {
    echo "[SKIP] bash が見つからないため、初期化スクリプトの確認は省略します\n";
} else {
    $script = str_replace('\\', '/', dirname(__DIR__)) . '/bin/reset.sh';

    // Windows の shell_exec は cmd.exe 経由で、"VAR=値 コマンド" の書き方が使えないため
    // 環境変数は putenv で渡す（子プロセスが引き継ぐ）
    putenv('MYSQL_BIN=' . (DIRECTORY_SEPARATOR === '\\' ? '/c/xampp/mysql/bin/mysql.exe' : 'mysql'));
    putenv('MYSQLDUMP_BIN=' . (DIRECTORY_SEPARATOR === '\\' ? '/c/xampp/mysql/bin/mysqldump.exe' : 'mysqldump'));

    $run = static function (string $options) use ($script): string {
        return (string) @shell_exec('bash ' . escapeshellarg($script) . ' ' . $options . ' 2>&1');
    };

    // --dry-run は件数を出すだけで、何も消さない
    $output = $run('--responses --event=' . $resetEventId . ' --dry-run');
    check('dry run reports the event it targets', str_contains($output, 'RESET CO ' . $stamp)
        || str_contains($output, 'TEST RESET ' . $stamp), mb_substr($output, 0, 120));
    check('dry run keeps the data', count_responses((int) $resetSurvey['id']) === 1);

    // --responses は回答と来場者だけを消し、イベント・企業・設問は残す
    $output = $run('--responses --event=' . $resetEventId . ' --force --yes --no-backup');
    check('responses reset removes the answers', count_responses((int) $resetSurvey['id']) === 0, mb_substr($output, 0, 160));
    check('responses reset removes the visitors and claims',
        find_visitor((int) $resetVisitor['id']) === null && find_claim_by_code($claimCode) !== null);
    check('responses reset keeps the event', find_event($resetEventId) !== null);
    check('responses reset keeps the company and questions',
        find_company((int) $resetCompany['id']) !== null && questions_for_survey((int) $resetSurvey['id']) !== []);
    check('responses reset leaves other events alone', count_responses($surveyAId) > 0);

    // --all は指定したイベントだけを丸ごと消す
    $output = $run('--all --event=' . $resetEventId . ' --force --yes --no-backup');
    check('full reset removes the event', find_event($resetEventId) === null, mb_substr($output, 0, 160));
    check('full reset removes its companies', find_company((int) $resetCompany['id']) === null);
    check('full reset leaves other events alone', find_event($eventId) !== null && count_responses($surveyAId) > 0);
}

// 消えていなければ後片付け（bash が無い環境でも残さない）
if (find_event($resetEventId) !== null) {
    $cleanup = db()->prepare('DELETE FROM events WHERE id = ?');
    $cleanup->execute([$resetEventId]);
}

echo "=== login rate limit ===\n";

db()->exec('DELETE FROM admin_login_attempts');
for ($i = 0; $i < 6; $i++) {
    $res = request('GET', '/admin/login.php', null, 'lock');
    $token = csrf_from($res['body']);
    $res = request('POST', '/admin/login.php', ['csrf_token' => $token, 'username' => $organizerName, 'password' => 'wrong'], 'lock');
}
check('repeated failures lock the ip out', str_contains($res['body'], 'しばらくお待ちください'));

db()->exec('DELETE FROM admin_login_attempts');

echo "\n";
echo "passed: {$passed}\n";
echo "failed: {$failed}\n";

exit($failed === 0 ? 0 : 1);
