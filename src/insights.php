<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/repository.php';

/**
 * 回答者の傾向の集計。
 *
 * 数え方の前提（画面にも注記する）：
 *   - 来場者は端末の匿名Cookie単位。端末を変えた人は別人として数える
 *   - 同じ企業への2回目の送信は書き換えになる（行は増えない）。古いデータの重複は数えない
 *   - 「周回時間」は最初の回答から最後の回答までの間隔であって、滞在時間ではない
 *   - 1社しか回っていない人は周回時間が0になるため、時間の統計からは除く
 */

/**
 * 来場者ごとの周回（回った企業数・最初と最後の回答時刻・その間隔）。
 *
 * この1本を使い回して、企業数・周回時間・満足度との関係を計算する。
 *
 * @return list<array{visitor_id: int, companies: int, span_seconds: int, first_at: string, last_at: string}>
 */
function visitor_laps(int $eventId): array
{
    $stmt = db()->prepare(
        "SELECT r.visitor_id,
                COUNT(DISTINCT s.company_id) AS companies,
                MIN(r.submitted_at) AS first_at,
                MAX(r.submitted_at) AS last_at,
                TIMESTAMPDIFF(SECOND, MIN(r.submitted_at), MAX(r.submitted_at)) AS span_seconds
         FROM responses r
         JOIN surveys s ON s.id = r.survey_id
         WHERE s.event_id = ? AND s.type = 'company' AND r.is_duplicate = 0
         GROUP BY r.visitor_id"
    );
    $stmt->execute([$eventId]);

    $laps = [];
    foreach ($stmt->fetchAll() as $row) {
        $laps[] = [
            'visitor_id'   => (int) $row['visitor_id'],
            'companies'    => (int) $row['companies'],
            'span_seconds' => (int) $row['span_seconds'],
            'first_at'     => (string) $row['first_at'],
            'last_at'      => (string) $row['last_at'],
        ];
    }

    return $laps;
}

/**
 * 数値の代表値。
 *
 * @param list<int|float> $values
 * @return array{count: int, avg: float, median: float, min: float, max: float}
 */
function number_summary(array $values): array
{
    if ($values === []) {
        return ['count' => 0, 'avg' => 0.0, 'median' => 0.0, 'min' => 0.0, 'max' => 0.0];
    }
    sort($values);
    $count  = count($values);
    $middle = intdiv($count, 2);
    $median = $count % 2 === 1 ? $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2;

    return [
        'count'  => $count,
        'avg'    => round(array_sum($values) / $count, 2),
        'median' => round((float) $median, 2),
        'min'    => (float) $values[0],
        'max'    => (float) $values[$count - 1],
    ];
}

/**
 * 周回時間の統計（2社以上回った人のみ）。
 *
 * @param list<array<string,mixed>> $laps visitor_laps() の結果
 * @return array{summary: array{count: int, avg: float, median: float, min: float, max: float},
 *               single_company_visitors: int, distribution: array<string,int>}
 */
function lap_time_stats(array $laps): array
{
    $spans  = [];
    $single = 0;
    foreach ($laps as $lap) {
        if ((int) $lap['companies'] < 2) {
            $single++;
            continue;
        }
        $spans[] = (int) $lap['span_seconds'];
    }

    // 分布（何分かかったか）
    $buckets = [
        '15分未満'      => 0,
        '15〜30分'      => 0,
        '30分〜1時間'   => 0,
        '1〜2時間'      => 0,
        '2時間以上'     => 0,
    ];
    foreach ($spans as $span) {
        $minutes = $span / 60;
        if ($minutes < 15) {
            $buckets['15分未満']++;
        } elseif ($minutes < 30) {
            $buckets['15〜30分']++;
        } elseif ($minutes < 60) {
            $buckets['30分〜1時間']++;
        } elseif ($minutes < 120) {
            $buckets['1〜2時間']++;
        } else {
            $buckets['2時間以上']++;
        }
    }

    return [
        'summary'                 => number_summary($spans),
        'single_company_visitors' => $single,
        'distribution'            => $buckets,
    ];
}

