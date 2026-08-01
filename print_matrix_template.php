<?php
require_once __DIR__ . '/config.php';
requireRole(['admin', 'teacher']);

$gradeLevel = sanitize($_GET['grade_level'] ?? '');
$section    = sanitize($_GET['section'] ?? '');
$subjectId  = intval($_GET['subject_id']   ?? 0);
$dateFrom   = sanitize($_GET['date_from']  ?? date('Y-m-01'));
$dateTo     = sanitize($_GET['date_to']    ?? date('Y-m-d'));

if (empty($gradeLevel) || empty($section) || $subjectId < 1) {
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!DOCTYPE html><html><head><title>Error - Attendance Sheet</title><style>body{font-family:Segoe UI,Arial,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;background:#f8fafc;color:#1e293b;} .err-box{text-align:center;padding:40px;background:#fff;border-radius:12px;box-shadow:0 4px 24px rgba(0,0,0,0.08);max-width:420px;} .err-box h2{color:#dc2626;margin:0 0 8px;} .err-box p{color:#64748b;margin:0;}</style></head><body><div class="err-box"><h2>Missing Parameters</h2><p>Please select Grade, Section, and Subject filters, then try again.</p></div></body></html>';
    exit;
}

$db = $db ?? null;
if (!$db) {
    $database = new Database();
    $db = $database->getConnection();
}

$subject = getSubjectById($db, $subjectId);
if (!$subject) {
    die('Subject not found.');
}

$teacherId = getCurrentUserId();
$teacher   = $db->prepare("SELECT * FROM teachers WHERE user_id = ?");
$teacher->execute([$teacherId]);
$teacher = $teacher->fetch();
$teacherName = $teacher['first_name'] ?? '';
if (!empty($teacher['middle_name'])) {
    $teacherName .= ' ' . strtoupper($teacher['middle_name'][0]) . '.';
}
$teacherName .= ' ' . ($teacher['last_name'] ?? '');
if (empty($teacherName)) $teacherName = 'ANGELYN S. PARRABA';

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
    'excused' => 'E',
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance Sheet Matrix</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/print.css">
    <style>
        body {
            margin: 0;
            padding: 0;
            background: #fff;
            font-family: 'Segoe UI', Arial, sans-serif;
            color: #1e293b;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
.print-header-img {
            display: block;
            max-height: 120px;
            object-fit: contain;
            margin-bottom: 8px;
        }
        .matrix-sheet {
            width: 100%;
            max-width: 100%;
            margin: 0;
            padding: 8px 6px;
            box-sizing: border-box;
        }
        .matrix-header {
            text-align: center;
            margin-bottom: 10px;
        }
        .matrix-header .double-rule {
            border: none;
            border-top: 3px solid #1e3a5f;
            margin: 0;
            padding: 0;
        }
        .matrix-header .double-rule + .double-rule {
            border-top-width: 1px;
            margin-top: -1px;
        }
        .matrix-header h1 {
            font-size: 20px;
            font-weight: 800;
            color: #1e3a5f;
            margin: 8px 0 4px;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }
        .matrix-header .class-details {
            font-size: 12px;
            color: #475569;
            line-height: 1.5;
        }
        .matrix-header .class-details strong {
            color: #1e293b;
        }
        .matrix-header .month-range {
            font-size: 11px;
            color: #64748b;
            margin-top: 3px;
            font-weight: 600;
        }
        .matrix-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 9px;
            table-layout: auto;
            min-width: 800px;
        }
        .matrix-table th,
        .matrix-table td {
            border: 1px solid #94a3b8;
            padding: 3px 2px;
            text-align: center;
            vertical-align: middle;
            font-size: 9px;
            white-space: nowrap;
        }
        .matrix-table thead th {
            background: #f0f1f4;
            font-weight: 700;
            font-size: 8px;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            color: #334155;
            padding: 4px 2px;
        }
        .matrix-table thead th.week-header {
            background: #1e3a5f;
            color: #fff;
            font-size: 8px;
        }
        .matrix-table thead th.day-header {
            background: #e2e8f0;
            font-size: 8px;
            font-weight: 700;
            color: #475569;
        }
        .matrix-table tbody td.student-name {
            text-align: left;
            padding-left: 6px;
            font-weight: 600;
            font-size: 9px;
            min-width: 100px;
            max-width: 150px;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .matrix-table tbody td.day-cell {
            font-family: 'JetBrains Mono', 'Courier New', monospace;
            font-size: 9px;
            font-weight: 700;
            min-width: 28px;
            max-width: 32px;
        }
        .matrix-table tbody td.day-cell.present {
            color: #059669;
            background: rgba(16,185,129,0.08);
        }
        .matrix-table tbody td.day-cell.absent {
            color: #dc2626;
            background: rgba(239,68,68,0.08);
        }
        .matrix-table tbody td.day-cell.late {
            color: #d97706;
            background: rgba(245,158,11,0.08);
        }
        .matrix-table tbody td.day-cell.excused {
            color: #0e7490;
            background: rgba(6,182,212,0.08);
        }
        .matrix-table tbody tr {
            page-break-inside: avoid;
        }
        .matrix-footer {
            margin-top: 20px;
            padding-top: 8px;
            border-top: 2px solid #1e3a5f;
        }
        .matrix-footer .signature-row {
            display: flex;
            justify-content: space-between;
            gap: 30px;
            margin-top: 12px;
        }
        .matrix-footer .sig-line {
            flex: 1;
            padding-top: 3px;
            font-size: 9px;
            color: #475569;
            text-align: center;
        }
        .matrix-footer .sig-line strong {
            display: block;
            margin-bottom: 1px;
            color: #1e293b;
        }
        @media print {
            body {
                padding: 0 !important;
                margin: 0 !important;
            }
            .matrix-sheet {
                padding: 5px 6px !important;
            }
            .matrix-header h1 {
                font-size: 16px;
            }
            .matrix-table {
                font-size: 8px;
            }
            .matrix-table th,
            .matrix-table td {
                padding: 2px 1px;
                font-size: 8px;
            }
            .matrix-table thead th {
                font-size: 7px;
                padding: 3px 1px;
            }
            .matrix-table tbody td.student-name {
                font-size: 8px;
                min-width: 80px;
                max-width: 120px;
            }
            .matrix-table tbody td.day-cell {
                font-size: 8px;
                min-width: 24px;
                max-width: 28px;
            }
            .matrix-footer {
                margin-top: 12px;
            }
            .matrix-footer .sig-line {
                font-size: 8px;
            }
            .matrix-header .month-range {
                font-size: 10px;
            }
            .print-header-img {
                max-height: 100px !important;
                margin-bottom: 4px !important;
            }
            @page {
                size: landscape;
                margin: 8mm 6mm 8mm 6mm;
            }
        }
    </style>
</head>
<body>
    <img src="<?= BASE_URL ?>/assets/images/header.jpg" alt="Header" class="print-header-img" style="width:100%;max-height:120px;object-fit:contain;display:block;margin-bottom:8px;">
    <div class="matrix-sheet">
        <div class="matrix-header">
            <hr class="double-rule">
            <hr class="double-rule">
            <h1>Attendance Sheet</h1>
            <div class="class-details">
                <strong>Subject:</strong> <?= sanitize($subject['subject_name']) ?><br>
                <strong>Grade & Section:</strong> <?= sanitize($gradeLevel) ?> - <?= sanitize($section) ?><br>
                <strong>Academic Year:</strong> <?= sanitize($academicYear) ?> &bull; <?= sanitize($semester) ?>
            </div>
            <div class="month-range">Month: <?= sanitize($monthRange) ?></div>
        </div>

        <table class="matrix-table">
            <thead>
                <tr>
                    <th rowspan="2" style="width:100px;min-width:100px;">Student Name</th>
                    <?php for ($w = 1; $w <= $totalWeeks; $w++): ?>
                    <th colspan="5" class="week-header">Week <?= $w ?></th>
                    <?php endfor; ?>
                </tr>
                <tr>
                    <?php for ($w = 1; $w <= $totalWeeks; $w++): ?>
                        <?php for ($d = 1; $d <= 5; $d++): ?>
                        <th class="day-header">
                            <?php
                            $dayLabels = ['M', 'T', 'W', 'T', 'F'];
                            echo $dayLabels[$d - 1];
                            ?>
                        </th>
                        <?php endfor; ?>
                    <?php endfor; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($roster as $student): ?>
                <tr>
                    <td class="student-name"><?= sanitize($student['last_name'] . ', ' . $student['first_name']) ?></td>
                    <?php
                    $studentId = $student['id'];
                    $studentAtt = $attendanceMap[$studentId] ?? [];
                    for ($w = 0; $w < $totalWeeks; $w++):
                        for ($d = 0; $d < 5; $d++):
                            $dateKey = $weekDates[$w][$d];
                            $status = $dateKey ? ($studentAtt[$dateKey] ?? null) : null;
                            $display = $status && isset($statusSymbols[$status]) ? $statusSymbols[$status] : '';
                            $cssClass = $status && isset($statusSymbols[$status]) ? strtolower($status) : '';
                    ?>
                    <td class="day-cell <?= $cssClass ?>"><?= $display ?></td>
                    <?php endfor; endfor; ?>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="matrix-footer">
            <div class="signature-row">
                <div class="sig-line">
                    <hr style="border: none; border-top: 1px solid #94a3b8; margin: 0 auto 4px auto; width: 70%;">
                    <strong><?= sanitize($teacherName) ?></strong>
                    <span>Subject Teacher</span>
                </div>
                <div class="sig-line">
                    <hr style="border: none; border-top: 1px solid #94a3b8; margin: 0 auto 4px auto; width: 70%;">
                    <strong>ERWIN M. ESPENILLA</strong>
                    <span>OIC/Assistant Principal</span>
                </div>
            </div>
        </div>
    </div>

    <script>
        window.onload = function() {
            setTimeout(function() {
                window.print();
            }, 200);
        };
    </script>
</body>
</html>