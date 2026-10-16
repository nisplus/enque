-- グループの内訳（小学生・大人など区分ごとの人数）を集計できるようにする
--
--   mysql -u root -p enque < sql/migrate_group_member.sql
--
-- 新規に構築する場合は sql/schema.sql だけで足り、このファイルは不要。
-- 二度流しても壊れない（同じ定義に置き換えるだけ）。

-- 設問の集計での扱いに group_member（グループの内訳）を追加する。
--   none         … 集計に使わない
--   party_size   … 来場人数（のべ来場者の集計に使う）
--   group_member … グループの内訳（区分ごとの人数）
ALTER TABLE questions
  MODIFY COLUMN metric ENUM('none','party_size','group_member') NOT NULL DEFAULT 'none';
