<?php

require_once __DIR__ . '/../config.php';
requireRole(['admin']);

$currentPageUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];

$pageTitle = 'Face Registration Dashboard';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

$stats = ['total' => 0, 'registered' => 0, 'pending' => 0, 'percent' => 0];
$gradeStats = [];
$unregistered = [];
$recentRegistrations = [];

try {
    $total = $db->query("SELECT COUNT(*) FROM students WHERE status = 'active'")->fetchColumn();
    $registered = $db->query("SELECT COUNT(*) FROM student_faces WHERE face_encoding IS NOT NULL")->fetchColumn();
    $stats['total'] = $total;
    $stats['registered'] = $registered;
    $stats['pending'] = max(0, $total - $registered);
    $stats['percent'] = $total > 0 ? round(($registered / $total) * 100) : 0;

    $gradeStats = $db->query(
        "SELECT s.grade_level, COUNT(*) as total,
                SUM(CASE WHEN sf.face_encoding IS NOT NULL THEN 1 ELSE 0 END) as registered
         FROM students s LEFT JOIN student_faces sf ON s.id = sf.student_id
         WHERE s.status = 'active' GROUP BY s.grade_level ORDER BY s.grade_level"
    )->fetchAll();

    $unregistered = $db->query(
        "SELECT s.id, s.student_id, s.first_name, s.last_name, s.grade_level, s.section
         FROM students s LEFT JOIN student_faces sf ON s.id = sf.student_id
         WHERE s.status = 'active' AND (sf.face_encoding IS NULL OR sf.id IS NULL)
         ORDER BY s.grade_level, s.last_name LIMIT 200"
    )->fetchAll();

    $recentRegistrations = $db->query(
        "SELECT s.student_id, s.first_name, s.last_name, s.grade_level, s.section, sf.updated_at
         FROM student_faces sf JOIN students s ON sf.student_id = s.id
         WHERE sf.face_encoding IS NOT NULL ORDER BY sf.updated_at DESC LIMIT 20"
    )->fetchAll();
} catch (Exception $e) { error_log("Registration dashboard error: " . $e->getMessage()); }
?>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/pages-theme.css">

<style>
/* ═══════════════════════════════════════════════════════════════════════════
   DESIGN SYSTEM — Face Registration Dashboard (Dark Theme)
   ═══════════════════════════════════════════════════════════════════════════ */

:root {
    --frd-font: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif;
    --frd-mono: 'JetBrains Mono', 'SF Mono', monospace;

    --frd-primary: #4f46e5;
    --frd-primary-glow: rgba(79,70,229,0.25);
    --frd-accent: #4f46e5;
    --frd-accent-soft: rgba(79,70,229,0.15);
    --frd-accent-hover: #4338ca;

    --frd-success: #10b981;
    --frd-success-soft: rgba(16,185,129,0.15);
    --frd-danger: #ef4444;
    --frd-danger-soft: rgba(239,68,68,0.15);
    --frd-warning: #f59e0b;
    --frd-warning-soft: rgba(245,158,11,0.15);
    --frd-info: #06b6d4;
    --frd-info-soft: rgba(6,182,212,0.15);

    --frd-bg: #0b0b14;
    --frd-surface: rgba(255,255,255,0.04);
    --frd-surface-hover: rgba(255,255,255,0.07);
    --frd-surface-card: rgba(255,255,255,0.04);
    --frd-border: rgba(255,255,255,0.06);
    --frd-text: #f0ece4;
    --frd-text-secondary: rgba(255,255,255,0.55);
    --frd-text-muted: rgba(255,255,255,0.3);

    --frd-shadow-sm: 0 1px 3px rgba(0,0,0,0.3);
    --frd-shadow: 0 4px 16px rgba(0,0,0,0.25);
    --frd-shadow-md: 0 8px 24px rgba(0,0,0,0.3);
    --frd-shadow-lg: 0 12px 40px rgba(0,0,0,0.35);

    --frd-radius-sm: 10px;
    --frd-radius: 14px;
    --frd-radius-lg: 16px;
    --frd-transition: 0.2s cubic-bezier(0.4, 0, 0.2, 1);
    --frd-spring: 0.35s cubic-bezier(0.34,1.56,0.64,1);

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
    font-size: 20px; font-weight: 800; color: var(--frd-text);
    margin: 0; letter-spacing: -0.03em;
    display: flex; align-items: center; gap: 8px;
}
.top-navbar .page-title h5 i { color: var(--frd-accent); font-size: 22px; }
.top-navbar .page-title small {
    font-size: 13px; color: var(--frd-text-secondary); font-weight: 500;
}

