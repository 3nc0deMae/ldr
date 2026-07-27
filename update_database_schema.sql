-- ═══════════════════════════════════════════════════════════════════════════
-- LDB-FRAS Database Schema Upgrade
-- Parent Notification Management & Textbee SMS Usage Tracker
-- Generated: July 2026
--
-- HOW TO RUN:
--   mysql -u root -p ldb_fras < update_database_schema.sql
--   OR import via phpMyAdmin → Import tab
-- ═══════════════════════════════════════════════════════════════════════════

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
  `last_counter_reset` DATE DEFAULT CURRENT_DATE,
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
ALTER TABLE `system_notification_config` 
  DROP COLUMN IF EXISTS `notification_dispatch_mode`,
  ADD COLUMN IF NOT EXISTS `gate_notification_channel` ENUM('both', 'sms', 'email', 'disabled') DEFAULT 'both' AFTER `last_counter_reset`,
  ADD COLUMN IF NOT EXISTS `absence_notification_channel` ENUM('both', 'sms', 'email', 'disabled') DEFAULT 'email' AFTER `gate_notification_channel`,
  ADD COLUMN IF NOT EXISTS `consecutive_absence_limit` INT DEFAULT 3 AFTER `absence_notification_channel`,
  ADD COLUMN IF NOT EXISTS `template_gate_absent` TEXT DEFAULT NULL AFTER `template_absent_3x`,
  ADD COLUMN IF NOT EXISTS `email_daily_count` INT DEFAULT 0 AFTER `sms_yearly_count`,
  ADD COLUMN IF NOT EXISTS `email_monthly_count` INT DEFAULT 0 AFTER `email_daily_count`,
  ADD COLUMN IF NOT EXISTS `email_yearly_count` INT DEFAULT 0 AFTER `email_monthly_count`;

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
