-- ============================================================
-- TiDB-compatible schema upgrade
--
-- Applies the same objects as update_database_schema.sql, minus the
-- stored procedures and legacy `sections` backfill that TiDB Serverless
-- does not support. Safe to run once on a fresh import.
--
-- Order on a fresh TiDB database:
--   1. ldb_fras.sql
--   2. php migrations/run_once.php
--   3. mysql ... < migrations/tidb_schema_upgrade.sql
-- ============================================================

-- STEP 1: system_notification_config (columns already final in CREATE,
-- so the ensure_* procedure calls in update_database_schema.sql are moot)
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

-- STEP 2: notification_logs
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

-- STEP 3a: role-based announcement routing columns on `announcements`
ALTER TABLE `announcements` ADD COLUMN `body_html` TEXT DEFAULT NULL AFTER `body`;
ALTER TABLE `announcements` ADD COLUMN `attachment_path` VARCHAR(255) DEFAULT NULL AFTER `channels`;
ALTER TABLE `announcements` ADD COLUMN `recipient_type` VARCHAR(100) DEFAULT 'all' COMMENT 'Comma-separated admin scopes: all_parents,all_teachers,advisers_only,grade,individual; or advisory_class' AFTER `template_type`;
ALTER TABLE `announcements` ADD COLUMN `target_section_id` INT UNSIGNED DEFAULT NULL COMMENT 'Locked section for class-adviser (advisory_class) announcements' AFTER `recipient_type`;
ALTER TABLE `announcements` ADD COLUMN `scope` ENUM('school','advisory') DEFAULT 'school' COMMENT 'school = admin broadcast, advisory = class-adviser section scope' AFTER `target_section_id`;

ALTER TABLE `announcements` ADD INDEX `idx_announcements_scope` (`scope`);
ALTER TABLE `announcements` ADD INDEX `idx_announcements_target_section` (`target_section_id`);
ALTER TABLE `announcements` ADD INDEX `idx_announcements_created_by` (`created_by`);

-- STEP 4: per-user workspace preferences
CREATE TABLE IF NOT EXISTS `user_preferences` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL COMMENT 'FK to users.id',
  `default_subject` VARCHAR(255) DEFAULT NULL COMMENT 'Teacher: default assigned section/subject',
  `webcam_device` VARCHAR(50) DEFAULT '0' COMMENT 'Teacher: webcam device index (0=builtin, 1=external)',
  `low_attendance_warning_margin` INT DEFAULT 20 COMMENT 'Teacher: percentage threshold for low attendance warning',
  `session_timeout_limit` INT DEFAULT 30 COMMENT 'Teacher: minutes before auto-killing python camera process',
  `audio_feedback_profile` VARCHAR(50) DEFAULT 'standard_chime' COMMENT 'Gate: audio profile (standard_chime, voice_greeting, muted)',
  `log_stream_refresh_rate` VARCHAR(50) DEFAULT 'realtime' COMMENT 'Gate: log refresh rate (realtime, 5s, 30s)',
  `biometric_tolerance_limit` DECIMAL(3,2) DEFAULT 0.60 COMMENT 'Gate: ML biometric tolerance (0.40-0.70)',
  `startup_checkpoint_mode` VARCHAR(50) DEFAULT 'time_in' COMMENT 'Gate: default view on startup (time_in, time_out)',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_preferences_user` (`user_id`),
  CONSTRAINT `fk_user_preferences_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- STEP 5: interactive tools state (participation tracker / wheel / leaderboard)
CREATE TABLE IF NOT EXISTS `interactive_tools_state` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL COMMENT 'FK to users.id',
  `state_date` DATE NOT NULL COMMENT 'Day this state belongs to (rows expire when a new day begins)',
  `class_label` VARCHAR(255) NOT NULL COMMENT 'Grade & Section label this state is for',
  `state_json` LONGTEXT NOT NULL COMMENT 'JSON: points, pickCounts, log, wheel items, last groups',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_its_user_date_class` (`user_id`, `state_date`, `class_label`),
  CONSTRAINT `fk_its_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
