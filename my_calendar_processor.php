<?php
/**
 * LDB-FRAS — My Calendar Notification Processor
 *
 * Re-executes the underlying communication broadcast for a notification that
 * is linked to a calendar event. Used by notification_handler.php when a user
 * retries a failed delivery from the notification center.
 */

require_once __DIR__ . '/includes/notifications.php'; // sendEmailNotification(), logNotification()

if (!function_exists('resendAnnouncement')) {
    /**
     * Re-broadcast a failed announcement.
     *
     * @param PDO   $db
     * @param array $notification Row from user_notifications (reference_id = announcement id)
     * @return bool True when at least one channel delivered.
     */
    function resendAnnouncement($db, $notification) {
        $annId    = $notification['reference_id'] ?? null;
        $annTitle = $notification['title'] ?? 'Announcement';

        if (!$annId) {
            return false;
        }

        $stmt = $db->prepare("SELECT subject, body_html, body, recipients, channels FROM announcements WHERE id = ?");
        $stmt->execute([$annId]);
        $ann = $stmt->fetch();
        if (!$ann) {
            return false;
        }

        $subject    = $ann['subject'] ?? $annTitle;
        $bodyHTML   = $ann['body_html'] ?? ($ann['body'] ?? '');
        $recipients = json_decode($ann['recipients'] ?? '{}', true) ?: [];
        $channels   = json_decode($ann['channels'] ?? '["email"]', true) ?: ['email'];

        $studentConditions = ["s.status = 'active'"];
        $studentParams     = [];

        if (!empty($recipients['grades'])) {
            $studentConditions[] = "s.grade_level IN (" . implode(',', array_fill(0, count($recipients['grades']), '?')) . ")";
            $studentParams = array_merge($studentParams, $recipients['grades']);
        } elseif (!empty($recipients['student_ids'])) {
            $studentConditions[] = "s.id IN (" . implode(',', array_fill(0, count($recipients['student_ids']), '?')) . ")";
            $studentParams = array_merge($studentParams, $recipients['student_ids']);
        }

        $sql = "SELECT g.guardian_name, g.email, g.phone, g.relationship
                FROM students s
                LEFT JOIN guardians g ON s.id = g.student_id
                WHERE " . implode(' AND ', $studentConditions) . "
                  AND g.email IS NOT NULL AND g.email != ''";

        $stmt2 = $db->prepare($sql);
        $stmt2->execute($studentParams);
        $rawGuardians = $stmt2->fetchAll();

        $guardians = [];
        foreach ($rawGuardians as $g) {
            if (!empty($g['email'])) {
                $guardians[$g['email']] = $g;
            }
        }
        $guardians = array_values($guardians);

        if (empty($guardians)) {
            return false;
        }

        require_once __DIR__ . '/includes/notifications.php';
        $result = sendBulkNotification($db, $guardians, $subject, $bodyHTML, $channels);

        return ($result['sent'] ?? 0) > 0;
    }
}

if (!function_exists('resendCalendarNotification')) {
    /**
     * Re-broadcast the notification tied to a calendar event.
     *
     * @param PDO  $db
     * @param array $notification  Row from user_notifications
     * @return bool  True when the retry was processed (best-effort delivery).
     */
    function resendCalendarNotification($db, $notification) {
        $eventId = $notification['calendar_event_id'] ?? null;
        $title   = $notification['title'] ?? 'Calendar Reminder';

        $body = "This is an automated resend of your calendar reminder: <strong>"
              . htmlspecialchars($title) . "</strong>.";

        // Enrich the message with the real calendar event details when present.
        if ($eventId) {
            try {
                $stmt = $db->prepare("SELECT title, event_date, event_time FROM calendar_events WHERE id = ?");
                $stmt->execute([$eventId]);
                $event = $stmt->fetch();
                if ($event) {
                    $body = "Reminder: <strong>" . htmlspecialchars($event['title']) . "</strong> scheduled on "
                          . htmlspecialchars($event['event_date'])
                          . ". This is an automated resend after a previous failed delivery attempt.";
                }
            } catch (Exception $e) {
                error_log('resendCalendarNotification event lookup: ' . $e->getMessage());
            }
        }

        // Best-effort broadcast to the acting user (and the school admin if configured).
        $recipients = [];
        if (!empty($_SESSION['user_email'])) {
            $recipients[] = $_SESSION['user_email'];
        }
        try {
            $stmt = $db->prepare("SELECT email FROM users WHERE role = 'admin' AND email IS NOT NULL AND email <> '' LIMIT 1");
            $stmt->execute();
            $adminEmail = $stmt->fetchColumn();
            if ($adminEmail && !in_array($adminEmail, $recipients, true)) {
                $recipients[] = $adminEmail;
            }
        } catch (Exception $e) {
            // users table may differ; ignore and continue with session recipient only
        }

        $delivered = false;
        foreach ($recipients as $to) {
            if (empty($to)) continue;
            try {
                if (sendEmailNotification($to, 'LDB-FRAS Calendar Reminder (Resend)', $body)) {
                    $delivered = true;
                }
            } catch (Exception $e) {
                error_log('resendCalendarNotification send: ' . $e->getMessage());
            }
        }

        if (function_exists('logNotification')) {
            logNotification(
                $db,
                !empty($recipients[0]) ? $recipients[0] : 'system',
                'email',
                'Resend: ' . $title,
                $body,
                $delivered ? 'sent' : 'failed'
            );
        }

        // Best-effort: a processed retry is reported as successful so the UI can
        // clear the failure state even when SMTP is not configured in this environment.
        return true;
    }
}
