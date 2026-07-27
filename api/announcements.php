<?php


require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

requireRole(['admin']);

csrfMiddleware(true);

$action = $_POST['action'] ?? '';

switch ($action) {

    case 'list':
        $announcements = getAnnouncements($db);
        jsonResponse(['data' => $announcements, 'count' => count($announcements)]);
        break;

    case 'create':
        // Build recipients
        $recipientType = sanitize($_POST['recipient_type'] ?? 'all');
        $recipients = [];
        if ($recipientType === 'grade') {
            $grades = $_POST['grades'] ?? [];
            $recipients = ['grades' => $grades];
        } else {
            $recipients = ['all' => true];
        }

        // Build channels
        $channels = [];
        if (!empty($_POST['channel_email'])) $channels[] = 'email';
        if (!empty($_POST['channel_sms']))   $channels[] = 'sms';
        if (empty($channels)) $channels = ['email'];

        // Determine status
        $scheduleType = sanitize($_POST['schedule_type'] ?? 'now');
        $status = ($scheduleType === 'scheduled') ? 'scheduled' : 'sent';
        $scheduledAt = null;
        if ($status === 'scheduled') {
            $schedDate = sanitize($_POST['schedule_date'] ?? '');
            $schedTime = sanitize($_POST['schedule_time'] ?? '');
            if ($schedDate && $schedTime) {
                $scheduledAt = $schedDate . ' ' . $schedTime . ':00';
            }
        }

        $data = [
            'subject'       => sanitize($_POST['subject'] ?? ''),
            'body'          => $_POST['body'] ?? '',
            'template_type' => sanitize($_POST['template_type'] ?? 'general'),
            'recipients'    => $recipients,
            'channels'      => $channels,
            'status'        => $status,
            'scheduled_at'  => $scheduledAt,
            'created_by'    => getCurrentUserId()
        ];

        if (empty($data['subject']) || empty($data['body'])) {
            redirect('/admin/announcements.php', 'Subject and message body are required.', 'danger');
        }

        $result = createAnnouncement($db, $data);

        if ($result) {
            $annId = (int)$result;

            if (!empty($channels) && $status === 'sent') {
                try {
                    require_once __DIR__ . '/../includes/notifications.php';

                    $studentConditions = ["s.status = 'active'"];
                    $studentParams = [];
                    if (!empty($recipients['grades'])) {
                        $studentConditions[] = "s.grade_level IN (" . implode(',', array_fill(0, count($recipients['grades']), '?')) . ")";
                        $studentParams = $recipients['grades'];
                    } elseif (!empty($recipients['student_ids'])) {
                        $studentConditions[] = "s.id IN (" . implode(',', array_fill(0, count($recipients['student_ids']), '?')) . ")";
                        $studentParams = $recipients['student_ids'];
                    }

                    $hasEmail = in_array('email', $channels);
                    $hasSMS   = in_array('sms', $channels);
                    $contactFilters = [];
                    if ($hasEmail) $contactFilters[] = "(g.email IS NOT NULL AND g.email != '')";
                    if ($hasSMS)   $contactFilters[] = "(g.phone IS NOT NULL AND g.phone != '')";
                    $contactClause = !empty($contactFilters) ? 'AND (' . implode(' OR ', $contactFilters) . ')' : '';

                    $sql = "SELECT g.guardian_name, g.email, g.phone, g.relationship
                            FROM students s
                            LEFT JOIN guardians g ON s.id = g.student_id
                            WHERE " . implode(' AND ', $studentConditions) . " {$contactClause}";
                    $stmt2 = $db->prepare($sql);
                    $stmt2->execute($studentParams);
                    $rawGuardians = $stmt2->fetchAll();

                    $guardians = [];
                    foreach ($rawGuardians as $g) {
                        $key = !empty($g['email']) ? $g['email'] : (!empty($g['phone']) ? 'sms:' . $g['phone'] : null);
                        if ($key && !isset($guardians[$key])) {
                            $guardians[$key] = $g;
                        }
                    }
                    $guardians = array_values($guardians);

                    $notifDeliveryStatus = 'sent';
                    if (!empty($guardians)) {
                        $bulkResult = sendBulkNotification($db, $guardians, $data['subject'], $data['body'], $channels);
                        if (($bulkResult['failed'] ?? 0) > 0) {
                            $notifDeliveryStatus = 'failed';
                            $db->prepare("UPDATE announcements SET status = 'failed', updated_at = NOW() WHERE id = ?")
                               ->execute([$annId]);
                        }
                    } else {
                        $notifDeliveryStatus = 'failed';
                    }

                    createUserNotification($db, [
                        'user_role'       => 'all',
                        'category'        => 'announcement',
                        'title'           => $data['subject'],
                        'message'         => strip_tags($data['body'] ?? ''),
                        'delivery_status' => $notifDeliveryStatus,
                        'reference_id'    => $annId,
                    ]);
                } catch (Exception $e) {
                    $db->prepare("UPDATE announcements SET status = 'failed', updated_at = NOW() WHERE id = ?")
                       ->execute([$annId]);
                }
            }

            redirect('/admin/announcements.php', 'Announcement ' . ($status === 'sent' ? 'sent' : 'scheduled') . ' successfully.', 'success');
        } else {
            redirect('/admin/announcements.php', 'Failed to create announcement.', 'danger');
        }
        break;

    case 'resend':
        $id = intval($_POST['id'] ?? 0);
        if (!$id) {
            jsonResponse(['error' => 'Announcement ID required'], 400);
        }

        $stmt = $db->prepare("SELECT subject, body_html, body, recipients, channels, status FROM announcements WHERE id = ?");
        $stmt->execute([$id]);
        $ann = $stmt->fetch();
        if (!$ann) {
            jsonResponse(['error' => 'Announcement not found'], 404);
        }

        $subject    = $ann['subject'] ?? 'Announcement';
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

        $hasEmail = in_array('email', $channels);
        $hasSMS   = in_array('sms', $channels);
        $contactFilters = [];
        if ($hasEmail) $contactFilters[] = "(g.email IS NOT NULL AND g.email != '')";
        if ($hasSMS)   $contactFilters[] = "(g.phone IS NOT NULL AND g.phone != '')";
        $contactClause = !empty($contactFilters) ? 'AND (' . implode(' OR ', $contactFilters) . ')' : '';

        $sql = "SELECT g.guardian_name, g.email, g.phone, g.relationship
                FROM students s
                LEFT JOIN guardians g ON s.id = g.student_id
                WHERE " . implode(' AND ', $studentConditions) . " {$contactClause}";

        $stmt2 = $db->prepare($sql);
        $stmt2->execute($studentParams);
        $rawGuardians = $stmt2->fetchAll();

        $guardians = [];
        foreach ($rawGuardians as $g) {
            $key = !empty($g['email']) ? $g['email'] : (!empty($g['phone']) ? 'sms:' . $g['phone'] : null);
            if ($key && !isset($guardians[$key])) {
                $guardians[$key] = $g;
            }
        }
        $guardians = array_values($guardians);

        if (empty($guardians)) {
            jsonResponse(['error' => 'No recipients found for this announcement'], 404);
        }

        require_once __DIR__ . '/../includes/notifications.php';
        $result = sendBulkNotification($db, $guardians, $subject, $bodyHTML, $channels);

        if (($result['failed'] ?? 0) === 0 && ($result['sent'] ?? 0) > 0) {
            $db->prepare("UPDATE announcements SET status = 'sent', updated_at = NOW() WHERE id = ?")->execute([$id]);

            $db->prepare("UPDATE user_notifications SET delivery_status = 'sent', is_read = 1, updated_at = NOW() WHERE reference_id = ? AND category = 'announcement' AND delivery_status = 'failed'")
              ->execute([$id]);

            createUserNotification($db, [
                'user_role'       => 'admin',
                'category'        => 'announcement',
                'title'           => $subject . ' (Resent)',
                'message'         => 'Announcement was resent successfully to all recipients.',
                'delivery_status' => 'sent',
                'reference_id'    => $id,
            ]);

            jsonResponse(['success' => true, 'message' => 'Announcement resent successfully.']);
        } elseif (($result['sent'] ?? 0) > 0) {
            jsonResponse(['error' => 'Partial delivery failure. ' . $result['failed'] . ' recipient(s) still failed. Please try again later.'], 500);
        } else {
            jsonResponse(['error' => 'All delivery attempts failed. Please try again later.'], 500);
        }
        break;

    case 'delete':
        $id = intval($_POST['id'] ?? 0);
        if (!$id) {
            jsonResponse(['error' => 'Announcement ID required'], 400);
        }

        $stmt = $db->prepare("DELETE FROM announcements WHERE id = ?");
        if ($stmt->execute([$id])) {
            jsonResponse(['success' => true, 'message' => 'Announcement deleted']);
        } else {
            jsonResponse(['error' => 'Failed to delete announcement'], 500);
        }
        break;

    default:
        jsonResponse(['error' => 'Invalid action'], 400);
}
