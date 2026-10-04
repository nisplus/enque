-- 回答のあとからの修正（同じ企業のQRを読み直して書き換える）に必要な列を足す
--
--   mysql -u root -p enque < sql/migrate_response_edit.sql
--
-- 新規に構築する場合は sql/schema.sql だけで足り、このファイルは不要。
-- 二度流しても壊れない（ADD COLUMN IF NOT EXISTS）。

-- 回答を書き換えた日時。NULL なら一度も修正していない
ALTER TABLE responses
  ADD COLUMN IF NOT EXISTS updated_at DATETIME NULL AFTER submitted_at;
