<?php
/**
 * LDB-FRAS - Teacher Dashboard
 */
require_once __DIR__ . '/../config.php';
requireRole(['admin', 'teacher']);

$pageTitle = 'Teacher Dashboard';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
$teacherId = getCurrentUserId();
$teacherRecord = null;
$teacherRecordId = 0;
$totalSessions = 0; $mySubjects = [];

try {
    $stmt = $db->prepare("SELECT t.* FROM teachers t JOIN users u ON t.user_id = u.id WHERE u.id = ? LIMIT 1");
    $stmt->execute([$teacherId]);
    $teacherRecord = $stmt->fetch();
    $teacherRecordId = $teacherRecord ? (int)$teacherRecord['id'] : 0;
} catch (Exception $e) { error_log('teacherRecord: ' . $e->getMessage()); }

try {
    $stmt = $db->prepare("SELECT COUNT(*) FROM attendance_sessions WHERE created_by = ?");
    $stmt->execute([$teacherRecordId]);
    $totalSessions = (int)$stmt->fetchColumn();
} catch (Exception $e) { error_log('totalSessions: ' . $e->getMessage()); }

$assignedSubjectIds = [];
if ($teacherRecord) {
    foreach (['grade_section_handled', 'core_subjects_handled', 'track_elective_handled'] as $field) {
        $raw = $teacherRecord[$field] ?? '';
        if (is_string($raw)) { $raw = json_decode($raw, true); }
        if (!is_array($raw)) { continue; }
        foreach ($raw as $item) {
            if (isset($item['subject_id']) && is_numeric($item['subject_id'])) {
                $assignedSubjectIds[] = (int)$item['subject_id'];
            }
        }
    }
}
$assignedSubjectIds = array_values(array_unique(array_filter($assignedSubjectIds, function ($id) { return $id > 0; })));

$mySubjects = [];
if (!empty($assignedSubjectIds)) {
    $ph = implode(',', array_fill(0, count($assignedSubjectIds), '?'));
    try {
        $stmt = $db->prepare("SELECT * FROM subjects WHERE id IN ($ph) ORDER BY subject_name ASC");
        $stmt->execute($assignedSubjectIds);
        $mySubjects = $stmt->fetchAll();
    } catch (Exception $e) { error_log('mySubjects: ' . $e->getMessage()); }
}

$teacherSessionIds = [];
try {
    $stmt = $db->prepare("SELECT id FROM attendance_sessions WHERE created_by = ?");
    $stmt->execute([$teacherRecordId]);
    $teacherSessionIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) { error_log('teacherSessionIds: ' . $e->getMessage()); }

$avgAttendance = 0; $perfectAttendance = 0; $lowAttendance = 0;
if (!empty($teacherSessionIds)) {
    $ph = implode(',', array_fill(0, count($teacherSessionIds), '?'));
    try {
        $stmt = $db->prepare("SELECT ROUND(AVG(CASE WHEN total > 0 THEN (present * 100.0 / total) ELSE 0 END), 1) as avg_rate FROM (SELECT session_id, SUM(CASE WHEN status='present' THEN 1 ELSE 0 END) as present, COUNT(*) as total FROM attendance_records WHERE session_id IN ($ph) GROUP BY session_id) rates");
        $stmt->execute($teacherSessionIds);
        $avgAttendance = (float)$stmt->fetchColumn();
    } catch (Exception $e) { error_log('avgAttendance: ' . $e->getMessage()); }
    try {
        $stmt = $db->prepare("SELECT COUNT(*) FROM (SELECT session_id, SUM(CASE WHEN status='present' THEN 1 ELSE 0 END) as present, COUNT(*) as total FROM attendance_records WHERE session_id IN ($ph) GROUP BY session_id HAVING total > 0 AND present = total) perfect");
        $stmt->execute($teacherSessionIds);
        $perfectAttendance = (int)$stmt->fetchColumn();
    } catch (Exception $e) { error_log('perfectAttendance: ' . $e->getMessage()); }
    try {
        $stmt = $db->prepare("SELECT COUNT(*) FROM (SELECT session_id, SUM(CASE WHEN status='present' THEN 1 ELSE 0 END) as present, COUNT(*) as total FROM attendance_records WHERE session_id IN ($ph) GROUP BY session_id HAVING total > 0 AND (present * 100.0 / total) < 75) low");
        $stmt->execute($teacherSessionIds);
        $lowAttendance = (int)$stmt->fetchColumn();
    } catch (Exception $e) { error_log('lowAttendance: ' . $e->getMessage()); }
}

