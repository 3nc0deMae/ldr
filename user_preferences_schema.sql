-- ============================================================
-- User Preferences Schema
-- Stores per-user workspace environment variables for
-- Teacher and Gate Guard roles
-- ============================================================

CREATE TABLE IF NOT EXISTS `user_preferences` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL COMMENT 'FK to users.id',
  `default_subject` VARCHAR(255) DEFAULT NULL COMMENT 'Teacher: default assigned section/subject',
  `webcam_device` VARCHAR(50) DEFAULT '0' COMMENT 'Teacher: webcam device index (0=builtin, 1=external)',
  `low_attendance_warning_margin` INT DEFAULT 20 COMMENT 'Teacher: percentage threshold for low attendance warning',
  `session_timeout_limit` INT DEFAULT 30 COMMENT 'Teacher: minutes before auto-killing python camera process',
  `audio_feedback_profile` VARCHAR(50) DEFAULT 'standard_chime' COMMENT 'Gate: audio profile (standard_chime, voice_greeting, muted)',
  `log_stream_refresh_rate` VARCHAR(50) DEFAULT 'realtime' COMMENT 'Gate: log refresh rate (realtime, 5s, 30s)',
  `biometric_tolerance_limit` DECIMAL(3,2) DEFAULT 0.60 COMMENT 'Gate: ML biometric tolerance (0.40–0.70)',
  `startup_checkpoint_mode` VARCHAR(50) DEFAULT 'time_in' COMMENT 'Gate: default view on startup (time_in, time_out)',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_preferences_user` (`user_id`),
  CONSTRAINT `fk_user_preferences_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
