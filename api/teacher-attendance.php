<?php
/**
 * Endpoints: start_session, end_session, recognize_class
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/face_api.php';
require_once __DIR__ . '/../send_notification_helper.php';

header('Content-Type: application/json');
requireLogin();
requireRole(['teacher', 'admin']);

csrfMiddleware(true);

$action = sanitize($_POST['action'] ?? $_GET['action'] ?? '');
$db = getDB();

try {
    switch ($action) {

        // ============================================
        // START CLASS ATTENDANCE SESSION
        // ============================================
        case 'start_session':
            $subjectId    = intval($_POST['subject_id'] ?? 0);
            $gradeLevel   = sanitize($_POST['grade_level'] ?? '');
            $section      = sanitize($_POST['section'] ?? '');
            $timeLimit    = intval($_POST['time_limit'] ?? 60);
            $lateThreshold= intval($_POST['late_threshold'] ?? 15);
            $startTime    = sanitize($_POST['start_time'] ?? date('H:i'));

            if (!$subjectId || !$gradeLevel || !$section) {
                redirect('/teacher/attendance.php', 'Subject, grade level, and section are required.', 'danger');
            }

            // Check for existing active session
            $stmt = $db->prepare(
                "SELECT id FROM attendance_sessions 
                 WHERE session_type = 'class' AND status = 'active' AND created_by = ?"
            );
            $stmt->execute([getCurrentUserId()]);
            if ($stmt->fetch()) {
                redirect('/teacher/attendance.php', 'You already have an active session. End it first.', 'warning');
            }

            $startDate = date('Y-m-d');
            $startDT = $startDate . ' ' . $startTime . ':00';
            $endDT = date('Y-m-d H:i:s', strtotime($startDT . " +{$timeLimit} minutes"));

            $stmt = $db->prepare(
                "INSERT INTO attendance_sessions 
                 (session_type, subject_id, grade_level, section, start_time, end_time, late_threshold, status, created_by, created_at)
                 VALUES ('class', ?, ?, ?, ?, ?, ?, 'active', ?, NOW())"
            );
            $stmt->execute([
                $subjectId, $gradeLevel, $section,
                $startDT, $endDT, $lateThreshold,
                getCurrentUserId()
            ]);

            // Audit log
            $stmt = $db->prepare(
                "INSERT INTO audit_logs (user_id, action, description, ip_address, created_at)
                 VALUES (?, 'class_session_start', ?, ?, NOW())"
            );
            $stmt->execute([
                getCurrentUserId(),
                "Started class attendance for subject $subjectId, Grade $gradeLevel-$section",
                $_SERVER['REMOTE_ADDR'] ?? ''
            ]);

            redirect('/teacher/attendance.php', 'Attendance session started successfully.', 'success');
            break;

        // ============================================
        // END CLASS ATTENDANCE SESSION
        // ============================================
        case 'end_session':
            $sessionId = intval($_POST['session_id'] ?? 0);
            if (!$sessionId) {
                redirect('/teacher/attendance.php', 'Invalid session.', 'danger');
            }

            // Get session info
            $stmt = $db->prepare("SELECT * FROM attendance_sessions WHERE id = ? AND created_by = ?");
            $stmt->execute([$sessionId, getCurrentUserId()]);
            $session = $stmt->fetch();

            if (!$session) {
                redirect('/teacher/attendance.php', 'Session not found.', 'danger');
            }

            // Mark session completed
            $stmt = $db->prepare(
                "UPDATE attendance_sessions SET status = 'completed', updated_at = NOW() WHERE id = ?"
            );
            $stmt->execute([$sessionId]);

            // Mark absent students
            $today = date('Y-m-d');
            $stmt = $db->prepare(
                "SELECT id FROM students 
                 WHERE grade_level = ? AND section = ? AND status = 'active'
                 AND id NOT IN (
                     SELECT student_id FROM attendance 
                     WHERE date = ? AND subject_id = ? AND recorded_by = ?
                 )"
            );
            $stmt->execute([
                $session['grade_level'], $session['section'],
                $today, $session['subject_id'], getCurrentUserId()
            ]);
            $absentStudents = $stmt->fetchAll(PDO::FETCH_COLUMN);

            $absentCount = 0;
            foreach ($absentStudents as $studentId) {
                $stmt = $db->prepare(
                    "INSERT INTO attendance (student_id, subject_id, date, time, status, session_type, recorded_by, created_at)
                     VALUES (?, ?, ?, ?, 'absent', 'class', ?, NOW())"
                );
                try {
                    $stmt->execute([$studentId, $session['subject_id'], $today, date('H:i:s'), getCurrentUserId()]);
                    $absentCount++;

                    try {
                        $subjStmt = $db->prepare("SELECT subject_name FROM subjects WHERE id = ? LIMIT 1");
                        $subjStmt->execute([$session['subject_id']]);
                        $subject = $subjStmt->fetch();
                        $subjectName = $subject ? ($subject['subject_name']) : 'Class';

                        dispatchParentNotification($db, $studentId, '3X_ABSENCE', [
                            'subject_id' => $session['subject_id'],
                            'subject_name' => $subjectName
                        ]);
                    } catch (Exception $e) {
                        error_log("3X absence notification error: " . $e->getMessage());
                    }
                } catch (Exception $e) {}
            }

            // Audit log
            $stmt = $db->prepare(
                "INSERT INTO audit_logs (user_id, action, description, ip_address, created_at)
                 VALUES (?, 'class_session_end', ?, ?, NOW())"
            );
            $stmt->execute([
                getCurrentUserId(),
                "Ended class session. {$absentCount} students marked absent.",
                $_SERVER['REMOTE_ADDR'] ?? ''
            ]);

            $endMessage = "Session ended. {$absentCount} student(s) marked absent.";
            if (strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest') {
                $_SESSION['flash_message'] = $endMessage;
                $_SESSION['flash_type'] = 'success';
                jsonResponse(['success' => true, 'message' => $endMessage]);
            }
            redirect('/teacher/attendance.php', $endMessage, 'success');
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

            $autoEnded = autoEndClassSessionIfLate($db, $sessionId, getCurrentUserId());
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

            $stmt = $db->prepare("SELECT * FROM attendance_sessions WHERE id = ? AND created_by = ?");
            $stmt->execute([$sessionId, getCurrentUserId()]);
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
        // RECOGNIZE FACE FOR CLASS ATTENDANCE
        // ============================================
        case 'recognize_class':
            $sessionId   = intval($_POST['session_id'] ?? 0);
            $imageBase64 = $_POST['image'] ?? '';

            if (empty($imageBase64)) {
                echo json_encode(['success' => false, 'error' => 'No image.']);
                exit;
            }

            // Get session info
            $stmt = $db->prepare("SELECT * FROM attendance_sessions WHERE id = ? AND status = 'active' AND created_by = ?");
            $stmt->execute([$sessionId, getCurrentUserId()]);
            $session = $stmt->fetch();

            if (!$session) {
                echo json_encode(['success' => false, 'error' => 'No active session.', 'auto_ended' => false]);
                exit;
            }

            // Analyze the face against EVERY student registered in the admin
            // (the engine only returns a match when it is confident and unambiguous).
            // We keep each student's grade/section so we can verify the match
            // actually belongs to this session before recording attendance.
            $stmt = $db->prepare(
                "SELECT sf.student_id, s.first_name, s.last_name, s.student_id as sid,
                        s.grade_level, s.section, sf.face_encoding
                 FROM student_faces sf
                 JOIN students s ON sf.student_id = s.id
                 WHERE sf.face_encoding IS NOT NULL"
            );
            $stmt->execute();
            $knownFaces = $stmt->fetchAll();

            if (empty($knownFaces)) {
                echo json_encode(['success' => false, 'error' => 'No students are registered for face recognition yet.']);
                exit;
            }

            // Call Python API
            $knownFacesData = [];
            foreach ($knownFaces as $face) {
                $knownFacesData[] = [
                    'student_id' => $face['student_id'],
                    'encoding'   => json_decode($face['face_encoding'], true)
                ];
            }

            $apiResponse = callTeacherFaceAPI('/api/recognize-face', [
                'image'       => $imageBase64,
                'known_faces' => $knownFacesData
            ]);

            if (!$apiResponse || empty($apiResponse['matched'])) {
                echo json_encode([
                    'success' => false,
                    'matched' => false,
                    'error'   => $apiResponse['error'] ?? ($apiResponse['message'] ?? 'Face not recognized'),
                    'quality_warnings' => $apiResponse['quality_warnings'] ?? []
                ]);
                exit;
            }

            // Find matched student
            $matchedFace = null;
            foreach ($knownFaces as $face) {
                if ($face['student_id'] == $apiResponse['student_id']) {
                    $matchedFace = $face;
                    break;
                }
            }

            if (!$matchedFace) {
                echo json_encode(['success' => false, 'matched' => false, 'error' => 'Student not found.']);
                exit;
            }

            // Safety check: the recognized student must be enrolled in this
            // session's grade & section. A face that matches a student from a
            // different class must NEVER be recorded for this session.
            $sessionGrade = (string)$session['grade_level'];
            $sessionSection = strtolower(trim((string)$session['section']));
            $matchGrade = (string)$matchedFace['grade_level'];
            $matchSection = strtolower(trim((string)($matchedFace['section'] ?? '')));

            if ($matchGrade !== $sessionGrade || $matchSection !== $sessionSection) {
                echo json_encode([
                    'success'      => false,
                    'matched'      => false,
                    'error'        => 'Matched student is not enrolled in this section.',
                    'student_name' => $matchedFace['first_name'] . ' ' . $matchedFace['last_name'],
                    'student_id'   => $matchedFace['sid']
                ]);
                exit;
            }

            // Determine status
            $sessionStart = strtotime($session['start_time']);
            $now = time();
            $minutesLate = max(0, ($now - $sessionStart) / 60);
            $status = ($minutesLate > intval($session['late_threshold'])) ? 'late' : 'present';

            // Check duplicate or pending
            $stmt = $db->prepare(
                "SELECT id, status FROM attendance 
                 WHERE student_id = ? AND subject_id = ? AND date = ? AND recorded_by = ?"
            );
            $stmt->execute([$matchedFace['student_id'], $session['subject_id'], date('Y-m-d'), getCurrentUserId()]);
            $existing = $stmt->fetch();

            if ($existing) {
                error_log("recognize_class: existing status='" . $existing['status'] . "' for student_id=" . $matchedFace['student_id']);
                if (in_array($existing['status'], ['pending', 'absent'], true)) {
                    $stmt = $db->prepare(
                        "UPDATE attendance SET status = ?, time = ? WHERE id = ?"
                    );
                    $stmt->execute([$status, date('H:i:s'), $existing['id']]);

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
                        'message'      => 'Attendance status updated'
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
                        'status'       => $existing['status'],
                        'confidence'   => $apiResponse['confidence'] ?? 0,
                        'message'      => 'Already scanned today'
                    ]);
                }
                exit;
            }

            // Save attendance
            $stmt = $db->prepare(
                "INSERT INTO attendance (student_id, subject_id, date, time, status, session_type, recorded_by, created_at)
                 VALUES (?, ?, ?, ?, ?, 'class', ?, NOW())"
            );
            $stmt->execute([
                $matchedFace['student_id'],
                $session['subject_id'],
                date('Y-m-d'),
                date('H:i:s'),
                $status,
                getCurrentUserId()
            ]);

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
                'message'      => 'Attendance recorded'
            ]);
            break;

        // ============================================
        // MANUAL ATTENDANCE (fallback when face recognition fails)
        // ============================================
        case 'manual_attendance':
            $studentIdInput = sanitize($_POST['student_id'] ?? '');
            $sessionId      = intval($_POST['session_id'] ?? 0);

            if (empty($studentIdInput)) {
                echo json_encode(['success' => false, 'error' => 'Student ID is required.']);
                exit;
            }

            $stmt = $db->prepare("SELECT * FROM attendance_sessions WHERE id = ? AND status = 'active' AND created_by = ?");
            $stmt->execute([$sessionId, getCurrentUserId()]);
            $session = $stmt->fetch();

            if (!$session) {
                echo json_encode(['success' => false, 'error' => 'No active session found.']);
                exit;
            }

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

            $sessionGrade = (string)$session['grade_level'];
            $sessionSection = strtolower(trim((string)$session['section']));
            $matchGrade = (string)$student['grade_level'];
            $matchSection = strtolower(trim((string)($student['section'] ?? '')));

            if ($matchGrade !== $sessionGrade || $matchSection !== $sessionSection) {
                echo json_encode([
                    'success'      => false,
                    'matched'      => false,
                    'error'        => 'Student is not enrolled in this section.',
                    'student_name' => $student['first_name'] . ' ' . $student['last_name'],
                    'student_id'   => $student['student_id'],
                    'grade_level'  => $student['grade_level'],
                ]);
                exit;
            }

            $sessionStart = strtotime($session['start_time']);
            $lateThreshold = intval($session['late_threshold']);
            $now = time();
            $minutesLate = max(0, ($now - $sessionStart) / 60);
            $status = ($minutesLate > $lateThreshold) ? 'late' : 'present';

            $stmt = $db->prepare(
                "SELECT id, status FROM attendance 
                 WHERE student_id = ? AND subject_id = ? AND date = ? AND recorded_by = ?"
            );
            $stmt->execute([$student['id'], $session['subject_id'], date('Y-m-d'), getCurrentUserId()]);
            $existing = $stmt->fetch();

            if ($existing) {
                if (in_array($existing['status'], ['pending', 'absent'], true)) {
                    $stmt = $db->prepare(
                        "UPDATE attendance SET status = ?, time = ? WHERE id = ?"
                    );
                    $stmt->execute([$status, date('H:i:s'), $existing['id']]);

                    echo json_encode([
                        'success'      => true,
                        'updated'      => true,
                        'duplicate'    => false,
                        'student_id'   => $student['student_id'],
                        'student_name' => $student['first_name'] . ' ' . $student['last_name'],
                        'grade_level'  => $student['grade_level'],
                        'section'      => $student['section'] ?? '',
                        'status'       => $status,
                        'message'      => 'Attendance status updated'
                    ]);
                } else {
                    echo json_encode([
                        'success'      => true,
                        'duplicate'    => true,
                        'student_id'   => $student['student_id'],
                        'student_name' => $student['first_name'] . ' ' . $student['last_name'],
                        'grade_level'  => $student['grade_level'],
                        'section'      => $student['section'] ?? '',
                        'status'       => $existing['status'],
                        'message'      => 'Already recorded today'
                    ]);
                }
                exit;
            }

            $stmt = $db->prepare(
                "INSERT INTO attendance (student_id, subject_id, date, time, status, session_type, recorded_by, created_at)
                 VALUES (?, ?, ?, ?, ?, 'class', ?, NOW())"
            );
            $stmt->execute([
                $student['id'],
                $session['subject_id'],
                date('Y-m-d'),
                date('H:i:s'),
                $status,
                getCurrentUserId()
            ]);

            $stmt = $db->prepare(
                "INSERT INTO audit_logs (user_id, action, description, ip_address, created_at)
                 VALUES (?, 'manual_class_attendance', ?, ?, NOW())"
            );
            $stmt->execute([
                getCurrentUserId(),
                "Manual class attendance for student {$student['student_id']} ({$student['first_name']} {$student['last_name']})",
                $_SERVER['REMOTE_ADDR'] ?? ''
            ]);

            echo json_encode([
                'success'      => true,
                'duplicate'    => false,
                'student_id'   => $student['student_id'],
                'student_name' => $student['first_name'] . ' ' . $student['last_name'],
                'grade_level'  => $student['grade_level'],
                'section'      => $student['section'] ?? '',
                'status'       => $status,
                'message'      => 'Attendance recorded manually'
            ]);
            break;

        // ============================================
        // UPDATE ATTENDANCE STATUS (correction)
        // ============================================
        case 'update_status':
            $sessionId      = intval($_POST['session_id'] ?? 0);
            $studentIdInput = sanitize($_POST['student_id'] ?? '');
            $newStatus      = sanitize($_POST['status'] ?? '');

            if (!$sessionId || empty($studentIdInput) || !in_array($newStatus, ['present', 'late', 'absent', 'pending', 'excused'])) {
                echo json_encode(['success' => false, 'error' => 'Invalid parameters.']);
                exit;
            }

            $stmt = $db->prepare("SELECT id FROM attendance_sessions WHERE id = ? AND status = 'active' AND created_by = ?");
            $stmt->execute([$sessionId, getCurrentUserId()]);
            if (!$stmt->fetch()) {
                echo json_encode(['success' => false, 'error' => 'No active session found.']);
                exit;
            }

            $stmt = $db->prepare("SELECT id FROM students WHERE student_id = ? AND status = 'active'");
            $stmt->execute([$studentIdInput]);
            $student = $stmt->fetch();
            if (!$student) {
                echo json_encode(['success' => false, 'error' => 'Student not found.']);
                exit;
            }

            $today = date('Y-m-d');
            $stmt = $db->prepare(
                "SELECT id FROM attendance 
                 WHERE student_id = ? AND subject_id = (
                     SELECT subject_id FROM attendance_sessions WHERE id = ?
                 ) AND date = ? AND recorded_by = ?"
            );
            $stmt->execute([$student['id'], $sessionId, $today, getCurrentUserId()]);
            $record = $stmt->fetch();

            if (!$record) {
                echo json_encode(['success' => false, 'error' => 'Attendance record not found for this student.']);
                exit;
            }

            $stmt = $db->prepare(
                "UPDATE attendance SET status = ?, updated_at = NOW() WHERE id = ?"
            );
            $stmt->execute([$newStatus, $record['id']]);
            error_log("update_status: Updated attendance id=" . $record['id'] . " to status='" . $newStatus . "' for student=" . $studentIdInput);

            $stmt = $db->prepare(
                "INSERT INTO audit_logs (user_id, action, description, ip_address, created_at)
                 VALUES (?, 'attendance_status_update', ?, ?, NOW())"
            );
            $stmt->execute([
                getCurrentUserId(),
                "Updated attendance status for student {$studentIdInput} to {$newStatus} in session {$sessionId}",
                $_SERVER['REMOTE_ADDR'] ?? ''
            ]);

            echo json_encode([
                'success' => true,
                'status' => $newStatus,
                'message' => 'Attendance status updated successfully.'
            ]);
            break;

        // ============================================
        // RECOGNITION ENGINE STATUS
        // Used by the scanner to show "Model still loading..." until the
        // Python face engine is warm and answering.
        // ============================================
        case 'engine_status':
            $engineApi = new FaceRecognitionAPI(PYTHON_API_URL, PYTHON_API_KEY, 3);
            echo json_encode([
                'success' => true,
                'ready'   => $engineApi->isAvailable()
            ]);
            break;

        default:
            echo json_encode(['success' => false, 'error' => 'Invalid action.']);
    }

} catch (Exception $e) {
    error_log("Teacher Attendance API error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
}

/**
 * Auto-end class session if late threshold is exceeded.
 * Marks remaining unmarked students as absent.
 */