/**
 * 周回企業数の統計。
 *
 * @param list<array<string,mixed>> $laps
 * @return array{summary: array{count: int, avg: float, median: float, min: float, max: float},
 *               distribution: array<string,int>}
 */
function lap_company_stats(array $laps): array
{
    $counts = [];
    foreach ($laps as $lap) {
        $counts[] = (int) $lap['companies'];
    }

    $distribution = [];
    $max = $counts === [] ? 0 : max($counts);
    for ($i = 1; $i <= max(1, $max); $i++) {
        $distribution[$i . '社'] = 0;
    }
    foreach ($counts as $count) {
        $distribution[$count . '社']++;
    }

    return ['summary' => number_summary($counts), 'distribution' => $distribution];
}

/**
 * 企業ごとの「何番目に回られたか」。
 *
 * @return list<array{company_id: int, name: string, visits: int, avg_position: float, first_visits: int}>
 */
function company_visit_order(int $eventId): array
{
    $stmt = db()->prepare(
        "SELECT t.company_id, c.name,
                COUNT(*) AS visits,
                AVG(t.seq) AS avg_position,
                SUM(CASE WHEN t.seq = 1 THEN 1 ELSE 0 END) AS first_visits
         FROM (
            SELECT s.company_id,
                   ROW_NUMBER() OVER (PARTITION BY r.visitor_id ORDER BY r.submitted_at, r.id) AS seq
            FROM responses r
            JOIN surveys s ON s.id = r.survey_id
            WHERE s.event_id = ? AND s.type = 'company' AND r.is_duplicate = 0
         ) AS t
         JOIN companies c ON c.id = t.company_id
         GROUP BY t.company_id, c.name
         ORDER BY avg_position, visits DESC"
    );
    $stmt->execute([$eventId]);

    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
        $rows[] = [
            'company_id'   => (int) $row['company_id'],
            'name'         => (string) $row['name'],
            'visits'       => (int) $row['visits'],
            'avg_position' => round((float) $row['avg_position'], 2),
            'first_visits' => (int) $row['first_visits'],
        ];
    }

    return $rows;
}

/**
 * よくある動線（A社の次にB社を回った回数）の上位。
 *
 * @return list<array{from: string, to: string, count: int}>
 */
function company_transitions(int $eventId, int $limit = 20): array
{
    $stmt = db()->prepare(
        "SELECT cf.name AS from_name, ct.name AS to_name, COUNT(*) AS n
         FROM (
            SELECT s.company_id,
                   LAG(s.company_id) OVER (PARTITION BY r.visitor_id ORDER BY r.submitted_at, r.id) AS prev_company
            FROM responses r
            JOIN surveys s ON s.id = r.survey_id
            WHERE s.event_id = ? AND s.type = 'company' AND r.is_duplicate = 0
         ) AS t
         JOIN companies cf ON cf.id = t.prev_company
         JOIN companies ct ON ct.id = t.company_id
         WHERE t.prev_company IS NOT NULL
         GROUP BY cf.name, ct.name
         ORDER BY n DESC, cf.name, ct.name
         LIMIT " . max(1, $limit)
    );
    $stmt->execute([$eventId]);

    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
        $rows[] = [
            'from'  => (string) $row['from_name'],
            'to'    => (string) $row['to_name'],
            'count' => (int) $row['n'],
        ];
    }

    return $rows;
}

/**
 * ブース間の移動間隔（連続した回答の間隔）。会場の混み具合の目安。
 *
 * 途中で会場を離れた人などの極端な値が混じるため、中央値を主に見る。
 *
 * @return array{count: int, avg: float, median: float, min: float, max: float}
 */
