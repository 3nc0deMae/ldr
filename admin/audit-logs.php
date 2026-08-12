<?php

$pageTitle = 'Audit Logs';

// Load backend-only includes first (no HTML output) so we can handle
// CSV exports before any output is sent to the browser.
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/security.php';

requireRole('admin');

// Filters
$userFilter   = intval($_GET['user_id'] ?? 0);
$actionFilter = sanitize($_GET['action'] ?? '');
$searchFilter = sanitize($_GET['search'] ?? '');
$dateFrom     = sanitize($_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days')));
$dateTo       = sanitize($_GET['date_to'] ?? date('Y-m-d'));

// Pagination
$page    = max(1, intval($_GET['page'] ?? 1));
$perPage = 25;
$offset  = ($page - 1) * $perPage;

$filters = [];
if ($userFilter)    $filters['user_id']  = $userFilter;
if ($actionFilter)  $filters['action']   = $actionFilter;
if ($searchFilter)  $filters['search']   = $searchFilter;
if ($dateFrom)      $filters['date_from']= $dateFrom;
if ($dateTo)        $filters['date_to']  = $dateTo;

// Handle CSV export BEFORE any HTML output
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="audit_logs_' . $dateFrom . '_to_' . $dateTo . '.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Date/Time', 'User', 'Role', 'Action', 'Description', 'IP Address', 'Browser']);
    $exportLogs = getAuditLogs($db, $filters, 99999, 0);
    foreach ($exportLogs as $log) {
        fputcsv($output, [
            $log['created_at'],
            $log['user_email'] ?? 'System',
            $log['user_role'] ?? '-',
            $log['action'],
            $log['description'] ?? '',
            $log['ip_address'] ?? '-',
            parseUserAgent($log['user_agent'] ?? '')
        ]);
    }
    fclose($output);
    exit;
}

