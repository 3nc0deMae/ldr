<?php


require_once __DIR__ . '/../bootstrap.php';

$t = new TestSuite('Authentication & Login Tests');

// ============================================================
// Password Hashing Tests
// ============================================================

// Test bcrypt password hashing
$hash = hashPassword('Test@12345');
$t->assertNotEmpty($hash, 'hashPassword() returns a non-empty hash');
$t->assertNotEqual('Test@12345', $hash, 'Hash should not equal plaintext password');
$t->assertContains('$2y$', $hash, 'Hash uses bcrypt algorithm ($2y$ prefix)');

// Test password verification (correct password)
$valid = verifyPassword('Test@12345', $hash);
$t->assertTrue($valid, 'verifyPassword() returns true for correct password');

// Test password verification (wrong password)
$invalid = verifyPassword('WrongPassword', $hash);
$t->assertFalse($invalid, 'verifyPassword() returns false for wrong password');

// Test different passwords produce different hashes
$hash1 = hashPassword('Password1');
$hash2 = hashPassword('Password1');
$t->assertNotEqual($hash1, $hash2, 'Same password produces different hashes (salt)');

// Test empty password hashing
$emptyHash = hashPassword('');
$t->assertNotEmpty($emptyHash, 'Empty password can be hashed');
$t->assertTrue(verifyPassword('', $emptyHash), 'Empty password verifies correctly');

// ============================================================
// Email Validation Tests
// ============================================================

$t->assertTrue(isValidEmail('admin@liceodebaleno.edu.ph'), 'Valid school email passes validation');
$t->assertTrue(isValidEmail('user@gmail.com'), 'Valid Gmail passes validation');
$t->assertTrue(isValidEmail('test.user+tag@domain.co'), 'Email with plus and subdomain passes');
$t->assertFalse(isValidEmail(''), 'Empty string fails email validation');
$t->assertFalse(isValidEmail('not-an-email'), 'Plain text fails email validation');
$t->assertFalse(isValidEmail('missing@'), 'Incomplete email fails validation');
$t->assertFalse(isValidEmail('@domain.com'), 'Missing local part fails validation');
$t->assertFalse(isValidEmail('spaces in@email.com'), 'Spaces in email fails validation');

// ============================================================
// OTP Generation Tests
// ============================================================

$otp1 = generateOTP();
$otp2 = generateOTP();
$t->assertEqual(6, strlen($otp1), 'OTP is 6 digits long');
$t->assertTrue(ctype_digit($otp1), 'OTP contains only digits');
$t->assertNotEqual($otp1, $otp2, 'Two OTPs are different (random)');

// Test OTP with leading zeros
for ($i = 0; $i < 20; $i++) {
    $otp = generateOTP();
    $t->assertEqual(6, strlen($otp), "OTP #$i is always 6 characters");
}

// ============================================================
// Token Generation Tests
// ============================================================

$token = generateToken(32);
$t->assertEqual(32, strlen($token), 'Token length matches requested length (32)');
$t->assertTrue(ctype_xdigit($token), 'Token contains only hex characters');

$token64 = generateToken(64);
$t->assertEqual(64, strlen($token64), 'Token length matches requested length (64)');

// ============================================================
// Sanitize Input Tests (security for auth forms)
// ============================================================

$t->assertEqual('alert(1)', sanitize('<script>alert(1)</script>'), 'Sanitize strips HTML script tags');
$t->assertEqual('', sanitize('<script>'), 'Sanitize removes bare HTML tags');
$t->assertEqual('Hello', sanitize('  Hello  '), 'Sanitize trims whitespace');
$t->assertEqual('normal text', sanitize('normal text'), 'Sanitize preserves normal text');
$t->assertEqual('&amp;', sanitize('&'), 'Sanitize encodes ampersand');
$t->assertEqual('', sanitize(''), 'Sanitize handles empty string');

// ============================================================
// Date/Time Utility Tests
// ============================================================

$today = today();
$t->assertEqual(date('Y-m-d'), $today, 'today() returns current date in Y-m-d format');

$now = now();
$t->assertContains(date('Y-m-d'), $now, 'now() contains current date');

$formatted = formatDate('2026-01-15');
$t->assertEqual('January 15, 2026', $formatted, 'formatDate() formats correctly');

$timeFormatted = formatTime('14:30:00');
$t->assertEqual('2:30 PM', $timeFormatted, 'formatTime() formats to 12-hour');

// ============================================================
// Database Connection Test
// ============================================================

$db = getTestDB();
$t->assertNotNull($db, 'Database connection is established');
if ($db) {
    $t->assertInstanceOf('PDO', $db, 'Database returns PDO instance');

    // Test user lookup
    $stmt = $db->query("SELECT COUNT(*) FROM users WHERE role = 'admin'");
    $adminCount = $stmt->fetchColumn();
    $t->assertGreaterThan(0, (int)$adminCount, 'At least one admin user exists in database');

    // Test getUserByEmail
    $admin = getUserByEmail($db, 'admin@liceodebaleno.edu.ph');
    if ($admin) {
        $t->assertEqual('admin', $admin['role'], 'Admin user has correct role');
        $t->assertEqual('active', $admin['status'], 'Admin user is active');
        $t->assertTrue(strpos($admin['password'], '$2y$') === 0, 'Admin password is stored as a bcrypt hash');
    } else {
        $t->assert(false, 'Admin user should exist in database (run database.sql first)');
    }
}

return $t;
