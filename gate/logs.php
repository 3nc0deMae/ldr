<?php
/**
 * View all gate session history and attendance records
 */
require_once __DIR__ . '/../config.php';
requireRole(['admin', 'gate']);

$pageTitle = 'Gate Logs';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

// Filters
$dateFilter  = sanitize($_GET['date'] ?? date('Y-m-d'));
$typeFilter  = sanitize($_GET['session_type'] ?? '');
$statusFilter = sanitize($_GET['status'] ?? '');
$search      = sanitize($_GET['search'] ?? '');

// Build query for attendance records
$sql = "SELECT ar.*, s.first_name, s.last_name, s.student_id, s.grade_level, s.section,
               gs.session_type, gs.start_time as session_start, gs.end_time as session_end
        FROM attendance_records ar
        LEFT JOIN students s ON ar.student_id = s.id
        LEFT JOIN gate_sessions gs ON ar.gate_session_id = gs.id
                 WHERE DATE(ar.scan_time) = :date AND ar.status != 'pending'";
$params = [':date' => $dateFilter];

if ($typeFilter) {
    $sql .= " AND ar.session_type = :type";
    $params[':type'] = $typeFilter;
}
if ($statusFilter) {
    $sql .= " AND ar.status = :status";
    $params[':status'] = $statusFilter;
}
if ($search) {
    $sql .= " AND (s.first_name LIKE :search OR s.last_name LIKE :search2 OR s.student_id LIKE :search3)";
    $params[':search']  = "%$search%";
    $params[':search2'] = "%$search%";
    $params[':search3'] = "%$search%";
}

$sql .= " ORDER BY ar.scan_time DESC LIMIT 500";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$records = $stmt->fetchAll();

// Get gate sessions for the selected date
$sessions = [];
try {
    $stmt = $db->prepare(
        "SELECT * FROM gate_sessions WHERE DATE(start_time) = ? ORDER BY start_time DESC"
    );
    $stmt->execute([$dateFilter]);
    $sessions = $stmt->fetchAll();
} catch (Exception $e) {}

// Summary stats for the day
$stats = [
    'total_scans' => count($records),
    'present'     => 0,
    'late'        => 0,
    'absent'      => 0
];
foreach ($records as $r) {
    if ($r['status'] === 'present') $stats['present']++;
    if ($r['status'] === 'late')    $stats['late']++;
}
?>

<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/pages-theme.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/pages-navbar.css">

<style>
    :root {
        --ad-font: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif;
        --ad-mono: 'JetBrains Mono', monospace;
        --ad-primary: #4f46e5;
        --ad-primary-light: rgba(79,70,229,0.15);
        --ad-primary-dark: #3730a3;
        --ad-primary-glow: rgba(79,70,229,0.2);
        --ad-success: #10b981;
        --ad-success-light: rgba(16,185,129,0.15);
        --ad-danger: #ef4444;
        --ad-danger-light: rgba(239,68,68,0.15);
        --ad-warning: #f59e0b;
        --ad-warning-light: rgba(245,158,17,0.15);
        --ad-info: #06b6d4;
        --ad-info-light: rgba(6,182,212,0.15);
        --ad-radius: 14px;
        --ad-radius-sm: 10px;
        --ad-radius-xs: 8px;
        --ad-shadow-sm: 0 1px 3px rgba(0,0,0,0.2);
        --ad-shadow: 0 4px 16px rgba(0,0,0,0.25);
        --ad-shadow-lg: 0 12px 40px rgba(0,0,0,0.3);
        --ad-transition: 0.2s cubic-bezier(0.4,0,0.2,1);
        --ad-transition-spring: 0.35s cubic-bezier(0.34,1.56,0.64,1);
    }

    /* TOP NAVBAR */
    .page-title h5 { font-size: 20px; font-weight: 800; letter-spacing: -0.03em; margin: 0; }
    .page-title small { font-size: 13px; font-weight: 500; }
    .mobile-title-left h5 { font-size: 18px; font-weight: 800; letter-spacing: -0.03em; margin: 0; }
    .mobile-title-left small { font-size: 12px; font-weight: 500; }

    .stat-card { transition: all var(--ad-transition); }
    .stat-card:hover { transform: translateY(-3px); }
    @keyframes fadeUp { from { opacity: 0; transform: translateY(16px); } to { opacity: 1; transform: translateY(0); } }
    .stat-card { animation: fadeUp 0.5s ease forwards; opacity: 0; }
    .stat-card:nth-child(1) { animation-delay: 0.05s; }
    .stat-card:nth-child(2) { animation-delay: 0.1s; }
    .stat-card:nth-child(3) { animation-delay: 0.15s; }

    @media (max-width: 991px) {
        .content-area { padding: 20px; }
    }

    @media (max-width: 767px) {
        .top-navbar { padding: 12px 14px; flex-wrap: wrap; gap: 0; }
        .navbar-left { flex: 1; gap: 10px; }
        #sidebarToggle { width: 38px; height: 38px; font-size: 20px; flex-shrink: 0; }
        .navbar-brand { display: flex; }
        .navbar-brand-logo { width: 44px; height: 44px; }
        .navbar-brand-name { font-size: 12px; }
        .navbar-brand-sub { font-size: 9px; opacity: 0.45; }
        .desktop-title { display: none !important; }
        .desktop-date { display: none !important; }
        .mobile-title { display: block; }
        .navbar-actions { gap: 6px; }
        .nav-icon-btn { width: 38px; height: 38px; font-size: 15px; }
        .content-area { padding: 10px 12px 28px; }
        .stat-card { padding: 16px; }
        .stat-value { font-size: 24px; }
        .stat-label { font-size: 10px; margin-top: 4px; }
        .stat-icon { width: 38px; height: 38px; font-size: 15px; border-radius: 10px; }
    }

    @media (max-width: 576px) {
        .top-navbar { padding: 10px 10px; }
        .navbar-brand-logo { width: 38px; height: 38px; }
        .navbar-brand-name { font-size: 11px; }
        .navbar-brand-sub { font-size: 8px; }
        .navbar-actions { gap: 4px; }
        .nav-icon-btn { width: 34px; height: 34px; font-size: 14px; }
        #sidebarToggle { width: 34px; height: 34px; font-size: 18px; }
        .mobile-date { font-size: 10px; padding: 5px 8px; }
        .content-area { padding: 8px 8px 24px; }
        .stat-card { padding: 12px 10px; }
        .stat-value { font-size: 20px; }
        .stat-label { font-size: 9px; letter-spacing: 0.04em; }
        .stat-icon { width: 32px; height: 32px; font-size: 13px; }
    }
