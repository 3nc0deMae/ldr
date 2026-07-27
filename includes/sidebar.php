<?php
$currentRole = getCurrentUserRole();
$currentPage = basename($_SERVER['PHP_SELF'], '.php');
$activeSection = $_GET['section'] ?? '';

// Unread notification badge for the sidebar "Notifications" link.
$__navUnread = 0;
if (isset($db) && $currentRole) {
    try {
        $stmt = $db->prepare("SELECT COUNT(*) FROM user_notifications WHERE (user_role = ? OR user_role = 'all') AND is_read = 0");
        $stmt->execute([$currentRole]);
        $__navUnread = (int)$stmt->fetchColumn();
    } catch (Exception $e) { /* table may not exist yet */ }
}
?>
<style>
    .nav-badge {
        display: inline-flex; align-items: center; justify-content: center;
        min-width: 18px; height: 18px; padding: 0 5px; margin-left: 8px;
        background: #ef4444; color: #fff; font-size: 10px; font-weight: 700;
        border-radius: 999px; line-height: 1;
    }
</style>

<!-- Sidebar Navigation -->
<div class="sidebar d-flex flex-column" id="mainSidebar">
    <!-- Brand -->
    <div class="brand">
        <h1>Hello!</h1>
        <p>Welcome, <?= ucfirst($currentRole ?? 'User') ?>!</p>
    </div>

    <!-- Navigation -->
    <nav class="flex-grow-1 mt-2">
        <?php if ($currentRole === 'admin'): ?>
        <!-- ============ ADMIN NAVIGATION ============ -->
        <div class="nav-section-title">Main</div>
        <ul class="nav flex-column">
            <li class="nav-item">
                <a class="nav-link <?= $currentPage === 'index' ? 'active' : '' ?>" href="<?= BASE_URL ?>/admin/index.php">
                    <i class="bi bi-speedometer2"></i>
                    <span>Dashboard</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $currentPage === 'MyCalendar' ? 'active' : '' ?>" href="<?= BASE_URL ?>/admin/MyCalendar.php">
                    <i class="bi bi-calendar3-event"></i>
                    <span>My Calendar</span>
                </a>
            </li>
        </ul>

        <div class="nav-section-title">Management</div>
        <ul class="nav flex-column">
            <li class="nav-item">
                <a class="nav-link <?= $currentPage === 'students' ? 'active' : '' ?>" href="<?= BASE_URL ?>/admin/students.php">
                    <i class="bi bi-people"></i>
                    <span>Students</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $currentPage === 'teachers' ? 'active' : '' ?>" href="<?= BASE_URL ?>/admin/teachers.php">
                    <i class="bi bi-person-badge"></i>
                    <span>Teachers</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $currentPage === 'subjects' ? 'active' : '' ?>" href="<?= BASE_URL ?>/admin/subjects.php">
                    <i class="bi bi-book"></i>
                    <span>Subjects</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $currentPage === 'strands' ? 'active' : '' ?>" href="<?= BASE_URL ?>/admin/strands.php">
                    <i class="bi bi-grid-1x2"></i>
                    <span>Electives</span>
                </a>
            </li>
        </ul>

        <div class="nav-section-title">Attendance</div>
        <ul class="nav flex-column">
            <li class="nav-item">
                <a class="nav-link <?= $currentPage === 'attendance' ? 'active' : '' ?>" href="<?= BASE_URL ?>/admin/attendance.php">
                    <i class="bi bi-calendar-check"></i>
                    <span>Attendance</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $currentPage === 'face-registration' ? 'active' : '' ?>" href="<?= BASE_URL ?>/admin/face-registration.php">
                    <i class="bi bi-camera-fill"></i>
                    <span>Face Registration</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $currentPage === 'reports' ? 'active' : '' ?>" href="<?= BASE_URL ?>/admin/reports.php">
                    <i class="bi bi-file-earmark-bar-graph"></i>
                    <span>Reports</span>
                </a>
            </li>
        </ul>

        <div class="nav-section-title">Communication</div>
        <ul class="nav flex-column">
            <li class="nav-item">
                <a class="nav-link <?= $currentPage === 'announcements' ? 'active' : '' ?>" href="<?= BASE_URL ?>/admin/announcements.php">
                    <i class="bi bi-megaphone"></i>
                    <span>Announcements</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $currentPage === 'notifications' ? 'active' : '' ?>" href="<?= BASE_URL ?>/admin/notifications.php">
                    <i class="bi bi-bell"></i>
                    <span>Notifications</span>
                    <?php if (($$__navUnread ?? 0) > 0): ?>
                        <span class="badge bg-danger ms-auto" style="font-size:10px;padding:3px 7px;border-radius:10px;"><?= (int)$__navUnread ?></span>
                    <?php endif; ?>
                </a>
            </li>
        </ul>

        <div class="nav-section-title">System</div>
        <ul class="nav flex-column">
            <li class="nav-item">
                <a class="nav-link <?= $currentPage === 'audit-logs' ? 'active' : '' ?>" href="<?= BASE_URL ?>/admin/audit-logs.php">
                    <i class="bi bi-shield-lock"></i>
                    <span>Audit Logs</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $currentPage === 'profile' ? 'active' : '' ?>" href="<?= BASE_URL ?>/admin/profile.php">
                    <i class="bi bi-person"></i>
                    <span>Profile</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $currentPage === 'settings' ? 'active' : '' ?>" href="<?= BASE_URL ?>/settings.php">
                    <i class="bi bi-gear"></i>
                    <span>Settings</span>
                </a>
            </li>
        </ul>

        <?php elseif ($currentRole === 'teacher'): ?>
        <!-- ============ TEACHER NAVIGATION ============ -->
        <div class="nav-section-title">Main</div>
        <ul class="nav flex-column">
            <li class="nav-item">
                <a class="nav-link <?= $currentPage === 'index' ? 'active' : '' ?>" href="<?= BASE_URL ?>/teacher/index.php">
                    <i class="bi bi-speedometer2"></i>
                    <span>Dashboard</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $currentPage === 'MyCalendar' ? 'active' : '' ?>" href="<?= BASE_URL ?>/teacher/MyCalendar.php">
                    <i class="bi bi-calendar3-event"></i>
                    <span>My Calendar</span>
                </a>
            </li>
        </ul>

        <div class="nav-section-title">Attendance</div>
        <ul class="nav flex-column">
            <li class="nav-item">
                <a class="nav-link <?= $currentPage === 'attendance' ? 'active' : '' ?>" href="<?= BASE_URL ?>/teacher/attendance.php">
                    <i class="bi bi-camera-video"></i>
                    <span>Take Attendance</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $currentPage === 'records' ? 'active' : '' ?>" href="<?= BASE_URL ?>/teacher/records.php">
                    <i class="bi bi-list-check"></i>
                    <span>Attendance Records</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $currentPage === 'reports' ? 'active' : '' ?>" href="<?= BASE_URL ?>/teacher/reports.php">
                    <i class="bi bi-file-earmark-bar-graph"></i>
                    <span>Reports</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $currentPage === 'interactive-tools' ? 'active' : '' ?>" href="<?= BASE_URL ?>/teacher/interactive-tools.php">
                    <i class="bi bi-dice-5-fill"></i>
                    <span>Interactive Tools</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $currentPage === 'master-list' ? 'active' : '' ?>" href="<?= BASE_URL ?>/teacher/master-list.php">
                    <i class="bi bi-clipboard-data"></i>
                    <span>Master List</span>
                </a>
            </li>
        </ul>

        <div class="nav-section-title">System</div>
        <ul class="nav flex-column">
            <li class="nav-item">
                <a class="nav-link <?= $currentPage === 'profile' ? 'active' : '' ?>" href="<?= BASE_URL ?>/teacher/profile.php">
                    <i class="bi bi-person"></i>
                    <span>Profile</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $currentPage === 'settings' ? 'active' : '' ?>" href="<?= BASE_URL ?>/settings.php">
                    <i class="bi bi-gear"></i>
                    <span>Settings</span>
                </a>
            </li>
        </ul>

        <?php elseif ($currentRole === 'gate'): ?>
        <!-- ============ GATE PERSONNEL NAVIGATION ============ -->
        <div class="nav-section-title">Main</div>
        <ul class="nav flex-column">
            <li class="nav-item">
                <a class="nav-link <?= $currentPage === 'index' ? 'active' : '' ?>" href="<?= BASE_URL ?>/gate/index.php">
                    <i class="bi bi-speedometer2"></i>
                    <span>Dashboard</span>
                </a>
            </li>
        </ul>

        <div class="nav-section-title">Gate Operations</div>
        <ul class="nav flex-column">
            <li class="nav-item">
                <a class="nav-link <?= $currentPage === 'timein' ? 'active' : '' ?>" href="<?= BASE_URL ?>/gate/timein.php">
                    <i class="bi bi-box-arrow-in-right"></i>
                    <span>Start Time-In</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $currentPage === 'timeout' ? 'active' : '' ?>" href="<?= BASE_URL ?>/gate/timeout.php">
                    <i class="bi bi-box-arrow-left"></i>
                    <span>Start Time-Out</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $currentPage === 'register' ? 'active' : '' ?>" href="<?= BASE_URL ?>/gate/register.php">
                    <i class="bi bi-camera-fill"></i>
                    <span>Registration Kiosk</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $currentPage === 'logs' ? 'active' : '' ?>" href="<?= BASE_URL ?>/gate/logs.php">
                    <i class="bi bi-journal-text"></i>
                    <span>Gate Logs</span>
                </a>
            </li>
        </ul>

        <div class="nav-section-title">System</div>
        <ul class="nav flex-column">
            <li class="nav-item">
                <a class="nav-link <?= $currentPage === 'profile' ? 'active' : '' ?>" href="<?= BASE_URL ?>/gate/profile.php">
                    <i class="bi bi-person"></i>
                    <span>Profile</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $currentPage === 'settings' ? 'active' : '' ?>" href="<?= BASE_URL ?>/settings.php">
                    <i class="bi bi-gear"></i>
                    <span>Settings</span>
                </a>
            </li>
        </ul>
        <?php endif; ?>
    </nav>

    <!-- Sidebar Footer: User Info & Logout -->
    <div class="sidebar-footer">
        <div class="d-flex justify-content-between align-items-center">
            <div class="user-info">
                <div class="user-avatar">
                    <i class="bi bi-person-circle"></i>
                </div>
                <div>
                    <div class="user-name"><?= sanitize($_SESSION['user_email'] ?? 'User') ?></div>
                    <div class="user-role"><?= ucfirst($currentRole ?? 'Guest') ?></div>
                </div>
            </div>
            <a href="<?= BASE_URL ?>/logout.php" class="logout-btn" title="Logout">
                <i class="bi bi-box-arrow-right"></i>
            </a>
        </div>
    </div>
</div>

<!-- Mobile Sidebar Overlay -->
<div class="sidebar-overlay d-lg-none" id="sidebarOverlay"
     style="position:fixed;top:0;left:0;width:100%;height:100%;
            background:rgba(0,0,0,0.5);z-index:998;display:none;"
     onclick="document.getElementById('mainSidebar').classList.remove('show');this.style.display='none';">
</div>
