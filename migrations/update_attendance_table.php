<?php
/**
 * Migration: Update attendance table to support pending status and updated_at
 */

require_once __DIR__ . '/../config.php';

$db = getDB();

try {
    // Add updated_at column if it doesn't exist
    $stmt = $db->prepare("SHOW COLUMNS FROM attendance LIKE 'updated_at'");
    $stmt->execute();
    if (!$stmt->fetch()) {
        $db->exec("ALTER TABLE attendance ADD COLUMN updated_at datetime DEFAULT NULL ON UPDATE current_timestamp()");
        echo "Added updated_at column to attendance table.\n";
    } else {
        echo "updated_at column already exists.\n";
    }

    // Update status enum to include 'pending' and 'excused'
    $stmt = $db->prepare("SHOW COLUMNS FROM attendance WHERE Field = 'status'");
    $stmt->execute();
    $row = $stmt->fetch();
    if ($row && (strpos($row['Type'], 'pending') === false || strpos($row['Type'], 'excused') === false)) {
        $db->exec("ALTER TABLE attendance MODIFY COLUMN status enum('present','absent','late','pending','excused') NOT NULL DEFAULT 'present'");
        echo "Updated status enum to include 'pending' and 'excused'.\n";
    } else {
        echo "Status enum already includes 'pending' and 'excused'.\n";
    }

    echo "Migration completed successfully.\n";
} catch (Exception $e) {
    echo "Migration error: " . $e->getMessage() . "\n";
}
