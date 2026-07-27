<?php
/**
 * LDB-FRAS - Settings API
 * AJAX endpoint for system settings management
 */

require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

requireRole(['admin']);

csrfMiddleware(true);

$action = $_POST['action'] ?? '';

switch ($action) {

    case 'get_all':
        $stmt = $db->query("SELECT * FROM settings ORDER BY setting_key ASC");
        $settings = $stmt->fetchAll();
        $settingsMap = [];
        foreach ($settings as $s) {
            $settingsMap[$s['setting_key']] = $s['setting_value'];
        }
        jsonResponse(['data' => $settingsMap, 'count' => count($settingsMap)]);
        break;

    case 'get':
        $key = sanitize($_POST['key'] ?? '');
        if (empty($key)) {
            jsonResponse(['error' => 'Setting key is required'], 400);
        }
        $stmt = $db->prepare("SELECT * FROM settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $setting = $stmt->fetch();
        if ($setting) {
            jsonResponse(['data' => $setting]);
        } else {
            jsonResponse(['error' => 'Setting not found'], 404);
        }
        break;

    case 'update':
        $key   = sanitize($_POST['key'] ?? '');
        $value = $_POST['value'] ?? '';

        if (empty($key)) {
            jsonResponse(['error' => 'Setting key is required'], 400);
        }

        $sql = "INSERT INTO settings (setting_key, setting_value) VALUES (:key, :value)
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)";
        $stmt = $db->prepare($sql);
        $result = $stmt->execute([':key' => $key, ':value' => $value]);

        if ($result) {
            jsonResponse(['success' => true, 'message' => 'Setting updated']);
        } else {
            jsonResponse(['error' => 'Failed to update setting'], 500);
        }
        break;

    case 'update_bulk':
        $settings = json_decode($_POST['settings'] ?? '{}', true);
        if (empty($settings)) {
            jsonResponse(['error' => 'No settings provided'], 400);
        }

        $sql = "INSERT INTO settings (setting_key, setting_value) VALUES (:key, :value)
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)";
        $stmt = $db->prepare($sql);

        $updated = 0;
        foreach ($settings as $key => $value) {
            $stmt->execute([':key' => sanitize($key), ':value' => $value]);
            $updated++;
        }

        jsonResponse(['success' => true, 'message' => "$updated setting(s) updated"]);
        break;

    default:
        jsonResponse(['error' => 'Invalid action'], 400);
}
