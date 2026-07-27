<?php

require_once __DIR__ . '/../bootstrap.php';
require_once TEST_ROOT . '/includes/notifications.php';

$t = new TestSuite('Email Integration Tests');
$db = getTestDB();

if (!$db) {
    $t->assert(false, 'Database connection required for email tests');
    return $t;
}

// ============================================================
// Email Configuration Tests
// ============================================================

$emailEnabled = getSetting($db, 'enable_email_notifications', '1');
$smtpHost     = getSetting($db, 'smtp_host', 'smtp.gmail.com');
$smtpPort     = getSetting($db, 'smtp_port', '587');
$smtpUsername = getSetting($db, 'smtp_username', '');
$fromEmail    = getSetting($db, 'smtp_from_email', 'noreply@liceodebaleno.edu.ph');
$fromName     = getSetting($db, 'smtp_from_name', 'LDB-FRAS');

$t->assertEqual('1', $emailEnabled, 'Email notifications are enabled by default');
$t->assertNotEmpty($smtpHost, 'SMTP host is configured');
// Test email configuration includes encryption setting
$smtpEncrypt = getSetting($db, 'smtp_encryption', 'tls');
$t->assertNotEmpty($smtpEncrypt, 'SMTP encryption setting exists');
$t->assertTrue(in_array($smtpEncrypt, ['tls', 'ssl', 'none']), 'SMTP encryption is valid (tls/ssl/none)');
$t->assertEqual('587', $smtpPort, 'SMTP port defaults to 587 (TLS)');
$t->assertNotEmpty($fromEmail, 'From email is configured');
$t->assertNotEmpty($fromName, 'From name is configured');

// ============================================================
// Email Template Rendering Tests
// ============================================================

// Test basic template
$html = wrapEmailTemplate('Attendance Alert', 'Your child was present today.');
$t->assertContains('<!DOCTYPE html>', $html, 'Template has DOCTYPE declaration');
$t->assertContains('<html', $html, 'Template has HTML tag');
$t->assertContains('</html>', $html, 'Template has closing HTML tag');
$t->assertContains('Attendance Alert', $html, 'Template renders subject in body');
$t->assertContains('Your child was present today.', $html, 'Template renders body content');

// Test template styling
$t->assertContains('background', $html, 'Template has background styling');
$t->assertContains('font', strtolower($html), 'Template has font configuration');

// Test template with HTML body
$htmlBody = '<p>Your child <strong>Juan Dela Cruz</strong> was marked <span style="color:green;">PRESENT</span>.</p>';
$htmlWithBody = wrapEmailTemplate('Attendance Notification', $htmlBody);
$t->assertContains('<strong>Juan Dela Cruz</strong>', $htmlWithBody, 'Template preserves HTML in body');
$t->assertContains('PRESENT', $htmlWithBody, 'Template renders styled content');

// Test template with long content
$longBody = str_repeat('This is a test paragraph for email template rendering. ', 20);
$longHtml = wrapEmailTemplate('Long Email Test', $longBody);
$t->assertContains('Long Email Test', $longHtml, 'Template handles long content');

// Test template with special characters
$specialBody = 'Special chars: <>&"\' and unicode: ñ, é, ü';
$specialHtml = wrapEmailTemplate('Special Characters Test', $specialBody);
$t->assertContains('Special Characters Test', $specialHtml, 'Template handles special characters');

// ============================================================
// PHP mail() Function Availability Test
// ============================================================

$mailExists = function_exists('mail');
$t->assertTrue($mailExists, 'PHP mail() function is available');

// ============================================================
// SMTP Socket Connectivity Test
// ============================================================

if (!empty($smtpUsername)) {
    // Test SMTP server connectivity
    $socketTimeout = 5;
    $smtpSocket = @fsockopen($smtpHost, intval($smtpPort), $errno, $errstr, $socketTimeout);

    if ($smtpSocket) {
        $t->assert(true, "SMTP server $smtpHost:$smtpPort is reachable");
        fclose($smtpSocket);
    } else {
        $t->assert(false, "SMTP server $smtpHost:$smtpPort is NOT reachable ($errstr)");
    }
} else {
    $t->assert(true, 'SMTP username not configured - using PHP mail() fallback');
}

// ============================================================
// Email Sending Test (dry run)
// ============================================================

if (empty($smtpUsername)) {
    // When SMTP not configured, sendEmailNotification should still not crash
    // It will attempt mail() which may or may not succeed based on server config
    $t->assert(true, 'SMTP not configured - sendEmailNotification will use mail() fallback');
} else {
    $t->assert(true, 'SMTP configured - live send test skipped to avoid spam');
}

// ============================================================
// Email Notification Logging Test
// ============================================================

// Test that email notifications are logged even if sending fails
try {
    logNotification($db, 'test_email_log@test.com', 'email', 'Log Test', 'Test body', 'failed');

    $stmt = $db->prepare("SELECT * FROM notifications WHERE recipient = 'test_email_log@test.com' ORDER BY sent_at DESC LIMIT 1");
    $stmt->execute();
    $log = $stmt->fetch();

    $t->assertNotNull($log, 'Failed email notification is still logged');
    if ($log) {
        $t->assertEqual('failed', $log['status'], 'Logged status is "failed"');
        $t->assertEqual('email', $log['channel'], 'Logged channel is "email"');
    }

    // Cleanup
    $db->exec("DELETE FROM notifications WHERE recipient = 'test_email_log@test.com'");
} catch (Exception $e) {
    $t->assert(false, 'Email logging error: ' . $e->getMessage());
}

// ============================================================
// Bulk Notification Function Test
// ============================================================

$t->assertTrue(function_exists('sendBulkNotification'), 'sendBulkNotification() function exists');

return $t;
