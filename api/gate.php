<?php


require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/face_api.php';
require_once __DIR__ . '/../send_notification_helper.php';

header('Content-Type: application/json');

// Check authentication
requireLogin();

csrfMiddleware(true);

$db = getDB();

error_log("GATE API LOADED action=" . ($_POST['action'] ?? $_GET['action'] ?? 'none') . " helper_loaded=" . (function_exists('dispatchParentNotification') ? 'yes' : 'NO'));
$action = sanitize($_POST['action'] ?? $_GET['action'] ?? '');

try {
    switch ($action) {

        // ============================================
        // START GATE SESSION
        // ============================================
        case 'start_session':
            requireRole(['admin', 'gate']);

            $sessionType = sanitize($_POST['session_type'] ?? 'time_in');
            if (!in_array($sessionType, ['time_in', 'time_out'], true)) {
                $sessionType = 'time_in';
            }
            // Auto-select the session period from the current time (AM = morning, PM = afternoon)
            $sessionPeriod = getGateSessionPeriod($db, $sessionType);
            $startTime     = date('Y-m-d H:i:s');
            $endTime       = sanitize($_POST['end_time'] ?? '');
            $lateThreshold = intval($_POST['late_threshold'] ?? 15);

            if (empty($endTime)) {
                echo json_encode([
                    'success' => false,
                    'error'   => 'End time is required.'
                ]);
                exit;
            }

            $now   = time();
            $endTs = strtotime(date('Y-m-d') . ' ' . $endTime . ':00');

            if ($endTs === false) {
                echo json_encode([
                    'success' => false,
                    'error'   => 'Invalid end time format.'
                ]);
                exit;
            }

            // End times are plain clock times anchored to today. A time
            // earlier than "now" means the session ends after midnight
            // (e.g. starts 11:30 PM, ends 12:30 AM), so roll it over to
            // tomorrow. The 60s grace absorbs form submission delay.
            if ($endTs < ($now - 60)) {
                $endTs += 86400;
            }

            $endTime = date('Y-m-d H:i:s', $endTs);

            $stmt = $db->prepare(
                "SELECT id FROM gate_sessions 
                 WHERE session_type = ? AND session_period = ? AND DATE(start_time) = CURDATE()
                   AND status = 'active'"
            );
            $stmt->execute([$sessionType, $sessionPeriod]);
            if ($stmt->fetch()) {
                error_log("GATE API: start_session rejected - $sessionType/$sessionPeriod session already active today");
                echo json_encode([
                    'success' => false,
                    'error'   => 'A ' . $sessionPeriod . ' ' . $sessionType . ' session is currently active. End it before starting a new one.'
                ]);
                exit;
            }

            $stmt = $db->prepare(
                "INSERT INTO gate_sessions 
                 (created_by, session_type, session_period, start_time, end_time, late_threshold, status, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, 'active', NOW())"
            );
            $stmt->execute([
                $_SESSION['user_id'],
                $sessionType,
                $sessionPeriod,
                $startTime,
                $endTime,
                $lateThreshold
            ]);

            $sessionId = $db->lastInsertId();
            error_log("GATE API: start_session created session_id=$sessionId type=$sessionType period=$sessionPeriod");

            // Pre-create pending attendance records for all active students
            $stmt = $db->prepare(
                "INSERT IGNORE INTO attendance_records 
                 (student_id, gate_session_id, session_type, scan_time, status, created_at)
                 SELECT id, ?, ?, ?, 'pending', NOW()
                 FROM students
                 WHERE status = 'active'"
            );
            $stmt->execute([$sessionId, $sessionType, $startTime]);
            $pendingCount = $stmt->rowCount();
            error_log("GATE API: start_session created $pendingCount pending records for session_id=$sessionId");

            // Log the action
            $stmt = $db->prepare(
                "INSERT INTO audit_logs (user_id, action, description, ip_address, created_at)
                 VALUES (?, 'gate_session_start', ?, ?, NOW())"
            );
            $stmt->execute([
                $_SESSION['user_id'],
                "Started {$sessionType} session from {$startTime} to {$endTime}",
                $_SERVER['REMOTE_ADDR'] ?? ''
            ]);

            $redirectUrl = $sessionType === 'time_in' ? '/gate/timein.php' : '/gate/timeout.php';
            redirect($redirectUrl, ucfirst(str_replace('_', ' ', $sessionType)) . ' session started successfully.', 'success');
            break;

        // ============================================
        // CONFIRM AUTO END (after modal acknowledgment)
        // ============================================
        case 'confirm_auto_end':
            $sessionId = intval($_POST['session_id'] ?? 0);
            if (!$sessionId) {
                echo json_encode(['success' => false, 'error' => 'Invalid session.']);
                exit;
            }

            $autoEnded = autoEndGateSessionIfLate($db, $sessionId);
            if ($autoEnded) {
                echo json_encode([
                    'success' => true,
                    'status' => 'completed',
                    'auto_ended' => true,
                    'message' => 'Session auto-ended. All unmarked students marked absent.'
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'error' => 'Session could not be auto-ended. It may have already ended or the condition is no longer met.'
                ]);
            }
            exit;

        // ============================================
        // CHECK SESSION STATUS (auto-end if late threshold exceeded)
        // ============================================
        case 'check_session_status':
            $sessionId = intval($_POST['session_id'] ?? 0);
            if (!$sessionId) {
                echo json_encode(['success' => false, 'error' => 'Invalid session.', 'auto_ended' => false]);
                exit;
            }

            $stmt = $db->prepare("SELECT * FROM gate_sessions WHERE id = ?");
            $stmt->execute([$sessionId]);
            $session = $stmt->fetch();

            if (!$session) {
                echo json_encode(['success' => false, 'error' => 'Session not found.', 'auto_ended' => false]);
                exit;
            }

            if ($session['status'] === 'completed' || $session['status'] === 'cancelled') {
                echo json_encode(['success' => true, 'status' => $session['status'], 'auto_ended' => false]);
                exit;
            }

            $sessionEnd = strtotime($session['end_time']);
            $now = time();
            $minutesAfterEnd = max(0, ($now - $sessionEnd) / 60);

            if ($minutesAfterEnd > intval($session['late_threshold'])) {
                echo json_encode([
                    'success' => true,
                    'status' => 'active',
                    'auto_end_pending' => true,
                    'auto_end_at' => date('c', $sessionEnd + intval($session['late_threshold']) * 60),
                    'minutes_late' => round($minutesAfterEnd),
                    'late_threshold' => intval($session['late_threshold']),
                    'message' => 'Session auto-end threshold exceeded. Please acknowledge to end session.'
                ]);
                exit;
            }

            echo json_encode([
                'success' => true,
                'status' => 'active',
                'auto_ended' => false,
                'minutes_remaining' => max(0, intval($session['late_threshold']) - round($minutesAfterEnd))
            ]);
            exit;

        // ============================================
        // END GATE SESSION
        // ============================================
        case 'end_session':
            requireRole(['admin', 'gate']);

            $sessionId = intval($_POST['session_id'] ?? 0);
            if (!$sessionId) {
                echo json_encode(['success' => false, 'error' => 'Invalid session ID.']);
                exit;
            }

            $stmt = $db->prepare("SELECT * FROM gate_sessions WHERE id = ? AND status = 'active'");
            $stmt->execute([$sessionId]);
            $session = $stmt->fetch();

            if (!$session) {
                echo json_encode(['success' => false, 'error' => 'No active session found.']);
                exit;
            }

            // Mark session as completed
            $stmt = $db->prepare(
                "UPDATE gate_sessions SET status = 'completed', ended_at = NOW() WHERE id = ?"
            );
            $stmt->execute([$sessionId]);

            // Mark pending students as absent
            $sessionType = $session['session_type'];
            $stmt = $db->prepare(
                "SELECT id, student_id FROM attendance_records
                 WHERE gate_session_id = ? AND session_type = ? AND status = 'pending'"
            );
            $stmt->execute([$sessionId, $sessionType]);
            $pendingRecords = $stmt->fetchAll();

            $absentCount = 0;
            foreach ($pendingRecords as $record) {
                $stmt = $db->prepare(
                    "UPDATE attendance_records 
                     SET status = 'absent', scan_time = NOW(), created_at = NOW()
                     WHERE id = ?"
                );
                $stmt->execute([$record['id']]);
                $absentCount++;

                try {
                    dispatchParentNotification($db, $record['student_id'], 'GATE_ABSENT', [
                        'subject_name' => $session['session_type'] ?? 'Gate'
                    ]);
                } catch (Exception $e) {
                    error_log("Gate absence notification error: " . $e->getMessage());
                }
            }

            // Backward compatibility: mark any students without records as absent
            $today = date('Y-m-d');
            $stmt = $db->prepare(
                "SELECT s.id FROM students s
                 WHERE s.status = 'active'
                 AND s.id NOT IN (
                     SELECT student_id FROM attendance_records
                     WHERE DATE(scan_time) = ? AND session_type = ? AND gate_session_id = ?
                 )"
            );
            $stmt->execute([$today, $sessionType, $sessionId]);
            $absentStudents = $stmt->fetchAll(PDO::FETCH_COLUMN);

            foreach ($absentStudents as $studentId) {
                try {
                    $stmt = $db->prepare(
                        "INSERT INTO attendance_records 
                         (student_id, gate_session_id, session_type, scan_time, status, created_at)
                         VALUES (?, ?, ?, NOW(), 'absent', NOW())"
                    );
                    $stmt->execute([$studentId, $sessionId, $sessionType]);
                    $absentCount++;

                    try {
                        dispatchParentNotification($db, $studentId, 'GATE_ABSENT', [
                            'subject_name' => $session['session_type'] ?? 'Gate'
                        ]);
                    } catch (Exception $e) {
                        error_log("Gate absence notification error: " . $e->getMessage());
                    }
                } catch (Exception $e) {
                    // Skip if duplicate
                }
            }

            // Log
            $stmt = $db->prepare(
                "INSERT INTO audit_logs (user_id, action, description, ip_address, created_at)
                 VALUES (?, 'gate_session_end', ?, ?, NOW())"
            );
            $stmt->execute([
                $_SESSION['user_id'],
                "Ended {$sessionType} session. {$absentCount} students marked absent.",
                $_SERVER['REMOTE_ADDR'] ?? ''
            ]);

            $redirectUrl = $sessionType === 'time_in' ? '/gate/timein.php' : '/gate/timeout.php';
            redirect($redirectUrl, "Session ended. {$absentCount} students marked absent.", 'success');
            break;

        // ============================================
        // RECOGNIZE FACE FOR ATTENDANCE
        // ============================================
        case 'recognize_attendance':
            $sessionId   = intval($_POST['session_id'] ?? 0);
            $sessionType = sanitize($_POST['session_type'] ?? 'time_in');
            $imageBase64 = $_POST['image'] ?? '';

            if (empty($imageBase64)) {
                echo json_encode(['success' => false, 'error' => 'No image provided.']);
                exit;
            }

            // Get session info
            $stmt = $db->prepare("SELECT * FROM gate_sessions WHERE id = ? AND status = 'active'");
            $stmt->execute([$sessionId]);
            $session = $stmt->fetch();

            if (!$session) {
                echo json_encode(['success' => false, 'error' => 'No active session found.']);
                exit;
            }

            // Get all registered faces from database
            $stmt = $db->query(
                "SELECT sf.*, s.first_name, s.last_name, s.student_id as sid, s.grade_level, s.section
                 FROM student_faces sf
                 JOIN students s ON sf.student_id = s.id
                 WHERE sf.face_encoding IS NOT NULL"
            );
            $knownFaces = $stmt->fetchAll();

            if (empty($knownFaces)) {
                echo json_encode(['success' => false, 'error' => 'No registered faces in database.']);
                exit;
            }

            // Call Python face recognition API
            $knownFacesData = [];
            foreach ($knownFaces as $face) {
                $knownFacesData[] = [
                    'student_id' => $face['student_id'],  // This is the DB id from student_faces
                    'encoding'   => json_decode($face['face_encoding'], true)
                ];
            }

            $apiResponse = callFaceAPI('/api/recognize-face', [
                'image'       => $imageBase64,
                'known_faces' => $knownFacesData
            ]);

            if (!$apiResponse || empty($apiResponse['matched'])) {
                $qualityWarnings = $apiResponse['quality_warnings'] ?? [];
                echo json_encode([
                    'success' => false,
                    'matched' => false,
                    'error'   => $apiResponse['error'] ?? ($apiResponse['message'] ?? 'Face not recognized'),
                    'quality_warnings' => $qualityWarnings
                ]);
                exit;
            }

            // Find the matched student
            $matchedFace = null;
            foreach ($knownFaces as $face) {
                if ($face['student_id'] == $apiResponse['student_id']) {
                    $matchedFace = $face;
                    break;
                }
            }

            if (!$matchedFace) {
                error_log("GATE API: matchedFace not found for student_id=" . ($apiResponse['student_id'] ?? 'null'));
                echo json_encode(['success' => false, 'matched' => false, 'error' => 'Student not found.']);
                exit;
            }

            error_log("GATE API: matchedFace found student_id=" . $matchedFace['student_id'] . " name=" . $matchedFace['first_name'] . " " . $matchedFace['last_name']);

            // Validate time-out: student must have a time-in record today
            if ($sessionType === 'time_out') {
                $today = date('Y-m-d');
                $stmt = $db->prepare(
                    "SELECT id FROM attendance_records 
                     WHERE student_id = ? AND session_type = 'time_in' AND DATE(scan_time) = ? 
                     AND status IN ('present', 'late') LIMIT 1"
                );
                $stmt->execute([$matchedFace['student_id'], $today]);
                if (!$stmt->fetch()) {
                    echo json_encode([
                        'success'      => false,
                        'matched'      => true,
                        'invalid'      => true,
                        'student_id'   => $matchedFace['sid'],
                        'student_name' => $matchedFace['first_name'] . ' ' . $matchedFace['last_name'],
                        'grade_level'  => $matchedFace['grade_level'],
                        'error'        => 'Invalid attendance: No time-in record found. Student must time-in first before time-out.'
                    ]);
                    exit;
                }
            }

            // Determine status (present or late)
            $sessionStart = strtotime($session['start_time']);
            $lateThreshold = intval($session['late_threshold']);
            $now = time();
            $minutesLate = max(0, ($now - $sessionStart) / 60);

            $status = ($sessionType === 'time_in' && $minutesLate > $lateThreshold) ? 'late' : 'present';

            // Check for existing record
            $stmt = $db->prepare(
                "SELECT id, status FROM attendance_records 
                 WHERE student_id = ? AND gate_session_id = ? AND session_type = ?"
            );
            $stmt->execute([$matchedFace['student_id'], $sessionId, $sessionType]);
            $existing = $stmt->fetch();
            error_log("GATE API: duplicate check student_id=" . $matchedFace['student_id'] . " session_id=$sessionId session_type=$sessionType existing=" . ($existing ? 'yes(status=' . $existing['status'] . ')' : 'no'));

            if ($existing) {
                if ($existing['status'] === 'pending') {
                    error_log("GATE API: pending branch entered student_id=" . $matchedFace['student_id'] . " session_id=$sessionId");
                    $stmt = $db->prepare(
                        "UPDATE attendance_records 
                         SET scan_time = NOW(), status = ?, confidence_score = ?, created_at = NOW()
                         WHERE id = ?"
                    );
                    $stmt->execute([$status, $apiResponse['confidence'] ?? 0, $existing['id']]);

                    try {
                        $event = ($sessionType === 'time_in') ? 'TIME_IN' : 'TIME_OUT';
                        error_log("GATE API: pending branch about to dispatch student_id=" . $matchedFace['student_id'] . " event=$event");
                        dispatchParentNotification($db, $matchedFace['student_id'], $event, [
                            'subject_name' => $session['session_type'] ?? 'Gate'
                        ]);
                        error_log("GATE API: pending branch dispatch returned student_id=" . $matchedFace['student_id']);
                    } catch (Exception $e) {
                        error_log("Gate pending notification dispatch error: " . $e->getMessage());
                    }

                    echo json_encode([
                        'success'      => true,
                        'matched'      => true,
                        'updated'      => true,
                        'duplicate'    => false,
                        'student_id'   => $matchedFace['sid'],
                        'student_name' => $matchedFace['first_name'] . ' ' . $matchedFace['last_name'],
                        'grade_level'  => $matchedFace['grade_level'],
                        'section'      => $matchedFace['section'] ?? '',
                        'status'       => $status,
                        'confidence'   => $apiResponse['confidence'] ?? 0,
                        'record_id'    => $existing['id'],
                        'session_id'   => $sessionId,
                        'session_type' => $sessionType,
                        'message'      => 'Status updated from pending'
                    ]);
                } else {
                    echo json_encode([
                        'success'      => true,
                        'matched'      => true,
                        'duplicate'    => true,
                        'student_id'   => $matchedFace['sid'],
                        'student_name' => $matchedFace['first_name'] . ' ' . $matchedFace['last_name'],
                        'grade_level'  => $matchedFace['grade_level'],
                        'section'      => $matchedFace['section'] ?? '',
                        'status'       => $status,
                        'record_id'    => $existing['id'],
                        'session_id'   => $sessionId,
                        'session_type' => $sessionType,
                        'message'      => 'Already scanned'
                    ]);
                }
                exit;
            }

            // Save attendance record
            $stmt = $db->prepare(
                "INSERT INTO attendance_records 
                 (student_id, gate_session_id, session_type, scan_time, status, confidence_score, created_at)
                 VALUES (?, ?, ?, NOW(), ?, ?, NOW())"
            );
            $stmt->execute([
                $matchedFace['student_id'],
                $sessionId,
                $sessionType,
                $status,
                $apiResponse['confidence'] ?? 0
            ]);
            $recordId = $db->lastInsertId();

            try {
                error_log("GATE API: about to dispatch notification for student_id=" . $matchedFace['student_id'] . " event=" . $event);
                $event = ($sessionType === 'time_in') ? 'TIME_IN' : 'TIME_OUT';
                dispatchParentNotification($db, $matchedFace['student_id'], $event, [
                    'subject_name' => $session['session_type'] ?? 'Gate'
                ]);
                error_log("GATE API: dispatch completed for student_id=" . $matchedFace['student_id']);
            } catch (Exception $e) {
                error_log("Gate notification dispatch error: " . $e->getMessage());
            }

            echo json_encode([
                'success'      => true,
                'matched'      => true,
                'student_id'   => $matchedFace['sid'],
                'student_name' => $matchedFace['first_name'] . ' ' . $matchedFace['last_name'],
                'grade_level'  => $matchedFace['grade_level'],
                'section'      => $matchedFace['section'] ?? '',
                'status'       => $status,
                'confidence'   => $apiResponse['confidence'] ?? 0,
                'quality_warnings' => $apiResponse['quality_warnings'] ?? [],
                'record_id'    => $recordId,
                'session_id'   => $sessionId,
                'session_type' => $sessionType,
                'message'      => 'Attendance recorded'
            ]);
            break;

        // ============================================
        // GET SESSION INFO
        // ============================================
        case 'get_session_info':
            $stmt = $db->query(
                "SELECT * FROM gate_sessions WHERE status = 'active' ORDER BY start_time DESC"
            );
            $sessions = $stmt->fetchAll();

            echo json_encode([
                'success'  => true,
                'sessions' => $sessions
            ]);
            break;

        // ============================================
        // MARK ABSENT (manual / after session end)
        // ============================================
        case 'mark_absent':
            requireRole(['admin', 'gate']);

            $sessionId   = intval($_POST['session_id'] ?? 0);
            $studentIds  = $_POST['student_ids'] ?? [];

            if (empty($studentIds)) {
                echo json_encode(['success' => false, 'error' => 'No students selected.']);
                exit;
            }

            $sessionType = sanitize($_POST['session_type'] ?? 'time_in');
            $today = date('Y-m-d');
            $count = 0;

            foreach ($studentIds as $studentId) {
                $studentId = intval($studentId);
                // Check for existing pending record or any record
                $stmt = $db->prepare(
                    "SELECT id, status FROM attendance_records 
                     WHERE student_id = ? AND gate_session_id = ? AND session_type = ?"
                );
                $stmt->execute([$studentId, $sessionId, $sessionType]);
                $existing = $stmt->fetch();

                if ($existing) {
                    if ($existing['status'] === 'pending') {
                        $stmt = $db->prepare(
                            "UPDATE attendance_records 
                             SET status = 'absent', scan_time = NOW(), created_at = NOW()
                             WHERE id = ?"
                        );
                        $stmt->execute([$existing['id']]);
                        $count++;
                    }
                } else {
                    $stmt = $db->prepare(
                        "INSERT INTO attendance_records 
                         (student_id, gate_session_id, session_type, scan_time, status, created_at)
                         VALUES (?, ?, ?, NOW(), 'absent', NOW())"
                    );
                    $stmt->execute([$studentId, $sessionId, $sessionType]);
                    $count++;
                }
            }

            echo json_encode([
                'success' => true,
                'message' => "$count student(s) marked absent."
            ]);
            break;

        // ============================================
        // MANUAL ATTENDANCE (fallback when face recognition fails)
        // ============================================
        case 'manual_attendance':
            $studentIdInput = sanitize($_POST['student_id'] ?? '');
            $sessionId      = intval($_POST['session_id'] ?? 0);
            $sessionType    = sanitize($_POST['session_type'] ?? 'time_in');

            if (empty($studentIdInput)) {
                echo json_encode(['success' => false, 'error' => 'Student ID is required.']);
                exit;
            }

            // Get session info
            $stmt =$db->prepare("SELECT * FROM gate_sessions WHERE id = ? AND status = 'active'");
            $stmt->execute([$sessionId]);
            $session = $stmt->fetch();

            if (!$session) {
                echo json_encode(['success' => false, 'error' => 'No active session found.']);
                exit;
            }

            // Find student by student_id (the display ID like "2024-0001")
            $stmt = $db->prepare(
                "SELECT id, first_name, last_name, student_id, grade_level, section 
                 FROM students WHERE student_id = ? AND status = 'active'"
            );
            $stmt->execute([$studentIdInput]);
            $student = $stmt->fetch();

            if (!$student) {
                echo json_encode(['success' => false, 'error' => 'Student not found with ID: ' . $studentIdInput]);
                exit;
            }

            // Validate time-out: student must have a time-in record today
            if ($sessionType === 'time_out') {
                $today = date('Y-m-d');
                $stmt = $db->prepare(
                    "SELECT id FROM attendance_records 
                     WHERE student_id = ? AND session_type = 'time_in' AND DATE(scan_time) = ? 
                     AND status IN ('present', 'late') LIMIT 1"
                );
                $stmt->execute([$student['id'], $today]);
                if (!$stmt->fetch()) {
                    echo json_encode([
                        'success'      => false,
                        'matched'      => true,
                        'invalid'      => true,
                        'student_id'   => $student['student_id'],
                        'student_name' => $student['first_name'] . ' ' . $student['last_name'],
                        'grade_level'  => $student['grade_level'],
                        'error'        => 'Invalid attendance: No time-in record found. Student must time-in first before time-out.'
                    ]);
                    exit;
                }
            }

            // Determine status (present or late)
            $sessionStart = strtotime($session['start_time']);
            $lateThreshold = intval($session['late_threshold']);
            $now = time();
            $minutesLate = max(0, ($now - $sessionStart) / 60);

            $status = ($sessionType === 'time_in' && $minutesLate > $lateThreshold) ? 'late' : 'present';

            // Check for existing record
            $stmt = $db->prepare(
                "SELECT id, status FROM attendance_records 
                 WHERE student_id = ? AND gate_session_id = ? AND session_type = ?"
            );
            $stmt->execute([$student['id'], $sessionId, $sessionType]);
            $existing = $stmt->fetch();

            if ($existing) {
                if ($existing['status'] === 'pending') {
                    $stmt = $db->prepare(
                        "UPDATE attendance_records 
                         SET scan_time = NOW(), status = ?, created_at = NOW()
                         WHERE id = ?"
                    );
                    $stmt->execute([$status, $existing['id']]);

                    try {
                        $event = ($sessionType === 'time_in') ? 'TIME_IN' : 'TIME_OUT';
                        dispatchParentNotification($db, $student['id'], $event, [
                            'subject_name' => $session['session_type'] ?? 'Gate'
                        ]);
                    } catch (Exception $e) {
                        error_log("Gate manual pending notification dispatch error: " . $e->getMessage());
                    }

                    echo json_encode([
                        'success'      => true,
                        'updated'      => true,
                        'duplicate'    => false,
                        'student_name' => $student['first_name'] . ' ' . $student['last_name'],
                        'student_id'   => $student['student_id'],
                        'grade_level'  => $student['grade_level'],
                        'section'      => $student['section'] ?? '',
                        'status'       => $status,
                        'record_id'    => $existing['id'],
                        'session_id'   => $sessionId,
                        'session_type' => $sessionType,
                        'message'      => 'Status updated from pending'
                    ]);
                } else {
                    echo json_encode([
                        'success'      => true,
                        'duplicate'    => true,
                        'student_name' => $student['first_name'] . ' ' . $student['last_name'],
                        'student_id'   => $student['student_id'],
                        'grade_level'  => $student['grade_level'],
                        'section'      => $student['section'] ?? '',
                        'status'       => $status,
                        'record_id'    => $existing['id'],
                        'session_id'   => $sessionId,
                        'session_type' => $sessionType,
                        'message'      => 'Already scanned today'
                    ]);
                }
                exit;
            }

            // Save attendance record (confidence = 0 for manual entry)
            $stmt = $db->prepare(
                "INSERT INTO attendance_records 
                 (student_id, gate_session_id, session_type, scan_time, status, confidence_score, created_at)
                 VALUES (?, ?, ?, NOW(), ?, 0, NOW())"
            );
            $stmt->execute([
                $student['id'],
                $sessionId,
                $sessionType,
                $status
            ]);
            $recordId = $db->lastInsertId();

            try {
                $event = ($sessionType === 'time_in') ? 'TIME_IN' : 'TIME_OUT';
                error_log("GATE MANUAL: about to dispatch notification for student_id=" . $student['id'] . " event=" . $event);
                dispatchParentNotification($db, $student['id'], $event, [
                    'subject_name' => $session['session_type'] ?? 'Gate'
                ]);
                error_log("GATE MANUAL: dispatch completed for student_id=" . $student['id']);
            } catch (Exception $e) {
                error_log("Gate manual notification dispatch error: " . $e->getMessage());
            }

            // Log the manual entry
            $stmt = $db->prepare(
                "INSERT INTO audit_logs (user_id, action, description, ip_address, created_at)
                 VALUES (?, 'manual_attendance', ?, ?, NOW())"
            );
            $stmt->execute([
                $_SESSION['user_id'],
                "Manual {$sessionType} for student {$student['student_id']} ({$student['first_name']} {$student['last_name']})",
                $_SERVER['REMOTE_ADDR'] ?? ''
            ]);

            echo json_encode([
                'success'      => true,
                'student_name' => $student['first_name'] . ' ' . $student['last_name'],
                'student_id'   => $student['student_id'],
                'grade_level'  => $student['grade_level'],
                'section'      => $student['section'] ?? '',
                'status'       => $status,
                'record_id'    => $recordId,
                'session_id'   => $sessionId,
                'session_type' => $sessionType,
                'message'      => 'Attendance recorded manually'
            ]);
            break;

        // ============================================
        // UPDATE GATE ATTENDANCE STATUS (edit detected student)
        // ============================================
        case 'update_status':
            requireRole(['admin', 'gate']);

            $recordId     = intval($_POST['record_id'] ?? 0);
            $studentIdInput = sanitize($_POST['student_id'] ?? '');
            $sessionId    = intval($_POST['session_id'] ?? 0);
            $sessionType  = sanitize($_POST['session_type'] ?? 'time_in');
            $newStatus    = sanitize($_POST['status'] ?? '');

            if (!$recordId || !$sessionId || empty($studentIdInput) || !in_array($newStatus, ['present', 'late', 'absent'], true)) {
                echo json_encode(['success' => false, 'error' => 'Invalid parameters.']);
                exit;
            }

            // Validate the session is still active
            $stmt = $db->prepare("SELECT id FROM gate_sessions WHERE id = ? AND status = 'active'");
            $stmt->execute([$sessionId]);
            $session = $stmt->fetch();
            if (!$session) {
                echo json_encode(['success' => false, 'error' => 'No active session found.']);
                exit;
            }

            // Validate student by display ID (e.g. LRN / 2024-0001)
            $stmt = $db->prepare("SELECT id FROM students WHERE student_id = ? AND status = 'active'");
            $stmt->execute([$studentIdInput]);
            $student = $stmt->fetch();
            if (!$student) {
                echo json_encode(['success' => false, 'error' => 'Student not found.']);
                exit;
            }

            // The record must belong to this student + session
            $stmt = $db->prepare(
                "SELECT id FROM attendance_records 
                 WHERE id = ? AND student_id = ? AND gate_session_id = ? AND session_type = ?"
            );
            $stmt->execute([$recordId, $student['id'], $sessionId, $sessionType]);
            if (!$stmt->fetch()) {
                echo json_encode(['success' => false, 'error' => 'Attendance record not found for this student.']);
                exit;
            }

            $stmt = $db->prepare("UPDATE attendance_records SET status = ? WHERE id = ?");
            $stmt->execute([$newStatus, $recordId]);
            error_log("GATE API: update_status updated attendance id=" . $recordId . " to status='" . $newStatus . "' for student=" . $studentIdInput . " session=$sessionId");

            $stmt = $db->prepare(
                "INSERT INTO audit_logs (user_id, action, description, ip_address, created_at)
                 VALUES (?, 'gate_status_update', ?, ?, NOW())"
            );
            $stmt->execute([
                $_SESSION['user_id'],
                "Updated {$sessionType} status for student {$studentIdInput} to {$newStatus} in session {$sessionId}",
                $_SERVER['REMOTE_ADDR'] ?? ''
            ]);

            echo json_encode([
                'success' => true,
                'status'  => $newStatus,
                'message' => 'Attendance status updated successfully.'
            ]);
            break;

        // ============================================
        // REGISTER FACE (for gate users)
        // ============================================
        case 'register_face':
            $studentId = intval($_POST['student_id'] ?? 0);
            $frontFace = $_POST['front_face'] ?? '';
            $leftFace  = $_POST['left_face'] ?? '';
            $rightFace = $_POST['right_face'] ?? '';

            if (!$studentId) {
                echo json_encode(['success' => false, 'error' => 'Student ID is required.']);
                exit;
            }

            if (empty($frontFace)) {
                echo json_encode(['success' => false, 'error' => 'At least front face image is required.']);
                exit;
            }

            // Get student info
            $stmt = $db->prepare(
                "SELECT id, student_id, first_name, last_name FROM students WHERE id = ? AND status = 'active'"
            );
            $stmt->execute([$studentId]);
            $student = $stmt->fetch();

            if (!$student) {
                echo json_encode(['success' => false, 'error' => 'Student not found.']);
                exit;
            }

            // Call Python face recognition API for encoding
            $faceApi = new FaceRecognitionAPI();
            
            // Pre-flight quality check on front face
            if (!empty($frontFace)) {
                $frontBase64 = preg_replace('#^data:image/\w+;base64,#i', '', $frontFace);
                $frontBinary = base64_decode($frontBase64, true);
                if ($frontBinary !== false && !empty($frontBinary)) {
                    $magic = substr($frontBinary, 0, 4);
                    $isImage = ($magic === "\xFF\xD8\xFF" || $magic === "\x89PNG" || $magic === 'GIF8');
                    if ($isImage) {
                        $qualityCheck = $faceApi->checkQuality($frontFace);
                        if ($qualityCheck && !$qualityCheck['quality_ok']) {
                            $issues = implode(' ', $qualityCheck['issues']);
                            echo json_encode([
                                'success' => false,
                                'error' => 'Face image quality insufficient: ' . $issues
                            ]);
                            exit;
                        }
                    }
                }
            }
            
            // Batch encode all face images
            $result = $faceApi->batchEncode([
                'front' => $frontFace,
                'left'  => $leftFace,
                'right' => $rightFace
            ]);

            if ($result && isset($result['encoding'])) {
                // Save images via Python API
                if ($frontFace) $faceApi->saveFaceImage($frontFace, $student['student_id'], 'front');
                if ($leftFace)  $faceApi->saveFaceImage($leftFace, $student['student_id'], 'left');
                if ($rightFace) $faceApi->saveFaceImage($rightFace, $student['student_id'], 'right');

                // Check if face record already exists
                $stmt = $db->prepare("SELECT id FROM student_faces WHERE student_id = ?");
                $stmt->execute([$studentId]);
                $existingFace = $stmt->fetch();

                if ($existingFace) {
                    // Update existing record
                    $stmt = $db->prepare(
                        "UPDATE student_faces 
                         SET front_face = ?, left_face = ?, right_face = ?, face_encoding = ?, updated_at = NOW()
                         WHERE student_id = ?"
                    );
                    $stmt->execute([
                        $frontFace ? 'saved' : null,
                        $leftFace ? 'saved' : null,
                        $rightFace ? 'saved' : null,
                        json_encode($result['encoding']),
                        $studentId
                    ]);
                } else {
                    // Insert new record
                    $stmt = $db->prepare(
                        "INSERT INTO student_faces (student_id, front_face, left_face, right_face, face_encoding, created_at, updated_at)
                         VALUES (?, ?, ?, ?, ?, NOW(), NOW())"
                    );
                    $stmt->execute([
                        $studentId,
                        $frontFace ? 'saved' : null,
                        $leftFace ? 'saved' : null,
                        $rightFace ? 'saved' : null,
                        json_encode($result['encoding'])
                    ]);
                }

                // Log the action
                $stmt = $db->prepare(
                    "INSERT INTO audit_logs (user_id, action, description, ip_address, created_at)
                     VALUES (?, 'face_registration', ?, ?, NOW())"
                );
                $stmt->execute([
                    $_SESSION['user_id'],
                    "Registered face for student {$student['student_id']} ({$student['first_name']} {$student['last_name']})",
                    $_SERVER['REMOTE_ADDR'] ?? ''
                ]);

                $warnings = $result['quality_warnings'] ?? [];
                echo json_encode([
                    'success'       => true,
                    'message'       => 'Face registered successfully for ' . $student['first_name'] . ' ' . $student['last_name'],
                    'student_name'  => $student['first_name'] . ' ' . $student['last_name'],
                    'faces_encoded' => $result['faces_encoded'] ?? 1,
                    'quality_warnings' => $warnings
                ]);
            } else {
                $errorMsg = 'Failed to encode face. Please ensure a clear face image is captured.';
                if ($result && isset($result['error'])) {
                    $errorMsg = $result['error'];
                }
                echo json_encode([
                    'success' => false,
                    'error'   => $errorMsg
                ]);
            }
            break;

        // ============================================
        // LOOKUP STUDENT (for kiosk verification)
        // ============================================
        case 'lookup_student':
            $studentIdInput = sanitize($_POST['student_id'] ?? '');

            if (empty($studentIdInput)) {
                echo json_encode(['success' => false, 'error' => 'Student ID is required.']);
                exit;
            }

            // Find student by student_id (display ID)
            $stmt = $db->prepare(
                "SELECT s.id, s.student_id, s.first_name, s.last_name, s.grade_level, s.section,
                        CASE WHEN sf.face_encoding IS NOT NULL THEN 1 ELSE 0 END as has_face,
                        sf.updated_at as face_registered_at
                 FROM students s
                 LEFT JOIN student_faces sf ON s.id = sf.student_id
                 WHERE s.student_id = ? AND s.status = 'active'"
            );
            $stmt->execute([$studentIdInput]);
            $student = $stmt->fetch();

            if (!$student) {
                echo json_encode(['success' => false, 'error' => 'Student not found.']);
                exit;
            }

            echo json_encode([
                'success' => true,
                'student' => [
                    'id'                  => $student['id'],
                    'student_id'          => $student['student_id'],
                    'first_name'          => $student['first_name'],
                    'last_name'           => $student['last_name'],
                    'grade_level'         => $student['grade_level'],
                    'section'             => $student['section'] ?? '',
                    'has_face'            => (bool)$student['has_face'],
                    'face_registered_at'  => $student['face_registered_at'] ? date('M d, Y', strtotime($student['face_registered_at'])) : null
                ]
            ]);
            break;

        // ============================================
        // GET REGISTRATION STATS (for admin dashboard)
        // ============================================
        case 'registration_stats':
            requireRole(['admin']);

            // Overall stats
            $total = $db->query("SELECT COUNT(*) FROM students WHERE status = 'active'")->fetchColumn();
            $registered = $db->query("SELECT COUNT(*) FROM student_faces WHERE face_encoding IS NOT NULL")->fetchColumn();

            // By grade level
            $gradeStats = $db->query(
                "SELECT s.grade_level, 
                        COUNT(*) as total,
                        SUM(CASE WHEN sf.face_encoding IS NOT NULL THEN 1 ELSE 0 END) as registered
                 FROM students s
                 LEFT JOIN student_faces sf ON s.id = sf.student_id
                 WHERE s.status = 'active'
                 GROUP BY s.grade_level
                 ORDER BY s.grade_level"
            )->fetchAll();

            // Unregistered students list
            $unregistered = $db->query(
                "SELECT s.student_id, s.first_name, s.last_name, s.grade_level, s.section
                 FROM students s
                 LEFT JOIN student_faces sf ON s.id = sf.student_id
                 WHERE s.status = 'active' AND (sf.face_encoding IS NULL OR sf.id IS NULL)
                 ORDER BY s.grade_level, s.last_name
                 LIMIT 100"
            )->fetchAll();

            echo json_encode([
                'success'      => true,
                'total'        => $total,
                'registered'   => $registered,
                'pending'      => $total - $registered,
                'percent'      => $total > 0 ? round(($registered / $total) * 100) : 0,
                'by_grade'     => $gradeStats,
                'unregistered' => $unregistered
            ]);
            break;

        default:
            echo json_encode(['success' => false, 'error' => 'Invalid action.']);
    }

} catch (Exception $e) {
    error_log("Gate API error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error'   => 'An error occurred: ' . $e->getMessage()
    ]);
}

