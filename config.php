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
require_once __DIR__ . '/includes/notifications.php';

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

try {
    $col = $db->query("SHOW COLUMNS FROM user_notifications LIKE 'user_id'")->fetchAll();
    if (empty($col)) $db->exec("ALTER TABLE user_notifications ADD COLUMN user_id INT NULL DEFAULT NULL");
} catch (Exception $e) { error_log('mig user_notifications.user_id: ' . $e->getMessage()); }

// Ensure attendance table supports status corrections (updated_at column + excused enum)
try {
    $col = $db->query("SHOW COLUMNS FROM attendance LIKE 'updated_at'")->fetchAll();
    if (empty($col)) $db->exec("ALTER TABLE attendance ADD COLUMN updated_at datetime DEFAULT NULL ON UPDATE current_timestamp()");
} catch (Exception $e) { error_log('mig attendance.updated_at: ' . $e->getMessage()); }

try {
    $row = $db->query("SHOW COLUMNS FROM attendance WHERE Field = 'status'")->fetch();
    if ($row && (strpos($row['Type'], 'pending') === false || strpos($row['Type'], 'excused') === false)) {
        $db->exec("ALTER TABLE attendance MODIFY COLUMN status enum('present','absent','late','pending','excused') NOT NULL DEFAULT 'present'");
    }
} catch (Exception $e) { error_log('mig attendance.status enum: ' . $e->getMessage()); }

// Materialize notifications for calendar events that have reached their due
// date/time, so the user is notified on whatever page they are on.
try {
    if (getCurrentUserId()) { processDueCalendarNotifications($db); }
} catch (Exception $e) { error_log('processDueCalendarNotifications: ' . $e->getMessage()); }

// Allow announcements to claim a transient 'sending' status while a scheduled
// dispatch is in progress (prevents double-sending under concurrent requests).
try {
    $row = $db->query("SHOW COLUMNS FROM announcements WHERE Field = 'status'")->fetch();
    if ($row && strpos($row['Type'], 'sending') === false) {
        $db->exec("ALTER TABLE announcements MODIFY COLUMN status enum('sent','scheduled','draft','failed','sending') NOT NULL DEFAULT 'sent'");
    }
} catch (Exception $e) { error_log('mig announcements.status sending: ' . $e->getMessage()); }

