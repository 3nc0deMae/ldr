-- ═══════════════════════════════════════════════════════════════════════════
-- LDB-FRAS Database Schema Upgrade
-- Parent Notification Management & Textbee SMS Usage Tracker
-- Generated: July 2026
--
-- HOW TO RUN:
--   mysql -u root -p db_name < update_database_schema.sql
--   OR import via phpMyAdmin → Import tab
--
-- NOTE: Compatible with MySQL 8.0+ AND MariaDB 10.4+.
--   * MySQL 8/9 does NOT support `ADD COLUMN IF NOT EXISTS` (MariaDB-only),
--     so column/index additions are made idempotent via the `ensure_*`
--     stored procedures defined below (checked against information_schema).
--   * `DEFAULT (CURRENT_DATE)` is used instead of MariaDB's `DEFAULT CURRENT_DATE`.
-- ═══════════════════════════════════════════════════════════════════════════

-- ═══════════════════════════════════════════════════════════════════════════
-- STEP 0: Idempotent DDL helpers (safe on MySQL 8/9 and MariaDB)
-- ═══════════════════════════════════════════════════════════════════════════

DELIMITER $$

DROP PROCEDURE IF EXISTS `ensure_column` $$
CREATE PROCEDURE `ensure_column`(IN tbl VARCHAR(64), IN col VARCHAR(64), IN ddl VARCHAR(512))
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = tbl AND COLUMN_NAME = col
  ) THEN
    SET @s = CONCAT('ALTER TABLE `', tbl, '` ADD COLUMN `', col, '` ', ddl);
    PREPARE stmt FROM @s;
    EXECUTE stmt;
    DEALLOCATE PREPARE stmt;
  END IF;
END $$

DROP PROCEDURE IF EXISTS `drop_column_if_exists` $$
CREATE PROCEDURE `drop_column_if_exists`(IN tbl VARCHAR(64), IN col VARCHAR(64))
BEGIN
  IF EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = tbl AND COLUMN_NAME = col
  ) THEN
    SET @s = CONCAT('ALTER TABLE `', tbl, '` DROP COLUMN `', col, '`');
    PREPARE stmt FROM @s;
    EXECUTE stmt;
    DEALLOCATE PREPARE stmt;
  END IF;
END $$

DROP PROCEDURE IF EXISTS `ensure_index` $$
CREATE PROCEDURE `ensure_index`(IN tbl VARCHAR(64), IN idx VARCHAR(64), IN ddl VARCHAR(512))
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = tbl AND INDEX_NAME = idx
  ) THEN
    SET @s = CONCAT('ALTER TABLE `', tbl, '` ADD INDEX `', idx, '` ', ddl);
    PREPARE stmt FROM @s;
    EXECUTE stmt;
    DEALLOCATE PREPARE stmt;
  END IF;
END $$

DELIMITER ;

-- ═══════════════════════════════════════════════════════════════════════════
-- STEP 1: Create system_notification_config table
-- ═══════════════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS `system_notification_config` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `sms_subscription_tier` ENUM('free_50', 'monthly_unlimited', 'yearly_unlimited') DEFAULT 'free_50',
  `sms_daily_count` INT DEFAULT 0,
  `sms_monthly_count` INT DEFAULT 0,
  `sms_yearly_count` INT DEFAULT 0,
  `email_daily_count` INT DEFAULT 0,
  `email_monthly_count` INT DEFAULT 0,
  `email_yearly_count` INT DEFAULT 0,
  `last_counter_reset` DATE DEFAULT (CURRENT_DATE),
  `gate_notification_channel` ENUM('both', 'sms', 'email', 'disabled') DEFAULT 'both',
  `absence_notification_channel` ENUM('both', 'sms', 'email', 'disabled') DEFAULT 'email',
  `consecutive_absence_limit` INT DEFAULT 3,
  `template_time_in` TEXT DEFAULT NULL,
  `template_time_out` TEXT DEFAULT NULL,
  `template_absent_3x` TEXT DEFAULT NULL,
  `template_gate_absent` TEXT DEFAULT NULL,
  `updated_at` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed default row if none exists
INSERT INTO `system_notification_config` (`id`, `sms_subscription_tier`, `sms_daily_count`, `sms_monthly_count`, `sms_yearly_count`, `last_counter_reset`, `gate_notification_channel`, `absence_notification_channel`, `consecutive_absence_limit`, `template_time_in`, `template_time_out`, `template_absent_3x`, `template_gate_absent`, `updated_at`)
SELECT 1, 'free_50', 0, 0, 0, CURRENT_DATE, 'both', 'email', 3,
  'LDB-FRAS: {student_name} has safely arrived at {school_name} on {date} at {time}.',
  'LDB-FRAS: {student_name} has departed {school_name} on {date} at {time}.',
  'ALERT: {student_name} has accumulated 3 consecutive absences in {subject_name} as of {date}.',
  'LDB-FRAS: {student_name} was marked absent for {subject_name} on {date}.',
  NOW()
WHERE NOT EXISTS (SELECT 1 FROM `system_notification_config` WHERE id = 1);

-- Migration for existing installations: replace old single column with split channels
CALL `drop_column_if_exists`('system_notification_config', 'notification_dispatch_mode');
CALL `ensure_column`('system_notification_config', 'gate_notification_channel',
  "ENUM('both', 'sms', 'email', 'disabled') DEFAULT 'both' AFTER `last_counter_reset`");