/* MOBILE PAGE TITLE */
.mobile-title { display: none; padding: 14px 0 4px; }
.mobile-title-inner { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; }
.mobile-title-left h5 { font-size: 18px; font-weight: 800; letter-spacing: -0.03em; margin: 0; color: var(--frd-text); display: flex; align-items: center; gap: 7px; }
.mobile-title-left h5 i { color: var(--frd-accent); font-size: 18px; }
.mobile-title-left small { font-size: 12px; font-weight: 500; color: var(--frd-text-secondary); }
.mobile-date { font-size: 11px; font-weight: 600; padding: 6px 10px; border-radius: var(--frd-radius-sm); display: flex; align-items: center; gap: 6px; white-space: nowrap; flex-shrink: 0; margin-top: 2px; background: var(--frd-surface); color: var(--frd-text-secondary); }
.mobile-date i { font-size: 12px; color: var(--frd-accent); }

.navbar-actions { display: flex; align-items: center; gap: 10px; }
.navbar-actions .date-pill {
    font-size: 13px; font-weight: 600; padding: 8px 16px;
    border-radius: var(--frd-radius-sm);
    background: var(--frd-surface); border: none;
    color: var(--frd-text-secondary);
    display: flex; align-items: center; gap: 8px;
}
.navbar-actions .date-pill i { font-size: 14px; color: var(--frd-accent); }

/* NAV ICON BUTTON */
.nav-icon-btn {
    width: 40px; height: 40px;
    border-radius: var(--frd-radius-sm);
    background: var(--frd-surface); border: none;
    color: var(--frd-text);
    display: inline-flex; align-items: center; justify-content: center;
    cursor: pointer; transition: all var(--frd-transition); font-size: 16px;
}
.nav-icon-btn:hover { transform: translateY(-1px); background: var(--frd-surface-hover); }

.content-area { padding: 24px 28px 40px; }

