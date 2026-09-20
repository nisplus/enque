-- 企業周遊アンケートシステム スキーマ（MariaDB 11.8 想定 / 10.4 以上で動作）
-- 実行例: mysql -u root -p < sql/schema.sql

CREATE DATABASE IF NOT EXISTS enque
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE enque;

-- イベント -----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS events (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(255) NOT NULL,
  slug        VARCHAR(64)  NOT NULL UNIQUE,
  start_date  DATE NULL,
  end_date    DATE NULL,
  status      ENUM('draft','open','closed') NOT NULL DEFAULT 'draft',
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 出展企業 -----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS companies (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_id   INT UNSIGNED NOT NULL,
  name       VARCHAR(255) NOT NULL,
  qr_slug    VARCHAR(64)  NOT NULL UNIQUE,
  logo_url   VARCHAR(500) NULL,
  color      VARCHAR(7)   NULL,
  booth_no   VARCHAR(32)  NULL,
  sort_order INT NOT NULL DEFAULT 0,
  is_active  TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_company_event (event_id, is_active, sort_order),
  CONSTRAINT fk_company_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- アンケート ---------------------------------------------------------------
-- type=company : 企業ブースのアンケート（company_id 必須）
-- type=overall : イベント終了後に送付する全体アンケート（company_id は NULL、イベントに1本）
-- type=template: 共通設問のテンプレート（company_id は NULL。回答は受け付けない）
CREATE TABLE IF NOT EXISTS surveys (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_id     INT UNSIGNED NOT NULL,
  company_id   INT UNSIGNED NULL,
  type         ENUM('company','overall','template') NOT NULL DEFAULT 'company',
  title        VARCHAR(255) NOT NULL,
  description  TEXT NULL,
  is_published TINYINT(1) NOT NULL DEFAULT 0,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_survey_company (company_id),
  INDEX idx_survey_event (event_id, type),
  CONSTRAINT fk_survey_event   FOREIGN KEY (event_id)   REFERENCES events(id)    ON DELETE CASCADE,
  CONSTRAINT fk_survey_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 設問 ---------------------------------------------------------------------
-- options は single/multi のとき ["選択肢1","選択肢2"] のJSON配列。
-- rating は最大値（既定5）、nps は 0-10 固定のため options は使わない。
CREATE TABLE IF NOT EXISTS questions (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  survey_id  INT UNSIGNED NOT NULL,
  type       ENUM('single','multi','text','rating','nps') NOT NULL,
  label      VARCHAR(500) NOT NULL,
  options    TEXT NULL,
  required   TINYINT(1) NOT NULL DEFAULT 0,
  sort_order INT NOT NULL DEFAULT 0,
  INDEX idx_question_survey (survey_id, sort_order),
  CONSTRAINT fk_question_survey FOREIGN KEY (survey_id) REFERENCES surveys(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 来場者（匿名セッション） ---------------------------------------------------
-- 氏名・住所等は保持しない。email は全体アンケート案内の送付目的のみで、
-- 送付・集計完了後に bin/purge_emails.php で削除する。
CREATE TABLE IF NOT EXISTS visitors (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_id      INT UNSIGNED NOT NULL,
  session_token CHAR(64) NOT NULL,
  email         VARCHAR(255) NULL,
  email_purged  TINYINT(1) NOT NULL DEFAULT 0,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_visitor_session (event_id, session_token),
  INDEX idx_visitor_email (event_id, email),
  CONSTRAINT fk_visitor_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 回答 ---------------------------------------------------------------------
-- 同一来場者が同じアンケートに再送信した場合も記録し、is_duplicate=1 を立てる
-- （集計時は既定で is_duplicate=0 のみを数える）。
CREATE TABLE IF NOT EXISTS responses (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  survey_id    INT UNSIGNED NOT NULL,
  visitor_id   INT UNSIGNED NOT NULL,
  is_duplicate TINYINT(1) NOT NULL DEFAULT 0,
  submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_response_survey (survey_id, is_duplicate, submitted_at),
  INDEX idx_response_visitor (visitor_id),
  CONSTRAINT fk_response_survey  FOREIGN KEY (survey_id)  REFERENCES surveys(id)  ON DELETE CASCADE,
  CONSTRAINT fk_response_visitor FOREIGN KEY (visitor_id) REFERENCES visitors(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 回答内容 -----------------------------------------------------------------
-- value は multi のとき JSON配列、それ以外は文字列（rating/nps は数値の文字列）。
CREATE TABLE IF NOT EXISTS answers (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  response_id INT UNSIGNED NOT NULL,
  question_id INT UNSIGNED NOT NULL,
  value       TEXT NULL,
  INDEX idx_answer_response (response_id),
  INDEX idx_answer_question (question_id),
  CONSTRAINT fk_answer_response FOREIGN KEY (response_id) REFERENCES responses(id) ON DELETE CASCADE,
  CONSTRAINT fk_answer_question FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 壁紙 ---------------------------------------------------------------------
-- company_id が NULL ならイベント共通（当面はこれのみ使用）。
CREATE TABLE IF NOT EXISTS wallpapers (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_id   INT UNSIGNED NOT NULL,
  company_id INT UNSIGNED NULL,
  title      VARCHAR(255) NOT NULL,
  file_name  VARCHAR(255) NOT NULL,
  mime_type  VARCHAR(64)  NOT NULL,
  width      INT UNSIGNED NOT NULL,
  height     INT UNSIGNED NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_wallpaper_event (event_id, sort_order),
  CONSTRAINT fk_wallpaper_event   FOREIGN KEY (event_id)   REFERENCES events(id)    ON DELETE CASCADE,
  CONSTRAINT fk_wallpaper_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 景品交換 -----------------------------------------------------------------
-- 回答済み画面に表示する交換コード。来場者1人につき1件。
CREATE TABLE IF NOT EXISTS prize_claims (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  visitor_id INT UNSIGNED NOT NULL UNIQUE,
  claim_code VARCHAR(16) NOT NULL UNIQUE,
  claimed_at DATETIME NULL,
  claimed_by VARCHAR(100) NULL,
  note       VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_claim_visitor FOREIGN KEY (visitor_id) REFERENCES visitors(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 全体アンケートの案内メール ------------------------------------------------
-- token は来場者ごとの全体アンケートURL（/o.php?t=...）に使う。
CREATE TABLE IF NOT EXISTS overall_invites (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_id     INT UNSIGNED NOT NULL,
  visitor_id   INT UNSIGNED NOT NULL UNIQUE,
  email        VARCHAR(255) NULL,
  token        CHAR(40) NOT NULL UNIQUE,
  status       ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
  attempts     INT UNSIGNED NOT NULL DEFAULT 0,
  last_error   VARCHAR(500) NULL,
  sent_at      DATETIME NULL,
  responded_at DATETIME NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_invite_event (event_id, status),
  CONSTRAINT fk_invite_event   FOREIGN KEY (event_id)   REFERENCES events(id)   ON DELETE CASCADE,
  CONSTRAINT fk_invite_visitor FOREIGN KEY (visitor_id) REFERENCES visitors(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 管理ユーザー --------------------------------------------------------------
-- organizer:主催者（全権） / company:企業ブース担当者（自社のみ） / reception:総合受付（景品照会のみ）
CREATE TABLE IF NOT EXISTS admin_users (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username      VARCHAR(64) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  display_name  VARCHAR(100) NOT NULL,
  role          ENUM('organizer','company','reception') NOT NULL,
  company_id    INT UNSIGNED NULL,
  is_active     TINYINT(1) NOT NULL DEFAULT 1,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_admin_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_login_attempts (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ip_address   VARCHAR(45) NOT NULL,
  attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ip_time (ip_address, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