/**
 * Auto-end gate session if late threshold is exceeded.
 * Marks remaining unmarked students as absent.
 */
function autoEndGateSessionIfLate($db, $sessionId) {
    $stmt = $db->prepare("SELECT * FROM gate_sessions WHERE id = ? AND status = 'active'");
    $stmt->execute([$sessionId]);
    $session = $stmt->fetch();
    if (!$session) return false;

    $sessionEnd = strtotime($session['end_time']);
    $now = time();
    $minutesAfterEnd = max(0, ($now - $sessionEnd) / 60);

    if ($minutesAfterEnd > intval($session['late_threshold'])) {
        $stmt = $db->prepare(
            "UPDATE gate_sessions SET status = 'completed', ended_at = NOW() WHERE id = ?"
        );
        $stmt->execute([$sessionId]);

        $sessionType = $session['session_type'];

        $stmt = $db->prepare(
            "SELECT id, student_id FROM attendance_records
             WHERE gate_session_id = ? AND session_type = ? AND status = 'pending'"
        );
        $stmt->execute([$sessionId, $sessionType]);
        $pendingRecords = $stmt->fetchAll();

        $absentCount = 0;
        foreach ($pendingRecords as $record) {
            $stmt = $db->prepare(
                "UPDATE attendance_records 
                 SET status = 'absent', scan_time = NOW(), created_at = NOW()
                 WHERE id = ?"
            );
            $stmt->execute([$record['id']]);
            $absentCount++;

            try {
                dispatchParentNotification($db, $record['student_id'], 'GATE_ABSENT', [
                    'subject_name' => $session['session_type'] ?? 'Gate'
                ]);
            } catch (Exception $e) {
                error_log("Auto-end gate absence notification error: " . $e->getMessage());
            }
        }

        // Backward compatibility: mark any students without records as absent
        $today = date('Y-m-d');
        $stmt = $db->prepare(
            "SELECT s.id FROM students s
             WHERE s.status = 'active'
             AND s.id NOT IN (
                 SELECT student_id FROM attendance_records
                 WHERE DATE(scan_time) = ? AND session_type = ? AND gate_session_id = ?
             )"
        );
        $stmt->execute([$today, $sessionType, $sessionId]);
        $absentStudents = $stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($absentStudents as $studentId) {
            try {
                $stmt = $db->prepare(
                    "INSERT INTO attendance_records 
                     (student_id, gate_session_id, session_type, scan_time, status, created_at)
                     VALUES (?, ?, ?, NOW(), 'absent', NOW())"
                );
                $stmt->execute([$studentId, $sessionId, $sessionType]);
                $absentCount++;

                try {
                    dispatchParentNotification($db, $studentId, 'GATE_ABSENT', [
                        'subject_name' => $session['session_type'] ?? 'Gate'
                    ]);
                } catch (Exception $e) {
                    error_log("Auto-end gate absence notification error: " . $e->getMessage());
                }
            } catch (Exception $e) {
                // Skip if duplicate
            }
        }

        try {
            $db->prepare(
                "INSERT INTO audit_logs (user_id, action, description, ip_address, created_at)
                 VALUES (?, 'gate_session_auto_end', ?, ?, NOW())"
            )->execute([
                $session['created_by'],
                "Auto-ended {$sessionType} session due to late threshold. {$absentCount} students marked absent.",
                $_SERVER['REMOTE_ADDR'] ?? ''
            ]);
        } catch (Exception $e) {}

        return true;
    }
    return false;
}

/**
 * Call the Python face recognition API
 */
function callFaceAPI($endpoint, $data = []) {
    $apiUrl = PYTHON_API_URL . $endpoint;

    $ch = curl_init($apiUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'X-API-Key: ' . PYTHON_API_KEY],
        CURLOPT_POSTFIELDS     => json_encode($data),
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => false
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$response) {
        return ['error' => 'Face recognition service unavailable.'];
    }

    return json_decode($response, true) ?: ['error' => 'Invalid API response.'];
}
