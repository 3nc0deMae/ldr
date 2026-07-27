<?php
require_once __DIR__ . '/../config.php';
requireRole(['admin', 'teacher']);

$pageTitle = 'Take Attendance';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

$teacherId = getCurrentUserId();
$teacher = null;
$userEmail = $_SESSION['user_email'] ?? '';
try {
    $stmt = $db->prepare("SELECT t.* FROM teachers t JOIN users u ON t.user_id = u.id WHERE u.email = ? LIMIT 1");
    $stmt->execute([$userEmail]);
    $teacher = $stmt->fetch();
} catch (Exception $e) {}

$assignedSubjectIds = [];
$assignedSectionIds = [];
if ($teacher) {
    foreach (['grade_section_handled', 'core_subjects_handled', 'track_elective_handled'] as $field) {
        $raw = $teacher[$field] ?? '';
        if (is_string($raw)) { $raw = json_decode($raw, true); }
        if (!is_array($raw)) { continue; }
        foreach ($raw as $item) {
            if (isset($item['subject_id']) && is_numeric($item['subject_id'])) { $assignedSubjectIds[] = (int)$item['subject_id']; }
            if (isset($item['section_id']) && is_numeric($item['section_id'])) { $assignedSectionIds[] = (int)$item['section_id']; }
        }
    }
}
$assignedSubjectIds = array_values(array_unique(array_filter($assignedSubjectIds, function ($id) { return $id > 0; })));
$assignedSectionIds = array_values(array_unique(array_filter($assignedSectionIds, function ($id) { return $id > 0; })));

$subjects = [];
if (!empty($assignedSubjectIds)) {
    $ph = implode(',', array_fill(0, count($assignedSubjectIds), '?'));
    try { $stmt = $db->prepare("SELECT * FROM subjects WHERE id IN ($ph) ORDER BY subject_name"); $stmt->execute($assignedSubjectIds); $subjects = $stmt->fetchAll(); } catch (Exception $e) {}
}

$teacherSections = [];
if (!empty($assignedSectionIds)) {
    $ph = implode(',', array_fill(0, count($assignedSectionIds), '?'));
    try {
        $stmt = $db->prepare("SELECT s.id, s.section_name, s.grade_level, st.strand_name, st.strand_code FROM sections s LEFT JOIN strands st ON s.strand_id = st.id WHERE s.id IN ($ph) ORDER BY CAST(s.grade_level AS UNSIGNED), s.section_name");
        $stmt->execute($assignedSectionIds);
        $teacherSections = $stmt->fetchAll();
    } catch (Exception $e) {}
}

/* Map each assigned subject to the grade levels of the sections it is paired with */
$sectionGradeMap = [];
foreach ($teacherSections as $sec) {
    $sectionGradeMap[(int)$sec['id']] = (int)$sec['grade_level'];
}

$subjectGradesMap = [];
$allSubjectGrades = [];
if ($teacher) {
    foreach (['grade_section_handled', 'core_subjects_handled', 'track_elective_handled'] as $field) {
        $raw = $teacher[$field] ?? '';
        if (is_string($raw)) { $raw = json_decode($raw, true); }
        if (!is_array($raw)) { continue; }
        foreach ($raw as $item) {
            $sid   = isset($item['subject_id']) && is_numeric($item['subject_id']) ? (int)$item['subject_id'] : 0;
            $secId = isset($item['section_id']) && is_numeric($item['section_id']) ? (int)$item['section_id'] : 0;
            if (!$sid || !$secId || !isset($sectionGradeMap[$secId])) { continue; }
            $g = $sectionGradeMap[$secId];
            if ($g <= 0) { continue; }
            $subjectGradesMap[$sid][] = $g;
            if (!in_array($g, $allSubjectGrades, true)) { $allSubjectGrades[] = $g; }
        }
    }
}
foreach ($subjectGradesMap as $sid => $grades) {
    $subjectGradesMap[$sid] = array_values(array_unique($grades));
}
sort($allSubjectGrades, SORT_NUMERIC);

$teacherGrades = [];
foreach ($teacherSections as $sec) {
    $g = (string)$sec['grade_level'];
    if ($g !== '' && !in_array($g, $teacherGrades, true)) { $teacherGrades[] = $g; }
}
sort($teacherGrades, SORT_NUMERIC);

$activeSession = null;
try {
    $stmt = $db->prepare("SELECT * FROM attendance_sessions WHERE session_type = 'class' AND status = 'active' AND created_by = ? ORDER BY start_time DESC LIMIT 1");
    $stmt->execute([$teacherId]);
    $activeSession = $stmt->fetch();
} catch (Exception $e) {}

/* Fetch the FULL enrolled roster for the active session: every active student
   matching the session's subject/grade/section, joined with any existing
   attendance record so detected students show as present/late. */
$sessionRoster = [];
if ($activeSession) {
    try {
        $stmt = $db->prepare(
            "SELECT s.id AS student_pk, s.student_id as sid, s.first_name, s.last_name, s.grade_level, s.section,
                    a.status AS att_status, a.time AS att_time
             FROM students s
             LEFT JOIN attendance a
                 ON a.student_id = s.id
                 AND a.subject_id = ?
                 AND a.date = ?
                 AND a.recorded_by = ?
                 AND a.session_type = 'class'
             WHERE s.grade_level = ? AND s.section = ? AND s.status = 'active'
             ORDER BY s.last_name ASC, s.first_name ASC"
        );
        $stmt->execute([
            $activeSession['subject_id'],
            date('Y-m-d'),
            $teacherId,
            $activeSession['grade_level'],
            $activeSession['section']
        ]);
        $sessionRoster = $stmt->fetchAll();
    } catch (Exception $e) {}

    /* Any previously recorded attendance (e.g. from a scanned student) that is
       not in the roster list gets appended so nothing is lost. */
    try {
        $stmt = $db->prepare(
            "SELECT s.id AS student_pk, s.student_id as sid, s.first_name, s.last_name, s.grade_level, s.section,
                    a.status AS att_status, a.time AS att_time
             FROM attendance a
             JOIN students s ON a.student_id = s.id
             WHERE a.session_type = 'class' AND a.recorded_by = ? AND a.date = ? AND a.subject_id = ?
               AND NOT (s.grade_level = ? AND s.section = ?)
             ORDER BY s.last_name ASC, s.first_name ASC"
        );
        $stmt->execute([
            $teacherId,
            date('Y-m-d'),
            $activeSession['subject_id'],
            $activeSession['grade_level'],
            $activeSession['section']
        ]);
        foreach ($stmt->fetchAll() as $extra) {
            $sessionRoster[] = $extra;
        }
    } catch (Exception $e) {}
}
?>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/pages-theme.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/pages-navbar.css">

