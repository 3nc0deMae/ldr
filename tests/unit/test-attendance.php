<?php


require_once __DIR__ . '/../bootstrap.php';

$t = new TestSuite('Attendance Module Tests');
$db = getTestDB();

// ============================================================
// Subject Level Bucket Tests
// ============================================================

$t->assertEqual('jhs', getSubjectLevelBucket('7', '10'), 'Grade range 7-10 is classified as JHS');
$t->assertEqual('shs', getSubjectLevelBucket('11', '12'), 'Grade range 11-12 is classified as SHS');
$t->assertNull(getSubjectLevelBucket('9', '11'), 'Cross-level range is not counted as either bucket');

// ============================================================
// Teacher Form Helpers Tests
// ============================================================

$t->assertEqual('Math, Science', normalizeTeacherSubjectsInput(['Math', 'Science']), 'Teacher subjects are normalized into a comma-separated string');
$t->assertEqual('7A - St. Niño', formatAdvisoryClassLabel('7', 'A', 'St. Niño'), 'Advisory class labels follow the grade-section format');

// ============================================================
// Database Structure Tests
// ============================================================

if ($db) {
    // Verify required tables exist
    $tables = ['students', 'teachers', 'subjects', 'attendance', 'gate_logs', 'gate_sessions', 'attendance_records'];
    foreach ($tables as $table) {
        try {
            $stmt = $db->query("SHOW TABLES LIKE '$table'");
            $exists = $stmt->fetch();
            $t->assertNotEmpty($exists, "Table '$table' exists in database");
        } catch (Exception $e) {
            $t->assert(false, "Table '$table' check failed: " . $e->getMessage());
        }
    }

    // ============================================================
    // Student Functions Tests
    // ============================================================

    // Test getStudents with no filters
    $students = getStudents($db);
    $t->assertIsArray($students, 'getStudents() returns an array');

    // Test getStudents with grade filter
    $filtered = getStudents($db, ['grade_level' => '7']);
    $t->assertIsArray($filtered, 'getStudents() with grade filter returns array');

    // Test getStudents with search
    $searched = getStudents($db, ['search' => 'nonexistent_xyz_abc']);
    $t->assertIsArray($searched, 'getStudents() with search returns array');

    // Test addStudent
    $testStudentId = addStudent($db, [
        'student_id'  => 'TEST-' . time(),
        'first_name'  => 'TestFirst',
        'middle_name' => 'TestMiddle',
        'last_name'   => 'TestLast',
        'age'         => 15,
        'gender'      => 'Male',
        'address'     => 'Test Address',
        'email'       => 'test_' . time() . '@test.com',
        'grade_level' => '10',
        'section'     => 'TestSection'
    ]);
    $t->assertNotEmpty($testStudentId, 'addStudent() returns new student ID');

    if ($testStudentId) {
        // Test getStudentById
        $student = getStudentById($db, $testStudentId);
        $t->assertNotNull($student, 'getStudentById() returns the student');
        if ($student) {
            $t->assertEqual('TestFirst', $student['first_name'], 'Student first_name matches');
            $t->assertEqual('TestLast', $student['last_name'], 'Student last_name matches');
            $t->assertEqual('10', $student['grade_level'], 'Student grade_level matches');
        }

        // Test updateStudent
        $updated = updateStudent($db, $testStudentId, [
            'first_name'  => 'UpdatedFirst',
            'middle_name' => '',
            'last_name'   => 'UpdatedLast',
            'age'         => 16,
            'gender'      => 'Male',
            'address'     => 'Updated Address',
            'email'       => 'updated_' . time() . '@test.com',
            'grade_level' => '11',
            'section'     => 'UpdatedSection'
        ]);
        $t->assertTrue($updated, 'updateStudent() returns true');

        // Verify update
        $updatedStudent = getStudentById($db, $testStudentId);
        if ($updatedStudent) {
            $t->assertEqual('UpdatedFirst', $updatedStudent['first_name'], 'Updated first_name matches');
            $t->assertEqual('11', $updatedStudent['grade_level'], 'Updated grade_level matches');
        }

        // Test guardian save
        $guardianSaved = saveGuardian($db, $testStudentId, [
            'guardian_name' => 'Test Guardian',
            'relationship'  => 'Father',
            'phone'         => '09171234567',
            'email'         => 'guardian@test.com',
            'address'       => 'Guardian Address'
        ]);
        $t->assertTrue($guardianSaved, 'saveGuardian() returns true');

        // Test recordAttendance
        $attSubjectId = addSubject($db, [
            'subject_name' => 'Att Test Subject ' . time(),
            'subject_code' => 'ATT' . time(),
            'grade_level'  => '10',
            'description'  => 'Attendance test subject'
        ]);
        $t->assertNotEmpty($attSubjectId, 'addSubject() returns new ID for attendance test');

        $attResult = recordAttendance($db, [
            'student_id' => $testStudentId,
            'subject_id' => $attSubjectId,
            'date'       => today(),
            'time'       => date('H:i:s'),
            'status'     => 'present'
        ]);
        $t->assertNotEmpty($attResult, 'recordAttendance() returns a result');

        // Test getAttendance with filters
        $attRecords = getAttendance($db, [
            'date'  => today(),
            'status'=> 'present'
        ]);
        $t->assertIsArray($attRecords, 'getAttendance() returns array with filters');

        // Cleanup temp attendance subject
        if ($attSubjectId) {
            deleteSubject($db, $attSubjectId);
        }

        // Cleanup test student
        deleteStudent($db, $testStudentId);
        $deletedStudent = getStudentById($db, $testStudentId);
        $t->assertFalse($deletedStudent ? true : false, 'Test student deleted successfully');
    }

    // ============================================================
    // Attendance Statistics Tests
    // ============================================================

    $stats = getAttendanceStats($db);
    $t->assertIsArray($stats, 'getAttendanceStats() returns array');
    $t->assertArrayHasKey('present_today', $stats, 'Stats has present_today key');
    $t->assertArrayHasKey('absent_today', $stats, 'Stats has absent_today key');
    $t->assertArrayHasKey('late_today', $stats, 'Stats has late_today key');

    // ============================================================
    // Dashboard Stats Tests
    // ============================================================

    $dashStats = getDashboardStats($db);
    $t->assertIsArray($dashStats, 'getDashboardStats() returns array');
    $t->assertArrayHasKey('total_students', $dashStats, 'Dashboard stats has total_students');
    $t->assertArrayHasKey('total_teachers', $dashStats, 'Dashboard stats has total_teachers');
    $t->assertArrayHasKey('total_subjects', $dashStats, 'Dashboard stats has total_subjects');

    // ============================================================
    // Subject Functions Tests
    // ============================================================

    $subjects = getSubjects($db);
    $t->assertIsArray($subjects, 'getSubjects() returns array');

    $newSubjectId = addSubject($db, [
        'subject_name' => 'Test Subject ' . time(),
        'subject_code' => 'TST' . time(),
        'grade_level'  => '10',
        'description'  => 'Test description'
    ]);
    $t->assertNotEmpty($newSubjectId, 'addSubject() returns new ID');

    if ($newSubjectId) {
        $subject = getSubjectById($db, $newSubjectId);
        $t->assertNotNull($subject, 'getSubjectById() returns the subject');
        if ($subject) {
            $t->assertContains('Test Subject', $subject['subject_name'], 'Subject name matches');
        }

        updateSubject($db, $newSubjectId, [
            'subject_name' => 'Updated Subject',
            'subject_code' => 'UPD' . time(),
            'grade_level'  => '11',
            'description'  => 'Updated description'
        ]);
        $updatedSub = getSubjectById($db, $newSubjectId);
        if ($updatedSub) {
            $t->assertEqual('Updated Subject', $updatedSub['subject_name'], 'Subject updated correctly');
        }

        deleteSubject($db, $newSubjectId);
    }

    // ============================================================
    // Strand Functions Tests
    // ============================================================

    $strands = getStrands($db);
    $t->assertIsArray($strands, 'getStrands() returns array');

    $newStrandId = addStrand($db, [
        'strand_name' => 'Test Strand ' . time(),
        'strand_code' => 'TS' . time(),
        'description' => 'Test strand description'
    ]);
    $t->assertNotEmpty($newStrandId, 'addStrand() returns new ID');

    if ($newStrandId) {
        deleteStrand($db, $newStrandId);
    }

} else {
    $t->assert(false, 'Database connection failed - cannot run attendance tests');
}

