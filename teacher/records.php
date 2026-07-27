<?php
require_once __DIR__ . '/../config.php';
requireRole(['admin', 'teacher']);

$pageTitle = 'Attendance Records';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

$teacherId = getCurrentUserId();

// Filters
$dateFilter    = sanitize($_GET['date'] ?? date('Y-m-d'));
$subjectFilter = intval($_GET['subject_id'] ?? 0);
$statusFilter  = sanitize($_GET['status'] ?? '');
$sectionFilter = sanitize($_GET['section'] ?? '');

// Get teacher's subjects
$teacherSubjects = [];
try {
    $teacher = null;
    $stmt = $db->prepare("SELECT * FROM teachers WHERE user_id = ?");
    $stmt->execute([$teacherId]);
    $teacher = $stmt->fetch();

    if ($teacher && !empty($teacher['subjects_handled'])) {
        $subjectNames = explode(',', $teacher['subjects_handled']);
        foreach ($subjectNames as $name) {
            $name = trim($name);
            $stmt = $db->prepare("SELECT * FROM subjects WHERE subject_name LIKE ?");
            $stmt->execute(["%$name%"]);
            $found = $stmt->fetchAll();
            $teacherSubjects = array_merge($teacherSubjects, $found);
        }
    }
    if (empty($teacherSubjects)) {
        $teacherSubjects = $db->query("SELECT * FROM subjects ORDER BY subject_name")->fetchAll();
    }
} catch (Exception $e) {
    $teacherSubjects = [];
}

