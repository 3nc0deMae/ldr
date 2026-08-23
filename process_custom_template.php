<?php

require_once __DIR__ . '/config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
}

requireRole(['admin']);

csrfMiddleware(true);

$action = $_POST['action'] ?? 'save';

// ─── Delete a custom template ──────────────────────────────────────────────
if ($action === 'delete') {
    $id = intval($_POST['id'] ?? 0);
    $presetKey = preg_replace('/[^a-z0-9_]/', '', strtolower(trim($_POST['preset_key'] ?? '')));

    // Built-in preset → persist it as "hidden" instead of a row delete
    if ($presetKey !== '') {
        $allowedPresets = ['class_suspension', 'school_event', 'faculty_meeting', 'general', 'student_misconduct'];
        if (!in_array($presetKey, $allowedPresets, true)) {
            jsonResponse(['success' => false, 'message' => 'Unknown template preset.'], 422);
        }
        try {
            $stmt = $db->prepare("INSERT IGNORE INTO announcement_templates_hidden (preset_key) VALUES (?)");
            $stmt->execute([$presetKey]);
            jsonResponse(['success' => true, 'message' => 'Template removed from the library.']);
        } catch (Exception $e) {
            error_log('Hide template preset error: ' . $e->getMessage());
            jsonResponse(['success' => false, 'message' => 'Failed to remove the template. Please try again.'], 500);
        }
    }

    if ($id <= 0) {
        jsonResponse(['success' => false, 'message' => 'Invalid template ID.'], 422);
    }

    try {
        $stmt = $db->prepare("DELETE FROM announcement_templates WHERE id = ?");
        $stmt->execute([$id]);
        jsonResponse([
            'success' => true,
            'message' => $stmt->rowCount() > 0
                ? 'Custom template deleted.'
                : 'Template not found or already deleted.',
        ]);
    } catch (Exception $e) {
        error_log('Delete custom template error: ' . $e->getMessage());
        jsonResponse(['success' => false, 'message' => 'Failed to delete the template. Please try again.'], 500);
    }
}

$title       = strip_tags(trim($_POST['title'] ?? ''));
$icon        = strtolower(strip_tags(trim($_POST['icon'] ?? '')));
$subject     = strip_tags(trim($_POST['subject'] ?? ''));
$bodyContent = trim($_POST['body_content'] ?? '');

if ($title === '' || $subject === '' || $bodyContent === '') {
    jsonResponse(['success' => false, 'message' => 'Template name, subject, and body are required.'], 422);
}

if (mb_strlen($title) > 150)  $title  = mb_substr($title, 0, 150);
if (mb_strlen($subject) > 255) $subject = mb_substr($subject, 0, 255);

// Only allow safe Bootstrap icon classes; fall back to the default bookmark
if (!preg_match('/^bi-[a-z0-9-]+$/', $icon)) {
    $icon = 'bi-bookmark';
}

try {
    $stmt = $db->prepare(
        "INSERT INTO announcement_templates (name, icon, color, subject, body, created_by, created_at)
         VALUES (:name, :icon, 'custom', :subject, :body, :created_by, NOW())"
    );
    $stmt->execute([
        ':name'       => $title,
        ':icon'       => $icon,
        ':subject'    => $subject,
        ':body'       => $bodyContent,
        ':created_by' => getCurrentUserId(),
    ]);

    jsonResponse([
        'success' => true,
        'message' => "Custom template \"{$title}\" saved.",
        'template' => [
            'id'           => (int)$db->lastInsertId(),
            'title'        => $title,
            'icon'         => $icon,
            'subject'      => $subject,
            'body_content' => $bodyContent,
            'is_custom'    => true,
        ],
    ]);
} catch (Exception $e) {
    error_log('Save custom template error: ' . $e->getMessage());
    jsonResponse(['success' => false, 'message' => 'Failed to save the template. Please try again.'], 500);
}
