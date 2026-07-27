<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/security.php';

header('Content-Type: application/json');

$userEmail = $_SESSION['user_email'] ?? '';
$user      = null;
try {
    $stmt = $db->prepare("SELECT * FROM users WHERE email = ? AND status = 'active' LIMIT 1");
    $stmt->execute([$userEmail]);
    $user = $stmt->fetch();
} catch (Exception $e) {}

if (!$user) {
    jsonResponse(['error' => 'Unauthorized'], 401);
}

$userId   = $user['id'];
$avatarDir = ROOT_PATH . '/uploads/avatars';
$avatarUrl = BASE_URL . '/uploads/avatars/' . $userId . '.jpg';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $path = $avatarDir . '/' . $userId . '.jpg';
    if (file_exists($path)) {
        jsonResponse(['success' => true, 'avatar_url' => $avatarUrl, 'exists' => true]);
    }
    jsonResponse(['success' => true, 'avatar_url' => null, 'exists' => false]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

csrfMiddleware(true);

$action = $_POST['action'] ?? '';

if ($action === 'upload') {
    if (empty($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
        jsonResponse(['error' => 'No image file provided or upload failed.'], 400);
    }

    $file = $_FILES['image'];
    $maxSize = 5 * 1024 * 1024;
    if ($file['size'] > $maxSize) {
        jsonResponse(['error' => 'Image size must be less than 5MB.'], 400);
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    $allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];
    if (!in_array($mime, $allowedMimes, true)) {
        jsonResponse(['error' => 'Invalid image format. Allowed: JPG, PNG, WebP.'], 400);
    }

    if (!is_dir($avatarDir)) {
        @mkdir($avatarDir, 0755, true);
    }

    $ext      = match ($mime) { 'image/png' => '.png', 'image/webp' => '.webp', default => '.jpg' };
    $filename = $userId . $ext;
    $dest     = $avatarDir . '/' . $filename;

    $existing = glob($avatarDir . '/' . $userId . '.*');
    foreach ($existing as $old) {
        @unlink($old);
    }

    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        jsonResponse(['error' => 'Failed to save uploaded image.'], 500);
    }

    $avatarUrl = BASE_URL . '/uploads/avatars/' . $filename;
    logAudit($db, 'avatar_upload', 'User uploaded profile avatar');
    jsonResponse(['success' => true, 'avatar_url' => $avatarUrl, 'message' => 'Profile picture updated successfully.']);
}

if ($action === 'delete') {
    $existing = glob($avatarDir . '/' . $userId . '.*');
    foreach ($existing as $old) {
        @unlink($old);
    }
    logAudit($db, 'avatar_delete', 'User removed profile avatar');
    jsonResponse(['success' => true, 'message' => 'Profile picture removed.']);
}

jsonResponse(['error' => 'Invalid action'], 400);
