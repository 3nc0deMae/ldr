<?php
require_once __DIR__ . '/../config.php';
requireRole(['admin', 'teacher']);

$pageTitle = 'Teacher Reports';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

$teacherId = getCurrentUserId();

$teacherName = '';
try {
    $teacher = $db->prepare("SELECT * FROM teachers WHERE user_id = ?");
    $teacher->execute([$teacherId]);
    $teacher = $teacher->fetch();
    if ($teacher) {
        $teacherName = $teacher['first_name'] ?? '';
        if (!empty($teacher['middle_name'])) {
            $teacherName .= ' ' . strtoupper($teacher['middle_name'][0]) . '.';
        }
        $teacherName .= ' ' . ($teacher['last_name'] ?? '');
    }
} catch (Exception $e) {}
if (empty($teacherName)) $teacherName = 'ANGELYN S. PARRABA';

$dateFrom = sanitize($_GET['date_from'] ?? date('Y-m-01'));
$dateTo   = sanitize($_GET['date_to'] ?? date('Y-m-d'));
$gradeLevel = sanitize($_GET['grade_level'] ?? '');
$section    = sanitize($_GET['section']    ?? '');
$subjectId  = intval($_GET['subject_id']   ?? 0);

/* Advisory class (JHS/SHS) assigned to this teacher — the print signature shows
   "Adviser" instead of "Subject Teacher" when the filtered grade & section is the advisory. */
$isAdvisoryPrint = false;
$advisoryRecord  = getAdvisorySectionRecord($db);
if ($advisoryRecord && $gradeLevel !== '' && $section !== '') {
    $isAdvisoryPrint = ((string)$gradeLevel === (string)$advisoryRecord['grade_level']
        && strtolower(trim($section)) === strtolower(trim((string)$advisoryRecord['section_name'])));
}

$gradeSql = "SELECT DISTINCT s.grade_level FROM students s
                JOIN attendance a ON a.student_id = s.id
                WHERE a.recorded_by = :tid AND a.date BETWEEN :df AND :dt
                ORDER BY s.grade_level ASC";
$gradeStmt = $db->prepare($gradeSql);
$gradeStmt->execute([':tid' => $teacherId, ':df' => $dateFrom, ':dt' => $dateTo]);
$gradeOptions = $gradeStmt->fetchAll();

$sectionSql = "SELECT DISTINCT s.section FROM students s
                 JOIN attendance a ON a.student_id = s.id
                 WHERE a.recorded_by = :tid AND a.date BETWEEN :df AND :dt
                 ORDER BY s.section ASC";
$sectionStmt = $db->prepare($sectionSql);
$sectionStmt->execute([':tid' => $teacherId, ':df' => $dateFrom, ':dt' => $dateTo]);
$sectionOptions = $sectionStmt->fetchAll();

$subjectSql = "SELECT DISTINCT sub.id, sub.subject_name
                 FROM attendance a
                 JOIN subjects sub ON a.subject_id = sub.id
                 WHERE a.recorded_by = :tid AND a.date BETWEEN :df AND :dt
                 ORDER BY sub.subject_name ASC";
$subjectStmt = $db->prepare($subjectSql);
$subjectStmt->execute([':tid' => $teacherId, ':df' => $dateFrom, ':dt' => $dateTo]);
$subjectOptions = $subjectStmt->fetchAll();

$sql = "SELECT a.*, s.first_name, s.last_name, s.student_id as sid,
                s.grade_level, s.section, sub.subject_name
          FROM attendance a
          LEFT JOIN students s ON a.student_id = s.id
          LEFT JOIN subjects sub ON a.subject_id = sub.id
          WHERE a.recorded_by = :tid AND a.date BETWEEN :df AND :dt";
$params = [':tid' => $teacherId, ':df' => $dateFrom, ':dt' => $dateTo];
if ($gradeLevel !== '') {
    $sql .= " AND s.grade_level = :grade_level";
    $params[':grade_level'] = $gradeLevel;
}
if ($section !== '') {
    $sql .= " AND s.section = :section";
    $params[':section'] = $section;
}
if ($subjectId > 0) {
    $sql .= " AND a.subject_id = :subject_id";
    $params[':subject_id'] = $subjectId;
}
$sql .= " ORDER BY a.date DESC, s.last_name ASC, a.time DESC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$allRecords = $stmt->fetchAll();

$sessionKeys = [];
foreach ($allRecords as $r) {
    $key = $r['date'] . '_' . $r['subject_id'];
    $sessionKeys[$key] = true;
}
$totalSessions = count($sessionKeys);

$pCount = $lCount = $aCount = $pendCount = 0;
$total = count($allRecords);
foreach ($allRecords as $r) {
    if ($r['status'] === 'present') $pCount++;
    elseif ($r['status'] === 'late') $lCount++;
    elseif ($r['status'] === 'absent') $aCount++;
    else $pendCount++;
}
$avgRate = $total > 0 ? round((($pCount + $lCount) / $total) * 100, 1) : 0;

