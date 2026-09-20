<?php
declare(strict_types=1);

/**
 * 回答者傾向の画面を確認するためのダミー回答を作る（CLI専用・開発用）。
 *
 *   php bin/seed_traffic.php --force [--event=1] [--visitors=200]
 *
 * 来場者ごとに「入場時刻」を決め、近くのブースから順に回っていく動きを模した
 * 回答を作る（全員が同じ順番にならないよう、企業の並びに揺らぎを持たせる）。
 *
 * 本番のデータに混ざると集計が狂うため、開発用DBでのみ実行すること。
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

if (!isset($args['force'])) {
    echo "ダミーの来場者と回答を作ります。集計が変わるため、開発用DBでのみ実行してください：\n";
    echo "  php bin/seed_traffic.php --force [--event=1] [--visitors=200]\n";
    exit(1);
}

$event = isset($args['event']) ? find_event((int) $args['event']) : (all_events()[0] ?? null);
if ($event === null) {
    exit("イベントが見つかりません。先に bin/seed_demo.php を実行してください。\n");
}
$eventId  = (int) $event['id'];
$visitors = max(1, (int) ($args['visitors'] ?? '200'));

$companies = companies_for_event($eventId);
if ($companies === []) {
    exit("企業が登録されていません。\n");
}

/** 企業ごとのアンケートと設問を用意する */
$surveys = [];
foreach ($companies as $company) {
    $survey = survey_for_company((int) $company['id']);
    if ($survey === null || (int) $survey['is_published'] !== 1) {
        continue;
    }
    $questions = questions_for_survey((int) $survey['id']);
    if ($questions === []) {
        continue;
    }
    $surveys[(int) $company['id']] = ['survey' => $survey, 'questions' => $questions];
}

if ($surveys === []) {
    exit("公開済みのアンケートがありません。\n");
}

/** 設問に対するもっともらしい回答を作る */
function random_answer(array $question): ?string
{
    $options = question_options($question);

    return match ((string) $question['type']) {
        'single' => $options === [] ? null : $options[array_rand($options)],
        'multi'  => $options === [] ? null : json_encode(
            array_slice($options, 0, random_int(1, min(2, count($options)))),
            JSON_UNESCAPED_UNICODE
        ),
        // 評価は高めに寄せる（実際のアンケートに近い分布にする）
        'rating' => (string) min(RATING_MAX, random_int(3, RATING_MAX + 1)),
        'nps'    => (string) min(10, random_int(5, 11)),
        'text'   => random_int(1, 4) === 1 ? 'ダミーの自由記述です。' : null,
        default  => null,
    };
}

$companyIds = array_keys($surveys);
$base       = strtotime(date('Y-m-d') . ' 10:00:00');
$created    = 0;
$responses  = 0;

for ($i = 0; $i < $visitors; $i++) {
    // 入場時刻は10時〜16時に分散させる（昼過ぎを山にする）
    $enter = $base + (int) round((random_int(0, 100) + random_int(0, 100)) / 200 * 6 * 3600);

    $visitor = find_or_create_visitor($eventId, hash('sha256', 'traffic-' . $eventId . '-' . $i . '-' . random_int(1, 999999)));
    $created++;

    // 会場の並び順に沿って回るが、少し前後する
    $route = $companyIds;
    usort($route, static function (int $a, int $b) use ($companyIds): int {
        $pa = array_search($a, $companyIds, true) + random_int(-1, 1);
        $pb = array_search($b, $companyIds, true) + random_int(-1, 1);
        return $pa <=> $pb;
    });
    // 何社回るか（1社だけの人もいる）
    $route = array_slice($route, 0, random_int(1, count($route)));

    $at = $enter;
    foreach ($route as $companyId) {
        $questions = $surveys[$companyId]['questions'];
        $answers   = [];
        foreach ($questions as $question) {
            $value = random_answer($question);
            if ($value !== null) {
                $answers[(int) $question['id']] = $value;
            } elseif ((int) $question['required'] === 1) {
                $answers[(int) $question['id']] = question_options($question)[0] ?? '1';
            }
        }

        $result = insert_response((int) $surveys[$companyId]['survey']['id'], (int) $visitor['id'], $answers);
        $responses++;

        // 実際の時刻に置き換える（insert_response は現在時刻で記録するため）
        $stmt = db()->prepare('UPDATE responses SET submitted_at = ? WHERE id = ?');
        $stmt->execute([date('Y-m-d H:i:s', $at), $result['response_id']]);

        // 次のブースまで3〜25分
        $at += random_int(180, 1500);
    }

    // 3割の人はメールを登録し、2割は交換コードを発行済みにする
    if (random_int(1, 10) <= 3) {
        set_visitor_email((int) $visitor['id'], 'dummy' . $i . '@example.jp');
    }
    if (random_int(1, 10) <= 8) {
        find_or_create_claim((int) $visitor['id']);
    }
}

echo "ダミーデータを作成しました：来場者 {$created}人 / 回答 {$responses}件（{$event['name']}）\n";
echo "管理画面の「回答者傾向」で確認できます。\n";
