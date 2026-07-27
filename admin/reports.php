<?php

require_once __DIR__ . '/../config.php';
requireRole(['admin']);

// Filters
$gradeFilter   = sanitize($_GET['grade_level'] ?? '');
$sectionFilter = sanitize($_GET['section'] ?? '');
$subjectFilter = intval($_GET['subject_id'] ?? 0);
$dateFrom      = sanitize($_GET['date_from'] ?? date('Y-m-01'));
$dateTo        = sanitize($_GET['date_to'] ?? date('Y-m-d'));

// Build query
$sql = "SELECT records.*, s.first_name, s.last_name, s.student_id as sid, s.grade_level, s.section,
               sub.subject_name
        FROM (
            SELECT a.id, a.student_id, a.subject_id, a.date, a.time, a.status, a.session_type, 'class' AS source
            FROM attendance a
            UNION ALL
            SELECT ar.id, ar.student_id, NULL AS subject_id, DATE(ar.scan_time) AS date, TIME(ar.scan_time) AS time, ar.status, ar.session_type, 'gate' AS source
            FROM attendance_records ar
        ) records
        LEFT JOIN students s ON records.student_id = s.id
        LEFT JOIN subjects sub ON records.subject_id = sub.id
        WHERE records.date BETWEEN :date_from AND :date_to AND records.status != 'pending'";
$params = [':date_from' => $dateFrom, ':date_to' => $dateTo];
if ($gradeFilter)   { $sql .= " AND s.grade_level = :grade_level"; $params[':grade_level'] = $gradeFilter; }
if ($sectionFilter) { $sql .= " AND s.section = :section"; $params[':section'] = $sectionFilter; }
if ($subjectFilter) { $sql .= " AND records.subject_id = :subject_id"; $params[':subject_id'] = $subjectFilter; }
$sql .= " ORDER BY records.date DESC, s.last_name ASC, records.time DESC";
$stmt = $db->prepare($sql); $stmt->execute($params); $records = $stmt->fetchAll();

// Pagination
$page    = max(1, intval($_GET['page'] ?? 1));
$perPage = 10;
$total   = count($records);
$totalPages = max(1, ceil($total / $perPage));
$page    = min($page, $totalPages);
$offset  = ($page - 1) * $perPage;
$pageRecords = array_slice($records, $offset, $perPage);

// Handle CSV export (must run before any HTML output)
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="attendance_report_' . $dateFrom . '_to_' . $dateTo . '.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Date','Student ID','Name','Grade','Section','Subject','Source','Status']);
    foreach ($records as $r) {
        fputcsv($output, [
            $r['date'],
            $r['sid'] ?? '-',
            ($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''),
            $r['grade_level'] ?? '-',
            $r['section'] ?? '-',
            $r['subject_name'] ?? '-',
            $r['source'] === 'gate' ? 'Gate' : 'Class',
            $r['status']
        ]);
    }
    fclose($output);
    exit;
}

$pageTitle = 'Attendance Reports';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

// Stats
$pCount = $lCount = $aCount = 0;
foreach ($records as $r) {
    if ($r['status'] === 'present') $pCount++;
    elseif ($r['status'] === 'late') $lCount++;
    else $aCount++;
}
$total = count($records);
$rate = $total > 0 ? round((($pCount + $lCount) / $total) * 100, 1) : 0;

$subjects = [];
try { $subjects = $db->query("SELECT * FROM subjects ORDER BY subject_name")->fetchAll(); } catch (Exception $e) {}
$sections = [];
try { $sections = $db->query("SELECT DISTINCT section FROM students WHERE section != '' ORDER BY section")->fetchAll(PDO::FETCH_COLUMN); } catch (Exception $e) {}

// Daily trend data (server-side for chart)
$dailyTrend = [];
foreach ($records as $rec) {
    $d = $rec['date'];
    if (!isset($dailyTrend[$d])) $dailyTrend[$d] = ['present' => 0, 'late' => 0, 'absent' => 0];
    $dailyTrend[$d][$rec['status']]++;
}
ksort($dailyTrend);

// Define BASE_URL for JavaScript
$baseUrl = BASE_URL;
?>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/pages-theme.css">
<style>
/* ═══════════════════════════════════════════════════════════════════════════
   DESIGN SYSTEM — Attendance Reports (Dark Theme)
   ═══════════════════════════════════════════════════════════════════════════ */

