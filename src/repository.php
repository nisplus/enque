<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/survey.php';

/**
 * DBアクセス関数群。
 *
 * すべてのクエリはプリペアドステートメントで実装し、
 * 値を文字列連結でSQLに埋め込まないこと（SQLインジェクション対策）。
 */

// ================================================================ イベント

/** @return list<array<string,mixed>> */
function all_events(): array
{
    $stmt = db()->query('SELECT * FROM events ORDER BY id DESC');

    return $stmt->fetchAll();
}

function find_event(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM events WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();

    return $row === false ? null : $row;
}

function find_event_by_slug(string $slug): ?array
{
    $stmt = db()->prepare('SELECT * FROM events WHERE slug = ?');
    $stmt->execute([$slug]);
    $row = $stmt->fetch();

    return $row === false ? null : $row;
}

function create_event(string $name, ?string $startDate, ?string $endDate, string $status): int
{
    $stmt = db()->prepare(
        'INSERT INTO events (name, slug, start_date, end_date, status) VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([$name, random_slug(6), $startDate, $endDate, $status]);

    return (int) db()->lastInsertId();
}

function update_event(int $id, string $name, ?string $startDate, ?string $endDate, string $status): void
{
    $stmt = db()->prepare(
        'UPDATE events SET name = ?, start_date = ?, end_date = ?, status = ? WHERE id = ?'
    );
    $stmt->execute([$name, $startDate, $endDate, $status, $id]);
}

// ================================================================ 企業

/** @return list<array<string,mixed>> */
function companies_for_event(int $eventId, bool $includeInactive = false): array
{
    $sql = 'SELECT * FROM companies WHERE event_id = ?';
    if (!$includeInactive) {
        $sql .= ' AND is_active = 1';
    }
    $sql .= ' ORDER BY sort_order, id';

    $stmt = db()->prepare($sql);
    $stmt->execute([$eventId]);

    return $stmt->fetchAll();
}

function find_company(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM companies WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();

    return $row === false ? null : $row;
}

/** イベント内の企業をスラグで引く（来場者向けURLの解決に使う） */
function find_company_by_slug(int $eventId, string $slug): ?array
{
    $stmt = db()->prepare('SELECT * FROM companies WHERE event_id = ? AND qr_slug = ?');
    $stmt->execute([$eventId, $slug]);
    $row = $stmt->fetch();

    return $row === false ? null : $row;
}

function create_company(int $eventId, string $name, ?string $boothNo, ?string $color, ?string $logoUrl): int
{
    $order = db()->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM companies WHERE event_id = ?');
    $order->execute([$eventId]);

    $stmt = db()->prepare(
        'INSERT INTO companies (event_id, name, qr_slug, booth_no, color, logo_url, sort_order)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$eventId, $name, random_slug(8), $boothNo, $color, $logoUrl, (int) $order->fetchColumn()]);

    return (int) db()->lastInsertId();
}

function update_company(int $id, string $name, ?string $boothNo, ?string $color, ?string $logoUrl, int $sortOrder): void
{
    $stmt = db()->prepare(
        'UPDATE companies SET name = ?, booth_no = ?, color = ?, logo_url = ?, sort_order = ? WHERE id = ?'
    );
    $stmt->execute([$name, $boothNo, $color, $logoUrl, $sortOrder, $id]);
}

/** 企業の論理削除・復帰（回答データは消さない） */
function set_company_active(int $id, bool $active): void
{
    $stmt = db()->prepare('UPDATE companies SET is_active = ? WHERE id = ?');
    $stmt->execute([$active ? 1 : 0, $id]);
}

/** QRスラグを再発行する（印刷物を差し替える場合に使う） */
function regenerate_company_slug(int $id): string
{
    $slug = random_slug(8);
    $stmt = db()->prepare('UPDATE companies SET qr_slug = ? WHERE id = ?');
    $stmt->execute([$slug, $id]);

    return $slug;
}

// ================================================================ アンケート

function find_survey(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM surveys WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();

    return $row === false ? null : $row;
}

function survey_for_company(int $companyId): ?array
{
    $stmt = db()->prepare('SELECT * FROM surveys WHERE company_id = ?');
    $stmt->execute([$companyId]);
    $row = $stmt->fetch();

    return $row === false ? null : $row;
}

/** イベントの全体アンケート（type=overall）。無ければ null */
function overall_survey(int $eventId): ?array
{
    $stmt = db()->prepare("SELECT * FROM surveys WHERE event_id = ? AND type = 'overall' LIMIT 1");
    $stmt->execute([$eventId]);
    $row = $stmt->fetch();

    return $row === false ? null : $row;
}

/** イベントの共通設問テンプレート（type=template）。無ければ null */
function template_survey(int $eventId): ?array
{
    $stmt = db()->prepare("SELECT * FROM surveys WHERE event_id = ? AND type = 'template' LIMIT 1");
    $stmt->execute([$eventId]);
    $row = $stmt->fetch();

    return $row === false ? null : $row;
}

function create_survey(int $eventId, ?int $companyId, string $type, string $title, ?string $description, bool $published): int
{
    $stmt = db()->prepare(
        'INSERT INTO surveys (event_id, company_id, type, title, description, is_published) VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$eventId, $companyId, $type, $title, $description, $published ? 1 : 0]);

    return (int) db()->lastInsertId();
}

function update_survey(int $id, string $title, ?string $description, bool $published): void
{
    $stmt = db()->prepare('UPDATE surveys SET title = ?, description = ?, is_published = ? WHERE id = ?');
    $stmt->execute([$title, $description, $published ? 1 : 0, $id]);
}

/** 企業のアンケートを（無ければ）作って返す */
function ensure_company_survey(array $company): array
{
    $survey = survey_for_company((int) $company['id']);
    if ($survey !== null) {
        return $survey;
    }
    $id = create_survey(
        (int) $company['event_id'],
        (int) $company['id'],
        'company',
        (string) $company['name'] . ' アンケート',
        null,
        false
    );

    return find_survey($id) ?? [];
}

// ================================================================ 設問

/** @return list<array<string,mixed>> */
function questions_for_survey(int $surveyId): array
{
    $stmt = db()->prepare('SELECT * FROM questions WHERE survey_id = ? ORDER BY sort_order, id');
    $stmt->execute([$surveyId]);

    return $stmt->fetchAll();
}

/**
 * 設問を丸ごと置き換える。
 *
 * 既存の設問IDが送られてきたものは UPDATE、無くなったものは DELETE する
 * （DELETE すると紐づく answers も外部キーで消えるため、回答受付後の削除は
 * 管理画面側で確認を取ってから呼ぶこと）。
 *
 * @param list<array{id: ?int, type: string, label: string, options: list<string>, required: bool}> $questions
 */
function replace_questions(int $surveyId, array $questions): void
{
    db_transaction(static function () use ($surveyId, $questions): void {
        $keepIds = [];
        $order   = 0;

        foreach ($questions as $q) {
            $order++;
            $options = $q['options'] === []
                ? null
                : json_encode(array_values($q['options']), JSON_UNESCAPED_UNICODE);

            if ($q['id'] !== null) {
                $stmt = db()->prepare(
                    'UPDATE questions SET type = ?, label = ?, options = ?, required = ?, sort_order = ?
                     WHERE id = ? AND survey_id = ?'
                );
                $stmt->execute([$q['type'], $q['label'], $options, $q['required'] ? 1 : 0, $order, $q['id'], $surveyId]);
                $keepIds[] = (int) $q['id'];
                continue;
            }

            $stmt = db()->prepare(
                'INSERT INTO questions (survey_id, type, label, options, required, sort_order) VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$surveyId, $q['type'], $q['label'], $options, $q['required'] ? 1 : 0, $order]);
            $keepIds[] = (int) db()->lastInsertId();
        }

        if ($keepIds === []) {
            $stmt = db()->prepare('DELETE FROM questions WHERE survey_id = ?');
            $stmt->execute([$surveyId]);
            return;
        }

        $placeholders = implode(',', array_fill(0, count($keepIds), '?'));
        $stmt = db()->prepare("DELETE FROM questions WHERE survey_id = ? AND id NOT IN ({$placeholders})");
        $stmt->execute(array_merge([$surveyId], $keepIds));
    });
}

/** テンプレートの設問を、別のアンケートの末尾に複製する。戻り値は追加件数 */
function copy_questions(int $fromSurveyId, int $toSurveyId): int
{
    return db_transaction(static function () use ($fromSurveyId, $toSurveyId): int {
        $stmt = db()->prepare('SELECT COALESCE(MAX(sort_order), 0) FROM questions WHERE survey_id = ?');
        $stmt->execute([$toSurveyId]);
        $order = (int) $stmt->fetchColumn();

        $added = 0;
        foreach (questions_for_survey($fromSurveyId) as $q) {
            $order++;
            $added++;
            $insert = db()->prepare(
                'INSERT INTO questions (survey_id, type, label, options, required, sort_order) VALUES (?, ?, ?, ?, ?, ?)'
            );
            $insert->execute([$toSurveyId, $q['type'], $q['label'], $q['options'], $q['required'], $order]);
        }

        return $added;
    });
}

// ================================================================ 来場者

/** 匿名セッショントークンから来場者を引く（無ければ作る） */
function find_or_create_visitor(int $eventId, string $token): array
{
    $stmt = db()->prepare('SELECT * FROM visitors WHERE event_id = ? AND session_token = ?');
    $stmt->execute([$eventId, $token]);
    $row = $stmt->fetch();
    if ($row !== false) {
        return $row;
    }

    $insert = db()->prepare('INSERT INTO visitors (event_id, session_token) VALUES (?, ?)');
    try {
        $insert->execute([$eventId, $token]);
    } catch (PDOException $e) {
        // 同時リクエストで二重に作られた場合は既存行を読み直す
        $stmt->execute([$eventId, $token]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw $e;
        }
        return $row;
    }

    $stmt->execute([$eventId, $token]);

    return $stmt->fetch() ?: [];
}

function find_visitor(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM visitors WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();

    return $row === false ? null : $row;
}

/** 任意入力のメールアドレスを保存する（後から上書きも可能） */
function set_visitor_email(int $visitorId, string $email): void
{
    $stmt = db()->prepare('UPDATE visitors SET email = ?, email_purged = 0 WHERE id = ?');
    $stmt->execute([$email, $visitorId]);
}

// ================================================================ 回答

/** 同じ来場者がこのアンケートに回答済みか */
function has_response(int $surveyId, int $visitorId): bool
{
    $stmt = db()->prepare('SELECT 1 FROM responses WHERE survey_id = ? AND visitor_id = ? LIMIT 1');
    $stmt->execute([$surveyId, $visitorId]);

    return $stmt->fetchColumn() !== false;
}

/**
 * 回答を登録する。
 *
 * 既に同じ来場者の回答があれば is_duplicate=1 で記録する（送信自体は拒否しない）。
 *
 * @param array<int, ?string> $answers question_id => 保存値
 * @return array{response_id: int, is_duplicate: bool}
 */
function insert_response(int $surveyId, int $visitorId, array $answers): array
{
    return db_transaction(static function () use ($surveyId, $visitorId, $answers): array {
        $duplicate = has_response($surveyId, $visitorId);

        $stmt = db()->prepare('INSERT INTO responses (survey_id, visitor_id, is_duplicate) VALUES (?, ?, ?)');
        $stmt->execute([$surveyId, $visitorId, $duplicate ? 1 : 0]);
        $responseId = (int) db()->lastInsertId();

        $insert = db()->prepare('INSERT INTO answers (response_id, question_id, value) VALUES (?, ?, ?)');
        foreach ($answers as $questionId => $value) {
            if ($value === null) {
                continue; // 未回答（任意項目）は行を作らない
            }
            $insert->execute([$responseId, (int) $questionId, $value]);
        }

        return ['response_id' => $responseId, 'is_duplicate' => $duplicate];
    });
}

/** 有効回答数（重複を除く） */
function count_responses(int $surveyId, bool $includeDuplicates = false): int
{
    $sql = 'SELECT COUNT(*) FROM responses WHERE survey_id = ?';
    if (!$includeDuplicates) {
        $sql .= ' AND is_duplicate = 0';
    }
    $stmt = db()->prepare($sql);
    $stmt->execute([$surveyId]);

    return (int) $stmt->fetchColumn();
}

/** 重複として記録された回答数 */
function count_duplicate_responses(int $surveyId): int
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM responses WHERE survey_id = ? AND is_duplicate = 1');
    $stmt->execute([$surveyId]);

    return (int) $stmt->fetchColumn();
}

/**
 * 回答一覧（新しい順）。
 *
 * @return list<array<string,mixed>>
 */
function responses_for_survey(int $surveyId, bool $includeDuplicates, int $limit = 0, int $offset = 0): array
{
    $sql = 'SELECT r.*, v.email FROM responses r
            JOIN visitors v ON v.id = r.visitor_id
            WHERE r.survey_id = ?';
    if (!$includeDuplicates) {
        $sql .= ' AND r.is_duplicate = 0';
    }
    $sql .= ' ORDER BY r.submitted_at DESC, r.id DESC';
    if ($limit > 0) {
        $sql .= ' LIMIT ' . $limit . ' OFFSET ' . max(0, $offset);
    }

    $stmt = db()->prepare($sql);
    $stmt->execute([$surveyId]);

    return $stmt->fetchAll();
}

/**
 * 複数の回答の回答内容をまとめて取得する。
 *
 * @param list<int> $responseIds
 * @return array<int, array<int, string>> response_id => [question_id => value]
 */
function answers_for_responses(array $responseIds): array
{
    if ($responseIds === []) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($responseIds), '?'));
    $stmt = db()->prepare("SELECT response_id, question_id, value FROM answers WHERE response_id IN ({$placeholders})");
    $stmt->execute($responseIds);

    $map = [];
    foreach ($stmt->fetchAll() as $row) {
        $map[(int) $row['response_id']][(int) $row['question_id']] = (string) ($row['value'] ?? '');
    }

    return $map;
}

/** その来場者が回答した企業数（重複を除く） */
function visited_company_count(int $visitorId): int
{
    $stmt = db()->prepare(
        "SELECT COUNT(DISTINCT s.company_id)
         FROM responses r JOIN surveys s ON s.id = r.survey_id
         WHERE r.visitor_id = ? AND s.type = 'company'"
    );
    $stmt->execute([$visitorId]);

    return (int) $stmt->fetchColumn();
}

/**
 * その来場者が回答した企業の一覧（回答済み画面で「回った企業」を出す）。
 *
 * @return list<array<string,mixed>>
 */
function visited_companies(int $visitorId): array
{
    $stmt = db()->prepare(
        "SELECT c.id, c.name, MIN(r.submitted_at) AS first_submitted_at
         FROM responses r
         JOIN surveys s   ON s.id = r.survey_id
         JOIN companies c ON c.id = s.company_id
         WHERE r.visitor_id = ? AND s.type = 'company'
         GROUP BY c.id, c.name
         ORDER BY first_submitted_at"
    );
    $stmt->execute([$visitorId]);

    return $stmt->fetchAll();
}

// ================================================================ 集計

/**
 * 設問ごとの回答値の出現数。
 *
 * 複数選択は保存値がJSON配列のため、PHP側で展開して数える。
 *
 * @return array{counts: array<string,int>, answered: int}
 */
function answer_counts(int $surveyId, array $question, bool $includeDuplicates = false): array
{
    $sql = 'SELECT a.value FROM answers a
            JOIN responses r ON r.id = a.response_id
            WHERE r.survey_id = ? AND a.question_id = ?';
    if (!$includeDuplicates) {
        $sql .= ' AND r.is_duplicate = 0';
    }
    $stmt = db()->prepare($sql);
    $stmt->execute([$surveyId, (int) $question['id']]);

    $counts = [];
    foreach (question_value_domain($question) as $value) {
        $counts[$value] = 0;
    }

    $answered = 0;
    $isMulti  = (string) $question['type'] === 'multi';
    foreach ($stmt->fetchAll() as $row) {
        $value = (string) ($row['value'] ?? '');
        if ($value === '') {
            continue;
        }
        $answered++;
        $values = $isMulti ? decode_multi_value($value) : [$value];
        foreach ($values as $v) {
            $counts[$v] = ($counts[$v] ?? 0) + 1;
        }
    }

    return ['counts' => $counts, 'answered' => $answered];
}

/**
 * 自由記述の一覧。
 *
 * @return list<array{value: string, submitted_at: string}>
 */
function text_answers(int $surveyId, int $questionId, bool $includeDuplicates = false, int $limit = 200): array
{
    $sql = 'SELECT a.value, r.submitted_at FROM answers a
            JOIN responses r ON r.id = a.response_id
            WHERE r.survey_id = ? AND a.question_id = ?';
    if (!$includeDuplicates) {
        $sql .= ' AND r.is_duplicate = 0';
    }
    $sql .= ' ORDER BY r.submitted_at DESC LIMIT ' . max(1, $limit);

    $stmt = db()->prepare($sql);
    $stmt->execute([$surveyId, $questionId]);

    return $stmt->fetchAll();
}

/**
 * 時間帯別の回答数（当日の推移グラフ用）。
 *
 * @return array<string,int> 'MM/DD HH時' => 件数
 */
function responses_by_hour(int $surveyId): array
{
    $stmt = db()->prepare(
        "SELECT DATE_FORMAT(submitted_at, '%m/%d %H') AS bucket, COUNT(*) AS n
         FROM responses WHERE survey_id = ? AND is_duplicate = 0
         GROUP BY bucket ORDER BY bucket"
    );
    $stmt->execute([$surveyId]);

    $series = [];
    foreach ($stmt->fetchAll() as $row) {
        $series[(string) $row['bucket'] . '時'] = (int) $row['n'];
    }

    return $series;
}

/**
 * イベント全体の時間帯別の回答数（主催者ダッシュボードの推移グラフ用）。
 *
 * @return array<string,int>
 */
function event_responses_by_hour(int $eventId): array
{
    $stmt = db()->prepare(
        "SELECT DATE_FORMAT(r.submitted_at, '%m/%d %H') AS bucket, COUNT(*) AS n
         FROM responses r JOIN surveys s ON s.id = r.survey_id
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
 * 企業別の回答数ランキング（主催者ダッシュボード）。
 *
 * @return list<array{id: int, name: string, is_active: int, responses: int, duplicates: int, visitors: int}>
 */
function company_response_ranking(int $eventId): array
{
    $stmt = db()->prepare(
        "SELECT c.id, c.name, c.is_active,
                COALESCE(SUM(CASE WHEN r.is_duplicate = 0 THEN 1 ELSE 0 END), 0) AS responses,
                COALESCE(SUM(CASE WHEN r.is_duplicate = 1 THEN 1 ELSE 0 END), 0) AS duplicates,
                COUNT(DISTINCT CASE WHEN r.is_duplicate = 0 THEN r.visitor_id END) AS visitors
         FROM companies c
         LEFT JOIN surveys   s ON s.company_id = c.id
         LEFT JOIN responses r ON r.survey_id  = s.id
         WHERE c.event_id = ?
         GROUP BY c.id, c.name, c.is_active
         ORDER BY responses DESC, c.sort_order, c.id"
    );
    $stmt->execute([$eventId]);

    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
        $rows[] = [
            'id'         => (int) $row['id'],
            'name'       => (string) $row['name'],
            'is_active'  => (int) $row['is_active'],
            'responses'  => (int) $row['responses'],
            'duplicates' => (int) $row['duplicates'],
            'visitors'   => (int) $row['visitors'],
        ];
    }

    return $rows;
}

/**
 * イベント全体のサマリー。
 *
 * @return array{visitors: int, responding_visitors: int, responses: int, duplicates: int,
 *               avg_companies: float, emails: int, claims: int, claimed: int, overall_responses: int}
 */
function event_summary(int $eventId): array
{
    $pdo = db();

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM visitors WHERE event_id = ?');
    $stmt->execute([$eventId]);
    $visitors = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT COUNT(DISTINCT r.visitor_id),
                COALESCE(SUM(CASE WHEN r.is_duplicate = 0 THEN 1 ELSE 0 END), 0),
                COALESCE(SUM(CASE WHEN r.is_duplicate = 1 THEN 1 ELSE 0 END), 0)
         FROM responses r JOIN surveys s ON s.id = r.survey_id
         WHERE s.event_id = ? AND s.type = 'company'"
    );
    $stmt->execute([$eventId]);
    $row = $stmt->fetch(PDO::FETCH_NUM) ?: [0, 0, 0];

    $stmt = $pdo->prepare(
        "SELECT COALESCE(AVG(cnt), 0) FROM (
            SELECT COUNT(DISTINCT s.company_id) AS cnt
            FROM responses r JOIN surveys s ON s.id = r.survey_id
            WHERE s.event_id = ? AND s.type = 'company' AND r.is_duplicate = 0
            GROUP BY r.visitor_id
         ) AS t"
    );
    $stmt->execute([$eventId]);
    $avg = (float) $stmt->fetchColumn();

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM visitors WHERE event_id = ? AND email IS NOT NULL');
    $stmt->execute([$eventId]);
    $emails = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare(
        'SELECT COUNT(*), COALESCE(SUM(CASE WHEN pc.claimed_at IS NOT NULL THEN 1 ELSE 0 END), 0)
         FROM prize_claims pc JOIN visitors v ON v.id = pc.visitor_id WHERE v.event_id = ?'
    );
    $stmt->execute([$eventId]);
    $claimRow = $stmt->fetch(PDO::FETCH_NUM) ?: [0, 0];

    $overall = overall_survey($eventId);

    return [
        'visitors'            => $visitors,
        'responding_visitors' => (int) $row[0],
        'responses'           => (int) $row[1],
        'duplicates'          => (int) $row[2],
        'avg_companies'       => round($avg, 2),
        'emails'              => $emails,
        'claims'              => (int) $claimRow[0],
        'claimed'             => (int) $claimRow[1],
        'overall_responses'   => $overall === null ? 0 : count_responses((int) $overall['id']),
    ];
}

