-- ============================================================
-- Interactive Tools State Schema
-- Persists Participation Tracker + Points & Leaderboard (and the
-- rest of the wheel state) per teacher per day, so data survives
-- page refreshes / navigation and automatically "resets" when a
-- new day starts (each row is scoped to state_date = today).
-- ============================================================

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