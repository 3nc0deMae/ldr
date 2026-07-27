<?php
/**
 * LDB-FRAS System Usability Scale (SUS) Survey (Phase 19)
 * Standard 10-item questionnaire with 5-point Likert scale
 * Used for User Acceptance Testing (UAT)
 *
 * SUS Score Interpretation:
 *   90-100: Excellent (A)
 *   80-89:  Good (B)
 *   70-79:  OK (C)
 *   60-69:  Poor (D)
 *   0-59:   Awful (F)
 */

require_once __DIR__ . '/bootstrap.php';

// SUS Questions (standard 10-item)
$susQuestions = [
    ['id' => 1,  'text' => 'I think that I would like to use this system frequently.', 'positive' => true],
    ['id' => 2,  'text' => 'I found the system unnecessarily complex.', 'positive' => false],
    ['id' => 3,  'text' => 'I thought the system was easy to use.', 'positive' => true],
    ['id' => 4,  'text' => 'I think that I would need the support of a technical person to be able to use this system.', 'positive' => false],
    ['id' => 5,  'text' => 'I found the various functions in this system were well integrated.', 'positive' => true],
    ['id' => 6,  'text' => 'I thought there was too much inconsistency in this system.', 'positive' => false],
    ['id' => 7,  'text' => 'I would imagine that most people would learn to use this system very quickly.', 'positive' => true],
    ['id' => 8,  'text' => 'I found the system very cumbersome to use.', 'positive' => false],
    ['id' => 9,  'text' => 'I felt very confident using the system.', 'positive' => true],
    ['id' => 10, 'text' => 'I needed to learn a lot of things before I could get going with this system.', 'positive' => false],
];

$likertLabels = [
    1 => 'Strongly Disagree',
    2 => 'Disagree',
    3 => 'Neutral',
    4 => 'Agree',
    5 => 'Strongly Agree'
];

