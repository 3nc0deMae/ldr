<?php
/**
 * LDB-FRAS - Unauthorized Access Page
 */
require_once __DIR__ . '/config.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Unauthorized - <?= APP_FULL_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background: <?= COLOR_BACKGROUND ?>;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .error-container { text-align: center; padding: 40px; }
        .error-code { font-size: 120px; font-weight: 800; color: <?= COLOR_DANGER ?>; line-height: 1; }
        .error-icon { font-size: 64px; color: <?= COLOR_DANGER ?>; margin-bottom: 20px; }
    </style>
</head>
<body>
    <div class="error-container">
        <div class="error-icon">
            <i class="bi bi-shield-exclamation"></i>
        </div>
        <div class="error-code">403</div>
        <h3 class="fw-bold mt-2">Unauthorized Access</h3>
        <p class="text-muted mb-4">You don't have permission to access this page.</p>
        <a href="<?= BASE_URL ?>/" class="btn btn-primary px-4">
            <i class="bi bi-house"></i> Go Home
        </a>
        <?php if (isLoggedIn()): ?>
        <a href="<?= BASE_URL ?>/<?= getCurrentUserRole() ?>/index.php" class="btn btn-outline-secondary px-4 ms-2">
            <i class="bi bi-speedometer2"></i> My Dashboard
        </a>
        <?php endif; ?>
    </div>
</body>
</html>