// Get sections for this teacher's students
$sections = [];
try {
    $stmt = $db->prepare("SELECT DISTINCT s.section FROM students s
        INNER JOIN attendance a ON a.student_id = s.id
        WHERE a.recorded_by = :tid AND s.section != ''
        ORDER BY s.section");
    $stmt->execute([':tid' => $teacherId]);
    $sections = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {}

// Build query
$whereClause = "WHERE a.recorded_by = :teacher_id AND a.date = :date";
$params = [':teacher_id' => $teacherId, ':date' => $dateFilter];

if ($subjectFilter) {
    $whereClause .= " AND a.subject_id = :subject_id";
    $params[':subject_id'] = $subjectFilter;
}
if ($statusFilter) {
    $whereClause .= " AND a.status = :status";
    $params[':status'] = $statusFilter;
}
if ($sectionFilter) {
    $whereClause .= " AND s.section = :section";
    $params[':section'] = $sectionFilter;
}

$joinClause = "FROM attendance a
    LEFT JOIN students s ON a.student_id = s.id
    LEFT JOIN subjects sub ON a.subject_id = sub.id";

// Count total for pagination
$countStmt = $db->prepare("SELECT COUNT(*) as cnt $joinClause $whereClause");
$countStmt->execute($params);
$totalRecords = $countStmt->fetch()['cnt'];

$sql = "SELECT a.*, s.first_name, s.last_name, s.student_id as sid, s.grade_level, s.section,
               sub.subject_name, sub.subject_code
        $joinClause $whereClause ORDER BY a.time DESC";

$perPage = 15;
$page = max(1, intval($_GET['page'] ?? 1));
$totalPages = max(1, (int)ceil($totalRecords / $perPage));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $perPage;

$sql .= " LIMIT $offset, $perPage";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$records = $stmt->fetchAll();

// Stats (based on ALL filtered records, not just current page)
$pCount = $lCount = $aCount = 0;
foreach ($records as $r) {
    if ($r['status'] === 'present') $pCount++;
    elseif ($r['status'] === 'late') $lCount++;
    else $aCount++;
}
$total = $totalRecords;
$rate = $total > 0 ? round((($pCount + $lCount) / $total) * 100, 1) : 0;
?>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
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
    .btn-secondary { border-radius: var(--td-radius-xs); font-weight: 600; font-size: 13px; }

    /* CARDS */
    .card { overflow: hidden; border-radius: var(--td-radius); }
    .card-header { font-size: 14px; font-weight: 700; letter-spacing: -0.01em; padding: 16px 20px; }
    .card-body { padding: 20px; }

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
    .bg-secondary-soft { background: rgba(96,165,250,0.15); color: #93C5FD; font-size: 11px; font-weight: 700; padding: 3px 8px; border-radius: 6px; }

    /* BADGE STATUS */
    .badge-status { font-size: 10px; font-weight: 700; padding: 4px 10px; border-radius: 20px; text-transform: uppercase; letter-spacing: 0.04em; }
    .badge-present { background: var(--td-success-light); color: var(--td-success); }
    .badge-late { background: var(--td-warning-light); color: var(--td-warning); }
    .badge-absent { background: var(--td-danger-light); color: var(--td-danger); }

    /* PAGINATION */
    .pagination { gap: 4px; }
    .pagination .page-link { background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); color: rgba(255,255,255,0.7); border-radius: var(--td-radius-xs); padding: 6px 12px; font-size: 12px; font-weight: 600; transition: all var(--td-transition); }
    .pagination .page-link:hover { background: var(--td-primary-light); color: var(--td-primary); border-color: var(--td-primary); }
    .pagination .page-item.active .page-link { background: var(--td-primary); border-color: var(--td-primary); color: #fff; }
    .pagination .page-item.disabled .page-link { opacity: 0.3; pointer-events: none; }

    /* TABLE */
    .table { margin: 0; font-size: 13px; }
    .table thead th { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; padding: 12px 16px; border-bottom: 1px solid rgba(255,255,255,0.1); white-space: nowrap; }
    .table tbody td { padding: 12px 16px; vertical-align: middle; border-bottom: 1px solid rgba(255,255,255,0.04); }
    .table tbody tr { transition: background var(--td-transition); }
    .table tbody tr:hover { background: rgba(255,255,255,0.03); }
    .table code { font-family: var(--td-mono); font-size: 12px; padding: 2px 6px; border-radius: 4px; background: rgba(255,255,255,0.06); }

    /* FILTER BAR */
    .filter-bar { padding: 16px 20px; }

    /* SCAN LOG HEADER */
    .scan-log-header { display: flex; justify-content: space-between; align-items: center; }

    /* EMPTY STATE */
    .empty-state { text-align: center; padding: 60px 20px; display: flex; flex-direction: column; align-items: center; gap: 12px; }
    .empty-icon { width: 72px; height: 72px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 32px; background: rgba(255,255,255,0.05); }
    .empty-state h6 { font-size: 16px; font-weight: 700; margin: 0; opacity: 0.7; }
    .empty-state p { font-size: 13px; margin: 0; opacity: 0.4; }

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
        .top-navbar { padding: 8px 12px; flex-wrap: wrap; gap: 8px; height: auto; }
        .navbar-left { flex-shrink: 0; gap: 10px; }
        .navbar-brand { display: flex; }
        .navbar-brand-logo { width: 44px; height: 44px; }
        .navbar-brand-name { font-size: 12px; }
        .navbar-brand-sub { font-size: 9px; opacity: 0.45; }
        .desktop-title { display: none !important; }
        .desktop-date { display: none !important; }
        .mobile-title { display: block; }
        .mobile-title-left h5 { font-size: 17px; }
        .navbar-actions { gap: 6px; }

        .content-area { padding: 10px 12px 28px; }
        .btn-primary, .btn-outline-secondary { width: 100%; text-align: center; display: block; }

        /* Stats — 2x2 grid */
        .stat-card { padding: 14px; }
        .stat-value { font-size: 22px; }
        .stat-label { font-size: 10px; }
        .stat-icon { width: 36px; height: 36px; font-size: 15px; }

        /* Filters */
        .filter-bar { padding: 14px; }
        .filter-bar .form-label { font-size: 10px; margin-bottom: 4px; }
        .filter-bar .form-select, .filter-bar .form-control { padding: 8px 10px; font-size: 12px; }

        /* Table mobile */
        .table thead th { font-size: 10px; padding: 10px 12px; }
        .table tbody td { padding: 10px 12px; font-size: 12px; }

        .row.g-3 { --bs-gutter-x: 10px; --bs-gutter-y: 10px; }

        /* Empty state */
        .empty-state { padding: 40px 16px; }
        .empty-icon { width: 56px; height: 56px; font-size: 26px; }
        .empty-state h6 { font-size: 14px; }
        .empty-state p { font-size: 12px; }
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
        .stat-value { font-size: 20px; }
        .stat-icon { width: 32px; height: 32px; font-size: 14px; }
        .sidebar { width: 260px; }
    }

    @media (min-width: 768px) {
        .mobile-title { display: none !important; }
    }

    /* PRINT STYLES */
    .print-container { display: none; }
    @media print {
        body * { visibility: hidden; }
        .print-container, .print-container * { visibility: visible; }
        .print-container { display: block !important; position: absolute; left: 0; top: 0; width: 100%; padding: 20px; }
        .print-container table { width: 100%; border-collapse: collapse; font-size: 12px; }
        .print-container th, .print-container td { border: 1px solid #333; padding: 8px 12px; text-align: left; }
        .print-container th { background: #f0f0f0; font-weight: 700; }
    }
</style>

<?php require_once __DIR__ . '/../includes/pages-topnavbar.php'; ?>
<div class="main-content">

    <!-- ===== CONTENT ===== -->
    <div class="content-area">
        <?= displayFlashMessage() ?>

        <div class="d-none d-md-flex justify-content-between align-items-center gap-3 mb-3">
            <div class="page-title mb-0">
                <h5 class="mb-0">Attendance Records</h5>
                <small>View and manage your class attendance history</small>
            </div>
            <div class="d-flex gap-2">
                <a href="<?= BASE_URL ?>/teacher/attendance.php" class="btn btn-primary"><i class="bi bi-camera-video"></i> <span class="d-none d-sm-inline">New Session</span></a>
            </div>
        </div>

        <div class="page-title mobile-title">
            <div class="mobile-title-inner">
                <div class="mobile-title-left">
                    <h5>Attendance Records</h5>
                    <small>Class attendance history</small>
                </div>
                <div class="d-flex gap-2">
                    <a href="<?= BASE_URL ?>/teacher/attendance.php" class="btn btn-primary"><i class="bi bi-camera-video"></i> <span class="d-none d-sm-inline">New Session</span></a>
                </div>
            </div>
        </div>

        <!-- Stats — 2x2 on mobile, 4-across on desktop -->
        <div class="row g-3 mb-4">
            <div class="col-6 col-md-3">
                <div class="stat-card">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-value"><?= $total ?></div>
                            <div class="stat-label">Total Records</div>
                        </div>
                        <div class="stat-icon bg-primary-soft"><i class="bi bi-clipboard-data"></i></div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-card">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-value text-success"><?= $pCount ?></div>
                            <div class="stat-label">Present</div>
                        </div>
                        <div class="stat-icon bg-success-soft"><i class="bi bi-check-circle"></i></div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-card">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-value text-warning"><?= $lCount ?></div>
                            <div class="stat-label">Late</div>
                        </div>
                        <div class="stat-icon bg-warning-soft"><i class="bi bi-clock"></i></div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-card">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-value"><?= $rate ?>%</div>
                            <div class="stat-label">Attendance Rate</div>
                        </div>
                        <div class="stat-icon bg-info-soft"><i class="bi bi-graph-up"></i></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filters — paired rows on mobile -->
        <div class="card mb-4">
            <div class="card-body filter-bar">
                <form method="GET" class="row g-2 align-items-end">
                    <!-- Row 1: Date + Subject + Section -->
                    <div class="col-6 col-md-3">
                        <label class="form-label">Date</label>
                        <input type="date" class="form-control" name="date" value="<?= sanitize($dateFilter) ?>">
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label">Subject</label>
                        <select name="subject_id" class="form-select">
                            <option value="">All Subjects</option>
                            <?php foreach ($teacherSubjects as $sub): ?>
                                <option value="<?= $sub['id'] ?>" <?= $subjectFilter == $sub['id'] ? 'selected' : '' ?>>
                                    <?= sanitize($sub['subject_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label">Section</label>
                        <select name="section" class="form-select">
                            <option value="">All Sections</option>
                            <?php foreach ($sections as $sec): ?>
                                <option value="<?= sanitize($sec) ?>" <?= $sectionFilter === $sec ? 'selected' : '' ?>>
                                    <?= sanitize($sec) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <!-- Row 2: Status + Filter + Clear -->
                    <div class="col-6 col-md-2">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select">
                            <option value="">All</option>
                            <option value="present" <?= $statusFilter === 'present' ? 'selected' : '' ?>>Present</option>
                            <option value="late" <?= $statusFilter === 'late' ? 'selected' : '' ?>>Late</option>
                            <option value="absent" <?= $statusFilter === 'absent' ? 'selected' : '' ?>>Absent</option>
                        </select>
                    </div>
                    <div class="col-3 col-md-2">
                        <label class="form-label">&nbsp;</label>
                        <button type="submit" class="btn btn-primary w-100"><i class="bi bi-funnel"></i> Filter</button>
                    </div>
                    <div class="col-3 col-md-2">
                        <label class="form-label">&nbsp;</label>
                        <a href="<?= BASE_URL ?>/teacher/records.php" class="btn btn-outline-secondary w-100">Clear</a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Records Table -->
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-table me-2"></i>Attendance Records — <?= formatDate($dateFilter) ?></span>
                <span class="d-flex gap-2 align-items-center">
                    <span class="badge bg-primary"><?= $total ?> record(s)</span>
                    <button class="btn btn-sm btn-outline-secondary" onclick="printRecords()" title="Print Records">
                        <i class="bi bi-printer"></i> Print
                    </button>
                </span>
            </div>
            <div class="card-body p-0">
                <?php if (empty($records)): ?>
                    <div class="empty-state">
                        <div class="empty-icon"><i class="bi bi-clipboard-data"></i></div>
                        <h6>No Records Found</h6>
                        <p>Start an attendance session or adjust filters</p>
                    </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Time</th>
                                <th>Student ID</th>
                                <th>Name</th>
                                <th>Grade</th>
                                <th>Subject</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($records as $rec): ?>
                            <tr>
                                <td><?= date('h:i A', strtotime($rec['time'])) ?></td>
                                <td><code><?= sanitize($rec['sid'] ?? '-') ?></code></td>
                                <td style="font-weight:600;"><?= sanitize(($rec['first_name'] ?? '') . ' ' . ($rec['last_name'] ?? '')) ?></td>
                                <td><span class="bg-secondary-soft">G<?= $rec['grade_level'] ?? '-' ?></span></td>
                                <td><?= sanitize($rec['subject_name'] ?? '-') ?></td>
                                <td>
                                    <span class="badge-status badge-<?= $rec['status'] ?>">
                                        <?= ucfirst($rec['status']) ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
            <?php if ($totalPages > 1): ?>
            <div class="card-footer d-flex justify-content-between align-items-center" style="background:rgba(255,255,255,0.03);border-top:1px solid rgba(255,255,255,0.06);padding:10px 16px;">
                <span style="font-size:12px;opacity:0.6;">Showing <?= $offset + 1 ?>-<?= min($offset + $perPage, $totalRecords) ?> of <?= $totalRecords ?> records</span>
                <nav>
                    <ul class="pagination pagination-sm mb-0">
                        <?php if ($page > 1): ?>
                            <li class="page-item"><a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>"><i class="bi bi-chevron-left"></i></a></li>
                        <?php endif; ?>
                        <?php
                        $range = 2;
                        for ($i = max(1, $page - $range); $i <= min($totalPages, $page + $range); $i++):
                        ?>
                            <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"><?= $i ?></a>
                            </li>
                        <?php endfor; ?>
                        <?php if ($page < $totalPages): ?>
                            <li class="page-item"><a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>"><i class="bi bi-chevron-right"></i></a></li>
                        <?php endif; ?>
                    </ul>
                </nav>
            </div>
            <?php endif; ?>
        </div>
        <!-- Print Container (hidden, used for printing) -->
        <div class="print-container" id="printContainer">
            <?php
            $printDocTitle = 'Attendance Records — ' . formatDate($dateFilter);
            $printDocMeta  = '<span><strong>Generated:</strong> ' . date('F j, Y g:i A') . '</span>'
                           . ($subjectFilter ? '<span><strong>Subject:</strong> ' . sanitize($teacherSubjects[array_search($subjectFilter, array_column($teacherSubjects, 'id'))]['subject_name'] ?? 'Unknown') . '</span>' : '')
                           . ($sectionFilter ? '<span><strong>Section:</strong> ' . sanitize($sectionFilter) . '</span>' : '')
                           . ($statusFilter ? '<span><strong>Status:</strong> ' . ucfirst($statusFilter) . '</span>' : '');
            include __DIR__ . '/../includes/print-header.php';
            ?>
            <table class="print-table">
                <thead>
                    <tr>
                        <th style="width:80px;">Time</th>
                        <th style="width:90px;">Student ID</th>
                        <th>Name</th>
                        <th style="width:60px;">Grade</th>
                        <th>Subject</th>
                        <th style="width:70px;">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($records as $rec): ?>
                    <tr>
                        <td style="font-weight:600;"><?= date('h:i A', strtotime($rec['time'])) ?></td>
                        <td style="font-family:'Courier New',monospace;font-size:11px;"><?= sanitize($rec['sid'] ?? '-') ?></td>
                        <td style="font-weight:600;"><?= sanitize(($rec['first_name'] ?? '') . ' ' . ($rec['last_name'] ?? '')) ?></td>
                        <td>G<?= $rec['grade_level'] ?? '-' ?></td>
                        <td><?= sanitize($rec['subject_name'] ?? '-') ?></td>
                        <td>
                            <span style="display:inline-block;padding:2px 10px;border-radius:12px;font-size:9px;font-weight:700;background:<?= $rec['status'] === 'present' ? '#d1fae5' : ($rec['status'] === 'late' ? '#fef3c7' : '#fee2e2') ?>;color:<?= $rec['status'] === 'present' ? '#065f46' : ($rec['status'] === 'late' ? '#92400e' : '#991b1b') ?>;">
                                <?= ucfirst($rec['status']) ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>


<script>
    function printRecords() {
        const printContainer = document.getElementById('printContainer');
        if (!printContainer) return;
        const printWindow = window.open('', '_blank', 'width=900,height=700');
        printWindow.document.write(`
            <!DOCTYPE html>
            <html>
            <head>
                <title>Attendance Records - <?= formatDate($dateFilter) ?></title>
                <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/print.css">
                <style>
                    body.print-new-window { font-family: 'Segoe UI', Arial, sans-serif; margin: 0; padding: 20px 25px; color: #333; background: #fff; }
                    .print-table { width: 100%; border-collapse: collapse; font-size: 12px; margin-top: 10px; }
                    .print-table thead { background: #f0f1f4; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
                    .print-table thead th { padding: 10px 12px; text-align: left; font-weight: 700; font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; color: #555; border-bottom: 2px solid #ddd; white-space: nowrap; }
                    .print-table tbody td { padding: 8px 12px; border-bottom: 1px solid #eee; color: #444; }
                    .print-table tbody tr:last-child td { border-bottom: none; }
                    .print-table tbody tr { page-break-inside: avoid; }
                    @media print { body.print-new-window { padding: 0; } .print-table { margin-top: 0; } }
                </style>
            </head>
            <body class="print-new-window">
                ${printContainer.innerHTML}
                <script>window.onload = function() { setTimeout(function() { window.print(); }, 100); }<\/script>
            </body>
            </html>
        `);
        printWindow.document.close();
    }
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>