function move_interval_stats(int $eventId): array
{
    $stmt = db()->prepare(
        "SELECT TIMESTAMPDIFF(SECOND, t.prev_at, t.submitted_at) AS gap
         FROM (
            SELECT r.submitted_at,
                   LAG(r.submitted_at) OVER (PARTITION BY r.visitor_id ORDER BY r.submitted_at, r.id) AS prev_at
            FROM responses r
            JOIN surveys s ON s.id = r.survey_id
            WHERE s.event_id = ? AND s.type = 'company' AND r.is_duplicate = 0
         ) AS t
         WHERE t.prev_at IS NOT NULL"
    );
    $stmt->execute([$eventId]);

    $gaps = [];
    foreach ($stmt->fetchAll() as $row) {
        $gaps[] = (int) $row['gap'];
    }

    return number_summary($gaps);
}

/**
 * 時間帯別のユニーク来場者数（回答数ではなく人数）。
 *
 * @return array<string,int>
 */
function hourly_unique_visitors(int $eventId): array
{
    $stmt = db()->prepare(
        "SELECT DATE_FORMAT(r.submitted_at, '%m/%d %H') AS bucket,
                COUNT(DISTINCT r.visitor_id) AS n
         FROM responses r
         JOIN surveys s ON s.id = r.survey_id
         WHERE s.event_id = ? AND s.type = 'company' AND r.is_duplicate = 0
         GROUP BY bucket ORDER BY bucket"
    );
    $stmt->execute([$eventId]);

    $series = [];
    foreach ($stmt->fetchAll() as $row) {
        $series[(string) $row['bucket'] . '時'] = (int) $row['n'];
    }

    return $series;
}

/**
 * 周回企業数と評価の関係。
 *
 * 評価（星）とNPSの回答を、その人が回った企業数の区分ごとに平均する。
 * $surveyType で、企業アンケート（company）と総合アンケート（overall）を切り替える。
 * 相関であって因果ではない（たくさん回った人は元々関心が高い、という説明もつく）。
 *
 * @param list<array<string,mixed>> $laps
 * @return list<array{label: string, visitors: int, rating_avg: ?float, nps_score: ?int, answers: int}>
 */
function satisfaction_by_lap_count(int $eventId, array $laps, string $surveyType = 'company'): array
{
    $bucketOf = static function (int $companies): string {
        if ($companies <= 1) {
            return '1社';
        }
        if ($companies <= 3) {
            return '2〜3社';
        }

        return '4社以上';
    };

    $bucketByVisitor = [];
    $visitorsInBucket = ['1社' => 0, '2〜3社' => 0, '4社以上' => 0];
    foreach ($laps as $lap) {
        $bucket = $bucketOf((int) $lap['companies']);
        $bucketByVisitor[(int) $lap['visitor_id']] = $bucket;
        $visitorsInBucket[$bucket]++;
    }

    $stmt = db()->prepare(
        "SELECT r.visitor_id, q.type, a.value
         FROM answers a
         JOIN questions q ON q.id = a.question_id
         JOIN responses r ON r.id = a.response_id
         JOIN surveys s   ON s.id = r.survey_id
         WHERE s.event_id = ? AND s.type = ? AND r.is_duplicate = 0
           AND q.type IN ('rating', 'nps') AND a.value IS NOT NULL"
    );
    $stmt->execute([$eventId, $surveyType]);

    $ratings = ['1社' => [], '2〜3社' => [], '4社以上' => []];
    $nps     = ['1社' => [], '2〜3社' => [], '4社以上' => []];
    foreach ($stmt->fetchAll() as $row) {
        $bucket = $bucketByVisitor[(int) $row['visitor_id']] ?? null;
        if ($bucket === null) {
            continue;
        }
        if ((string) $row['type'] === 'rating') {
            $ratings[$bucket][] = (int) $row['value'];
        } else {
            $nps[$bucket][] = (int) $row['value'];
        }
    }

    $rows = [];
    foreach (['1社', '2〜3社', '4社以上'] as $bucket) {
        $npsCounts = [];
        foreach ($nps[$bucket] as $value) {
            $npsCounts[(string) $value] = ($npsCounts[(string) $value] ?? 0) + 1;
        }

        $rows[] = [
            'label'      => $bucket,
            'visitors'   => $visitorsInBucket[$bucket],
            'rating_avg' => $ratings[$bucket] === []
                ? null
                : round(array_sum($ratings[$bucket]) / count($ratings[$bucket]), 2),
            'nps_score'  => $nps[$bucket] === [] ? null : nps_breakdown($npsCounts)['score'],
            'answers'    => count($ratings[$bucket]) + count($nps[$bucket]),
        ];
    }

    return $rows;
}

