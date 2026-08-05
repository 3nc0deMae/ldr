<?php
/**
 * LDB-FRAS - Global Search API
 * Returns search results across students, teachers, sessions, and subjects
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/session.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    jsonResponse(['error' => 'Unauthorized'], 401);
}

$query = sanitize($_GET['q'] ?? '');
$role = sanitize($_GET['role'] ?? $_SESSION['user_role'] ?? 'guest');

if (strlen($query) < 2) {
    jsonResponse([]);
}

$results = [];
$searchPattern = '%' . $query . '%';

try {
    // Search students (accessible to all logged-in roles)
    $stmt = $db->prepare("
        SELECT s.id, s.student_id, CONCAT(s.last_name, ', ', s.first_name) as name, s.grade_level, s.section, 'student' as type, 'bi-people' as icon, CONCAT('Grade ', s.grade_level, ' - ', s.section) as meta, CONCAT(:base, '/admin/student-edit.php?id=', s.id) as url
        FROM students s
        WHERE s.first_name LIKE :q OR s.last_name LIKE :q OR s.student_id LIKE :q
        LIMIT 5
    ");
    $stmt->execute([':q' => $searchPattern, ':base' => BASE_URL]);
    foreach ($stmt->fetchAll() as $row) {
        $results[] = $row;
    }
} catch (Exception $e) {}

// Teachers - admin and teacher roles
if (in_array($role, ['admin', 'teacher'])) {
    try {
        $stmt = $db->prepare("
            SELECT t.id, t.employee_id, CONCAT(t.last_name, ', ', t.first_name) as name, t.department, 'teacher' as type, 'bi-person-badge' as icon, t.department as meta, CONCAT(:base, '/admin/teachers.php') as url
            FROM teachers t
            WHERE t.first_name LIKE :q OR t.last_name LIKE :q OR t.employee_id LIKE :q
            LIMIT 5
        ");
        $stmt->execute([':q' => $searchPattern, ':base' => BASE_URL]);
        foreach ($stmt->fetchAll() as $row) {
            $results[] = $row;
        }
    } catch (Exception $e) {}
}

// Sessions/Subjects - admin and teacher roles
if (in_array($role, ['admin', 'teacher'])) {
    try {
        $stmt = $db->prepare("\
            SELECT sub.id, sub.subject_name as name, sub.grade_level, s.section, 'subject' as type, 'bi-book' as icon, CONCAT('Grade ', sub.grade_level, ' - ', s.section) as meta, CONCAT(:base, '/admin/subjects.php') as url\
            FROM subjects sub\
            LEFT JOIN students s ON 1=1\
            WHERE sub.subject_name LIKE :q\
            LIMIT 5\
        ");
        $stmt->execute([':q' => $searchPattern, ':base' => BASE_URL]);
        foreach ($stmt->fetchAll() as $row) {
            $results[] = $row;
        }
    } catch (Exception $e) {}
}

// Attendance sessions - all roles
try {
    $stmt = $db->prepare("
        SELECT ses.id, ses.session_type, ses.status, sub.subject_name as name, ses.start_time, 'session' as type, 'bi-calendar-check' as icon, CONCAT('Status: ', ses.status) as meta, CONCAT(:base, '/admin/attendance.php') as url
        FROM attendance_sessions ses
        LEFT JOIN subjects sub ON ses.subject_id = sub.id
        WHERE ses.status = 'active' AND sub.subject_name LIKE :q
        LIMIT 5
    ");
    $stmt->execute([':q' => $searchPattern, ':base' => BASE_URL]);
    foreach ($stmt->fetchAll() as $row) {
        $results[] = $row;
    }
} catch (Exception $e) {}

// Deduplicate and limit
$results = array_slice(array_unique($results, SORT_REGULAR), 0, 8);

jsonResponse(array_values($results));