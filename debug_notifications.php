<?php
require_once __DIR__ . '/config.php';
$db = getDB();

echo "=== system_notification_config ===\n";
$stmt = $db->query('SELECT * FROM system_notification_config ORDER BY id ASC LIMIT 1');
$config = $stmt->fetch();
if ($config) {
    echo 'id: ' . $config['id'] . "\n";
    echo 'gate_notification_channel: ' . ($config['gate_notification_channel'] ?? 'NULL') . "\n";
    echo 'absence_notification_channel: ' . ($config['absence_notification_channel'] ?? 'NULL') . "\n";
    echo 'template_time_in: ' . substr($config['template_time_in'] ?? 'NULL', 0, 60) . "\n";
    echo 'template_time_out: ' . substr($config['template_time_out'] ?? 'NULL', 0, 60) . "\n";
    echo 'template_gate_absent: ' . substr($config['template_gate_absent'] ?? 'NULL', 0, 60) . "\n";
    echo 'sms_daily_count: ' . ($config['sms_daily_count'] ?? 'NULL') . "\n";
    echo 'email_daily_count: ' . ($config['email_daily_count'] ?? 'NULL') . "\n";
} else {
    echo "NO CONFIG ROW\n";
}

echo "\n=== SMTP settings ===\n";
$keys = ['smtp_host','smtp_port','smtp_username','smtp_password','smtp_from_email','smtp_from_name','enable_email_notifications'];
foreach ($keys as $k) {
    $stmt = $db->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');
    $stmt->execute([$k]);
    $val = $stmt->fetchColumn();
    echo $k . ' = ' . ($val !== false ? $val : '(NOT SET)') . "\n";
}

echo "\n=== Recent notification_logs ===\n";
$stmt = $db->query('SELECT * FROM notification_logs ORDER BY created_at DESC LIMIT 10');
$rows = $stmt->fetchAll();
foreach ($rows as $r) {
    echo '[' . $r['created_at'] . '] event=' . $r['trigger_event'] . ' channel=' . $r['channel'] . ' status=' . $r['delivery_status'] . ' contact=' . $r['recipient_contact'] . ' err=' . ($r['error_message'] ?: '-') . "\n";
}

echo "\n=== Recent emails sent (notifications table) ===\n";
$stmt = $db->query("SELECT * FROM notifications WHERE channel='email' ORDER BY sent_at DESC LIMIT 10");
$rows = $stmt->fetchAll();
foreach ($rows as $r) {
    echo '[' . $r['sent_at'] . '] to=' . $r['recipient'] . ' status=' . $r['status'] . ' err=' . ($r['error_message'] ?: '-') . "\n";
}

echo "\n=== Guardians with contacts ===\n";
$stmt = $db->query("SELECT s.student_id, s.first_name, s.last_name, g.guardian_name, g.phone, g.email FROM guardians g JOIN students s ON g.student_id = s.id WHERE g.phone IS NOT NULL AND TRIM(g.phone) <> '' OR g.email IS NOT NULL AND TRIM(g.email) <> '' LIMIT 20");
$rows = $stmt->fetchAll();
foreach ($rows as $r) {
    echo $r['student_id'] . ' | ' . $r['first_name'] . ' ' . $r['last_name'] . ' | ' . $r['guardian_name'] . ' | phone=' . ($r['phone'] ?: '-') . ' | email=' . ($r['email'] ?: '-') . "\n";
}
