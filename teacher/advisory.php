<?php

ob_start();

require_once __DIR__ . '/../config.php';

requireRole(['teacher']);

// ─── AJAX: Student Search (scoped to the adviser's advisory class) ───────────
if (isset($_GET['action']) && $_GET['action'] === 'search_students') {
    header('Content-Type: application/json');
    $search = sanitize($_GET['q'] ?? '');
    $secId  = getAdvisorySectionId($db);
    $secRow = getAdvisorySectionRecord($db);
    if (strlen($search) < 2 || !$secId || !$secRow) { echo json_encode([]); exit; }
    try {
        $stmt = $db->prepare(
            "SELECT id, student_id, first_name, last_name, grade_level, section
             FROM students
             WHERE (first_name LIKE :q1 OR last_name LIKE :q2 OR student_id LIKE :q3)
               AND status = 'active'
               AND grade_level = :grade AND section = :section
             ORDER BY last_name, first_name LIMIT 20"
        );
        $like = "%{$search}%";
        $stmt->execute([
            ':q1' => $like, ':q2' => $like, ':q3' => $like,
            ':grade'   => $secRow['grade_level'],
            ':section' => $secRow['section_name'],
        ]);
        echo json_encode($stmt->fetchAll(), JSON_INVALID_UTF8_SUBSTITUTE);
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()], JSON_INVALID_UTF8_SUBSTITUTE);
    }
    exit;
}

$pageTitle = 'Class Advisories';
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
                $sectionId = getAdvisorySectionId($db);
                $stmt = $db->prepare("DELETE FROM announcements WHERE id = ? AND scope = 'advisory' AND target_section_id = ?");
                $stmt->execute([$id, $sectionId]);
                $_SESSION['flash_message'] = ['type' => 'success', 'message' => 'Announcement deleted.'];
            } catch (Exception $e) {
                $_SESSION['flash_message'] = ['type' => 'danger', 'message' => 'Failed to delete announcement.'];
            }
        }
        if (ob_get_length()) { ob_end_clean(); }
        header('Location: ' . BASE_URL . '/teacher/advisory.php');
        exit;
    }
}

// ─── Data ───────────────────────────────────────────────────────────────────
$advisorySectionId = getAdvisorySectionId($db);
$advisorySection   = getAdvisorySectionRecord($db);
$currentTeacher    = getCurrentTeacherRecord($db);
$perPage = 10;
$currentPage = max(1, intval($_GET['page'] ?? 1));

$totalAnnouncements = 0;
try {
    $stmt = $db->prepare("SELECT COUNT(*) FROM announcements WHERE scope = 'advisory' AND target_section_id = ?");
    $stmt->execute([$advisorySectionId]);
    $totalAnnouncements = (int)$stmt->fetchColumn();
} catch (Exception $e) {}

$totalPages = max(1, (int)ceil($totalAnnouncements / $perPage));
$currentPage = min($currentPage, $totalPages);
$offset = ($currentPage - 1) * $perPage;

$announcements = [];
try {
    $stmt = $db->prepare("SELECT a.*, u.email AS created_by_email FROM announcements a LEFT JOIN users u ON a.created_by = u.id WHERE a.scope = 'advisory' AND a.target_section_id = ? ORDER BY a.created_at DESC LIMIT :limit OFFSET :offset");
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute([$advisorySectionId]);
    $announcements = $stmt->fetchAll();
} catch (Exception $e) {}

