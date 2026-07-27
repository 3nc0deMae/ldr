<?php

require_once __DIR__ . '/config.php';

// Redirect if already logged in
if (isLoggedIn()) {
    $role = getCurrentUserRole();
    header("Location: " . BASE_URL . "/{$role}/index.php");
    exit();
}

$role = sanitize($_GET['role'] ?? 'admin');
$error = '';
$success = '';

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = sanitize($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        $error = 'Please enter your email and password.';
    } elseif (!isValidEmail($email)) {
        $error = 'Please enter a valid email address.';
    } else {
        $user = getUserByEmail($db, $email);

        if ($user && verifyPassword($password, $user['password'])) {
            if ($user['role'] !== $role) {
                $error = 'This account does not match the selected user type.';
            } elseif (!isset($_POST['agreement'])) {
                    $error = 'You must agree to the Terms and Conditions and Data Privacy to continue.';
            } else {
                regenerateSession();
                setUserSession($user);

                $stmt = $db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
                $stmt->execute([$user['id']]);

                $stmt = $db->prepare("INSERT INTO audit_logs (user_id, action, description, ip_address, user_agent, created_at)
                                      VALUES (?, 'login', ?, ?, ?, NOW())");
                $stmt->execute([
                    $user['id'],
                    "User logged in as {$user['role']}",
                    $_SERVER['REMOTE_ADDR'] ?? '',
                    $_SERVER['HTTP_USER_AGENT'] ?? ''
                ]);

                header("Location: " . BASE_URL . "/{$user['role']}/index.php");
                exit();
            }
        } else {
            $error = 'Invalid email or password. Please try again.';
        }
    }
}

