<?php
/**
 * Standalone schema migration script.
 * Run ONCE after deployment or when schema changes are needed:
 *   php migrations/run_once.php
 *
 * All migration logic that was previously embedded in config.php,
 * notifications.php, gate.php, and send_notification_helper.php
 * has been consolidated here.
 */

require_once __DIR__ . '/../config.php';

$db = getDB();
$applied = 0;
$skipped = 0;

function migrate($db, $name, callable $fn) {
    global $applied, $skipped;
    try {
        $fn($db);
        $applied++;
        echo "[OK]   $name\n";
    } catch (Exception $e) {
        $skipped++;
        echo "[SKIP] $name — " . $e->getMessage() . "\n";
    }
}

echo "Running schema migrations...\n\n";

// 1. calendar_events table
migrate($db, 'calendar_events table', function ($db) {
    $db->exec("CREATE TABLE IF NOT EXISTS calendar_events (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(255) NOT NULL,
        description TEXT,
        event_date DATE NOT NULL,
        event_time TIME DEFAULT NULL,
        event_type ENUM('meeting','deadline','reminder','general') DEFAULT 'general',
        created_by INT DEFAULT NULL,
        is_completed TINYINT(1) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_event_date (event_date),
        INDEX idx_created_by (created_by)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
});

// 2. teachers.advisory_section_id column
migrate($db, 'teachers.advisory_section_id', function ($db) {
    $col = $db->query("SHOW COLUMNS FROM teachers LIKE 'advisory_section_id'")->fetchAll();
    if (empty($col)) $db->exec("ALTER TABLE teachers ADD COLUMN advisory_section_id INT DEFAULT NULL AFTER advisory_class");
});

// 3. user_notifications.user_id column
migrate($db, 'user_notifications.user_id', function ($db) {
    $col = $db->query("SHOW COLUMNS FROM user_notifications LIKE 'user_id'")->fetchAll();
    if (empty($col)) $db->exec("ALTER TABLE user_notifications ADD COLUMN user_id INT NULL DEFAULT NULL");
});

// 4. attendance.updated_at column
migrate($db, 'attendance.updated_at', function ($db) {
    $col = $db->query("SHOW COLUMNS FROM attendance LIKE 'updated_at'")->fetchAll();
    if (empty($col)) $db->exec("ALTER TABLE attendance ADD COLUMN updated_at datetime DEFAULT NULL ON UPDATE current_timestamp()");
});

// 5. attendance.status enum update
migrate($db, 'attendance.status enum', function ($db) {
    $row = $db->query("SHOW COLUMNS FROM attendance WHERE Field = 'status'")->fetch();
    if ($row && (strpos($row['Type'], 'pending') === false || strpos($row['Type'], 'excused') === false)) {
        $db->exec("ALTER TABLE attendance MODIFY COLUMN status enum('present','absent','late','pending','excused') NOT NULL DEFAULT 'present'");
    }
});

// 6. announcements.status sending enum
migrate($db, 'announcements.status sending', function ($db) {
    $row = $db->query("SHOW COLUMNS FROM announcements WHERE Field = 'status'")->fetch();
    if ($row && strpos($row['Type'], 'sending') === false) {
        $db->exec("ALTER TABLE announcements MODIFY COLUMN status enum('sent','scheduled','draft','failed','sending') NOT NULL DEFAULT 'sent'");
    }
});

// 7. announcement_templates table
migrate($db, 'announcement_templates table', function ($db) {
    $db->exec("CREATE TABLE IF NOT EXISTS announcement_templates (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        name VARCHAR(150) NOT NULL,
        icon VARCHAR(60) DEFAULT 'bi-bookmark',
        color VARCHAR(30) DEFAULT 'dark',
        subject VARCHAR(255) NOT NULL,
        body TEXT NOT NULL,
        created_by INT UNSIGNED DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
});

// 8. announcement_templates_hidden table
migrate($db, 'announcement_templates_hidden table', function ($db) {
    $db->exec("CREATE TABLE IF NOT EXISTS announcement_templates_hidden (
        preset_key VARCHAR(50) NOT NULL PRIMARY KEY
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
});

// 9. announcements attachment columns
migrate($db, 'announcements link attachments', function ($db) {
    $cols = $db->query("SHOW COLUMNS FROM announcements WHERE Field IN ('attachment_link','attachment_link_label')")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('attachment_link', $cols, true)) {
        $db->exec("ALTER TABLE announcements ADD COLUMN attachment_link VARCHAR(500) DEFAULT NULL AFTER attachment_path");
    }
    if (!in_array('attachment_link_label', $cols, true)) {
        $db->exec("ALTER TABLE announcements ADD COLUMN attachment_link_label VARCHAR(150) DEFAULT NULL AFTER attachment_link");
    }
});

// 10. system_settings table + legal seed
migrate($db, 'system_settings + legal seed', function ($db) {
    $db->exec("CREATE TABLE IF NOT EXISTS system_settings (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        setting_key VARCHAR(100) NOT NULL,
        setting_value TEXT DEFAULT NULL,
        description VARCHAR(255) DEFAULT NULL,
        updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uk_setting_key (setting_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    if (!defined('DEFAULT_DPA_MASTER_NOTICE')) {
        define('DEFAULT_DPA_MASTER_NOTICE', 'See config.php for full text');
    }
    if (!defined('DEFAULT_SYSTEM_TERMS_CONDITIONS')) {
        define('DEFAULT_SYSTEM_TERMS_CONDITIONS', 'See config.php for full text');
    }

    $seedLegal = [
        ['dpa_master_notice', DEFAULT_DPA_MASTER_NOTICE, 'Master Data Privacy Notice (RA 10173)'],
        ['system_terms_conditions', DEFAULT_SYSTEM_TERMS_CONDITIONS, 'System Terms and Conditions'],
    ];
    $chk = $db->prepare("SELECT COUNT(*) FROM system_settings WHERE setting_key = ?");
    $ins = $db->prepare("INSERT INTO system_settings (setting_key, setting_value, description, updated_at) VALUES (?, ?, ?, NOW())");
    foreach ($seedLegal as $row) {
        $chk->execute([$row[0]]);
        if ((int)$chk->fetchColumn() === 0) $ins->execute($row);
    }
});

// 11. gate_sessions.session_period column
migrate($db, 'gate_sessions.session_period', function ($db) {
    $stmt = $db->query("SHOW COLUMNS FROM gate_sessions WHERE Field = 'session_period'");
    if (!$stmt->fetch()) {
        $db->exec("ALTER TABLE gate_sessions ADD COLUMN session_period enum('morning','afternoon') DEFAULT NULL AFTER session_type");
    }
});

// 12. attendance_records.status enum update
migrate($db, 'attendance_records.status enum', function ($db) {
    $stmt = $db->query("SHOW COLUMNS FROM attendance_records WHERE Field = 'status'");
    $row = $stmt->fetch();
    if ($row && strpos($row['Type'], 'pending') === false) {
        $db->exec("ALTER TABLE attendance_records MODIFY COLUMN status enum('present','late','absent','pending') NOT NULL DEFAULT 'present'");
    }
});

// 13. system_notification_config email counter columns
migrate($db, 'system_notification_config email counters', function ($db) {
    $colsStmt = $db->query("SHOW COLUMNS FROM system_notification_config WHERE Field IN ('email_daily_count','email_monthly_count','email_yearly_count')");
    $existingCols = $colsStmt->fetchAll(PDO::FETCH_COLUMN);
    $missing = array_diff(['email_daily_count','email_monthly_count','email_yearly_count'], $existingCols);
    if (!empty($missing)) {
        $alterParts = [];
        foreach ($missing as $col) {
            if ($col === 'email_daily_count') $alterParts[] = "ADD COLUMN `email_daily_count` INT DEFAULT 0 AFTER `sms_yearly_count`";
            elseif ($col === 'email_monthly_count') $alterParts[] = "ADD COLUMN `email_monthly_count` INT DEFAULT 0 AFTER `email_daily_count`";
            elseif ($col === 'email_yearly_count') $alterParts[] = "ADD COLUMN `email_yearly_count` INT DEFAULT 0 AFTER `email_monthly_count`";
        }
        if (!empty($alterParts)) {
            $db->exec("ALTER TABLE `system_notification_config` " . implode(', ', $alterParts));
        }
    }
});

// 14. system_notification_config.template_gate_absent column
migrate($db, 'system_notification_config.template_gate_absent', function ($db) {
    $colStmt = $db->query("SHOW COLUMNS FROM system_notification_config WHERE Field = 'template_gate_absent'");
    if (!$colStmt->fetch()) {
        $db->exec("ALTER TABLE `system_notification_config` ADD COLUMN `template_gate_absent` TEXT DEFAULT NULL AFTER `template_absent_3x`");
    }
});

// 15. notification_logs.trigger_event enum update
migrate($db, 'notification_logs.trigger_event enum', function ($db) {
    $evtStmt = $db->query("SHOW COLUMNS FROM notification_logs WHERE Field = 'trigger_event'");
    $evtRow = $evtStmt->fetch();
    if ($evtRow && strpos($evtRow['Type'], 'GATE_ABSENT') === false) {
        $db->exec("ALTER TABLE `notification_logs` MODIFY COLUMN `trigger_event` ENUM('TIME_IN','TIME_OUT','3X_ABSENCE','GATE_ABSENT') NOT NULL");
    }
});

echo "\nDone: $applied applied, $skipped skipped.\n";