// ================================================================ 景品交換

/** 来場者の交換コード（無ければ発行する） */
function find_or_create_claim(int $visitorId): array
{
    $stmt = db()->prepare('SELECT * FROM prize_claims WHERE visitor_id = ?');
    $stmt->execute([$visitorId]);
    $row = $stmt->fetch();
    if ($row !== false) {
        return $row;
    }

    // コードの衝突は極めて稀だが、念のため数回まで作り直す
    for ($i = 0; $i < 5; $i++) {
        try {
            $insert = db()->prepare('INSERT INTO prize_claims (visitor_id, claim_code) VALUES (?, ?)');
            $insert->execute([$visitorId, random_claim_code()]);
            break;
        } catch (PDOException $e) {
            $stmt->execute([$visitorId]);
            $existing = $stmt->fetch();
            if ($existing !== false) {
                return $existing; // 同時リクエストで作られていた
            }
            if ($i === 4) {
                throw $e;
            }
        }
    }

    $stmt->execute([$visitorId]);

    return $stmt->fetch() ?: [];
}

/** 交換コードで照会する（総合受付の画面） */
function find_claim_by_code(string $code): ?array
{
    $stmt = db()->prepare(
        'SELECT pc.*, v.event_id FROM prize_claims pc
         JOIN visitors v ON v.id = pc.visitor_id
         WHERE pc.claim_code = ?'
    );
    $stmt->execute([$code]);
    $row = $stmt->fetch();

    return $row === false ? null : $row;
}

