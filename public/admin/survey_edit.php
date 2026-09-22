<?php
declare(strict_types=1);

/**
 * アンケート（設問）の編集。
 *
 * 企業アンケート・総合アンケート・共通設問テンプレートのいずれも、この画面で編集する。
 * JavaScriptに依存せず、設問の追加・削除・並べ替えはすべてサーバー側で処理する。
 */

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';
require_once dirname(__DIR__, 2) . '/src/admin_view.php';

$user     = require_admin();
$surveyId = (int) (get_string('survey') ?? (post_string('survey_id') ?? '0'));
$survey   = $surveyId > 0 ? find_survey($surveyId) : null;
if ($survey === null) {
    abort(404, 'ページが見つかりません。');
}

$company = $survey['company_id'] === null ? null : find_company((int) $survey['company_id']);
if ($company !== null) {
    assert_company_access($user, (int) $company['id']);
} elseif ((string) $user['role'] !== 'organizer') {
    // 総合アンケート・テンプレートは主催者のみ
    abort(404, 'ページが見つかりません。');
}

$event = find_event((int) $survey['event_id']);

/**
 * 送信された設問をパースする。
 *
 * @return list<array{id: ?int, type: string, label: string, options: list<string>, required: bool, sort: int}>
 */
function parse_posted_questions(): array
{
    $posted = $_POST['q'] ?? [];
    if (!is_array($posted)) {
        return [];
    }

    $questions = [];
    foreach ($posted as $index => $row) {
        if (!is_array($row) || ($row['delete'] ?? '') === '1') {
            continue;
        }
        $label = trim_ja(strip_control_chars((string) ($row['label'] ?? '')));
        $type  = (string) ($row['type'] ?? 'single');
        if (QuestionType::tryFrom($type) === null) {
            $type = 'single';
        }
        if ($label === '') {
            continue; // 設問文が空の行は無視する（追加直後の空欄など）
        }

        $options = QuestionType::from($type)->hasOptions()
            ? parse_options_text((string) ($row['options'] ?? ''))
            : [];
        // 数値入力は同じ欄を「min=1 / max=99 / unit=人」の設定として読む
        $number = $type === 'number' ? parse_number_settings_text((string) ($row['options'] ?? '')) : null;

        $questions[] = [
            'id'       => isset($row['id']) && (int) $row['id'] > 0 ? (int) $row['id'] : null,
            'type'     => $type,
            'label'    => mb_substr($label, 0, 500),
            'options'  => $options,
            'number'   => $number,
            'required' => ($row['required'] ?? '') === '1',
            // 来場人数として集計するかどうか（数値入力のときだけ有効）
            'metric'   => $type === 'number' && ($row['metric'] ?? '') === 'party_size' ? 'party_size' : 'none',
            'sort'     => (int) ($row['sort'] ?? $index),
        ];
    }

    usort($questions, static fn(array $a, array $b): int => $a['sort'] <=> $b['sort']);

    return $questions;
}

$addBlank = false;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_valid_csrf();
    $action = (string) (post_string('action') ?? 'save');

    if ($action === 'import_template') {
        $template = template_survey((int) $survey['event_id']);
        if ($template === null || (int) $template['id'] === $surveyId) {
            flash_set('error', '取り込める共通設問テンプレートがありません。');
        } else {
            $added = copy_questions((int) $template['id'], $surveyId);
            flash_set('success', '共通設問を' . $added . '問取り込みました。');
        }
        redirect('survey_edit.php?survey=' . $surveyId);
    }

    $title     = trim_ja((string) (post_string('title') ?? ''));
    $desc      = trim_ja(strip_control_chars((string) (post_string('description') ?? '')));
    $published = (post_string('is_published') ?? '') === '1';
    $questions = parse_posted_questions();

    // 選択肢が1つも無い選択式の設問は回答できないため保存させない
    $invalid = null;
    foreach ($questions as $q) {
        if (QuestionType::from($q['type'])->hasOptions() && count($q['options']) < 2) {
            $invalid = $q['label'];
            break;
        }
    }

    if ($title === '') {
        flash_set('error', 'アンケートのタイトルを入力してください。');
    } elseif ($invalid !== null) {
        flash_set('error', '「' . $invalid . '」の選択肢を2つ以上入力してください（1行に1つ）。');
    } elseif ($published && $questions === []) {
        flash_set('error', '設問が1問も無いアンケートは公開できません。');
    } else {
        update_survey($surveyId, mb_substr($title, 0, 255), $desc !== '' ? $desc : null, $published);
        replace_questions($surveyId, array_map(
            static fn(array $q): array => [
                'id'       => $q['id'],
                'type'     => $q['type'],
                'label'    => $q['label'],
                'options'  => $q['options'],
                'number'   => $q['number'],
                'required' => $q['required'],
                'metric'   => $q['metric'],
            ],
            $questions
        ));
        flash_set('success', $action === 'save_add' ? '保存しました。続けて設問を入力してください。' : 'アンケートを保存しました。');

        // 「保存して設問を追加」では、画面の先頭ではなく追加された設問の位置に戻す
        redirect('survey_edit.php?survey=' . $surveyId . ($action === 'save_add' ? '&add=1#q-new' : ''));
    }
    // エラー時はこのまま再表示する（入力値はDBから読み直す）
}

