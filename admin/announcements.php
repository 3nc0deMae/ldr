<?php

ob_start();

require_once __DIR__ . '/../config.php';

// ─── AJAX: Student Search ───────────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'search_students') {
    header('Content-Type: application/json');
    $search = sanitize($_GET['q'] ?? '');
    if (strlen($search) < 2) { echo json_encode([]); exit; }
    try {
        $stmt = $db->prepare(
            "SELECT id, student_id, first_name, last_name, grade_level, section
             FROM students
             WHERE (first_name LIKE :q1 OR last_name LIKE :q2 OR student_id LIKE :q3)
               AND status = 'active'
             ORDER BY last_name, first_name LIMIT 20"
        );
        $like = "%{$search}%";
        $stmt->execute([':q1' => $like, ':q2' => $like, ':q3' => $like]);
        echo json_encode($stmt->fetchAll(), JSON_INVALID_UTF8_SUBSTITUTE);
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()], JSON_INVALID_UTF8_SUBSTITUTE);
    }
    exit;
}

requireRole(['admin']);

$pageTitle = 'Announcements';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

// ─── Handle Form Submissions ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create' || $action === 'save_draft') {
        // Creation / draft handling now lives in the shared processor so that
        // role-based recipient routing is enforced for admins AND advisers.
        require_once __DIR__ . '/../process_announcement.php';
        exit;
    }

    if ($action === 'delete') {
        $id = intval($_POST['announcement_id'] ?? 0);
        if ($id > 0) {
            try {
                $db->prepare("DELETE FROM announcements WHERE id = ?")->execute([$id]);
                $_SESSION['flash_message'] = ['type' => 'success', 'message' => 'Announcement deleted.'];
            } catch (Exception $e) {
                $_SESSION['flash_message'] = ['type' => 'danger', 'message' => 'Failed to delete announcement.'];
            }
        }
        if (ob_get_length()) { ob_end_clean(); }
        header('Location: ' . BASE_URL . '/admin/announcements.php');
        exit;
    }
}

// ─── Data ───────────────────────────────────────────────────────────────────
$perPage = 10;
$currentPage = max(1, intval($_GET['page'] ?? 1));

$totalAnnouncements = 0;
try { $totalAnnouncements = (int)$db->query("SELECT COUNT(*) FROM announcements WHERE scope != 'advisory'")->fetchColumn(); } catch (Exception $e) {}

$totalPages = max(1, (int)ceil($totalAnnouncements / $perPage));
$currentPage = min($currentPage, $totalPages);
$offset = ($currentPage - 1) * $perPage;

$announcements = [];
try {
    $stmt = $db->prepare("SELECT a.*, u.email AS created_by_email FROM announcements a LEFT JOIN users u ON a.created_by = u.id WHERE a.scope != 'advisory' ORDER BY a.created_at DESC LIMIT :limit OFFSET :offset");
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $announcements = $stmt->fetchAll();
} catch (Exception $e) {}

$customTemplates = [];
try { $customTemplates = $db->query("SELECT * FROM announcement_templates ORDER BY created_at DESC")->fetchAll(); } catch (Exception $e) {}

$hiddenPresetKeys = [];
try { $hiddenPresetKeys = $db->query("SELECT preset_key FROM announcement_templates_hidden")->fetchAll(PDO::FETCH_COLUMN); } catch (Exception $e) {}

$templates = [
    'class_suspension' => [
        'title'   => 'Emergency & Suspension',
        'icon'    => 'bi-calendar-x',
        'color'   => 'danger',
        'subject' => 'EMERGENCY: School Suspension on [Date] due to [Reason/Weather]',
        'body'    => '<p>Dear Parents, Guardians, and Faculty,</p><p><br></p><p>Please be informed that classes at Liceo de Baleno are officially suspended on <strong>[Date]</strong> due to <strong>[Reason/Weather]</strong>.</p><p><br></p><p>Normal operations and classes are scheduled to resume on <strong>[Date]</strong>. Please stay safe and await further official advisories.</p><p><br></p><p>— Liceo de Baleno Administration</p>'
    ],
    'school_event' => [
        'title'   => 'Institutional Event',
        'icon'    => 'bi-calendar-event',
        'color'   => 'primary',
        'subject' => 'ANNOUNCEMENT: [Event Name] Scheduled for [Date]',
        'body'    => '<p>Dear Liceo de Baleno Community,</p><p><br></p><p>We are pleased to invite everyone to our upcoming <strong>[Event Name]</strong> taking place on <strong>[Date]</strong> at <strong>[Venue/Location]</strong>.</p><p><br></p><p>Event Schedule &amp; Details:</p><ul><li>Time: <strong>[Time]</strong></li><li>Activity: <strong>[Brief Description]</strong></li></ul><p><br></p><p>We look forward to your active participation!</p><p><br></p><p>— Liceo de Baleno Administration</p>'
    ],
    'faculty_meeting' => [
        'title'   => 'Faculty & Staff Briefing',
        'icon'    => 'bi-people',
        'color'   => 'info',
        'subject' => 'FACULTY NOTICE: Mandatory Staff Meeting on [Date]',
        'body'    => '<p>Dear Teachers and Class Advisers,</p><p><br></p><p>There will be an urgent faculty meeting on <strong>[Date]</strong> at <strong>[Time]</strong> in <strong>[Location/Online Link]</strong>.</p><p><br></p><p>Agenda Items:</p><ol><li>Quarterly Grade Submission Deadlines</li><li>Attendance &amp; FRAS System Protocols</li><li>Upcoming School Activities</li></ol><p><br></p><p>Attendance is required. Please prepare your class reports accordingly.</p><p><br></p><p>— Office of the Principal / Admin</p>'
    ],
    'general' => [
        'title'   => 'General Campus Notice',
        'icon'    => 'bi-megaphone',
        'color'   => 'secondary',
        'subject' => 'CAMPUS NOTICE: [Topic / Policy Update]',
        'body'    => '<p>Dear Parents and Students,</p><p><br></p><p>Please be advised of the following administrative update regarding <strong>[Topic]</strong>:</p><p><br></p><p>[Insert Details / Guidelines Here]</p><p><br></p><p>Thank you for your continued cooperation.</p><p><br></p><p>— Liceo de Baleno Administration</p>'
    ],
    'student_misconduct' => [
        'title'   => 'Student Misconduct',
        'icon'    => 'bi-exclamation-triangle',
        'color'   => 'warning',
        'subject' => 'NOTICE: Concern Regarding [Student Name] — [Issue]',
        'body'    => '<p>Dear Parent/Guardian of <strong>[Student Name]</strong>,</p><p><br></p><p>We are writing to inform you about a concern regarding your child observed on <strong>[Date]</strong>.</p><p><br></p><p>Incident Details:</p><ul><li>Issue: <strong>[Issue / Incident]</strong></li><li>Location: <strong>[Venue / Location]</strong></li><li>Time: <strong>[Time]</strong></li></ul><p><br></p><p>In line with the Student Handbook, we would appreciate it if you could discuss this matter with your child. You may coordinate with the Class Adviser or the Guidance Office to schedule a conference at your earliest convenience.</p><p><br></p><p>We believe that open communication between home and school will help guide your child toward better conduct.</p><p><br></p><p>— Liceo de Baleno Administration</p>'
    ]
];

// Metadata for history badges (includes legacy types still present in the DB)
$templateMeta = [
    'class_suspension'  => ['title' => 'Emergency & Suspension', 'icon' => 'bi-calendar-x'],
    'school_event'      => ['title' => 'Institutional Event',    'icon' => 'bi-calendar-event'],
    'faculty_meeting'   => ['title' => 'Faculty & Staff',        'icon' => 'bi-people'],
    'general'           => ['title' => 'Campus Notice',          'icon' => 'bi-megaphone'],
    'student_achievement' => ['title' => 'Achievement',          'icon' => 'bi-trophy'],
    'student_misconduct'  => ['title' => 'Misconduct',           'icon' => 'bi-exclamation-triangle'],
    'parent_meeting'      => ['title' => 'Meeting',              'icon' => 'bi-people']
];

$notifTemplates = [];
try {
    $stmt = $db->query("SELECT template_time_in, template_time_out, template_absent_3x, template_gate_absent FROM system_notification_config ORDER BY id ASC LIMIT 1");
    $notifTemplates = $stmt->fetch() ?: [];
} catch (Exception $e) {}

$gradeLevels = ['7','8','9','10','11','12'];

$resendAnn = null;
if (isset($_GET['resend'])) {
    $resendId = (int) $_GET['resend'];
    if ($resendId > 0) {
        try {
            $stmt = $db->prepare("SELECT * FROM announcements WHERE id = ?");
            $stmt->execute([$resendId]);
            $resendAnn = $stmt->fetch();
        } catch (Exception $e) { $resendAnn = null; }
    }
}

?>
<!-- ═══════════════════════════════════════════════════════════════════════════
     FONTS + QUILL + DESIGN SYSTEM
     ═══════════════════════════════════════════════════════════════════════════ -->

<link href="https://cdn.quilljs.com/1.3.7/quill.snow.css" rel="stylesheet">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/pages-theme.css">

<style>
/* ═══════════════════════════════════════════════════════════════════════════
   DESIGN SYSTEM — Announcements (Dark Theme matching Admin Dashboard)
   ═══════════════════════════════════════════════════════════════════════════ */

:root {
    --ann-font: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif;
    --ann-mono: 'JetBrains Mono', 'SF Mono', monospace;

    --ann-primary: #4f46e5;
    --ann-primary-light: rgba(79,70,229,0.15);
    --ann-primary-dark: #3730a3;
    --ann-primary-glow: rgba(79,70,229,0.25);

    --ann-accent: #4f46e5;
    --ann-accent-soft: rgba(79,70,229,0.15);
    --ann-accent-hover: #4338ca;

    --ann-success: #10b981;
    --ann-success-soft: rgba(16,185,129,0.15);
    --ann-danger: #ef4444;
    --ann-danger-soft: rgba(239,68,68,0.15);
    --ann-warning: #f59e0b;
    --ann-warning-soft: rgba(245,158,11,0.15);
    --ann-info: #06b6d4;
    --ann-info-soft: rgba(6,182,212,0.15);

    --ann-bg: #0b0b14;
    --ann-surface: rgba(255,255,255,0.04);
    --ann-surface-hover: rgba(255,255,255,0.07);
    --ann-surface-card: rgba(255,255,255,0.04);
    --ann-border: rgba(255,255,255,0.06);
    --ann-border-light: rgba(255,255,255,0.04);
    --ann-text: #f0ece4;
    --ann-text-secondary: rgba(255,255,255,0.55);
    --ann-text-muted: rgba(255,255,255,0.3);

    --ann-shadow-sm: 0 1px 3px rgba(0,0,0,0.3);
    --ann-shadow: 0 4px 16px rgba(0,0,0,0.25);
    --ann-shadow-md: 0 8px 24px rgba(0,0,0,0.3);
    --ann-shadow-lg: 0 12px 40px rgba(0,0,0,0.35);
    --ann-shadow-xl: 0 20px 50px rgba(0,0,0,0.4);

    --ann-radius-sm: 10px;
    --ann-radius: 14px;
    --ann-radius-lg: 16px;
    --ann-radius-xl: 20px;
    --ann-transition: 0.2s cubic-bezier(0.4, 0, 0.2, 1);
    --ann-spring: 0.35s cubic-bezier(0.34,1.56,0.64,1);

    --hist-bg: #ffffff;
    --hist-bg-hover: #f8f9fb;
    --hist-bg-thead: #f4f5f7;
    --hist-text: #1e1e2a;
    --hist-text-secondary: #5a5a72;
    --hist-text-muted: #9b9bb0;
    --hist-border: #e8e9ed;
    --hist-border-light: #f0f1f4;
}

/* ─── Base ────────────────────────────────────────────────────────────── */

/* ─── Top Navbar ──────────────────────────────────────────────────────── */
.top-navbar {
    padding: 16px 28px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    flex-wrap: wrap;
    position: sticky;
    top: 0;
    z-index: 100;
    backdrop-filter: blur(16px);
    -webkit-backdrop-filter: blur(16px);
    background: rgba(11,11,20,0.85);
}
.navbar-left { display: flex; align-items: center; gap: 16px; }
.top-navbar .page-title h5 {
    font-size: 20px;
    font-weight: 800;
    color: var(--ann-text);
    margin: 0;
    letter-spacing: -0.03em;
}
.top-navbar .page-title small {
    font-size: 13px;
    color: var(--ann-text-secondary);
    font-weight: 500;
}

/* MOBILE PAGE TITLE */
.mobile-title { display: none; padding: 14px 0 4px; }
.mobile-title-inner { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; }
.mobile-title-left h5 { font-size: 18px; font-weight: 800; letter-spacing: -0.03em; margin: 0; color: var(--ann-text); }
.mobile-title-left small { font-size: 12px; font-weight: 500; color: var(--ann-text-secondary); }
.mobile-date { font-size: 11px; font-weight: 600; padding: 6px 10px; border-radius: var(--ann-radius-sm); display: flex; align-items: center; gap: 6px; white-space: nowrap; flex-shrink: 0; margin-top: 2px; background: var(--ann-surface); color: var(--ann-text-secondary); }
.mobile-date i { font-size: 12px; color: var(--ann-accent); }

.navbar-actions { display: flex; align-items: center; gap: 10px; }
.navbar-actions .date-pill {
    font-size: 13px; font-weight: 600; padding: 8px 16px;
    border-radius: var(--ann-radius-sm);
    background: var(--ann-surface);
    border: none;
    color: var(--ann-text-secondary);
    display: flex; align-items: center; gap: 8px;
}
.navbar-actions .date-pill i { font-size: 14px; color: var(--ann-accent); }

/* NAV ICON BUTTON */
.nav-icon-btn {
    width: 40px; height: 40px;
    border-radius: var(--ann-radius-sm);
    background: var(--ann-surface);
    border: none;
    color: var(--ann-text);
    display: inline-flex; align-items: center; justify-content: center;
    cursor: pointer; transition: all var(--ann-transition); font-size: 16px;
    position: relative;
}
.nav-icon-btn:hover {
    transform: translateY(-1px);
    background: var(--ann-surface-hover);
}



.content-area { padding: 24px 28px 40px; }

/* ─── Card System ─────────────────────────────────────────────────────── */
.card {
    background: var(--ann-surface-card);
    border: none;
    border-radius: var(--ann-radius);
    box-shadow: var(--ann-shadow-sm);
    transition: all var(--ann-transition);
    overflow: hidden;
}
.card:hover { box-shadow: var(--ann-shadow); }

.card-header {
    background: transparent;
    border-bottom: 1px solid var(--ann-border);
    padding: 14px 20px;
    font-size: 14px;
    font-weight: 700;
    color: var(--ann-text);
    letter-spacing: -0.01em;
    display: flex;
    align-items: center;
}
.card-header i { color: #fff; font-size: 15px; }
.card-body { padding: 20px; color: var(--ann-text); }

/* ─── Template Cards ──────────────────────────────────────────────────── */
.template-card {
    border: none !important;
    border-radius: var(--ann-radius-sm) !important;
    transition: all var(--ann-spring) !important;
    position: relative;
    overflow: hidden;
    background: var(--ann-surface) !important;
}
.template-card::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 3px;
    background: transparent;
    transition: background 0.25s ease;
}
.template-card:hover {
    transform: translateY(-3px);
    box-shadow: var(--ann-shadow-md) !important;
    background: var(--ann-surface-hover) !important;
}
.template-card:hover::before { background: var(--ann-accent); }
.template-card.active-template {
    background: var(--ann-accent-soft) !important;
    box-shadow: 0 0 0 3px rgba(79,70,229,0.15) !important;
}
.template-card.active-template::before { background: var(--ann-accent); }
.template-card .card-body { padding: 16px 8px !important; }
.template-card .card-body i { font-size: 26px !important; transition: transform 0.25s ease; }
.template-card:hover .card-body i { transform: scale(1.12); }
.template-card .tpl-name {
    font-size: 11px; font-weight: 700; color: var(--ann-text);
    margin-top: 8px; letter-spacing: 0.02em; text-transform: uppercase;
}

/* ─── Custom Template Delete Button ──────────────────────────────────── */
.tpl-delete-btn {
    position: absolute;
    top: 6px; right: 6px;
    width: 22px; height: 22px;
    border-radius: 7px;
    border: none;
    background: var(--ann-danger-soft);
    color: var(--ann-danger);
    font-size: 11px;
    padding: 0;
    display: inline-flex; align-items: center; justify-content: center;
    cursor: pointer;
    opacity: 0;
    transition: all 0.15s ease;
    z-index: 2;
}
.template-card:hover .tpl-delete-btn { opacity: 1; }
.tpl-delete-btn:hover {
    background: var(--ann-danger); color: #fff;
    transform: scale(1.1);
}
@media (hover: none) { .tpl-delete-btn { opacity: 0.7; } }

/* ─── Subject Input ───────────────────────────────────────────────────── */
.subject-input {
    border: 1.5px solid var(--ann-border);
    border-radius: var(--ann-radius-sm);
    padding: 12px 16px;
    font-size: 15px; font-weight: 600;
    color: var(--ann-text);
    transition: all var(--ann-transition);
    background: var(--ann-surface);
    letter-spacing: -0.01em;
    width: 100%;
}
.subject-input:focus {
    border-color: var(--ann-accent);
    box-shadow: 0 0 0 3px var(--ann-primary-glow);
    outline: none;
    background: var(--ann-surface-hover);
}
.subject-input::placeholder { color: var(--ann-text-muted); font-weight: 500; }