/**
 * 交換済みとして記録する（渡した景品も残す）。
 *
 * 既に交換済みの場合も記録は上書きせず false を返す（判断はスタッフに委ねる）。
 */
function mark_claimed(int $claimId, string $staff, ?string $note, ?int $prizeId = null): bool
{
    $stmt = db()->prepare(
        'UPDATE prize_claims SET claimed_at = NOW(), claimed_by = ?, note = ?, prize_id = ?
         WHERE id = ? AND claimed_at IS NULL'
    );
    $stmt->execute([$staff, $note, $prizeId, $claimId]);

    return $stmt->rowCount() === 1;
}

/** @return list<array<string,mixed>> 交換履歴（新しい順） */
function claim_history(int $eventId, int $limit = 100): array
{
    $stmt = db()->prepare(
        'SELECT pc.claim_code, pc.claimed_at, pc.claimed_by, pc.note, p.name AS prize_name
         FROM prize_claims pc
         JOIN visitors v ON v.id = pc.visitor_id
         LEFT JOIN prizes p ON p.id = pc.prize_id
         WHERE v.event_id = ? AND pc.claimed_at IS NOT NULL
         ORDER BY pc.claimed_at DESC LIMIT ' . max(1, $limit)
    );
    $stmt->execute([$eventId]);

    return $stmt->fetchAll();
}

