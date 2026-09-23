<?php
/**
 * Features: Time-Out session configuration, face recognition for dismissal,
 *           Live scan log, Automatic detection
 */
require_once __DIR__ . '/../config.php';
requireRole(['admin', 'gate']);

$pageTitle = 'Gate Time-Out';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

// Get all active timeout sessions
$activeSessions = [];
try {
    $stmt = $db->prepare(
        "SELECT * FROM gate_sessions 
         WHERE session_type = 'time_out' AND status = 'active' 
         ORDER BY start_time DESC"
    );
    $stmt->execute();
    $activeSessions = $stmt->fetchAll();
    $activeSession = $activeSessions[0] ?? null;
} catch (Exception $e) {}

// Auto-select session period from current time (AM = morning, PM = afternoon)
$autoPeriod = getGateSessionPeriod($db, 'time_out');

// Prefer the active session that matches the auto-detected period
$targetSessionId = null;
foreach ($activeSessions as $s) {
    if (($s['session_period'] ?? '') === $autoPeriod) {
        $targetSessionId = $s['id'];
        break;
    }
}
$targetSessionId = $targetSessionId ?: ($activeSession['id'] ?? null);

// Get today's timeout attendance counts
$todayStats = ['scanned' => 0, 'total' => 0];
try {
    $today = date('Y-m-d');
        $scanned = $db->prepare(
            "SELECT COUNT(DISTINCT student_id) FROM attendance_records 
             WHERE DATE(scan_time) = ? AND session_type = 'time_out' AND status != 'pending'"
        );
    $scanned->execute([$today]);
    $todayStats['scanned'] = $scanned->fetchColumn();
    $todayStats['total'] = $db->query("SELECT COUNT(*) FROM students WHERE status = 'active'")->fetchColumn();
} catch (Exception $e) {}

// Get recent timeout scans
$recentScans = [];
try {
    if ($activeSession) {
        $stmt = $db->prepare(
            "SELECT ar.*, s.first_name, s.last_name, s.grade_level, s.section, s.student_id
             FROM attendance_records ar
             LEFT JOIN students s ON ar.student_id = s.id
             WHERE ar.gate_session_id = ? AND ar.session_type = 'time_out' AND ar.status != 'pending'
             ORDER BY ar.scan_time DESC LIMIT 20"
        );
        $stmt->execute([$activeSession['id']]);
        $recentScans = $stmt->fetchAll();
    }
} catch (Exception $e) {
    $recentScans = [];
}
?>

<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/pages-theme.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/pages-navbar.css">

