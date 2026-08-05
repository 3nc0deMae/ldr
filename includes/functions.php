<?php

// ============================================================
// DATABASE HELPER
// ============================================================

/**
 * Get global database connection
 * Convenience wrapper for use in API files and notification modules
 * @return PDO
 */
function getDB() {
    global $db;
    if (!$db) {
        $database = new Database();
        $db = $database->getConnection();
    }
    return $db;
}

// ============================================================
// UTILITY FUNCTIONS
// ============================================================

/**
 * Sanitize input string
 * @param string|null $data
 * @return string
 */
function sanitize($data) {
    if ($data === null) {
        return '';
    }
    return htmlspecialchars(strip_tags(trim($data)), ENT_QUOTES, 'UTF-8');
}

/**
 * Validate email format
 * @param string $email
 * @return bool
 */
function isValidEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Validate Philippine phone number format.
 * Requires +63 followed by 10 digits (11 digits total including the +63 prefix).
 * @param string $phone
 * @return bool
 */
function isValidPhilippinePhone($phone) {
    $phone = trim((string) $phone);
    if ($phone === '') {
        return true;
    }

    return preg_match('/^09\d{9}$/', $phone) === 1;
}

/**
 * Normalize Philippine phone number to +63 format.
 * Accepts formats like 09171234567 or +639171234567.
 * @param string $phone
 * @return string
 */
function normalizePhilippinePhone($phone) {
    $phone = trim((string) $phone);
    if ($phone === '') {
        return '';
    }

    $digits = preg_replace('/[^0-9]/', '', $phone);
    if (strlen($digits) === 11 && $digits[0] === '0') {
        return $digits;
    }

    if (strlen($digits) === 10 && $digits[0] === '9') {
        return '0' . $digits;
    }

    return $phone;
}

/**
 * Generate random string (for tokens, OTP)
 * @param int $length
 * @return string
 */
function generateToken($length = 32) {
    return bin2hex(random_bytes($length / 2));
}

/**
 * Generate 6-digit OTP
 * @return string
 */
