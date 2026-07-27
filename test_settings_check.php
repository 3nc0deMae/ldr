<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/notifications.php';

$db = getDB();
$stmt = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE '%sms%' OR setting_key LIKE '%api%'");
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    echo $r['setting_key'] . ' = ' . $r['setting_value'] . PHP_EOL;
}
if (empty($rows)) {
    echo "No SMS/API settings found in database.\n";
}
?>
