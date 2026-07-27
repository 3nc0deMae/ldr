<?php
/**
 * LDB-FRAS - Teacher Chart Data API
 * Returns attendance rate data for a given period
 */

require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');
requireLogin();
requireRole(['teacher', 'admin']);

$teacherId = getCurrentUserId();
$period = sanitize($_GET['period'] ?? 'week');

$days = ($period === 'month') ? 30 : 7;
$endDate = new DateTime('today');
$startDate = new DateTime('today');
$startDate->modify("-" . ($days - 1) . " days");

$start = $startDate->format('Y-m-d');
$end = $endDate->format('Y-m-d');

$teacherRecord = null;
$teacherRecordId = 0;
$teacherSessionIds = [];
$teacherSubjectIds = [];

try {
    $stmt = $db->prepare("SELECT t.* FROM teachers t JOIN users u ON t.user_id = u.id WHERE u.id = ? LIMIT 1");
    $stmt->execute([$teacherId]);
    $teacherRecord = $stmt->fetch();
    $teacherRecordId = $teacherRecord ? (int)$teacherRecord['id'] : 0;
} catch (Exception $e) {
    error_log('chart teacherRecord: ' . $e->getMessage());
}

try {
    $stmt = $db->prepare("SELECT id FROM attendance_sessions WHERE created_by = ?");
    $stmt->execute([$teacherRecordId]);
    $teacherSessionIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    error_log('chart teacherSessionIds: ' . $e->getMessage());
}

function collectSubjectIdsFromJson($raw) {
    $ids = [];
    if (empty($raw)) return $ids;
    if (is_string($raw)) {
        $raw = json_decode($raw, true);
    }
    if (!is_array($raw)) return $ids;
    foreach ($raw as $item) {
        if (isset($item['subject_id']) && is_numeric($item['subject_id'])) {
            $ids[] = (int)$item['subject_id'];
        }
    }
    return $ids;
}

if ($teacherRecord) {
    $teacherSubjectIds = array_merge(
        $teacherSubjectIds,
        collectSubjectIdsFromJson($teacherRecord['grade_section_handled'] ?? ''),
        collectSubjectIdsFromJson($teacherRecord['core_subjects_handled'] ?? ''),
        collectSubjectIdsFromJson($teacherRecord['track_elective_handled'] ?? '')
    );
}

try {
    $stmt = $db->prepare("SELECT subject_id FROM teacher_subjects WHERE teacher_id = ?");
    $stmt->execute([$teacherRecordId]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $sid) {
        $teacherSubjectIds[] = (int)$sid;
    }
} catch (Exception $e) {
    error_log('chart teacher_subjects: ' . $e->getMessage());
}

$teacherSubjectIds = array_values(array_unique(array_filter($teacherSubjectIds, function ($id) {
    return $id > 0;
})));

$calendarEvents = [];

if (!empty($teacherSessionIds)) {
    try {
        $ph = implode(',', array_fill(0, count($teacherSessionIds), '?'));
        $stmt = $db->prepare("SELECT DATE(scan_time) as event_date, SUM(CASE WHEN status='present' THEN 1 ELSE 0 END) as present, SUM(CASE WHEN status='absent' THEN 1 ELSE 0 END) as absent, SUM(CASE WHEN status='late' THEN 1 ELSE 0 END) as late, COUNT(*) as total FROM attendance_records WHERE session_id IN ($ph) AND DATE(scan_time) BETWEEN ? AND ? GROUP BY DATE(scan_time)");
        $stmt->execute(array_merge($teacherSessionIds, [$start, $end]));
        foreach ($stmt->fetchAll() as $row) {
            $d = $row['event_date'];
            if (!isset($calendarEvents[$d])) {
                $calendarEvents[$d] = ['present' => 0, 'absent' => 0, 'late' => 0, 'total' => 0];
            }
            $calendarEvents[$d]['present'] += (int)$row['present'];
            $calendarEvents[$d]['absent'] += (int)$row['absent'];
            $calendarEvents[$d]['late'] += (int)$row['late'];
            $calendarEvents[$d]['total'] += (int)$row['total'];
        }
    } catch (Exception $e) {
        error_log('chart attendance_records: ' . $e->getMessage());
    }
}

if (!empty($teacherSubjectIds)) {
    try {
        $ph = implode(',', array_fill(0, count($teacherSubjectIds), '?'));
        $stmt = $db->prepare("SELECT date as event_date, SUM(CASE WHEN status='present' THEN 1 ELSE 0 END) as present, SUM(CASE WHEN status='absent' THEN 1 ELSE 0 END) as absent, SUM(CASE WHEN status='late' THEN 1 ELSE 0 END) as late, COUNT(*) as total FROM attendance WHERE subject_id IN ($ph) AND date BETWEEN ? AND ? GROUP BY date");
        $stmt->execute(array_merge($teacherSubjectIds, [$start, $end]));
        foreach ($stmt->fetchAll() as $row) {
            $d = $row['event_date'];
            if (!isset($calendarEvents[$d])) {
                $calendarEvents[$d] = ['present' => 0, 'absent' => 0, 'late' => 0, 'total' => 0];
            }
            $calendarEvents[$d]['present'] += (int)$row['present'];
            $calendarEvents[$d]['absent'] += (int)$row['absent'];
            $calendarEvents[$d]['late'] += (int)$row['late'];
            $calendarEvents[$d]['total'] += (int)$row['total'];
        }
    } catch (Exception $e) {
        error_log('chart attendance: ' . $e->getMessage());
    }
}

$labels = [];
$data = [];
for ($i = $days - 1; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-{$i} days"));
    if ($period === 'month') {
        $labels[] = date('M j', strtotime($d));
    } else {
        $labels[] = date('D M j', strtotime($d));
    }
    $ev = $calendarEvents[$d] ?? null;
    $data[] = ($ev && $ev['total'] > 0) ? round(($ev['present'] / $ev['total']) * 100, 1) : null;
}

$avgRate = 0;
$validCount = 0;
foreach ($data as $val) {
    if ($val !== null) {
        $avgRate += $val;
        $validCount++;
    }
}
$avgRate = $validCount > 0 ? round($avgRate / $validCount, 1) : 0;

jsonResponse([
    'labels' => $labels,
    'data' => $data,
    'avgRate' => $avgRate,
    'daysWithData' => $validCount,
    'period' => $period
]);
