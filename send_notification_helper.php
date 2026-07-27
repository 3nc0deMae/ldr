<?php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';

/**
 * Dispatch parent notification based on trigger event and system configuration.
 *
 * @param PDO    $db
 * @param int    $student_id
 * @param string $trigger_event  TIME_IN | TIME_OUT | 3X_ABSENCE | GATE_ABSENT
 * @param array  $extra_data     Optional extra context (subject_name, session_type, etc.)
 * @return array                  ['success' => bool, 'channel' => string, 'status' => string]
 */
function dispatchParentNotification($db, $student_id, $trigger_event, $extra_data = []) {
    $result = ['success' => false, 'channel' => 'NONE', 'status' => 'SKIPPED'];

    error_log("DISPATCH CALLED student_id=$student_id event=$trigger_event");

    try {
        // Auto-migrate gate_absent template column if missing
        try {
            $colStmt = $db->query("SHOW COLUMNS FROM system_notification_config WHERE Field = 'template_gate_absent'");
            if (!$colStmt->fetch()) {
                $db->exec("ALTER TABLE `system_notification_config` ADD COLUMN `template_gate_absent` TEXT DEFAULT NULL AFTER `template_absent_3x`");
            }
        } catch (Exception $e) {
            error_log('Auto-migrate template_gate_absent error: ' . $e->getMessage());
        }

        // Auto-migrate notification_logs trigger_event enum if missing GATE_ABSENT
        try {
            $evtStmt = $db->query("SHOW COLUMNS FROM notification_logs WHERE Field = 'trigger_event'");
            $evtRow = $evtStmt->fetch();
            if ($evtRow && strpos($evtRow['Type'], 'GATE_ABSENT') === false) {
                $db->exec("ALTER TABLE `notification_logs` MODIFY COLUMN `trigger_event` ENUM('TIME_IN','TIME_OUT','3X_ABSENCE','GATE_ABSENT') NOT NULL");
            }
        } catch (Exception $e) {
            error_log('Auto-migrate notification_logs trigger_event error: ' . $e->getMessage());
        }

        // Load notification configuration
        $stmt = $db->prepare("SELECT * FROM system_notification_config LIMIT 1");
        $stmt->execute();
        $config = $stmt->fetch();

        if (!$config) {
            error_log("DISPATCH EARLY RETURN student_id=$student_id event=$trigger_event status=NO_CONFIG");
            $result['status'] = 'NO_CONFIG';
            return $result;
        }

        // Determine active channel based on trigger event
        if (in_array($trigger_event, ['TIME_IN', 'TIME_OUT', 'GATE_ABSENT'], true)) {
            $active_channel = $config['gate_notification_channel'] ?? 'both';
        } elseif ($trigger_event === '3X_ABSENCE') {
            $active_channel = $config['absence_notification_channel'] ?? 'email';
        } else {
            $active_channel = $config['gate_notification_channel'] ?? 'both';
        }

        if ($active_channel === 'disabled') {
            $result['status'] = 'DISABLED';
            return $result;
        }

        // Auto-reset daily counter if date changed
        $today = date('Y-m-d');
        if ($config['last_counter_reset'] < $today) {
            $resetStmt = $db->prepare("UPDATE system_notification_config SET sms_daily_count = 0, email_daily_count = 0, last_counter_reset = ? WHERE id = ?");
            $resetStmt->execute([$today, $config['id']]);
            $config['sms_daily_count'] = 0;
            $config['email_daily_count'] = 0;
            $config['last_counter_reset'] = $today;
        }

        // Get student info
        $stmt = $db->prepare(
            "SELECT id, student_id, first_name, last_name
             FROM students
             WHERE id = ?"
        );
        $stmt->execute([$student_id]);
        $student = $stmt->fetch();

        if (!$student) {
            $result['status'] = 'STUDENT_NOT_FOUND';
            return $result;
        }

        $student_name = trim($student['first_name'] . ' ' . $student['last_name']);

        // Get ALL guardians for this student (guardians.student_id = students.id PK)
        $stmt = $db->prepare(
            "SELECT guardian_name, phone, email, relationship
             FROM guardians
             WHERE student_id = ?
             AND (phone IS NOT NULL AND TRIM(phone) <> '' OR email IS NOT NULL AND TRIM(email) <> '')"
        );
        $stmt->execute([$student['id']]);
        $guardians = $stmt->fetchAll();

        error_log("DISPATCH GUARDIANS student_id=$student_id count=" . count($guardians) . " emails=" . implode(',', array_column($guardians, 'email')));

        if (empty($guardians)) {
            error_log("DISPATCH EARLY RETURN student_id=$student_id event=$trigger_event status=NO_CONTACT");
            $result['status'] = 'NO_CONTACT';
            return $result;
        }

        // Build message from template
        $template = '';
        $subject_name = $extra_data['subject_name'] ?? '';
        $time_str = $extra_data['time'] ?? date('g:i A');
        $date_str = $extra_data['date'] ?? date('F j, Y');
        $school_name = getSetting($db, 'school_name', 'Liceo de Baleno');

        $placeholders = [
            '{student_name}' => $student_name,
            '{time}' => $time_str,
            '{date}' => $date_str,
            '{subject_name}' => $subject_name ?: 'General',
            '{school_name}' => $school_name,
            '{consecutive_absence_limit}' => $config['consecutive_absence_limit']
        ];

        switch ($trigger_event) {
            case 'TIME_IN':
                $template = $config['template_time_in'] ?? '';
                break;
            case 'TIME_OUT':
                $template = $config['template_time_out'] ?? '';
                break;
            case 'GATE_ABSENT':
                $template = $config['template_gate_absent'] ?? '';
                break;
            case '3X_ABSENCE':
                $template = $config['template_absent_3x'] ?? '';
                // Verify consecutive absences before sending
                if (!verifyConsecutiveAbsences($db, $student_id, (int)($config['consecutive_absence_limit'] ?? 3), $extra_data['subject_id'] ?? null)) {
                    $result['status'] = 'ABSENCE_THRESHOLD_NOT_MET';
                    return $result;
                }
                break;
        }

        $message = str_replace(array_keys($placeholders), array_values($placeholders), $template);
        error_log("DISPATCH MESSAGE student_id=$student_id event=$trigger_event template_len=" . strlen($template) . " message_len=" . strlen($message) . " template_preview=" . substr($template, 0, 60));
        if (empty($message)) {
            $result['status'] = 'NO_TEMPLATE';
            return $result;
        }

        $sms_msg = "[$school_name] " . $message;

        // Determine channels
        $send_sms = in_array($active_channel, ['both', 'sms'], true);
        $send_email = in_array($active_channel, ['both', 'email'], true);

        error_log("DISPATCH TRACE event=$trigger_event student_id=$student_id channel=$active_channel template_len=" . strlen($template) . " message_len=" . strlen($message) . " send_email=$send_email guardian_count=" . count($guardians));

        // Check SMS quota for free tier
        $sms_quota_exceeded = false;
        if ($send_sms && ($config['sms_subscription_tier'] ?? 'free_50') === 'free_50') {
            if ((int)($config['sms_daily_count'] ?? 0) >= 50) {
                $sms_quota_exceeded = true;
                $send_sms = false;
            }
        }

        $sms_success = false;
        $email_success = false;
        $any_guardian_notified = false;
        $log_phone = '';
        $log_email = '';

        foreach ($guardians as $guardian) {
            $guardian_phone = $guardian['phone'] ?? '';
            $guardian_email = $guardian['email'] ?? '';
            $guardian_name = $guardian['guardian_name'] ?? 'Parent';

            $guard_sms_ok = false;
            $guard_email_ok = false;

            // Send SMS
            if ($send_sms && !$sms_quota_exceeded && !empty($guardian_phone)) {
                $guard_sms_ok = sendSMSNotification($guardian_phone, $sms_msg, true);
                if ($guard_sms_ok) $sms_success = true;
            }

            // Send Email
            if ($send_email && !empty($guardian_email)) {
                $email_subject = "[$school_name] Parent Notification: " . ucfirst(strtolower(str_replace('_', ' ', $trigger_event)));
                $email_body = nl2br(htmlspecialchars($message));
                error_log("DISPATCH EMAIL SEND student_id=$student_id to=$guardian_email subject=$email_subject");
                $guard_email_ok = sendEmailNotification($guardian_email, $email_subject, $email_body);
                error_log("DISPATCH EMAIL RESULT student_id=$student_id to=$guardian_email result=" . ($guard_email_ok ? 'OK' : 'FAIL'));
                if ($guard_email_ok) $email_success = true;
            }

            if ($guard_sms_ok || $guard_email_ok) {
                $any_guardian_notified = true;
                $log_phone = $guard_sms_ok ? $guardian_phone : $log_phone;
                $log_email = $guard_email_ok ? $guardian_email : $log_email;
            }
        }

        // Determine overall result and log
        if (!$any_guardian_notified) {
            $result['status'] = 'FAILED';
            if ($sms_quota_exceeded) {
                $result['channel'] = 'EMAIL';
                $result['status'] = 'QUOTA_EXCEEDED';
                logNotificationResult($db, $student_id, $log_phone ?: ($log_email ?: 'N/A'), 'SMS', $trigger_event, 'QUOTA_EXCEEDED', 'SMS daily quota reached (50/50)');
            } else {
                $result['channel'] = ($send_sms ? 'SMS' : 'EMAIL');
                $result['status'] = 'FAILED';
                logNotificationResult($db, $student_id, $log_phone ?: ($log_email ?: 'N/A'), $result['channel'], $trigger_event, 'FAILED', 'All channels failed');
            }
        } elseif ($sms_success && $email_success) {
            $result['channel'] = 'BOTH';
            $result['status'] = 'SUCCESS';
            $result['success'] = true;
            logNotificationResult($db, $student_id, $log_phone ?: $log_email, 'BOTH', $trigger_event, 'SUCCESS');
        } elseif ($sms_success) {
            $result['channel'] = 'SMS';
            $result['status'] = 'SUCCESS';
            $result['success'] = true;
            logNotificationResult($db, $student_id, $log_phone, 'SMS', $trigger_event, 'SUCCESS');
        } elseif ($email_success) {
            $result['channel'] = 'EMAIL';
            $result['status'] = 'SUCCESS';
            $result['success'] = true;
            logNotificationResult($db, $student_id, $log_email, 'EMAIL', $trigger_event, 'SUCCESS');
        } else {
            $result['channel'] = ($send_sms ? 'SMS' : 'EMAIL');
            $result['status'] = 'FAILED';
            logNotificationResult($db, $student_id, $log_phone ?: ($log_email ?: 'N/A'), $result['channel'], $trigger_event, 'FAILED', 'All channels failed');
        }

    } catch (Exception $e) {
        error_log("dispatchParentNotification error: " . $e->getMessage());
        $result['status'] = 'ERROR';
    }

    return $result;
}