function generateOTP() {
    return str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

/**
 * Hash password using bcrypt
 * @param string $password
 * @return string
 */
function hashPassword($password) {
    return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
}

/**
 * Verify password against hash
 * @param string $password
 * @param string $hash
 * @return bool
 */
function verifyPassword($password, $hash) {
    return password_verify($password, $hash);
}

/**
 * Format date
 * @param string $date
 * @param string $format
 * @return string
 */
function formatDate($date, $format = 'F j, Y') {
    return date($format, strtotime($date));
}

/**
 * Format datetime
 * @param string $datetime
 * @param string $format
 * @return string
 */
function formatDateTime($datetime, $format = 'F j, Y g:i A') {
    return date($format, strtotime($datetime));
}

/**
 * Format time only
 * @param string $time
 * @return string
 */
function formatTime($time) {
    return date('g:i A', strtotime($time));
}

/**
 * Get current date
 * @return string
 */
function today() {
    return date('Y-m-d');
}

/**
 * Get current datetime
 * @return string
 */
function now() {
    return date('Y-m-d H:i:s');
}

/**
 * Redirect with flash message
 * @param string $url
 * @param string $message
 * @param string $type (success, danger, warning, info)
 */
function redirect($url, $message = '', $type = 'info') {
    if ($message) {
        $_SESSION['flash_message'] = $message;
        $_SESSION['flash_type']    = $type;
    }
    // Prepend BASE_URL for relative paths starting with /
    if (defined('BASE_URL') && strpos($url, '/') === 0 && strpos($url, '//') !== 0) {
        $url = BASE_URL . $url;
    }
    header("Location: $url");
    exit();
}

/**
 * Display and clear flash message
 * @return string HTML
 */
function displayFlashMessage() {
    if (isset($_SESSION['flash_message'])) {
        $flash = $_SESSION['flash_message'];
        if (is_array($flash)) {
            $message = $flash['message'] ?? '';
            $type    = $flash['type'] ?? ($_SESSION['flash_type'] ?? 'info');
        } else {
            $message = $flash;
            $type    = $_SESSION['flash_type'] ?? 'info';
        }
        unset($_SESSION['flash_message'], $_SESSION['flash_type']);
        return "<div class='alert alert-{$type} alert-dismissible fade show' role='alert'>
                    {$message}
                    <button type='button' class='btn-close' data-bs-dismiss='alert'></button>
                </div>";
    }
    return '';
}

/**
 * Generate CSRF token
 * @return string
 */
function generateCSRFToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = generateToken(64);
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verify CSRF token
 * @param string $token
 * @return bool
 */
function verifyCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Send JSON response
 * @param array $data
 * @param int $statusCode
 */
function jsonResponse($data, $statusCode = 200) {
    while (ob_get_level()) ob_end_clean();
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit();
}

// ============================================================
// USER FUNCTIONS
// ============================================================

/**
 * Get user by ID
 * @param PDO $db
 * @param int $id
 * @return array|null
 */
function getUserById($db, $id) {
    $stmt = $db->prepare("SELECT * FROM users WHERE id = ? AND status = 'active'");
    $stmt->execute([$id]);
    return $stmt->fetch();
}

/**
 * Get user by email
 * @param PDO $db
 * @param string $email
 * @return array|null
 */
function getUserByEmail($db, $email) {
    $stmt = $db->prepare("SELECT * FROM users WHERE email = ? AND status = 'active'");
    $stmt->execute([$email]);
    return $stmt->fetch();
}

/**
 * Create new user
 * @param PDO $db
 * @param array $data
 * @return int|false Last insert ID or false
 */
function createUser($db, $data) {
    $sql = "INSERT INTO users (role, email, password, status, created_at) 
            VALUES (:role, :email, :password, :status, NOW())";
    $stmt = $db->prepare($sql);
    return $stmt->execute([
        ':role'     => $data['role'],
        ':email'    => $data['email'],
        ':password' => hashPassword($data['password']),
        ':status'   => $data['status'] ?? 'active'
    ]) ? $db->lastInsertId() : false;
}

/**
 * Update user password
 * @param PDO $db
 * @param int $userId
 * @param string $newPassword
 * @return bool
 */
function updatePassword($db, $userId, $newPassword) {
    $stmt = $db->prepare("UPDATE users SET password = ? WHERE id = ?");
    return $stmt->execute([hashPassword($newPassword), $userId]);
}

// ============================================================
// STUDENT FUNCTIONS
// ============================================================

/**
 * Get all students with optional filters
 * @param PDO $db
 * @param array $filters
 * @return array
 */
function getStudents($db, $filters = []) {
    $sql = "SELECT s.*, g.guardian_name, g.phone as guardian_phone, g.email as guardian_email
            FROM students s
            LEFT JOIN guardians g ON s.id = g.student_id";
    $conditions = [];
    $params = [];

    if (!empty($filters['grade_level'])) {
        $conditions[] = "s.grade_level = :grade_level";
        $params[':grade_level'] = $filters['grade_level'];
    }
    if (!empty($filters['section'])) {
        $conditions[] = "s.section = :section";
        $params[':section'] = $filters['section'];
    }
    if (!empty($filters['search'])) {
        $conditions[] = "(s.first_name LIKE :search OR s.last_name LIKE :search2 OR s.student_id LIKE :search3)";
        $params[':search']  = '%' . $filters['search'] . '%';
        $params[':search2'] = '%' . $filters['search'] . '%';
        $params[':search3'] = '%' . $filters['search'] . '%';
    }

    if ($conditions) {
        $sql .= " WHERE " . implode(" AND ", $conditions);
    }
    $sql .= " ORDER BY s.last_name ASC, s.first_name ASC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Get single student by ID
 * @param PDO $db
 * @param int $id
 * @return array|null
 */
function getStudentById($db, $id) {
    $stmt = $db->prepare("SELECT s.*, g.guardian_name, g.relationship, g.phone as guardian_phone, 
                          g.email as guardian_email, g.address as guardian_address
                          FROM students s
                          LEFT JOIN guardians g ON s.id = g.student_id
                          WHERE s.id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch();
}

/**
 * Get student by student_id number
 * @param PDO $db
 * @param string $studentId
 * @return array|null
 */
function getStudentByStudentId($db, $studentId) {
    $stmt = $db->prepare("SELECT * FROM students WHERE student_id = ?");
    $stmt->execute([$studentId]);
    return $stmt->fetch();
}

/**
 * Add new student
 * @param PDO $db
 * @param array $data
 * @return int|false
 */
function addStudent($db, $data) {
    $sql = "INSERT INTO students (student_id, first_name, middle_name, last_name, name_extension, age, gender, 
            address, email, grade_level, section, created_at)
            VALUES (:student_id, :first_name, :middle_name, :last_name, :name_extension, :age, :gender,
            :address, :email, :grade_level, :section, NOW())";
    $stmt = $db->prepare($sql);
    return $stmt->execute([
        ':student_id'   => $data['student_id'],
        ':first_name'   => $data['first_name'],
        ':middle_name'  => $data['middle_name'] ?? '',
        ':last_name'    => $data['last_name'],
        ':name_extension' => $data['name_extension'] ?? '',
        ':age'          => $data['age'] ?? '',
        ':gender'       => $data['gender'] ?? '',
        ':address'      => $data['address'] ?? '',
        ':email'        => $data['email'] ?? '',
        ':grade_level'  => $data['grade_level'] ?? '',
        ':section'      => $data['section'] ?? '']
    ) ? $db->lastInsertId() : false;
}

/**
 * Update student
 * @param PDO $db
 * @param int $id
 * @param array $data
 * @return bool
 */
function updateStudent($db, $id, $data) {
    $sql = "UPDATE students SET first_name = :first_name, name_extension = :name_extension, middle_name = :middle_name,
            last_name = :last_name, age = :age, gender = :gender, address = :address,
            email = :email, grade_level = :grade_level, section = :section, updated_at = NOW()
            WHERE id = :id";
    $stmt = $db->prepare($sql);
    return $stmt->execute([
        ':first_name'   => $data['first_name'],
        ':name_extension' => $data['name_extension'] ?? '',
        ':middle_name'  => $data['middle_name'] ?? '',
        ':last_name'    => $data['last_name'],
        ':age'          => $data['age'] ?? '',
        ':gender'       => $data['gender'] ?? '',
        ':address'      => $data['address'] ?? '',
        ':email'        => $data['email'] ?? '',
        ':grade_level'  => $data['grade_level'] ?? '',
        ':section'      => $data['section'] ?? '',
        ':id'           => $id
    ]);
}

/**
 * Delete student (soft delete)
 * @param PDO $db
 * @param int $id
 * @return bool
 */
function deleteStudent($db, $id) {
    $stmt = $db->prepare("DELETE FROM students WHERE id = ?");
    return $stmt->execute([$id]);
}

// ============================================================
// GUARDIAN FUNCTIONS
// ============================================================

/**
 * Add or update guardian for a student
 * @param PDO $db
 * @param int $studentId
 * @param array $data
 * @return bool
 */
function saveGuardian($db, $studentId, $data) {
    $existing = null;
    try {
        $stmt = $db->prepare("SELECT * FROM guardians WHERE student_id = ?");
        $stmt->execute([$studentId]);
        $existing = $stmt->fetch();
    } catch (Exception $e) {
        $existing = null;
    }

    $guardianName = trim($data['guardian_name'] ?? '');
    $relationship = trim($data['relationship'] ?? '');
    $phone = trim($data['phone'] ?? '');
    $email = trim($data['email'] ?? '');
    $address = trim($data['address'] ?? '');

    if (empty($guardianName) && $existing) {
        $guardianName = $existing['guardian_name'] ?? '';
    }
    if (empty($relationship) && $existing) {
        $relationship = $existing['relationship'] ?? 'parents';
    }
    if (empty($phone) && $existing) {
        $phone = $existing['phone'] ?? '';
    }
    if (empty($email) && $existing) {
        $email = $existing['email'] ?? '';
    }
    if (empty($address) && $existing) {
        $address = $existing['address'] ?? '';
    }

    if (!$existing && empty($guardianName) && empty($relationship) && empty($phone) && empty($email) && empty($address)) {
        return true;
    }

    $sql = "INSERT INTO guardians (student_id, guardian_name, relationship, phone, email, address)
            VALUES (:student_id, :guardian_name, :relationship, :phone, :email, :address)
            ON DUPLICATE KEY UPDATE
            guardian_name = VALUES(guardian_name), relationship = VALUES(relationship),
            phone = VALUES(phone), email = VALUES(email), address = VALUES(address)";
    $stmt = $db->prepare($sql);
    return $stmt->execute([
        ':student_id'    => $studentId,
        ':guardian_name' => $guardianName,
        ':relationship'  => $relationship,
        ':phone'         => $phone,
        ':email'         => $email,
        ':address'       => $address
    ]);
}

// ============================================================
// STUDENT FACES FUNCTIONS
// ============================================================

/**
 * Save face encoding for a student
 * @param PDO $db
 * @param int $studentId
 * @param array $data
 * @return bool
 */
function saveStudentFace($db, $studentId, $data) {
    $sql = "INSERT INTO student_faces (student_id, front_face, left_face, right_face, face_encoding, created_at, updated_at)
            VALUES (:student_id, :front_face, :left_face, :right_face, :face_encoding, NOW(), NOW())
            ON DUPLICATE KEY UPDATE
            front_face = VALUES(front_face), left_face = VALUES(left_face),
            right_face = VALUES(right_face), face_encoding = VALUES(face_encoding), updated_at = NOW()";
    $stmt = $db->prepare($sql);
    return $stmt->execute([
        ':student_id'    => $studentId,
        ':front_face'    => $data['front_face'],
        ':left_face'     => $data['left_face'],
        ':right_face'    => $data['right_face'],
        ':face_encoding' => $data['face_encoding']
    ]);
}

/**
 * Get all face encodings for recognition matching
 * @param PDO $db
 * @return array
 */
function getAllFaceEncodings($db) {
    $stmt = $db->query("SELECT sf.*, s.first_name, s.last_name, s.student_id as student_number
                        FROM student_faces sf
                        JOIN students s ON sf.student_id = s.id
                        WHERE sf.face_encoding IS NOT NULL");
    return $stmt->fetchAll();
}

// ============================================================
// TEACHER FUNCTIONS
// ============================================================

/**
 * Get all teachers with optional filters
 * @param PDO $db
 * @param array $filters
 * @param int $limit
 * @param int $offset
 * @return array
 */
function getTeachers($db, $filters = [], $limit = 0, $offset = 0) {
    $sql = "SELECT t.*, u.email as user_email FROM teachers t
            LEFT JOIN users u ON t.user_id = u.id";
    $conditions = [];
    $params = [];

    if (!empty($filters['search'])) {
        $conditions[] = "(t.first_name LIKE :search OR t.last_name LIKE :search2 OR t.email LIKE :search3 OR t.employee_id LIKE :search4)";
        $params[':search']  = '%' . $filters['search'] . '%';
        $params[':search2'] = '%' . $filters['search'] . '%';
        $params[':search3'] = '%' . $filters['search'] . '%';
        $params[':search4'] = '%' . $filters['search'] . '%';
    }
    if (!empty($filters['department'])) {
        $conditions[] = "t.department = :department";
        $params[':department'] = $filters['department'];
    }

    if ($conditions) {
        $sql .= " WHERE " . implode(" AND ", $conditions);
    }
    $sql .= " ORDER BY t.last_name ASC, t.first_name ASC";

    if ($limit > 0) {
        $sql .= " LIMIT $limit OFFSET $offset";
    }

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Get teacher by ID
 * @param PDO $db
 * @param int $id
 * @return array|null
 */
function getTeacherById($db, $id) {
    $stmt = $db->prepare("SELECT * FROM teachers WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch();
}

/**
 * Normalize teacher subject selections into a compact comma-separated string.
 * @param mixed $subjectsHandled
 * @return string
 */
function normalizeTeacherSubjectsInput($subjectsHandled) {
    if (is_array($subjectsHandled)) {
        $subjects = array_map('trim', $subjectsHandled);
    } elseif (is_string($subjectsHandled)) {
        $subjects = preg_split('/[\r\n,;]+/', $subjectsHandled);
        $subjects = array_map('trim', $subjects);
    } else {
        $subjects = [];
    }

    $subjects = array_values(array_unique(array_filter($subjects, function ($subject) {
        return $subject !== '';
    })));

    return implode(', ', $subjects);
}

/**
 * Format advisory class labels from grade, section, and optional strand data.
 * @param string|int $gradeLevel
 * @param string $sectionName
 * @param string $strandName
 * @return string
 */
function formatAdvisoryClassLabel($gradeLevel, $sectionName = '', $strandName = '') {
    $grade = trim((string)$gradeLevel);
    $section = trim((string)$sectionName);
    $strand = trim((string)$strandName);

    $label = $grade;
    if ($section !== '') {
        $label .= $section;
    }

    if ($strand !== '') {
        $label .= ' - ' . $strand;
    }

    return $label;
}

/**
 * Resolve an advisory class label (e.g. "Grade 10-St. Peter", "10St. Peter",
 * "11STEM-A - STEM") back to its matching row id in the `sections` table.
 *
 * @param PDO    $db
 * @param string $label
 * @return int|null
 */
function resolveAdvisorySection($db, $label) {
    $label = trim((string)$label);
    if ($label === '') return null;

    // Grade run must not be followed by another digit (labels such as "10St. Peter"
    // have no separator between the grade and the section name, so \b would fail).
    $grade = null;
    if (preg_match('/(?:Grade\s+)?(\d{1,2})(?![0-9])/i', $label, $m)) {
        $grade = $m[1];
    }
    if ($grade === null) return null;

    // Candidate section names derived from the label
    $candidates = [];
    $rest = trim(preg_replace('/^\s*(?:Grade\s+)?\d{1,2}(?![0-9])\s*(?:-\s*)?/i', '', $label));
    if ($rest !== '') {
        $candidates[] = trim($rest, " \t\r\n-");
        // Strip a strand suffix such as " - STEM" or " - ABM"
        $withoutStrand = trim(preg_replace('/\s*-\s*[^\-]*$/u', '', $rest));
        if ($withoutStrand !== '' && $withoutStrand !== $rest) {
            $candidates[] = $withoutStrand;
        }
    }

    $stmt = $db->prepare("SELECT id FROM sections WHERE grade_level = ? AND section_name = ? ORDER BY id LIMIT 1");
    foreach (array_unique($candidates) as $cand) {
        if ($cand === '') continue;
        $stmt->execute([$grade, $cand]);
        $id = $stmt->fetchColumn();
        if ($id) return (int)$id;
    }

    // Flexible fallback: match a section whose name matches ignoring spaces,
    // dashes and dots, and optionally its strand suffix (e.g. "STEM-A" vs "STEM A").
    $flex = $db->prepare(
        "SELECT s.id FROM sections s
         WHERE s.grade_level = ?
           AND LOWER(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(TRIM(s.section_name),' ',''),'-',''),'.',''),'''',''),'/','')) = ?
         ORDER BY s.id LIMIT 1"
    );
    foreach (array_unique($candidates) as $cand) {
        if ($cand === '') continue;
        $normalized = strtolower(preg_replace('/[^a-z0-9]/i', '', $cand));
        if ($normalized === '') continue;
        $flex->execute([$grade, $normalized]);
        $id = $flex->fetchColumn();
        if ($id) return (int)$id;
    }

    // Last resort: the section is recorded but we could not parse its name.
    $stmt = $db->prepare("SELECT id FROM sections WHERE grade_level = ? ORDER BY id LIMIT 1");
    $stmt->execute([$grade]);
    $id = $stmt->fetchColumn();
    return $id ? (int)$id : null;
}

/**
 * Resolve the currently logged-in teacher's advisory section id.
 * Also caches the result in the session for quick access.
 *
 * @param PDO $db
 * @return int|null
 */
function getAdvisorySectionId($db) {
    $uid = getCurrentUserId();
    if (!$uid) return null;

    // Reuse a previously resolved id, but only while its section still exists.
    if (!empty($_SESSION['advisory_section_id'])) {
        $cached = (int)$_SESSION['advisory_section_id'];
        $check = $db->prepare("SELECT id FROM sections WHERE id = ? LIMIT 1");
        $check->execute([$cached]);
        if ($check->fetch()) return $cached;
        unset($_SESSION['advisory_section_id']);
    }

    // Locate the teacher row by user id (fall back to email).
    $teacher = null;
    $stmt = $db->prepare(
        "SELECT advisory_class, advisory_section_id FROM teachers
         WHERE user_id = ? AND (advisory_class IS NOT NULL AND advisory_class != '')
         LIMIT 1"
    );
    $stmt->execute([$uid]);
    $teacher = $stmt->fetch();
    if (!$teacher) {
        $email = getCurrentUserEmail();
        if ($email) {
            $stmt = $db->prepare(
                "SELECT advisory_class, advisory_section_id FROM teachers
                 WHERE email = ? AND (advisory_class IS NOT NULL AND advisory_class != '')
                 LIMIT 1"
            );
            $stmt->execute([$email]);
            $teacher = $stmt->fetch();
        }
    }
    if (!$teacher) return null;

    $sectionId = null;
    if (!empty($teacher['advisory_section_id'])) {
        $sectionId = (int)$teacher['advisory_section_id'];
    } elseif (!empty($teacher['advisory_class'])) {
        $sectionId = resolveAdvisorySection($db, $teacher['advisory_class']);
    }
    if ($sectionId) {
        $_SESSION['advisory_section_id'] = $sectionId;
    }
    return $sectionId;
}

/**
 * Fetch the advisory section record (id, grade_level, section_name, strand) for
 * the currently logged-in teacher.
 *
 * @param PDO $db
 * @return array|null
 */
function getAdvisorySectionRecord($db) {
    $sectionId = getAdvisorySectionId($db);
    if (!$sectionId) return null;

    $stmt = $db->prepare(
        "SELECT s.id, s.grade_level, s.section_name, st.strand_name, st.strand_code
         FROM sections s
         LEFT JOIN strands st ON s.strand_id = st.id
         WHERE s.id = ? LIMIT 1"
    );
    $stmt->execute([$sectionId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Fetch the currently logged-in teacher's profile record.
 *
 * @param PDO $db
 * @return array|null
 */
function getCurrentTeacherRecord($db) {
    $uid = getCurrentUserId();
    if (!$uid) return null;
    $stmt = $db->prepare("SELECT t.* FROM teachers t JOIN users u ON t.user_id = u.id WHERE u.id = ? LIMIT 1");
    $stmt->execute([$uid]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Sync teacher_subjects pivot table from extracted subject IDs
 */
function syncTeacherSubjects($db, $teacherId, $allSubjectIds) {
    $allSubjectIds = array_values(array_unique(array_filter($allSubjectIds, function ($id) { return $id > 0; })));
    if (empty($allSubjectIds)) {
        $db->prepare("DELETE FROM teacher_subjects WHERE teacher_id = ?")->execute([$teacherId]);
        return;
    }
    $db->prepare("DELETE FROM teacher_subjects WHERE teacher_id = ?")->execute([$teacherId]);
    $stmt = $db->prepare("INSERT IGNORE INTO teacher_subjects (teacher_id, subject_id) VALUES (?, ?)");
    foreach ($allSubjectIds as $sid) {
        $stmt->execute([$teacherId, $sid]);
    }
}

/**
 * Add teacher
 * @param PDO $db
 * @param array $data
 * @return int|false
 */
function addTeacher($db, $data) {
    $gradeSectionHandled = $data['grade_section_handled'] ?? [];
    if (is_string($gradeSectionHandled)) {
        $gradeSectionHandled = json_decode($gradeSectionHandled, true) ?: [];
    }
    if (!is_array($gradeSectionHandled)) $gradeSectionHandled = [];

    $gradeSectionJson = !empty($gradeSectionHandled) ? json_encode($gradeSectionHandled) : null;

    $subjectIds = [];
    foreach ($gradeSectionHandled as $item) {
        if (!empty($item['subject_id']) && is_numeric($item['subject_id'])) {
            $subjectIds[] = (int)$item['subject_id'];
        }
    }
    $subjectIds = array_values(array_unique($subjectIds));

    $subjectNames = [];
    if (!empty($subjectIds)) {
        $ph = implode(',', array_fill(0, count($subjectIds), '?'));
        $stmt = $db->prepare("SELECT subject_name FROM subjects WHERE id IN ($ph)");
        $stmt->execute($subjectIds);
        foreach ($stmt->fetchAll() as $s) {
            $subjectNames[] = $s['subject_name'];
        }
    }

    $coreSubjectsHandled = $data['core_subjects_handled'] ?? [];
    if (is_string($coreSubjectsHandled)) {
        $coreSubjectsHandled = json_decode($coreSubjectsHandled, true) ?: [];
    }
    if (!is_array($coreSubjectsHandled)) $coreSubjectsHandled = [];

    $coreSubjectIds = [];
    foreach ($coreSubjectsHandled as $item) {
        if (!empty($item['subject_id']) && is_numeric($item['subject_id'])) {
            $coreSubjectIds[] = (int)$item['subject_id'];
        }
    }
    $coreSubjectIds = array_values(array_unique($coreSubjectIds));
    $coreSubjectNames = [];
    if (!empty($coreSubjectIds)) {
        $ph = implode(',', array_fill(0, count($coreSubjectIds), '?'));
        $stmt = $db->prepare("SELECT subject_name FROM subjects WHERE id IN ($ph)");
        $stmt->execute($coreSubjectIds);
        foreach ($stmt->fetchAll() as $s) {
            $coreSubjectNames[] = $s['subject_name'];
        }
    }
    $coreSubjectsJson = !empty($coreSubjectsHandled) ? json_encode($coreSubjectsHandled) : null;

    $trackElectiveHandled = $data['track_elective_handled'] ?? [];
    if (is_string($trackElectiveHandled)) {
        $trackElectiveHandled = json_decode($trackElectiveHandled, true) ?: [];
    }
    if (!is_array($trackElectiveHandled)) $trackElectiveHandled = [];
    $trackElectiveJson = !empty($trackElectiveHandled) ? json_encode($trackElectiveHandled) : null;

    $trackElectiveSubjectIds = [];
    foreach ($trackElectiveHandled as $item) {
        if (!empty($item['subject_id']) && is_numeric($item['subject_id'])) {
            $trackElectiveSubjectIds[] = (int)$item['subject_id'];
        }
    }
    $trackElectiveSubjectIds = array_values(array_unique($trackElectiveSubjectIds));

    $sql = "INSERT INTO teachers (employee_id, first_name, middle_name, last_name, email, phone, department, subjects_handled, grade_section_handled, core_subjects_handled, track_elective_handled, advisory_class, created_at)
            VALUES (:employee_id, :first_name, :middle_name, :last_name, :email, :phone, :department, :subjects_handled, :grade_section_handled, :core_subjects_handled, :track_elective_handled, :advisory_class, NOW())";
    $stmt = $db->prepare($sql);
    $result = $stmt->execute([
        ':employee_id'        => $data['employee_id'] ?? null,
        ':first_name'         => $data['first_name'],
        ':middle_name'        => $data['middle_name'] ?? '',
        ':last_name'          => $data['last_name'],
        ':email'              => $data['email'],
        ':phone'              => $data['phone'] ?? '',
        ':department'         => $data['department'] ?? '',
        ':subjects_handled'   => implode(', ', array_unique(array_merge($subjectNames, $coreSubjectNames))),
        ':grade_section_handled' => $gradeSectionJson,
        ':core_subjects_handled' => $coreSubjectsJson,
        ':track_elective_handled' => $trackElectiveJson,
        ':advisory_class'     => $data['advisory_class'] ?? ''
    ]);
    $teacherId = $result ? $db->lastInsertId() : false;

    if ($teacherId) {
        $allSubjectIds = array_values(array_unique(array_merge($subjectIds, $coreSubjectIds, $trackElectiveSubjectIds)));
        syncTeacherSubjects($db, $teacherId, $allSubjectIds);
    }

    if ($teacherId && !empty($data['user_id'])) {
        $db->prepare("UPDATE teachers SET user_id = ? WHERE id = ?")->execute([(int)$data['user_id'], $teacherId]);
    } elseif ($teacherId && !empty($data['password'])) {
        try {
            $userId = createUser($db, [
                'role'     => 'teacher',
                'email'    => $data['email'],
                'password' => $data['password'],
                'status'   => 'active'
            ]);
            if ($userId) {
                $db->prepare("UPDATE teachers SET user_id = ? WHERE id = ?")->execute([$userId, $teacherId]);
            }
        } catch (Exception $e) {
            error_log('addTeacher createUser: ' . $e->getMessage());
        }
    }

    return $teacherId;
}

/**
 * Update teacher
 * @param PDO $db
 * @param int $id
 * @param array $data
 * @return bool
 */
function updateTeacher($db, $id, $data) {
    $gradeSectionHandled = $data['grade_section_handled'] ?? [];
    if (is_string($gradeSectionHandled)) {
        $gradeSectionHandled = json_decode($gradeSectionHandled, true) ?: [];
    }
    if (!is_array($gradeSectionHandled)) $gradeSectionHandled = [];

    $gradeSectionJson = !empty($gradeSectionHandled) ? json_encode($gradeSectionHandled) : null;

    $subjectIds = [];
    foreach ($gradeSectionHandled as $item) {
        if (!empty($item['subject_id']) && is_numeric($item['subject_id'])) {
            $subjectIds[] = (int)$item['subject_id'];
        }
    }
    $subjectIds = array_values(array_unique($subjectIds));

    $subjectNames = [];
    if (!empty($subjectIds)) {
        $ph = implode(',', array_fill(0, count($subjectIds), '?'));
        $stmt = $db->prepare("SELECT subject_name FROM subjects WHERE id IN ($ph)");
        $stmt->execute($subjectIds);
        foreach ($stmt->fetchAll() as $s) {
            $subjectNames[] = $s['subject_name'];
        }
    }

    $coreSubjectsHandled = $data['core_subjects_handled'] ?? [];
    if (is_string($coreSubjectsHandled)) {
        $coreSubjectsHandled = json_decode($coreSubjectsHandled, true) ?: [];
    }
    if (!is_array($coreSubjectsHandled)) $coreSubjectsHandled = [];

    $coreSubjectIds = [];
    foreach ($coreSubjectsHandled as $item) {
        if (!empty($item['subject_id']) && is_numeric($item['subject_id'])) {
            $coreSubjectIds[] = (int)$item['subject_id'];
        }
    }
    $coreSubjectIds = array_values(array_unique($coreSubjectIds));
    $coreSubjectNames = [];
    if (!empty($coreSubjectIds)) {
        $ph = implode(',', array_fill(0, count($coreSubjectIds), '?'));
        $stmt = $db->prepare("SELECT subject_name FROM subjects WHERE id IN ($ph)");
        $stmt->execute($coreSubjectIds);
        foreach ($stmt->fetchAll() as $s) {
            $coreSubjectNames[] = $s['subject_name'];
        }
    }
    $coreSubjectsJson = !empty($coreSubjectsHandled) ? json_encode($coreSubjectsHandled) : null;

    $trackElectiveHandled = $data['track_elective_handled'] ?? [];
    if (is_string($trackElectiveHandled)) {
        $trackElectiveHandled = json_decode($trackElectiveHandled, true) ?: [];
    }
    if (!is_array($trackElectiveHandled)) $trackElectiveHandled = [];
    $trackElectiveJson = !empty($trackElectiveHandled) ? json_encode($trackElectiveHandled) : null;

    $trackElectiveSubjectIds = [];
    foreach ($trackElectiveHandled as $item) {
        if (!empty($item['subject_id']) && is_numeric($item['subject_id'])) {
            $trackElectiveSubjectIds[] = (int)$item['subject_id'];
        }
    }
    $trackElectiveSubjectIds = array_values(array_unique($trackElectiveSubjectIds));

    $sql = "UPDATE teachers SET employee_id = :employee_id, first_name = :first_name,
            middle_name = :middle_name, last_name = :last_name, email = :email, phone = :phone, department = :department,
            subjects_handled = :subjects_handled, grade_section_handled = :grade_section_handled,
            core_subjects_handled = :core_subjects_handled, track_elective_handled = :track_elective_handled,
            advisory_class = :advisory_class, updated_at = NOW()
            WHERE id = :id";
    $stmt = $db->prepare($sql);
    $updated = $stmt->execute([
        ':employee_id'        => $data['employee_id'] ?? null,
        ':first_name'         => $data['first_name'],
        ':middle_name'        => $data['middle_name'] ?? '',
        ':last_name'          => $data['last_name'],
        ':email'              => $data['email'],
        ':phone'              => $data['phone'] ?? '',
        ':department'         => $data['department'] ?? '',
        ':subjects_handled'   => implode(', ', array_unique(array_merge($subjectNames, $coreSubjectNames))),
        ':grade_section_handled' => $gradeSectionJson,
        ':core_subjects_handled' => $coreSubjectsJson,
        ':track_elective_handled' => $trackElectiveJson,
        ':advisory_class'     => $data['advisory_class'] ?? '',
        ':id'                 => $id
    ]);
    if ($updated) {
        $allSubjectIds = array_values(array_unique(array_merge($subjectIds, $coreSubjectIds, $trackElectiveSubjectIds)));
        syncTeacherSubjects($db, $id, $allSubjectIds);
    }
    return $updated;
}

/**
 * Delete teacher
 * @param PDO $db
 * @param int $id
 * @return bool
 */
function deleteTeacher($db, $id) {
    $stmt = $db->prepare("DELETE FROM teachers WHERE id = ?");
    return $stmt->execute([$id]);
}

// ============================================================
// SUBJECT FUNCTIONS
// ============================================================

/**
 * Get all subjects
 * @param PDO $db
 * @param string $gradeLevel
 * @return array
 */
function getSubjects($db, $gradeLevel = '') {
    $sql = "SELECT * FROM subjects";
    $params = [];
    if ($gradeLevel) {
        $sql .= " WHERE grade_level = :grade_level";
        $params[':grade_level'] = $gradeLevel;
    }
    $sql .= " ORDER BY subject_name ASC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Get subject by ID
 * @param PDO $db
 * @param int $id
 * @return array|null
 */
function getSubjectById($db, $id) {
    $stmt = $db->prepare("SELECT * FROM subjects WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch();
}

/**
 * Determine if a subject belongs to junior or senior high based on its grade range.
 * @param string|int $gradeLevel
 * @param string|int|null $gradeLevelEnd
 * @return string|null
 */
function getSubjectLevelBucket($gradeLevel, $gradeLevelEnd = null) {
    $start = (int)$gradeLevel;
    $end = (int)($gradeLevelEnd !== null && $gradeLevelEnd !== '' ? $gradeLevelEnd : $gradeLevel);

    if ($end <= 10) {
        return 'jhs';
    }

    if ($start >= 11) {
        return 'shs';
    }

    return null;
}

/**
 * Add subject
 * @param PDO $db
 * @param array $data
 * @return int|false
 */
function addSubject($db, $data) {
    $sql = "INSERT INTO subjects (subject_name, subject_code, grade_level, description, created_at)
            VALUES (:subject_name, :subject_code, :grade_level, :description, NOW())";
    $stmt = $db->prepare($sql);
    return $stmt->execute([
        ':subject_name' => $data['subject_name'],
        ':subject_code' => $data['subject_code'],
        ':grade_level'  => $data['grade_level'],
        ':description'  => $data['description'] ?? ''
    ]) ? $db->lastInsertId() : false;
}

/**
 * Update subject
 * @param PDO $db
 * @param int $id
 * @param array $data
 * @return bool
 */
function updateSubject($db, $id, $data) {
    $sql = "UPDATE subjects SET subject_name = :subject_name, subject_code = :subject_code,
            grade_level = :grade_level, description = :description, updated_at = NOW() WHERE id = :id";
    $stmt = $db->prepare($sql);
    return $stmt->execute([
        ':subject_name' => $data['subject_name'],
        ':subject_code' => $data['subject_code'],
        ':grade_level'  => $data['grade_level'],
        ':description'  => $data['description'] ?? '',
        ':id'           => $id
    ]);
}

/**
 * Delete subject
 * @param PDO $db
 * @param int $id
 * @return bool
 */
function deleteSubject($db, $id) {
    $stmt = $db->prepare("DELETE FROM subjects WHERE id = ?");
    return $stmt->execute([$id]);
}

// ============================================================
// ATTENDANCE FUNCTIONS
// ============================================================

/**
 * Record attendance
 * @param PDO $db
 * @param array $data
 * @return int|false
 */
function recordAttendance($db, $data) {
    $sql = "INSERT INTO attendance (student_id, subject_id, date, time, status, created_at)
            VALUES (:student_id, :subject_id, :date, :time, :status, NOW())
            ON DUPLICATE KEY UPDATE status = VALUES(status), time = VALUES(time)";
    $stmt = $db->prepare($sql);
    return $stmt->execute([
        ':student_id' => $data['student_id'],
        ':subject_id' => $data['subject_id'],
        ':date'       => $data['date'],
        ':time'       => $data['time'],
        ':status'     => $data['status']
    ]) ? $db->lastInsertId() : false;
}

/**
 * Get attendance records with filters
 * @param PDO $db
 * @param array $filters
 * @return array
 */
function getAttendance($db, $filters = []) {
    $sql = "SELECT a.*, s.first_name, s.last_name, s.student_id as student_number,
            sub.subject_name
            FROM attendance a
            JOIN students s ON a.student_id = s.id
            LEFT JOIN subjects sub ON a.subject_id = sub.id";
    $conditions = [];
    $params = [];

    if (!empty($filters['date'])) {
        $conditions[] = "a.date = :date";
        $params[':date'] = $filters['date'];
    }
    if (!empty($filters['subject_id'])) {
        $conditions[] = "a.subject_id = :subject_id";
        $params[':subject_id'] = $filters['subject_id'];
    }
    if (!empty($filters['status'])) {
        $conditions[] = "a.status = :status";
        $params[':status'] = $filters['status'];
    }
    if (!empty($filters['student_id'])) {
        $conditions[] = "a.student_id = :student_id";
        $params[':student_id'] = $filters['student_id'];
    }
    if (!empty($filters['date_from'])) {
        $conditions[] = "a.date >= :date_from";
        $params[':date_from'] = $filters['date_from'];
    }
    if (!empty($filters['date_to'])) {
        $conditions[] = "a.date <= :date_to";
        $params[':date_to'] = $filters['date_to'];
    }

    if ($conditions) {
        $sql .= " WHERE " . implode(" AND ", $conditions);
    }
    $sql .= " ORDER BY a.date DESC, a.time DESC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Get attendance statistics for dashboard
 * @param PDO $db
 * @param string $date
 * @return array
 */
function getAttendanceStats($db, $date = '') {
    $date = $date ?: today();
    $stats = [];

    $sql = "SELECT status, COUNT(*) as count
            FROM (
                SELECT status FROM attendance WHERE date = ?
                UNION ALL
                SELECT status FROM attendance_records WHERE DATE(scan_time) = ?
            ) records
            GROUP BY status";

    $stmt = $db->prepare($sql);
    $stmt->execute([$date, $date]);

    $stats['present_today'] = 0;
    $stats['absent_today'] = 0;
    $stats['late_today'] = 0;

    foreach ($stmt->fetchAll() as $row) {
        if ($row['status'] === 'present') {
            $stats['present_today'] = (int) $row['count'];
        } elseif ($row['status'] === 'absent') {
            $stats['absent_today'] = (int) $row['count'];
        } elseif ($row['status'] === 'late') {
            $stats['late_today'] = (int) $row['count'];
        }
    }

    return $stats;
}

// ============================================================
// GATE LOG FUNCTIONS
// ============================================================

/**
 * Record gate log (time-in or time-out)
 * @param PDO $db
 * @param array $data
 * @return int|false
 */
function recordGateLog($db, $data) {
    if (isset($data['time_in'])) {
        $sql = "INSERT INTO gate_logs (student_id, time_in, status, created_at)
                VALUES (:student_id, :time_in, :status, NOW())";
        $stmt = $db->prepare($sql);
        return $stmt->execute([
            ':student_id' => $data['student_id'],
            ':time_in'    => $data['time_in'],
            ':status'     => $data['status'] ?? 'time-in'
        ]) ? $db->lastInsertId() : false;
    } else {
        $sql = "UPDATE gate_logs SET time_out = :time_out WHERE student_id = :student_id 
                AND DATE(time_in) = CURDATE() AND time_out IS NULL";
        $stmt = $db->prepare($sql);
        return $stmt->execute([
            ':time_out'   => $data['time_out'],
            ':student_id' => $data['student_id']
        ]);
    }
}

/**
 * Get gate logs
 * @param PDO $db
 * @param string $date
 * @return array
 */
function getGateLogs($db, $date = '') {
    $date = $date ?: today();
    $sql = "SELECT gl.*, s.first_name, s.last_name, s.student_id as student_number
            FROM gate_logs gl
            JOIN students s ON gl.student_id = s.id
            WHERE DATE(gl.time_in) = :date
            ORDER BY gl.time_in DESC";
    $stmt = $db->prepare($sql);
    $stmt->execute([':date' => $date]);
    return $stmt->fetchAll();
}

/**
 * Determine the automatic gate session period (morning/afternoon) based on
 * the current time. When the current time falls inside an admin-configured
 * gate time window the matching period is returned; otherwise it falls back
 * to the clock: AM = morning, PM = afternoon.
 *
 * @param PDO|null $db
 * @param string   $sessionType 'time_in' or 'time_out'
 * @param int|null $time        Unix timestamp (defaults to now)
 * @return string 'morning' or 'afternoon'
 */
function getGateSessionPeriod($db = null, $sessionType = 'time_in', $time = null) {
    $time = $time !== null ? (int)$time : time();
    $current = date('H:i', $time);

    $defaults = [
        'time_in'  => ['morning' => ['06:00', '08:00'], 'afternoon' => ['12:30', '13:30']],
        'time_out' => ['morning' => ['10:30', '11:30'], 'afternoon' => ['15:30', '17:00']]
    ];
    if (!isset($defaults[$sessionType])) {
        $sessionType = 'time_in';
    }

    $windows = $defaults[$sessionType];

    // Override with admin-configured gate time windows when present
    if ($db) {
        try {
            $stmt = $db->query("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'gate_time_%'");
            foreach ($stmt->fetchAll() as $s) {
                $key = $s['setting_key'];
                $val = trim($s['setting_value']);
                if (!preg_match('/^gate_time_(time_in|time_out)_(morning|afternoon)_(start|end)$/', $key, $m) || $val === '') {
                    continue;
                }
                $windows[$m[2]][($m[3] === 'start') ? 0 : 1] = $val;
            }
        } catch (Exception $e) {}
    }

    foreach ($windows as $period => $range) {
        if ($current >= $range[0] && $current <= $range[1]) {
            return $period;
        }
    }

    return (int)date('G', $time) < 12 ? 'morning' : 'afternoon';
}

// ============================================================
// ANNOUNCEMENT FUNCTIONS
// ============================================================

/**
 * Get all announcements
 * @param PDO $db
 * @return array
 */
function getAnnouncements($db) {
    $stmt = $db->query("SELECT * FROM announcements ORDER BY created_at DESC");
    return $stmt->fetchAll();
}

/**
 * Create announcement
 * @param PDO $db
 * @param array $data
 * @return int|false
 */
function createAnnouncement($db, $data) {
    $sql = "INSERT INTO announcements (title, subject, body, template_type, recipients, channels, status, scheduled_at, created_by, created_at)
            VALUES (:title, :subject, :body, :template_type, :recipients, :channels, :status, :scheduled_at, :created_by, NOW())";
    $stmt = $db->prepare($sql);
    return $stmt->execute([
        ':title'          => $data['title'] ?? $data['subject'] ?? '',
        ':subject'        => $data['subject'] ?? $data['title'] ?? '',
        ':body'           => $data['body'] ?? $data['message'] ?? '',
        ':template_type'  => $data['template_type'] ?? 'general',
        ':recipients'     => is_array($data['recipients'] ?? null) ? json_encode($data['recipients']) : ($data['recipients'] ?? 'all'),
        ':channels'       => is_array($data['channels'] ?? null) ? json_encode($data['channels']) : ($data['channels'] ?? '["email"]'),
        ':status'         => $data['status'] ?? 'sent',
        ':scheduled_at'   => $data['scheduled_at'] ?? null,
        ':created_by'     => $data['created_by'] ?? null
    ]) ? $db->lastInsertId() : false;
}

// ============================================================
// NOTIFICATION FUNCTIONS
// ============================================================

/**
 * Create notification record
 * @param PDO $db
 * @param array $data
 * @return int|false
 */
function createNotification($db, $data) {
    $sql = "INSERT INTO notifications (recipient, channel, message, status, sent_at)
            VALUES (:recipient, :channel, :message, :status, NOW())";
    $stmt = $db->prepare($sql);
    return $stmt->execute([
        ':recipient' => $data['recipient'],
        ':channel'   => $data['channel'],
        ':message'   => $data['message'],
        ':status'    => $data['status'] ?? 'sent'
    ]) ? $db->lastInsertId() : false;
}

/**
 * Create in-app user notification record (for notification center / bell)
 * @param PDO $db
 * @param array $data
 * @return int|false
 */
function createUserNotification($db, $data) {
    $sql = "INSERT INTO user_notifications
            (user_role, category, title, message, delivery_status, reference_id, destination_url, is_read)
            VALUES (:user_role, :category, :title, :message, :delivery_status, :reference_id, :destination_url, 0)";
    $stmt = $db->prepare($sql);
    return $stmt->execute([
        ':user_role'       => $data['user_role'] ?? 'all',
        ':category'        => $data['category'] ?? 'system',
        ':title'           => $data['title'] ?? 'Notification',
        ':message'         => $data['message'] ?? '',
        ':delivery_status' => $data['delivery_status'] ?? 'sent',
        ':reference_id'    => $data['reference_id'] ?? null,
        ':destination_url' => $data['destination_url'] ?? null,
    ]) ? $db->lastInsertId() : false;
}

/**
 * Get notifications for a recipient
 * @param PDO $db
 * @param string $recipient
 * @return array
 */
function getNotifications($db, $recipient) {
    $stmt = $db->prepare("SELECT * FROM notifications WHERE recipient = ? ORDER BY sent_at DESC LIMIT 50");
    $stmt->execute([$recipient]);
    return $stmt->fetchAll();
}

// ============================================================
// STRAND FUNCTIONS
// ============================================================

/**
 * Get all strands
 * @param PDO $db
 * @return array
 */
function getStrands($db) {
    $stmt = $db->query("SELECT * FROM strands ORDER BY strand_name ASC");
    return $stmt->fetchAll();
}

/**
 * Add strand
 * @param PDO $db
 * @param array $data
 * @return int|false
 */
function addStrand($db, $data) {
    $sql = "INSERT INTO strands (strand_name, strand_code, description, created_at) VALUES (:strand_name, :strand_code, :description, NOW())";
    $stmt = $db->prepare($sql);
    return $stmt->execute([
        ':strand_name' => $data['strand_name'],
        ':strand_code' => $data['strand_code'],
        ':description' => $data['description'] ?? ''
    ]) ? $db->lastInsertId() : false;
}

/**
 * Update strand
 * @param PDO $db
 * @param int $id
 * @param array $data
 * @return bool
 */
function updateStrand($db, $id, $data) {
    $sql = "UPDATE strands SET strand_name = :strand_name, strand_code = :strand_code, description = :description, updated_at = NOW() WHERE id = :id";
    $stmt = $db->prepare($sql);
    return $stmt->execute([
        ':strand_name' => $data['strand_name'],
        ':strand_code' => $data['strand_code'],
        ':description' => $data['description'] ?? '',
        ':id'          => $id
    ]);
}

/**
 * Delete strand
 * @param PDO $db
 * @param int $id
 * @return bool
 */
function deleteStrand($db, $id) {
    $stmt = $db->prepare("DELETE FROM strands WHERE id = ?");
    return $stmt->execute([$id]);
}

// ============================================================
// TRACK FUNCTIONS
// ============================================================

function getTracks($db) {
    $stmt = $db->query("SELECT * FROM tracks ORDER BY track_name ASC");
    return $stmt->fetchAll();
}

function addTrack($db, $data) {
    $sql = "INSERT INTO tracks (track_name, description, created_at) VALUES (:track_name, :description, NOW())";
    $stmt = $db->prepare($sql);
    return $stmt->execute([
        ':track_name' => $data['track_name'],
        ':description' => $data['description'] ?? ''
    ]) ? $db->lastInsertId() : false;
}

function getTrack($db, $id) {
    $stmt = $db->prepare("SELECT * FROM tracks WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    return $stmt->fetch();
}

function updateTrack($db, $id, $data) {
    $sql = "UPDATE tracks SET track_name = :track_name, description = :description, updated_at = NOW() WHERE id = :id";
    $stmt = $db->prepare($sql);
    return $stmt->execute([
        ':track_name'  => $data['track_name'],
        ':description' => $data['description'] ?? '',
        ':id'          => $id
    ]);
}

function deleteTrack($db, $id) {
    $stmt = $db->prepare("DELETE FROM tracks WHERE id = ?");
    return $stmt->execute([$id]);
}

function getElectives($db, $trackId) {
    $stmt = $db->prepare("SELECT * FROM electives WHERE track_id = ? ORDER BY elective_name ASC");
    $stmt->execute([$trackId]);
    return $stmt->fetchAll();
}

function addElective($db, $data) {
    $sql = "INSERT INTO electives (track_id, elective_name, description, created_at) VALUES (:track_id, :elective_name, :description, NOW())";
    $stmt = $db->prepare($sql);
    return $stmt->execute([
        ':track_id'     => $data['track_id'],
        ':elective_name' => $data['elective_name'],
        ':description'  => $data['description'] ?? ''
    ]) ? $db->lastInsertId() : false;
}

function getElective($db, $id) {
    $stmt = $db->prepare("SELECT * FROM electives WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    return $stmt->fetch();
}

function updateElective($db, $id, $data) {
    $sql = "UPDATE electives SET elective_name = :elective_name, description = :description, updated_at = NOW() WHERE id = :id";
    $stmt = $db->prepare($sql);
    return $stmt->execute([
        ':elective_name' => $data['elective_name'],
        ':description'   => $data['description'] ?? '',
        ':id'            => $id
    ]);
}

function deleteElective($db, $id) {
    $stmt = $db->prepare("DELETE FROM electives WHERE id = ?");
    return $stmt->execute([$id]);
}

function getElectiveSubjects($db, $electiveId) {
    $stmt = $db->prepare("SELECT s.* FROM elective_subjects es LEFT JOIN subjects s ON es.subject_id = s.id WHERE es.elective_id = ? ORDER BY s.subject_name ASC");
    $stmt->execute([$electiveId]);
    return $stmt->fetchAll();
}

function addElectiveSubject($db, $electiveId, $subjectId) {
    $sql = "INSERT IGNORE INTO elective_subjects (elective_id, subject_id) VALUES (:elective_id, :subject_id)";
    $stmt = $db->prepare($sql);
    return $stmt->execute([':elective_id' => $electiveId, ':subject_id' => $subjectId]);
}

function getAllSubjects($db) {
    $stmt = $db->query("SELECT * FROM subjects ORDER BY subject_name ASC");
    return $stmt->fetchAll();
}

// ============================================================
// DASHBOARD STATISTICS
// ============================================================

/**
 * Get dashboard statistics
 * @param PDO $db
 * @return array
 */
function getDashboardStats($db) {
    $stats = [];

    $row = $db->query(
        "SELECT (SELECT COUNT(*) FROM students) AS students,
                (SELECT COUNT(*) FROM teachers) AS teachers,
                (SELECT COUNT(*) FROM subjects) AS subjects"
    )->fetch();
    $stats['total_students'] = (int)($row['students'] ?? 0);
    $stats['total_teachers'] = (int)($row['teachers'] ?? 0);
    $stats['total_subjects'] = (int)($row['subjects'] ?? 0);

    $attendanceStats = getAttendanceStats($db);
    $stats['present_today'] = $attendanceStats['present_today'] ?? 0;
    $stats['absent_today']  = $attendanceStats['absent_today'] ?? 0;
    $stats['late_today']    = $attendanceStats['late_today'] ?? 0;

    return $stats;
}
