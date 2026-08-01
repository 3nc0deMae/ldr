<?php
/**
 * LDB-FRAS - Teachers API
 * AJAX endpoint for teacher CRUD operations
 */

require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

requireRole(['admin']);

csrfMiddleware(true);

$action = $_POST['action'] ?? '';

switch ($action) {

    case 'list':
        $search   = sanitize($_POST['search'] ?? '');
        $filters  = $search ? ['search' => $search] : [];
        $teachers = getTeachers($db, $filters);
        jsonResponse(['data' => $teachers, 'count' => count($teachers)]);
        break;

    case 'get':
        $id = intval($_POST['id'] ?? 0);
        if (!$id) {
            jsonResponse(['error' => 'Teacher ID is required'], 400);
        }
        $teacher = getTeacherById($db, $id);
        if ($teacher) {
            jsonResponse(['data' => $teacher]);
        } else {
            jsonResponse(['error' => 'Teacher not found'], 404);
        }
        break;

    case 'add':
        $gradeSectionRaw = $_POST['grade_section_handled'] ?? '[]';
        if (is_string($gradeSectionRaw)) {
            $gradeSectionHandled = json_decode($gradeSectionRaw, true) ?: [];
        } elseif (is_array($gradeSectionRaw)) {
            $gradeSectionHandled = $gradeSectionRaw;
        } else {
            $gradeSectionHandled = [];
        }
        if (!is_array($gradeSectionHandled)) $gradeSectionHandled = [];

        $subjectsHandled = array_values(array_filter(array_map(function ($item) {
            return trim((string)($item['subject_id'] ?? ''));
        }, $gradeSectionHandled)));

        $subjectIds = array_values(array_filter(array_map(function ($v) {
            return is_numeric($v) ? (int)$v : 0;
        }, $subjectsHandled)));

        $subjectNames = [];
        if (!empty($subjectIds)) {
            $ph = implode(',', array_fill(0, count($subjectIds), '?'));
            $stmt = $db->prepare("SELECT subject_name FROM subjects WHERE id IN ($ph)");
            $stmt->execute($subjectIds);
            foreach ($stmt->fetchAll() as $s) {
                $subjectNames[] = $s['subject_name'];
            }
        }

        $coreSubjectsRaw = $_POST['core_subjects_handled'] ?? '[]';
        if (is_string($coreSubjectsRaw)) {
            $coreSubjectsHandled = json_decode($coreSubjectsRaw, true) ?: [];
        } elseif (is_array($coreSubjectsRaw)) {
            $coreSubjectsHandled = $coreSubjectsRaw;
        } else {
            $coreSubjectsHandled = [];
        }
        if (!is_array($coreSubjectsHandled)) $coreSubjectsHandled = [];

        $coreSubjectIds = array_values(array_filter(array_map(function ($item) {
            return is_numeric($item['subject_id'] ?? '') ? (int)$item['subject_id'] : 0;
        }, $coreSubjectsHandled)));
        $coreSubjectNames = [];
        if (!empty($coreSubjectIds)) {
            $ph = implode(',', array_fill(0, count($coreSubjectIds), '?'));
            $stmt = $db->prepare("SELECT subject_name FROM subjects WHERE id IN ($ph)");
            $stmt->execute($coreSubjectIds);
            foreach ($stmt->fetchAll() as $s) {
                $coreSubjectNames[] = $s['subject_name'];
            }
        }

        $trackElectiveRaw = $_POST['track_elective_handled'] ?? '[]';
        if (is_string($trackElectiveRaw)) {
            $trackElectiveHandled = json_decode($trackElectiveRaw, true) ?: [];
        } elseif (is_array($trackElectiveRaw)) {
            $trackElectiveHandled = $trackElectiveRaw;
        } else {
            $trackElectiveHandled = [];
        }
        if (!is_array($trackElectiveHandled)) $trackElectiveHandled = [];

         $data = [
             'employee_id'     => sanitize($_POST['employee_id'] ?? ''),
             'first_name'      => sanitize($_POST['first_name'] ?? ''),
             'middle_name'     => sanitize($_POST['middle_name'] ?? ''),
             'last_name'       => sanitize($_POST['last_name'] ?? ''),
             'email'           => sanitize($_POST['email'] ?? ''),
             'phone'           => sanitize($_POST['phone'] ?? ''),
             'department'      => sanitize($_POST['department'] ?? ''),
             'subjects_handled'=> $subjectNames,
             'grade_section_handled' => $gradeSectionHandled,
             'core_subjects_handled' => $coreSubjectsHandled,
             'track_elective_handled' => $trackElectiveHandled,
             'advisory_class'  => sanitize($_POST['advisory_class'] ?? '')
         ];

         if (empty($data['first_name']) || empty($data['last_name']) || empty($data['email'])) {
             jsonResponse(['success' => false, 'message' => 'First name, last name, and email are required.'], 400);
         }

        if (!isValidEmail($data['email'])) {
            jsonResponse(['success' => false, 'message' => 'Invalid email address.'], 400);
        }

        $dupTeacher = $db->prepare("SELECT id FROM teachers WHERE email = ? LIMIT 1");
        $dupTeacher->execute([$data['email']]);
        if ($dupTeacher->fetch()) {
            jsonResponse(['success' => false, 'message' => 'This email is already in use by an existing teacher.'], 409);
        }

        $dupUser = $db->prepare("SELECT id, role FROM users WHERE email = ? LIMIT 1");
        $dupUser->execute([$data['email']]);
        $existingUser = $dupUser->fetch();
        if ($existingUser) {
            if ($existingUser['role'] !== 'teacher') {
                jsonResponse(['success' => false, 'message' => 'This email is already in use by an existing account.'], 409);
            }
            $linked = $db->prepare("SELECT id FROM teachers WHERE user_id = ? LIMIT 1");
            $linked->execute([$existingUser['id']]);
            if ($linked->fetch()) {
                jsonResponse(['success' => false, 'message' => 'This email is already in use by an existing teacher.'], 409);
            }
            $data['user_id'] = $existingUser['id'];
        }

        $result = addTeacher($db, $data);

        if ($result) {
            $newTeacher = getTeacherById($db, $result);
            if ($newTeacher) {
                jsonResponse([
                    'success' => true,
                    'message' => 'Teacher "' . $newTeacher['first_name'] . ' ' . $newTeacher['last_name'] . '" added successfully.',
                    'teacher' => [
                        'id' => $newTeacher['id'],
                        'employee_id' => $newTeacher['employee_id'] ?? '',
                        'first_name' => $newTeacher['first_name'],
                        'middle_name' => $newTeacher['middle_name'] ?? '',
                        'last_name' => $newTeacher['last_name'],
                        'email' => $newTeacher['email'],
                        'phone' => $newTeacher['phone'] ?? '',
                        'department' => $newTeacher['department'] ?? '',
                        'subjects_handled' => $newTeacher['subjects_handled'] ?? '',
                        'grade_section_handled' => $newTeacher['grade_section_handled'],
                        'core_subjects_handled' => $newTeacher['core_subjects_handled'],
                        'track_elective_handled' => $newTeacher['track_elective_handled'],
                        'advisory_class' => $newTeacher['advisory_class'] ?? '',
                    ]
                ]);
            } else {
                jsonResponse(['success' => false, 'message' => 'Failed to retrieve inserted teacher.'], 500);
            }
        } else {
            jsonResponse(['success' => false, 'message' => 'Failed to add teacher.'], 500);
        }
        break;

    case 'update':
        $id = intval($_POST['id'] ?? 0);
        if (!$id) {
            jsonResponse(['success' => false, 'message' => 'Teacher ID required.'], 400);
        }

        $gradeSectionRaw = $_POST['grade_section_handled'] ?? '[]';
        if (is_string($gradeSectionRaw)) {
            $gradeSectionHandled = json_decode($gradeSectionRaw, true) ?: [];
        } elseif (is_array($gradeSectionRaw)) {
            $gradeSectionHandled = $gradeSectionRaw;
        } else {
            $gradeSectionHandled = [];
        }
        if (!is_array($gradeSectionHandled)) $gradeSectionHandled = [];

        $subjectsHandled = array_values(array_filter(array_map(function ($item) {
            return trim((string)($item['subject_id'] ?? ''));
        }, $gradeSectionHandled)));

        $subjectIds = array_values(array_filter(array_map(function ($v) {
            return is_numeric($v) ? (int)$v : 0;
        }, $subjectsHandled)));

        $subjectNames = [];
        if (!empty($subjectIds)) {
            $ph = implode(',', array_fill(0, count($subjectIds), '?'));
            $stmt = $db->prepare("SELECT subject_name FROM subjects WHERE id IN ($ph)");
            $stmt->execute($subjectIds);
            foreach ($stmt->fetchAll() as $s) {
                $subjectNames[] = $s['subject_name'];
            }
        }

        $coreSubjectsRaw = $_POST['core_subjects_handled'] ?? '[]';
        if (is_string($coreSubjectsRaw)) {
            $coreSubjectsHandled = json_decode($coreSubjectsRaw, true) ?: [];
        } elseif (is_array($coreSubjectsRaw)) {
            $coreSubjectsHandled = $coreSubjectsRaw;
        } else {
            $coreSubjectsHandled = [];
        }
        if (!is_array($coreSubjectsHandled)) $coreSubjectsHandled = [];

        $coreSubjectIds = array_values(array_filter(array_map(function ($item) {
            return is_numeric($item['subject_id'] ?? '') ? (int)$item['subject_id'] : 0;
        }, $coreSubjectsHandled)));
        $coreSubjectNames = [];
        if (!empty($coreSubjectIds)) {
            $ph = implode(',', array_fill(0, count($coreSubjectIds), '?'));
            $stmt = $db->prepare("SELECT subject_name FROM subjects WHERE id IN ($ph)");
            $stmt->execute($coreSubjectIds);
            foreach ($stmt->fetchAll() as $s) {
                $coreSubjectNames[] = $s['subject_name'];
            }
        }

        $trackElectiveRaw = $_POST['track_elective_handled'] ?? '[]';
        if (is_string($trackElectiveRaw)) {
            $trackElectiveHandled = json_decode($trackElectiveRaw, true) ?: [];
        } elseif (is_array($trackElectiveRaw)) {
            $trackElectiveHandled = $trackElectiveRaw;
        } else {
            $trackElectiveHandled = [];
        }
        if (!is_array($trackElectiveHandled)) $trackElectiveHandled = [];

         $data = [
             'employee_id'     => sanitize($_POST['employee_id'] ?? ''),
             'first_name'      => sanitize($_POST['first_name'] ?? ''),
             'middle_name'     => sanitize($_POST['middle_name'] ?? ''),
             'last_name'       => sanitize($_POST['last_name'] ?? ''),
             'email'           => sanitize($_POST['email'] ?? ''),
             'phone'           => sanitize($_POST['phone'] ?? ''),
             'department'      => sanitize($_POST['department'] ?? ''),
             'subjects_handled'=> array_unique(array_merge($subjectNames, $coreSubjectNames)),
             'grade_section_handled' => $gradeSectionHandled,
             'core_subjects_handled' => $coreSubjectsHandled,
             'track_elective_handled' => $trackElectiveHandled,
             'advisory_class'  => sanitize($_POST['advisory_class'] ?? '')
         ];

         if (updateTeacher($db, $id, $data)) {
            jsonResponse(['success' => true, 'message' => 'Teacher updated successfully.']);
        } else {
            jsonResponse(['success' => false, 'message' => 'Failed to update teacher.'], 500);
        }
        break;

    case 'delete':
        $id = intval($_POST['id'] ?? 0);
        if (!$id) {
            jsonResponse(['error' => 'Teacher ID is required'], 400);
        }

        if (deleteTeacher($db, $id)) {
            jsonResponse(['success' => true, 'message' => 'Teacher deleted successfully']);
        } else {
            jsonResponse(['error' => 'Failed to delete teacher'], 500);
        }
        break;

    default:
        jsonResponse(['error' => 'Invalid action'], 400);
}
