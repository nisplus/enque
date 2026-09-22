<?php
declare(strict_types=1);

/**
 * 動作確認用のデモデータを作る（CLI専用）。
 *
 *   php bin/seed_demo.php [--force]
 *
 * イベント1件・企業3社・共通設問テンプレート・総合アンケートを登録する。
 * 既存データは消さないが、開発用DBでのみ実行すること。
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("このスクリプトはコマンドラインからのみ実行できます。\n");
}

require_once dirname(__DIR__) . '/src/bootstrap.php';

if (!in_array('--force', array_slice($argv, 1), true)) {
    echo "デモ用のイベント・企業・アンケートを追加します。開発用DBでのみ実行してください：\n";
    echo "  php bin/seed_demo.php --force\n";
    exit(1);
}

$eventId = create_event('デモ合同説明会 2026', date('Y-m-d'), date('Y-m-d'), 'open');
$event   = find_event($eventId);
echo "イベントを作成しました：{$event['name']}（slug={$event['slug']}）\n";

// 共通設問テンプレート
$templateId = create_survey($eventId, null, 'template', '共通設問テンプレート', null, false);
replace_questions($templateId, [
    ['id' => null, 'type' => 'single', 'label' => '本日の来場目的を教えてください', 'options' => ['情報収集', '就職活動', '取引先の開拓', 'その他'], 'required' => true],
    ['id' => null, 'type' => 'single', 'label' => 'ご所属の業界を教えてください', 'options' => ['製造', 'IT・通信', '流通・小売', '金融', '学生', 'その他'], 'required' => false],
]);

// 企業3社とアンケート
$companyNames = ['株式会社アオゾラ製作所', 'ミドリソフト株式会社', '有限会社ハマナス商会'];
foreach ($companyNames as $i => $name) {
    $companyId = create_company($eventId, $name, 'A-' . ($i + 1), ['#2a78d6', '#1baf7a', '#eb6834'][$i], null);
    $company   = find_company($companyId);
    $survey    = ensure_company_survey($company);
    $surveyId  = (int) $survey['id'];

    copy_questions($templateId, $surveyId);
    $existing = questions_for_survey($surveyId);
    $questions = [];
    foreach ($existing as $q) {
        $questions[] = [
            'id'       => (int) $q['id'],
            'type'     => (string) $q['type'],
            'label'    => (string) $q['label'],
            'options'  => question_options($q),
            'required' => (int) $q['required'] === 1,
        ];
    }
    $questions[] = ['id' => null, 'type' => 'rating', 'label' => '当社ブースの説明はいかがでしたか', 'options' => [], 'required' => true];
    $questions[] = ['id' => null, 'type' => 'multi', 'label' => '興味を持った内容はどれですか（複数選択可）', 'options' => ['製品デモ', '事業説明', '採用情報', 'ノベルティ'], 'required' => false];
    $questions[] = ['id' => null, 'type' => 'nps', 'label' => '当社を知人にすすめたいと思いますか', 'options' => [], 'required' => false];
    $questions[] = ['id' => null, 'type' => 'text', 'label' => 'ご意見・ご感想があればお聞かせください', 'options' => [], 'required' => false];

    replace_questions($surveyId, $questions);
    update_survey($surveyId, $name . ' アンケート', 'ご来場ありがとうございます。差し支えなければご回答ください。', true);

    $url = survey_url((string) $event['slug'], (string) $company['qr_slug']);
    echo "企業を作成しました：{$name}\n  {$url}\n";
}

// 総合アンケート
$overallId = create_survey($eventId, null, 'overall', 'デモ合同説明会 2026 総合アンケート', 'イベント全体についてお聞かせください。', true);
replace_questions($overallId, [
    ['id' => null, 'type' => 'rating', 'label' => 'イベント全体の満足度を教えてください', 'options' => [], 'required' => true],
    ['id' => null, 'type' => 'multi', 'label' => '来場のきっかけを教えてください（複数選択可）', 'options' => ['Webサイト', 'SNS', '知人の紹介', 'DM・チラシ'], 'required' => false],
    ['id' => null, 'type' => 'text', 'label' => '次回に向けてのご意見をお聞かせください', 'options' => [], 'required' => false],
]);
echo "総合アンケートを作成しました。\n";

// 景品（総合受付が交換時に選ぶ）
create_prize($eventId, 'オリジナルトートバッグ', 100, '先着100名');
create_prize($eventId, 'ボールペン', 300, null);
create_prize($eventId, 'パンフレット', null, '数量は管理しない');
echo "景品を3件登録しました。\n";

if (admin_user_count() === 0) {
    echo "\n管理ユーザーがまだありません。/admin/setup.php で主催者アカウントを作成してください。\n";
}
