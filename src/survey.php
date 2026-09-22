<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

/**
 * 設問の型と、回答値の検証・整形。
 *
 * 保存形式（answers.value）：
 *   single  … 選択肢の文字列
 *   multi   … 選択肢のJSON配列（例: ["A","B"]）
 *   text    … 入力された文字列
 *   rating  … "1"〜"5"（星の数）
 *   nps     … "0"〜"10"
 */

/** 設問の型 */
enum QuestionType: string
{
    case Single = 'single';
    case Multi  = 'multi';
    case Text   = 'text';
    case Rating = 'rating';
    case Nps    = 'nps';
    case Number = 'number';

    public function label(): string
    {
        return match ($this) {
            self::Single => '単一選択',
            self::Multi  => '複数選択',
            self::Text   => '自由記述',
            self::Rating => '評価（星5段階）',
            self::Nps    => 'NPS（0〜10）',
            self::Number => '数値入力（人数など）',
        };
    }

    /** 選択肢を持つ型か */
    public function hasOptions(): bool
    {
        return $this === self::Single || $this === self::Multi;
    }

    /** 集計でグラフにできる型か */
    public function isAggregatable(): bool
    {
        return $this !== self::Text;
    }

    public static function tryFromString(?string $value): ?self
    {
        return $value === null ? null : self::tryFrom($value);
    }
}

/** 星評価の段階数（固定） */
const RATING_MAX = 5;

/** 数値入力の既定の範囲と単位（設問ごとに options で上書きできる） */
const NUMBER_DEFAULTS = ['min' => 1, 'max' => 99, 'unit' => '人'];

/**
 * 数値入力の設定（最小値・最大値・単位）。
 *
 * 管理画面では options 欄に「min=1」「max=99」「unit=人」の形で書ける。
 * 省略した項目は既定値を使う。
 *
 * @return array{min: int, max: int, unit: string}
 */
function number_settings(array $question): array
{
    $settings = NUMBER_DEFAULTS;
    $decoded  = json_decode((string) ($question['options'] ?? ''), true);
    if (is_array($decoded)) {
        foreach (['min', 'max'] as $key) {
            if (isset($decoded[$key]) && is_numeric($decoded[$key])) {
                $settings[$key] = (int) $decoded[$key];
            }
        }
        if (isset($decoded['unit']) && is_string($decoded['unit']) && $decoded['unit'] !== '') {
            $settings['unit'] = mb_substr($decoded['unit'], 0, 8);
        }
    }
    if ($settings['max'] < $settings['min']) {
        $settings['max'] = $settings['min'];
    }

    return $settings;
}

/**
 * 来場人数として集計する設問か。
 *
 * 「何人で来られましたか」のように、回答をそのまま人数として足し上げる設問。
 */
function is_party_size_question(array $question): bool
{
    return (string) ($question['metric'] ?? 'none') === 'party_size';
}

/**
 * 設問行の options（JSON文字列）を配列に戻す。
 *
 * @return list<string>
 */
function question_options(array $question): array
{
    $type = QuestionType::tryFromString((string) $question['type']);
    if ($type === null || !$type->hasOptions()) {
        return [];
    }
    $decoded = json_decode((string) ($question['options'] ?? ''), true);
    if (!is_array($decoded)) {
        return [];
    }

    return array_values(array_map(static fn($o) => (string) $o, $decoded));
}

/**
 * 集計・CSVで使う、その設問が取りうる値の一覧。
 *
 * @return list<string>
 */
function question_value_domain(array $question): array
{
    $type = QuestionType::tryFromString((string) $question['type']);

    return match ($type) {
        QuestionType::Single, QuestionType::Multi => question_options($question),
        QuestionType::Rating => array_map('strval', range(1, RATING_MAX)),
        QuestionType::Nps    => array_map('strval', range(0, 10)),
        default              => [],
    };
}

/**
 * 1設問ぶんの入力を検証し、保存する値を返す。
 *
 * @param array $question questions テーブルの1行
 * @param mixed $input    $_POST['q'][question_id]（未回答なら null）
 * @return array{ok: true, value: ?string}|array{ok: false, error: string}
 */