// ============================================================
// Validation Function Tests (no DB required)
// ============================================================

// validateRequired
$errors = validateRequired(['name' => 'John', 'email' => ''], ['name', 'email', 'phone']);
$t->assertArrayHasKey('email', $errors, 'validateRequired catches empty email');
$t->assertArrayHasKey('phone', $errors, 'validateRequired catches missing phone');
$t->assert(!isset($errors['name']), 'validateRequired passes for non-empty name');

// validateLength
$t->assertNull(validateLength('Hello', 3, 10, 'Name'), 'validateLength passes for valid length');
$t->assertNotNull(validateLength('Hi', 3, 10, 'Name'), 'validateLength fails for too short');
$t->assertNotNull(validateLength(str_repeat('a', 11), 3, 10, 'Name'), 'validateLength fails for too long');

// validateNumeric
$t->assertNull(validateNumeric('42', 0, 100, 'Age'), 'validateNumeric passes for valid number');
$t->assertNotNull(validateNumeric('abc', null, null, 'Age'), 'validateNumeric fails for non-numeric');
$t->assertNotNull(validateNumeric('150', 0, 100, 'Age'), 'validateNumeric fails for out of range');

// validateEnum
$t->assertNull(validateEnum('present', ['present', 'absent', 'late', 'pending', 'excused'], 'Status'), 'validateEnum passes for valid value');
$t->assertNotNull(validateEnum('invalid', ['present', 'absent', 'late', 'pending', 'excused'], 'Status'), 'validateEnum fails for invalid value');