</style>
<?php require_once __DIR__ . '/../includes/pages-topnavbar.php'; ?>
<div class="main-content">

    <div class="content-area">
        <?= displayFlashMessage() ?>

        <div class="d-none d-md-flex justify-content-between align-items-center gap-3 mb-3">
            <div class="page-title mb-0">
                <h5 class="mb-0">Gate Session Logs</h5>
                <small>View attendance records and gate session history</small>
            </div>
            <div class="d-flex gap-2">
                <a href="<?= BASE_URL ?>/gate/timein.php" class="btn btn-success btn-sm"><i class="bi bi-box-arrow-in-right"></i> Time-In</a>
                <a href="<?= BASE_URL ?>/gate/timeout.php" class="btn btn-warning btn-sm text-dark"><i class="bi bi-box-arrow-right"></i> Time-Out</a>
            </div>
        </div>

        <div class="page-title mobile-title">
            <div class="mobile-title-inner">
                <div class="mobile-title-left">
                    <h5>Gate Session Logs</h5>
                    <small>View attendance records and gate session history</small>
                </div>
                <div class="d-flex gap-2">
                <a href="<?= BASE_URL ?>/gate/timein.php" class="btn btn-success btn-sm"><i class="bi bi-box-arrow-in-right"></i> Time-In</a>
                <a href="<?= BASE_URL ?>/gate/timeout.php" class="btn btn-warning btn-sm text-dark"><i class="bi bi-box-arrow-right"></i> Time-Out</a>
            </div>
            </div>
        </div>

        <!-- Stats Summary -->
        <div class="row g-3 mb-4">
            <div class="col-6 col-md-3">
                <div class="stat-card">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-value"><?= $stats['total_scans'] ?></div>
                            <div class="stat-label">Total Scans</div>
                        </div>
                        <div class="stat-icon bg-primary-soft"><i class="bi bi-qr-code-scan"></i></div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-card">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-value"><?= $stats['present'] ?></div>
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
                            <div class="stat-value"><?= $stats['late'] ?></div>
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
                            <div class="stat-value"><?= count($sessions) ?></div>
                            <div class="stat-label">Sessions</div>
                        </div>
                        <div class="stat-icon bg-info-soft"><i class="bi bi-broadcast"></i></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="card mb-4">
            <div class="card-body py-3">
                <form method="GET" class="row g-2 align-items-end">
                    <div class="col-6 col-md-2">
                        <label class="form-label fs-sm fw-600">Date</label>
                        <input type="date" class="form-control" name="date"
                               value="<?= sanitize($dateFilter) ?>">
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label fs-sm fw-600">Search</label>
                        <input type="text" class="form-control" name="search"
                               value="<?= sanitize($search) ?>"
                               placeholder="Student name or ID...">
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label fs-sm fw-600">Session Type</label>
                        <select name="session_type" class="form-select">
                            <option value="">All Types</option>
                            <option value="time_in"  <?= $typeFilter === 'time_in' ? 'selected' : '' ?>>Time-In</option>
                            <option value="time_out" <?= $typeFilter === 'time_out' ? 'selected' : '' ?>>Time-Out</option>
                        </select>
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label fs-sm fw-600">Status</label>
                        <select name="status" class="form-select">
                            <option value="">All Status</option>
                            <option value="present" <?= $statusFilter === 'present' ? 'selected' : '' ?>>Present</option>
                            <option value="late"    <?= $statusFilter === 'late' ? 'selected' : '' ?>>Late</option>
                        </select>
                    </div>
                    <div class="col-6 col-md-1">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-funnel"></i>
                        </button>
                    </div>
                    <div class="col-6 col-md-2">
                        <a href="<?= BASE_URL ?>/gate/logs.php" class="btn btn-outline-secondary w-100">Clear</a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Sessions Table -->
        <?php if (!empty($sessions)): ?>
        <div class="card mb-4">
            <div class="card-header">
                <span><i class="bi bi-broadcast me-2"></i>Gate Sessions on <?= date('M d, Y', strtotime($dateFilter)) ?></span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Session ID</th>
                                <th>Type</th>
                                <th>Period</th>
                                <th>Start</th>
                                <th>End</th>
                                <th>Late Threshold</th>
                                <th>Status</th>
                                <th>Created</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($sessions as $sess): ?>
                            <tr>
                                <td><code>#<?= $sess['id'] ?></code></td>
                                <td>
                                    <span class="badge bg-<?= $sess['session_type'] === 'time_in' ? 'success' : 'warning' ?>-soft text-<?= $sess['session_type'] === 'time_in' ? 'success' : 'warning' ?>">
                                        <?= ucfirst(str_replace('_', '-', $sess['session_type'])) ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge bg-<?= ($sess['session_period'] ?? '') === 'morning' ? 'info' : 'primary' ?>-soft text-<?= ($sess['session_period'] ?? '') === 'morning' ? 'info' : 'primary' ?>">
                                        <?= ucfirst($sess['session_period'] ?? 'General') ?>
                                    </span>
                                </td>
                                <td><?= date('h:i A', strtotime($sess['start_time'])) ?></td>
                                <td><?= date('h:i A', strtotime($sess['end_time'])) ?></td>
                                <td><?= $sess['late_threshold'] ?> min</td>
                                <td>
                                    <span class="badge-status badge-<?= $sess['status'] === 'active' ? 'present' : 'absent' ?>">
                                        <?= ucfirst($sess['status']) ?>
                                    </span>
                                </td>
                                <td style="font-size:12px;"><?= formatDateTime($sess['created_at']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Attendance Records -->
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-table me-2"></i>Attendance Records</span>
                <span class="badge bg-primary"><?= count($records) ?> record(s)</span>
            </div>
            <div class="card-body p-0">
                <?php if (empty($records)): ?>
                    <div class="empty-state">
                        <div class="empty-icon"><i class="bi bi-qr-code-scan"></i></div>
                        <h6>No Records Found</h6>
                        <p>No attendance records for the selected date/filters</p>
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
                                <th>Type</th>
                                <th>Status</th>
                                <th>Confidence</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($records as $rec): ?>
                            <tr>
                                <td style="font-size:13px;">
                                    <?= date('h:i:s A', strtotime($rec['scan_time'])) ?>
                                </td>
                                <td><code><?= sanitize($rec['student_id'] ?? '-') ?></code></td>
                                <td class="fw-600">
                                    <?= sanitize(($rec['first_name'] ?? '') . ' ' . ($rec['last_name'] ?? '')) ?>
                                </td>
                                <td>
                                    <span class="badge bg-secondary-soft text-secondary">
                                        G<?= $rec['grade_level'] ?? '-' ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge bg-<?= ($rec['session_type'] ?? '') === 'time_in' ? 'success' : 'warning' ?>-soft text-<?= ($rec['session_type'] ?? '') === 'time_in' ? 'success' : 'warning' ?>">
                                        <?= ucfirst(str_replace('_', '-', $rec['session_type'] ?? 'Unknown')) ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge-status badge-<?= $rec['status'] === 'present' ? 'present' : 'late' ?>">
                                        <?= ucfirst($rec['status']) ?>
                                    </span>
                                </td>
                                <td style="font-size:12px;">
                                    <?= !empty($rec['confidence_score']) ? $rec['confidence_score'] . '%' : '-' ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
</div>
         </div>
     </div>
 </div>


 <?php require_once __DIR__ . '/../includes/footer.php'; ?>
