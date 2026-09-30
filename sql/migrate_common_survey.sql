-- 共通アンケート（会期中にブースのアンケートへ混ぜて1回だけ聞く）に必要な列を足す
--
--   mysql -u root -p enque < sql/migrate_common_survey.sql
--
-- 新規に構築する場合は sql/schema.sql だけで足り、このファイルは不要。
-- 二度流しても壊れない（ADD COLUMN IF NOT EXISTS）。

-- 共通アンケートを何社目のブースから出すか（1 = 1社目から、2 = 2社目から）
ALTER TABLE events
  ADD COLUMN IF NOT EXISTS common_survey_from TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER status;