$studentStats = [];
foreach ($allRecords as $r) {
    $sid = $r['student_id'];
    if (!isset($studentStats[$sid])) {
        $studentStats[$sid] = [
            'name'        => ($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''),
            'sid'         => $r['sid'] ?? '',
            'grade_level' => $r['grade_level'] ?? '',
            'section'     => $r['section'] ?? '',
            'subject'     => $r['subject_name'] ?? 'Multiple',
            'present'     => 0, 'late' => 0, 'absent' => 0, 'pending' => 0, 'excused' => 0, 'total' => 0
        ];
    }
    $studentStats[$sid]['total']++;
    $studentStats[$sid][$r['status']]++;
}

$perfectCount = 0; $lowCount = 0;
$perfectStudents = []; $lowStudents = [];
$reportData = [];
foreach ($studentStats as $sid => $stats) {
    $rate = $stats['total'] > 0 ? round((($stats['present'] + $stats['late']) / $stats['total']) * 100, 1) : 0;
    $stats['rate'] = $rate;
    $reportData[] = $stats;
    if ($rate >= 100) { $perfectCount++; $perfectStudents[] = $stats; }
    if ($rate < 75)   { $lowCount++; $lowStudents[] = $stats; }
}
usort($reportData, fn($a, $b) => strcmp($a['name'], $b['name']));

$reportPerPage = 10;
$reportPage = max(1, intval($_GET['report_page'] ?? 1));
$reportTotalPages = max(1, (int)ceil(count($reportData) / $reportPerPage));
if ($reportPage > $reportTotalPages) $reportPage = $reportTotalPages;
$reportOffset = ($reportPage - 1) * $reportPerPage;
$reportPageData = array_slice($reportData, $reportOffset, $reportPerPage);

$lowPerPage = 10;
$lowPage = max(1, intval($_GET['low_page'] ?? 1));
$lowTotalPages = max(1, (int)ceil(count($lowStudents) / $lowPerPage));
if ($lowPage > $lowTotalPages) $lowPage = $lowTotalPages;
$lowOffset = ($lowPage - 1) * $lowPerPage;
$lowPageData = array_slice($lowStudents, $lowOffset, $lowPerPage);

$dailyTrend = [];
foreach ($allRecords as $r) {
    $d = $r['date'];
    if (!isset($dailyTrend[$d])) $dailyTrend[$d] = ['present' => 0, 'late' => 0, 'absent' => 0, 'pending' => 0, 'excused' => 0];
    $dailyTrend[$d][$r['status']]++;
}
ksort($dailyTrend);

$subjectStats = [];
foreach ($allRecords as $r) {
    $subId = $r['subject_id'];
    if (!isset($subjectStats[$subId])) {
            $subjectStats[$subId] = ['name' => $r['subject_name'] ?? 'Unknown', 'present' => 0, 'late' => 0, 'absent' => 0, 'pending' => 0, 'excused' => 0, 'total' => 0];
    }
    $subjectStats[$subId]['total']++;
    $subjectStats[$subId][$r['status']]++;
}
?>