// ================================================================ 景品

/** @return list<array<string,mixed>> */
function prizes_for_event(int $eventId, bool $includeInactive = false): array
{
    $sql = 'SELECT * FROM prizes WHERE event_id = ?';
    if (!$includeInactive) {
        $sql .= ' AND is_active = 1';
    }
    $sql .= ' ORDER BY sort_order, id';

    $stmt = db()->prepare($sql);
    $stmt->execute([$eventId]);

    return $stmt->fetchAll();
}

function find_prize(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM prizes WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();

    return $row === false ? null : $row;
}

function create_prize(int $eventId, string $name, ?int $totalQty, ?string $note): int
{
    $order = db()->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM prizes WHERE event_id = ?');
    $order->execute([$eventId]);

    $stmt = db()->prepare(
        'INSERT INTO prizes (event_id, name, total_qty, note, sort_order) VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([$eventId, $name, $totalQty, $note, (int) $order->fetchColumn()]);

    return (int) db()->lastInsertId();
}

function update_prize(int $id, string $name, ?int $totalQty, ?string $note, int $sortOrder): void
{
    $stmt = db()->prepare(
        'UPDATE prizes SET name = ?, total_qty = ?, note = ?, sort_order = ? WHERE id = ?'
    );
    $stmt->execute([$name, $totalQty, $note, $sortOrder, $id]);
}

/** 景品の取り扱いを止める・再開する（交換記録は残す） */
function set_prize_active(int $id, bool $active): void
{
    $stmt = db()->prepare('UPDATE prizes SET is_active = ? WHERE id = ?');
    $stmt->execute([$active ? 1 : 0, $id]);
}

/** その景品で交換済みの件数 */
function prize_claimed_count(int $prizeId): int
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM prize_claims WHERE prize_id = ? AND claimed_at IS NOT NULL');
    $stmt->execute([$prizeId]);

    return (int) $stmt->fetchColumn();
}

