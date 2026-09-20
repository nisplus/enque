<?php
declare(strict_types=1);

/**
 * 交換コードのQRコードを読み取ったときの入口。
 *
 *   /c/<交換コード>        （mod_rewrite 経由）
 *   /c.php?code=<交換コード>
 *
 * 読み取った人によって行き先を変える：
 *   - 総合受付・主催者としてログイン済み … 交換の照会画面（/admin/claim.php）
 *   - それ以外（来場者本人など）         … その人の回答済み画面（/done.php）
 *
 * 同じQRコードを、受付は「照会」に、来場者は「自分の控え」に使えるようにするため。
 */

require_once dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/view.php';

$code  = normalize_claim_code((string) (get_string('code') ?? ''));
$claim = $code === '' ? null : find_claim_by_code($code);

if ($claim === null) {
    http_response_code(404);
    page_header('交換コードが見つかりません');
    echo '<h1>交換コードが見つかりません</h1>';
    echo '<div class="alert alert-warn">読み取ったQRコードが古いか、URLが正しくないようです。</div>';
    echo '<p class="muted">お困りのときは総合受付のスタッフにお声がけください。</p>';
    page_footer();
    exit;
}

// スタッフ（受付・主催者）が読み取った場合は、そのまま照会画面へ
$admin = current_admin();
if ($admin !== null && in_array((string) $admin['role'], ['organizer', 'reception'], true)) {
    redirect('/admin/claim.php?code=' . rawurlencode($code));
}

$event = find_event((int) $claim['event_id']);
if ($event === null) {
    abort(404, 'ページが見つかりません。');
}

redirect('/done.php?e=' . rawurlencode((string) $event['slug']) . '&c=' . rawurlencode($code));