$addBlank  = (get_string('add') ?? '') === '1';
$questions = questions_for_survey($surveyId);
$template  = template_survey((int) $survey['event_id']);

$typeLabel = (string) $survey['type'];
$heading   = match ($typeLabel) {
    'overall'  => '総合アンケート',
    'template' => '共通設問テンプレート',
    default    => (string) ($company['name'] ?? '') . ' のアンケート',
};

admin_page_header($user, 'アンケート編集', 'index.php');
render_alert(flash_take());

echo '<h1>' . e($heading) . '</h1>';
echo '<p class="muted">' . e((string) ($event['name'] ?? '')) . '</p>';

if ($typeLabel === 'template') {
    echo '<div class="alert alert-info">このテンプレートは回答を受け付けません。各企業のアンケート編集画面から';
    echo '「共通設問を取り込む」で複製して使います。</div>';
}

echo '<form method="post">' . csrf_field();
echo '<input type="hidden" name="survey_id" value="' . $surveyId . '">';

echo '<div class="card">';
echo '<label class="field">タイトル<input type="text" name="title" value="' . e((string) $survey['title']) . '" required></label>';
echo '<label class="field">説明文（任意）<span class="hint">回答画面の冒頭に表示します。</span>';
echo '<textarea name="description">' . e((string) ($survey['description'] ?? '')) . '</textarea></label>';

if ($typeLabel !== 'template') {
    $checked = (int) $survey['is_published'] === 1 ? ' checked' : '';
    echo '<label class="choice"><input type="checkbox" name="is_published" value="1"' . $checked . '>';
    echo '<span>公開する（回答を受け付ける）</span></label>';
}
echo '</div>';

echo '<h2>設問（' . count($questions) . '問）</h2>';

$index = 0;

/**
 * 設問1問ぶんの編集欄。
 *
 * 各ブロックに id を振り、「保存して設問を追加」の直後は #q-new へ飛ばして
 * 画面の先頭ではなく追加された設問が見えるようにする（$isNew のときは入力欄に
 * カーソルも当てる）。
 */