function autoEndClassSessionIfLate($db, $sessionId, $userId) {
    $stmt = $db->prepare("SELECT * FROM attendance_sessions WHERE id = ? AND status = 'active' AND created_by = ?");
    $stmt->execute([$sessionId, $userId]);
    $session = $stmt->fetch();
    if (!$session) return false;

    $sessionEnd = strtotime($session['end_time']);
    $now = time();
    $minutesAfterEnd = max(0, ($now - $sessionEnd) / 60);

    if ($minutesAfterEnd > intval($session['late_threshold'])) {
        $stmt = $db->prepare("UPDATE attendance_sessions SET status = 'completed', updated_at = NOW() WHERE id = ?");
        $stmt->execute([$sessionId]);

        $today = date('Y-m-d');
        $stmt = $db->prepare(
            "SELECT id FROM students 
             WHERE grade_level = ? AND section = ? AND status = 'active'
             AND id NOT IN (
                 SELECT student_id FROM attendance 
                 WHERE date = ? AND subject_id = ? AND recorded_by = ?
             )"
        );
        $stmt->execute([
            $session['grade_level'], $session['section'],
            $today, $session['subject_id'], $userId
        ]);
        $absentStudents = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $absentCount = 0;
        foreach ($absentStudents as $studentId) {
            $stmt = $db->prepare(
                "INSERT INTO attendance (student_id, subject_id, date, time, status, session_type, recorded_by, created_at)
                 VALUES (?, ?, ?, ?, 'absent', 'class', ?, NOW())"
            );
            try {
                $stmt->execute([$studentId, $session['subject_id'], $today, date('H:i:s'), $userId]);
                $absentCount++;
            } catch (Exception $e) {}
        }

        try {
            $subjStmt = $db->prepare("SELECT subject_name FROM subjects WHERE id = ? LIMIT 1");
            $subjStmt->execute([$session['subject_id']]);
            $subject = $subjStmt->fetch();
            $subjectName = $subject ? ($subject['subject_name']) : 'Class';

            if ($session['grade_level'] && $session['section']) {
                $stmt = $db->prepare(
                    "SELECT s.id FROM students s 
                     WHERE s.grade_level = ? AND s.section = ? AND s.status = 'active'
                     AND id NOT IN (
                         SELECT student_id FROM attendance 
                         WHERE date = ? AND subject_id = ? AND recorded_by = ? AND status = 'absent'
                     )"
                );
                $stmt->execute([$session['grade_level'], $session['section'], $today, $session['subject_id'], $userId]);
                $newAbsents = $stmt->fetchAll(PDO::FETCH_COLUMN);
                foreach ($newAbsents as $absId) {
                    try {
                        dispatchParentNotification($db, $absId, '3X_ABSENCE', [
                            'subject_id' => $session['subject_id'],
                            'subject_name' => $subjectName
                        ]);
                    } catch (Exception $e) {
                        error_log("3X absence notification error: " . $e->getMessage());
                    }
                }
            }
        } catch (Exception $e) {
            error_log("Auto-end notification error: " . $e->getMessage());
        }

        try {
            $db->prepare(
                "INSERT INTO audit_logs (user_id, action, description, ip_address, created_at)
                 VALUES (?, 'class_session_auto_end', ?, ?, NOW())"
            )->execute([
                $userId,
                "Auto-ended class session due to late threshold. {$absentCount} students marked absent.",
                $_SERVER['REMOTE_ADDR'] ?? ''
            ]);
        } catch (Exception $e) {}

        return true;
    }
    return false;
}

/**
 * Call Python face recognition API
 */
function callTeacherFaceAPI($endpoint, $data = []) {
    $apiUrl = PYTHON_API_URL . $endpoint;
    $ch = curl_init($apiUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'X-API-Key: ' . PYTHON_API_KEY],
        CURLOPT_POSTFIELDS     => json_encode($data),
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    if ($response === false) {
        error_log("Face API transport error ($endpoint): $curlError");
        return ['error' => 'Face recognition service unavailable.'];
    }
    $result = json_decode($response, true);
    if ($httpCode >= 400) {
        error_log("Face API HTTP error ($endpoint): $httpCode");
        if ($httpCode === 400 && is_array($result) && isset($result['error'])) {
            return $result;
        }
        return ['error' => 'Face recognition service unavailable.'];
    }
    return is_array($result) ? $result : ['error' => 'Invalid API response.'];
}

