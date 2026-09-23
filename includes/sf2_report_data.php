<?php
/* ═══════════════════════════════════════════════════════════════════════════
   SHARED SF2 (School Form 2) DATA PREPARATION
   Used by print_sf2_template.php (print) and export_report.php (Excel).

   Parameters (GET):
     grade_level | section | class_id  — class resolution (class_id optional)
     subject_id                          — restrict attendance to a subject (optional)
     month | year | date_from | date_to — report month resolution

   Exposes the following variables to the caller:
     $gradeLevel, $section, $subjectId, $month, $year, $dateFrom, $dateTo,
     $monthLabel, $schoolName, $schoolYear, $schoolId, $teacherName,
     $schoolDays, $weekList, $totalWeeks, $roster, $males, $females,
     $maleBlock, $femaleBlock, $combinedAttended, $combinedAbsent,
     $combinedTardy, $registeredLearners, $schoolDaysCount,
     $totalDailyAttendance, $ada, $pctAttendance, $enrolFirstFriday

   Also defines helpers: sf2BuildBlock(), sf2StudentName(), sf2Star().
   ═══════════════════════════════════════════════════════════════════════════ */
require_once __DIR__ . '/../config.php';
requireRole(['admin', 'teacher']);

$teacherId = getCurrentUserId();
$db = $db ?? null;
if (!$db) {
    $database = new Database();
    $db = $database->getConnection();
}

/* ── Active page parameters ─────────────────────────────────────────────── */
$gradeLevel = sanitize($_GET['grade_level'] ?? '');
$section    = sanitize($_GET['section']    ?? '');
$classId    = sanitize($_GET['class_id']    ?? '');
$subjectId  = intval($_GET['subject_id']   ?? 0);
$month      = intval($_GET['month']        ?? 0);
$year       = intval($_GET['year']         ?? 0);

/* ── Resolve class (grade_level + section) from class_id when needed ────── */
if (empty($gradeLevel) || empty($section)) {
    if (is_numeric($classId) && (int)$classId > 0) {
        $secStmt = $db->prepare("SELECT id, grade_level, section_name FROM sections WHERE id = ? LIMIT 1");
        $secStmt->execute([(int)$classId]);
        $secRow = $secStmt->fetch();
        if ($secRow) {
            $gradeLevel = $secRow['grade_level'];
            $section    = $secRow['section_name'];
        }
    } elseif (strpos($classId, '-') !== false) {
        $parts = explode('-', $classId, 2);
        $gradeLevel = trim($parts[0]);
        $section    = trim($parts[1]);
    }
}

/* ── Month / Year resolution (SF2 is a monthly form) ────────────────────── */
if ($month < 1 || $month > 12 || $year < 2000) {
    $dateFromParam = sanitize($_GET['date_from'] ?? '');
    $ts = strtotime($dateFromParam ?: 'now');
    $month = (int)date('n', $ts);
    $year  = (int)date('Y', $ts);
}
$dateFrom = date('Y-m-01', mktime(0, 0, 0, $month, 1, $year));
$dateTo   = date('Y-m-t',  mktime(0, 0, 0, $month, 1, $year));
$monthLabel = date('F Y', strtotime($dateFrom));

/* ── School identity from settings ──────────────────────────────────────── */
$settings = [];
try {
    $settingsStmt = $db->query("SELECT setting_key, setting_value FROM settings");
    foreach ($settingsStmt->fetchAll() as $s) {
        $settings[$s['setting_key']] = $s['setting_value'];
    }
} catch (Exception $e) {}
$schoolName = ($settings['school_name'] ?? '') !== '' ? $settings['school_name'] : 'Liceo de Baleno';
$schoolYear = ($settings['school_year'] ?? '') !== '' ? $settings['school_year'] : (date('Y') . '-' . (date('Y') + 1));
$schoolId   = ($settings['school_id'] ?? '') !== '' ? $settings['school_id'] : '403756';

/* ── Teacher (class adviser) ────────────────────────────────────────────── */
$teacherStmt = $db->prepare("SELECT * FROM teachers WHERE user_id = ?");
$teacherStmt->execute([$teacherId]);
$teacher = $teacherStmt->fetch();
$teacherName = '';
if ($teacher) {
    $teacherName = $teacher['first_name'] ?? '';
    if (!empty($teacher['middle_name'])) {
        $teacherName .= ' ' . strtoupper($teacher['middle_name'][0]) . '.';
    }
    $teacherName .= ' ' . ($teacher['last_name'] ?? '');
}
if (empty($teacherName)) $teacherName = 'ANGELYN S. PARRABA';

