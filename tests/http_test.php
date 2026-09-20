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

$res = request('GET', '/done.php?e=' . $eventSlug);
check('done page returns 200', $res['status'] === 200);
check('done page shows the visited booth', str_contains($res['body'], 'TEST CO A'));
check('done page shows a claim code',
    preg_match('/[2-9A-HJ-NP-Z]{4}-[2-9A-HJ-NP-Z]{4}/', strip_tags($res['body'])) === 1);
preg_match('/([2-9A-HJ-NP-Z]{4}-[2-9A-HJ-NP-Z]{4})/', strip_tags($res['body']), $codeMatch);
$claimCode = $codeMatch[1] ?? '';
check('claim code is not re-issued on reload', (function () use ($eventSlug, $claimCode): bool {
    $again = request('GET', '/done.php?e=' . $eventSlug);
    return str_contains($again['body'], $claimCode);
})());

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

echo "=== prize claim ===\n";

$res = request('GET', '/admin/login.php', null, 'rcp');
$token = csrf_from($res['body']);
request('POST', '/admin/login.php', ['csrf_token' => $token, 'username' => $receptionName, 'password' => $password], 'rcp');

$res = request('GET', '/admin/companies.php', null, 'rcp');
check('reception cannot open company management', $res['status'] === 404);

$res = request('GET', '/admin/claim.php?code=' . rawurlencode($claimCode), null, 'rcp');
check('reception can look up a claim code', $res['status'] === 200 && str_contains($res['body'], $claimCode));
check('unclaimed code is shown as not yet exchanged', str_contains($res['body'], '未交換'));

$token = csrf_from($res['body']);
$claim = find_claim_by_code($claimCode);
$res = request('POST', '/admin/claim.php', [
    'csrf_token' => $token,
    'action'     => 'mark',
    'code'       => $claimCode,
    'claim_id'   => (string) $claim['id'],
    'note'       => 'テスト景品',
], 'rcp');
check('marking as claimed redirects back', $res['status'] === 302);

$res = request('GET', '/admin/claim.php?code=' . rawurlencode($claimCode), null, 'rcp');
check('claimed code shows the exchange time', str_contains($res['body'], '交換済み'));
check('claim history records the staff name', str_contains($res['body'], 'TEST 受付'));

$claim = find_claim_by_code($claimCode);
check('claimed_at is stored once', ($claim['claimed_at'] ?? null) !== null);
check('second claim attempt does not overwrite the record', !mark_claimed((int) $claim['id'], 'x', null));

// 来場者側には「交換済み」と表示しない
$res = request('GET', '/done.php?e=' . $eventSlug);
check('visitor page does not reveal the claimed state',
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
