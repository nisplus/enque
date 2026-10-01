<?php
declare(strict_types=1);

/**
 * スタッフ共有用のアンケートURL一覧（ログイン不要）。
 *
 *   /urls.php?t=<合言葉>
 *
 * 運用テストのときに、Slackなどへ長いURLを並べて貼らずに済むよう、
 * このページのリンク1本を共有すれば全社ぶんを開けるようにしている。
 *
 * 合言葉はイベントのスラグとは別の乱数で、ブースのQRコードからは推測できない。
 * 漏れたときは管理画面の「企業・QR」から作り直せる（古いリンクは開けなくなる）。
 * 検索エンジンに拾われないよう noindex を付ける。
 */

require_once dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/view.php';

$event = find_event_by_share_token((string) (get_string('t') ?? ''));
if ($event === null) {
    abort(404, 'このURLは無効です。事務局にお問い合わせください。');
}

$eventId   = (int) $event['id'];
$companies = companies_for_event($eventId, true);
$status    = (string) $event['status'];

header('X-Robots-Tag: noindex, nofollow');

page_header('アンケートURL一覧｜' . (string) $event['name'], ['brand' => (string) $event['name']]);

echo '<h1>アンケートURLの一覧</h1>';
echo '<p class="muted">' . e((string) $event['name']) . '（' . e(event_status_label($status)) . '）</p>';
echo '<p class="text-secondary">スタッフの動作確認用です。QRコードを読み取らなくても、';
echo 'ここから各' . e(booth_label()) . 'のアンケートを開けます。</p>';

if ($status === 'open') {
    echo '<div class="alert alert-warn">このイベントは<strong>開催中</strong>です。';
    echo 'ここからアンケートを開くと、回答しなくても<strong>「ユニーク来場者」が1人ぶん増えます</strong>。';
    echo '動作確認は準備中のうちに済ませるか、増えるぶんを見込んでご利用ください。</div>';
} else {
    echo '<div class="alert alert-info">このイベントは現在' . e(event_status_label($status)) . 'です。';
    echo 'アンケート画面は開けますが、回答は受け付けません（集計にも影響しません）。</div>';
}

$lines = [];
echo '<div class="card"><ul class="url-list">';
foreach ($companies as $company) {
    $url     = survey_url((string) $event['slug'], (string) $company['qr_slug']);
    $lines[] = (string) $company['name'] . "\n" . $url;

    echo '<li>';
    echo '<div class="url-name">' . e((string) $company['name']);
    if ((int) $company['is_active'] !== 1) {
        echo ' <span class="badge badge-optional">停止中</span>';
    }
    if (($company['booth_no'] ?? null) !== null && (string) $company['booth_no'] !== '') {
        echo ' <span class="muted">' . e(booth_label()) . ' ' . e((string) $company['booth_no']) . '</span>';
    }
    echo '</div>';
    echo '<a class="btn btn-small" href="' . e($url) . '" target="_blank" rel="noopener">アンケートを開く</a>';
    echo '<div class="mono muted" style="word-break:break-all">' . e($url) . '</div>';
    echo '</li>';
}
echo '</ul></div>';

if ($companies === []) {
    echo '<p class="muted">企業がまだ登録されていません。</p>';
}

// 貼り付け用のテキスト（Slackなどに流すとき）
echo '<details class="card">';
echo '<summary>テキストでコピーする</summary>';
echo '<textarea id="url-list" rows="6" readonly>' . e(implode("\n\n", $lines)) . '</textarea>';
echo '<div class="btn-row"><button type="button" class="btn" id="url-copy">まとめてコピーする</button>';
echo '<span class="muted" id="url-copied" hidden>コピーしました</span></div>';
echo '</details>';

echo '<p class="muted">このページのURLは、関係者以外に共有しないでください。';
echo '共有先を変えたいときは、事務局が管理画面からリンクを作り直せます。</p>';

page_footer('/assets/copy.js');
