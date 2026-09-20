<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

/**
 * 画面の共通部分（HTMLの外枠）。
 *
 * テンプレートエンジンは使わず、出力は必ず e() を通す。
 */

/**
 * @param array{nav?: list<array{href: string, label: string, current?: bool}>, wide?: bool, script?: string, noindex?: bool} $options
 */
function page_header(string $title, array $options = []): void
{
    $nav      = $options['nav'] ?? [];
    $wide     = $options['wide'] ?? false;
    $brand    = $options['brand'] ?? null;

    header('Content-Type: text/html; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('X-Frame-Options: DENY');

    echo '<!doctype html>' . "\n";
    echo '<html lang="ja">' . "\n<head>\n";
    echo '<meta charset="UTF-8">' . "\n";
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">' . "\n";
    // 来場者向けURL・管理画面とも検索エンジンには載せない
    echo '<meta name="robots" content="noindex, nofollow">' . "\n";
    echo '<title>' . e($title) . '</title>' . "\n";
    echo '<link rel="stylesheet" href="/assets/style.css">' . "\n";
    echo "</head>\n<body>\n";

    if ($brand !== null || $nav !== []) {
        echo '<header class="site-header">';
        echo '<span class="brand">' . e((string) ($brand ?? '')) . '</span>';
        if ($nav !== []) {
            echo '<nav>';
            foreach ($nav as $item) {
                $current = ($item['current'] ?? false) ? ' aria-current="page"' : '';
                echo '<a href="' . e($item['href']) . '"' . $current . '>' . e($item['label']) . '</a>';
            }
            echo '</nav>';
        }
        echo '</header>' . "\n";
    }

    echo '<main class="wrap' . ($wide ? ' wrap-wide' : '') . '">' . "\n";
}

/**
 * 画面の末尾。読み込むスクリプトは1本でも配列でも渡せる（読み込む順に並べる）。
 *
 * @param string|list<string>|null $scripts
 */
function page_footer(string|array|null $scripts = null): void
{
    echo "</main>\n";
    foreach (is_array($scripts) ? $scripts : ($scripts === null ? [] : [$scripts]) as $scriptPath) {
        echo '<script src="' . e($scriptPath) . '"></script>' . "\n";
    }
    echo "</body>\n</html>\n";
}

/** 画面上部の通知 */
function render_alert(?array $flash): void
{
    if ($flash === null) {
        return;
    }
    $type = in_array((string) $flash['type'], ['error', 'success', 'info', 'warn'], true) ? $flash['type'] : 'info';
    echo '<div class="alert alert-' . e((string) $type) . '">' . e((string) $flash['message']) . '</div>';
}

/** 企業名・ロゴ・カラーの見出し */
function render_company_badge(array $company): void
{
    $color = (string) ($company['color'] ?? '');
    $valid = preg_match('/\A#[0-9a-fA-F]{6}\z/', $color) === 1;

    echo '<div class="company-badge">';
    if (($company['logo_url'] ?? null) !== null && (string) $company['logo_url'] !== '') {
        echo '<img src="' . e((string) $company['logo_url']) . '" alt="">';
    } else {
        echo '<span class="chip"' . ($valid ? ' style="background:' . e($color) . '"' : '') . '></span>';
    }
    echo '<strong>' . e((string) $company['name']) . '</strong>';
    echo '</div>';
}

/**
 * 設問1問ぶんの入力欄を描く。
 *
 * @param array<string, mixed> $question
 * @param array<int, mixed>    $previous 前回入力（エラー時の再表示用）
 */
function render_question(array $question, array $previous = [], bool $invalid = false): void
{
    $id       = (int) $question['id'];
    $type     = QuestionType::tryFromString((string) $question['type']);
    $required = (int) $question['required'] === 1;
    $name     = 'q[' . $id . ']';
    $old      = $previous[$id] ?? null;

    echo '<div class="question" data-question="' . $id . '"' . ($invalid ? ' data-invalid="1"' : '') . '>';
    echo '<fieldset>';
    echo '<legend>' . e((string) $question['label']);
    echo $required
        ? '<span class="badge badge-required">必須</span>'
        : '<span class="badge badge-optional">任意</span>';
    echo '</legend>';

    switch ($type) {
        case QuestionType::Single:
            foreach (question_options($question) as $i => $option) {
                $checked = is_string($old) && $old === $option ? ' checked' : '';
                echo '<label class="choice"><input type="radio" name="' . e($name) . '" value="' . e($option) . '"'
                    . $checked . ($required ? ' data-required="1"' : '') . '>';
                echo '<span>' . e($option) . '</span></label>';
            }
            break;

        case QuestionType::Multi:
            $selected = is_array($old) ? array_map('strval', $old) : [];
            foreach (question_options($question) as $option) {
                $checked = in_array($option, $selected, true) ? ' checked' : '';
                echo '<label class="choice"><input type="checkbox" name="' . e($name) . '[]" value="' . e($option) . '"'
                    . $checked . '>';
                echo '<span>' . e($option) . '</span></label>';
            }
            break;

        case QuestionType::Text:
            echo '<textarea name="' . e($name) . '" maxlength="' . TEXT_ANSWER_MAX_LENGTH . '"'
                . ' placeholder="ご自由にご記入ください">' . e(is_string($old) ? $old : '') . '</textarea>';
            break;

        case QuestionType::Rating:
            echo '<div class="rating">';
            for ($i = 1; $i <= RATING_MAX; $i++) {
                $checked = is_string($old) && (int) $old === $i ? ' checked' : '';
                echo '<label><input type="radio" name="' . e($name) . '" value="' . $i . '"' . $checked . '>'
                    . str_repeat('★', $i) . '<br><span class="muted">' . $i . '</span></label>';
            }
            echo '</div>';
            break;

        case QuestionType::Nps:
            echo '<div class="rating">';
            for ($i = 0; $i <= 10; $i++) {
                $checked = is_string($old) && $old !== '' && (int) $old === $i ? ' checked' : '';
                echo '<label><input type="radio" name="' . e($name) . '" value="' . $i . '"' . $checked . '>' . $i . '</label>';
            }
            echo '<div class="scale-ends"><span>0：まったく思わない</span><span>10：非常にそう思う</span></div>';
            echo '</div>';
            break;

        default:
            echo '<p class="field-error">この設問は表示できません。</p>';
    }

    echo '</fieldset></div>';
}