<style>
    :root {
        --td-font: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif;
        --td-mono: 'JetBrains Mono', monospace;
        --td-primary: #4f46e5; --td-primary-light: rgba(79,70,229,0.15); --td-primary-dark: #3730a3; --td-primary-glow: rgba(79,70,229,0.2);
        --td-success: #10b981; --td-success-light: rgba(16,185,129,0.15);
        --td-danger: #ef4444; --td-danger-light: rgba(239,68,68,0.15);
        --td-warning: #f59e0b; --td-warning-light: rgba(245,158,11,0.15);
        --td-info: #06b6d4; --td-info-light: rgba(6,182,212,0.15);
        --td-radius: 14px; --td-radius-sm: 10px; --td-radius-xs: 8px;
        --td-shadow-sm: 0 1px 3px rgba(0,0,0,0.2); --td-shadow: 0 4px 16px rgba(0,0,0,0.25);
        --td-shadow-lg: 0 12px 40px rgba(0,0,0,0.3);
        --td-transition: 0.2s cubic-bezier(0.4,0,0.2,1);
    }
    .main-content .card > .card-header { display: flex; align-items: center; justify-content: flex-start; gap: 6px; }

    /* Mirror the face scanner preview */
    #classVideo { transform: scaleX(-1); }

    /* TOP NAVBAR */
    .navbar-left { display: flex; align-items: center; gap: 16px; }
    .page-title h5 { font-size: 20px; font-weight: 800; letter-spacing: -0.03em; margin: 0; }
    .page-title small { font-size: 13px; font-weight: 500; }
    .navbar-actions { display: flex; align-items: center; gap: 10px; }
    .date-display { font-size: 13px; font-weight: 600; padding: 8px 16px; border-radius: var(--td-radius-sm); display: flex; align-items: center; gap: 8px; }
    .date-display i { font-size: 14px; }
    .nav-icon-btn { width: 40px; height: 40px; border-radius: var(--td-radius-sm); display: inline-flex; align-items: center; justify-content: center; cursor: pointer; transition: all var(--td-transition); font-size: 16px; position: relative; }
    .nav-icon-btn:hover { transform: translateY(-1px); }

    /* NAVBAR BRAND (mobile) */
    .navbar-brand-logo { border-radius: 10px; object-fit: contain; background: rgba(255,255,255,0.08); padding: 3px; }
    .navbar-brand-text { display: flex; flex-direction: column; line-height: 1.25; }
    .navbar-brand-name { font-size: 13px; font-weight: 800; letter-spacing: 0.04em; text-transform: uppercase; }
    .navbar-brand-sub { font-size: 9px; font-weight: 500; opacity: 0.5; }

    /* MOBILE PAGE TITLE */
    .mobile-title { display: none; padding: 14px 0 4px; }
    .mobile-title-inner { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; }
    .mobile-title-left h5 { font-size: 18px; font-weight: 800; letter-spacing: -0.03em; margin: 0; }
    .mobile-title-left small { font-size: 12px; font-weight: 500; }
    .mobile-date { font-size: 11px; font-weight: 600; padding: 6px 10px; border-radius: var(--td-radius-xs); display: flex; align-items: center; gap: 6px; white-space: nowrap; flex-shrink: 0; margin-top: 2px; }
    .mobile-date i { font-size: 12px; }

    .content-area { padding: 28px; }

    /* BUTTONS */
    .btn-primary { background: var(--td-primary); border-color: var(--td-primary); border-radius: var(--td-radius-xs); font-weight: 600; font-size: 13px; padding: 10px 18px; transition: all var(--td-transition); }
    .btn-primary:hover { background: var(--td-primary-dark); border-color: var(--td-primary-dark); transform: translateY(-1px); box-shadow: 0 4px 12px var(--td-primary-glow); }
    .btn-outline-secondary { border: 1px solid rgba(255,255,255,0.15); color: rgba(255,255,255,0.7); border-radius: var(--td-radius-xs); font-weight: 600; font-size: 13px; padding: 10px 18px; background: transparent; transition: all var(--td-transition); }
    .btn-outline-secondary:hover { background: rgba(255,255,255,0.08); border-color: rgba(255,255,255,0.25); color: #fff; transform: translateY(-1px); }
    .btn-danger { border-radius: var(--td-radius-xs); font-weight: 600; font-size: 13px; padding: 10px 18px; transition: all var(--td-transition); }
    .btn-danger:hover { transform: translateY(-1px); }
    .btn-secondary { border-radius: var(--td-radius-xs); font-weight: 600; font-size: 13px; }

    /* CARDS */
    .card { overflow: hidden; border-radius: var(--td-radius); }
    .card-header { font-size: 14px; font-weight: 700; letter-spacing: -0.01em; padding: 16px 20px; }
    .card-body { padding: 20px; }
    .card-footer { padding: 14px 20px; }

    /* FORM */
    .form-label { font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 6px; display: block; }
    .form-select, .form-control { padding: 10px 14px; border-radius: var(--td-radius-sm); font-size: 13px; font-weight: 500; border-width: 1.5px; transition: all var(--td-transition); }
    .form-select:focus, .form-control:focus { box-shadow: 0 0 0 3px var(--td-primary-glow); }

    /* SESSION BANNER */
    .session-banner { display: flex; justify-content: space-between; align-items: center; gap: 14px; padding: 14px 18px; border-radius: var(--td-radius-sm); margin-bottom: 20px; flex-wrap: wrap; animation: fadeIn 0.3s ease; }
    .session-banner-left { display: flex; align-items: center; gap: 10px; flex: 1; min-width: 0; }
    .session-banner-dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; animation: pulseDot 1.5s ease-in-out infinite; }
    @keyframes pulseDot { 0%,100% { opacity: 1; } 50% { opacity: 0.4; } }
    .session-banner-info { font-size: 13px; font-weight: 600; line-height: 1.4; }
    .session-banner-info span { font-weight: 400; opacity: 0.6; }

    /* SCAN LOG */
    .scan-log-header { display: flex; justify-content: space-between; align-items: center; }
    .scan-log-count { font-size: 11px; font-weight: 700; padding: 4px 10px; border-radius: 20px; }
    .scan-log-body { overflow-y: auto; }
    .scan-log-body::-webkit-scrollbar { width: 4px; }
    .scan-item { display: flex; align-items: center; gap: 12px; padding: 12px 16px; border-bottom: 1px solid rgba(255,255,255,0.06); animation: scanItemIn 0.3s ease; transition: background var(--td-transition); }
    .scan-item:hover { background: rgba(255,255,255,0.03); }
    .scan-item:last-child { border-bottom: none; }
    @keyframes scanItemIn { from { opacity: 0; transform: translateX(-8px); } to { opacity: 1; transform: translateX(0); } }
    .scan-item-new { background: rgba(16,185,129,0.06); }
    .scan-avatar { width: 38px; height: 38px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 15px; flex-shrink: 0; }
    .scan-info { flex: 1; min-width: 0; }
    .scan-name { font-size: 13px; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .scan-meta { font-size: 11px; margin-top: 2px; }
    .scan-time { font-size: 11px; margin-top: 2px; }
    .edit-status-btn { opacity: 0.7; transition: all var(--td-transition); }
    .edit-status-btn:hover { opacity: 1; transform: translateY(-1px); }

    /* SESSION SUMMARY */
    .session-summary { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; text-align: center; }
    .summary-item { padding: 10px 4px; border-radius: var(--td-radius-xs); }
    .summary-num { font-size: 20px; font-weight: 800; font-family: var(--td-mono); letter-spacing: -0.03em; line-height: 1.2; }
    .summary-label { font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.06em; margin-top: 4px; }

    /* EMPTY STATE */
    .empty-log { text-align: center; padding: 48px 20px; display: flex; flex-direction: column; align-items: center; gap: 10px; }
    .empty-log-icon { width: 64px; height: 64px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 28px; }
    .empty-log p { font-size: 13px; margin: 0; }

    /* =====================================================
       MANUAL LRN MODAL
       ===================================================== */
    .event-modal-overlay { position: fixed; inset: 0; background: rgba(26,29,46,0.5); backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px); z-index: 9998; display: none; align-items: center; justify-content: center; padding: 20px; }
    .event-modal-overlay.show { display: flex; }
    .event-modal { border-radius: 20px; width: min(620px,100%); max-width: 100%; height: auto; max-height: 90vh; display: flex; flex-direction: column; overflow: hidden; animation: modalSlideIn .35s cubic-bezier(.34,1.56,.64,1); }
    .event-modal form { display: flex; flex-direction: column; flex: 1 1 auto; min-height: 0; }
    @keyframes modalSlideIn { from { opacity: 0; transform: translateY(24px) scale(.96); } to { opacity: 1; transform: translateY(0) scale(1); } }
    .event-modal-header { display: flex; justify-content: space-between; align-items: center; padding: 18px 22px; flex-shrink: 0; }
    .event-modal-title { display: flex; align-items: center; gap: 10px; font-size: 16px; font-weight: 800; letter-spacing: -0.02em; }
    .event-modal-title i { font-size: 20px; }
    .event-modal-close { width: 32px; height: 32px; border: none; border-radius: var(--td-radius-xs); display: flex; align-items: center; justify-content: center; cursor: pointer; transition: all var(--td-transition); font-size: 13px; background: rgba(255,255,255,0.06); color: inherit; }
    .event-modal-close:hover { background: rgba(255,255,255,0.14); }
    .event-modal-body { padding: 0 22px 14px; overflow-y: auto; flex: 1 1 auto; min-height: 0; -webkit-overflow-scrolling: touch; }
    .event-modal-body::-webkit-scrollbar { width: 5px; }
    .event-modal-body::-webkit-scrollbar-track { background: transparent; margin: 4px 0; }
    .event-modal-body::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.15); border-radius: 10px; }
    .event-modal-body::-webkit-scrollbar-thumb:hover { background: rgba(255,255,255,0.25); }
    .event-modal-body { scrollbar-width: thin; scrollbar-color: rgba(255,255,255,0.15) transparent; }
    .event-modal-footer { display: flex; justify-content: flex-end; gap: 10px; padding: 12px 22px; flex-shrink: 0; border-top: 1px solid rgba(255,255,255,0.06); background: rgba(255,255,255,0.02); }
    .evt-field { margin-bottom: 12px; }
    .evt-field:last-child { margin-bottom: 0; }
    .evt-field label { display: block; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; margin-bottom: 5px; line-height: 1.3; }
    .evt-field label .required { color: var(--td-danger); }
    .evt-field input[type="text"], .evt-field input[type="email"], .evt-field input[type="tel"], .evt-field textarea, .evt-field select { width: 100%; padding: 9px 12px; border: 1.5px solid rgba(255,255,255,0.12); border-radius: var(--td-radius-sm); font-size: 13px; transition: all var(--td-transition); font-family: var(--td-font); background: rgba(255,255,255,0.05); color: #fff; }
    .evt-field select option { background: #1e2a4a; color: #fff; }
    .evt-field input:focus, .evt-field textarea:focus, .evt-field select:focus { outline: none; border-color: var(--td-primary); box-shadow: 0 0 0 3px var(--td-primary-glow); }
    .evt-field input::placeholder { color: rgba(255,255,255,0.25); }
    .evt-btn { padding: 10px 18px; border: none; border-radius: var(--td-radius-sm); font-size: 13px; font-weight: 700; cursor: pointer; transition: all var(--td-transition); display: flex; align-items: center; gap: 6px; }
    .evt-btn-cancel { background: rgba(255,255,255,0.08); color: inherit; }
    .evt-btn-cancel:hover { background: rgba(255,255,255,0.14); }
    .evt-btn-save { background: var(--td-primary); color: #fff; }
    .evt-btn-save:hover { background: var(--td-primary-dark); box-shadow: 0 4px 16px var(--td-primary-glow); }

    @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }

    /* =====================================================
       TABLET
       ===================================================== */
    @media (max-width: 991px) {
        .content-area { padding: 20px; }
        .card-body { padding: 16px; }
        .card-header { padding: 14px 16px; }
    }

    /* =====================================================
       MOBILE
       ===================================================== */
    @media (max-width: 767px) {
        .top-navbar { padding: 8px 12px; flex-wrap: wrap; gap: 8px; height: auto; }
        .navbar-left { flex-shrink: 0; gap: 10px; }
        .navbar-brand { display: flex; }
        .navbar-brand-logo { width: 44px; height: 44px; }
        .navbar-brand-name { font-size: 12px; }
        .navbar-brand-sub { font-size: 9px; opacity: 0.45; }
        .desktop-title { display: none !important; }
        .desktop-date { display: none !important; }
        .mobile-title { display: block; }
        .navbar-actions { gap: 6px; }

        .content-area { padding: 10px 12px 28px; }
        .btn-primary, .btn-outline-secondary, .btn-danger { width: 100%; text-align: center; display: block; }
        .btn-primary.btn-lg { padding: 12px 18px; font-size: 14px; }

        /* Session banner */
        .session-banner { flex-direction: column; align-items: stretch; gap: 10px; padding: 12px 14px; }
        .session-banner-info { font-size: 12px; }
        .session-banner form { width: 100%; }
        .session-banner .btn { width: 100%; text-align: center; }

        /* Scan log */
        .scan-log-body { max-height: 400px; }
        .scan-item { padding: 10px 12px; gap: 10px; }
        .scan-avatar { width: 34px; height: 34px; font-size: 13px; }
        .scan-name { font-size: 12px; }

        /* Summary */
        .summary-num { font-size: 18px; }
        .summary-label { font-size: 9px; }

        .row.g-4 { --bs-gutter-x: 12px; --bs-gutter-y: 12px; }
    }

    /* =====================================================
       SMALL PHONE
       ===================================================== */
    @media (max-width: 576px) {
        .top-navbar { padding: 6px 10px; }
        .navbar-brand-logo { width: 38px; height: 38px; }
        .navbar-brand-name { font-size: 11px; }
        .navbar-brand-sub { font-size: 8px; }
        .mobile-title-left h5 { font-size: 15px; }
        .mobile-title-left small { font-size: 11px; }
        .mobile-date { font-size: 10px; padding: 5px 8px; }
        .content-area { padding: 8px 8px 24px; }
        .card-header { padding: 12px 14px; font-size: 13px; }
        .card-body { padding: 14px; }
        .scan-avatar { width: 30px; height: 30px; font-size: 12px; }
        .scan-name { font-size: 11px; }
        .summary-num { font-size: 16px; }
        .sidebar { width: 260px; }
    }

    @media (min-width: 768px) {
        .mobile-title { display: none !important; }
    }
</style>

<?php require_once __DIR__ . '/../includes/pages-topnavbar.php'; ?>
<div class="main-content">

    <!-- ===== CONTENT ===== -->
    <div class="content-area">
        <?= displayFlashMessage() ?>

        <div class="d-none d-md-flex justify-content-between align-items-center gap-3 mb-3">
            <div class="page-title mb-0">
                <h5 class="mb-0">Take Attendance</h5>
                <small>Start a class attendance session with face recognition</small>
            </div>
            <div class="d-flex gap-2">
                <a href="<?= BASE_URL ?>/teacher/records.php" class="btn btn-primary"><i class="bi bi-list-check"></i> <span class="d-none d-sm-inline">View Records</span></a>
            </div>
        </div>

        <div class="page-title mobile-title">
            <div class="mobile-title-inner">
                <div class="mobile-title-left">
                    <h5>Take Attendance</h5>
                    <small>Face recognition scanning</small>
                </div>
                <div class="d-flex gap-2">
                    <a href="<?= BASE_URL ?>/teacher/records.php" class="btn btn-primary"><i class="bi bi-list-check"></i> <span class="d-none d-sm-inline">View Records</span></a>
                </div>
            </div>
        </div>

        <div class="row g-4">
            <!-- Left: Configuration + Scanner -->
            <div class="col-lg-7">
                <?php if (!$activeSession): ?>
                <!-- Session Configuration — paired rows -->
                <div class="card mb-4">
                    <div class="card-header">
                        <i class="bi bi-gear"></i>Session Configuration
                    </div>
                    <div class="card-body">
                        <form method="POST" action="<?= BASE_URL ?>/api/teacher-attendance.php" id="sessionForm">
                            <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                            <input type="hidden" name="action" value="start_session">
                            <div class="row g-3">
                                <!-- Row 1: Subject + Grade Level -->
                                <div class="col-7 col-md-8">
                                    <label class="form-label">Subject <span style="color:var(--td-danger);">*</span></label>
                                    <select name="subject_id" class="form-select" required>
                                        <option value="">Select Subject</option>
                                        <?php foreach ($subjects as $sub): ?>
                                            <option value="<?= $sub['id'] ?>">
                                                <?= sanitize($sub['subject_name']) ?> (<?= sanitize($sub['subject_code']) ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php if (empty($subjects)): ?><small class="text-muted" style="font-size:11px;">No subjects assigned by admin.</small><?php endif; ?>
                                </div>
                                <div class="col-5 col-md-4">
                                    <label class="form-label">Grade <span style="color:var(--td-danger);">*</span></label>
                                    <select name="grade_level" class="form-select" id="gradeSelect" required>
                                        <option value="">Select</option>
                                        <?php foreach ($allSubjectGrades as $g): ?>
                                            <option value="<?= $g ?>">Grade <?= $g ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <!-- Row 2: Section + Time Limit -->
                                <div class="col-7 col-md-8">
                                    <label class="form-label">Section <span style="color:var(--td-danger);">*</span></label>
                                    <select name="section" class="form-select" id="sectionSelect" required>
                                        <option value="">Select Section</option>
                                        <?php foreach ($teacherSections as $sec): ?>
                                            <?php
                                            $secLabel = sanitize($sec['section_name']);
                                            if (!empty($sec['strand_code'])) { $secLabel .= ' (' . sanitize($sec['strand_code']) . ')'; }
                                            elseif (!empty($sec['strand_name'])) { $secLabel .= ' (' . sanitize($sec['strand_name']) . ')'; }
                                            ?>
                                            <option value="<?= sanitize($sec['section_name']) ?>" data-grade="<?= sanitize($sec['grade_level']) ?>">
                                                <?= $secLabel ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php if (empty($teacherSections)): ?><small class="text-muted" style="font-size:11px;">No sections assigned by admin.</small><?php endif; ?>
                                </div>
                                <div class="col-5 col-md-4">
                                    <label class="form-label">Time Limit (min)</label>
                                    <input type="number" class="form-control" name="time_limit"
                                           value="60" min="5" max="180" required>
                                </div>
                                <!-- Row 3: Late Threshold + Start Time -->
                                <div class="col-6 col-md-4">
                                    <label class="form-label">Late After (min)</label>
                                    <input type="number" class="form-control" name="late_threshold"
                                           value="15" min="1" max="60" required>
                                </div>
                                <div class="col-6 col-md-4">
                                    <label class="form-label">Start Time</label>
                                    <input type="time" class="form-control" name="start_time"
                                           value="<?= date('H:i') ?>" required>
                                </div>
                                <!-- Row 4: Button — centered -->
                                <div class="col-12 mt-2 d-flex justify-content-center">
                                    <button type="submit" class="btn btn-primary btn-lg" style="width:100%;max-width:400px;">
                                        <i class="bi bi-play-circle me-1"></i> Start Attendance Session
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
                <?php else: ?>
                <!-- Active Session Banner -->
                <?php
                $sessSubject = null;
                try {
                    $stmt = $db->prepare("SELECT * FROM subjects WHERE id = ?");
                    $stmt->execute([$activeSession['subject_id']]);
                    $sessSubject = $stmt->fetch();
                } catch (Exception $e) {}
                ?>
                <div class="session-banner mb-4" style="background:var(--td-primary-light);border:1px solid rgba(79,70,229,0.25);">
                    <div class="session-banner-left">
                        <div class="session-banner-dot" style="background:var(--td-success);"></div>
                        <div class="session-banner-info">
                            <strong>Session Active</strong>
                            <span>|</span>
                            <?= sanitize($sessSubject['subject_name'] ?? 'Unknown') ?>
                            <span>|</span>
                            Grade <?= $activeSession['grade_level'] ?> - <?= sanitize($activeSession['section']) ?>
                            <span>|</span>
                            Late after <?= $activeSession['late_threshold'] ?> min
                            <span>|</span>
                            <i class="bi bi-clock" style="font-size:12px;"></i> Time left: <strong id="sessionTimer">--:--</strong>
                        </div>
                    </div>
                    <form method="POST" action="<?= BASE_URL ?>/api/teacher-attendance.php" class="m-0" style="flex-shrink:0;">
                        <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                        <input type="hidden" name="action" value="end_session">
                        <input type="hidden" name="session_id" value="<?= $activeSession['id'] ?>">
                        <button type="submit" class="btn btn-danger btn-sm">
                            <i class="bi bi-stop-circle me-1"></i> End Session
                        </button>
                    </form>
                </div>
                <?php endif; ?>

                <!-- Face Scanner — original untouched -->
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-camera-video-fill me-2"></i>Face Recognition Scanner</span>
                        <span class="badge-status badge-<?= $activeSession ? 'present' : 'absent' ?>">
                            <?= $activeSession ? 'Scanning' : 'Waiting' ?>
                        </span>
                    </div>
                    <div class="card-body text-center">
                        <div class="scanner-container mb-3" id="scannerContainer">
                            <video id="classVideo" autoplay playsinline></video>
                            <div class="scanner-overlay"></div>
                            <div class="scanner-status" id="scannerStatus">
                                <span class="pulse-dot"></span>
                                <span id="statusText"><?= $activeSession ? 'Camera Ready' : 'Start a session first' ?></span>
                            </div>
                        </div>

                        <div class="d-flex justify-content-center gap-2 mb-3">
<?php if ($activeSession): ?>
                            <button type="button" class="btn btn-primary btn-lg" id="startScanBtn" onclick="startClassScan()">
                                <i class="bi bi-camera-video"></i> Start Scanning
                            </button>
                            <button type="button" class="btn btn-danger d-none" id="stopScanBtn" onclick="stopClassScan()">
                                <i class="bi bi-stop-fill"></i> Stop
                            </button>
                            <button type="button" class="btn btn-outline-secondary btn-lg d-flex align-items-center gap-2 mb-0 active" id="voiceToggleBtn" title="Toggle voice announcement">
                                <i class="bi bi-volume-up" id="voiceToggleIcon"></i>
                                <span class="d-none d-sm-inline">Voice</span>
                            </button>
                            <?php else: ?>
                            <button type="button" class="btn btn-secondary btn-lg" disabled>
                                <i class="bi bi-camera-video-off"></i> Start a session first
                            </button>
                            <?php endif; ?>
                        </div>

                        <!-- Alternative Options -->
                        <div class="border-top pt-3 mt-2">
                            <div class="d-flex gap-2">
                                <button type="button" class="btn btn-outline-warning flex-grow-1" onclick="openManualAttendanceModal()" <?= !$activeSession ? 'disabled' : '' ?>>
                                    <i class="bi bi-keyboard"></i> Manual LRN Input
                                </button>
                            </div>
                            <small class="text-muted d-block mt-2">
                                <i class="bi bi-info-circle"></i> Use manual input if face recognition fails
                            </small>
                        </div>

                        <!-- Recognition Result -->
                        <div id="recognitionResult" class="d-none">
                            <div class="alert alert-success" id="successResult" style="display:none;">
                                <i class="bi bi-check-circle-fill" style="font-size:24px;"></i>
                                <h5 class="mt-2 mb-1" id="matchedName">-</h5>
                                <p class="mb-0" id="matchedInfo">-</p>
                                <small class="text-muted">Confidence: <span id="confidence">-</span>%</small>
                                <p class="mb-0 mt-2 text-success"><strong>Attendance recorded successfully</strong></p>
                            </div>
                            <div class="alert alert-danger" id="failedResult" style="display:none;">
                                <i class="bi bi-x-circle-fill" style="font-size:24px;"></i>
                                <h6 class="mt-2 mb-0" id="failedTitle">Face Not Recognized</h6>
                                <small id="failedDesc">Student may not be registered</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right: Live Scan Log -->
            <div class="col-lg-5">
                <div class="card" style="height:100%;">
                    <div class="card-header">
                        <div class="scan-log-header">
                            <span><i class="bi bi-list-check me-2"></i>Attendance Log</span>
                            <span class="scan-log-count" style="background:var(--td-primary-light);color:var(--td-primary);" id="scanCount"><?= count($sessionRoster) ?> enrolled</span>
                        </div>
                    </div>

                    <?php if ($activeSession && count($sessionRoster) > 0): ?>
                    <?php
                    $pCount = $lCount = $aCount = $pendCount = 0;
                    foreach ($sessionRoster as $r) {
                        $st = $r['att_status'] ?: 'pending';
                        if ($st === 'present') $pCount++;
                        elseif ($st === 'late') $lCount++;
                        elseif ($st === 'absent') $aCount++;
                        else $pendCount++;
                    }
                    ?>
                    <div class="card-footer" style="background:rgba(255,255,255,0.04);">
                        <div class="session-summary">
                            <div class="summary-item" style="background:var(--td-success-light);">
                                <div class="summary-num" style="color:var(--td-success);" id="sumPresent"><?= $pCount ?></div>
                                <div class="summary-label" style="color:var(--td-success);">Present</div>
                            </div>
                            <div class="summary-item" style="background:var(--td-warning-light);">
                                <div class="summary-num" style="color:var(--td-warning);" id="sumLate"><?= $lCount ?></div>
                                <div class="summary-label" style="color:var(--td-warning);">Late</div>
                            </div>
                            <div class="summary-item" style="background:var(--td-danger-light);">
                                <div class="summary-num" style="color:var(--td-danger);" id="sumAbsent"><?= $aCount ?></div>
                                <div class="summary-label" style="color:var(--td-danger);">Absent</div>
                            </div>
                            <div class="summary-item" style="background:rgba(255,255,255,0.06);">
                                <div class="summary-num" style="color:rgba(255,255,255,0.5);" id="sumPending"><?= $pendCount ?></div>
                                <div class="summary-label" style="color:rgba(255,255,255,0.5);">Pending</div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="scan-log-body" style="max-height:520px;">
                        <div id="liveScanList">
                            <?php if (empty($sessionRoster)): ?>
                                <div class="empty-log">
                                    <div class="empty-log-icon" style="background:rgba(255,255,255,0.05);">
                                        <i class="bi bi-person-check" style="opacity:0.4;"></i>
                                    </div>
                                    <p style="opacity:0.5;">No enrolled students for this session</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($sessionRoster as $rec): ?>
                                    <?php
                                        $curStatus    = $rec['att_status'] ?: 'pending';
                                        $hasRemark    = $curStatus !== 'pending';
                                        $statusColor  = $curStatus === 'present' ? 'success' : ($curStatus === 'late' ? 'warning' : 'danger');
                                        $statusIcon   = $curStatus === 'present' ? 'check' : ($curStatus === 'late' ? 'clock' : 'x');
                                        $attTime      = !empty($rec['att_time']) ? date('h:i A', strtotime($rec['att_time'])) : '';
                                    ?>
                                    <div class="scan-item" data-student-id="<?= sanitize($rec['sid']) ?>" data-status="<?= $curStatus ?>">
                                        <div class="scan-avatar" style="background:<?= $hasRemark ? 'var(--td-' . $statusColor . '-light)' : 'rgba(255,255,255,0.06)' ?>;color:<?= $hasRemark ? 'var(--td-' . $statusColor . ')' : 'rgba(255,255,255,0.4)' ?>;">
                                            <i class="bi bi-<?= $hasRemark ? $statusIcon : 'person' ?>"></i>
                                        </div>
                                        <div class="scan-info">
                                            <div class="scan-name"><?= sanitize($rec['first_name'] . ' ' . $rec['last_name']) ?></div>
                                            <div class="scan-meta" style="opacity:0.5;"><?= sanitize($rec['sid']) ?></div>
                                        </div>
                                        <div style="text-align:right;">
                                            <?php if ($hasRemark): ?>
                                                <span class="badge-status badge-<?= $curStatus ?>"><?= ucfirst($curStatus) ?></span>
                                                <div class="scan-time" style="opacity:0.4;"><?= $attTime ?></div>
                                                <button type="button" class="btn btn-icon btn-sm btn-outline-primary mt-1 edit-status-btn" data-sid="<?= sanitize($rec['sid']) ?>" data-name="<?= sanitize($rec['first_name'] . ' ' . $rec['last_name']) ?>" data-status="<?= $curStatus ?>" title="Edit status" style="width:26px;height:26px;font-size:11px;padding:0;">
                                                    <i class="bi bi-pencil"></i>
                                                </button>
                                            <?php else: ?>
                                                <span class="scan-time" style="opacity:0.35;">Pending</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>


<!-- Modal: Manual LRN Input -->
<div class="event-modal-overlay" id="manualAttendanceModal">
    <div class="event-modal">
        <div class="event-modal-header">
            <div class="event-modal-title"><i class="bi bi-keyboard"></i><span>Manual Attendance</span></div>
            <button class="event-modal-close" id="manualAttendanceClose"><i class="bi bi-x-lg"></i></button>
        </div>
        <form id="manualAttendanceForm" onsubmit="submitManualAttendance(event)">
            <div class="event-modal-body">
                <p style="font-size:13px;opacity:0.6;margin-bottom:12px">Enter student LRN manually if face recognition is not working.</p>
                <div class="evt-field">
                    <label>Student LRN <span class="required">*</span></label>
                    <input type="text" id="manualLrn" placeholder="113400000001" required autofocus inputmode="numeric" maxlength="12">
                </div>
                <input type="hidden" id="manualSessionId" value="<?= $activeSession['id'] ?? '' ?>">
                <div id="manualMessage"></div>
            </div>
            <div class="event-modal-footer">
                <button type="button" class="evt-btn evt-btn-cancel" id="manualAttendanceCancel">Cancel</button>
                <button type="submit" class="evt-btn evt-btn-save" id="manualAttendanceSave"><i class="bi bi-check-circle me-1"></i> Submit Attendance</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Session Auto-End Notification -->
<div class="event-modal-overlay" id="sessionAutoEndOverlay">
    <div class="event-modal">
        <div class="event-modal-header">
            <div class="event-modal-title"><i class="bi bi-exclamation-triangle-fill" style="color:var(--td-warning);"></i><span>Session Auto-End Warning</span></div>
        </div>
        <div class="event-modal-body">
            <p style="font-size:13px;opacity:0.8;margin-bottom:8px">The session auto-end threshold has been exceeded.</p>
            <p style="font-size:13px;opacity:0.8;margin-bottom:12px">All unmarked students will be marked as absent.</p>
            <div style="background:var(--td-warning-light);border-radius:var(--td-radius-sm);padding:12px 16px;text-align:center;">
                <span style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;opacity:0.7;">Session will end automatically in</span>
                <div id="autoEndCountdown" style="font-size:28px;font-weight:800;font-family:var(--td-mono);color:var(--td-warning);margin-top:4px;">60</div>
                <span style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;opacity:0.7;">seconds</span>
            </div>
        </div>
        <div class="event-modal-footer">
            <button type="button" class="evt-btn evt-btn-save" id="confirmAutoEndBtn" style="background:var(--td-warning);color:#fff;"><i class="bi bi-check-circle me-1"></i> OK — End Session Now</button>
        </div>
    </div>
</div>

<!-- Modal: Edit Attendance Status -->
<div class="event-modal-overlay" id="editStatusOverlay">
    <div class="event-modal">
        <div class="event-modal-header">
            <div class="event-modal-title"><i class="bi bi-pencil-square"></i><span>Edit Attendance Status</span></div>
            <button class="event-modal-close" id="editStatusClose"><i class="bi bi-x-lg"></i></button>
        </div>
        <form id="editStatusForm" onsubmit="submitEditStatus(event)">
            <div class="event-modal-body">
                <input type="hidden" id="editStatusStudentId">
                <input type="hidden" id="editStatusStudentName">
                <p style="font-size:13px;opacity:0.6;margin-bottom:12px">Update attendance status for <strong id="editStatusNameDisplay"></strong></p>
                <div class="evt-field">
                    <label>Status <span class="required">*</span></label>
                    <select id="editStatusSelect" required>
                        <option value="present">Present</option>
                        <option value="late">Late</option>
                        <option value="absent">Absent</option>
                        <option value="pending">Pending</option>
                    </select>
                </div>
                <div id="editStatusMessage"></div>
            </div>
            <div class="event-modal-footer">
                <button type="button" class="evt-btn evt-btn-cancel" id="editStatusCancel">Cancel</button>
                <button type="submit" class="evt-btn evt-btn-save" id="editStatusSave"><i class="bi bi-check-circle me-1"></i> Save Changes</button>
            </div>
        </form>
    </div>
</div>

<?php if ($activeSession): ?>
<script>
    let scanningInterval = null;
    let isScanning = false;
    let liveness = null;
    const sessionId = <?= $activeSession['id'] ?>;
    const scannedStudents = new Set();
    const statusColorMap = { present: 'success', late: 'warning', absent: 'danger', pending: '' };
    const statusIconMap  = { present: 'check', late: 'clock', absent: 'x', pending: 'person' };

    /* Voice announcement toggle — speak once per student when detected */
    let voiceEnabled = true;
    const voiceToggleBtn = document.getElementById('voiceToggleBtn');
    const voiceToggleIcon = document.getElementById('voiceToggleIcon');
    if (voiceToggleBtn) {
        voiceToggleBtn.addEventListener('click', function () {
            voiceEnabled = !voiceEnabled;
            if (voiceToggleIcon) {
                voiceToggleIcon.className = voiceEnabled ? 'bi bi-volume-up' : 'bi bi-volume-mute';
            }
            this.classList.toggle('active', voiceEnabled);
            this.classList.toggle('btn-outline-secondary', voiceEnabled);
            this.classList.toggle('btn-outline-warning', !voiceEnabled);
        });
    }

    let sessionCheckInterval = null;

    function checkSessionStatus() {
        if (!sessionId) return;
        const formData = new FormData();
        formData.append('action', 'check_session_status');
        formData.append('session_id', sessionId);
        const csrf = document.querySelector('meta[name="csrf-token"]');
        if (csrf) formData.append('csrf_token', csrf.getAttribute('content'));

        fetch(window.BASE_URL + '/api/teacher-attendance.php', {
            method: 'POST',
            body: formData
        })
        .then(r => r.json())
        .then(data => {
            if (data.success && data.auto_end_pending) {
                if (scanningInterval) { clearInterval(scanningInterval); scanningInterval = null; }
                if (sessionCheckInterval) { clearInterval(sessionCheckInterval); sessionCheckInterval = null; }
                isScanning = false;
                if (liveness) { liveness.stop(); liveness = null; }
                showAutoEndModal(sessionId);
            }
        })
        .catch(() => {});
    }

    if (sessionId) {
        sessionCheckInterval = setInterval(checkSessionStatus, 10000);
    }

    function playConfirmationBeep() {
        try {
            const ctx = new (window.AudioContext || window.webkitAudioContext)();
            const osc = ctx.createOscillator();
            const gain = ctx.createGain();
            osc.connect(gain);
            gain.connect(ctx.destination);
            osc.frequency.value = 880;
            osc.type = 'sine';
            gain.gain.value = 0.08;
            osc.start();
            setTimeout(() => { try { osc.stop(); } catch(e){} }, 120);
        } catch (e) {}
    }

    function announce(text) {
        if (!voiceEnabled) return;
        if ('speechSynthesis' in window) {
            window.speechSynthesis.cancel();
            const utterance = new SpeechSynthesisUtterance(text);
            utterance.lang = 'en-US';
            utterance.rate = 0.9;
            window.speechSynthesis.speak(utterance);
        }
        playConfirmationBeep();
    }

    // Unlock browser audio/speech on first user interaction
    document.addEventListener('click', function unlockAudio() {
        if ('speechSynthesis' in window) {
            window.speechSynthesis.cancel();
        }
        playConfirmationBeep();
    }, { once: true });

    /* Render the full enrolled roster as a JS array so the UI can be rebuilt
       (detected students moved to the top, undetected kept at the bottom). */
    let roster = [];
    (function initRoster() {
        const items = document.querySelectorAll('#liveScanList .scan-item[data-student-id]');
        items.forEach(function (el) {
            roster.push({
                id: el.getAttribute('data-student-id'),
                name: el.querySelector('.scan-name').textContent.trim(),
                meta: el.querySelector('.scan-meta').textContent.trim(),
                status: el.getAttribute('data-status') || 'pending',
                time: el.querySelector('.scan-time').textContent.trim()
            });
        });
    })();

    function renderRoster() {
        const list = document.getElementById('liveScanList');
        list.innerHTML = '';
        if (roster.length === 0) {
            list.innerHTML = `<div class="empty-log">
                <div class="empty-log-icon" style="background:rgba(255,255,255,0.05);">
                    <i class="bi bi-person-check" style="opacity:0.4;"></i>
                </div>
                <p style="opacity:0.5;">No enrolled students for this session</p>
            </div>`;
            return;
        }
        roster.forEach(function (stu) {
            const isPending = (stu.status === 'pending');
            const isEditable = !isPending;
            const color = statusColorMap[stu.status] || 'danger';
            const icon  = statusIconMap[stu.status] || 'x';
            const label = stu.status.charAt(0).toUpperCase() + stu.status.slice(1);
            const div = document.createElement('div');
            div.className = 'scan-item' + (stu.justScanned ? ' scan-item-new' : '') + (isEditable ? ' scan-item-editable' : '');
            div.setAttribute('data-student-id', stu.id);
            div.setAttribute('data-status', stu.status);
            div.style.cursor = 'default';
            div.title = '';
            div.innerHTML = `
                <div class="scan-avatar" style="background:${isPending ? 'rgba(255,255,255,0.06)' : 'var(--td-' + color + '-light)'};color:${isPending ? 'rgba(255,255,255,0.4)' : 'var(--td-' + color + ')'};">
                    <i class="bi bi-${isPending ? 'person' : icon}"></i>
                </div>
                <div class="scan-info">
                    <div class="scan-name">${stu.name}</div>
                    <div class="scan-meta" style="opacity:0.5;">${stu.meta}</div>
                </div>
                <div style="text-align:right;">
                    ${isPending
                        ? '<span class="scan-time" style="opacity:0.35;">Pending</span>'
                        : '<span class="badge-status badge-' + stu.status + '">' + label + '</span>' +
                          '<div class="scan-time" style="opacity:0.4;">' + stu.time + '</div>' +
                          '<button type="button" class="btn btn-icon btn-sm btn-outline-primary mt-1 edit-status-btn" data-sid="' + stu.id + '" data-name="' + stu.name + '" data-status="' + stu.status + '" title="Edit status" style="width:26px;height:26px;font-size:11px;padding:0;display:inline-flex;align-items:center;justify-content:center;vertical-align:middle;">' +
                            '<i class="bi bi-pencil"></i>' +
                          '</button>'}
                </div>`;
            list.appendChild(div);

            const editBtn = div.querySelector('.edit-status-btn');
            if (editBtn) {
                editBtn.addEventListener('click', function (e) {
                    e.stopPropagation();
                    openEditStatusModal(stu.id, stu.name, stu.status);
                });
            }
        });
        updateSummary();
    }

    function updateSummary() {
        let p = 0, l = 0, a = 0, pend = 0;
        roster.forEach(function (stu) {
            if (stu.status === 'present') p++;
            else if (stu.status === 'late') l++;
            else if (stu.status === 'absent') a++;
            else pend++;
        });
        const presentEl = document.getElementById('sumPresent');
        const lateEl = document.getElementById('sumLate');
        const absentEl = document.getElementById('sumAbsent');
        const pendingEl = document.getElementById('sumPending');
        const countEl = document.getElementById('scanCount');
        if (presentEl) presentEl.textContent = p;
        if (lateEl) lateEl.textContent = l;
        if (absentEl) absentEl.textContent = a;
        if (pendingEl) pendingEl.textContent = pend;
        if (countEl) countEl.textContent = roster.length + ' enrolled';
    }

    function onLivenessStatus(status, isLive) {
        const el = document.getElementById('statusText');
        if (!el || !isScanning) return;
        if (status === 'no_face') {
            el.textContent = 'Look at the camera...';
        } else if (status === 'awaiting_blink') {
            el.textContent = '👁️ Please blink to verify you are present';
        } else if (isLive) {
            el.textContent = 'Verified live — scanning...';
        }
    }

    async function startClassScan() {
        try {
            await startCamera('classVideo');
            document.getElementById('startScanBtn').classList.add('d-none');
            document.getElementById('stopScanBtn').classList.remove('d-none');
            isScanning = true;

            liveness = new Liveness({
                video: document.getElementById('classVideo'),
                onStatus: onLivenessStatus
            });
            await liveness.start();

            scanningInterval = setInterval(autoClassScan, 3000);
            document.getElementById('statusText').textContent = '👁️ Please blink to verify you are present';
        } catch (e) {
            showAlertModal('Camera access denied.', { title: 'Camera Error', icon: 'camera-video-off-fill', type: 'danger' });
        }
    }

    function stopClassScan() {
        stopCamera();
        if (scanningInterval) clearInterval(scanningInterval);
        if (liveness) { liveness.stop(); liveness = null; }
        isScanning = false;
        document.getElementById('startScanBtn').classList.remove('d-none');
        document.getElementById('stopScanBtn').classList.add('d-none');
        document.getElementById('statusText').textContent = 'Camera stopped';
    }

    async function autoClassScan() {
        if (!isScanning) return;

        // Anti-spoofing: require a verified live person (blink + motion)
        // before sending a frame. Blocks photos / ID pictures.
        if (liveness && !liveness.isLive()) {
            return;
        }

        const imageBase64 = captureFrameInZone('classVideo', 0.15);
        if (!imageBase64) return;
        try {
            const formData = new FormData();
            formData.append('action', 'recognize_class');
            formData.append('image', imageBase64);
            formData.append('session_id', sessionId);
            const csrf = document.querySelector('meta[name="csrf-token"]');
            if (csrf) formData.append('csrf_token', csrf.getAttribute('content'));
            const response = await fetch(window.BASE_URL + '/api/teacher-attendance.php', {
                method: 'POST',
                body: formData
            });
            const data = await response.json();
            showClassResult(data);
        } catch (e) {
            console.error('Scan error:', e);
        }
    }

    function showClassResult(data) {
        console.log('showClassResult:', data);
        const resultDiv = document.getElementById('recognitionResult');
        const successDiv = document.getElementById('successResult');
        const failedDiv = document.getElementById('failedResult');
        resultDiv.classList.remove('d-none');

        if (data.success && data.matched) {
            successDiv.style.display = 'block';
            failedDiv.style.display = 'none';
            document.getElementById('matchedName').textContent = data.student_name || 'Unknown';
            document.getElementById('matchedInfo').textContent =
                `${data.student_id || ''} | Grade ${data.grade_level || ''}`;
            document.getElementById('confidence').textContent = data.confidence || '0';
            
            if (data.updated) {
                const successText = successDiv.querySelector('strong');
                if (successText) successText.textContent = 'Status Updated from Pending';
            }
            
            addClassScanToLog(data);
            // Require a fresh blink before the next student is recorded.
            if (liveness) liveness.reset();
        } else {
            successDiv.style.display = 'none';
            failedDiv.style.display = 'block';
            const errorMsg = data.error || data.message || 'Face not recognized';
            document.getElementById('failedTitle').textContent = 'Recognition Failed';
            const failedDesc = document.getElementById('failedDesc');
            if (failedDesc) {
                let fullMsg = errorMsg;
                if (data.quality_warnings && data.quality_warnings.length > 0) {
                    fullMsg += ' | ' + data.quality_warnings.join(' | ');
                }
                failedDesc.textContent = fullMsg;
            }
        }
        setTimeout(() => resultDiv.classList.add('d-none'), 3000);
    }

    function addClassScanToLog(data) {
        const studentId = String(data.student_id);
        console.log('addClassScanToLog:', { studentId, updated: data.updated, duplicate: data.duplicate, status: data.status });
        if (!data.updated && (data.duplicate || scannedStudents.has(studentId))) {
            console.log('Early return - duplicate or already scanned');
            return;
        }
        scannedStudents.add(studentId);

        const status = data.status || 'present';
        const now = new Date().toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });

        let idx = roster.findIndex(s => s.id === String(data.student_id));
        console.log('Roster findIndex:', idx, 'roster length:', roster.length);
        if (idx === -1) {
            console.log('Pushing new roster entry');
            roster.push({
                id: String(data.student_id),
                name: data.student_name || 'Unknown',
                meta: data.student_id || '',
                status: status,
                time: now,
                justScanned: true
            });
        } else {
            const existing = roster[idx];
            console.log('Existing status:', existing.status, 'data.updated:', data.updated);
            if (existing.status === 'pending' || data.updated) {
                console.log('Updating roster entry');
                const stu = roster.splice(idx, 1)[0];
                stu.status = status;
                stu.time = now;
                stu.justScanned = true;
                roster.unshift(stu);
            } else {
                console.log('NOT updating - status is', existing.status, 'and data.updated is', data.updated);
            }
        }
        renderRoster();

        // Speak once per detected student (only if voice toggle is on)
        announce((data.student_name || 'Student') + ' marked ' + status);
    }

    // ============================================
    // MANUAL LRN INPUT FUNCTIONS
    // ============================================
    async function submitManualAttendance(event) {
        event.preventDefault();

        const studentId = document.getElementById('manualLrn').value.trim();
        const sessionId = document.getElementById('manualSessionId').value;
        const msg = document.getElementById('manualMessage');

        if (!studentId) {
            msg.innerHTML = '<div class="alert alert-danger">Please enter a student LRN.</div>';
            return;
        }

        msg.innerHTML = '<div class="alert alert-info"><span class="spinner-border spinner-border-sm"></span> Processing...</div>';

        try {
            const formData = new FormData();
            formData.append('csrf_token', document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '');
            formData.append('action', 'manual_attendance');
            formData.append('student_id', studentId);
            formData.append('session_id', sessionId);

            const response = await fetch(window.BASE_URL + '/api/teacher-attendance.php', {
                method: 'POST',
                credentials: 'same-origin',
                body: formData
            });

            const contentType = response.headers.get('content-type');
            if (!contentType || !contentType.includes('application/json')) {
                throw new Error('Server returned non-JSON response. Status: ' + response.status);
            }

            const data = await response.json();

            if (data.success) {
                if (data.duplicate && !data.updated) {
                    msg.innerHTML = `
                        <div class="alert alert-warning">
                            <div class="d-flex align-items-center mb-2">
                                <i class="bi bi-exclamation-triangle-fill me-2" style="font-size:20px;"></i>
                                <strong>Already Recorded Today</strong>
                            </div>
                            <div class="ps-4">
                                <div class="fw-bold">${data.student_name || 'Unknown Student'}</div>
                                <small class="text-muted">
                                    LRN: ${data.student_id || ''} | Grade ${data.grade_level || ''}<br>
                                    Status: ${data.status === 'late' ? '⏰ Late' : '✅ Present'}
                                </small>
                            </div>
                        </div>`;
                } else {
                    msg.innerHTML = `
                        <div class="alert alert-success">
                            <div class="d-flex align-items-center mb-2">
                                <i class="bi bi-check-circle-fill me-2" style="font-size:20px;"></i>
                                <strong>${data.updated ? 'Status Updated!' : 'Attendance Recorded!'}</strong>
                            </div>
                            <div class="ps-4">
                                <div class="fw-bold">${data.student_name || 'Unknown Student'}</div>
                                <small class="text-muted">
                                    LRN: ${data.student_id || ''} | Grade ${data.grade_level || ''}<br>
                                    Status: ${data.status === 'late' ? '⏰ Late' : '✅ Present'}
                                </small>
                            </div>
                        </div>`;

                    // Update roster log
                    addManualScanToLog(data);

                    document.getElementById('manualLrn').value = '';

                    setTimeout(() => {
                        const modal = document.getElementById('manualAttendanceModal');
                        if (modal) {
                            modal.classList.remove('show');
                            document.body.style.overflow = '';
                        }
                        msg.innerHTML = '';
                    }, 2000);
                }
            } else {
                msg.innerHTML = '<div class="alert alert-danger"><i class="bi bi-exclamation-circle"></i> ' + (data.error || data.message || 'Failed to record attendance') + '</div>';
            }
        } catch (e) {
            msg.innerHTML = '<div class="alert alert-danger">Network error. Please try again.</div>';
        }
    }

    function addManualScanToLog(data) {
        const now = new Date().toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });

        // Find the student in the roster
        let idx = roster.findIndex(s => s.id === String(data.student_id));
        if (idx === -1) {
            roster.push({
                id: String(data.student_id),
                name: data.student_name || 'Unknown',
                meta: data.student_id || '',
                status: data.status || 'present',
                time: now,
                justScanned: true
            });
        } else {
            const existing = roster[idx];
            if (existing.status === 'pending' || data.updated) {
                const stu = roster.splice(idx, 1)[0];
                stu.status = data.status || 'present';
                stu.time = now;
                stu.justScanned = true;
                roster.unshift(stu);
            }
        }
        renderRoster();

        // Update summary
        updateSummary();

        // Announce
        announce((data.student_name || 'Student') + ' marked ' + (data.status || 'present'));
    }

    function openManualAttendanceModal() {
        const modal = document.getElementById('manualAttendanceModal');
        if (modal) {
            modal.classList.add('show');
            document.body.style.overflow = 'hidden';
            const input = document.getElementById('manualLrn');
            if (input) {
                input.value = '';
                setTimeout(() => input.focus(), 200);
            }
            document.getElementById('manualMessage').innerHTML = '';
        }
    }

    var manualAttendanceModal = document.getElementById('manualAttendanceModal');
    manualAttendanceModal.addEventListener('click', function (e) {
        if (e.target === manualAttendanceModal) {
            manualAttendanceModal.classList.remove('show');
            document.body.style.overflow = '';
        }
    });
    document.getElementById('manualAttendanceClose').addEventListener('click', function () {
        manualAttendanceModal.classList.remove('show');
        document.body.style.overflow = '';
        document.getElementById('manualLrn').value = '';
        document.getElementById('manualMessage').innerHTML = '';
    });
    document.getElementById('manualAttendanceCancel').addEventListener('click', function () {
        manualAttendanceModal.classList.remove('show');
        document.body.style.overflow = '';
        document.getElementById('manualLrn').value = '';
        document.getElementById('manualMessage').innerHTML = '';
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && manualAttendanceModal.classList.contains('show')) {
            manualAttendanceModal.classList.remove('show');
            document.body.style.overflow = '';
            document.getElementById('manualLrn').value = '';
            document.getElementById('manualMessage').innerHTML = '';
        }
    });

    // ============================================
    // SESSION AUTO-END MODAL
    // ============================================
    let autoEndTimer = null;
    let autoEndCountdownInterval = null;

    function showAutoEndModal(sessionId) {
        const overlay = document.getElementById('sessionAutoEndOverlay');
        if (!overlay) return;
        overlay.classList.add('show');
        document.body.style.overflow = 'hidden';

        const countdownEl = document.getElementById('autoEndCountdown');
        let secondsLeft = 60;

        if (countdownEl) countdownEl.textContent = secondsLeft;

        autoEndCountdownInterval = setInterval(function() {
            secondsLeft--;
            if (countdownEl) countdownEl.textContent = secondsLeft;
            if (secondsLeft <= 0) {
                clearInterval(autoEndCountdownInterval);
                autoEndCountdownInterval = null;
                confirmAutoEnd(sessionId);
            }
        }, 1000);

        const confirmBtn = document.getElementById('confirmAutoEndBtn');
        if (confirmBtn) {
            confirmBtn.onclick = function() {
                if (autoEndCountdownInterval) { clearInterval(autoEndCountdownInterval); autoEndCountdownInterval = null; }
                confirmAutoEnd(sessionId);
            };
        }
    }

    function hideAutoEndModal() {
        const overlay = document.getElementById('sessionAutoEndOverlay');
        if (overlay) overlay.classList.remove('show');
        document.body.style.overflow = '';
        if (autoEndCountdownInterval) { clearInterval(autoEndCountdownInterval); autoEndCountdownInterval = null; }
    }

    function confirmAutoEnd(sessionId) {
        hideAutoEndModal();
        const formData = new FormData();
        formData.append('csrf_token', document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '');
        formData.append('action', 'confirm_auto_end');
        formData.append('session_id', sessionId);

        fetch(window.BASE_URL + '/api/teacher-attendance.php', {
            method: 'POST',
            body: formData
        })
        .then(r => r.json())
        .then(data => {
            window.location.reload();
        })
        .catch(() => {
            window.location.reload();
        });
    }

    // ============================================
    // SESSION TIMER
    // ============================================
    const sessionEndTime = new Date('<?= date('c', strtotime($activeSession['end_time'] . ' + ' . $activeSession['late_threshold'] . ' minutes')) ?>').getTime();

    function updateSessionTimer() {
        const now = new Date().getTime();
        const distance = sessionEndTime - now;

        const timerEl = document.getElementById('sessionTimer');
        if (!timerEl) return;

        if (distance <= 0) {
            timerEl.textContent = '00:00';
            timerEl.style.color = 'var(--td-danger)';
            return;
        }

        const totalSeconds = Math.floor(distance / 1000);
        const minutes = Math.floor(totalSeconds / 60);
        const seconds = totalSeconds % 60;

        timerEl.textContent = String(minutes).padStart(2, '0') + ':' + String(seconds).padStart(2, '0');

        if (distance < 60000) {
            timerEl.style.color = 'var(--td-danger)';
        } else if (distance < 300000) {
            timerEl.style.color = 'var(--td-warning)';
        } else {
            timerEl.style.color = 'var(--td-success)';
        }
    }

    let sessionTimerInterval = null;
    if (sessionEndTime) {
        updateSessionTimer();
        sessionTimerInterval = setInterval(updateSessionTimer, 1000);
    }

    // ============================================
    // EDIT ATTENDANCE STATUS
    // ============================================

    function openEditStatusModal(studentId, studentName, currentStatus) {
        const overlay = document.getElementById('editStatusOverlay');
        if (!overlay) return;

        document.getElementById('editStatusStudentId').value = studentId;
        document.getElementById('editStatusStudentName').value = studentName;
        document.getElementById('editStatusNameDisplay').textContent = studentName;
        document.getElementById('editStatusSelect').value = currentStatus;
        document.getElementById('editStatusMessage').innerHTML = '';

        overlay.classList.add('show');
        document.body.style.overflow = 'hidden';
    }

    function hideEditStatusModal() {
        const overlay = document.getElementById('editStatusOverlay');
        if (overlay) overlay.classList.remove('show');
        document.body.style.overflow = '';
    }

    function submitEditStatus(event) {
        event.preventDefault();

        const studentId = document.getElementById('editStatusStudentId').value;
        const studentName = document.getElementById('editStatusStudentName').value;
        const newStatus = document.getElementById('editStatusSelect').value;
        const msgEl = document.getElementById('editStatusMessage');

        if (!studentId || !newStatus) {
            msgEl.innerHTML = '<div class="alert alert-danger">Invalid parameters.</div>';
            return;
        }

        msgEl.innerHTML = '<div class="alert alert-info"><span class="spinner-border spinner-border-sm"></span> Updating...</div>';

        const formData = new FormData();
        formData.append('csrf_token', document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '');
        formData.append('action', 'update_status');
        formData.append('session_id', <?= $activeSession['id'] ?>);
        formData.append('student_id', studentId);
        formData.append('status', newStatus);

        fetch(window.BASE_URL + '/api/teacher-attendance.php', {
            method: 'POST',
            body: formData
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                msgEl.innerHTML = '<div class="alert alert-success">Status updated successfully.</div>';

                const stu = roster.find(function(s) { return s.id === studentId; });
                if (stu) {
                    stu.status = newStatus;
                    stu.time = new Date().toLocaleTimeString('en-US', {hour:'2-digit', minute:'2-digit'});
                }
                if (newStatus === 'pending') {
                    scannedStudents.delete(String(studentId));
                }
                renderRoster();

                setTimeout(function() {
                    hideEditStatusModal();
                }, 800);
            } else {
                msgEl.innerHTML = '<div class="alert alert-danger">' + (data.error || 'Failed to update status.') + '</div>';
            }
        })
        .catch(function() {
            msgEl.innerHTML = '<div class="alert alert-danger">Network error. Please try again.</div>';
        });
    }

    document.getElementById('editStatusClose').addEventListener('click', hideEditStatusModal);
    document.getElementById('editStatusCancel').addEventListener('click', hideEditStatusModal);
    document.getElementById('editStatusOverlay').addEventListener('click', function (e) {
        if (e.target === this) hideEditStatusModal();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && document.getElementById('editStatusOverlay').classList.contains('show')) {
            hideEditStatusModal();
        }
    });

    // Server-rendered edit buttons (initial page load)
    document.querySelectorAll('.edit-status-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const sid = this.getAttribute('data-sid');
            const name = this.getAttribute('data-name');
            const status = this.getAttribute('data-status');
            openEditStatusModal(sid, name, status);
        });
    });
    </script>
    <?php endif; ?>

