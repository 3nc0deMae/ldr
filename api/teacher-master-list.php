<?php
/**
 * LDB-FRAS - Teacher Master List API
 * Returns the master list of students (separated Boys / Girls, alphabetical)
 * for a grade level handled by the currently logged-in teacher.
 *
 * Expected GET params:
 *   - grade_level : required (7-12) — the grade level to list
 *   - subject_id  : optional — the grade level is validated against the sections
 *                            the teacher is paired with for that subject
 *
 * Response (JSON):
 *   {
 *     "success": bool,
 *     "message": string,
 *     "grade_level": string,
 *     "subject": {id, subject_name, subject_code} | null,
 *     "total": int,
 *     "boys":  [ {student_id, first_name, middle_name, last_name, name_extension, section, guardian_name}, ... ],
 *     "girls": [ ... ]
 *   }
 */

require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');
requireLogin();
requireRole(['teacher', 'admin']);

$teacherId = getCurrentUserId();

/* ---- Resolve the teacher record and the subject ids the admin assigned ---- */
/* (assignments stored in the teacher's grade_section_handled /
   core_subjects_handled / track_elective_handled JSON). ---- */
$teacher = null;
$assignedSubjectIds = [];
$subjectGradesMap = [];

try {
    $stmt = $db->prepare("SELECT t.* FROM teachers t JOIN users u ON t.user_id = u.id WHERE u.id = ? LIMIT 1");
    $stmt->execute([$teacherId]);
    $teacher = $stmt->fetch();

    if ($teacher) {
        $assignedSectionIds = [];
        foreach (['grade_section_handled', 'core_subjects_handled', 'track_elective_handled'] as $field) {
            $raw = $teacher[$field] ?? '';
            if (is_string($raw)) { $raw = json_decode($raw, true); }
            if (!is_array($raw)) { continue; }
            foreach ($raw as $item) {
                if (isset($item['subject_id']) && is_numeric($item['subject_id'])) {
                    $assignedSubjectIds[] = (int)$item['subject_id'];
                }
                if (isset($item['section_id']) && is_numeric($item['section_id'])) {
                    $assignedSectionIds[] = (int)$item['section_id'];
                }
            }
        }
        $assignedSubjectIds = array_values(array_unique(array_filter($assignedSubjectIds, function ($id) { return $id > 0; })));
        $assignedSectionIds = array_values(array_unique(array_filter($assignedSectionIds, function ($id) { return $id > 0; })));

        $sectionGradeMap = [];
        if (!empty($assignedSectionIds)) {
            $ph = implode(',', array_fill(0, count($assignedSectionIds), '?'));
            $stmt = $db->prepare("SELECT id, grade_level FROM sections WHERE id IN ($ph)");
            $stmt->execute($assignedSectionIds);
            foreach ($stmt->fetchAll() as $sec) {
                $sectionGradeMap[(int)$sec['id']] = (int)$sec['grade_level'];
            }
        }

        foreach (['grade_section_handled', 'core_subjects_handled', 'track_elective_handled'] as $field) {
            $raw = $teacher[$field] ?? '';
            if (is_string($raw)) { $raw = json_decode($raw, true); }
            if (!is_array($raw)) { continue; }
            foreach ($raw as $item) {
                $sid   = isset($item['subject_id']) && is_numeric($item['subject_id']) ? (int)$item['subject_id'] : 0;
                $secId = isset($item['section_id']) && is_numeric($item['section_id']) ? (int)$item['section_id'] : 0;
                if (!$sid || !$secId || !isset($sectionGradeMap[$secId])) { continue; }
                $g = $sectionGradeMap[$secId];
                if ($g <= 0) { continue; }
                $subjectGradesMap[$sid][] = $g;
            }
        }
        foreach ($subjectGradesMap as $sid => $grades) {
            $subjectGradesMap[$sid] = array_values(array_unique($grades));
        }
    }
} catch (Exception $e) {
    error_log('master-list api teacher: ' . $e->getMessage());
}

$assignedSubjectIds = array_values(array_unique(array_filter($assignedSubjectIds, function ($id) { return $id > 0; })));

/* ---- Read + validate input ---- */
$gradeLevel = sanitize($_GET['grade_level'] ?? '');
$subjectId  = intval($_GET['subject_id'] ?? 0);
$sectionId  = intval($_GET['section_id'] ?? 0);

