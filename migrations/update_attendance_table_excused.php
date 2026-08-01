<?php
/**
 * Migration: Add excused status to attendance table enum
 */

require_once __DIR__ . '/../config.php';

$db = getDB();

try {
    $stmt = $db->prepare("SHOW COLUMNS FROM attendance WHERE Field = 'status'");
    $stmt->execute();
    $row = $stmt->fetch();
    if ($row && strpos($row['Type'], 'excused') === false) {
        $db->exec("ALTER TABLE attendance MODIFY COLUMN status enum('present','absent','late','pending','excused') NOT NULL DEFAULT 'present'");
        echo "Updated attendance status enum to include 'excused'.\n";
    } else {
        echo "attendance status enum already includes 'excused'.\n";
    }

    echo "Migration completed successfully.\n";
} catch (Exception $e) {
    echo "Migration error: " . $e->getMessage() . "\n";
}
