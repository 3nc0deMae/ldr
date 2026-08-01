<?php
/**

 * Email Notifications via SMTP
 * SMS Notifications via Semaphore API
 *
 * Events: Attendance Recorded, Student Absent, Student Late, Announcement Sent
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * Store/retrieve the last email send error message.
 * @param string|null $msg When provided, stores the message; otherwise reads it.
 * @return string
 */
function _emailLastError($msg = null) {
    static $last = '';
    if ($msg !== null) {
        $last = $msg;
    }
    return $last;
}

/**
 * Get the human-readable reason the last email send failed (empty if it succeeded).
 * @return string
 */
function getLastEmailError() {
    return _emailLastError();
}

/**
 * Send email notification using PHPMailer SMTP
 * @param string $to        Recipient email
 * @param string $subject   Email subject
 * @param string $body      Email body (HTML supported)
 * @param array  $options   Additional options (cc, bcc, attachments)
 * @return bool
 */
function sendEmailNotification($to, $subject, $body, $options = []) {
    $db = getDB();
    error_log("EMAIL SEND START: to=$to subject=$subject");
    _emailLastError('');

    // Auto-migrate email counter columns if missing
    try {
        $colsStmt = $db->query("SHOW COLUMNS FROM system_notification_config WHERE Field IN ('email_daily_count','email_monthly_count','email_yearly_count')");
        $existingCols = $colsStmt->fetchAll(PDO::FETCH_COLUMN);
        $missing = array_diff(['email_daily_count','email_monthly_count','email_yearly_count'], $existingCols);
        if (!empty($missing)) {
            $alterParts = [];
            foreach ($missing as $col) {
                if ($col === 'email_daily_count') {
                    $alterParts[] = "ADD COLUMN `email_daily_count` INT DEFAULT 0 AFTER `sms_yearly_count`";
                } elseif ($col === 'email_monthly_count') {
                    $alterParts[] = "ADD COLUMN `email_monthly_count` INT DEFAULT 0 AFTER `email_daily_count`";
                } elseif ($col === 'email_yearly_count') {
                    $alterParts[] = "ADD COLUMN `email_yearly_count` INT DEFAULT 0 AFTER `email_monthly_count`";
                }
            }
            if (!empty($alterParts)) {
                $db->exec("ALTER TABLE `system_notification_config` " . implode(', ', $alterParts));
                error_log('EMAIL COUNTER: Auto-migrated columns: ' . implode(', ', $missing));
            }
        }
    } catch (Exception $e) {
        error_log('Email counter auto-migration error: ' . $e->getMessage());
    }

    $smtpHost     = getSetting($db, 'smtp_host', SMTP_HOST);
    $smtpPort     = (int)getSetting($db, 'smtp_port', SMTP_PORT);
    $smtpUsername = getSetting($db, 'smtp_username', '');
    $smtpPassword = getSetting($db, 'smtp_password', '');
    $smtpEncrypt  = getSetting($db, 'smtp_encryption', SMTP_ENCRYPTION);
    $fromEmail    = getSetting($db, 'smtp_from_email', SMTP_FROM_EMAIL);
    $fromName     = getSetting($db, 'smtp_from_name', SMTP_FROM_NAME);
    $enabled      = getSetting($db, 'enable_email_notifications', '1');

    if ($enabled !== '1') {
        _emailLastError('Email notifications are disabled in System Settings. Enable "Email Notifications" under Admin → System Settings → Email & SMS.');
        logNotification($db, $to, 'email', $subject, $body, 'failed', _emailLastError());
        return false;
    }

    if (empty($smtpUsername)) {
        $headers = "From: {$fromName} <{$fromEmail}>\r\n";
        $headers .= "Reply-To: {$fromEmail}\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $result = @mail($to, $subject, wrapEmailTemplate($subject, $body, defined('APP_LOGO_URL') ? APP_LOGO_URL : ''), $headers);
        if (!$result) {
            $mailErr = error_get_last();
            _emailLastError('SMTP credentials are not configured, the PHP mail() fallback also failed'
                . ($mailErr && !empty($mailErr['message']) ? ': ' . $mailErr['message'] : '')
                . '. Enter your SMTP username and password (e.g., a Gmail App Password) under Admin → System Settings → Email & SMS.');
        }
    } else {
        $logoUrl = defined('APP_LOGO_URL') ? APP_LOGO_URL : '';
        $embedImages = [];
        if ($logoUrl && defined('APP_LOGO_PATH') && file_exists(APP_LOGO_PATH)) {
            $embedImages[$logoUrl] = APP_LOGO_PATH;
        }
        $result = sendViaPHPMailer($smtpHost, $smtpPort, $smtpUsername, $smtpPassword, $smtpEncrypt,
                                  $fromEmail, $fromName, $to, $subject,
                                  wrapEmailTemplate($subject, $body, $logoUrl, !empty($embedImages)),
                                  $embedImages);
    }

    logNotification($db, $to, 'email', $subject, $body, $result ? 'sent' : 'failed', $result ? null : _emailLastError());
    error_log("EMAIL SEND RESULT: to=$to result=" . ($result ? 'SUCCESS' : 'FAILED'));

    if ($result) {
        $today = date('Y-m-d');

        try {
            $checkStmt = $db->query("SELECT id, email_daily_count, last_counter_reset FROM system_notification_config ORDER BY id ASC LIMIT 1");
            $row = $checkStmt->fetch();
            if (!$row) {
                error_log('EMAIL COUNTER: No config row found, inserting default');
                $db->exec("INSERT INTO system_notification_config (sms_subscription_tier, sms_daily_count, sms_monthly_count, sms_yearly_count, email_daily_count, email_monthly_count, email_yearly_count, last_counter_reset, gate_notification_channel, absence_notification_channel, consecutive_absence_limit) VALUES ('free_50', 0, 0, 0, 1, 1, 1, CURDATE(), 'both', 'email', 3)");
                error_log('EMAIL COUNTER: Inserted default row with email_daily_count=1');
            } else {
                error_log('EMAIL COUNTER: Found row id=' . $row['id'] . ' email_daily_count=' . $row['email_daily_count'] . ' last_counter_reset=' . $row['last_counter_reset']);

                $upd = $db->prepare("UPDATE system_notification_config SET email_daily_count = IF(IFNULL(last_counter_reset, '1970-01-01') < ?, 1, IFNULL(email_daily_count, 0) + 1), email_monthly_count = IFNULL(email_monthly_count, 0) + 1, email_yearly_count = IFNULL(email_yearly_count, 0) + 1, last_counter_reset = ? WHERE id = ?");
                $upd->execute([$today, $today, $row['id']]);
                $affected = $upd->rowCount();
                error_log("EMAIL COUNTER: Updated row id={$row['id']}, affected rows: $affected, today: $today");

                $verifyStmt = $db->prepare("SELECT email_daily_count, last_counter_reset FROM system_notification_config WHERE id = ?");
                $verifyStmt->execute([$row['id']]);
                $verify = $verifyStmt->fetch();
                error_log('EMAIL COUNTER: Verified email_daily_count=' . ($verify['email_daily_count'] ?? 'NULL') . ' last_counter_reset=' . ($verify['last_counter_reset'] ?? 'NULL'));
            }
        } catch (Exception $e) {
            error_log('EMAIL COUNTER ERROR: ' . $e->getMessage());
        }
    }

    return $result;
}