/**
 * 景品ごとの在庫状況。
 *
 * remaining は残数（数量を管理しない景品は null）。
 * 在庫を超えて渡した場合はマイナスにせず、over に超過数を入れる
 * （品切れでも記録できるようにしているため。記録の正しさを優先する）。
 *
 * @return list<array{id: int, name: string, total_qty: ?int, note: ?string, is_active: int,
 *                    sort_order: int, claimed: int, remaining: ?int, over: int}>
 */
function prize_stock(int $eventId, bool $includeInactive = true): array
{
    $sql = 'SELECT p.*, COUNT(pc.id) AS claimed
            FROM prizes p
            LEFT JOIN prize_claims pc ON pc.prize_id = p.id AND pc.claimed_at IS NOT NULL
            WHERE p.event_id = ?';
    if (!$includeInactive) {
        $sql .= ' AND p.is_active = 1';
    }
    $sql .= ' GROUP BY p.id ORDER BY p.sort_order, p.id';

    $stmt = db()->prepare($sql);
    $stmt->execute([$eventId]);

    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
        $total   = $row['total_qty'] === null ? null : (int) $row['total_qty'];
        $claimed = (int) $row['claimed'];

        $rows[] = [
            'id'        => (int) $row['id'],
            'name'      => (string) $row['name'],
            'total_qty' => $total,
            'note'       => $row['note'] === null ? null : (string) $row['note'],
            'is_active'  => (int) $row['is_active'],
            'sort_order' => (int) $row['sort_order'],
            'claimed'   => $claimed,
            'remaining' => $total === null ? null : max(0, $total - $claimed),
            'over'      => $total === null ? 0 : max(0, $claimed - $total),
        ];
    }

    return $rows;
}

