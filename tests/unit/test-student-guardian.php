<?php
require_once __DIR__ . '/../bootstrap.php';

$t = new TestSuite('Student Guardian Preservation Tests');
$db = getTestDB();

if ($db) {
    $studentId = addStudent($db, [
        'student_id'  => 'TEST-GUARD-' . time(),
        'first_name'  => 'Guardian',
        'middle_name' => '',
        'last_name'   => 'Test',
        'age'         => 15,
        'gender'      => 'Female',
        'address'     => 'Test Address',
        'email'       => 'guardian_' . time() . '@test.com',
        'grade_level' => '10',
        'section'     => 'TestSection'
    ]);

    $t->assertNotEmpty($studentId, 'Test student created for guardian preservation');

    $t->assertTrue(isValidPhilippinePhone('09171234567'), 'Philippine local phone format is accepted');
    $t->assertFalse(isValidPhilippinePhone('+63917123456'), 'Phone numbers starting with +63 are rejected');
    $t->assertEqual('09171234567', normalizePhilippinePhone('9171234567'), 'Local phone number is normalized to 11-digit format');

    if ($studentId) {
        $saved = saveGuardian($db, $studentId, [
            'guardian_name' => 'Initial Guardian',
            'relationship'  => 'Mother',
            'phone'         => '09171234567',
            'email'         => 'initial.guardian@test.com',
            'address'       => 'Initial Address'
        ]);
        $t->assertTrue($saved, 'Initial guardian record saved');

        $updated = saveGuardian($db, $studentId, [
            'guardian_name' => 'Updated Guardian',
            'relationship'  => 'Guardian',
            'phone'         => '',
            'email'         => '',
            'address'       => ''
        ]);
        $t->assertTrue($updated, 'Guardian update with blank values is accepted');

        $stmt = $db->prepare('SELECT * FROM guardians WHERE student_id = ?');
        $stmt->execute([$studentId]);
        $guardian = $stmt->fetch();

        $t->assertNotEmpty($guardian, 'Guardian row exists after update');
        if ($guardian) {
            $t->assertEqual('Updated Guardian', $guardian['guardian_name'], 'Guardian name updates');
            $t->assertEqual('09171234567', $guardian['phone'], 'Existing phone is preserved when blank update is provided');
            $t->assertEqual('initial.guardian@test.com', $guardian['email'], 'Existing email is preserved when blank update is provided');
            $t->assertEqual('Initial Address', $guardian['address'], 'Existing address is preserved when blank update is provided');
        }

        deleteStudent($db, $studentId);
    }
} else {
    $t->assert(false, 'Database connection unavailable for guardian preservation tests');
}

$t->printResults();
