<?php
/**
 * LDB-FRAS - Unified Announcement / Advisory Processor
 *
 * Handles submission from both the Admin Announcements page
 * (admin/announcements.php) and the Class Adviser page (teacher/advisory.php)
 * with strict role-based recipient routing:
 *
 *   ADMIN   → multi-select scoping: All Parents, All Teachers,
 *             Class Advisers Only, Specific Grade(s), Individual Students
 *   ADVISER → recipient_type forced to `advisory_class`, target_section_id
 *             auto-locked to the adviser's assigned section
 */

ob_start();

require_once __DIR__ . '/config.php';

// ─── Guard: must be authenticated as admin or teacher ───────────────────────
requireLogin();
$role = getCurrentUserRole();
if (!in_array($role, ['admin', 'teacher'], true)) {
    header('Location: ' . BASE_URL . '/unauthorized.php');
    exit;
}

csrfMiddleware();

$rawRequest = file_get_contents('php://input');
$parsedJson = json_decode($rawRequest, true);
$request = is_array($parsedJson) ? $parsedJson : $_POST;

$action = sanitize($request['action'] ?? '');
$announcementId = intval($request['announcement_id'] ?? 0);
if (!in_array($action, ['create', 'save_draft', 'send_draft'], true)) {
    $_SESSION['flash_message'] = ['type' => 'danger', 'message' => 'Invalid announcement action.'];
    redirectToOrigin($role);
}