/** 景品を指定していない交換の件数（景品登録前に記録したぶん） */
function claims_without_prize(int $eventId): int
{
    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM prize_claims pc JOIN visitors v ON v.id = pc.visitor_id
         WHERE v.event_id = ? AND pc.claimed_at IS NOT NULL AND pc.prize_id IS NULL'
    );
    $stmt->execute([$eventId]);

    return (int) $stmt->fetchColumn();
}

// ================================================================ 全体アンケート案内

/**
 * メールアドレスを登録済みで、まだ案内行の無い来場者に案内行を作る。
 *
 * @return int 追加件数
 */
function create_pending_invites(int $eventId): int
{
    $stmt = db()->prepare(
        'SELECT v.id, v.email FROM visitors v
         LEFT JOIN overall_invites oi ON oi.visitor_id = v.id
         WHERE v.event_id = ? AND v.email IS NOT NULL AND oi.id IS NULL'
    );
    $stmt->execute([$eventId]);
    $rows = $stmt->fetchAll();

    $added = 0;
    foreach ($rows as $row) {
        $insert = db()->prepare(
            'INSERT INTO overall_invites (event_id, visitor_id, email, token) VALUES (?, ?, ?, ?)'
        );
        try {
            $insert->execute([$eventId, (int) $row['id'], (string) $row['email'], bin2hex(random_bytes(20))]);
            $added++;
        } catch (PDOException) {
            // 同時実行で既に作られていた場合は読み飛ばす
        }
    }

    return $added;
}

