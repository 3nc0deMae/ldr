<?php

require_once __DIR__ . '/config.php';
requireLogin();
csrfMiddleware(true);

header('Content-Type: application/json');

ob_start();

function emitJson($data, $statusCode = 200) {
    http_response_code($statusCode);
    $payload = json_encode($data);
    $buffer = ob_get_contents();
    if ($buffer !== false && $buffer !== '') {
        @file_put_contents(__DIR__ . '/uploads/logs/save_templates_debug.log', '[' . date('Y-m-d H:i:s') . '] BUFFERED OUTPUT: ' . substr($buffer, 0, 2000) . "\n", FILE_APPEND | LOCK_EX);
        ob_clean();
    }
    echo $payload;
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['save_parent_templates'])) {
    emitJson(['success' => false, 'message' => 'Invalid request.'], 400);
}

if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    emitJson(['success' => false, 'message' => 'Unauthorized. Admin access required.'], 403);
}

try {
    $timeIn   = isset($_POST['template_time_in']) ? trim($_POST['template_time_in']) : '';
    $timeOut  = isset($_POST['template_time_out']) ? trim($_POST['template_time_out']) : '';
    $absent3x = isset($_POST['template_absent_3x']) ? trim($_POST['template_absent_3x']) : '';
    $gateAbsent = isset($_POST['template_gate_absent']) ? trim($_POST['template_gate_absent']) : '';

    $stmt = $db->prepare(
        "INSERT INTO system_notification_config 
         (id, template_time_in, template_time_out, template_absent_3x, template_gate_absent, updated_at)
         VALUES (1, :t_in, :t_out, :t_3x, :t_gate, NOW())
         ON DUPLICATE KEY UPDATE 
         template_time_in = VALUES(template_time_in), 
         template_time_out = VALUES(template_time_out), 
         template_absent_3x = VALUES(template_absent_3x), 
         template_gate_absent = VALUES(template_gate_absent), 
         updated_at = NOW()"
    );

    $result = $stmt->execute([
        ':t_in'  => $timeIn,
        ':t_out' => $timeOut,
        ':t_3x'  => $absent3x,
        ':t_gate' => $gateAbsent,
    ]);

    if (!$result) {
        emitJson(['success' => false, 'message' => 'Database execution failed.'], 500);
    }

    logAudit($db, 'update_notification_templates', 'Admin updated parent notification templates from announcements page.');

    emitJson(['success' => true, 'message' => 'Notification templates saved successfully.']);
} catch (Exception $e) {
    error_log('Save templates error: ' . $e->getMessage());
    $debugFile = __DIR__ . '/uploads/logs/save_templates_debug.log';
    @file_put_contents($debugFile, '[' . date('Y-m-d H:i:s') . '] ERROR: ' . $e->getMessage() . "\n", FILE_APPEND | LOCK_EX);
    
    $msg = $e->getMessage();
    if (strpos($msg, "42S02") !== false || strpos($msg, "doesn't exist") !== false || strpos($msg, "does not exist") !== false) {
        emitJson(['success' => false, 'message' => 'Database setup required. The notification tables have not been created yet. Please run the database migration script from the root directory: update_database_schema.sql (import it into phpMyAdmin or run via MySQL command line).'], 500);
    }
    
    emitJson(['success' => false, 'message' => 'Failed to save templates: ' . $e->getMessage()], 500);
}