if ($action === 'send_draft') {
    $announcementId = intval($_POST['announcement_id'] ?? 0);
    if (!$announcementId) {
        respondJson(false, 'Announcement ID is required to send draft.');
    }

    try {
        $stmt = $db->prepare("SELECT * FROM announcements WHERE id = ? LIMIT 1");
        $stmt->execute([$announcementId]);
        $draft = $stmt->fetch();
    } catch (Exception $e) {
        respondJson(false, 'Failed to load draft announcement.');
    }

    if (!$draft) {
        respondJson(false, 'Draft announcement not found.');
    }

    if ($role === 'teacher') {
        $currentSectionId = getAdvisorySectionId($db);
        if ($draft['scope'] !== 'advisory' || (int)$draft['target_section_id'] !== $currentSectionId) {
            respondJson(false, 'Unauthorized to send this draft announcement.');
        }
    } elseif ($role === 'admin') {
        if ($draft['scope'] !== 'school') {
            respondJson(false, 'Unauthorized to send this draft announcement.');
        }
    }

    $subject      = $draft['subject'] ?? 'Announcement';
    $bodyHTML     = $draft['body_html'] ?? ($draft['body'] ?? '');
    $channels     = json_decode($draft['channels'] ?? '[]', true) ?: [];
    $recipients   = json_decode($draft['recipients'] ?? '{}', true) ?: [];
    $recipientList = [];
    $contactFilter = function ($row) use (&$recipientList, $channels) {
        $hasEmail = in_array('email', $channels, true) && !empty($row['email']);
        $hasPhone = in_array('sms', $channels, true) && !empty($row['phone']);
        if (!$hasEmail && !$hasPhone) return;
        $key = !empty($row['email']) ? 'email:' . $row['email'] : 'sms:' . $row['phone'];
        if (!isset($recipientList[$key])) $recipientList[$key] = $row;
    };

    if (empty($channels)) {
        respondJson(false, 'Draft cannot be sent because no channels were selected.');
    }

    try {
        require_once __DIR__ . '/includes/notifications.php';

        if ($role === 'teacher') {
            $sectionRow = null;
            try {
                $st2 = $db->prepare("SELECT grade_level, section_name FROM sections WHERE id = ?");
                $st2->execute([(int)$draft['target_section_id']]);
                $sectionRow = $st2->fetch();
            } catch (Exception $e) { $sectionRow = null; }

            if ($sectionRow) {
                $sql = "SELECT g.guardian_name, g.email, g.phone
                        FROM students s
                        INNER JOIN guardians g ON s.id = g.student_id
                        WHERE s.status = 'active' AND s.grade_level = ? AND s.section = ?";
                $params = [$sectionRow['grade_level'], $sectionRow['section_name']];
                if (!empty($recipients['recipient_type']) && $recipients['recipient_type'] === 'individual' && !empty($recipients['student_ids'])) {
                    $studentIds = array_map('intval', (array)$recipients['student_ids']);
                    if ($studentIds) {
                        $sql .= " AND s.id IN (" . implode(',', array_fill(0, count($studentIds), '?')) . ")";
                        $params = array_merge($params, $studentIds);
                    }
                }
                $stmt = $db->prepare($sql);
                $stmt->execute($params);
                foreach ($stmt->fetchAll() as $g) { $contactFilter($g); }
            }
        } else {
            $studentConditions = ["s.status = 'active'"];
            $studentParams     = [];
            if (!empty($recipients['grades'])) {
                $studentConditions[] = "s.grade_level IN (" . implode(',', array_fill(0, count($recipients['grades']), '?')) . ")";
                $studentParams = array_merge($studentParams, array_map('intval', (array)$recipients['grades']));
            } elseif (!empty($recipients['student_ids'])) {
                $studentConditions[] = "s.id IN (" . implode(',', array_fill(0, count($recipients['student_ids']), '?')) . ")";
                $studentParams = array_merge($studentParams, array_map('intval', (array)$recipients['student_ids']));
            }

            $hasEmail = in_array('email', $channels, true);
            $hasSMS   = in_array('sms', $channels, true);
            $contactFilters = [];
            if ($hasEmail) $contactFilters[] = "(g.email IS NOT NULL AND g.email != '')";
            if ($hasSMS)   $contactFilters[] = "(g.phone IS NOT NULL AND g.phone != '')";
            $contactClause = !empty($contactFilters) ? 'AND (' . implode(' OR ', $contactFilters) . ')' : '';

            $sql = "SELECT g.guardian_name, g.email, g.phone
                    FROM students s
                    LEFT JOIN guardians g ON s.id = g.student_id
                    WHERE " . implode(' AND ', $studentConditions) . " {$contactClause}";
            $stmt = $db->prepare($sql);
            $stmt->execute($studentParams);
            foreach ($stmt->fetchAll() as $g) { $contactFilter($g); }
        }

        if (!empty($recipientList)) {
            $bulkResult = sendBulkNotification($db, array_values($recipientList), $subject, $bodyHTML, $channels);
            $notifDeliveryStatus = (($bulkResult['failed'] ?? 0) > 0) ? 'failed' : 'sent';
            if ($notifDeliveryStatus === 'failed') {
                $db->prepare("UPDATE announcements SET status = 'failed', updated_at = NOW() WHERE id = ?")->execute([$announcementId]);
            } else {
                $db->prepare("UPDATE announcements SET status = 'sent', updated_at = NOW() WHERE id = ?")->execute([$announcementId]);
            }
        } else {
            $notifDeliveryStatus = 'failed';
            $db->prepare("UPDATE announcements SET status = 'failed', updated_at = NOW() WHERE id = ?")->execute([$announcementId]);
        }

        if ($notifDeliveryStatus === 'failed') {
            createUserNotification($db, [
                'user_role'       => ($role === 'teacher') ? 'teacher' : 'admin',
                'user_id'         => (int)$_SESSION['user_id'],
                'category'        => 'announcement',
                'title'           => $subject,
                'message'         => 'Announcement delivery failed: ' . strip_tags($bodyHTML),
                'delivery_status' => 'failed',
                'reference_id'    => $announcementId,
                'destination_url' => ($role === 'teacher') ? '/teacher/advisory.php' : '/admin/announcements.php',
            ]);
        }
    } catch (Exception $e) {
        respondJson(false, 'Failed to send draft announcement.');
    }

    respondJson(true, 'Draft announcement sent successfully.');
}

// ─── Capture common fields ───────────────────────────────────────────────────
$subject       = sanitize($_POST['subject'] ?? '');
$bodyHTML      = trim($_POST['body_html'] ?? '');
$bodyPlain     = trim(strip_tags($bodyHTML));
$templateType  = sanitize($_POST['template_type'] ?? 'general');
$channelEmail  = !empty($_POST['channel_email']) ? 1 : 0;
$channelSMS    = !empty($_POST['channel_sms']) ? 1 : 0;
$scheduleType  = sanitize($_POST['schedule_type'] ?? 'now');
$scheduleDate  = sanitize($_POST['schedule_date'] ?? '');
$scheduleTime  = sanitize($_POST['schedule_time'] ?? '');
$status        = ($action === 'save_draft') ? 'draft' : (($scheduleType === 'scheduled') ? 'scheduled' : 'sent');

