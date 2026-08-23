<?php
/**
 * LDB-FRAS - Subjects & Strands API
 * AJAX endpoint for subject and strand CRUD operations
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

    // ---- SUBJECT OPERATIONS ----
    case 'list':
        $gradeLevel = sanitize($_POST['grade_level'] ?? '');
        $subjects = getSubjects($db, $gradeLevel);
        jsonResponse(['data' => $subjects, 'count' => count($subjects)]);
        break;

    case 'get':
        $id = intval($_POST['id'] ?? 0);
        if (!$id) jsonResponse(['error' => 'Subject ID required'], 400);
        $subject = getSubjectById($db, $id);
        if ($subject) {
            jsonResponse(['data' => $subject]);
        } else {
            jsonResponse(['error' => 'Subject not found'], 404);
        }
        break;

    case 'add':
        $data = [
            'subject_name' => sanitize($_POST['subject_name'] ?? ''),
            'grade_level'  => sanitize($_POST['grade_level'] ?? ''),
            'description'  => sanitize($_POST['description'] ?? '')
        ];
        if (empty($data['subject_name']) || empty($data['grade_level'])) {
            redirect('/admin/subjects.php', 'Subject name and grade level are required.', 'danger');
        }
        $result = addSubject($db, $data);
        if ($result) {
            redirect('/admin/subjects.php', 'Subject "' . $data['subject_name'] . '" added successfully.', 'success');
        } else {
            redirect('/admin/subjects.php', 'Failed to add subject. Code may already exist.', 'danger');
        }
        break;

    case 'update':
        $id = intval($_POST['id'] ?? 0);
        if (!$id) redirect('/admin/subjects.php', 'Subject ID required.', 'danger');
        $data = [
            'subject_name' => sanitize($_POST['subject_name'] ?? ''),
            'grade_level'  => sanitize($_POST['grade_level'] ?? ''),
            'description'  => sanitize($_POST['description'] ?? '')
        ];
        if (updateSubject($db, $id, $data)) {
            redirect('/admin/subjects.php', 'Subject updated successfully.', 'success');
        } else {
            redirect('/admin/subjects.php', 'Failed to update subject.', 'danger');
        }
        break;

    case 'delete':
        $id = intval($_POST['id'] ?? 0);
        if (!$id) jsonResponse(['error' => 'Subject ID required'], 400);
        if (deleteSubject($db, $id)) {
            jsonResponse(['success' => true, 'message' => 'Subject deleted']);
        } else {
            jsonResponse(['error' => 'Failed to delete subject'], 500);
        }
        break;

    // ---- STRAND OPERATIONS ----
    case 'add_strand':
        $data = [
            'strand_name' => sanitize($_POST['strand_name'] ?? ''),
            'strand_code' => sanitize($_POST['strand_code'] ?? ''),
            'description' => sanitize($_POST['description'] ?? '')
        ];
        if (empty($data['strand_name']) || empty($data['strand_code'])) {
            redirect('/admin/subjects.php', 'Strand name and code are required.', 'danger');
        }
        $result = addStrand($db, $data);
        if ($result) {
            redirect('/admin/subjects.php', 'Strand "' . $data['strand_name'] . '" added successfully.', 'success');
        } else {
            redirect('/admin/subjects.php', 'Failed to add strand. Code may already exist.', 'danger');
        }
        break;

    case 'update_strand':
        $id = intval($_POST['id'] ?? 0);
        if (!$id) redirect('/admin/strands.php', 'Strand ID required.', 'danger');
        $data = [
            'strand_name' => sanitize($_POST['strand_name'] ?? ''),
            'strand_code' => sanitize($_POST['strand_code'] ?? ''),
            'description' => sanitize($_POST['description'] ?? '')
        ];
        if (updateStrand($db, $id, $data)) {
            redirect('/admin/strands.php', 'Strand updated successfully.', 'success');
        } else {
            redirect('/admin/strands.php', 'Failed to update strand.', 'danger');
        }
        break;

    case 'delete_strand':
        $id = intval($_POST['id'] ?? 0);
        if (!$id) jsonResponse(['error' => 'Strand ID required'], 400);
        if (deleteStrand($db, $id)) {
            jsonResponse(['success' => true, 'message' => 'Strand deleted']);
        } else {
            jsonResponse(['error' => 'Failed to delete strand'], 500);
        }
        break;

    // ---- TRACK OPERATIONS ----
    case 'list_tracks':
        $tracks = getTracks($db);
        jsonResponse(['data' => $tracks, 'count' => count($tracks)]);
        break;

    case 'add_track':
        $data = [
            'track_name'  => sanitize($_POST['track_name'] ?? ''),
            'description' => sanitize($_POST['description'] ?? '')
        ];
        if (empty($data['track_name'])) {
            jsonResponse(['error' => 'Track name is required'], 400);
        }
        $result = addTrack($db, $data);
        if ($result) {
            jsonResponse(['success' => true, 'id' => $result, 'message' => 'Track added successfully']);
        } else {
            jsonResponse(['error' => 'Failed to add track'], 500);
        }
        break;

    case 'get_track':
        $id = intval($_POST['id'] ?? 0);
        if (!$id) jsonResponse(['error' => 'Track ID required'], 400);
        $track = getTrack($db, $id);
        if ($track) {
            jsonResponse(['data' => $track]);
        } else {
            jsonResponse(['error' => 'Track not found'], 404);
        }
        break;

    case 'update_track':
        $id = intval($_POST['id'] ?? 0);
        if (!$id) jsonResponse(['error' => 'Track ID required'], 400);
        $data = [
            'track_name'  => sanitize($_POST['track_name'] ?? ''),
            'description' => sanitize($_POST['description'] ?? '')
        ];
        if (updateTrack($db, $id, $data)) {
            jsonResponse(['success' => true, 'message' => 'Track updated']);
        } else {
            jsonResponse(['error' => 'Failed to update track'], 500);
        }
        break;

    case 'delete_track':
        $id = intval($_POST['id'] ?? 0);
        if (!$id) jsonResponse(['error' => 'Track ID required'], 400);
        if (deleteTrack($db, $id)) {
            jsonResponse(['success' => true, 'message' => 'Track deleted']);
        } else {
            jsonResponse(['error' => 'Failed to delete track'], 500);
        }
        break;

    case 'list_track_electives':
        $trackId = intval($_POST['track_id'] ?? 0);
        if (!$trackId) jsonResponse(['error' => 'Track ID required'], 400);
        $electives = getElectives($db, $trackId);
        jsonResponse(['data' => $electives, 'count' => count($electives)]);
        break;

    // ---- ELECTIVE OPERATIONS ----
    case 'add_elective':
        $data = [
            'track_id'      => intval($_POST['track_id'] ?? 0),
            'elective_name' => sanitize($_POST['elective_name'] ?? ''),
            'description'   => sanitize($_POST['description'] ?? '')
        ];
        if (empty($data['track_id']) || empty($data['elective_name'])) {
            jsonResponse(['error' => 'Track and Elective name are required'], 400);
        }
        $result = addElective($db, $data);
        if ($result) {
            jsonResponse(['success' => true, 'id' => $result, 'message' => 'Elective added successfully']);
        } else {
            jsonResponse(['error' => 'Failed to add elective'], 500);
        }
        break;

    case 'get_elective':
        $id = intval($_POST['id'] ?? 0);
        if (!$id) jsonResponse(['error' => 'Elective ID required'], 400);
        $elective = getElective($db, $id);
        if ($elective) {
            jsonResponse(['data' => $elective]);
        } else {
            jsonResponse(['error' => 'Elective not found'], 404);
        }
        break;

    case 'update_elective':
        $id = intval($_POST['id'] ?? 0);
        if (!$id) jsonResponse(['error' => 'Elective ID required'], 400);
        $data = [
            'elective_name' => sanitize($_POST['elective_name'] ?? ''),
            'description'   => sanitize($_POST['description'] ?? '')
        ];
        if (updateElective($db, $id, $data)) {
            jsonResponse(['success' => true, 'message' => 'Elective updated']);
        } else {
            jsonResponse(['error' => 'Failed to update elective'], 500);
        }
        break;

    case 'delete_elective':
        $id = intval($_POST['id'] ?? 0);
        if (!$id) jsonResponse(['error' => 'Elective ID required'], 400);
        if (deleteElective($db, $id)) {
            jsonResponse(['success' => true, 'message' => 'Elective deleted']);
        } else {
            jsonResponse(['error' => 'Failed to delete elective'], 500);
        }
        break;

    case 'list_elective_subjects':
        $electiveId = intval($_POST['elective_id'] ?? 0);
        if (!$electiveId) jsonResponse(['error' => 'Elective ID required'], 400);
        $subjects = getElectiveSubjects($db, $electiveId);
        jsonResponse(['data' => $subjects, 'count' => count($subjects)]);
        break;

    case 'add_elective_subject_bulk':
        $electiveId = intval($_POST['elective_id'] ?? 0);
        if (!$electiveId) jsonResponse(['error' => 'Elective ID required'], 400);
        $names     = $_POST['subject_name']     ?? [];
        $gradeFrom = $_POST['grade_from']       ?? [];
        $gradeTo   = $_POST['grade_to']         ?? [];
        $descs     = $_POST['subject_desc']     ?? [];
        if (!is_array($names) || empty($names)) jsonResponse(['error' => 'At least one subject is required'], 400);
        $added = 0;
        foreach ($names as $i => $name) {
            $name = trim($name);
            if ($name === '') continue;
            $subjectId = null;
            // Try to find existing subject by name
            $dup = $db->prepare("SELECT id FROM subjects WHERE subject_name = ? LIMIT 1");
            $dup->execute([$name]);
            $row = $dup->fetch();
            if ($row) {
                $subjectId = (int)$row['id'];
                $db->prepare("UPDATE subjects SET subject_name = ?, description = ?, grade_level = ?, grade_level_end = ? WHERE id = ?")
                   ->execute([$name, trim($descs[$i] ?? ''), trim($gradeFrom[$i] ?? ''), trim($gradeTo[$i] ?? ''), $subjectId]);
            } else {
                // Insert subject record (subject_code removed from schema)
                $stmt = $db->prepare("INSERT INTO subjects (subject_name, description, grade_level, grade_level_end, strand_id) VALUES (?,?,?,?,NULL)");
                $stmt->execute([$name, trim($descs[$i] ?? ''), trim($gradeFrom[$i] ?? ''), trim($gradeTo[$i] ?? '')]);
                $subjectId = (int)$db->lastInsertId();
            }
            $link = $db->prepare("INSERT IGNORE INTO elective_subjects (elective_id, subject_id) VALUES (?, ?)");
            if ($link->execute([$electiveId, $subjectId])) $added++;
        }
        jsonResponse(['success' => true, 'id' => $added > 0 ? $added : 0, 'message' => 'Saved ' . $added . ' subject(s)']);
        break;

    case 'add_elective_subject':
        $electiveId = intval($_POST['elective_id'] ?? 0);
        $subjectId  = intval($_POST['subject_id'] ?? 0);
        if (!$electiveId || !$subjectId) jsonResponse(['error' => 'Elective ID and Subject ID are required'], 400);
        if (addElectiveSubject($db, $electiveId, $subjectId)) {
            jsonResponse(['success' => true, 'message' => 'Subject added to elective']);
        } else {
            jsonResponse(['error' => 'Subject already added or failed'], 500);
        }
        break;

    case 'list_all_subjects':
        $subjects = getAllSubjects($db);
        jsonResponse(['data' => $subjects, 'count' => count($subjects)]);
        break;

    case 'delete_elective_subject':
        $electiveId = intval($_POST['elective_id'] ?? 0);
        $subjectId  = intval($_POST['subject_id'] ?? 0);
        if (!$electiveId || !$subjectId) jsonResponse(['error' => 'Elective ID and Subject ID are required'], 400);
        $stmt = $db->prepare("DELETE FROM elective_subjects WHERE elective_id = ? AND subject_id = ?");
        $res = $stmt->execute([$electiveId, $subjectId]);
        if ($res) {
            // Remove the underlying subject as well once it is no longer linked to
            // any elective and not referenced by teachers or attendance records,
            // so deleted subjects never resurface in other forms.
            try {
                $linked = $db->prepare("SELECT COUNT(*) FROM elective_subjects WHERE subject_id = ?");
                $linked->execute([$subjectId]);
                if ((int)$linked->fetchColumn() === 0) {
                    $used = 0;
                    foreach (['teacher_subjects', 'attendance', 'attendance_sessions'] as $refTable) {
                        $chk = $db->prepare("SELECT COUNT(*) FROM `{$refTable}` WHERE subject_id = ?");
                        $chk->execute([$subjectId]);
                        $used += (int)$chk->fetchColumn();
                    }
                    if ($used === 0) {
                        $db->prepare("DELETE FROM subjects WHERE id = ?")->execute([$subjectId]);
                    }
                }
            } catch (Exception $e) {
                error_log('elective subject cleanup: ' . $e->getMessage());
            }
            jsonResponse(['success' => true, 'message' => 'Subject removed']);
        } else {
            jsonResponse(['error' => 'Failed to remove subject'], 500);
        }
        break;

    case 'update_elective_subject':
        $subjectId = intval($_POST['subject_id'] ?? 0);
        if (!$subjectId) jsonResponse(['error' => 'Subject ID required'], 400);
        $data = [
            'subject_name'    => sanitize($_POST['subject_name'] ?? ''),
            'grade_level'     => sanitize($_POST['grade_level'] ?? ''),
            'grade_level_end' => sanitize($_POST['grade_level_end'] ?? ''),
            'description'     => sanitize($_POST['description'] ?? '')
        ];
        if (empty($data['subject_name'])) {
            jsonResponse(['error' => 'Subject name is required'], 400);
        }
        $stmt = $db->prepare("UPDATE subjects SET subject_name = ?, grade_level = ?, grade_level_end = ?, description = ?, updated_at = NOW() WHERE id = ?");
        if ($stmt->execute([$data['subject_name'], $data['grade_level'], $data['grade_level_end'], $data['description'], $subjectId])) {
            jsonResponse(['success' => true, 'message' => 'Subject updated']);
        } else {
            jsonResponse(['error' => 'Failed to update subject'], 500);
        }
        break;

    default:
        jsonResponse(['error' => 'Invalid action'], 400);
}