$roleLabels = [
    'admin'   => 'Administrator',
    'teacher' => 'Teacher',
    'gate'    => 'Gate Personnel'
];
$roleLabel  = $roleLabels[$role] ?? 'User';
$roleIcons  = [
    'admin'   => 'bi-shield-lock',
    'teacher' => 'bi-person-badge',
    'gate'    => 'bi-door-open'
];
$roleIcon = $roleIcons[$role] ?? 'bi-person';
$roleColors = [
    'admin'   => '#0A224C',
    'teacher' => '#0066FE',
    'gate'    => '#17A2B8'
];
$roleColor = $roleColors[$role] ?? '#0066FE';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $roleLabel ?> Login — <?= APP_FULL_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Instrument+Serif:ital@0;1&family=DM+Sans:ital,opsz,wght@0,9..40,100..1000;1,9..40,100..1000&family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        /* =============================================
           ROOT & VARIABLES
           ============================================= */
        :root {
            --ink: #0B1426;
            --ink-deep: #070E1A;
            --ink-surface: #152238;
            --ink-glow: #1a2d4d;
            --navy: #0A224C;
            --panel-bg: #FAF8F5;
            --panel-bg-end: #F5F2ED;
            --white: #FFFFFF;
            --accent: <?= $roleColor ?>;
            --text-heading: #111827;
            --text-body: #374151;
            --text-muted: #6B7280;
            --text-faint: #9CA3AF;
            --border: #E5E7EB;
            --border-hover: #D1D5DB;
            --border-light: #F3F4F6;
            --success: #10B981;
            --danger: #EF4444;
            --danger-bg: rgba(239, 68, 68, 0.06);
            --danger-text: #991B1B;
            --radius-sm: 8px;
            --radius: 10px;
            --radius-lg: 14px;
            --radius-xl: 24px;
            --shadow-xs: 0 1px 2px rgba(0,0,0,0.04);
            --shadow-sm: 0 1px 3px rgba(0,0,0,0.08), 0 1px 2px rgba(0,0,0,0.04);
            --shadow-md: 0 4px 6px -1px rgba(0,0,0,0.07), 0 2px 4px -2px rgba(0,0,0,0.04);
            --shadow-focus: 0 0 0 3px rgba(0,0,0,0.04);
            --font-display: 'Instrument Serif', Georgia, 'Times New Roman', serif;
            --font-body: 'DM Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            --font-nav: 'Poppins', sans-serif;
            --ease-out: cubic-bezier(0.16, 1, 0.3, 1);
            --ease-spring: cubic-bezier(0.34, 1.56, 0.64, 1);
        }

        /* =============================================
           RESET & BASE
           ============================================= */
        *, *::before, *::after {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        html {
            scroll-behavior: smooth;
        }

        body {
            font-family: 'Poppins', sans-serif;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            color: var(--gray-800);
            -webkit-font-smoothing: antialiased;
            background:
                url('assets/images/background.png') center center / cover no-repeat fixed;
        }

        /* =============================================
           NAVBAR
           ============================================= */
        .ldb-navbar {
            background: #0A224C;
            padding: 14px 0;
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: 0 2px 12px rgba(10, 34, 76, 0.25);
        }

        .nav-content {
            display: flex;
            align-items: center;
            justify-content: space-between;
            width: 100%;
            margin: 0 auto;
            gap: 16px;
            padding: 0 40px;
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
            font-family: 'Poppins', sans-serif;
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
            font-family: 'Poppins', sans-serif;
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

        /* =============================================
           PAGE CONTAINER (transparent so backdrop reads through)
           ============================================= */
        .page-container {
            flex: 1;
            display: flex;
            justify-content: center;
            align-items: stretch;
            padding: 28px 32px 32px;
        }

        .page-card {
            width: 100%;
            max-width: 1100px;
            background: transparent;
            border-radius: 16px;
            overflow: hidden;
            display: flex;
        }

        /* =============================================
           MAIN WRAPPER (holds both panels)
           ============================================= */
        .main-wrapper {
            flex: 1;
            display: flex;
        }

        /* =============================================
           BRAND PANEL (LEFT)
           ============================================= */
        .brand-panel {
            flex: 1;
            background: linear-gradient(160deg, #0e2d5e 0%, #133a72 35%, #194a8a 100%);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 60px 48px;
            position: relative;
            overflow: hidden;
        }

        .brand-panel::before {
            content: '';
            position: absolute;
            width: 600px;
            height: 600px;
            background: radial-gradient(circle, rgba(80,160,255,0.12) 0%, transparent 65%);
            top: -180px;
            right: -120px;
            pointer-events: none;
            animation: orbDrift 20s ease-in-out infinite;
        }

        .brand-panel::after {
            content: '';
            position: absolute;
            width: 400px;
            height: 400px;
            background: radial-gradient(circle, rgba(16,185,129,0.07) 0%, transparent 60%);
            bottom: -100px;
            left: -80px;
            pointer-events: none;
            animation: orbDrift 25s ease-in-out infinite reverse;
        }

        .deco-ring {
            position: absolute;
            border: 1px solid rgba(255,255,255,0.03);
            border-radius: 50%;
            pointer-events: none;
        }

        .deco-ring--1 {
            width: 520px; height: 520px;
            top: -140px; right: -100px;
            animation: ringPulse 18s ease-in-out infinite;
        }

        .deco-ring--2 {
            width: 360px; height: 360px;
            bottom: -70px; left: -70px;
            animation: ringPulse 22s ease-in-out 3s infinite;
        }

        .deco-ring--3 {
            width: 180px; height: 180px;
            top: 48%; left: 48%;
            opacity: 0.4;
            animation: ringPulse 15s ease-in-out 1s infinite;
        }

        .deco-line-v {
            position: absolute;
            left: 15%; top: 10%; bottom: 10%;
            width: 1px;
            background: linear-gradient(180deg, transparent, rgba(255,255,255,0.04) 30%, rgba(255,255,255,0.04) 70%, transparent);
            pointer-events: none;
        }

        .brand-content {
            position: relative;
            z-index: 2;
            text-align: center;
            max-width: 420px;
            animation: fadeInUp 0.8s var(--ease-out) forwards;
        }

        .brand-logo {
            width: 88px;
            height: 88px;
            background: rgba(255,255,255,0.05);
            border: 1.5px solid rgba(255,255,255,0.1);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 28px;
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            animation: floatGentle 6s ease-in-out infinite;
        }

        .brand-logo i {
            font-size: 36px;
            color: rgba(255,255,255,0.85);
        }

        .brand-content h2 {
            font-family: var(--font-display);
            font-size: 32px;
            font-weight: 400;
            color: #FFFFFF;
            margin-bottom: 10px;
            letter-spacing: -0.01em;
            line-height: 1.2;
        }

        .brand-tagline {
            color: rgba(255,255,255,0.4);
            font-size: 14px;
            line-height: 1.75;
            max-width: 340px;
            margin: 0 auto;
        }

        .brand-divider {
            width: 40px;
            height: 1.5px;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            margin: 32px auto;
        }

        .brand-features {
            display: flex;
            flex-direction: column;
            gap: 14px;
            text-align: left;
            max-width: 310px;
            margin: 0 auto;
        }

        .feature-item {
            display: flex;
            align-items: center;
            gap: 12px;
            color: rgba(255,255,255,0.45);
            font-size: 13px;
            line-height: 1.4;
            transition: color 0.3s ease;
        }

        .feature-item:hover { color: rgba(255,255,255,0.7); }

        .feature-item i {
            color: var(--success);
            font-size: 14px;
            flex-shrink: 0;
        }

        /* =============================================
           FORM PANEL (RIGHT) — fully transparent so
           the frosted-glass on form-panel-inner can
           blur the body background image
           ============================================= */
        .form-panel {
            width: 500px;
            min-width: 420px;
            background: transparent;
            backdrop-filter: none;
            -webkit-backdrop-filter: none;
            display: flex;
            flex-direction: column;
            position: relative;
        }

        .form-panel::before {
            content: '';
            position: absolute;
            left: 0; top: 0; bottom: 0;
            width: 1px;
            background: linear-gradient(180deg, transparent 5%, rgba(255,255,255,0.08) 30%, rgba(255,255,255,0.08) 70%, transparent 95%);
            z-index: 1;
        }

        /* =============================================
           MOBILE BRAND HEADER (hidden on desktop)
           ============================================= */
        .mobile-brand-header {
            display: none;
        }

        /* =============================================
           FORM PANEL INNER — frosted glass matching
           the landing page .user-btn exactly
           ============================================= */
        .form-panel-inner {
            flex: 1;
            display: flex;
            flex-direction: column;
            justify-content: center;
            padding: 56px 48px;
            width: 100%;
            background: rgba(10, 34, 76, 0.50);
            backdrop-filter: blur(24px) saturate(1.6);
            -webkit-backdrop-filter: blur(24px) saturate(1.6);
            border: 1px solid rgba(255, 255, 255, 0.12);
            box-shadow:
                0 8px 32px rgba(0, 0, 0, 0.2),
                inset 0 1px 0 rgba(255, 255, 255, 0.08);
        }

        /* =============================================
           LOGIN HEADER
           ============================================= */
        .login-header {
            margin-bottom: 32px;
        }

        .role-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 16px 6px 12px;
            border-radius: var(--radius-xl);
            font-size: 12px;
            font-weight: 600;
            letter-spacing: 0.02em;
            margin-bottom: 20px;
            background: rgba(255,255,255,0.08);
            color: #ffffff;
            opacity: 0;
            animation: fadeInUp 0.55s var(--ease-out) 0.15s forwards;
        }

        .role-badge-dot {
            width: 7px; height: 7px;
            background: var(--accent);
            border-radius: 50%;
            flex-shrink: 0;
            animation: pulseDot 2.5s ease-in-out infinite;
        }

        .login-header h4 {
            font-family: var(--font-display);
            font-size: 30px;
            font-weight: 400;
            color: #ffffff;
            margin-bottom: 8px;
            letter-spacing: -0.01em;
            line-height: 1.2;
            opacity: 0;
            animation: fadeInUp 0.55s var(--ease-out) 0.25s forwards;
        }

        .login-header p {
            color: rgba(255, 255, 255, 0.5);
            font-size: 14px;
            margin: 0;
            opacity: 0;
            animation: fadeInUp 0.55s var(--ease-out) 0.3s forwards;
        }

        /* =============================================
           ALERT
           ============================================= */
        .alert-custom {
            border: none;
            border-radius: var(--radius);
            padding: 14px 16px;
            margin-bottom: 24px;
            display: flex;
            align-items: flex-start;
            gap: 12px;
            font-size: 13.5px;
            line-height: 1.55;
            background: rgba(239, 68, 68, 0.12);
            border-left: 3px solid var(--danger);
            color: #FCA5A5;
            animation: slideDown 0.4s var(--ease-out);
        }

        .alert-custom i {
            color: var(--danger);
            font-size: 16px;
            flex-shrink: 0;
            margin-top: 1px;
        }

        /* =============================================
           FORM ELEMENTS
           ============================================= */
        .form-group {
            margin-bottom: 22px;
            opacity: 0;
            animation: fadeInUp 0.55s var(--ease-out) forwards;
        }

        .form-group:nth-of-type(1) { animation-delay: 0.35s; }
        .form-group:nth-of-type(2) { animation-delay: 0.45s; }

        .form-label-custom {
            display: block;
            font-weight: 600;
            font-size: 13px;
            color: rgba(255, 255, 255, 0.85);
            margin-bottom: 7px;
            letter-spacing: 0.01em;
        }

        .input-wrapper {
            position: relative;
        }

        .input-wrapper .input-icon {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: rgba(255, 255, 255, 0.35);
            font-size: 16px;
            z-index: 4;
            pointer-events: none;
            transition: color 0.25s ease;
        }

        .input-wrapper .form-control-custom {
            width: 100%;
            height: 48px;
            padding: 0 16px 0 46px;
            border: 1.5px solid rgba(255, 255, 255, 0.12);
            border-radius: var(--radius);
            font-family: var(--font-body);
            font-size: 14px;
            font-weight: 400;
            color: #ffffff;
            background: rgba(255, 255, 255, 0.06);
            transition: border-color 0.25s ease, box-shadow 0.25s ease, background 0.25s ease;
            outline: none;
            appearance: none;
            -webkit-appearance: none;
        }

        .input-wrapper .form-control-custom::placeholder {
            color: rgba(255, 255, 255, 0.3);
            font-weight: 400;
        }

        .input-wrapper .form-control-custom:hover {
            border-color: rgba(255, 255, 255, 0.22);
            background: rgba(255, 255, 255, 0.08);
        }

        .input-wrapper .form-control-custom:focus {
            border-color: var(--accent);
            background: rgba(255, 255, 255, 0.1);
            box-shadow: 0 0 0 3px rgba(255, 255, 255, 0.04), 0 0 0 3px <?= $roleColor ?>30;
        }

        .input-wrapper:focus-within .input-icon {
            color: #ffffff;
        }

        .toggle-password {
            position: absolute;
            right: 14px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            padding: 6px;
            color: rgba(255, 255, 255, 0.35);
            cursor: pointer;
            font-size: 16px;
            z-index: 4;
            transition: color 0.2s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 6px;
        }

        .toggle-password:hover { color: rgba(255, 255, 255, 0.7); }

        .toggle-password:focus-visible {
            outline: 2px solid var(--accent);
            outline-offset: 2px;
        }

        .input-wrapper .form-control-custom.is-invalid {
            border-color: var(--danger);
        }

        .input-wrapper .form-control-custom.is-invalid:focus {
            box-shadow: var(--shadow-focus), 0 0 0 3px rgba(239,68,68,0.15);
        }

        .input-wrapper:has(.is-invalid) .input-icon {
            color: #FCA5A5;
        }

        .field-error {
            font-size: 12px;
            color: #FCA5A5;
            margin-top: 6px;
            padding-left: 2px;
            display: none;
        }

        .field-error.visible {
            display: block;
            animation: fadeInUp 0.25s var(--ease-out);
        }

        /* =============================================
           FORM OPTIONS
           ============================================= */
        .form-options {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 26px;
            opacity: 0;
            animation: fadeInUp 0.55s var(--ease-out) 0.5s forwards;
        }

        .custom-check {
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            user-select: none;
        }

        .custom-check input[type="checkbox"] {
            width: 16px; height: 16px;
            border-radius: 4px;
            border: 1.5px solid rgba(255, 255, 255, 0.2);
            cursor: pointer;
            accent-color: var(--accent);
            flex-shrink: 0;
            transition: border-color 0.2s ease;
            background: rgba(255, 255, 255, 0.06);
        }

        .custom-check input[type="checkbox"]:focus-visible {
            outline: 2px solid var(--accent);
            outline-offset: 2px;
        }

        .custom-check span {
            font-size: 13px;
            color: rgba(255, 255, 255, 0.6);
        }

        .link-forgot {
            font-size: 13px;
            font-weight: 500;
            color: rgba(255, 255, 255, 0.65);
            text-decoration: none;
            transition: opacity 0.2s ease;
        }

        .link-forgot:hover {
            color: #ffffff;
            text-decoration: underline;
        }

        .link-forgot:focus-visible {
            outline: 2px solid var(--accent);
            outline-offset: 3px;
            border-radius: 2px;
        }

        /* =============================================
           LOGIN BUTTON
           ============================================= */
        .btn-login {
            width: 100%;
            height: 48px;
            background: var(--accent);
            border: none;
            border-radius: var(--radius);
            font-family: var(--font-body);
            font-weight: 600;
            font-size: 15px;
            color: #FFFFFF;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            cursor: pointer;
            transition: all 0.3s var(--ease-out);
            box-shadow: 0 1px 3px rgba(0,0,0,0.12), 0 1px 2px rgba(0,0,0,0.08);
            opacity: 0;
            animation: fadeInUp 0.55s var(--ease-out) 0.55s forwards;
            position: relative;
            overflow: hidden;
        }

        .btn-login::after {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(135deg, rgba(255,255,255,0.12) 0%, transparent 60%);
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .btn-login:hover {
            filter: brightness(0.88);
            transform: translateY(-1px);
            box-shadow: 0 6px 20px rgba(0,0,0,0.18), 0 2px 6px rgba(0,0,0,0.1);
        }

        .btn-login:hover::after { opacity: 1; }

        .btn-login:active {
            transform: translateY(0);
            filter: brightness(0.82);
            box-shadow: 0 1px 3px rgba(0,0,0,0.12);
        }

        .btn-login:focus-visible {
            outline: 2px solid var(--accent);
            outline-offset: 3px;
        }

        .btn-login:disabled {
            opacity: 0.65;
            cursor: not-allowed;
            transform: none !important;
            filter: none !important;
        }

        .btn-login .spinner-border-sm {
            width: 16px; height: 16px;
            border-width: 2px;
        }

        /* =============================================
           DIVIDER
           ============================================= */
        .form-divider {
            display: flex;
            align-items: center;
            gap: 16px;
            margin: 28px 0;
            color: rgba(255, 255, 255, 0.3);
            font-size: 11px;
            font-weight: 500;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            opacity: 0;
            animation: fadeIn 0.5s ease 0.6s forwards;
        }

        .form-divider::before,
        .form-divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: rgba(255, 255, 255, 0.1);
        }

        /* =============================================
           REGISTER LINK
           ============================================= */
        .register-link {
            text-align: center;
            opacity: 0;
            animation: fadeInUp 0.55s var(--ease-out) 0.65s forwards;
        }

        .register-link a {
            font-size: 13px;
            font-weight: 500;
            color: rgba(255, 255, 255, 0.65);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 8px 16px;
            border-radius: var(--radius);
            transition: background 0.2s ease, color 0.2s ease;
        }

        .register-link a:hover {
            background: rgba(255, 255, 255, 0.06);
            color: #ffffff;
        }

        .register-link a:focus-visible {
            outline: 2px solid var(--accent);
            outline-offset: 3px;
        }

        /* =============================================
           FOOTER
           ============================================= */
        .form-footer {
            margin-top: auto;
            padding-top: 36px;
            text-align: center;
            opacity: 0;
            animation: fadeInUp 0.55s var(--ease-out) 0.7s forwards;
        }

        .link-back {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
            font-weight: 500;
            color: rgba(255, 255, 255, 0.4);
            text-decoration: none;
            padding: 6px 12px;
            border-radius: var(--radius-sm);
            transition: color 0.2s ease, background 0.2s ease;
        }

        .link-back:hover {
            color: rgba(255, 255, 255, 0.8);
            background: rgba(255, 255, 255, 0.04);
        }

        .link-back:focus-visible {
            outline: 2px solid var(--accent);
            outline-offset: 2px;
        }

        .copyright {
            font-size: 11px;
            color: rgba(255, 255, 255, 0.25);
            margin-top: 14px;
        }

        /* =============================================
           KEYFRAME ANIMATIONS
           ============================================= */
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(18px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to   { opacity: 1; }
        }

        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-10px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        @keyframes floatGentle {
            0%, 100% { transform: translateY(0); }
            50%      { transform: translateY(-7px); }
        }

        @keyframes pulseDot {
            0%, 100% { opacity: 1; transform: scale(1); }
            50%      { opacity: 0.35; transform: scale(0.75); }
        }

        @keyframes orbDrift {
            0%, 100% { transform: translate(0, 0) scale(1); }
            33%      { transform: translate(18px, -22px) scale(1.04); }
            66%      { transform: translate(-12px, 14px) scale(0.96); }
        }

        @keyframes ringPulse {
            0%, 100% { opacity: 1; transform: scale(1); }
            50%      { opacity: 0.4; transform: scale(1.015); }
        }

        @keyframes modalSlideIn {
            from { opacity: 0; transform: translateY(24px) scale(.96); }
            to   { opacity: 1; transform: translateY(0) scale(1); }
        }

        /* =============================================
           RESPONSIVE
           ============================================= */
        @media (min-width: 1400px) {
            .brand-panel { padding: 80px 64px; }
            .brand-content h2 { font-size: 36px; }
            .form-panel-inner { padding: 64px 56px; }
        }

        @media (max-width: 1199px) {
            .form-panel { width: 460px; min-width: 400px; }
            .form-panel-inner { padding: 48px 40px; }
        }

        @media (max-width: 991px) {
            .ldb-navbar { padding: 12px 16px; }
            .nav-content { padding: 0 12px; }
            .nav-title { font-size: 1.1rem; letter-spacing: 0.8px; }
            .nav-subtitle { font-size: 0.75rem; }
            .ldb-logo { width: 40px; height: 40px; }
            .nav-scanner-icon { width: 40px; height: 40px; }

            .page-container {
                padding: 16px;
            }

            .page-card {
                border-radius: 12px;
                flex-direction: column;
            }

            .brand-panel { display: none; }

            .main-wrapper { display: block; }

            .form-panel {
                width: 100%;
                min-width: 100%;
                background: transparent;
            }

            .form-panel::before { display: none; }

            .form-panel-inner {
                max-width: 440px;
                margin: 0 auto;
                padding: 48px 28px;
                justify-content: center;
                border-radius: 12px;
            }

            .mobile-brand-header {
                display: none;
            }

            .mobile-brand-header::before {
                content: '';
                position: absolute;
                width: 300px; height: 300px;
                background: radial-gradient(circle, rgba(59,130,246,0.1) 0%, transparent 60%);
                top: -120px; right: -60px;
                pointer-events: none;
            }

            .mobile-brand-icon {
                width: 46px; height: 46px;
                background: rgba(255,255,255,0.06);
                border: 1.5px solid rgba(255,255,255,0.1);
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                flex-shrink: 0;
                position: relative;
                z-index: 1;
            }

            .mobile-brand-icon i {
                font-size: 20px;
                color: rgba(255,255,255,0.85);
            }

            .mobile-brand-text {
                position: relative;
                z-index: 1;
            }

            .mobile-brand-text h6 {
                font-family: var(--font-display);
                font-size: 19px;
                font-weight: 400;
                color: #FFFFFF;
                margin: 0;
                line-height: 1.2;
            }

            .mobile-brand-text span {
                font-size: 11px;
                color: rgba(255,255,255,0.4);
                letter-spacing: 0.04em;
            }
        }

        @media (max-width: 575px) {
            .page-container { padding: 12px; }
            .form-panel-inner { padding: 0 20px 36px; }
            .mobile-brand-header { padding: 22px 20px; }

            .nav-title { font-size: 0.8rem; }

            .login-header { margin-top: 28px; margin-bottom: 26px; }
            .login-header h4 { font-size: 26px; }
            .input-wrapper .form-control-custom { height: 50px; font-size: 15px; }
            .btn-login { height: 50px; font-size: 16px; }
            .form-options { margin-bottom: 22px; }
            .form-group { margin-bottom: 18px; }
        }

        @media (max-width: 374px) {
            .page-container { padding: 8px; }
            .page-card { border-radius: 10px; }
            .form-panel-inner { padding: 0 16px 28px; }
            .mobile-brand-header { padding: 18px 16px; }
            .login-header h4 { font-size: 23px; }
            .login-header p { font-size: 13px; }
            .brand-features { gap: 10px; }
            .form-options {
                flex-direction: column;
                align-items: flex-start;
                gap: 12px;
            }
        }

        @media (max-height: 500px) and (orientation: landscape) {
            .form-panel-inner {
                justify-content: flex-start;
                padding-top: 24px;
                padding-bottom: 24px;
            }
            .login-header { margin-top: 16px; margin-bottom: 16px; }
            .form-group { margin-bottom: 14px; }
            .form-options { margin-bottom: 16px; }
        }

        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after {
                animation-duration: 0.01ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: 0.01ms !important;
            }
            .brand-logo,
            .deco-ring,
            .brand-panel::before,
            .brand-panel::after,
            .role-badge-dot { animation: none; }
        }

        /* =============================================
           EVENT MODALS (matching forgot-password style)
           ============================================= */
        .event-modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(26, 29, 46, 0.55);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            z-index: 9998;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }

        .event-modal-overlay.show {
            display: flex;
        }

        .event-modal {
            background: rgba(10, 34, 76, 0.90) !important;
            backdrop-filter: blur(24px) saturate(1.6);
            -webkit-backdrop-filter: blur(24px) saturate(1.6);
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-radius: 20px;
            box-shadow: 0 24px 80px rgba(0, 0, 0, 0.4);
            color: #ffffff;
            width: min(480px, 100%);
            max-width: 100%;
            height: 90vh;
            max-height: 90vh;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            animation: modalSlideIn .35s cubic-bezier(.34,1.56,.64,1);
            margin: auto;
        }

        .event-modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 18px 22px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.06);
            flex-shrink: 0;
        }

        .event-modal-title {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 16px;
            font-weight: 800;
            letter-spacing: -0.02em;
            color: #ffffff;
        }

        .event-modal-title i {
            font-size: 20px;
            color: #60A5FA;
        }

        .event-modal-close {
            width: 32px; height: 32px;
            border: none;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            font-size: 13px;
            background: rgba(255, 255, 255, 0.08);
            color: rgba(255, 255, 255, 0.5);
            text-decoration: none;
        }

        .event-modal-close:hover {
            background: rgba(239, 68, 68, 0.2);
            color: #F87171;
        }

        .event-modal-body {
            padding: 22px;
            overflow-y: auto;
            flex: 1 1 auto;
            min-height: 0;
            font-size: 14px;
            line-height: 1.75;
            color: #ffffff;
        }

        .event-modal-body::-webkit-scrollbar { width: 5px; }
        .event-modal-body::-webkit-scrollbar-track { background: transparent; }
        .event-modal-body::-webkit-scrollbar-thumb { background: rgba(255, 255, 255, 0.15); border-radius: 10px; }
        .event-modal-body::-webkit-scrollbar-thumb:hover { background: rgba(255, 255, 255, 0.25); }
        .event-modal-body { scrollbar-width: thin; scrollbar-color: rgba(255, 255, 255, 0.15) transparent; }

        .event-modal-body p {
            margin-bottom: 16px;
        }

        .event-modal-body ul {
            margin: 0 0 16px 20px;
            padding: 0;
        }

        .event-modal-body li {
            margin-bottom: 8px;
        }

        .event-modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            padding: 12px 22px;
            flex-shrink: 0;
            border-top: 1px solid rgba(255, 255, 255, 0.06);
            background: rgba(255, 255, 255, 0.02);
        }

        .evt-btn {
            padding: 13px;
            border: none;
            border-radius: 10px;
            font-family: 'Poppins', sans-serif;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.25s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            letter-spacing: 0.01em;
        }

        .evt-btn-primary {
            background: rgba(0, 102, 254, 0.5);
            color: white;
            width: 100%;
            box-shadow: 0 4px 12px rgba(0, 102, 254, 0.3);
        }

        .evt-btn-primary:hover {
            background: rgba(0, 102, 254, 0.65);
            color: white;
            box-shadow: 0 6px 16px rgba(0, 102, 254, 0.4);
            transform: translateY(-1px);
        }

        .evt-btn-primary:active {
            transform: translateY(0);
            box-shadow: 0 2px 8px rgba(0, 102, 254, 0.3);
        }

        .custom-check a {
            color: #60a5fa;
            text-decoration: underline;
            text-underline-offset: 2px;
            transition: color 0.2s ease;
        }

        .custom-check a:hover {
            color: #ffffff;
        }
    </style>