<script>
(function() {
    var subjectSelect = document.querySelector('select[name="subject_id"]');
    var gradeSelect = document.getElementById('gradeSelect');
    var sectionSelect = document.getElementById('sectionSelect');
    if (!gradeSelect || !sectionSelect) return;

    var subjectGradesMap = <?= json_encode($subjectGradesMap) ?>;

    function filterGrades() {
        var sid = subjectSelect ? subjectSelect.value : '';
        var allowed = (sid && subjectGradesMap[sid]) ? subjectGradesMap[sid] : [];
        Array.prototype.forEach.call(gradeSelect.options, function(opt) {
            if (opt.value === '') return;
            var show = allowed.length === 0 || allowed.indexOf(parseInt(opt.value, 10)) !== -1;
            opt.style.display = show ? '' : 'none';
            opt.disabled = !show;
        });
        if (gradeSelect.value && gradeSelect.selectedOptions.length && gradeSelect.selectedOptions[0].disabled) {
            gradeSelect.value = '';
        }
    }

    function filterSections() {
        var grade = gradeSelect.value;
        Array.prototype.forEach.call(sectionSelect.options, function(opt) {
            if (opt.value === '') return;
            var g = opt.getAttribute('data-grade');
            var match = (grade === '' || g === grade);
            opt.style.display = match ? '' : 'none';
            opt.disabled = !match;
        });
        if (sectionSelect.value && sectionSelect.selectedOptions.length && sectionSelect.selectedOptions[0].disabled) {
            sectionSelect.value = '';
        }
    }

    if (subjectSelect) {
        subjectSelect.addEventListener('change', function() {
            filterGrades();
            filterSections();
        });
    }
    gradeSelect.addEventListener('change', filterSections);

    filterGrades();
    filterSections();
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>