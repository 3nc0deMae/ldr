<?php
/**
 * LDB-FRAS - Gate Personnel Dashboard
 */
require_once __DIR__ . '/../config.php';
requireRole(['admin', 'gate']);

$pageTitle = 'Gate Dashboard';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

// Fetch today's gate logs — one row per student with time-in and time-out
$today = date('Y-m-d');
$stmt = $db->prepare("
    SELECT 
        s.student_id,
        s.first_name,
        s.last_name,
        MIN(CASE WHEN ar.session_type = 'time_in' THEN ar.scan_time END) AS time_in,
        MIN(CASE WHEN ar.session_type = 'time_out' THEN ar.scan_time END) AS time_out,
        MAX(CASE WHEN ar.session_type = 'time_in' THEN ar.status END) AS status_in,
        MAX(CASE WHEN ar.session_type = 'time_out' THEN ar.status END) AS status_out
    FROM attendance_records ar
    JOIN students s ON ar.student_id = s.id
    WHERE DATE(ar.scan_time) = ? AND ar.status != 'pending'
    GROUP BY s.id, s.student_id, s.first_name, s.last_name
    ORDER BY s.last_name ASC
");
$stmt->execute([$today]);
$gateLogs = $stmt->fetchAll();

// Compute stats
$totalTimeIn = 0;
$totalTimeOut = 0;
$totalLate = 0;
foreach ($gateLogs as $log) {
    if ($log['time_in']) $totalTimeIn++;
    if ($log['time_out']) $totalTimeOut++;
    if ($log['status_in'] === 'late' || $log['status_out'] === 'late') $totalLate++;
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
    .date-display { font-size: 13px; font-weight: 600; padding: 8px 16px; border-radius: var(--ad-radius-sm); display: flex; align-items: center; gap: 8px; }
    .date-display i { font-size: 14px; }

    /* STAT CARDS */
    .stat-card { transition: all var(--ad-transition); }
    .stat-card:hover { transform: translateY(-3px); }
    @keyframes fadeUp { from { opacity: 0; transform: translateY(16px); } to { opacity: 1; transform: translateY(0); } }
    .stat-card { animation: fadeUp 0.5s ease forwards; opacity: 0; }
    .stat-card:nth-child(1) { animation-delay: 0.05s; }
    .stat-card:nth-child(2) { animation-delay: 0.1s; }
    .stat-card:nth-child(3) { animation-delay: 0.15s; }
    .stat-card:nth-child(4) { animation-delay: 0.2s; }

    /* TABLET (max-width: 991px) */
    @media (max-width: 991px) {
        .content-area { padding: 20px; }
    }

    /* MOBILE (max-width: 767px) */
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

    /* SMALL PHONE (max-width: 576px) */
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

    /* DataTables – Show entries dropdown */
    .dataTables_wrapper .dataTables_length {
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .dataTables_wrapper .dataTables_length label {
        font-size: 13px;
        font-weight: 600;
        color: #ffffff;
        margin: 0;
        white-space: nowrap;
    }
    .dataTables_wrapper .dataTables_length select {
        appearance: none;
        -webkit-appearance: none;
        -moz-appearance: none;
        background: #fff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%236b7280' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E") right 10px center no-repeat;
        border: 1.5px solid #d1d5db;
        border-radius: 10px;
        padding: 7px 34px 7px 12px;
        font-size: 13px;
        font-weight: 600;
        color: #374151;
        cursor: pointer;
        transition: border-color 0.2s, box-shadow 0.2s;
        min-width: 70px;
    }
    .dataTables_wrapper .dataTables_length select:hover {
        border-color: #4f46e5;
    }
    .dataTables_wrapper .dataTables_length select:focus {
        outline: none;
        border-color: #4f46e5;
        box-shadow: 0 0 0 3px rgba(79,70,229,0.15);
    }
    .dataTables_wrapper .dataTables_length select option {
        padding: 6px 10px;
        font-weight: 500;
    }

    /* DataTables – Search bar */
    .dataTables_wrapper .dataTables_filter {
        text-align: right;
    }
    .dataTables_wrapper .dataTables_filter label {
        font-size: 13px;
        font-weight: 600;
        color: #6b7280;
    }
    .dataTables_wrapper .dataTables_filter input {
        appearance: none;
        border: 1.5px solid #d1d5db;
        border-radius: 10px;
        padding: 7px 12px 7px 34px;
        font-size: 13px;
        color: #374151;
        background: #fff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='15' height='15' viewBox='0 0 24 24' fill='none' stroke='%236b7280' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Ccircle cx='11' cy='11' r='8'/%3E%3Cline x1='21' y1='21' x2='16.65' y2='16.65'/%3E%3C/svg%3E") 10px center no-repeat;
        transition: border-color 0.2s, box-shadow 0.2s;
        max-width: 220px;
    }
    .dataTables_wrapper .dataTables_filter input:hover {
        border-color: #4f46e5;
    }
    .dataTables_wrapper .dataTables_filter input:focus {
        outline: none;
        border-color: #4f46e5;
        box-shadow: 0 0 0 3px rgba(79,70,229,0.15);
    }

    /* DataTables – Pagination */
    .dataTables_wrapper .dataTables_paginate {
        padding-top: 14px;
    }
    .dataTables_wrapper .dataTables_paginate .paginate_button {
        border: none !important;
        background: transparent !important;
        color: #6b7280 !important;
        border-radius: 8px !important;
        padding: 5px 12px !important;
        font-size: 13px;
        font-weight: 600;
        transition: all 0.2s;
    }
    .dataTables_wrapper .dataTables_paginate .paginate_button:hover {
        background: #f3f4f6 !important;
        color: #4f46e5 !important;
    }
    .dataTables_wrapper .dataTables_paginate .paginate_button.current {
        background: #4f46e5 !important;
        color: #fff !important;
        box-shadow: 0 2px 8px rgba(79,70,229,0.3);
    }
    .dataTables_wrapper .dataTables_paginate .paginate_button.disabled {
        opacity: 0.35;
        cursor: not-allowed;
    }

    /* DataTables – Info text */
    .dataTables_wrapper .dataTables_info {
        font-size: 12px;
        font-weight: 500;
        color: #9ca3af;
    }

    /* Mobile: Show entries + Search inline */
    @media (max-width: 576px) {
        .dataTables_wrapper .row.align-items-center {
            display: flex;
            flex-wrap: nowrap;
            gap: 8px;
            align-items: center;
        }
        .dataTables_wrapper .row.align-items-center > .col-sm-6 {
            flex: 1 1 auto;
            width: auto;
            max-width: none;
        }
        .dataTables_wrapper .dataTables_filter {
            text-align: left;
        }
        .dataTables_wrapper .dataTables_filter input {
            max-width: 100%;
            width: 100%;
        }
        .dataTables_wrapper .dataTables_length label {
            font-size: 11px;
        }
        .dataTables_wrapper .dataTables_length select {
            padding: 6px 28px 6px 8px;
            font-size: 12px;
            min-width: 58px;
        }
    }
</style>
<?php require_once __DIR__ . '/../includes/pages-topnavbar.php'; ?>
<div class="main-content">

    <!-- Content Area -->
    <div class="content-area">
        <?= displayFlashMessage() ?>

        <div class="d-none d-md-flex justify-content-between align-items-center gap-3 mb-3">
            <div class="page-title mb-0">
                <h5 class="mb-0">Gate Personnel Dashboard</h5>
                <small>Welcome, <strong><?= sanitize($_SESSION['user_email']) ?></strong></small>
            </div>
            <div class="d-flex gap-2">
                <div class="date-display desktop-date"><i class="bi bi-calendar3"></i> <?= date('l, F j, Y') ?></div>
            </div>
        </div>

        <div class="page-title mobile-title">
            <div class="mobile-title-inner">
                <div class="mobile-title-left">
                    <h5>Gate Personnel Dashboard</h5>
                    <small>Welcome, <strong><?= sanitize($_SESSION['user_email']) ?></strong></small>
                </div>
                <div class="mobile-date">
                    <i class="bi bi-calendar3"></i> <?= date('D, M j, Y') ?>
                </div>
            </div>
        </div>

        <!-- Quick Stats -->
        <div class="row g-3 mb-4">
            <div class="col-6 col-lg-3 col-md-6">
                <div class="stat-card">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-value" id="totalTimeIn"><?= $totalTimeIn ?></div>
                            <div class="stat-label">Total Time-In Today</div>
                        </div>
                        <div class="stat-icon bg-primary-soft"><i class="bi bi-box-arrow-in-right"></i></div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-lg-3 col-md-6">
                <div class="stat-card">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-value" id="totalTimeOut"><?= $totalTimeOut ?></div>
                            <div class="stat-label">Total Time-Out Today</div>
                        </div>
                        <div class="stat-icon bg-success-soft"><i class="bi bi-box-arrow-left"></i></div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-lg-3 col-md-6">
                <div class="stat-card">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-value" id="totalLate"><?= $totalLate ?></div>
                            <div class="stat-label">Late Arrivals Today</div>
                        </div>
                        <div class="stat-icon bg-warning-soft"><i class="bi bi-clock-fill"></i></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="row g-3 mb-4">
            <div class="col-12 col-md-6">
                <div class="card">
                    <div class="card-body text-center p-5">
                        <i class="bi bi-box-arrow-in-right" style="font-size: 48px; color: #28A745;"></i>
                        <h5 class="mt-3">Start Time-In Session</h5>
                        <p class="text-muted">Begin scanning students for morning arrival</p>
                        <a href="<?= BASE_URL ?>/gate/timein.php" class="btn btn-success btn-lg">
                            <i class="bi bi-camera-video"></i> Start Time-In
                        </a>
                    </div>
                </div>
            </div>
            <div class="col-12 col-md-6">
                <div class="card">
                    <div class="card-body text-center p-5">
                        <i class="bi bi-box-arrow-left" style="font-size: 48px; color: #0066FE;"></i>
                        <h5 class="mt-3">Start Time-Out Session</h5>
                        <p class="text-muted">Begin scanning students for dismissal</p>
                        <a href="<?= BASE_URL ?>/gate/timeout.php" class="btn btn-primary btn-lg">
                            <i class="bi bi-camera-video"></i> Start Time-Out
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Gate Logs -->
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center p-3">
                <span>Today's Gate Logs</span>
                <a href="<?= BASE_URL ?>/gate/logs.php" class="btn btn-sm" style="background:#4f46e5;color:#fff;border:none;font-weight:600;">View All</a>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover datatable">
                        <thead>
                            <tr>
                                <th>Student ID</th>
                                <th>Name</th>
                                <th>Time In</th>
                                <th>Time Out</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody id="gateLogsTable">
                            <?php foreach ($gateLogs as $log): 
                                $name = sanitize($log['last_name'] . ', ' . $log['first_name']);
                                $timeIn = $log['time_in'] ? date('h:i A', strtotime($log['time_in'])) : '—';
                                $timeOut = $log['time_out'] ? date('h:i A', strtotime($log['time_out'])) : '—';
                                $status = $log['status_in'] ?? $log['status_out'] ?? '';
                                $badgeClass = 'secondary';
                                if ($status === 'present') $badgeClass = 'success';
                                if ($status === 'late') $badgeClass = 'warning';
                                if ($status === 'absent') $badgeClass = 'danger';
                            ?>
                            <tr>
                                <td><code><?= sanitize($log['student_id']) ?></code></td>
                                <td class="fw-600"><?= $name ?></td>
                                <td><?= $timeIn ?></td>
                                <td><?= $timeOut ?></td>
                                <td><span class="badge bg-<?= $badgeClass ?>"><?= ucfirst($status) ?></span></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
</div>
             </div>
         </div>
     </div>
 </div>

 <script>
 document.addEventListener('DOMContentLoaded', function() {
     setTimeout(function() {
         var label = document.querySelector('.dataTables_wrapper .dataTables_length label');
         if (label) {
             label.childNodes[0].textContent = 'Show entries ';
             if (label.childNodes[2]) label.childNodes[2].textContent = '';
         }
     }, 100);
 });
 </script>

  <?php require_once __DIR__ . '/../includes/footer.php'; ?>
