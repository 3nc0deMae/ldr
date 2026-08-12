<?php
/**
 * LDB-FRAS - Offline Attendance Sync API
 * =======================================
 * Receives attendance logs queued in the kiosk's IndexedDB while it was
 * OFFLINE and inserts them into the Railway MySQL database when connectivity
 * is restored.
 *
 * Request (JSON body):
 *   {
 *     "csrf_token": "...",
 *     "logs": [
 *       {
 *         "sync_guid":   "uuid generated on device",
 *         "student_id":  "2024-0001 (display LRN)",
 *         "session_id":  12,
 *         "session_type":"time_in" | "time_out",
 *         "scan_time":   "Y-m-d H:i:s",
 *         "status":      "present" | "late",
 *         "confidence":  98.5
 *       }
 *     ]
 *   }
 *
 * Response:
 *   { "success": true, "synced": 3, "duplicates": 1, "synced_ids": [...] }
 *
 * Idempotency:
 *   - attendance_records.sync_guid has a UNIQUE index (added by config.php
 *     migration), so re-sending a log cannot create duplicates.
 *   - A student/session/session_type duplicate check mirrors the online flow.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../send_notification_helper.php';

header('Content-Type: application/json');

// Only authenticated gate/admin users can post attendance.
requireLogin();

if (!in_array($_SESSION['user_role'] ?? '', ['admin', 'gate'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden.']);
    exit;
}

// The kiosk sends a JSON body; surface its CSRF token to the middleware.
$raw = file_get_contents('php://input');
$body = json_decode($raw, true);
if (is_array($body) && isset($body['csrf_token'])) {
    $_POST['csrf_token'] = $body['csrf_token'];
}

csrfMiddleware(true);

if (!is_array($body) || empty($body['logs']) || !is_array($body['logs'])) {
    echo json_encode(['success' => false, 'error' => 'No logs provided.']);
    exit;
}

$logs = array_slice($body['logs'], 0, 500);

$synced = 0;
$duplicates = 0;
$syncedIds = [];
$failed = 0;

foreach ($logs as $log) {
    if (!is_array($log)) continue;

    $syncGuid   = sanitize($log['sync_guid'] ?? '');
    $studentLrn = sanitize($log['student_id'] ?? '');
    $sessionId  = intval($log['session_id'] ?? 0);
    $sessionType = sanitize($log['session_type'] ?? 'time_in');
    $scanTime   = sanitize($log['scan_time'] ?? '');
    $status     = sanitize($log['status'] ?? 'present');
    $confidence = floatval($log['confidence'] ?? 0);

    if (empty($syncGuid) || empty($studentLrn) || empty($scanTime)) {
        $failed++;
        continue;
    }
    if (!in_array($sessionType, ['time_in', 'time_out'], true)) $sessionType = 'time_in';
    if (!in_array($status, ['present', 'late'], true)) $status = 'present';

    // Normalize scan_time into a MySQL datetime.
    $scanTime = date('Y-m-d H:i:s', strtotime($scanTime));

    // Resolve the student by display LRN.
    $stmt = $db->prepare(
        "SELECT id FROM students WHERE student_id = ? AND status = 'active'"
    );
    $stmt->execute([$studentLrn]);
    $student = $stmt->fetch();
    if (!$student) {
        $failed++;
        continue;
    }
    $studentDbId = $student['id'];

    // A valid gate session is required for a meaningful record.
    if ($sessionId > 0) {
        $stmt = $db->prepare("SELECT id FROM gate_sessions WHERE id = ?");
        $stmt->execute([$sessionId]);
        if (!$stmt->fetch()) {
            $failed++;
            continue;
        }
    } else {
        $failed++;
        continue;
    }

    // Duplicate prevention 1: the device GUID was already synced.
    if ($syncGuid !== '') {
        $stmt = $db->prepare("SELECT id FROM attendance_records WHERE sync_guid = ?");
        $stmt->execute([$syncGuid]);
        if ($stmt->fetch()) {
            $duplicates++;
            $syncedIds[] = $syncGuid;
            continue;
        }
    }

    // Duplicate prevention 2: same student/session/session_type already recorded
    // (mirrors the online recognize_attendance/manual_attendance check).
    $stmt = $db->prepare(
        "SELECT id FROM attendance_records
         WHERE student_id = ? AND gate_session_id = ? AND session_type = ?"
    );
    $stmt->execute([$studentDbId, $sessionId, $sessionType]);
    if ($stmt->fetch()) {
        $duplicates++;
        $syncedIds[] = $syncGuid;
        continue;
    }

    try {
        $stmt = $db->prepare(
            "INSERT INTO attendance_records
                (student_id, gate_session_id, session_type, scan_time, status,
                 confidence_score, notes, sync_guid, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())"
        );
        $stmt->execute([
            $studentDbId,
            $sessionId,
            $sessionType,
            $scanTime,
            $status,
            ($confidence > 0 ? $confidence : null),
            'synced from offline kiosk',
            $syncGuid
        ]);
        $synced++;
        $syncedIds[] = $syncGuid;

        // Notify the guardian (best-effort, matches online behavior).
        try {
            dispatchParentNotification($db, $studentDbId, strtoupper($sessionType) === 'TIME_OUT' ? 'TIME_OUT' : 'TIME_IN', [
                'subject_name' => 'Gate (offline sync)'
            ]);
        } catch (Exception $e) {
            error_log('Offline sync notification error: ' . $e->getMessage());
        }
    } catch (Exception $e) {
        error_log('Offline attendance sync insert error: ' . $e->getMessage());
        $failed++;
    }
}

echo json_encode([
    'success'    => true,
    'synced'     => $synced,
    'duplicates' => $duplicates,
    'failed'     => $failed,
    'synced_ids' => $syncedIds
]);