/**
 * Verify that a student has reached the consecutive absence limit.
 *
 * @param PDO    $db
 * @param int    $student_id
 * @param int    $limit
 * @param int|null $subject_id  If provided, check only for this subject
 * @return bool
 */
function verifyConsecutiveAbsences($db, $student_id, $limit = 3, $subject_id = null) {
    $consecutive = 0;
    $date = date('Y-m-d');

    // Check today and previous days going backwards
    for ($i = 0; $i < 365; $i++) {
        $check_date = date('Y-m-d', strtotime("-$i days"));
        if ($check_date > $date) continue;

        if ($subject_id) {
            $stmt = $db->prepare(
                "SELECT status FROM attendance 
                 WHERE student_id = ? AND subject_id = ? AND date = ? LIMIT 1"
            );
            $stmt->execute([$student_id, $subject_id, $check_date]);
        } else {
            $stmt = $db->prepare(
                "SELECT status FROM attendance 
                 WHERE student_id = ? AND date = ? AND session_type = 'class' LIMIT 1"
            );
            $stmt->execute([$student_id, $check_date]);
        }

        $row = $stmt->fetch();
        if ($row && $row['status'] === 'absent') {
            $consecutive++;
            if ($consecutive >= $limit) {
                return true;
            }
        } elseif ($row && $row['status'] !== 'absent') {
            // Break streak on present/late
            break;
        }
        // If no record for this day, continue checking previous days
        // (absence by non-record counts as part of consecutive absence streak)
    }

    return false;
}

/**
 * Log notification dispatch result to notification_logs table.
 *
 * @param PDO    $db
 * @param int    $student_id
 * @param string $recipient_contact
 * @param string $channel
 * @param string $trigger_event
 * @param string $delivery_status
 * @param string|null $error_message
 */
function logNotificationResult($db, $student_id, $recipient_contact, $channel, $trigger_event, $delivery_status, $error_message = null) {
    try {
        $stmt = $db->prepare(
            "INSERT INTO notification_logs 
             (student_id, recipient_contact, channel, trigger_event, delivery_status, error_message, created_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW())"
        );
        $stmt->execute([
            $student_id ?: null,
            $recipient_contact,
            $channel,
            $trigger_event,
            $delivery_status,
            $error_message
        ]);
    } catch (Exception $e) {
        error_log("Notification log error: " . $e->getMessage());
    }
}
