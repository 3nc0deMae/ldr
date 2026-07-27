<?php
require_once __DIR__ . '/config.php';

// Redirect logged-in users to their dashboard
if (isLoggedIn()) {
    $role = getCurrentUserRole();
    switch ($role) {
        case 'admin':   header('Location: ' . BASE_URL . '/admin/index.php'); exit();
        case 'teacher': header('Location: ' . BASE_URL . '/teacher/index.php'); exit();
        case 'gate':    header('Location: ' . BASE_URL . '/gate/index.php'); exit();
        default:        header('Location: ' . BASE_URL . '/admin/index.php'); exit();
    }
}
$baseUrl = BASE_URL;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= APP_FULL_NAME ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        /* ========== RESET & BASE ========== */
        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

        :root {
            --navy:       #0A224C;
            --blue:       #0066FE;
            --blue-light: rgba(0, 102, 254, 0.18);
            --success:    #10B981;
            --success-bg: rgba(16, 185, 129, 0.18);
            --warning:    #F59E0B;
            --warning-bg: rgba(245, 158, 11, 0.18);
            --gray-200:   #E5E7EB;
            --gray-300:   #D1D5DB;
            --gray-500:   #6B7280;
            --gray-800:   #1F2937;
            --radius-lg:  12px;
            --radius-md:  10px;
            --shadow-sm:  0 1px 3px rgba(0, 0, 0, 0.06);
            --shadow-md:  0 4px 14px rgba(0, 0, 0, 0.1);
            --transition: 0.25s ease;
        }

        body {
            font-family: 'Poppins', sans-serif;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            color: #ffffff;
            -webkit-font-smoothing: antialiased;
            background: url('assets/images/background.png') center center / cover no-repeat fixed;
        }

        /* ========== NAVBAR ========== */
        .ldb-navbar {
            background: var(--navy);
            padding: 14px 28px;
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: 0 2px 12px rgba(10, 34, 76, 0.25);
        }

        .nav-content {
            display: flex;
            align-items: center;
            justify-content: space-between;
            max-width: 100%;
            margin: 0 auto;
            gap: 16px;
            padding: 0 12px;
        }

        .ldb-logo {
            width: 62px;
            height: 62px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid rgba(255, 255, 255, 0.2);
            flex-shrink: 0;
            background: #fff;
        }

        .system-title-container {
            flex: 1;
            text-align: center;
            min-width: 0;
        }

        .nav-title {
            font-size: 1.70rem;
            font-weight: 700;
            color: #fff;
            letter-spacing: 1.2px;
            text-transform: uppercase;
            line-height: 1.3;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .nav-subtitle {
            font-size: 0.80rem;
            font-weight: 400;
            color: rgba(255, 255, 255, 0.55);
            letter-spacing: 0.5px;
            margin-top: 1px;
        }

        .nav-scanner-icon {
            width: 62px;
            height: 62px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid rgba(255, 255, 255, 0.2);
            flex-shrink: 0;
            background: #fff;
        }

        /* ========== LANDING MAIN ========== */
        .landing-main {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 60px 20px 40px;
            background: transparent;
            position: relative;
        }

        /* dark overlay so glass buttons are readable */
        .landing-main::before {
            content: '';
            position: absolute;
            inset: 0;
            background: rgba(0, 0, 0, 0.25);
            pointer-events: none;
            z-index: 0;
        }

        .landing-main > * {
            position: relative;
            z-index: 1;
        }

        /* ========== WELCOME HEADER ========== */
        .welcome-header {
            text-align: center;
            margin-bottom: 40px;
            animation: fadeInDown 0.6s ease both;
        }

        .welcome-title {
            font-size: 1.85rem;
            font-weight: 700;
            color: #ffffff;
            margin-bottom: 8px;
            line-height: 1.35;
            text-shadow: 0 2px 10px rgba(0, 0, 0, 0.4);
        }

        .welcome-title span {
            color: #60A5FA;
        }

        .instruction {
            font-size: 1.02rem;
            color: white;
            font-weight: 400;
            text-shadow: 0 1px 6px rgba(0, 0, 0, 0.3);
        }

        /* ========== USER SELECTION BUTTONS — FROSTED GLASS ========== */
        .user-selection-group {
            display: flex;
            flex-direction: column;
            gap: 16px;
            width: 100%;
            max-width: 380px;
            animation: fadeInUp 0.6s ease 0.15s both;
        }

        .user-btn {
            display: flex;
            align-items: center;
            background: rgba(10, 34, 76, 0.50);
            backdrop-filter: blur(24px) saturate(1.6);
            -webkit-backdrop-filter: blur(24px) saturate(1.6);
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-radius: var(--radius-lg);
            padding: 18px 24px;
            gap: 18px;
            text-decoration: none;
            color: #ffffff;
            transition: all var(--transition);
            box-shadow:
                0 8px 32px rgba(0, 0, 0, 0.2),
                inset 0 1px 0 rgba(255, 255, 255, 0.08);
            position: relative;
            overflow: hidden;
        }

        /* left accent bar */
        .user-btn::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 5px;
            border-radius: 3px 0 0 3px;
            transition: width var(--transition);
        }

        .user-btn.admin::before   { background: var(--blue); }
        .user-btn.gate::before     { background: var(--success); }
        .user-btn.teacher::before  { background: var(--warning); }

        /* Hover — glass stays, tint shifts */
        .user-btn:hover {
            transform: translateX(6px);
            box-shadow:
                0 12px 40px rgba(0, 0, 0, 0.3),
                inset 0 1px 0 rgba(255, 255, 255, 0.12);
        }

        .user-btn.admin:hover {
            background: rgba(0, 102, 254, 0.3);
            border-color: rgba(0, 102, 254, 0.4);
        }
        .user-btn.gate:hover {
            background: rgba(16, 185, 129, 0.3);
            border-color: rgba(16, 185, 129, 0.4);
        }
        .user-btn.teacher:hover {
            background: rgba(245, 158, 11, 0.3);
            border-color: rgba(245, 158, 11, 0.4);
        }

        .user-btn:hover::before {
            width: 6px;
        }

        /* icon circle */
        .btn-icon-wrap {
            width: 52px;
            height: 52px;
            border-radius: var(--radius-md);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            flex-shrink: 0;
            transition: transform var(--transition);
        }

        .admin .btn-icon-wrap   { background: rgba(0, 102, 254, 0.3); color: #60A5FA; }
        .gate .btn-icon-wrap     { background: rgba(16, 185, 129, 0.3); color: #34D399; }
        .teacher .btn-icon-wrap  { background: rgba(245, 158, 11, 0.3); color: #FBBF24; }

        .user-btn:hover .btn-icon-wrap {
            transform: scale(1.08);
        }

        /* button content */
        .btn-content { flex: 1; min-width: 0; }

        .btn-label {
            font-size: 17px;
            font-weight: 700;
            color: #ffffff;
            display: block;
            line-height: 1.3;
        }

        .btn-desc {
            font-size: 12px;
            color: white;
            line-height: 1.4;
        }

        /* arrow */
        .btn-arrow {
            font-size: 18px;
            color: rgba(255, 255, 255, 0.35);
            transition: all var(--transition);
            flex-shrink: 0;
        }

        .user-btn:hover .btn-arrow {
            transform: translateX(4px);
            color: rgba(255, 255, 255, 0.8);
        }

        /* ========== FOOTER ========== */
        .landing-footer {
            background: var(--navy);
            color: rgba(255, 255, 255, 0.7);
            text-align: center;
            padding: 16px 20px;
            font-size: 13px;
        }

        .landing-footer a {
            color: rgba(255, 255, 255, 0.85);
            text-decoration: none;
        }

        .landing-footer a:hover {
            color: #fff;
        }

        /* ========== ENTRANCE ANIMATIONS ========== */
        @keyframes fadeInDown {
            from { opacity: 0; transform: translateY(-18px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(18px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        /* ========== RESPONSIVE ========== */
        @media (max-width: 768px) {
            .ldb-navbar { padding: 12px 16px; }
            .nav-title { font-size: 1.1rem; letter-spacing: 0.8px; }
            .nav-subtitle { font-size: 0.75rem; }
            .ldb-logo { width: 40px; height: 40px; }
            .nav-scanner-icon { width: 40px; height: 40px; }
            .welcome-title { font-size: 1.5rem; }
            .instruction { font-size: 0.95rem; }
            .landing-main { padding: 40px 16px 32px; }
        }

        @media (max-width: 480px) {
            .user-selection-group { max-width: 100%; }
            .user-btn { padding: 15px 18px; gap: 14px; }
            .btn-icon-wrap { width: 44px; height: 44px; font-size: 19px; }
            .btn-label { font-size: 15px; }
            .nav-title { font-size: 1rem; }
        }
    </style>
</head>
<body>

    <?php include 'includes/navbar.php'; ?>

    <!-- ========== MAIN CONTENT ========== -->
    <main class="landing-main">
        <div class="welcome-header">
            <h2 class="welcome-title">Welcome to <span>LDB</span> Facial Recognition<br>Attendance System!</h2>
            <p class="instruction">Please select your type of user to continue</p>
        </div>

        <div class="user-selection-group">
            <!-- Admin -->
            <a href="<?= BASE_URL ?>/login.php?role=admin" class="user-btn admin">
                <div class="btn-icon-wrap">
                    <i class="fas fa-shield-alt"></i>
                </div>
                <div class="btn-content">
                    <span class="btn-label">Admin</span>
                    <span class="btn-desc">System administrator access</span>
                </div>
                <i class="fas fa-chevron-right btn-arrow"></i>
            </a>

            <!-- Gate -->
            <a href="<?= BASE_URL ?>/login.php?role=gate" class="user-btn gate">
                <div class="btn-icon-wrap">
                    <i class="fas fa-door-open"></i>
                </div>
                <div class="btn-content">
                    <span class="btn-label">Gate</span>
                    <span class="btn-desc">Student time-in &amp; time-out</span>
                </div>
                <i class="fas fa-chevron-right btn-arrow"></i>
            </a>

            <!-- Teachers -->
            <a href="<?= BASE_URL ?>/login.php?role=teacher" class="user-btn teacher">
                <div class="btn-icon-wrap">
                    <i class="fas fa-graduation-cap"></i>
                </div>
                <div class="btn-content">
                    <span class="btn-label">Teachers</span>
                    <span class="btn-desc">Classroom attendance management</span>
                </div>
                <i class="fas fa-chevron-right btn-arrow"></i>
            </a>
        </div>
    </main>

    <!-- ========== FOOTER ========== -->
    <footer class="landing-footer">
        &copy; <?= APP_YEAR ?> <a href="<?= BASE_URL ?>/">Liceo de Baleno</a>. Facial Recognition Attendance System. All rights reserved.
    </footer>

</body>
</html>