<style>
    :root {
        --ad-font: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif;
        --ad-mono: 'JetBrains Mono', monospace;
        --ad-primary: #4f46e5;
        --ad-primary-light: rgba(79,70,229,0.15);
        --ad-primary-dark: #3730a3;
        --ad-primary-glow: rgba(79,70,229,0.2);
        --ad-success: #10b981;
        --ad-success-light: rgba(16,185,129,0.15);
        --ad-danger: #ef4444;
        --ad-danger-light: rgba(239,68,68,0.15);
        --ad-warning: #f59e0b;
        --ad-warning-light: rgba(245,158,17,0.15);
        --ad-info: #06b6d4;
        --ad-info-light: rgba(6,182,212,0.15);
        --ad-radius: 14px;
        --ad-radius-sm: 10px;
        --ad-radius-xs: 8px;
        --ad-shadow-sm: 0 1px 3px rgba(0,0,0,0.2);
        --ad-shadow: 0 4px 16px rgba(0,0,0,0.25);
        --ad-shadow-lg: 0 12px 40px rgba(0,0,0,0.3);
        --ad-transition: 0.2s cubic-bezier(0.4,0,0.2,1);
        --ad-transition-spring: 0.35s cubic-bezier(0.34,1.56,0.64,1);
    }

    /* TOP NAVBAR */
    .page-title h5 { font-size: 20px; font-weight: 800; letter-spacing: -0.03em; margin: 0; }
    .page-title small { font-size: 13px; font-weight: 500; }
    .mobile-title-left h5 { font-size: 18px; font-weight: 800; letter-spacing: -0.03em; margin: 0; }
    .mobile-title-left small { font-size: 12px; font-weight: 500; }

    .stat-card { transition: all var(--ad-transition); }
    .stat-card:hover { transform: translateY(-3px); }
    @keyframes fadeUp { from { opacity: 0; transform: translateY(16px); } to { opacity: 1; transform: translateY(0); } }
    .stat-card { animation: fadeUp 0.5s ease forwards; opacity: 0; }
    .stat-card:nth-child(1) { animation-delay: 0.05s; }
    .stat-card:nth-child(2) { animation-delay: 0.1s; }
    .stat-card:nth-child(3) { animation-delay: 0.15s; }

    @media (max-width: 991px) {
        .content-area { padding: 20px; }
    }

    @media (max-width: 767px) {
        .top-navbar { padding: 12px 14px; flex-wrap: nowrap; gap: 8px; }
        .navbar-left { flex: 1; gap: 10px; min-width: 0; }
        #sidebarToggle { width: 38px; height: 38px; font-size: 20px; flex-shrink: 0; }
        .navbar-brand { display: flex; }
        .navbar-brand-logo { width: 44px; height: 44px; }
        .navbar-brand-name { font-size: 12px; }
        .navbar-brand-sub { font-size: 9px; opacity: 0.45; }
        .desktop-title { display: none !important; }
        .desktop-date { display: none !important; }
        .mobile-title { display: block; }
        .navbar-actions { gap: 6px; }
        .nav-icon-btn { width: 38px; height: 38px; font-size: 15px; }
        .content-area { padding: 10px 12px 28px; }
        .stat-card { padding: 16px; }
        .stat-value { font-size: 24px; }
        .stat-label { font-size: 10px; margin-top: 4px; }
        .stat-icon { width: 38px; height: 38px; font-size: 15px; border-radius: 10px; }
    }

    @media (max-width: 576px) {
        .top-navbar { padding: 10px 10px; }
        .navbar-brand-logo { width: 38px; height: 38px; }
        .navbar-brand-name { font-size: 11px; }
        .navbar-brand-sub { font-size: 8px; }
        .navbar-actions { gap: 4px; }
        .nav-icon-btn { width: 34px; height: 34px; font-size: 14px; }
        #sidebarToggle { width: 34px; height: 34px; font-size: 18px; }
        .mobile-date { font-size: 10px; padding: 5px 8px; }
        .content-area { padding: 8px 8px 24px; }
        .stat-card { padding: 12px 10px; }
        .stat-value { font-size: 20px; }
        .stat-label { font-size: 9px; letter-spacing: 0.04em; }
        .stat-icon { width: 32px; height: 32px; font-size: 13px; }
    }

    /* MANUAL LRN MODAL */
    .event-modal-overlay{position:fixed;inset:0;background:rgba(26,29,46,0.5);backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px);z-index:9998;display:none;align-items:center;justify-content:center;padding:20px}
    .event-modal-overlay.show{display:flex}
    .event-modal{border-radius:20px;width:min(620px,100%);max-width:100%;height:auto;max-height:90vh;display:flex;flex-direction:column;overflow:hidden;animation:modalSlideIn .35s cubic-bezier(.34,1.56,.64,1)}
    .event-modal form{display:flex;flex-direction:column;flex:1 1 auto;min-height:0}
    @keyframes modalSlideIn{from{opacity:0;transform:translateY(24px) scale(.96)}to{opacity:1;transform:translateY(0) scale(1)}}
    .event-modal-header{display:flex;justify-content:space-between;align-items:center;padding:18px 22px;flex-shrink:0}
    .event-modal-title{display:flex;align-items:center;gap:10px;font-size:16px;font-weight:800;letter-spacing:-0.02em}
    .event-modal-title i{font-size:20px}
    .event-modal-close{width:32px;height:32px;border:none;border-radius:var(--ad-radius-xs);display:flex;align-items:center;justify-content:center;cursor:pointer;transition:all var(--ad-transition);font-size:13px;background:rgba(255,255,255,0.06);color:inherit}
    .event-modal-close:hover{background:rgba(255,255,255,0.14)}
    .event-modal-body{padding:0 22px 14px;overflow-y:auto;flex:1 1 auto;min-height:0;-webkit-overflow-scrolling:touch}
    .event-modal-body::-webkit-scrollbar{width:5px}
    .event-modal-body::-webkit-scrollbar-track{background:transparent;margin:4px 0}
    .event-modal-body::-webkit-scrollbar-thumb{background:rgba(255,255,255,0.15);border-radius:10px}
    .event-modal-body::-webkit-scrollbar-thumb:hover{background:rgba(255,255,255,0.25)}
    .event-modal-body{scrollbar-width:thin;scrollbar-color:rgba(255,255,255,0.15) transparent}
    .event-modal-footer{display:flex;justify-content:flex-end;gap:10px;padding:12px 22px;flex-shrink:0;border-top:1px solid rgba(255,255,255,0.06);background:rgba(255,255,255,0.02)}
    .evt-field{margin-bottom:12px}.evt-field:last-child{margin-bottom:0}
    .evt-field label{display:block;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;margin-bottom:5px;line-height:1.3}
    .evt-field label .required{color:var(--ad-danger)}
    .evt-field input[type="text"],.evt-field input[type="email"],.evt-field input[type="tel"],.evt-field textarea,.evt-field select{width:100%;padding:9px 12px;border:1.5px solid rgba(255,255,255,0.12);border-radius:var(--ad-radius-sm);font-size:13px;transition:all var(--ad-transition);font-family:var(--ad-font);background:rgba(255,255,255,0.05);color:inherit}
    .evt-field input:focus,.evt-field textarea:focus,.evt-field select:focus{outline:none;border-color:var(--ad-primary);box-shadow:0 0 0 3px var(--ad-primary-glow)}
    .evt-field input::placeholder{color:rgba(255,255,255,0.25)}
    .evt-btn{padding:10px 18px;border:none;border-radius:var(--ad-radius-sm);font-size:13px;font-weight:700;cursor:pointer;transition:all var(--ad-transition);display:flex;align-items:center;gap:6px}
    .evt-btn-cancel{background:rgba(255,255,255,0.08);color:inherit}.evt-btn-cancel:hover{background:rgba(255,255,255,0.14)}
    .evt-btn-save{background:var(--ad-primary);color:#fff}.evt-btn-save:hover{background:var(--ad-primary-dark);box-shadow:0 4px 16px var(--ad-primary-glow)}
    .evt-btn-danger{background:var(--ad-danger);color:#fff}.evt-btn-danger:hover{background:#dc2626;box-shadow:0 4px 14px rgba(239,68,68,0.3)}
</style>
<?php require_once __DIR__ . '/../includes/pages-topnavbar.php'; ?>
<div class="main-content">

    <div class="content-area">
        <?= displayFlashMessage() ?>

        <div class="d-none d-md-flex justify-content-between align-items-center gap-3 mb-3">
            <div class="page-title mb-0">
                <h5 class="mb-0">Gate Time-Out Session</h5>
                <small>Face recognition dismissal scanning</small>
            </div>
            <div class="d-flex gap-2">
                    <a href="<?= BASE_URL ?>/gate/timein.php" class="btn btn-success btn-sm"><i class="bi bi-box-arrow-in-right"></i> Time-In Session</a>
            </div>
        </div>

        <div class="page-title mobile-title">
            <div class="mobile-title-inner">
                <div class="mobile-title-left">
                    <h5>Gate Time-Out Session</h5>
                    <small>Face recognition dismissal scanning</small>
                </div>
                <div class="d-flex gap-2">
                <a href="<?= BASE_URL ?>/gate/timein.php" class="btn btn-success btn-sm"><i class="bi bi-box-arrow-in-right"></i> Time-In Session</a>
                </div>
            </div>
        </div>

        <!-- Stats -->
        <div class="row g-3 mb-4">
            <div class="col-6 col-lg-3 col-md-6">
                <div class="stat-card">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-value" id="scannedCount"><?= $todayStats['scanned'] ?></div>
                            <div class="stat-label">Scanned Out</div>
                        </div>
                        <div class="stat-icon bg-success-soft"><i class="bi bi-box-arrow-right"></i></div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-lg-3 col-md-6">
                <div class="stat-card">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-value"><?= max(0, $todayStats['total'] - $todayStats['scanned']) ?></div>
                            <div class="stat-label">Still In School</div>
                        </div>
                        <div class="stat-icon bg-warning-soft"><i class="bi bi-building"></i></div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-lg-3 col-md-6">
                <div class="stat-card">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-value">
                                <?= $activeSession ? 'ACTIVE' : 'INACTIVE' ?>
                            </div>
                            <div class="stat-label">Session Status</div>
                        </div>
                        <div class="stat-icon bg-<?= $activeSession ? 'success' : 'secondary' ?>-soft">
                            <i class="bi bi-<?= $activeSession ? 'broadcast' : 'broadcast-pin' ?>"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4">
            <!-- Left: Session Config + Scanner -->
            <div class="col-lg-7">
                <!-- Session Configuration -->
                <?php if (!$activeSession): ?>
                <div class="card mb-4">
                    <div class="card-header">
                        <span><i class="bi bi-gear me-2"></i>Time-Out Session Configuration</span>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="<?= BASE_URL ?>/api/gate.php" class="row g-3">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="start_session">
                            <input type="hidden" name="session_type" value="time_out">

                            <div class="col-6 col-md-4">
                                <label class="form-label fw-600">Session Period</label>
                                <input type="hidden" name="session_period" value="<?= $autoPeriod ?>">
                                <input type="text" class="form-control"
                                       value="<?= ucfirst($autoPeriod) ?>" readonly disabled>
                                <small class="text-muted">Auto-selected from current time (<?= date('A') ?>)</small>
                            </div>
                            <div class="col-6 col-md-4">
                                <label class="form-label fw-600">Start Time</label>
                                <input type="time" class="form-control" name="start_time"
                                       value="<?= date('H:i') ?>" disabled>
                                <small class="text-muted">Auto-set to current server time</small>
                            </div>
                            <div class="col-6 col-md-4">
                                <label class="form-label fw-600">End Time</label>
                                <input type="time" class="form-control" name="end_time"
                                       value="<?= date('H:i', strtotime('+1 hour')) ?>" required>
                            </div>
                            <div class="col-6 col-md-4">
                                <label class="form-label fw-600">Late Threshold (min)</label>
                                <input type="number" class="form-control" name="late_threshold"
                                       value="15" min="1" max="120" required>
                                <small class="text-muted">Not used for time-out</small>
                            </div>
                            <div class="col-12">
                                <button type="submit" class="btn btn-warning btn-lg w-100">
                                    <i class="bi bi-play-circle"></i> Start Time-Out Session
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
                <?php else: ?>
                <div class="mb-4">
                    <?php foreach ($activeSessions as $s): ?>
                    <div class="alert alert-warning d-flex justify-content-between align-items-center mb-2">
                        <div>
                            <i class="bi bi-broadcast"></i>
                            <strong><?= ucfirst($s['session_period'] ?? 'General') ?> Time-Out Active</strong> |
                            Start: <?= date('h:i A', strtotime($s['start_time'])) ?> |
                            End: <?= date('h:i A', strtotime($s['end_time'])) ?>
                        </div>
                        <form method="POST" action="<?= BASE_URL ?>/api/gate.php" class="m-0" id="endSessionForm">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="end_session">
                            <input type="hidden" name="session_id" id="endSessionId" value="">
                            <button type="button" class="btn btn-danger btn-sm" onclick="openEndModal(<?= $s['id'] ?>)">
                                <i class="bi bi-stop-circle"></i> End
                            </button>
                        </form>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <?php if (!empty($activeSessions)): ?>
                <div class="card mb-3">
                    <div class="card-body py-2">
                        <label class="form-label fw-600 mb-1" style="font-size:13px;">Scanning Target Session</label>
                        <select class="form-control form-control-sm" id="scanSessionSelect" onchange="document.getElementById('scanSessionId').value=this.value">
                            <?php foreach ($activeSessions as $s): ?>
                            <option value="<?= $s['id'] ?>" <?= ($s['id'] == $targetSessionId) ? 'selected' : '' ?>>
                                <?= ucfirst($s['session_period'] ?? 'General') ?> (<?= date('h:i A', strtotime($s['start_time'])) ?> - <?= date('h:i A', strtotime($s['end_time'])) ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <input type="hidden" id="scanSessionId" value="<?= $targetSessionId ?? '' ?>">
                    </div>
                </div>
                <?php endif; ?>

                <!-- Face Scanner -->
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-camera-video-fill me-2"></i>Time-Out Scanner</span>
                        <span class="badge-status badge-<?= $activeSession ? 'present' : 'absent' ?>">
                            <?= $activeSession ? 'Scanning' : 'Waiting' ?>
                        </span>
                    </div>
                    <div class="card-body text-center">
                        <div class="scanner-container mb-3">
                            <video id="gateVideoOut" autoplay playsinline style="transform: scaleX(-1);"></video>
                            <div class="scanner-overlay"></div>
                            <div class="scanner-status" id="scannerStatus">
                                <span class="pulse-dot"></span>
                                <span id="statusText"><?= $activeSession ? 'Camera Active - Scanning...' : 'Start session to begin' ?></span>
                            </div>
                        </div>

                        <div class="d-flex justify-content-center gap-2 mb-3">
                            <?php if ($activeSession): ?>
                            <button type="button" class="btn btn-warning btn-lg" id="startScanBtn" onclick="startScanning()">
                                <i class="bi bi-camera-video"></i> Start Scanning
                            </button>
                            <button type="button" class="btn btn-danger d-none" id="stopScanBtn" onclick="stopScanning()">
                                <i class="bi bi-stop-fill"></i> Stop
                            </button>
                            <button type="button" class="btn btn-outline-secondary btn-lg d-flex align-items-center gap-2 mb-0 active" id="voiceToggle" onclick="toggleVoice()">
                                <i class="bi bi-volume-up" id="voiceIconOut"></i>
                                <span class="d-none d-sm-inline">Voice</span>
                            </button>
                            <?php else: ?>
                            <button type="button" class="btn btn-secondary btn-lg" disabled>
                                <i class="bi bi-camera-video-off"></i> Start a session first
                            </button>
                            <?php endif; ?>
                        </div>

                        <!-- Alternative Options -->
                        <div class="border-top pt-3 mt-2">
                            <div class="d-flex gap-2">
                                <button type="button" class="btn btn-outline-warning flex-grow-1" onclick="openManualLrnModal()" <?= !$activeSession ? 'disabled' : '' ?>>
                                    <i class="bi bi-keyboard"></i> Manual LRN Input
                                </button>
                            </div>
                            <small class="text-muted d-block mt-2">
                                <i class="bi bi-info-circle"></i> Use manual input if face recognition fails
                            </small>
                        </div>

                        <div id="recognitionResult" class="d-none">
                            <div class="alert alert-success" id="successResult" style="display:none;">
                                <div class="d-flex align-items-center mb-2">
                                    <i class="bi bi-check-circle-fill me-2" style="font-size:24px;"></i>
                                    <strong>Time-Out Recorded!</strong>
                                </div>
                                <div class="ps-4">
                                    <h5 class="mb-1" id="matchedName">-</h5>
                                    <p class="mb-1" id="matchedInfo">-</p>
                                    <small class="text-muted">
                                        Confidence: <span id="confidence">-</span>% | ⏰ Time-Out
                                    </small>
                                </div>
                            </div>
                            <div class="alert alert-warning" id="duplicateResult" style="display:none;">
                                <div class="d-flex align-items-center mb-2">
                                    <i class="bi bi-exclamation-triangle-fill me-2" style="font-size:24px;"></i>
                                    <strong>Already Recorded Today</strong>
                                </div>
                                <div class="ps-4">
                                    <h5 class="mb-1" id="duplicateName">-</h5>
                                    <p class="mb-1" id="duplicateInfo">-</p>
                                    <small class="text-muted"><i class="bi bi-clock-history"></i> This student has already been scanned</small>
                                </div>
                            </div>
                            <div class="alert alert-danger" id="failedResult" style="display:none;">
                                <i class="bi bi-x-circle-fill" style="font-size:24px;"></i>
                                <h6 class="mt-2 mb-0">Face Not Recognized</h6>
                            </div>
                            <div class="alert alert-danger" id="invalidResult" style="display:none;">
                                <div class="d-flex align-items-center mb-2">
                                    <i class="bi bi-exclamation-octagon-fill me-2" style="font-size:24px;"></i>
                                    <strong>Invalid Attendance</strong>
                                </div>
                                <div class="ps-4">
                                    <h5 class="mb-1" id="invalidName">-</h5>
                                    <p class="mb-1" id="invalidInfo">-</p>
                                    <small class="text-muted"><i class="bi bi-shield-lock"></i> Student must time-in first before time-out</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right: Live Scan Log -->
            <div class="col-lg-5">
                <div class="card h-100">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-list-check me-2"></i>Live Scan Log</span>
                        <span class="badge bg-primary" id="scanCount"><?= count($recentScans) ?> scans</span>
                    </div>
                    <div class="card-body p-0" style="max-height:550px;overflow-y:auto;">
                        <div id="liveScanList">
                            <?php if (empty($recentScans)): ?>
                                <div class="text-center text-muted py-5">
                                    <i class="bi bi-person-check" style="font-size:40px;"></i>
                                    <p class="mt-2 mb-0">No scans yet today</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($recentScans as $scan): ?>
                                <?php
                                    $gStatus = $scan['status'] ?: 'present';
                                    $gColor  = $gStatus === 'present' ? 'ad-success' : ($gStatus === 'late' ? 'ad-warning' : 'ad-danger');
                                    $gIcon   = $gStatus === 'present' ? 'check' : ($gStatus === 'late' ? 'clock' : 'x');
                                ?>
                                <div class="scan-item d-flex align-items-center p-3 border-bottom"
                                     data-record-id="<?= (int)$scan['id'] ?>"
                                     data-student-id="<?= sanitize($scan['student_id']) ?>"
                                     data-name="<?= sanitize($scan['first_name'] . ' ' . $scan['last_name']) ?>"
                                     data-status="<?= $gStatus ?>"
                                     data-session-id="<?= (int)$scan['gate_session_id'] ?>"
                                     data-session-type="time_out">
                                    <div class="me-3">
                                        <div class="rounded-circle d-flex align-items-center justify-content-center"
                                             style="width:40px;height:40px;background:var(--<?= $gColor ?>-light);">
                                            <i class="bi bi-<?= $gIcon ?>"
                                               style="color:var(--<?= $gColor ?>);"></i>
                                        </div>
                                    </div>
                                    <div class="flex-grow-1">
                                        <div class="fw-600" style="font-size:13px;">
                                            <?= sanitize($scan['first_name'] . ' ' . $scan['last_name']) ?>
                                        </div>
                                        <small class="text-muted">
                                            <?= sanitize($scan['student_id']) ?> |
                                            Grade <?= $scan['grade_level'] ?>-<?= sanitize($scan['section'] ?? '') ?>
                                        </small>
                                    </div>
                                    <div class="text-end">
                                        <span class="badge-status badge-<?= $gStatus ?>">
                                            <?= ucfirst($gStatus) ?>
                                        </span>
                                        <div style="font-size:11px;color:#999;">
                                            <?= date('h:i A', strtotime($scan['scan_time'])) ?>
                                        </div>
                                        <button type="button" class="btn btn-icon btn-sm btn-outline-primary mt-1 edit-status-btn" title="Edit status" style="width:26px;height:26px;font-size:11px;padding:0;">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if ($activeSession): ?>
<script>
    let scanningInterval = null;
    let isScanning = false;
    let voiceEnabled = true;
    let liveness = null;

    const gateStatusColorMap = { present: 'ad-success', late: 'ad-warning', absent: 'ad-danger' };
    const gateStatusIconMap  = { present: 'check', late: 'clock', absent: 'x' };

    function playConfirmationBeep() {
        try {
            const ctx = new (window.AudioContext || window.webkitAudioContext)();
            const osc = ctx.createOscillator();
            const gain = ctx.createGain();
            osc.connect(gain);
            gain.connect(ctx.destination);
            osc.frequency.value = 880;
            osc.type = 'sine';
            gain.gain.value = 0.08;
            osc.start();
            setTimeout(() => { try { osc.stop(); } catch(e){} }, 120);
        } catch (e) {}
    }

    function announce(text) {
        if (!voiceEnabled) return;
        if ('speechSynthesis' in window) {
            window.speechSynthesis.cancel();
            const utterance = new SpeechSynthesisUtterance(text);
            utterance.lang = 'en-US';
            utterance.rate = 0.9;
            window.speechSynthesis.speak(utterance);
        }
        if (voiceEnabled) playConfirmationBeep();
    }

    function toggleVoice() {
        voiceEnabled = !voiceEnabled;
        const btn = document.getElementById('voiceToggle');
        const icon = document.getElementById('voiceIconOut');
        if (voiceEnabled) {
            btn.classList.add('active');
            icon.className = 'bi bi-volume-up';
        } else {
            btn.classList.remove('active');
            icon.className = 'bi bi-volume-mute';
        }
    }

    // Unlock browser audio/speech on first user interaction
    document.addEventListener('click', function unlockAudio() {
        if ('speechSynthesis' in window) {
            window.speechSynthesis.cancel();
        }
        playConfirmationBeep();
    }, { once: true });

    function onLivenessStatus(status, isLive) {
        const el = document.getElementById('statusText');
        if (!el || !isScanning) return;
        if (status === 'no_face') {
            el.textContent = 'Look at the camera...';
        } else if (status === 'awaiting_blink') {
            el.textContent = '👁️ Please blink to verify you are present';
        } else if (isLive) {
            el.textContent = 'Verified live — scanning...';
        }
    }

    async function startScanning() {
        try {
            await startCamera('gateVideoOut');
            document.getElementById('startScanBtn').classList.add('d-none');
            document.getElementById('stopScanBtn').classList.remove('d-none');
            isScanning = true;

            // Start liveness (anti-spoofing) watcher on the live video.
            // Never block scanning if it cannot start: liveness is fail-open.
            try {
                liveness = new Liveness({
                    video: document.getElementById('gateVideoOut'),
                    onStatus: onLivenessStatus
                });
                await liveness.start();
            } catch (e) {
                console.warn('Liveness failed to start, scanning without it:', e);
                liveness = null;
            }

            scanningInterval = setInterval(autoScan, 3000);
            const st = document.getElementById('statusText');
            if (st) st.textContent = '👁️ Please blink to verify you are present';

            let sessionCheckInterval = null;

            function checkSessionStatus() {
                const sid = document.getElementById('scanSessionId')?.value;
                if (!sid) return;
                const formData = new FormData();
                formData.append('csrf_token', document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '');
                formData.append('action', 'check_session_status');
                formData.append('session_id', sid);

                fetch(window.BASE_URL + '/api/gate.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: formData
                })
                .then(r => r.json())
                .then(data => {
                    if (data.success && data.auto_end_pending) {
                        if (scanningInterval) { clearInterval(scanningInterval); scanningInterval = null; }
                        if (sessionCheckInterval) { clearInterval(sessionCheckInterval); sessionCheckInterval = null; }
                        isScanning = false;
                        if (liveness) { liveness.stop(); liveness = null; }
                        showAutoEndModal(sid);
                    }
                })
                .catch(() => {});
            }

            sessionCheckInterval = setInterval(checkSessionStatus, 10000);
        } catch (e) {
            showAlertModal('Camera access denied.', { title: 'Camera Error', icon: 'camera-video-off-fill', type: 'danger' });
        }
    }

    function stopScanning() {
        stopCamera();
        if (scanningInterval) clearInterval(scanningInterval);
        if (liveness) { liveness.stop(); liveness = null; }
        isScanning = false;
        document.getElementById('startScanBtn').classList.remove('d-none');
        document.getElementById('stopScanBtn').classList.add('d-none');
    }

    async function autoScan() {
        if (!isScanning) return;

        // Anti-spoofing: require a verified live person (blink + motion)
        // before sending a frame. Blocks photos / ID pictures.
        if (liveness && !liveness.isLive()) {
            return;
        }

        const imageBase64 = captureFrameInZone('gateVideoOut', 0.15);
        if (!imageBase64) return;

        try {
            const formData = new FormData();
            formData.append('csrf_token', document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '');
            formData.append('action', 'recognize_attendance');
            formData.append('image', imageBase64);
            formData.append('session_id', document.getElementById('scanSessionId')?.value || '');
            formData.append('session_type', 'time_out');

            const response = await fetch(window.BASE_URL + '/api/gate.php', {
                method: 'POST',
                credentials: 'same-origin',
                body: formData
            });
            const data = await response.json();
            showResult(data);
        } catch (e) {
            console.error('Scan error:', e);
        }
    }

    function showResult(data) {
        const resultDiv = document.getElementById('recognitionResult');
        const successDiv = document.getElementById('successResult');
        const duplicateDiv = document.getElementById('duplicateResult');
        const failedDiv = document.getElementById('failedResult');
        const invalidDiv = document.getElementById('invalidResult');

        resultDiv.classList.remove('d-none');

        // Hide all results first
        successDiv.style.display = 'none';
        duplicateDiv.style.display = 'none';
        failedDiv.style.display = 'none';
        invalidDiv.style.display = 'none';

        if (data.success && data.matched) {
            if (data.duplicate) {
                // Already recorded - show warning
                duplicateDiv.style.display = 'block';
                document.getElementById('duplicateName').textContent = data.student_name || '';
                document.getElementById('duplicateInfo').textContent =
                    `${data.student_id || ''} | Grade ${data.grade_level || ''}`;
                // Speak the duplicate alert
                announce((data.student_name || 'Student') + ' already marked time out, please step aside');
            } else {
                // New scan - show success
                successDiv.style.display = 'block';
                document.getElementById('matchedName').textContent = data.student_name || '';
                document.getElementById('matchedInfo').textContent =
                    `ID: ${data.student_id || ''} | Grade ${data.grade_level || ''}`;
                document.getElementById('confidence').textContent = data.confidence || '0';
                addScanToLog(data);
                // Require a fresh blink before the next student is recorded.
                if (liveness) liveness.reset();
            }
        } else if (data.invalid) {
            // Invalid attendance - no time-in record
            invalidDiv.style.display = 'block';
            document.getElementById('invalidName').textContent = data.student_name || '';
            document.getElementById('invalidInfo').textContent =
                `${data.student_id || ''} | Grade ${data.grade_level || ''}`;
            // Speak the invalid alert
            announce((data.student_name || 'Student') + ' has no time in record, cannot mark time out');
        } else {
            failedDiv.style.display = 'block';
            const failedSmall = failedDiv.querySelector('small');
            if (failedSmall && data.quality_warnings && data.quality_warnings.length > 0) {
                failedSmall.textContent = data.quality_warnings.join(' | ');
            }
            // Speak the recognition failure alert
            const failMsg = data.error || data.message || 'Face not recognized, please try again';
            announce('Face not recognized, ' + failMsg);
        }
        setTimeout(() => resultDiv.classList.add('d-none'), 3000);
    }

    function addScanToLog(data) {
        const list = document.getElementById('liveScanList');
        const status = data.status || 'present';
        const recordId = data.record_id || '';
        const sessionId = data.session_id || (document.getElementById('scanSessionId')?.value || '');
        const sessionType = data.session_type || 'time_out';
        const color = gateStatusColorMap[status] || 'ad-danger';
        const icon  = gateStatusIconMap[status] || 'x';
        const label = status.charAt(0).toUpperCase() + status.slice(1);

        // Remove "No scans yet" placeholder if present
        const placeholder = list.querySelector('.text-center.text-muted');
        if (placeholder) placeholder.remove();

        const div = document.createElement('div');
        div.className = 'scan-item d-flex align-items-center p-3 border-bottom';
        div.style.animation = 'fadeIn 0.3s';
        if (recordId) div.setAttribute('data-record-id', recordId);
        div.setAttribute('data-student-id', data.student_id || '');
        div.setAttribute('data-name', data.student_name || 'Unknown');
        div.setAttribute('data-status', status);
        if (sessionId) div.setAttribute('data-session-id', sessionId);
        div.setAttribute('data-session-type', sessionType);
        div.innerHTML = `
            <div class="me-3">
                <div class="rounded-circle d-flex align-items-center justify-content-center"
                     style="width:40px;height:40px;background:var(--${color}-light);">
                    <i class="bi bi-${icon}" style="color:var(--${color});"></i>
                </div>
            </div>
            <div class="flex-grow-1">
                <div class="fw-600" style="font-size:13px;">${data.student_name || 'Unknown'}</div>
                <small class="text-muted">${data.student_id || ''} | Grade ${data.grade_level || ''}</small>
            </div>
            <div class="text-end">
                <span class="badge-status badge-${status}">${label}</span>
                <div style="font-size:11px;color:#999;">
                    ${new Date().toLocaleTimeString('en-US', {hour:'2-digit', minute:'2-digit'})}
                </div>
                <button type="button" class="btn btn-icon btn-sm btn-outline-primary mt-1 edit-status-btn" title="Edit status" style="width:26px;height:26px;font-size:11px;padding:0;">
                    <i class="bi bi-pencil"></i>
                </button>
            </div>
        `;
        list.insertBefore(div, list.firstChild);

        announce((data.student_name || 'Student') + ' marked time out');

        document.getElementById('scanCount').textContent = parseInt(document.getElementById('scanCount').textContent) + 1 + ' scans';
    }
</script>
<?php endif; ?>

<!-- Modal: Manual LRN Input -->
<div class="event-modal-overlay" id="manualLrnModal">
    <div class="event-modal">
        <div class="event-modal-header">
            <div class="event-modal-title"><i class="bi bi-keyboard"></i><span>Manual Time-Out</span></div>
            <button class="event-modal-close" id="manualLrnClose"><i class="bi bi-x-lg"></i></button>
        </div>
        <form id="manualAttendanceForm" onsubmit="submitManualAttendance(event)">
            <div class="event-modal-body">
                <p style="font-size:13px;opacity:0.6;margin-bottom:12px">Enter student LRN manually if face recognition is not working.</p>
                <div class="evt-field">
                    <label>Student LRN <span class="required">*</span></label>
                    <input type="text" id="manualLrn" placeholder="113400000001" required autofocus inputmode="numeric" maxlength="12">
                </div>
                <input type="hidden" id="manualSessionType" value="time_out">
                <div id="manualMessage"></div>
            </div>
            <div class="event-modal-footer">
                <button type="button" class="evt-btn evt-btn-cancel" id="manualLrnCancel">Cancel</button>
                <button type="submit" class="evt-btn evt-btn-save" id="manualLrnSave"><i class="bi bi-check-circle me-1"></i> Submit Time-Out</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Session Auto-End Notification -->
<div class="event-modal-overlay" id="sessionAutoEndOverlay">
    <div class="event-modal">
        <div class="event-modal-header">
            <div class="event-modal-title"><i class="bi bi-exclamation-triangle-fill" style="color:var(--ad-warning);"></i><span>Session Auto-End Warning</span></div>
        </div>
        <div class="event-modal-body">
            <p style="font-size:13px;opacity:0.8;margin-bottom:8px">The session auto-end threshold has been exceeded.</p>
            <p style="font-size:13px;opacity:0.8;margin-bottom:12px">All unmarked students will be marked as absent.</p>
            <div style="background:var(--ad-warning-light);border-radius:var(--ad-radius-sm);padding:12px 16px;text-align:center;">
                <span style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;opacity:0.7;">Session will end automatically in</span>
                <div id="autoEndCountdown" style="font-size:28px;font-weight:800;font-family:var(--ad-mono);color:var(--ad-warning);margin-top:4px;">60</div>
                <span style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;opacity:0.7;">seconds</span>
            </div>
        </div>
        <div class="event-modal-footer">
            <button type="button" class="evt-btn evt-btn-save" id="confirmAutoEndBtn" style="background:var(--ad-warning);color:#fff;"><i class="bi bi-check-circle me-1"></i> OK — End Session Now</button>
        </div>
    </div>
</div>

<!-- Modal: End Session Confirmation -->
<div class="event-modal-overlay" id="endSessionOverlay">
    <div class="event-modal">
        <div class="event-modal-header">
            <div class="event-modal-title"><i class="bi bi-exclamation-triangle-fill" style="color:var(--ad-danger);"></i><span>End Session</span></div>
            <button class="event-modal-close" id="endSessionClose"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="event-modal-body">
            <p style="font-size:13px;opacity:0.8;margin-bottom:8px">Are you sure you want to end this session?</p>
            <p style="font-size:13px;opacity:0.8">All unmarked students will be marked as absent for this session.</p>
        </div>
        <div class="event-modal-footer">
            <button type="button" class="evt-btn evt-btn-cancel" id="endSessionCancel">Cancel</button>
            <button type="button" class="evt-btn evt-btn-danger" id="endSessionConfirm"><i class="bi bi-stop-circle me-1"></i> End Session</button>
        </div>
    </div>
</div>

<!-- Modal: Edit Attendance Status -->
<div class="event-modal-overlay" id="gateEditStatusOverlay">
    <div class="event-modal">
        <div class="event-modal-header">
            <div class="event-modal-title"><i class="bi bi-pencil-square"></i><span>Edit Attendance Status</span></div>
            <button class="event-modal-close" id="gateEditStatusClose"><i class="bi bi-x-lg"></i></button>
        </div>
        <form id="gateEditStatusForm" onsubmit="submitGateEditStatus(event)">
            <div class="event-modal-body">
                <input type="hidden" id="gateEditRecordId">
                <input type="hidden" id="gateEditStudentId">
                <input type="hidden" id="gateEditSessionId">
                <input type="hidden" id="gateEditSessionType">
                <p style="font-size:13px;opacity:0.6;margin-bottom:12px">Update attendance status for <strong id="gateEditNameDisplay"></strong></p>
                <div class="evt-field">
                    <label>Status <span class="required">*</span></label>
                    <select id="gateEditSelect" required>
                        <option value="present">Present</option>
                        <option value="late">Late</option>
                        <option value="absent">Absent</option>
                    </select>
                </div>
                <div id="gateEditMessage"></div>
            </div>
            <div class="event-modal-footer">
                <button type="button" class="evt-btn evt-btn-cancel" id="gateEditStatusCancel">Cancel</button>
                <button type="submit" class="evt-btn evt-btn-save"><i class="bi bi-check-circle me-1"></i> Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
// Manual attendance submission
async function submitManualAttendance(event) {
    event.preventDefault();
    
    const studentId = document.getElementById('manualLrn').value.trim();
    const sessionType = document.getElementById('manualSessionType').value;
    const msg = document.getElementById('manualMessage');

    if (!studentId) {
        msg.innerHTML = '<div class="alert alert-danger">Please enter a student LRN.</div>';
        return;
    }

    msg.innerHTML = '<div class="alert alert-info"><span class="spinner-border spinner-border-sm"></span> Processing...</div>';

    try {
        const formData = new FormData();
        formData.append('csrf_token', document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '');
        formData.append('action', 'manual_attendance');
        formData.append('student_id', studentId);
        formData.append('session_id', document.getElementById('scanSessionId')?.value || '');
        formData.append('session_type', sessionType);

        const response = await fetch(window.BASE_URL + '/api/gate.php', {
            method: 'POST',
            credentials: 'same-origin',
            body: formData
        });
        
        const contentType = response.headers.get('content-type');
        if (!contentType || !contentType.includes('application/json')) {
            throw new Error('Server returned non-JSON response. Status: ' + response.status);
        }
        
        const data = await response.json();

            if (data.success) {
            if (data.duplicate) {
                msg.innerHTML = `
                    <div class="alert alert-warning">
                        <div class="d-flex align-items-center mb-2">
                            <i class="bi bi-exclamation-triangle-fill me-2" style="font-size:20px;"></i>
                            <strong>Already Recorded Today</strong>
                        </div>
                        <div class="ps-4">
                            <div class="fw-bold">${data.student_name || ''}</div>
                            <small class="text-muted">
                                LRN: ${data.student_id || ''} | Grade ${data.grade_level || ''}<br>
                                Status: ⏰ Time-Out
                            </small>
                        </div>
                    </div>`;
                announce((data.student_name || 'Student') + ' already marked time out today');
            } else {
                msg.innerHTML = `
                    <div class="alert alert-success">
                        <div class="d-flex align-items-center mb-2">
                            <i class="bi bi-check-circle-fill me-2" style="font-size:20px;"></i>
                            <strong>Time-Out Recorded!</strong>
                        </div>
                        <div class="ps-4">
                            <div class="fw-bold">${data.student_name || ''}</div>
                            <small class="text-muted">
                                LRN: ${data.student_id || ''} | Grade ${data.grade_level || ''}
                            </small>
                        </div>
                    </div>`;
                
                addScanToLog({
                    student_name: data.student_name || 'Student',
                    student_id: studentId,
                    grade_level: data.grade_level || '',
                    status: data.status || 'present',
                    record_id: data.record_id || '',
                    session_id: data.session_id || (document.getElementById('scanSessionId')?.value || ''),
                    session_type: data.session_type || 'time_out'
                });
                
                document.getElementById('manualLrn').value = '';
                
                setTimeout(() => {
                    const modal = document.getElementById('manualLrnModal');
                    if (modal) {
                        modal.classList.remove('show');
                        document.body.style.overflow = '';
                    }
                    msg.innerHTML = '';
                }, 2000);
            }
        } else if (data.invalid) {
            msg.innerHTML = `
                <div class="alert alert-danger">
                    <div class="d-flex align-items-center mb-2">
                        <i class="bi bi-exclamation-octagon-fill me-2" style="font-size:20px;"></i>
                        <strong>Invalid Attendance</strong>
                    </div>
                    <div class="ps-4">
                        <div class="fw-bold">${data.student_name || ''}</div>
                        <small class="text-muted">
                            LRN: ${data.student_id || ''} | Grade ${data.grade_level || ''}<br>
                            <i class="bi bi-shield-lock"></i> Student must time-in first before time-out
                        </small>
                    </div>
                    </div>`;
            announce((data.student_name || 'Student') + ', you must time in first before time out');
        } else {
            msg.innerHTML = '<div class="alert alert-danger"><i class="bi bi-exclamation-circle"></i> ' + (data.error || data.message || 'Failed to record time-out') + '</div>';
            announce('Student not found or invalid, please check and try again');
        }
    } catch (e) {
        msg.innerHTML = '<div class="alert alert-danger">Network error. Please try again.</div>';
        announce('Network error, please try again');
    }
}

function openManualLrnModal() {
    const modal = document.getElementById('manualLrnModal');
    if (modal) {
        modal.classList.add('show');
        document.body.style.overflow = 'hidden';
        const input = document.getElementById('manualLrn');
        if (input) {
            input.value = '';
            setTimeout(() => input.focus(), 200);
        }
        document.getElementById('manualMessage').innerHTML = '';
    }
}

var manualLrnModal = document.getElementById('manualLrnModal');
manualLrnModal.addEventListener('click', function (e) {
    if (e.target === manualLrnModal) {
        manualLrnModal.classList.remove('show');
        document.body.style.overflow = '';
    }
});
document.getElementById('manualLrnClose').addEventListener('click', function () {
    manualLrnModal.classList.remove('show');
    document.body.style.overflow = '';
    document.getElementById('manualLrn').value = '';
    document.getElementById('manualMessage').innerHTML = '';
});
document.getElementById('manualLrnCancel').addEventListener('click', function () {
    manualLrnModal.classList.remove('show');
    document.body.style.overflow = '';
    document.getElementById('manualLrn').value = '';
    document.getElementById('manualMessage').innerHTML = '';
});
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && manualLrnModal.classList.contains('show')) {
            manualLrnModal.classList.remove('show');
            document.body.style.overflow = '';
            document.getElementById('manualLrn').value = '';
            document.getElementById('manualMessage').innerHTML = '';
        }
    });

    // ============================================
    // SESSION AUTO-END MODAL
    // ============================================
    let autoEndTimer = null;
    let autoEndCountdownInterval = null;

    function showAutoEndModal(sessionId) {
        const overlay = document.getElementById('sessionAutoEndOverlay');
        if (!overlay) return;
        overlay.classList.add('show');
        document.body.style.overflow = 'hidden';

        const countdownEl = document.getElementById('autoEndCountdown');
        let secondsLeft = 60;

        if (countdownEl) countdownEl.textContent = secondsLeft;

        autoEndCountdownInterval = setInterval(function() {
            secondsLeft--;
            if (countdownEl) countdownEl.textContent = secondsLeft;
            if (secondsLeft <= 0) {
                clearInterval(autoEndCountdownInterval);
                autoEndCountdownInterval = null;
                confirmAutoEnd(sessionId);
            }
        }, 1000);

        const confirmBtn = document.getElementById('confirmAutoEndBtn');
        if (confirmBtn) {
            confirmBtn.onclick = function() {
                if (autoEndCountdownInterval) { clearInterval(autoEndCountdownInterval); autoEndCountdownInterval = null; }
                confirmAutoEnd(sessionId);
            };
        }
    }

    function hideAutoEndModal() {
        const overlay = document.getElementById('sessionAutoEndOverlay');
        if (overlay) overlay.classList.remove('show');
        document.body.style.overflow = '';
        if (autoEndCountdownInterval) { clearInterval(autoEndCountdownInterval); autoEndCountdownInterval = null; }
    }

