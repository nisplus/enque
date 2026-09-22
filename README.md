# 企業周遊アンケートシステム

複数の企業ブースを周遊するイベントで、来場者がブースのQRコードからその場でアンケートに答え、
企業担当者・主催者がリアルタイムに集計を見られる、フレームワーク不使用のPHPアプリケーションです。

仕様書：`企業周遊アンケートシステム仕様書.md`（このリポジトリ外で管理）

**来場者は氏名・住所を一切入力しません。** 端末の匿名Cookieだけで回答を束ね、
メールアドレスのみ任意でお預かりして、イベント終了後の「総合アンケート」案内に使います。

## 動作環境

| 項目 | 想定 |
|---|---|
| OS | Debian 12 |
| Webサーバー | Apache 2.4（php-fpm 推奨、mod_rewrite 有効） |
| PHP | 8.4（8.1以上で動作。`pdo_mysql` / `mbstring` / `session` / `curl` が必要） |
| DB | MariaDB 11.8（10.4以上で動作確認済み） |

Composer・Node.js は不要です（ビルド工程はありません）。QRコード生成・メール送信・グラフ描画は
外部ライブラリを使わずに実装しています。唯一の同梱ライブラリは、受付がカメラでQRを読み取るための
[jsQR](https://github.com/cozmo/jsQR)（`public/assets/vendor/`、Apache-2.0）です。

**受付のカメラ読み取りには HTTPS が必要です**（ブラウザの仕様でカメラは secure context でのみ使えます）。

## 全体の流れ

```
来場者  ブースのQR → /s/<イベント>/<企業> で回答 → 回答済み画面（交換コード＋QR）
                                                  = /done.php?e=<イベント>&c=<コード>
                                                    （ブックマーク・スクショで戻れる）
                                                  ↓ 総合受付がそのQRをスマホで読む
                                              /c/<コード> → /admin/claim.php で照会・交換記録
                                              （来場者本人が読むと自分の控えが開く）

イベント終了（状態を「終了」に）
  → cron: bin/send_overall_invites.php が案内メールを送信
  → 来場者: メールの /o/<トークン> で総合アンケートに回答
  → /wallpaper.php でスマホ壁紙をダウンロード
```

## ディレクトリ構成

```
enque/
├── public/                     ← Apache の DocumentRoot はここを指す
│   ├── index.php               トップ（QRを読むよう案内するだけ）
│   ├── s.php                   企業アンケート回答画面（/s/<イベント>/<企業>）
│   ├── submit.php              POST 回答の受け付け（企業・全体の共通）
│   ├── done.php                回答済み画面（交換コード・訪問企業一覧）
│   ├── o.php                   総合アンケート回答画面（/o/<トークン>）
│   ├── c.php                   交換コードQRの入口（/c/<コード>。受付は照会画面へ）
│   ├── wallpaper.php           壁紙ダウンロード画面
│   ├── wallpaper_file.php      壁紙の配信（回答済みトークンのみ）
│   ├── assets/                 style.css / app.js / scan.js（受付のQR読み取り）
│   │   └── vendor/             jsqr.min.js（QRデコーダ, Apache-2.0）とライセンス全文
│   └── admin/
│       ├── setup.php           初回の主催者アカウント作成
│       ├── login.php / logout.php
│       ├── index.php           ダッシュボード（主催者／企業担当）
│       ├── companies.php       企業とQRの管理
│       ├── survey_edit.php     設問の編集（企業・全体・共通テンプレート）
│       ├── company_stats.php   1社の集計（グラフ）
│       ├── insights.php        回答者傾向（周回時間・周回企業数・動線）
│       ├── responses.php       回答一覧
│       ├── export_csv.php      CSV（1社分／イベント全社分）
│       ├── qr.php              QR画像（SVG/PNG）
│       ├── qr_print.php        QR印刷ページ（ブラウザからPDF保存）
│       ├── prizes.php          景品の登録・残数
│       ├── claim.php           景品交換の照会・記録
│       ├── wallpapers.php      壁紙の登録
│       ├── wallpaper_preview.php
│       ├── invites.php         総合アンケートと案内メール
│       └── users.php           管理ユーザー
├── src/                        ← 非公開。DocumentRoot の外に置く
│   ├── config.php              設定と環境変数の読み込み
│   ├── db.php                  PDO接続とトランザクション
│   ├── helpers.php             エスケープ・URL生成・交換コードなど
│   ├── survey.php              設問の型と回答値の検証
│   ├── repository.php          DBアクセス関数群（全てプリペアドステートメント）
│   ├── visitor.php             来場者の匿名Cookieセッション
│   ├── auth.php                管理者認証・CSRF・権限・レート制限
│   ├── view.php                画面の共通部分と設問の描画
│   ├── render_survey.php       回答フォームの描画（回答画面とエラー再表示で共用）
│   ├── admin_view.php          管理画面の共通部分と集計の描画
│   ├── chart.php               インラインSVGのグラフ
│   ├── insights.php            回答者傾向の集計（周回時間・順番・動線など）
│   ├── csv.php                 CSV出力（UTF-8 BOM・CRLF）
│   ├── qrcode.php              QRコード生成（自前実装）
│   ├── mailer.php              メール送信（postfix中継／外部SMTP／ログ の切替）
│   └── invites.php             案内メールの本文と送信処理
├── sql/schema.sql              スキーマ定義
├── sql/migrate_prizes.sql      既存DBに景品マスタを足すALTER
├── bin/
│   ├── init_db.php             スキーマ適用（CLI）
│   ├── seed_demo.php           デモデータ投入（CLI）
│   ├── send_overall_invites.php 案内メール送信（cron）
│   ├── purge_emails.php        メールアドレスの削除（CLI/cron）
│   ├── bench.php               回答送信の負荷試験（CLI）
│   ├── seed_traffic.php        画面確認用のダミー周遊データ（CLI・開発用）
│   ├── reset.sh                データの初期化（回答のみ／運用データすべて）
│   ├── build_manuals.js        docs/manual-*.md から A4縦の pptx を生成
│   └── router.php              開発サーバー用ルーター
├── storage/wallpapers/         壁紙の実体（DocumentRoot外・要書き込み権限）
├── tests/
│   ├── unit_test.php           QR・検証・CSVの単体テスト（DB不要）
│   ├── http_test.php           受け入れ条件のE2Eテスト
│   └── scan_test.js            受付のQR読み取り判定のテスト（Nodeがあるときのみ・任意）
├── docs/                       マニュアル（原稿の .md と生成物の .pptx）
├── deploy/apache-enque.conf    Apache設定サンプル
├── deploy/cron-enque           cron設定サンプル
├── .env.example                環境変数のテンプレート
├── .gitattributes              改行コードの固定（Linux配置ぶんは必ずLF）
└── serve.cmd                   ローカル開発用サーバー（Windows）
```

## セットアップ（Debian 12）

```bash
# 1. 配置
sudo mkdir -p /var/www/enque
sudo rsync -a ./ /var/www/enque/
cd /var/www/enque

# 2. DBユーザーとデータベースを作る
sudo mariadb <<'SQL'
CREATE DATABASE IF NOT EXISTS enque
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'enque_app'@'localhost' IDENTIFIED BY '<強いパスワード>';
GRANT SELECT, INSERT, UPDATE, DELETE ON enque.* TO 'enque_app'@'localhost';
FLUSH PRIVILEGES;
SQL

# 3. 接続情報を .env に書く（リポジトリにはコミットしない）
cp .env.example .env
sudo -e .env
sudo chown root:www-data .env && sudo chmod 640 .env

# 4. テーブルを作成する（CREATE権限のあるユーザーで実行）
sudo mariadb enque < sql/schema.sql

# 5. 書き込み先の権限
sudo chown -R www-data:www-data logs storage

# 6. Apacheに登録する
sudo a2enmod rewrite
sudo cp deploy/apache-enque.conf /etc/apache2/sites-available/enque.conf
sudo a2ensite enque && sudo systemctl reload apache2

# 7. 案内メールのcronを登録する
sudo cp deploy/cron-enque /etc/cron.d/enque
```

`.env` の `DISPLAY_ERRORS` は本番では必ず `0`、HTTPS運用なら `SESSION_SECURE=1` にします。
**`BASE_URL` はQRコードとメールに埋め込まれるため、QRを印刷する前に必ず本番URLへ設定してください。**

管理画面の見出し（既定は「周遊アンケート管理」）は `.env` の `ADMIN_TITLE` で変えられます。
ヘッダーとブラウザのタブ名の両方に反映されます。

```ini
ADMIN_TITLE=◯◯フェア2026 運営
```

## 更新（デプロイ）とデータベースのマイグレーション

稼働中のサーバーを新しい版に更新する手順です。**回答や企業・設問などの既存データは消えません。**

```bash
cd /var/www/enque

# 1. 念のためデータベースをバックアップする（数秒で終わります）
mysqldump -u root -p enque > ~/enque_$(date +%Y%m%d_%H%M).sql

# 2. 新しいコードを取り込む
sudo -u www-data git pull --rebase origin main

# 3. DBの変更を適用する（下の一覧の、まだ流していないものを順に実行）
#    アプリ用ユーザー（enque_app）には CREATE / ALTER 権限が無いため、管理者ユーザーで実行する
mysql -u root -p enque < sql/migrate_prizes.sql

# 4. 反映確認
mysql -u root -p enque -e "SHOW TABLES; SHOW COLUMNS FROM prize_claims;"
```

### マイグレーション一覧

| ファイル | 内容 | いつ必要か |
|---|---|---|
| `sql/migrate_prizes.sql` | `prizes` テーブルの追加と、`prize_claims.prize_id`（渡した景品）の追加 | 景品機能より前に `sql/schema.sql` でDBを作った場合 |
| `sql/migrate_party_size.sql` | 設問の種類に「数値入力」を追加し、`questions.metric`（来場人数として集計するか）を追加 | 来場人数の集計より前に `sql/schema.sql` でDBを作った場合 |

- **新規に構築する場合は `sql/schema.sql` だけで足ります**（マイグレーションは不要です）。
- マイグレーションは `IF NOT EXISTS` で書いてあるので、**二度流しても壊れません**。
  適用済みかどうか分からないときは、そのまま実行して構いません。
- 既存の交換記録は `prize_id` が空（景品なし）になります。残数の計算からは除かれ、
  受付画面に「景品を選ばずに記録された交換が n 件あります」と表示されます。
- 今後スキーマを変更するときは `sql/schema.sql`（新規構築用）と `sql/migrate_*.sql`（既存DB用）の
  両方を更新し、この表に1行足します。

## マニュアル（主催者・企業担当者・総合受付）

`docs/` に3つのマニュアルがあります。**A4縦のPowerPoint**で、そのまま印刷・配布できます。

| ファイル | 対象 | ページ数 |
|---|---|---|
| `docs/manual-organizer.pptx` | 主催者（事務局） | 19 |
| `docs/manual-company.pptx` | 企業担当者 | 10 |
| `docs/manual-reception.pptx` | 総合受付 | 9 |

**原稿は `docs/manual-*.md` が正で、pptx は生成物です。** 文章を直すときは Markdown を編集して
作り直してください（画面が変わったときも同じ手順で更新できます）。

```
npm install                  REM 初回のみ。pptxgenjs を入れる
node bin/build_manuals.js    REM 3本の pptx と screenshot-list.md を作り直す
```

- **スクリーンショットは貼り込み済みです（全27箇所）。** 画像は `docs/images/` にあり、
  テスト環境（デモデータ）の画面です。本番の画面に差し替えたいときは、同じ名前で画像を置き換えて
  作り直してください。どのページにどの画像が入っているかは `docs/screenshot-list.md` にあります。
- Node.js が必要なのは**このマニュアル生成だけ**で、アプリ本体の動作・デプロイには不要です。
- 原稿の書き方（見出し・手順・メモ・注意・スクショ枠の記法）は `bin/build_manuals.js` の冒頭に
  書いてあります。

## データの初期化

テスト用に入れたデータを消すときや、次のイベントに向けてまっさらにするときは
`bin/reset.sh` を使います（**管理ユーザーと `.env` は消しません**）。

```bash
cd /var/www/enque

# 何件消えるかだけを確認する（消さない）
bin/reset.sh --responses --dry-run

# 回答と回答者のデータだけ消す（イベント・企業・設問・景品・壁紙は残る）
bin/reset.sh --responses --force

# イベント・企業・設問・回答など、運用データをすべて消す
bin/reset.sh --all --force

# 特定のイベントだけを対象にする
bin/reset.sh --all --event=3 --force
```

| モード | 消えるもの | 残るもの |
|---|---|---|
| `--responses` | 回答・回答内容・来場者・交換コード・案内メール | イベント・企業・設問・景品・壁紙・管理ユーザー |
| `--all` | 上記に加えて、イベント・企業・設問・景品・壁紙（と壁紙の画像ファイル） | 管理ユーザー（主催者・受付）・`.env` |

- 実行には `--force` が必要で、さらに**データベース名の入力を求めます**（`--yes` で省略可）。
- 実行前に**自動でバックアップ**を取ります（`backups/` に保存。`--no-backup` で省略可）。
  戻すときは `mysql enque < backups/enque_YYYYMMDD_HHMMSS.sql` です。
- `--all` では、**企業担当者のアカウントは企業と一緒に消えます**（主催者・受付は残ります）。
- Windows（Git Bash）で実行する場合は、mysql の場所を環境変数で指定できます。
  ```
  MYSQL_BIN=/c/xampp/mysql/bin/mysql.exe MYSQLDUMP_BIN=/c/xampp/mysql/bin/mysqldump.exe bash bin/reset.sh --responses --dry-run
  ```

## メール送信の設定

案内メールの送信方式は `.env` の `MAIL_TRANSPORT` で選びます。

### 1. ローカルのpostfixで中継する（既定・推奨）

サーバーに設定済みのpostfixに渡し、中継（relay）はpostfixに任せます。
アプリ側に認証情報を持たせずに済み、再送やキューイングもpostfixの仕組みに乗ります。

```ini
MAIL_TRANSPORT=postfix
SENDMAIL_PATH=/usr/sbin/sendmail
MAIL_FROM=no-reply@example.jp
MAIL_FROM_NAME=イベント事務局
```

エンベロープ送信者（バウンス先）は `MAIL_FROM` を `sendmail -f` で渡します。
postfix 側の `relayhost` や `sender_canonical` の設定はサーバー管理の範囲です。
動作確認は「総合アンケート」ページの**テスト送信**か、次のコマンドで行えます。

```bash
php -r 'require "src/bootstrap.php"; require "src/mailer.php"; send_mail("自分@example.jp","テスト","本文");'
sudo tail -f /var/log/mail.log      # postfix 側のログ
```

### 2. 外部SMTPサーバーへ直接つなぐ

```ini
MAIL_TRANSPORT=smtp
SMTP_HOST=smtp.example.jp
SMTP_PORT=587
SMTP_SECURE=tls        # none / tls（STARTTLS） / ssl（SMTPS, 通常465）
SMTP_USER=...
SMTP_PASS=...
```

sendmailコマンドが使えない環境でpostfixに渡したいときは、この方式で
`SMTP_HOST=127.0.0.1` / `SMTP_PORT=25` / `SMTP_SECURE=none`（認証なし）を指定すれば、
同じローカルpostfixに中継させられます。

### テスト送信について

「総合アンケート」ページの**テスト送信**は、本番と同じ文面を指定のアドレスへ送ります。
ただし**リンクだけはスタッフ確認用のプレビュー**（`/o/preview-<イベントID>`）です。
案内メールのURLは来場者ごとに発行されるトークンを含むため、送信前の時点では本物のURLが存在しないためです。

- プレビューは**主催者としてログインした状態**で開きます（ログインしていないと案内ページが出ます）。
  設問の見え方・文面・`BASE_URL` が正しいかの確認に使えます。
- プレビュー画面からは**送信できません**（回答が集計に混ざらないようにするため）。
- 来場者に届く本番のメールには、その方専用のURLが入り、回答すると壁紙のダウンロード画面に進みます。

### 3. 送信しない（開発・動作確認）

`MAIL_TRANSPORT=log` にすると、送信せず `logs/mail-dryrun.log` に本文を書き出します。
また、`postfix` なのに sendmail が見つからない場合や `smtp` なのに `SMTP_HOST` が空の場合も、
黙って失敗させずにこの動作へフォールバックし、理由を `logs/app-error.log` に残します。
管理画面の「総合アンケート」ページには、現在どの方式で送るかが表示されます。

## 初回の操作手順

1. `/admin/setup.php` を開き、主催者アカウントを作成します（この画面は最初の1回だけ使えます）。
2. ダッシュボードで**イベントを作成**します。状態は「準備中」から始まります。
3. 「企業・QR」で**出展企業を登録**します。企業ごとに推測困難なURLが自動発行されます。
4. 必要なら「総合アンケート」ページで**共通設問テンプレート**を作り、各企業の編集画面で取り込みます。
5. 各企業の「アンケート編集」で設問を登録し、**公開する**にチェックを入れます。
6. 「QRコードを印刷する（全社）」で印刷し、ブースに掲示します。
7. 「景品」で**渡す景品と用意した数量を登録**します（受付はここから選び、残数が出ます）。
8. 「ユーザー」で企業担当者・総合受付のアカウントを発行します。
   **受付担当のスマホでは、当日までに `/admin/login.php` からログインしておいてください**
   （ログイン済みの端末で来場者のQRを読み取ると、そのまま交換の照会画面が開きます）。
9. イベント当日、イベントの状態を**「開催中」**にすると回答の受け付けが始まります。
10. イベント終了後、状態を**「終了」**にし、「総合アンケート」ページから案内メールを送ります。
11. 案内・集計が終わったら、同じページで**メールアドレスを削除**します。

イベントの状態が「開催中」でないと、ブースのアンケートは回答できません（QRを読んでも受付終了と表示されます）。

## ローカル開発（Windows / XAMPP）

```
copy .env.example .env      REM DB_USER=root、BASE_URL=http://127.0.0.1:8080 などに書き換える
C:\xampp\php\php.exe bin\init_db.php
C:\xampp\php\php.exe bin\seed_demo.php --force    REM デモのイベント・企業・設問
serve.cmd                                          REM http://127.0.0.1:8080/
```

`serve.cmd` は `bin/router.php` を使い、本番と同じ短いURL（`/s/<イベント>/<企業>`）で動きます。

## 回答者の傾向（主催者向け）

管理画面の「回答者傾向」（`/admin/insights.php`）で、1人あたりの動きを集計して見られます。
企業をまたいだ動きが見えるため、**主催者のみ**が開けます。

| 表示 | 内容 |
|---|---|
| 周回時間 | 最初の回答から最後の回答までの平均・中央値・最長・最短と、その分布 |
| 周回企業数 | 平均・中央値・最大・最小と、「何社回った人が何人いたか」の分布 |
| 企業をまわる順番 | 企業ごとの「平均何番目に回られたか」と、そこから周遊を始めた人数 |
| よくある動線 | 「A社の次にB社」の多い組み合わせ 上位20 |
| 時間帯別のユニーク来場者数 | 回答数ではなく人数の推移 |
| 周回企業数と評価の関係 | 1社 / 2〜3社 / 4社以上ごとの平均評価とNPS |
| 回答・登録の割合 | 回答率・重複送信率・メール登録率・総合アンケート回答率・景品交換率 |

数え方の前提（画面にも注記しています）:

- **「周回時間」は滞在時間ではありません。** 最初の回答から最後の回答までの間隔なので、
  入場から1社目まで、最後のブースから退場までは含みません。
- **1社だけ回った方は周回時間が0**になるため、時間の集計からは除き、人数だけ別に表示します。
- 来場者は端末の匿名Cookie単位です。端末を変えた方は別の方として数えられます。
- 重複送信（同じ企業への2回目以降）は数えません。
- 「周回企業数と評価の関係」は**相関であって因果ではありません**（もともと関心の高い方が
  たくさん回っている、とも読めます）。

画面の見え方を確認したいときは、ダミーの周遊データを作れます（**開発用DBのみ**）。

```
C:\xampp\php\php.exe bin\seed_traffic.php --force --visitors=200
```

## テスト

```
C:\xampp\php\php.exe tests\unit_test.php                  REM DB・サーバー不要（53項目）
serve.cmd                                                 REM 別ウィンドウで起動しておく
C:\xampp\php\php.exe tests\http_test.php --force          REM E2E（233項目）
node tests\scan_test.js                                   REM QR読み取り判定（17項目・Nodeがある場合のみ）
```

`scan_test.js` は、読み取った文字列から交換コードを取り出す判定と、**このシステムが生成したQRコードを
同梱の jsQR が読めること**を確認します（Node が無い環境では省略して構いません）。
カメラの起動そのものは自動テストできないため、受付端末での実機確認が必要です。

Debian 側では `php -S 127.0.0.1:8080 -t public bin/router.php` を起動してから
`php tests/http_test.php --force` を実行します。

E2Eテストは**テスト専用のイベント・企業・管理ユーザーを作成し、終了時に削除**します。
既存データは触りませんが、念のため開発用DBで実行してください。
検証内容は、回答の登録・必須チェック・選択肢の改ざん検知・重複フラグ・交換コードの発行と照会・
景品の登録と残数（在庫切れ・超過の扱いを含む）・権限境界（他社データが見えないこと）・
CSV（BOM・メールアドレス非出力）・QR発行・設問編集（追加後のアンカー移動を含む）・
壁紙のアップロードとダウンロード・総合アンケートのトークン・メール送信方式の切り替えと本文の組み立て・
メールアドレス削除・ログインのレート制限・CSRFです。

### 負荷試験

想定規模（各社約400名、全体で延べ約3,000名）に耐えるかは、本番相当の環境で確認します。

```
php bin/bench.php --url=https://survey.example.jp --survey=<アンケートID> --requests=500 --concurrency=30
```

PHPの組み込みサーバーは逐次処理のため、**必ず Apache + php-fpm に対して実行**してください。
テスト回答がDBに入るので、本番データ投入前に行うか、実行後に削除してください。

## 運用上の注意

- **QRコードのURLを再発行すると、印刷済みのQRは使えなくなります。** 企業の編集欄にある
  「URLを再発行」は、URLが外部に漏れた場合など、貼り替えができるときだけ使ってください。
- **企業を「停止」しても回答データは消えません。** `companies.is_active` による論理削除で、
  回答画面が受付停止になり、一覧から外れるだけです。再開すれば元に戻ります。
- **設問を削除すると、その設問への回答も一緒に消えます**（外部キーの連鎖削除）。
  回答受付後に設問を削除する場合は、先にCSVを取得してください。
- 設問編集画面の「保存して設問を追加」は、保存後に画面の先頭ではなく**追加された設問の位置に戻り**、
  設問文の入力欄にカーソルが入ります（続けて何問も登録しやすくするため）。
- **交換の照会は、来場者のQRコードをスタッフのスマホカメラで読み取るのが基本**です。方法は2つあります。
  1. 照会画面の「**QRコードを読み取る**」ボタン（ページ内でカメラが開き、読み取ると自動で照会します）。
     複数のスマホで次々さばくときはこちらが速く、アプリの切り替えが要りません。**初回はカメラの使用許可**を
     求められるので「許可」を選んでください。
  2. OS標準のカメラアプリでQRを読む。QRには交換コードのURL（`/c/<コード>`）が入っていて、
     **ログイン済みの受付・主催者が読むと照会画面**、来場者本人が読むと**自分の回答済み画面**が開きます。
  どちらも使えないときは、従来どおり交換コードを手入力できます。
- **カメラでの読み取りには HTTPS が必要**です（ブラウザの制約）。HTTPでアクセスした場合はボタンを出さず、
  手入力の案内だけを表示します。iOS Safari はブラウザ内蔵のQR読み取りAPIを持たないため、
  同梱の jsQR で解析します（Android Chrome などでは内蔵APIを優先し、より軽く動きます）。
- **景品を1件でも登録すると、交換の記録時に景品の選択が必須**になります（何を渡したか残すため）。
  登録前に記録した交換は「景品なし」として残り、残数の計算には含まれません。
- **景品の取り扱いを止めても交換記録は消えません。** `prizes.is_active` による論理削除で、
  受付の選択肢から外れるだけです。残数の表には「停止中」として残ります。
- 既にテーブルを作成済みのデータベースに景品機能を足す場合は `sql/migrate_prizes.sql` を流します
  （新規構築なら `sql/schema.sql` だけで足ります）。
- **改行コードは `.gitattributes` で LF に固定しています。** 本番は Linux（Debian）配置のため、
  Windows で編集してもリポジトリと配置先は必ず LF になります。これが無いと環境によっては CRLF が
  混入し、`deploy/cron-enque`（CRLF だと cron がジョブを読めない）やシェルから実行するファイルが
  壊れます。Windows専用の `serve.cmd` だけは CRLF で取り出す設定です。
- **CSVはUTF-8 BOM付き・CRLF改行**です。Excelでそのまま開けます。
  メールアドレスは出力しません。来場者は匿名ID（`visitor_id`）だけを出力するので、
  企業をまたいだ同一来場者の突き合わせはこのIDで行えます。
- ログイン失敗が同一IPから1分間に5回に達すると、そのIPは一時的にロックされます。
- 案内メールは `overall_invites` で送信済みを管理しており、cronが何度動いても二重送信しません。
  失敗したものは3回まで自動で再試行します。

## セキュリティ設計

| 対策 | 実装 |
|---|---|
| SQLインジェクション | 全クエリをプリペアドステートメント化（`ATTR_EMULATE_PREPARES=false`）。値の文字列連結なし |
| XSS | 出力は必ず `e()`（`htmlspecialchars`）を通す |
| CSRF（管理画面） | 全フォームにトークンを発行し `hash_equals` で検証。ログイン成功時に再発行 |
| CSRF（来場者側） | セッションを作らないため、`Origin` 検証と `SameSite=Lax` のCookieで守る |
| セッション固定化 | ログイン成功時に `session_regenerate_id(true)` |
| Cookie | `HttpOnly` / 管理画面は `SameSite=Strict`、来場者は `Lax`／HTTPS時は `Secure` |
| パスワード | `password_hash(PASSWORD_DEFAULT)` で保存、`password_verify` で照合、必要に応じ再ハッシュ |
| 総当たり | `admin_login_attempts` による同一IPのレート制限。存在しないユーザーでも応答時間を揃える |
| 権限境界 | 企業担当者は自社のみ。他社IDを直接開くと **403 ではなく 404** を返す（他社の存在を伏せる）。<br>ロール・所属・有効フラグはセッションに持たず毎リクエストDBから読み直す（無効化が即時反映） |
| 入力検証 | 選択肢はDBに登録されたものだけを許可、評価は範囲チェック、UTF-8妥当性検査、制御文字の除去 |
| URLの推測 | イベント・企業のスラグは `random_bytes` 由来。総合アンケートのトークンは20バイト |
| 壁紙の配布制限 | 画像は DocumentRoot 外に置き、総合アンケート回答済みのトークンがある場合のみ配信 |
| 交換コードの扱い | 回答済み画面はコード付きURLでも開ける（Cookieが消えても戻れるようにするため）。出すのは交換コードと回答済みブース名だけで、交換済みかどうかは受付の画面にしか表示しない |
| ファイルアップロード | 拡張子ではなく `getimagesize()` の判定でPNG/JPEGのみ許可。保存名はランダム生成 |
| CSVインジェクション | `= + - @` で始まるセルの先頭に `'` を付ける |
| 設定情報 | DB接続情報・SMTP認証情報は `.env`（DocumentRoot外）に置き、リポジトリにコミットしない |

## 仕様に対する補足（判断したこと）

- **QRコード生成は自前実装です。** Composerを使わない構成のため `endroid/qr-code` は入れず、
  `src/qrcode.php` に8ビットバイトモード・誤り訂正レベルM・型番1〜15のエンコーダを実装しました。
  JIS X 0510 の既知の値（Reed-Solomon符号・形式情報・型番情報）と突き合わせる単体テストを用意し、
  生成したQRが実際にデコードできることを外部のQRデコーダで確認済みです（型番1/3/4/5/13）。
  **印刷前に一度、実機のカメラで読み取り確認**してください。
- **印刷用PDFはブラウザの「PDFとして保存」を使います。** PDFライブラリを持たない構成のため、
  `qr_print.php` を印刷用スタイル付きのページとして用意し、変換はブラウザに任せています。
- **グラフは円グラフではなく横棒グラフです。** 選択肢のラベルが日本語で長くなりやすく、
  横棒のほうが読み取りやすく比較もしやすいためです。割合（%）は棒の右に併記しています。
  NPSはスコアと推奨者・中立・批判者の内訳、評価設問は平均値をあわせて表示します。
- **壁紙はApacheの静的配信ではなくPHP経由で配信します。** 静的配信ではURLを知っていれば
  誰でも取得できてしまい、「総合アンケートに回答した人への特典」という条件を満たせないためです。
- **総合アンケートはメールのトークンからのみ回答できます。** 壁紙の配布条件を満たすためで、
  メールアドレスを登録していない来場者には総合アンケートのURLは発行されません。
- **企業アンケートの重複送信は拒否しません。** 同じ端末から同じ企業へ再送信された場合も
  通常どおり受け付け、`responses.is_duplicate` を立てて集計から除きます。
  画面には「再送信できます」とは表示していません（仕様どおり）。
- **景品交換は来場者側に拒否を表示しません。** 交換済みかどうかは受付の照会画面にだけ表示し、
  対応はスタッフの判断に委ねます。交換履歴（日時・対応者・メモ）を残します。
- **来場者の同定は端末の匿名Cookieだけで、メールアドレスは必須にしていません。**
  交換コードはCookieに紐づくので、別の端末・別のブラウザで読み取ると別のコードになります。
  完全な一意性は求めず（来場者を必要以上に疑わない方針）、Cookieが消えた場合の備えとして
  回答済み画面のURLに交換コードを含め、ブックマークやスクリーンショットから同じコードに戻れるようにしました。
  回答画面と回答済み画面には「ほかのブースも同じスマホ・同じブラウザで読み取ってください」と案内しています。
  コードが分かれてしまった来場者から申し出があった場合は、受付の照会画面（回答ブース数・交換履歴）を見て
  スタッフが判断する運用です。
- **回答期限やブースごとの受付時間は設けていません。** イベントの状態（準備中／開催中／終了）で
  受付の可否を切り替えます。
- **メールの送信方式は `.env` の `MAIL_TRANSPORT` で選べます（既定はローカルpostfixの中継）。**
  仕様書では外部SMTPの利用を前提にしていましたが、サーバーに設定済みのpostfixで中継する運用に合わせ、
  `postfix`（sendmailコマンド）／`smtp`（外部SMTP直結）／`log`（送信せずログ）を切り替えられるようにしました。
  設定が足りないとき（sendmailが無い・SMTP_HOSTが空）は黙って失敗させず、`log` に落としてエラーログに理由を残します。
- **メールアドレスの削除は手動または日次cronです。** 「案内・集計が完了した」判断は運用側にあるため、
  管理画面のボタン（即時）と `bin/purge_emails.php --older-than=<日数>`（cron）の両方を用意しました。
- **外部ライブラリの同梱は jsQR だけです。** QRの生成は自前実装で足りますが、**読み取り（デコード）は
  画像の二値化・位置検出・透視変換・誤り訂正が必要で、自前実装は割に合いません**。また iOS Safari は
  ブラウザ内蔵のQR読み取りAPIを持たないため、iPhone を受付端末に使う以上ライブラリが要ります。
  CDNから読み込まず `public/assets/vendor/` に同梱しているのは、会場のネットワークが不安定でも動くようにするためです。
  ライセンスは Apache-2.0 で、全文を `jsqr-LICENSE.txt` として同梱し、ファイル先頭に出典・版・改変点を記しています
  （jsQR 1.4.0、`sourceMappingURL` 行のみ削除）。読み込むのは受付の照会画面だけで、来場者の画面には配信しません。
- **PHP 8.4 を前提にしつつ、8.1以上で動作する構文にとどめています**（`enum`・`never`・`match` は使用）。
  ローカル検証は XAMPP の PHP 8.2.12 / MariaDB 10.4.32 で行いました。

## 仕様書の未確定事項について

| 項目 | 現状の実装 |
|---|---|
| 景品の総数・在庫上限 | 「景品」ページで景品名と用意した数量を登録でき、受付の照会画面に残数を表示する（数量を空にすると数量管理なし）。**在庫が尽きても交換の記録は止めない**（実際に渡したものを残せるようにするため）。用意した数を超えたぶんは「超過」として表示する |
| メールの送信経路 | ローカルpostfixの中継が既定（`MAIL_TRANSPORT=postfix`）。外部SMTPに直接つなぐ場合は `MAIL_TRANSPORT=smtp` と `SMTP_*` を設定する。どちらも未設定のうちはドライラン（ログ出力）で動作確認できる |
| 回答データ本体の保持期間 | 自動削除は実装していない（メールアドレスのみ削除対象）。保持期間が決まり次第、`bin/purge_emails.php` と同じ形で削除スクリプトを追加する |