/**
 * 来場人数と評価の関係。
 *
 * 「何人で来られましたか」の回答で来場者を分け、星評価の平均を出す。
 * 1人で来た方とグループで来た方で感じ方が違うか、の目安にする。
 * 人数を答えていない方は数えない（推計で補うと読み違いのもとになるため）。
 *
 * @return list<array{label: string, visitors: int, rating_avg: ?float, answers: int}>
 */
function rating_by_party_size(int $eventId, string $surveyType = 'overall'): array
{
    // 来場者ごとの人数（複数回答えていれば小さいほうを使う）
    $stmt = db()->prepare(
        "SELECT r.visitor_id, MIN(CAST(a.value AS UNSIGNED)) AS party
         FROM responses r
         JOIN surveys s   ON s.id = r.survey_id
         JOIN questions q ON q.survey_id = s.id AND q.metric = 'party_size'
         JOIN answers a   ON a.response_id = r.id AND a.question_id = q.id
         WHERE s.event_id = ? AND r.is_duplicate = 0 AND a.value IS NOT NULL
         GROUP BY r.visitor_id"
    );
    $stmt->execute([$eventId]);

    $bucketByVisitor = [];
    $visitors        = ['1人' => 0, '2人' => 0, '3人以上' => 0];
    foreach ($stmt->fetchAll() as $row) {
        $party  = (int) $row['party'];
        $bucket = $party <= 1 ? '1人' : ($party === 2 ? '2人' : '3人以上');
        $bucketByVisitor[(int) $row['visitor_id']] = $bucket;
        $visitors[$bucket]++;
    }

    if ($bucketByVisitor === []) {
        return [];
    }

    $stmt = db()->prepare(
        "SELECT r.visitor_id, a.value
         FROM answers a
         JOIN questions q ON q.id = a.question_id
         JOIN responses r ON r.id = a.response_id
         JOIN surveys s   ON s.id = r.survey_id
         WHERE s.event_id = ? AND s.type = ? AND r.is_duplicate = 0
           AND q.type = 'rating' AND a.value IS NOT NULL"
    );
    $stmt->execute([$eventId, $surveyType]);

    $ratings = ['1人' => [], '2人' => [], '3人以上' => []];
    foreach ($stmt->fetchAll() as $row) {
        $bucket = $bucketByVisitor[(int) $row['visitor_id']] ?? null;
        if ($bucket !== null) {
            $ratings[$bucket][] = (int) $row['value'];
        }
    }

    $rows = [];
    foreach (['1人', '2人', '3人以上'] as $bucket) {
        $values = $ratings[$bucket];
        $rows[] = [
            'label'      => $bucket,
            'visitors'   => $visitors[$bucket],
            'rating_avg' => $values === [] ? null : round(array_sum($values) / count($values), 2),
            'answers'    => count($values),
        ];
    }

    return $rows;
}

/**
 * 回答・登録まわりの割合。
 *
 * @return array{opened: int, responded: int, response_rate: float, edited_rate: float,
 *               email_rate: float, overall_rate: float, edited_rate: float, claim_rate: float}
 */