// ── Dashboard report charts (same sources as teacher/reports.php) ──
$dashBreakdown = [];
$dashSubjectStats = [];
try {
    $chartStmt = $db->prepare("SELECT a.status, a.subject_id, sub.subject_name
                               FROM attendance a
                               LEFT JOIN subjects sub ON a.subject_id = sub.id
                               WHERE a.recorded_by = ?");
    $chartStmt->execute([$teacherId]);
    $chartRows = $chartStmt->fetchAll();
} catch (Exception $e) {
    error_log('dashCharts: ' . $e->getMessage());
    $chartRows = [];
}

$dashTotals = ['present' => 0, 'late' => 0, 'absent' => 0, 'pending' => 0, 'excused' => 0];
foreach ($chartRows as $r) {
    $st = $r['status'];
    if (isset($dashTotals[$st])) {
        $dashTotals[$st]++;
    }
    $sid = $r['subject_id'] ? (int)$r['subject_id'] : 0;
    if (!isset($dashSubjectStats[$sid])) {
        $dashSubjectStats[$sid] = ['name' => $r['subject_name'] ?: 'Unknown', 'present' => 0, 'late' => 0, 'absent' => 0, 'pending' => 0, 'excused' => 0];
    }
    if (isset($dashSubjectStats[$sid][$st])) {
        $dashSubjectStats[$sid][$st]++;
    }
}
foreach (['present' => '#10b981', 'late' => '#f59e0b', 'absent' => '#ef4444'] as $st => $color) {
    if ($dashTotals[$st] > 0) {
        $dashBreakdown[] = ['label' => ucfirst($st), 'value' => $dashTotals[$st], 'color' => $color];
    }
}
if ($dashTotals['pending'] > 0) {
    $dashBreakdown[] = ['label' => 'Pending', 'value' => $dashTotals['pending'], 'color' => '#94a3b8'];
}
if ($dashTotals['excused'] > 0) {
    $dashBreakdown[] = ['label' => 'Excused', 'value' => $dashTotals['excused'], 'color' => '#06b6d4'];
}
usort($dashSubjectStats, function ($a, $b) { return strcasecmp($a['name'], $b['name']); });
$dashSubjectStats = array_values($dashSubjectStats);

// ── Gender distribution (from the teacher's attendance roster) ──
$dashGender = ['Male' => 0, 'Female' => 0];
try {
    $stmt = $db->prepare("SELECT s.gender, COUNT(DISTINCT s.id) AS cnt
                          FROM attendance a
                          JOIN students s ON a.student_id = s.id
                          WHERE a.recorded_by = ?
                          GROUP BY s.gender");
    $stmt->execute([$teacherId]);
    foreach ($stmt->fetchAll() as $g) {
        if (isset($dashGender[$g['gender']])) {
            $dashGender[$g['gender']] = (int)$g['cnt'];
        }
    }
} catch (Exception $e) { error_log('dashGender: ' . $e->getMessage()); }

// ── Student attendance leaders (top 5 by rate) ──
$dashLeaders = [];
try {
    $stmt = $db->prepare("SELECT s.first_name, s.last_name,
                          SUM(CASE WHEN a.status IN ('present','late') THEN 1 ELSE 0 END) AS ok,
                          COUNT(*) AS total
                          FROM attendance a
                          JOIN students s ON a.student_id = s.id
                          WHERE a.recorded_by = ?
                          GROUP BY s.id
                          HAVING total > 0
                          ORDER BY (SUM(CASE WHEN a.status IN ('present','late') THEN 1 ELSE 0 END) * 100.0 / COUNT(*)) DESC, s.last_name ASC
                          LIMIT 5");
    $stmt->execute([$teacherId]);
    foreach ($stmt->fetchAll() as $ld) {
        $dashLeaders[] = [
            'name' => ($ld['last_name'] ?? '') . ', ' . ($ld['first_name'] ?? ''),
            'rate' => (float)$ld['total'] > 0 ? round(((float)$ld['ok'] / (float)$ld['total']) * 100, 1) : 0
        ];
    }
} catch (Exception $e) { error_log('dashLeaders: ' . $e->getMessage()); }

// ── Daily status breakdown (last 7 days) ──
$dashDaily = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-{$i} days"));
    $dashDaily[$d] = ['label' => date('D M j', strtotime($d)), 'present' => 0, 'late' => 0, 'absent' => 0, 'pending' => 0];
}
try {
    $stmt = $db->prepare("SELECT date, status, COUNT(*) AS cnt
                          FROM attendance
                          WHERE recorded_by = ? AND date BETWEEN ? AND ?
                          GROUP BY date, status");
    $stmt->execute([$teacherId, date('Y-m-d', strtotime('-6 days')), date('Y-m-d')]);
    foreach ($stmt->fetchAll() as $dd) {
        if (isset($dashDaily[$dd['date']]) && isset($dashDaily[$dd['date']][$dd['status']])) {
            $dashDaily[$dd['date']][$dd['status']] = (int)$dd['cnt'];
        }
    }
} catch (Exception $e) { error_log('dashDaily: ' . $e->getMessage()); }
$dashDaily = array_values($dashDaily);
?>

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
        --td-shadow-lg: 0 12px 40px rgba(0,0,0,0.3); --td-shadow-xl: 0 24px 64px rgba(0,0,0,0.35);
        --td-transition: 0.2s cubic-bezier(0.4,0,0.2,1);
        --td-transition-spring: 0.35s cubic-bezier(0.34,1.56,0.64,1);
    }

    /* TOP NAVBAR */
    .navbar-left { display: flex; align-items: center; gap: 16px; }
    .page-title h5 { font-size: 20px; font-weight: 800; letter-spacing: -0.03em; margin: 0; }
    .page-title small { font-size: 13px; font-weight: 500; }
    .page-title small strong { font-weight: 600; }
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
    .mobile-title-left small strong { font-weight: 600; }
    .mobile-date { font-size: 11px; font-weight: 600; padding: 6px 10px; border-radius: var(--td-radius-xs); display: flex; align-items: center; gap: 6px; white-space: nowrap; flex-shrink: 0; margin-top: 2px; }
    .mobile-date i { font-size: 12px; }

    /* CONTENT & STATS */
    .content-area { padding: 28px; }
    .stat-card { padding: 22px 24px; transition: all var(--td-transition); position: relative; overflow: hidden; }
    .stat-card::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px; border-radius: var(--td-radius) var(--td-radius) 0 0; opacity: 0; transition: opacity var(--td-transition); }
    .stat-card:hover { transform: translateY(-3px); }
    .stat-card:hover::before { opacity: 1; }
    .stat-card:nth-child(1)::before { background: var(--td-primary); }
    .stat-card:nth-child(2)::before { background: var(--td-success); }
    .stat-card:nth-child(3)::before { background: var(--td-warning); }
    .stat-card:nth-child(4)::before { background: var(--td-danger); }
    .stat-value { font-size: 32px; font-weight: 800; letter-spacing: -0.04em; line-height: 1.1; }
    .stat-label { font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.06em; margin-top: 6px; }
    .stat-icon { width: 46px; height: 46px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 18px; flex-shrink: 0; }

    /* CARDS & BUTTONS */
    .card { overflow: hidden; }
    .card-header { font-size: 14px; font-weight: 700; letter-spacing: -0.01em; }
    .btn-primary { background: var(--td-primary); border-color: var(--td-primary); border-radius: var(--td-radius-xs); font-weight: 600; font-size: 13px; padding: 10px 18px; transition: all var(--td-transition); }
    .btn-primary:hover { background: var(--td-primary-dark); border-color: var(--td-primary-dark); transform: translateY(-1px); box-shadow: 0 4px 12px var(--td-primary-glow); }
    .btn-outline-secondary { border: 1px solid rgba(255,255,255,0.15); color: rgba(255,255,255,0.7); border-radius: var(--td-radius-xs); font-weight: 600; font-size: 13px; padding: 10px 18px; background: transparent; transition: all var(--td-transition); }
    .btn-outline-secondary:hover { background: rgba(255,255,255,0.08); border-color: rgba(255,255,255,0.25); color: #fff; transform: translateY(-1px); }
    .badge { background: var(--td-primary); color: #fff; padding: 8px 16px; font-size: 13px; font-weight: 600; border-radius: var(--td-radius-xs); border: 1px solid transparent; transition: all var(--td-transition); }
    .badge:hover { border-color: var(--td-primary); transform: translateY(-1px); }

    /* CALENDAR */
    .calendar-card { overflow: hidden; }
    .calendar-card-header { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; }
    .cal-header-left { display: flex; align-items: center; }
    .cal-header-left i { font-size: 18px; }
    .cal-title { font-weight: 800; font-size: 15px; letter-spacing: -0.02em; }
    .cal-header-right { display: flex; align-items: center; gap: 8px; }
    .cal-month-label { font-weight: 700; font-size: 15px; min-width: 140px; text-align: center; letter-spacing: -0.01em; }
    .cal-nav-btn { width: 34px; height: 34px; border-radius: var(--td-radius-xs); display: inline-flex; align-items: center; justify-content: center; cursor: pointer; transition: all var(--td-transition); font-size: 13px; }
    .cal-nav-btn:hover { transform: scale(1.08); box-shadow: 0 4px 12px var(--td-primary-glow); }
    .cal-today-btn { padding: 6px 16px; border-radius: var(--td-radius-xs); font-size: 12px; font-weight: 700; cursor: pointer; transition: all var(--td-transition); letter-spacing: 0.02em; }
    .cal-today-btn:hover { transform: translateY(-1px); box-shadow: 0 4px 12px var(--td-primary-glow); }
    .cal-weekdays { display: grid; grid-template-columns: repeat(7, 1fr); gap: 4px; margin-bottom: 6px; }
    .cal-weekday { text-align: center; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; padding: 10px 0; }
    .cal-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 4px; }
    .cal-day { position: relative; aspect-ratio: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; border-radius: var(--td-radius-sm); cursor: pointer; transition: all var(--td-transition); border: 2px solid transparent; background: transparent; user-select: none; animation: dayFadeIn 0.3s ease forwards; }
    .cal-day:hover { transform: translateY(-1px); }
    .cal-day-num { font-size: 14px; line-height: 1; font-family: var(--td-mono); font-weight: 500; }
    .cal-day.today::after { content: ''; position: absolute; bottom: 6px; width: 5px; height: 5px; border-radius: 50%; }
    .cal-day.today.has-data::after, .cal-day.today.has-event::after { display: none; }
    .event-dots { display: flex; gap: 3px; margin-top: 4px; height: 6px; align-items: center; }
    .event-dot { width: 6px; height: 6px; border-radius: 50%; border: 1.5px solid transparent; }
    .cal-event-indicator { position: absolute; top: 4px; right: 4px; display: flex; gap: 2px; }
    .cal-event-indicator .evt-pip { width: 6px; height: 6px; border-radius: 50%; }
    .cal-legend { display: flex; gap: 18px; padding: 14px 4px 4px; margin-top: 14px; flex-wrap: wrap; }
    .cal-legend-item { display: flex; align-items: center; gap: 7px; font-size: 11px; font-weight: 600; }
    .cal-legend-dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
    .cal-legend-dot-today { background: transparent; }
    .cal-legend-dot-event { background: linear-gradient(135deg, var(--td-primary), var(--td-success)); }
    @keyframes todayPulse { 0% { box-shadow: 0 0 0 0 var(--td-primary-glow); } 70% { box-shadow: 0 0 0 8px rgba(79,70,229,0); } 100% { box-shadow: 0 0 0 0 rgba(79,70,229,0); } }
    .cal-day.today:not(.selected) { animation: todayPulse 2.5s ease-in-out infinite; }
    @keyframes dayFadeIn { from { opacity: 0; transform: scale(0.88); } to { opacity: 1; transform: scale(1); } }
    .cal-grid .cal-day:nth-child(-n+7) { animation-delay: 0.02s; }
    .cal-grid .cal-day:nth-child(n+8):nth-child(-n+14) { animation-delay: 0.05s; }
    .cal-grid .cal-day:nth-child(n+15):nth-child(-n+21) { animation-delay: 0.08s; }
    .cal-grid .cal-day:nth-child(n+22):nth-child(-n+28) { animation-delay: 0.11s; }
    .cal-grid .cal-day:nth-child(n+29):nth-child(-n+35) { animation-delay: 0.14s; }
    .cal-grid .cal-day:nth-child(n+36) { animation-delay: 0.17s; }

    /* NOTIFICATIONS */
    .notification-wrapper { position: relative; }
    .notification-bell { position: relative; }
    .notification-badge { position: absolute; top: -5px; right: -5px; min-width: 18px; height: 18px; border-radius: 10px; font-size: 10px; font-weight: 700; display: flex; align-items: center; justify-content: center; padding: 0 5px; font-family: var(--td-mono); animation: badgePop 0.3s cubic-bezier(0.34,1.56,0.64,1); box-shadow: 0 2px 6px rgba(239,68,68,0.3); }
    @keyframes badgePop { from { transform: scale(0); } to { transform: scale(1); } }
    .notification-dropdown { position: absolute; top: calc(100% + 12px); right: 0; width: 380px; max-height: 460px; border-radius: var(--td-radius); z-index: 1000; display: none; overflow: hidden; animation: ddSlide 0.25s cubic-bezier(0.34,1.56,0.64,1); }
    .notification-dropdown.show { display: block; }
    @keyframes ddSlide { from { opacity: 0; transform: translateY(-6px) scale(0.98); } to { opacity: 1; transform: translateY(0) scale(1); } }
    .notif-header { display: flex; justify-content: space-between; align-items: center; padding: 16px 20px; }
    .notif-header-title { font-size: 14px; font-weight: 700; letter-spacing: -0.01em; }
    .notif-count { font-size: 11px; font-weight: 700; padding: 3px 10px; border-radius: 20px; font-family: var(--td-mono); }
    .notif-list { max-height: 380px; overflow-y: auto; padding: 8px; }
    .notif-list::-webkit-scrollbar { width: 4px; }
    .notif-item { display: flex; align-items: center; gap: 12px; padding: 12px 14px; border-radius: var(--td-radius-sm); margin-bottom: 4px; transition: all var(--td-transition); }
    .notif-item:last-child { margin-bottom: 0; }
    .notif-item-bar { width: 3px; height: 38px; border-radius: 3px; flex-shrink: 0; }
    .notif-item-body { flex: 1; min-width: 0; }
    .notif-item-title { font-size: 13px; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .notif-item-meta { font-size: 11px; margin-top: 4px; display: flex; gap: 12px; }
    .notif-type-tag { font-size: 9px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; padding: 4px 10px; border-radius: 6px; flex-shrink: 0; }
    .notif-empty { text-align: center; padding: 36px 20px; font-size: 13px; display: flex; flex-direction: column; align-items: center; gap: 10px; }
    .notif-empty i { font-size: 32px; opacity: 0.4; }

    /* DAY DETAIL */
    .detail-card { min-height: 440px; display: flex; flex-direction: column; }
    .detail-placeholder { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 48px 32px; text-align: center; }
    .detail-placeholder-icon { width: 80px; height: 80px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 30px; margin-bottom: 20px; }
    .detail-placeholder h6 { font-weight: 700; margin-bottom: 8px; font-size: 16px; letter-spacing: -0.02em; }
    .detail-placeholder p { font-size: 13px; max-width: 260px; line-height: 1.6; }
    .detail-content { flex: 1; }
    .detail-header { display: flex; justify-content: space-between; align-items: center; padding: 22px 22px 18px; }
    .detail-date-wrapper { display: flex; align-items: center; gap: 16px; }
    .detail-day-num { width: 56px; height: 56px; border-radius: 16px; font-size: 24px; font-weight: 800; display: flex; align-items: center; justify-content: center; font-family: var(--td-mono); letter-spacing: -0.02em; }
    .detail-day-name { font-weight: 700; font-size: 16px; letter-spacing: -0.02em; }
    .detail-month-name { font-size: 12px; margin-top: 3px; font-weight: 500; }
    .detail-close-btn { flex-shrink: 0; }
    .detail-section { padding: 18px 22px; }
    .detail-section-title { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; margin-bottom: 14px; display: flex; align-items: center; gap: 7px; }
    .detail-stats-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin-bottom: 18px; }
    .detail-stat-box { text-align: center; padding: 14px 6px; border-radius: var(--td-radius-sm); transition: all var(--td-transition); border: 1px solid transparent; }
    .detail-stat-box:hover { transform: translateY(-2px); }
    .detail-stat-num { font-size: 22px; font-weight: 800; line-height: 1.2; font-family: var(--td-mono); letter-spacing: -0.03em; }
    .detail-stat-lbl { font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.06em; margin-top: 5px; }
    .detail-bar-container { margin-bottom: 16px; }
    .detail-bar { display: flex; height: 10px; border-radius: 10px; overflow: hidden; }
    .detail-bar-segment { height: 100%; transition: width 0.6s cubic-bezier(0.4,0,0.2,1); }
    .detail-bar-labels { display: flex; justify-content: space-between; margin-top: 8px; font-size: 10px; font-weight: 600; font-family: var(--td-mono); }
    .detail-rate-wrapper { text-align: center; }
    .detail-rate-badge { display: inline-flex; align-items: center; gap: 7px; padding: 7px 18px; border-radius: 20px; font-size: 13px; font-weight: 700; transition: all 0.3s ease; }
    .detail-sessions { max-height: 180px; overflow-y: auto; }
    .detail-sessions::-webkit-scrollbar { width: 4px; }
    .session-item { display: flex; align-items: center; gap: 14px; padding: 12px 14px; border-radius: var(--td-radius-sm); margin-bottom: 6px; transition: all var(--td-transition); animation: sessionSlideIn 0.3s ease forwards; opacity: 0; border: 1px solid transparent; }
    @keyframes sessionSlideIn { from { opacity: 0; transform: translateX(-12px); } to { opacity: 1; transform: translateX(0); } }
    .session-item:last-child { margin-bottom: 0; }
    .session-icon { width: 38px; height: 38px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 15px; flex-shrink: 0; }
    .session-info { flex: 1; min-width: 0; }
    .session-name { font-size: 13px; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .session-meta { font-size: 11px; margin-top: 3px; }
    .session-status { font-size: 10px; font-weight: 700; padding: 3px 10px; border-radius: 20px; text-transform: capitalize; letter-spacing: 0.03em; }
    .detail-no-sessions, .detail-no-events { text-align: center; padding: 28px; font-size: 13px; display: flex; flex-direction: column; align-items: center; gap: 10px; }
    .detail-no-sessions i, .detail-no-events i { font-size: 28px; opacity: 0.4; }
    .detail-events-section { border-bottom: none; }
    .detail-events-list { max-height: 200px; overflow-y: auto; margin-bottom: 14px; }
    .detail-events-list::-webkit-scrollbar { width: 4px; }
    .detail-event-item { display: flex; align-items: flex-start; gap: 12px; padding: 12px 14px; border-radius: var(--td-radius-sm); margin-bottom: 6px; transition: all var(--td-transition); animation: sessionSlideIn 0.3s ease forwards; opacity: 0; border: 1px solid transparent; }
    .detail-event-item:last-child { margin-bottom: 0; }
    .detail-event-bar { width: 4px; min-height: 38px; border-radius: 4px; flex-shrink: 0; align-self: stretch; }
    .detail-event-body { flex: 1; min-width: 0; }
    .detail-event-title { font-size: 13px; font-weight: 600; }
    .detail-event-item.completed .detail-event-title { text-decoration: line-through; }
    .detail-event-desc { font-size: 11px; margin-top: 3px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .detail-event-meta { font-size: 11px; margin-top: 5px; display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
    .detail-event-tag { font-size: 9px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; padding: 3px 8px; border-radius: 6px; }
    .detail-event-actions { display: flex; gap: 4px; flex-shrink: 0; align-self: flex-start; }
    .evt-action-btn { width: 30px; height: 30px; border: none; border-radius: var(--td-radius-xs); display: flex; align-items: center; justify-content: center; cursor: pointer; font-size: 13px; transition: all var(--td-transition); background: transparent; }
    .btn-add-event { width: 100%; padding: 12px; border: 2px dashed rgba(255,255,255,0.15); border-radius: var(--td-radius-sm); background: transparent; font-size: 13px; font-weight: 700; cursor: pointer; transition: all var(--td-transition); display: flex; align-items: center; justify-content: center; gap: 6px; }
    .btn-add-event:hover { transform: translateY(-1px); }

    /* EVENT MODAL — DESKTOP */
    .event-modal-overlay {
        position: fixed;
        inset: 0;
        background: rgba(26, 29, 46, 0.5);
        backdrop-filter: blur(8px);
        -webkit-backdrop-filter: blur(8px);
        z-index: 9998;
        display: none;
        align-items: center;
        justify-content: center;
        padding: 20px;
    }
    .event-modal-overlay.show { display: flex; }
    .event-modal {
        border-radius: 20px;
        width: 500px;
        max-width: 100%;
        max-height: 90vh;
        overflow: hidden;
        display: flex;
        flex-direction: column;
        animation: modalSlideIn 0.35s cubic-bezier(0.34, 1.56, 0.64, 1);
    }
    @keyframes modalSlideIn {
        from { opacity: 0; transform: translateY(24px) scale(0.96); }
        to   { opacity: 1; transform: translateY(0) scale(1); }
    }
    .event-modal-header { display: flex; justify-content: space-between; align-items: center; padding: 22px 26px; flex-shrink: 0; }
    .event-modal-title { display: flex; align-items: center; gap: 12px; font-size: 17px; font-weight: 800; letter-spacing: -0.02em; }
    .event-modal-title i { font-size: 22px; }
    .event-modal-close { width: 34px; height: 34px; border: none; border-radius: var(--td-radius-xs); display: flex; align-items: center; justify-content: center; cursor: pointer; transition: all var(--td-transition); font-size: 14px; }
    .event-modal-body { padding: 26px; overflow-y: auto; flex: 1; }
    .evt-field { margin-bottom: 20px; }
    .evt-field:last-child { margin-bottom: 0; }
    .evt-field label { display: block; font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; margin-bottom: 8px; }
    .evt-field label .required { color: var(--td-danger); }
    .evt-field input[type="text"], .evt-field input[type="date"], .evt-field input[type="time"], .evt-field textarea { width: 100%; padding: 11px 16px; border-width: 1.5px; border-style: solid; border-radius: var(--td-radius-sm); font-size: 14px; transition: all var(--td-transition); font-family: var(--td-font); }
    .evt-field textarea { resize: vertical; min-height: 76px; }
    .evt-field input:focus, .evt-field textarea:focus { outline: none; }
    .evt-row { display: flex; gap: 14px; }
    .evt-flex-1 { flex: 1; }
    .evt-type-selector { display: flex; gap: 8px; flex-wrap: wrap; }
    .evt-type-btn { padding: 9px 16px; border: 2px solid; border-radius: var(--td-radius-xs); font-size: 12px; font-weight: 600; cursor: pointer; transition: all var(--td-transition); display: flex; align-items: center; gap: 7px; }
    .evt-type-dot { font-size: 8px; }
    .event-modal-footer { display: flex; justify-content: flex-end; gap: 10px; padding: 18px 26px; flex-shrink: 0; }
    .evt-btn { padding: 11px 22px; border: none; border-radius: var(--td-radius-sm); font-size: 13px; font-weight: 700; cursor: pointer; transition: all var(--td-transition); display: flex; align-items: center; gap: 6px; }
    .evt-btn-save.loading { opacity: 0.7; pointer-events: none; }

    /* TOAST */
    .toast-container { position: fixed; bottom: 28px; right: 28px; z-index: 9999; display: flex; flex-direction: column; gap: 10px; pointer-events: none; }
    .toast-notification { padding: 14px 22px; border-radius: var(--td-radius-sm); color: #fff; font-size: 13px; font-weight: 600; display: flex; align-items: center; gap: 12px; max-width: 400px; pointer-events: auto; animation: toastIn 0.4s cubic-bezier(0.34,1.56,0.64,1), toastOut 0.4s ease 3.6s forwards; font-family: var(--td-font); }
    @keyframes toastIn { from { opacity: 0; transform: translateX(40px) scale(0.95); } to { opacity: 1; transform: translateX(0) scale(1); } }
    @keyframes toastOut { from { opacity: 1; transform: translateX(0); } to { opacity: 0; transform: translateX(40px); } }
    .toast-notification i { font-size: 18px; flex-shrink: 0; opacity: 0.9; }

    /* CHART */
    .chart-section { overflow: hidden; }
    .chart-section .card-header { padding: 18px 24px; font-weight: 700; font-size: 15px; letter-spacing: -0.02em; }
    .chart-section > .card-header { justify-content: flex-start; gap: 6px; }
    .chart-header-icon { width: 36px; height: 36px; border-radius: 10px; background: rgba(96,165,250,0.12); color: #60A5FA; display: flex; align-items: center; justify-content: center; font-size: 16px; flex-shrink: 0; }
    .chart-header-range { margin-left: auto; font-size: 11px; font-weight: 600; color: rgba(255,255,255,0.35); font-family: var(--td-mono); background: rgba(255,255,255,0.05); padding: 4px 12px; border-radius: 6px; }
    .chart-wrapper { position: relative; height: 240px; padding: 8px 0; }
    .chart-wrapper canvas { width: 100% !important; height: 100% !important; }
    .chart-empty { height: 100%; display: flex; align-items: center; justify-content: center; font-size: 13px; font-weight: 600; color: rgba(255,255,255,0.35); letter-spacing: 0.02em; }

    @keyframes fadeUp { from { opacity: 0; transform: translateY(16px); } to { opacity: 1; transform: translateY(0); } }
    .stat-card { animation: fadeUp 0.5s ease forwards; opacity: 0; }
    .stat-card:nth-child(1) { animation-delay: 0.05s; }
    .stat-card:nth-child(2) { animation-delay: 0.1s; }
    .stat-card:nth-child(3) { animation-delay: 0.15s; }
    .stat-card:nth-child(4) { animation-delay: 0.2s; }

    /* =====================================================
       TABLET
       ===================================================== */
    @media (max-width: 991px) {
        .detail-card { min-height: auto; }
        .notification-dropdown { width: 340px; right: -20px; }
        .content-area { padding: 20px; }
    }

    /* =====================================================
       MOBILE
       ===================================================== */
    @media (max-width: 767px) {
        .top-navbar { padding: 8px 12px; flex-wrap: wrap; gap: 8px; height: auto; }
        .navbar-left { flex-shrink: 0; gap: 10px; }
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
        .card-header { font-size: 13px; }
        .btn-primary, .btn-outline-secondary { width: 100%; text-align: center; display: block; }

        .cal-day-num { font-size: 11px; }
        .cal-weekday { font-size: 9px; padding: 6px 0; }
        .cal-weekdays { gap: 2px; margin-bottom: 4px; }
        .cal-grid { gap: 2px; }
        .cal-month-label { font-size: 13px; min-width: 110px; }
        .cal-today-btn { padding: 4px 10px; font-size: 10px; }
        .cal-nav-btn { width: 28px; height: 28px; font-size: 11px; }
        .event-dots { gap: 2px; margin-top: 3px; }
        .event-dot { width: 4px; height: 4px; }
        .cal-event-indicator .evt-pip { width: 4px; height: 4px; }
        .cal-legend { gap: 10px; padding: 10px 4px 0; margin-top: 10px; }
        .cal-legend-item { font-size: 9px; gap: 5px; }
        .cal-legend-dot { width: 6px; height: 6px; }

        .detail-stats-row { grid-template-columns: repeat(2, 1fr); gap: 8px; }
        .detail-stat-box { padding: 10px 4px; }
        .detail-stat-num { font-size: 18px; }
        .detail-stat-lbl { font-size: 9px; }
        .detail-section { padding: 14px 16px; }
        .detail-header { padding: 16px 16px 14px; }
        .detail-day-num { width: 44px; height: 44px; font-size: 18px; border-radius: 12px; }
        .detail-day-name { font-size: 14px; }
        .detail-month-name { font-size: 11px; }
        .detail-placeholder { padding: 32px 20px; }
        .detail-placeholder-icon { width: 60px; height: 60px; font-size: 24px; margin-bottom: 14px; }
        .detail-placeholder h6 { font-size: 14px; }
        .detail-placeholder p { font-size: 12px; max-width: 220px; }
        .session-item { padding: 10px 12px; gap: 10px; }
        .session-icon { width: 32px; height: 32px; font-size: 13px; border-radius: 8px; }
        .session-name { font-size: 12px; }
        .session-meta { font-size: 10px; }
        .session-status { font-size: 9px; padding: 2px 8px; }
        .detail-event-item { padding: 10px 12px; gap: 10px; }
        .detail-event-title { font-size: 12px; }
        .detail-event-tag { font-size: 8px; padding: 2px 6px; }
        .btn-add-event { padding: 10px; font-size: 12px; }

        .chart-wrapper { height: 180px; }
        .chart-section .card-header { padding: 12px 16px; font-size: 13px; }
        .chart-section .card-body { padding: 8px 16px 16px; }
        .chart-header-icon { width: 30px; height: 30px; font-size: 13px; border-radius: 8px; }
        .chart-header-range { font-size: 9px; padding: 3px 8px; }

        .notification-dropdown { width: calc(100vw - 24px); right: -50px; }
        .notif-header { padding: 12px 16px; }
        .notif-header-title { font-size: 13px; }
        .notif-item { padding: 10px 12px; }
        .notif-item-title { font-size: 12px; }

        .toast-container { bottom: 24px; right: 12px; left: 12px; }
        .toast-notification { max-width: 100%; font-size: 12px; padding: 12px 16px; }

        /* ===== MODAL — MOBILE BOTTOM SHEET (FIXED) ===== */
        .event-modal-overlay {
            align-items: flex-end;
            justify-content: center;
            padding: 0;
        }
        .event-modal {
            width: 100%;
            max-width: 100vw;
            max-height: 92vh;
            border-radius: 16px 16px 0 0;
            animation: modalSheetUp 0.3s ease-out;
        }
        @keyframes modalSheetUp {
            from { transform: translateY(100%); }
            to   { transform: translateY(0); }
        }
        .event-modal-header { padding: 18px 20px; }
        .event-modal-title { font-size: 15px; gap: 10px; }
        .event-modal-title i { font-size: 18px; }
        .event-modal-body { padding: 4px 20px 20px; }
        .evt-field { margin-bottom: 16px; }
        .evt-field input[type="text"], .evt-field input[type="date"], .evt-field input[type="time"], .evt-field textarea { padding: 10px 14px; font-size: 13px; }
        .evt-row { flex-direction: column; gap: 0; }
        .evt-type-btn { padding: 7px 12px; font-size: 11px; }
        .event-modal-footer { padding: 14px 20px; padding-bottom: calc(14px + env(safe-area-inset-bottom, 0px)); }
        .evt-btn { padding: 10px 18px; font-size: 12px; }

        /* DRAG HANDLE */
        .event-modal::before {
            content: '';
            display: block;
            width: 36px;
            height: 4px;
            border-radius: 4px;
            background: rgba(255,255,255,0.2);
            margin: 10px auto 0;
            flex-shrink: 0;
        }

        .stat-card { animation: none; opacity: 1; }
    }

    /* =====================================================
       SMALL PHONE
       ===================================================== */
    @media (max-width: 576px) {
        .top-navbar { padding: 6px 10px; }
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
        .detail-day-num { width: 40px; height: 40px; font-size: 16px; }
        .detail-day-name { font-size: 13px; }
        .detail-stat-num { font-size: 16px; }
        .detail-stats-row { gap: 6px; }
        .cal-day-num { font-size: 10px; }
        .cal-month-label { font-size: 12px; min-width: 100px; }
        .session-item { padding: 8px 10px; }
        .detail-event-item { padding: 8px 10px; }
        .notification-dropdown { right: -40px; max-height: 380px; }
        .notif-item { gap: 8px; }
        .sidebar { width: 260px; }

        /* Modal — even more compact on small phones */
        .event-modal { max-height: 95vh; }
        .event-modal-body { padding: 4px 16px 16px; }
        .event-modal-header { padding: 16px; }
        .event-modal-footer { padding: 12px 16px; padding-bottom: calc(12px + env(safe-area-inset-bottom, 0px)); }
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

<?php
    $teacherFullName = $teacherRecord['first_name'] ?? '';
    if (!empty($teacherRecord['middle_name'])) {
        $teacherFullName .= ' ' . strtoupper($teacherRecord['middle_name'][0]) . '.';
    }
    $teacherFullName .= ' ' . ($teacherRecord['last_name'] ?? '');
?>
        <div class="d-none d-md-flex justify-content-between align-items-center gap-3 mb-3">
            <div class="page-title mb-0">
                <h5 class="mb-0">Teacher Dashboard</h5>
                <small>Welcome back, <strong><?= sanitize($teacherFullName) ?></strong></small>
            </div>
        </div>

        <div class="page-title mobile-title">
            <div class="mobile-title-inner">
                <div class="mobile-title-left"><h5>Teacher Dashboard</h5><small>Welcome back, <strong><?= sanitize($teacherFullName) ?></strong></small></div>
                <div class="mobile-date"><i class="bi bi-calendar3"></i> <?= date('D, M j, Y') ?></div>
            </div>
        </div>

        <div class="row g-4 mb-4">
            <div class="col-6 col-md-3"><div class="stat-card"><div class="d-flex justify-content-between align-items-start"><div><div class="stat-value"><?= $totalSessions ?></div><div class="stat-label">Total Sessions</div></div><div class="stat-icon" style="background:var(--td-primary-light);color:var(--td-primary);"><i class="bi bi-camera-video-fill"></i></div></div></div></div>
            <div class="col-6 col-md-3"><div class="stat-card"><div class="d-flex justify-content-between align-items-start"><div><div class="stat-value"><?= $avgAttendance ? $avgAttendance . '%' : '0%' ?></div><div class="stat-label">Avg. Attendance</div></div><div class="stat-icon" style="background:var(--td-success-light);color:var(--td-success);"><i class="bi bi-graph-up"></i></div></div></div></div>
            <div class="col-6 col-md-3"><div class="stat-card"><div class="d-flex justify-content-between align-items-start"><div><div class="stat-value"><?= $perfectAttendance ?></div><div class="stat-label">Perfect Attendance</div></div><div class="stat-icon" style="background:var(--td-warning-light);color:var(--td-warning);"><i class="bi bi-award-fill"></i></div></div></div></div>
            <div class="col-6 col-md-3"><div class="stat-card"><div class="d-flex justify-content-between align-items-start"><div><div class="stat-value"><?= $lowAttendance ?></div><div class="stat-label">Low Attendance</div></div><div class="stat-icon" style="background:var(--td-danger-light);color:var(--td-danger);"><i class="bi bi-exclamation-triangle-fill"></i></div></div></div></div>
        </div>
        <div class="row g-4 mb-4">
            <div class="col-md-6"><div class="card"><div class="card-header p-3">Quick Actions</div><div class="card-body p-3"><a href="<?= BASE_URL ?>/teacher/attendance.php" class="btn btn-primary me-2 mb-2"><i class="bi bi-camera-video"></i> Start Attendance Session</a><a href="<?= BASE_URL ?>/teacher/records.php" class="btn btn-outline-secondary mb-2"><i class="bi bi-list-check"></i> View Records</a><a href="<?= BASE_URL ?>/teacher/reports.php" class="btn btn-outline-secondary mb-2"><i class="bi bi-file-earmark-bar-graph"></i> Generate Report</a></div></div></div>
            <div class="col-md-6"><div class="card"><div class="card-header p-3">My Subjects</div><div class="card-body p-3"><?php if (empty($mySubjects)): ?><p class="text-muted text-center mb-0">No subjects assigned yet.</p><?php else: ?><div class="d-flex flex-wrap gap-2"><?php foreach ($mySubjects as $subj): ?><span class="badge"><i class="bi bi-book me-1"></i> <?= sanitize($subj['subject_name']) ?></span><?php endforeach; ?></div><?php endif; ?></div></div></div>
        </div>

        <div class="row g-4 mb-4">
            <div class="col-md-5"><div class="card h-100 chart-section"><div class="card-header p-3 d-flex align-items-center gap-2"><div class="chart-header-icon"><i class="bi bi-pie-chart"></i></div><span>Overall Breakdown</span></div><div class="card-body"><div class="chart-wrapper"><canvas id="dashPieChart"></canvas></div></div></div></div>
            <div class="col-md-7"><div class="card h-100 chart-section"><div class="card-header p-3 d-flex align-items-center gap-2"><div class="chart-header-icon"><i class="bi bi-bar-chart"></i></div><span>Subject Comparison</span></div><div class="card-body"><div class="chart-wrapper"><canvas id="dashBarChart"></canvas></div></div></div></div>
        </div>

        <div class="row g-4 mb-4">
            <div class="col-md-4"><div class="card h-100 chart-section"><div class="card-header p-3 d-flex align-items-center gap-2"><div class="chart-header-icon"><i class="bi bi-people"></i></div><span>Gender Distribution</span></div><div class="card-body"><div class="chart-wrapper"><canvas id="dashGenderChart"></canvas></div></div></div></div>
            <div class="col-md-8"><div class="card h-100 chart-section"><div class="card-header p-3 d-flex align-items-center gap-2"><div class="chart-header-icon"><i class="bi bi-calendar-week"></i></div><span>Daily Status Breakdown</span></div><div class="card-body"><div class="chart-wrapper"><canvas id="dashDailyChart"></canvas></div></div></div></div>
        </div>

        <div class="card chart-section mb-4"><div class="card-header p-3 d-flex align-items-center gap-2"><div class="chart-header-icon"><i class="bi bi-trophy"></i></div><span>Student Attendance Leaders</span></div><div class="card-body"><div class="chart-wrapper" style="height:280px;"><canvas id="dashLeadersChart"></canvas></div></div></div>

        <div class="card chart-section mb-4"><div class="card-header p-3 d-flex align-items-center gap-2"><div class="chart-header-icon"><i class="bi bi-graph-up"></i></div><span>Attendance Trends</span><select class="chart-header-range" id="chartPeriod" style="width:auto;padding:0.2rem 0.5rem;font-size:0.75rem;cursor:pointer;"><option value="week" selected>This Week</option><option value="month">This Month</option></select></div><div class="card-body"><div class="chart-wrapper"><canvas id="teacherChart"></canvas></div></div></div>
    </div>
</div>


<!-- CHART.JS -->
<script>
(function() {
    var canvas = document.getElementById('teacherChart');
    if (!canvas) return;
    var ctx = canvas.getContext('2d');
    var chartInstance = null;

    function getChartGradient() {
        var gradient = ctx.createLinearGradient(0, 0, 0, 240);
        gradient.addColorStop(0, 'rgba(96,165,250,0.20)');
        gradient.addColorStop(0.5, 'rgba(96,165,250,0.08)');
        gradient.addColorStop(1, 'rgba(96,165,250,0.0)');
        return gradient;
    }

    var chartOptions = {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { intersect: false, mode: 'index' },
        layout: { padding: { top: 8, right: 4, bottom: 0, left: 0 } },
        plugins: {
            legend: { display: false },
            tooltip: {
                backgroundColor: 'rgba(10,34,76,0.95)',
                titleColor: '#fff',
                bodyColor: 'rgba(255,255,255,0.75)',
                borderColor: 'rgba(255,255,255,0.12)',
                borderWidth: 1,
                cornerRadius: 10,
                padding: { top: 10, bottom: 10, left: 14, right: 14 },
                titleFont: { family: "'Plus Jakarta Sans',sans-serif", weight: '700', size: 13 },
                bodyFont: { family: "'JetBrains Mono',monospace", size: 12, weight: '500' },
                displayColors: false,
                callbacks: {
                    title: function(items) {
                        return items.length ? items[0].label : '';
                    },
                    label: function(context) {
                        return context.parsed.y === null ? 'No data' : context.parsed.y + '% attendance';
                    }
                }
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                max: 100,
                grid: { color: 'rgba(255,255,255,0.04)', drawBorder: false },
                ticks: {
                    callback: function(v) { return v + '%'; },
                    font: { family: "'JetBrains Mono',monospace", size: 11, weight: '500' },
                    color: 'rgba(255,255,255,0.25)',
                    stepSize: 25,
                    padding: 12
                },
                border: { display: false }
            },
            x: {
                grid: { display: false },
                ticks: {
                    font: { family: "'Plus Jakarta Sans',sans-serif", weight: '600', size: 11 },
                    color: 'rgba(255,255,255,0.3)',
                    padding: 8
                },
                border: { display: false }
            }
        }
    };

    function renderChart(labelsArr, dataArr) {
        if (chartInstance) {
            chartInstance.destroy();
            chartInstance = null;
        }
        chartInstance = new Chart(ctx, {
            type: 'line',
            data: {
                labels: labelsArr,
                datasets: [{
                    label: 'Attendance Rate',
                    data: dataArr,
                    borderColor: '#60A5FA',
                    backgroundColor: getChartGradient(),
                    fill: true,
                    tension: 0.35,
                    pointBackgroundColor: '#60A5FA',
                    pointBorderColor: 'rgba(10,34,76,0.9)',
                    pointBorderWidth: 3,
                    pointRadius: dataArr.map(function(v) { return v === null ? 0 : 5; }),
                    pointHoverRadius: 8,
                    pointHoverBackgroundColor: '#93C5FD',
                    pointHoverBorderColor: '#fff',
                    pointHoverBorderWidth: 2,
                    borderWidth: 2.5,
                    spanGaps: false
                }]
            },
            options: chartOptions
        });
    }

    function loadChart(period) {
        fetch('<?= BASE_URL ?>/api/teacher-chart.php?period=' + encodeURIComponent(period))
            .then(function(response) {
                if (!response.ok) {
                    throw new Error('Failed to load chart data');
                }
                return response.json();
            })
            .then(function(result) {
                if (result && result.labels && result.data) {
                    renderChart(result.labels, result.data);
                }
            })
            .catch(function(err) {
                console.error('Chart load error:', err);
                if (chartInstance) {
                    chartInstance.destroy();
                    chartInstance = null;
                }
            });
    }

    loadChart('week');

    var chartPeriod = document.getElementById('chartPeriod');
    if (chartPeriod) {
        chartPeriod.addEventListener('change', function() {
            loadChart(this.value);
        });
    }
})();
</script>

<!-- DASHBOARD REPORT CHARTS -->
<script>
(function() {
    var chartFont = "'Plus Jakarta Sans',sans-serif";
    var legendLabels = {
        position: 'bottom',
        labels: { padding: 12, usePointStyle: true, pointStyleWidth: 10, font: { size: 11, family: chartFont, weight: '600' }, color: 'rgba(255,255,255,0.6)' }
    };

    // ── Overall Breakdown (doughnut) ──
    (function() {
        var canvas = document.getElementById('dashPieChart');
        if (!canvas) return;
        var ctx = canvas.getContext('2d');
        var items = <?= json_encode($dashBreakdown) ?>;
        var wrapper = canvas.closest('.chart-wrapper');
        if (!items.length) {
            if (wrapper) wrapper.innerHTML = '<div class="chart-empty">No attendance records yet.</div>';
            return;
        }
        new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: items.map(function(it) { return it.label + ' (' + it.value + ')'; }),
                datasets: [{
                    data: items.map(function(it) { return it.value; }),
                    backgroundColor: items.map(function(it) { return it.color; }),
                    borderWidth: 0,
                    spacing: 3
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '58%',
                plugins: { legend: legendLabels }
            }
        });
    })();

    // ── Subject Comparison (stacked bar) ──
    (function() {
        var canvas = document.getElementById('dashBarChart');
        if (!canvas) return;
        var ctx = canvas.getContext('2d');
        var subjects = <?= json_encode($dashSubjectStats) ?>;
        var wrapper = canvas.closest('.chart-wrapper');
        if (!subjects.length) {
            if (wrapper) wrapper.innerHTML = '<div class="chart-empty">No subject records yet.</div>';
            return;
        }
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: subjects.map(function(s) { return s.name; }),
                datasets: [
                    { label: 'Present', data: subjects.map(function(s) { return s.present; }), backgroundColor: '#10b981', borderRadius: 4 },
                    { label: 'Late', data: subjects.map(function(s) { return s.late; }), backgroundColor: '#f59e0b', borderRadius: 4 },
                    { label: 'Absent', data: subjects.map(function(s) { return s.absent; }), backgroundColor: '#ef4444', borderRadius: 4 },
                    { label: 'Excused', data: subjects.map(function(s) { return s.excused; }), backgroundColor: '#06b6d4', borderRadius: 4 },
                    { label: 'Pending', data: subjects.map(function(s) { return s.pending; }), backgroundColor: '#94a3b8', borderRadius: 4 }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: { stacked: true, grid: { display: false }, ticks: { font: { size: 11, family: chartFont, weight: '600' }, color: 'rgba(255,255,255,0.4)' } },
                    y: { stacked: true, beginAtZero: true, ticks: { font: { size: 11, family: chartFont }, color: 'rgba(255,255,255,0.4)' } }
                },
                plugins: { legend: legendLabels }
            }
        });
    })();

    // ── Gender Distribution (doughnut) ──
    (function() {
        var canvas = document.getElementById('dashGenderChart');
        if (!canvas) return;
        var ctx = canvas.getContext('2d');
        var male = <?= (int)($dashGender['Male'] ?? 0) ?>;
        var female = <?= (int)($dashGender['Female'] ?? 0) ?>;
        var wrapper = canvas.closest('.chart-wrapper');
        if (!male && !female) {
            if (wrapper) wrapper.innerHTML = '<div class="chart-empty">No student roster data yet.</div>';
            return;
        }
        var items = [];
        if (male) items.push({ label: 'Boys', value: male, color: '#60A5FA' });
        if (female) items.push({ label: 'Girls', value: female, color: '#F472B6' });
        new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: items.map(function(it) { return it.label + ' (' + it.value + ')'; }),
                datasets: [{
                    data: items.map(function(it) { return it.value; }),
                    backgroundColor: items.map(function(it) { return it.color; }),
                    borderWidth: 0,
                    spacing: 3
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '58%',
                plugins: { legend: legendLabels }
            }
        });
    })();

    // ── Daily Status Breakdown (last 7 days, stacked bar) ──
    (function() {
        var canvas = document.getElementById('dashDailyChart');
        if (!canvas) return;
        var ctx = canvas.getContext('2d');
        var days = <?= json_encode($dashDaily) ?>;
        var wrapper = canvas.closest('.chart-wrapper');
        var any = days.some(function(d) { return d.present || d.late || d.absent || d.pending; });
        if (!any) {
            if (wrapper) wrapper.innerHTML = '<div class="chart-empty">No records in the last 7 days.</div>';
            return;
        }
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: days.map(function(d) { return d.label; }),
                datasets: [
                    { label: 'Present', data: days.map(function(d) { return d.present; }), backgroundColor: '#10b981', borderRadius: 3 },
                    { label: 'Late', data: days.map(function(d) { return d.late; }), backgroundColor: '#f59e0b', borderRadius: 3 },
                    { label: 'Absent', data: days.map(function(d) { return d.absent; }), backgroundColor: '#ef4444', borderRadius: 3 },
                    { label: 'Pending', data: days.map(function(d) { return d.pending; }), backgroundColor: '#94a3b8', borderRadius: 3 }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: { stacked: true, grid: { display: false }, ticks: { font: { size: 11, family: chartFont, weight: '600' }, color: 'rgba(255,255,255,0.4)' } },
                    y: { stacked: true, beginAtZero: true, ticks: { font: { size: 11, family: chartFont }, color: 'rgba(255,255,255,0.4)' } }
                },
                plugins: { legend: legendLabels }
            }
        });
    })();

    // ── Student Attendance Leaders (horizontal bar, top 5) ──
    (function() {
        var canvas = document.getElementById('dashLeadersChart');
        if (!canvas) return;
        var ctx = canvas.getContext('2d');
        var leaders = <?= json_encode($dashLeaders) ?>;
        var wrapper = canvas.closest('.chart-wrapper');
        if (!leaders.length) {
            if (wrapper) wrapper.innerHTML = '<div class="chart-empty">No attendance data yet.</div>';
            return;
        }
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: leaders.map(function(l) { return l.name; }),
                datasets: [{
                    label: 'Attendance Rate',
                    data: leaders.map(function(l) { return l.rate; }),
                    backgroundColor: 'rgba(16,185,129,0.75)',
                    borderColor: '#10b981',
                    borderWidth: 1.5,
                    borderRadius: 4,
                    maxBarThickness: 22
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: { beginAtZero: true, max: 100, grid: { color: 'rgba(255,255,255,0.04)' }, ticks: { callback: function(v) { return v + '%'; }, font: { size: 11, family: chartFont }, color: 'rgba(255,255,255,0.4)' } },
                    y: { grid: { display: false }, ticks: { font: { size: 11, family: chartFont, weight: '600' }, color: 'rgba(255,255,255,0.6)' } }
                },
                plugins: {
                    legend: { display: false },
                    tooltip: { callbacks: { label: function(t) { return ' ' + t.parsed.x + '%'; } } }
                }
            }
        });
    })();
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>