/* ── School days (Mon-Fri) and weekly grouping for the matrix ───────────── */
$start = new DateTime($dateFrom);
$end   = new DateTime($dateTo);

$schoolDays = [];
for ($d = clone $start; $d <= $end; $d->modify('+1 day')) {
    if ((int)$d->format('N') <= 5) {
        $schoolDays[] = $d->format('Y-m-d');
    }
}

$weeks = [];
for ($d = clone $start; $d <= $end; $d->modify('+1 day')) {
    $dow = (int)$d->format('N');
    if ($dow > 5) continue;
    $wkey = $d->format('o-W');
    if (!isset($weeks[$wkey])) {
        $weeks[$wkey] = ['', '', '', '', ''];
    }
    $weeks[$wkey][$dow - 1] = $d->format('Y-m-d');
}
ksort($weeks);
$weekList   = array_values($weeks);
$totalWeeks = count($weekList);

/* ── Roster (strictly separated male then female, alphabetical) ─────────── */
$rosterSql = "SELECT id, student_id, first_name, middle_name, last_name, name_extension, gender, status
              FROM students
              WHERE grade_level = :grade AND section = :section
              ORDER BY (CASE WHEN gender = 'Male' THEN 0 ELSE 1 END), last_name ASC, first_name ASC";
$rosterStmt = $db->prepare($rosterSql);
$rosterStmt->execute([':grade' => $gradeLevel, ':section' => $section]);
$roster = $rosterStmt->fetchAll();

/* ── Attendance records for the month ───────────────────────────────────── */
$attSql = "SELECT student_id, date, status FROM attendance
           WHERE recorded_by = :tid AND date BETWEEN :df AND :dt";
$attParams = [':tid' => $teacherId, ':df' => $dateFrom, ':dt' => $dateTo];
if ($subjectId > 0) {
    $attSql .= " AND subject_id = :sid";
    $attParams[':sid'] = $subjectId;
}
$attStmt = $db->prepare($attSql);
$attStmt->execute($attParams);
$attRows = $attStmt->fetchAll();

$statusRank = ['absent' => 4, 'late' => 3, 'excused' => 2, 'present' => 1, 'pending' => 0];
$attMap = [];
foreach ($attRows as $r) {
    $sid  = $r['student_id'];
    $date = $r['date'];
    if (!isset($attMap[$sid][$date])) {
        $attMap[$sid][$date] = $r['status'];
    } elseif (($statusRank[$r['status']] ?? 0) > ($statusRank[$attMap[$sid][$date]] ?? 0)) {
        $attMap[$sid][$date] = $r['status'];
    }
}

/* ── Gender-segregated blocks with per-student & per-day stats ──────────── */
if (!function_exists('sf2BuildBlock')) {
    function sf2BuildBlock($students, $attMap, $schoolDays) {
        $attended = array_fill_keys($schoolDays, 0);
        $absent   = array_fill_keys($schoolDays, 0);
        $tardy    = array_fill_keys($schoolDays, 0);
        $rows = [];
        foreach ($students as $st) {
            $id        = (int)$st['id'];
            $absCount  = 0;
            $tardCount = 0;
            $maxRun    = 0;
            $run       = 0;
            $cells = [];
            foreach ($schoolDays as $d) {
                $status = $attMap[$id][$d] ?? null;
                $display = '';
                $cls     = '';
                switch ($status) {
                    case 'absent':
                        $display = 'x';
                        $cls = 'cell-absent';
                        $absCount++;
                        $run++;
                        $absent[$d]++;
                        break;
                    case 'late':
                        $tardCount++;
                        $run = 0;
                        $tardy[$d]++;
                        $attended[$d]++;
                        break;
                    case 'present':
                    case 'excused':
                        $run = 0;
                        $attended[$d]++;
                        break;
                    default:
                        $run = 0;
                }
                if ($run > $maxRun) $maxRun = $run;
                $cells[$d] = ['display' => $display, 'cls' => $cls];
            }
            $remarks = '';
            $stStatus = strtolower(trim((string)($st['status'] ?? 'active')));
            if ($stStatus === 'transferred') {
                $remarks = 'Transferred out';
            } elseif ($stStatus === 'inactive' || $stStatus === 'dropped') {
                $remarks = 'Dropped out';
            } elseif ($stStatus === 'graduated') {
                $remarks = 'Graduated';
            } elseif ($maxRun >= 5) {
                $remarks = '5+ consecutive absences';
            }
            $rows[] = [
                'student' => $st,
                'cells'   => $cells,
                'absent'  => $absCount,
                'tardy'   => $tardCount,
                'maxRun'  => $maxRun,
                'remarks' => $remarks,
            ];
        }
        return [
            'rows'     => $rows,
            'attended' => $attended,
            'absent'   => array_sum($absent),
            'tardy'    => array_sum($tardy),
        ];
    }
}