/** @return list<array<string,mixed>> 未送信（失敗ぶんを含む）の案内 */
function pending_invites(int $eventId, int $limit, int $maxAttempts = 3): array
{
    $stmt = db()->prepare(
        "SELECT * FROM overall_invites
         WHERE event_id = ? AND status <> 'sent' AND email IS NOT NULL AND attempts < ?
         ORDER BY id LIMIT " . max(1, $limit)
    );
    $stmt->execute([$eventId, $maxAttempts]);

    return $stmt->fetchAll();
}

function mark_invite_sent(int $inviteId): void
{
    $stmt = db()->prepare(
        "UPDATE overall_invites SET status = 'sent', sent_at = NOW(), attempts = attempts + 1, last_error = NULL WHERE id = ?"
    );
    $stmt->execute([$inviteId]);
}

function mark_invite_failed(int $inviteId, string $error): void
{
    $stmt = db()->prepare(
        "UPDATE overall_invites SET status = 'failed', attempts = attempts + 1, last_error = ? WHERE id = ?"
    );
    $stmt->execute([mb_substr($error, 0, 500), $inviteId]);
}

function find_invite_by_token(string $token): ?array
{
    $stmt = db()->prepare('SELECT * FROM overall_invites WHERE token = ?');
    $stmt->execute([$token]);
    $row = $stmt->fetch();

    return $row === false ? null : $row;
}

function mark_invite_responded(int $inviteId): void
{
    $stmt = db()->prepare('UPDATE overall_invites SET responded_at = NOW() WHERE id = ? AND responded_at IS NULL');
    $stmt->execute([$inviteId]);
}

/**
 * 案内メールの送信状況。
 *
 * @return array{pending: int, sent: int, failed: int, responded: int}
 */
function invite_stats(int $eventId): array
{
    $stmt = db()->prepare(
        "SELECT
            COALESCE(SUM(status = 'pending'), 0) AS pending,
            COALESCE(SUM(status = 'sent'), 0)    AS sent,
            COALESCE(SUM(status = 'failed'), 0)  AS failed,
            COALESCE(SUM(responded_at IS NOT NULL), 0) AS responded
         FROM overall_invites WHERE event_id = ?"
    );
    $stmt->execute([$eventId]);
    $row = $stmt->fetch() ?: [];

    return [
        'pending'   => (int) ($row['pending'] ?? 0),
        'sent'      => (int) ($row['sent'] ?? 0),
        'failed'    => (int) ($row['failed'] ?? 0),
        'responded' => (int) ($row['responded'] ?? 0),
    ];
}

/**
 * メールアドレスを削除する（送付・集計完了後の個人情報削除）。
 *
 * @return array{visitors: int, invites: int}
 */
