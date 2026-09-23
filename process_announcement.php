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
        if (!teacherOwnsAdvisoryDraft($db, $draft)) {
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

        // Append image + link attachment blocks to the outgoing notification
        $attBlocks = buildAnnouncementAttachmentBlocks(
            $draft['attachment_path'] ?? null,
            $draft['attachment_link'] ?? null,
            $draft['attachment_link_label'] ?? null
        );
        $bodyHTML .= $attBlocks['html'];

        if ($role === 'teacher') {
            // Multi-advisory: deliver to every section targeted on the draft.
            $recips  = $recipients;
            $sectIds = array_values(array_unique(array_filter(array_map('intval', (array)($recips['section_ids'] ?? [])))));
            if (empty($sectIds) && !empty($draft['target_section_id'])) $sectIds = [(int)$draft['target_section_id']];
            $isIndividual = (($recips['recipient_type'] ?? '') === 'individual') && !empty($recips['student_ids']);

            foreach ($sectIds as $sid) {
                $sectionRow = getSectionRow($db, $sid);
                if (!$sectionRow) continue;

                $sql = "SELECT g.guardian_name, g.email, g.phone
                        FROM students s
                        INNER JOIN guardians g ON s.id = g.student_id
                        WHERE s.status = 'active' AND s.grade_level = ? AND s.section = ?";
                $params = [$sectionRow['grade_level'], $sectionRow['section_name']];
                if ($isIndividual) {
                    $studentIds = array_map('intval', (array)$recips['student_ids']);
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
            // ── Admin: union of every scope saved on the draft ────────────
            $hasEmail = in_array('email', $channels, true);
            $hasSMS   = in_array('sms', $channels, true);
            $contactFilters = [];
            if ($hasEmail) $contactFilters[] = "(g.email IS NOT NULL AND g.email != '')";
            if ($hasSMS)   $contactFilters[] = "(g.phone IS NOT NULL AND g.phone != '')";
            $contactClause = !empty($contactFilters) ? 'AND (' . implode(' OR ', $contactFilters) . ')' : '';

            $scopeQueries = [];
            $selectedScopes = array_map('trim', explode(',', (string)($recipients['recipient_type'] ?? '')));

            if (in_array('all_parents', $selectedScopes, true) || empty($selectedScopes)) {
                $scopeQueries[] = [
                    "SELECT g.guardian_name, g.email, g.phone
                     FROM students s
                     LEFT JOIN guardians g ON s.id = g.student_id
                     WHERE s.status = 'active' {$contactClause}",
                    []
                ];
            }

            if (in_array('grade', $selectedScopes, true) && !empty($recipients['grades'])) {
                $grades = array_values(array_filter(array_map('intval', (array)$recipients['grades'])));
                if ($grades) {
                    $scopeQueries[] = [
                        "SELECT g.guardian_name, g.email, g.phone
                         FROM students s
                         LEFT JOIN guardians g ON s.id = g.student_id
                         WHERE s.status = 'active'
                           AND s.grade_level IN (" . implode(',', array_fill(0, count($grades), '?')) . ") {$contactClause}",
                        $grades
                    ];
                }
            }

            if (in_array('individual', $selectedScopes, true) && !empty($recipients['student_ids'])) {
                $draftStudentIds = array_values(array_filter(array_map('intval', (array)$recipients['student_ids'])));
                if ($draftStudentIds) {
                    $scopeQueries[] = [
                        "SELECT g.guardian_name, g.email, g.phone
                         FROM students s
                         LEFT JOIN guardians g ON s.id = g.student_id
                         WHERE s.status = 'active'
                           AND s.id IN (" . implode(',', array_fill(0, count($draftStudentIds), '?')) . ") {$contactClause}",
                        $draftStudentIds
                    ];
                }
            }

            foreach ($scopeQueries as $sq) {
                $stmt = $db->prepare($sq[0]);
                $stmt->execute($sq[1]);
                foreach ($stmt->fetchAll() as $g) { $contactFilter($g); }
            }
        }

        if (!empty($recipientList)) {
            $bulkResult = sendBulkNotification($db, array_values($recipientList), $subject, $bodyHTML, $channels, $attBlocks['embed']);
            $verdict = summarizeBulkResult($bulkResult, $channels);
            $notifDeliveryStatus = $verdict['status'];
            $draftErr = $verdict['error'];
            if ($notifDeliveryStatus === 'failed') {
                $db->prepare("UPDATE announcements SET status = 'failed', updated_at = NOW() WHERE id = ?")->execute([$announcementId]);
            } else {
                $db->prepare("UPDATE announcements SET status = 'sent', updated_at = NOW() WHERE id = ?")->execute([$announcementId]);
            }
        } else {
            $notifDeliveryStatus = 'failed';
            $draftErr = 'No recipients with valid contact details were found for this announcement.';
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

    respondJson(true, 'Draft announcement sent successfully.', $draftErr !== '');
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
    // ── Class Adviser: force advisory scope, broadcast to ALL assigned sections ──
    $scope              = 'advisory';
    $advisorySectionIds = getAdvisorySectionIds($db);

    if (empty($advisorySectionIds)) {
        $message = 'You have no advisory class assigned. Please contact the administrator.';
        if (!empty($_POST['ajax'])) { respondJson(false, $message); }
        $_SESSION['flash_message'] = ['type' => 'danger', 'message' => $message];
        redirectToOrigin($role);
    }

    $targetSectionId = $advisorySectionIds[0];

    // Adviser may target ALL parents of the advisory class(es) (default) or
    // individual parents of specific students in any of those classes.
    $recipientScope = (($_POST['recipient_scope'] ?? 'all') === 'individual') ? 'individual' : 'all';

    if ($recipientScope === 'individual') {
        $studentIds = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['student_ids'] ?? [])))));
        $validIds   = [];
        if (!empty($studentIds)) {
            $ph         = implode(',', array_fill(0, count($studentIds), '?'));
            $sectClause = [];
            $qparams    = [];
            foreach ($advisorySectionIds as $sid) {
                $sr = getSectionRow($db, $sid);
                if (!$sr) continue;
                $sectClause[] = "(grade_level = ? AND section = ?)";
                $qparams      = array_merge($qparams, [$sr['grade_level'], $sr['section_name']]);
            }
            if (!empty($sectClause)) {
                try {
                    $st = $db->prepare(
                        "SELECT id FROM students
                         WHERE id IN ($ph) AND status = 'active'
                           AND (" . implode(' OR ', $sectClause) . ")"
                    );
                    $st->execute(array_merge($studentIds, $qparams));
                    $validIds = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
                } catch (Exception $e) { $validIds = []; }
            }
        }

        if (!empty($validIds)) {
            $recipientType = 'individual';
            $recipients = [
                'scope'             => 'advisory',
                'recipient_type'    => 'individual',
                'target_section_id' => $targetSectionId,
                'section_ids'       => $advisorySectionIds,
                'student_ids'       => $validIds,
            ];
        } else {
            // Fall back to the whole advisory class(es) if no valid students chosen.
            $recipientType = 'advisory_class';
            $recipients = [
                'scope'             => 'advisory',
                'recipient_type'    => 'advisory_class',
                'target_section_id' => $targetSectionId,
                'section_ids'       => $advisorySectionIds,
            ];
        }
    } else {
        $recipientType = 'advisory_class';
        $recipients = [
            'scope'             => 'advisory',
            'recipient_type'    => 'advisory_class',
            'target_section_id' => $targetSectionId,
            'section_ids'       => $advisorySectionIds,
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

// ─── Link attachment ─────────────────────────────────────────────────────────
$attachmentLink = trim($_POST['attachment_link'] ?? '');
if ($attachmentLink !== '' && !filter_var($attachmentLink, FILTER_VALIDATE_URL)) {
    $attachmentLink = '';
}
if (strlen($attachmentLink) > 500) { $attachmentLink = ''; }
$attachmentLinkLabel = sanitize(trim($_POST['attachment_link_label'] ?? ''));
if (mb_strlen($attachmentLinkLabel) > 150) { $attachmentLinkLabel = mb_substr($attachmentLinkLabel, 0, 150); }

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
        if (!teacherOwnsAdvisoryDraft($db, $existingAnnouncement)) {
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
             attachment_link = :attachment_link,
             attachment_link_label = :attachment_link_label,
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
            ':attachment_link'   => $attachmentLink !== '' ? $attachmentLink : null,
            ':attachment_link_label' => $attachmentLinkLabel !== '' ? $attachmentLinkLabel : null,
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
              recipients, channels, attachment_path, attachment_link, attachment_link_label, status, scheduled_at, created_by, created_at)
             VALUES (:template_type, :recipient_type, :target_section_id, :scope, :subject, :body,
                     :body_html, :recipients, :channels, :attachment_path, :attachment_link, :attachment_link_label, :status, :scheduled_at, :created_by, NOW())"
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
            ':attachment_link'   => $attachmentLink !== '' ? $attachmentLink : null,
            ':attachment_link_label' => $attachmentLinkLabel !== '' ? $attachmentLinkLabel : null,
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
$notifDeliveryStatus = '';
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
            // ── Adviser: guardians of students in every assigned section ──
            //    (filtered to individually selected students when scoped so)
            $sectIds = array_values(array_unique(array_filter(array_map('intval', (array)($recipients['section_ids'] ?? [])))));
            if (empty($sectIds) && !empty($targetSectionId)) $sectIds = [(int)$targetSectionId];

            foreach ($sectIds as $sid) {
                $sectionRow = getSectionRow($db, $sid);
                if (!$sectionRow) continue;

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
            // Each selected scope contributes its own group of guardians and
            // the results are merged; contactFilter() removes duplicates so
            // multi-select (e.g. All Parents + Individual Students) is a UNION.
            $hasEmail = in_array('email', $channels, true);
            $hasSMS   = in_array('sms', $channels, true);
            $contactFilters = [];
            if ($hasEmail) $contactFilters[] = "(g.email IS NOT NULL AND g.email != '')";
            if ($hasSMS)   $contactFilters[] = "(g.phone IS NOT NULL AND g.phone != '')";
            $contactClause = !empty($contactFilters) ? 'AND (' . implode(' OR ', $contactFilters) . ')' : '';

            $scopeQueries = [];

            if (in_array('all_parents', $selected, true)) {
                $scopeQueries[] = [
                    "SELECT g.guardian_name, g.email, g.phone
                     FROM students s
                     INNER JOIN guardians g ON s.id = g.student_id
                     WHERE s.status = 'active' {$contactClause}",
                    []
                ];
            }

            if (in_array('grade', $selected, true)) {
                $grades = array_values(array_filter(array_map('intval', (array)($recipients['grades'] ?? []))));
                if ($grades) {
                    $scopeQueries[] = [
                        "SELECT g.guardian_name, g.email, g.phone
                         FROM students s
                         INNER JOIN guardians g ON s.id = g.student_id
                         WHERE s.status = 'active'
                           AND s.grade_level IN (" . implode(',', array_fill(0, count($grades), '?')) . ") {$contactClause}",
                        $grades
                    ];
                }
            }

            if (in_array('individual', $selected, true)) {
                $studentIds = array_values(array_filter(array_map('intval', (array)($recipients['student_ids'] ?? []))));
                if ($studentIds) {
                    $scopeQueries[] = [
                        "SELECT g.guardian_name, g.email, g.phone
                         FROM students s
                         INNER JOIN guardians g ON s.id = g.student_id
                         WHERE s.status = 'active'
                           AND s.id IN (" . implode(',', array_fill(0, count($studentIds), '?')) . ") {$contactClause}",
                        $studentIds
                    ];
                }
            }

            foreach ($scopeQueries as $sq) {
                $stmt = $db->prepare($sq[0]);
                $stmt->execute($sq[1]);
                foreach ($stmt->fetchAll() as $g) { $contactFilter($g); }
            }

            if (in_array('all_teachers', $selected, true) || in_array('advisers_only', $selected, true)) {
                $sql = "SELECT CONCAT(t.first_name, ' ', t.last_name) AS guardian_name, t.email, t.phone
                        FROM teachers t
                        INNER JOIN users u ON t.user_id = u.id
                        WHERE u.role = 'teacher' AND t.status = 'active'";
                $params = [];
                if (in_array('advisers_only', $selected, true)) {
                    $sql .= " AND (t.advisory_class IS NOT NULL AND t.advisory_class != ''
                               OR EXISTS (SELECT 1 FROM teacher_advisory_sections tas WHERE tas.teacher_id = t.id))";
                }
                try {
                    $stmt = $db->prepare($sql);
                    $stmt->execute($params);
                    foreach ($stmt->fetchAll() as $t) { $contactFilter($t); }
                } catch (Exception $e) {
                    // Pivot table missing (migration not run yet): legacy single-value check only.
                    $legacySql = "SELECT CONCAT(t.first_name, ' ', t.last_name) AS guardian_name, t.email, t.phone
                                  FROM teachers t
                                  INNER JOIN users u ON t.user_id = u.id
                                  WHERE u.role = 'teacher' AND t.status = 'active'"
                        . (in_array('advisers_only', $selected, true) ? " AND t.advisory_class IS NOT NULL AND t.advisory_class != ''" : '');
                    $stmt = $db->prepare($legacySql);
                    $stmt->execute();
                    foreach ($stmt->fetchAll() as $t) { $contactFilter($t); }
                }
            }
        }

        $notifDeliveryStatus = 'sent';
        if (!empty($recipientList)) {
            // Append image + link attachment blocks to the outgoing notification
            $attBlocks = buildAnnouncementAttachmentBlocks($imagePath, $attachmentLink, $attachmentLinkLabel);
            $emailBodyHTML = $bodyHTML . $attBlocks['html'];

            $bulkResult = sendBulkNotification($db, array_values($recipientList), $subject, $emailBodyHTML, $channels, $attBlocks['embed']);
            $verdict = summarizeBulkResult($bulkResult, $channels);
            $notifDeliveryStatus = $verdict['status'];
            if ($notifDeliveryStatus === 'failed') {
                $notifErr = $verdict['error'];
                $db->prepare("UPDATE announcements SET status = 'failed', updated_at = NOW() WHERE id = ?")->execute([$announcementId]);
            } elseif ($verdict['error'] !== '') {
                // Partial delivery failure: announcement still counts as sent.
                $notifErr = 'Partial delivery: ' . $verdict['error'];
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
$allFailed = ($notifDeliveryStatus === 'failed');
$message = ($action === 'save_draft')
    ? 'Announcement saved as draft.'
    : (($status === 'scheduled')
        ? 'Announcement scheduled successfully.'
        : ($allFailed ? 'Announcement delivery failed.' : 'Announcement sent successfully.'));
if ($notifErr) $message .= $allFailed ? ' (' . $notifErr . ')' : ' (Notification warning: ' . $notifErr . ')';

if (!empty($_POST['ajax'])) {
    respondJson(true, $message, true);
}

$_SESSION['flash_message'] = [
    'type'    => $allFailed ? 'danger' : (($notifErr !== '') ? 'warning' : 'success'),
    'message' => $message,
];
redirectToOrigin($role);

// ─── Helpers ─────────────────────────────────────────────────────────────────
/**
 * Whether the current teacher owns this advisory announcement and still has
 * every targeted section assigned (multi-advisory aware).
 */
function teacherOwnsAdvisoryDraft($db, $draft) {
    if (($draft['scope'] ?? '') !== 'advisory') return false;
    if ((int)($draft['created_by'] ?? 0) !== (int)($_SESSION['user_id'] ?? 0)) return false;

    $mine = getAdvisorySectionIds($db);
    if (empty($mine)) return false;

    $recips  = json_decode($draft['recipients'] ?? '{}', true) ?: [];
    $draftSectionIds = array_values(array_unique(array_filter(array_map('intval', (array)($recips['section_ids'] ?? [])))));
    if (empty($draftSectionIds) && !empty($draft['target_section_id'])) {
        $draftSectionIds = [(int)$draft['target_section_id']];
    }
    if (empty($draftSectionIds)) return false;
    foreach ($draftSectionIds as $sid) {
        if (in_array($sid, $mine, true)) return true;
    }
    return false;
}

/**
 * Resolve a section's grade_level / section_name for guard/student queries.
 */
function getSectionRow($db, $sectionId) {
    try {
        $st = $db->prepare("SELECT grade_level, section_name FROM sections WHERE id = ?");
        $st->execute([(int)$sectionId]);
        return $st->fetch() ?: null;
    } catch (Exception $e) {
        return null;
    }
}

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
