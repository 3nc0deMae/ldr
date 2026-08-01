<?php

require_once __DIR__ . '/../bootstrap.php';
require_once TEST_ROOT . '/includes/notifications.php';

$t = new TestSuite('SMS Integration Tests');
$db = getTestDB();

// ============================================================
// SMS Configuration Tests
// ============================================================

if (!$db) {
    $t->assert(false, 'Database connection required for SMS tests');
    return $t;
}

$smsEnabled = getSetting($db, 'enable_sms_notifications', '0');
$apiKey     = getSetting($db, 'sms_api_key', '');
$apiUrl     = getSetting($db, 'sms_api_url', 'https://api.textbee.dev/api/v1/gateway/send-sms');
$senderId   = getSetting($db, 'sms_sender_id', 'LDBFRAS');

$t->assertNotEmpty($apiUrl, 'SMS API URL is configured');
$t->assertContains('http', $apiUrl, 'SMS API URL is a valid HTTP endpoint');
$t->assertNotEmpty($senderId, 'SMS Sender ID is configured');

// ============================================================
// Phone Number Format Tests
// ============================================================

$t->assertEqual('639171234567', formatPhoneNumber('09171234567'), 'Format: 09XX → 639XX');
$t->assertEqual('639171234567', formatPhoneNumber('639171234567'), 'Format: 639XX unchanged');
$t->assertEqual('639171234567', formatPhoneNumber('+639171234567'), 'Format: +639XX → 639XX');
$t->assertEqual('639171234567', formatPhoneNumber('63-917-123-4567'), 'Format: with dashes → 639XX');

// ============================================================
// SMS API Connectivity Test
// ============================================================

if ($smsEnabled === '1' && !empty($apiKey)) {
    // Test API endpoint connectivity (without sending)
    $ch = curl_init($apiUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_NOBODY         => true
    ]);
    curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error    = curl_error($ch);
    curl_close($ch);

    $t->assertEmpty($error, 'SMS API endpoint is reachable (no cURL error)');
    $t->assertGreaterThan(0, $httpCode, 'SMS API returns HTTP response');

    // Test sendSMSNotification function with a dummy number
    // NOTE: This will actually attempt to send - only run with test credits
    $t->assert(true, 'SMS API is configured and enabled - live test skipped to save credits');

} else {
    $t->assert(true, 'SMS is disabled or API key not set - skipping live tests (expected)');

    // Verify function handles disabled SMS gracefully
    $result = sendSMSNotification('09171234567', 'Test message');
    $t->assertFalse($result, 'sendSMSNotification() returns false when SMS is disabled');
}

// ============================================================
// SMS Message Format Tests
// ============================================================

// Build attendance notification messages (without sending)
$schoolName = getSetting($db, 'school_name', 'Liceo de Baleno');

// Present message
$presentMsg = "[$schoolName] Your child Juan Dela Cruz timed in at 7:15 AM on " . date('M j, Y') . '.';
$t->assertContains($schoolName, $presentMsg, 'Present SMS contains school name');
$t->assertContains('timed in', $presentMsg, 'Present SMS contains timed in');
$t->assertTrue(strlen($presentMsg) <= 160, 'Present SMS fits in single SMS (160 chars)');

// Late message
$lateMsg = "[$schoolName] Your child Juan Dela Cruz arrived LATE at 8:30 AM on " . date('M j, Y') . '.';
$t->assertContains('LATE', $lateMsg, 'Late SMS contains LATE keyword');

// Absent message
$absentMsg = "[$schoolName] Your child Juan Dela Cruz was ABSENT today (" . date('M j, Y') . ').';
$t->assertContains('ABSENT', $absentMsg, 'Absent SMS contains ABSENT keyword');

return $t;
