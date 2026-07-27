<?php
/**
 * LDB-FRAS - Students API
 * AJAX endpoint for student CRUD operations
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/face_api.php';

header('Content-Type: application/json');

// Handle GET requests for exports
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    requireRole(['admin']);
    $action = $_GET['action'] ?? '';
    
    if ($action === 'export_csv') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="students_export_' . date('Y-m-d') . '.csv"');

        $students = getStudents($db, []);

        $output = fopen('php://output', 'w');
        fputcsv($output, ['student_id', 'first_name', 'last_name', 'middle_name', 'grade_level', 'section', 'gender', 'status']);
        
        foreach ($students as $student) {
            fputcsv($output, [
                $student['student_id'],
                $student['first_name'],
                $student['last_name'],
                $student['middle_name'] ?? '',
                $student['grade_level'],
                $student['section'] ?? '',
                $student['gender'] ?? '',
                $student['status'] ?? 'active'
            ]);
        }
        
        fclose($output);
        exit;
    }

    if ($action === 'download_template') {
        require_once __DIR__ . '/../includes/xlsx_template.php';

        // Columns mirror the Add Student form (admin/student-add.php)
        $headers = [
            'LRN', 'first_name', 'middle_name', 'last_name', 'name_extension',
            'age', 'gender', 'email', 'grade_level', 'section', 'address',
            'guardian_name', 'relationship', 'guardian_phone', 'guardian_email', 'guardian_address'
        ];

        $example = [
            '113400000001', 'Juan', 'Santos', 'Dela Cruz', 'Jr.',
            '16', 'Male', 'juan@example.com', '11', 'St. Luke', 'Malolos, Bulacan',
            'Jose Dela Cruz', 'parents', '09171234567', 'jose@example.com', 'Malolos, Bulacan'
        ];

        $templateRows = [$headers, $example];
        $columnWidths = array_fill(0, count($headers), 18);

        $templateSheet = [
            'name' => 'Template',
            'xml'  => xlsx_sheet_xml($templateRows, 1, $columnWidths)
        ];

        $instructions = [
            ['Field', 'Required', 'Format / Allowed Values'],
            ['LRN', 'Yes', 'exactly 12 digits, must start with 1134'],
            ['first_name', 'Yes', 'Text'],
            ['middle_name', 'No', 'Text'],
            ['last_name', 'Yes', 'Text'],
            ['name_extension', 'No', 'Jr., Sr., II, III, IV, V, VI, VII, VIII, IX, X or blank'],
            ['age', 'Yes', 'Number between 5 and 25'],
            ['gender', 'Yes', 'Male or Female'],
            ['email', 'No', 'Valid email address'],
            ['grade_level', 'Yes', '7, 8, 9, 10, 11 or 12'],
            ['section', 'No', 'Text (must match an existing section for the grade)'],
            ['address', 'No', 'Text'],
            ['guardian_name', 'No', 'Text'],
            ['relationship', 'No', 'parents, guardian, sibling, relative, others'],
            ['guardian_phone', 'No', 'Philippine mobile: 11 digits starting with 09 (e.g. 09171234567)'],
            ['guardian_email', 'No', 'Valid email address'],
            ['guardian_address', 'No', 'Text'],
            ['', '', ''],
            ['Note', '', 'Save this file as .csv before using the Import CSV feature.'],
        ];
        $instructionSheet = [
            'name' => 'Instructions',
            'xml'  => xlsx_sheet_xml($instructions, 1, [20, 12, 60])
        ];

        $xlsx = xlsx_build_package([$templateSheet, $instructionSheet]);

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="student_import_template_' . date('Y-m-d') . '.xlsx"');
        header('Content-Length: ' . strlen($xlsx));
        header('Cache-Control: max-age=0');
        echo $xlsx;
        exit;
    }

    jsonResponse(['error' => 'Invalid GET action'], 400);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

requireRole(['admin']);

csrfMiddleware(true);

$action = $_POST['action'] ?? '';

switch ($action) {

    case 'list':
        $filters = [
            'grade_level' => $_POST['grade_level'] ?? '',
            'section'     => $_POST['section'] ?? '',
            'search'      => $_POST['search'] ?? ''
        ];
        $students = getStudents($db, $filters);
        jsonResponse(['data' => $students, 'count' => count($students)]);
        break;

    case 'get':
        $id = intval($_POST['id'] ?? 0);
        if (!$id) {
            jsonResponse(['error' => 'Student ID is required'], 400);
        }
        $student = getStudentById($db, $id);
        if ($student) {
            jsonResponse(['data' => $student]);
        } else {
            jsonResponse(['error' => 'Student not found'], 404);
        }
        break;

    case 'add':
        $data = [
            'student_id'  => sanitize($_POST['student_id'] ?? ''),
            'first_name'  => sanitize($_POST['first_name'] ?? ''),
            'middle_name' => sanitize($_POST['middle_name'] ?? ''),
            'last_name'   => sanitize($_POST['last_name'] ?? ''),
            'age'         => intval($_POST['age'] ?? 0),
            'gender'      => sanitize($_POST['gender'] ?? ''),
            'address'     => sanitize($_POST['address'] ?? ''),
            'email'       => sanitize($_POST['email'] ?? ''),
            'grade_level' => sanitize($_POST['grade_level'] ?? ''),
            'section'     => sanitize($_POST['section'] ?? '')
        ];

        // Validation
        if (empty($data['student_id']) || empty($data['first_name']) || empty($data['last_name'])) {
            jsonResponse(['error' => 'Student ID, first name, and last name are required'], 400);
        }

        // Check duplicate student ID
        $existing = getStudentByStudentId($db, $data['student_id']);
        if ($existing) {
            jsonResponse(['error' => 'Student ID already exists'], 400);
        }

        $guardianPhone = trim($_POST['guardian_phone'] ?? '');
        if (!empty($guardianPhone) && !isValidPhilippinePhone($guardianPhone)) {
            jsonResponse(['error' => 'Guardian phone number must start with +63 and contain 11 digits only.'], 400);
        }

        $result = addStudent($db, $data);

        if ($result) {
            saveGuardian($db, $result, [
                'guardian_name' => sanitize($_POST['guardian_name'] ?? ''),
                'relationship'  => sanitize($_POST['relationship'] ?? ''),
                'phone'         => normalizePhilippinePhone($_POST['guardian_phone'] ?? ''),
                'email'         => sanitize($_POST['guardian_email'] ?? ''),
                'address'       => sanitize($_POST['guardian_address'] ?? '')
            ]);

            jsonResponse(['success' => true, 'message' => 'Student added successfully', 'id' => $result]);
        } else {
            jsonResponse(['error' => 'Failed to add student'], 500);
        }
        break;

    case 'update':
        $id = intval($_POST['id'] ?? 0);
        if (!$id) {
            jsonResponse(['error' => 'Student ID is required'], 400);
        }

        $data = [
            'first_name'  => sanitize($_POST['first_name'] ?? ''),
            'middle_name' => sanitize($_POST['middle_name'] ?? ''),
            'last_name'   => sanitize($_POST['last_name'] ?? ''),
            'age'         => intval($_POST['age'] ?? 0),
            'gender'      => sanitize($_POST['gender'] ?? ''),
            'address'     => sanitize($_POST['address'] ?? ''),
            'email'       => sanitize($_POST['email'] ?? ''),
            'grade_level' => sanitize($_POST['grade_level'] ?? ''),
            'section'     => sanitize($_POST['section'] ?? '')
        ];

        $guardianPhone = trim($_POST['guardian_phone'] ?? '');
        if (!empty($guardianPhone) && !isValidPhilippinePhone($guardianPhone)) {
            jsonResponse(['error' => 'Guardian phone number must start with +63 and contain 11 digits only.'], 400);
        }

        if (updateStudent($db, $id, $data)) {
            $studentDbId = getStudentById($db, $id);
            saveGuardian($db, $studentDbId['id'], [
                'guardian_name' => sanitize($_POST['guardian_name'] ?? ''),
                'relationship'  => sanitize($_POST['relationship'] ?? ''),
                'phone'         => normalizePhilippinePhone($_POST['guardian_phone'] ?? ''),
                'email'         => sanitize($_POST['guardian_email'] ?? ''),
                'address'       => sanitize($_POST['guardian_address'] ?? '')
            ]);

            jsonResponse(['success' => true, 'message' => 'Student updated successfully']);
        } else {
            jsonResponse(['error' => 'Failed to update student'], 500);
        }
        break;

    case 'delete':
        $id = intval($_POST['id'] ?? 0);
        if (!$id) {
            jsonResponse(['error' => 'Student ID is required'], 400);
        }

        if (deleteStudent($db, $id)) {
            jsonResponse(['success' => true, 'message' => 'Student deleted successfully']);
        } else {
            jsonResponse(['error' => 'Failed to delete student'], 500);
        }
        break;

    case 'register_face':
        $studentId = intval($_POST['student_id'] ?? 0);
        $frontFace = $_POST['front_face'] ?? '';
        $leftFace  = $_POST['left_face'] ?? '';
        $rightFace = $_POST['right_face'] ?? '';

        if (!$studentId) {
            jsonResponse(['error' => 'Student ID is required'], 400);
        }

        $faceApi = new FaceRecognitionAPI();

        // Batch encode all face images
        $result = $faceApi->batchEncode([
            'front' => $frontFace,
            'left'  => $leftFace,
            'right' => $rightFace
        ]);

        if ($result && isset($result['encoding'])) {
            $student = getStudentById($db, $studentId);
            
            // Save images via Python API
            if ($frontFace) $faceApi->saveFaceImage($frontFace, $student['student_id'], 'front');
            if ($leftFace)  $faceApi->saveFaceImage($leftFace, $student['student_id'], 'left');
            if ($rightFace) $faceApi->saveFaceImage($rightFace, $student['student_id'], 'right');

            // Save encoding to database
            saveStudentFace($db, $studentId, [
                'front_face'    => $frontFace ? 'saved' : null,
                'left_face'     => $leftFace ? 'saved' : null,
                'right_face'    => $rightFace ? 'saved' : null,
                'face_encoding' => json_encode($result['encoding'])
            ]);

            jsonResponse([
                'success'       => true,
                'message'       => 'Face registered successfully',
                'faces_encoded' => $result['faces_encoded']
            ]);
        } else {
            jsonResponse(['error' => 'Failed to encode faces. Ensure clear face images.'], 400);
        }
        break;

    case 'import_csv':
        $studentsJson = $_POST['students'] ?? '[]';
        $skipDuplicates = ($_POST['skip_duplicates'] ?? '1') === '1';
        $updateExisting = ($_POST['update_existing'] ?? '0') === '1';
        
        $studentsData = json_decode($studentsJson, true);
        
        if (empty($studentsData) || !is_array($studentsData)) {
            jsonResponse(['error' => 'No valid student data provided'], 400);
        }
        
        $imported = 0;
        $skipped = 0;
        $updated = 0;
        $errors = 0;
        $errorDetails = [];
        
        $db->beginTransaction();
        
        try {
            foreach ($studentsData as $index => $row) {
                $rowNum = $index + 2; // +2 because CSV is 1-indexed and we skip header
                
                // Validate required fields
                if (empty($row['lrn']) || empty($row['first_name']) || empty($row['last_name'])) {
                    $errors++;
                    $errorDetails[] = "Row $rowNum: Missing required fields (LRN, first_name, last_name)";
                    continue;
                }
                
                $studentId = sanitize($row['lrn']);
                
                // Check if student already exists
                $existing = getStudentByStudentId($db, $studentId);
                
                if ($existing) {
                    if ($updateExisting) {
                        // Update existing student
                        $data = [
                            'first_name'  => sanitize($row['first_name']),
                            'last_name'   => sanitize($row['last_name']),
                            'middle_name' => sanitize($row['middle_name'] ?? ''),
                            'grade_level' => sanitize($row['grade_level'] ?? ''),
                            'section'     => sanitize($row['section'] ?? ''),
                            'gender'      => sanitize($row['gender'] ?? ''),
                        ];
                        
                        if (updateStudent($db, $existing['id'], $data)) {
                            $updated++;
                        } else {
                            $errors++;
                            $errorDetails[] = "Row $rowNum: Failed to update $studentId";
                        }
                    } elseif ($skipDuplicates) {
                        $skipped++;
                        continue;
                    } else {
                        $errors++;
                        $errorDetails[] = "Row $rowNum: Duplicate student ID $studentId";
                        continue;
                    }
                } else {
                    // Insert new student
                    $data = [
                        'student_id'  => $studentId,
                        'first_name'  => sanitize($row['first_name']),
                        'last_name'   => sanitize($row['last_name']),
                        'middle_name' => sanitize($row['middle_name'] ?? ''),
                        'age'         => intval($row['age'] ?? 0),
                        'gender'      => sanitize($row['gender'] ?? ''),
                        'address'     => sanitize($row['address'] ?? ''),
                        'email'       => sanitize($row['email'] ?? ''),
                        'grade_level' => sanitize($row['grade_level'] ?? ''),
                        'section'     => sanitize($row['section'] ?? '')
                    ];
                    
                    // Calculate age from birthdate if provided and age not set
                    if (empty($data['age']) && !empty($row['birthdate'])) {
                        $birthdate = new DateTime($row['birthdate']);
                        $now = new DateTime();
                        $data['age'] = $now->diff($birthdate)->y;
                    }
                    
                    if (addStudent($db, $data)) {
                        $imported++;
                    } else {
                        $errors++;
                        $errorDetails[] = "Row $rowNum: Failed to add $studentId";
                    }
                }
            }
            
            $db->commit();
            
            jsonResponse([
                'success'   => true,
                'message'   => "Import completed. $imported imported, $skipped skipped, $updated updated, $errors errors.",
                'imported'  => $imported,
                'skipped'   => $skipped,
                'updated'   => $updated,
                'errors'    => $errors,
                'error_details' => $errorDetails
            ]);
            
        } catch (Exception $e) {
            $db->rollBack();
            jsonResponse(['error' => 'Import failed: ' . $e->message], 500);
        }
        break;

    default:
        jsonResponse(['error' => 'Invalid action'], 400);
}