function validate_answer(array $question, mixed $input): array
{
    $type     = QuestionType::tryFromString((string) $question['type']);
    $required = (int) $question['required'] === 1;

    if ($type === null) {
        return ['ok' => false, 'error' => 'invalid'];
    }
    if (!is_valid_utf8($input)) {
        return ['ok' => false, 'error' => 'encoding'];
    }

    switch ($type) {
        case QuestionType::Single:
            $value   = is_string($input) ? trim_ja($input) : '';
            $options = question_options($question);
            if ($value === '') {
                return $required ? ['ok' => false, 'error' => 'required'] : ['ok' => true, 'value' => null];
            }
            // 選択肢に無い値は受け付けない（改ざんされたフォーム対策）
            if (!in_array($value, $options, true)) {
                return ['ok' => false, 'error' => 'invalid'];
            }
            return ['ok' => true, 'value' => $value];

        case QuestionType::Multi:
            $options  = question_options($question);
            $selected = [];
            if (is_array($input)) {
                foreach ($input as $item) {
                    if (!is_string($item)) {
                        return ['ok' => false, 'error' => 'invalid'];
                    }
                    $item = trim_ja($item);
                    if ($item === '') {
                        continue;
                    }
                    if (!in_array($item, $options, true)) {
                        return ['ok' => false, 'error' => 'invalid'];
                    }
                    if (!in_array($item, $selected, true)) {
                        $selected[] = $item;
                    }
                }
            } elseif (is_string($input) && trim_ja($input) !== '') {
                $item = trim_ja($input);
                if (!in_array($item, $options, true)) {
                    return ['ok' => false, 'error' => 'invalid'];
                }
                $selected[] = $item;
            }
            if ($selected === []) {
                return $required ? ['ok' => false, 'error' => 'required'] : ['ok' => true, 'value' => null];
            }
            return ['ok' => true, 'value' => json_encode($selected, JSON_UNESCAPED_UNICODE)];

        case QuestionType::Text:
            $value = is_string($input) ? trim_ja(strip_control_chars($input)) : '';
            if ($value === '') {
                return $required ? ['ok' => false, 'error' => 'required'] : ['ok' => true, 'value' => null];
            }
            if (mb_strlen($value) > TEXT_ANSWER_MAX_LENGTH) {
                return ['ok' => false, 'error' => 'length'];
            }
            return ['ok' => true, 'value' => $value];

        case QuestionType::Rating:
        case QuestionType::Nps:
        case QuestionType::Number:
            if ($type === QuestionType::Number) {
                $settings = number_settings($question);
                $min = $settings['min'];
                $max = $settings['max'];
            } else {
                $min = $type === QuestionType::Rating ? 1 : 0;
                $max = $type === QuestionType::Rating ? RATING_MAX : 10;
            }
            $raw = is_string($input) ? trim_ja($input) : '';
            // 全角数字で入力されることがあるので半角に直す
            $raw = (string) mb_convert_kana($raw, 'n');
            if ($raw === '') {
                return $required ? ['ok' => false, 'error' => 'required'] : ['ok' => true, 'value' => null];
            }
            if (preg_match('/\A-?\d+\z/', $raw) !== 1) {
                return ['ok' => false, 'error' => 'invalid'];
            }
            $number = (int) $raw;
            if ($number < $min || $number > $max) {
                return ['ok' => false, 'error' => 'invalid'];
            }
            return ['ok' => true, 'value' => (string) $number];
    }

    return ['ok' => false, 'error' => 'invalid'];
}

/**
 * 保存済みの値を、画面・CSVで読める文字列にする。
 */
function format_answer_value(array $question, ?string $value): string
{
    if ($value === null || $value === '') {
        return '';
    }
    $type = QuestionType::tryFromString((string) $question['type']);

    return match ($type) {
        QuestionType::Multi  => implode(' / ', decode_multi_value($value)),
        QuestionType::Rating => $value . ' / ' . RATING_MAX,
        QuestionType::Number => $value . number_settings($question)['unit'],
        default              => $value,
    };
}

/**
 * 複数選択の保存値（JSON配列）を配列に戻す。
 *
 * @return list<string>
 */
function decode_multi_value(string $value): array
{
    $decoded = json_decode($value, true);
    if (!is_array($decoded)) {
        return [$value];
    }

    return array_values(array_map(static fn($v) => (string) $v, $decoded));
}

/**
 * 管理画面のフォームから送られた選択肢テキスト（1行1選択肢）を配列にする。
 *
 * @return list<string>
 */
function parse_options_text(string $text): array
{
    $options = [];
    foreach (preg_split('/\R/u', $text) ?: [] as $line) {
        $line = trim_ja(strip_control_chars($line));
        if ($line === '' || in_array($line, $options, true)) {
            continue;
        }
        $options[] = mb_substr($line, 0, 200);
    }

    return $options;
}

/**
 * 数値入力の設定テキスト（min=1 / max=99 / unit=人）を配列にする。
 *
 * 管理画面では選択肢と同じ入力欄を使うため、書かれていない項目は既定値のままにする。
 *
 * @return array{min: int, max: int, unit: string}
 */
function parse_number_settings_text(string $text): array
{
    $settings = NUMBER_DEFAULTS;
    foreach (preg_split('/\R/u', $text) ?: [] as $line) {
        $line = trim_ja(strip_control_chars($line));
        if ($line === '' || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = array_map('trim', explode('=', $line, 2));
        $key = strtolower($key);
        if (($key === 'min' || $key === 'max') && is_numeric($value)) {
            $settings[$key] = max(0, (int) $value);
        } elseif ($key === 'unit' && $value !== '') {
            $settings['unit'] = mb_substr($value, 0, 8);
        }
    }
    if ($settings['max'] < $settings['min']) {
        $settings['max'] = $settings['min'];
    }

    return $settings;
}
