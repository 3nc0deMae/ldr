<?php
/**

 * AJAX endpoint for notification operations
 * Events: send_notification, send_attendance_alert, send_bulk, get_notifications
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/notifications.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

csrfMiddleware(true);

$action = $_POST['action'] ?? '';

switch ($action) {

    case 'get_notifications':
        $recipient = sanitize($_POST['recipient'] ?? '');
        if (empty($recipient)) {
            jsonResponse(['error' => 'Recipient is required'], 400);
        }
        $notifications = getNotifications($db, $recipient);
        jsonResponse(['data' => $notifications, 'count' => count($notifications)]);
        break;

    case 'send_notification':
        requireRole(['admin']);

        $recipient = sanitize($_POST['recipient'] ?? '');
        $channel   = sanitize($_POST['channel'] ?? 'email');
        $subject   = sanitize($_POST['subject'] ?? 'Notification');
        $message   = $_POST['message'] ?? '';

        if (empty($recipient) || empty($message)) {
            jsonResponse(['error' => 'Recipient and message are required'], 400);
        }

        $success = false;
        if ($channel === 'email') {
            $success = sendEmailNotification($recipient, $subject, $message);
        } elseif ($channel === 'sms') {
            $success = sendSMSNotification($recipient, strip_tags($message));
        }

        jsonResponse([
            'success' => $success,
            'message' => $success ? 'Notification sent successfully' : 'Failed to send notification. ' . getLastEmailError()
        ]);
        break;

    case 'send_attendance_alert':
        requireRole(['admin', 'gate', 'teacher']);

        $studentId = intval($_POST['student_id'] ?? 0);
        $status    = sanitize($_POST['status'] ?? '');
        $time      = sanitize($_POST['time'] ?? date('H:i:s'));
        $type      = sanitize($_POST['type'] ?? 'time_in');

        if (!$studentId || !$status) {
            jsonResponse(['error' => 'Student ID and status are required'], 400);
        }

        sendAttendanceNotification($db, $studentId, $status, $time, $type);

        jsonResponse([
            'success' => true,
            'message' => 'Attendance alert sent'
        ]);
        break;

    case 'send_bulk':
        requireRole(['admin']);

        $subject  = sanitize($_POST['subject'] ?? '');
        $body     = $_POST['body'] ?? '';
        $channels = json_decode($_POST['channels'] ?? '["email"]', true);

        if (empty($subject) || empty($body)) {
            jsonResponse(['error' => 'Subject and body are required'], 400);
        }

        // Get all guardians matching criteria
        $recipientType = sanitize($_POST['recipient_type'] ?? 'all');
        $sql = "SELECT g.phone, g.email FROM guardians g";
        $params = [];

        if ($recipientType === 'grade' && !empty($_POST['grades'])) {
            $grades = array_map('sanitize', (array)$_POST['grades']);
            $placeholders = implode(',', array_fill(0, count($grades), '?'));
            $sql .= " JOIN students s ON g.student_id = s.id WHERE s.grade_level IN ($placeholders)";
            $params = $grades;
        }

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $recipients = $stmt->fetchAll();

        $result = sendBulkNotification($db, $recipients, $subject, $body, $channels);

        jsonResponse([
            'success' => true,
            'message' => "{$result['sent']} notifications sent, {$result['failed']} failed",
            'data'    => $result
        ]);
        break;

    case 'test_email':
        requireRole(['admin']);
        $to = sanitize($_POST['to'] ?? '');
        if (empty($to) || !isValidEmail($to)) {
            jsonResponse(['error' => 'Valid email is required'], 400);
        }

        $success = sendEmailNotification($to, 'LDB-FRAS Test Email',
            '<p>This is a test email from the LDB-FRAS notification system.</p><p>If you received this, your SMTP configuration is working correctly.</p>');

        jsonResponse([
            'success' => $success,
            'message' => $success ? 'Test email sent successfully' : 'Failed to send test email. ' . getLastEmailError()
        ]);
        break;

    case 'test_sms':
        requireRole(['admin']);
        $to = sanitize($_POST['to'] ?? '');
        if (empty($to)) {
            jsonResponse(['error' => 'Phone number is required'], 400);
        }

        $success = sendSMSNotification($to, '[LDB-FRAS] This is a test SMS notification.', true);
        $smsErr = _smsLastError();

        jsonResponse([
            'success' => $success,
            'message' => $success ? 'Test SMS sent successfully' : ('Failed to send SMS.' . ($smsErr !== '' ? ' Reason: ' . $smsErr : ' Check API settings.'))
        ]);
        break;

    default:
        jsonResponse(['error' => 'Invalid action'], 400);
}
