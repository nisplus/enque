<?php
declare(strict_types=1);

/**
 * 壁紙の登録（主催者）。
 *
 * 画像の実体は DocumentRoot の外（storage/wallpapers/）に保存し、
 * 配信は wallpaper_file.php（来場者向け）と wallpaper_preview.php（管理画面）を通す。
 */

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';
require_once dirname(__DIR__, 2) . '/src/admin_view.php';

$user   = require_organizer();
$events = all_events();

$eventId = (int) (get_string('event') ?? (post_string('event_id') ?? '0'));
$event   = $eventId > 0 ? find_event($eventId) : ($events[0] ?? null);
if ($event === null) {
    flash_set('error', '先にイベントを作成してください。');
    redirect('index.php');
}
$eventId = (int) $event['id'];

/** アップロードを受け取って保存する。失敗時はエラーメッセージを返す */
function store_uploaded_wallpaper(int $eventId, ?int $companyId, string $title): ?string
{
    $file = $_FILES['image'] ?? null;
    if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return '画像ファイルを選択してください。';
    }
    if ((int) $file['error'] !== UPLOAD_ERR_OK) {
        return 'アップロードに失敗しました（サーバーの上限を超えている可能性があります）。';
    }
    if ((int) $file['size'] > 12 * 1024 * 1024) {
        return 'ファイルサイズは12MBまでにしてください。';
    }
    if (!is_uploaded_file((string) $file['tmp_name'])) {
        return 'アップロードに失敗しました。';
    }

    // 拡張子ではなく中身で判定する
    $info = @getimagesize((string) $file['tmp_name']);
    if ($info === false) {
        return '画像として読み取れませんでした（PNG または JPEG を指定してください）。';
    }
    $mime = (string) ($info['mime'] ?? '');
    $ext  = match ($mime) {
        'image/png'  => 'png',
        'image/jpeg' => 'jpg',
        default      => null,
    };
    if ($ext === null) {
        return 'PNG または JPEG の画像を指定してください。';
    }

    $dir = config()['storage_dir'];
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return '保存先ディレクトリを作成できませんでした：' . $dir;
    }
    if (!is_writable($dir)) {
        return '保存先ディレクトリに書き込めません：' . $dir;
    }

    $fileName = 'wp_' . bin2hex(random_bytes(8)) . '.' . $ext;
    if (!move_uploaded_file((string) $file['tmp_name'], $dir . '/' . $fileName)) {
        return 'ファイルの保存に失敗しました。';
    }

    insert_wallpaper($eventId, $companyId, $title, $fileName, $mime, (int) $info[0], (int) $info[1]);

    return null;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_valid_csrf();
    $action = (string) (post_string('action') ?? '');

    if ($action === 'upload') {
        $title     = trim_ja((string) (post_string('title') ?? ''));
        $companyId = (int) (post_string('company_id') ?? '0');
        $company   = $companyId > 0 ? find_company($companyId) : null;
        if ($company !== null && (int) $company['event_id'] !== $eventId) {
            abort(404, 'ページが見つかりません。');
        }

        if ($title === '') {
            flash_set('error', 'タイトルを入力してください。');
        } else {
            $error = store_uploaded_wallpaper($eventId, $company === null ? null : $companyId, mb_substr($title, 0, 255));
            if ($error !== null) {
                flash_set('error', $error);
            } else {
                flash_set('success', '壁紙を登録しました。');
            }
        }
    } elseif ($action === 'delete') {
        $wallpaperId = (int) (post_string('wallpaper_id') ?? '0');
        $wallpaper   = find_wallpaper($wallpaperId);
        if ($wallpaper === null || (int) $wallpaper['event_id'] !== $eventId) {
            abort(404, 'ページが見つかりません。');
        }
        $path = config()['storage_dir'] . '/' . basename((string) $wallpaper['file_name']);
        delete_wallpaper($wallpaperId);
        if (is_file($path)) {
            @unlink($path);
        }
        flash_set('success', '壁紙を削除しました。');
    }

    redirect('wallpapers.php?event=' . $eventId);
}

$wallpapers = db()->prepare('SELECT * FROM wallpapers WHERE event_id = ? ORDER BY company_id IS NOT NULL, sort_order, id');
$wallpapers->execute([$eventId]);
$wallpapers = $wallpapers->fetchAll();
$companies  = companies_for_event($eventId);

admin_page_header($user, '壁紙', 'wallpapers.php');
render_alert(flash_take());

echo '<h1>スマホ壁紙の登録</h1>';
echo '<p class="muted">' . e((string) $event['name']) . '</p>';
echo '<div class="alert alert-info">壁紙は「総合アンケート」に回答した来場者だけがダウンロードできます。';
echo '端末に合わせて選べるよう、縦長（例：1080×2340）を1〜2種類登録しておくことをおすすめします。</div>';

echo '<div class="card"><h2 style="margin-top:0">壁紙を追加する</h2>';
echo '<form method="post" enctype="multipart/form-data">' . csrf_field();
echo '<input type="hidden" name="action" value="upload">';
echo '<input type="hidden" name="event_id" value="' . $eventId . '">';
echo '<label class="field" for="title">タイトル<span class="hint">ダウンロード画面に表示します（例：イベント公式壁紙）。</span></label>';
echo '<input type="text" id="title" name="title" required>';
echo '<label class="field" for="image">画像ファイル<span class="hint">PNG または JPEG、12MBまで。</span></label>';
echo '<input type="file" id="image" name="image" accept="image/png,image/jpeg" required>';
echo '<label class="field" for="company_id">企業別にする場合<span class="hint">通常は「イベント共通」のままにします。</span></label>';
echo '<select id="company_id" name="company_id"><option value="0">イベント共通</option>';
foreach ($companies as $company) {
    echo '<option value="' . (int) $company['id'] . '">' . e((string) $company['name']) . '</option>';
}
echo '</select>';
echo '<div class="btn-row"><button type="submit" class="btn btn-primary">登録する</button></div>';
echo '</form></div>';

echo '<h2>登録済みの壁紙（' . count($wallpapers) . '件）</h2>';
echo '<div class="wallpaper-grid">';
foreach ($wallpapers as $wallpaper) {
    echo '<figure>';
    echo '<img src="wallpaper_preview.php?id=' . (int) $wallpaper['id'] . '" alt="' . e((string) $wallpaper['title']) . '">';
    echo '<figcaption>' . e((string) $wallpaper['title']) . '<br>';
    echo (int) $wallpaper['width'] . '×' . (int) $wallpaper['height'] . '<br>';
    echo $wallpaper['company_id'] === null
        ? 'イベント共通'
        : e((string) (find_company((int) $wallpaper['company_id'])['name'] ?? '企業別'));
    echo '</figcaption>';
    echo '<form method="post" onsubmit="return confirm(\'この壁紙を削除します。よろしいですか？\');">' . csrf_field();
    echo '<input type="hidden" name="action" value="delete">';
    echo '<input type="hidden" name="event_id" value="' . $eventId . '">';
    echo '<input type="hidden" name="wallpaper_id" value="' . (int) $wallpaper['id'] . '">';
    echo '<div class="btn-row"><button type="submit" class="btn btn-small btn-danger btn-block">削除</button></div>';
    echo '</form>';
    echo '</figure>';
}
echo '</div>';

if ($wallpapers === []) {
    echo '<p class="muted">まだ登録されていません。</p>';
}

page_footer();
