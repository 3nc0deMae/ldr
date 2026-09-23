<?php
// Never emit PHP warnings/notices into a binary download (breaks Excel).
ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/xlsx_template.php';
requireRole(['admin', 'teacher']);

$teacherId = getCurrentUserId();

$dateFrom   = sanitize($_GET['date_from'] ?? date('Y-m-01'));
$dateTo     = sanitize($_GET['date_to']   ?? date('Y-m-d'));
$gradeLevel = sanitize($_GET['grade_level'] ?? '');
$section    = sanitize($_GET['section']    ?? '');
$subjectId  = intval($_GET['subject_id']   ?? 0);
$format     = $_GET['format'] ?? 'standard';
if (!in_array($format, ['standard', 'matrix', 'sf2'], true)) $format = 'standard';

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
} elseif ($format === 'sf2') {
    require_once __DIR__ . '/../includes/sf2_report_data.php';

    if (empty($gradeLevel) || empty($section)) {
        header('Content-Type: text/html; charset=UTF-8');
        echo '<!DOCTYPE html><html><head><title>Error - School Form 2</title><style>body{font-family:Segoe UI,Arial,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;background:#f8fafc;color:#1e293b;} .err-box{text-align:center;padding:40px;background:#fff;border-radius:12px;box-shadow:0 4px 24px rgba(0,0,0,0.08);max-width:420px;} .err-box h2{color:#dc2626;margin:0 0 8px;} .err-box p{color:#64748b;margin:0;}</style></head><body><div class="err-box"><h2>Missing Parameters</h2><p>Please select Grade and Section filters, then try again.</p></div></body></html>';
        exit;
    }

    $totalDays = $totalWeeks * 5;
    $maleCount = count($males);
    $femaleCount = count($females);

    /* 1-based matrix columns: 1=No, 2=NAME, 3..(2+totalDays)=days,
       then ABSENT (2 cols), PRESENT (2 cols), REMARKS (5 cols),
       mirroring SF2_Template.xls AM-AU bands (AM:AN / AO:AP / AQ:AU). */
    $absCol = 3 + $totalDays;                 // 1-based ABSENT column start
    $preCol = $absCol + 2;                    // 1-based PRESENT column start
    $remCol = $absCol + 4;                    // 1-based REMARKS column start
    $fullCols = $absCol + 8;                  // 1-based last column

    $malePresent    = max(0, $maleCount * $schoolDaysCount - $maleBlock['absent']);
    $femalePresent  = max(0, $femaleCount * $schoolDaysCount - $femaleBlock['absent']);
    $combinedPresent = max(0, $registeredLearners * $schoolDaysCount - $combinedAbsent);

    $fiveConsecM = 0;
    foreach ($maleBlock['rows'] as $r) {
        if ((int)($r['maxRun'] ?? 0) >= 5) $fiveConsecM++;
    }
    $fiveConsecF = 0;
    foreach ($femaleBlock['rows'] as $r) {
        if ((int)($r['maxRun'] ?? 0) >= 5) $fiveConsecF++;
    }
    $fiveConsec = $fiveConsecM + $fiveConsecF;
    $droppedOutM = 0; $droppedOutF = 0; $transferredOutM = 0; $transferredOutF = 0;
    foreach (array_merge($maleBlock['rows'], $femaleBlock['rows']) as $r) {
        $st = strtolower(trim((string)($r['student']['status'] ?? 'active')));
        $isMale = strcasecmp(trim((string)($r['student']['gender'] ?? '')), 'Male') === 0;
        if (in_array($st, ['inactive', 'dropped'], true)) {
            if ($isMale) $droppedOutM++; else $droppedOutF++;
        } elseif ($st === 'transferred') {
            if ($isMale) $transferredOutM++; else $transferredOutF++;
        }
    }
    $droppedOut = $droppedOutM + $droppedOutF;
    $transferredOut = $transferredOutM + $transferredOutF;
    $pctEnrol = $enrolFirstFriday > 0 ? round(($registeredLearners / $enrolFirstFriday) * 100, 2) : 0;
    $malePctEnrol = $maleEnrolFirstFriday > 0 ? round(($maleCount / $maleEnrolFirstFriday) * 100, 2) : 0;
    $femalePctEnrol = $femaleEnrolFirstFriday > 0 ? round(($femaleCount / $femaleEnrolFirstFriday) * 100, 2) : 0;
    $maleADA = $schoolDaysCount > 0 ? round(array_sum($maleBlock['attended']) / $schoolDaysCount, 2) : 0;
    $femaleADA = $schoolDaysCount > 0 ? round(array_sum($femaleBlock['attended']) / $schoolDaysCount, 2) : 0;
    $malePctAttendance = $maleCount > 0 ? round(($maleADA / $maleCount) * 100, 2) : 0;
    $femalePctAttendance = $femaleCount > 0 ? round(($femaleADA / $femaleCount) * 100, 2) : 0;
    $pctAttendance = $registeredLearners > 0 ? round(($ada / $registeredLearners) * 100, 2) : 0;
    $principalName = ($settings['principal_name'] ?? '') !== '' ? $settings['principal_name'] : 'ERWIN M. ESPENILLA';

    $S = function ($v, $st) { return ['v' => (string)$v, 's' => $st]; };
    $padRow = function (array $cells, $fullCols) {
        $row = [];
        for ($i = 0; $i < $fullCols; $i++) {
            $row[] = isset($cells[$i]) ? $cells[$i] : ['v' => '', 's' => XLSX_ST_DEFAULT];
        }
        return $row;
    };

    /* ── Column widths — copied from SF2_Template.xls (character units) ── */
    $widths = [];
    $widths[0] = 3.02;                          // No.
    $widths[1] = 23.01;                         // NAME
    for ($i = 2; $i < 2 + $totalDays; $i++) $widths[$i] = 2.86;  // day columns
    /* ABSENT (AM:AN) / PRESENT (AO:AP) / REMARKS (AQ:AU) — template widths */
    $widths[$absCol - 1] = 1.18;                // AM
    $widths[$absCol]     = 5.04;                // AN
    $widths[$absCol + 1] = 5.04;                // AO
    $widths[$absCol + 2] = 1.68;                // AP
    $widths[$absCol + 3] = 10.08;               // AQ
    $widths[$absCol + 4] = 3.36;                // AR
    $widths[$absCol + 5] = 3.36;                // AS
    $widths[$absCol + 6] = 6.72;                // AT
    $widths[$absCol + 7] = 0.34;                // AU

    /* ── Bottom panel split (like the template):
       guidelines = No + NAME + first ~60% of the day columns
       codes      = remaining day columns
       summary    = ABSENT + PRESENT + REMARKS columns ── */
    $gDays = (int)round($totalDays * 0.6);
    if ($gDays < 2) $gDays = 2;
    $g2 = 2 + $gDays;                           // last guideline column (1-based)
    $c1 = 3 + $gDays;                           // first codes column
    $c2 = $absCol - 1;                          // last codes column
    if ($c1 > $c2) $c1 = $c2;

    $sumWidth = function ($from, $to) use (&$widths) {
        $s = 0;
        for ($i = $from; $i <= $to; $i++) $s += isset($widths[$i]) ? $widths[$i] : 0;
        return $s;
    };
    $gw = $sumWidth(0, $g2 - 1);
    $cw = $sumWidth($c1 - 1, $c2 - 1);
    $sw = $sumWidth($absCol - 1, $absCol + 7);   // full right panel (label + value bands)

    /* Estimated row height for wrapped text in merged cells (5-7pt fonts) */
    $estHeight = function ($text, $width) {
        $text = (string)$text;
        $len = function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
        $charsPerLine = max(1, (int)round($width * 1.3));
        $lines = $text === '' ? 1 : max(1, (int)ceil($len / $charsPerLine));
        $lines += substr_count($text, "\n");
        return max(10, $lines * 9 + 2);
    };

    $rows = [];
    $merges = [];
    $heights = [];

    /* ── Title (rows 1-2) ── */
    $rows[] = [$S('School Form 2 (SF2) Daily Attendance Report of Learners', XLSX_ST_TITLE)];
    $merges[] = [1, 1, 1, $fullCols];
    $rows[] = [$S('(This replaces Form 1, Form 2 & STS Form 4 - Absenteeism and Dropout Profile)', XLSX_ST_SUBTITLE)];
    $merges[] = [2, 1, 2, $fullCols];

    /* ── Meta bar (rows 3-4) — mirrors SF2_Template.xls r3-r4 ──
       The template splits its width into fixed bands. Bands that sit over the
       day region (cols 3..2+totalDays) are scaled proportionally to the number
       of day columns; the No./NAME columns (1-2) stay fixed. */
    $dayBands = function ($lo, $hi) use ($totalDays) {
        $c1 = 3 + (int)round($lo * $totalDays);
        $c2 = 3 + (int)round($hi * $totalDays) - 1;
        return $c2 >= $c1 ? [$c1, $c2] : [$c1, $c1];
    };
    // r3 bands (day-region fractions)
    list($idV, $idE)  = $dayBands(0.00, 0.12);   // School ID value
    list($syL, $syE)  = $dayBands(0.12, 0.20);   // School Year label
    list($syV, $syVE) = $dayBands(0.20, 0.36);   // School Year value
    list($rmL, $rmE)  = $dayBands(0.36, 0.56);   // Report for the Month of
    list($rmV, $rmVE) = $dayBands(0.56, 0.84);   // month value
    // r4 bands (day-region fractions)
    list($nsV, $nsE)  = $dayBands(0.00, 0.40);   // Name of School value
    list($glL, $glE)  = $dayBands(0.40, 0.60);   // Grade Level label
    list($glV, $glVE) = $dayBands(0.60, 0.88);   // grade value
    list($seL, $seE)  = $dayBands(0.88, 1.00);   // Section label

    $rows[] = $padRow([
        0 => $S('School ID', XLSX_ST_META_LABEL),
        $idV - 1 => $S($schoolId !== '' ? $schoolId : '_______________', XLSX_ST_META_VALUE),
        $syL - 1 => $S('School Year', XLSX_ST_META_LABEL),
        $syV - 1 => $S(sf2SchoolYearLabel($schoolYear), XLSX_ST_META_VALUE),
        $rmL - 1 => $S('Report for the Month of', XLSX_ST_META_LABEL),
        $rmV - 1 => $S($monthLabel, XLSX_ST_META_VALUE),
    ], $fullCols);
    $merges[] = [3, 1, 3, 2];
    $merges[] = [3, $idV, 3, $idE];
    $merges[] = [3, $syL, 3, $syE];
    $merges[] = [3, $syV, 3, $syVE];
    $merges[] = [3, $rmL, 3, $rmE];
    $merges[] = [3, $rmV, 3, $rmVE];

    $rows[] = $padRow([
        0 => $S('Name of School', XLSX_ST_META_LABEL),
        $nsV - 1 => $S($schoolName, XLSX_ST_META_VALUE),
        $glL - 1 => $S('Grade Level', XLSX_ST_META_LABEL),
        $glV - 1 => $S(sf2GradeLabel($gradeLevel), XLSX_ST_META_VALUE),
        $seL - 1 => $S('Section', XLSX_ST_META_LABEL),
        $absCol - 1 => $S(sf2Upper($section), XLSX_ST_META_VALUE),
    ], $fullCols);
    $merges[] = [4, 1, 4, 2];
    $merges[] = [4, $nsV, 4, $nsE];
    $merges[] = [4, $glL, 4, $glE];
    $merges[] = [4, $glV, 4, $glVE];
    $merges[] = [4, $seL, 4, $seE];
    $merges[] = [4, $absCol, 4, $remCol];

    /* ── Header block (rows 5-7) ── */
    $h1 = [];
    $h1[0] = $S('No.', XLSX_ST_HDR);
    $h1[1] = $S('NAME (Last Name, First Name, Middle Name)', XLSX_ST_HDR);
    $h1[2] = $S('(1st row for date)', XLSX_ST_HDR);
    $h1[$absCol - 1] = $S('Total for the Month', XLSX_ST_HDR_TOTAL);
    $h1[$remCol - 1] = $S('REMARKS (If DROPPED OUT, state reason, please refer to legend number 2. If TRANSFERRED IN/OUT, write the name of School.)', XLSX_ST_HDR_TINY);
    $rows[] = $padRow($h1, $fullCols);
    $merges[] = [5, 1, 7, 1];
    $merges[] = [5, 2, 7, 2];
    $merges[] = [5, 3, 5, 2 + $totalDays];
    $merges[] = [5, $absCol, 6, $preCol + 1];
    $merges[] = [5, $remCol, 7, $fullCols];

    /* r6: empty week-band row (template r6) — one merged band per week */
    $h2 = [];
    $ci = 2;
    foreach ($weekList as $week) {
        $wc1 = $ci + 1;
        $h2[$wc1 - 1] = $S('', XLSX_ST_DAY_HDR);
        foreach ($week as $day) $ci++;
        $merges[] = [6, $wc1, 6, $ci];
    }
    $rows[] = $padRow($h2, $fullCols);

    $dowShort = ['1' => 'M', '2' => 'T', '3' => 'W', '4' => 'TH', '5' => 'F'];
    $h3 = [];
    $ci = 2;
    foreach ($weekList as $week) {
        foreach ($week as $day) {
            $h3[$ci] = $S($day === '' ? '' : ($dowShort[date('N', strtotime($day))] ?? ''), XLSX_ST_DAY_HDR);
            $ci++;
        }
    }
    $h3[$absCol - 1] = $S('ABSENT', XLSX_ST_HDR_TINY);
    $h3[$preCol - 1] = $S('PRESENT', XLSX_ST_HDR_TINY);
    $h3[$remCol - 1] = $S('', XLSX_ST_HDR_TINY);
    $rows[] = $padRow($h3, $fullCols);
    $merges[] = [7, $absCol, 7, $absCol + 1];
    $merges[] = [7, $preCol, 7, $preCol + 1];

    /* ── Student blocks + per-day totals ── */
    $num = 0;
    $buildBlock = function ($label, $count, $block, $totalRowStyle, $presentOverride = null) use ($weekList, $S, $padRow, $fullCols, $absCol, $preCol, $remCol, $schoolDaysCount, &$num) {
        $out = [];
        foreach ($block['rows'] as $r) {
            $num++;
            $cells = [];
            $cells[0] = $S($num . '.', XLSX_ST_NO);
            $cells[1] = $S(sf2StudentName($r['student']), XLSX_ST_NAME);
            $ci = 2;
            foreach ($weekList as $week) {
                foreach ($week as $day) {
                    if ($day === '') {
                        $cells[$ci] = $S('', XLSX_ST_DAY);
                    } else {
                        $cell = $r['cells'][$day] ?? ['display' => '', 'cls' => ''];
                        $cells[$ci] = $S($cell['display'], XLSX_ST_DAY);
                    }
                    $ci++;
                }
            }
            $cells[$absCol - 1] = $S((string)(int)$r['absent'], XLSX_ST_COUNT);
            $cells[$preCol - 1] = $S((string)max(0, $schoolDaysCount - (int)$r['absent']), XLSX_ST_COUNT);
            $cells[$remCol - 1] = $S($r['remarks'], XLSX_ST_REMARKS);
            $out[] = $padRow($cells, $fullCols);
        }
        $tot = [];
        $tot[0] = $S($count . '.', $totalRowStyle);
        $tot[1] = $S($label, $totalRowStyle);
        $ci = 2;
        foreach ($weekList as $week) {
            foreach ($week as $day) {
                $tot[$ci] = $S($day !== '' ? (string)($block['attended'][$day] ?? 0) : '', $totalRowStyle);
                $ci++;
            }
        }
        $tot[$absCol - 1] = $S((string)$block['absent'], $totalRowStyle);
        $tot[$preCol - 1] = $S((string)($presentOverride !== null ? $presentOverride : max(0, count($block['rows']) * $schoolDaysCount - $block['absent'])), $totalRowStyle);
        $tot[$remCol - 1] = $S('', $totalRowStyle);
        $out[] = $padRow($tot, $fullCols);
        return $out;
    };

    foreach ($buildBlock('<=== MALE | TOTAL Per Day ===>', $maleCount, $maleBlock, XLSX_ST_SUBTOTAL) as $r) { $rows[] = $r; }
    foreach ($buildBlock('<=== FEMALE | TOTAL Per Day ===>', $femaleCount, $femaleBlock, XLSX_ST_SUBTOTAL) as $r) { $rows[] = $r; }
    foreach ($buildBlock('Combined TOTAL Per Day', $registeredLearners, [
        'rows' => [],
        'attended' => $combinedAttended,
        'absent' => $combinedAbsent,
        'tardy' => $combinedTardy,
    ], XLSX_ST_COMBINED, $combinedPresent) as $r) { $rows[] = $r; }

    /* ── Bottom panel: three vertical bands interleaved on shared rows,
          replicating SF2_Template.xls rows 52-84 exactly (33 rows):
          left    = guidelines  (cols 1..g2)
          middle  = codes + NLS reasons (cols c1..c2)
          right   = summary + signatures (cols absCol..fullCols) —
                    label band absCol..absCol+4, value band absCol+5..absCol+8
                    with M / F / TOTAL sub-columns (AM:AN=ABSENT, AO:AP=PRESENT,
                    AQ:AU=REMARKS in the template header). */
    for ($i = 7; $i < count($rows); $i++) $heights[$i] = 20;   // student rows: 400 twips = 20pt

    $bp0 = count($rows);            // 0-based index of first bottom row
    for ($i = 0; $i < 33; $i++) $rows[] = $padRow([], $fullCols);

    $RLB1 = $absCol;                // right label band first col (AM)
    $RLB2 = $absCol + 4;            // right label band last col (AQ)
    $VM   = $absCol + 5;            // M value col (AR)
    $VF   = $absCol + 6;            // F value col (AS)
    $VTT1 = $absCol + 7;            // TOTAL value col start (AT)
    $VTT2 = $absCol + 8;            // TOTAL value col end (AU)

    $rl = $sumWidth($absCol - 1, $absCol + 3);   // label band width
    $rv = $sumWidth($absCol + 4, $absCol + 7);   // value band width

    /* left-panel fraction columns (scaled to the guidelines band) */
    $frac1 = 7;                     // numerator / denominator start
    $frac2 = $g2 - 2;               // numerator / denominator end
    $fx1   = $g2 - 1;               // 'x 100' start
    $fx2   = $g2;                   // 'x 100' end

    $put = function ($text, $style, $r1, $r2, $from, $to, $width) use (&$rows, &$merges, &$heights, $S, $estHeight, $bp0) {
        $rows[$bp0 + $r1 - 1][$from - 1] = $S($text, $style);
        if ($r2 > $r1 || $to > $from) $merges[] = [$bp0 + $r1, $from, $bp0 + $r2, $to];
        $need = $estHeight($text, $width);
        $per  = max(1, (int)ceil($need / ($r2 - $r1 + 1)));
        for ($i = $r1; $i <= $r2; $i++) {
            $heights[$bp0 + $i - 1] = max($heights[$bp0 + $i - 1] ?? 0, $per);
        }
    };

    /* Row 1  (template r52) */
    $put('GUIDELINES:', XLSX_ST_BTITLE, 1, 1, 1, $g2, $gw);
    $put('1. CODES FOR CHECKING ATTENDANCE', XLSX_ST_BTITLE, 1, 1, $c1, $c2, $cw);
    $put('Month :', XLSX_ST_SUM_LABEL, 1, 2, $RLB1, $RLB1 + 2, $sumWidth($absCol - 1, $absCol + 1));
    $put('No. of Days of Classes:', XLSX_ST_SUM_LABEL, 1, 2, $RLB1 + 3, $RLB1 + 4, $sumWidth($absCol + 2, $absCol + 3));
    $put('Summary', XLSX_ST_SUM_HEAD, 1, 1, $VM, $VTT2, $rv);

    /* Row 2  (template r53) */
    $put("1. The attendance shall be accomplished daily. Refer to the codes for checking learners' attendance.\n2. Dates shall be written in the columns after Learner's Name.\n3. To compute the following:", XLSX_ST_TEXT, 2, 5, 1, $g2, $gw);
    $put('(blank) - Present; (x)- Absent; Tardy (half shaded= Upper for Late Commer, Lower for Cutting Classes)', XLSX_ST_TEXT_SMALL, 2, 3, $c1, $c2, $cw);
    $put('M', XLSX_ST_SUM_HEAD, 2, 2, $VM, $VM, $rv);
    $put('F', XLSX_ST_SUM_HEAD, 2, 2, $VF, $VF, $rv);
    $put('TOTAL', XLSX_ST_SUM_HEAD, 2, 2, $VTT1, $VTT2, $rv);

    /* Row 3  (template r54) */
    $put('* Enrolment as of (1st Friday of June)', XLSX_ST_SUM_LABEL, 3, 4, $RLB1, $RLB2, $rl);
    $put((string)$maleCount, XLSX_ST_SUM_VALUE, 3, 4, $VM, $VM, $rv);
    $put((string)$femaleCount, XLSX_ST_SUM_VALUE, 3, 4, $VF, $VF, $rv);
    $put((string)$registeredLearners, XLSX_ST_SUM_VALUE, 3, 4, $VTT1, $VTT2, $rv);

    /* Row 4  (template r55) */
    $put('2. REASONS/CAUSES FOR NLS', XLSX_ST_BTITLE, 4, 5, $c1, $c2, $cw);

    /* Row 5  (template r56) */
    $put('Late enrolment ', XLSX_ST_SUM_LABEL, 5, 6, $RLB1, $RLB1 + 2, $sumWidth($absCol - 1, $absCol + 1));
    $put('during the month', XLSX_ST_SUM_LABEL, 5, 6, $RLB1 + 3, $RLB1 + 4, $sumWidth($absCol + 2, $absCol + 3));
    $put('0', XLSX_ST_SUM_VALUE, 5, 8, $VM, $VM, $rv);
    $put('0', XLSX_ST_SUM_VALUE, 5, 8, $VF, $VF, $rv);
    $put('0', XLSX_ST_SUM_VALUE, 5, 8, $VTT1, $VTT2, $rv);

    /* Row 6  (template r57) */
    $put('a. Percentage of Enrolment =', XLSX_ST_TEXT, 6, 8, 2, 6, $sumWidth(1, 5));
    $put('Registered Learners as of end of the month', XLSX_ST_TEXT, 6, 7, $frac1, $frac2, $sumWidth($frac1 - 1, $frac2 - 1));
    $put('x 100', XLSX_ST_TEXT, 6, 8, $fx1, $fx2, $sumWidth($fx1 - 1, $fx2 - 1));
    $put('a. Domestic-Related Factors', XLSX_ST_TEXT_SMALL, 6, 7, $c1, $c2, $cw);

    /* Row 7  (template r58) */
    $put('(beyond cut-off)', XLSX_ST_SUM_LABEL, 7, 8, $RLB1, $RLB2, $rl);

    /* Row 8  (template r59) */
    $put('Enrolment as of 1st Friday of the school year', XLSX_ST_TEXT, 8, 8, $frac1, $frac2, $sumWidth($frac1 - 1, $frac2 - 1));
    $put("a.1. Had to take care of siblings\na.2. Early marriage/pregnancy\na.3. Parents' attitude toward schooling\na.4. Family problems", XLSX_ST_TEXT_SMALL, 8, 11, $c1, $c2, $cw);

    /* Row 9  (template r60) */
    $put('b. Average Daily Attendance =', XLSX_ST_TEXT, 9, 10, 2, 6, $sumWidth(1, 5));
    $put('Total Daily Attendance', XLSX_ST_TEXT, 9, 9, $frac1, $frac2, $sumWidth($frac1 - 1, $frac2 - 1));
    $put('Registered Learners as of', XLSX_ST_SUM_LABEL, 9, 9, $RLB1, $RLB2, $rl);
    $put((string)$maleCount, XLSX_ST_SUM_VALUE, 9, 10, $VM, $VM, $rv);
    $put((string)$femaleCount, XLSX_ST_SUM_VALUE, 9, 10, $VF, $VF, $rv);
    $put((string)$registeredLearners, XLSX_ST_SUM_VALUE, 9, 10, $VTT1, $VTT2, $rv);

    /* Row 10  (template r61) */
    $put('Number of School Days in reporting month', XLSX_ST_TEXT, 10, 10, $frac1, $frac2, $sumWidth($frac1 - 1, $frac2 - 1));
    $put('end of month', XLSX_ST_SUM_LABEL, 10, 10, $RLB1, $RLB2, $rl);

    /* Row 11  (template r62) */
    $put('c. Percentage of Attendance for the month =', XLSX_ST_TEXT, 11, 12, 2, 6, $sumWidth(1, 5));
    $put('Average daily attendance', XLSX_ST_TEXT, 11, 11, $frac1, $frac2, $sumWidth($frac1 - 1, $frac2 - 1));
    $put('x 100', XLSX_ST_TEXT, 11, 12, $fx1, $fx2, $sumWidth($fx1 - 1, $fx2 - 1));
    $put('Percentage of Enrolment as of', XLSX_ST_SUM_LABEL, 11, 11, $RLB1, $RLB2, $rl);
    $put($malePctEnrol . '%', XLSX_ST_SUM_VALUE, 11, 12, $VM, $VM, $rv);
    $put($femalePctEnrol . '%', XLSX_ST_SUM_VALUE, 11, 12, $VF, $VF, $rv);
    $put($pctEnrol . '%', XLSX_ST_SUM_VALUE, 11, 12, $VTT1, $VTT2, $rv);

    /* Row 12  (template r63) */
    $put('Registered Learners as of end of the month', XLSX_ST_TEXT, 12, 12, $frac1, $frac2, $sumWidth($frac1 - 1, $frac2 - 1));
    $put('b. Individual-Related Factors', XLSX_ST_TEXT_SMALL, 12, 12, $c1, $c2, $cw);
    $put('end of month', XLSX_ST_SUM_LABEL, 12, 12, $RLB1, $RLB2, $rl);

    /* Row 13  (template r64) */
    $put("b.1. Illness\nb.2. Overage\nb.3. Death\nb.4. Drug Abuse\nb.5. Poor academic performance\nb.6. Lack of interest/Distractions\nb.7. Hunger/Malnutrition", XLSX_ST_TEXT_SMALL, 13, 17, $c1, $c2, $cw);
    $put('Average Daily Attendance', XLSX_ST_SUM_LABEL, 13, 14, $RLB1, $RLB2, $rl);
    $put((string)$maleADA, XLSX_ST_SUM_VALUE, 13, 14, $VM, $VM, $rv);
    $put((string)$femaleADA, XLSX_ST_SUM_VALUE, 13, 14, $VF, $VF, $rv);
    $put((string)$ada, XLSX_ST_SUM_VALUE, 13, 14, $VTT1, $VTT2, $rv);

    /* Row 14  (template r65) */
    $put("4. Every end of the month, the class adviser will submit this form to the office of the principal for recording of summary table into School Form 4. Once signed by the principal, this form should be returned to the adviser.\n5. The adviser will provide necessary interventions including but not limited to home visitation to learner/s who were absent for 5 consecutive days and/or those at risk of dropping out.\n6. Attendance performance of learners will be reflected in Form 137 and Form 138 every grading period.", XLSX_ST_TEXT, 14, 17, 1, $g2, $gw);

    /* Row 15  (template r66) */
    $put('Percentage of Attendance for the month', XLSX_ST_SUM_LABEL, 15, 15, $RLB1, $RLB2, $rl);
    $put($malePctAttendance . '%', XLSX_ST_SUM_VALUE, 15, 15, $VM, $VM, $rv);
    $put($femalePctAttendance . '%', XLSX_ST_SUM_VALUE, 15, 15, $VF, $VF, $rv);
    $put($pctAttendance . '%', XLSX_ST_SUM_VALUE, 15, 15, $VTT1, $VTT2, $rv);

    /* Row 16  (template r67) */
    $put('Number of students absent for 5 consecutive days', XLSX_ST_SUM_LABEL, 16, 16, $RLB1, $RLB2, $rl);
    $put((string)$fiveConsecM, XLSX_ST_SUM_VALUE, 16, 16, $VM, $VM, $rv);
    $put((string)$fiveConsecF, XLSX_ST_SUM_VALUE, 16, 16, $VF, $VF, $rv);
    $put((string)$fiveConsec, XLSX_ST_SUM_VALUE, 16, 16, $VTT1, $VTT2, $rv);

    /* Row 17  (template r68) */
    $put('Dropped out', XLSX_ST_SUM_LABEL, 17, 18, $RLB1, $RLB2, $rl);
    $put((string)$droppedOutM, XLSX_ST_SUM_VALUE, 17, 18, $VM, $VM, $rv);
    $put((string)$droppedOutF, XLSX_ST_SUM_VALUE, 17, 18, $VF, $VF, $rv);
    $put((string)$droppedOut, XLSX_ST_SUM_VALUE, 17, 18, $VTT1, $VTT2, $rv);

    /* Row 18  (template r69) */
    $put('*Beginning of School Year cut-off report is every 1st Friday of the School Year', XLSX_ST_TEXT, 18, 21, 1, $g2, $gw);
    $put('c. School-Related Factors', XLSX_ST_TEXT_SMALL, 18, 19, $c1, $c2, $cw);

    /* Row 19  (template r70) */
    $put('Transferred out', XLSX_ST_SUM_LABEL, 19, 20, $RLB1, $RLB2, $rl);
    $put((string)$transferredOutM, XLSX_ST_SUM_VALUE, 19, 20, $VM, $VM, $rv);
    $put((string)$transferredOutF, XLSX_ST_SUM_VALUE, 19, 20, $VF, $VF, $rv);
    $put((string)$transferredOut, XLSX_ST_SUM_VALUE, 19, 20, $VTT1, $VTT2, $rv);

    /* Row 20  (template r71) */
    $put("c.1. Teacher Factor\nc.2. Physical condition of classroom\nc.3. Peer influence", XLSX_ST_TEXT_SMALL, 20, 23, $c1, $c2, $cw);

    /* Row 21  (template r72) */
    $put('Transferred in', XLSX_ST_SUM_LABEL, 21, 22, $RLB1, $RLB2, $rl);
    $put('0', XLSX_ST_SUM_VALUE, 21, 22, $VM, $VM, $rv);
    $put('0', XLSX_ST_SUM_VALUE, 21, 22, $VF, $VF, $rv);
    $put('0', XLSX_ST_SUM_VALUE, 21, 22, $VTT1, $VTT2, $rv);

    /* Rows 22-23: continuation of rows 20-21 merges (template r73-r74) */

    /* Row 24  (template r75) */
    $put('d. Geographic/Environmental', XLSX_ST_TEXT_SMALL, 24, 24, $c1, $c2, $cw);
    $put('I certify that this is a true and correct report.', XLSX_ST_CERTIFY, 24, 25, $RLB1, $VTT2, $sw);

    /* Row 25  (template r76) */
    $put("d.1. Distance between home and school\nd.2. Armed conflict (incl. Tribal wars & clanfeuds)\nd.3. Calamities/Disasters", XLSX_ST_TEXT_SMALL, 25, 26, $c1, $c2, $cw);

    /* Row 26  (template r77) — empty spacer before the signatures */
    $put('', XLSX_ST_DEFAULT, 26, 26, $RLB1 + 1, $VTT2, $sw);

    /* Row 27  (template r78) */
    $put('e. Financial-Related', XLSX_ST_TEXT_SMALL, 27, 27, $c1, $c2, $cw);

    /* Row 28  (template r79) */
    $put('e.1. Child labor, work', XLSX_ST_TEXT_SMALL, 28, 28, $c1, $c2, $cw);
    $put('(Signature of Adviser over Printed Name)', XLSX_ST_SIG_ROLE, 28, 29, $RLB1 + 1, $VTT2, $sw);

    /* Row 29  (template r80) */
    $put('f. Others (Specify)', XLSX_ST_TEXT_SMALL, 29, 30, $c1, $c2, $cw);

    /* Row 30  (template r81) */
    $put('Attested by:', XLSX_ST_CERTIFY, 30, 31, $RLB1, $VTT2, $sw);

    /* Row 31  (template r82) — continuation of Attested by */

    /* Row 32  (template r83) */
    $put($principalName, XLSX_ST_SIG_NAME, 32, 32, $RLB1 + 1, $VTT2 - 1, $sw);

    /* Row 33  (template r84) */
    $put('Generated thru LIS', XLSX_ST_GENERATED, 33, 33, $c1, $c2, $cw);
    $put('(Signature of School Head over Printed Name)', XLSX_ST_SIG_ROLE, 33, 33, $RLB1 + 1, $VTT2, $sw);

    /* Header row heights (twips -> pt): title 600=30, subtitle/meta 400=20,
       1st header row 200=10, date rows 300=15 */
    $heights[0] = 30;
    $heights[1] = 20;
    $heights[2] = 20;
    $heights[3] = 20;
    $heights[4] = 10;
    $heights[5] = 15;
    $heights[6] = 15;

    $sheet = xlsx_sheet_xml_styled($rows, [
        'merges' => $merges,
        'widths' => $widths,
        'heights' => $heights,
        'page_setup' => ['left' => 0.2, 'right' => 0.2, 'top' => 0.2, 'bottom' => 0.2, 'size' => 9, 'landscape' => true, 'fit_to_page' => true],
    ]);
    $filename = 'sf2_' . preg_replace('/[^A-Za-z0-9]+/', '-', $gradeLevel . '-' . $section) . '_' . date('Y-m') . '.xlsx';
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
    $setStmt = $db->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('school_name','school_id')");
    $setRow = $setStmt ? $setStmt->fetchAll() : [];
    $settingsMap = [];
    foreach ($setRow as $r) { $settingsMap[$r['setting_key']] = $r['setting_value']; }
    $schoolName = $settingsMap['school_name'] ?? '';
    $schoolId   = $settingsMap['school_id'] ?? '';
    $rows[] = ['School: ' . ($schoolName !== '' ? $schoolName : '—') . '  |  School ID: ' . ($schoolId !== '' ? $schoolId : '—')];
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

if ($format === 'sf2') {
    $xlsx = xlsx_build_package([['name' => 'School Form 2 (SF2)', 'xml' => $sheet]], true);
} else {
    $xlsx = xlsx_build_package([['name' => 'Attendance Report', 'xml' => $sheet]]);
}

while (ob_get_level() > 0) { ob_end_clean(); }

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($xlsx));
echo $xlsx;
exit;
