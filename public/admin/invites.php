<?php
declare(strict_types=1);

/**
 * 総合アンケートと案内メールの管理（主催者）。
 *
 * 大量送信は cron（bin/send_overall_invites.php）で行う前提で、この画面からは
 * 準備・テスト送信・少量の送信・状況確認・メールアドレスの削除を行う。
 */

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';
require_once dirname(__DIR__, 2) . '/src/admin_view.php';
require_once dirname(__DIR__, 2) . '/src/invites.php';

$user   = require_organizer();
$events = all_events();

$eventId = (int) (get_string('event') ?? (post_string('event_id') ?? '0'));
$event   = $eventId > 0 ? find_event($eventId) : ($events[0] ?? null);
if ($event === null) {
    flash_set('error', '先にイベントを作成してください。');
    redirect('index.php');
}
$eventId = (int) $event['id'];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_valid_csrf();
    $action = (string) (post_string('action') ?? '');

    if ($action === 'create_overall') {
        if (overall_survey($eventId) === null) {
            $id = create_survey($eventId, null, 'overall', (string) $event['name'] . ' 総合アンケート', null, false);
            flash_set('success', '総合アンケートを作成しました。設問を登録してください。');
            redirect('survey_edit.php?survey=' . $id);
        }
        flash_set('error', '総合アンケートはすでに作成されています。');
    } elseif ($action === 'create_template') {
        if (template_survey($eventId) === null) {
            $id = create_survey($eventId, null, 'template', '共通設問テンプレート', null, false);
            flash_set('success', '共通設問テンプレートを作成しました。');
            redirect('survey_edit.php?survey=' . $id);
        }
        flash_set('error', '共通設問テンプレートはすでに作成されています。');
    } elseif ($action === 'prepare') {
        $added = create_pending_invites($eventId);
        flash_set('success', '案内メールの送信対象を' . $added . '件追加しました。');
    } elseif ($action === 'send_batch') {
        try {
            $result = send_pending_invites($eventId, 50);
            flash_set(
                $result['failed'] > 0 ? 'warn' : 'success',
                '送信しました：成功' . $result['sent'] . '件／失敗' . $result['failed'] . '件'
                . (mail_is_configured() ? '' : '（送信方式が未設定のため logs/mail-dryrun.log に書き出しました）')
            );
        } catch (Throwable $e) {
            flash_set('error', $e->getMessage());
        }
    } elseif ($action === 'test_mail') {
        $to = trim_ja((string) (post_string('test_email') ?? ''));
        if (!is_valid_email($to)) {
            flash_set('error', 'テスト送信先のメールアドレスが正しくありません。');
        } else {
            // 本番と同じ文面を送りつつ、リンクだけはスタッフが開けるプレビューにする
            // （来場者ごとのトークンはこの時点ではまだ発行されていないため）
            $mail = build_invite_mail($event, 'preview-' . $eventId);
            $body = "※ これは管理画面からのテスト送信です。以下のリンクはスタッフ確認用のプレビューで、\n"
                . "　 主催者としてログインした状態で開けます。来場者にはその方専用のURLが届きます。\n\n"
                . $mail['body'];
            try {
                send_mail($to, '[テスト] ' . $mail['subject'], $body);
                flash_set('success', mail_is_configured()
                    ? 'テストメールを送信しました（' . mail_transport_label() . '）。'
                    : '送信方式が未設定のため、logs/mail-dryrun.log に書き出しました。');
            } catch (Throwable $e) {
                flash_set('error', '送信に失敗しました：' . $e->getMessage());
            }
        }
    } elseif ($action === 'purge') {
        $result = purge_emails($eventId);
        flash_set('success', 'メールアドレスを削除しました（来場者' . $result['visitors'] . '件・案内' . $result['invites'] . '件）。');
    }

    redirect('invites.php?event=' . $eventId);
}

$overall  = overall_survey($eventId);
$template = template_survey($eventId);
$stats    = invite_stats($eventId);
$summary  = event_summary($eventId);

admin_page_header($user, '総合アンケート', 'invites.php');
render_alert(flash_take());

echo '<h1>総合アンケートと案内メール</h1>';
echo '<p class="muted">' . e((string) $event['name']) . '（' . e(event_status_label((string) $event['status'])) . '）</p>';

if (mail_is_configured()) {
    echo '<p class="muted">送信方式：' . e(mail_transport_label()) . '</p>';
} else {
    echo '<div class="alert alert-warn">メールの送信方式が設定されていません（.env の MAIL_TRANSPORT）。';
    echo 'この状態では実際には送信せず、メール内容を logs/mail-dryrun.log に書き出します。<br>';
    echo 'サーバーのpostfixで中継する場合は <code class="mono">MAIL_TRANSPORT=postfix</code>、';
    echo '外部SMTPに直接接続する場合は <code class="mono">MAIL_TRANSPORT=smtp</code> と接続情報を設定してください。</div>';
}

