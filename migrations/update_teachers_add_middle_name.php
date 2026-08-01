<?php

require_once __DIR__ . '/../config.php';

try {
    $col = $db->query("SHOW COLUMNS FROM teachers LIKE 'middle_name'")->fetchAll();
    if (empty($col)) {
        $db->exec("ALTER TABLE teachers ADD COLUMN middle_name VARCHAR(100) DEFAULT '' AFTER first_name");
    }
} catch (Exception $e) {
    error_log('teachers mig middle_name: ' . $e->getMessage());
}