$templates = [
    'HOMEROOM_PTA' => [
        'title'   => 'Homeroom / PTA Meeting',
        'icon'    => 'bi-people-fill',
        'color'   => 'primary',
        'subject' => '[Section Name] Homeroom / PTA Meeting on [Date]',
        'body'    => '<p>Dear Parents and Guardians of <strong>[Section Name]</strong>,</p><p><br></p><p>Please be informed that a Homeroom / PTA meeting for our advisory class will be held on <strong>[Date]</strong> at <strong>[Time]</strong> in <strong>[Venue/Location]</strong>.</p><p><br></p><p>Agenda:</p><ul><li>Class progress and academic standing</li><li>Attendance and behavior updates</li><li>Upcoming class activities</li></ul><p><br></p><p>Your presence and participation are highly valued. Thank you!</p><p><br></p><p>— [Teacher Name], Class Adviser</p>'
    ],
    'CLEARANCE_REMINDER' => [
        'title'   => 'Clearance Reminder',
        'icon'    => 'bi-clipboard-check',
        'color'   => 'success',
        'subject' => 'REMINDER: Clearance / Requirements Deadline for [Section Name] on [Date]',
        'body'    => '<p>Dear Parents and Guardians of <strong>[Section Name]</strong>,</p><p><br></p><p>This is a friendly reminder that all clearance requirements and school obligations must be settled on or before <strong>[Date]</strong>.</p><p><br></p><p>Outstanding items include:</p><ul><li><strong>[Requirement / Obligation 1]</strong></li><li><strong>[Requirement / Obligation 2]</strong></li><li><strong>[Requirement / Obligation 3]</strong></li></ul><p><br></p><p>Please make sure everything is submitted before the deadline to avoid any inconvenience.</p><p><br></p><p>— [Teacher Name], Class Adviser</p>'
    ],
    'CLASS_PROJECT' => [
        'title'   => 'Class Project / Activity',
        'icon'    => 'bi-lightbulb-fill',
        'color'   => 'info',
        'subject' => 'CLASS ACTIVITY: [Project/Activity Name] of [Section Name] on [Date]',
        'body'    => '<p>Dear Parents and Guardians of <strong>[Section Name]</strong>,</p><p><br></p><p>Our advisory class will be participating in <strong>[Project / Activity Name]</strong> on <strong>[Date]</strong>.</p><p><br></p><p>Details:</p><ul><li>Time: <strong>[Time]</strong></li><li>Venue: <strong>[Venue/Location]</strong></li><li>Requirements: <strong>[List of Materials / Requirements]</strong></li></ul><p><br></p><p>We encourage you to guide and support your child in completing this activity. Thank you!</p><p><br></p><p>— [Teacher Name], Class Adviser</p>'
    ],
    'ADVISORY_GENERAL' => [
        'title'   => 'General Advisory',
        'icon'    => 'bi-megaphone-fill',
        'color'   => 'secondary',
        'subject' => 'ADVISORY: [Topic / Reminder] for [Section Name]',
        'body'    => '<p>Dear Parents and Guardians of <strong>[Section Name]</strong>,</p><p><br></p><p>Please be advised of the following reminder for our advisory class: <strong>[Topic / Reminder]</strong>.</p><p><br></p><p>[Insert Details / Instructions Here]</p><p><br></p><p>Thank you for your continued support and cooperation.</p><p><br></p><p>— [Teacher Name], Class Adviser</p>'
    ],
    'ACHIEVEMENTS' => [
        'title'   => 'Achievements',
        'icon'    => 'bi-trophy-fill',
        'color'   => 'success',
        'subject' => 'CONGRATULATIONS: [Student Name] of [Section Name] — [Achievement]',
        'body'    => '<p>Dear Parents and Guardians of <strong>[Section Name]</strong>,</p><p><br></p><p>We are delighted to announce that <strong>[Student Name]</strong> of our advisory class recently achieved <strong>[Achievement / Recognition]</strong> in <strong>[Event / Activity]</strong>.</p><p><br></p><p>Details:</p><ul><li>Award / Recognition: <strong>[Achievement]</strong></li><li>Event: <strong>[Event / Competition]</strong></li><li>Date: <strong>[Date]</strong></li></ul><p><br></p><p>Please join us in congratulating [Student Name] for this well-deserved recognition!</p><p><br></p><p>— [Teacher Name], Class Adviser</p>'
    ]
];

// Metadata for history badges (includes legacy types still present in the DB)
$templateMeta = [
    'HOMEROOM_PTA'        => ['title' => 'Homeroom / PTA Meeting', 'icon' => 'bi-people-fill'],
    'CLEARANCE_REMINDER'  => ['title' => 'Clearance Reminder',     'icon' => 'bi-clipboard-check'],
    'CLASS_PROJECT'       => ['title' => 'Class Project',          'icon' => 'bi-lightbulb-fill'],
    'ADVISORY_GENERAL'    => ['title' => 'General Advisory',       'icon' => 'bi-megaphone-fill'],
    'ACHIEVEMENTS'        => ['title' => 'Achievements',           'icon' => 'bi-trophy-fill'],
    'general'             => ['title' => 'General',                'icon' => 'bi-megaphone']
];

$notifTemplates = [];