$channels = [];
if ($channelEmail) $channels[] = 'email';
if ($channelSMS)   $channels[] = 'sms';

// ─── Role-based routing ──────────────────────────────────────────────────────
$scope           = 'school';
$recipientType   = 'all';
$targetSectionId = null;
$recipients      = [];

if ($role === 'teacher') {
    // ── Class Adviser: force advisory scope, lock section id ──────────────
    $scope         = 'advisory';
    $targetSectionId = getAdvisorySectionId($db);

    if (!$targetSectionId) {
        $message = 'You have no advisory class assigned. Please contact the administrator.';
        if (!empty($_POST['ajax'])) { respondJson(false, $message); }
        $_SESSION['flash_message'] = ['type' => 'danger', 'message' => $message];
        redirectToOrigin($role);
    }

    // Adviser may target ALL parents of the advisory class (default) or
    // individual parents of specific students in that class.
    $recipientScope = (($_POST['recipient_scope'] ?? 'all') === 'individual') ? 'individual' : 'all';

    if ($recipientScope === 'individual') {
        $sectionRow = null;
        try {
            $st = $db->prepare("SELECT grade_level, section_name FROM sections WHERE id = ?");
            $st->execute([$targetSectionId]);
            $sectionRow = $st->fetch();
        } catch (Exception $e) { $sectionRow = null; }

        $studentIds = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['student_ids'] ?? [])))));
        $validIds   = [];
        if ($sectionRow && !empty($studentIds)) {
            $ph = implode(',', array_fill(0, count($studentIds), '?'));
            try {
                $st = $db->prepare(
                    "SELECT id FROM students
                     WHERE id IN ($ph) AND status = 'active'
                       AND grade_level = ? AND section = ?"
                );
                $params = array_merge($studentIds, [$sectionRow['grade_level'], $sectionRow['section_name']]);
                $st->execute($params);
                $validIds = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
            } catch (Exception $e) { $validIds = []; }
        }

        if (!empty($validIds)) {
            $recipientType = 'individual';
            $recipients = [
                'scope'             => 'advisory',
                'recipient_type'    => 'individual',
                'target_section_id' => $targetSectionId,
                'student_ids'       => $validIds,
            ];
        } else {
            // Fall back to the whole advisory class if no valid students chosen.
            $recipientType = 'advisory_class';
            $recipients = [
                'scope'             => 'advisory',
                'recipient_type'    => 'advisory_class',
                'target_section_id' => $targetSectionId,
            ];
        }
    } else {
        $recipientType = 'advisory_class';
        $recipients = [
            'scope'             => 'advisory',
            'recipient_type'    => 'advisory_class',
            'target_section_id' => $targetSectionId,
        ];
    }
} else {
    // ── Admin: allow multi-select scoping ─────────────────────────────────
    $selected = $_POST['recipient_type'] ?? [];
    if (!is_array($selected)) $selected = [$selected];
    $allowedScopes = ['all_parents', 'all_teachers', 'advisers_only', 'grade', 'individual'];
    $selected = array_values(array_intersect($allowedScopes, array_map('strval', $selected)));
    if (empty($selected)) $selected = ['all_parents'];

    $recipientType = implode(',', $selected);

    $recipients = [
        'scope'          => 'school',
        'recipient_type' => $recipientType,
    ];
    if (in_array('grade', $selected, true)) {
        $recipients['grades'] = array_map('intval', (array)($_POST['grades'] ?? []));
    }
    if (in_array('individual', $selected, true)) {
        $recipients['student_ids'] = array_map('intval', (array)($_POST['student_ids'] ?? []));
    }
}

// ─── Validate ────────────────────────────────────────────────────────────────
if ($subject === '' || $bodyPlain === '') {
    $message = 'Subject and message body are required.';
    if (!empty($_POST['ajax'])) { respondJson(false, $message); }
    $_SESSION['flash_message'] = ['type' => 'warning', 'message' => $message];
    redirectToOrigin($role);
}