function purge_emails(int $eventId): array
{
    return db_transaction(static function () use ($eventId): array {
        $stmt = db()->prepare(
            'UPDATE visitors SET email = NULL, email_purged = 1 WHERE event_id = ? AND email IS NOT NULL'
        );
        $stmt->execute([$eventId]);
        $visitors = $stmt->rowCount();

        $stmt = db()->prepare('UPDATE overall_invites SET email = NULL WHERE event_id = ? AND email IS NOT NULL');
        $stmt->execute([$eventId]);

        return ['visitors' => $visitors, 'invites' => $stmt->rowCount()];
    });
}

// ================================================================ 壁紙

/** @return list<array<string,mixed>> */
function wallpapers_for_event(int $eventId, ?int $companyId = null): array
{
    // 企業指定があればその企業ぶんを優先し、無ければイベント共通を返す
    if ($companyId !== null) {
        $stmt = db()->prepare('SELECT * FROM wallpapers WHERE event_id = ? AND company_id = ? ORDER BY sort_order, id');
        $stmt->execute([$eventId, $companyId]);
        $rows = $stmt->fetchAll();
        if ($rows !== []) {
            return $rows;
        }
    }

    $stmt = db()->prepare('SELECT * FROM wallpapers WHERE event_id = ? AND company_id IS NULL ORDER BY sort_order, id');
    $stmt->execute([$eventId]);

    return $stmt->fetchAll();
}

function find_wallpaper(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM wallpapers WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();

    return $row === false ? null : $row;
}

function insert_wallpaper(int $eventId, ?int $companyId, string $title, string $fileName, string $mime, int $w, int $h): int
{
    $stmt = db()->prepare(
        'INSERT INTO wallpapers (event_id, company_id, title, file_name, mime_type, width, height, sort_order)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    // 縦長（スマホ向け）を先に出したいので、幅の狭い順に並べる
    $stmt->execute([$eventId, $companyId, $title, $fileName, $mime, $w, $h, $w]);

    return (int) db()->lastInsertId();
}

function delete_wallpaper(int $id): void
{
    $stmt = db()->prepare('DELETE FROM wallpapers WHERE id = ?');
    $stmt->execute([$id]);
}

// ================================================================ 管理ユーザー

function admin_user_count(): int
{
    return (int) db()->query('SELECT COUNT(*) FROM admin_users')->fetchColumn();
}

function find_admin_by_username(string $username): ?array
{
    $stmt = db()->prepare('SELECT * FROM admin_users WHERE username = ?');
    $stmt->execute([$username]);
    $row = $stmt->fetch();

    return $row === false ? null : $row;
}

function find_admin(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM admin_users WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();

    return $row === false ? null : $row;
}

function create_admin_user(string $username, string $password, string $displayName, string $role, ?int $companyId): int
{
    $stmt = db()->prepare(
        'INSERT INTO admin_users (username, password_hash, display_name, role, company_id) VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT), $displayName, $role, $companyId]);

    return (int) db()->lastInsertId();
}

function update_admin_password(int $id, string $hash): void
{
    $stmt = db()->prepare('UPDATE admin_users SET password_hash = ? WHERE id = ?');
    $stmt->execute([$hash, $id]);
}

function set_admin_active(int $id, bool $active): void
{
    $stmt = db()->prepare('UPDATE admin_users SET is_active = ? WHERE id = ?');
    $stmt->execute([$active ? 1 : 0, $id]);
}

/** @return list<array<string,mixed>> */
function all_admin_users(): array
{
    $stmt = db()->query(
        'SELECT au.*, c.name AS company_name FROM admin_users au
         LEFT JOIN companies c ON c.id = au.company_id
         ORDER BY au.role, au.id'
    );

    return $stmt->fetchAll();
}

// ---------------------------------------------------------------- ログイン試行

function recent_login_failures(string $ip, int $windowSeconds): int
{
    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM admin_login_attempts
         WHERE ip_address = ? AND attempted_at > (NOW() - INTERVAL ? SECOND)'
    );
    $stmt->execute([$ip, $windowSeconds]);

    return (int) $stmt->fetchColumn();
}

function record_login_failure(string $ip): void
{
    $stmt = db()->prepare('INSERT INTO admin_login_attempts (ip_address) VALUES (?)');
    $stmt->execute([$ip]);

    // 古い記録は溜め続けない
    db()->exec('DELETE FROM admin_login_attempts WHERE attempted_at < (NOW() - INTERVAL 1 DAY)');
}

function clear_login_failures(string $ip): void
{
    $stmt = db()->prepare('DELETE FROM admin_login_attempts WHERE ip_address = ?');
    $stmt->execute([$ip]);
}