CALL `ensure_column`('system_notification_config', 'absence_notification_channel',
  "ENUM('both', 'sms', 'email', 'disabled') DEFAULT 'email' AFTER `gate_notification_channel`");
CALL `ensure_column`('system_notification_config', 'consecutive_absence_limit',
  "INT DEFAULT 3 AFTER `absence_notification_channel`");
CALL `ensure_column`('system_notification_config', 'template_gate_absent',
  "TEXT DEFAULT NULL AFTER `template_absent_3x`");
CALL `ensure_column`('system_notification_config', 'email_daily_count',
  "INT DEFAULT 0 AFTER `sms_yearly_count`");
CALL `ensure_column`('system_notification_config', 'email_monthly_count',
  "INT DEFAULT 0 AFTER `email_daily_count`");
CALL `ensure_column`('system_notification_config', 'email_yearly_count',
  "INT DEFAULT 0 AFTER `email_monthly_count`");

-- Backfill existing rows if they were using the old column
UPDATE `system_notification_config`
SET `gate_notification_channel` = COALESCE(`gate_notification_channel`, 'both'),
    `absence_notification_channel` = COALESCE(`absence_notification_channel`, 'email')
WHERE `gate_notification_channel` IS NULL OR `absence_notification_channel` IS NULL;

-- ═══════════════════════════════════════════════════════════════════════════
-- STEP 2: Create notification_logs table
-- ═══════════════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS `notification_logs` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `student_id` INT DEFAULT NULL,
  `recipient_contact` VARCHAR(255) NOT NULL,
  `channel` ENUM('SMS', 'EMAIL', 'BOTH') NOT NULL,
  `trigger_event` ENUM('TIME_IN', 'TIME_OUT', '3X_ABSENCE', 'GATE_ABSENT') NOT NULL,
  `delivery_status` ENUM('SUCCESS', 'FAILED', 'QUOTA_EXCEEDED') NOT NULL,
  `error_message` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_student_id` (`student_id`),
  INDEX `idx_trigger_event` (`trigger_event`),
  INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ═══════════════════════════════════════════════════════════════════════════
-- STEP 3: Role-Based Announcement Templates & Targeted Audience Routing
-- ═══════════════════════════════════════════════════════════════════════════

-- 3a. Extend `announcements` with role/scope routing columns
CALL `ensure_column`('announcements', 'body_html', "TEXT DEFAULT NULL AFTER `body`");
CALL `ensure_column`('announcements', 'attachment_path', "VARCHAR(255) DEFAULT NULL AFTER `channels`");
CALL `ensure_column`('announcements', 'recipient_type',
  "VARCHAR(100) DEFAULT 'all' COMMENT 'Comma-separated admin scopes: all_parents,all_teachers,advisers_only,grade,individual; or advisory_class' AFTER `template_type`");
CALL `ensure_column`('announcements', 'target_section_id',
  "INT UNSIGNED DEFAULT NULL COMMENT 'Locked section for class-adviser (advisory_class) announcements' AFTER `recipient_type`");
CALL `ensure_column`('announcements', 'scope',
  "ENUM('school','advisory') DEFAULT 'school' COMMENT 'school = admin broadcast, advisory = class-adviser section scope' AFTER `target_section_id`");

-- Backfill legacy rows (older rows were always school-wide)
UPDATE `announcements`
SET `scope` = COALESCE(`scope`, 'school'),
    `recipient_type` = COALESCE(`recipient_type`, 'all')
WHERE `scope` IS NULL OR `recipient_type` IS NULL;

-- Indexes for advisory lookup + filtering
CALL `ensure_index`('announcements', 'idx_announcements_scope', '(`scope`)');
CALL `ensure_index`('announcements', 'idx_announcements_target_section', '(`target_section_id`)');
CALL `ensure_index`('announcements', 'idx_announcements_created_by', '(`created_by`)');

-- 3b. Pre-configured template library (custom/user templates + preset persistence)
CREATE TABLE IF NOT EXISTS `announcement_templates` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(150) NOT NULL,
  `icon` VARCHAR(60) DEFAULT 'bi-bookmark',
  `color` VARCHAR(30) DEFAULT 'dark',
  `subject` VARCHAR(255) NOT NULL,
  `body` TEXT NOT NULL,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3c. Persist each teacher's advisory section id for fast, tamper-proof lookup
CALL `ensure_column`('teachers', 'advisory_section_id',
  "INT UNSIGNED DEFAULT NULL AFTER `advisory_class`");

-- Backfill advisory_section_id for existing teachers from their advisory_class
-- label (e.g. "7St. Niño" = grade_level + section_name, no separator).
-- COLLATE coercion avoids "Illegal mix of collations" between utf8mb4_0900_ai_ci
-- and utf8mb4_unicode_ci columns.
UPDATE `teachers` t
LEFT JOIN `sections` s ON CONCAT(s.grade_level, s.section_name) COLLATE utf8mb4_unicode_ci = t.`advisory_class`
SET t.`advisory_section_id` = s.id
WHERE t.`advisory_class` IS NOT NULL
  AND t.`advisory_class` != ''
  AND t.`advisory_section_id` IS NULL
  AND s.id IS NOT NULL;