// Handle Print All - Direct print without displaying on screen
if (isset($_GET['print_all']) && $_GET['print_all'] === '1') {
    $printOrientation = isset($_GET['orientation']) && $_GET['orientation'] === 'landscape' ? 'landscape' : 'portrait';
    $isLandscape = $printOrientation === 'landscape';
    $pageSize = $isLandscape ? 'A4 landscape' : 'A4 portrait';
    $thPad = $isLandscape ? '6px 8px' : '10px 12px';
    $tdPad = $isLandscape ? '5px 8px' : '8px 12px';
    $thFont = $isLandscape ? '10px' : '11px';
    $tdFont = $isLandscape ? '10px' : '12px';
    $bodyPad = $isLandscape ? '12px 15px' : '20px 25px';
    // Get ALL logs without pagination
    $allLogs = getAuditLogs($db, $filters, 99999, 0);
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>Audit Logs - Print</title>
        <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/print.css">
        <style>
            @page { size: <?= $pageSize ?>; margin: 0 12mm 15mm 12mm; }
            body.print-new-window {
                margin: 0;
                padding: <?= $bodyPad ?>;
                background: #fff;
                font-family: 'Segoe UI', Arial, sans-serif;
                color: #333;
                opacity: 0;
                visibility: hidden;
                height: 0;
                overflow: hidden;
            }
            @media print {
                body.print-new-window {
                    opacity: 1;
                    visibility: visible;
                    height: auto;
                    overflow: visible;
                    padding: <?= $bodyPad ?> !important;
                    background: #fff;
                }
                .print-header-img {
                    display: block !important;
                    max-height: 160px;
                    margin-bottom: 30px;
                }
                .print-table {
                    width: 100%;
                    border-collapse: collapse;
                    font-size: <?= $tdFont ?>;
                    margin-top: 10px;
                }
                .print-table thead {
                    background: #f0f1f4;
                    -webkit-print-color-adjust: exact;
                    print-color-adjust: exact;
                }
                .print-table thead th {
                    padding: <?= $thPad ?>;
                    text-align: left;
                    font-weight: 700;
                    font-size: <?= $thFont ?>;
                    text-transform: uppercase;
                    letter-spacing: 0.05em;
                    color: #555;
                    border-bottom: 2px solid #ddd;
                    white-space: nowrap;
                }
                .print-table tbody td {
                    padding: <?= $tdPad ?>;
                    border-bottom: 1px solid #eee;
                    vertical-align: top;
                    color: #444;
                }
                .print-table tbody tr:last-child td {
                    border-bottom: none;
                }
                .print-table .date-cell {
                    white-space: nowrap;
                }
                .print-table .date-cell .date-part {
                    font-size: 10px;
                    color: #888;
                }
                .print-table .date-cell .time-part {
                    font-size: 12px;
                    font-weight: 600;
                    color: #1e1e2a;
                }
                .print-table .user-cell {
                    font-weight: 600;
                    color: #1e1e2a;
                }
                .print-table .role-cell .role-badge {
                    display: inline-block;
                    padding: 2px 10px;
                    border-radius: 12px;
                    font-size: 9px;
                    font-weight: 700;
                    text-transform: uppercase;
                    letter-spacing: 0.04em;
                    background: #f0f1f4;
                    color: #555;
                    -webkit-print-color-adjust: exact;
                    print-color-adjust: exact;
                }
                .print-table .action-cell {
                    font-weight: 600;
                }
                .print-table .description-cell {
                    max-width: 300px;
                }
                .print-table .ip-cell {
                    font-family: 'Courier New', monospace;
                    font-size: 11px;
                    color: #666;
                }
                .print-table .browser-cell {
                    font-size: 10px;
                    color: #888;
                }
                .print-footer {
                    text-align: center;
                    margin-top: 40px;
                    padding-top: 20px;
                    border-top: 2px solid #eee;
                    font-size: 12px;
                    color: #999;
                }
                .no-data {
                    text-align: center;
                    padding: 60px 20px;
                    color: #999;
                }
                .no-data .icon {
                    font-size: 48px;
                    margin-bottom: 10px;
                }
                .page-break {
                    page-break-after: always;
                }
                .print-table tbody tr {
                    page-break-inside: avoid;
                }
            }
        </style>
        <script>
            window.onload = function() {
                setTimeout(function() {
                    window.print();
                }, 100);
            };
            window.onafterprint = function() {
                window.location.href = 'audit-logs.php';
            };
        </script>
    </head>
    <body class="print-new-window">
        <img src="<?= BASE_URL ?>/assets/images/header.jpg" alt="Header" class="print-header-img" style="width:100%;max-height:160px;object-fit:contain;">
        <div style="text-align:center;font-size:16px;font-weight:800;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:4px;color:#1e293b;">Audit Logs — Complete Security Trail</div>
        <div style="text-align:center;font-size:12px;color:#64748b;margin-bottom:16px;">
            Date Range: <?= date('M d, Y', strtotime($dateFrom)) ?> to <?= date('M d, Y', strtotime($dateTo)) ?>
            &bull; Total Entries: <?= number_format(count($allLogs)) ?>
            &bull; Generated: <?= date('F d, Y g:i A') ?>
        </div>

        <?php if (empty($allLogs)): ?>
            <div class="no-data">
                <div class="icon">📭</div>
                <h3>No Log Entries Found</h3>
                <p>No audit logs match your current filters.</p>
            </div>
        <?php else: ?>
            <table class="print-table">
                <thead>
                    <tr>
                        <th style="width:130px;">Date / Time</th>
                        <th style="width:140px;">User</th>
                        <th style="width:70px;">Role</th>
                        <th style="width:130px;">Action</th>
                        <th>Description</th>
                        <th style="width:110px;">IP Address</th>
                        <th style="width:80px;">Browser</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($allLogs as $log): ?>
                    <tr>
                        <td class="date-cell">
                            <div class="date-part"><?= date('M j, Y', strtotime($log['created_at'])) ?></div>
                            <div class="time-part"><?= date('g:i:s A', strtotime($log['created_at'])) ?></div>
                        </td>
                        <td class="user-cell">
                            <?= !empty($log['user_email']) ? sanitize($log['user_email']) : '<span style="font-style:italic;color:#999;">System</span>' ?>
                        </td>
                        <td class="role-cell">
                            <?php if (!empty($log['user_role'])): ?>
                                <span class="role-badge" style="background:<?= ($log['user_role'] === 'admin') ? '#fef2f2' : (($log['user_role'] === 'teacher') ? '#eff6ff' : '#fefce8') ?>;color:<?= ($log['user_role'] === 'admin') ? '#dc2626' : (($log['user_role'] === 'teacher') ? '#2563eb' : '#d97706') ?>;">
                                    <?= ucfirst($log['user_role']) ?>
                                </span>
                            <?php else: ?>
                                <span style="color:#999;">-</span>
                            <?php endif; ?>
                        </td>
                        <td class="action-cell">
                            <?= sanitize(str_replace('_', ' ', ucfirst($log['action']))) ?>
                        </td>
                        <td class="description-cell">
                            <?= sanitize($log['description'] ?? '') ?>
                        </td>
                        <td class="ip-cell">
                            <?= sanitize($log['ip_address'] ?? '-') ?>
                        </td>
                        <td class="browser-cell">
                            <?= parseUserAgent($log['user_agent'] ?? '') ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <div class="print-footer">
            <p>Generated on <?= date('F d, Y g:i A') ?> &bull; Audit Logs Report &bull; Confidential</p>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Now that non-HTML handlers are done, load the HTML layout
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

$totalCount = countAuditLogs($db, $filters);
$logs       = getAuditLogs($db, $filters, $perPage, $offset);
$totalPages = max(1, ceil($totalCount / $perPage));

// Users for dropdown
$users = [];
try {
    $users = $db->query("SELECT id, email, role FROM users ORDER BY email")->fetchAll();
} catch (Exception $e) {}

// Distinct actions for filter
$actions = [];
try {
    $actions = $db->query("SELECT DISTINCT action FROM audit_logs ORDER BY action")->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {}

// Action icons + colors mapping (shared between PHP and JS)
$actionIcons = [
    'login'         => ['icon' => 'bi-box-arrow-in-right',    'color' => '#10b981'],
    'logout'        => ['icon' => 'bi-box-arrow-left',        'color' => 'rgba(255,255,255,0.3)'],
    'add_student'   => ['icon' => 'bi-person-plus',           'color' => '#60a5fa'],
    'edit_student'  => ['icon' => 'bi-pencil-square',         'color' => '#06b6d4'],
    'delete_student'=> ['icon' => 'bi-person-x',              'color' => '#ef4444'],
    'add_teacher'   => ['icon' => 'bi-person-badge',          'color' => '#60a5fa'],
    'edit_teacher'  => ['icon' => 'bi-pencil',                'color' => '#06b6d4'],
    'delete_teacher'=> ['icon' => 'bi-person-dash',           'color' => '#ef4444'],
    'start_session' => ['icon' => 'bi-play-circle',           'color' => '#10b981'],
    'end_session'   => ['icon' => 'bi-stop-circle',           'color' => '#f59e0b'],
    'settings'      => ['icon' => 'bi-gear',                  'color' => 'rgba(255,255,255,0.4)'],
    'announcement'  => ['icon' => 'bi-megaphone',             'color' => '#f59e0b'],
    'attendance'    => ['icon' => 'bi-calendar-check',        'color' => '#10b981'],
    'export'        => ['icon' => 'bi-download',              'color' => '#06b6d4'],
    'failed_login'  => ['icon' => 'bi-exclamation-triangle',  'color' => '#ef4444'],
];

$roleColors = [
    'admin'   => ['bg' => 'rgba(239,68,68,0.12)', 'fg' => '#f87171'],
    'teacher' => ['bg' => 'rgba(96,165,250,0.12)', 'fg' => '#60a5fa'],
    'gate'    => ['bg' => 'rgba(245,158,11,0.12)', 'fg' => '#fbbf24'],
    'parent'  => ['bg' => 'rgba(6,182,212,0.12)',  'fg' => '#22d3ee'],
];
?>

<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/pages-theme.css">
<style>
/* ═══════════════════════════════════════════════════════════════════════════
   DESIGN SYSTEM — Audit Logs (Dark Theme)
   ═══════════════════════════════════════════════════════════════════════════ */

:root {
    --aud-font: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif;
    --aud-mono: 'JetBrains Mono', 'SF Mono', monospace;

    --aud-primary: #4f46e5;
    --aud-primary-glow: rgba(79,70,229,0.25);
    --aud-accent: #4f46e5;
    --aud-accent-soft: rgba(79,70,229,0.15);
    --aud-accent-hover: #4338ca;

    --aud-success: #10b981;
    --aud-success-soft: rgba(16,185,129,0.15);
    --aud-danger: #ef4444;
    --aud-danger-soft: rgba(239,68,68,0.15);
    --aud-warning: #f59e0b;
    --aud-warning-soft: rgba(245,158,11,0.15);
    --aud-info: #06b6d4;
    --aud-info-soft: rgba(6,182,212,0.15);

    --aud-bg: #0b0b14;
    --aud-surface: rgba(255,255,255,0.04);
    --aud-surface-hover: rgba(255,255,255,0.07);
    --aud-surface-card: rgba(255,255,255,0.04);
    --aud-border: rgba(255,255,255,0.06);
    --aud-text: #f0ece4;
    --aud-text-secondary: rgba(255,255,255,0.55);
    --aud-text-muted: rgba(255,255,255,0.3);

    --aud-shadow-sm: 0 1px 3px rgba(0,0,0,0.3);
    --aud-shadow: 0 4px 16px rgba(0,0,0,0.25);
    --aud-shadow-md: 0 8px 24px rgba(0,0,0,0.3);
    --aud-shadow-lg: 0 12px 40px rgba(0,0,0,0.35);
    --aud-shadow-xl: 0 20px 50px rgba(0,0,0,0.4);

    --aud-radius-sm: 10px;
    --aud-radius: 14px;
    --aud-radius-lg: 16px;
    --aud-transition: 0.2s cubic-bezier(0.4, 0, 0.2, 1);
    --aud-spring: 0.35s cubic-bezier(0.34,1.56,0.64,1);

    /* Table uses a light background for readability */
    --tbl-bg: #ffffff;
    --tbl-bg-hover: #f8f9fb;
    --tbl-bg-thead: #f4f5f7;
    --tbl-text: #1e1e2a;
    --tbl-text-secondary: #5a5a72;
    --tbl-text-muted: #9b9bb0;
    --tbl-border: #e8e9ed;
    --tbl-border-light: #f0f1f4;
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
    font-size: 20px; font-weight: 800; color: var(--aud-text);
    margin: 0; letter-spacing: -0.03em;
    display: flex; align-items: center; gap: 8px;
}
.top-navbar .page-title h5 i { color: var(--aud-accent); font-size: 22px; }
.top-navbar .page-title small {
    font-size: 13px; color: var(--aud-text-secondary); font-weight: 500;
}

/* MOBILE PAGE TITLE */
.mobile-title { display: none; padding: 14px 0 4px; }
.mobile-title-inner { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; }
.mobile-title-left h5 { font-size: 18px; font-weight: 800; letter-spacing: -0.03em; margin: 0; color: var(--aud-text); display: flex; align-items: center; gap: 7px; }
.mobile-title-left h5 i { color: var(--aud-accent); font-size: 18px; }
.mobile-title-left small { font-size: 12px; font-weight: 500; color: var(--aud-text-secondary); }
.mobile-date { font-size: 11px; font-weight: 600; padding: 6px 10px; border-radius: var(--aud-radius-sm); display: flex; align-items: center; gap: 6px; white-space: nowrap; flex-shrink: 0; margin-top: 2px; background: var(--aud-surface); color: var(--aud-text-secondary); }
.mobile-date i { font-size: 12px; color: var(--aud-accent); }

.navbar-actions { display: flex; align-items: center; gap: 10px; }
.navbar-actions .date-pill {
    font-size: 13px; font-weight: 600; padding: 8px 16px;
    border-radius: var(--aud-radius-sm);
    background: var(--aud-surface); border: none;
    color: var(--aud-text-secondary);
    display: flex; align-items: center; gap: 8px;
}
.navbar-actions .date-pill i { font-size: 14px; color: var(--aud-accent); }

/* NAV ICON BUTTON */
.nav-icon-btn {
    width: 40px; height: 40px;
    border-radius: var(--aud-radius-sm);
    background: var(--aud-surface); border: none;
    color: var(--aud-text);
    display: inline-flex; align-items: center; justify-content: center;
    cursor: pointer; transition: all var(--aud-transition); font-size: 16px;
}
.nav-icon-btn:hover { transform: translateY(-1px); background: var(--aud-surface-hover); }

.content-area { padding: 24px 28px 40px; }

/* ─── Cards ───────────────────────────────────────────────────────────── */
.card {
    background: var(--aud-surface-card);
    border: none;
    border-radius: var(--aud-radius);
    box-shadow: var(--aud-shadow-sm);
    transition: all var(--aud-transition);
    overflow: hidden;
}
.card:hover { box-shadow: var(--aud-shadow); }
.card-header {
    background: transparent;
    border-bottom: 1px solid var(--aud-border);
    padding: 14px 20px;
    font-size: 14px; font-weight: 700;
    color: var(--aud-text);
    letter-spacing: -0.01em;
    display: flex; align-items: center; justify-content: space-between;
}
.card-header i { color: var(--aud-accent); font-size: 15px; }
.card-body { padding: 20px; color: var(--aud-text); }

/* ─── Stat Cards ──────────────────────────────────────────────────────── */
.stat-card {
    background: var(--aud-surface-card);
    border: none;
    border-radius: var(--aud-radius);
    padding: 20px;
    box-shadow: var(--aud-shadow-sm);
    transition: all var(--aud-transition);
    position: relative;
    overflow: hidden;
    animation: fadeUp 0.5s ease forwards;
    opacity: 0;
}
.stat-card::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 3px;
    border-radius: var(--aud-radius) var(--aud-radius) 0 0;
    opacity: 0;
    transition: opacity var(--aud-transition);
}
.stat-card:hover { transform: translateY(-3px); box-shadow: var(--aud-shadow-md); }
.stat-card:hover::before { opacity: 1; }
.stat-card:nth-child(1) { animation-delay: 0.05s; }
.stat-card:nth-child(1)::before { background: var(--aud-accent); }
.stat-card:nth-child(2) { animation-delay: 0.1s; }
.stat-card:nth-child(2)::before { background: var(--aud-info); }
.stat-card:nth-child(3) { animation-delay: 0.15s; }
.stat-card:nth-child(3)::before { background: var(--aud-success); }
.stat-card:nth-child(4) { animation-delay: 0.2s; }
.stat-card:nth-child(4)::before { background: var(--aud-warning); }
.stat-value {
    font-size: 28px; font-weight: 800; color: var(--aud-text);
    font-family: var(--aud-mono); letter-spacing: -0.03em;
    line-height: 1.2;
}
.stat-label {
    font-size: 11px; font-weight: 600; color: var(--aud-text-muted);
    text-transform: uppercase; letter-spacing: 0.06em;
    margin-top: 6px;
}
.stat-icon {
    width: 44px; height: 44px;
    border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    font-size: 18px; flex-shrink: 0;
}
.bg-primary-soft { background: var(--aud-accent-soft); color: var(--aud-accent); }
.bg-info-soft { background: var(--aud-info-soft); color: var(--aud-info); }
.bg-success-soft { background: var(--aud-success-soft); color: var(--aud-success); }
.bg-warning-soft { background: var(--aud-warning-soft); color: var(--aud-warning); }

/* ─── Filter Card ─────────────────────────────────────────────────────── */
.filter-card .card-body { padding: 16px 20px; }
.filter-label {
    display: block;
    font-size: 11px; font-weight: 700;
    color: var(--aud-text-muted);
    text-transform: uppercase; letter-spacing: 0.06em;
    margin-bottom: 6px;
}

/* ─── Form Controls ───────────────────────────────────────────────────── */
.aud-input,
.aud-select {
    width: 100%;
    padding: 9px 14px;
    border: 1.5px solid var(--aud-border);
    border-radius: var(--aud-radius-sm);
    font-size: 13px; font-weight: 500;
    color: var(--aud-text);
    background: var(--aud-surface);
    transition: all var(--aud-transition);
    font-family: var(--aud-font);
}
.aud-input:focus,
.aud-select:focus {
    border-color: var(--aud-accent);
    box-shadow: 0 0 0 3px var(--aud-primary-glow);
    outline: none;
    background: var(--aud-surface-hover);
}
.aud-input::placeholder { color: var(--aud-text-muted); }
.aud-select {
    appearance: none;
    -webkit-appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' fill='rgba(255,255,255,0.4)' viewBox='0 0 16 16'%3E%3Cpath d='M8 11L3 6h10z'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 12px center;
    padding-right: 32px;
    cursor: pointer;
}
.aud-select option { background: #1a1a2e; color: var(--aud-text); }

/* ─── Buttons ─────────────────────────────────────────────────────────── */
.btn-filter {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 9px 20px;
    border: none;
    border-radius: var(--aud-radius-sm);
    font-size: 13px; font-weight: 700;
    color: #fff;
    background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
    box-shadow: 0 2px 12px rgba(79,70,229,0.3);
    cursor: pointer;
    transition: all var(--aud-spring);
}
.btn-filter:hover {
    transform: translateY(-1px);
    box-shadow: 0 6px 20px rgba(79,70,229,0.4);
    color: #fff;
}
.btn-clear {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 38px; height: 38px;
    border: 1.5px solid var(--aud-border);
    border-radius: var(--aud-radius-sm);
    background: var(--aud-surface);
    color: var(--aud-text-muted);
    cursor: pointer;
    transition: all var(--aud-transition);
    font-size: 14px;
}
.btn-clear:hover {
    border-color: var(--aud-danger);
    color: var(--aud-danger);
    background: var(--aud-danger-soft);
}

/* Export + Print buttons */
.btn-export {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 9px 18px;
    border: none;
    border-radius: var(--aud-radius-sm);
    font-size: 12px; font-weight: 700;
    color: #fff;
    background: linear-gradient(135deg, #059669 0%, #10b981 100%);
    box-shadow: 0 2px 12px rgba(16,185,129,0.25);
    cursor: pointer;
    text-decoration: none;
    transition: all var(--aud-spring);
}
.btn-export:hover {
    transform: translateY(-1px);
    box-shadow: 0 6px 20px rgba(16,185,129,0.35);
    color: #fff;
    text-decoration: none;
}
.btn-print {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 9px 18px;
    border: 1.5px solid rgba(255,255,255,0.1);
    border-radius: var(--aud-radius-sm);
    font-size: 12px; font-weight: 700;
    color: var(--aud-text-secondary);
    background: var(--aud-surface);
    cursor: pointer;
    transition: all var(--aud-transition);
    text-decoration: none;
}
.btn-print:hover {
    border-color: rgba(255,255,255,0.2);
    color: var(--aud-text);
    background: var(--aud-surface-hover);
    text-decoration: none;
}

/* ─── Audit Table ─────────────────────────────────────────────────────── */
.audit-table-wrapper {
    background: var(--tbl-bg);
    border-radius: 0 0 var(--aud-radius) var(--aud-radius);
    overflow: hidden;
}
.audit-table-scroll {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}
.audit-table {
    font-size: 13px;
    color: var(--tbl-text);
    background: var(--tbl-bg);
    margin: 0;
    min-width: 900px;
}
.audit-table thead { background: var(--tbl-bg-thead); }
.audit-table thead th {
    padding: 12px 16px;
    font-size: 11px; font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.06em;
    color: var(--tbl-text-muted);
    border-bottom: 1px solid var(--tbl-border);
    background: transparent;
    white-space: nowrap;
}
.audit-table tbody td {
    padding: 14px 16px;
    border-bottom: 1px solid var(--tbl-border-light);
    vertical-align: middle;
    color: var(--tbl-text-secondary);
}
.audit-table tbody tr { transition: background 0.15s ease; background: var(--tbl-bg); }
.audit-table tbody tr:hover { background: var(--tbl-bg-hover); }
.audit-table tbody tr:last-child td { border-bottom: none; }

.audit-table .cell-datetime { white-space: nowrap; }
.audit-table .cell-datetime .date-part { font-size: 11px; color: var(--tbl-text-muted); }
.audit-table .cell-datetime .time-part { font-size: 13px; font-weight: 600; color: var(--tbl-text); font-family: var(--aud-mono); }
.audit-table .cell-user { font-weight: 600; color: var(--tbl-text); font-size: 13px; }
.audit-table .cell-description { font-size: 13px; color: var(--tbl-text-secondary); max-width: 280px; }
.audit-table .cell-ip { font-size: 12px; font-family: var(--aud-mono); color: var(--tbl-text-muted); }
.audit-table .cell-browser { font-size: 11px; color: var(--tbl-text-muted); }

/* Action badge */
.action-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    font-weight: 600;
    color: var(--tbl-text);
}
.action-badge i { font-size: 14px; }

/* Role badge */
.role-badge {
    display: inline-flex;
    align-items: center;
    padding: 3px 10px;
    border-radius: 20px;
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.04em;
}

/* ─── Empty State ─────────────────────────────────────────────────────── */
.empty-state { text-align: center; padding: 60px 24px; }
.empty-state .empty-icon {
    width: 72px; height: 72px; border-radius: 20px;
    background: var(--aud-accent-soft); color: var(--aud-accent);
    display: inline-flex; align-items: center; justify-content: center;
    font-size: 28px; margin-bottom: 16px;
}
.empty-state h6 { font-size: 16px; font-weight: 700; color: var(--aud-text); margin-bottom: 6px; }
.empty-state p { font-size: 13px; color: var(--aud-text-muted); max-width: 320px; margin: 0 auto; }

/* ─── Pagination ──────────────────────────────────────────────────────── */
.pagination-wrapper {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 14px 20px;
    border-top: 1px solid var(--tbl-border);
    background: var(--tbl-bg);
}
.pagination-info {
    font-size: 12px; font-weight: 600;
    color: var(--tbl-text-muted);
}
.pagination {
    display: flex;
    gap: 4px;
    list-style: none;
    padding: 0;
    margin: 0;
}
.page-btn {
    min-width: 34px; height: 34px;
    display: inline-flex;
    align-items: center; justify-content: center;
    border: 1px solid var(--tbl-border);
    border-radius: 8px;
    font-size: 12px; font-weight: 600;
    color: var(--tbl-text-secondary);
    background: var(--tbl-bg);
    text-decoration: none;
    transition: all var(--aud-transition);
    cursor: pointer;
    padding: 0 8px;
}
.page-btn:hover {
    border-color: var(--aud-accent);
    color: var(--aud-accent);
    background: rgba(79,70,229,0.06);
    text-decoration: none;
}
.page-btn.active {
    background: var(--aud-accent);
    border-color: var(--aud-accent);
    color: #fff;
    box-shadow: 0 2px 8px rgba(79,70,229,0.3);
}
.page-btn.disabled {
    opacity: 0.3;
    pointer-events: none;
}

/* ─── Legend Card ──────────────────────────────────────────────────────── */
.legend-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 4px; }
.legend-item {
    display: flex; align-items: center; gap: 8px;
    padding: 8px 10px;
    border-radius: 8px;
    transition: background var(--aud-transition);
}
.legend-item:hover { background: var(--aud-surface-hover); }
.legend-item i { font-size: 15px; flex-shrink: 0; }
.legend-item small { font-size: 12px; color: var(--aud-text-secondary); }
.legend-item small strong { color: var(--aud-text); font-weight: 700; }

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

/* ─── Count Pill ──────────────────────────────────────────────────────── */
.count-pill {
    background: var(--aud-accent-soft); color: var(--aud-accent);
    font-size: 11px; font-weight: 700; padding: 3px 10px;
    border-radius: 20px; font-family: var(--aud-mono);
}

/* ─── Toasts ──────────────────────────────────────────────────────────── */
.toast-container {
    position: fixed; bottom: 28px; right: 28px; z-index: 9999;
    display: flex; flex-direction: column; gap: 10px;
    pointer-events: none;
}
.aud-toast {
    padding: 14px 22px; border-radius: var(--aud-radius-sm);
    color: #fff; font-size: 13px; font-weight: 600;
    display: flex; align-items: center; gap: 12px;
    max-width: 400px; pointer-events: auto;
    animation: toastIn 0.4s cubic-bezier(0.34,1.56,0.64,1), toastOut 0.4s ease 3.6s forwards;
    font-family: var(--aud-font);
    box-shadow: 0 8px 24px rgba(0,0,0,0.5);
}
.aud-toast.success { background: #059669; }
.aud-toast.danger  { background: #dc2626; }
.aud-toast.warning { background: #d97706; color: #1a1a1a; }
.aud-toast.info    { background: #2563eb; }
.aud-toast i { font-size: 18px; flex-shrink: 0; opacity: 0.9; }
@keyframes toastIn { from { opacity: 0; transform: translateX(40px) scale(0.95); } to { opacity: 1; transform: translateX(0) scale(1); } }
@keyframes toastOut { from { opacity: 1; transform: translateX(0); } to { opacity: 0; transform: translateX(40px); } }

/* ─── Scrollbar ───────────────────────────────────────────────────────── */
::-webkit-scrollbar { width: 6px; height: 6px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.1); border-radius: 3px; }
::-webkit-scrollbar-thumb:hover { background: rgba(255,255,255,0.2); }

/* ═══════════════════════════════════════════════════════════════════════════
   RESPONSIVE — TABLET (max-width: 991px)
   ═══════════════════════════════════════════════════════════════════════════ */
@media (max-width: 991px) {
    .content-area { padding: 20px; }
    .top-navbar { padding: 16px; }
    .legend-grid { grid-template-columns: repeat(2, 1fr); }
}

/* ═══════════════════════════════════════════════════════════════════════════
   RESPONSIVE — MOBILE (max-width: 767px)
   ═══════════════════════════════════════════════════════════════════════════ */
@media (max-width: 767px) {
    /* ─── Navbar ──────────────────────────────────────────────────────── */
    .top-navbar { padding: 12px 14px; flex-wrap: nowrap; gap: 8px; }
    .navbar-left { flex: 1; gap: 10px; min-width: 0; }
    #sidebarToggle { width: 38px; height: 38px; font-size: 20px; flex-shrink: 0; }
    .navbar-brand { display: flex; }
    .navbar-brand-logo { width: 44px; height: 44px; }
    .navbar-brand-name { font-size: 12px; }
    .navbar-brand-sub { font-size: 9px; opacity: 0.45; }
    .desktop-title { display: none !important; }
    .desktop-date { display: none !important; }
    .mobile-title { display: block !important; }
    .navbar-actions { gap: 6px; }
    .nav-icon-btn { width: 38px; height: 38px; font-size: 15px; }

    /* ─── Content ─────────────────────────────────────────────────────── */
    .content-area { padding: 10px 12px 28px; }
    .card { border-radius: var(--aud-radius-sm); }
    .card-header { font-size: 13px; padding: 12px 16px; }
    .card-body { padding: 16px; }

    /* ─── Stat Cards ──────────────────────────────────────────────────── */
    .stat-card { padding: 16px; }
    .stat-value { font-size: 22px; }
    .stat-label { font-size: 10px; }
    .stat-icon { width: 38px; height: 38px; font-size: 15px; border-radius: 10px; }

    /* ─── Filters ─────────────────────────────────────────────────────── */
    .filter-card .card-body { padding: 14px; }
    .filter-label { font-size: 10px; margin-bottom: 4px; }
    .aud-input, .aud-select { padding: 8px 12px; font-size: 12px; }
    .btn-filter { padding: 8px 16px; font-size: 12px; }
    .btn-clear { width: 34px; height: 34px; font-size: 13px; }

    /* ─── Action Buttons ──────────────────────────────────────────────── */
    .btn-export { padding: 7px 12px; font-size: 11px; }
    .btn-export .btn-text { display: none; }
    .btn-print { padding: 7px 12px; font-size: 11px; }
    .btn-print .btn-text { display: none; }

    /* ─── Table ───────────────────────────────────────────────────────── */
    .audit-table { min-width: 750px; }
    .audit-table thead th { font-size: 10px; padding: 10px 12px; }
    .audit-table tbody td { padding: 10px 12px; }
    .audit-table .cell-datetime .date-part { font-size: 10px; }
    .audit-table .cell-datetime .time-part { font-size: 12px; }
    .audit-table .cell-user { font-size: 12px; }
    .audit-table .cell-description { font-size: 12px; max-width: 200px; }
    .audit-table .cell-ip { font-size: 11px; }
    .audit-table .cell-browser { font-size: 10px; }
    .action-badge { font-size: 11px; gap: 5px; }
    .action-badge i { font-size: 13px; }
    .role-badge { font-size: 9px; padding: 2px 8px; }

    /* ─── Pagination ──────────────────────────────────────────────────── */
    .pagination-wrapper {
        flex-direction: column;
        gap: 10px;
        padding: 12px 16px;
        text-align: center;
    }
    .page-btn { min-width: 30px; height: 30px; font-size: 11px; }

    /* ─── Legend ──────────────────────────────────────────────────────── */
    .legend-grid { grid-template-columns: 1fr; }
    .legend-item { padding: 6px 8px; }
    .legend-item small { font-size: 11px; }

    /* ─── Empty State ─────────────────────────────────────────────────── */
    .empty-state { padding: 40px 20px; }
    .empty-state .empty-icon { width: 56px; height: 56px; font-size: 22px; }
    .empty-state h6 { font-size: 14px; }
    .empty-state p { font-size: 12px; }

    /* ─── Toasts ──────────────────────────────────────────────────────── */
    .toast-container { bottom: 24px; right: 12px; left: 12px; }
    .aud-toast { max-width: 100%; font-size: 12px; padding: 12px 16px; }

    /* ─── Animations ──────────────────────────────────────────────────── */
    .animate-in { animation: none; opacity: 1; }
    .stat-card { animation: none; opacity: 1; }
}

.print-header-img { display: none; }

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
    .mobile-date { font-size: 10px; padding: 5px 8px; }
    .content-area { padding: 8px 8px 24px; }

    .card { border-radius: 10px; }
    .card-header { font-size: 12px; padding: 10px 12px; }
    .card-body { padding: 12px; }

    .stat-card { padding: 12px 10px; }
    .stat-value { font-size: 18px; }
    .stat-label { font-size: 9px; letter-spacing: 0.04em; }
    .stat-icon { width: 32px; height: 32px; font-size: 13px; }

    .aud-input, .aud-select { padding: 7px 10px; font-size: 11px; }
    .filter-label { font-size: 9px; }

    .btn-export { padding: 6px 10px; font-size: 10px; }
    .btn-print { padding: 6px 10px; font-size: 10px; }

    .audit-table { min-width: 700px; }
    .audit-table thead th { font-size: 9px; padding: 8px 8px; }
    .audit-table tbody td { padding: 8px 8px; }

    .page-btn { min-width: 28px; height: 28px; font-size: 10px; }

    .legend-item i { font-size: 13px; }
    .legend-item small { font-size: 10px; }

    .count-pill { font-size: 10px; padding: 2px 8px; }

    .aud-toast { font-size: 11px; padding: 10px 14px; gap: 10px; }
    .aud-toast i { font-size: 16px; }

    .sidebar { width: 260px; }
}

/* ─── Desktop — hide mobile-only elements ─────────────────────────────── */
@media (min-width: 768px) {
    .mobile-title { display: none !important; }
}

/* ═══════════════════════════════════════════════════════════════════════════
   PRINT STYLES - Hide UI, show only table data
   ═══════════════════════════════════════════════════════════════════════════ */
@media print {
    /* Hide all navigation, filters, and UI elements */
    .sidebar, 
    .sidebar-overlay, 
    .top-navbar, 
    .mobile-title,
    .navbar-brand, 
    .toast-container, 
    .pagination-wrapper,
    .btn-export, 
    .btn-print, 
    .btn-filter, 
    .btn-clear,
    .filter-card, 
    .legend-card,
    .navbar-actions,
    .desktop-title { 
        display: none !important; 
    }
    
    /* HIDE STAT CARDS IN PRINT */
    .stat-card { 
        display: none !important; 
    }
    
    /* Hide the stats row container */
    .row.g-3.mb-4 { 
        display: none !important; 
    }
    
    /* Main content layout for print */
    .main-content { 
        margin-left: 0 !important; 
        padding: 0 !important; 
        background: #fff !important; 
        width: 100% !important;
    }
    
    .content-area { 
        padding: 20px !important; 
        max-width: 100% !important;
    }
    
    /* Cards styling for print */
    .card { 
        box-shadow: none !important; 
        border: 1px solid #ddd !important; 
        background: #fff !important;
        border-radius: 4px !important;
        margin-bottom: 0 !important;
    }
    
    .card-header {
        background: #f5f5f5 !important;
        border-bottom: 1px solid #ddd !important;
        color: #333 !important;
        padding: 10px 16px !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    
    .card-header i {
        color: #333 !important;
    }
    
    .card-body {
        padding: 0 !important;
    }
    
    /* Table styling for print */
    .audit-table-wrapper {
        background: #fff !important;
        border-radius: 0 !important;
        overflow: visible !important;
    }
    
    .audit-table-scroll {
        overflow: visible !important;
    }
    
    .audit-table {
        min-width: auto !important;
        width: 100% !important;
        font-size: 11px !important;
        border-collapse: collapse !important;
        background: #fff !important;
        color: #333 !important;
    }
    
    .audit-table thead {
        background: #f0f0f0 !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    
    .audit-table thead th {
        color: #333 !important;
        background: #f0f0f0 !important;
        border-bottom: 2px solid #333 !important;
        padding: 8px 10px !important;
        font-size: 10px !important;
        text-transform: uppercase !important;
        letter-spacing: 0.04em !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    
    .audit-table tbody td {
        color: #333 !important;
        padding: 8px 10px !important;
        border-bottom: 1px solid #e0e0e0 !important;
        font-size: 10px !important;
    }
    
    .audit-table tbody tr {
        background: #fff !important;
    }
    
    .audit-table tbody tr:hover {
        background: #fff !important;
    }
    
    .audit-table .cell-datetime .date-part {
        color: #666 !important;
        font-size: 9px !important;
    }
    
    .audit-table .cell-datetime .time-part {
        color: #333 !important;
        font-size: 11px !important;
    }
    
    .audit-table .cell-user {
        color: #333 !important;
        font-size: 11px !important;
    }
    
    .audit-table .cell-description {
        color: #555 !important;
        font-size: 10px !important;
        max-width: none !important;
    }
    
    .audit-table .cell-ip {
        color: #666 !important;
        font-size: 10px !important;
    }
    
    .audit-table .cell-browser {
        color: #666 !important;
        font-size: 9px !important;
    }
    
    /* Action badge in print */
    .action-badge {
        color: #333 !important;
        font-size: 10px !important;
    }
    
    .action-badge i {
        font-size: 12px !important;
    }
    
    /* Role badge in print */
    .role-badge {
        font-size: 8px !important;
        padding: 2px 6px !important;
        border: 1px solid #ccc !important;
        border-radius: 12px !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    
    /* Count pill in print */
    .count-pill {
        background: #f0f0f0 !important;
        color: #333 !important;
        border: 1px solid #ddd !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    
    /* Page break control */
    .page-break {
        page-break-after: always;
    }
    
    /* Ensure table doesn't break awkwardly */
    .audit-table tbody tr {
        page-break-inside: avoid;
    }
    
    /* Empty state for print */
    .empty-state {
        padding: 40px 20px !important;
    }
    
    .empty-state .empty-icon {
        background: #f0f0f0 !important;
        color: #666 !important;
    }
    
    .empty-state h6 {
        color: #333 !important;
    }
    
    .empty-state p {
        color: #666 !important;
    }
    
    .print-header-img {
        display: block !important;
        max-height: 160px;
        margin-bottom: 30px;
    }
    body { padding: 0 !important; }
    .content-area { padding-top: 10px !important; }
    @page { margin-top: 0; }
}
</style>

<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/pages-navbar.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/print.css">
<style>
@media print {
    body { padding: 0 !important; }
}
</style>

<!-- Toast Container -->
<div class="toast-container" id="toastContainer"></div>

<?php require_once __DIR__ . '/../includes/pages-topnavbar.php'; ?>
<div class="main-content">

    <div class="content-area">
        <img src="<?= BASE_URL ?>/assets/images/header.jpg" alt="Header" class="print-header-img" style="width:100%;max-height:160px;object-fit:contain;">

        <div class="d-none d-md-flex justify-content-between align-items-center gap-3 mb-3">
            <div class="page-title mb-0">
                <h5 class="mb-0"> Audit Logs</h5>
                <small>Security audit trail and activity tracking</small>
            </div>
            <div class="d-flex gap-2">
                <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>" class="btn-export"><i class="bi bi-file-earmark-excel"></i> Export CSV</a>
                <select id="auditPrintOrientation" class="aud-select" style="width:auto;display:inline-block;" onchange="var q=new URLSearchParams(window.location.search);q.set('print_all','1');q.set('orientation',this.value);document.getElementById('auditPrintAllLink').href='?'+q.toString()">
                    <option value="portrait" <?= (!isset($_GET['orientation']) || $_GET['orientation'] !== 'landscape') ? 'selected' : '' ?>>Portrait</option>
                    <option value="landscape" <?= (isset($_GET['orientation']) && $_GET['orientation'] === 'landscape') ? 'selected' : '' ?>>Landscape</option>
                </select>
                <a href="?<?= http_build_query(array_merge($_GET, ['print_all' => '1'])) ?>" class="btn-print" id="auditPrintAllLink"><i class="bi bi-printer"></i> Print All</a>
            </div>
        </div>

        <!-- MOBILE TITLE -->
        <div class="page-title mobile-title">
            <div class="mobile-title-inner">
                <div class="mobile-title-left">
                    <h5><i></i> Audit Logs</h5>
                    <small>Security audit trail and activity tracking</small>
                </div>
            </div>
        </div>

        <!-- MOBILE ACTIONS (below title) -->
        <div class="d-flex d-md-none gap-2 align-items-center mb-3" style="flex-wrap:wrap;">
            <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>" class="btn-export"><i class="bi bi-file-earmark-excel"></i> <span class="btn-text">Export CSV</span></a>
            <select id="auditPrintOrientationMobile" class="aud-select" style="width:auto;display:inline-block;" onchange="var q=new URLSearchParams(window.location.search);q.set('print_all','1');q.set('orientation',this.value);document.getElementById('auditPrintAllLinkMobile').href='?'+q.toString()">
                <option value="portrait" <?= (!isset($_GET['orientation']) || $_GET['orientation'] !== 'landscape') ? 'selected' : '' ?>>Portrait</option>
                <option value="landscape" <?= (isset($_GET['orientation']) && $_GET['orientation'] === 'landscape') ? 'selected' : '' ?>>Landscape</option>
            </select>
            <a href="?<?= http_build_query(array_merge($_GET, ['print_all' => '1'])) ?>" class="btn-print" id="auditPrintAllLinkMobile"><i class="bi bi-printer"></i> <span class="btn-text">Print All</span></a>
        </div>

        <!-- ═══ Stats Summary ═══ -->
        <div class="row g-3 mb-4">
            <div class="col-6 col-lg-3">
                <div class="stat-card">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-value"><?= number_format($totalCount) ?></div>
                            <div class="stat-label">Total Log Entries</div>
                        </div>
                        <div class="stat-icon bg-primary-soft"><i class="bi bi-journal-text"></i></div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="stat-card">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-value"><?= count($actions) ?></div>
                            <div class="stat-label">Unique Actions</div>
                        </div>
                        <div class="stat-icon bg-info-soft"><i class="bi bi-activity"></i></div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="stat-card">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-value"><?= count($users) ?></div>
                            <div class="stat-label">System Users</div>
                        </div>
                        <div class="stat-icon bg-success-soft"><i class="bi bi-people"></i></div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="stat-card">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-value" style="font-size:14px;font-family:var(--aud-font);line-height:1.4;"><?= $dateFrom ?> <span style="opacity:0.4;">&rarr;</span> <?= $dateTo ?></div>
                            <div class="stat-label">Date Range</div>
                        </div>
                        <div class="stat-icon bg-warning-soft"><i class="bi bi-calendar-range"></i></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ═══ Filters ═══ -->
        <div class="card filter-card animate-in mb-4">
            <div class="card-body">
                <form method="GET" class="row g-3 align-items-end">
                    <div class="col-6 col-md-2">
                        <label class="filter-label">User</label>
                        <select name="user_id" class="aud-select">
                            <option value="">All Users</option>
                            <?php foreach ($users as $u): ?>
                                <option value="<?= $u['id'] ?>" <?= $userFilter == $u['id'] ? 'selected' : '' ?>>
                                    <?= sanitize($u['email']) ?> (<?= $u['role'] ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="filter-label">Action</label>
                        <select name="action" class="aud-select">
                            <option value="">All Actions</option>
                            <?php foreach ($actions as $act): ?>
                                <option value="<?= sanitize($act) ?>" <?= $actionFilter === $act ? 'selected' : '' ?>>
                                    <?= sanitize(str_replace('_', ' ', ucfirst($act))) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-md-2">
                        <label class="filter-label">Search</label>
                        <input type="text" name="search" class="aud-input"
                               value="<?= sanitize($searchFilter) ?>" placeholder="Keyword...">
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="filter-label">Date From</label>
                        <input type="date" name="date_from" class="aud-input"
                               value="<?= sanitize($dateFrom) ?>">
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="filter-label">Date To</label>
                        <input type="date" name="date_to" class="aud-input"
                               value="<?= sanitize($dateTo) ?>">
                    </div>
                    <div class="col-12 col-md-2 d-flex gap-2">
                        <button type="submit" class="btn-filter flex-grow-1">
                            <i class="bi bi-search"></i> Filter
                        </button>
                        <a href="audit-logs.php" class="btn-clear" title="Clear filters">
                            <i class="bi bi-x-lg"></i>
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- ═══ Audit Log Table ═══ -->
        <div class="card animate-in" style="animation-delay:0.1s;">
            <div class="card-header">
                <span style="display:flex;align-items:center;gap:8px;">
                    <i class="bi bi-shield-check"></i>
                    <span>Activity Log</span>
                    <span class="count-pill"><?= number_format($totalCount) ?></span>
                </span>
                <small style="font-size:12px;font-weight:500;color:var(--aud-text-muted);">
                    Showing <?= count($logs) ?> of <?= number_format($totalCount) ?>
                </small>
            </div>
            <div class="card-body" style="padding:0;">
                <?php if (empty($logs)): ?>
                    <div class="empty-state">
                        <div class="empty-icon"><i class="bi bi-journal-x"></i></div>
                        <h6>No Log Entries Found</h6>
                        <p>Adjust your filters or check back later. Audit activity will appear here as users interact with the system.</p>
                    </div>
                <?php else: ?>
                <div class="audit-table-wrapper">
                    <div class="audit-table-scroll">
                        <table class="table audit-table mb-0">
                            <thead>
                                <tr>
                                    <th style="width:150px;">Date / Time</th>
                                    <th style="width:140px;">User</th>
                                    <th style="width:80px;">Role</th>
                                    <th style="width:140px;">Action</th>
                                    <th>Description</th>
                                    <th style="width:110px;">IP Address</th>
                                    <th style="width:90px;">Browser</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($logs as $log): ?>
                                <tr>
                                    <td class="cell-datetime">
                                        <div class="date-part"><?= date('M j, Y', strtotime($log['created_at'])) ?></div>
                                        <div class="time-part"><?= date('g:i:s A', strtotime($log['created_at'])) ?></div>
                                    </td>
                                    <td class="cell-user">
                                        <?= !empty($log['user_email']) ? sanitize($log['user_email']) : '<span style="font-style:italic;color:var(--tbl-text-muted);">System</span>' ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($log['user_role'])):
                                            $rc = $roleColors[$log['user_role']] ?? ['bg' => 'rgba(255,255,255,0.06)', 'fg' => 'rgba(255,255,255,0.4)'];
                                        ?>
                                            <span class="role-badge" style="background:<?= $rc['bg'] ?>;color:<?= $rc['fg'] ?>;">
                                                <?= ucfirst($log['user_role']) ?>
                                            </span>
                                        <?php else: ?>
                                            <span style="color:var(--tbl-text-muted);">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php
                                            $actData = $actionIcons[$log['action']] ?? ['icon' => 'bi-circle', 'color' => 'rgba(255,255,255,0.3)'];
                                        ?>
                                        <span class="action-badge">
                                            <i class="bi <?= $actData['icon'] ?>" style="color:<?= $actData['color'] ?>;"></i>
                                            <?= sanitize(str_replace('_', ' ', ucfirst($log['action']))) ?>
                                        </span>
                                    </td>
                                    <td class="cell-description">
                                        <?= sanitize($log['description'] ?? '') ?>
                                    </td>
                                    <td class="cell-ip">
                                        <?= sanitize($log['ip_address'] ?? '-') ?>
                                    </td>
                                    <td class="cell-browser">
                                        <?= parseUserAgent($log['user_agent'] ?? '') ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                <div class="pagination-wrapper">
                    <div class="pagination-info">
                        Page <?= $page ?> of <?= $totalPages ?> &middot; <?= number_format($totalCount) ?> entries
                    </div>
                    <nav class="pagination">
                        <a class="page-btn <?= $page <= 1 ? 'disabled' : '' ?>"
                           href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">
                            <i class="bi bi-chevron-left"></i>
                        </a>
                        <?php
                        $startPage = max(1, $page - 3);
                        $endPage   = min($totalPages, $page + 3);
                        if ($startPage > 1): ?>
                            <a class="page-btn" href="?<?= http_build_query(array_merge($_GET, ['page' => 1])) ?>">1</a>
                            <?php if ($startPage > 2): ?><span class="page-btn disabled" style="border:none;">...</span><?php endif; ?>
                        <?php endif;
                        for ($i = $startPage; $i <= $endPage; $i++): ?>
                            <a class="page-btn <?= $i === $page ? 'active' : '' ?>"
                               href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"><?= $i ?></a>
                        <?php endfor;
                        if ($endPage < $totalPages): ?>
                            <?php if ($endPage < $totalPages - 1): ?><span class="page-btn disabled" style="border:none;">...</span><?php endif; ?>
                            <a class="page-btn" href="?<?= http_build_query(array_merge($_GET, ['page' => $totalPages])) ?>"><?= $totalPages ?></a>
                        <?php endif; ?>
                        <a class="page-btn <?= $page >= $totalPages ? 'disabled' : '' ?>"
                           href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">
                            <i class="bi bi-chevron-right"></i>
                        </a>
                    </nav>
                </div>
                <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- ═══ Legend ═══ -->
        <div class="card legend-card animate-in" style="animation-delay:0.15s;margin-top:20px;">
            <div class="card-header">
                <span style="display:flex;align-items:center;gap:8px;">
                    <i class="bi bi-info-circle"></i>
                    <span>Audit Log Legend</span>
                </span>
            </div>
            <div class="card-body">
                <div class="legend-grid">
                    <div class="legend-item">
                        <i class="bi bi-box-arrow-in-right" style="color:#10b981;"></i>
                        <small><strong>Login</strong> &mdash; Successful authentication</small>
                    </div>
                    <div class="legend-item">
                        <i class="bi bi-exclamation-triangle" style="color:#ef4444;"></i>
                        <small><strong>Failed Login</strong> &mdash; Unsuccessful attempt</small>
                    </div>
                    <div class="legend-item">
                        <i class="bi bi-person-plus" style="color:#60a5fa;"></i>
                        <small><strong>Add</strong> &mdash; New record created</small>
                    </div>
                    <div class="legend-item">
                        <i class="bi bi-pencil-square" style="color:#06b6d4;"></i>
                        <small><strong>Edit</strong> &mdash; Record modified</small>
                    </div>
                    <div class="legend-item">
                        <i class="bi bi-person-x" style="color:#ef4444;"></i>
                        <small><strong>Delete</strong> &mdash; Record removed</small>
                    </div>
                    <div class="legend-item">
                        <i class="bi bi-play-circle" style="color:#10b981;"></i>
                        <small><strong>Session</strong> &mdash; Attendance session</small>
                    </div>
                    <div class="legend-item">
                        <i class="bi bi-gear" style="color:rgba(255,255,255,0.4);"></i>
                        <small><strong>Settings</strong> &mdash; Configuration changed</small>
                    </div>
                    <div class="legend-item">
                        <i class="bi bi-megaphone" style="color:#f59e0b;"></i>
                        <small><strong>Announcement</strong> &mdash; Notification sent</small>
                    </div>
                    <div class="legend-item">
                        <i class="bi bi-download" style="color:#06b6d4;"></i>
                        <small><strong>Export</strong> &mdash; Data exported</small>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<script>
(function() {
    'use strict';

    // ─── Toast ──────────────────────────────────────────────────────────
    function showToast(type, message) {
        var c = document.getElementById('toastContainer');
        var icons = { success: 'bi-check-circle-fill', danger: 'bi-x-circle-fill', warning: 'bi-exclamation-triangle-fill', info: 'bi-info-circle-fill' };
        var t = document.createElement('div');
        t.className = 'aud-toast ' + type;
        t.innerHTML = '<i class="bi ' + (icons[type] || icons.info) + '"></i><span>' + esc(message) + '</span>';
        c.appendChild(t);
        setTimeout(function() { if (t.parentNode) t.remove(); }, 4200);
    }

    <?php if (!empty($_SESSION['flash_message'])): ?>
    showToast('<?= $_SESSION['flash_message']['type'] ?>', <?= json_encode($_SESSION['flash_message']['message']) ?>);
    <?php unset($_SESSION['flash_message']); endif; ?>

    function esc(s) { if (!s) return ''; var d = document.createElement('div'); d.appendChild(document.createTextNode(s)); return d.innerHTML; }
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>