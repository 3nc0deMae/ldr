<?php
// Safe migration: drop subject_code column and related indexes if present
// Usage: php migrations/remove_subject_code.php

require_once __DIR__ . '/../config.php';

try {
    $db->beginTransaction();

    // Check for column existence
    $stmt = $db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subjects' AND COLUMN_NAME = 'subject_code'");
    $stmt->execute();
    $colExists = (int)$stmt->fetchColumn();

    if ($colExists > 0) {
        $db->exec("ALTER TABLE subjects DROP COLUMN subject_code");
        echo "Dropped column subject_code\n";
    } else {
        echo "Column subject_code not found; skipping column drop.\n";
    }

    // Find and drop indexes if present
    $idxStmt = $db->prepare("SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subjects' AND INDEX_NAME IN ('idx_subjects_code','subject_code')");
    $idxStmt->execute();
    $indexes = $idxStmt->fetchAll(PDO::FETCH_COLUMN);
    foreach ($indexes as $idx) {
        $sql = "ALTER TABLE subjects DROP INDEX `" . str_replace('`','', $idx) . "`";
        $db->exec($sql);
        echo "Dropped index: $idx\n";
    }

    $db->commit();
    echo "Migration completed successfully.\n";
} catch (Exception $e) {
    $db->rollBack();
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
