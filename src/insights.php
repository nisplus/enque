<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/repository.php';

/**
 * 回答者の傾向の集計。
 *
 * 数え方の前提（画面にも注記する）：
 *   - 来場者は端末の匿名Cookie単位。端末を変えた人は別人として数える
 *   - 重複送信（同じ企業への2回目以降）は数えない
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
 * 企業アンケートの評価（星）とNPSの回答を、その人が回った企業数の区分ごとに平均する。
 * 相関であって因果ではない（たくさん回った人は元々関心が高い、という説明もつく）。
 *
 * @param list<array<string,mixed>> $laps
 * @return list<array{label: string, visitors: int, rating_avg: ?float, nps_score: ?int, answers: int}>
 */
function satisfaction_by_lap_count(int $eventId, array $laps): array
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
         WHERE s.event_id = ? AND s.type = 'company' AND r.is_duplicate = 0
           AND q.type IN ('rating', 'nps') AND a.value IS NOT NULL"
    );
    $stmt->execute([$eventId]);

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
 * 回答・登録まわりの割合。
 *
 * @return array{opened: int, responded: int, response_rate: float, duplicate_rate: float,
 *               email_rate: float, overall_rate: float, claim_rate: float}
 */
function participation_rates(int $eventId): array
{
    $summary = event_summary($eventId);
    $invites = invite_stats($eventId);

    $stmt = db()->prepare(
        "SELECT COALESCE(SUM(r.is_duplicate = 1), 0) AS dup, COUNT(*) AS total
         FROM responses r JOIN surveys s ON s.id = r.survey_id
         WHERE s.event_id = ? AND s.type = 'company'"
    );
    $stmt->execute([$eventId]);
    $row = $stmt->fetch() ?: ['dup' => 0, 'total' => 0];

    return [
        'opened'         => $summary['visitors'],
        'responded'      => $summary['responding_visitors'],
        // アンケート画面を開いた端末のうち、実際に送信した割合
        'response_rate'  => percentage($summary['responding_visitors'], $summary['visitors']),
        'duplicate_rate' => percentage((int) $row['dup'], (int) $row['total']),
        'email_rate'     => percentage($summary['emails'], $summary['responding_visitors']),
        // 案内メールを送った人のうち、全体アンケートに答えた割合
        'overall_rate'   => percentage($invites['responded'], $invites['sent']),
        'claim_rate'     => percentage($summary['claimed'], $summary['claims']),
    ];
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
