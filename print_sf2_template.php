<?php
/* ═══════════════════════════════════════════════════════════════════════════
   SCHOOL FORM 2 (SF2) — DAILY ATTENDANCE REPORT OF LEARNERS
   HTML replica matching the SF2_Template.xls layout & appearance.
   A4 landscape, tight margins, small fonts (like the spreadsheet).

   Usage:
     print_sf2_template.php?class_id=11-STEM-A&month=8&year=2026
     print_sf2_template.php?grade_level=11&section=STEM-A&subject_id=5&month=8&year=2026
   ═══════════════════════════════════════════════════════════════════════════ */
require_once __DIR__ . '/includes/sf2_report_data.php';

if (empty($gradeLevel) || empty($section)) {
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!DOCTYPE html><html><head><title>Error - School Form 2</title><style>body{font-family:Segoe UI,Arial,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;background:#f8fafc;color:#1e293b;} .err-box{text-align:center;padding:40px;background:#fff;border-radius:12px;box-shadow:0 4px 24px rgba(0,0,0,0.08);max-width:420px;} .err-box h2{color:#dc2626;margin:0 0 8px;} .err-box p{color:#64748b;margin:0;}</style></head><body><div class="err-box"><h2>Missing Parameters</h2><p>Please select Grade and Section filters, then try again.</p></div></body></html>';
    exit;
}

$totalDays   = $totalWeeks * 5;
$maleCount   = count($males);
$femaleCount = count($females);
$dayWidth    = 52.99 / max(1, $totalDays);

$malePresent    = ($maleCount * $schoolDaysCount) - $maleBlock['absent'] - $maleBlock['tardy'];
$femalePresent  = ($femaleCount * $schoolDaysCount) - $femaleBlock['absent'] - $femaleBlock['tardy'];
$combinedPresent = ($registeredLearners * $schoolDaysCount) - $combinedAbsent - $combinedTardy;

$fiveConsec = 0; $droppedOutM = 0; $droppedOutF = 0; $transferredOutM = 0; $transferredOutF = 0; $transferredIn = 0;
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
$droppedOut = $droppedOutM + $droppedOutF;
$transferredOut = $transferredOutM + $transferredOutF;
$pctEnrol = $registeredLearners > 0 ? round(($registeredLearners / max(1, $enrolFirstFriday)) * 100, 2) : 0;
$principalName = $settings['principal_name'] ?? 'MYRNA MINGOY BARRUN';

$dowShort = ['1' => 'M', '2' => 'T', '3' => 'W', '4' => 'TH', '5' => 'F'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>School Form 2 (SF2) Daily Attendance Report of Learners</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/sf2_print.css">
</head>
<body>
    <div class="sf2-screen-actions sf2-no-print">
        <p style="margin:0 0 8px;font-size:12px;color:#334155;"><strong>SF2 &mdash; Daily Attendance Report of Learners</strong> &bull; <?= sanitize(sf2GradeLabel($gradeLevel)) ?> - <?= sanitize($section) ?> &bull; <?= sanitize($monthLabel) ?></p>
        <button type="button" onclick="window.print()" style="padding:8px 22px;font-size:13px;font-weight:700;background:#0a3d62;color:#fff;border:none;border-radius:6px;cursor:pointer;">Print SF2</button>
    </div>

    <div class="sf2-sheet">
        <!-- ═════════════════════════════ TITLE ═════════════════════════════ -->
        <div class="sf2-title">School Form 2 (SF2) Daily Attendance Report of Learners</div>
        <div class="sf2-title-sub">(This replaces Form 1, Form 2 &amp; STS Form 4 - Absenteeism and Dropout Profile)</div>

        <!-- ═════════════════════════════ META ═════════════════════════════ -->
        <table class="sf2-meta">
            <tr>
                <td class="sf2-meta-label">School ID</td>
                <td class="sf2-meta-value"><?= sanitize($schoolId !== '' ? $schoolId : '_______________') ?></td>
                <td class="sf2-meta-label">School Year</td>
                <td class="sf2-meta-value"><?= sanitize(sf2SchoolYearLabel($schoolYear)) ?></td>
                <td class="sf2-meta-label">Report for the Month of</td>
                <td class="sf2-meta-value"><?= sanitize($monthLabel) ?></td>
            </tr>
            <tr>
                <td class="sf2-meta-label">Name of School</td>
                <td class="sf2-meta-value"><?= sanitize($schoolName) ?></td>
                <td class="sf2-meta-label">Grade Level</td>
                <td class="sf2-meta-value"><?= sanitize(sf2GradeLabel($gradeLevel)) ?></td>
                <td class="sf2-meta-label">Section</td>
                <td class="sf2-meta-value"><?= sanitize(sf2Upper($section)) ?></td>
            </tr>
        </table>

        <!-- ═══════════════════════════ ATTENDANCE MATRIX ═══════════════════════════ -->
        <table class="sf2-matrix">
            <colgroup>
                <col style="width:2.24%">
                <col style="width:17.08%">
                <?php for ($i = 0; $i < $totalDays; $i++): ?><col style="width:<?= $dayWidth ?>%"><?php endfor; ?>
                <col style="width:4.99%">
                <col style="width:4.99%">
                <col style="width:17.71%">
            </colgroup>
            <thead>
                <tr>
                    <th rowspan="3" class="sf2-h-no">No.</th>
                    <th rowspan="3" class="sf2-h-name">NAME<br>(Last Name, First Name, Middle Name)</th>
                    <th colspan="<?= $totalDays ?>" class="sf2-h-date1">(1st row for date)</th>
                    <th rowspan="2" colspan="2" class="sf2-h-total">Total for the Month</th>
                    <th rowspan="3" class="sf2-h-remarks">REMARKS (If DROPPED OUT, state reason, please refer to legend number 2. If TRANSFERRED IN/OUT, write the name of School.)</th>
                </tr>
                <tr>
                    <td colspan="<?= $totalDays ?>" class="sf2-h-date2">&nbsp;</td>
                </tr>
                <tr>
                    <?php foreach ($weekList as $week): foreach ($week as $day): ?>
                        <?php if ($day === ''): ?>
                        <th class="sf2-h-day"></th>
                        <?php else: ?>
                        <th class="sf2-h-day"><?= $dowShort[date('N', strtotime($day))] ?? '' ?></th>
                        <?php endif; ?>
                    <?php endforeach; endforeach; ?>
                    <th class="sf2-h-count">ABSENT</th>
                    <th class="sf2-h-count">PRESENT</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($registeredLearners === 0): ?>
                <tr>
                    <td class="sf2-td-remarks" colspan="<?= 2 + $totalDays + 3 ?>" style="text-align:center;padding:10px;">No learners found for Grade <?= sanitize($gradeLevel) ?> - <?= sanitize($section) ?>.</td>
                </tr>
                <?php else: ?>

                <?php $num = 0; ?>
                <?php foreach ($maleBlock['rows'] as $row): $num++; ?>
                <tr>
                    <td class="sf2-td-no"><?= $num ?>.</td>
                    <td class="sf2-td-name"><?= sanitize(sf2StudentName($row['student'])) ?></td>
                    <?php foreach ($weekList as $week): foreach ($week as $day): ?>
                        <?php if ($day === ''): ?>
                        <td class="sf2-td-day"></td>
                        <?php else: $cell = $row['cells'][$day] ?? ['display' => '', 'cls' => '']; ?>
                        <td class="sf2-td-day <?= $cell['cls'] ?>"><?= $cell['display'] ?></td>
                        <?php endif; ?>
                    <?php endforeach; endforeach; ?>
                    <td class="sf2-td-count"><?= (int)$row['absent'] ?></td>
                    <td class="sf2-td-count"><?= max(0, $schoolDaysCount - (int)$row['absent'] - (int)$row['tardy']) ?></td>
                    <td class="sf2-td-remarks"><?= sanitize($row['remarks']) ?></td>
                </tr>
                <?php endforeach; ?>
                <tr class="sf2-subtotal">
                    <td class="sf2-td-no"><?= $maleCount ?>.</td>
                    <td class="sf2-td-name">&lt;=== MALE | TOTAL Per Day ===&gt;</td>
                    <?php foreach ($weekList as $week): foreach ($week as $day): ?>
                    <td class="sf2-td-count"><?= $day !== '' ? (int)($maleBlock['attended'][$day] ?? 0) : '' ?></td>
                    <?php endforeach; endforeach; ?>
                    <td class="sf2-td-count"><?= (int)$maleBlock['absent'] ?></td>
                    <td class="sf2-td-count"><?= max(0, $malePresent) ?></td>
                    <td class="sf2-td-remarks"></td>
                </tr>

                <?php $num = 0; ?>
                <?php foreach ($femaleBlock['rows'] as $row): $num++; ?>
                <tr>
                    <td class="sf2-td-no"><?= $num ?>.</td>
                    <td class="sf2-td-name"><?= sanitize(sf2StudentName($row['student'])) ?></td>
                    <?php foreach ($weekList as $week): foreach ($week as $day): ?>
                        <?php if ($day === ''): ?>
                        <td class="sf2-td-day"></td>
                        <?php else: $cell = $row['cells'][$day] ?? ['display' => '', 'cls' => '']; ?>
                        <td class="sf2-td-day <?= $cell['cls'] ?>"><?= $cell['display'] ?></td>
                        <?php endif; ?>
                    <?php endforeach; endforeach; ?>
                    <td class="sf2-td-count"><?= (int)$row['absent'] ?></td>
                    <td class="sf2-td-count"><?= max(0, $schoolDaysCount - (int)$row['absent'] - (int)$row['tardy']) ?></td>
                    <td class="sf2-td-remarks"><?= sanitize($row['remarks']) ?></td>
                </tr>
                <?php endforeach; ?>
                <tr class="sf2-subtotal">
                    <td class="sf2-td-no"><?= $femaleCount ?>.</td>
                    <td class="sf2-td-name">&lt;=== FEMALE | TOTAL Per Day ===&gt;</td>
                    <?php foreach ($weekList as $week): foreach ($week as $day): ?>
                    <td class="sf2-td-count"><?= $day !== '' ? (int)($femaleBlock['attended'][$day] ?? 0) : '' ?></td>
                    <?php endforeach; endforeach; ?>
                    <td class="sf2-td-count"><?= (int)$femaleBlock['absent'] ?></td>
                    <td class="sf2-td-count"><?= max(0, $femalePresent) ?></td>
                    <td class="sf2-td-remarks"></td>
                </tr>

                <tr class="sf2-combined">
                    <td class="sf2-td-no"><?= $registeredLearners ?>.</td>
                    <td class="sf2-td-name">Combined TOTAL Per Day</td>
                    <?php foreach ($weekList as $week): foreach ($week as $day): ?>
                    <td class="sf2-td-count"><?= $day !== '' ? (int)($combinedAttended[$day] ?? 0) : '' ?></td>
                    <?php endforeach; endforeach; ?>
                    <td class="sf2-td-count"><?= (int)$combinedAbsent ?></td>
                    <td class="sf2-td-count"><?= max(0, $combinedPresent) ?></td>
                    <td class="sf2-td-remarks"></td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>

        <!-- ═══════════════════════════ BOTTOM PANEL ═══════════════════════════ -->
        <div class="sf2-bottom">
            <!-- Guidelines -->
            <div class="sf2-guidelines">
                <div class="sf2-bottom-title">GUIDELINES:</div>
                <ol class="sf2-guidelines-list">
                    <li>The attendance shall be accomplished daily. Refer to the codes for checking learners' attendance.</li>
                    <li>Dates shall be written in the columns after Learner's Name.</li>
                    <li>To compute the following:
                        <ul class="sf2-formulas">
                            <li><b>a. Percentage of Enrolment</b> = <span class="frac"><i>Registered Learners as of end of the month</i></span> &times; 100<br><span class="frac-denom">Enrolment as of 1st Friday of the school year</span></li>
                            <li><b>b. Average Daily Attendance</b> = <span class="frac"><i>Total Daily Attendance</i></span><br><span class="frac-denom">Number of School Days in reporting month</span></li>
                            <li><b>c. Percentage of Attendance for the month</b> = <span class="frac"><i>Average daily attendance</i></span> &times; 100<br><span class="frac-denom">Registered Learners as of end of the month</span></li>
                        </ul>
                    </li>
                    <li>Every end of the month, the class adviser will submit this form to the office of the principal for recording of summary table into School Form 4. Once signed by the principal, this form should be returned to the adviser.</li>
                    <li>The adviser will provide necessary interventions including but not limited to home visitation to learner/s who were absent for 5 consecutive days and/or those at risk of dropping out.</li>
                    <li>Attendance performance of learners will be reflected in Form 137 and Form 138 every grading period.</li>
                </ol>
                <div class="sf2-cutoff">*Beginning of School Year cut-off report is every 1st Friday of the School Year</div>
            </div>

            <!-- Codes + Reasons for NLS -->
            <div class="sf2-codes-panel">
                <div class="sf2-bottom-title">1. CODES FOR CHECKING ATTENDANCE</div>
                <div class="sf2-codes-text">(blank) - Present; (x)- Absent; Tardy (half shaded = Upper for Late Commer, Lower for Cutting Classes)</div>

                <div class="sf2-bottom-title">2. REASONS/CAUSES FOR NLS</div>
                <div class="sf2-reason-cat">a. Domestic-Related Factors</div>
                <div class="sf2-reason-items">a.1. Had to take care of siblings<br>a.2. Early marriage/pregnancy<br>a.3. Parents' attitude toward schooling<br>a.4. Family problems</div>
                <div class="sf2-reason-cat">b. Individual-Related Factors</div>
                <div class="sf2-reason-items">b.1. Illness<br>b.2. Overage<br>b.3. Death<br>b.4. Drug Abuse<br>b.5. Poor academic performance<br>b.6. Lack of interest/Distractions<br>b.7. Hunger/Malnutrition</div>
                <div class="sf2-reason-cat">c. School-Related Factors</div>
                <div class="sf2-reason-items">c.1. Teacher Factor<br>c.2. Physical condition of classroom<br>c.3. Peer influence</div>
                <div class="sf2-reason-cat">d. Geographic/Environmental</div>
                <div class="sf2-reason-items">d.1. Distance between home and school<br>d.2. Armed conflict (incl. Tribal wars &amp; clanfeuds)<br>d.3. Calamities/Disasters</div>
                <div class="sf2-reason-cat">e. Financial-Related</div>
                <div class="sf2-reason-items">e.1. Child labor, work</div>
                <div class="sf2-reason-cat">f. Others (Specify)</div>
            </div>

            <!-- Summary + Signatures -->
            <div class="sf2-summary-panel">
                <table class="sf2-summary">
                    <tr>
                        <td class="sf2-sum-label" colspan="3">Month :</td>
                        <td class="sf2-sum-value"><?= sanitize($monthLabel) ?></td>
                        <td class="sf2-sum-label" colspan="3">No. of Days of Classes:</td>
                        <td class="sf2-sum-value"><?= (int)$schoolDaysCount ?></td>
                    </tr>
                    <tr>
                        <td class="sf2-sum-head" colspan="5">Summary</td>
                        <td class="sf2-sum-head">M</td>
                        <td class="sf2-sum-head">F</td>
                        <td class="sf2-sum-head">TOTAL</td>
                    </tr>
                    <tr>
                        <td class="sf2-sum-label" colspan="5">* Enrolment as of (1st Friday of June)</td>
                        <td class="sf2-sum-value"><?= $maleCount ?></td>
                        <td class="sf2-sum-value"><?= $femaleCount ?></td>
                        <td class="sf2-sum-value"><?= $registeredLearners ?></td>
                    </tr>
                    <tr>
                        <td class="sf2-sum-label" colspan="3">Late enrolment</td>
                        <td class="sf2-sum-label" colspan="2">during the month</td>
                        <td class="sf2-sum-value">0</td>
                        <td class="sf2-sum-value">0</td>
                        <td class="sf2-sum-value">0</td>
                    </tr>
                    <tr>
                        <td class="sf2-sum-label" colspan="5">(beyond cut-off)</td>
                        <td class="sf2-sum-value" colspan="3"></td>
                    </tr>
                    <tr>
                        <td class="sf2-sum-label" colspan="5">Registered Learners as of end of month</td>
                        <td class="sf2-sum-value"><?= $maleCount ?></td>
                        <td class="sf2-sum-value"><?= $femaleCount ?></td>
                        <td class="sf2-sum-value"><?= $registeredLearners ?></td>
                    </tr>
                    <tr>
                        <td class="sf2-sum-label" colspan="5">Percentage of Enrolment as of end of month</td>
                        <td class="sf2-sum-value" colspan="3"><?= $pctEnrol ?>%</td>
                    </tr>
                    <tr>
                        <td class="sf2-sum-label" colspan="5">Average Daily Attendance</td>
                        <td class="sf2-sum-value" colspan="3"><?= $ada ?></td>
                    </tr>
                    <tr>
                        <td class="sf2-sum-label" colspan="5">Percentage of Attendance for the month</td>
                        <td class="sf2-sum-value" colspan="3"><?= $pctAttendance ?>%</td>
                    </tr>
                    <tr>
                        <td class="sf2-sum-label" colspan="5">Number of students absent for 5 consecutive days</td>
                        <td class="sf2-sum-value" colspan="3"><?= $fiveConsec ?></td>
                    </tr>
                    <tr>
                        <td class="sf2-sum-label" colspan="5">Dropped out</td>
                        <td class="sf2-sum-value"><?= $droppedOutM ?></td>
                        <td class="sf2-sum-value"><?= $droppedOutF ?></td>
                        <td class="sf2-sum-value"><?= $droppedOut ?></td>
                    </tr>
                    <tr>
                        <td class="sf2-sum-label" colspan="5">Transferred out</td>
                        <td class="sf2-sum-value"><?= $transferredOutM ?></td>
                        <td class="sf2-sum-value"><?= $transferredOutF ?></td>
                        <td class="sf2-sum-value"><?= $transferredOut ?></td>
                    </tr>
                    <tr>
                        <td class="sf2-sum-label" colspan="5">Transferred in</td>
                        <td class="sf2-sum-value">0</td>
                        <td class="sf2-sum-value">0</td>
                        <td class="sf2-sum-value">0</td>
                    </tr>
                </table>

                <div class="sf2-certify">I certify that this is a true and correct report.</div>
                <div class="sf2-sig-line"></div>
                <div class="sf2-sig-name"><?= sanitize($teacherName) ?></div>
                <div class="sf2-sig-role">(Signature of Adviser over Printed Name)</div>

                <div class="sf2-attested">Attested by:</div>
                <div class="sf2-sig-line"></div>
                <div class="sf2-sig-name"><?= sanitize($principalName) ?></div>
                <div class="sf2-sig-role">(Signature of School Head over Printed Name)</div>
                <div class="sf2-generated">Generated thru LIS</div>
            </div>
        </div>
    </div>

    <script>
        window.onload = function () {
            setTimeout(function () {
                window.print();
            }, 350);
        };
    </script>
</body>
</html>