function confirmAutoEnd(sessionId) {
        hideAutoEndModal();
        const formData = new FormData();
        formData.append('csrf_token', document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '');
        formData.append('action', 'confirm_auto_end');
        formData.append('session_id', sessionId);

        fetch(window.BASE_URL + '/api/gate.php', {
            method: 'POST',
            body: formData
        })
        .then(r => r.json())
        .then(data => {
            window.location.reload();
        })
        .catch(() => {
            window.location.reload();
        });
    }

    // ============================================
    // END SESSION MODAL
    // ============================================
    function openEndModal(sessionId) {
        const overlay = document.getElementById('endSessionOverlay');
        if (!overlay) return;
        overlay.classList.add('show');
        document.body.style.overflow = 'hidden';
        document.getElementById('endSessionId').value = sessionId;
    }

    function closeEndModal() {
        const overlay = document.getElementById('endSessionOverlay');
        if (overlay) overlay.classList.remove('show');
        document.body.style.overflow = '';
    }

    document.getElementById('endSessionClose').addEventListener('click', closeEndModal);
    document.getElementById('endSessionCancel').addEventListener('click', closeEndModal);
    document.getElementById('endSessionOverlay').addEventListener('click', function (e) {
        if (e.target === this) closeEndModal();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && document.getElementById('endSessionOverlay').classList.contains('show')) {
            closeEndModal();
        }
    });
    document.getElementById('endSessionConfirm').addEventListener('click', function () {
        const sessionId = document.getElementById('endSessionId').value;
        closeEndModal();
        const form = document.getElementById('endSessionForm');
        if (form) {
            const input = form.querySelector('input[name="session_id"]');
            if (input) input.value = sessionId;
            form.submit();
        }
    });

    // ============================================
    // EDIT ATTENDANCE STATUS (detected student)
    // ============================================
    function openGateEditModal(recordId, studentId, studentName, currentStatus, sessionId, sessionType) {
        const overlay = document.getElementById('gateEditStatusOverlay');
        if (!overlay) return;

        if (!recordId) {
            showAlertModal('Unable to edit: missing attendance record.', { title: 'Edit Status', icon: 'exclamation-triangle', type: 'warning' });
            return;
        }

        document.getElementById('gateEditRecordId').value = recordId || '';
        document.getElementById('gateEditStudentId').value = studentId || '';
        document.getElementById('gateEditSessionId').value = sessionId || (document.getElementById('scanSessionId')?.value || '');
        document.getElementById('gateEditSessionType').value = sessionType || 'time_out';
        document.getElementById('gateEditNameDisplay').textContent = studentName || 'Student';
        document.getElementById('gateEditSelect').value = currentStatus || 'present';
        document.getElementById('gateEditMessage').innerHTML = '';

        overlay.classList.add('show');
        document.body.style.overflow = 'hidden';
    }

    function hideGateEditModal() {
        const overlay = document.getElementById('gateEditStatusOverlay');
        if (overlay) overlay.classList.remove('show');
        document.body.style.overflow = '';
    }

    function submitGateEditStatus(event) {
        event.preventDefault();

        const recordId = document.getElementById('gateEditRecordId').value;
        const studentId = document.getElementById('gateEditStudentId').value;
        const sessionId = document.getElementById('gateEditSessionId').value;
        const sessionType = document.getElementById('gateEditSessionType').value;
        const newStatus = document.getElementById('gateEditSelect').value;
        const msgEl = document.getElementById('gateEditMessage');

        if (!recordId || !studentId || !sessionId || !newStatus) {
            msgEl.innerHTML = '<div class="alert alert-danger">Invalid parameters.</div>';
            return;
        }

        msgEl.innerHTML = '<div class="alert alert-info"><span class="spinner-border spinner-border-sm"></span> Updating...</div>';

        const formData = new FormData();
        formData.append('csrf_token', document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '');
        formData.append('action', 'update_status');
        formData.append('record_id', recordId);
        formData.append('student_id', studentId);
        formData.append('session_id', sessionId);
        formData.append('session_type', sessionType);
        formData.append('status', newStatus);

        fetch(window.BASE_URL + '/api/gate.php', {
            method: 'POST',
            body: formData
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                const row = document.querySelector('#liveScanList [data-record-id="' + recordId + '"]');
                if (row) {
                    row.setAttribute('data-status', newStatus);
                    const color = (typeof gateStatusColorMap !== 'undefined' && gateStatusColorMap[newStatus]) ? gateStatusColorMap[newStatus] : 'ad-danger';
                    const icon  = (typeof gateStatusIconMap !== 'undefined' && gateStatusIconMap[newStatus]) ? gateStatusIconMap[newStatus] : 'x';
                    const label = newStatus.charAt(0).toUpperCase() + newStatus.slice(1);
                    const avatar = row.querySelector('.rounded-circle');
                    if (avatar) {
                        avatar.style.background = 'var(--' + color + '-light)';
                        const i = avatar.querySelector('i');
                        if (i) { i.className = 'bi bi-' + icon; i.style.color = 'var(--' + color + ')'; }
                    }
                    const badge = row.querySelector('.badge-status');
                    if (badge) {
                        badge.className = 'badge-status badge-' + newStatus;
                        badge.textContent = label;
                    }
                }
                msgEl.innerHTML = '<div class="alert alert-success">Status updated successfully.</div>';
                setTimeout(hideGateEditModal, 800);
            } else {
                msgEl.innerHTML = '<div class="alert alert-danger">' + (data.error || 'Failed to update status.') + '</div>';
            }
        })
        .catch(function () {
            msgEl.innerHTML = '<div class="alert alert-danger">Network error. Please try again.</div>';
        });
    }

    document.getElementById('gateEditStatusClose').addEventListener('click', hideGateEditModal);
    document.getElementById('gateEditStatusCancel').addEventListener('click', hideGateEditModal);
    document.getElementById('gateEditStatusOverlay').addEventListener('click', function (e) {
        if (e.target === this) hideGateEditModal();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && document.getElementById('gateEditStatusOverlay').classList.contains('show')) {
            hideGateEditModal();
        }
    });

    // Delegated handler so both server-rendered and dynamically added rows work
    const gateLiveLog = document.getElementById('liveScanList');
    if (gateLiveLog) {
        gateLiveLog.addEventListener('click', function (e) {
            const btn = e.target.closest('.edit-status-btn');
            if (!btn) return;
            const row = btn.closest('.scan-item');
            if (!row) return;
            openGateEditModal(
                row.getAttribute('data-record-id') || '',
                row.getAttribute('data-student-id') || '',
                row.getAttribute('data-name') || '',
                row.getAttribute('data-status') || 'present',
                row.getAttribute('data-session-id') || (document.getElementById('scanSessionId')?.value || ''),
                row.getAttribute('data-session-type') || 'time_out'
            );
        });
    }
  </script>
<!-- MediaPipe FaceMesh (liveness / anti-spoofing) -->
<script>window.FACE_MESH_BASE = '<?= BASE_URL ?>/assets/vendor/face_mesh';</script>
<script src="<?= BASE_URL ?>/assets/vendor/face_mesh/face_mesh.js"></script>
<script src="<?= BASE_URL ?>/assets/js/liveness.js?v=<?= @filemtime(__DIR__ . '/../assets/js/liveness.js') ?: time() ?>"></script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