$resendAnn = null;

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

/* ─── Advisory Scope Banner ─────────────────────────────────────────── */
.advisory-banner {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 14px 18px;
    margin-bottom: 16px;
    border-radius: var(--ann-radius);
    background: linear-gradient(120deg, rgba(79,70,229,0.12), rgba(124,58,237,0.08));
    border: 1px solid rgba(79,70,229,0.25);
    box-shadow: var(--ann-shadow-sm);
}
.advisory-banner-icon {
    width: 42px; height: 42px;
    border-radius: 12px;
    background: var(--ann-accent-soft);
    color: var(--ann-accent);
    display: flex; align-items: center; justify-content: center;
    font-size: 18px;
    flex-shrink: 0;
}
.advisory-banner-info { display: flex; flex-direction: column; gap: 1px; min-width: 0; }
.advisory-banner-label {
    font-size: 10px; font-weight: 800; text-transform: uppercase;
    letter-spacing: 0.08em; color: var(--ann-text-muted);
}
.advisory-banner-section {
    font-size: 16px; font-weight: 800; letter-spacing: -0.02em;
    color: var(--ann-text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.advisory-banner-section em { font-weight: 600; font-style: normal; color: var(--ann-text-secondary); }
.advisory-banner-hint {
    font-size: 11px; color: var(--ann-text-secondary);
    display: flex; align-items: center; gap: 4px;
}
.advisory-banner-hint i { color: var(--ann-success); }
.advisory-banner-chip {
    margin-left: auto; flex-shrink: 0;
    padding: 6px 14px;
    border-radius: 20px;
    font-size: 11px; font-weight: 700;
    background: rgba(16,185,129,0.12);
    color: var(--ann-success);
    border: 1px solid rgba(16,185,129,0.25);
    display: flex; align-items: center; gap: 6px;
}

/* ─── Locked Recipient Card ─────────────────────────────────────────── */
.locked-recipient {
    display: flex; align-items: center; gap: 12px;
    padding: 12px 14px;
    margin-bottom: 12px;
    border-radius: 12px;
    background: rgba(255,255,255,0.04);
    border: 1px solid var(--ann-border);
}
.locked-recipient-icon {
    width: 38px; height: 38px; border-radius: 10px;
    background: var(--ann-accent-soft); color: var(--ann-accent);
    display: flex; align-items: center; justify-content: center;
    font-size: 16px; flex-shrink: 0;
}
.locked-recipient-info { display: flex; flex-direction: column; min-width: 0; }
.locked-recipient-label {
    font-size: 10px; font-weight: 800; text-transform: uppercase;
    letter-spacing: 0.08em; color: var(--ann-text-muted);
}
.locked-recipient-name {
    font-size: 14px; font-weight: 700; color: var(--ann-text);
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.locked-recipient-hint { font-size: 11px; color: var(--ann-text-secondary); }

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

    /* ─── Advisory Banner ─────────────────────────────────────────────── */
    .advisory-banner { padding: 12px 14px; gap: 12px; }
    .advisory-banner-chip { display: none; }
    .advisory-banner-section { font-size: 14px; }

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
                <h5 class="mb-0">Class Advisories</h5>
                <small>Send announcements to the parents and guardians of your advisory class</small>
            </div>
        </div>

        <?php if ($advisorySection): ?>
        <div class="advisory-banner animate-in">
            <div class="advisory-banner-icon"><i class="bi bi-people-fill"></i></div>
            <div class="advisory-banner-info">
                <span class="advisory-banner-label">Broadcast Target</span>
                <span class="advisory-banner-section">
                    Grade <?= sanitize($advisorySection['grade_level']) ?> - <?= sanitize($advisorySection['section_name']) ?>
                    <?php if (!empty($advisorySection['strand_name'])): ?>
                        <em>(<?= sanitize($advisorySection['strand_name']) ?>)</em>
                    <?php endif; ?>
                </span>
                <span class="advisory-banner-hint">
                    <i class="bi bi-lock-fill"></i> Broadcasts reach the guardians of students in this advisory class
                </span>
            </div>
            <span class="advisory-banner-chip"><i class="bi bi-broadcast"></i> Advisory Scope</span>
        </div>
        <?php else: ?>
        <div class="alert-preview" style="margin-bottom:16px;background:rgba(245,158,11,0.08);border:1px solid rgba(245,158,11,0.25);">
            <i class="bi bi-exclamation-triangle-fill" style="font-size:16px;flex-shrink:0;margin-top:1px;"></i>
            <span>No advisory class assigned to your account. Please contact the administrator to set your advisory section before sending class announcements.</span>
        </div>
        <?php endif; ?>

        <!-- MOBILE TITLE -->
        <div class="page-title mobile-title">
            <div class="mobile-title-inner">
                <div class="mobile-title-left">
                    <h5>Class Advisories</h5>
                    <small>Announcements for your advisory class</small>
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
                    <span class="d-none d-sm-inline">Advisory Templates</span>
                    <span class="d-inline d-sm-none">Templates</span>
                    <span style="font-size:11px;font-weight:500;color:var(--ann-text-muted);margin-left:4px;" class="d-none d-md-inline">
                        Click to auto-fill
                    </span>
                </span>
            </div>
            <div class="card-body" style="padding:16px 20px;">
                <div class="row g-3">
                    <?php
                    $tplColors = [
                        'HOMEROOM_PTA'       => ['bg'=>'var(--ann-accent-soft)',  'fg'=>'var(--ann-accent)'],
                        'CLEARANCE_REMINDER' => ['bg'=>'var(--ann-success-soft)', 'fg'=>'var(--ann-success)'],
                        'CLASS_PROJECT'      => ['bg'=>'var(--ann-info-soft)',    'fg'=>'var(--ann-info)'],
                        'ADVISORY_GENERAL'   => ['bg'=>'rgba(255,255,255,0.06)',  'fg'=>'#9ca3af'],
                        'ACHIEVEMENTS'       => ['bg'=>'var(--ann-success-soft)', 'fg'=>'var(--ann-success)'],
                        'general'            => ['bg'=>'rgba(255,255,255,0.06)',  'fg'=>'#9ca3af'],
                    ];
                    foreach ($templates as $key => $tpl):
                        $c = $tplColors[$key] ?? $tplColors['general'];
                    ?>
                    <div class="col-4 col-md-4 col-lg-2">
                        <div class="card h-100 text-center template-card" data-template-key="<?= $key ?>">
                            <div class="card-body">
                                <div style="width:44px;height:44px;border-radius:12px;background:<?= $c['bg'] ?>;display:inline-flex;align-items:center;justify-content:center;margin-bottom:10px;transition:transform 0.25s ease;">
                                    <i class="bi <?= $tpl['icon'] ?>" style="font-size:20px;color:<?= $c['fg'] ?>;"></i>
                                </div>
                                <div class="tpl-name"><?= $tpl['title'] ?></div>
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
                                   placeholder="[Section Name] Homeroom / PTA Meeting on [Date]" required>
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
                                <span class="placeholder-chip" data-insert="[TIME]">[TIME]</span>
                                <span class="placeholder-chip" data-insert="[SECTION NAME]">[SECTION NAME]</span>
                                <span class="placeholder-chip" data-insert="[VENUE]">[VENUE]</span>
                                <span class="placeholder-chip" data-insert="[REASON]">[REASON]</span>
                                <span class="placeholder-chip" data-insert="[TEACHER NAME]">[TEACHER NAME]</span>
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

                    <!-- Recipients (advisory class scope) -->
                    <div class="card settings-card compose-section animate-in" style="animation-delay:0.25s;">
                        <div class="settings-header">
                            <i class="bi bi-people"></i> Recipients
                        </div>
                        <div class="settings-body">
                            <?php if ($advisorySection): ?>
                            <div class="locked-recipient">
                                <div class="locked-recipient-icon"><i class="bi bi-people-fill"></i></div>
                                <div class="locked-recipient-info">
                                    <span class="locked-recipient-label">Advisory Class</span>
                                    <span class="locked-recipient-name">
                                        Grade <?= sanitize($advisorySection['grade_level']) ?> - <?= sanitize($advisorySection['section_name']) ?>
                                        <?php if (!empty($advisorySection['strand_name'])): ?>
                                            (<?= sanitize($advisorySection['strand_name']) ?>)
                                        <?php endif; ?>
                                    </span>
                                    <span class="locked-recipient-hint">Guardians of students in this section</span>
                                </div>
                            </div>

                            <div style="margin-top:14px;">
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="radio" name="recipient_scope" id="recScopeAll" value="all" checked>
                                    <label class="form-check-label" for="recScopeAll">
                                        <i class="bi bi-person-hearts" style="margin-right:4px;color:#fff;"></i> All Parents of the Advisory Class
                                    </label>
                                </div>
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="radio" name="recipient_scope" id="recScopeIndividual" value="individual">
                                    <label class="form-check-label" for="recScopeIndividual">Individual Parents of Specific Students</label>
                                </div>
                                <div id="advisoryIndividualSearch" style="display:none;margin-top:10px;">
                                    <div class="search-box" style="margin-bottom:10px;position:relative;">
                                        <i class="bi bi-search search-icon"></i>
                                        <input type="text" class="search-input" id="advisoryStudentSearchInput" placeholder="Search students in this advisory class...">
                                        <button type="button" class="search-btn" id="advisorySearchBtn"><i class="bi bi-search"></i> Search</button>
                                        <div class="search-results-dropdown" id="advisorySearchResults" style="display:none;"></div>
                                    </div>
                                    <div id="advisorySelectedStudents" style="margin-top:10px;display:flex;flex-wrap:wrap;gap:4px;"></div>
                                    <p style="font-size:11px;color:var(--ann-text-muted);margin:8px 0 0;line-height:1.6;">
                                        <i class="bi bi-info-circle-fill" style="margin-right:3px;"></i>
                                        Only the guardians of the selected students will receive this announcement.
                                    </p>
                                </div>
                            </div>
                            <?php else: ?>
                            <div class="alert-preview" style="margin:0;">
                                <i class="bi bi-exclamation-triangle-fill" style="font-size:16px;flex-shrink:0;margin-top:1px;"></i>
                                <span>No advisory class assigned yet.</span>
                            </div>
                            <?php endif; ?>
                            <div class="form-check mb-2" style="border-top:1px solid var(--ann-border);padding-top:12px;margin-top:14px;">
                                <input class="form-check-input" type="checkbox" id="chEmailAdvisory" value="1" checked disabled>
                                <label class="form-check-label" for="chEmailAdvisory">
                                    <i class="bi bi-envelope" style="margin-right:4px;color:#fff;"></i> Guardians' Email
                                </label>
                            </div>
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" id="chSMSAdvisory" value="1" checked disabled>
                                <label class="form-check-label" for="chSMSAdvisory">
                                    <i class="bi bi-chat-dots" style="margin-right:4px;color:#fff;"></i> Guardians' SMS
                                </label>
                            </div>
                            <p style="font-size:11px;color:var(--ann-text-muted);margin:0;line-height:1.6;">
                                <i class="bi bi-shield-lock-fill" style="margin-right:3px;"></i>
                                Delivery channels are enforced by the system for class advisories.
                            </p>
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
                                        elseif ($rt === 'advisory_class') $recLabels[] = 'All Parents (Advisory Class)';
                                        elseif ($rt === 'grade') $recLabels[] = 'Grades ' . implode(', ', $recJson['grades'] ?? []);
                                        elseif ($rt === 'individual') $recLabels[] = count($recJson['student_ids'] ?? []) . ' individual parent(s)';
                                    }
                                    if (empty($recLabels)) {
                                        if (!empty($recJson['grades'])) $recLabels[] = 'Grades ' . implode(', ', $recJson['grades']);
                                        elseif (!empty($recJson['student_ids'])) $recLabels[] = count($recJson['student_ids']) . ' individual parent(s)';
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
                                        <form method="POST" action="<?= BASE_URL ?>/teacher/advisory.php" class="d-inline" onsubmit="return confirm('Delete this announcement?');">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="announcement_id" value="<?= $ann['id'] ?>">
                                            <button type="submit" class="tbl-action danger-hover" title="Delete">
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
            <button type="button" class="evt-btn evt-btn-cancel" id="viewModalCancel">Close</button>
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
        placeholder: 'Dear Parents/Guardians,\n\nWrite your advisory announcement here...\n\n\u2014 [Teacher Name], Class Adviser'
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

    document.querySelectorAll('.template-card').forEach(function(card) {
        card.addEventListener('click', function() {
            var key = this.dataset.templateKey;
            var subject = '', body = '', typeKey = 'general';
            if (key.startsWith('custom_')) { subject = this.dataset.customSubject || ''; body = this.dataset.customBody || ''; }
            else if (TEMPLATES[key]) { subject = TEMPLATES[key].subject || ''; body = TEMPLATES[key].body || ''; typeKey = key; }
            subjectInput.value = subject;
            tplTypeInput.value = typeKey;
            if (body) quill.root.innerHTML = body; else quill.setText('');
            bodyHidden.value = quill.root.innerHTML;
            quill.focus();
            if (activeCard) activeCard.classList.remove('active-template');
            this.classList.add('active-template');
            activeCard = this;
        });
    });

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

    // ─── Recipients: All Parents vs Individual Parents ────────────────────
    var recScopeAll        = document.getElementById('recScopeAll');
    var recScopeIndividual = document.getElementById('recScopeIndividual');
    var advisorySearchWrap = document.getElementById('advisoryIndividualSearch');

    function updateRecipientScopeUI() {
        advisorySearchWrap.style.display = recScopeIndividual.checked ? 'block' : 'none';
    }
    recScopeAll.addEventListener('change', updateRecipientScopeUI);
    recScopeIndividual.addEventListener('change', updateRecipientScopeUI);

    var advSearchInput = document.getElementById('advisoryStudentSearchInput');
    var advSearchDD    = document.getElementById('advisorySearchResults');
    var advSelCont     = document.getElementById('advisorySelectedStudents');
    var advSelMap      = new Map();
    var advDebounce;

    advSearchInput.addEventListener('input', function() {
        clearTimeout(advDebounce);
        var q = this.value.trim();
        if (q.length < 2) { advSearchDD.style.display = 'none'; return; }
        advDebounce = setTimeout(function() { doAdvisorySearch(q); }, 250);
    });
    advSearchInput.addEventListener('keydown', function(e) { if (e.key === 'Enter') { clearTimeout(advDebounce); doAdvisorySearch(this.value.trim()); } });
    document.getElementById('advisorySearchBtn').addEventListener('click', function() { doAdvisorySearch(advSearchInput.value.trim()); });
    advSearchInput.addEventListener('blur', function() { setTimeout(function() { advSearchDD.style.display = 'none'; }, 200); });
    advSearchInput.addEventListener('focus', function() { if (this.value.trim().length >= 2) advSearchDD.style.display = 'block'; });

    function doAdvisorySearch(q) {
        if (q.length < 2) { advSearchDD.style.display = 'none'; return; }
        var url = 'advisory.php?action=search_students&q=' + encodeURIComponent(q);
        fetch(url)
            .then(function(r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
            .then(function(data) {
                if (data.error) { throw new Error(data.error); }
                if (!data.length) { advSearchDD.innerHTML = '<div style="padding:14px;text-align:center;color:var(--ann-text-muted);font-size:12px;">No students found in this advisory class</div>'; }
                else {
                    advSearchDD.innerHTML = data.map(function(s) {
                        var sel = advSelMap.has(s.id);
                        return '<div class="search-result-item '+(sel?'selected':'')+'" data-id="'+s.id+'" data-name="'+esc(s.first_name)+' '+esc(s.last_name)+'" data-sid="'+esc(s.student_id)+'" data-grade="'+s.grade_level+'"><div><span class="result-name">'+esc(s.last_name)+', '+esc(s.first_name)+'</span> <span class="result-meta">'+esc(s.student_id)+'</span></div><span class="result-badge">G'+s.grade_level+'</span></div>';
                    }).join('');
                    advSearchDD.querySelectorAll('.search-result-item:not(.selected)').forEach(function(item) { item.addEventListener('click', function() { addAdvisoryStudent(this.dataset); this.classList.add('selected'); }); });
                }
                advSearchDD.style.display = 'block';
            }).catch(function(err) { console.error('Advisory search error:', err); advSearchDD.innerHTML = '<div style="padding:14px;text-align:center;color:var(--ann-danger);font-size:12px;">Search error: ' + esc(err.message) + '</div>'; advSearchDD.style.display = 'block'; });
    }
    function addAdvisoryStudent(d) { if (advSelMap.has(parseInt(d.id))) return; advSelMap.set(parseInt(d.id), { name: d.name, sid: d.sid, grade: d.grade }); renderAdvisoryStudents(); showToast('success', d.name + ' added'); }
    function removeAdvisoryStudent(id) { advSelMap.delete(id); renderAdvisoryStudents(); }
    function renderAdvisoryStudents() {
        advSelCont.innerHTML = '';
        advSelMap.forEach(function(s, id) { var tag = document.createElement('span'); tag.className = 'selected-student-tag'; tag.innerHTML = esc(s.name)+' <span style="opacity:0.6;">(G'+s.grade+')</span> <button type="button" class="tag-remove" data-rid="'+id+'">&times;</button>'; advSelCont.appendChild(tag); });
        document.querySelectorAll('input[name="student_ids[]"]').forEach(function(h) { h.remove(); });
        var f = document.getElementById('announcementForm');
        advSelMap.forEach(function(s, id) { var h = document.createElement('input'); h.type='hidden'; h.name='student_ids[]'; h.value=id; f.appendChild(h); });
        advSelCont.querySelectorAll('.tag-remove').forEach(function(btn) { btn.addEventListener('click', function() { removeAdvisoryStudent(parseInt(this.dataset.rid)); }); });
    }

    function advisoryRecipientLabel() {
        var grade = '<?= $advisorySection ? addslashes($advisorySection['grade_level'] . ' - ' . $advisorySection['section_name']) : 'N/A' ?>';
        if (recScopeIndividual && recScopeIndividual.checked) {
            return advSelMap.size ? advSelMap.size + ' individual parent(s) of ' + grade : 'No students selected';
        }
        return 'All Parents of ' + grade;
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
    if (recScopeIndividual && recScopeIndividual.checked && advSelMap.size === 0) { showToast('warning', 'Select at least one student for individual recipients.'); return; }
    document.getElementById('preview-subject').textContent = subjectInput.value;
    document.getElementById('preview-body').innerHTML = quill.root.innerHTML;
    document.getElementById('preview-recipients').textContent = advisoryRecipientLabel();
    var ch = [];
    if (document.getElementById('chEmail').checked) ch.push('Email');
    if (document.getElementById('chSMS').checked) ch.push('SMS');
    document.getElementById('preview-channels').innerHTML = ch.length ? ch.map(function(c) { return '<span class="ch-badge '+c.toLowerCase()+'"><i class="bi bi-'+(c==='Email'?'envelope':'chat-dots')+'"></i> '+c+'</span>'; }).join(' ') : '<span style="color:#ef4444;font-size:12px;">None selected</span>';
    document.getElementById('preview-timing').textContent = schedLater.checked ? 'Scheduled: '+(document.querySelector('[name="schedule_date"]').value||'?')+' at '+(document.querySelector('[name="schedule_time"]').value||'?') : 'Send immediately';
    var pa = document.getElementById('preview-attachment'), pi = document.getElementById('preview-attachment-img');
    if (attachInput.files.length) { pa.style.display='block'; pi.src=URL.createObjectURL(attachInput.files[0]); } else { pa.style.display='none'; }
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
        else if (v === 'advisory_class') parts.push('All Parents (Advisory Class)');
        else if (v === 'grade') parts.push('Grades: ' + (rec.grades || []).join(', '));
        else if (v === 'individual') parts.push((rec.student_ids || []).length + ' individual parent(s)');
    });
    if (!parts.length) {
        if (rec.all) parts.push('All Parents');
        if (rec.grades && rec.grades.length) parts.push('Grades: ' + rec.grades.join(', '));
        if (rec.student_ids && rec.student_ids.length) parts.push(rec.student_ids.length + ' individual parent(s)');
    }
    return parts.length ? parts.join(' + ') : 'N/A';
}
var viewOverlay = document.getElementById('viewAnnouncementOverlay');
document.querySelectorAll('.view-announcement-trigger').forEach(function(btn) {
    btn.addEventListener('click', function(e) {
        if (!this.dataset.announcement) return;
        var ann = JSON.parse(this.dataset.announcement);
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
            (ann.attachment_path ? '<div style="margin-top:16px;"><img src="<?= BASE_URL ?>/'+ann.attachment_path+'" style="max-height:200px;border-radius:10px;border:1px solid rgba(255,255,255,0.06);" alt="Attachment"></div>' : '');
        openModal(viewOverlay);
    });
});

// ─── Escape key closes modals ─────────────────────────────────────────
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') { closeAll(); }
});

function esc(s) { if (!s) return ''; var d = document.createElement('div'); d.appendChild(document.createTextNode(s)); return d.innerHTML; }

})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>