function participation_rates(int $eventId): array
{
    $summary = event_summary($eventId);
    $invites = invite_stats($eventId);

    // 来場者があとから書き直した回答の割合（同じ端末からの書き換え）
    $stmt = db()->prepare(
        "SELECT COALESCE(SUM(r.updated_at IS NOT NULL), 0) AS edited, COUNT(*) AS total
         FROM responses r JOIN surveys s ON s.id = r.survey_id
         WHERE s.event_id = ? AND s.type = 'company' AND r.is_duplicate = 0"
    );
    $stmt->execute([$eventId]);
    $row = $stmt->fetch() ?: ['edited' => 0, 'total' => 0];

    return [
        'opened'         => $summary['visitors'],
        'responded'      => $summary['responding_visitors'],
        // アンケート画面を開いた端末のうち、実際に送信した割合
        'response_rate'  => percentage($summary['responding_visitors'], $summary['visitors']),
        'edited_rate'    => percentage((int) $row['edited'], (int) $row['total']),
        'email_rate'     => percentage($summary['emails'], $summary['responding_visitors']),
        // 回答した来場者のうち、共通アンケートにも答えた割合。
        // 会期中にブースのアンケートへ混ぜて集めるため、分母は案内メールではなく回答者。
        'overall_rate'   => percentage($summary['overall_responses'], $summary['responding_visitors']),
        'claim_rate'     => percentage($summary['claimed'], $summary['claims']),
    ];
}

/**
 * 来場人数（「何人で来られましたか」の回答）の集計。
 *
 * 任意回答のため、未回答ぶんは次の順で値を補う：
 *   1. その回答自身の人数
 *   2. 同じ来場者が別の企業で答えた人数（持ち越し）
 *   3. それも無ければ、回答があったぶんの平均
 *
 * のべ来場者は「回答1件ごとの人数」を足したもの（1組が3社回れば3回数える）。
 * 実来場者は「来場者ごとに1回だけ」足したもの。
 *
 * @return array{
 *   configured: bool, responses: int, answered: int, answer_rate: float,
 *   average: float, total_visits: int, unique_people: int,
 *   answered_visitors: int, visitors: int, distribution: array<string,int>
 * }
 */
function party_size_stats(int $eventId): array
{
    $empty = [
        'configured' => party_size_questions($eventId) !== [],
        'responses' => 0, 'answered' => 0, 'answer_rate' => 0.0, 'average' => 0.0,
        'total_visits' => 0, 'unique_people' => 0, 'answered_visitors' => 0, 'visitors' => 0,
        'distribution' => [],
    ];

    // 企業アンケートの有効回答を、来場人数の回答とあわせて取り出す
    $stmt = db()->prepare(
        "SELECT r.id AS response_id, r.visitor_id, a.value
         FROM responses r
         JOIN surveys s ON s.id = r.survey_id
         LEFT JOIN questions q ON q.survey_id = s.id AND q.metric = 'party_size'
         LEFT JOIN answers a  ON a.response_id = r.id AND a.question_id = q.id
         WHERE s.event_id = ? AND s.type = 'company' AND r.is_duplicate = 0"
    );
    $stmt->execute([$eventId]);
    $rows = $stmt->fetchAll();

    if ($rows === []) {
        return $empty;
    }

    // 1. 回答があったものを集める（来場者ごとの持ち越し値も作る）
    $values       = [];
    $byVisitor    = [];
    $answered     = 0;
    $distribution = [];
    foreach ($rows as $row) {
        $visitorId = (int) $row['visitor_id'];
        $value     = $row['value'] === null || $row['value'] === '' ? null : (int) $row['value'];
        $values[]  = ['visitor_id' => $visitorId, 'value' => $value];
        if ($value === null) {
            continue;
        }
        $answered++;
        $byVisitor[$visitorId] ??= $value;
        $key = $value . '人';
        $distribution[$key] = ($distribution[$key] ?? 0) + 1;
    }

    $responses = count($values);
    if ($answered === 0) {
        $empty['responses'] = $responses;
        $empty['visitors']  = count(array_unique(array_column($values, 'visitor_id')));
        return $empty;
    }

    // 2. 回答があったぶんの平均（未回答を埋めるのに使う）
    $sumAnswered = 0;
    foreach ($values as $row) {
        if ($row['value'] !== null) {
            $sumAnswered += $row['value'];
        }
    }
    $average = $sumAnswered / $answered;

    // 3. のべ来場者：回答ごとに、自分の回答 → 持ち越し → 平均 の順で当てはめる
    $totalVisits = 0.0;
    foreach ($values as $row) {
        $totalVisits += $row['value'] ?? ($byVisitor[$row['visitor_id']] ?? $average);
    }

    // 4. 実来場者：来場者ごとに1回だけ数える
    $visitorIds   = array_values(array_unique(array_column($values, 'visitor_id')));
    $uniquePeople = 0.0;
    foreach ($visitorIds as $visitorId) {
        $uniquePeople += $byVisitor[$visitorId] ?? $average;
    }

    ksort($distribution, SORT_NATURAL);

    return [
        'configured'        => true,
        'responses'         => $responses,
        'answered'          => $answered,
        'answer_rate'       => percentage($answered, $responses),
        'average'           => round($average, 2),
        'total_visits'      => (int) round($totalVisits),
        'unique_people'     => (int) round($uniquePeople),
        'answered_visitors' => count($byVisitor),
        'visitors'          => count($visitorIds),
        'distribution'      => $distribution,
    ];
}

