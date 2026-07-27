<?php
/**
 * LDB-FRAS — Notification Handler (AJAX backend)
 *
 * Handles asynchronous notification operations triggered from the notification
 * center and navbar bell: approve/decline pending actions, mark single read,
 * mark all as read, and resend failed calendar deliveries.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/notifications.php';
require_once __DIR__ . '/my_calendar_processor.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['ajax_action'] ?? '') === 'get_upcoming') {
    $role = getCurrentUserRole();
    $userId = getCurrentUserId();
    if (!$role) {
        jsonResponse(['success' => false, 'message' => 'Unauthorized'], 401);
    }
    $sql = "SELECT * FROM calendar_events WHERE event_date >= CURDATE() AND event_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY) AND is_completed = 0";
    $params = [];
    if ($role === 'teacher') {
        $sql .= " AND created_by = ?";
        $params[] = $userId ?: 0;
    }
    $sql .= " ORDER BY event_date ASC, event_time IS NULL, event_time ASC LIMIT 20";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    jsonResponse(['success' => true, 'events' => $stmt->fetchAll()]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
}

$role = getCurrentUserRole();

csrfMiddleware(true);

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
    $data = $_POST;
}
$action = $data['action'] ?? '';

switch ($action) {

    /* ── Approve a pending action (teacher registration / override) ── */
    case 'approve_notif':
        $id = (int)($data['id'] ?? 0);
        if ($id <= 0) {
            jsonResponse(['success' => false, 'message' => 'Invalid notification ID'], 400);
        }
        $stmt = $db->prepare(
            "UPDATE user_notifications SET delivery_status = 'sent', is_read = 1, updated_at = NOW()
             WHERE id = ? AND (user_role = ? OR user_role = 'all')"
        );
        $stmt->execute([$id, $role]);
        jsonResponse(['success' => true, 'message' => 'Request approved successfully.']);
        break;

    /* ── Decline a pending action ── */
    case 'decline_notif':
        $id = (int)($data['id'] ?? 0);
        if ($id <= 0) {
            jsonResponse(['success' => false, 'message' => 'Invalid notification ID'], 400);
        }
        $stmt = $db->prepare(
            "UPDATE user_notifications SET delivery_status = 'failed', is_read = 1, updated_at = NOW()
             WHERE id = ? AND (user_role = ? OR user_role = 'all')"
        );
        $stmt->execute([$id, $role]);
        jsonResponse(['success' => true, 'message' => 'Request declined successfully.']);
        break;

    /* ── Mark a single notification as read ── */
    case 'mark_read_one':
        $id = (int)($data['id'] ?? 0);
        if ($id <= 0) {
            jsonResponse(['success' => false, 'message' => 'Invalid notification ID'], 400);
        }
        $db->prepare("UPDATE user_notifications SET is_read = 1 WHERE id = ? AND (user_role = ? OR user_role = 'all')")->execute([$id, $role]);
        jsonResponse(['success' => true]);
        break;

    /* ── Mark all visible notifications as read ── */
    case 'mark_all_read':
        $db->prepare("UPDATE user_notifications SET is_read = 1 WHERE (user_role = ? OR user_role = 'all') AND is_read = 0")->execute([$role]);
        jsonResponse(['success' => true, 'message' => 'All notifications marked as read']);
        break;

    /* ── Retry a failed delivery ── */
    case 'resend':
        $id = (int)($data['id'] ?? 0);
        if ($id <= 0) {
            jsonResponse(['success' => false, 'message' => 'Invalid notification ID'], 400);
        }
        $stmt = $db->prepare("SELECT * FROM user_notifications WHERE id = ? AND (user_role = ? OR user_role = 'all')");
        $stmt->execute([$id, $role]);
        $notification = $stmt->fetch();
        if (!$notification) {
            jsonResponse(['success' => false, 'message' => 'Notification not found'], 404);
        }

        $ok = false;
        if (!empty($notification['calendar_event_id'])) {
            $ok = resendCalendarNotification($db, $notification);
        } elseif (!empty($notification['reference_id'])) {
            $ok = resendAnnouncement($db, $notification);
        }

        $db->prepare("UPDATE user_notifications SET delivery_status = ?, retry_count = retry_count + 1, updated_at = NOW() WHERE id = ?")
           ->execute([$ok ? 'sent' : 'failed', $id]);
        jsonResponse([
            'success' => $ok,
            'message' => $ok ? 'Notification resent successfully.' : 'Resend failed. Please try again.'
        ]);
        break;

    default:
        jsonResponse(['success' => false, 'message' => 'Invalid action'], 400);
}
