<?php


require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/face_api.php';

header('Content-Type: application/json');

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

// Require authentication
requireLogin();

csrfMiddleware(true);

$action = $_POST['action'] ?? '';
$faceApi = new FaceRecognitionAPI();

switch ($action) {

    case 'record_attendance':
        $studentId = intval($_POST['student_id'] ?? 0);
        $subjectId = intval($_POST['subject_id'] ?? 0);
        $status    = sanitize($_POST['status'] ?? 'present');
        $date      = sanitize($_POST['date'] ?? today());
        $time      = sanitize($_POST['time'] ?? date('H:i:s'));

        if (!$studentId) {
            jsonResponse(['error' => 'Student ID is required'], 400);
        }

        $result = recordAttendance($db, [
            'student_id' => $studentId,
            'subject_id' => $subjectId ?: null,
            'date'       => $date,
            'time'       => $time,
            'status'     => $status
        ]);

        if ($result) {
            jsonResponse(['success' => true, 'message' => 'Attendance recorded', 'id' => $result]);
        } else {
            jsonResponse(['error' => 'Failed to record attendance'], 500);
        }
        break;

    case 'recognize_face':
        $imageData = $_POST['image'] ?? '';
        if (!$imageData) {
            jsonResponse(['error' => 'No image data provided'], 400);
        }

        // Get all known face encodings from database
        $knownFaces = getAllFaceEncodings($db);

        if (empty($knownFaces)) {
            jsonResponse(['matched' => false, 'message' => 'No registered faces found']);
        }

        // Format for Python API
        $facesForApi = [];
        foreach ($knownFaces as $face) {
            $facesForApi[] = [
                'student_id' => $face['student_number'],
                'encoding'   => $face['face_encoding']
            ];
        }

        // Call Python recognition API
        $result = $faceApi->recognizeFace($imageData, $facesForApi);

        if ($result && !empty($result['matched'])) {
            // Find student details
            $student = getStudentByStudentId($db, $result['student_id']);
            jsonResponse([
                'matched'    => true,
                'student_id' => $result['student_id'],
                'student'    => [
                    'id'          => $student['id'],
                    'student_id'  => $student['student_id'],
                    'first_name'  => $student['first_name'],
                    'last_name'   => $student['last_name'],
                    'grade_level' => $student['grade_level'],
                    'section'     => $student['section']
                ],
                'confidence' => $result['confidence'],
                'quality_warnings' => $result['quality_warnings'] ?? [],
                'message'    => $result['message']
            ]);
        } else {
            jsonResponse([
                'matched' => false,
                'quality_warnings' => $result['quality_warnings'] ?? [],
                'message' => $result['message'] ?? ($result['error'] ?? 'No match found')
            ]);
        }
        break;

    case 'get_attendance':
        $filters = [
            'date'       => $_POST['date'] ?? '',
            'subject_id' => $_POST['subject_id'] ?? '',
            'status'     => $_POST['status'] ?? '',
            'student_id' => $_POST['student_id'] ?? '',
            'date_from'  => $_POST['date_from'] ?? '',
            'date_to'    => $_POST['date_to'] ?? ''
        ];

        $records = getAttendance($db, $filters);
        jsonResponse(['data' => $records, 'count' => count($records)]);
        break;

    case 'get_gate_logs':
        $date = $_POST['date'] ?? today();
        $logs = getGateLogs($db, $date);
        jsonResponse(['data' => $logs, 'count' => count($logs)]);
        break;

    case 'record_gate_log':
        $studentId = intval($_POST['student_id'] ?? 0);
        $type      = sanitize($_POST['type'] ?? 'time-in');

        if (!$studentId) {
            jsonResponse(['error' => 'Student ID is required'], 400);
        }

        if ($type === 'time-in') {
            $result = recordGateLog($db, [
                'student_id' => $studentId,
                'time_in'    => now(),
                'status'     => 'time-in'
            ]);
        } else {
            $result = recordGateLog($db, [
                'student_id' => $studentId,
                'time_out'   => now()
            ]);
        }

        if ($result) {
            jsonResponse(['success' => true, 'message' => "Gate log recorded ($type)"]);
        } else {
            jsonResponse(['error' => 'Failed to record gate log'], 500);
        }
        break;

    case 'mark_absent':
        $subjectId = intval($_POST['subject_id'] ?? 0);
        $date      = sanitize($_POST['date'] ?? today());
        $gradeLevel = sanitize($_POST['grade_level'] ?? '');
        $section   = sanitize($_POST['section'] ?? '');

        if (!$subjectId || !$gradeLevel) {
            jsonResponse(['error' => 'Subject ID and grade level are required'], 400);
        }

        // Get all students in the grade/section
        $students = getStudents($db, ['grade_level' => $gradeLevel, 'section' => $section]);

        // Get students already marked present
        $presentIds = [];
        $existing = getAttendance($db, ['subject_id' => $subjectId, 'date' => $date]);
        foreach ($existing as $att) {
            $presentIds[] = $att['student_id'];
        }

        // Mark remaining students as absent
        $absentCount = 0;
        foreach ($students as $student) {
            if (!in_array($student['id'], $presentIds)) {
                recordAttendance($db, [
                    'student_id' => $student['id'],
                    'subject_id' => $subjectId,
                    'date'       => $date,
                    'time'       => null,
                    'status'     => 'absent'
                ]);
                $absentCount++;
            }
        }

        jsonResponse([
            'success' => true,
            'message' => "$absentCount student(s) marked as absent"
        ]);
        break;

    default:
        jsonResponse(['error' => 'Invalid action'], 400);
}