/* ─── Quill Editor ────────────────────────────────────────────────────── */
.editor-container {
    border: 1.5px solid var(--ann-border);
    border-radius: var(--ann-radius);
    overflow: hidden;
    transition: border-color var(--ann-transition), box-shadow var(--ann-transition);
}
.editor-container:focus-within {
    border-color: var(--ann-accent);
    box-shadow: 0 0 0 3px var(--ann-primary-glow);
}
.editor-container .ql-toolbar.ql-snow,
.editor-container .ql-container.ql-snow,
.editor-container .ql-editor {
    background: #ffffff !important;
    background-color: #ffffff !important;
}
.editor-container .ql-toolbar.ql-snow {
    border: none !important;
    border-bottom: 1px solid #e8e9ed !important;
    padding: 10px 14px !important;
}
.editor-container .ql-container.ql-snow { border: none !important; }
.editor-container .ql-toolbar .ql-formats {
    margin-right: 10px;
    padding-right: 10px;
    border-right: 1px solid #e8e9ed;
}
.editor-container .ql-toolbar .ql-formats:last-child { border-right: none; margin-right: 0; padding-right: 0; }
.editor-container .ql-toolbar button { width: 30px; height: 30px; border-radius: 6px; transition: all 0.15s ease; }
.editor-container .ql-toolbar button:hover { background: rgba(79,70,229,0.08); }
.editor-container .ql-toolbar button.ql-active { background: var(--ann-accent); color: #fff; }
.editor-container .ql-toolbar button.ql-active .ql-stroke { stroke: #fff; }
.editor-container .ql-toolbar button.ql-active .ql-fill { fill: #fff; }
.editor-container .ql-toolbar select { border-radius: 6px; border-color: #e8e9ed; font-size: 12px; }

.ql-snow .ql-stroke { stroke: #4a4a5a; }
.ql-snow .ql-fill { fill: #4a4a5a; }
.ql-snow .ql-picker-label { color: #4a4a5a; border-color: #e8e9ed; }
.ql-snow .ql-picker-label:hover { color: var(--ann-accent); }
.ql-snow .ql-picker-label::before { color: #4a4a5a; }
.ql-snow .ql-picker.ql-expanded .ql-picker-label { border-color: var(--ann-accent); color: var(--ann-accent); }
.ql-snow .ql-picker-options {
    background: #ffffff !important;
    border-color: #e8e9ed;
    border-radius: var(--ann-radius-sm);
    box-shadow: 0 8px 24px rgba(0,0,0,0.12);
    padding: 6px;
}
.ql-snow .ql-picker-item { color: #4a4a5a; border-radius: 4px; padding: 4px 8px; }
.ql-snow .ql-picker-item:hover { color: var(--ann-accent); background: rgba(79,70,229,0.08); }
.ql-snow button.ql-active .ql-stroke { stroke: #fff; }
.ql-snow button.ql-active .ql-fill { fill: #fff; }
.ql-snow button:hover .ql-stroke { stroke: var(--ann-accent); }
.ql-snow button:hover .ql-fill { fill: var(--ann-accent); }
.ql-snow a { color: var(--ann-accent); }

.ql-snow .ql-tooltip {
    background: #ffffff !important;
    border: 1px solid #e8e9ed !important;
    border-radius: var(--ann-radius-sm) !important;
    box-shadow: 0 8px 24px rgba(0,0,0,0.12) !important;
    color: #1a1a2e !important;
}
.ql-snow .ql-tooltip input[type="text"] {
    background: #ffffff !important;
    border: 1px solid #e8e9ed !important;
    color: #1a1a2e !important;
    border-radius: 6px;
}
.ql-snow .ql-tooltip a.ql-action,
.ql-snow .ql-tooltip a.ql-remove { color: var(--ann-accent) !important; }
.ql-snow .ql-color-picker .ql-picker-options {
    background: #ffffff !important;
    border-color: #e8e9ed !important;
    padding: 4px !important;
}

.editor-container .ql-container {
    font-family: var(--ann-font);
    font-size: 14px;
    color: #1a1a2e;
}
.editor-container .ql-editor {
    padding: 20px 24px;
    min-height: 340px;
    line-height: 1.85;
    font-size: 14px;
    color: #1a1a2e;
}
.editor-container .ql-editor.ql-blank::before {
    font-style: normal;
    color: #9b9bb0;
    font-size: 14px;
    font-weight: 500;
}
.editor-container .ql-editor p { margin-bottom: 0.6em; color: #1a1a2e; }
.editor-container .ql-editor strong { font-weight: 700; color: #1a1a2e; }
.editor-container .ql-editor h1,
.editor-container .ql-editor h2,
.editor-container .ql-editor h3 {
    font-family: var(--ann-font);
    font-weight: 800;
    letter-spacing: -0.02em;
    color: #1a1a2e;
}
.editor-container .ql-editor blockquote {
    border-left: 3px solid var(--ann-accent);
    padding-left: 16px;
    color: #5a5a72;
    font-style: italic;
}

/* ─── Attach Button ───────────────────────────────────────────────────── */
.ql-toolbar .btn-attach {
    width: auto !important;
    height: 30px;
    padding: 0 14px !important;
    border-radius: 7px !important;
    background: var(--ann-accent) !important;
    color: #fff !important;
    border: none !important;
    font-family: var(--ann-font);
    font-size: 11.5px;
    font-weight: 600;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    margin-left: 4px;
    transition: all var(--ann-transition) !important;
}
.ql-toolbar .btn-attach:hover {
    background: var(--ann-accent-hover) !important;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px var(--ann-primary-glow);
}
.ql-toolbar .btn-attach:active { transform: translateY(0); }
.ql-toolbar .btn-attach i { font-size: 13px; }

/* ─── Placeholder Chips ───────────────────────────────────────────────── */
.placeholder-bar {
    display: flex; flex-wrap: wrap; align-items: center; gap: 6px;
    padding: 12px 20px;
    background: var(--ann-surface);
    border-top: 1px solid var(--ann-border);
}
.placeholder-bar .bar-label {
    font-size: 11px; font-weight: 600; color: var(--ann-text-muted);
    text-transform: uppercase; letter-spacing: 0.06em; margin-right: 4px;
}
.placeholder-chip {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 5px 12px; border-radius: 20px;
    font-size: 11px; font-weight: 600; font-family: var(--ann-mono);
    background: var(--ann-surface-hover);
    color: var(--ann-text-secondary);
    border: 1px solid var(--ann-border);
    cursor: pointer; transition: all var(--ann-transition); user-select: none;
}
.placeholder-chip:hover {
    background: var(--ann-accent); color: #fff;
    border-color: var(--ann-accent);
    transform: translateY(-1px);
    box-shadow: 0 3px 10px var(--ann-primary-glow);
}

/* ─── Attachment Strip ────────────────────────────────────────────────── */
.attachment-strip {
    display: flex; align-items: center; gap: 12px;
    padding: 10px 20px;
    background: var(--ann-surface-card);
    border-top: 1px solid var(--ann-border);
    cursor: pointer; transition: all var(--ann-transition);
}
.attachment-strip:hover { background: var(--ann-surface-hover); }
.attachment-strip.has-file {
    background: var(--ann-accent-soft);
    border-top-color: rgba(79,70,229,0.2);
}
.attachment-strip .strip-icon {
    width: 28px; height: 28px; border-radius: 7px;
    background: var(--ann-surface-hover);
    display: flex; align-items: center; justify-content: center;
    font-size: 13px; color: var(--ann-text-muted);
    transition: all var(--ann-transition); flex-shrink: 0;
}
.attachment-strip.has-file .strip-icon { background: var(--ann-accent); color: #fff; }
.attachment-strip .strip-text { font-size: 12px; color: var(--ann-text-muted); font-weight: 500; flex: 1; }
.attachment-strip .strip-text .file-name { color: var(--ann-accent); font-weight: 600; }
.attachment-strip .strip-remove {
    padding: 4px 12px; border-radius: 6px; border: none;
    background: var(--ann-danger-soft); color: var(--ann-danger);
    font-size: 11px; font-weight: 600; cursor: pointer;
    display: none; align-items: center; gap: 4px; transition: all 0.15s ease;
}
.attachment-strip.has-file .strip-remove { display: inline-flex; }
.attachment-strip .strip-remove:hover { background: var(--ann-danger); color: #fff; }

/* ─── Settings Cards ──────────────────────────────────────────────────── */
.settings-card { border-radius: var(--ann-radius) !important; overflow: visible !important; }
.settings-card .settings-header {
    padding: 12px 16px;
    border-bottom: 1px solid var(--ann-border);
    font-size: 12px; font-weight: 700;
    color: var(--ann-text);
    text-transform: uppercase; letter-spacing: 0.05em;
    display: flex; align-items: center; gap: 7px;
}
.settings-card .settings-header i { color: #fff; font-size: 14px; }
.settings-card .settings-body { padding: 16px; overflow: visible; }

/* ─── Form Checks ─────────────────────────────────────────────────────── */
.form-check { padding-left: 28px; margin-bottom: 8px; }
.form-check-input {
    width: 17px; height: 17px; margin-left: -28px;
    border-radius: 5px;
    border: 1.5px solid rgba(255,255,255,0.15);
    background-color: var(--ann-surface-hover);
    transition: all 0.15s ease; cursor: pointer;
}
.form-check-input[type="radio"] { border-radius: 50%; }
.form-check-input:checked { background-color: var(--ann-accent); border-color: var(--ann-accent); }
.form-check-input:focus { box-shadow: 0 0 0 3px var(--ann-primary-glow); }
.form-check-label {
    font-size: 13px; font-weight: 500;
    color: var(--ann-text);
    cursor: pointer; transition: color 0.15s ease;
}
.form-check-label:hover { color: var(--ann-accent); }

/* ─── Buttons — using evt-btn classes ─────────────────────────────────── */
.btn-send:hover {
    background: var(--ann-accent-hover);
    transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(79,70,229,0.4);
    color: #fff;
}
.btn-send:active { transform: translateY(0); }

.btn-draft {
    background: var(--ann-surface); color: var(--ann-text-secondary);
    border: 1.5px solid rgba(255,255,255,0.1);
    border-radius: var(--ann-radius-sm);
    padding: 12px 24px;
    font-size: 13px; font-weight: 600;
    display: flex; align-items: center; justify-content: center; gap: 7px;
    transition: all var(--ann-transition);
    width: 100%; cursor: pointer;
}
.btn-draft:hover {
    border-color: rgba(255,255,255,0.2);
    background: var(--ann-surface-hover);
    color: var(--ann-text);
}

/* ─── Grade Grid ──────────────────────────────────────────────────────── */
.grade-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 6px; margin-top: 10px; }
.grade-chip {
    display: flex; align-items: center; gap: 6px;
    padding: 7px 10px; border-radius: var(--ann-radius-sm);
    border: 1.5px solid var(--ann-border);
    background: var(--ann-surface);
    cursor: pointer; transition: all var(--ann-transition);
    font-size: 12px; font-weight: 600; color: var(--ann-text);
}
.grade-chip:hover { border-color: var(--ann-accent); background: var(--ann-accent-soft); }
.grade-chip.active { border-color: var(--ann-accent); background: var(--ann-accent-soft); color: var(--ann-accent); }

/* ─── Student Search ──────────────────────────────────────────────────── */
.search-box { position: relative; }
.search-box .search-input {
    border: 1.5px solid var(--ann-border);
    border-radius: var(--ann-radius-sm);
    padding: 10px 50px 10px 36px;
    font-size: 13px; width: 100%;
    transition: all var(--ann-transition);
    background: var(--ann-surface);
    color: var(--ann-text);
}
.search-box .search-input:focus {
    border-color: var(--ann-accent);
    box-shadow: 0 0 0 3px var(--ann-primary-glow);
    outline: none;
    background: var(--ann-surface-hover);
}
.search-box .search-input::placeholder { color: var(--ann-text-muted); }
.search-box .search-icon {
    position: absolute; left: 12px; top: 50%; transform: translateY(-50%);
    color: var(--ann-text-muted); font-size: 14px; pointer-events: none;
}
.search-box .search-btn {
    position: absolute; right: 8px; top: 50%; transform: translateY(-50%);
    padding: 6px 14px; border-radius: 8px; border: none;
    background: var(--ann-accent); color: #fff;
    font-size: 12px; font-weight: 600; cursor: pointer;
    display: inline-flex; align-items: center; gap: 4px;
    transition: all var(--ann-transition);
}
.search-box .search-btn:hover { background: var(--ann-accent-hover); box-shadow: 0 2px 8px var(--ann-primary-glow); }
.search-results-dropdown {
    position: absolute; top: calc(100% + 4px); left: 0; right: 0;
    background: rgba(20,20,32,0.98);
    border: 1px solid var(--ann-border);
    border-radius: var(--ann-radius-sm);
    box-shadow: var(--ann-shadow-lg);
    max-height: 200px; overflow-y: auto; z-index: 1000;
}
.search-result-item {
    display: flex; align-items: center; justify-content: space-between;
    padding: 10px 14px;
    border-bottom: 1px solid var(--ann-border);
    cursor: pointer; transition: all 0.15s ease; font-size: 13px;
}
.search-result-item:last-child { border-bottom: none; }
.search-result-item:hover { background: var(--ann-accent-soft); }
.search-result-item.selected { background: var(--ann-accent-soft); opacity: 0.6; cursor: default; }
.search-result-item .result-name { font-weight: 600; color: var(--ann-text); }
.search-result-item .result-meta { font-size: 11px; color: var(--ann-text-muted); font-family: var(--ann-mono); }
.search-result-item .result-badge {
    padding: 2px 8px; border-radius: 20px; font-size: 10px;
    font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em;
    background: var(--ann-accent-soft); color: var(--ann-accent);
}
.selected-student-tag {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 4px 6px 4px 10px; border-radius: var(--ann-radius-sm);
    background: var(--ann-accent-soft); color: var(--ann-accent);
    font-size: 11px; font-weight: 600; margin: 2px;
    border: none; animation: tagIn 0.2s ease;
}
@keyframes tagIn { from { opacity: 0; transform: scale(0.9); } to { opacity: 1; transform: scale(1); } }
.selected-student-tag .tag-remove {
    width: 16px; height: 16px; border-radius: 4px; border: none;
    background: rgba(79,70,229,0.2); color: var(--ann-accent);
    font-size: 10px; cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    transition: all 0.15s ease; padding: 0; line-height: 1;
}
.selected-student-tag .tag-remove:hover { background: var(--ann-danger); color: #fff; }

/* ─── Schedule Inputs ─────────────────────────────────────────────────── */
.schedule-input {
    border: 1.5px solid var(--ann-border);
    border-radius: var(--ann-radius-sm);
    padding: 8px 12px; font-size: 13px;
    transition: all var(--ann-transition); width: 100%;
    background: var(--ann-surface);
    color: var(--ann-text);
}
.schedule-input:focus {
    border-color: var(--ann-accent);
    box-shadow: 0 0 0 3px var(--ann-primary-glow);
    outline: none;
    background: var(--ann-surface-hover);
}
.schedule-input::-webkit-calendar-picker-indicator { filter: invert(0.7); }

/* ─── History Table ───────────────────────────────────────────────────── */
.history-table-wrapper {
    background: var(--hist-bg);
    border-radius: 0 0 var(--ann-radius) var(--ann-radius);
    overflow: hidden;
}
.table-scroll-wrapper{overflow-x:auto;-webkit-overflow-scrolling:touch}
.table-scroll-wrapper::-webkit-scrollbar{height:6px}
.table-scroll-wrapper::-webkit-scrollbar-track{background:rgba(255,255,255,0.03)}
.table-scroll-wrapper::-webkit-scrollbar-thumb{background:rgba(255,255,255,0.12);border-radius:6px}
.table-scroll-wrapper::-webkit-scrollbar-thumb:hover{background:rgba(255,255,255,0.2)}
.history-table{min-width:650px}
.history-table {
    font-size: 13px;
    color: var(--hist-text);
    background: var(--hist-bg);
    margin: 0;
    table-layout: fixed;
    width: 100%;
}
.history-table thead { background: var(--hist-bg-thead); }
.history-table thead th {
    padding: 10px 12px;
    font-size: 11px; font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.06em;
    color: var(--hist-text-muted);
    border-bottom: 1px solid var(--hist-border);
    background: transparent;
}
.history-table tbody td {
    padding: 10px 12px;
    border-bottom: 1px solid var(--hist-border-light);
    vertical-align: middle;
    color: var(--hist-text-secondary);
}
.history-table tbody tr { transition: background 0.15s ease; background: var(--hist-bg); }
.history-table tbody tr:hover { background: var(--hist-bg-hover); }
.history-table tbody tr:last-child td { border-bottom: none; }
.history-table .subject-cell {
    font-weight: 600; max-width: 200px;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    color: var(--hist-text);
}
.history-table .recipient-cell { font-size: 12px; font-weight: 600; color: var(--hist-text-secondary); }
.history-table .date-cell { font-size: 12px; font-weight: 500; color: var(--hist-text-muted); }

/* ─── History Pagination ────────────────────────────────────────────────── */
.history-pagination {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    padding: 10px 20px;
    gap: 10px;
    border-top: 1px solid var(--hist-border-light);
    background: var(--hist-bg);
}
.pagination-inner {
    display: flex;
    align-items: center;
    gap: 8px;
}
.page-link {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 7px 16px;
    border-radius: 8px;
    font-size: 12px;
    font-weight: 600;
    color: var(--ann-accent);
    background: var(--ann-accent-soft);
    text-decoration: none;
    transition: all var(--ann-transition);
    border: none;
    cursor: pointer;
}
.page-link:hover {
    background: var(--ann-accent);
    color: #fff;
    box-shadow: 0 3px 10px var(--ann-primary-glow);
}
.page-indicator {
    font-size: 12px;
    font-weight: 500;
    color: var(--hist-text-muted);
    padding: 0 8px;
}
.page-indicator strong {
    color: var(--hist-text);
    font-weight: 700;
}

/* ─── Send Button Loading ───────────────────────────────────────────────── */
.btn-loading {
    position: relative;
    pointer-events: none;
    opacity: 0.75;
}
.btn-loading .btn-text { visibility: hidden; }
.btn-loading::after {
    content: '';
    width: 18px; height: 18px;
    border: 2px solid rgba(255,255,255,0.3);
    border-top-color: #fff;
    border-radius: 50%;
    position: absolute;
    top: 50%; left: 50%;
    margin-top: -9px; margin-left: -9px;
    animation: spin 0.6s linear infinite;
}
@keyframes spin { to { transform: rotate(360deg); } }

/* ─── Status Badges ───────────────────────────────────────────────────── */
.status-badge {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 4px 12px; border-radius: 20px;
    font-size: 11px; font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.04em;
}
.status-badge::before { content: ''; width: 6px; height: 6px; border-radius: 50%; flex-shrink: 0; }
.status-badge.sent { background: rgba(16,185,129,0.12); color: #059669; }
.status-badge.sent::before { background: #059669; }
.status-badge.draft { background: #f0f0f3; color: #6b7280; }
.status-badge.draft::before { background: #9ca3af; }
.status-badge.scheduled { background: rgba(245,158,11,0.12); color: #b45309; }
.status-badge.scheduled::before { background: #b45309; }
.status-badge.failed { background: rgba(239,68,68,0.12); color: #dc2626; }
.status-badge.failed::before { background: #dc2626; }

/* ─── Channel Badges ──────────────────────────────────────────────────── */
.ch-badge {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 3px 10px; border-radius: 6px;
    font-size: 10px; font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.04em;
}
.ch-badge.email { background: rgba(6,182,212,0.12); color: #0891b2; }
.ch-badge.sms { background: rgba(16,185,129,0.12); color: #059669; }

/* ─── Type Badge ──────────────────────────────────────────────────────── */
.type-badge {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 4px 10px; border-radius: 7px;
    font-size: 11px; font-weight: 700;
}
.type-badge i { font-size: 12px; }

/* ─── Table Action Buttons ────────────────────────────────────────────── */
.tbl-action {
    width: 32px; height: 32px;
    border: 1px solid #e5e7eb;
    border-radius: var(--ann-radius-sm);
    display: inline-flex; align-items: center; justify-content: center;
    cursor: pointer; font-size: 13px;
    transition: all var(--ann-transition);
    background: #fff;
    color: #9ca3af; padding: 0;
}
.tbl-action:hover {
    border-color: var(--ann-accent);
    color: var(--ann-accent);
    background: rgba(79,70,229,0.06);
}
.tbl-action.danger-hover:hover {
    border-color: var(--ann-danger);
    color: var(--ann-danger);
    background: rgba(239,68,68,0.06);
}

/* ─── Empty State ─────────────────────────────────────────────────────── */
.empty-state { text-align: center; padding: 40px 24px; }
.empty-state .empty-icon {
    width: 72px; height: 72px; border-radius: 20px;
    background: var(--ann-accent-soft); color: var(--ann-accent);
    display: inline-flex; align-items: center; justify-content: center;
    font-size: 28px; margin-bottom: 16px;
}
.history-section .empty-state .empty-icon { color: #fff; }
.empty-state h6 { font-size: 16px; font-weight: 700; color: var(--ann-text); margin-bottom: 6px; }
.empty-state p { font-size: 13px; color: var(--ann-text-muted); max-width: 320px; margin: 0 auto; }

/* ─── Modals — Desktop — Matching Teacher Management Design ────────────────── */
.event-modal-overlay{
    position:fixed;inset:0;
    background:rgba(26,29,46,0.5);
    backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px);
    z-index:9998;display:none;
    align-items:center;justify-content:center;padding:20px;
}
.event-modal-overlay.show{display:flex}
.event-modal{
    border-radius:20px;width:min(620px, 100%);max-width:100%;
    height:90vh;max-height:90vh;
    display:flex;flex-direction:column;overflow:hidden;
    animation:modalSlideIn .35s cubic-bezier(.34,1.56,.64,1);
}
.event-modal form{
    display:flex;flex-direction:column;flex:1 1 auto;min-height:0;
}
@keyframes modalSlideIn{from{opacity:0;transform:translateY(24px) scale(.96)}to{opacity:1;transform:translateY(0) scale(1)}}

/* Header — pinned top */
.event-modal-header{
    display:flex;justify-content:space-between;align-items:center;
    padding:18px 22px;flex-shrink:0;
}
.event-modal-title{display:flex;align-items:center;gap:10px;font-size:16px;font-weight:800;letter-spacing:-0.02em}
.event-modal-title i{font-size:20px}
.event-modal-close{width:32px;height:32px;border:none;border-radius:8px;display:flex;align-items:center;justify-content:center;cursor:pointer;transition:all 0.2s cubic-bezier(0.4,0,0.2,1);font-size:13px;background:rgba(255,255,255,0.06);color:inherit}
.event-modal-close:hover{background:rgba(255,255,255,0.14)}

/* Body — scrollable if needed */
.event-modal-body{
    padding:0 22px 14px;
    overflow-y:auto;overflow-x:hidden;
    flex:1 1 auto;min-height:0;
    -webkit-overflow-scrolling:touch;
}
.event-modal-body::-webkit-scrollbar{width:5px}
.event-modal-body::-webkit-scrollbar-track{background:transparent;margin:4px 0}
.event-modal-body::-webkit-scrollbar-thumb{background:rgba(255,255,255,0.15);border-radius:10px}
.event-modal-body::-webkit-scrollbar-thumb:hover{background:rgba(255,255,255,0.25)}
.event-modal-body{scrollbar-width:thin;scrollbar-color:rgba(255,255,255,0.15) transparent}

/* Footer — pinned bottom */
.event-modal-footer{
    display:flex;justify-content:flex-end;gap:10px;
    padding:12px 22px;flex-shrink:0;
    border-top:1px solid rgba(255,255,255,0.06);
    background:rgba(255,255,255,0.02);
}

/* ─── Form Fields — compact spacing ─────────────────────────────────────── */
.evt-field{margin-bottom:12px}.evt-field:last-child{margin-bottom:0}
.evt-field label{display:block;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;margin-bottom:5px;line-height:1.3}
.evt-field label .required{color:#ef4444}
.evt-field label small{font-weight:500;text-transform:none;letter-spacing:0;opacity:0.55}
.evt-field input[type="text"],.evt-field input[type="email"],.evt-field input[type="tel"],.evt-field textarea,.evt-field select{
    width:100%;padding:9px 12px;border:1.5px solid rgba(255,255,255,0.12);
    border-radius:10px;font-size:13px;transition:all 0.2s cubic-bezier(0.4,0,0.2,1);
    font-family:'Plus Jakarta Sans',-apple-system,BlinkMacSystemFont,sans-serif;background:rgba(255,255,255,0.05);color:inherit;
}
.evt-field input:focus,.evt-field textarea:focus,.evt-field select:focus{outline:none;border-color:#4f46e5;box-shadow:0 0 0 3px rgba(79,70,229,0.2)}
.evt-field input::placeholder{color:rgba(255,255,255,0.25)}
.evt-field .form-hint{font-size:10px;margin-top:3px;opacity:0.4;line-height:1.4}
.evt-field select{background-color:rgba(255,255,255,0.05);padding-right:36px}
.evt-row{display:flex;gap:10px}
.evt-flex-1{flex:1;min-width:0}
.evt-separator{border:none;border-top:1px solid rgba(255,255,255,0.06);margin:10px 0}
.evt-section-label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;margin-bottom:10px;display:flex;align-items:center;gap:6px;opacity:0.5}
.evt-btn{padding:10px 18px;border:none;border-radius:10px;font-size:13px;font-weight:700;cursor:pointer;transition:all 0.2s cubic-bezier(0.4,0,0.2,1);display:flex;align-items:center;justify-content:center;gap:6px;width:100%}
.evt-btn-cancel{background:rgba(255,255,255,0.08);color:inherit}.evt-btn-cancel:hover{background:rgba(255,255,255,0.14)}
.evt-btn-save{background:#4f46e5;color:#fff}.evt-btn-save:hover{background:#3730a3;box-shadow:0 4px 16px rgba(79,70,229,0.2)}
.evt-btn-sm{padding:5px 10px !important;font-size:11px !important;font-weight:600 !important;border-radius:6px !important;gap:4px !important;width:auto !important;display:inline-flex !important}

/* Delete modal */
.delete-modal{width:400px;height:auto;max-height:90vh}
.delete-modal-icon{width:56px;height:56px;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 12px;font-size:24px}
.delete-modal-text{text-align:center}
.delete-modal-text h6{font-weight:700;font-size:16px;letter-spacing:-0.02em;margin-bottom:6px}
.delete-modal-text p{font-size:13px;max-width:280px;margin:0 auto;line-height:1.5}

.evt-btn-danger{background:#ef4444;color:#fff}.evt-btn-danger:hover{background:#dc2626;box-shadow:0 4px 14px rgba(239,68,68,0.3)}

/* Preview modal styles */
.preview-label {
    font-size: 10px; font-weight: 700; text-transform: uppercase;
    letter-spacing: 0.08em; color: rgba(255,255,255,0.55); margin-bottom: 6px;
}
.preview-card {
    border: 1px solid rgba(255,255,255,0.06);
    border-radius: 10px;
    padding: 20px; line-height: 1.85; font-size: 14px;
    max-height: 300px; overflow-y: auto;
    background: rgba(255,255,255,0.04);
    color: rgba(255,255,255,0.55);
}

.alert-preview {
    background: rgba(245,158,11,0.12);
    border: 1px solid rgba(245,158,11,0.2);
    border-radius: 10px;
    padding: 12px 16px;
    font-size: 13px; color: #f59e0b;
    display: flex; align-items: flex-start; gap: 8px;
}

/* Toast styles using tmToast pattern */
.toast-notification{padding:14px 22px;border-radius:10px;color:#fff;font-size:13px;font-weight:600;display:flex;align-items:center;gap:12px;max-width:400px;pointer-events:auto;animation:tmToastIn .4s cubic-bezier(.34,1.56,.64,1),tmToastOut .4s ease 3.6s forwards;font-family:'Plus Jakarta Sans',-apple-system,BlinkMacSystemFont,sans-serif;box-shadow:0 8px 24px rgba(0,0,0,0.5)}.toast-notification i{font-size:18px;flex-shrink:0;opacity:0.9}.toast-success{background:#059669}.toast-error{background:#dc2626}.toast-info{background:#0284c7}.toast-warning{background:#f59e0b;color:#1a1a1a}
@keyframes tmToastIn{from{opacity:0;transform:translateX(40px) scale(.95)}to{opacity:1;transform:translateX(0) scale(1)}}
@keyframes tmToastOut{from{opacity:1;transform:translateX(0)}to{opacity:0;transform:translateX(40px)}}

/* ─── Count Pill ──────────────────────────────────────────────────────── */
.count-pill {
    background: var(--ann-accent-soft); color: var(--ann-accent);
    font-size: 11px; font-weight: 700; padding: 3px 10px;
    border-radius: 20px; font-family: var(--ann-mono);
}

/* ─── Stagger Animation ───────────────────────────────────────────────── */
.animate-in {
    opacity: 0; transform: translateY(16px);
    animation: fadeUp 0.5s cubic-bezier(0.4, 0, 0.2, 1) forwards;
}
.animate-in:nth-child(2) { animation-delay: 0.05s; }
.animate-in:nth-child(3) { animation-delay: 0.1s; }
.animate-in:nth-child(4) { animation-delay: 0.15s; }
.animate-in:nth-child(5) { animation-delay: 0.2s; }
@keyframes fadeUp { to { opacity: 1; transform: translateY(0); } }

/* ─── Compose Sections ────────────────────────────────────────────────── */
.compose-section { margin-bottom: 14px; }
.compose-section:last-child { margin-bottom: 0; }
.char-counter {
    font-size: 11px; font-weight: 600; color: var(--ann-text-muted);
    font-family: var(--ann-mono); transition: color 0.2s ease;
}
.char-counter.active { color: var(--ann-accent); }

/* ─── Mobile Announcement Cards ──────────────────────────────────── */

/* ─── Scrollbar ───────────────────────────────────────────────────────── */
::-webkit-scrollbar { width: 6px; height: 6px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.1); border-radius: 3px; }
::-webkit-scrollbar-thumb:hover { background: rgba(255,255,255,0.2); }

/* ─── Utility ─────────────────────────────────────────────────────────── */
.form-control {
    background: var(--ann-surface);
    border-color: var(--ann-border);
    color: var(--ann-text);
}
.form-control:focus {
    background: var(--ann-surface-hover);
    border-color: var(--ann-accent);
    box-shadow: 0 0 0 3px var(--ann-primary-glow);
    color: var(--ann-text);
}
.form-control::placeholder { color: var(--ann-text-muted); }

/* ─── Variable Pills ─────────────────────────────────────────────────── */
.var-pill:hover {
    background: var(--ann-accent) !important;
    color: #fff !important;
    border-color: var(--ann-accent) !important;
    transform: translateY(-1px);
    box-shadow: 0 3px 10px var(--ann-primary-glow);
}
.preview-template-btn:hover {
    background: rgba(255,255,255,0.08) !important;
    border-color: rgba(255,255,255,0.2) !important;
    color: var(--ann-text) !important;
}

/* ─── Quill Mobile Toolbar Fix ────────────────────────────────────────── */
.ql-toolbar-mobile {
    display: none;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    white-space: nowrap;
    padding: 6px 10px !important;
    gap: 2px;
}
.ql-toolbar-mobile::-webkit-scrollbar { height: 0; }
.ql-toolbar-mobile .ql-formats {
    display: inline-flex;
    margin-right: 4px !important;
    padding-right: 4px !important;
    border-right: 1px solid #e8e9ed !important;
    flex-shrink: 0;
}
.ql-toolbar-mobile .ql-formats:last-child { border-right: none !important; }
.ql-toolbar-mobile button { width: 28px !important; height: 28px !important; }
.ql-toolbar-mobile select { font-size: 11px !important; }
.ql-toolbar-mobile .btn-attach { font-size: 10.5px !important; padding: 0 10px !important; height: 28px !important; }

/* ═══════════════════════════════════════════════════════════════════════════
   RESPONSIVE — TABLET (max-width: 991px)
   ═══════════════════════════════════════════════════════════════════════════ */
@media (max-width: 991px) {
    .content-area { padding: 20px; }
    .top-navbar { padding: 16px; }
    .editor-container .ql-editor { min-height: 240px; padding: 16px; }
}

/* ═══════════════════════════════════════════════════════════════════════════
   RESPONSIVE — MOBILE (max-width: 767px)
   ═══════════════════════════════════════════════════════════════════════════ */
@media (max-width: 767px) {
    /* ─── Navbar ──────────────────────────────────────────────────────── */
    .top-navbar { padding: 12px 14px; flex-wrap: nowrap; gap: 8px; align-items: center; }
    .navbar-left { flex: 1; gap: 10px; min-width: 0; }
    #sidebarToggle { width: 38px; height: 38px; font-size: 20px; flex-shrink: 0; }
    .navbar-brand { display: flex; }
    .navbar-brand-logo { width: 44px; height: 44px; }
    .navbar-brand-name { font-size: 12px; }
    .navbar-brand-sub { font-size: 9px; opacity: 0.45; }
    .desktop-title { display: none !important; }
    .desktop-date { display: none !important; }
    .mobile-title { display: block !important; }
    .navbar-actions { gap: 6px; flex-shrink: 0; }
    .nav-icon-btn { width: 38px; height: 38px; font-size: 15px; }

    /* ─── Content ─────────────────────────────────────────────────────── */
    .content-area { padding: 10px 12px 28px; }
    .card { border-radius: var(--ann-radius-sm); }
    .card-header { font-size: 13px; padding: 12px 16px; }
    .card-body { padding: 16px; }

    /* ─── Templates ───────────────────────────────────────────────────── */
    .template-card .card-body { padding: 14px 6px !important; }
    .template-card .tpl-name { font-size: 10px; }
    .template-card .card-body i { font-size: 22px !important; }
    .template-card .card-body > div:first-child { width: 38px !important; height: 38px !important; border-radius: 10px !important; }

    /* ─── Subject Input ───────────────────────────────────────────────── */
    .subject-input { padding: 10px 14px; font-size: 14px; }

    /* ─── Quill Editor ────────────────────────────────────────────────── */
    .editor-container .ql-editor { min-height: 200px; padding: 14px 16px; font-size: 13px; }
    .editor-container .ql-editor.ql-blank::before { font-size: 13px; }

    /* Desktop toolbar hidden, mobile toolbar shown */
    #quill-toolbar { display: none !important; }
    .ql-toolbar-mobile { display: flex !important; }

    /* ─── Placeholder Bar ─────────────────────────────────────────────── */
    .placeholder-bar { padding: 10px 14px; gap: 5px; }
    .placeholder-bar .bar-label { font-size: 10px; }
    .placeholder-chip { padding: 4px 10px; font-size: 10px; }

    /* ─── Attachment ──────────────────────────────────────────────────── */
    .attachment-strip { padding: 8px 14px; gap: 10px; }
    .attachment-strip .strip-icon { width: 24px; height: 24px; font-size: 11px; }
    .attachment-strip .strip-text { font-size: 11px; }

    /* ─── Settings ────────────────────────────────────────────────────── */
    .settings-card .settings-body { padding: 12px; }
    .settings-card .settings-header { padding: 10px 14px; font-size: 11px; }
    .form-check { padding-left: 24px; }
    .form-check-label { font-size: 12px; }

    /* ─── Grade Grid ──────────────────────────────────────────────────── */
    .grade-grid { grid-template-columns: repeat(2, 1fr); gap: 5px; }
    .grade-chip { padding: 6px 8px; font-size: 11px; }

    /* ─── Student Search ──────────────────────────────────────────────── */
    .search-box .search-input { padding: 8px 10px 8px 32px; font-size: 12px; }
    .search-results-dropdown { max-height: 180px; }
    .search-result-item { padding: 8px 12px; font-size: 12px; }
    .selected-student-tag { font-size: 10px; padding: 3px 5px 3px 8px; }

    /* ─── Schedule ────────────────────────────────────────────────────── */
    .schedule-input { padding: 7px 10px; font-size: 12px; }

    /* ─── Buttons — evt-btn overrides ─────────────────────────────────── */
    .evt-btn { padding: 12px 18px; font-size: 13px; }

    /* ─── History Table → Mobile Scroll ───────────────────────────────── */
    .history-table{min-width:540px}
    .history-table thead th{padding:8px 10px;font-size:10px}
    .history-table tbody td{padding:8px 10px;font-size:12px}

    /* ─── History Table ───────────────────────────────────────────────── */
    .history-table thead th { font-size: 10px; padding: 8px 10px; }
    .history-table tbody td { padding: 8px 10px; font-size: 12px; }
    .history-table .subject-cell { max-width: 140px; }
    .type-badge { font-size: 10px; padding: 3px 8px; }
    .type-badge i { font-size: 11px; }
    .tbl-action { width: 28px; height: 28px; font-size: 12px; }

/* ─── Empty State ─────────────────────────────────────────────────── */
    .empty-state { padding: 40px 20px; }
    .empty-state .empty-icon { width: 56px; height: 56px; font-size: 22px; }
    .empty-state h6 { font-size: 14px; }
    .empty-state p { font-size: 12px; max-width: 260px; }

    /* ─── Modals — Bottom Sheet — Matching Teacher Management ──────────────── */
    .event-modal-overlay{align-items:flex-end;justify-content:center;padding:0}
    .event-modal{width:100%;max-width:100vw;height:92vh;max-height:92vh;border-radius:16px 16px 0 0;animation:modalSheetUp .3s ease-out}
    @keyframes modalSheetUp{from{transform:translateY(100%)}to{transform:translateY(0)}}
    .event-modal-header{padding:14px 18px}
    .event-modal-title{font-size:14px;gap:9px}.event-modal-title i{font-size:17px}
    .event-modal-body{padding:0 18px 12px;-webkit-overflow-scrolling:touch}
    .evt-field{margin-bottom:10px}
    .evt-field input[type="text"],.evt-field input[type="email"],.evt-field input[type="tel"],.evt-field textarea,.evt-field select{padding:9px 12px;font-size:13px}
    .evt-row{flex-direction:column;gap:0}
    .event-modal-footer{padding:10px 18px;padding-bottom:calc(10px + env(safe-area-inset-bottom, 0px));border-top:1px solid rgba(255,255,255,0.06)}
    .evt-btn{padding:9px 16px;font-size:12px}
    .event-modal::before{content:'';display:block;width:36px;height:4px;border-radius:4px;background:rgba(255,255,255,0.2);margin:8px auto 0;flex-shrink:0}
    .delete-modal{width:100%;max-width:100vw;height:auto;max-height:92vh}

    /* ─── Animations (disable on mobile for perf) ─────────────────────── */
    .animate-in { animation: none; opacity: 1; }
    .template-card:hover { transform: translateY(-2px); }

    /* ─── Count Pill ──────────────────────────────────────────────────── */
    .count-pill { font-size: 10px; padding: 2px 8px; }
    .toast-container{bottom:24px;right:12px;left:12px}.toast-notification{max-width:100%;font-size:12px;padding:12px 16px}

    /* ─── History Pagination ──────────────────────────────────────────── */
    .history-pagination { padding: 12px 16px; }
    .page-link { padding: 6px 12px; font-size: 11px; }
    .page-indicator { font-size: 11px; }
}

/* ═══════════════════════════════════════════════════════════════════════════
   RESPONSIVE — SMALL PHONE (max-width: 576px)
   ═══════════════════════════════════════════════════════════════════════════ */
@media (max-width: 576px) {
    .top-navbar { padding: 10px 10px; }
    .navbar-brand-logo { width: 38px; height: 38px; }
    .navbar-brand-name { font-size: 11px; }
    .navbar-brand-sub { font-size: 8px; }
    .navbar-actions { gap: 4px; }
    .nav-icon-btn { width: 34px; height: 34px; font-size: 14px; }
    #sidebarToggle { width: 34px; height: 34px; font-size: 18px; }
    .mobile-title-left h5 { font-size: 15px; }
    .mobile-title-left small { font-size: 11px; }
    .mobile-date { font-size: 10px; padding: 5px 8px; }
    .content-area { padding: 8px 8px 24px; }

    /* ─── Cards ───────────────────────────────────────────────────────── */
    .card { border-radius: 10px; }
    .card-header { font-size: 12px; padding: 10px 12px; }
    .card-body { padding: 12px; }

    /* ─── Templates ───────────────────────────────────────────────────── */
    .template-card .card-body { padding: 12px 4px !important; }
    .template-card .tpl-name { font-size: 9px; }
    .template-card .card-body i { font-size: 20px !important; }
    .template-card .card-body > div:first-child { width: 34px !important; height: 34px !important; }

    /* ─── Editor ──────────────────────────────────────────────────────── */
    .editor-container .ql-editor { min-height: 160px; padding: 12px 14px; font-size: 12px; }
    .editor-container .ql-editor.ql-blank::before { font-size: 12px; }

    /* ─── Placeholders ────────────────────────────────────────────────── */
    .placeholder-bar { padding: 8px 10px; gap: 4px; }
    .placeholder-chip { padding: 3px 8px; font-size: 9px; }

    /* ─── Buttons — evt-btn overrides ─────────────────────────────────── */
    .evt-btn { padding: 11px 14px; font-size: 12px; }

    /* ─── Grade Grid ──────────────────────────────────────────────────── */
    .grade-grid { gap: 4px; }
    .grade-chip { padding: 5px 6px; font-size: 10px; }

    /* ─── History Table ───────────────────────────────────────────────── */
    .history-table thead th { font-size: 9px; padding: 8px 8px; }
    .history-table tbody td { padding: 8px 8px; font-size: 11px; }
    .history-table .subject-cell { max-width: 110px; font-size: 11px; }
    .status-badge { font-size: 9px; padding: 3px 8px; }
    .ch-badge { font-size: 9px; padding: 2px 6px; }
    .type-badge { font-size: 9px; padding: 3px 6px; }
    .type-badge i { font-size: 10px; }
    .tbl-action { width: 26px; height: 26px; font-size: 11px; }
    .recipient-cell { font-size: 11px; }
    .date-cell { font-size: 11px; }

    /* ─── Modals — Even more compact ──────────────────────────────────── */
    .event-modal{max-height:95vh;height:95vh}
    .event-modal-body{padding:0 14px 10px}
    .event-modal-header{padding:12px 14px}
    .event-modal-footer{padding:10px 14px;padding-bottom:calc(10px + env(safe-area-inset-bottom, 0px))}
    .sidebar{width:260px}

    /* ─── History Pagination ──────────────────────────────────────────── */
    .history-pagination { flex-direction: column; gap: 8px; padding: 10px 14px; }
    .pagination-inner { width: 100%; justify-content: center; }
    .page-link { flex: 1; justify-content: center; padding: 8px 10px; font-size: 11px; }
    .page-indicator { font-size: 11px; }
}

/* ─── Desktop — hide mobile-only elements ─────────────────────────────── */
@media (min-width: 768px) {
    .mobile_title { display: none !important; }
    .ql-toolbar-mobile { display: none !important; }
}
</style>

<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/pages-navbar.css">

<!-- Toast Container -->
<div class="toast-container" id="toastContainer"></div>

<?php require_once __DIR__ . '/../includes/pages-topnavbar.php'; ?>
<div class="main-content">

    <div class="content-area">
        <?= displayFlashMessage() ?>

        <div class="d-none d-md-flex justify-content-between align-items-center gap-3 mb-3">
            <div class="page-title mb-0">
                <h5 class="mb-0">Compose Announcement</h5>
                <small>Create and send announcements to parents and students</small>
            </div>
        </div>

        <!-- MOBILE TITLE -->
        <div class="page-title mobile-title">
            <div class="mobile-title-inner">
                <div class="mobile-title-left">
                    <h5>Compose Announcement</h5>
                    <small>Create and send announcements to parents and students</small>
                </div>
                <div class="mobile-date">
                    <i class="bi bi-calendar3"></i> <?= date('D, M j, Y') ?>
                </div>
            </div>
        </div>

        <!-- ═══ Template Library ═══ -->
        <div class="card compose-section animate-in">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span style="display:flex;align-items:center;gap:8px;">
                    <i class="bi bi-grid-3x3-gap"></i>
                    <span class="d-none d-sm-inline">Template Library</span>
                    <span class="d-inline d-sm-none">Templates</span>
                    <span style="font-size:11px;font-weight:500;color:var(--ann-text-muted);margin-left:4px;" class="d-none d-md-inline">
                        Click to auto-fill
                    </span>
                </span>
                <button class="evt-btn evt-btn-save evt-btn-sm" id="openCustomTemplateModal">
                    <i class="bi bi-plus-circle"></i> <span class="d-none d-sm-inline">Create Custom Template</span><span class="d-inline d-sm-none">Custom</span>
                </button>
            </div>
            <div class="card-body" style="padding:16px 20px;">
                <div class="row g-3" id="templateGrid">
                    <?php
                    $tplColors = [
                        'class_suspension'     => ['bg'=>'var(--ann-danger-soft)',  'fg'=>'var(--ann-danger)'],
                        'school_event'         => ['bg'=>'var(--ann-accent-soft)',  'fg'=>'var(--ann-accent)'],
                        'faculty_meeting'      => ['bg'=>'var(--ann-info-soft)',    'fg'=>'var(--ann-info)'],
                        'general'              => ['bg'=>'rgba(255,255,255,0.06)',  'fg'=>'#9ca3af'],
                        'student_achievement'  => ['bg'=>'var(--ann-success-soft)', 'fg'=>'var(--ann-success)'],
                        'student_misconduct'   => ['bg'=>'var(--ann-warning-soft)', 'fg'=>'var(--ann-warning)'],
                        'parent_meeting'       => ['bg'=>'var(--ann-info-soft)',    'fg'=>'var(--ann-info)'],
                    ];
                    foreach ($templates as $key => $tpl):
                        if (in_array($key, $hiddenPresetKeys, true)) continue;
                        $c = $tplColors[$key] ?? $tplColors['general'];
                    ?>
                    <div class="col-4 col-md-4 col-lg-2">
                        <div class="card h-100 text-center template-card" data-template-key="<?= $key ?>">
                            <button type="button" class="tpl-delete-btn" data-preset-key="<?= $key ?>" title="Remove template"><i class="bi bi-trash"></i></button>
                            <div class="card-body">
                                <div style="width:44px;height:44px;border-radius:12px;background:<?= $c['bg'] ?>;display:inline-flex;align-items:center;justify-content:center;margin-bottom:10px;transition:transform 0.25s ease;">
                                    <i class="bi <?= $tpl['icon'] ?>" style="font-size:20px;color:<?= $c['fg'] ?>;"></i>
                                </div>
                                <div class="tpl-name"><?= $tpl['title'] ?></div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>

                    <?php foreach ($customTemplates as $ctpl): ?>
                    <div class="col-4 col-md-4 col-lg-2">
                        <div class="card h-100 text-center template-card"
                             data-template-key="custom_<?= $ctpl['id'] ?>"
                             data-custom-subject="<?= htmlspecialchars($ctpl['subject']) ?>"
                             data-custom-body="<?= htmlspecialchars($ctpl['body']) ?>">
                            <button type="button" class="tpl-delete-btn" data-tpl-id="<?= (int)$ctpl['id'] ?>" title="Delete template"><i class="bi bi-trash"></i></button>
                            <div class="card-body">
                                <div style="width:44px;height:44px;border-radius:12px;background:rgba(255,255,255,0.06);display:inline-flex;align-items:center;justify-content:center;margin-bottom:10px;">
                                    <i class="bi bi-bookmark-fill" style="font-size:20px;color:var(--ann-text-secondary);"></i>
                                </div>
                                <div class="tpl-name"><?= sanitize($ctpl['name']) ?></div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- ═══ Compose Form ═══ -->
        <form method="POST" action="<?= BASE_URL ?>/process_announcement.php" id="announcementForm" enctype="multipart/form-data">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="create" id="formAction">
            <input type="hidden" name="announcement_id" id="announcement_id" value="0">
            <input type="hidden" name="existing_attachment_path" id="existing_attachment_path" value="">
            <input type="hidden" name="template_type" id="ann-template_type" value="general">
            <input type="hidden" name="body_html" id="body_html">

            <div class="row g-4">
                <!-- Left Column -->
                <div class="col-lg-8">
                    <!-- Subject -->
                    <div class="card compose-section animate-in" style="animation-delay:0.1s;">
                        <div class="card-body" style="padding:16px 20px;">
                            <label style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:var(--ann-text-muted);margin-bottom:8px;display:block;">
                                Subject
                            </label>
                            <input type="text" class="subject-input" name="subject" id="ann-subject"
                                   placeholder="EMERGENCY: School Closure [Date] due to [Weather]" required>
                        </div>
                    </div>

                    <!-- Message Editor -->
                    <div class="card compose-section animate-in" style="animation-delay:0.15s;">
                        <div class="card-header">
                            <i class="bi bi-pencil-square"></i>
                            <span style="margin-left:2px;">Message Editor</span>
                            <span class="char-counter ms-auto" id="charCount">0 characters</span>
                        </div>
                        <div class="card-body" style="padding:0;">
                            <div class="editor-container">
                                <!-- Desktop Toolbar -->
                                <div id="quill-toolbar">
                                    <span class="ql-formats">
                                        <select class="ql-header">
                                            <option value="1">Heading 1</option>
                                            <option value="2">Heading 2</option>
                                            <option value="3">Heading 3</option>
                                            <option selected>Normal</option>
                                        </select>
                                    </span>
                                    <span class="ql-formats">
                                        <button class="ql-bold" title="Bold"></button>
                                        <button class="ql-italic" title="Italic"></button>
                                        <button class="ql-underline" title="Underline"></button>
                                        <button class="ql-strike" title="Strikethrough"></button>
                                    </span>
                                    <span class="ql-formats">
                                        <select class="ql-color" title="Text Color"></select>
                                        <select class="ql-background" title="Highlight"></select>
                                    </span>
                                    <span class="ql-formats">
                                        <button class="ql-blockquote" title="Blockquote"></button>
                                        <button class="ql-code-block" title="Code Block"></button>
                                    </span>
                                    <span class="ql-formats">
                                        <button class="ql-list" value="ordered" title="Numbered List"></button>
                                        <button class="ql-list" value="bullet" title="Bullet List"></button>
                                        <button class="ql-indent" value="-1" title="Decrease Indent"></button>
                                        <button class="ql-indent" value="+1" title="Increase Indent"></button>
                                    </span>
                                    <span class="ql-formats">
                                        <button class="ql-align" value="" title="Align Left"></button>
                                        <button class="ql-align" value="center" title="Align Center"></button>
                                        <button class="ql-align" value="right" title="Align Right"></button>
                                    </span>
                                    <span class="ql-formats">
                                        <button class="ql-link" title="Insert Link"></button>
                                        <button class="ql-clean" title="Clear Formatting"></button>
                                    </span>
                                    <span class="ql-formats" style="border-right:none;">
                                        <button type="button" class="btn-attach" id="toolbarAttachBtn" title="Attach Image">
                                            <i class="bi bi-paperclip"></i> Attach Image
                                        </button>
                                    </span>
                                </div>

                                <!-- Mobile Toolbar (compact, horizontally scrollable) -->
                                <div class="ql-toolbar ql-snow ql-toolbar-mobile" id="quill-toolbar-mobile">
                                    <span class="ql-formats">
                                        <button class="ql-bold" title="Bold"></button>
                                        <button class="ql-italic" title="Italic"></button>
                                        <button class="ql-underline" title="Underline"></button>
                                    </span>
                                    <span class="ql-formats">
                                        <button class="ql-list" value="ordered" title="List"></button>
                                        <button class="ql-list" value="bullet" title="Bullets"></button>
                                    </span>
                                    <span class="ql-formats">
                                        <select class="ql-header" title="Heading">
                                            <option value="1">H1</option>
                                            <option value="2">H2</option>
                                            <option value="3">H3</option>
                                            <option selected>Normal</option>
                                        </select>
                                    </span>
                                    <span class="ql-formats">
                                        <button class="ql-link" title="Link"></button>
                                        <button class="ql-clean" title="Clear"></button>
                                    </span>
                                    <span class="ql-formats" style="border-right:none;">
                                        <button type="button" class="btn-attach" id="toolbarAttachBtnMobile" title="Attach Image">
                                            <i class="bi bi-paperclip"></i> Attach
                                        </button>
                                    </span>
                                </div>

                                <div id="quill-editor"></div>
                            </div>

                            <!-- Placeholders -->
                            <div class="placeholder-bar">
                                <span class="bar-label">Insert:</span>
                                <span class="placeholder-chip" data-insert="[STUDENT NAME]">[STUDENT NAME]</span>
                                <span class="placeholder-chip" data-insert="[DATE]">[DATE]</span>
                                <span class="placeholder-chip" data-insert="[GRADE LEVEL]">[GRADE LEVEL]</span>
                                <span class="placeholder-chip" data-insert="[REASON]">[REASON]</span>
                                <span class="placeholder-chip" data-insert="[TIME]">[TIME]</span>
                                <span class="placeholder-chip" data-insert="[VENUE]">[VENUE]</span>
                                <span class="placeholder-chip" data-insert="[School Name]">[School Name]</span>
                            </div>

                            <!-- Attachment -->
                            <div class="attachment-strip" id="attachmentStrip">
                                <div class="strip-icon"><i class="bi bi-paperclip"></i></div>
                                <span class="strip-text" id="attachText">No image attached</span>
                                <span class="strip-text file-name" id="attachFileName" style="display:none;"></span>
                                <button type="button" class="strip-remove" id="btnRemoveFile">
                                    <i class="bi bi-x"></i> Remove
                                </button>
                                <input type="file" name="attachment_image" id="attachmentInput"
                                       accept="image/jpeg,image/png,image/gif,image/webp" style="display:none;">
                            </div>

                            <!-- Link Attachment -->
                            <div class="attachment-strip" id="linkStrip">
                                <div class="strip-icon"><i class="bi bi-link-45deg"></i></div>
                                <span class="strip-text" id="linkText">No link attached — click to add one</span>
                                <button type="button" class="strip-remove" id="btnRemoveLink">
                                    <i class="bi bi-x"></i> Remove
                                </button>
                            </div>
                            <div id="linkFields" style="display:none;padding:12px 20px;border-top:1px solid var(--ann-border);background:var(--ann-surface-card);">
                                <label style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:var(--ann-text-muted);margin-bottom:5px;display:block;">Link URL <span style="color:#ef4444;">*</span></label>
                                <input type="url" name="attachment_link" id="attachLinkUrl" class="schedule-input"
                                       placeholder="https://drive.google.com/file/..." style="margin-bottom:10px;">
                                <label style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:var(--ann-text-muted);margin-bottom:5px;display:block;">Button Label <small style="text-transform:none;letter-spacing:0;opacity:.6;">(optional)</small></label>
                                <input type="text" name="attachment_link_label" id="attachLinkLabel" class="schedule-input"
                                       maxlength="150" placeholder="e.g. View Full Memo">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Right Column -->
                <div class="col-lg-4">
                    <!-- Delivery Options -->
                    <div class="card settings-card compose-section animate-in" style="animation-delay:0.2s;">
                        <div class="settings-header">
                            <i class="bi bi-send"></i> Delivery Options
                        </div>
                        <div class="settings-body">
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" name="channel_email" id="chEmail" value="1" checked>
                                <label class="form-check-label" for="chEmail">
                                    <i class="bi bi-envelope" style="margin-right:4px;color:#fff;"></i> Send via Email
                                </label>
                            </div>
                            <div class="form-check mb-3">
                                <input class="form-check-input" type="checkbox" name="channel_sms" id="chSMS" value="1">
                                <label class="form-check-label" for="chSMS">
                                    <i class="bi bi-chat-dots" style="margin-right:4px;color:#fff;"></i> Send via SMS
                                </label>
                            </div>
                            <div style="border-top:1px solid var(--ann-border);padding-top:12px;margin-top:4px;">
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="radio" name="schedule_type" id="schedNow" value="now" checked>
                                    <label class="form-check-label" for="schedNow">Send Now</label>
                                </div>
                                <div class="form-check mb-3">
                                    <input class="form-check-input" type="radio" name="schedule_type" id="schedLater" value="scheduled">
                                    <label class="form-check-label" for="schedLater">Schedule for Later</label>
                                </div>
                                <div id="scheduleDateTime" style="display:none;">
                                    <div class="row g-2">
                                        <div class="col-7">
                                            <label style="font-size:11px;font-weight:600;color:var(--ann-text-muted);margin-bottom:4px;display:block;">Date</label>
                                            <input type="date" class="schedule-input" name="schedule_date">
                                        </div>
                                        <div class="col-5">
                                            <label style="font-size:11px;font-weight:600;color:var(--ann-text-muted);margin-bottom:4px;display:block;">Time</label>
                                            <input type="time" class="schedule-input" name="schedule_time">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Recipients -->
                    <div class="card settings-card compose-section animate-in" style="animation-delay:0.25s;">
                        <div class="settings-header">
                            <i class="bi bi-people"></i> Recipients
                        </div>
                        <div class="settings-body">
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" name="recipient_type[]" id="recAllParents" value="all_parents" checked>
                                <label class="form-check-label" for="recAllParents">
                                    <i class="bi bi-person-hearts" style="margin-right:4px;color:#fff;"></i> All Parents
                                </label>
                            </div>
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" name="recipient_type[]" id="recAllTeachers" value="all_teachers">
                                <label class="form-check-label" for="recAllTeachers">
                                    <i class="bi bi-person-badge" style="margin-right:4px;color:#fff;"></i> All Teachers
                                </label>
                            </div>
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" name="recipient_type[]" id="recAdvisers" value="advisers_only">
                                <label class="form-check-label" for="recAdvisers">
                                    <i class="bi bi-person-check" style="margin-right:4px;color:#fff;"></i> Class Advisers Only
                                </label>
                            </div>
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" name="recipient_type[]" id="recGrade" value="grade">
                                <label class="form-check-label" for="recGrade">Specific Grade(s)</label>
                            </div>
                            <div id="gradeCheckboxes" style="display:none;margin:10px 0 6px 0;">
                                <div class="form-check" style="margin-bottom:8px;">
                                    <input class="form-check-input" type="checkbox" id="selectAllGrades">
                                    <label class="form-check-label" for="selectAllGrades" style="font-weight:700;font-size:12px;color:var(--ann-accent);">
                                        Select All
                                    </label>
                                </div>
                                <div class="grade-grid">
                                    <?php foreach ($gradeLevels as $g): ?>
                                    <label class="grade-chip" id="gradeChip<?= $g ?>">
                                        <input type="checkbox" name="grades[]" value="<?= $g ?>" id="g<?= $g ?>" style="display:none;">
                                        <span>Grade <?= $g ?></span>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" name="recipient_type[]" id="recIndividual" value="individual">
                                <label class="form-check-label" for="recIndividual">Individual Students</label>
                            </div>
                            <div id="individualSearch" style="display:none;margin-top:10px;">
                                <div class="search-box" style="margin-bottom:10px;position:relative;">
                                    <i class="bi bi-search search-icon"></i>
                                    <input type="text" class="search-input" id="studentSearchInput" placeholder="Search by name or student ID...">
                                    <button type="button" class="search-btn" id="searchBtn"><i class="bi bi-search"></i> Search</button>
                                    <div class="search-results-dropdown" id="searchResults" style="display:none;"></div>
                                </div>
                                <div id="selectedStudents" style="margin-top:10px;display:flex;flex-wrap:wrap;gap:4px;"></div>
                            </div>
                        </div>
                    </div>

                    <!-- Action Buttons -->
                    <div class="card compose-section animate-in" style="animation-delay:0.3s;margin-top:16px;">
                        <div class="card-body" style="padding:16px 20px;">
                            <button type="button" class="evt-btn evt-btn-save" id="btnPreviewSend">
                                <i class="bi bi-send-fill me-1"></i> Send Announcement
                            </button>
                            <div style="height:8px;"></div>
                            <button type="button" class="evt-btn evt-btn-cancel" id="btnSaveDraft" style="color:#fff;background:#f59e0b;border:1.5px solid rgba(245,158,11,0.3);">
                                <i class="bi bi-file-earmark me-1"></i> Save as Draft
                            </button>
                            <div style="height:8px;"></div>
                            <button type="button" id="openParentNotifModalBtn" class="w-full py-3 px-4 rounded-xl bg-white/10 hover:bg-white/20 border border-white/20 text-white font-medium text-sm flex items-center justify-center gap-2 backdrop-blur-md transition-all shadow-lg" style="width:100%;">
                                🔔 Parent Notification Templates
                            </button>
                        </div>
                    </div>
                </div>
                </div>
            </div>
        </form>

        <!-- ═══ Announcement History ═══ -->
        <div class="card compose-section animate-in history-section" style="animation-delay:0.35s;margin-top:12px;">
            <div class="card-header d-flex justify-content-between align-items-center" style="padding:10px 16px;">
                <span style="display:flex;align-items:center;gap:8px;">
                    <i class="bi bi-clock-history"></i>
                    <span class="d-none d-sm-inline">Announcement History</span>
                    <span class="d-inline d-sm-none">History</span>
                </span>
                <span class="count-pill"><?= $totalAnnouncements ?></span>
            </div>
            <div class="card-body" style="padding:0;">
                <div class="table-scroll-wrapper">
                    <?php if (empty($announcements)): ?>
                        <div class="empty-state">
                            <div class="empty-icon"><i class="bi bi-megaphone"></i></div>
                            <h6>No Announcements Yet</h6>
                            <p>Create your first announcement using a template above, or compose one from scratch.</p>
                        </div>
                    <?php else: ?>
                    <table class="table history-table mb-0">
                        <thead>
                            <tr>
                                <th style="width:110px;">Type</th>
                                <th style="width:220px;">Subject</th>
                                <th style="width:120px;">Recipients</th>
                                <th style="width:110px;" class="d-none d-md-table-cell">Channel</th>
                                <th style="width:100px;">Status</th>
                                <th style="width:130px;" class="d-none d-sm-table-cell">Date</th>
                                <th style="width:70px;" class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($announcements as $ann): ?>
                            <tr>
                                <td>
                                    <?php $typeInfo = $templateMeta[$ann['template_type'] ?? ''] ?? $templateMeta['general']; $tc = $tplColors[$ann['template_type'] ?? ''] ?? $tplColors['general']; ?>
                                    <span class="type-badge" style="background:<?= $tc['bg'] ?>;color:<?= $tc['fg'] ?>;">
                                        <i class="bi <?= $typeInfo['icon'] ?>"></i>
                                        <?= $typeInfo['title'] ?>
                                    </span>
                                </td>
                                <td class="subject-cell">
                                    <?= sanitize($ann['subject'] ?? $ann['title'] ?? '') ?>
                                </td>
                                <td>
                                    <?php
                                    $recJson = json_decode($ann['recipients'] ?? '{}', true) ?: [];
                                    $recTypes = explode(',', (string)($ann['recipient_type'] ?? ($recJson['recipient_type'] ?? '')));
                                    $recLabels = [];
                                    foreach ($recTypes as $rt) {
                                        $rt = trim($rt);
                                        if ($rt === 'all_parents' || $rt === 'all' || !empty($recJson['all'])) $recLabels[] = 'All Parents';
                                        elseif ($rt === 'all_teachers') $recLabels[] = 'All Teachers';
                                        elseif ($rt === 'advisers_only') $recLabels[] = 'Class Advisers Only';
                                        elseif ($rt === 'advisory_class') $recLabels[] = 'Advisory Class';
                                        elseif ($rt === 'grade') $recLabels[] = 'Grades ' . implode(', ', $recJson['grades'] ?? []);
                                        elseif ($rt === 'individual') $recLabels[] = count($recJson['student_ids'] ?? []) . ' student(s)';
                                    }
                                    if (empty($recLabels)) {
                                        if (!empty($recJson['grades'])) $recLabels[] = 'Grades ' . implode(', ', $recJson['grades']);
                                        elseif (!empty($recJson['student_ids'])) $recLabels[] = count($recJson['student_ids']) . ' student(s)';
                                    }
                                    if (!empty($recLabels)) echo '<span class="recipient-cell">' . sanitize(implode(' + ', array_unique($recLabels))) . '</span>';
                                    else echo '<span style="font-size:12px;color:var(--hist-text-muted);">-</span>';
                                    ?>
                                </td>
                                <td class="d-none d-md-table-cell">
                                    <?php $channels = json_decode($ann['channels'] ?? '[]', true) ?: []; foreach ($channels as $ch): ?>
                                        <span class="ch-badge <?= $ch ?>">
                                            <i class="bi bi-<?= $ch === 'email' ? 'envelope' : 'chat-dots' ?>"></i>
                                            <?= strtoupper($ch) ?>
                                        </span>
                                    <?php endforeach; ?>
                                </td>
                                <td>
                                    <?php $st = $ann['status'] ?? 'sent'; ?>
                                    <span class="status-badge <?= $st ?>"><?= ucfirst($st) ?></span>
                                </td>
                                <td class="date-cell d-none d-sm-table-cell">
                                    <?= formatDateTime($ann['created_at']) ?>
                                </td>
                                <td class="text-center">
                                    <div style="display:flex;gap:4px;justify-content:center;flex-wrap:wrap;">
                                        <button class="tbl-action view-announcement-trigger"
                                                data-announcement='<?= htmlspecialchars(json_encode($ann), ENT_QUOTES) ?>' title="View">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                        <?php if ($st === 'failed'): ?>
                                        <button class="tbl-action resend-ann-btn" data-id="<?= $ann['id'] ?>" title="Resend Announcement" style="color:#f59e0b;border-color:#f59e0b;">
                                            <i class="bi bi-arrow-clockwise"></i>
                                        </button>
                                        <?php endif; ?>
                                        <form method="POST" action="<?= BASE_URL ?>/admin/announcements.php" class="d-inline ann-delete-form">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="announcement_id" value="<?= $ann['id'] ?>">
                                            <button type="button" class="tbl-action danger-hover delete-ann-btn"
                                                    data-subject="<?= sanitize($ann['subject'] ?? $ann['title'] ?? '') ?>"
                                                    title="Delete">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>
                </div>

                <?php if ($totalPages > 1): ?>
                <nav class="history-pagination" aria-label="Announcement history pagination">
                    <div class="pagination-inner">
                        <?php if ($currentPage > 1): ?>
                            <a class="page-link page-link-prev" href="?page=<?= $currentPage - 1 ?>">
                                <i class="bi bi-chevron-left"></i> Prev
                            </a>
                        <?php endif; ?>

                        <div class="page-indicator">
                            Page <strong><?= $currentPage ?></strong> of <strong><?= $totalPages ?></strong>
                        </div>

                        <?php if ($currentPage < $totalPages): ?>
                            <a class="page-link page-link-next" href="?page=<?= $currentPage + 1 ?>">
                                Next <i class="bi bi-chevron-right"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                </nav>
                <?php endif; ?>
            </div>
        </div>

    </div>
</div>

<!-- ═══ VIEW MODAL ═══ -->
<div class="event-modal-overlay" id="viewAnnouncementOverlay">
    <div class="event-modal">
        <div class="event-modal-header">
            <div class="event-modal-title"><i class="bi bi-megaphone"></i><span>Announcement Details</span></div>
            <button class="event-modal-close" id="viewModalClose"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="event-modal-body" id="view-announcement-content"></div>
        <div class="event-modal-footer">
            <button type="button" class="evt-btn evt-btn-secondary" id="editDraftBtn" style="display:none;">Edit Draft</button>
            <button type="button" class="evt-btn evt-btn-cancel" id="viewModalCancel">Close</button>
        </div>
    </div>
</div>

<!-- ═══ DELETE CONFIRMATION MODAL ═══ -->
<div class="event-modal-overlay" id="deleteAnnOverlay">
    <div class="event-modal delete-modal">
        <div class="event-modal-header">
            <div class="event-modal-title"><i class="bi bi-trash3" style="color:#ef4444;"></i><span>Delete Announcement</span></div>
            <button type="button" class="event-modal-close" id="deleteModalClose"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="event-modal-body" style="display:flex;align-items:center;justify-content:center;">
            <div class="delete-modal-text" style="width:100%;padding-top:10px;">
                <div class="delete-modal-icon" style="background:var(--ann-danger-soft);color:#ef4444;"><i class="bi bi-trash3-fill"></i></div>
                <h6 style="color:var(--ann-text);">Delete this announcement?</h6>
                <p style="color:var(--ann-text-secondary);" id="deleteAnnSubject">This will permanently remove the announcement for all recipients.</p>
                <p style="color:var(--ann-danger);font-size:12px;font-weight:600;margin-top:10px;"><i class="bi bi-exclamation-triangle-fill"></i> This action cannot be undone.</p>
            </div>
        </div>
        <div class="event-modal-footer">
            <button type="button" class="evt-btn evt-btn-cancel" id="deleteModalCancel">Cancel</button>
            <button type="button" class="evt-btn evt-btn-danger" id="btnConfirmDelete"><i class="bi bi-trash-fill"></i> Yes, Delete</button>
        </div>
    </div>
</div>

<!-- ═══ DELETE CUSTOM TEMPLATE MODAL ═══ -->
<div class="event-modal-overlay" id="deleteTemplateOverlay">
    <div class="event-modal delete-modal">
        <div class="event-modal-header">
            <div class="event-modal-title"><i class="bi bi-trash3" style="color:#ef4444;"></i><span>Delete Template</span></div>
            <button type="button" class="event-modal-close" id="deleteTemplateClose"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="event-modal-body" style="display:flex;align-items:center;justify-content:center;">
            <div class="delete-modal-text" style="width:100%;padding-top:10px;">
                <div class="delete-modal-icon" style="background:var(--ann-danger-soft);color:#ef4444;"><i class="bi bi-bookmark-x-fill"></i></div>
                <h6 style="color:var(--ann-text);">Delete this custom template?</h6>
                <p style="color:var(--ann-text-secondary);" id="deleteTplSubject">This template will be permanently removed from your Template Library.</p>
                <p style="color:var(--ann-danger);font-size:12px;font-weight:600;margin-top:10px;"><i class="bi bi-exclamation-triangle-fill"></i> This action cannot be undone.</p>
            </div>
        </div>
        <div class="event-modal-footer">
            <button type="button" class="evt-btn evt-btn-cancel" id="deleteTemplateCancel">Cancel</button>
            <button type="button" class="evt-btn evt-btn-danger" id="btnConfirmDeleteTemplate"><i class="bi bi-trash-fill"></i> Yes, Delete</button>
        </div>
    </div>
</div>

<!-- ═══ PREVIEW MODAL ═══ -->
<div class="event-modal-overlay" id="previewOverlay">
    <div class="event-modal">
        <div class="event-modal-header">
            <div class="event-modal-title"><i class="bi bi-eye-fill"></i><span>Preview & Confirm</span></div>
            <button class="event-modal-close" id="previewModalClose"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="event-modal-body">
            <div class="evt-field">
                <label>Subject</label>
                <h5 id="preview-subject" style="font-size:18px;font-weight:800;letter-spacing:-0.02em;margin:0;color:inherit;"></h5>
            </div>
            <div class="evt-field" style="margin-bottom:10px;">
                <label>Message Body</label>
                <div class="preview-card" id="preview-body"></div>
            </div>
            <div class="evt-row" style="gap:12px;flex-wrap:wrap;">
                <div class="evt-flex-1" style="min-width:120px;">
                    <div class="preview-label">Recipients</div>
                    <div id="preview-recipients" style="font-size:14px;font-weight:600;"></div>
                </div>
                <div class="evt-flex-1" style="min-width:120px;">
                    <div class="preview-label">Channels</div>
                    <div id="preview-channels"></div>
                </div>
                <div class="evt-flex-1" style="min-width:120px;">
                    <div class="preview-label">Timing</div>
                    <div id="preview-timing" style="font-size:14px;font-weight:600;"></div>
                </div>
            </div>
            <div id="preview-attachment" style="display:none;margin-top:16px;">
                <label>Attachment</label>
                <img id="preview-attachment-img" src="" alt="Attachment" style="max-height:120px;border-radius:10px;border:1px solid rgba(255,255,255,0.12);">
            </div>
            <div id="preview-link" style="display:none;margin-top:16px;">
                <label>Attached Link</label>
                <a id="preview-link-anchor" href="#" target="_blank" rel="noopener"
                   style="display:inline-flex;align-items:center;gap:8px;padding:10px 18px;background:var(--ann-accent);color:#fff;text-decoration:none;border-radius:8px;font-size:13px;font-weight:600;transition:all var(--ann-transition);"
                   onmouseover="this.style.background='var(--ann-accent-hover)'" onmouseout="this.style.background='var(--ann-accent)'">
                    <i class="bi bi-box-arrow-up-right"></i> <span id="preview-link-label"></span>
                </a>
                <div id="preview-link-url" style="font-size:11px;color:var(--ann-text-muted);word-break:break-all;font-family:var(--ann-mono);margin-top:6px;"></div>
            </div>
            <div class="alert-preview" style="margin-top:16px;">
                <i class="bi bi-exclamation-triangle-fill" style="font-size:16px;flex-shrink:0;margin-top:1px;"></i>
                <span>Please review the content above before confirming. This action <strong>cannot be undone</strong>.</span>
            </div>
        </div>
        <div class="event-modal-footer">
            <button type="button" class="evt-btn evt-btn-cancel" id="previewModalCancel">
                <i class="bi bi-arrow-left me-1"></i> Edit
            </button>
            <button type="button" class="evt-btn evt-btn-save" id="btnConfirmSend">
                <i class="bi bi-send-fill"></i> Confirm & Send
            </button>
        </div>
    </div>
</div>

<!-- ═══ CUSTOM TEMPLATE MODAL ═══ -->
<div class="event-modal-overlay" id="customTemplateOverlay">
    <div class="event-modal">
        <form id="customTemplateForm">
            <?= csrfField() ?>
            <div class="event-modal-header">
                <div class="event-modal-title"><i class="bi bi-bookmark-plus" style="color:#4f46e5;"></i><span>Create Custom Template</span></div>
                <button type="button" class="event-modal-close" id="customTemplateClose"><i class="bi bi-x-lg"></i></button>
            </div>
            <div class="event-modal-body">
                <div class="evt-field">
                    <label>Template Name <span class="required">*</span></label>
                    <input type="text" class="subject-input" name="title" maxlength="150" placeholder="e.g. Exam Schedule, Holiday Notice" required style="width:100%;padding:9px 12px;border:1.5px solid rgba(255,255,255,0.12);border-radius:10px;font-size:13px;transition:all 0.2s cubic-bezier(0.4,0,0.2,1);font-family:'Plus Jakarta Sans',-apple-system,BlinkMacSystemFont,sans-serif;background:rgba(255,255,255,0.05);color:inherit;">
                </div>
                <div class="evt-field">
                    <label>Icon</label>
                    <select name="icon" style="width:100%;padding:9px 12px;border:1.5px solid rgba(255,255,255,0.12);border-radius:10px;font-size:13px;font-family:'Plus Jakarta Sans',-apple-system,BlinkMacSystemFont,sans-serif;background:rgba(255,255,255,0.05);color:inherit;">
                        <option value="bi-bookmark">Bookmark (default)</option>
                        <option value="bi-file-text">Document / Form</option>
                        <option value="bi-calendar-event">Calendar / Event</option>
                        <option value="bi-megaphone">Announcement</option>
                        <option value="bi-bell">Reminder</option>
                        <option value="bi-star">Achievement</option>
                        <option value="bi-envelope-paper">Letter / Notice</option>
                        <option value="bi-clipboard-check">Approval / Consent</option>
                        <option value="bi-exclamation-triangle">Warning</option>
                        <option value="bi-people">Meeting</option>
                    </select>
                </div>
                <div class="evt-field">
                    <label>Default Subject <span class="required">*</span></label>
                    <input type="text" class="subject-input" name="subject" maxlength="255" placeholder="Default subject line" required style="width:100%;padding:9px 12px;border:1.5px solid rgba(255,255,255,0.12);border-radius:10px;font-size:13px;transition:all 0.2s cubic-bezier(0.4,0,0.2,1);font-family:'Plus Jakarta Sans',-apple-system,BlinkMacSystemFont,sans-serif;background:rgba(255,255,255,0.05);color:inherit;">
                </div>
                <div class="evt-field">
                    <label>Default Body <span class="required">*</span></label>
                    <textarea class="subject-input" name="body_content" rows="8" placeholder="Template body with [PLACEHOLDERS]..." required style="border:1.5px solid rgba(255,255,255,0.12);border-radius:10px;padding:12px 16px;font-family:'JetBrains Mono',monospace;font-size:13px;line-height:1.7;resize:vertical;background:rgba(255,255,255,0.05);color:inherit;"></textarea>
                </div>
            </div>
            <div class="event-modal-footer">
                <button type="button" class="evt-btn evt-btn-cancel" id="customTemplateCancel">Cancel</button>
                <button type="submit" class="evt-btn evt-btn-save">
                    <i class="bi bi-check-lg me-1"></i> Save Template
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ═══ PARENT NOTIFICATION TEMPLATES MODAL ═══ -->
<div class="event-modal-overlay backdrop-blur-xl bg-black/60 border border-white/20" id="parentNotifModal">
    <div class="event-modal" style="width:min(720px,100%);max-width:100%;height:auto;max-height:92vh;border:1px solid rgba(255,255,255,0.2);box-shadow:0 20px 60px rgba(0,0,0,0.5);">
        <form id="parentNotifForm">
            <div class="event-modal-header">
                <div class="event-modal-title">
                    <i class="bi bi-bell" style="color:#4f46e5;"></i>
                    <span>Automated Parent Notification Templates</span>
                </div>
                <button class="event-modal-close" id="parentNotifModalClose"><i class="bi bi-x-lg"></i></button>
            </div>
            <div class="event-modal-body">
                <p style="font-size:12px;color:var(--ann-text-muted);margin-bottom:16px;margin-top:4px;">Configure auto-generated SMS & Email messages dispatched by system triggers.</p>

                <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:16px;padding:10px 14px;background:rgba(255,255,255,0.04);border-radius:10px;border:1px solid rgba(255,255,255,0.06);">
                    <span style="font-size:11px;font-weight:700;color:var(--ann-text-muted);text-transform:uppercase;letter-spacing:0.06em;margin-right:4px;width:100%;">Dynamic Variables</span>
                    <button type="button" class="var-pill" data-var="{student_name}" style="padding:5px 12px;border-radius:20px;font-size:11px;font-weight:600;font-family:'JetBrains Mono',monospace;background:rgba(255,255,255,0.06);color:var(--ann-text-secondary);border:1px solid rgba(255,255,255,0.1);cursor:pointer;transition:all 0.2s ease;">{student_name}</button>
                    <button type="button" class="var-pill" data-var="{date}" style="padding:5px 12px;border-radius:20px;font-size:11px;font-weight:600;font-family:'JetBrains Mono',monospace;background:rgba(255,255,255,0.06);color:var(--ann-text-secondary);border:1px solid rgba(255,255,255,0.1);cursor:pointer;transition:all 0.2s ease;">{date}</button>
                    <button type="button" class="var-pill" data-var="{time}" style="padding:5px 12px;border-radius:20px;font-size:11px;font-weight:600;font-family:'JetBrains Mono',monospace;background:rgba(255,255,255,0.06);color:var(--ann-text-secondary);border:1px solid rgba(255,255,255,0.1);cursor:pointer;transition:all 0.2s ease;">{time}</button>
                    <button type="button" class="var-pill" data-var="{subject_name}" style="padding:5px 12px;border-radius:20px;font-size:11px;font-weight:600;font-family:'JetBrains Mono',monospace;background:rgba(255,255,255,0.06);color:var(--ann-text-secondary);border:1px solid rgba(255,255,255,0.1);cursor:pointer;transition:all 0.2s ease;">{subject_name}</button>
                    <button type="button" class="var-pill" data-var="{school_name}" style="padding:5px 12px;border-radius:20px;font-size:11px;font-weight:600;font-family:'JetBrains Mono',monospace;background:rgba(255,255,255,0.06);color:var(--ann-text-secondary);border:1px solid rgba(255,255,255,0.1);cursor:pointer;transition:all 0.2s ease;">{school_name}</button>
                </div>

                <div class="evt-field">
                    <label>🚏 Gate Time-In Notification <span style="color:var(--ann-danger);">*</span></label>
                    <textarea id="template_time_in" name="template_time_in" rows="3" placeholder="LDB-FRAS: {student_name} has safely arrived at {school_name} on {date} at {time}." style="font-family:'JetBrains Mono',monospace;font-size:13px;line-height:1.7;resize:vertical;"><?= htmlspecialchars($notifTemplates['template_time_in'] ?? '') ?></textarea>
                    <button type="button" class="preview-template-btn" data-target="template_time_in" style="margin-top:6px;padding:6px 12px;border-radius:8px;border:1px solid rgba(255,255,255,0.1);background:rgba(255,255,255,0.04);color:var(--ann-text-secondary);font-size:11px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:5px;transition:all 0.2s ease;">
                        👁️ Preview Live Message
                    </button>
                    <div class="preview-bubble" id="preview_template_time_in" style="display:none;margin-top:10px;padding:14px 16px;border-radius:10px;background:rgba(79,70,229,0.08);border:1px solid rgba(79,70,229,0.15);font-size:13px;line-height:1.7;color:var(--ann-text);"></div>
                </div>

                <div class="evt-field">
                    <label>🚪 Gate Time-Out Notification <span style="color:var(--ann-danger);">*</span></label>
                    <textarea id="template_time_out" name="template_time_out" rows="3" placeholder="LDB-FRAS: {student_name} has departed {school_name} on {date} at {time}." style="font-family:'JetBrains Mono',monospace;font-size:13px;line-height:1.7;resize:vertical;"><?= htmlspecialchars($notifTemplates['template_time_out'] ?? '') ?></textarea>
                    <button type="button" class="preview-template-btn" data-target="template_time_out" style="margin-top:6px;padding:6px 12px;border-radius:8px;border:1px solid rgba(255,255,255,0.1);background:rgba(255,255,255,0.04);color:var(--ann-text-secondary);font-size:11px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:5px;transition:all 0.2s ease;">
                        👁️ Preview Live Message
                    </button>
                    <div class="preview-bubble" id="preview_template_time_out" style="display:none;margin-top:10px;padding:14px 16px;border-radius:10px;background:rgba(79,70,229,0.08);border:1px solid rgba(79,70,229,0.15);font-size:13px;line-height:1.7;color:var(--ann-text);"></div>
                </div>

                <div class="evt-field">
                    <label>⚠️ 3-Consecutive Absences Alert <span style="color:var(--ann-danger);">*</span></label>
                    <textarea id="template_absent_3x" name="template_absent_3x" rows="3" placeholder="ALERT: {student_name} has accumulated 3 consecutive absences in {subject_name} as of {date}." style="font-family:'JetBrains Mono',monospace;font-size:13px;line-height:1.7;resize:vertical;"><?= htmlspecialchars($notifTemplates['template_absent_3x'] ?? '') ?></textarea>
                    <button type="button" class="preview-template-btn" data-target="template_absent_3x" style="margin-top:6px;padding:6px 12px;border-radius:8px;border:1px solid rgba(255,255,255,0.1);background:rgba(255,255,255,0.04);color:var(--ann-text-secondary);font-size:11px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:5px;transition:all 0.2s ease;">
                        👁️ Preview Live Message
                    </button>
                    <div class="preview-bubble" id="preview_template_absent_3x" style="display:none;margin-top:10px;padding:14px 16px;border-radius:10px;background:rgba(79,70,229,0.08);border:1px solid rgba(79,70,229,0.15);font-size:13px;line-height:1.7;color:var(--ann-text);"></div>
                </div>

                <div class="evt-field">
                    <label>🚪 Gate Session Absence Notification <span style="color:var(--ann-danger);">*</span></label>
                    <textarea id="template_gate_absent" name="template_gate_absent" rows="3" placeholder="LDB-FRAS: {student_name} was marked absent for {subject_name} on {date}." style="font-family:'JetBrains Mono',monospace;font-size:13px;line-height:1.7;resize:vertical;"><?= htmlspecialchars($notifTemplates['template_gate_absent'] ?? '') ?></textarea>
                    <button type="button" class="preview-template-btn" data-target="template_gate_absent" style="margin-top:6px;padding:6px 12px;border-radius:8px;border:1px solid rgba(255,255,255,0.1);background:rgba(255,255,255,0.04);color:var(--ann-text-secondary);font-size:11px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:5px;transition:all 0.2s ease;">
                        👁️ Preview Live Message
                    </button>
                    <div class="preview-bubble" id="preview_template_gate_absent" style="display:none;margin-top:10px;padding:14px 16px;border-radius:10px;background:rgba(79,70,229,0.08);border:1px solid rgba(79,70,229,0.15);font-size:13px;line-height:1.7;color:var(--ann-text);"></div>
                </div>
            </div>
            <div class="event-modal-footer">
                <button type="button" class="evt-btn evt-btn-cancel" id="parentNotifCancel">Cancel</button>
                <button type="submit" class="evt-btn evt-btn-save" id="btnSaveTemplates">
                    <i class="bi bi-check-lg me-1"></i> Save Template Changes
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     JAVASCRIPT
     ═══════════════════════════════════════════════════════════════════════════ -->
<script src="https://cdn.quilljs.com/1.3.7/quill.min.js"></script>
<script>
(function() {
    'use strict';

// ─── Toast ──────────────────────────────────────────────────────────
function showToast(type, message) {
    var c = document.getElementById('toastContainer');
    var icons = { success: 'check-circle-fill', danger: 'x-circle-fill', warning: 'exclamation-triangle-fill', info: 'info-circle-fill' };
    var t = document.createElement('div');
    t.className = 'toast-notification ' + type;
    t.innerHTML = '<i class="bi bi-' + (icons[type] || icons.info) + '"></i><span>' + esc(message) + '</span>';
    c.appendChild(t);
    setTimeout(function() { if (t.parentNode) t.remove(); }, 4200);
}

    <?php if (!empty($_SESSION['flash_message'])): ?>
    showToast('<?= $_SESSION['flash_message']['type'] ?>', <?= json_encode($_SESSION['flash_message']['message']) ?>);
    <?php unset($_SESSION['flash_message']); endif; ?>

    // ─── Quill — dual toolbar setup ─────────────────────────────────────
    var desktopToolbar = document.getElementById('quill-toolbar');
    var mobileToolbar  = document.getElementById('quill-toolbar-mobile');

    // Determine which toolbar to use based on screen width
    function isMobileView() { return window.innerWidth <= 767; }

    var quill = new Quill('#quill-editor', {
        modules: {
            toolbar: isMobileView() ? '#quill-toolbar-mobile' : '#quill-toolbar'
        },
        theme: 'snow',
        placeholder: 'Dear Parents/Guardians,\n\nWrite your announcement here...\n\n\u2014 Liceo de Baleno Administration'
    });

    // Handle resize: swap toolbar binding
    var lastMobile = isMobileView();
    window.addEventListener('resize', function() {
        var nowMobile = isMobileView();
        if (nowMobile !== lastMobile) {
            // On toolbar swap, update the Quill toolbar container
            var newToolbar = nowMobile ? '#quill-toolbar-mobile' : '#quill-toolbar';
            quill.getModule('toolbar').container = document.querySelector(newToolbar + ' .ql-formats') ? document.querySelector(newToolbar) : document.querySelector(newToolbar);
            lastMobile = nowMobile;
        }
    });

    var bodyHidden = document.getElementById('body_html');
    var charCount  = document.getElementById('charCount');
    quill.on('text-change', function() {
        bodyHidden.value = quill.root.innerHTML;
        var len = quill.getText().trim().length;
        charCount.textContent = len.toLocaleString() + ' character' + (len !== 1 ? 's' : '');
        charCount.classList.toggle('active', len > 0);
    });

    <?php if ($resendAnn): ?>
    (function() {
        function prefillResend() {
            try {
                var ann = <?= json_encode($resendAnn) ?>;
                var subjectInput = document.getElementById('ann-subject');
                var tplTypeInput = document.getElementById('ann-template_type');
                var chEmail = document.getElementById('chEmail');
                var chSMS = document.getElementById('chSMS');
                var recAllParents = document.getElementById('recAllParents');
                var recAllTeachers = document.getElementById('recAllTeachers');
                var recAdvisers = document.getElementById('recAdvisers');
                var recGrade = document.getElementById('recGrade');
                var recIndiv = document.getElementById('recIndividual');
                var gradeBox = document.getElementById('gradeCheckboxes');
                var indivBox = document.getElementById('individualSearch');

                if (subjectInput && ann.subject) subjectInput.value = ann.subject;
                if (tplTypeInput && ann.template_type) tplTypeInput.value = ann.template_type;

                var body = ann.body_html || ann.body || '';
                if (body && typeof quill !== 'undefined') {
                    quill.setContents([]);
                    quill.clipboard.dangerouslyPasteHTML(0, body);
                    var bodyHidden = document.getElementById('body_html');
                    var charCount = document.getElementById('charCount');
                    bodyHidden.value = body;
                    var len = quill.getText().trim().length;
                    charCount.textContent = len.toLocaleString() + ' character' + (len !== 1 ? 's' : '');
                    charCount.classList.toggle('active', len > 0);
                }

                var channels = [];
                try { channels = JSON.parse(ann.channels || '[]'); } catch(e) { channels = []; }
                if (chEmail) chEmail.checked = channels.indexOf('email') !== -1;
                if (chSMS) chSMS.checked = channels.indexOf('sms') !== -1;

                var recipients = {};
                try { recipients = JSON.parse(ann.recipients || '{}'); } catch(e) { recipients = {}; }
                var rt = (ann.recipient_type || recipients.recipient_type || '').toString().split(',');
                var hasRt = function(v) { return rt.indexOf(v) !== -1; };

                if (recAllParents) recAllParents.checked = hasRt('all_parents') || hasRt('all') || !!recipients.all;
                if (recAllTeachers) recAllTeachers.checked = hasRt('all_teachers');
                if (recAdvisers) recAdvisers.checked = hasRt('advisers_only');

                if ((hasRt('grade') || recipients.grades) && recGrade) {
                    recGrade.checked = true;
                    gradeBox.style.display = 'block';
                    (recipients.grades || []).forEach(function(g) {
                        var cb = document.getElementById('g' + g);
                        if (cb) { cb.checked = true; var chip = document.getElementById('gradeChip' + g); if (chip) chip.classList.add('active'); }
                    });
                    var selectAll = document.getElementById('selectAllGrades');
                    if (selectAll) {
                        var gradeChips = document.querySelectorAll('.grade-chip input');
                        selectAll.checked = Array.from(gradeChips).every(function(c) { return c.checked; });
                    }
                }
                if ((hasRt('individual') || recipients.student_ids) && recIndiv) {
                    recIndiv.checked = true;
                    indivBox.style.display = 'block';
                    (recipients.student_ids || []).forEach(function(id) {
                        if (selMap && !selMap.has(parseInt(id))) selMap.set(parseInt(id), { name: 'Student #' + id, sid: '', grade: '' });
                    });
                    if (typeof renderStudents === 'function') renderStudents();
                }

                setTimeout(function() {
                    var composeForm = document.getElementById('announcementForm');
                    if (composeForm) composeForm.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }, 200);
            } catch(e) {
                console.error('Resend prefill error:', e);
            }
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function() { setTimeout(prefillResend, 500); });
        } else {
            setTimeout(prefillResend, 500);
        }
    })();
    <?php endif; ?>

    // ─── Templates ──────────────────────────────────────────────────────
    var TEMPLATES    = <?= json_encode($templates) ?>;
    var subjectInput = document.getElementById('ann-subject');
    var tplTypeInput = document.getElementById('ann-template_type');
    var activeCard   = null;

    // Delegated — also covers custom template cards inserted via AJAX
    var templateGrid = document.getElementById('templateGrid');
    if (templateGrid) {
        templateGrid.addEventListener('click', function(e) {
            if (e.target.closest('.tpl-delete-btn')) return; // handled by announcement_templates.js
            var card = e.target.closest('.template-card');
            if (!card) return;
            var key = card.dataset.templateKey;
            var subject = '', body = '', typeKey = 'general';
            if (key.startsWith('custom_')) { subject = card.dataset.customSubject || ''; body = card.dataset.customBody || ''; }
            else if (TEMPLATES[key]) { subject = TEMPLATES[key].subject || ''; body = TEMPLATES[key].body || ''; typeKey = key; }
            subjectInput.value = subject;
            tplTypeInput.value = typeKey;
            if (body) quill.root.innerHTML = body; else quill.setText('');
            bodyHidden.value = quill.root.innerHTML;
            quill.focus();
            if (activeCard) activeCard.classList.remove('active-template');
            card.classList.add('active-template');
            activeCard = card;
        });
    }

    // ─── Placeholder Chips ──────────────────────────────────────────────
    document.querySelectorAll('.placeholder-chip').forEach(function(el) {
        el.addEventListener('click', function() {
            var text = this.dataset.insert;
            var range = quill.getSelection(true);
            var index = range ? range.index : quill.getLength() - 1;
            quill.insertText(index, text, { 'bold': true, 'color': '#4f46e5' });
            quill.setSelection(index + text.length);
            quill.focus();
        });
    });

    // ─── Attachment (both desktop + mobile toolbar buttons) ──────────────
    var attachInput   = document.getElementById('attachmentInput');
    var toolbarBtn    = document.getElementById('toolbarAttachBtn');
    var toolbarBtnMob = document.getElementById('toolbarAttachBtnMobile');
    var strip         = document.getElementById('attachmentStrip');
    var attachText    = document.getElementById('attachText');
    var attachFile    = document.getElementById('attachFileName');
    var btnRemove     = document.getElementById('btnRemoveFile');

    function triggerFileInput(e) { e.preventDefault(); e.stopPropagation(); attachInput.click(); }
    toolbarBtn.addEventListener('click', triggerFileInput);
    if (toolbarBtnMob) toolbarBtnMob.addEventListener('click', triggerFileInput);
    strip.addEventListener('click', function(e) { if (e.target === btnRemove || btnRemove.contains(e.target)) return; attachInput.click(); });
    attachInput.addEventListener('change', function() {
        var file = this.files[0];
        if (!file) { resetStrip(); return; }
        if (file.size > 5*1024*1024) { showToast('warning', 'File too large. Max 5MB.'); this.value = ''; resetStrip(); return; }
        if (!['image/jpeg','image/png','image/gif','image/webp'].includes(file.type)) { showToast('warning', 'Invalid file type.'); this.value = ''; resetStrip(); return; }
        attachText.style.display = 'none';
        attachFile.textContent = file.name + ' (' + (file.size/1024).toFixed(1) + ' KB)';
        attachFile.style.display = 'inline';
        strip.classList.add('has-file');
        showToast('success', 'Image attached: ' + file.name);
    });
    btnRemove.addEventListener('click', function(e) { e.stopPropagation(); attachInput.value = ''; resetStrip(); });
    function resetStrip() { attachText.style.display = 'inline'; attachFile.style.display = 'none'; strip.classList.remove('has-file'); }

    // ─── Link Attachment ──────────────────────────────────────────────────
    var linkStrip    = document.getElementById('linkStrip');
    var linkFields   = document.getElementById('linkFields');
    var linkUrl      = document.getElementById('attachLinkUrl');
    var linkLabelInp = document.getElementById('attachLinkLabel');
    var linkTextEl   = document.getElementById('linkText');
    var btnRemoveLnk = document.getElementById('btnRemoveLink');

    function updLinkStrip() {
        var url = (linkUrl.value || '').trim();
        var has = url !== '';
        linkStrip.classList.toggle('has-file', has);
        if (has) {
            var lbl = (linkLabelInp.value || '').trim() || url;
            linkTextEl.innerHTML = '';
            var nameSpan = document.createElement('span');
            nameSpan.className = 'file-name';
            nameSpan.style.display = 'inline';
            nameSpan.textContent = lbl;
            linkTextEl.appendChild(nameSpan);
        } else {
            linkTextEl.textContent = 'No link attached \u2014 click to add one';
        }
    }
    if (linkStrip && linkUrl) {
        linkStrip.addEventListener('click', function(e) {
            if (e.target === btnRemoveLnk || btnRemoveLnk.contains(e.target)) return;
            var open = linkFields.style.display === 'none';
            linkFields.style.display = open ? 'block' : 'none';
            if (open) setTimeout(function() { linkUrl.focus(); }, 60);
        });
        [linkUrl, linkLabelInp].forEach(function(inp) { inp.addEventListener('input', updLinkStrip); });
        btnRemoveLnk.addEventListener('click', function(e) {
            e.stopPropagation();
            linkUrl.value = ''; linkLabelInp.value = '';
            updLinkStrip();
            linkFields.style.display = 'none';
        });
    }

    // ─── Recipients (multi-select scoping) ───────────────────────────────
    var recAllParents  = document.getElementById('recAllParents');
    var recAllTeachers = document.getElementById('recAllTeachers');
    var recAdvisers    = document.getElementById('recAdvisers');
    var recGrade       = document.getElementById('recGrade');
    var recIndiv       = document.getElementById('recIndividual');
    var gradeBox = document.getElementById('gradeCheckboxes'), indivBox = document.getElementById('individualSearch');
    function recUI() { gradeBox.style.display = recGrade.checked ? 'block' : 'none'; indivBox.style.display = recIndiv.checked ? 'block' : 'none'; }
    [recAllParents, recAllTeachers, recAdvisers, recGrade, recIndiv].forEach(function(r) { if (r) r.addEventListener('change', recUI); });
    var selectAll = document.getElementById('selectAllGrades');
    var gradeChips = document.querySelectorAll('.grade-chip');
    gradeChips.forEach(function(chip) {
        var cb = chip.querySelector('input[type="checkbox"]');
        chip.addEventListener('click', function() { cb.checked = !cb.checked; this.classList.toggle('active', cb.checked); updSel(); });
    });
    selectAll.addEventListener('change', function() { gradeChips.forEach(function(c) { var cb = c.querySelector('input'); cb.checked = selectAll.checked; c.classList.toggle('active', selectAll.checked); }); });
    function updSel() { selectAll.checked = Array.from(gradeChips).every(function(c) { return c.querySelector('input').checked; }); }

    // ─── Student Search ─────────────────────────────────────────────────
    var searchInput = document.getElementById('studentSearchInput');
    var searchDD    = document.getElementById('searchResults');
    var selCont     = document.getElementById('selectedStudents');
    var selMap      = new Map();
    var debounce;
    searchInput.addEventListener('input', function() {
        clearTimeout(debounce);
        var q = this.value.trim();
        if (q.length < 2) { searchDD.style.display = 'none'; return; }
        debounce = setTimeout(function() { doStudentSearch(q); }, 250);
    });
    searchInput.addEventListener('keydown', function(e) { if (e.key === 'Enter') { clearTimeout(debounce); doStudentSearch(this.value.trim()); } });
    document.getElementById('searchBtn').addEventListener('click', function() { doStudentSearch(searchInput.value.trim()); });
    searchInput.addEventListener('blur', function() { setTimeout(function() { searchDD.style.display = 'none'; }, 200); });
    searchInput.addEventListener('focus', function() { if (this.value.trim().length >= 2) searchDD.style.display = 'block'; });
    function doStudentSearch(q) {
        if (q.length < 2) { searchDD.style.display = 'none'; return; }
        var url = 'announcements.php?action=search_students&q=' + encodeURIComponent(q);
        console.log('Searching:', window.location.origin + '/' + url);
        fetch(url)
            .then(function(r) {
                console.log('Response status:', r.status, r.statusText);
                if (!r.ok) throw new Error('HTTP ' + r.status + ': ' + r.statusText);
                return r.json();
            })
            .then(function(data) {
                console.log('Search results:', data);
                if (data.error) { throw new Error(data.error); }
                if (!data.length) { searchDD.innerHTML = '<div style="padding:14px;text-align:center;color:var(--ann-text-muted);font-size:12px;">No students found</div>'; }
                else {
                    searchDD.innerHTML = data.map(function(s) {
                        var sel = selMap.has(s.id);
                        return '<div class="search-result-item '+(sel?'selected':'')+'" data-id="'+s.id+'" data-name="'+esc(s.first_name)+' '+esc(s.last_name)+'" data-sid="'+esc(s.student_id)+'" data-grade="'+s.grade_level+'"><div><span class="result-name">'+esc(s.last_name)+', '+esc(s.first_name)+'</span> <span class="result-meta">'+esc(s.student_id)+'</span></div><span class="result-badge">G'+s.grade_level+'</span></div>';
                    }).join('');
                    searchDD.querySelectorAll('.search-result-item:not(.selected)').forEach(function(item) { item.addEventListener('click', function() { addStudent(this.dataset); this.classList.add('selected'); }); });
                }
                searchDD.style.display = 'block';
            }).catch(function(err) { console.error('Search error:', err); searchDD.innerHTML = '<div style="padding:14px;text-align:center;color:var(--ann-danger);font-size:12px;">Search error: ' + esc(err.message) + '</div>'; searchDD.style.display = 'block'; });
    }
    function addStudent(d) { if (selMap.has(parseInt(d.id))) return; selMap.set(parseInt(d.id), { name: d.name, sid: d.sid, grade: d.grade }); renderStudents(); showToast('success', d.name + ' added'); }
    function removeStudent(id) { selMap.delete(id); renderStudents(); }
    function renderStudents() {
        selCont.innerHTML = '';
        selMap.forEach(function(s, id) { var tag = document.createElement('span'); tag.className = 'selected-student-tag'; tag.innerHTML = esc(s.name)+' <span style="opacity:0.6;">(G'+s.grade+')</span> <button type="button" class="tag-remove" data-rid="'+id+'">&times;</button>'; selCont.appendChild(tag); });
        document.querySelectorAll('input[name="student_ids[]"]').forEach(function(h) { h.remove(); });
        var f = document.getElementById('announcementForm');
        selMap.forEach(function(s, id) { var h = document.createElement('input'); h.type='hidden'; h.name='student_ids[]'; h.value=id; f.appendChild(h); });
        selCont.querySelectorAll('.tag-remove').forEach(function(btn) { btn.addEventListener('click', function() { removeStudent(parseInt(this.dataset.rid)); }); });
    }

    // ─── Schedule ───────────────────────────────────────────────────────
    var schedNow = document.getElementById('schedNow'), schedLater = document.getElementById('schedLater'), schedDT = document.getElementById('scheduleDateTime');
    function schedUI() { schedDT.style.display = schedLater.checked ? 'block' : 'none'; }
    schedNow.addEventListener('change', schedUI); schedLater.addEventListener('change', schedUI);

// ─── Modal System (matching teacher management) ────────────────────────
var cur=null;
function openModal(el){if(!el)return;el.classList.add('show');document.body.style.overflow='hidden';cur=el.id;}
function closeModal(el){if(!el)return;el.classList.remove('show');if(!document.querySelector('.event-modal-overlay.show')){document.body.style.overflow='';cur=null;}}
function closeAll(){document.querySelectorAll('.event-modal-overlay.show').forEach(function(e){e.classList.remove('show');});document.body.style.overflow='';cur=null;}
document.querySelectorAll('.event-modal-overlay').forEach(function(o){o.addEventListener('click',function(e){if(e.target===o)closeModal(o);});});
document.addEventListener('keydown',function(e){if(e.key==='Escape')closeAll();});

// ─── Preview & Send ─────────────────────────────────────────────────
var form = document.getElementById('announcementForm'), formAction = document.getElementById('formAction');
var previewOverlay = document.getElementById('previewOverlay');
document.getElementById('btnPreviewSend').addEventListener('click', function() {
    bodyHidden.value = quill.root.innerHTML;
    if (!subjectInput.value.trim()) { subjectInput.focus(); showToast('warning', 'Please enter a subject.'); return; }
    if (!quill.getText().trim()) { quill.focus(); showToast('warning', 'Please write a message body.'); return; }
    if (!document.getElementById('chEmail').checked && !document.getElementById('chSMS').checked) { showToast('warning', 'Select at least one channel.'); return; }
    document.getElementById('preview-subject').textContent = subjectInput.value;
    document.getElementById('preview-body').innerHTML = quill.root.innerHTML;
    var recParts = [];
    if (recAllParents.checked) recParts.push('All Parents');
    if (recAllTeachers.checked) recParts.push('All Teachers');
    if (recAdvisers.checked) recParts.push('Class Advisers Only');
    if (recGrade.checked) {
        var g = Array.from(document.querySelectorAll('.grade-chip input:checked')).map(function(c) { return 'Grade ' + c.value; });
        recParts.push(g.length ? 'Grades: ' + g.join(', ') : 'No grades selected');
    }
    if (recIndiv.checked) recParts.push(selMap.size + ' individual student(s)');
    document.getElementById('preview-recipients').textContent = recParts.length ? recParts.join(' + ') : 'No recipients selected';
    var ch = [];
    if (document.getElementById('chEmail').checked) ch.push('Email');
    if (document.getElementById('chSMS').checked) ch.push('SMS');
    document.getElementById('preview-channels').innerHTML = ch.length ? ch.map(function(c) { return '<span class="ch-badge '+c.toLowerCase()+'"><i class="bi bi-'+(c==='Email'?'envelope':'chat-dots')+'"></i> '+c+'</span>'; }).join(' ') : '<span style="color:#ef4444;font-size:12px;">None selected</span>';
    document.getElementById('preview-timing').textContent = schedLater.checked ? 'Scheduled: '+(document.querySelector('[name="schedule_date"]').value||'?')+' at '+(document.querySelector('[name="schedule_time"]').value||'?') : 'Send immediately';
    var pa = document.getElementById('preview-attachment'), pi = document.getElementById('preview-attachment-img');
    if (attachInput.files.length) { pa.style.display='block'; pi.src=URL.createObjectURL(attachInput.files[0]); } else { pa.style.display='none'; }
    var plk = document.getElementById('preview-link');
    var linkVal = ((typeof linkUrl !== 'undefined' && linkUrl) ? linkUrl.value : '').trim();
    if (linkVal) {
        plk.style.display = 'block';
        document.getElementById('preview-link-anchor').href = linkVal;
        document.getElementById('preview-link-label').textContent = ((typeof linkLabelInp !== 'undefined' && linkLabelInp ? linkLabelInp.value : '').trim()) || linkVal;
        document.getElementById('preview-link-url').textContent = linkVal;
    } else { plk.style.display = 'none'; }
    openModal(previewOverlay);
});
document.getElementById('btnConfirmSend').addEventListener('click', function(e) {
    e.preventDefault();
    bodyHidden.value = quill.root.innerHTML;
    formAction.value = 'create';
    var btn = document.getElementById('btnConfirmSend');
    btn.classList.add('btn-loading');
    btn.disabled = true;
    var fd = new FormData(form);
    fd.append('ajax', '1');
    fetch(form.action, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function(r) { return r.text().then(function(t) { return { status: r.status, text: t }; }); })
        .then(function(resp) {
            var trimmed = (resp.text || '').trim();
            if (!trimmed) {
                throw new Error('Empty response');
            }
            try {
                var d = JSON.parse(trimmed);
                if (d.success) {
                    showToast('success', d.message || 'Announcement sent successfully!');
                    setTimeout(function() { location.reload(); }, 400);
                } else {
                    throw new Error(d && d.message ? d.message : 'Server reported failure');
                }
            } catch (parseErr) {
                throw new Error('Unexpected server response');
            }
        })
        .catch(function(err) {
            console.warn('AJAX send failed, falling back to normal submit:', err.message || err);
            formAction.value = 'create';
            form.submit();
        });
});
document.getElementById('btnSaveDraft').addEventListener('click', function() { if (!subjectInput.value.trim()) { subjectInput.focus(); showToast('warning', 'Enter a subject first.'); return; } bodyHidden.value = quill.root.innerHTML; formAction.value = 'save_draft'; form.submit(); });

// Modal close handlers
document.getElementById('previewModalClose').addEventListener('click', function(){closeModal(previewOverlay);});
document.getElementById('previewModalCancel').addEventListener('click', function(){closeModal(previewOverlay);});
document.getElementById('viewModalClose').addEventListener('click', function(){closeModal(document.getElementById('viewAnnouncementOverlay'));});
document.getElementById('viewModalCancel').addEventListener('click', function(){closeModal(document.getElementById('viewAnnouncementOverlay'));});

// ─── Delete Announcement Confirmation Modal ─────────────────────────
var deleteAnnOverlay = document.getElementById('deleteAnnOverlay');
var pendingDeleteForm = null;
var btnConfirmDelete = document.getElementById('btnConfirmDelete');

document.querySelectorAll('.delete-ann-btn').forEach(function(btn) {
    btn.addEventListener('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        pendingDeleteForm = this.closest('form');
        var subjectEl = document.getElementById('deleteAnnSubject');
        var subj = this.dataset.subject;
        if (subjectEl) {
            subjectEl.textContent = subj ? '"' + subj + '" will be permanently deleted and removed from all recipients.' : 'This announcement will be permanently deleted and removed from all recipients.';
        }
        openModal(deleteAnnOverlay);
    });
});
if (deleteAnnOverlay) {
    document.getElementById('deleteModalClose').addEventListener('click', function(){ closeModal(deleteAnnOverlay); });
    document.getElementById('deleteModalCancel').addEventListener('click', function(){ closeModal(deleteAnnOverlay); });
    btnConfirmDelete.addEventListener('click', function() {
        if (!pendingDeleteForm) { closeModal(deleteAnnOverlay); return; }
        this.classList.add('btn-loading');
        this.disabled = true;
        pendingDeleteForm.submit();
    });
}

// ─── View Announcement ──────────────────────────────────────────────
function describeRecipients(ann) {
    var rec = {};
    try { rec = JSON.parse(ann.recipients || '{}'); } catch(e) { rec = {}; }
    var rt = (ann.recipient_type || rec.recipient_type || '').toString().split(',');
    var parts = [];
    rt.forEach(function(v) {
        v = v.trim();
        if (v === 'all_parents' || v === 'all' || rec.all) parts.push('All Parents');
        else if (v === 'all_teachers') parts.push('All Teachers');
        else if (v === 'advisers_only') parts.push('Class Advisers Only');
        else if (v === 'advisory_class') parts.push('Advisory Class');
        else if (v === 'grade') parts.push('Grades: ' + (rec.grades || []).join(', '));
        else if (v === 'individual') parts.push((rec.student_ids || []).length + ' individual student(s)');
    });
    if (!parts.length) {
        if (rec.all) parts.push('All Parents');
        if (rec.grades && rec.grades.length) parts.push('Grades: ' + rec.grades.join(', '));
        if (rec.student_ids && rec.student_ids.length) parts.push(rec.student_ids.length + ' individual student(s)');
    }
    return parts.length ? parts.join(' + ') : 'N/A';
}
var viewOverlay = document.getElementById('viewAnnouncementOverlay');
var currentViewAnnouncement = null;
var editDraftBtn = document.getElementById('editDraftBtn');

function updateViewDraftButtons(ann) {
    if (!editDraftBtn) return;
    if (ann && ann.status === 'draft') {
        editDraftBtn.style.display = '';
    } else {
        editDraftBtn.style.display = 'none';
    }
}

function loadDraftIntoCompose(ann) {
    if (!ann) return;
    currentViewAnnouncement = ann;
    var subjectInput = document.getElementById('ann-subject');
    var tplTypeInput = document.getElementById('ann-template_type');
    var chEmail = document.getElementById('chEmail');
    var chSMS = document.getElementById('chSMS');
    var recAllParents = document.getElementById('recAllParents');
    var recAllTeachers = document.getElementById('recAllTeachers');
    var recAdvisers = document.getElementById('recAdvisers');
    var recGrade = document.getElementById('recGrade');
    var recIndiv = document.getElementById('recIndividual');
    var gradeBox = document.getElementById('gradeCheckboxes');
    var indivBox = document.getElementById('individualSearch');

    try {
        if (subjectInput) subjectInput.value = ann.subject || ann.title || '';
        if (tplTypeInput) tplTypeInput.value = ann.template_type || 'general';
        if (chEmail) chEmail.checked = false;
        if (chSMS) chSMS.checked = false;
        var channels = [];
        try { channels = JSON.parse(ann.channels || '[]'); } catch(e) { channels = []; }
        if (chEmail) chEmail.checked = channels.indexOf('email') !== -1;
        if (chSMS) chSMS.checked = channels.indexOf('sms') !== -1;
        var recipients = {};
        try { recipients = JSON.parse(ann.recipients || '{}'); } catch(e) { recipients = {}; }
        var rt = (ann.recipient_type || recipients.recipient_type || '').toString().split(',');
        var hasRt = function(v) { return rt.indexOf(v) !== -1; };

        if (recAllParents) recAllParents.checked = hasRt('all_parents') || hasRt('all') || !!recipients.all;
        if (recAllTeachers) recAllTeachers.checked = hasRt('all_teachers');
        if (recAdvisers) recAdvisers.checked = hasRt('advisers_only');
        if (recGrade) {
            recGrade.checked = hasRt('grade') || !!recipients.grades;
            gradeBox.style.display = recGrade.checked ? 'block' : 'none';
            if (recipients.grades && Array.isArray(recipients.grades)) {
                recipients.grades.forEach(function(g) {
                    var cb = document.getElementById('g' + g);
                    if (cb) { cb.checked = true; var chip = document.getElementById('gradeChip' + g); if (chip) chip.classList.add('active'); }
                });
            }
        }
        if (recIndiv) {
            recIndiv.checked = hasRt('individual') || !!recipients.student_ids;
            indivBox.style.display = recIndiv.checked ? 'block' : 'none';
            if (recipients.student_ids && Array.isArray(recipients.student_ids)) {
                recipients.student_ids.forEach(function(id) {
                    if (typeof selMap === 'object' && !selMap.has(parseInt(id))) {
                        selMap.set(parseInt(id), { name: 'Student #' + id, sid: '', grade: '' });
                    }
                });
                if (typeof renderStudents === 'function') renderStudents();
            }
        }

        if (typeof quill !== 'undefined') {
            var body = ann.body_html || ann.body || '';
            quill.setContents([]);
            quill.clipboard.dangerouslyPasteHTML(0, body);
            var bodyHidden = document.getElementById('body_html');
            var charCount = document.getElementById('charCount');
            if (bodyHidden) bodyHidden.value = body;
            if (charCount) {
                var len = quill.getText().trim().length;
                charCount.textContent = len.toLocaleString() + ' character' + (len !== 1 ? 's' : '');
                charCount.classList.toggle('active', len > 0);
            }
        }

        document.getElementById('announcement_id').value = ann.id || 0;
        document.getElementById('existing_attachment_path').value = ann.attachment_path || '';
        // Prefill link attachment
        var annLink = ann.attachment_link || '';
        var annLinkLabel = ann.attachment_link_label || '';
        if (linkUrl) linkUrl.value = annLink;
        if (linkLabelInp) linkLabelInp.value = annLinkLabel;
        if (typeof updLinkStrip === 'function') updLinkStrip();
        if (linkFields) linkFields.style.display = annLink ? 'block' : 'none';
        if (document.getElementById('formAction')) {
            document.getElementById('formAction').value = 'create';
        }
        closeModal(viewOverlay);
        if (subjectInput) subjectInput.focus();
        showToast('Draft loaded for editing.', 'success');
        setTimeout(function(){ document.getElementById('announcementForm').scrollIntoView({ behavior: 'smooth', block: 'start' }); }, 150);
    } catch (e) {
        console.error('Load draft error:', e);
        showToast('Unable to load draft for editing.', 'danger');
    }
}

document.querySelectorAll('.view-announcement-trigger').forEach(function(btn) {
    btn.addEventListener('click', function(e) {
        if (!this.dataset.announcement) return;
        var ann = JSON.parse(this.dataset.announcement);
        currentViewAnnouncement = ann;
        updateViewDraftButtons(ann);
        var recH = describeRecipients(ann);
        var ch = [];
        try { ch = JSON.parse(ann.channels || '[]'); } catch(err) { ch = []; }
        var chH = (ch||[]).map(function(c) { return '<span class="ch-badge '+c+'"><i class="bi bi-'+(c==='email'?'envelope':'chat-dots')+'"></i> '+c.toUpperCase()+'</span>'; }).join(' ');
        var body = ann.body_html || (ann.body||'').replace(/\n/g,'<br>');
        document.getElementById('view-announcement-content').innerHTML =
            '<h5 style="font-size:18px;font-weight:800;letter-spacing:-0.02em;margin-bottom:8px;">'+esc(ann.subject||ann.title||'Untitled')+'</h5>' +
            '<div style="display:flex;gap:16px;margin-bottom:16px;font-size:12px;color:rgba(255,255,255,0.55);"><span><i class="bi bi-clock" style="margin-right:4px;"></i>'+esc(ann.created_at||'N/A')+'</span><span><i class="bi bi-person" style="margin-right:4px;"></i>'+esc(ann.created_by_email||'System')+'</span></div>' +
            '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px;"><span style="background:rgba(255,255,255,0.06);padding:4px 12px;border-radius:20px;font-size:12px;font-weight:600;color:rgba(255,255,255,0.55);">'+esc(recH)+'</span>'+chH+'</div>' +
            '<div style="border:1px solid rgba(255,255,255,0.06);border-radius:10px;padding:20px;line-height:1.85;font-size:14px;background:rgba(255,255,255,0.04);">'+body+'</div>' +
            (ann.attachment_path ? '<div style="margin-top:16px;"><img src="<?= BASE_URL ?>/'+ann.attachment_path+'" style="max-height:200px;border-radius:10px;border:1px solid rgba(255,255,255,0.06);" alt="Attachment"></div>' : '') +
            (ann.attachment_link
                ? '<div style="margin-top:16px;">'
                  + '<div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:rgba(255,255,255,0.55);margin-bottom:8px;">Attached Link</div>'
                  + '<a href="'+esc(ann.attachment_link)+'" target="_blank" rel="noopener" style="display:inline-flex;align-items:center;gap:8px;padding:10px 18px;background:#4f46e5;color:#fff;text-decoration:none;border-radius:8px;font-size:13px;font-weight:600;">'
                  + '<i class="bi bi-box-arrow-up-right"></i> '+esc(ann.attachment_link_label || ann.attachment_link)+'</a>'
                  + '<div style="font-size:11px;color:rgba(255,255,255,0.4);word-break:break-all;font-family:\'JetBrains Mono\',monospace;margin-top:6px;">'+esc(ann.attachment_link)+'</div>'
                  + '</div>'
                : '');
        openModal(viewOverlay);
    });
});

if (editDraftBtn) {
    editDraftBtn.addEventListener('click', function() {
        if (currentViewAnnouncement && currentViewAnnouncement.status === 'draft') {
            loadDraftIntoCompose(currentViewAnnouncement);
        }
    });
}

// ─── Custom Template Modal trigger is handled in announcement_templates.js ─

// ─── Escape key closes modals + resets sidebar on resize ───────────────
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        // Close search dropdown
        if (searchDD) searchDD.style.display = 'none';
    }
});

function esc(s) { if (!s) return ''; var d = document.createElement('div'); d.appendChild(document.createTextNode(s)); return d.innerHTML; }

// ─── Resend Failed Announcement ─────────────────────────────────────
document.querySelectorAll('.resend-ann-btn').forEach(function(btn) {
    btn.addEventListener('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        var id = this.dataset.id;
        if (!id) return;
        var row = this.closest('tr') || this.closest('.ann-card');
        this.disabled = true;
        this.innerHTML = '<i class="bi bi-arrow-clockwise spin"></i>';
        var fd = new FormData();
        fd.append('action', 'resend');
        fd.append('id', id);
        var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
        if (csrf) fd.append('csrf_token', csrf);
        fetch('<?= BASE_URL ?>/api/announcements.php', {
            method: 'POST',
            body: fd,
            credentials: 'same-origin'
        })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (res && res.success) {
                showToast('success', res.message || 'Announcement resent successfully.');
                if (row) {
                    var badge = row.querySelector('.status-badge');
                    if (badge) { badge.className = 'status-badge sent'; badge.textContent = 'Sent'; }
                }
                setTimeout(function(){ location.reload(); }, 1200);
            } else {
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-arrow-clockwise"></i>';
                showToast('danger', (res && res.error) ? res.error : 'Resend failed. Please try again.');
            }
        })
        .catch(function() {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-arrow-clockwise"></i>';
            showToast('danger', 'Resend failed. Please try again.');
        });
    });
});

    // ─── Parent Notification Templates Modal ────────────────────────────
    var parentNotifOverlay = document.getElementById('parentNotifModal');
    var parentNotifForm = document.getElementById('parentNotifForm');
    var btnSaveTemplates = document.getElementById('btnSaveTemplates');
    var openParentNotifModalBtn = document.getElementById('openParentNotifModalBtn');
    var parentNotifClose = document.getElementById('parentNotifModalClose');
    var parentNotifCancel = document.getElementById('parentNotifCancel');

    if (openParentNotifModalBtn) {
        openParentNotifModalBtn.addEventListener('click', function() {
            openModal(parentNotifOverlay);
        });
    }
    if (parentNotifClose) {
        parentNotifClose.addEventListener('click', function() {
            closeModal(parentNotifOverlay);
        });
    }
    if (parentNotifCancel) {
        parentNotifCancel.addEventListener('click', function() {
            closeModal(parentNotifOverlay);
        });
    }

    var sampleData = {
        '{student_name}': 'Juan Dela Cruz',
        '{date}': 'July 24, 2026',
        '{time}': '07:15 AM',
        '{subject_name}': 'Mathematics 10',
        '{school_name}': 'Liceo de Baleno'
    };

    function renderPreview(textareaId, previewId) {
        var ta = document.getElementById(textareaId);
        var pv = document.getElementById(previewId);
        if (!ta || !pv) return;
        var raw = ta.value || '';
        var rendered = raw.replace(/\{student_name\}/g, sampleData['{student_name}'])
                         .replace(/\{date\}/g, sampleData['{date}'])
                         .replace(/\{time\}/g, sampleData['{time}'])
                         .replace(/\{subject_name\}/g, sampleData['{subject_name}'])
                         .replace(/\{school_name\}/g, sampleData['{school_name}']);
        pv.innerHTML = rendered ? esc(rendered).replace(/\n/g, '<br>') : '<span style="color:var(--ann-text-muted);font-style:italic;">Enter template text to see preview...</span>';
        pv.style.display = 'block';
    }

    document.querySelectorAll('.preview-template-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var target = this.dataset.target;
            var previewId = 'preview_' + target;
            var existing = document.getElementById(previewId);
            if (existing && existing.style.display === 'block') {
                existing.style.display = 'none';
                this.innerHTML = '👁️ Preview Live Message';
            } else {
                renderPreview(target, previewId);
                this.innerHTML = '🙈 Hide Preview';
            }
        });
    });

    document.querySelectorAll('.var-pill').forEach(function(pill) {
        pill.addEventListener('click', function() {
            var varText = this.dataset.var;
            var activeEl = document.activeElement;
            if (activeEl && (activeEl.tagName === 'TEXTAREA' || activeEl.tagName === 'INPUT')) {
                var start = activeEl.selectionStart;
                var end = activeEl.selectionEnd;
                var val = activeEl.value;
                activeEl.value = val.substring(0, start) + varText + val.substring(end);
                activeEl.selectionStart = activeEl.selectionEnd = start + varText.length;
                activeEl.focus();
            } else {
                var taIn = document.getElementById('template_time_in');
                if (taIn) {
                    taIn.value += varText;
                    taIn.focus();
                }
            }
            showToast('info', 'Inserted ' + varText);
        });
    });

    if (btnSaveTemplates && parentNotifForm) {
        btnSaveTemplates.addEventListener('click', function(e) {
            e.preventDefault();
            var fd = new FormData(parentNotifForm);
            fd.append('save_parent_templates', '1');
            var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
            if (csrf) fd.append('csrf_token', csrf);

            btnSaveTemplates.classList.add('btn-loading');
            btnSaveTemplates.disabled = true;

            fetch('<?= BASE_URL ?>/save_templates_processor.php', {
                method: 'POST',
                body: fd,
                credentials: 'same-origin'
            })
            .then(function(r) { return r.text().then(function(t) { return { status: r.status, text: t }; }); })
            .then(function(resp) {
                var trimmed = (resp.text || '').trim();
                if (!trimmed) throw new Error('Empty response');
                try {
                    var d = JSON.parse(trimmed);
                    if (d.success) {
                        showToast('success', d.message || 'Notification templates saved successfully.');
                        setTimeout(function() { closeModal(parentNotifModal); }, 600);
                    } else {
                        throw new Error(d && d.message ? d.message : 'Save failed');
                    }
                } catch (parseErr) {
                    console.warn('Raw server response:', trimmed.slice(0, 500));
                    var debugMsg = 'Server returned non-JSON. First 120 chars: ' + trimmed.slice(0, 120).replace(/</g, '&lt;');
                    throw new Error(debugMsg);
                }
            })
            .catch(function(err) {
                console.warn('Save templates failed:', err);
                var msg = (err && err.message) ? err.message : 'Failed to save templates. Please try again.';
                showToast('danger', msg);
            })
            .finally(function() {
                btnSaveTemplates.classList.remove('btn-loading');
                btnSaveTemplates.disabled = false;
            });
        });
    }

})();
</script>

<script src="<?= BASE_URL ?>/assets/js/announcement_templates.js?v=<?= @filemtime(ROOT_PATH . '/assets/js/announcement_templates.js') ?: time() ?>"></script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>