// Pre-configured template library (custom/user templates)
try {
    $db->exec("CREATE TABLE IF NOT EXISTS announcement_templates (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        name VARCHAR(150) NOT NULL,
        icon VARCHAR(60) DEFAULT 'bi-bookmark',
        color VARCHAR(30) DEFAULT 'dark',
        subject VARCHAR(255) NOT NULL,
        body TEXT NOT NULL,
        created_by INT UNSIGNED DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Exception $e) { error_log('mig announcement_templates: ' . $e->getMessage()); }

// Tracks built-in template presets an admin removed from the library
try {
    $db->exec("CREATE TABLE IF NOT EXISTS announcement_templates_hidden (
        preset_key VARCHAR(50) NOT NULL PRIMARY KEY
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Exception $e) { error_log('mig announcement_templates_hidden: ' . $e->getMessage()); }

// Announcement link attachments (URL + optional label shown in emails)
try {
    $cols = $db->query("SHOW COLUMNS FROM announcements WHERE Field IN ('attachment_link','attachment_link_label')")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('attachment_link', $cols, true)) {
        $db->exec("ALTER TABLE announcements ADD COLUMN attachment_link VARCHAR(500) DEFAULT NULL AFTER attachment_path");
    }
    if (!in_array('attachment_link_label', $cols, true)) {
        $db->exec("ALTER TABLE announcements ADD COLUMN attachment_link_label VARCHAR(150) DEFAULT NULL AFTER attachment_link");
    }
} catch (Exception $e) { error_log('mig announcements link attachments: ' . $e->getMessage()); }

// ============================================================
// DEFAULT LEGAL & COMPLIANCE CONTENT (single source of truth)
// Seeded into system_settings on first run so the Privacy Policy
// tab in Settings always shows the Master Data Privacy Notice and
// Terms & Conditions, including on a fresh Railway deployment.
// ============================================================
if (!defined('DEFAULT_DPA_MASTER_NOTICE')) {
    define('DEFAULT_DPA_MASTER_NOTICE', <<<'TXT'
1. Introduction & Commitment:
Liceo de Baleno is highly committed to protecting the personal and biometric data of its students, faculty, and stakeholders. This Privacy Notice explains how our Automated Facial Recognition Attendance System (LDB-FRAS) collects, processes, stores, and safeguards your data in absolute compliance with the Philippine Data Privacy Act of 2012 (RA 10173).

2. Scope of Data Collection:
To facilitate automated gate tracking and classroom attendance monitoring, the system securely collects and processes the following information:
- Students: Full Name, Learner Reference Number (LRN), Grade Level, Section, and three (3) distinct facial biometric photographs (Frontal, Left Profile, Right Profile).
- Parents/Guardians: Full Name, Active Contact Number, and Email Address (exclusively for automated emergency or attendance notifications).
- Faculty & Staff: Full Name, Employee ID, Department, and System Access Role (Admin, Teacher, Gate Guard).

3. Purpose of Processing:
The data gathered by LDB-FRAS is used solely for institutional purposes, which include:
- Verifying identity at school entry and exit checkpoints via the Gate Kiosks.
- Automating daily classroom attendance recording for subject teachers.
- Sending automated SMS or email alerts to parents/guardians regarding student arrivals or consecutive absences.
- Generating real-time statistical attendance analytics for administrative monitoring.

4. One-Shot Learning & Biometric Processing:
We do not raw-store video feeds or continuous recordings. The system utilizes the FaceNet512 machine learning framework to extract landmarks from the three captured enrollment photographs. These landmarks are instantly converted into a localized, 512-dimensional mathematical vector (numerical matrix). The system matches live camera inputs against these mathematical vectors to confirm identities at a 0.6 tolerance threshold, ensuring biological security without saving raw facial photos in the scanning logs.

5. Data Retention Policy:
Liceo de Baleno maintains strict data lifecycle controls. Biometric vectors and personal registration profiles are strictly retained for the duration of the current academic school year. At the end of the academic year, or upon a student's formal honorable dismissal/transfer, all associated biometric profiles and multi-angle photographs are permanently purged from the host server database. Historical numerical gate attendance logs are archived securely for institutional auditing before being scheduled for permanent deletion.

6. Security Safeguards:
Your data is securely locked within a localized network environment. The system employs strict role-based access controls (RBAC), meaning Gate Guards can only see basic identity verification prompts, and Teachers can only access attendance metrics for their assigned classes. The backend server relies on secure prepared SQL statement parameters to prevent unauthorized cross-site access, and all data transfers between the PHP dashboard and the Python machine learning microservice are fully sandboxed.

7. Third-Party Disclosure:
Liceo de Baleno does not lease, sell, or share biometric records or student profiles with third-party advertising companies, private commercial entities, or external agencies. Data is only shared with legal entities if explicitly required by law enforcement, statutory mandates, or under formal department orders from the Department of Education (DepEd).

8. Data Subject Rights:
Under RA 10173, registered students (through their legal parents/guardians) and faculty members retain full rights as data subjects. You have the explicit right to:
- Inspect, review, or request a digital copy of your recorded attendance logs.
- Request immediate correction or updating of inaccurate contact information or profile data.
- Object to processing or request the complete removal of biometric profiles (which will require returning to manual barcode/logbook attendance recording).

9. Contact and Institutional Inquiries:
For any concerns, clarifications, or requests regarding your personal information, biometric tokens, or system records, please contact the Liceo de Baleno Compliance Team directly through the school administration office.
TXT
    );
}

if (!defined('DEFAULT_SYSTEM_TERMS_CONDITIONS')) {
    define('DEFAULT_SYSTEM_TERMS_CONDITIONS', <<<'TXT'
1. Acceptance of Terms & System Purpose:
By accessing or using the Liceo de Baleno Facial Recognition Attendance System (LDB-FRAS), whether through an administrative account, teacher portal, or automated gate terminal, you agree to be legally bound by these Terms and Conditions. This software is designed exclusively for academic schedule validation, institutional logistics, and school perimeter security management.

2. Account Security & Responsibilities:
Users (Admins, Faculty, and Staff) are strictly responsible for maintaining the confidentiality of their portal access credentials. You agree to:
- Never share login passwords, active session cookies, or device tokens with unauthorized personnel.
- Log out immediately after using a public shared terminal (e.g., classroom desktop or gate kiosk monitoring screen).
- Notify the IT System Administrator instantly if you suspect any unauthorized access to your account environment.

3. Prohibited Bypasses & Anti-Spoofing Policy:
To maintain the absolute accuracy and integrity of attendance logs, users and students are strictly prohibited from attempting to manipulate or bypass the biometric recognition process. Prohibited behaviors include, but are not limited to:
- Presenting high-resolution digital screens, static photographs, or physical printouts of a student's face to gate cameras to simulate presence.
- Utilizing digital masks, physical prosthetics, or clothing configurations designed maliciously to trigger false positives or exploit the 0.6 model tolerance limit.
- Intentionally tampering with webcam connections, network cabling, or the local background Flask API execution scripts.

Penalty: Any verified attempt to spoof the system will be automatically treated as an institutional disciplinary infraction and handled under the official Student/Employee Code of Conduct.

4. Software Proprietary Rights & Copyrights:
The custom user interface elements, glassmorphism design layouts, database structural schemas, and system-specific integration code supporting the LDB-FRAS ecosystem remain the exclusive intellectual property of Liceo de Baleno. Unauthorized copying, reverse-engineering, duplication, or redistribution of the system's PHP modules or Python machine learning architecture is strictly forbidden.

5. System Availability & Service Liability Disclaimer:
Liceo de Baleno strives to ensure optimal runtime execution during core operational hours. However, the system is provided on an "as-is" and "as-available" basis. The technical development team and school administration hold no legal liability for data synchronization delays, network connection timeouts, or brief device offline states caused by:
- Local infrastructure power outages or voltage fluctuations at physical gate checkpoints.
- Server database optimization locks during heavy morning arrival rushes.
- Sudden ambient lighting shifts at gate terminals that temporarily interfere with the real-time face detection bounding box isolation.

6. Automated Action Notification Approvals:
By maintaining an active student enrollment status in the system, parents and guardians acknowledge that automated administrative workflows are authorized to trigger background communication events. This includes dispatching automated cellular SMS alerts or structural email notifications regarding real-time arrival timestamps, afternoon dismissal logs, or consecutive absence system warnings.

7. Policy Amendments & Updates:
The school administration retains the complete right to modify, amend, or adjust these system operational guidelines at any time to accommodate software version updates or national security regulations. Continued utilization of the portal or automated gate scanning hardware following a recorded adjustment constitutes explicit acceptance of the newly revised terms.
TXT
    );
}

// Ensure the system_settings table exists and seed the legal documents so the
// Privacy Policy tab is never empty on a fresh install / Railway deploy.
try {
    $db->exec("CREATE TABLE IF NOT EXISTS system_settings (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        setting_key VARCHAR(100) NOT NULL,
        setting_value TEXT DEFAULT NULL,
        description VARCHAR(255) DEFAULT NULL,
        updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uk_setting_key (setting_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $seedLegal = [
        ['dpa_master_notice', DEFAULT_DPA_MASTER_NOTICE, 'Master Data Privacy Notice (RA 10173) shown at registration'],
        ['system_terms_conditions', DEFAULT_SYSTEM_TERMS_CONDITIONS, 'System Terms and Conditions Guidelines'],
    ];
    $chkLegal = $db->prepare("SELECT COUNT(*) FROM system_settings WHERE setting_key = ?");
    $insLegal = $db->prepare("INSERT INTO system_settings (setting_key, setting_value, description, updated_at) VALUES (?, ?, ?, NOW())");
    foreach ($seedLegal as $row) {
        $chkLegal->execute([$row[0]]);
        if ((int)$chkLegal->fetchColumn() === 0) {
            $insLegal->execute($row);
        }
    }
} catch (Exception $e) { error_log('mig system_settings legal seed: ' . $e->getMessage()); }

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

// Dispatch announcements whose scheduled send time has arrived.
// Runs last so all constants (SMTP, etc.) are already defined.
try {
    if (getCurrentUserId()) { processDueScheduledAnnouncements($db); }
} catch (Exception $e) { error_log('processDueScheduledAnnouncements: ' . $e->getMessage()); }