// ============================================================
// Gate Session Period Auto-Selection Tests
// ============================================================

// Configured time-in morning window 06:00-08:00, afternoon 12:30-13:30
$t->assertEqual('morning', getGateSessionPeriod(null, 'time_in', strtotime('2026-08-01 07:00:00')), 'time-in at 07:00 selects Morning');
$t->assertEqual('afternoon', getGateSessionPeriod(null, 'time_in', strtotime('2026-08-01 13:00:00')), 'time-in at 13:00 selects Afternoon');
$t->assertEqual('morning', getGateSessionPeriod(null, 'time_in', strtotime('2026-08-01 06:00:00')), 'time-in at 06:00 (window edge) selects Morning');
$t->assertEqual('afternoon', getGateSessionPeriod(null, 'time_in', strtotime('2026-08-01 13:30:00')), 'time-in at 13:30 (window edge) selects Afternoon');

// Fallback to AM/PM when outside any configured window
$t->assertEqual('morning', getGateSessionPeriod(null, 'time_in', strtotime('2026-08-01 09:00:00')), 'time-in at 09:00 falls back to AM = Morning');
$t->assertEqual('afternoon', getGateSessionPeriod(null, 'time_in', strtotime('2026-08-01 23:00:00')), 'time-in at 23:00 falls back to PM = Afternoon');

// Configured time-out morning window 10:30-11:30, afternoon 15:30-17:00
$t->assertEqual('morning', getGateSessionPeriod(null, 'time_out', strtotime('2026-08-01 10:45:00')), 'time-out at 10:45 selects Morning');
$t->assertEqual('afternoon', getGateSessionPeriod(null, 'time_out', strtotime('2026-08-01 16:00:00')), 'time-out at 16:00 selects Afternoon');
$t->assertEqual('afternoon', getGateSessionPeriod(null, 'time_out', strtotime('2026-08-01 12:00:00')), 'time-out at noon falls back to PM = Afternoon');

// Invalid session type defaults to time_in windows
$t->assertEqual('morning', getGateSessionPeriod(null, 'lunch', strtotime('2026-08-01 07:00:00')), 'Unknown session type defaults to time-in windows');

// Live DB read of configured windows returns a valid period
if ($db) {
    $livePeriod = getGateSessionPeriod($db, 'time_in');
    $t->assertTrue(in_array($livePeriod, ['morning', 'afternoon'], true), 'getGateSessionPeriod() with DB returns a valid period');
}

return $t;
