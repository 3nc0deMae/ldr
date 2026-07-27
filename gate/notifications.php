<?php
require_once __DIR__ . '/../config.php';
requireRole(['gate']);

$pageTitle = 'Notifications';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/pages-theme.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/pages-navbar.css">
<?php require_once __DIR__ . '/../includes/pages-topnavbar.php'; ?>
<div class="main-content">
    <div class="content-area">
        <?php require_once __DIR__ . '/../includes/notification_center.php'; ?>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