:root {
    --rpt-font: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif;
    --rpt-mono: 'JetBrains Mono', 'SF Mono', monospace;

    --rpt-primary: #4f46e5;
    --rpt-primary-glow: rgba(79,70,229,0.25);
    --rpt-accent: #4f46e5;
    --rpt-accent-soft: rgba(79,70,229,0.15);
    --rpt-accent-hover: #4338ca;

    --rpt-success: #10b981;
    --rpt-success-soft: rgba(16,185,129,0.15);
    --rpt-danger: #ef4444;
    --rpt-danger-soft: rgba(239,68,68,0.15);
    --rpt-warning: #f59e0b;
    --rpt-warning-soft: rgba(245,158,11,0.15);
    --rpt-info: #06b6d4;
    --rpt-info-soft: rgba(6,182,212,0.15);

    --rpt-bg: #0b0b14;
    --rpt-surface: rgba(255,255,255,0.04);
    --rpt-surface-hover: rgba(255,255,255,0.07);
    --rpt-surface-card: rgba(255,255,255,0.04);
    --rpt-border: rgba(255,255,255,0.06);
    --rpt-border-light: rgba(255,255,255,0.04);
    --rpt-text: #f0ece4;
    --rpt-text-secondary: rgba(255,255,255,0.55);
    --rpt-text-muted: rgba(255,255,255,0.3);

    --rpt-shadow-sm: 0 1px 3px rgba(0,0,0,0.3);
    --rpt-shadow: 0 4px 16px rgba(0,0,0,0.25);
    --rpt-shadow-md: 0 8px 24px rgba(0,0,0,0.3);
    --rpt-shadow-lg: 0 12px 40px rgba(0,0,0,0.35);
    --rpt-shadow-xl: 0 20px 50px rgba(0,0,0,0.4);

    --rpt-radius-sm: 10px;
    --rpt-radius: 14px;
    --rpt-radius-lg: 16px;
    --rpt-transition: 0.2s cubic-bezier(0.4, 0, 0.2, 1);
    --rpt-spring: 0.35s cubic-bezier(0.34,1.56,0.64,1);

    /* Table light palette */
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
    height: 60px;
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
.navbar-left { display: flex; align-items: center; gap: 16px; flex-shrink: 0; min-width: 0; }
.top-navbar .page-title h5 {
    font-size: 20px; font-weight: 800; color: var(--rpt-text);
    margin: 0; letter-spacing: -0.03em;
}
.top-navbar .page-title small {
    font-size: 13px; color: var(--rpt-text-secondary); font-weight: 500;
}

/* ─── Mobile Page Title ─────────────────────────────────────────────────── */
.mobile-title { display: none; padding: 14px 0 4px; }
.mobile-title-inner { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; }
.mobile-title-left h5 { font-size: 18px; font-weight: 800; letter-spacing: -0.03em; margin: 0; color: var(--rpt-text); }
.mobile-title-left small { font-size: 12px; font-weight: 500; color: var(--rpt-text-secondary); }
.mobile-date { font-size: 11px; font-weight: 600; padding: 6px 10px; border-radius: var(--rpt-radius-sm); display: flex; align-items: center; gap: 6px; white-space: nowrap; flex-shrink: 0; margin-top: 2px; background: var(--rpt-surface); color: var(--rpt-text-secondary); }
.mobile-date i { font-size: 12px; color: var(--rpt-accent); }

.navbar-actions { display: flex; align-items: center; gap: 10px; flex-shrink: 0; }
.navbar-actions .date-pill {
    font-size: 13px; font-weight: 600; padding: 8px 16px;
    border-radius: var(--rpt-radius-sm);
    background: var(--rpt-surface); border: none;
    color: var(--rpt-text-secondary);
    display: flex; align-items: center; gap: 8px;
}
.navbar-actions .date-pill i { font-size: 14px; color: var(--rpt-accent); }

/* NAV ICON BUTTON */
.nav-icon-btn {
    width: 40px; height: 40px;
    border-radius: var(--rpt-radius-sm);
    background: var(--rpt-surface); border: none;
    color: var(--rpt-text);
    display: inline-flex; align-items: center; justify-content: center;
    cursor: pointer; transition: all var(--rpt-transition); font-size: 16px;
    flex-shrink: 0;
}
.nav-icon-btn:hover { transform: translateY(-1px); background: var(--rpt-surface-hover); }

.content-area { padding: 24px 28px 40px; }

/* ─── Cards ───────────────────────────────────────────────────────────── */
.card {
    background: var(--rpt-surface-card);
    border: none;
    border-radius: var(--rpt-radius);
    box-shadow: var(--rpt-shadow-sm);
    transition: all var(--rpt-transition);
    overflow: hidden;
}
.card:hover { box-shadow: var(--rpt-shadow); }
.card-header {
    background: transparent;
    border-bottom: 1px solid var(--rpt-border);
    padding: 14px 20px;
    font-size: 14px; font-weight: 700;
    color: var(--rpt-text);
    letter-spacing: -0.01em;
    display: flex; align-items: center; justify-content: space-between;
}
.card-header i { color: #ffffff; font-size: 15px; }
.card-body { padding: 20px; color: var(--rpt-text); }

/* ─── Stat Cards ──────────────────────────────────────────────────────── */
.stat-card {
    background: var(--rpt-surface-card);
    border: none;
    border-radius: var(--rpt-radius);
    padding: 20px;
    box-shadow: var(--rpt-shadow-sm);
    transition: all var(--rpt-transition);
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
    border-radius: var(--rpt-radius) var(--rpt-radius) 0 0;
    opacity: 0;
    transition: opacity var(--rpt-transition);
}
.stat-card:hover { transform: translateY(-3px); box-shadow: var(--rpt-shadow-md); }
.stat-card:hover::before { opacity: 1; }
.stat-card:nth-child(1) { animation-delay: 0.05s; }
.stat-card:nth-child(1)::before { background: var(--rpt-accent); }
.stat-card:nth-child(2) { animation-delay: 0.1s; }
.stat-card:nth-child(2)::before { background: var(--rpt-success); }
.stat-card:nth-child(3) { animation-delay: 0.15s; }
.stat-card:nth-child(3)::before { background: var(--rpt-warning); }
.stat-card:nth-child(4) { animation-delay: 0.2s; }
.stat-card:nth-child(4)::before { background: var(--rpt-danger); }
.stat-value {
    font-size: 28px; font-weight: 800; color: var(--rpt-text);
    font-family: var(--rpt-mono); letter-spacing: -0.03em;
    line-height: 1.2;
}
.stat-label {
    font-size: 11px; font-weight: 600; color: var(--rpt-text-muted);
    text-transform: uppercase; letter-spacing: 0.06em;
    margin-top: 6px;
}
.stat-icon {
    width: 44px; height: 44px;
    border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    font-size: 18px; flex-shrink: 0;
}

/* ─── Form Controls ───────────────────────────────────────────────────── */
.rpt-label {
    display: block;
    font-size: 11px; font-weight: 700;
    color: var(--rpt-text-muted);
    text-transform: uppercase; letter-spacing: 0.06em;
    margin-bottom: 6px;
}
.rpt-input,
.rpt-select {
    width: 100%;
    padding: 9px 14px;
    border: 1.5px solid var(--rpt-border);
    border-radius: var(--rpt-radius-sm);
    font-size: 13px; font-weight: 500;
    color: var(--rpt-text);
    background: var(--rpt-surface);
    transition: all var(--rpt-transition);
    font-family: var(--rpt-font);
}
.rpt-input:focus,
.rpt-select:focus {
    border-color: var(--rpt-accent);
    box-shadow: 0 0 0 3px var(--rpt-primary-glow);
    outline: none;
    background: var(--rpt-surface-hover);
}
.rpt-input::placeholder { color: var(--rpt-text-muted); }
.rpt-select {
    appearance: none;
    -webkit-appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' fill='rgba(255,255,255,0.4)' viewBox='0 0 16 16'%3E%3Cpath d='M8 11L3 6h10z'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 12px center;
    padding-right: 32px;
    cursor: pointer;
}
.rpt-select option { background: #1a1a2e; color: var(--rpt-text); }

/* ─── Buttons ─────────────────────────────────────────────────────────── */
.btn-filter {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 9px 20px;
    border: none;
    border-radius: var(--rpt-radius-sm);
    font-size: 13px; font-weight: 700;
    color: #fff;
    background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
    box-shadow: 0 2px 12px rgba(79,70,229,0.3);
    cursor: pointer;
    transition: all var(--rpt-spring);
}
.btn-filter:hover {
    transform: translateY(-1px);
    box-shadow: 0 6px 20px rgba(79,70,229,0.4);
    color: #fff;
}
.btn-reset {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 4px;
    padding: 9px 16px;
    border: 1.5px solid var(--rpt-border);
    border-radius: var(--rpt-radius-sm);
    font-size: 13px; font-weight: 700;
    background: var(--rpt-surface);
    color: var(--rpt-text-muted);
    cursor: pointer;
    transition: all var(--rpt-transition);
    text-decoration: none;
}
.btn-reset:hover {
    border-color: var(--rpt-danger);
    color: var(--rpt-danger);
    background: var(--rpt-danger-soft);
    text-decoration: none;
}
.btn-export {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 9px 18px;
    border: none;
    border-radius: var(--rpt-radius-sm);
    font-size: 12px; font-weight: 700;
    color: #fff;
    background: linear-gradient(135deg, #059669 0%, #10b981 100%);
    box-shadow: 0 2px 12px rgba(16,185,129,0.25);
    cursor: pointer;
    text-decoration: none;
    transition: all var(--rpt-spring);
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
    border-radius: var(--rpt-radius-sm);
    font-size: 12px; font-weight: 700;
    color: var(--rpt-text-secondary);
    background: var(--rpt-surface);
    cursor: pointer;
    transition: all var(--rpt-transition);
}
.btn-print:hover {
    border-color: rgba(255,255,255,0.2);
    color: var(--rpt-text);
    background: var(--rpt-surface-hover);
}
.btn-table-export {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 6px 14px;
    border: none;
    border-radius: 8px;
    font-size: 12px; font-weight: 700;
    color: #fff;
    background: var(--rpt-accent);
    cursor: pointer;
    text-decoration: none;
    transition: all var(--rpt-transition);
}
.btn-table-export:hover {
    background: var(--rpt-accent-hover);
    color: #fff;
    text-decoration: none;
    transform: translateY(-1px);
}
.btn-table-export i {
    color: #fff;
    font-size: 14px;
    transition: color var(--rpt-transition);
}

/* ─── Report Table ────────────────────────────────────────────────────── */
.report-table-wrapper { border-radius: var(--rpt-radius); overflow: hidden; }
.table-scroll-wrapper {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    background: var(--tbl-bg);
}
.table-scroll-wrapper::-webkit-scrollbar { height: 6px; }
.table-scroll-wrapper::-webkit-scrollbar-track { background: rgba(0,0,0,0.02); }
.table-scroll-wrapper::-webkit-scrollbar-thumb { background: rgba(0,0,0,0.1); border-radius: 6px; }
.table-scroll-wrapper::-webkit-scrollbar-thumb:hover { background: rgba(0,0,0,0.18); }

/* ─── Pagination ──────────────────────────────────────────────────────── */
.rpt-pagination {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
    padding: 14px 18px;
    border-top: 1px solid var(--tbl-border, rgba(0,0,0,0.06));
    background: var(--tbl-bg-thead, rgba(0,0,0,0.02));
}
.rpt-page-info {
    font-size: 12.5px;
    color: var(--tbl-text-muted, rgba(0,0,0,0.55));
    font-weight: 500;
    white-space: nowrap;
}
.rpt-page-nav {
    display: flex;
    align-items: center;
    gap: 6px;
    flex-wrap: wrap;
}
.rpt-page-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 34px;
    height: 34px;
    padding: 0 8px;
    border-radius: 8px;
    border: 1px solid var(--tbl-border, rgba(0,0,0,0.1));
    background: var(--tbl-bg, #fff);
    color: var(--tbl-text, #1f2937);
    font-size: 13px;
    font-weight: 600;
    text-decoration: none;
    transition: all 0.15s ease;
    cursor: pointer;
}
.rpt-page-btn:hover:not(.disabled):not(.active) {
    border-color: var(--rpt-accent, #4f46e5);
    color: var(--rpt-accent, #4f46e5);
    background: var(--rpt-accent-soft, rgba(79,70,229,0.08));
}
.rpt-page-btn.active {
    background: var(--rpt-accent, #4f46e5);
    border-color: var(--rpt-accent, #4f46e5);
    color: #fff;
    cursor: default;
}
.rpt-page-btn.disabled {
    opacity: 0.4;
    pointer-events: none;
    cursor: not-allowed;
}
.rpt-page-ellipsis {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 24px;
    color: var(--tbl-text-muted, rgba(0,0,0,0.4));
    font-size: 14px;
}

@media (max-width: 575px) {
    .rpt-pagination { justify-content: center; }
    .rpt-page-info { width: 100%; text-align: center; }
}

.report-table {
    width: 100%;
    border-collapse: collapse;
    min-width: 820px;
    font-size: 13px;
    color: var(--tbl-text);
    background: var(--tbl-bg);
}
.report-table thead { background: var(--tbl-bg-thead); }
.report-table thead th {
    padding: 12px 16px;
    font-size: 11px; font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.06em;
    color: var(--tbl-text-muted);
    border-bottom: 1px solid var(--tbl-border);
    text-align: left;
    white-space: nowrap;
}
.report-table tbody td {
    padding: 14px 16px;
    font-size: 13px;
    vertical-align: middle;
    border-bottom: 1px solid var(--tbl-border-light);
    transition: background 0.15s ease;
    white-space: nowrap;
    color: var(--tbl-text-secondary);
}
.report-table tbody tr { background: var(--tbl-bg); }
.report-table tbody tr:hover td { background: var(--tbl-bg-hover); }
.report-table tbody tr:last-child td { border-bottom: none; }

.report-table .cell-date {
    font-family: var(--rpt-mono); font-size: 12px; font-weight: 500;
    color: var(--tbl-text-muted);
}
.report-table .cell-student-id {
    font-family: var(--rpt-mono); font-size: 12px; font-weight: 500;
    padding: 3px 8px; border-radius: 6px; display: inline-block;
    background: rgba(79,70,229,0.08); color: #4f46e5;
}
.report-table .cell-name { font-weight: 600; font-size: 14px; letter-spacing: -0.01em; color: var(--tbl-text); }
.report-table .cell-grade {
    font-size: 10px; font-weight: 700; padding: 3px 10px;
    border-radius: 6px; display: inline-block;
    background: rgba(79,70,229,0.08); color: #4f46e5;
    letter-spacing: 0.04em;
}
.report-table .cell-section { font-size: 12.5px; }
.report-table .cell-subject { font-size: 12.5px; }
.report-table .cell-source {
    font-size: 10px; font-weight: 600; padding: 2px 8px;
    border-radius: 6px; display: inline-flex; align-items: center; gap: 4px;
    letter-spacing: 0.03em;
}
.report-table .cell-source.gate { background: rgba(6,182,212,0.1); color: #0891b2; }
.report-table .cell-source.class-source { background: rgba(79,70,229,0.08); color: #4f46e5; }

.badge-status {
    font-size: 10px; font-weight: 700; padding: 3px 10px;
    border-radius: 20px; display: inline-flex; align-items: center; gap: 5px;
    letter-spacing: 0.03em;
}
.badge-present { background: rgba(16,185,129,0.1); color: #059669; }
.badge-late    { background: rgba(245,158,11,0.1); color: #b45309; }
.badge-absent  { background: rgba(239,68,68,0.1); color: #dc2626; }

/* ─── Chart ───────────────────────────────────────────────────────────── */
.chart-container { position: relative; width: 100%; min-height: 200px; }
.chart-container canvas { width: 100% !important; }

/* ─── Empty State ─────────────────────────────────────────────────────── */
.empty-state { text-align: center; padding: 60px 24px; }
.empty-state .empty-icon {
    width: 72px; height: 72px; border-radius: 20px;
    background: var(--rpt-accent-soft); color: var(--rpt-accent);
    display: inline-flex; align-items: center; justify-content: center;
    font-size: 28px; margin-bottom: 16px;
}
.empty-state h6 { font-size: 16px; font-weight: 700; color: var(--rpt-text); margin-bottom: 6px; }
.empty-state p { font-size: 13px; color: var(--rpt-text-muted); max-width: 320px; margin: 0 auto; line-height: 1.6; }

/* ─── Count Pill ──────────────────────────────────────────────────────── */
.count-pill {
    background: var(--rpt-accent-soft); color: var(--rpt-accent);
    font-size: 11px; font-weight: 700; padding: 3px 10px;
    border-radius: 20px; font-family: var(--rpt-mono);
}

/* ─── Stagger Animation ───────────────────────────────────────────────── */
.animate-in {
    opacity: 0; transform: translateY(16px);
    animation: fadeUp 0.5s cubic-bezier(0.4, 0, 0.2, 1) forwards;
}
.animate-in:nth-child(2) { animation-delay: 0.05s; }
.animate-in:nth-child(3) { animation-delay: 0.1s; }
.animate-in:nth-child(4) { animation-delay: 0.15s; }
@keyframes fadeUp { to { opacity: 1; transform: translateY(0); } }

/* ─── Toasts ──────────────────────────────────────────────────────────── */
.toast-container {
    position: fixed; bottom: 28px; right: 28px; z-index: 9999;
    display: flex; flex-direction: column; gap: 10px;
    pointer-events: none;
}
.rpt-toast {
    padding: 14px 22px; border-radius: var(--rpt-radius-sm);
    color: #fff; font-size: 13px; font-weight: 600;
    display: flex; align-items: center; gap: 12px;
    max-width: 400px; pointer-events: auto;
    animation: toastIn 0.4s cubic-bezier(0.34,1.56,0.64,1), toastOut 0.4s ease 3.6s forwards;
    font-family: var(--rpt-font);
    box-shadow: 0 8px 24px rgba(0,0,0,0.5);
}
.rpt-toast.success { background: #059669; }
.rpt-toast.danger  { background: #dc2626; }
.rpt-toast.warning { background: #d97706; color: #1a1a1a; }
.rpt-toast.info    { background: #2563eb; }
.rpt-toast i { font-size: 18px; flex-shrink: 0; opacity: 0.9; }
@keyframes toastIn { from { opacity: 0; transform: translateX(40px) scale(0.95); } to { opacity: 1; transform: translateX(0) scale(1); } }
@keyframes toastOut { from { opacity: 1; transform: translateX(0); } to { opacity: 0; transform: translateX(40px); } }

    /* ─── Mobile Report Cards ─────────────────────────────────────────── */

    /* ─── Table Scroll ──────────────────────────────────────────────────── */
    .table-scroll-wrapper{overflow-x:auto;-webkit-overflow-scrolling:touch}
    .table-scroll-wrapper::-webkit-scrollbar{height:6px}
    .table-scroll-wrapper::-webkit-scrollbar-track{background:rgba(255,255,255,0.03)}
    .table-scroll-wrapper::-webkit-scrollbar-thumb{background:rgba(255,255,255,0.12);border-radius:6px}
    .table-scroll-wrapper::-webkit-scrollbar-thumb:hover{background:rgba(255,255,255,0.2)}
    .report-table{min-width:700px}

    /* ─── Scrollbar ───────────────────────────────────────────────────────── */
::-webkit-scrollbar { width: 6px; height: 6px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.1); border-radius: 3px; }
::-webkit-scrollbar-thumb:hover { background: rgba(255,255,255,0.2); }

/* Print styles removed — using new-window popup instead */

/* ═══════════════════════════════════════════════════════════════════════════
   RESPONSIVE — TABLET (max-width: 991px)
   ═══════════════════════════════════════════════════════════════════════════ */
@media (max-width: 991px) {
    .content-area { padding: 20px; }
    .top-navbar { padding: 16px; }
    .chart-container { min-height: 180px; }
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
    .mobile-title { display: block; position: sticky; top: 60px; z-index: 99; background: var(--rpt-bg); backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px); }
    .navbar-actions { gap: 6px; flex-shrink: 0; }
    .nav-icon-btn { width: 38px; height: 38px; font-size: 15px; }

    /* ─── Content ─────────────────────────────────────────────────────── */
    .content-area { padding: 10px 12px 28px; overflow-x: hidden; }
    .card { border-radius: var(--rpt-radius-sm); }
    .card-header { font-size: 13px; padding: 12px 16px; }
    .card-body { padding: 16px; }

    /* ─── Stat Cards ──────────────────────────────────────────────────── */
    .stat-card { padding: 16px; animation: none; opacity: 1; }
    .stat-value { font-size: 22px; }
    .stat-label { font-size: 10px; }
    .stat-icon { width: 38px; height: 38px; font-size: 15px; border-radius: 10px; }

    /* ─── Forms ───────────────────────────────────────────────────────── */
    .rpt-label { font-size: 10px; margin-bottom: 4px; }
    .rpt-input, .rpt-select { padding: 8px 12px; font-size: 13px; }
    .btn-filter { padding: 8px 16px; font-size: 12px; }
    .btn-reset { padding: 8px 14px; font-size: 12px; }

    /* ─── Action Buttons ──────────────────────────────────────────────── */
    .btn-export { padding: 7px 12px; font-size: 11px; }
    .btn-print { padding: 7px 12px; font-size: 11px; }

    /* ─── Table → mobile scroll ────────────────────────────────────────── */
    .report-table{min-width:600px}
    .report-table thead th{padding:8px 10px;font-size:9px}
    .report-table tbody td{padding:8px 10px;font-size:11px}
    .report-table .cell-name{font-size:12px}
    .badge-status{font-size:8px;padding:2px 6px}

    /* ─── Charts ──────────────────────────────────────────────────────── */
    .chart-container { min-height: 180px; }

    /* ─── Empty State ─────────────────────────────────────────────────── */
    .empty-state { padding: 40px 20px; }
    .empty-state .empty-icon { width: 56px; height: 56px; font-size: 22px; }
    .empty-state h6 { font-size: 14px; }
    .empty-state p { font-size: 12px; }

    /* ─── Toasts ──────────────────────────────────────────────────────── */
    .toast-container { bottom: 24px; right: 12px; left: 12px; }
    .rpt-toast { max-width: 100%; font-size: 12px; padding: 12px 16px; }

    /* ─── Animations ──────────────────────────────────────────────────── */
    .animate-in { animation: none; opacity: 1; }
}

/* ═══════════════════════════════════════════════════════════════════════════
   RESPONSIVE — SMALL PHONE (max-width: 576px)
   ═══════════════════════════════════════════════════════════════════════════ */
@media (max-width: 576px) {
    /* ─── Navbar ──────────────────────────────────────────────────────── */
    .top-navbar { padding: 10px 10px; gap: 6px; }
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

    .card { border-radius: 10px; }
    .card-header { font-size: 12px; padding: 10px 12px; }
    .card-body { padding: 12px; }

    .stat-card { padding: 12px 10px; }
    .stat-value { font-size: 18px; }
    .stat-label { font-size: 9px; letter-spacing: 0.04em; }
    .stat-icon { width: 32px; height: 32px; font-size: 13px; }

    .rpt-input, .rpt-select { padding: 7px 10px; font-size: 12px; }
    .rpt-label { font-size: 9px; }

    .btn-export { padding: 6px 10px; font-size: 10px; }
    .btn-print { padding: 6px 10px; font-size: 10px; }

    .report-table { min-width: 650px; }
    .report-table thead th { padding: 8px 10px; font-size: 9px; }
    .report-table tbody td { padding: 8px 10px; font-size: 11px; }
    .report-table .cell-name { font-size: 12px; }
    .badge-status { font-size: 8px; padding: 2px 6px; }

    .chart-container { min-height: 150px; }

    .count-pill { font-size: 10px; padding: 2px 8px; }

    .rpt-toast { font-size: 11px; padding: 10px 14px; gap: 10px; }
    .rpt-toast i { font-size: 16px; }

    .sidebar { width: 260px; }
}

/* ─── Desktop — hide mobile-only elements ─────────────────────────────── */
@media (min-width: 768px) {
    .mobile-title { display: none !important; }
}

/* Print Header - hidden by default, shown only in print */
.report-table-print-all { display: none; }
#printReportArea { display: none; }
</style>

<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/pages-navbar.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/print.css">

<!-- Toast Container -->
<div class="toast-container" id="toastContainer"></div>

<?php require_once __DIR__ . '/../includes/pages-topnavbar.php'; ?>
<div class="main-content">

    <div class="content-area">

        <div class="d-none d-md-flex justify-content-between align-items-center gap-3 mb-3">
            <div class="page-title mb-0">
                <h5 class="mb-0">Attendance Reports</h5>
                <small>Generate and export attendance data</small>
            </div>
            <div class="d-flex gap-2">
                <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>" class="btn-export"><i class="bi bi-file-earmark-excel"></i> Export CSV</a>
                <button onclick="printReport()" class="btn-print"><i class="bi bi-printer"></i> Print Report</button>
            </div>
        </div>

        <!-- MOBILE TITLE -->
        <div class="page-title mobile-title">
            <div class="mobile-title-inner">
                <div class="mobile-title-left">
                    <h5>Attendance Reports</h5>
                    <small>Generate and export attendance data</small>
                </div>
                <div class="mobile-date">
                    <i class="bi bi-calendar3"></i> <?= date('D, M j, Y') ?>
                </div>
            </div>
        </div>

        <!-- ═══ Stat Cards ═══ -->
        <div class="row g-3 mb-4">
            <div class="col-6 col-lg-3">
                <div class="stat-card">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-value"><?= number_format($total) ?></div>
                            <div class="stat-label">Total Records</div>
                        </div>
                        <div class="stat-icon" style="background:var(--rpt-accent-soft);color:var(--rpt-accent);">
                            <i class="bi bi-clipboard-data"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="stat-card">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-value" style="color:var(--rpt-success);"><?= number_format($pCount) ?></div>
                            <div class="stat-label">Present</div>
                        </div>
                        <div class="stat-icon" style="background:var(--rpt-success-soft);color:var(--rpt-success);">
                            <i class="bi bi-check-circle-fill"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="stat-card">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-value" style="color:var(--rpt-warning);"><?= number_format($lCount) ?></div>
                            <div class="stat-label">Late</div>
                        </div>
                        <div class="stat-icon" style="background:var(--rpt-warning-soft);color:var(--rpt-warning);">
                            <i class="bi bi-clock-fill"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="stat-card">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-value" style="color:<?= $rate >= 80 ? 'var(--rpt-success)' : ($rate >= 60 ? 'var(--rpt-warning)' : 'var(--rpt-danger)') ?>;"><?= $rate ?>%</div>
                            <div class="stat-label">Attendance Rate</div>
                        </div>
                        <div class="stat-icon" style="background:var(--rpt-info-soft);color:var(--rpt-info);">
                            <i class="bi bi-graph-up"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ═══ Filter Bar ═══ -->
        <div class="card filter-bar animate-in mb-4">
            <div class="card-body">
                <form method="GET" class="row g-3 align-items-end">
                    <div class="col-6 col-md-2">
                        <label class="rpt-label">Grade</label>
                        <select name="grade_level" class="rpt-select">
                            <option value="">All Grades</option>
                            <?php foreach(['7','8','9','10','11','12'] as $g): ?>
                                <option value="<?= $g ?>" <?= $gradeFilter === $g ? 'selected' : '' ?>>Grade <?= $g ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="rpt-label">Section</label>
                        <select name="section" class="rpt-select">
                            <option value="">All Sections</option>
                            <?php foreach($sections as $s): ?>
                                <option value="<?= sanitize($s) ?>" <?= $sectionFilter === $s ? 'selected' : '' ?>><?= sanitize($s) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-md-2">
                        <label class="rpt-label">Subject</label>
                        <select name="subject_id" class="rpt-select">
                            <option value="">All Subjects</option>
                            <?php foreach($subjects as $sub): ?>
                                <option value="<?= $sub['id'] ?>" <?= $subjectFilter == $sub['id'] ? 'selected' : '' ?>><?= sanitize($sub['subject_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="rpt-label">From</label>
                        <input type="date" class="rpt-input" name="date_from" value="<?= sanitize($dateFrom) ?>">
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="rpt-label">To</label>
                        <input type="date" class="rpt-input" name="date_to" value="<?= sanitize($dateTo) ?>">
                    </div>
                    <div class="col-6 col-md-1">
                        <button type="submit" class="btn-filter w-100">
                            <i class="bi bi-funnel"></i> Filter
                        </button>
                    </div>
                    <div class="col-6 col-md-1">
                        <a href="<?= BASE_URL ?>/admin/reports.php" class="btn-reset w-100">
                            <i class="bi bi-x-lg"></i> Reset
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- ═══ Charts ═══ -->
        <div class="row g-3 mb-4">
            <div class="col-12 col-md-5">
                <div class="card h-100 animate-in" style="animation-delay:0.05s;">
                    <div class="card-header">
                        <span style="display:flex;align-items:center;gap:8px;">
                            <i class="bi bi-pie-chart"></i>
                            <span>Attendance Breakdown</span>
                        </span>
                    </div>
                    <div class="card-body">
                        <div class="chart-container"><canvas id="breakdownChart"></canvas></div>
                    </div>
                </div>
            </div>
            <div class="col-12 col-md-7">
                <div class="card h-100 animate-in" style="animation-delay:0.1s;">
                    <div class="card-header">
                        <span style="display:flex;align-items:center;gap:8px;">
                            <i class="bi bi-bar-chart"></i>
                            <span>Daily Attendance Trend</span>
                        </span>
                    </div>
                    <div class="card-body">
                        <div class="chart-container"><canvas id="trendChart"></canvas></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ═══ Report Table ═══ -->
        <div class="card report-table-wrapper animate-in" style="animation-delay:0.15s;">
            <div class="card-header">
                <span style="display:flex;align-items:center;gap:8px;">
                    <i class="bi bi-table"></i>
                    <span>Report Data</span>
                    <span class="count-pill"><?= number_format($total) ?></span>
                </span>
                <div class="d-flex gap-2">
                    <button onclick="printReport()" class="btn-table-export"><i class="bi bi-printer"></i> Print</button>
                    <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>" class="btn-table-export">
                        <i class="bi bi-download"></i> Export
                    </a>
                </div>
            </div>
            <div class="card-body p-0">
                <!-- Print Header — Standard School Letterhead (visible only in print) -->
                <?php
                $printDocTitle = 'Attendance Report';
                $printDocMeta  = '<span>Period: ' . formatDate($dateFrom) . ' to ' . formatDate($dateTo)
                               . ($gradeFilter ? ' | Grade: ' . $gradeFilter : '')
                               . ($sectionFilter ? ' | Section: ' . sanitize($sectionFilter) : '')
                               . ($subjectFilter ? ' | Subject: ' . sanitize($subjects[array_search($subjectFilter, array_column($subjects, 'id'))]['subject_name'] ?? '') : '')
                               . '</span>'
                               . '<span>Generated: ' . date('F d, Y h:i A') . '</span>';
                include __DIR__ . '/../includes/print-header.php';
                ?>
                
                <!-- Desktop Table -->
                <div class="table-scroll-wrapper">
                    <?php if (empty($records)): ?>
                        <div class="empty-state">
                            <div class="empty-icon"><i class="bi bi-clipboard-data"></i></div>
                            <h6>No Records Found</h6>
                            <p>Adjust filters to view attendance data for the selected period.</p>
                        </div>
                    <?php else: ?>
                        <table class="report-table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Student ID</th>
                                    <th>Name</th>
                                    <th>Grade</th>
                                    <th>Section</th>
                                    <th>Subject</th>
                                    <th>Source</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($pageRecords as $rec): ?>
                            <tr>
                                <td>
                                    <span class="cell-date"><?= formatDate($rec['date'], 'M d, Y') ?></span>
                                    </td>
                                    <td>
                                        <span class="cell-student-id"><?= sanitize($rec['sid'] ?? '-') ?></span>
                                    </td>
                                    <td class="cell-name">
                                        <?= sanitize(($rec['first_name'] ?? '') . ' ' . ($rec['last_name'] ?? '')) ?>
                                    </td>
                                    <td>
                                        <span class="cell-grade">Grade <?= $rec['grade_level'] ?? '-' ?></span>
                                    </td>
                                    <td class="cell-section"><?= sanitize($rec['section'] ?? '-') ?></td>
                                    <td class="cell-subject"><?= sanitize($rec['subject_name'] ?? '-') ?></td>
                                    <td>
                                        <?php if ($rec['source'] === 'gate'): ?>
                                            <span class="cell-source gate"><i class="bi bi-door-open" style="font-size:9px;"></i> Gate</span>
                                        <?php else: ?>
                                            <span class="cell-source class-source"><i class="bi bi-book" style="font-size:9px;"></i> Class</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge-status badge-<?= $rec['status'] ?>">
                                            <?php if ($rec['status'] === 'present'): ?>
                                                <i class="bi bi-check-circle-fill" style="font-size:9px;"></i>
                                            <?php elseif ($rec['status'] === 'late'): ?>
                                                <i class="bi bi-clock-fill" style="font-size:9px;"></i>
                                            <?php else: ?>
                                                <i class="bi bi-x-circle-fill" style="font-size:9px;"></i>
                                            <?php endif; ?>
                                            <?= ucfirst($rec['status']) ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
                <!-- Print-only full data table (all records) -->
                <div class="report-table-print-all">
                    <?php if (empty($records)): ?>
                        <div class="empty-state">
                            <div class="empty-icon"><i class="bi bi-clipboard-data"></i></div>
                            <h6>No Records Found</h6>
                            <p>Adjust filters to view attendance data for the selected period.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-scroll-wrapper">
                            <table class="report-table">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Student ID</th>
                                        <th>Name</th>
                                        <th>Grade</th>
                                        <th>Section</th>
                                        <th>Subject</th>
                                        <th>Source</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($records as $rec): ?>
                                <tr>
                                    <td>
                                        <span class="cell-date"><?= formatDate($rec['date'], 'M d, Y') ?></span>
                                    </td>
                                    <td>
                                        <span class="cell-student-id"><?= sanitize($rec['sid'] ?? '-') ?></span>
                                    </td>
                                    <td class="cell-name">
                                        <?= sanitize(($rec['first_name'] ?? '') . ' ' . ($rec['last_name'] ?? '')) ?>
                                    </td>
                                    <td>
                                        <span class="cell-grade">Grade <?= $rec['grade_level'] ?? '-' ?></span>
                                    </td>
                                    <td class="cell-section"><?= sanitize($rec['section'] ?? '-') ?></td>
                                    <td class="cell-subject"><?= sanitize($rec['subject_name'] ?? '-') ?></td>
                                    <td>
                                        <?php if ($rec['source'] === 'gate'): ?>
                                            <span class="cell-source gate"><i class="bi bi-door-open" style="font-size:9px;"></i> Gate</span>
                                        <?php else: ?>
                                            <span class="cell-source class-source"><i class="bi bi-book" style="font-size:9px;"></i> Class</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge-status badge-<?= $rec['status'] ?>">
                                            <?php if ($rec['status'] === 'present'): ?>
                                                <i class="bi bi-check-circle-fill" style="font-size:9px;"></i>
                                            <?php elseif ($rec['status'] === 'late'): ?>
                                                <i class="bi bi-clock-fill" style="font-size:9px;"></i>
                                            <?php else: ?>
                                                <i class="bi bi-x-circle-fill" style="font-size:9px;"></i>
                                            <?php endif; ?>
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
                <?php
                    $baseQuery = array_filter($_GET, function ($k) { return $k !== 'page'; }, ARRAY_FILTER_USE_KEY);
                    function rptPageUrl($p) {
                        global $baseQuery;
                        return '?' . http_build_query(array_merge($baseQuery, ['page' => $p]));
                    }
                ?>
                <div class="rpt-pagination">
                    <span class="rpt-page-info">Showing <?= number_format($offset + 1) ?>–<?= number_format(min($offset + $perPage, $total)) ?> of <?= number_format($total) ?></span>
                    <nav class="rpt-page-nav">
                        <a class="rpt-page-btn <?= $page <= 1 ? 'disabled' : '' ?>" href="<?= $page <= 1 ? '#' : rptPageUrl($page - 1) ?>" <?= $page <= 1 ? 'aria-disabled="true"' : '' ?>>
                            <i class="bi bi-chevron-left"></i>
                        </a>
                        <?php
                            $window = 2;
                            $pages = [];
                            for ($i = 1; $i <= $totalPages; $i++) {
                                if ($i == 1 || $i == $totalPages || ($i >= $page - $window && $i <= $page + $window)) {
                                    $pages[] = $i;
                                } elseif (end($pages) != '...') {
                                    $pages[] = '...';
                                }
                            }
                            foreach ($pages as $p):
                        ?>
                            <?php if ($p === '...'): ?>
                                <span class="rpt-page-ellipsis">…</span>
                            <?php else: ?>
                                <a class="rpt-page-btn <?= $p == $page ? 'active' : '' ?>" href="<?= rptPageUrl($p) ?>"><?= $p ?></a>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        <a class="rpt-page-btn <?= $page >= $totalPages ? 'disabled' : '' ?>" href="<?= $page >= $totalPages ? '#' : rptPageUrl($page + 1) ?>" <?= $page >= $totalPages ? 'aria-disabled="true"' : '' ?>>
                            <i class="bi bi-chevron-right"></i>
                        </a>
                    </nav>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Hidden print area — full data (all records, not paginated) -->
    <div id="printReportArea">
        <?php
        $printDocTitle = 'Attendance Report';
        $printDocMeta  = '<span><strong>Period:</strong> ' . formatDate($dateFrom) . ' to ' . formatDate($dateTo) . '</span>'
                       . '<span><strong>Total Records:</strong> ' . number_format($total) . '</span>'
                       . ($gradeFilter ? '<span><strong>Grade:</strong> ' . $gradeFilter . '</span>' : '')
                       . ($sectionFilter ? '<span><strong>Section:</strong> ' . sanitize($sectionFilter) . '</span>' : '')
                       . '<span><strong>Generated:</strong> ' . date('F d, Y g:i A') . '</span>';
        include __DIR__ . '/../includes/print-header.php';
        ?>
        <table class="print-table">
            <thead>
                <tr>
                    <th style="width:100px;">Date</th>
                    <th style="width:90px;">Student ID</th>
                    <th>Name</th>
                    <th style="width:60px;">Grade</th>
                    <th style="width:70px;">Section</th>
                    <th>Subject</th>
                    <th style="width:60px;">Source</th>
                    <th style="width:70px;">Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($records as $rec): ?>
                <tr>
                    <td style="font-family:'Courier New',monospace;font-size:10px;color:#555;"><?= formatDate($rec['date'], 'M d, Y') ?></td>
                    <td style="font-family:'Courier New',monospace;font-size:11px;"><?= sanitize($rec['sid'] ?? '-') ?></td>
                    <td style="font-weight:600;"><?= sanitize(($rec['first_name'] ?? '') . ' ' . ($rec['last_name'] ?? '')) ?></td>
                    <td>Grade <?= $rec['grade_level'] ?? '-' ?></td>
                    <td><?= sanitize($rec['section'] ?? '-') ?></td>
                    <td><?= sanitize($rec['subject_name'] ?? '-') ?></td>
                    <td style="font-size:10px;font-weight:600;"><?= ucfirst($rec['source'] ?? '-') ?></td>
                    <td>
                        <span style="display:inline-block;padding:2px 10px;border-radius:12px;font-size:9px;font-weight:700;background:<?= $rec['status'] === 'present' ? '#d1fae5' : ($rec['status'] === 'late' ? '#fef3c7' : '#fee2e2') ?>;color:<?= $rec['status'] === 'present' ? '#065f46' : ($rec['status'] === 'late' ? '#92400e' : '#991b1b') ?>;">
                            <?= ucfirst($rec['status']) ?>
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <div style="text-align:center;margin-top:30px;padding-top:12px;border-top:2px solid #e5e7eb;font-size:10px;color:#94a3b8;">
            <p>Generated on <?= date('F d, Y g:i A') ?> &bull; Attendance Report &bull; Confidential</p>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
function printReport() {
    var area = document.getElementById('printReportArea');
    if (!area) return;
    var pw = window.open('', '_blank', 'width=900,height=700');
    pw.document.write(
        '<!DOCTYPE html><html><head><title>Attendance Report</title>' +
        '<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/print.css">' +
        '<style>' +
        'body.print-new-window{margin:0;padding:20px 25px;background:#fff;font-family:"Segoe UI",Arial,sans-serif;color:#333;}' +
        '.print-table{width:100%;border-collapse:collapse;font-size:12px;margin-top:10px;}' +
        '.print-table thead{background:#f0f1f4;-webkit-print-color-adjust:exact;print-color-adjust:exact;}' +
        '.print-table thead th{padding:10px 12px;text-align:left;font-weight:700;font-size:11px;text-transform:uppercase;letter-spacing:0.05em;color:#555;border-bottom:2px solid #ddd;white-space:nowrap;}' +
        '.print-table tbody td{padding:8px 12px;border-bottom:1px solid #eee;color:#444;}' +
        '.print-table tbody tr:last-child td{border-bottom:none;}' +
        '.print-table tbody tr{page-break-inside:avoid;}' +
        '@media print{body.print-new-window{padding:0;}.print-table{margin-top:0;}}' +
        '</style></head>' +
        '<body class="print-new-window">' +
        area.innerHTML +
        '<script>window.onload=function(){setTimeout(function(){window.print();},100);};<\/script>' +
        '</body></html>'
    );
    pw.document.close();
}
</script>
(function() {
    'use strict';

    // ─── Toast ──────────────────────────────────────────────────────────
    function showToast(type, message) {
        var c = document.getElementById('toastContainer');
        var icons = { success: 'bi-check-circle-fill', danger: 'bi-x-circle-fill', warning: 'bi-exclamation-triangle-fill', info: 'bi-info-circle-fill' };
        var t = document.createElement('div');
        t.className = 'rpt-toast ' + type;
        t.innerHTML = '<i class="bi ' + (icons[type] || icons.info) + '"></i><span>' + esc(message) + '</span>';
        c.appendChild(t);
        setTimeout(function() { if (t.parentNode) t.remove(); }, 4200);
    }

    <?php if (!empty($_SESSION['flash_message'])): ?>
    showToast('<?= $_SESSION['flash_message']['type'] ?>', <?= json_encode($_SESSION['flash_message']['message']) ?>);
    <?php unset($_SESSION['flash_message']); endif; ?>

    // ─── Charts ─────────────────────────────────────────────────────────
    var isMobile = window.innerWidth <= 767;

    // Doughnut — Attendance Breakdown
    var breakdownCanvas = document.getElementById('breakdownChart');
    if (breakdownCanvas && typeof Chart !== 'undefined') {
        new Chart(breakdownCanvas.getContext('2d'), {
            type: 'doughnut',
            data: {
                labels: ['Present', 'Late', 'Absent'],
                datasets: [{
                    data: [<?= $pCount ?>, <?= $lCount ?>, <?= $aCount ?>],
                    backgroundColor: ['#10b981', '#f59e0b', '#ef4444'],
                    borderWidth: 0,
                    hoverOffset: isMobile ? 4 : 8
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: isMobile ? '55%' : '60%',
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            usePointStyle: true,
                            pointStyle: 'circle',
                            padding: isMobile ? 10 : 15,
                            color: 'rgba(255,255,255,0.6)',
                            font: { size: isMobile ? 10 : 12, family: "'Plus Jakarta Sans', sans-serif" }
                        }
                    },
                    tooltip: {
                        titleFont: { size: isMobile ? 11 : 13, family: "'Plus Jakarta Sans', sans-serif" },
                        bodyFont: { size: isMobile ? 10 : 12, family: "'Plus Jakarta Sans', sans-serif" },
                        padding: 10,
                        cornerRadius: 8
                    }
                }
            }
        });
    }

    // Stacked Bar — Daily Trend
    var dailyData = <?= json_encode($dailyTrend) ?>;
    var dates = Object.keys(dailyData).sort();
    var trendCanvas = document.getElementById('trendChart');
    if (trendCanvas && typeof Chart !== 'undefined') {
        new Chart(trendCanvas.getContext('2d'), {
            type: 'bar',
            data: {
                labels: dates.map(function(d) {
                    return new Date(d + 'T00:00:00').toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
                }),
                datasets: [
                    {
                        label: 'Present',
                        data: dates.map(function(d) { return dailyData[d].present; }),
                        backgroundColor: '#10b981',
                        borderRadius: isMobile ? 2 : 4,
                        borderSkipped: false
                    },
                    {
                        label: 'Late',
                        data: dates.map(function(d) { return dailyData[d].late; }),
                        backgroundColor: '#f59e0b',
                        borderRadius: isMobile ? 2 : 4,
                        borderSkipped: false
                    },
                    {
                        label: 'Absent',
                        data: dates.map(function(d) { return dailyData[d].absent; }),
                        backgroundColor: '#ef4444',
                        borderRadius: isMobile ? 2 : 4,
                        borderSkipped: false
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            usePointStyle: true,
                            pointStyle: 'circle',
                            padding: isMobile ? 10 : 15,
                            color: 'rgba(255,255,255,0.6)',
                            font: { size: isMobile ? 10 : 12, family: "'Plus Jakarta Sans', sans-serif" }
                        }
                    },
                    tooltip: {
                        titleFont: { size: isMobile ? 11 : 13, family: "'Plus Jakarta Sans', sans-serif" },
                        bodyFont: { size: isMobile ? 10 : 12, family: "'Plus Jakarta Sans', sans-serif" },
                        padding: 10,
                        cornerRadius: 8
                    }
                },
                scales: {
                    x: {
                        stacked: true,
                        grid: { display: false },
                        ticks: {
                            color: 'rgba(255,255,255,0.4)',
                            font: { size: isMobile ? 9 : 11, family: "'Plus Jakarta Sans', sans-serif" },
                            maxRotation: isMobile ? 45 : 0
                        }
                    },
                    y: {
                        stacked: true,
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1,
                            color: 'rgba(255,255,255,0.4)',
                            font: { size: isMobile ? 9 : 11, family: "'Plus Jakarta Sans', sans-serif" }
                        },
                        grid: { color: 'rgba(255,255,255,0.04)' }
                    }
                }
            }
        });
    }

    function esc(s) { if (!s) return ''; var d = document.createElement('div'); d.appendChild(document.createTextNode(s)); return d.innerHTML; }
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>