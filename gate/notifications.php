<?php
require_once __DIR__ . '/../config.php';
requireRole(['gate']);

$pageTitle = 'Notifications';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/pages-theme.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/pages-navbar.css">
<style>@media(max-width:767px){.mobile-title{display:block!important}}</style>
<?php require_once __DIR__ . '/../includes/pages-topnavbar.php'; ?>
<div class="main-content">
    <div class="content-area">
        <div class="d-none d-md-flex justify-content-between align-items-center gap-3 mb-3">
            <div class="page-title mb-0"><h5 class="mb-0">Notifications</h5><small>In-app notification center</small></div>
        </div>
        <div class="page-title mobile-title"><div class="mobile-title-inner"><div class="mobile-title-left"><h5>Notifications</h5><small>In-app notification center</small></div></div></div>
        <?php require_once __DIR__ . '/../includes/notification_center.php'; ?>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
