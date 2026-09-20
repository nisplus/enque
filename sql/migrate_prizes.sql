-- 景品マスタの追加（既にテーブルがあるデータベースに後から適用する）
--
--   mysql -u root -p enque < sql/migrate_prizes.sql
--
-- 新規に構築する場合は sql/schema.sql だけで足り、このファイルは不要。
-- MariaDB の IF NOT EXISTS 付き DDL を使っているため、二度流しても壊れない。

CREATE TABLE IF NOT EXISTS prizes (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_id   INT UNSIGNED NOT NULL,
  name       VARCHAR(255) NOT NULL,
  total_qty  INT UNSIGNED NULL,
  note       VARCHAR(255) NULL,
  sort_order INT NOT NULL DEFAULT 0,
  is_active  TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_prize_event (event_id, is_active, sort_order),
  CONSTRAINT fk_prize_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE prize_claims
  ADD COLUMN IF NOT EXISTS prize_id INT UNSIGNED NULL AFTER claim_code;

ALTER TABLE prize_claims
  ADD INDEX IF NOT EXISTS idx_claim_prize (prize_id);

-- MariaDB では IF NOT EXISTS を FOREIGN KEY の直後に置く
ALTER TABLE prize_claims
  ADD CONSTRAINT fk_claim_prize FOREIGN KEY IF NOT EXISTS (prize_id) REFERENCES prizes(id) ON DELETE SET NULL;