if (!function_exists('sf2Upper')) {
    function sf2Upper($s) {
        $s = strtoupper((string)$s);
        $map = ['Ñ' => 'Ñ', 'ñ' => 'Ñ', 'É' => 'É', 'é' => 'É', 'È' => 'È', 'è' => 'È',
                'Á' => 'Á', 'á' => 'Á', 'À' => 'À', 'à' => 'À', 'Â' => 'Â', 'â' => 'Â',
                'Í' => 'Í', 'í' => 'Í', 'Ì' => 'Ì', 'ì' => 'Ì', 'Î' => 'Î', 'î' => 'Î',
                'Ó' => 'Ó', 'ó' => 'Ó', 'Ò' => 'Ò', 'ò' => 'Ò', 'Ô' => 'Ô', 'ô' => 'Ô',
                'Ú' => 'Ú', 'ú' => 'Ú', 'Ù' => 'Ù', 'ù' => 'Ù', 'Ü' => 'Ü', 'ü' => 'Ü'];
        return strtr($s, $map);
    }
}

if (!function_exists('sf2StudentName')) {
    function sf2StudentName($st) {
        $last  = strtoupper(trim((string)$st['last_name']));
        $first = strtoupper(trim((string)$st['first_name']));
        $mid   = strtoupper(trim((string)($st['middle_name'] ?? '')));
        $ext   = strtoupper(trim((string)($st['name_extension'] ?? '')));
        $name  = $last . ',' . $first;
        if ($mid !== '' || $ext !== '') {
            $midPart = '';
            if ($ext !== '') $midPart .= $ext . ' ';
            if ($mid !== '' && $mid !== '-') $midPart .= $mid;
            $midPart = rtrim($midPart);
            if ($midPart !== '') $name .= ', ' . $midPart;
        }
        return $name;
    }
}

if (!function_exists('sf2GradeLabel')) {
    function sf2GradeLabel($grade) {
        $grade = trim((string)$grade);
        if ($grade === '' || !is_numeric($grade)) return 'Grade ' . strtoupper($grade);
        $g = (int)$grade;
        if ($g >= 7 && $g <= 12) {
            $years = [0 => '', 7 => 'I', 8 => 'II', 9 => 'III', 10 => 'IV', 11 => 'V', 12 => 'VI'];
            return 'Grade ' . $g . ' (Year ' . $years[$g] . ')';
        }
        return 'Grade ' . $g;
    }
}

if (!function_exists('sf2SchoolYearLabel')) {
    function sf2SchoolYearLabel($sy) {
        $sy = trim((string)$sy);
        return preg_replace('/\s*-\s*/', ' - ', $sy);
    }
}

if (!function_exists('sf2Star')) {
    function sf2Star($cx, $cy, $rOuter = 5.5, $rInner = 2.4) {
        $pts = [];
        for ($i = 0; $i < 10; $i++) {
            $r = ($i % 2 === 0) ? $rOuter : $rInner;
            $ang = deg2rad(-90 + $i * 36);
            $pts[] = round($cx + $r * cos($ang), 2) . ' ' . round($cy + $r * sin($ang), 2);
        }
        return 'M ' . implode(' L ', $pts) . ' Z';
    }
}

$males   = [];
$females = [];
foreach ($roster as $st) {
    if (strcasecmp(trim((string)$st['gender']), 'Male') === 0) {
        $males[] = $st;
    } else {
        $females[] = $st;
    }
}

$maleBlock   = sf2BuildBlock($males, $attMap, $schoolDays);
$femaleBlock = sf2BuildBlock($females, $attMap, $schoolDays);

$combinedAttended = [];
foreach ($schoolDays as $d) {
    $combinedAttended[$d] = ($maleBlock['attended'][$d] ?? 0) + ($femaleBlock['attended'][$d] ?? 0);
}
$combinedAbsent = $maleBlock['absent'] + $femaleBlock['absent'];
$combinedTardy  = $maleBlock['tardy']  + $femaleBlock['tardy'];

