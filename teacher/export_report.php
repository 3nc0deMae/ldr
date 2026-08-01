<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/xlsx_template.php';
requireRole(['admin', 'teacher']);

$teacherId = getCurrentUserId();

$dateFrom   = sanitize($_GET['date_from'] ?? date('Y-m-01'));
$dateTo     = sanitize($_GET['date_to']   ?? date('Y-m-d'));
$gradeLevel = sanitize($_GET['grade_level'] ?? '');
$section    = sanitize($_GET['section']    ?? '');
$subjectId  = intval($_GET['subject_id']   ?? 0);
$format     = ($_GET['format'] ?? 'standard') === 'matrix' ? 'matrix' : 'standard';

if ($format === 'matrix') {
    if (empty($gradeLevel) || empty($section) || $subjectId < 1) {
        header('Content-Type: text/html; charset=UTF-8');
        echo '<!DOCTYPE html><html><head><title>Error - Attendance Sheet</title><style>body{font-family:Segoe UI,Arial,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;background:#f8fafc;color:#1e293b;} .err-box{text-align:center;padding:40px;background:#fff;border-radius:12px;box-shadow:0 4px 24px rgba(0,0,0,0.08);max-width:420px;} .err-box h2{color:#dc2626;margin:0 0 8px;} .err-box p{color:#64748b;margin:0;}</style></head><body><div class="err-box"><h2>Missing Parameters</h2><p>Please select Grade, Section, and Subject filters, then try again.</p></div></body></html>';
        exit;
    }

    $subject = getSubjectById($db, $subjectId);
    if (!$subject) {
        die('Subject not found.');
    }

    $teacherStmt = $db->prepare("SELECT * FROM teachers WHERE user_id = ?");
    $teacherStmt->execute([$teacherId]);
    $teacherRow = $teacherStmt->fetch();
    $teacherName = 'N/A';
    if ($teacherRow) {
        $teacherName = $teacherRow['first_name'] ?? '';
        if (!empty($teacherRow['middle_name'])) {
            $teacherName .= ' ' . strtoupper($teacherRow['middle_name'][0]) . '.';
        }
        $teacherName .= ' ' . ($teacherRow['last_name'] ?? '');
    }

    $rosterSql = "SELECT s.id, s.student_id, s.first_name, s.last_name, s.grade_level, s.section
                   FROM students s
                   WHERE s.grade_level = :grade AND s.section = :section AND s.status = 'active'
                   ORDER BY s.last_name ASC, s.first_name ASC";
    $rosterStmt = $db->prepare($rosterSql);
    $rosterStmt->execute([':grade' => $gradeLevel, ':section' => $section]);
    $roster = $rosterStmt->fetchAll();

    $attendanceSql = "SELECT student_id, date, status
                      FROM attendance
                      WHERE subject_id = :sid AND recorded_by = :tid
                        AND date BETWEEN :df AND :dt";
    $attStmt = $db->prepare($attendanceSql);
    $attStmt->execute([':sid' => $subjectId, ':tid' => $teacherId, ':df' => $dateFrom, ':dt' => $dateTo]);
    $attRecords = $attStmt->fetchAll();

    $attendanceMap = [];
    foreach ($attRecords as $r) {
        $attendanceMap[$r['student_id']][$r['date']] = $r['status'];
    }

    $statusSymbols = [
        'present' => '✓',
        'absent'  => '✗',
        'late'    => 'L',
        'pending' => '-',
    ];

    $academicYear = date('Y') . '–' . (date('Y') + 1);
    $semester = (date('n') <= 6) ? 'First Semester' : 'Second Semester';

    $monthRange = date('F Y', strtotime($dateFrom));
    if (date('Y-m', strtotime($dateFrom)) !== date('Y-m', strtotime($dateTo))) {
        $monthRange = date('F Y', strtotime($dateFrom)) . ' – ' . date('F Y', strtotime($dateTo));
    }

    $startDate = new DateTime($dateFrom);
    $endDate = new DateTime($dateTo);
    $weekDates = [];
    $currentWeekStart = clone $startDate;
    while ($currentWeekStart <= $endDate) {
        $days = [];
        for ($d = 0; $d < 5; $d++) {
            $dayDate = clone $currentWeekStart;
            $dayDate->modify('+' . $d . ' days');
            if ($dayDate > $endDate) break;
            $days[] = $dayDate->format('Y-m-d');
        }
        while (count($days) < 5) {
            $days[] = '';
        }
        if (!empty($days)) {
            $weekDates[] = $days;
        }
        $currentWeekStart->modify('+7 days');
    }
    $totalWeeks = count($weekDates);

    $rows = [];
    $rows[] = ['Attendance Sheet'];
    $rows[] = ['Subject: ' . $subject['subject_name']];
    $rows[] = ['Grade & Section: ' . $gradeLevel . ' - ' . $section];
    $rows[] = ['Academic Year: ' . $academicYear . ' • ' . $semester];
    $rows[] = ['Month: ' . $monthRange];
    $rows[] = [];
    $rows[] = ['Prepared by: ' . $teacherName];
    $rows[] = ['Period: ' . $dateFrom . ' to ' . $dateTo];
    $rows[] = [];

    $weekHeader = ['Student Name'];
    foreach ($weekDates as $w => $days) {
        $weekHeader[] = 'Week ' . ($w + 1);
        for ($i = 1; $i < 5; $i++) {
            $weekHeader[] = '';
        }
    }
    $rows[] = $weekHeader;

    $dayHeader = ['Student Name'];
    foreach ($weekDates as $days) {
        foreach ($days as $dateKey) {
            $dayHeader[] = $dateKey !== '' ? date('D m/d', strtotime($dateKey)) : '';
        }
    }
    $rows[] = $dayHeader;

    foreach ($roster as $student) {
        $row = [$student['last_name'] . ', ' . $student['first_name']];
        $studentId = $student['id'];
        $studentAtt = $attendanceMap[$studentId] ?? [];
        foreach ($weekDates as $days) {
            foreach ($days as $dateKey) {
                $status = $dateKey !== '' ? ($studentAtt[$dateKey] ?? null) : null;
                $row[] = $status && isset($statusSymbols[$status]) ? $statusSymbols[$status] : '';
            }
        }
        $rows[] = $row;
    }

    $widths = [0 => 32];
    $col = 1;
    foreach ($weekDates as $days) {
        foreach ($days as $i => $d) {
            $widths[$col] = $i === 0 ? 13 : 9;
            $col++;
        }
    }

    $sheet = xlsx_sheet_xml($rows, 1, $widths);
    $filename = 'attendance_matrix_' . preg_replace('/[^A-Za-z0-9]+/', '-', $gradeLevel . '-' . $section) . '_' . date('Y-m-d') . '.xlsx';
} else {
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
                'present'     => 0, 'late' => 0, 'absent' => 0, 'pending' => 0, 'total' => 0
            ];
        }
        $studentStats[$sid]['total']++;
        $studentStats[$sid][$r['status']]++;
    }

    $reportData = [];
    foreach ($studentStats as $sid => $stats) {
        $rate = $stats['total'] > 0 ? round((($stats['present'] + $stats['late']) / $stats['total']) * 100, 1) : 0;
        $stats['rate'] = $rate;
        $reportData[] = $stats;
    }
    usort($reportData, fn($a, $b) => strcmp($a['name'], $b['name']));

    $rows = [];
    $rows[] = ['Attendance Report'];
    $rows[] = ['Period: ' . $dateFrom . ' to ' . $dateTo];
    $rows[] = ['Generated: ' . date('F d, Y g:i A')];
    $rows[] = [];
    $rows[] = ['Student ID', 'Name', 'Grade', 'Section', 'Subject', 'Present', 'Late', 'Absent', 'Total', 'Rate'];
    foreach ($reportData as $stats) {
        $rows[] = [
            $stats['sid'],
            $stats['name'],
            $stats['grade_level'],
            $stats['section'],
            $stats['subject'],
            $stats['present'],
            $stats['late'],
            $stats['absent'],
            $stats['total'],
            $stats['rate'] . '%',
        ];
    }

    $sheet = xlsx_sheet_xml($rows, 1, [0 => 14, 1 => 28, 2 => 8, 3 => 12, 4 => 22, 5 => 9, 6 => 9, 7 => 9, 8 => 9, 9 => 9]);
    $filename = 'teacher_attendance_report_' . date('Y-m-d') . '.xlsx';
}

$xlsx = xlsx_build_package([['name' => 'Attendance Report', 'xml' => $sheet]]);

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($xlsx));
echo $xlsx;
exit;
