<?php

require_once __DIR__ . '/../config.php';
requireRole(['admin']);

$pageTitle = 'Attendance Overview';

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

// Filters
$dateFilter    = sanitize($_GET['date'] ?? today());
$typeFilter    = sanitize($_GET['session_type'] ?? '');
$statusFilter  = sanitize($_GET['status'] ?? '');
$gradeFilter   = sanitize($_GET['grade_level'] ?? '');

// Get today's overall statistics
$stats = ['present' => 0, 'absent' => 0, 'late' => 0, 'excused' => 0, 'total' => 0, 'rate' => 0];
try {
    $stmt = $db->prepare("SELECT status, COUNT(*) as count FROM attendance WHERE date = ? GROUP BY status");
    $stmt->execute([$dateFilter]);
    foreach ($stmt->fetchAll() as $row) { $stats[$row['status']] = intval($row['count']); }
    $stmt2 = $db->prepare("SELECT status, COUNT(*) as count FROM attendance_records WHERE DATE(scan_time) = ? GROUP BY status");
    $stmt2->execute([$dateFilter]);
    foreach ($stmt2->fetchAll() as $row) { if (isset($stats[$row['status']])) { $stats[$row['status']] += intval($row['count']); } }
    $stats['total'] = $stats['present'] + $stats['absent'] + $stats['late'] + $stats['excused'];
    $stats['rate']  = $stats['total'] > 0 ? round(($stats['present'] + $stats['late']) / $stats['total'] * 100, 1) : 0;
} catch (Exception $e) {}

// Get active sessions
$activeSessions = [];
try {
    // Explicit column projection: UNION ALL requires both branches to
    // return identical column counts (gate_sessions and attendance_sessions
    // have different schemas, so gs.* / att_ses.* breaks with ERROR 1222).
    $stmt = $db->query("SELECT 'gate' AS type, gs.session_type,
                               NULL AS subject_name, NULL AS grade_level, NULL AS section,
                               gs.start_time, gs.end_time
                        FROM gate_sessions gs WHERE gs.status = 'active'
                        UNION ALL
                        SELECT 'class' AS type, att_ses.session_type,
                               sub.subject_name, att_ses.grade_level, att_ses.section,
                               att_ses.start_time, att_ses.end_time
                        FROM attendance_sessions att_ses
                        LEFT JOIN subjects sub ON att_ses.subject_id = sub.id
                        WHERE att_ses.status = 'active'
                        ORDER BY start_time DESC");
    $activeSessions = $stmt->fetchAll();
} catch (Exception $e) { error_log('active sessions query: ' . $e->getMessage()); }

// Get class attendance records
$records = [];
try {
    $sql = "SELECT a.id, a.date, a.time, a.status, a.session_type,
                   s.first_name, s.last_name, s.student_id AS sid, s.grade_level, s.section, sub.subject_name
            FROM attendance a LEFT JOIN students s ON a.student_id = s.id LEFT JOIN subjects sub ON a.subject_id = sub.id
            WHERE a.date = :date";
    $params = [':date' => $dateFilter];
    if ($statusFilter) { $sql .= " AND a.status = :status"; $params[':status'] = $statusFilter; }
    if ($gradeFilter)  { $sql .= " AND s.grade_level = :grade"; $params[':grade'] = $gradeFilter; }
    $sql .= " ORDER BY a.time DESC, s.last_name ASC LIMIT 500";
    $stmt = $db->prepare($sql); $stmt->execute($params); $records = $stmt->fetchAll();
} catch (Exception $e) {}

// Get gate attendance records
$gateRecords = [];
try {
     $sql2 = "SELECT ar.*, s.first_name, s.last_name, s.student_id AS sid, s.grade_level, s.section
              FROM attendance_records ar LEFT JOIN students s ON ar.student_id = s.id
              WHERE DATE(ar.scan_time) = :date AND ar.status != 'pending'";
    $params2 = [':date' => $dateFilter];
    if ($statusFilter) { $sql2 .= " AND ar.status = :status"; $params2[':status'] = $statusFilter; }
    $sql2 .= " ORDER BY ar.scan_time DESC LIMIT 500";
    $stmt2 = $db->prepare($sql2); $stmt2->execute($params2); $gateRecords = $stmt2->fetchAll();
} catch (Exception $e) {}

// Weekly chart data
$weeklyData = [];
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-{$i} days")); $dayName = date('D', strtotime("-{$i} days"));
    $p = $a = $l = 0;
    try {
        $stmt = $db->prepare("SELECT status, COUNT(*) as count FROM attendance WHERE date = ? GROUP BY status");
        $stmt->execute([$date]);
        foreach ($stmt->fetchAll() as $row) {
            if ($row['status'] === 'present') $p = intval($row['count']);
            elseif ($row['status'] === 'absent') $a = intval($row['count']);
            elseif ($row['status'] === 'late') $l = intval($row['count']);
        }
    } catch (Exception $e) {}
        $weeklyData[] = ['day' => $dayName, 'present' => $p, 'absent' => $a, 'late' => $l, 'excused' => 0];
}
?>