/* ─── Cards ───────────────────────────────────────────────────────────── */
.card {
    background: var(--frd-surface-card);
    border: none;
    border-radius: var(--frd-radius);
    box-shadow: var(--frd-shadow-sm);
    transition: all var(--frd-transition);
    overflow: hidden;
}
.card:hover { box-shadow: var(--frd-shadow); }
.card-header {
    background: transparent;
    border-bottom: 1px solid var(--frd-border);
    padding: 14px 20px;
    font-size: 14px; font-weight: 700;
    color: var(--frd-text);
    letter-spacing: -0.01em;
    display: flex; align-items: center; justify-content: space-between;
}
.card-header i { color: #ffffff; font-size: 15px; }
.card-body { padding: 20px; color: var(--frd-text); }

/* ─── Stat Cards ──────────────────────────────────────────────────────── */
.stat-card {
    background: var(--frd-surface-card);
    border: none;
    border-radius: var(--frd-radius);
    padding: 20px;
    box-shadow: var(--frd-shadow-sm);
    transition: all var(--frd-transition);
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
    border-radius: var(--frd-radius) var(--frd-radius) 0 0;
    opacity: 0;
    transition: opacity var(--frd-transition);
}
.stat-card:hover { transform: translateY(-3px); box-shadow: var(--frd-shadow-md); }
.stat-card:hover::before { opacity: 1; }
.stat-card:nth-child(1) { animation-delay: 0.05s; }
.stat-card:nth-child(1)::before { background: var(--frd-accent); }
.stat-card:nth-child(2) { animation-delay: 0.1s; }
.stat-card:nth-child(2)::before { background: var(--frd-success); }
.stat-card:nth-child(3) { animation-delay: 0.15s; }
.stat-card:nth-child(3)::before { background: var(--frd-warning); }
.stat-card:nth-child(4) { animation-delay: 0.2s; }
.stat-card:nth-child(4)::before { background: var(--frd-info); }
.stat-value {
    font-size: 28px; font-weight: 800; color: var(--frd-text);
    font-family: var(--frd-mono); letter-spacing: -0.03em;
    line-height: 1.2;
}
.stat-label {
    font-size: 11px; font-weight: 600; color: var(--frd-text-muted);
    text-transform: uppercase; letter-spacing: 0.06em;
    margin-top: 6px;
}
.stat-icon {
    width: 44px; height: 44px;
    border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    font-size: 18px; flex-shrink: 0;
}
.bg-primary-soft { background: var(--frd-accent-soft); color: var(--frd-accent); }
.bg-success-soft { background: var(--frd-success-soft); color: var(--frd-success); }
.bg-warning-soft { background: var(--frd-warning-soft); color: var(--frd-warning); }
.bg-info-soft { background: var(--frd-info-soft); color: var(--frd-info); }

/* Stat progress bar */
.stat-progress {
    margin-top: 10px;
    height: 6px;
    border-radius: 4px;
    background: rgba(255,255,255,0.06);
    overflow: hidden;
}
.stat-progress-bar {
    height: 100%;
    border-radius: 4px;
    transition: width 0.8s cubic-bezier(0.4,0,0.2,1);
}

/* ─── Buttons ─────────────────────────────────────────────────────────── */
.btn-kiosk {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 9px 20px;
    border: none;
    border-radius: var(--frd-radius-sm);
    font-size: 13px; font-weight: 700;
    color: #fff;
    background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
    box-shadow: 0 2px 12px rgba(79,70,229,0.3);
    cursor: pointer;
    text-decoration: none;
    transition: all var(--frd-spring);
}
.btn-kiosk:hover {
    transform: translateY(-1px);
    box-shadow: 0 6px 20px rgba(79,70,229,0.4);
    color: #fff;
    text-decoration: none;
}
.btn-export-top {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 9px 18px;
    border: none;
    border-radius: var(--frd-radius-sm);
    font-size: 12px; font-weight: 700;
    color: #fff;
    background: linear-gradient(135deg, #059669 0%, #10b981 100%);
    box-shadow: 0 2px 12px rgba(16,185,129,0.25);
    cursor: pointer;
    transition: all var(--frd-spring);
}
.btn-export-top:hover {
    transform: translateY(-1px);
    box-shadow: 0 6px 20px rgba(16,185,129,0.35);
    color: #fff;
}
.btn-refresh {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 6px 14px;
    border: 1.5px solid var(--frd-border);
    border-radius: 8px;
    font-size: 12px; font-weight: 700;
    color: var(--frd-text-secondary);
    background: var(--frd-surface);
    cursor: pointer;
    transition: all var(--frd-transition);
}
.btn-refresh:hover {
    border-color: var(--frd-accent);
    color: var(--frd-accent);
    background: var(--frd-accent-soft);
}
.btn-register {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 6px 14px;
    border: 1.5px solid var(--frd-accent);
    border-radius: 8px;
    font-size: 12px; font-weight: 700;
    color: var(--frd-accent);
    background: var(--frd-accent-soft);
    cursor: pointer;
    text-decoration: none;
    transition: all var(--frd-transition);
}
.btn-register:hover {
    background: var(--frd-accent);
    color: #fff;
    text-decoration: none;
    transform: translateY(-1px);
}
.btn-send-reminders {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 6px 14px;
    border: none;
    border-radius: 8px;
    font-size: 12px; font-weight: 700;
    color: #fff;
    background: linear-gradient(135deg, #059669, #10b981);
    box-shadow: 0 2px 8px rgba(16,185,129,0.2);
    cursor: pointer;
    transition: all var(--frd-spring);
}
.btn-send-reminders:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 14px rgba(16,185,129,0.3);
    color: #fff;
}

/* ─── Table (light bg) ────────────────────────────────────────────────── */
.table-scroll-wrapper {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    background: var(--tbl-bg);
}
.table-scroll-wrapper::-webkit-scrollbar { height: 6px; }
.table-scroll-wrapper::-webkit-scrollbar-track { background: rgba(0,0,0,0.02); }
.table-scroll-wrapper::-webkit-scrollbar-thumb { background: rgba(0,0,0,0.1); border-radius: 6px; }

.data-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
    color: var(--tbl-text);
    background: var(--tbl-bg);
    margin: 0;
}
.data-table thead { background: var(--tbl-bg-thead); }
.data-table thead th {
    padding: 12px 16px;
    font-size: 11px; font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.06em;
    color: var(--tbl-text-muted);
    border-bottom: 1px solid var(--tbl-border);
    white-space: nowrap;
}
.data-table thead.sticky-top th {
    position: sticky; top: 0; z-index: 2;
    background: var(--tbl-bg-thead);
}
.data-table tbody td {
    padding: 12px 16px;
    font-size: 13px;
    vertical-align: middle;
    border-bottom: 1px solid var(--tbl-border-light);
    transition: background 0.15s ease;
    color: var(--tbl-text-secondary);
}
.data-table tbody tr { background: var(--tbl-bg); }
.data-table tbody tr:hover td { background: var(--tbl-bg-hover); }
.data-table tbody tr:last-child td { border-bottom: none; }

.data-table .cell-mono {
    font-family: var(--frd-mono); font-size: 12px; font-weight: 500;
}
.data-table .cell-name { font-weight: 600; color: var(--tbl-text); }
.data-table .cell-grade-pill {
    font-size: 10px; font-weight: 700; padding: 3px 10px;
    border-radius: 6px; display: inline-block;
    background: rgba(79,70,229,0.08); color: #4f46e5;
    letter-spacing: 0.04em;
}
.data-table .cell-success { color: #059669; font-weight: 600; }
.data-table .cell-warning { color: #b45309; font-weight: 600; }

/* Grade progress bar (in table) */
.grade-progress { display: flex; align-items: center; gap: 8px; min-width: 140px; }
.grade-progress .progress-bar-wrap {
    flex: 1; height: 7px; border-radius: 4px;
    background: rgba(0,0,0,0.06); overflow: hidden;
}
.grade-progress .progress-bar-inner {
    height: 100%; border-radius: 4px;
    transition: width 0.8s cubic-bezier(0.4,0,0.2,1);
}
.grade-progress small {
    font-size: 11px; font-weight: 700;
    font-family: var(--frd-mono); min-width: 32px; text-align: right;
    color: var(--tbl-text-muted);
}

/* Form control inside table */
.form-check-input {
    width: 16px; height: 16px;
    border-radius: 4px;
    border: 1.5px solid rgba(0,0,0,0.2);
    background: #fff;
    cursor: pointer;
    accent-color: var(--frd-accent);
}

/* ─── Table Scroll ──────────────────────────────────────────────────────── */
.recent-item {
    display: flex; align-items: center; gap: 12px;
    padding: 12px 16px;
    border-bottom: 1px solid var(--frd-border);
    transition: background var(--frd-transition);
}
.recent-item:last-child { border-bottom: none; }
.recent-item:hover { background: var(--frd-surface-hover); }
.recent-avatar {
    width: 35px; height: 35px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 14px; flex-shrink: 0;
}
.recent-info { flex: 1; min-width: 0; }
.recent-name { font-weight: 600; font-size: 13px; color: var(--frd-text); }
.recent-meta { font-size: 11px; color: var(--frd-text-muted); }
.recent-date { font-size: 11px; color: var(--frd-text-muted); flex-shrink: 0; font-family: var(--frd-mono); }

/* ─── Tips ────────────────────────────────────────────────────────────── */
.tip-item {
    padding: 10px 14px; margin-bottom: 6px;
    border-radius: var(--frd-radius-sm);
    font-size: 12px; line-height: 1.5;
    display: flex; align-items: flex-start; gap: 8px;
}
.tip-item.info { background: rgba(6,182,212,0.08); border: 1px solid rgba(6,182,212,0.15); color: var(--frd-text-secondary); }
.tip-item.warning { background: rgba(245,158,11,0.08); border: 1px solid rgba(245,158,11,0.15); color: var(--frd-text-secondary); }
.tip-item i { flex-shrink: 0; margin-top: 1px; font-size: 14px; }
.tip-item strong { font-weight: 700; color: var(--frd-text); }

/* ─── Search Box ──────────────────────────────────────────────────────── */
.search-box { position: relative; }
.search-box .search-icon {
    position: absolute; left: 10px; top: 50%; transform: translateY(-50%);
    color: var(--frd-text-muted); font-size: 14px; pointer-events: none;
}
.search-box .search-input {
    width: 100%;
    padding: 8px 12px 8px 32px;
    border: 1.5px solid var(--frd-border);
    border-radius: var(--frd-radius-sm);
    font-size: 13px; font-weight: 500;
    color: var(--frd-text);
    background: var(--frd-surface);
    transition: all var(--frd-transition);
    font-family: var(--frd-font);
}
.search-box .search-input:focus {
    border-color: var(--frd-accent);
    box-shadow: 0 0 0 3px var(--frd-primary-glow);
    outline: none;
    background: var(--frd-surface-hover);
}
.search-box .search-input::placeholder { color: var(--frd-text-muted); }

/* ─── Empty State ─────────────────────────────────────────────────────── */
.empty-state { text-align: center; padding: 48px 20px; }
.empty-state .empty-icon {
    width: 60px; height: 60px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    margin: 0 auto 16px; font-size: 24px;
}
.empty-state h6 { font-weight: 700; font-size: 16px; margin-bottom: 6px; color: var(--frd-text); }
.empty-state p { font-size: 13px; color: var(--frd-text-muted); }

/* ─── Count Pill ──────────────────────────────────────────────────────── */
.count-pill {
    background: var(--frd-accent-soft); color: var(--frd-accent);
    font-size: 11px; font-weight: 700; padding: 3px 10px;
    border-radius: 20px; font-family: var(--frd-mono);
}

/* ─── Badge (mobile grade progress) ───────────────────────────────────── */
.grade-badge {
    font-size: 11px; font-weight: 700; padding: 3px 10px;
    border-radius: 20px; font-family: var(--frd-mono);
}
.grade-badge.high { background: rgba(16,185,129,0.12); color: #059669; }
.grade-badge.mid { background: rgba(245,158,11,0.12); color: #b45309; }
.grade-badge.low { background: rgba(239,68,68,0.12); color: #dc2626; }

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
.frd-toast {
    padding: 14px 22px; border-radius: var(--frd-radius-sm);
    color: #fff; font-size: 13px; font-weight: 600;
    display: flex; align-items: center; gap: 12px;
    max-width: 400px; pointer-events: auto;
    animation: toastIn 0.4s cubic-bezier(0.34,1.56,0.64,1), toastOut 0.4s ease 3.6s forwards;
    font-family: var(--frd-font);
    box-shadow: 0 8px 24px rgba(0,0,0,0.5);
}
.frd-toast.success { background: #059669; }
.frd-toast.danger  { background: #dc2626; }
.frd-toast.warning { background: #d97706; color: #1a1a1a; }
.frd-toast.info    { background: #2563eb; }
.frd-toast i { font-size: 18px; flex-shrink: 0; opacity: 0.9; }
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
    .btn-kiosk { padding: 7px 12px !important; font-size: 12px !important; }
    .btn-kiosk .btn-text { display: none; }
    .btn-export-top { padding: 7px 12px !important; font-size: 12px !important; }
    .btn-export-top .btn-text { display: none; }



    /* ─── Content ─────────────────────────────────────────────────────── */
    .content-area { padding: 10px 12px 28px; }
    .card { border-radius: var(--frd-radius-sm); }
    .card-header { font-size: 13px; padding: 12px 16px; }
    .card-body { padding: 16px; }

    /* ─── Stat Cards ──────────────────────────────────────────────────── */
    .stat-card { padding: 16px; animation: none; opacity: 1; }
    .stat-value { font-size: 22px !important; }
    .stat-label { font-size: 10px; }
    .stat-icon { width: 38px; height: 38px; font-size: 15px; border-radius: 10px; }

    /* ─── Table → mobile scroll ─────────────────────────────────────────── */
    .data-table{min-width:500px}
    .data-table thead th{padding:10px 12px;font-size:10px}
    .data-table tbody td{padding:10px 12px;font-size:12px}

    /* ─── Pending Search Bar ───────────────────────────────────────────── */
    .pending-search-bar { flex-wrap: wrap; gap: 6px; }
    .pending-search-bar .search-box { flex: 1; min-width: 100%; }
    .search-box .search-input { padding: 7px 10px 7px 30px; font-size: 12px; }

    /* ─── Section Headers ─────────────────────────────────────────────── */
    .section-header { padding: 10px 14px !important; flex-wrap: wrap; gap: 8px; }

    /* ─── Tips ────────────────────────────────────────────────────────── */
    .tip-item { font-size: 11px; padding: 8px 12px; }

    /* ─── Recent Registrations ─────────────────────────────────────────── */
    .recent-item { padding: 10px 14px; gap: 10px; }
    .recent-avatar { width: 30px; height: 30px; font-size: 12px; }
    .recent-name { font-size: 12px; }
    .recent-meta { font-size: 10px; }
    .recent-date { font-size: 10px; }

    /* ─── Empty State ─────────────────────────────────────────────────── */
    .empty-state { padding: 36px 16px; }
    .empty-state .empty-icon { width: 48px; height: 48px; font-size: 20px; }
    .empty-state h6 { font-size: 14px; }
    .empty-state p { font-size: 12px; }

    /* ─── Toasts ──────────────────────────────────────────────────────── */
    .toast-container { bottom: 24px; right: 12px; left: 12px; }
    .frd-toast { max-width: 100%; font-size: 12px; padding: 12px 16px; }

    /* ─── Animations ──────────────────────────────────────────────────── */
    .animate-in { animation: none; opacity: 1; }
}

/* ═══════════════════════════════════════════════════════════════════════════
   RESPONSIVE — SMALL PHONE (max-width: 576px)
   ═══════════════════════════════════════════════════════════════════════════ */
@media (max-width: 576px) {
    .top-navbar { padding: 10px 10px; gap: 6px; }
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
    .stat-value { font-size: 18px !important; }
    .stat-label { font-size: 9px; letter-spacing: 0.04em; }
    .stat-icon { width: 32px; height: 32px; font-size: 13px; }

    .tip-item { font-size: 10px; padding: 7px 10px; }

    .count-pill { font-size: 10px; padding: 2px 8px; }

    .frd-toast { font-size: 11px; padding: 10px 14px; gap: 10px; }
    .frd-toast i { font-size: 16px; }

    .sidebar { width: 260px; }
}

/* ─── Desktop — hide mobile-only elements ─────────────────────────────── */
@media (min-width: 768px) {
    .mobile-title { display: none !important; }
}

/* ─── Print ───────────────────────────────────────────────────────────── */
@media print {
    .sidebar, .sidebar-overlay, .top-navbar, .mobile-title,
    .navbar-brand, .toast-container, .btn-kiosk, .btn-export-top,
    .btn-refresh, .btn-register, .btn-send-reminders,
    .search-box { display: none !important; }
    .main-content { margin: 0 !important; width: 100% !important; background: #fff !important; }
    .content-area { padding: 0 !important; }
    .card { box-shadow: none !important; border: 1px solid #ddd !important; background: #fff !important; }
    .stat-card { background: #fff !important; border: 1px solid #ddd !important; }
    .stat-value { color: #000 !important; }
    .stat-label { color: #666 !important; }
    .recent-item { border-color: #ddd !important; }
    .recent-name { color: #000 !important; }
    .recent-meta, .recent-date { color: #666 !important; }
}
</style>

<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/pages-navbar.css">

<!-- Toast Container -->
<div class="toast-container" id="toastContainer"></div>

<?php require_once __DIR__ . '/../includes/pages-topnavbar.php'; ?>
<div class="main-content">

    <div class="content-area">

        <div class="d-none d-md-flex justify-content-between align-items-center gap-3 mb-3">
            <div class="page-title mb-0">
                <h5 class="mb-0"> Face Registration Dashboard</h5>
                <small>Track and manage student face registration progress</small>
            </div>
            <div class="d-flex gap-2">
                <a href="<?= BASE_URL ?>/gate/register.php?return_to=<?= urlencode($currentPageUrl) ?>" class="btn-kiosk"><i class="bi bi-window"></i> <span class="btn-text">Open Registration Kiosk</span></a>
                <button class="btn-export-top" onclick="exportUnregistered()"><i class="bi bi-download"></i> <span class="btn-text">Export Pending List</span></button>
            </div>
        </div>

        <!-- MOBILE TITLE -->
        <div class="page-title mobile-title">
            <div class="mobile-title-inner">
                <div class="mobile-title-left">
                    <h5><i></i> Face Registration</h5>
                    <small>Track &amp; manage student face registration</small>
                </div>
                <div class="d-flex gap-2">
                    <a href="<?= BASE_URL ?>/gate/register.php?return_to=<?= urlencode($currentPageUrl) ?>" class="btn-kiosk"><i class="bi bi-window"></i> <span class="btn-text">Open Registration Kiosk</span></a>
                    <button class="btn-export-top" onclick="exportUnregistered()"><i class="bi bi-download"></i> <span class="btn-text">Export Pending List</span></button>
                </div>
            </div>
        </div>

        <!-- ═══ Stat Cards ═══ -->
        <div class="row g-3 mb-4">
            <div class="col-6 col-lg-3">
                <div class="stat-card">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-value"><?= number_format($stats['total']) ?></div>
                            <div class="stat-label">Total Active Students</div>
                        </div>
                        <div class="stat-icon bg-primary-soft"><i class="bi bi-people-fill"></i></div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="stat-card">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-value" style="color:var(--frd-success);"><?= number_format($stats['registered']) ?></div>
                            <div class="stat-label">Faces Registered</div>
                        </div>
                        <div class="stat-icon bg-success-soft"><i class="bi bi-check-circle-fill"></i></div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="stat-card">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-value" style="color:var(--frd-warning);"><?= number_format($stats['pending']) ?></div>
                            <div class="stat-label">Pending Registration</div>
                        </div>
                        <div class="stat-icon bg-warning-soft"><i class="bi bi-clock-fill"></i></div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="stat-card">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-value" style="color:<?= $stats['percent'] >= 80 ? 'var(--frd-success)' : ($stats['percent'] >= 50 ? 'var(--frd-warning)' : 'var(--frd-danger)') ?>;"><?= $stats['percent'] ?>%</div>
                            <div class="stat-label">Completion Rate</div>
                        </div>
                        <div class="stat-icon bg-info-soft"><i class="bi bi-graph-up"></i></div>
                    </div>
                    <div class="stat-progress">
                        <div class="stat-progress-bar" style="width:<?= $stats['percent'] ?>%;background:<?= $stats['percent'] >= 80 ? '#10b981' : ($stats['percent'] >= 50 ? '#f59e0b' : '#ef4444') ?>;"></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-3">
            <!-- LEFT: Grade Progress + Pending -->
            <div class="col-lg-8">
                <!-- Grade Level Progress -->
                <div class="card mb-4 animate-in">
                    <div class="card-header section-header">
                        <span style="display:flex;align-items:center;gap:8px;">
                            <i class="bi bi-bar-chart-fill"></i>
                            <span>Registration by Grade</span>
                        </span>
                        <button class="btn-refresh" onclick="refreshStats()">
                            <i class="bi bi-arrow-clockwise"></i> Refresh
                        </button>
                    </div>
                    <div class="card-body" style="padding:0;">
                        <?php if (empty($gradeStats)): ?>
                            <div class="empty-state" style="padding:32px 16px;">
                                <div class="empty-icon" style="background:var(--frd-surface-hover);color:var(--frd-text-muted);"><i class="bi bi-inbox"></i></div>
                                <h6>No Students Found</h6>
                            </div>
                        <?php else: ?>
                            <div class="table-scroll-wrapper">
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>Grade</th>
                                            <th>Total</th>
                                            <th>Registered</th>
                                            <th>Pending</th>
                                            <th style="min-width:150px;">Progress</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($gradeStats as $grade):
                                        $gp = $grade['total'] > 0 ? round(($grade['registered'] / $grade['total']) * 100) : 0;
                                        $barColor = $gp >= 80 ? '#10b981' : ($gp >= 50 ? '#f59e0b' : '#ef4444');
                                    ?>
                                    <tr>
                                        <td><strong style="color:var(--tbl-text);">Grade <?= sanitize($grade['grade_level']) ?></strong></td>
                                        <td><?= $grade['total'] ?></td>
                                        <td class="cell-success"><?= $grade['registered'] ?></td>
                                        <td class="cell-warning"><?= $grade['total'] - $grade['registered'] ?></td>
                                        <td>
                                            <div class="grade-progress">
                                                <div class="progress-bar-wrap">
                                                    <div class="progress-bar-inner" style="width:<?= $gp ?>%;background:<?= $barColor ?>;"></div>
                                                </div>
                                                <small><?= $gp ?>%</small>
                                            </div>
                                        </td>
                                        <td>
                                            <button class="btn-register" onclick="viewGradeStudents('<?= sanitize($grade['grade_level']) ?>')">
                                                <i class="bi bi-eye"></i> View
                                            </button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Unregistered Students -->
                <div class="card mb-4 animate-in" style="animation-delay:0.05s;">
                    <div class="card-header section-header pending-search-bar">
                        <span style="display:flex;align-items:center;gap:8px;">
                            <i class="bi bi-person-x-fill"></i>
                            <span>Pending</span>
                            <span class="count-pill"><?= count($unregistered) ?></span>
                        </span>
                        <div class="d-flex gap-2" style="max-width:280px;flex-grow:1;">
                            <div class="search-box" style="flex:1 1 0;min-width:0;">
                                <i class="bi bi-search search-icon"></i>
                                <input type="text" class="search-input" id="searchPending" placeholder="Search students...">
                            </div>
                            <button class="btn-send-reminders" onclick="sendReminders()">
                                <i class="bi bi-envelope"></i> <span class="d-none d-md-inline">Reminders</span>
                            </button>
                        </div>
                    </div>
                    <div class="card-body" style="padding:0;">
                        <?php if (empty($unregistered)): ?>
                            <div class="empty-state" style="padding:40px 16px;">
                                <div class="empty-icon" style="background:var(--frd-success-soft);color:var(--frd-success);">
                                    <i class="bi bi-check-circle-fill"></i>
                                </div>
                                <h6>All Registered!</h6>
                                <p>All active students have registered faces.</p>
                            </div>
                        <?php else: ?>
                            <div class="table-scroll-wrapper" style="max-height:400px;overflow-y:auto;">
                                <table class="data-table" id="unregisteredTable">
                                    <thead class="sticky-top">
                                        <tr>
                                            <th style="width:40px;"><input type="checkbox" id="selectAll" onchange="toggleSelectAll()"></th>
                                            <th>Student ID</th>
                                            <th>Name</th>
                                            <th>Grade</th>
                                            <th>Section</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($unregistered as $student): ?>
                                    <tr class="unregistered-row">
                                        <td><input type="checkbox" class="student-checkbox" value="<?= $student['id'] ?>"></td>
                                        <td><span class="cell-mono"><?= sanitize($student['student_id']) ?></span></td>
                                        <td class="cell-name"><?= sanitize($student['last_name'] . ', ' . $student['first_name']) ?></td>
                                        <td><span class="cell-grade-pill">Grade <?= sanitize($student['grade_level']) ?></span></td>
                                        <td><?= sanitize($student['section'] ?? '-') ?></td>
                                        <td>
                                            <a href="<?= BASE_URL ?>/admin/student-edit.php?id=<?= $student['id'] ?>&tab=face" class="btn-register">
                                                <i class="bi bi-camera"></i> Register
                                            </a>
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

            <!-- RIGHT SIDEBAR -->
            <div class="col-lg-4">
                <!-- Registration Tips -->
                <div class="card mb-4 animate-in" style="animation-delay:0.15s;">
                    <div class="card-header section-header">
                        <span style="display:flex;align-items:center;gap:8px;">
                            <i class="bi bi-lightbulb-fill"></i>
                            <span>Registration Tips</span>
                        </span>
                    </div>
                    <div class="card-body" style="padding:12px 16px;">
                        <div class="tip-item info">
                            <i class="bi bi-1-circle-fill" style="color:var(--frd-info);"></i>
                            <div><strong>Bulk Import:</strong> Use CSV import to add students first</div>
                        </div>
                        <div class="tip-item info">
                            <i class="bi bi-2-circle-fill" style="color:var(--frd-info);"></i>
                            <div><strong>Kiosk Mode:</strong> Open kiosk for student self-registration</div>
                        </div>
                        <div class="tip-item info">
                            <i class="bi bi-3-circle-fill" style="color:var(--frd-info);"></i>
                            <div><strong>Class Schedule:</strong> Register by class for organized flow</div>
                        </div>
                        <div class="tip-item warning" style="margin-bottom:0;">
                            <i class="bi bi-exclamation-triangle-fill" style="color:var(--frd-warning);"></i>
                            <div><strong>Time Estimate:</strong> ~20 seconds per student</div>
                        </div>
                    </div>
                </div>

                <!-- Recent Registrations -->
                <div class="card animate-in" style="animation-delay:0.2s;">
                    <div class="card-header section-header">
                        <span style="display:flex;align-items:center;gap:8px;">
                            <i class="bi bi-clock-history"></i>
                            <span>Recent Registrations</span>
                        </span>
                    </div>
                    <div class="card-body" style="padding:0;max-height:320px;overflow-y:auto;">
                        <?php if (empty($recentRegistrations)): ?>
                            <div class="empty-state" style="padding:32px 16px;">
                                <p style="margin:0;color:var(--frd-text-muted);">No registrations yet</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($recentRegistrations as $reg): ?>
                            <div class="recent-item">
                                <div class="recent-avatar" style="background:var(--frd-success-soft);color:var(--frd-success);">
                                    <i class="bi bi-check-lg"></i>
                                </div>
                                <div class="recent-info">
                                    <div class="recent-name"><?= sanitize($reg['first_name'] . ' ' . $reg['last_name']) ?></div>
                                    <div class="recent-meta"><?= sanitize($reg['student_id']) ?> &middot; Grade <?= $reg['grade_level'] ?></div>
                                </div>
                                <div class="recent-date"><?= date('M d', strtotime($reg['updated_at'] ?? 'now')) ?></div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
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
        t.className = 'frd-toast ' + type;
        t.innerHTML = '<i class="bi ' + (icons[type] || icons.info) + '"></i><span>' + esc(message) + '</span>';
        c.appendChild(t);
        setTimeout(function() { if (t.parentNode) t.remove(); }, 4200);
    }

    // ─── Search — works for both desktop table rows and mobile cards ────
    document.getElementById('searchPending').addEventListener('input', function(e) {
        var search = e.target.value.toLowerCase();
        document.querySelectorAll('.unregistered-row').forEach(function(row) {
            var text = row.textContent.toLowerCase();
            row.style.display = text.includes(search) ? '' : 'none';
        });
    });

    // ─── Select All ─────────────────────────────────────────────────────
    window.toggleSelectAll = function() {
        var checked = document.getElementById('selectAll').checked;
        document.querySelectorAll('.student-checkbox').forEach(function(cb) {
            if (cb.closest('.unregistered-row') && cb.closest('.unregistered-row').style.display !== 'none') {
                cb.checked = checked;
            }
        });
        var m = document.getElementById('selectAllMobile');
        if (m) m.checked = checked;
    };
    window.toggleSelectAllMobile = function() {
        var checked = document.getElementById('selectAllMobile').checked;
        document.querySelectorAll('.student-checkbox').forEach(function(cb) {
            if (cb.closest('.unregistered-row') && cb.closest('.unregistered-row').style.display !== 'none') {
                cb.checked = checked;
            }
        });
        var d = document.getElementById('selectAll');
        if (d) d.checked = checked;
    };

    // ─── Export CSV ─────────────────────────────────────────────────────
    window.exportUnregistered = function() {
        var rows = document.querySelectorAll('.unregistered-row');
        var csv = 'Student ID,First Name,Last Name,Grade Level,Section\n';
        rows.forEach(function(row) {
            if (row.style.display === 'none') return;
            var cells = row.querySelectorAll('td');
            if (cells.length >= 5) {
                var sid = cells[1].textContent.trim();
                var parts = cells[2].textContent.trim().split(', ');
                var ln = parts[0] || ''; var fn = parts[1] || '';
                var gr = cells[3].textContent.replace('Grade ', '').trim();
                var sec = cells[4].textContent.trim();
                csv += sid + ',' + fn + ',' + ln + ',' + gr + ',' + sec + '\n';
            }
        });
        var blob = new Blob([csv], { type: 'text/csv' });
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url;
        a.download = 'pending_face_registration_' + new Date().toISOString().slice(0, 10) + '.csv';
        a.click();
        URL.revokeObjectURL(url);
        showToast('success', 'Exported pending students list');
    };

    // ─── View Grade ─────────────────────────────────────────────────────
    window.viewGradeStudents = function(gradeLevel) {
        var input = document.getElementById('searchPending');
        input.value = 'Grade ' + gradeLevel;
        input.dispatchEvent(new Event('input'));
        var target = document.getElementById('unregisteredTable');
        if (target) target.scrollIntoView({ behavior: 'smooth', block: 'start' });
    };

    // ─── Send Reminders ─────────────────────────────────────────────────
    window.sendReminders = function() {
        var selected = document.querySelectorAll('.student-checkbox:checked');
        if (selected.length === 0) {
            showToast('warning', 'Please select students first.');
            return;
        }
        showToast('info', 'Reminder feature coming soon! (' + selected.length + ' selected)');
    };

    // ─── Refresh Stats ──────────────────────────────────────────────────
    window.refreshStats = async function() {
        try {
            var fd = new FormData();
            fd.append('action', 'registration_stats');
            var r = await fetch(BASE_URL + '/api/gate.php', { method: 'POST', credentials: 'same-origin', body: fd });
            var d = await r.json();
            if (d.success) {
                showToast('success', 'Stats refreshed');
                location.reload();
            }
        } catch (err) {
            console.error(err);
            showToast('danger', 'Could not refresh stats.');
        }
    };

    function esc(s) { if (!s) return ''; var d = document.createElement('div'); d.appendChild(document.createTextNode(s)); return d.innerHTML; }


})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>