<?php

require_once __DIR__ . '/bootstrap.php';

// Collect all test files
$unitTests        = glob(__DIR__ . '/unit/test-*.php');
$integrationTests = glob(__DIR__ . '/integration/test-*.php');

$allResults    = [];
$totalPassed   = 0;
$totalFailed   = 0;
$totalTests    = 0;
$totalTime     = 0;

$runTests = isset($_GET['run']) && $_GET['run'] === '1';

if ($runTests) {
    foreach ($unitTests as $file) {
        $result = runTestFile($file);
        $allResults['unit'][] = $result;
        $totalPassed += $result['passed'];
        $totalFailed += $result['failed'];
        $totalTests  += $result['total'];
        $totalTime   += $result['elapsed'];
    }
    foreach ($integrationTests as $file) {
        $result = runTestFile($file);
        $allResults['integration'][] = $result;
        $totalPassed += $result['passed'];
        $totalFailed += $result['failed'];
        $totalTests  += $result['total'];
        $totalTime   += $result['elapsed'];
    }
}

$passRate = $totalTests > 0 ? round(($totalPassed / $totalTests) * 100, 1) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>LDB-FRAS Test Runner</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background: #f5f7fa; }
        .test-header {
            background: linear-gradient(135deg, #0A224C 0%, #122d5e 100%);
            color: white; padding: 40px 0; margin-bottom: 30px;
        }
        .stat-box {
            background: white; border-radius: 12px; padding: 24px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06); text-align: center;
        }
        .stat-box .value { font-size: 36px; font-weight: 800; }
        .stat-box .label { font-size: 13px; color: #6c757d; margin-top: 4px; }
        .test-suite-card {
            background: white; border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06); margin-bottom: 16px;
            overflow: hidden;
        }
        .suite-header {
            padding: 16px 20px; display: flex; justify-content: space-between;
            align-items: center; border-bottom: 1px solid #f0f0f0;
        }
        .suite-header h6 { margin: 0; font-weight: 700; }
        .test-item {
            padding: 10px 20px; border-bottom: 1px solid #f8f8f8;
            display: flex; align-items: center; gap: 10px; font-size: 13px;
        }
        .test-item:last-child { border-bottom: none; }
        .test-pass { color: #28A745; }
        .test-fail { color: #DC3545; }
        .badge-pass { background: #d4edda; color: #155724; padding: 3px 10px; border-radius: 20px; font-size: 12px; font-weight: 600; }
        .badge-fail { background: #f8d7da; color: #721c24; padding: 3px 10px; border-radius: 20px; font-size: 12px; font-weight: 600; }
        .run-btn {
            background: #0066FE; color: white; border: none; padding: 12px 32px;
            border-radius: 10px; font-weight: 600; font-size: 15px;
            text-decoration: none; display: inline-flex; align-items: center; gap: 8px;
            transition: all 0.2s;
        }
        .run-btn:hover { background: #0052cc; color: white; transform: translateY(-1px); }
        .section-title {
            font-size: 14px; font-weight: 700; color: #0A224C;
            text-transform: uppercase; letter-spacing: 1px; margin-bottom: 12px;
        }
        .overall-banner {
            padding: 16px 24px; border-radius: 12px; margin-bottom: 24px;
            display: flex; justify-content: space-between; align-items: center;
        }
        .overall-pass { background: #d4edda; border: 1px solid #c3e6cb; }
        .overall-fail { background: #f8d7da; border: 1px solid #f5c6cb; }
    </style>
</head>
<body>
    <div class="test-header">
        <div class="container">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h3 class="fw-800 mb-1"><i class="bi bi-beaker me-2"></i>LDB-FRAS Test Runner</h3>
                    <p class="mb-0" style="opacity:0.6;">Phase 19 — Testing &amp; Quality Assurance</p>
                </div>
                <a href="?run=1" class="run-btn">
                    <i class="bi bi-play-fill"></i> Run All Tests
                </a>
            </div>
        </div>
    </div>

    <div class="container pb-5">
        <?php if (!$runTests): ?>
        <!-- Welcome Screen -->
        <div class="text-center py-5">
            <i class="bi bi-beaker fs-1 text-muted mb-3 d-block"></i>
            <h4 class="fw-700 mb-2">Ready to Run Tests</h4>
            <p class="text-muted mb-4">
                This test runner executes all unit and integration tests for the LDB-FRAS system.<br>
                Tests cover authentication, attendance, notifications, face recognition, SMS, and email.
            </p>
            <a href="?run=1" class="run-btn"><i class="bi bi-play-fill"></i> Start Testing</a>

            <div class="row g-3 mt-5" style="max-width:700px;margin:0 auto;">
                <div class="col-md-4">
                    <div class="stat-box">
                        <div class="value text-primary"><?= count($unitTests) ?></div>
                        <div class="label">Unit Test Suites</div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stat-box">
                        <div class="value text-info"><?= count($integrationTests) ?></div>
                        <div class="label">Integration Suites</div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stat-box">
                        <div class="value text-success"><?= count($unitTests) + count($integrationTests) ?></div>
                        <div class="label">Total Suites</div>
                    </div>
                </div>
            </div>
        </div>

        <?php else: ?>
        <!-- Test Results -->
        <div class="overall-banner <?= $totalFailed === 0 ? 'overall-pass' : 'overall-fail' ?>">
            <div>
                <h5 class="mb-1 fw-700">
                    <?php if ($totalFailed === 0): ?>
                        <i class="bi bi-check-circle-fill text-success me-2"></i>All Tests Passed!
                    <?php else: ?>
                        <i class="bi bi-exclamation-triangle-fill text-danger me-2"></i><?= $totalFailed ?> Test(s) Failed
                    <?php endif; ?>
                </h5>
                <small class="text-muted">
                    <?= $totalPassed ?> passed, <?= $totalFailed ?> failed out of <?= $totalTests ?> tests
                    in <?= number_format($totalTime, 2) ?>ms
                </small>
            </div>
            <div style="font-size:28px;font-weight:800;color:<?= $totalFailed === 0 ? '#28A745' : '#DC3545' ?>">
                <?= $passRate ?>%
            </div>
        </div>

        <!-- Summary Stats -->
        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="stat-box">
                    <div class="value text-primary"><?= $totalTests ?></div>
                    <div class="label">Total Tests</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-box">
                    <div class="value text-success"><?= $totalPassed ?></div>
                    <div class="label">Passed</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-box">
                    <div class="value text-danger"><?= $totalFailed ?></div>
                    <div class="label">Failed</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-box">
                    <div class="value"><?= number_format($totalTime, 2) ?>ms</div>
                    <div class="label">Execution Time</div>
                </div>
            </div>
        </div>

        <!-- Unit Tests Section -->
        <?php if (!empty($allResults['unit'])): ?>
            <h5 class="section-title mt-4 mb-3"><i class="bi bi-flask me-2"></i>Unit Tests</h5>
            <?php foreach ($allResults['unit'] as $result): ?>
            <div class="test-suite-card">
                <div class="suite-header">
                    <div>
                        <h6>
                            <i class="bi bi-<?= $result['success'] ? 'check-circle-fill text-success' : 'x-circle-fill text-danger' ?> me-2"></i>
                            <?= htmlspecialchars($result['name']) ?>
                        </h6>
                        <small class="text-muted"><?= $result['passed'] ?> passed, <?= $result['failed'] ?> failed &middot; <?= number_format($result['elapsed'], 2) ?>ms</small>
                    </div>
                    <span class="<?= $result['success'] ? 'badge-pass' : 'badge-fail' ?>">
                        <?= $result['success'] ? 'PASS' : 'FAIL' ?>
                    </span>
                </div>
                <div style="max-height:350px;overflow-y:auto;">
                    <?php foreach ($result['tests'] as $test): ?>
                    <div class="test-item">
                        <i class="bi bi-<?= $test['status'] === 'pass' ? 'check-circle-fill test-pass' : 'x-circle-fill test-fail' ?>"></i>
                        <span style="flex:1;" class="<?= $test['status'] === 'pass' ? 'test-pass' : 'test-fail' ?>">
                            <?= htmlspecialchars($test['message']) ?>
                        </span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <!-- Integration Tests Section -->
        <?php if (!empty($allResults['integration'])): ?>
            <h5 class="section-title mt-4 mb-3"><i class="bi bi-plug me-2"></i>Integration Tests</h5>
            <?php foreach ($allResults['integration'] as $result): ?>
            <div class="test-suite-card">
                <div class="suite-header">
                    <div>
                        <h6>
                            <i class="bi bi-<?= $result['success'] ? 'check-circle-fill text-success' : 'x-circle-fill text-danger' ?> me-2"></i>
                            <?= htmlspecialchars($result['name']) ?>
                        </h6>
                        <small class="text-muted"><?= $result['passed'] ?> passed, <?= $result['failed'] ?> failed &middot; <?= number_format($result['elapsed'], 2) ?>ms</small>
                    </div>
                    <span class="<?= $result['success'] ? 'badge-pass' : 'badge-fail' ?>">
                        <?= $result['success'] ? 'PASS' : 'FAIL' ?>
                    </span>
                </div>
                <div style="max-height:350px;overflow-y:auto;">
                    <?php foreach ($result['tests'] as $test): ?>
                    <div class="test-item">
                        <i class="bi bi-<?= $test['status'] === 'pass' ? 'check-circle-fill test-pass' : 'x-circle-fill test-fail' ?>"></i>
                        <span style="flex:1;" class="<?= $test['status'] === 'pass' ? 'test-pass' : 'test-fail' ?>">
                            <?= htmlspecialchars($test['message']) ?>
                        </span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <!-- Actions -->
        <div class="text-center mt-4 py-3">
            <a href="?run=1" class="btn btn-primary me-2"><i class="bi bi-arrow-clockwise me-1"></i> Re-run Tests</a>
            <a href="test-runner.php" class="btn btn-outline-secondary me-2"><i class="bi bi-house me-1"></i> Reset</a>
            <a href="sus-survey.php" class="btn btn-outline-info"><i class="bi bi-file-earmark-text me-1"></i> SUS Survey</a>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>
