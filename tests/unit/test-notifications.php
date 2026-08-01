<?php


require_once __DIR__ . '/../bootstrap.php';
require_once TEST_ROOT . '/includes/notifications.php';

$t = new TestSuite('Notification System Tests');
$db = getTestDB();

// ============================================================
// Settings Helper Tests
// ============================================================

if ($db) {
    // Test getSetting with default
    $schoolName = getSetting($db, 'school_name', 'Default School');
    $t->assertNotEmpty($schoolName, 'getSetting() returns school_name from database');

    // Test getSetting with non-existent key returns default
    $fakeSetting = getSetting($db, 'nonexistent_key_xyz', 'DefaultValue');
    $t->assertEqual('DefaultValue', $fakeSetting, 'getSetting() returns default for missing key');

    // Test SMTP settings exist
    $smtpHost = getSetting($db, 'smtp_host', '');
    $t->assertNotEmpty($smtpHost, 'SMTP host setting exists');

    $smtpPort = getSetting($db, 'smtp_port', '587');
    $t->assertEqual('587', $smtpPort, 'SMTP port default is 587');

    // Test SMS settings
    $smsEnabled = getSetting($db, 'enable_sms_notifications', '0');
    $t->assertEqual('0', $smsEnabled, 'SMS notifications disabled by default');

    // Test email enabled by default
    $emailEnabled = getSetting($db, 'enable_email_notifications', '1');
    $t->assertEqual('1', $emailEnabled, 'Email notifications enabled by default');

    // Test late threshold setting
    $lateThreshold = getSetting($db, 'late_threshold', '15');
    $t->assertEqual('15', $lateThreshold, 'Late threshold default is 15 minutes');
}

// ============================================================
// Phone Number Formatting Tests
// ============================================================

// Test formatPhoneNumber (09XX -> 639XX)
$ph1 = formatPhoneNumber('09171234567');
$t->assertEqual('639171234567', $ph1, 'formatPhoneNumber converts 09XX to 639XX');

$ph2 = formatPhoneNumber('0917-123-4567');
$t->assertContains('639', $ph2, 'formatPhoneNumber handles dashes');

$ph3 = formatPhoneNumber('+639171234567');
$t->assertContains('639', $ph3, 'formatPhoneNumber handles +63 prefix');

        $ph4 = formatPhoneNumber('');                                                               
        $t->assertEqual('', $ph4, 'formatPhoneNumber handles empty string');

// ============================================================
// Email Template Tests
// ============================================================

// Test wrapEmailTemplate generates HTML
$html = wrapEmailTemplate('Test Subject', 'Test body content');
$t->assertContains('Test Subject', $html, 'Email template contains subject');
$t->assertContains('Test body content', $html, 'Email template contains body');
$t->assertContains('<html', $html, 'Email template is valid HTML');
$t->assertContains('Liceo de Baleno', $html, 'Email template includes school name');

// Test with empty body
$emptyHtml = wrapEmailTemplate('Subject Only', '');
$t->assertContains('Subject Only', $emptyHtml, 'Template handles empty body');

// ============================================================
// Notification Logging Tests
// ============================================================

if ($db) {
    // Test logNotification function
    try {
        logNotification($db, 'test@example.com', 'email', 'Test Subject', 'Test body', 'sent');

        // Verify log entry exists
        $stmt = $db->prepare("SELECT * FROM notifications WHERE recipient = 'test@example.com' ORDER BY sent_at DESC LIMIT 1");
        $stmt->execute();
        $log = $stmt->fetch();

        $t->assertNotNull($log, 'Notification log entry was created');
        if ($log) {
            $t->assertEqual('email', $log['channel'], 'Notification channel is email');
            $t->assertEqual('sent', $log['status'], 'Notification status is sent');
        }

        // Log SMS notification
        logNotification($db, '09171234567', 'sms', '', 'Test SMS message', 'sent');

        $stmt = $db->prepare("SELECT * FROM notifications WHERE recipient = '09171234567' ORDER BY sent_at DESC LIMIT 1");
        $stmt->execute();
        $smsLog = $stmt->fetch();

        $t->assertNotNull($smsLog, 'SMS notification log entry was created');
        if ($smsLog) {
            $t->assertEqual('sms', $smsLog['channel'], 'SMS notification channel is sms');
        }

        // Clean up test notifications
        $db->exec("DELETE FROM notifications WHERE recipient IN ('test@example.com', '09171234567')");
        $t->assert(true, 'Test notification cleanup successful');

    } catch (Exception $e) {
        $t->assert(false, 'Notification logging error: ' . $e->getMessage());
    }
}

// ============================================================
// Notification Function Signature Tests
// ============================================================

// Test that notification functions exist
$t->assertTrue(function_exists('sendEmailNotification'), 'sendEmailNotification() function exists');
$t->assertTrue(function_exists('sendSMSNotification'), 'sendSMSNotification() function exists');
$t->assertTrue(function_exists('sendAttendanceNotification'), 'sendAttendanceNotification() function exists');
$t->assertTrue(function_exists('sendBulkNotification'), 'sendBulkNotification() function exists');
$t->assertTrue(function_exists('wrapEmailTemplate'), 'wrapEmailTemplate() function exists');
$t->assertTrue(function_exists('formatPhoneNumber'), 'formatPhoneNumber() function exists');
$t->assertTrue(function_exists('logNotification'), 'logNotification() function exists');
$t->assertTrue(function_exists('getSetting'), 'getSetting() function exists');

// ============================================================
// Create Notification Function Test
// ============================================================

if ($db) {
    $notifId = createNotification($db, [
        'recipient' => 'test_parent@test.com',
        'channel'   => 'email',
        'message'   => 'Your child was present today.',
        'status'    => 'sent'
    ]);
    $t->assertNotEmpty($notifId, 'createNotification() returns new ID');

    // Get notifications
    $notifs = getNotifications($db, 'test_parent@test.com');
    $t->assertIsArray($notifs, 'getNotifications() returns array');
    $t->assertGreaterThan(0, count($notifs), 'At least one notification found for test recipient');

    // Cleanup
    if ($notifId) {
        $db->prepare("DELETE FROM notifications WHERE id = ?")->execute([$notifId]);
    }
}

// ============================================================
// Announcement Creation Test
// ============================================================

if ($db) {
    $annId = createAnnouncement($db, [
        'title'         => 'Test Announcement',
        'subject'       => 'Test Subject Line',
        'body'          => 'This is a test announcement body.',
        'template_type' => 'general',
        'recipients'    => 'all',
        'channels'      => ['email', 'sms'],
        'status'        => 'sent',
        'created_by'    => 1
    ]);
    $t->assertNotEmpty($annId, 'createAnnouncement() returns new ID');

    if ($annId) {
        // Verify
        $stmt = $db->prepare("SELECT * FROM announcements WHERE id = ?");
        $stmt->execute([$annId]);
        $ann = $stmt->fetch();

        $t->assertNotNull($ann, 'Created announcement exists in database');
        if ($ann) {
            $t->assertEqual('general', $ann['template_type'], 'Announcement template_type is general');
            $t->assertEqual('sent', $ann['status'], 'Announcement status is sent');
        }

        // Cleanup
        $db->prepare("DELETE FROM announcements WHERE id = ?")->execute([$annId]);
    }
}

return $t;