$render = static function (?array $question, int $index, bool $isNew = false): void {
    $id       = $question === null ? 0 : (int) $question['id'];
    $type     = $question === null ? 'single' : (string) $question['type'];
    $label    = $question === null ? '' : (string) $question['label'];
    $required = $question !== null && (int) $question['required'] === 1;
    $options  = $question === null ? [] : question_options($question);
    $anchor   = $isNew ? 'q-new' : 'q-' . ($index + 1);

    echo '<div class="q-editor" id="' . $anchor . '">';
    echo '<div class="q-head"><strong>設問 ' . ($index + 1) . ($isNew ? '（新規）' : '') . '</strong>';
    if ($id > 0) {
        echo '<label class="muted"><input type="checkbox" name="q[' . $index . '][delete]" value="1"> 削除する</label>';
    }
    echo '</div>';
    echo '<input type="hidden" name="q[' . $index . '][id]" value="' . $id . '">';

    echo '<div class="q-grid">';
    echo '<label class="field">設問文<input type="text" name="q[' . $index . '][label]" value="' . e($label) . '"'
        . ($isNew ? ' autofocus' : '') . '></label>';
    echo '<label class="field">種類<select name="q[' . $index . '][type]">';
    foreach (QuestionType::cases() as $case) {
        $selected = $case->value === $type ? ' selected' : '';
        echo '<option value="' . $case->value . '"' . $selected . '>' . e($case->label()) . '</option>';
    }
    echo '</select></label>';
    echo '</div>';

    // 数値入力のときは、同じ欄を「min= / max= / unit=」の設定として使う
    $optionText = $type === 'number' && $question !== null
        ? implode("\n", array_map(
            static fn(string $k, $v): string => $k . '=' . $v,
            array_keys(number_settings($question)),
            array_values(number_settings($question))
        ))
        : implode("\n", $options);

    echo '<label class="field">選択肢／数値の設定';
    echo '<span class="hint">単一選択・複数選択は1行に1つの選択肢。';
    echo '数値入力のときは <code class="mono">min=1</code> <code class="mono">max=99</code> ';
    echo '<code class="mono">unit=人</code> のように書きます（省略時は 1〜99・単位「人」）。</span>';
    echo '<textarea name="q[' . $index . '][options]" rows="4">' . e($optionText) . '</textarea></label>';

    echo '<div class="q-grid">';
    echo '<label class="choice"><input type="checkbox" name="q[' . $index . '][required]" value="1"'
        . ($required ? ' checked' : '') . '><span>必須にする</span></label>';
    echo '<label class="field">並び順<input type="number" name="q[' . $index . '][sort]" value="' . ($index + 1) . '"></label>';
    echo '</div>';

    // 来場人数として集計する設問の指定（数値入力のときだけ効く）
    $isParty = $question !== null && is_party_size_question($question);
    echo '<label class="choice"><input type="checkbox" name="q[' . $index . '][metric]" value="party_size"'
        . ($isParty ? ' checked' : '') . '>';
    echo '<span>この回答を<strong>来場人数</strong>として集計する'
        . '<span class="hint">「何人で来られましたか」のような設問に付けます（種類が「数値入力」のときだけ有効）。'
        . '回答画面では <strong>1〜' . party_size_max() . '人以上の選択式</strong>になり、'
        . 'ダッシュボードの「のべ来場者」に足し上げられます。<br>'
        . '<strong>2社目以降は、前の企業で答えた人数が初期値として入ります</strong>'
        . '（同じスマホ・同じブラウザの場合）。実質的に入力は最初の1社だけで済むため、'
        . '必須にしても来場者の手間はほとんど増えません。<br>'
        . '未回答のぶんは「同じ来場者が別の企業で答えた人数 → それも無ければ全体の平均」の順に補って集計します。'
        . '</span></span></label>';

    echo '</div>';
};

foreach ($questions as $question) {
    $render($question, $index);
    $index++;
}

// 「保存して設問を追加」で空欄を1つ出す。初回（設問ゼロ）も空欄を出す
if ($addBlank || $questions === []) {
    $render(null, $index, true);
}

echo '<div class="btn-row">';
echo '<button type="submit" name="action" value="save" class="btn btn-primary">保存する</button>';
echo '<button type="submit" name="action" value="save_add" class="btn">保存して設問を追加</button>';
echo '</div>';
echo '<p class="muted">設問文が空のまま保存すると、その行は登録されません。';
echo '既存の設問を削除すると、その設問への回答も一緒に削除されます。</p>';
echo '</form>';

if ($template !== null && (int) $template['id'] !== $surveyId) {
    echo '<div class="card"><h2 style="margin-top:0">共通設問を取り込む</h2>';
    echo '<p class="muted">「' . e((string) $template['title']) . '」の設問を、このアンケートの末尾に複製します。</p>';
    echo '<form method="post" onsubmit="return confirm(\'共通設問をこのアンケートに追加します。よろしいですか？\');">' . csrf_field();
    echo '<input type="hidden" name="survey_id" value="' . $surveyId . '">';
    echo '<input type="hidden" name="action" value="import_template">';
    echo '<div class="btn-row"><button type="submit" class="btn">共通設問を取り込む</button></div>';
    echo '</form></div>';
}

echo '<div class="btn-row">';
if ($company !== null) {
    echo '<a class="btn" href="company_stats.php?company=' . (int) $company['id'] . '">集計を見る</a>';
}
echo '<a class="btn" href="index.php">ダッシュボードへ</a>';
echo '</div>';

page_footer();