<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/pages-theme.css">
<style>
    :root {
        --tm-font: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif;
        --tm-mono: 'JetBrains Mono', monospace;
        --tm-primary: #4f46e5; --tm-primary-light: rgba(79,70,229,0.15); --tm-primary-dark: #3730a3; --tm-primary-glow: rgba(79,70,229,0.2);
        --tm-success: #10b981; --tm-success-light: rgba(16,185,129,0.15);
        --tm-danger: #ef4444; --tm-danger-light: rgba(239,68,68,0.15);
        --tm-warning: #f59e0b; --tm-warning-light: rgba(245,158,11,0.15);
        --tm-info: #06b6d4; --tm-info-light: rgba(6,182,212,0.15);
        --tm-radius: 14px; --tm-radius-sm: 10px; --tm-radius-xs: 8px;
        --tm-shadow: 0 4px 16px rgba(0,0,0,0.25);
        --tm-transition: 0.2s cubic-bezier(0.4,0,0.2,1);
        --select-bg: #1e2a4a; --select-bg-hover: #2563eb; --select-bg-placeholder: #151d33;
        --select-text: #ffffff; --select-text-muted: rgba(255,255,255,0.45);
    }


    /* ============================================================
       REPORTS BUTTON - Enhanced visibility
       ============================================================ */
    .btn-reports {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 8px 18px;
        font-size: 13px;
        font-weight: 600;
        border-radius: 10px;
        border: none;
        background: linear-gradient(135deg, #4f46e5, #7c3aed);
        color: #ffffff;
        box-shadow: 0 4px 12px rgba(79, 70, 229, 0.35);
        transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        text-decoration: none;
        cursor: pointer;
        font-family: var(--tm-font);
        letter-spacing: 0.01em;
    }
    .btn-reports:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(79, 70, 229, 0.5);
        background: linear-gradient(135deg, #6366f1, #8b5cf6);
        color: #ffffff;
        text-decoration: none;
    }
    .btn-reports:active {
        transform: translateY(0);
        box-shadow: 0 2px 8px rgba(79, 70, 229, 0.3);
    }
    .btn-reports i {
        font-size: 16px;
    }
    /* Mobile reports button - smaller */
    .btn-reports-sm {
        padding: 6px 12px;
        font-size: 12px;
        border-radius: 8px;
    }
    .btn-reports-sm i {
        font-size: 14px;
    }
    /* Reports button in navbar (mobile) */
    .nav-action-icon.btn-reports-nav {
        background: linear-gradient(135deg, #4f46e5, #7c3aed);
        color: #ffffff;
        width: 36px;
        height: 36px;
        border-radius: 8px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        text-decoration: none;
        font-size: 15px;
        box-shadow: 0 2px 8px rgba(79, 70, 229, 0.3);
    }
    .nav-action-icon.btn-reports-nav:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 16px rgba(79, 70, 229, 0.5);
        color: #ffffff;
        text-decoration: none;
    }

    /* GLOBAL SELECT */
    select,.form-select{appearance:none;-webkit-appearance:none;-moz-appearance:none;background-color:rgba(255,255,255,0.05);background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' fill='rgba(255,255,255,0.5)' viewBox='0 0 16 16'%3E%3Cpath d='M1.646 4.646a.5.5 0 0 1 .708 0L8 10.293l5.646-5.647a.5.5 0 0 1 .708.708l-6 6a.5.5 0 0 1-.708 0l-6-6a.5.5 0 0 1 0-.708z'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 14px center;background-size:12px;padding-right:40px;cursor:pointer;border:1.5px solid rgba(255,255,255,0.12);border-radius:var(--tm-radius-sm);color:inherit;font-size:14px;font-family:var(--tm-font);transition:all var(--tm-transition)}
    select:focus,.form-select:focus{outline:none;border-color:var(--tm-primary);box-shadow:0 0 0 3px var(--tm-primary-glow)}
    select option,.form-select option{background:var(--select-bg);color:var(--select-text);padding:10px 14px;font-size:14px;line-height:1.6}
    select option:hover,select option:checked,select option:active{background:var(--select-bg-hover);color:var(--select-text)}
    select option[value=""]{color:var(--select-text-muted);background:var(--select-bg-placeholder)}
    select:disabled{opacity:0.45;cursor:not-allowed}

    /* NAVBAR */
    .page-title h5    .mobile-title-inner{display:flex;justify-content:space-between;align-items:flex-start;gap:12px}
    .mobile-title-left h5{font-size:18px;font-weight:800;letter-spacing:-0.03em;margin:0}
    .mobile-title-left small{font-size:12px;font-weight:500}

    /* STAT CARDS */
    .stat-card{transition:all var(--tm-transition);position:relative;overflow:hidden}
    .stat-card::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;border-radius:var(--tm-radius) var(--tm-radius) 0 0;opacity:0;transition:opacity var(--tm-transition)}
    .stat-card:hover{transform:translateY(-3px)}.stat-card:hover::before{opacity:1}
    .stat-card:nth-child(1)::before{background:#28a745}.stat-card:nth-child(2)::before{background:#dc3545}
    .stat-card:nth-child(3)::before{background:#ffc107}.stat-card:nth-child(4)::before{background:#0066fe}
    @keyframes tmFadeUp{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:translateY(0)}}
    .stat-card{animation:tmFadeUp .5s ease forwards;opacity:0}
    .stat-card:nth-child(1){animation-delay:.05s}.stat-card:nth-child(2){animation-delay:.1s}
    .stat-card:nth-child(3){animation-delay:.15s}.stat-card:nth-child(4){animation-delay:.2s}

    /* FILTER BAR */
    .filter-bar .form-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;margin-bottom:5px}

    /* SESSIONS LIST */
    .session-item{padding:10px 16px;border-bottom:1px solid rgba(255,255,255,0.04);transition:background var(--tm-transition)}
    .session-item:last-child{border-bottom:none}
    .session-item:hover{background:rgba(255,255,255,0.02)}
    .session-badge{font-size:10px;font-weight:700;letter-spacing:.04em;padding:3px 8px;border-radius:5px}
    .session-name{font-size:13px;font-weight:600}
    .session-meta{font-size:11px;opacity:0.5}
    .session-time{font-size:11px;opacity:0.6;font-family:var(--tm-mono)}

    /* TABLE */
    .data-table{margin:0}
    .data-table thead th{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;padding:14px 16px;border-bottom:1px solid rgba(255,255,255,0.06)}
    .data-table tbody td{padding:12px 16px;vertical-align:middle;font-size:13px;border-bottom:1px solid rgba(255,255,255,0.04)}
    .data-table tbody tr{transition:background var(--tm-transition)}.data-table tbody tr:hover{background:rgba(255,255,255,0.02)}

    /* TABLE SCROLL */
    .table-scroll-wrapper{overflow:hidden}
    .table-scroll-wrapper{overflow-x:auto;-webkit-overflow-scrolling:touch}
    .table-scroll-wrapper::-webkit-scrollbar{height:6px}
    .table-scroll-wrapper::-webkit-scrollbar-track{background:rgba(255,255,255,0.03)}
    .table-scroll-wrapper::-webkit-scrollbar-thumb{background:rgba(255,255,255,0.12);border-radius:6px}
    .table-scroll-wrapper::-webkit-scrollbar-thumb:hover{background:rgba(255,255,255,0.2)}
    .data-table{min-width:700px}

    /* MOBILE CARDS — attendance record cards */

    /* EMPTY STATE */
    .empty-state{text-align:center;padding:48px 20px}
    .empty-icon{width:60px;height:60px;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;font-size:24px}
    .empty-state h6{font-weight:700;font-size:16px;margin-bottom:6px}.empty-state p{font-size:13px}

    /* CHART */
    .chart-container{position:relative;width:100%;min-height:180px}
    .chart-container canvas{width:100%!important}

    /* NO SESSIONS */
    .no-sessions{text-align:center;padding:40px 16px;opacity:0.5}
    .no-sessions i{font-size:32px;margin-bottom:8px;display:block}

    /* TABLET */
    @media(max-width:991px){
        .content-area{padding:20px}
    }

    /* MOBILE */
    @media(max-width:767px){
        .top-navbar{padding:12px 14px;flex-wrap:nowrap;gap:8px}.navbar-left{flex:1;gap:10px;min-width:0}
        #sidebarToggle{width:38px;height:38px;font-size:20px;flex-shrink:0}
        .navbar-brand{display:flex}.navbar-brand-logo{width:44px;height:44px}
        .navbar-brand-name{font-size:12px}.navbar-brand-sub{font-size:9px;opacity:0.45}
        .desktop-title{display:none!important}.mobile-title{display:block!important}
        .navbar-actions{gap:6px}
        /* Mobile reports button - now visible */
        .btn-reports-mobile { display: inline-flex !important; }
        .content-area{padding:10px 12px 28px}

        /* Stat cards — 2x2 grid */
        .stat-card{padding:12px 10px;animation:none;opacity:1}
        .stat-value{font-size:20px!important}.stat-label{font-size:9px;margin-top:2px}
        .stat-icon{width:32px;height:32px;font-size:13px;border-radius:8px}

        /* Filter bar — stacked */
        .filter-bar .form-label{font-size:10px}
        .filter-bar .form-control,.filter-bar .form-select{font-size:13px;padding:8px 10px}

        /* Table → mobile scroll */
        .data-table{min-width:600px}
        .data-table thead th{padding:10px 12px;font-size:10px}
        .data-table tbody td{padding:10px 12px;font-size:12px}

        /* Sessions + chart stack */
        .chart-container{min-height:160px}

        /* Section headers */
        .section-header{padding:10px 14px!important}
        .section-header .badge{font-size:10px;padding:3px 8px}
    }

    /* SMALL PHONE */
    @media(max-width:576px){
        .top-navbar{padding:10px 10px}.navbar-brand-logo{width:38px;height:38px}
        .navbar-brand-name{font-size:11px}.navbar-brand-sub{font-size:8px}
        .navbar-actions{gap:4px}
        #sidebarToggle{width:34px;height:34px;font-size:18px}
        .content-area{padding:8px 8px 24px}
        .stat-card{padding:10px 8px}.stat-value{font-size:18px!important}.stat-label{font-size:8px}
        .stat-icon{width:28px;height:28px;font-size:12px;border-radius:7px}
        .chart-container{min-height:140px}
        .sidebar{width:260px}
    }

    @media(min-width:768px){
        .mobile-title{display:none!important}
        .btn-reports-mobile { display: none !important; }
    }
</style>

<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/pages-navbar.css">

<?php require_once __DIR__ . '/../includes/pages-topnavbar.php'; ?>
<div class="main-content">

    <div class="content-area">
        <?= displayFlashMessage() ?>

        <div class="d-none d-md-flex justify-content-between align-items-center gap-3 mb-3">
            <div class="page-title mb-0">
                <h5 class="mb-0">Attendance Overview</h5>
                <small>Daily attendance across gate and class sessions</small>
            </div>
            <!-- ===== UPDATED: Full Reports Button - Now more visible ===== -->
            <a href="<?= BASE_URL ?>/admin/reports.php" class="btn-reports">
                <i class="bi bi-file-earmark-bar-graph"></i> Full Reports
            </a>
        </div>

        <div class="page-title mobile-title">
            <div class="mobile-title-inner">
                <div class="mobile-title-left">
                    <h5>Attendance Overview</h5>
                    <small>Daily attendance across gate &amp; class sessions</small>
                </div>
                <!-- ===== UPDATED: Mobile Reports Button ===== -->
                <a href="<?= BASE_URL ?>/admin/reports.php" class="btn-reports btn-reports-sm btn-reports-mobile">
                    <i class="bi bi-file-earmark-bar-graph"></i> Reports
                </a>
            </div>
        </div>

        <!-- Filter Bar -->
        <div class="card mb-4 filter-bar">
            <div class="card-body py-3">
                <form method="GET" class="row g-2 align-items-end">
                    <div class="col-12 col-md-3">
                        <label class="form-label small fw-600">Date</label>
                        <input type="date" name="date" class="form-control form-control-sm" value="<?= $dateFilter ?>">
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label small fw-600">Status</label>
                        <select name="status" class="form-select form-select-sm">
                            <option value="">All</option>
                            <option value="present" <?= $statusFilter==='present'?'selected':'' ?>>Present</option>
                            <option value="absent"  <?= $statusFilter==='absent'?'selected':'' ?>>Absent</option>
                            <option value="late"    <?= $statusFilter==='late'?'selected':'' ?>>Late</option>
                            <option value="excused" <?= $statusFilter==='excused'?'selected':'' ?>>Excused</option>
                        </select>
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label small fw-600">Grade</label>
                        <select name="grade_level" class="form-select form-select-sm">
                            <option value="">All</option>
                            <?php foreach(['7','8','9','10','11','12'] as $g): ?>
                            <option value="<?= $g ?>" <?= $gradeFilter===$g?'selected':'' ?>>Grade <?= $g ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6 col-md-2">
                        <button type="submit" class="btn btn-primary btn-sm w-100"><i class="bi bi-funnel"></i> Filter</button>
                    </div>
                    <div class="col-6 col-md-2">
                        <a href="<?= BASE_URL ?>/admin/attendance.php" class="btn btn-outline-secondary btn-sm w-100"><i class="bi bi-x-lg"></i> Reset</a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Statistics Cards -->
        <div class="row g-3 mb-4">
            <div class="col-6 col-md-3">
                <div class="stat-card">
                    <div class="d-flex justify-content-between align-items-start">
                        <div><div class="stat-value text-success"><?= $stats['present'] ?></div><div class="stat-label">Present</div></div>
                        <div class="stat-icon" style="background:rgba(40,167,69,0.1);color:#28a745;"><i class="bi bi-check-circle-fill"></i></div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-card">
                    <div class="d-flex justify-content-between align-items-start">
                        <div><div class="stat-value text-danger"><?= $stats['absent'] ?></div><div class="stat-label">Absent</div></div>
                        <div class="stat-icon" style="background:rgba(220,53,69,0.1);color:#dc3545;"><i class="bi bi-x-circle-fill"></i></div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-card">
                    <div class="d-flex justify-content-between align-items-start">
                        <div><div class="stat-value text-warning"><?= $stats['late'] ?></div><div class="stat-label">Late</div></div>
                        <div class="stat-icon" style="background:rgba(255,193,7,0.1);color:#ffc107;"><i class="bi bi-clock-fill"></i></div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-card">
                    <div class="d-flex justify-content-between align-items-start">
                        <div><div class="stat-value text-info"><?= $stats['excused'] ?></div><div class="stat-label">Excused</div></div>
                        <div class="stat-icon" style="background:rgba(6,182,212,0.1);color:#06b6d4;"><i class="bi bi-journal-check"></i></div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-card">
                    <div class="d-flex justify-content-between align-items-start">
                        <div><div class="stat-value"><?= $stats['rate'] ?>%</div><div class="stat-label">Attendance Rate</div></div>
                        <div class="stat-icon" style="background:rgba(0,102,254,0.1);color:#0066fe;"><i class="bi bi-graph-up"></i></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Active Sessions + Weekly Chart -->
        <div class="row g-3 mb-4">
            <div class="col-12 col-md-5">
                <div class="card h-100">
                    <div class="card-header section-header p-3 d-flex justify-content-between align-items-center">
                        <span class="fw-600"><i class="bi bi-broadcast me-2"></i>Active Sessions</span>
                        <span class="badge bg-success"><?= count($activeSessions) ?></span>
                    </div>
                    <div class="card-body p-0">
                        <?php if(empty($activeSessions)): ?>
                            <div class="no-sessions"><i class="bi bi-moon"></i><p class="mb-0">No active sessions</p></div>
                        <?php else: ?>
                            <?php foreach($activeSessions as $session): ?>
                            <div class="session-item">
                                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                                    <div>
                                        <span class="session-badge badge bg-<?= $session['type']==='gate'?'primary':'success' ?>"><?= strtoupper($session['type']) ?></span>
                                        <span class="session-name ms-1"><?= $session['type']==='gate'
                                            ? ucfirst(str_replace('_','-', $session['session_type']))
                                            : (($session['subject_name'] ?: 'Class Session')) ?></span>
                                        <?php if(!empty($session['grade_level'])): ?>
                                            <span class="session-meta ms-1">Grade <?= $session['grade_level'] ?><?= !empty($session['section'])?'-'.$session['section']:'' ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="session-time"><?= date('g:i A',strtotime($session['start_time'])) ?> - <?= date('g:i A',strtotime($session['end_time'])) ?></div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-12 col-md-7">
                <div class="card h-100">
                    <div class="card-header section-header p-3 fw-600" style="justify-content:flex-start;gap:2px;"><i class="bi bi-bar-chart" style="margin-right:0"></i>Weekly Attendance Trend</div>
                    <div class="card-body">
                        <div class="chart-container"><canvas id="weeklyChart"></canvas></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Class Attendance Records -->
        <div class="card mb-4">
            <div class="card-header section-header p-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
                <span class="fw-600"><i class="bi bi-calendar-check me-2"></i>Class Attendance — <?= formatDate($dateFilter) ?></span>
                <span class="badge bg-secondary"><?= count($records) ?> records</span>
            </div>
            <div class="card-body p-0">
                <div class="table-scroll-wrapper">
                    <table class="table table-hover data-table mb-0">
                        <thead><tr><th>Student ID</th><th>Name</th><th>Grade</th><th>Section</th><th>Subject</th><th>Time</th><th>Status</th></tr></thead>
                        <tbody>
                        <?php if(!empty($records)): foreach($records as $r): ?>
                            <tr>
                                <td><span class="fw-600" style="font-family:var(--tm-mono);font-size:12px"><?= sanitize($r['sid']) ?></span></td>
                                <td><?= sanitize($r['last_name'].', '.$r['first_name']) ?></td>
                                <td><?= sanitize($r['grade_level']) ?></td>
                                <td><?= sanitize($r['section']) ?></td>
                                <td><?= sanitize($r['subject_name'] ?? '—') ?></td>
                                <td><?= $r['time']?formatTime($r['time']):'—' ?></td>
                                <td>
                                    <?php $bc='secondary'; if($r['status']==='present')$bc='success'; if($r['status']==='absent')$bc='danger'; if($r['status']==='late')$bc='warning'; if($r['status']==='excused')$bc='info'; ?>
                                    <span class="badge bg-<?= $bc ?>"><?= ucfirst($r['status']) ?></span>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Gate Attendance Records -->
        <div class="card mb-4">
            <div class="card-header section-header p-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
                <span class="fw-600"><i class="bi bi-door-open me-2"></i>Gate Attendance — <?= formatDate($dateFilter) ?></span>
                <span class="badge bg-secondary"><?= count($gateRecords) ?> records</span>
            </div>
            <div class="card-body p-0">
                <div class="table-scroll-wrapper">
                    <table class="table table-hover data-table mb-0">
                        <thead><tr><th>Student ID</th><th>Name</th><th>Grade</th><th>Section</th><th>Type</th><th>Scan Time</th><th>Confidence</th><th>Status</th></tr></thead>
                        <tbody>
                        <?php if(!empty($gateRecords)): foreach($gateRecords as $gr): ?>
                            <tr>
                                <td><span class="fw-600" style="font-family:var(--tm-mono);font-size:12px"><?= sanitize($gr['sid']) ?></span></td>
                                <td><?= sanitize($gr['last_name'].', '.$gr['first_name']) ?></td>
                                <td><?= sanitize($gr['grade_level']) ?></td>
                                <td><?= sanitize($gr['section']) ?></td>
                                <td><span class="badge bg-<?= $gr['session_type']==='time_in'?'info':'primary' ?>"><?= ucfirst(str_replace('_','-',$gr['session_type'])) ?></span></td>
                                <td><?= formatDateTime($gr['scan_time'],'g:i A') ?></td>
                                <td><?php if($gr['confidence_score']): ?><span class="text-success fw-600"><?= number_format($gr['confidence_score'],1) ?>%</span><?php else: ?><span class="text-muted">—</span><?php endif; ?></td>
                                <td>
                                    <?php $gb='secondary'; if($gr['status']==='present')$gb='success'; if($gr['status']==='absent')$gb='danger'; if($gr['status']==='late')$gb='warning'; if($gr['status']==='excused')$gb='info'; ?>
                                    <span class="badge bg-<?= $gb ?>"><?= ucfirst($gr['status']) ?></span>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
(function() {
    'use strict';

    /* WEEKLY CHART */
    var weeklyData = <?= json_encode($weeklyData) ?>;
    var ctx = document.getElementById('weeklyChart');
    if(ctx) {
        var isMobile = window.innerWidth <= 767;
        new Chart(ctx.getContext('2d'), {
            type: 'bar',
            data: {
                labels: weeklyData.map(function(d){return d.day}),
                datasets: [
                    { label:'Present', data:weeklyData.map(function(d){return d.present}), backgroundColor:'rgba(40,167,69,0.7)', borderRadius: isMobile ? 3 : 4 },
                    { label:'Late',    data:weeklyData.map(function(d){return d.late}),    backgroundColor:'rgba(255,193,7,0.7)',  borderRadius: isMobile ? 3 : 4 },
                    { label:'Absent',  data:weeklyData.map(function(d){return d.absent}),  backgroundColor:'rgba(220,53,69,0.7)',  borderRadius: isMobile ? 3 : 4 },
                    { label:'Excused', data:weeklyData.map(function(d){return d.excused||0}), backgroundColor:'rgba(6,182,212,0.7)', borderRadius: isMobile ? 3 : 4 }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            usePointStyle: true,
                            padding: isMobile ? 10 : 15,
                            font: { size: isMobile ? 10 : 12 }
                        }
                    },
                    tooltip: {
                        titleFont: { size: isMobile ? 11 : 13 },
                        bodyFont: { size: isMobile ? 10 : 12 }
                    }
                },
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: { font: { size: isMobile ? 10 : 12 } }
                    },
                    y: {
                        beginAtZero: true,
                        ticks: { stepSize: 1, font: { size: isMobile ? 10 : 12 } }
                    }
                }
            }
        });
    }

})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>