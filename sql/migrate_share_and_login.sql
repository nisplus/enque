-- 共有用のURL一覧ページと、管理ユーザーの最終ログイン日時のための列を足す
--
--   mysql -u root -p enque < sql/migrate_share_and_login.sql
--
-- 新規に構築する場合は sql/schema.sql だけで足り、このファイルは不要。
-- 二度流しても壊れない（ADD COLUMN IF NOT EXISTS）。

-- 1. ログイン不要で開けるURL一覧ページの合言葉（イベントごと。作り直せる）
ALTER TABLE events
  ADD COLUMN IF NOT EXISTS share_token CHAR(40) NULL UNIQUE AFTER common_survey_from;

-- 2. 管理ユーザーの最終ログイン日時（アカウント管理画面に表示する）
ALTER TABLE admin_users
  ADD COLUMN IF NOT EXISTS last_login_at DATETIME NULL AFTER is_active;