// Resolve the chosen section to its name (students.section stores the section name).
$sectionName = '';
if ($sectionId > 0 && !empty($assignedSectionIds)) {
    $ph = implode(',', array_fill(0, count($assignedSectionIds), '?'));
    try {
        $stmt = $db->prepare("SELECT section_name FROM sections WHERE id IN ($ph) AND id = ?");
        $stmt->execute(array_merge($assignedSectionIds, [$sectionId]));
        $row = $stmt->fetch();
        if ($row) { $sectionName = $row['section_name']; }
    } catch (Exception $e) {
        error_log('master-list api section lookup: ' . $e->getMessage());
    }
}

$validGrades = ['7', '8', '9', '10', '11', '12'];
if (!in_array($gradeLevel, $validGrades, true)) {
    echo json_encode([
        'success' => false,
        'message' => 'Please provide a valid grade level.',
        'boys'    => [],
        'girls'   => [],
        'total'   => 0,
    ]);
    exit;
}

$subject = null;
if ($subjectId > 0) {
    // Confirm the teacher actually handles this subject (and capture its details).
    $found = false;
    if (!empty($assignedSubjectIds)) {
        $ph = implode(',', array_fill(0, count($assignedSubjectIds), '?'));
        try {
            $stmt = $db->prepare("SELECT id, subject_name, subject_code FROM subjects WHERE id IN ($ph)");
            $stmt->execute($assignedSubjectIds);
            foreach ($stmt->fetchAll() as $sub) {
                if ((int)$sub['id'] === $subjectId) {
                    $subject = $sub;
                    $found = true;
                    break;
                }
            }
        } catch (Exception $e) {
            error_log('master-list api subject lookup: ' . $e->getMessage());
        }
    }
    if (!$found) {
        echo json_encode([
            'success' => false,
            'message' => 'You are not assigned to the selected subject.',
            'boys'    => [],
            'girls'   => [],
            'total'   => 0,
        ]);
        exit;
    }
    // The grade level must match one of the grade levels the admin assigned to
    // this teacher for the selected subject (derived from the paired sections).
    if (!empty($subjectGradesMap[$subjectId]) && !in_array($gradeLevel, array_map('strval', $subjectGradesMap[$subjectId]), true)) {
        echo json_encode([
            'success' => false,
            'message' => 'The selected grade level is not assigned to you for this subject.',
            'boys'    => [],
            'girls'   => [],
            'total'   => 0,
        ]);
        exit;
    }
}

/* ---- Fetch students for the grade level (alphabetical by last name, first name) ---- */
$boys = [];
$girls = [];

try {
$sql = "SELECT s.student_id, s.first_name, s.middle_name, s.last_name,
                    s.name_extension, s.gender, s.section, s.status,
                    g.guardian_name
             FROM students s
             LEFT JOIN guardians g ON s.id = g.student_id
             WHERE s.grade_level = ?";
    $params = [$gradeLevel];
    if ($sectionName !== '') {
        $sql .= " AND s.section = ?";
        $params[] = $sectionName;
    }
    $sql .= " ORDER BY s.last_name ASC, s.first_name ASC, s.middle_name ASC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $students = $stmt->fetchAll();

    foreach ($students as $stu) {
        $entry = [
            'student_id'     => $stu['student_id'],
            'first_name'     => $stu['first_name'],
            'middle_name'    => $stu['middle_name'],
            'last_name'      => $stu['last_name'],
            'name_extension' => $stu['name_extension'] ?? '',
            'gender'         => $stu['gender'],
            'section'        => $stu['section'],
            'status'         => $stu['status'],
            'guardian_name'  => $stu['guardian_name'],
        ];
        if ($stu['gender'] === 'Male') {
            $boys[] = $entry;
        } elseif ($stu['gender'] === 'Female') {
            $girls[] = $entry;
        }
    }
} catch (Exception $e) {
    error_log('master-list api students: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Failed to load the student master list.',
        'boys'    => [],
        'girls'   => [],
        'total'   => 0,
    ]);
    exit;
}

echo json_encode([
    'success'     => true,
    'message'     => 'ok',
    'grade_level' => $gradeLevel,
    'section'     => $sectionName !== '' ? $sectionName : null,
    'subject'     => $subject ? [
        'id'           => (int)$subject['id'],
        'subject_name' => $subject['subject_name'],
        'subject_code' => $subject['subject_code'],
    ] : null,
    'total' => count($boys) + count($girls),
    'boys'  => $boys,
    'girls' => $girls,
]);
exit;