/** グループの型として並べる上限（これより下は「その他」にまとめる） */
const GROUP_PATTERN_LIMIT = 8;

/** 「◯◯のみ」の行を出す最小グループ数（母数が小さい行は読み違いのもとになるため） */
const GROUP_ONLY_MIN = 3;

/**
 * グループの構成（区分ごとの人数）の集計。
 *
 * 「小学生 ◯人」「大人 ◯人」のように区分ごとの人数を聞く設問（metric = group_member）を、
 * 1件の回答＝1グループとして読む。来場人数（metric = party_size）とは別の集計で、
 * のべ来場者には足さない（同じ人数を二重に数えないため）。
 *
 * 数え方の前提（画面にも注記する）：
 *   - 母数はこのアンケートに答えたグループだけで、会場全体ではない
 *   - どの区分にも人数を入れなかった回答は数えない
 *   - 来場人数との差は、本人を含めたかどうかなど受け取り方の違いで普通に出る
 *
 * @param list<array<string,mixed>>|null $laps visitor_laps() の結果（周回企業数との関係に使う）
 * @return array{
 *   configured: bool, responses: int, groups: int, answer_rate: float, people: int, avg_size: float,
 *   categories: list<array{label: string, people: int, share: float, groups: int, group_share: float, avg: float}>,
 *   patterns: list<array{label: string, groups: int, share: float}>,
 *   segments: list<array{label: string, groups: int, share: float, laps_avg: ?float, rating_avg: ?float}>,
 *   check: array{compared: int, matched: int, match_rate: float, distribution: array<string,int>}
 * }
 */
