<?php
declare(strict_types=1);

/**
 * 回答送信の簡易負荷試験（CLI専用）。
 *
 *   php bin/bench.php --url=http://127.0.0.1:8080 --survey=2 --requests=300 --concurrency=20
 *
 * 想定規模（各社400名・全体で延べ3,000名）に耐えるかを、本番相当の環境で
 * 事前に確認するためのもの。テスト用の回答がDBに入るため、本番データが
 * 入る前か、投入後に削除できる環境で実行すること。
 *
 * 出力：成功数・失敗数・スループット・応答時間（平均/中央値/95パーセンタイル）
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

$baseUrl     = rtrim((string) ($args['url'] ?? 'http://127.0.0.1:8080'), '/');
$surveyId    = (int) ($args['survey'] ?? '0');
$requests    = max(1, (int) ($args['requests'] ?? '200'));
$concurrency = max(1, (int) ($args['concurrency'] ?? '20'));

if ($surveyId <= 0) {
    exit("--survey=<アンケートID> を指定してください（管理画面のURLで確認できます）。\n");
}

$survey = find_survey($surveyId);
if ($survey === null) {
    exit("アンケートが見つかりません：{$surveyId}\n");
}
$questions = questions_for_survey($surveyId);
if ($questions === []) {
    exit("設問が登録されていません。\n");
}

/** 設問に対する適当な回答を1つ作る */
function sample_answer(array $question): array
{
    $type    = (string) $question['type'];
    $name    = 'q[' . (int) $question['id'] . ']';
    $options = question_options($question);

    return match ($type) {
        'single' => $options === [] ? [] : [$name => $options[array_rand($options)]],
        'multi'  => $options === [] ? [] : [$name . '[]' => $options[array_rand($options)]],
        'rating' => [$name => (string) random_int(1, RATING_MAX)],
        'nps'    => [$name => (string) random_int(0, 10)],
        'text'   => [$name => '負荷試験の回答 ' . random_int(1, 999999)],
        default  => [],
    };
}

$body = static function () use ($surveyId, $questions): string {
    $fields = ['survey_id' => (string) $surveyId];
    foreach ($questions as $question) {
        $fields += sample_answer($question);
    }
    $parts = [];
    foreach ($fields as $name => $value) {
        $parts[] = rawurlencode($name) . '=' . rawurlencode((string) $value);
    }

    return implode('&', $parts);
};

echo "対象: {$baseUrl}/submit.php （survey_id={$surveyId}）\n";
echo "件数: {$requests}　同時接続: {$concurrency}\n\n";

$multi    = curl_multi_init();
$active   = 0;
$sent     = 0;
$done     = 0;
$ok       = 0;
$ng       = 0;
$times    = [];
$handles  = [];
$started  = microtime(true);

$addHandle = static function () use ($multi, $baseUrl, $body, &$handles, &$sent, $requests): bool {
    if ($sent >= $requests) {
        return false;
    }
    $sent++;
    $ch = curl_init($baseUrl . '/submit.php');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body(),
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        CURLOPT_TIMEOUT        => 30,
        // 来場者ごとに別端末として扱わせる（Cookieを共有しない）
        CURLOPT_COOKIEFILE     => '',
    ]);
    curl_multi_add_handle($multi, $ch);
    $handles[(int) $ch] = microtime(true);

    return true;
};

for ($i = 0; $i < $concurrency; $i++) {
    $addHandle();
}

do {
    curl_multi_exec($multi, $active);
    curl_multi_select($multi, 0.1);

    while (($info = curl_multi_info_read($multi)) !== false) {
        $ch     = $info['handle'];
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $times[] = (microtime(true) - $handles[(int) $ch]) * 1000;
        unset($handles[(int) $ch]);

        if ($status === 200) {
            $ok++;
        } else {
            $ng++;
        }
        $done++;

        curl_multi_remove_handle($multi, $ch);
        curl_close($ch);
        $addHandle();

        if ($done % 50 === 0) {
            echo "  {$done}/{$requests} 完了\n";
        }
    }
} while ($active > 0 || $handles !== []);

curl_multi_close($multi);

$elapsed = microtime(true) - $started;
sort($times);
$avg = $times === [] ? 0 : array_sum($times) / count($times);
$p50 = $times === [] ? 0 : $times[(int) floor(count($times) * 0.5)];
$p95 = $times === [] ? 0 : $times[min(count($times) - 1, (int) floor(count($times) * 0.95))];

printf("\n所要時間   : %.1f 秒\n", $elapsed);
printf("スループット: %.1f 件/秒\n", $done / max(0.001, $elapsed));
printf("成功 / 失敗 : %d / %d\n", $ok, $ng);
printf("応答時間   : 平均 %.0f ms／中央値 %.0f ms／95%% %.0f ms\n", $avg, $p50, $p95);

if ($ng > 0) {
    echo "\n失敗が出ています。php-fpm のプロセス数、MariaDB の max_connections、\n";
    echo "PHPのエラーログ（logs/app-error.log）を確認してください。\n";
}