// ---- 1. 総合アンケート
echo '<div class="card"><h2 style="margin-top:0">1. 総合アンケートを用意する</h2>';
if ($overall === null) {
    echo '<p>まだ作成されていません。</p>';
    echo '<form method="post">' . csrf_field();
    echo '<input type="hidden" name="action" value="create_overall">';
    echo '<input type="hidden" name="event_id" value="' . $eventId . '">';
    echo '<div class="btn-row"><button type="submit" class="btn btn-primary">総合アンケートを作成する</button></div>';
    echo '</form>';
} else {
    echo '<p>' . e((string) $overall['title']) . ' <span class="badge badge-'
        . ((int) $overall['is_published'] === 1 ? 'good">公開中' : 'warn">非公開') . '</span></p>';
    echo '<p class="muted">回答数：' . count_label($summary['overall_responses']) . '</p>';
    echo '<div class="btn-row">';
    echo '<a class="btn" href="survey_edit.php?survey=' . (int) $overall['id'] . '">設問を編集する</a>';
    echo '<a class="btn" href="responses.php?survey=' . (int) $overall['id'] . '">回答一覧</a>';
    echo '<a class="btn" href="export_csv.php?survey=' . (int) $overall['id'] . '">CSV</a>';
    echo '</div>';
}
echo '</div>';

// ---- 2. 送信対象
echo '<div class="card"><h2 style="margin-top:0">2. 送信対象を確定する</h2>';
echo '<p>メールアドレスを登録した来場者：' . count_label($summary['emails'], '人') . '</p>';
echo '<div class="stat-grid">';
render_stat('未送信', count_label($stats['pending']));
render_stat('送信済み', count_label($stats['sent']));
render_stat('失敗', count_label($stats['failed']));
render_stat('回答済み', count_label($stats['responded']));
echo '</div>';
echo '<form method="post">' . csrf_field();
echo '<input type="hidden" name="action" value="prepare">';
echo '<input type="hidden" name="event_id" value="' . $eventId . '">';
echo '<div class="btn-row"><button type="submit" class="btn">送信対象を作成・更新する</button></div>';
echo '</form>';
echo '<p class="muted">メールアドレスを登録した来場者のうち、まだ送信対象になっていない人を追加します。</p>';
echo '</div>';

// ---- 3. 送信
echo '<div class="card"><h2 style="margin-top:0">3. 送信する</h2>';
echo '<p class="muted">大量送信はサーバーの cron で行ってください：';
echo '<code class="mono">php bin/send_overall_invites.php --event=' . $eventId . ' --limit=200</code></p>';

echo '<form method="post" onsubmit="return confirm(\'未送信ぶんを最大50件送信します。よろしいですか？\');">' . csrf_field();
echo '<input type="hidden" name="action" value="send_batch">';
echo '<input type="hidden" name="event_id" value="' . $eventId . '">';
echo '<div class="btn-row"><button type="submit" class="btn btn-primary">この画面から50件だけ送信する</button></div>';
echo '</form>';

echo '<form method="post">' . csrf_field();
echo '<input type="hidden" name="action" value="test_mail">';
echo '<input type="hidden" name="event_id" value="' . $eventId . '">';
echo '<label class="field" for="test_email">テスト送信先</label>';
echo '<input type="email" id="test_email" name="test_email" placeholder="自分のアドレス">';
echo '<div class="btn-row"><button type="submit" class="btn btn-small">テスト送信</button></div>';
echo '</form>';
echo '</div>';

// ---- 4. 個人情報の削除
echo '<div class="card"><h2 style="margin-top:0">4. メールアドレスを削除する</h2>';
echo '<p>案内の送付と集計が終わったら、預かったメールアドレスを削除します。';
echo '削除後も回答データ（個人情報を含まない）は残ります。</p>';
echo '<p class="muted">削除すると、未送信ぶんの案内メールは送れなくなります。</p>';
echo '<form method="post" onsubmit="return confirm(\'登録されたメールアドレスをすべて削除します。元に戻せません。よろしいですか？\');">' . csrf_field();
echo '<input type="hidden" name="action" value="purge">';
echo '<input type="hidden" name="event_id" value="' . $eventId . '">';
echo '<div class="btn-row"><button type="submit" class="btn btn-danger">メールアドレスを削除する</button></div>';
echo '</form>';
echo '</div>';

// ---- 共通設問テンプレート
echo '<div class="card"><h2 style="margin-top:0">共通設問テンプレート</h2>';
if ($template === null) {
    echo '<p class="muted">全社で共通して聞きたい設問（来場目的・所属業界など）を登録しておくと、';
    echo '各企業のアンケート編集画面から取り込めます。</p>';
    echo '<form method="post">' . csrf_field();
    echo '<input type="hidden" name="action" value="create_template">';
    echo '<input type="hidden" name="event_id" value="' . $eventId . '">';
    echo '<div class="btn-row"><button type="submit" class="btn">テンプレートを作成する</button></div>';
    echo '</form>';
} else {
    echo '<p>' . e((string) $template['title']) . '（'
        . count_label(count(questions_for_survey((int) $template['id'])), '問') . '）</p>';
    echo '<div class="btn-row"><a class="btn" href="survey_edit.php?survey=' . (int) $template['id'] . '">編集する</a></div>';
}
echo '</div>';

page_footer();
