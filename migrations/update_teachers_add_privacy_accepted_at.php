<?php

require_once __DIR__ . '/../config.php';

try {
    $col = $db->query("SHOW COLUMNS FROM teachers LIKE 'privacy_accepted_at'")->fetchAll();
    if (empty($col)) {
        $db->exec("ALTER TABLE teachers ADD COLUMN privacy_accepted_at datetime DEFAULT NULL COMMENT 'Timestamp of data privacy consent'");
    }
} catch (Exception $e) {
    error_log('teachers mig privacy_accepted_at: ' . $e->getMessage());
}