/* ── Bottom analytics ───────────────────────────────────────────────────── */
$registeredLearners   = count($roster);
$maleCount            = count($males);
$femaleCount          = count($females);
$schoolDaysCount      = count($schoolDays);
$totalDailyAttendance = array_sum($combinedAttended);
$ada                  = $schoolDaysCount > 0 ? round($totalDailyAttendance / $schoolDaysCount, 2) : 0;
$pctAttendance        = $registeredLearners > 0 ? round(($ada / $registeredLearners) * 100, 2) : 0;

/* ── Male analytics ─────────────────────────────────────────────────────── */
$maleTotalDailyAttendance = array_sum($maleBlock['attended']);
$maleAda                  = $schoolDaysCount > 0 ? round($maleTotalDailyAttendance / $schoolDaysCount, 2) : 0;
$malePctAttendance        = $maleCount > 0 ? round(($maleAda / $maleCount) * 100, 2) : 0;

/* ── Female analytics ───────────────────────────────────────────────────── */
$femaleTotalDailyAttendance = array_sum($femaleBlock['attended']);
$femaleAda                  = $schoolDaysCount > 0 ? round($femaleTotalDailyAttendance / $schoolDaysCount, 2) : 0;
$femalePctAttendance        = $femaleCount > 0 ? round(($femaleAda / $femaleCount) * 100, 2) : 0;

/* ── 5+ Consecutive Absences (separate M/F/TOTAL) ───────────────────────── */
$fiveConsecM = 0;
foreach ($maleBlock['rows'] as $r) {
    if ((int)($r['maxRun'] ?? 0) >= 5) $fiveConsecM++;
}
$fiveConsecF = 0;
foreach ($femaleBlock['rows'] as $r) {
    if ((int)($r['maxRun'] ?? 0) >= 5) $fiveConsecF++;
}
$fiveConsec = $fiveConsecM + $fiveConsecF;

/* ── Enrolment as of 1st Friday of School Year (June) ───────────────────── */
$enrolFirstFriday = $registeredLearners;
$maleEnrolFirstFriday = $maleCount;
$femaleEnrolFirstFriday = $femaleCount;
$firstFridaySY = null;

/* Find 1st Friday of June of the school year */
$syStartYear = (int)explode('-', $schoolYear)[0];
$juneFirst = new DateTime("{$syStartYear}-06-01");
for ($d = clone $juneFirst; $d <= (clone $juneFirst)->modify('+6 days'); $d->modify('+1 day')) {
    if ((int)$d->format('N') === 5) { $firstFridaySY = $d->format('Y-m-d'); break; }
}

if ($firstFridaySY) {
    try {
        $cntStmt = $db->prepare("SELECT COUNT(*) FROM students WHERE grade_level = :g AND section = :s AND DATE(created_at) <= :ff");
        $cntStmt->execute([':g' => $gradeLevel, ':s' => $section, ':ff' => $firstFridaySY]);
        $enrol = (int)$cntStmt->fetchColumn();
        if ($enrol > 0) $enrolFirstFriday = $enrol;

        $cntStmtM = $db->prepare("SELECT COUNT(*) FROM students WHERE grade_level = :g AND section = :s AND gender = 'Male' AND DATE(created_at) <= :ff");
        $cntStmtM->execute([':g' => $gradeLevel, ':s' => $section, ':ff' => $firstFridaySY]);
        $enrolM = (int)$cntStmtM->fetchColumn();
        if ($enrolM > 0) $maleEnrolFirstFriday = $enrolM;

        $cntStmtF = $db->prepare("SELECT COUNT(*) FROM students WHERE grade_level = :g AND section = :s AND gender = 'Female' AND DATE(created_at) <= :ff");
        $cntStmtF->execute([':g' => $gradeLevel, ':s' => $section, ':ff' => $firstFridaySY]);
        $enrolF = (int)$cntStmtF->fetchColumn();
        if ($enrolF > 0) $femaleEnrolFirstFriday = $enrolF;
    } catch (Exception $e) {}
}

/* ── Percentage of Enrolment as of end of month ─────────────────────────── */
$pctEnrol         = $enrolFirstFriday > 0 ? round(($registeredLearners / $enrolFirstFriday) * 100, 2) : 0;
$malePctEnrol     = $maleEnrolFirstFriday > 0 ? round(($maleCount / $maleEnrolFirstFriday) * 100, 2) : 0;
$femalePctEnrol   = $femaleEnrolFirstFriday > 0 ? round(($femaleCount / $femaleEnrolFirstFriday) * 100, 2) : 0;
