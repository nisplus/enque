<?php
declare(strict_types=1);

/**
 * QRコード画像の出力。
 *
 *   ?company=<id>[&format=svg|png][&size=<1モジュールのpx>]
 *
 * GD拡張が無い環境ではPNGを作れないため、その場合はSVGを返す
 * （SVGは印刷時に劣化せず、そのまま入稿にも使える）。
 */

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';
require_once dirname(__DIR__, 2) . '/src/qrcode.php';

$user      = require_admin();
$companyId = (int) (get_string('company') ?? '0');
$company   = $companyId > 0 ? find_company($companyId) : null;
if ($company === null) {
    abort(404, 'ページが見つかりません。');
}
assert_company_access($user, $companyId);

$event = find_event((int) $company['event_id']);
if ($event === null) {
    abort(404, 'ページが見つかりません。');
}

$url    = survey_url((string) $event['slug'], (string) $company['qr_slug']);
$size   = min(20, max(2, (int) (get_string('size') ?? '8')));
$format = (get_string('format') ?? 'svg') === 'png' ? 'png' : 'svg';
$base   = preg_replace('/[^0-9A-Za-z_-]/', '_', (string) $company['qr_slug']) ?? 'qr';

if ($format === 'png') {
    $png = qr_png($url, $size, 4);
    if ($png !== null) {
        header('Content-Type: image/png');
        header('Content-Disposition: attachment; filename="qr_' . $base . '.png"');
        header('Cache-Control: private, no-store');
        echo $png;
        exit;
    }
    // GDが無い環境ではSVGにフォールバックする
}

header('Content-Type: image/svg+xml; charset=UTF-8');
header('Content-Disposition: inline; filename="qr_' . $base . '.svg"');
header('Cache-Control: private, no-store');
echo qr_svg($url, $size, 4);
