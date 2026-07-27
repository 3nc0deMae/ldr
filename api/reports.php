<?php
/**
 * LDB-FRAS - Reports API
 * AJAX endpoint for generating attendance reports
 */

require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

requireLogin();

csrfMiddleware(true);

$action = $_POST['action'] ?? '';

switch ($action) {

    case 'daily_report':
        $date = sanitize($_POST['date'] ?? today());
        
        $sql = "SELECT a.status, COUNT(*) as count
                FROM attendance a
                WHERE a.date = ?
                GROUP BY a.status";
        $stmt = $db->prepare($sql);
        $stmt->execute([$date]);
        $summary = $stmt->fetchAll();

        $records = getAttendance($db, ['date' => $date]);

        jsonResponse([
            'date'    => $date,
            'summary' => $summary,
            'records' => $records,
            'total'   => count($records)
        ]);
        break;

    case 'subject_report':
        $subjectId = intval($_POST['subject_id'] ?? 0);
        $dateFrom  = sanitize($_POST['date_from'] ?? '');
        $dateTo    = sanitize($_POST['date_to'] ?? '');

        if (!$subjectId) {
            jsonResponse(['error' => 'Subject ID is required'], 400);
        }

        $filters = ['subject_id' => $subjectId];
        if ($dateFrom) $filters['date_from'] = $dateFrom;
        if ($dateTo)   $filters['date_to']   = $dateTo;

        $records = getAttendance($db, $filters);

        // Calculate stats
        $present = $absent = $late = 0;
        foreach ($records as $r) {
            switch ($r['status']) {
                case 'present': $present++; break;
                case 'absent':  $absent++;  break;
                case 'late':    $late++;    break;
            }
        }
        $total = count($records);
        $rate = $total > 0 ? round(($present / $total) * 100, 1) : 0;

        jsonResponse([
            'subject_id' => $subjectId,
            'total'      => $total,
            'present'    => $present,
            'absent'     => $absent,
            'late'       => $late,
            'rate'       => $rate,
            'records'    => $records
        ]);
        break;

    case 'student_report':
        $studentId = intval($_POST['student_id'] ?? 0);
        $dateFrom  = sanitize($_POST['date_from'] ?? '');
        $dateTo    = sanitize($_POST['date_to'] ?? '');

        if (!$studentId) {
            jsonResponse(['error' => 'Student ID is required'], 400);
        }

        $filters = ['student_id' => $studentId];
        if ($dateFrom) $filters['date_from'] = $dateFrom;
        if ($dateTo)   $filters['date_to']   = $dateTo;

        $records = getAttendance($db, $filters);

        $present = $absent = $late = 0;
        foreach ($records as $r) {
            switch ($r['status']) {
                case 'present': $present++; break;
                case 'absent':  $absent++;  break;
                case 'late':    $late++;    break;
            }
        }
        $total = count($records);
        $rate = $total > 0 ? round(($present / $total) * 100, 1) : 0;

        jsonResponse([
            'student_id' => $studentId,
            'total'      => $total,
            'present'    => $present,
            'absent'     => $absent,
            'late'       => $late,
            'rate'       => $rate,
            'records'    => $records
        ]);
        break;

    case 'grade_section_report':
        $gradeLevel = sanitize($_POST['grade_level'] ?? '');
        $section    = sanitize($_POST['section'] ?? '');
        $dateFrom   = sanitize($_POST['date_from'] ?? '');
        $dateTo     = sanitize($_POST['date_to'] ?? '');

        if (!$gradeLevel) {
            jsonResponse(['error' => 'Grade level is required'], 400);
        }

        $filters = ['grade_level' => $gradeLevel];
        if ($section) $filters['section'] = $section;
        if ($dateFrom) $filters['date_from'] = $dateFrom;
        if ($dateTo)   $filters['date_to']   = $dateTo;

        $students = getStudents($db, ['grade_level' => $gradeLevel, 'section' => $section]);

        $reportData = [];
        foreach ($students as $student) {
            $attRecords = getAttendance($db, array_merge($filters, ['student_id' => $student['id']]));
            $present = $absent = $late = 0;
            foreach ($attRecords as $r) {
                switch ($r['status']) {
                    case 'present': $present++; break;
                    case 'absent':  $absent++;  break;
                    case 'late':    $late++;    break;
                }
            }
            $total = count($attRecords);
            $rate = $total > 0 ? round(($present / $total) * 100, 1) : 0;

            $reportData[] = [
                'student_id'   => $student['student_id'],
                'name'         => $student['first_name'] . ' ' . $student['last_name'],
                'total'        => $total,
                'present'      => $present,
                'absent'       => $absent,
                'late'         => $late,
                'rate'         => $rate
            ];
        }

        jsonResponse([
            'grade_level' => $gradeLevel,
            'section'     => $section,
            'students'    => count($reportData),
            'data'        => $reportData
        ]);
        break;

    case 'monthly_chart_data':
        $month = intval($_POST['month'] ?? date('m'));
        $year  = intval($_POST['year'] ?? date('Y'));

        $sql = "SELECT DATE(date) as att_date, status, COUNT(*) as count
                FROM attendance
                WHERE MONTH(date) = ? AND YEAR(date) = ?
                GROUP BY DATE(date), status
                ORDER BY att_date ASC";
        $stmt = $db->prepare($sql);
        $stmt->execute([$month, $year]);
        $data = $stmt->fetchAll();

        jsonResponse(['data' => $data]);
        break;

    default:
        jsonResponse(['error' => 'Invalid action'], 400);
}
