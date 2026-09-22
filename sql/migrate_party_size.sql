-- 来場人数の設問（数値入力）を追加する（既にテーブルがあるデータベースに適用する）
--
--   mysql -u root -p enque < sql/migrate_party_size.sql
--
-- 新規に構築する場合は sql/schema.sql だけで足り、このファイルは不要。
-- 二度流しても壊れない（列の追加は IF NOT EXISTS、型の変更は同じ定義に置き換えるだけ）。

-- 1. 設問の種類に「number（数値入力）」を追加する
ALTER TABLE questions
  MODIFY COLUMN type ENUM('single','multi','text','rating','nps','number') NOT NULL;

-- 2. その設問の回答を集計指標として使うかどうかの列を足す
--    party_size … 来場人数（のべ来場者の集計に使う）
ALTER TABLE questions
  ADD COLUMN IF NOT EXISTS metric ENUM('none','party_size') NOT NULL DEFAULT 'none' AFTER required;