function group_member_stats(int $eventId, int $surveyId, ?array $laps = null): array
{
    $stats = [
        'configured'  => false,
        'responses'   => 0,
        'groups'      => 0,
        'answer_rate' => 0.0,
        'people'      => 0,
        'avg_size'    => 0.0,
        'categories'  => [],
        'patterns'    => [],
        'segments'    => [],
        'check'       => ['compared' => 0, 'matched' => 0, 'match_rate' => 0.0, 'distribution' => []],
    ];

    $stmt = db()->prepare(
        "SELECT * FROM questions
         WHERE survey_id = ? AND metric = 'group_member'
         ORDER BY sort_order, id"
    );
    $stmt->execute([$surveyId]);
    $questions = $stmt->fetchAll();
    if ($questions === []) {
        return $stats;
    }
    $stats['configured'] = true;

    $stmt = db()->prepare('SELECT COUNT(*) FROM responses WHERE survey_id = ? AND is_duplicate = 0');
    $stmt->execute([$surveyId]);
    $stats['responses'] = (int) $stmt->fetchColumn();

    // 1件の回答＝1グループとして、区分別の人数を集める
    $stmt = db()->prepare(
        "SELECT r.id, r.visitor_id, a.question_id, a.value
         FROM responses r
         JOIN answers a   ON a.response_id = r.id
         JOIN questions q ON q.id = a.question_id AND q.metric = 'group_member'
         WHERE r.survey_id = ? AND r.is_duplicate = 0
           AND a.value IS NOT NULL AND a.value <> ''"
    );
    $stmt->execute([$surveyId]);

    $groups = [];
    foreach ($stmt->fetchAll() as $row) {
        $responseId = (int) $row['id'];
        $groups[$responseId]['visitor'] = (int) $row['visitor_id'];
        $groups[$responseId]['counts'][(int) $row['question_id']] = max(0, (int) $row['value']);
    }
    if ($groups === []) {
        return $stats;
    }

    $byCategory = [];
    foreach ($questions as $question) {
        $byCategory[(int) $question['id']] = [
            'label'  => (string) $question['label'],
            'unit'   => number_settings($question)['unit'],
            'people' => 0,
            'groups' => 0,
        ];
    }

    // 区分別の人数と、グループの型（0人の区分は書かない）
    $groupCount    = count($groups);
    $totalPeople   = 0;
    $patternCounts = [];
    $includes      = [];
    $onlys         = [];
    foreach ($groups as $group) {
        $size    = 0;
        $parts   = [];
        $present = [];
        foreach ($byCategory as $questionId => $info) {
            $count = $group['counts'][$questionId] ?? 0;
            $size += $count;
            if ($count > 0) {
                $byCategory[$questionId]['people'] += $count;
                $byCategory[$questionId]['groups']++;
                $parts[]   = $info['label'] . $count . $info['unit'];
                $present[] = $questionId;
            }
        }
        $totalPeople += $size;

        $label = $parts === [] ? '（どの区分も0人）' : implode('・', $parts);
        $patternCounts[$label] = ($patternCounts[$label] ?? 0) + 1;

        foreach ($present as $questionId) {
            $includes[$questionId][] = $group['visitor'];
        }
        if (count($present) === 1) {
            $onlys[$present[0]][] = $group['visitor'];
        }
    }

    $stats['groups']      = $groupCount;
    $stats['answer_rate'] = percentage($groupCount, $stats['responses']);
    $stats['people']      = $totalPeople;
    $stats['avg_size']    = round($totalPeople / $groupCount, 2);

    foreach ($byCategory as $info) {
        $stats['categories'][] = [
            'label'       => $info['label'],
            'people'      => $info['people'],
            'share'       => percentage($info['people'], $totalPeople),
            'groups'      => $info['groups'],
            'group_share' => percentage($info['groups'], $groupCount),
            'avg'         => round($info['people'] / $groupCount, 2),
        ];
    }

    arsort($patternCounts);
    $rest = 0;
    foreach ($patternCounts as $label => $count) {
        if (count($stats['patterns']) < GROUP_PATTERN_LIMIT) {
            $stats['patterns'][] = [
                'label'  => (string) $label,
                'groups' => $count,
                'share'  => percentage($count, $groupCount),
            ];
            continue;
        }
        $rest++;
    }
    if ($rest > 0) {
        $others = (int) array_sum(array_slice(array_values($patternCounts), GROUP_PATTERN_LIMIT));
        $stats['patterns'][] = [
            'label'  => 'その他（' . $rest . '通り）',
            'groups' => $others,
            'share'  => percentage($others, $groupCount),
        ];
    }

    // 周回企業数・評価との関係は「相関」として見る（因果ではない）
    $laps ??= visitor_laps($eventId);
    $companies = [];
    foreach ($laps as $lap) {
        $companies[(int) $lap['visitor_id']] = (int) $lap['companies'];
    }

    $stmt = db()->prepare(
        "SELECT r.visitor_id, AVG(CAST(a.value AS DECIMAL(10,4))) AS rating
         FROM answers a
         JOIN questions q ON q.id = a.question_id AND q.type = 'rating'
         JOIN responses r ON r.id = a.response_id
         WHERE r.survey_id = ? AND r.is_duplicate = 0 AND a.value IS NOT NULL AND a.value <> ''
         GROUP BY r.visitor_id"
    );
    $stmt->execute([$surveyId]);
    $ratings = [];
    foreach ($stmt->fetchAll() as $row) {
        $ratings[(int) $row['visitor_id']] = (float) $row['rating'];
    }

    $segment = static function (string $label, array $visitorIds) use ($companies, $ratings, $groupCount): array {
        $lapValues    = [];
        $ratingValues = [];
        foreach ($visitorIds as $visitorId) {
            if (isset($companies[$visitorId])) {
                $lapValues[] = $companies[$visitorId];
            }
            if (isset($ratings[$visitorId])) {
                $ratingValues[] = $ratings[$visitorId];
            }
        }

        return [
            'label'      => $label,
            'groups'     => count($visitorIds),
            'share'      => percentage(count($visitorIds), $groupCount),
            'laps_avg'   => $lapValues === [] ? null : round(array_sum($lapValues) / count($lapValues), 2),
            'rating_avg' => $ratingValues === [] ? null : round(array_sum($ratingValues) / count($ratingValues), 2),
        ];
    };

    foreach ($byCategory as $questionId => $info) {
        if (($includes[$questionId] ?? []) !== []) {
            $stats['segments'][] = $segment($info['label'] . 'を含む', $includes[$questionId]);
        }
    }
    foreach ($byCategory as $questionId => $info) {
        if (count($onlys[$questionId] ?? []) >= GROUP_ONLY_MIN) {
            $stats['segments'][] = $segment($info['label'] . 'のみ', $onlys[$questionId]);
        }
    }

    // 来場人数（企業アンケートでの申告）と内訳の合計がそろっているか
    $partySizes = party_sizes_for_event($eventId);
    $diffs      = [];
    $compared   = 0;
    $matched    = 0;
    foreach ($groups as $group) {
        $party = $partySizes[$group['visitor']] ?? null;
        if ($party === null) {
            continue;
        }
        $size = 0;
        foreach ($byCategory as $questionId => $info) {
            $size += $group['counts'][$questionId] ?? 0;
        }
        $diff = $size - $party;
        $compared++;
        if ($diff === 0) {
            $matched++;
        }
        $diffs[$diff] = ($diffs[$diff] ?? 0) + 1;
    }
    ksort($diffs, SORT_NUMERIC);
    $distribution = [];
    foreach ($diffs as $diff => $count) {
        $label = match (true) {
            $diff === 0 => '一致',
            $diff > 0   => '内訳が多い（＋' . $diff . '人）',
            default     => '内訳が少ない（' . $diff . '人）',
        };
        $distribution[$label] = $count;
    }
    $stats['check'] = [
        'compared'     => $compared,
        'matched'      => $matched,
        'match_rate'   => percentage($matched, $compared),
        'distribution' => $distribution,
    ];

    return $stats;
}

/** 秒数を「1時間23分」のように読みやすくする */
function format_duration(float $seconds): string
{
    $seconds = (int) round($seconds);
    if ($seconds < 60) {
        return $seconds . '秒';
    }
    $minutes = intdiv($seconds, 60);
    if ($minutes < 60) {
        return $minutes . '分';
    }

    return intdiv($minutes, 60) . '時間' . ($minutes % 60 === 0 ? '' : ($minutes % 60) . '分');
}
