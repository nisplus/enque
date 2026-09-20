<?php
declare(strict_types=1);

/**
 * ブース掲示用のQRコード印刷ページ。
 *
 *   ?event=<id>    … 全社分をまとめて
 *   ?company=<id>  … 1社分
 *
 * ブラウザの「印刷 → PDFとして保存」でPDFにできる（PDFライブラリを持たない構成のため、
 * 変換はブラウザに任せる）。A4縦で2列に並ぶよう印刷用のスタイルを当てている。
 */

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';
require_once dirname(__DIR__, 2) . '/src/admin_view.php';
require_once dirname(__DIR__, 2) . '/src/qrcode.php';

$user      = require_admin();
$companyId = (int) (get_string('company') ?? '0');
$eventId   = (int) (get_string('event') ?? '0');

if ($companyId > 0) {
    $company = find_company($companyId);
    if ($company === null) {
        abort(404, 'ページが見つかりません。');
    }
    assert_company_access($user, $companyId);
    $event     = find_event((int) $company['event_id']);
    $companies = [$company];
} else {
    require_organizer();
    $event = $eventId > 0 ? find_event($eventId) : (all_events()[0] ?? null);
    if ($event === null) {
        abort(404, 'ページが見つかりません。');
    }
    $companies = companies_for_event((int) $event['id']);
}

admin_page_header($user, 'QRコード印刷', 'companies.php');

echo '<h1 class="no-print">QRコードの印刷</h1>';
echo '<p class="no-print muted">ブラウザの印刷機能で「PDFとして保存」を選ぶとPDFになります。';
echo 'A4縦・余白は既定のままで、1ページに4枚並びます。</p>';
echo '<div class="btn-row no-print">';
echo '<button type="button" class="btn btn-primary" onclick="window.print()">印刷する</button>';
echo '<a class="btn" href="companies.php?event=' . (int) $event['id'] . '">企業一覧へ戻る</a>';
echo '</div>';

echo '<div class="qr-sheet">';
foreach ($companies as $company) {
    $url = survey_url((string) $event['slug'], (string) $company['qr_slug']);
    echo '<div class="qr-card">';
    echo '<div class="qr-name">' . e((string) $company['name']) . '</div>';
    if (($company['booth_no'] ?? null) !== null && (string) $company['booth_no'] !== '') {
        echo '<div class="muted">ブース ' . e((string) $company['booth_no']) . '</div>';
    }
    echo qr_svg($url, 4, 2);
    echo '<div style="font-weight:600">アンケートにご協力ください</div>';
    echo '<div class="qr-url">' . e($url) . '</div>';
    echo '</div>';
}
echo '</div>';

if ($companies === []) {
    echo '<p class="muted">企業が登録されていません。</p>';
}

page_footer();