</head>
<body>

    <?php include 'includes/navbar.php'; ?>
    <!-- =============================================
         PAGE CONTAINER
         ============================================= -->
    <div class="page-container">
        <div class="page-card">

            <!-- =============================================
                 MAIN WRAPPER (Brand Panel + Form Panel)
                 ============================================= -->
            <div class="main-wrapper">

                <!-- BRAND PANEL (Desktop only) -->
                <div class="brand-panel" aria-hidden="true">
                    <div class="deco-ring deco-ring--1"></div>
                    <div class="deco-ring deco-ring--2"></div>
                    <div class="deco-ring deco-ring--3"></div>
                    <div class="deco-line-v"></div>

                    <div class="brand-content">
                        <div class="brand-logo">
                            <i class="bi bi-mortarboard-fill"></i>
                        </div>
                        <h2>Liceo de Baleno</h2>
                        <p class="brand-tagline">Facial Recognition Attendance System &mdash; secure, fast, and reliable contactless attendance for your school.</p>

                        <div class="brand-divider"></div>
                    </div>
                </div>

                <!-- FORM PANEL -->
                <div class="form-panel">

                    <!-- Mobile Brand Header -->
                    <div class="mobile-brand-header">
                        <div class="mobile-brand-icon">
                            <i class="bi bi-mortarboard-fill"></i>
                        </div>
                        <div class="mobile-brand-text">
                            <h6>Liceo de Baleno</h6>
                            <span>Attendance System</span>
                        </div>
                    </div>

                    <div class="form-panel-inner">

                        <div class="login-header">
                            <div class="role-badge">
                                <span class="role-badge-dot"></span>
                                <i class="bi <?= $roleIcon ?>"></i>
                                <?= $roleLabel ?>
                            </div>
                            <h4>Welcome back</h4>
                            <p>Enter your credentials to access the dashboard</p>
                        </div>

                        <?php if ($error): ?>
                            <div class="alert-custom" role="alert">
                                <i class="bi bi-exclamation-circle-fill"></i>
                                <span><?= $error ?></span>
                            </div>
                        <?php endif; ?>

                        <form method="POST" action="" id="loginForm" novalidate>
                            <div class="form-group">
                                <label class="form-label-custom" for="emailInput">Email Address</label>
                                <div class="input-wrapper">
                                    <i class="bi bi-envelope input-icon"></i>
                                    <input type="email"
                                           class="form-control-custom"
                                           name="email"
                                           id="emailInput"
                                           placeholder="Enter your email"
                                           required
                                           value="<?= sanitize($_POST['email'] ?? '') ?>"
                                           autocomplete="email">
                                </div>
                                <div class="field-error" id="emailError" aria-live="polite"></div>
                            </div>

                            <div class="form-group">
                                <label class="form-label-custom" for="passwordInput">Password</label>
                                <div class="input-wrapper">
                                    <i class="bi bi-lock input-icon"></i>
                                    <input type="password"
                                           class="form-control-custom"
                                           name="password"
                                           id="passwordInput"
                                           placeholder="Enter your password"
                                           required
                                           autocomplete="current-password">
                                    <button type="button"
                                            class="toggle-password"
                                            id="togglePassword"
                                            tabindex="-1"
                                            aria-label="Toggle password visibility">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                                <div class="field-error" id="passwordError" aria-live="polite"></div>
                            </div>

                            <div class="form-options">
                                <div style="display:flex; flex-direction:column; gap:10px;">
                                    <label class="custom-check">
                                        <input type="checkbox"
                                               name="agreement"
                                               id="agreementCheck"
                                               required>
                                        <span>I agree to the <a href="#" class="terms-link" id="termsLink">Terms and Conditions</a> and <a href="#" class="privacy-link" id="privacyLink">Data Privacy</a> of LDB-FRAS</span>
                                    </label>
                                </div>
                            </div>
                            <div class="agreement-error" id="agreementError" style="display:none; color: #FCA5A5; font-size: 12px; margin-top: -16px; margin-bottom: 16px;">
                                You must agree to the Terms and Conditions and Data Privacy to continue.
                            </div>

                            <button type="submit" class="btn-login" id="loginBtn">
                                <i class="bi bi-box-arrow-in-right"></i>
                                Sign In
                            </button>

                            <div style="text-align:center; margin-top: 18px; opacity: 0; animation: fadeInUp 0.55s var(--ease-out) 0.6s forwards;">
                                <a href="<?= BASE_URL ?>/forgot-password.php?role=<?= $role ?>" class="link-forgot">Forgot Password?</a>
                            </div>
                        </form>

                        <?php if ($role === 'teacher'): ?>
                            <div class="form-divider">or</div>
                            <div class="register-link">
                                <a href="<?= BASE_URL ?>/register.php">
                                    <i class="bi bi-person-plus"></i> New Teacher? Register here
                                </a>
                            </div>
                        <?php endif; ?>

                        <div class="form-footer">
                            <a href="<?= BASE_URL ?>/" class="link-back">
                                <i class="bi bi-arrow-left"></i> Back to Home
                            </a>
                            <p class="copyright">&copy; <?= APP_YEAR ?> Liceo de Baleno</p>
                        </div>

                    </div>
                </div>

            </div>

        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
        document.getElementById('togglePassword').addEventListener('click', function () {
            const input = document.getElementById('passwordInput');
            const icon  = this.querySelector('i');
            if (input.type === 'password') {
                input.type = 'text';
                icon.className = 'bi bi-eye-slash';
                this.setAttribute('aria-label', 'Hide password');
            } else {
                input.type = 'password';
                icon.className = 'bi bi-eye';
                this.setAttribute('aria-label', 'Show password');
            }
        });

        function openModal(id) {
            const modal = document.getElementById(id);
            modal.classList.add('show');
            document.body.style.overflow = 'hidden';
        }

        function closeModal(id) {
            const modal = document.getElementById(id);
            modal.classList.remove('show');
            document.body.style.overflow = '';
        }

        document.getElementById('termsLink').addEventListener('click', function (e) {
            e.preventDefault();
            openModal('termsModal');
        });

        document.getElementById('privacyLink').addEventListener('click', function (e) {
            e.preventDefault();
            openModal('privacyModal');
        });

        document.querySelectorAll('[data-close-modal]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                closeModal(this.getAttribute('data-close-modal'));
            });
        });

        document.querySelectorAll('.event-modal-overlay').forEach(function (overlay) {
            overlay.addEventListener('click', function (e) {
                if (e.target === overlay) {
                    closeModal(overlay.id);
                }
            });
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                document.querySelectorAll('.event-modal-overlay.show').forEach(function (modal) {
                    closeModal(modal.id);
                });
            }
        });

        document.getElementById('loginForm').addEventListener('submit', function (e) {
            const email         = document.getElementById('emailInput');
            const password      = document.getElementById('passwordInput');
            const emailError    = document.getElementById('emailError');
            const passwordError = document.getElementById('passwordError');
            const agreementCheck  = document.getElementById('agreementCheck');
            const agreementError = document.getElementById('agreementError');
            let valid = true;

            email.classList.remove('is-invalid');
            password.classList.remove('is-invalid');
            emailError.classList.remove('visible');
            passwordError.classList.remove('visible');
            agreementError.style.display = 'none';

            if (!email.value.trim()) {
                email.classList.add('is-invalid');
                emailError.textContent = 'Email is required';
                emailError.classList.add('visible');
                valid = false;
            } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.value)) {
                email.classList.add('is-invalid');
                emailError.textContent = 'Enter a valid email address';
                emailError.classList.add('visible');
                valid = false;
            }

            if (!password.value) {
                password.classList.add('is-invalid');
                passwordError.textContent = 'Password is required';
                passwordError.classList.add('visible');
                valid = false;
            }

            if (!agreementCheck.checked) {
                agreementError.style.display = 'block';
                valid = false;
            }

            if (!valid) {
                e.preventDefault();
                const firstInvalid = this.querySelector('.is-invalid');
                if (firstInvalid) firstInvalid.focus();
            } else {
                const btn = document.getElementById('loginBtn');
                btn.disabled = true;
                btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status"></span> Signing in\u2026';
            }
        });
    });
    </script>

    <!-- Terms and Conditions Modal -->
    <div class="event-modal-overlay" id="termsModal" role="dialog" aria-modal="true" aria-labelledby="termsModalLabel">
        <div class="event-modal">
            <div class="event-modal-header">
                <div class="event-modal-title" id="termsModalLabel">Terms and Conditions</div>
                <button type="button" class="event-modal-close" data-close-modal="termsModal" aria-label="Close modal"><i class="bi bi-x-lg"></i></button>
            </div>
            <div class="event-modal-body">
                <p><strong>1. Acceptance of Terms & System Purpose:</strong> By accessing or using the Liceo de Baleno Facial Recognition Attendance System (LDB-FRAS), whether through an administrative account, teacher portal, or automated gate terminal, you agree to be legally bound by these Terms and Conditions. This software is designed exclusively for academic schedule validation, institutional logistics, and school perimeter security management.</p>
                
                <p><strong>2. Account Security & Responsibilities:</strong> Users (Admins, Faculty, and Staff) are strictly responsible for maintaining the confidentiality of their portal access credentials. You agree to:</p>
                <ul>
                    <li>Never share login passwords, active session cookies, or device tokens with unauthorized personnel.</li>
                    <li>Log out immediately after using a public shared terminal (e.g., classroom desktop or gate kiosk monitoring screen).</li>
                    <li>Notify the IT System Administrator instantly if you suspect any unauthorized access to your account environment.</li>
                </ul>
                
                <p><strong>3. Prohibited Bypasses & Anti-Spoofing Policy:</strong> To maintain the absolute accuracy and integrity of attendance logs, users and students are strictly prohibited from attempting to manipulate or bypass the biometric recognition process. Prohibited behaviors include, but are not limited to:</p>
                <ul>
                    <li>Presenting high-resolution digital screens, static photographs, or physical printouts of a student's face to gate cameras to simulate presence.</li>
                    <li>Utilizing digital masks, physical prosthetics, or clothing configurations designed maliciously to trigger false positives or exploit the 0.6 model tolerance limit.</li>
                    <li>Intentionally tampering with webcam connections, network cabling, or the local background Flask API execution scripts.</li>
                </ul>
                <p><strong>Penalty:</strong> Any verified attempt to spoof the system will be automatically treated as an institutional disciplinary infraction and handled under the official Student/Employee Code of Conduct.</p>
                
                <p><strong>4. Software Proprietary Rights & Copyrights:</strong> The custom user interface elements, glassmorphism design layouts, database structural schemas, and system-specific integration code supporting the LDB-FRAS ecosystem remain the exclusive intellectual property of Liceo de Baleno. Unauthorized copying, reverse-engineering, duplication, or redistribution of the system's PHP modules or Python machine learning architecture is strictly forbidden.</p>
                
                <p><strong>5. System Availability & Service Liability Disclaimer:</strong> Liceo de Baleno strives to ensure optimal runtime execution during core operational hours. However, the system is provided on an "as-is" and "as-available" basis. The technical development team and school administration hold no legal liability for data synchronization delays, network connection timeouts, or brief device offline states caused by:</p>
                <ul>
                    <li>Local infrastructure power outages or voltage fluctuations at physical gate checkpoints.</li>
                    <li>XAMPP server database optimization locks during heavy morning arrival rushes.</li>
                    <li>Sudden ambient lighting shifts at gate terminals that temporarily interfere with OpenCV's real-time object bounding box isolation.</li>
                </ul>
                
                <p><strong>6. Automated Action Notification Approvals:</strong> By maintaining an active student enrollment status in the system, parents and guardians acknowledge that automated administrative workflows are authorized to trigger background communication events. This includes dispatching automated cellular SMS alerts or structural email notifications regarding real-time arrival timestamps, afternoon dismissal logs, or consecutive absence system warnings.</p>
                
                <p><strong>7. Policy Amendments & Updates:</strong> The school administration retains the complete right to modify, amend, or adjust these system operational guidelines at any time to accommodate software version updates or national security regulations. Continued utilization of the portal or automated gate scanning hardware following a recorded adjustment constitutes explicit acceptance of the newly revised terms.</p>
            </div>
            <div class="event-modal-footer">
                <button type="button" class="evt-btn evt-btn-primary" data-close-modal="termsModal">I Understand</button>
            </div>
        </div>
    </div>

    <!-- Data Privacy Notice Modal -->
    <div class="event-modal-overlay" id="privacyModal" role="dialog" aria-modal="true" aria-labelledby="privacyModalLabel">
        <div class="event-modal">
            <div class="event-modal-header">
                <div class="event-modal-title" id="privacyModalLabel">Data Privacy Notice</div>
                <button type="button" class="event-modal-close" data-close-modal="privacyModal" aria-label="Close modal"><i class="bi bi-x-lg"></i></button>
            </div>
            <div class="event-modal-body">
                <p><strong>1. Introduction & Commitment:</strong> Liceo de Baleno is highly committed to protecting the personal and biometric data of its students, faculty, and stakeholders. This Privacy Notice explains how our Automated Facial Recognition Attendance System (LDB-FRAS) collects, processes, stores, and safeguards your data in absolute compliance with the Philippine Data Privacy Act of 2012 (RA 10173).</p>
                
                <p><strong>2. Scope of Data Collection:</strong> To facilitate automated gate tracking and classroom attendance monitoring, the system securely collects and processes the following information:</p>
                <ul>
                    <li><strong>Students:</strong> Full Name, Learner Reference Number (LRN), Grade Level, Section, and three (3) distinct facial biometric photographs (Frontal, Left Profile, Right Profile).</li>
                    <li><strong>Parents/Guardians:</strong> Full Name, Active Contact Number, and Email Address (exclusively for automated emergency or attendance notifications).</li>
                    <li><strong>Faculty & Staff:</strong> Full Name, Employee ID, Department, and System Access Role (Admin, Teacher, Gate Guard).</li>
                </ul>
                
                <p><strong>3. Purpose of Processing:</strong> The data gathered by LDB-FRAS is used solely for institutional purposes, which include:</p>
                <ul>
                    <li>Verifying identity at school entry and exit checkpoints via the Gate Kiosks.</li>
                    <li>Automating daily classroom attendance recording for subject teachers.</li>
                    <li>Sending automated SMS or email alerts to parents/guardians regarding student arrivals or consecutive absences.</li>
                    <li>Generating real-time statistical attendance analytics for administrative monitoring.</li>
                </ul>
                
                <p><strong>4. One-Shot Learning & Biometric Processing:</strong> We do not raw-store video feeds or continuous recordings. The system utilizes the FaceNet512 machine learning framework to extract landmarks from the three captured enrollment photographs. These landmarks are instantly converted into a localized, 512-dimensional mathematical vector (numerical matrix). The system matches live camera inputs against these mathematical vectors to confirm identities at a 0.6 tolerance threshold, ensuring biological security without saving raw facial photos in the scanning logs.</p>
                
                <p><strong>5. Data Retention Policy:</strong> Liceo de Baleno maintains strict data lifecycle controls. Biometric vectors and personal registration profiles are strictly retained for the duration of the current academic school year. At the end of the academic year, or upon a student's formal honorable dismissal/transfer, all associated biometric profiles and multi-angle photographs are permanently purged from the local XAMPP host server database. Historical numerical gate attendance logs are archived securely for institutional auditing before being scheduled for permanent deletion.</p>
                
                <p><strong>6. Security Safeguards:</strong> Your data is securely locked within a localized network environment. The system employs strict role-based access controls (RBAC), meaning Gate Guards can only see basic identity verification prompts, and Teachers can only access attendance metrics for their assigned classes. The backend server relies on secure prepared SQL statement parameters to prevent unauthorized cross-site access, and all data transfers between the PHP dashboard and the Python machine learning microservice are fully sandboxed.</p>
                
                <p><strong>7. Third-Party Disclosure:</strong> Liceo de Baleno does not lease, sell, or share biometric records or student profiles with third-party advertising companies, private commercial entities, or external agencies. Data is only shared with legal entities if explicitly required by law enforcement, statutory mandates, or under formal department orders from the Department of Education (DepEd).</p>
                
                <p><strong>8. Data Subject Rights:</strong> Under RA 10173, registered students (through their legal parents/guardians) and faculty members retain full rights as data subjects. You have the explicit right to:</p>
                <ul>
                    <li>Inspect, review, or request a digital copy of your recorded attendance logs.</li>
                    <li>Request immediate correction or updating of inaccurate contact information or profile data.</li>
                    <li>Objects to processing or request the complete removal of biometric profiles (which will require returning to manual barcode/logbook attendance recording).</li>
                </ul>
                
                <p><strong>9. Contact and Institutional Inquiries:</strong> For any concerns, clarifications, or requests regarding your personal information, biometric tokens, or system records, please contact the Liceo de Baleno Compliance Team directly. <strong>Data Protection Officer (DPO):</strong> dpo@liceodebaleno.edu.ph</p>
            </div>
            <div class="event-modal-footer">
                <button type="button" class="evt-btn evt-btn-primary" data-close-modal="privacyModal">I Understand</button>
            </div>
        </div>
    </div>

</body>
</html>