// ─── Attachment upload ───────────────────────────────────────────────────────
$imagePath = null;
if (!empty($_FILES['attachment_image']['name'])) {
    $uploadDir = __DIR__ . '/uploads/announcements/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
    $ext = strtolower(pathinfo($_FILES['attachment_image']['name'], PATHINFO_EXTENSION));
    if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
        $filename = 'ann_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        if (move_uploaded_file($_FILES['attachment_image']['tmp_name'], $uploadDir . $filename)) {
            $imagePath = 'uploads/announcements/' . $filename;
        }
    }
}

$scheduledAt = null;
if ($scheduleType === 'scheduled' && $scheduleDate && $scheduleTime) {
    $scheduledAt = $scheduleDate . ' ' . $scheduleTime . ':00';
}

$existingAttachmentPath = trim($_POST['existing_attachment_path'] ?? '');

$existingAnnouncement = null;
if ($announcementId > 0) {
    try {
        $stmt = $db->prepare("SELECT * FROM announcements WHERE id = ? LIMIT 1");
        $stmt->execute([$announcementId]);
        $existingAnnouncement = $stmt->fetch();
    } catch (Exception $e) {
        $existingAnnouncement = null;
    }
    if (!$existingAnnouncement) {
        $message = 'Announcement not found.';
        if (!empty($_POST['ajax'])) { respondJson(false, $message); }
        $_SESSION['flash_message'] = ['type' => 'danger', 'message' => $message];
        redirectToOrigin($role);
    }
    if ($role === 'teacher') {
        $currentSectionId = getAdvisorySectionId($db);
        if ($existingAnnouncement['scope'] !== 'advisory' || (int)$existingAnnouncement['target_section_id'] !== $currentSectionId) {
            $message = 'Unauthorized to update this announcement.';
            if (!empty($_POST['ajax'])) { respondJson(false, $message); }
            $_SESSION['flash_message'] = ['type' => 'danger', 'message' => $message];
            redirectToOrigin($role);
        }
    } elseif ($role === 'admin' && $existingAnnouncement['scope'] !== 'school') {
        $message = 'Unauthorized to update this announcement.';
        if (!empty($_POST['ajax'])) { respondJson(false, $message); }
        $_SESSION['flash_message'] = ['type' => 'danger', 'message' => $message];
        redirectToOrigin($role);
    }
}

if (!$imagePath && $existingAttachmentPath) {
    $imagePath = $existingAttachmentPath;
}

$announcementSaved = false;
if ($announcementId > 0) {
    try {
        $stmt = $db->prepare(
            "UPDATE announcements SET
             template_type = :template_type,
             recipient_type = :recipient_type,
             target_section_id = :target_section_id,
             scope = :scope,
             subject = :subject,
             body = :body,
             body_html = :body_html,
             recipients = :recipients,
             channels = :channels,
             attachment_path = :attachment_path,
             status = :status,
             scheduled_at = :scheduled_at,
             updated_at = NOW()
             WHERE id = :id"
        );
        $stmt->execute([
            ':template_type'     => $templateType,
            ':recipient_type'    => $recipientType,
            ':target_section_id' => $targetSectionId,
            ':scope'             => $scope,
            ':subject'           => $subject,
            ':body'              => $bodyPlain,
            ':body_html'         => $bodyHTML,
            ':recipients'        => json_encode($recipients),
            ':channels'          => json_encode($channels),
            ':attachment_path'   => $imagePath,
            ':status'            => $status,
            ':scheduled_at'      => $scheduledAt,
            ':id'                => $announcementId,
        ]);
        $announcementSaved = true;
    } catch (Exception $e) {
        error_log('process_announcement update: ' . $e->getMessage());
        $announcementSaved = false;
    }
} else {
    try {
        $stmt = $db->prepare(
            "INSERT INTO announcements
             (template_type, recipient_type, target_section_id, scope, subject, body, body_html,
              recipients, channels, attachment_path, status, scheduled_at, created_by, created_at)
             VALUES (:template_type, :recipient_type, :target_section_id, :scope, :subject, :body,
                     :body_html, :recipients, :channels, :attachment_path, :status, :scheduled_at, :created_by, NOW())"
        );
        $stmt->execute([
            ':template_type'     => $templateType,
            ':recipient_type'    => $recipientType,
            ':target_section_id' => $targetSectionId,
            ':scope'             => $scope,
            ':subject'           => $subject,
            ':body'              => $bodyPlain,
            ':body_html'         => $bodyHTML,
            ':recipients'        => json_encode($recipients),
            ':channels'          => json_encode($channels),
            ':attachment_path'   => $imagePath,
            ':status'            => $status,
            ':scheduled_at'      => $scheduledAt,
            ':created_by'        => (int)$_SESSION['user_id']
        ]);
        $announcementId = (int)$db->lastInsertId();
        $announcementSaved = $announcementId > 0;
    } catch (Exception $e) {
        error_log('process_announcement insert: ' . $e->getMessage());
        $announcementSaved = false;
    }
}