<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/pages-theme.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/pages-navbar.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/print.css">

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
    .btn-outline-primary { border: 1px solid var(--td-primary); color: var(--td-primary); border-radius: var(--td-radius-xs); font-weight: 600; font-size: 13px; padding: 10px 18px; background: transparent; transition: all var(--td-transition); }
    .btn-outline-primary:hover { background: var(--td-primary-light); color: #fff; transform: translateY(-1px); }
    .btn-secondary { border-radius: var(--td-radius-xs); font-weight: 600; font-size: 13px; }

    /* CARDS */
    .card { overflow: hidden; border-radius: var(--td-radius); }
    .card-header { font-size: 14px; font-weight: 700; letter-spacing: -0.01em; padding: 16px 20px; }
    .card-body { padding: 20px; }

    /* CHART CARD HEADERS — keep titles close to their icons */
    .chart-card > .card-header { justify-content: flex-start; gap: 0; }
    .chart-card > .card-header > i { margin-right: 4px; }

    /* FORM */
    .form-label { font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 6px; display: block; }
    .form-select, .form-control { padding: 10px 14px; border-radius: var(--td-radius-sm); font-size: 13px; font-weight: 500; border-width: 1.5px; transition: all var(--td-transition); }
    .form-select:focus, .form-control:focus { box-shadow: 0 0 0 3px var(--td-primary-glow); }

    /* STAT CARDS */
    .stat-card { padding: 20px; border-radius: var(--td-radius); border: 1px solid rgba(255,255,255,0.08); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px); transition: all var(--td-transition); }
    .stat-card:hover { transform: translateY(-2px); border-color: rgba(255,255,255,0.15); }
    .stat-value { font-size: 28px; font-weight: 800; font-family: var(--td-mono); letter-spacing: -0.04em; line-height: 1.1; }
    .stat-label { font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; margin-top: 4px; opacity: 0.5; }
    .stat-icon { width: 42px; height: 42px; border-radius: var(--td-radius-sm); display: flex; align-items: center; justify-content: center; font-size: 18px; flex-shrink: 0; }
    .bg-primary-soft { background: var(--td-primary-light); color: var(--td-primary); }
    .bg-success-soft { background: var(--td-success-light); color: var(--td-success); }
    .bg-warning-soft { background: var(--td-warning-light); color: var(--td-warning); }
    .bg-info-soft { background: var(--td-info-light); color: var(--td-info); }
    .bg-danger-soft { background: var(--td-danger-light); color: var(--td-danger); }
    .bg-secondary-soft { background: rgba(255,255,255,0.08); color: rgba(255,255,255,0.6); font-size: 11px; font-weight: 700; padding: 3px 8px; border-radius: 6px; }

    /* BADGE STATUS */
    .badge-status { font-size: 10px; font-weight: 700; padding: 4px 10px; border-radius: 20px; text-transform: uppercase; letter-spacing: 0.04em; }

    /* TABLE */
    .table { margin: 0; font-size: 13px; }
    .table thead th { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; padding: 12px 16px; border-bottom: 1px solid rgba(255,255,255,0.1); white-space: nowrap; }
    .table tbody td { padding: 12px 16px; vertical-align: middle; border-bottom: 1px solid rgba(255,255,255,0.04); }
    .table tbody tr { transition: background var(--td-transition); }
    .table tbody tr:hover { background: rgba(255,255,255,0.03); }
    .table code { font-family: var(--td-mono); font-size: 12px; padding: 2px 6px; border-radius: 4px; background: rgba(255,255,255,0.06); }
    .table-sm thead th { padding: 10px 12px; }
    .table-sm tbody td { padding: 10px 12px; }

    /* FILTER BAR */
    .filter-bar { padding: 16px 20px; }

    /* CHART CONTAINER */
    .chart-wrap { position: relative; width: 100%; height: 300px; }
    .chart-wrap canvas { display: block; width: 100% !important; }
    .chart-card .card-body { padding: 16px; }

    /* ALERT */
    .alert { background: rgba(255,255,255,0.08); border: 1px solid rgba(255,255,255,0.12); color: #fff; }
    .alert-success { background: rgba(16,185,129,0.15); border-color: rgba(16,185,129,0.3); color: #34D399; }
    .alert-danger { background: rgba(239,68,68,0.15); border-color: rgba(239,68,68,0.3); color: #F87171; }
    .alert-warning { background: rgba(245,158,11,0.15); border-color: rgba(245,158,11,0.3); color: #FBBF24; }
    .alert-info { background: rgba(59,130,246,0.15); border-color: rgba(59,130,246,0.3); color: #93C5FD; }
    .btn-close { filter: invert(1) grayscale(100%) brightness(200%); }

    @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }

    /* =====================================================
       TABLET
       ===================================================== */
    @media (max-width: 991px) {
        .content-area { padding: 20px; }
        .card-body { padding: 16px; }
        .card-header { padding: 14px 16px; }
        .stat-value { font-size: 24px; }
        .stat-card { padding: 16px; }
    }

    /* =====================================================
       MOBILE
       ===================================================== */
    @media (max-width: 767px) {
        .top-navbar { padding: 12px 14px; flex-wrap: nowrap; gap: 8px; }
        .navbar-left { flex: 1; gap: 10px; min-width: 0; }
        .navbar-brand { display: flex; }
        .navbar-brand-logo { width: 44px; height: 44px; }
        .navbar-brand-name { font-size: 12px; }
        .navbar-brand-sub { font-size: 9px; opacity: 0.45; }
        .desktop-title { display: none !important; }
        .desktop-date { display: none !important; }
        .mobile-title { display: block !important; }
        .navbar-actions { gap: 6px; }

        .content-area { padding: 10px 12px 28px; }
        .btn-primary, .btn-outline-secondary, .btn-outline-primary { width: 100%; text-align: center; display: block; }

        /* Stats — 2x2 grid */
        .stat-card { padding: 14px; }
        .stat-value { font-size: 22px; }
        .stat-label { font-size: 10px; }
        .stat-icon { width: 36px; height: 36px; font-size: 15px; }

        /* Filters */
        .filter-bar { padding: 14px; }
        .filter-bar .form-label { font-size: 10px; margin-bottom: 4px; }
        .filter-bar .form-select, .filter-bar .form-control { padding: 8px 10px; font-size: 12px; }

        /* Charts */
        .chart-wrap { height: 240px; }

        /* Table */
        .table thead th { font-size: 10px; padding: 10px 12px; }
        .table tbody td { padding: 10px 12px; font-size: 12px; }

        .row.g-3 { --bs-gutter-x: 10px; --bs-gutter-y: 10px; }
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
        .mobile-date { font-size: 10px; padding: 5px 8px; }
        .content-area { padding: 8px 8px 24px; }
        .card-header { padding: 12px 14px; font-size: 13px; }
        .card-body { padding: 14px; }
        .stat-value { font-size: 20px; }
        .stat-icon { width: 32px; height: 32px; font-size: 14px; }
        .sidebar { width: 260px; }
    }

    @media (min-width: 768px) {
        .mobile-title { display: none !important; }
    }

    #printReportArea { display: none; }
    .print-header-img { display: none; }
    @media print {
        .print-header-img { display: block !important; margin-bottom: 25px; }
        body { padding: 0 !important; }
        @page { margin-top: 0; }
        .pagination { display: none !important; }
    }
</style>

<?php require_once __DIR__ . '/../includes/pages-topnavbar.php'; ?>
<div class="main-content">

    <!-- ===== CONTENT ===== -->
    <div class="content-area">
        <?= displayFlashMessage() ?>
        <img src="<?= BASE_URL ?>/assets/images/header.jpg" alt="Header" class="print-header-img" style="width:100%;max-height:160px;object-fit:contain;">

<div class="d-none d-md-flex justify-content-between align-items-center gap-3 mb-3">
            <div class="page-title mb-0">
                <h5 class="mb-0">Attendance Reports</h5>
                <small>Your teaching performance analytics</small>
            </div>
        </div>

        <div class="page-title mobile-title">
            <div class="mobile-title-inner">
                <div class="mobile-title-left">
                    <h5>Attendance Reports</h5>
                    <small>Performance analytics</small>
                </div>
            </div>
        </div>

        <!-- Date Filter — paired rows on mobile -->
        <div class="card mb-4">
            <div class="card-body filter-bar">
                <form method="GET" class="row g-2 align-items-end">
                    <div class="col-6 col-md-4">
                        <label class="form-label">From</label>
                        <input type="date" class="form-control" name="date_from" value="<?= sanitize($dateFrom) ?>">
                    </div>
                    <div class="col-6 col-md-4">
                        <label class="form-label">To</label>
                        <input type="date" class="form-control" name="date_to" value="<?= sanitize($dateTo) ?>">
                    </div>
<div class="col-6 col-md-2">
                        <label class="form-label">Grade</label>
                        <select class="form-select" name="grade_level" id="gradeLevelFilter">
                            <option value="">All Grades</option>
                            <?php foreach ($gradeOptions as $go): ?>
                            <option value="<?= sanitize($go['grade_level']) ?>" <?= $gradeLevel == $go['grade_level'] ? 'selected' : '' ?>>
                                Grade <?= sanitize($go['grade_level']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label">Section</label>
                        <select class="form-select" name="section" id="sectionFilter">
                            <option value="">All Sections</option>
                            <?php foreach ($sectionOptions as $so): ?>
                            <option value="<?= sanitize($so['section']) ?>" <?= $section == $so['section'] ? 'selected' : '' ?>>
                                <?= sanitize($so['section']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label">Subject</label>
                        <select class="form-select" name="subject_id" id="subjectIdFilter">
                            <option value="">All Subjects</option>
                            <?php foreach ($subjectOptions as $so): ?>
                            <option value="<?= $so['id'] ?>" <?= $subjectId == $so['id'] ? 'selected' : '' ?>>
                                <?= sanitize($so['subject_name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label">&nbsp;</label>
                        <button type="submit" class="btn btn-primary w-100"><i class="bi bi-funnel"></i> Filter</button>
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label">&nbsp;</label>
                        <a href="<?= BASE_URL ?>/teacher/reports.php" class="btn btn-outline-secondary w-100">Reset</a>
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label">&nbsp;</label>
                        <button type="button" id="openPrintModalBtn" class="px-5 py-2.5 rounded-xl bg-emerald-600/80 hover:bg-emerald-500 text-white font-medium text-sm flex items-center gap-2 backdrop-blur-md transition-all shadow-lg ml-2"><i class="bi bi-printer"></i> Print / Export</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Statistics — 2x2 on mobile, 4-across on desktop -->
        <div class="row g-3 mb-4">
            <div class="col-6 col-md-3">
                <div class="stat-card">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-value"><?= $totalSessions ?></div>
                            <div class="stat-label">Total Sessions</div>
                        </div>
                        <div class="stat-icon bg-primary-soft"><i class="bi bi-camera-video-fill"></i></div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-card">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-value" style="color:<?= $avgRate >= 80 ? 'var(--td-success)' : ($avgRate >= 60 ? 'var(--td-warning)' : 'var(--td-danger)') ?>;"><?= $avgRate ?>%</div>
                            <div class="stat-label">Avg Attendance</div>
                        </div>
                        <div class="stat-icon bg-success-soft"><i class="bi bi-graph-up"></i></div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-card">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-value" style="color:var(--td-success);"><?= $perfectCount ?></div>
                            <div class="stat-label">Perfect Attendance</div>
                        </div>
                        <div class="stat-icon bg-warning-soft"><i class="bi bi-award-fill"></i></div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-card">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-value" style="color:var(--td-danger);"><?= $lowCount ?></div>
                            <div class="stat-label">Low Attendance</div>
                        </div>
                        <div class="stat-icon bg-danger-soft"><i class="bi bi-exclamation-triangle-fill"></i></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Charts: Pie + Bar -->
        <div class="row g-4 mb-4">
            <div class="col-md-5">
                <div class="card h-100 chart-card">
                    <div class="card-header"><i class="bi bi-pie-chart me-2"></i>Overall Breakdown</div>
                    <div class="card-body">
                        <div class="chart-wrap">
                            <canvas id="pieChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-7">
                <div class="card h-100 chart-card">
                    <div class="card-header"><i class="bi bi-bar-chart me-2"></i>Subject Comparison</div>
                    <div class="card-body">
                        <div class="chart-wrap">
                            <canvas id="barChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Charts: Trend -->
        <div class="card mb-4 chart-card">
            <div class="card-header"><i class="bi bi-graph-up me-2"></i>Attendance Trend</div>
            <div class="card-body">
                <div class="chart-wrap">
                    <canvas id="trendChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Attendance Report -->
        <div class="card mb-4 report-table-wrapper" id="attendanceReport">
            <div class="card-header">
                <span><i class="bi bi-table me-2"></i>Attendance Report</span>
                <span class="badge" style="background:var(--td-primary-light);color:var(--td-primary);font-size:11px;font-weight:700;"><?= count($reportData) ?> Students</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm report-table">
                        <thead>
                            <tr>
                                <th>Student ID</th>
                                <th>Name</th>
                                <th>Grade</th>
                                <th>Section</th>
                                <th>Subject</th>
                                <th>Present</th>
                                <th>Late</th>
                                <th>Absent</th>
                                <th>Excused</th>
                                <th>Total</th>
                                <th>Rate</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($reportPageData as $row): ?>
                            <tr>
                                <td><code><?= sanitize($row['sid']) ?></code></td>
                                <td style="font-weight:600;"><?= sanitize($row['name']) ?></td>
                                <td><?= sanitize($row['grade_level']) ?></td>
                                <td><?= sanitize($row['section']) ?></td>
                                <td><?= sanitize($row['subject']) ?></td>
                                <td style="color:var(--td-success);"><?= $row['present'] ?></td>
                                <td style="color:var(--td-warning);"><?= $row['late'] ?></td>
                                <td style="color:var(--td-danger);"><?= $row['absent'] ?></td>
                                <td style="color:var(--td-info);"><?= $row['excused'] ?></td>
                                <td><?= $row['total'] ?></td>
                                <td>
                                    <span class="badge" style="background:<?= $row['rate'] >= 100 ? 'var(--td-success-light)' : ($row['rate'] >= 75 ? 'var(--td-warning-light)' : 'var(--td-danger-light)') ?>;color:<?= $row['rate'] >= 100 ? 'var(--td-success)' : ($row['rate'] >= 75 ? 'var(--td-warning)' : 'var(--td-danger)') ?>;font-size:10px;font-weight:700;">
                                        <?= $row['rate'] ?>%
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php if ($reportTotalPages > 1): ?>
            <div class="card-footer d-flex justify-content-between align-items-center" style="background:rgba(255,255,255,0.03);border-top:1px solid rgba(255,255,255,0.06);padding:10px 16px;">
                <span style="font-size:12px;opacity:0.6;">Showing <?= $reportOffset + 1 ?>-<?= min($reportOffset + $reportPerPage, count($reportData)) ?> of <?= count($reportData) ?> students</span>
                <nav>
                    <ul class="pagination pagination-sm mb-0">
                        <?php if ($reportPage > 1): ?>
                            <li class="page-item"><a class="page-link" href="<?= BASE_URL ?>/teacher/reports.php?<?= http_build_query(array_merge($_GET, ['report_page' => $reportPage - 1])) ?>"><i class="bi bi-chevron-left"></i></a></li>
                        <?php endif; ?>
                        <?php for ($i = max(1, $reportPage - 2); $i <= min($reportTotalPages, $reportPage + 2); $i++): ?>
                            <li class="page-item <?= $i === $reportPage ? 'active' : '' ?>">
                                <a class="page-link" href="<?= BASE_URL ?>/teacher/reports.php?<?= http_build_query(array_merge($_GET, ['report_page' => $i])) ?>"><?= $i ?></a>
                            </li>
                        <?php endfor; ?>
                        <?php if ($reportPage < $reportTotalPages): ?>
                            <li class="page-item"><a class="page-link" href="<?= BASE_URL ?>/teacher/reports.php?<?= http_build_query(array_merge($_GET, ['report_page' => $reportPage + 1])) ?>"><i class="bi bi-chevron-right"></i></a></li>
                        <?php endif; ?>
                    </ul>
                </nav>
            </div>
            <?php endif; ?>
        </div>

        <!-- Perfect Attendance -->
        <?php if (!empty($perfectStudents)): ?>
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-award me-2"></i>Perfect Attendance Students</span>
                <span class="badge" style="background:var(--td-success-light);color:var(--td-success);font-size:11px;font-weight:700;"><?= count($perfectStudents) ?></span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr><th>Student ID</th><th>Name</th><th>Present</th><th>Late</th><th>Total</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($perfectStudents as $p): ?>
                            <tr>
                                <td><code><?= sanitize($p['sid']) ?></code></td>
                                <td style="font-weight:600;"><?= sanitize($p['name']) ?></td>
                                <td style="color:var(--td-success);"><?= $p['present'] ?></td>
                                <td><?= $p['late'] ?></td>
                                <td><?= $p['total'] ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Low Attendance -->
        <?php if (!empty($lowStudents)): ?>
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-exclamation-triangle me-2"></i>Low Attendance Students (&lt; 75%)</span>
                <span class="badge" style="background:var(--td-danger-light);color:var(--td-danger);font-size:11px;font-weight:700;"><?= count($lowStudents) ?></span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr><th>Student ID</th><th>Name</th><th>Rate</th><th>Present</th><th>Absent</th><th>Total</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($lowPageData as $l): ?>
                            <tr>
                                <td><code><?= sanitize($l['sid']) ?></code></td>
                                <td style="font-weight:600;"><?= sanitize($l['name']) ?></td>
                                <td><span class="badge" style="background:var(--td-danger-light);color:var(--td-danger);font-size:10px;font-weight:700;"><?= $l['rate'] ?>%</span></td>
                                <td><?= $l['present'] ?></td>
                                <td style="color:var(--td-danger);"><?= $l['absent'] ?></td>
                                <td><?= $l['total'] ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php if ($lowTotalPages > 1): ?>
            <div class="card-footer d-flex justify-content-between align-items-center" style="background:rgba(255,255,255,0.03);border-top:1px solid rgba(255,255,255,0.06);padding:10px 16px;">
                <span style="font-size:12px;opacity:0.6;">Showing <?= $lowOffset + 1 ?>-<?= min($lowOffset + $lowPerPage, count($lowStudents)) ?> of <?= count($lowStudents) ?> students</span>
                <nav>
                    <ul class="pagination pagination-sm mb-0">
                        <?php if ($lowPage > 1): ?>
                            <li class="page-item"><a class="page-link" href="<?= BASE_URL ?>/teacher/reports.php?<?= http_build_query(array_merge($_GET, ['low_page' => $lowPage - 1])) ?>"><i class="bi bi-chevron-left"></i></a></li>
                        <?php endif; ?>
                        <?php for ($i = max(1, $lowPage - 2); $i <= min($lowTotalPages, $lowPage + 2); $i++): ?>
                            <li class="page-item <?= $i === $lowPage ? 'active' : '' ?>">
                                <a class="page-link" href="<?= BASE_URL ?>/teacher/reports.php?<?= http_build_query(array_merge($_GET, ['low_page' => $i])) ?>"><?= $i ?></a>
                            </li>
                        <?php endfor; ?>
                        <?php if ($lowPage < $lowTotalPages): ?>
                            <li class="page-item"><a class="page-link" href="<?= BASE_URL ?>/teacher/reports.php?<?= http_build_query(array_merge($_GET, ['low_page' => $lowPage + 1])) ?>"><i class="bi bi-chevron-right"></i></a></li>
                        <?php endif; ?>
                    </ul>
                </nav>
            </div>
<?php endif; ?>
          </div>
          <?php endif; ?>

          <!-- ═══════════════════════════════════════════════════════════════
               PRINT FORMAT SELECTION MODAL (Glassmorphism)
               ═══════════════════════════════════════════════════════════════ -->
         <div id="printFormatModal" class="print-modal-overlay" role="dialog" aria-modal="true" aria-labelledby="printModalTitle">
             <div class="print-modal-backdrop"></div>
             <div class="print-modal-container">
                 <div class="print-modal-header">
                     <h5 id="printModalTitle"><i class="bi bi-printer me-2"></i>Print / Export Report</h5>
                     <button type="button" class="print-modal-close" onclick="closePrintModal()" aria-label="Close modal">&times;</button>
                 </div>
                 <div class="print-modal-body">
                     <div class="print-option-card" data-option="standard" onclick="selectPrintOption('standard')">
                         <div class="print-option-icon">
                             <i class="bi bi-file-earmark-text"></i>
                         </div>
                         <div class="print-option-content">
                             <h6 class="print-option-title">📄 Standard Dashboard View</h6>
                             <p class="print-option-desc">Prints the full report view as rendered on screen, including summary cards, stats, and student tables.</p>
                         </div>
                         <div class="print-option-check">
                             <i class="bi bi-check-circle"></i>
                         </div>
                     </div>
                     <div class="print-option-card" data-option="sf2_format" onclick="selectPrintOption('sf2_format')">
                         <div class="print-option-icon">
                             <i class="bi bi-mortarboard-fill"></i>
                         </div>
                         <div class="print-option-content">
                             <h6 class="print-option-title">🇵🇭 Official DepEd School Form 2 (SF2)</h6>
                             <p class="print-option-desc">Generates the official Department of Education Daily Attendance Report with strict Male/Female segregation, attendance codes, and summary computations.</p>
                         </div>
                         <div class="print-option-check">
                             <i class="bi bi-check-circle"></i>
                         </div>
                     </div>
                 </div>
                 <div class="print-modal-footer">
                     <button type="button" class="btn btn-outline-secondary" onclick="closePrintModal()">Cancel</button>
                     <button type="button" class="btn btn-success" id="printModalExport" onclick="exportReportOption()" disabled>
                         <i class="bi bi-file-earmark-excel me-1"></i> Export Excel
                     </button>
                     <button type="button" class="btn btn-primary" id="printModalConfirm" onclick="executePrintOption()" disabled>
                         <i class="bi bi-printer me-1"></i> Print
                     </button>
                 </div>
             </div>
         </div>
     </div>

    <!-- Hidden print area — full data (all students, not paginated) -->
    <div id="printReportArea">
        <img src="<?= BASE_URL ?>/assets/images/header.jpg" alt="Header" class="print-header-img" style="width:100%;max-height:160px;object-fit:contain;">
        <div style="text-align:center;font-size:16px;font-weight:800;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:4px;color:#1e293b;">Attendance Reports — Complete Data Printout</div>
        <div style="text-align:center;font-size:12px;color:#64748b;margin-bottom:16px;">Period: <?= formatDate($dateFrom) ?> to <?= formatDate($dateTo) ?> &bull; Generated: <?= date('F d, Y g:i A') ?></div>
        <table class="print-table">
            <thead>
                <tr>
                    <th style="width:90px;">Student ID</th>
                    <th>Name</th>
                    <th style="width:60px;">Grade</th>
                    <th style="width:70px;">Section</th>
                    <th>Subject</th>
                    <th style="width:65px;">Present</th>
                    <th style="width:55px;">Late</th>
                    <th style="width:60px;">Absent</th>
                    <th style="width:60px;">Excused</th>
                    <th style="width:55px;">Total</th>
                    <th style="width:60px;">Rate</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($reportData as $row): ?>
                <tr>
                    <td style="font-family:'Courier New',monospace;font-size:11px;"><?= sanitize($row['sid']) ?></td>
                    <td style="font-weight:600;"><?= sanitize($row['name']) ?></td>
                    <td><?= sanitize($row['grade_level']) ?></td>
                    <td><?= sanitize($row['section']) ?></td>
                    <td><?= sanitize($row['subject']) ?></td>
                    <td style="color:#059669;font-weight:600;"><?= $row['present'] ?></td>
                    <td style="color:#d97706;font-weight:600;"><?= $row['late'] ?></td>
                    <td style="color:#dc2626;font-weight:600;"><?= $row['absent'] ?></td>
                    <td style="color:#0e7490;font-weight:600;"><?= $row['excused'] ?></td>
                    <td><?= $row['total'] ?></td>
                    <td>
                        <span style="display:inline-block;padding:2px 10px;border-radius:12px;font-size:9px;font-weight:700;background:<?= $row['rate'] >= 100 ? '#d1fae5' : ($row['rate'] >= 75 ? '#fef3c7' : '#fee2e2') ?>;color:<?= $row['rate'] >= 100 ? '#065f46' : ($row['rate'] >= 75 ? '#92400e' : '#991b1b') ?>;">
                            <?= $row['rate'] ?>%
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <div style="text-align:center;margin-top:30px;padding-top:12px;border-top:2px solid #e5e7eb;font-size:10px;color:#94a3b8;">
            <p>Generated on <?= date('F d, Y g:i A') ?> &bull; Attendance Report &bull; Confidential</p>
            <div style="display:flex;justify-content:space-between;gap:30px;margin-top:12px;">
                <div style="flex:1;text-align:center;">
                    <hr style="border:none;border-top:1px solid #94a3b8;margin:0 auto 4px auto;width:70%;">
                    <strong><?= sanitize($teacherName ?? 'ANGELYN S. PARRABA') ?></strong><br>
                    <span><?= $isAdvisoryPrint ? 'Adviser' : 'Subject Teacher' ?></span>
                </div>
                <div style="flex:1;text-align:center;">
                    <hr style="border:none;border-top:1px solid #94a3b8;margin:0 auto 4px auto;width:70%;">
                    <strong>ERWIN M. ESPENILLA</strong><br>
                    <span>OIC/Assistant Principal</span>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- CHARTS -->
<script src="<?= BASE_URL ?>/assets/js/generate_report_print.js?v=<?= @filemtime(ROOT_PATH . '/assets/js/generate_report_print.js') ?: time() ?>"></script>

<script>
function printReport() {
    var area = document.getElementById('printReportArea');
    if (!area) return;
    var orient = (document.getElementById('printOrientation') || document.getElementById('printOrientationMobile') || {}).value || 'portrait';
    var isLandscape = orient === 'landscape';
    var pw = window.open('', '_blank', 'width=' + (isLandscape ? '1200' : '900') + ',height=' + (isLandscape ? '800' : '700'));
    var pageSize = isLandscape ? 'A4 landscape' : 'A4 portrait';
    var bodyPad = isLandscape ? '12px 15px' : '20px 25px';
    var thPad = isLandscape ? '6px 8px' : '10px 12px';
    var tdPad = isLandscape ? '5px 8px' : '8px 12px';
    var thFont = isLandscape ? '10px' : '11px';
    var tdFont = isLandscape ? '10px' : '12px';
    pw.document.write(
        '<!DOCTYPE html><html><head><title>Attendance Report</title>' +
        '<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/print.css">' +
        '<style>' +
        'body.print-new-window{margin:0;padding:' + bodyPad + ';background:#fff;font-family:"Segoe UI",Arial,sans-serif;color:#333;}' +
        '@page{size:' + pageSize + ';margin:0 12mm 15mm 12mm;}' +
        '.print-header-img{width:100%;max-height:160px;object-fit:contain;display:block;margin-bottom:12px;}' +
        '.print-table{width:100%;border-collapse:collapse;font-size:' + tdFont + ';margin-top:10px;}' +
        '.print-table thead{background:#f0f1f4;-webkit-print-color-adjust:exact;print-color-adjust:exact;}' +
        '.print-table thead th{padding:' + thPad + ';text-align:left;font-weight:700;font-size:' + thFont + ';text-transform:uppercase;letter-spacing:0.05em;color:#555;border-bottom:2px solid #ddd;white-space:nowrap;}' +
        '.print-table tbody td{padding:' + tdPad + ';border-bottom:1px solid #eee;color:#444;}' +
        '.print-table tbody tr:last-child td{border-bottom:none;}' +
        '.print-table tbody tr{page-break-inside:avoid;}' +
        '@media print{body.print-new-window{padding:0 !important;}.print-table{margin-top:0;}.print-header-img{max-height:160px;margin-bottom:20px;}}' +
        '</style></head>' +
        '<body class="print-new-window">' +
        area.innerHTML +
        '<script>window.onload=function(){setTimeout(function(){window.print();},100);};<\/script>' +
        '</body></html>'
    );
    pw.document.close();
}
</script>
<script>
Chart.defaults.color = 'rgba(255,255,255,0.5)';
Chart.defaults.borderColor = 'rgba(255,255,255,0.08)';

// Pie
new Chart(document.getElementById('pieChart'), {
    type: 'doughnut',
    data: {
        labels: ['Present (<?= $pCount ?>)', 'Late (<?= $lCount ?>)', 'Absent (<?= $aCount ?>)'],
        datasets: [{
            data: [<?= $pCount ?>, <?= $lCount ?>, <?= $aCount ?>],
            backgroundColor: ['#10b981', '#f59e0b', '#ef4444'],
            borderWidth: 0,
            spacing: 3
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '58%',
        plugins: {
            legend: {
                position: 'bottom',
                labels: {
                    padding: 16,
                    usePointStyle: true,
                    pointStyleWidth: 10,
                    font: { size: 12, family: "'Plus Jakarta Sans', sans-serif", weight: '600' }
                }
            }
        }
    }
});

// Bar — Subject Comparison
const subjectNames = <?= json_encode(array_values(array_map(fn($s) => $s['name'], $subjectStats))) ?>;
const subjectPresent = <?= json_encode(array_values(array_map(fn($s) => $s['present'], $subjectStats))) ?>;
const subjectLate = <?= json_encode(array_values(array_map(fn($s) => $s['late'], $subjectStats))) ?>;
const subjectAbsent = <?= json_encode(array_values(array_map(fn($s) => $s['absent'], $subjectStats))) ?>;
const subjectExcused = <?= json_encode(array_values(array_map(fn($s) => $s['excused'], $subjectStats))) ?>;

new Chart(document.getElementById('barChart'), {
    type: 'bar',
    data: {
        labels: subjectNames,
        datasets: [
            { label: 'Present', data: subjectPresent, backgroundColor: '#10b981', borderRadius: 4 },
            { label: 'Late', data: subjectLate, backgroundColor: '#f59e0b', borderRadius: 4 },
            { label: 'Absent', data: subjectAbsent, backgroundColor: '#ef4444', borderRadius: 4 },
            { label: 'Excused', data: subjectExcused, backgroundColor: '#06b6d4', borderRadius: 4 }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: {
            x: { stacked: true, grid: { display: false }, ticks: { font: { size: 11, family: "'Plus Jakarta Sans', sans-serif" } } },
            y: { stacked: true, beginAtZero: true, ticks: { font: { size: 11, family: "'Plus Jakarta Sans', sans-serif" } } }
        },
        plugins: {
            legend: {
                position: 'bottom',
                labels: { padding: 16, usePointStyle: true, pointStyleWidth: 10, font: { size: 12, family: "'Plus Jakarta Sans', sans-serif", weight: '600' } }
            }
        }
    }
});

// Line — Daily Trend
const trendDates = <?= json_encode(array_keys($dailyTrend)) ?>;
const trendPresent = <?= json_encode(array_values(array_map(fn($d) => $d['present'], $dailyTrend))) ?>;
const trendLate = <?= json_encode(array_values(array_map(fn($d) => $d['late'], $dailyTrend))) ?>;
const trendAbsent = <?= json_encode(array_values(array_map(fn($d) => $d['absent'], $dailyTrend))) ?>;

new Chart(document.getElementById('trendChart'), {
    type: 'line',
    data: {
        labels: trendDates.map(d => new Date(d).toLocaleDateString('en-US', { month: 'short', day: 'numeric' })),
        datasets: [
            {
                label: 'Present', data: trendPresent,
                borderColor: '#10b981', backgroundColor: 'rgba(16,185,129,0.08)',
                fill: true, tension: 0.35, pointRadius: 4, pointHoverRadius: 6, borderWidth: 2
            },
            {
                label: 'Late', data: trendLate,
                borderColor: '#f59e0b', backgroundColor: 'transparent',
                fill: false, tension: 0.35, pointRadius: 3, pointHoverRadius: 5, borderWidth: 2
            },
            {
                label: 'Absent', data: trendAbsent,
                borderColor: '#ef4444', backgroundColor: 'transparent',
                fill: false, tension: 0.35, pointRadius: 3, pointHoverRadius: 5, borderWidth: 2
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: {
            x: { grid: { display: false }, ticks: { font: { size: 11, family: "'Plus Jakarta Sans', sans-serif" }, maxRotation: 45 } },
            y: { beginAtZero: true, ticks: { font: { size: 11, family: "'Plus Jakarta Sans', sans-serif" } } }
        },
        plugins: {
            legend: {
                position: 'bottom',
                labels: { padding: 16, usePointStyle: true, pointStyleWidth: 10, font: { size: 12, family: "'Plus Jakarta Sans', sans-serif", weight: '600' } }
            }
        }
    }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>