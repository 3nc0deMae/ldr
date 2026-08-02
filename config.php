<?php


// Environment Detection (set to 'production' for live server)
$environment = 'development'; // Change to 'production' for deployment

// Error Reporting
if ($environment === 'production') {
    error_reporting(0);
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
    ini_set('error_log', __DIR__ . '/uploads/logs/php-error.log');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
}

// Timezone
date_default_timezone_set('Asia/Manila');

// Session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include core files
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/security.php';

// Global database connection (used by pages that reference $db directly)
$db = getDB();

// ============================================================
// SCHEMA MIGRATIONS (idempotent, safe to run on every request)
// ============================================================
try {
    $db->exec("CREATE TABLE IF NOT EXISTS calendar_events (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(255) NOT NULL,
        description TEXT,
        event_date DATE NOT NULL,
        event_time TIME DEFAULT NULL,
        event_type ENUM('meeting','deadline','reminder','general') DEFAULT 'general',
        created_by INT DEFAULT NULL,
        is_completed TINYINT(1) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_event_date (event_date),
        INDEX idx_created_by (created_by)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) { error_log('mig calendar_events: ' . $e->getMessage()); }

try {
    $col = $db->query("SHOW COLUMNS FROM teachers LIKE 'advisory_section_id'")->fetchAll();
    if (empty($col)) $db->exec("ALTER TABLE teachers ADD COLUMN advisory_section_id INT DEFAULT NULL AFTER advisory_class");
} catch (Exception $e) { error_log('mig advisory_section_id: ' . $e->getMessage()); }

// Convert uncaught exceptions in API endpoints to clean JSON responses
set_exception_handler(function (Throwable $e) {
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    if (stripos($script, '/api/') !== false) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    } else {
        http_response_code(500);
        echo 'An unexpected error occurred.';
    }
    error_log('Uncaught exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    exit;
});

// Dynamic Base URL Detection
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
$baseDir = dirname($scriptName);
$baseDir = str_replace('\\', '/', $baseDir);
$baseDir = preg_replace('/(\/(admin|teacher|gate|api|includes|python|tests|uploads|assets|deploy|docs))*$/i', '', $baseDir);
if ($baseDir === '/') {
    $baseDir = '';
}
define('BASE_URL', $baseDir);

// System Constants
define('APP_NAME', 'LDB-FRAS');
define('APP_FULL_NAME', 'Liceo de Baleno Facial Recognition Attendance System');
define('APP_VERSION', '1.0.0');
define('APP_YEAR', '2026');

// Theme Colors
define('COLOR_PRIMARY', '#0A224C');
define('COLOR_SECONDARY', '#0066FE');
define('COLOR_BACKGROUND', '#F7F9FC');
define('COLOR_SUCCESS', '#28A745');
define('COLOR_WARNING', '#FFC107'); 
define('COLOR_DANGER', '#DC3545');

// Paths
define('ROOT_PATH', __DIR__);
define('UPLOADS_PATH', __DIR__ . '/uploads');
define('FACES_PATH', __DIR__ . '/uploads/faces');

// Python Face Recognition API
define('PYTHON_API_URL', 'https://creative-rejoicing-production-9ea0.up.railway.app');
define('PYTHON_API_KEY', 'ldb_fras_api_key_2026');

// User Roles
define('ROLE_ADMIN', 'admin');
define('ROLE_TEACHER', 'teacher');
define('ROLE_GATE', 'gate');
define('ROLE_PARENT', 'parent');

// Attendance Status
define('ATT_PRESENT', 'present');
define('ATT_ABSENT', 'absent');
define('ATT_LATE', 'late');

// Notification Channels
define('NOTIFY_EMAIL', 'email');
define('NOTIFY_SMS', 'sms');    

// Pagination
define('PER_PAGE', 10);

// PHPMailer SMTP Configuration (defaults, overridden by database settings)
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_ENCRYPTION', 'tls');
define('SMTP_FROM_EMAIL', 'noreply@liceodebaleno.edu.ph');
define('SMTP_FROM_NAME', 'LDB-FRAS');
define('SMTP_TIMEOUT', 30);

// Email assets (absolute URLs accessible to email recipients)
define('APP_LOGO_URL', 'http://localhost/Tin/assets/images/ldb_logo.webp');

// Email asset file path (for CID embedding via PHPMailer)
define('APP_LOGO_PATH', __DIR__ . '/assets/images/ldb_logo.webp');