/**
 * Send SMS notification via provider API (TextBee / Semaphore)
 * @param string $to      Phone number
 * @param string $message SMS message
 * @param bool   $force   Bypass enabled check (for testing)
 * @return bool
 */
function sendSMSNotification($to, $message, $force = false) {
    $db = getDB();

    $apiKey   = getSetting($db, 'sms_api_key', '');
    $apiUrl   = getSetting($db, 'sms_api_url', 'https://api.textbee.dev/api/v1/gateway/send-sms');
    $senderId = getSetting($db, 'sms_sender_id', 'LDBFRAS');
    $enabled  = getSetting($db, 'enable_sms_notifications', '0');
    $provider = getSetting($db, 'sms_provider', 'textbee');

    if (!$force && ($enabled !== '1' || empty($apiKey))) {
        return false;
    }

    if (empty($apiKey)) {
        return false;
    }

    // Format phone number (Philippines: 09XX -> 639XX)
    $to = formatPhoneNumber($to);

    if ($provider === 'textbee') {
        $deviceId = getSetting($db, 'sms_device_id', '');
        if (empty($deviceId)) {
            logNotification($db, $to, 'sms', '', $message, 'failed', 'TextBee Device ID not configured');
            return false;
        }

        $postData = json_encode([
            'recipients' => [$to],
            'message'    => $message
        ]);

        $headers = [
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey
        ];

        // Build full endpoint URL with device ID
        $apiUrl = 'https://api.textbee.dev/api/v1/gateway/devices/' . $deviceId . '/send-sms';
    } else {
        // Semaphore fallback (form-encoded)
        $postData = http_build_query([
            'apikey'     => $apiKey,
            'number'     => $to,
            'message'    => $message,
            'sendername' => $senderId
        ]);

        $headers = ['Content-Type: application/x-www-form-urlencoded'];
    }

    $ch = curl_init($apiUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $postData,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => false
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $success = ($httpCode >= 200 && $httpCode < 300);

    // Log notification
    logNotification($db, $to, 'sms', '', $message, $success ? 'sent' : 'failed',
                    $success ? null : "HTTP $httpCode: $response");

    if ($success) {
        $today = date('Y-m-d');

        try {
            $checkStmt = $db->query("SELECT id, sms_daily_count, last_counter_reset FROM system_notification_config ORDER BY id ASC LIMIT 1");
            $row = $checkStmt->fetch();
            if (!$row) {
                error_log('SMS COUNTER: No config row found, inserting default');
                $db->exec("INSERT INTO system_notification_config (sms_subscription_tier, sms_daily_count, sms_monthly_count, sms_yearly_count, email_daily_count, email_monthly_count, email_yearly_count, last_counter_reset, gate_notification_channel, absence_notification_channel, consecutive_absence_limit) VALUES ('free_50', 1, 1, 1, 0, 0, 0, CURDATE(), 'both', 'email', 3)");
                error_log('SMS COUNTER: Inserted default row with sms_daily_count=1');
            } else {
                error_log('SMS COUNTER: Found row id=' . $row['id'] . ' sms_daily_count=' . $row['sms_daily_count'] . ' last_counter_reset=' . $row['last_counter_reset']);

                $upd = $db->prepare("UPDATE system_notification_config SET sms_daily_count = IF(IFNULL(last_counter_reset, '1970-01-01') < ?, 1, IFNULL(sms_daily_count, 0) + 1), sms_monthly_count = IFNULL(sms_monthly_count, 0) + 1, sms_yearly_count = IFNULL(sms_yearly_count, 0) + 1, last_counter_reset = ? WHERE id = ?");
                $upd->execute([$today, $today, $row['id']]);
                $affected = $upd->rowCount();
                error_log("SMS COUNTER: Updated row id={$row['id']}, affected rows: $affected, today: $today");

                $verifyStmt = $db->prepare("SELECT sms_daily_count, last_counter_reset FROM system_notification_config WHERE id = ?");
                $verifyStmt->execute([$row['id']]);
                $verify = $verifyStmt->fetch();
                error_log('SMS COUNTER: Verified sms_daily_count=' . ($verify['sms_daily_count'] ?? 'NULL') . ' last_counter_reset=' . ($verify['last_counter_reset'] ?? 'NULL'));
            }
        } catch (Exception $e) {
            error_log('SMS COUNTER ERROR: ' . $e->getMessage());
        }
    }

    return $success;
}

/**
 * Send attendance notification to parent/guardian
 * @param PDO    $db
 * @param int    $studentId
 * @param string $status    present, late, absent
 * @param string $time      Scan time
 * @param string $type      time_in, time_out, class
 */
function sendAttendanceNotification($db, $studentId, $status, $time = '', $type = 'time_in') {
    // Get student info
    $stmt = $db->prepare(
        "SELECT s.*, g.guardian_name, g.phone, g.email, g.relationship
         FROM students s
         LEFT JOIN guardians g ON s.student_id = g.student_id
         WHERE s.id = ?"
    );
    $stmt->execute([$studentId]);
    $student = $stmt->fetch();

    if (!$student || (empty($student['phone']) && empty($student['email']))) {
        return;
    }

    $studentName = $student['first_name'] . ' ' . $student['last_name'];
    $schoolName  = getSetting($db, 'school_name', 'Liceo de Baleno');
    $timeStr     = $time ? date('g:i A', strtotime($time)) : date('g:i A');
    $dateStr     = date('F j, Y');

    // Build message based on status
    switch ($status) {
        case 'present':
            $typeLabel = ($type === 'time_out') ? 'timed out' : 'timed in';
            $smsMsg = "[$schoolName] Your child $studentName $typeLabel at $timeStr on $dateStr.";
            $emailSubject = "Attendance: $studentName $typeLabel";
            $emailBody = "<p>Dear {$student['guardian_name']},</p>
                          <p>This is to inform you that your child, <strong>$studentName</strong>,
                          has <strong style='color:#28A745;'>$typeLabel</strong> at <strong>$timeStr</strong>
                          on $dateStr.</p>
                          <p>Thank you,<br>$schoolName</p>";
            break;

        case 'late':
            $smsMsg = "[$schoolName] Your child $studentName arrived LATE at $timeStr on $dateStr.";
            $emailSubject = "Attendance: $studentName was Late";
            $emailBody = "<p>Dear {$student['guardian_name']},</p>
                          <p>This is to inform you that your child, <strong>$studentName</strong>,
                          arrived <strong style='color:#FFC107;'>LATE</strong> at <strong>$timeStr</strong>
                          on $dateStr.</p>
                          <p>Please ensure punctual attendance. Thank you,<br>$schoolName</p>";
            break;

        case 'absent':
            $smsMsg = "[$schoolName] Your child $studentName was ABSENT today ($dateStr).";
            $emailSubject = "Attendance: $studentName was Absent";
            $emailBody = "<p>Dear {$student['guardian_name']},</p>
                          <p>This is to inform you that your child, <strong>$studentName</strong>,
                          was <strong style='color:#DC3545;'>ABSENT</strong> today, $dateStr.</p>
                          <p>If this is unexpected, please contact the school office.<br>
                          Thank you,<br>$schoolName</p>";
            break;

        default:
            return;
    }

    // Send SMS if phone available
    if (!empty($student['phone'])) {
        sendSMSNotification($student['phone'], $smsMsg);
    }

    // Send Email if available
    if (!empty($student['email'])) {
        sendEmailNotification($student['email'], $emailSubject, $emailBody);
    }
}

/**
 * Send bulk notification (for announcements)
 * @param PDO    $db
 * @param array  $recipients  Array of guardian records
 * @param string $subject
 * @param string $body
 * @param array  $channels    ['email', 'sms']
 */
function sendBulkNotification($db, $recipients, $subject, $body, $channels = ['email']) {
    $sent = 0;
    $failed = 0;

    foreach ($recipients as $recipient) {
        if (in_array('email', $channels) && !empty($recipient['email'])) {
            if (sendEmailNotification($recipient['email'], $subject, $body)) {
                $sent++;
            } else {
                $failed++;
            }
        }
        if (in_array('sms', $channels) && !empty($recipient['phone'])) {
            $smsMsg = strip_tags($body);
            if (sendSMSNotification($recipient['phone'], $smsMsg)) {
                $sent++;
            } else {
                $failed++;
            }
        }
    }

    return ['sent' => $sent, 'failed' => $failed];
}

/**
 * Get setting value from database
 */
function getSetting($db, $key, $default = '') {
    try {
        $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        return $value !== false ? $value : $default;
    } catch (Exception $e) {
        return $default;
    }
}

/**
 * Format Philippine phone number
 */
function formatPhoneNumber($phone) {
    $phone = preg_replace('/[^0-9]/', '', $phone);
    if (strlen($phone) === 11 && $phone[0] === '0') {
        return '63' . substr($phone, 1);
    }
    return $phone;
}

/**
 * Wrap email body in HTML template
 */
function wrapEmailTemplate($subject, $body, $logoUrl = '', $useCid = false) {
    $schoolName = 'Facial Recognition Attendance System';
    $logoBlock = '';
    if ($logoUrl) {
        $logoSrc = ($useCid && $logoUrl) ? 'cid:ldb_logo' : $logoUrl;
        $logoImg = '<img src="' . $logoSrc . '" alt="Logo" style="height:56px;border-radius:10px;vertical-align:middle;">';
        $logoBlock = '<tr>
            <td style="background:#0A224C;padding:20px 24px;">
                <table width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;margin:0 auto;">
                    <tr>
                        <td style="text-align:center;vertical-align:middle;">' . $logoImg . '
                            <span style="display:inline-block;vertical-align:middle;margin-left:12px;">
                                <h2 style="color:#fff;margin:0;font-size:22px;letter-spacing:0.02em;display:inline;">LICEO DE BALENO</h2><br>
                                <small style="color:#8ab4f8;font-size:12px;">Facial Recognition Attendance System</small>
                            </span>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>';
    }
    return "<!DOCTYPE html>
<html>
<head><meta charset='UTF-8'></head>
<body style='margin:0;padding:0;font-family:Arial,sans-serif;background:#f7f9fc;'>
    <table width='100%' cellpadding='0' cellspacing='0' style='max-width:600px;margin:0 auto;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.1);'>
        " . $logoBlock . "
        <tr>
            <td style='padding:30px;'>
                <h3 style='color:#0A224C;margin-top:0;'>" . htmlspecialchars($subject, ENT_QUOTES, 'UTF-8') . "</h3>
                <div style='color:#333;line-height:1.6;font-size:14px;'>" . $body . "</div>
            </td>
        </tr>
        <tr>
            <td style='background:#f8f9fa;padding:15px;text-align:center;font-size:12px;color:#6c757d;'>
                &copy; " . date('Y') . " Liceo de Baleno - " . $schoolName . "<br>
                This is an automated notification. Please do not reply.
            </td>
        </tr>
    </table>
</body>
</html>";
}

/**
 * Embed images in email HTML using PHPMailer CID (Content-ID)
 * Replaces external URLs with cid: references and attaches files
 * @param PHPMailer $mail
 * @param string    $html
 * @param array     $images  ['url' => 'local_file_path', ...]
 * @return string Modified HTML with cid: references
 */
function embedEmailImages($mail, $html, $images = []) {
    foreach ($images as $url => $filePath) {
        if (!empty($url) && !empty($filePath) && file_exists($filePath)) {
            $cid = pathinfo($filePath, PATHINFO_FILENAME);
            $mail->addEmbeddedImage($filePath, $cid);
            $html = str_replace($url, 'cid:' . $cid, $html);
        }
    }
    return $html;
}

/**
 * Send email via PHPMailer SMTP
 * @param string $host
 * @param int $port
 * @param string $username
 * @param string $password
 * @param string $encryption tls, ssl, or none
 * @param string $fromEmail
 * @param string $fromName
 * @param string $to
 * @param string $subject
 * @param string $htmlBody
 * @param array  $embedImages  Optional ['url' => 'file_path'] for CID embedding
 * @return bool
 */
function sendViaPHPMailer($host, $port, $username, $password, $encryption, $fromEmail, $fromName, $to, $subject, $htmlBody, $embedImages = []) {
    try {
        $mail = new PHPMailer(true);

        $mail->isSMTP();
        $mail->Host       = $host;
        $mail->SMTPAuth   = true;
        $mail->Username   = $username;
        $mail->Password   = $password;
        $mail->SMTPSecure = match($encryption) {
            'ssl' => PHPMailer::ENCRYPTION_SMTPS,
            'tls' => PHPMailer::ENCRYPTION_STARTTLS,
            default => ''
        };
        $mail->Port       = $port;
        $mail->Timeout    = SMTP_TIMEOUT;

        $mail->setFrom($fromEmail, $fromName);
        $mail->addAddress($to);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        if (!empty($embedImages)) {
            $htmlBody = embedEmailImages($mail, $htmlBody, $embedImages);
        }
        $mail->Body    = $htmlBody;
        $mail->AltBody = strip_tags(str_replace(['<br>', '</p>', '<p>'], ["\n", "\n", "\n"], $htmlBody));

        $mail->send();
        return true;
    } catch (Exception $e) {
        $msg = $e->getMessage();
        error_log("PHPMailer error: " . $msg);
        _emailLastError('SMTP send failed: ' . $msg);
        return false;
    }
}

/**
 * Log notification to database
 */
function logNotification($db, $recipient, $channel, $subject, $message, $status, $error = null) {
    try {
        $stmt = $db->prepare(
            "INSERT INTO notifications (recipient, channel, message, subject, status, error_message, sent_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW())"
        );
        $stmt->execute([$recipient, $channel, $message, $subject, $status, $error]);
    } catch (Exception $e) {
        error_log("Notification log error: " . $e->getMessage());
    }
}
?>