if (!$announcementSaved) {
    $message = 'Failed to save announcement. Please try again.';
    if (!empty($_POST['ajax'])) { respondJson(false, $message); }
    $_SESSION['flash_message'] = ['type' => 'danger', 'message' => $message];
    redirectToOrigin($role);
}

// ─── Dispatch notifications (only for immediately-sent announcements) ────────
$notifErr = '';
if (!empty($channels) && $status === 'sent') {
    try {
        require_once __DIR__ . '/includes/notifications.php';

        $recipientList = [];
        $contactFilter = function ($row) use (&$recipientList, $channels) {
            $hasEmail = in_array('email', $channels) && !empty($row['email']);
            $hasPhone = in_array('sms', $channels) && !empty($row['phone']);
            if (!$hasEmail && !$hasPhone) return;
            $key = !empty($row['email']) ? 'email:' . $row['email'] : 'sms:' . $row['phone'];
            if (!isset($recipientList[$key])) $recipientList[$key] = $row;
        };

        if ($role === 'teacher') {
            // ── Adviser: guardians of students in the locked section ──────
            //    (filtered to individually selected students when scoped so)
            $sectionRow = null;
            try {
                $st2 = $db->prepare("SELECT grade_level, section_name FROM sections WHERE id = ?");
                $st2->execute([$targetSectionId]);
                $sectionRow = $st2->fetch();
            } catch (Exception $e) { /* no-op */ }

            if ($sectionRow) {
                $sql = "SELECT g.guardian_name, g.email, g.phone
                        FROM students s
                        INNER JOIN guardians g ON s.id = g.student_id
                        WHERE s.status = 'active' AND s.grade_level = ? AND s.section = ?";
                $params = [$sectionRow['grade_level'], $sectionRow['section_name']];
                if ($recipientType === 'individual' && !empty($recipients['student_ids'])) {
                    $sql .= " AND s.id IN (" . implode(',', array_fill(0, count($recipients['student_ids']), '?')) . ")";
                    $params = array_merge($params, $recipients['student_ids']);
                }
                $stmt = $db->prepare($sql);
                $stmt->execute($params);
                foreach ($stmt->fetchAll() as $g) { $contactFilter($g); }
            }
        } else {
            // ── Admin: gather guardians + staff per selected scope ────────
            $guardianConditions = ["s.status = 'active'"];
            $guardianParams     = [];

            if (in_array('grade', $selected, true) && !empty($recipients['grades'])) {
                $guardianConditions[] = "s.grade_level IN (" . implode(',', array_fill(0, count($recipients['grades']), '?')) . ")";
                $guardianParams       = array_merge($guardianParams, $recipients['grades']);
            } elseif (in_array('individual', $selected, true) && !empty($recipients['student_ids'])) {
                $guardianConditions[] = "s.id IN (" . implode(',', array_fill(0, count($recipients['student_ids']), '?')) . ")";
                $guardianParams       = array_merge($guardianParams, $recipients['student_ids']);
            }

            $needsGuardians = in_array('all_parents', $selected, true)
                || (in_array('grade', $selected, true) && !empty($recipients['grades']))
                || (in_array('individual', $selected, true) && !empty($recipients['student_ids']));

            if ($needsGuardians) {
                $sql = "SELECT g.guardian_name, g.email, g.phone
                        FROM students s
                        INNER JOIN guardians g ON s.id = g.student_id
                        WHERE " . implode(' AND ', $guardianConditions);
                $stmt = $db->prepare($sql);
                $stmt->execute($guardianParams);
                foreach ($stmt->fetchAll() as $g) { $contactFilter($g); }
            }

            if (in_array('all_teachers', $selected, true) || in_array('advisers_only', $selected, true)) {
                $sql = "SELECT CONCAT(t.first_name, ' ', t.last_name) AS guardian_name, t.email, t.phone
                        FROM teachers t
                        INNER JOIN users u ON t.user_id = u.id
                        WHERE u.role = 'teacher' AND t.status = 'active'";
                $params = [];
                if (in_array('advisers_only', $selected, true)) {
                    $sql .= " AND t.advisory_class IS NOT NULL AND t.advisory_class != ''";
                }
                $stmt = $db->prepare($sql);
                $stmt->execute($params);
                foreach ($stmt->fetchAll() as $t) { $contactFilter($t); }
            }
        }

        $notifDeliveryStatus = 'sent';
        if (!empty($recipientList)) {
            $bulkResult = sendBulkNotification($db, array_values($recipientList), $subject, $bodyHTML, $channels);
            if (($bulkResult['failed'] ?? 0) > 0) {
                $notifDeliveryStatus = 'failed';
                $notifErr = ($bulkResult['failed'] ?? 0) . ' recipient(s) failed to receive the notification.';
                $lastErr = getLastEmailError();
                if (!empty($lastErr)) {
                    $notifErr .= ' Reason: ' . $lastErr;
                }
                $db->prepare("UPDATE announcements SET status = 'failed', updated_at = NOW() WHERE id = ?")->execute([$announcementId]);
            }
        } else {
            $notifDeliveryStatus = 'failed';
            $notifErr = 'No recipients with valid contact details were found for this announcement.';
            $db->prepare("UPDATE announcements SET status = 'failed', updated_at = NOW() WHERE id = ?")->execute([$announcementId]);
        }

        if ($notifDeliveryStatus === 'failed') {
            createUserNotification($db, [
                'user_role'       => ($role === 'teacher') ? 'teacher' : 'admin',
                'user_id'         => (int)$_SESSION['user_id'],
                'category'        => 'announcement',
                'title'           => $subject,
                'message'         => 'Announcement delivery failed: ' . ($notifErr !== '' ? $notifErr : strip_tags($bodyHTML)),
                'delivery_status' => 'failed',
                'reference_id'    => $announcementId,
                'destination_url' => ($role === 'teacher') ? '/teacher/advisory.php' : '/admin/announcements.php',
            ]);
        }
    } catch (Exception $e) {
        error_log('process_announcement notify: ' . $e->getMessage());
        $notifErr = $e->getMessage();
        $db->prepare("UPDATE announcements SET status = 'failed', updated_at = NOW() WHERE id = ?")->execute([$announcementId]);
    }
}

// ─── Response ────────────────────────────────────────────────────────────────
$message = ($action === 'save_draft')
    ? 'Announcement saved as draft.'
    : (($status === 'scheduled') ? 'Announcement scheduled successfully.' : 'Announcement sent successfully.');
if ($notifErr) $message .= ' (Notification warning: ' . $notifErr . ')';

if (!empty($_POST['ajax'])) {
    respondJson(true, $message, $notifErr !== '');
}

$_SESSION['flash_message'] = [
    'type'    => ($notifErr !== '') ? 'warning' : 'success',
    'message' => $message,
];
redirectToOrigin($role);

// ─── Helpers ─────────────────────────────────────────────────────────────────
function redirectToOrigin($role) {
    if (ob_get_length()) { ob_end_clean(); }
    $dest = ($role === 'teacher') ? '/teacher/advisory.php' : '/admin/announcements.php';
    header('Location: ' . BASE_URL . $dest);
    exit;
}

function respondJson($success, $message, $warning = false) {
    if (ob_get_length()) { ob_end_clean(); }
    header('Content-Type: application/json');
    echo json_encode([
        'success' => $success,
        'type'    => $warning ? 'warning' : ($success ? 'success' : 'danger'),
        'message' => $message,
    ]);
    exit;
}
