<?php
declare(strict_types=1);

/**
 * トップページ。
 *
 * 来場者はブースのQRコードから直接アンケートURLに入るため、ここには
 * 企業一覧を出さない（他社のアンケートURLを推測されにくくするため）。
 */

require_once dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/view.php';

page_header('アンケートのご案内');

echo '<h1>ブースのQRコードを読み取ってください</h1>';
echo '<div class="card">';
echo '<p>各企業ブースに掲示されているQRコードを読み取ると、そのブースのアンケートが開きます。</p>';
echo '<p>ご回答後に表示される<strong>交換コード</strong>を総合受付でご提示ください。</p>';
echo '<p class="muted">アンケートに氏名・住所などの入力は必要ありません。メールアドレスのみ任意でお伺いし、';
echo 'イベント終了後の全体アンケートのご案内に使用します（案内・集計の完了後に削除します）。</p>';
echo '</div>';

echo '<p class="muted"><a href="/admin/login.php">運営・企業ご担当者のログイン</a></p>';

page_footer();
