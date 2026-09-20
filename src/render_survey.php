<?php
declare(strict_types=1);

require_once __DIR__ . '/view.php';

/**
 * アンケート回答フォームの描画。
 *
 * 企業アンケート（s.php）・全体アンケート（o.php）・入力エラーでの再表示
 * （submit.php）の3か所から呼ぶ。エラー時は入力済みの内容をそのまま埋め戻すので、
 * JavaScriptが無効な端末でも入力し直しにならない。
 *
 * @param array{
 *   event: array<string,mixed>,
 *   company: ?array<string,mixed>,
 *   survey: array<string,mixed>,
 *   questions: list<array<string,mixed>>,
 *   hidden: array<string,string>,
 *   ask_email: bool,
 *   previous: array<int,mixed>,
 *   invalid: list<int>,
 *   error: ?string,
 *   email_value: string,
 *   preview?: bool,
 *   footer_note: ?string
 * } $view
 */
function render_survey_page(array $view): void
{
    $event     = $view['event'];
    $company   = $view['company'];
    $survey    = $view['survey'];
    $questions = $view['questions'];
    $preview   = ($view['preview'] ?? false) === true;

    $title = (string) $survey['title'] . '｜' . (string) $event['name'];
    page_header($title, ['brand' => (string) $event['name']]);

    if ($company !== null) {
        render_company_badge($company);
    }
    echo '<h1>' . e((string) $survey['title']) . '</h1>';

    if (($survey['description'] ?? null) !== null && (string) $survey['description'] !== '') {
        echo '<p class="text-secondary">' . nl2br(e((string) $survey['description'])) . '</p>';
    }

    if ($view['error'] !== null) {
        echo '<div class="alert alert-error">' . e($view['error']) . '</div>';
    }

    if ($preview) {
        echo '<div class="alert alert-warn">スタッフ確認用のプレビューです。';
        echo 'この画面からは送信できません（回答は保存されません）。</div>';
    }

    echo '<div class="progress"><span id="progress-text">' . count($questions) . '問中 0問に回答</span>';
    echo '<span class="progress-bar"><span id="progress-bar-fill"></span></span></div>';

    echo '<div class="alert alert-info" id="draft-notice" hidden>前回入力した内容を復元しました。</div>';
    echo '<div class="alert alert-error" id="form-error" hidden></div>';

    echo '<form method="post" action="/submit.php" id="survey-form" data-storage-key="enque-draft-'
        . (int) $survey['id'] . '" novalidate>';
    foreach ($view['hidden'] as $name => $value) {
        echo '<input type="hidden" name="' . e($name) . '" value="' . e($value) . '">';
    }

    foreach ($questions as $question) {
        render_question($question, $view['previous'], in_array((int) $question['id'], $view['invalid'], true));
    }

    if ($view['ask_email']) {
        echo '<div class="card">';
        echo '<label class="field" for="email">メールアドレス（任意）';
        echo '<span class="hint">イベント終了後に「全体アンケート」のご案内をお送りします。';
        echo 'ご回答いただくと、オリジナルのスマホ壁紙をダウンロードできます。';
        echo '入力は任意で、他の個人情報はお伺いしません。</span></label>';
        echo '<input type="email" id="email" name="email" autocomplete="email" inputmode="email" '
            . 'value="' . e($view['email_value']) . '" placeholder="example@example.jp">';
        echo '</div>';
    }

    echo '<div class="btn-row">';
    if ($preview) {
        echo '<button type="button" class="btn btn-block" disabled aria-disabled="true">'
            . 'プレビューのため送信できません</button>';
    } else {
        echo '<button type="submit" class="btn btn-primary btn-block" data-submit data-label="回答を送信する">回答を送信する</button>';
    }
    echo '</div>';
    if ($view['footer_note'] !== null) {
        echo '<p class="muted">' . e($view['footer_note']) . '</p>';
    }
    echo '</form>';

    page_footer('/assets/app.js');
}
