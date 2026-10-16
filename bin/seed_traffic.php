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
 * 来場人数は来場者ごとに1つ決め、どの企業でも同じ人数を答えたことにする。
 * 総合アンケートにも7割ほどが答えたことにし、グループの内訳（区分ごとの人数）は
 * その人数を割り振って作る（1割ほどはわざと合計をずらし、照合の画面を確かめられるようにする）。
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

/** 総合アンケート（会期中に各社のアンケートに混ぜて出しているぶん） */
$overall = overall_survey($eventId);
if ($overall !== null && (int) $overall['is_published'] !== 1) {
    $overall = null;
}
$overallQuestions = $overall === null ? [] : questions_for_survey((int) $overall['id']);
$groupQuestions   = array_values(array_filter($overallQuestions, 'is_group_member_question'));

/**
 * 設問に対するもっともらしい回答を作る。
 *
 * 来場人数（party_size）は来場者ごとに決めた人数で揃える。グループの内訳
 * （group_member）はここでは作らず、group_breakdown() でまとめて割り振る。
 */
function random_answer(array $question, int $party = 1): ?string
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
        'number' => is_party_size_question($question) ? (string) $party : null,
        default  => null,
    };
}

/**
 * 来場人数を区分ごとに割り振る（1つ目の区分を大人に見立て、残りを散らす）。
 *
 * 1割ほどは合計をわざと1人ずらす。実際のアンケートでも「本人を数えたか」などで
 * 来場人数と内訳の合計は食い違うため、照合の画面を確かめられるようにしておく。
 *
 * @param list<array<string,mixed>> $questions グループの内訳の設問（並び順）
 * @return array<int,string> question_id => 人数
 */
function group_breakdown(array $questions, int $party): array
{
    if ($questions === []) {
        return [];
    }
    $last      = count($questions) - 1;
    $counts    = array_fill(0, count($questions), 0);
    $counts[0] = min($party, random_int(1, 2));
    for ($i = $party - $counts[0]; $i > 0; $i--) {
        $counts[random_int(0, $last)]++;
    }
    if (random_int(1, 100) <= 11) {
        $index          = random_int(0, $last);
        $counts[$index] = max(0, $counts[$index] + (random_int(0, 1) === 0 ? 1 : -1));
    }

    $answers = [];
    foreach ($questions as $index => $question) {
        $answers[(int) $question['id']] = (string) $counts[$index];
    }

    return $answers;
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

    // 来場人数は1人で来た方が多め。どの企業でも同じ人数を答えたことにする
    $roll  = random_int(1, 100);
    $party = $roll <= 35 ? 1 : ($roll <= 65 ? 2 : ($roll <= 85 ? 3 : 4));

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
            $value = random_answer($question, $party);
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

    // 7割ほどは総合アンケートにも答える（会期中に各社のアンケートに混ぜて出しているぶん）
    if ($overall !== null && random_int(1, 10) <= 7) {
        $answers = group_breakdown($groupQuestions, $party);
        foreach ($overallQuestions as $question) {
            if (is_group_member_question($question)) {
                continue;
            }
            $value = random_answer($question, $party);
            if ($value !== null) {
                $answers[(int) $question['id']] = $value;
            }
        }
        $result = insert_response((int) $overall['id'], (int) $visitor['id'], $answers);
        $responses++;

        $stmt = db()->prepare('UPDATE responses SET submitted_at = ? WHERE id = ?');
        $stmt->execute([date('Y-m-d H:i:s', $at + random_int(60, 900)), $result['response_id']]);
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