// Handle form submission
$submitted = false;
$susScore  = 0;
$grade     = '';
$adjective = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_survey'])) {
    $submitted = true;
    $responses = [];
    $rawScore  = 0;

    foreach ($susQuestions as $q) {
        $answer = intval($_POST['q' . $q['id']] ?? 0);
        $responses[$q['id']] = $answer;

        if ($q['positive']) {
            $rawScore += ($answer - 1);
        } else {
            $rawScore += (5 - $answer);
        }
    }

    // SUS Score = raw sum × 2.5 (scale 0-100)
    $susScore  = round($rawScore * 2.5, 1);

    // Determine grade and adjective
    if ($susScore >= 90)      { $grade = 'A'; $adjective = 'Excellent'; }
    elseif ($susScore >= 80)  { $grade = 'B'; $adjective = 'Good'; }
    elseif ($susScore >= 70)  { $grade = 'C'; $adjective = 'OK'; }
    elseif ($susScore >= 60)  { $grade = 'D'; $adjective = 'Poor'; }
    else                      { $grade = 'F'; $adjective = 'Awful'; }

    // Save to database if available
    $db = getTestDB();
    if ($db) {
        try {
            $stmt = $db->prepare(
                "INSERT INTO audit_logs (user_id, action, description, ip_address, created_at)
                 VALUES (?, 'sus_survey', ?, ?, NOW())"
            );
            $stmt->execute([
                null,
                "SUS Survey submitted: Score=$susScore, Grade=$grade",
                $_SERVER['REMOTE_ADDR'] ?? ''
            ]);
        } catch (Exception $e) {
            // Non-critical
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SUS Survey - LDB-FRAS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background: #f5f7fa; }
        .survey-header {
            background: linear-gradient(135deg, #0A224C 0%, #122d5e 100%);
            color: white; padding: 40px 0; margin-bottom: 30px;
        }
        .question-card {
            background: white; border-radius: 12px; padding: 24px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06); margin-bottom: 16px;
        }
        .question-number {
            width: 32px; height: 32px; border-radius: 50%;
            background: #0A224C; color: white; display: flex;
            align-items: center; justify-content: center;
            font-weight: 700; font-size: 14px; flex-shrink: 0;
        }
        .likert-group { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 12px; }
        .likert-option {
            flex: 1; min-width: 100px;
        }
        .likert-option input[type="radio"] { display: none; }
        .likert-option label {
            display: block; text-align: center; padding: 10px 8px;
            border: 2px solid #e0e0e0; border-radius: 10px;
            cursor: pointer; font-size: 12px; font-weight: 500;
            transition: all 0.2s; color: #6c757d;
        }
        .likert-option input:checked + label {
            border-color: #0066FE; background: rgba(0,102,254,0.08);
            color: #0066FE; font-weight: 600;
        }
        .likert-option label:hover {
            border-color: #0066FE; background: rgba(0,102,254,0.04);
        }
        .result-card {
            background: white; border-radius: 16px; padding: 40px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.08); text-align: center;
        }
        .score-circle {
            width: 150px; height: 150px; border-radius: 50%;
            display: flex; flex-direction: column;
            align-items: center; justify-content: center;
            margin: 0 auto 20px; font-weight: 800;
        }
        .score-circle .score { font-size: 42px; line-height: 1; }
        .score-circle .of-100 { font-size: 14px; opacity: 0.6; }
        .grade-badge {
            display: inline-block; padding: 6px 20px;
            border-radius: 20px; font-weight: 700; font-size: 14px;
        }
    </style>
</head>
<body>
    <div class="survey-header">
        <div class="container">
            <h3 class="fw-800 mb-1"><i class="bi bi-clipboard-check me-2"></i>System Usability Scale (SUS) Survey</h3>
            <p class="mb-0" style="opacity:0.6;">Liceo de Baleno Facial Recognition Attendance System — User Acceptance Testing</p>
        </div>
    </div>

    <div class="container pb-5" style="max-width:800px;">
        <?php if (!$submitted): ?>
        <!-- Instructions -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body">
                <h6 class="fw-700 mb-2"><i class="bi bi-info-circle text-primary me-2"></i>Instructions</h6>
                <p class="text-muted mb-0" style="font-size:14px;">
                    Please rate each statement based on your experience using the <strong>LDB-FRAS</strong> system.
                    Select from <strong>1 (Strongly Disagree)</strong> to <strong>5 (Strongly Agree)</strong>.
                    There are no right or wrong answers — your honest feedback helps improve the system.
                </p>
            </div>
        </div>

        <form method="POST" id="susForm">
            <?php foreach ($susQuestions as $q): ?>
            <div class="question-card">
                <div class="d-flex align-items-start gap-3">
                    <div class="question-number"><?= $q['id'] ?></div>
                    <div class="flex-grow-1">
                        <p class="mb-0 fw-600" style="font-size:14px;">
                            <?= $q['text'] ?>
                        </p>
                        <?php if (!$q['positive']): ?>
                            <small class="text-muted fst-italic">(Negatively worded — reverse scored)</small>
                        <?php endif; ?>
                        <div class="likert-group">
                            <?php foreach ($likertLabels as $val => $label): ?>
                            <div class="likert-option">
                                <input type="radio" name="q<?= $q['id'] ?>"
                                       id="q<?= $q['id'] ?>_<?= $val ?>" value="<?= $val ?>" required>
                                <label for="q<?= $q['id'] ?>_<?= $val ?>">
                                    <strong><?= $val ?></strong><br><?= $label ?>
                                </label>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>

            <div class="text-center mt-4">
                <button type="submit" name="submit_survey" class="btn btn-primary btn-lg px-5"
                        style="border-radius:12px;font-weight:600;" id="submitBtn">
                    <i class="bi bi-send me-2"></i>Submit Survey
                </button>
            </div>
        </form>

        <?php else: ?>
        <!-- Results -->
        <div class="result-card mb-4">
            <h5 class="fw-700 mb-4"><i class="bi bi-bar-chart me-2 text-primary"></i>SUS Survey Results</h5>

            <?php
                $scoreColor = $susScore >= 80 ? '#28A745' : ($susScore >= 60 ? '#FFC107' : '#DC3545');
                $gradeBg    = $susScore >= 80 ? '#d4edda' : ($susScore >= 60 ? '#fff3cd' : '#f8d7da');
            ?>

            <div class="score-circle" style="background: <?= $scoreColor ?>15; border: 4px solid <?= $scoreColor ?>;">
                <div class="score" style="color: <?= $scoreColor ?>"><?= $susScore ?></div>
                <div class="of-100" style="color: <?= $scoreColor ?>">out of 100</div>
            </div>

            <div class="mb-3">
                <span class="grade-badge" style="background: <?= $gradeBg ?>; color: <?= $scoreColor ?>">
                    Grade: <?= $grade ?> — <?= $adjective ?>
                </span>
            </div>

            <!-- Score Interpretation -->
            <div class="text-start mt-4">
                <h6 class="fw-700 mb-3">Score Interpretation</h6>
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead><tr><th>Score Range</th><th>Grade</th><th>Adjective</th><th></th></tr></thead>
                        <tbody>
                            <tr <?= $susScore >= 90 ? 'class="table-success"' : '' ?>><td>90–100</td><td>A</td><td>Excellent</td><td><?= $susScore >= 90 ? '← Your score' : '' ?></td></tr>
                            <tr <?= $susScore >= 80 && $susScore < 90 ? 'class="table-success"' : '' ?>><td>80–89</td><td>B</td><td>Good</td><td><?= $susScore >= 80 && $susScore < 90 ? '← Your score' : '' ?></td></tr>
                            <tr <?= $susScore >= 70 && $susScore < 80 ? 'class="table-warning"' : '' ?>><td>70–79</td><td>C</td><td>OK</td><td><?= $susScore >= 70 && $susScore < 80 ? '← Your score' : '' ?></td></tr>
                            <tr <?= $susScore >= 60 && $susScore < 70 ? 'class="table-warning"' : '' ?>><td>60–69</td><td>D</td><td>Poor</td><td><?= $susScore >= 60 && $susScore < 70 ? '← Your score' : '' ?></td></tr>
                            <tr <?= $susScore < 60 ? 'class="table-danger"' : '' ?>><td>0–59</td><td>F</td><td>Awful</td><td><?= $susScore < 60 ? '← Your score' : '' ?></td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Individual Responses -->
            <div class="text-start mt-4">
                <h6 class="fw-700 mb-3">Your Responses</h6>
                <?php foreach ($susQuestions as $q): ?>
                <div class="d-flex align-items-center gap-3 mb-2 p-2 rounded" style="background:#f8f9fa;font-size:13px;">
                    <span class="question-number" style="width:26px;height:26px;font-size:11px;"><?= $q['id'] ?></span>
                    <span class="flex-grow-1"><?= $q['text'] ?></span>
                    <strong style="color:<?= $q['positive'] ? '#0066FE' : '#DC3545' ?>">
                        <?= $responses[$q['id']] ?? '-' ?>/5
                    </strong>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="text-center">
            <a href="sus-survey.php" class="btn btn-outline-primary me-2">
                <i class="bi bi-arrow-clockwise me-1"></i> Take Another Survey
            </a>
            <a href="test-runner.php" class="btn btn-outline-secondary">
                <i class="bi bi-beaker me-1"></i> Back to Test Runner
            </a>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>
