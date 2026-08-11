<?php
// Never emit PHP warnings/notices into a binary download (breaks Excel).
ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);
/**
 * export_sf2_excel.php — Official DepEd School Form 2 (SF2) Excel export.
 *
 * Loads the pre-formatted SF2_Template.xls via PhpSpreadsheet, injects live
 * attendance data (meta, roster, per-day marks, totals, bottom summary), then
 * streams an .xlsx replica to the browser while preserving all original fonts,
 * merged cell ranges, borders, and background shading of the template.
 *
 * GET: class_id (grade_level-section) | subject_id | month | year
 *      (grade_level | section also accepted directly)
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/includes/sf2_report_data.php';
requireRole(['admin', 'teacher']);

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

/* ── Parameters (class_id / subject_id / month / year resolved by
      sf2_report_data.php into $gradeLevel, $section, $subjectId, $month,
      $year, $dateFrom, $dateTo, $monthLabel, $roster, $males, $females,
      $maleBlock, $femaleBlock, $combinedAttended, $combinedAbsent,
      $combinedTardy, $registeredLearners, $schoolDays, $schoolDaysCount,
      $ada, $pctAttendance, $settings, $schoolName, $schoolYear, $schoolId) ── */

if (empty($gradeLevel) || empty($section)) {
    http_response_code(400);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!DOCTYPE html><html><head><title>Error - School Form 2</title><style>body{font-family:Segoe UI,Arial,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;background:#f8fafc;color:#1e293b;} .err-box{text-align:center;padding:40px;background:#fff;border-radius:12px;box-shadow:0 4px 24px rgba(0,0,0,0.08);max-width:420px;} .err-box h2{color:#dc2626;margin:0 0 8px;} .err-box p{color:#64748b;margin:0;}</style></head><body><div class="err-box"><h2>Missing Parameters</h2><p>Please select Grade and Section filters, then try again.</p></div></body></html>';
    exit;
}

$templatePath = __DIR__ . '/SF2_Template.xls';
if (!is_file($templatePath)) {
    http_response_code(500);
    die('SF2_Template.xls not found.');
}

try {
    $spreadsheet = IOFactory::load($templatePath);
} catch (Throwable $e) {
    http_response_code(500);
    die('Unable to load SF2_Template.xls: ' . $e->getMessage());
}

$ws = $spreadsheet->getSheetByName('school_form_2_ver2014.2.1.1');
if (!$ws) $ws = $spreadsheet->getActiveSheet();

$setVal = function ($col, $row, $val) use ($ws) {
    $ws->setCellValue(Coordinate::stringFromColumnIndex($col) . $row, $val);
};

/* ── Derived analytics (mirrors teacher/export_report.php) ──────────────── */
$maleCount   = count($males);
$femaleCount = count($females);
$malePresent   = max(0, $maleCount * $schoolDaysCount - $maleBlock['absent']);
$femalePresent = max(0, $femaleCount * $schoolDaysCount - $femaleBlock['absent']);
$combinedPresent = max(0, $registeredLearners * $schoolDaysCount - $combinedAbsent);

$fiveConsec = 0; $droppedOutM = 0; $droppedOutF = 0; $transferredOutM = 0; $transferredOutF = 0;
foreach (array_merge($maleBlock['rows'], $femaleBlock['rows']) as $r) {
    if ((int)($r['maxRun'] ?? 0) >= 5) $fiveConsec++;
    $st = strtolower(trim((string)($r['student']['status'] ?? 'active')));
    $isMale = strcasecmp(trim((string)($r['student']['gender'] ?? '')), 'Male') === 0;
    if (in_array($st, ['inactive', 'dropped'], true)) {
        if ($isMale) $droppedOutM++; else $droppedOutF++;
    } elseif ($st === 'transferred') {
        if ($isMale) $transferredOutM++; else $transferredOutF++;
    }
}
$droppedOut       = $droppedOutM + $droppedOutF;
$transferredOut   = $transferredOutM + $transferredOutF;
$pctEnrol         = $registeredLearners > 0 ? round(($registeredLearners / max(1, $enrolFirstFriday)) * 100, 2) : 0;
$maleADA          = $schoolDaysCount > 0 ? round(array_sum($maleBlock['attended']) / $schoolDaysCount, 2) : 0;
$femaleADA        = $schoolDaysCount > 0 ? round(array_sum($femaleBlock['attended']) / $schoolDaysCount, 2) : 0;

/* ── 1. Dynamic header metadata ───────────────────────────────────────────
   Only overwrite template cells when the live value is non-empty; otherwise
   keep the School ID / Name / Year already printed in the template file
   (Railway's settings table may not be seeded). */
if ($schoolId !== '') {
    $ws->setCellValue('F3', $schoolId);
}
if (trim($schoolYear) !== '') {
    $ws->setCellValue('M3', sf2SchoolYearLabel($schoolYear));
}
$ws->setCellValue('AA3', $monthLabel);                       // Report for the Month of ____
if (trim($schoolName) !== '') {
    $ws->setCellValue('F4', $schoolName);
}
$ws->setCellValue('AA4', sf2GradeLabel($gradeLevel));
$ws->setCellValue('AM4', sf2Upper($section));

/* ── 2. Daily attendance column mapping (template row 7 day headers).
      F..AL holds 25 day slots; several are double-width merges. The first
      column of each slot, in order (1-based), matches M,T,W,TH,F × 5 weeks: ── */
$dayCols = [6, 8, 9, 10, 11, 12, 14, 15, 16, 17, 18, 20, 21, 22, 24, 26, 28, 29, 30, 31, 32, 33, 35, 36, 37];

/* ── 3. Roster placement (template grid rows 8-51; 44 rows for up to
      41 learners: M + FEMALE blocks + 3 total rows).
      male rows 8..7+M | 'MALE | TOTAL Per Day' | female rows |
      'FEMALE | TOTAL Per Day' | 'Combined TOTAL Per Day' ────────────────── */
$firstDataRow = 8;
$lastGridRow  = 51;
$neededRows   = $maleCount + $femaleCount + 3;
if ($neededRows > ($lastGridRow - $firstDataRow + 1)) {
    $insert = $neededRows - ($lastGridRow - $firstDataRow + 1);
    $ws->insertNewRowBefore($lastGridRow + 1, $insert);
    $lastGridRow += $insert;
}

$clearCell = function ($col, $row) use ($ws, $setVal) {
    $setVal($col, $row, '');
};

/* Clear sample names/totals/marks across the whole data grid first. */
for ($r = $firstDataRow; $r <= $lastGridRow; $r++) {
    $clearCell(1, $r);   // No.
    $clearCell(3, $r);   // NAME
    foreach ($dayCols as $c) $clearCell($c, $r);
    $clearCell(39, $r);  // ABSENT (AM:AN)
    $clearCell(41, $r);  // PRESENT (AO:AP)
    $clearCell(43, $r);  // REMARKS (AQ:AU)
}

$writeBlock = function (array $rows, array $attended, $absentTotal, $tardyTotal, $count, $label, $startRow, $presentOverride = null) use ($ws, $setVal, $dayCols, $schoolDays, $schoolDaysCount) {
    $row = $startRow;
    foreach ($rows as $i => $r) {
        $setVal(1, $row, $i + 1);
        $setVal(3, $row, sf2StudentName($r['student']));
        foreach ($schoolDays as $di => $day) {
            if ($di >= count($dayCols)) break;
            $cell = $r['cells'][$day] ?? ['display' => '', 'cls' => ''];
            $setVal($dayCols[$di], $row, $cell['display']);
        }
        $setVal(39, $row, (int)$r['absent']);
        $setVal(41, $row, max(0, $schoolDaysCount - (int)$r['absent']));
        $setVal(43, $row, $r['remarks']);
        $row++;
    }
    /* block total row */
    $setVal(1, $row, $count);
    $setVal(3, $row, $label);
    foreach ($schoolDays as $di => $day) {
        if ($di >= count($dayCols)) break;
        $setVal($dayCols[$di], $row, (int)($attended[$day] ?? 0));
    }
    $setVal(39, $row, (int)$absentTotal);
    $setVal(41, $row, (int)($presentOverride !== null ? $presentOverride : max(0, $count * $schoolDaysCount - $absentTotal)));
    return $row + 1;
};

$row = $firstDataRow;
$row = $writeBlock($maleBlock['rows'], $maleBlock['attended'], $maleBlock['absent'], $maleBlock['tardy'], $maleCount, 'MALE | TOTAL Per Day', $row);
$row = $writeBlock($femaleBlock['rows'], $femaleBlock['attended'], $femaleBlock['absent'], $femaleBlock['tardy'], $femaleCount, 'FEMALE | TOTAL Per Day', $row);
$combinedRow = $writeBlock([], $combinedAttended, $combinedAbsent, $combinedTardy, $registeredLearners, 'Combined TOTAL Per Day', $row, $combinedPresent);

/* Hide unused grid rows (keep the template's borders but not printable). */
for ($r = $combinedRow; $r <= $lastGridRow; $r++) {
    $ws->getRowDimension($r)->setVisible(false);
}

/* ── 5. Bottom summary (template rows 52-84; values already aligned) ────── */
$ws->setCellValue('AR54', $maleCount);           // Enrolment as of (1st Friday)
$ws->setCellValue('AS54', $femaleCount);
$ws->setCellValue('AT54', $registeredLearners);
$ws->setCellValue('AR56', 0);                    // Late enrolment during the month
$ws->setCellValue('AS56', 0);
$ws->setCellValue('AT56', 0);
$ws->setCellValue('AR60', $maleCount);           // Registered Learners as of end of month
$ws->setCellValue('AS60', $femaleCount);
$ws->setCellValue('AT60', $registeredLearners);
$ws->setCellValue('AT62', $pctEnrol . '%');      // Percentage of Enrolment as of end of month
$ws->setCellValue('AR64', $maleADA);             // Average Daily Attendance
$ws->setCellValue('AS64', $femaleADA);
$ws->setCellValue('AT64', $ada);
$ws->setCellValue('AT66', $pctAttendance . '%'); // Percentage of Attendance for the month
$ws->setCellValue('AT67', $fiveConsec);          // Number of students absent for 5 consecutive days
$ws->setCellValue('AR68', $droppedOutM);         // Dropped out
$ws->setCellValue('AS68', $droppedOutF);
$ws->setCellValue('AT68', $droppedOut);
$ws->setCellValue('AR70', $transferredOutM);     // Transferred out
$ws->setCellValue('AS70', $transferredOutF);
$ws->setCellValue('AT70', $transferredOut);
$ws->setCellValue('AR72', 0);                    // Transferred in
$ws->setCellValue('AS72', 0);
$ws->setCellValue('AT72', 0);

/* ── 6. Force browser download ──────────────────────────────────────────── */
$filename = 'SF2_Daily_Attendance_'
    . preg_replace('/[^A-Za-z0-9]+/', '_', $section) . '_'
    . date('F_Y', strtotime($dateFrom)) . '.xlsx';

$writer = IOFactory::createWriter($spreadsheet, 'Xlsx');

while (ob_get_level() > 0) { ob_end_clean(); }

if (PHP_SAPI === 'cli') {
    $out = getenv('SF2_OUTPUT') ?: 'php://output';
    $writer->save($out);
    exit;
}

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');
$writer->save('php://output');
exit;
