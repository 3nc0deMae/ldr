<?php
/**
 * Migration: Add pending status to attendance_records enum
 */

require_once __DIR__ . '/../config.php';

$db = getDB();

try {
    $stmt = $db->prepare("SHOW COLUMNS FROM attendance_records WHERE Field = 'status'");
    $stmt->execute();
    $row = $stmt->fetch();
    if ($row && strpos($row['Type'], 'pending') === false) {
        $db->exec("ALTER TABLE attendance_records MODIFY COLUMN status enum('present','late','absent','pending') NOT NULL DEFAULT 'present'");
        echo "Updated attendance_records status enum to include 'pending'.\n";
    } else {
        echo "attendance_records status enum already includes 'pending'.\n";
    }
} catch (Exception $e) {
    echo "Migration error: " . $e->getMessage() . "\n";
}
