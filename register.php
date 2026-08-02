<?php

require_once __DIR__ . '/config.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teacher Registration — <?= APP_FULL_NAME ?></title>
    <link href="<?= BASE_URL ?>/assets/vendor/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?= BASE_URL ?>/assets/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
    <link rel="icon" type="image/png" href="assets/images/icon.png">
    <link href="<?= BASE_URL ?>/assets/vendor/fonts/fonts.css" rel="stylesheet">

    <script src="<?= BASE_URL ?>/assets/vendor/js/tailwind.js"></script>
    <script>
        tailwind.config = {
            corePlugins: { preflight: false }
        }
    </script>
    <style>
        /* =============================================
           ROOT & VARIABLES
           ============================================= */
        :root {
            --ink: #0B1426;
            --ink-deep: #070E1A;
            --ink-surface: #152238;
            --navy: #0A224C;
            --panel-bg: #FAF8F5;
            --panel-bg-end: #F5F2ED;
            --white: #FFFFFF;
            --accent: #0066FE;
            --text-heading: #111827;
            --text-body: #374151;
            --text-muted: #6B7280;
            --text-faint: #9CA3AF;
            --border: #E5E7EB;
            --border-hover: #D1D5DB;
            --success: #10B981;
            --success-bg: rgba(16,185,129,0.06);
            --success-text: #065F46;
            --danger: #EF4444;
            --danger-bg: rgba(239,68,68,0.06);
            --danger-text: #991B1B;
            --warning: #F59E0B;
            --warning-bg: rgba(245,158,11,0.07);
            --warning-text: #92400E;
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
        }

        /* =============================================
           RESET & BASE
           ============================================= */
        *, *::before, *::after {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            position: relative;
            font-family: 'Poppins', sans-serif;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            color: var(--gray-800);
            -webkit-font-smoothing: antialiased;
            background: transparent;
        }
        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: #0f172a url('assets/images/background.png') center center / cover no-repeat fixed;
            filter: blur(8px);
            -webkit-filter: blur(8px);
            z-index: -1;
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
            max-width: 100%;
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
           PAGE CONTAINER — transparent so backdrop reads
           through to the body background image
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
            max-width: 1160px;
            background: transparent;
            border-radius: 16px;
            overflow: hidden;
            display: flex;
        }

        /* =============================================
           MAIN WRAPPER
           ============================================= */
        .main-wrapper {
            flex: 1;
            display: flex;
        }

        /* =============================================
           BRAND PANEL (LEFT) — lighter navy
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
            width: 520px;
            height: 520px;
            top: -140px;
            right: -100px;
            animation: ringPulse 18s ease-in-out infinite;
        }

        .deco-ring--2 {
            width: 360px;
            height: 360px;
            bottom: -70px;
            left: -70px;
            animation: ringPulse 22s ease-in-out 3s infinite;
        }

        .deco-ring--3 {
            width: 180px;
            height: 180px;
            top: 48%;
            left: 48%;
            opacity: 0.4;
            animation: ringPulse 15s ease-in-out 1s infinite;
        }

        .deco-line-v {
            position: absolute;
            left: 15%;
            top: 10%;
            bottom: 10%;
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

        .brand-steps {
            display: flex;
            flex-direction: column;
            gap: 16px;
            text-align: left;
            max-width: 310px;
            margin: 0 auto;
        }

        .step-item {
            display: flex;
            gap: 14px;
            color: rgba(255,255,255,0.45);
            font-size: 13px;
            line-height: 1.5;
            transition: color 0.3s ease;
        }

        .step-item:hover {
            color: rgba(255,255,255,0.7);
        }

        .step-num {
            width: 26px;
            height: 26px;
            border: 1.5px solid rgba(255,255,255,0.12);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 11px;
            font-weight: 600;
            color: rgba(255,255,255,0.5);
            flex-shrink: 0;
        }

        .step-item strong {
            color: rgba(255,255,255,0.65);
            font-weight: 600;
        }

        /* =============================================
           FORM PANEL — transparent so backdrop-filter
           on form-panel-inner can blur the body bg
           ============================================= */
        .form-panel {
            width: 560px;
            min-width: 420px;
            background: transparent;
            backdrop-filter: none;
            -webkit-backdrop-filter: none;
            display: flex;
            flex-direction: column;
            position: relative;
            overflow-y: auto;
        }

        .form-panel::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 1px;
            background: linear-gradient(180deg, transparent 5%, rgba(255,255,255,0.08) 30%, rgba(255,255,255,0.08) 70%, transparent 95%);
            z-index: 1;
        }

        /* =============================================
           MOBILE BRAND HEADER
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
            padding: 48px 48px 40px;
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
           REGISTER HEADER
           ============================================= */
        .register-header {
            margin-bottom: 24px;
        }

        .register-header-icon {
            width: 56px;
            height: 56px;
            background: rgba(255,255,255,0.08);
            border: 1.5px solid rgba(255,255,255,0.12);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 18px;
            opacity: 0;
            animation: fadeInUp 0.55s var(--ease-out) 0.1s forwards;
        }

        .register-header-icon i {
            font-size: 24px;
            color: rgba(255,255,255,0.8);
        }

        .register-header h4 {
            font-family: var(--font-display);
            font-size: 28px;
            font-weight: 400;
            color: #ffffff;
            margin-bottom: 6px;
            letter-spacing: -0.01em;
            line-height: 1.2;
            opacity: 0;
            animation: fadeInUp 0.55s var(--ease-out) 0.18s forwards;
        }

        .register-header p {
            color: rgba(255, 255, 255, 0.5);
            font-size: 14px;
            margin: 0;
            opacity: 0;
            animation: fadeInUp 0.55s var(--ease-out) 0.24s forwards;
        }

        /* =============================================
           INFO BOX
           ============================================= */
        .info-box {
            background: rgba(245, 158, 11, 0.12);
            border-left: 3px solid var(--warning);
            border-radius: var(--radius);
            padding: 14px 16px;
            font-size: 13px;
            line-height: 1.6;
            color: #FCD34D;
            display: flex;
            align-items: flex-start;
            gap: 12px;
            margin-bottom: 24px;
            opacity: 0;
            animation: fadeInUp 0.55s var(--ease-out) 0.3s forwards;
        }

        .info-box i {
            font-size: 16px;
            flex-shrink: 0;
            margin-top: 2px;
            color: var(--warning);
        }

        .info-box strong {
            font-weight: 600;
            color: #FDE68A;
        }

        /* =============================================
           ALERT
           ============================================= */
        .alert-area {
            min-height: 0;
        }

        .alert-custom {
            border: none;
            border-radius: var(--radius);
            padding: 14px 16px;
            margin-bottom: 20px;
            display: flex;
            align-items: flex-start;
            gap: 12px;
            font-size: 13.5px;
            line-height: 1.55;
            animation: slideDown 0.4s var(--ease-out);
        }

        .alert-custom i {
            font-size: 16px;
            flex-shrink: 0;
            margin-top: 1px;
        }

        .alert-danger {
            background: rgba(239, 68, 68, 0.12);
            border-left: 3px solid var(--danger);
            color: #FCA5A5;
        }

        .alert-danger i {
            color: var(--danger);
        }

        .alert-success {
            background: rgba(16, 185, 129, 0.12);
            border-left: 3px solid var(--success);
            color: #6EE7B7;
        }

        .alert-success i {
            color: var(--success);
        }

        /* =============================================
           FORM ELEMENTS
           ============================================= */
        .form-group {
            margin-bottom: 20px;
            opacity: 0;
            animation: fadeInUp 0.55s var(--ease-out) forwards;
        }

        .form-group:nth-of-type(1) { animation-delay: 0.34s; }
        .form-group:nth-of-type(2) { animation-delay: 0.42s; }
        .form-group:nth-of-type(3) { animation-delay: 0.50s; }

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
            box-shadow: 0 0 0 3px rgba(255, 255, 255, 0.04), 0 0 0 3px rgba(0,102,254,0.3);
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

        .toggle-password:hover {
            color: rgba(255, 255, 255, 0.7);
        }

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
           PASSWORD STRENGTH METER
           ============================================= */
        .strength-meter {
            margin-top: 10px;
        }

        .strength-track {
            height: 3.5px;
            border-radius: 2px;
            background: rgba(255, 255, 255, 0.08);
            overflow: hidden;
        }

        .strength-fill {
            height: 100%;
            border-radius: 2px;
            width: 0%;
            transition: width 0.35s var(--ease-out), background 0.35s ease;
        }

        .strength-label {
            font-size: 11.5px;
            margin-top: 6px;
            color: rgba(255, 255, 255, 0.35);
            transition: color 0.25s ease;
        }

        /* =============================================
           CHECKBOX
           ============================================= */
        .custom-check-group {
            opacity: 0;
            animation: fadeInUp 0.55s var(--ease-out) 0.56s forwards;
            margin-bottom: 24px;
        }

        .custom-check {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            cursor: pointer;
            user-select: none;
        }

        .custom-check input[type="checkbox"] {
            width: 16px;
            height: 16px;
            border-radius: 4px;
            border: 1.5px solid rgba(255, 255, 255, 0.2);
            cursor: pointer;
            accent-color: var(--accent);
            flex-shrink: 0;
            margin-top: 1px;
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
            line-height: 1.5;
        }

        /* =============================================
           SUBMIT BUTTON
           ============================================= */
        .btn-register {
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
            animation: fadeInUp 0.55s var(--ease-out) 0.6s forwards;
            position: relative;
            overflow: hidden;
        }

        .btn-register::after {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(135deg, rgba(255,255,255,0.12) 0%, transparent 60%);
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .btn-register:hover {
            filter: brightness(0.88);
            transform: translateY(-1px);
            box-shadow: 0 6px 20px rgba(0,102,254,0.25), 0 2px 6px rgba(0,0,0,0.1);
            color: #fff;
        }

        .btn-register:hover::after {
            opacity: 1;
        }

        .btn-register:active {
            transform: translateY(0);
            filter: brightness(0.82);
            box-shadow: 0 1px 3px rgba(0,0,0,0.12);
        }

        .btn-register:focus-visible {
            outline: 2px solid var(--accent);
            outline-offset: 3px;
        }

        .btn-register:disabled {
            opacity: 0.55;
            cursor: not-allowed;
            transform: none !important;
            filter: none !important;
        }

        .btn-register .spinner-border-sm {
            width: 16px;
            height: 16px;
            border-width: 2px;
        }

        /* =============================================
           FOOTER LINKS
           ============================================= */
        .form-footer-links {
            text-align: center;
            margin-top: 28px;
            opacity: 0;
            animation: fadeInUp 0.55s var(--ease-out) 0.66s forwards;
        }

        .link-signin {
            font-size: 13px;
            font-weight: 500;
            color: rgba(255, 255, 255, 0.5);
            text-decoration: none;
            transition: color 0.2s ease;
        }

        .link-signin strong {
            color: rgba(255, 255, 255, 0.85);
            font-weight: 600;
        }

        .link-signin:hover {
            color: #ffffff;
        }

        .link-signin:focus-visible {
            outline: 2px solid var(--accent);
            outline-offset: 3px;
            border-radius: 2px;
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
            margin-top: 12px;
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
            from {
                opacity: 0;
                transform: translateY(18px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes floatGentle {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-7px); }
        }

        @keyframes orbDrift {
            0%, 100% { transform: translate(0, 0) scale(1); }
            33% { transform: translate(18px, -22px) scale(1.04); }
            66% { transform: translate(-12px, 14px) scale(0.96); }
        }

        @keyframes ringPulse {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.4; transform: scale(1.015); }
        }

        /* =============================================
           RESPONSIVE
           ============================================= */
        @media (min-width: 1400px) {
            .brand-panel {
                padding: 80px 64px;
            }
            .brand-content h2 {
                font-size: 36px;
            }
            .form-panel-inner {
                padding: 56px 56px 48px;
            }
        }

        @media (max-width: 1199px) {
            .form-panel {
                width: 500px;
                min-width: 400px;
            }
            .form-panel-inner {
                padding: 40px 40px 36px;
            }
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

            .brand-panel {
                display: none;
            }

            .main-wrapper {
                display: block;
            }

            .form-panel {
                width: 100%;
                min-width: 100%;
                background: transparent;
                backdrop-filter: none;
                -webkit-backdrop-filter: none;
                filter: none;
                -webkit-filter: none;
            }

            .form-panel::before {
                display: none;
            }

            .form-panel-inner {
                max-width: 480px;
                margin: 0 auto;
                padding: 48px 28px;
                justify-content: flex-start;
                border-radius: 12px;
                background: rgba(10, 34, 76, 0.50);
                backdrop-filter: blur(24px) saturate(1.6);
                -webkit-backdrop-filter: blur(24px) saturate(1.6);
                border: 1px solid rgba(255, 255, 255, 0.12);
                box-shadow:
                    0 8px 32px rgba(0, 0, 0, 0.2),
                    inset 0 1px 0 rgba(255, 255, 255, 0.08);
            }

            .mobile-brand-header {
                display: none;
            }

            .mobile-brand-header::before {
                content: '';
                position: absolute;
                width: 300px;
                height: 300px;
                background: radial-gradient(circle, rgba(80,160,255,0.12) 0%, transparent 60%);
                top: -120px;
                right: -60px;
                pointer-events: none;
            }

            .mobile-brand-icon {
                width: 46px;
                height: 46px;
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
            .form-panel-inner {
                padding: 0 20px 36px;
            }

            .mobile-brand-header {
                padding: 22px 20px;
            }

            .nav-title { font-size: 0.8rem; }

            .register-header {
                margin-top: 28px;
                margin-bottom: 20px;
            }

            .register-header h4 {
                font-size: 24px;
            }

            .input-wrapper .form-control-custom {
                height: 50px;
                font-size: 15px;
            }

            .btn-register {
                height: 50px;
                font-size: 16px;
            }

            .form-group {
                margin-bottom: 16px;
            }

            .info-box {
                font-size: 12.5px;
                padding: 12px 14px;
            }

            .custom-check-group {
                margin-bottom: 20px;
            }
        }

        @media (max-width: 374px) {
            .page-container { padding: 8px; }
            .page-card { border-radius: 10px; }
            .form-panel-inner {
                padding: 0 16px 28px;
            }

            .mobile-brand-header {
                padding: 18px 16px;
            }

            .register-header h4 {
                font-size: 22px;
            }

            .register-header p {
                font-size: 13px;
            }
        }

        @media (max-height: 700px) and (max-width: 991px) {
            .form-panel-inner {
                padding-top: 24px;
                padding-bottom: 24px;
            }

            .register-header {
                margin-top: 20px;
                margin-bottom: 16px;
            }

            .form-group {
                margin-bottom: 14px;
            }
        }

        /* =============================================
           REDUCED MOTION
           ============================================= */
        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after {
                animation-duration: 0.01ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: 0.01ms !important;
            }

            .brand-logo,
            .deco-ring,
            .brand-panel::before,
            .brand-panel::after {
                animation: none;
            }
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

        @keyframes modalSlideIn {
            from { opacity: 0; transform: translateY(24px) scale(.96); }
            to { opacity: 1; transform: translateY(0) scale(1); }
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

            <div class="main-wrapper">

                <!-- BRAND PANEL (Desktop only) -->
                <div class="brand-panel" aria-hidden="true">
                    <div class="deco-ring deco-ring--1"></div>
                    <div class="deco-ring deco-ring--2"></div>
                    <div class="deco-ring deco-ring--3"></div>
                    <div class="deco-line-v"></div>

                    <div class="brand-content">
                        <div class="brand-logo">
                            <i class="bi bi-person-badge-fill"></i>
                        </div>
                        <h2>Liceo de Baleno</h2>
                        <p class="brand-tagline">Join the school's attendance system in just a few steps.</p>

                        <div class="brand-divider"></div>

                        <div class="brand-steps">
                            <div class="step-item">
                                <span class="step-num">1</span>
                                <div><strong>Verify</strong> your email with the school administrator</div>
                            </div>
                            <div class="step-item">
                                <span class="step-num">2</span>
                                <div><strong>Create</strong> your secure account here</div>
                            </div>
                            <div class="step-item">
                                <span class="step-num">3</span>
                                <div><strong>Sign in</strong> and start managing attendance</div>
                            </div>
                        </div>
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

                        <!-- Header -->
                        <div class="register-header">
                            <div class="register-header-icon">
                                <i class="bi bi-person-badge"></i>
                            </div>
                            <h4>Create Account</h4>
                            <p>Register as a teacher to access the system</p>
                        </div>

                        <!-- Info Box -->
                        <div class="info-box">
                            <i class="bi bi-info-circle-fill"></i>
                            <div>
                                <strong>Important:</strong> Your email must match the one registered by the admin.
                                If you're not in the system yet, please contact the school administrator.
                            </div>
                        </div>

                        <!-- Alert Area -->
                        <div class="alert-area" id="alertArea"></div>

                        <!-- Registration Form -->
                        <form id="registerForm" novalidate>
                            <input type="hidden" id="regEmail" name="email">

                            <!-- STEP 1: Identity Check -->
                            <div id="stepIdentity">
                                <div class="form-group">
                                    <label class="form-label-custom" for="regIdentifier">Email</label>
                                    <div class="input-wrapper">
                                        <i class="bi bi-person-check input-icon"></i>
                                        <input type="text"
                                               class="form-control-custom"
                                               id="regIdentifier"
                                               placeholder="Enter your email"
                                               required
                                               autocomplete="off">
                                    </div>
                                    <div class="field-error" id="identifierError" aria-live="polite"></div>
                                </div>

                                <button type="button" class="btn-register" id="verifyBtn">
                                    <i class="bi bi-shield-check"></i> Verify Identity
                                </button>

                                <div class="alert-area mt-3" id="verifyAlertArea" style="min-height:0;"></div>
                            </div>

                            <!-- STEP 2: Consent & Password (Hidden initially) -->
                            <div id="stepRegistration" style="display:none;">
                                <div class="info-box" style="background:rgba(16,185,129,0.12);border-left:3px solid var(--success);color:#6EE7B7;padding:14px 16px;border-radius:10px;display:flex;align-items:center;gap:12px;margin-bottom:20px;" id="verifySuccessMsg">
                                    <i class="bi bi-check-circle-fill" style="color:var(--success);flex-shrink:0;"></i>
                                    <span id="verifyMsgText"></span>
                                </div>
                                

                                <div id="passwordWrapper">
                                    <div class="form-group">
                                        <label class="form-label-custom" for="regPassword">Create Password</label>
                                        <div class="input-wrapper">
                                            <i class="bi bi-lock input-icon"></i>
                                            <input type="password"
                                                    class="form-control-custom"
                                                    id="regPassword"
                                                    placeholder="Min. 8 characters"
                                                    required
                                                    minlength="8"
                                                    autocomplete="new-password">
                                            <button type="button"
                                                    class="toggle-password"
                                                    id="togglePassword"
                                                    tabindex="-1"
                                                    aria-label="Toggle password visibility">
                                                <i class="bi bi-eye"></i>
                                            </button>
                                        </div>
                                        <div class="strength-meter">
                                            <div class="strength-track">
                                                <div class="strength-fill" id="strengthBar"></div>
                                            </div>
                                            <div class="strength-label" id="strengthText">
                                                Use 8+ characters with uppercase, lowercase, numbers &amp; symbols
                                            </div>
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label-custom" for="regConfirmPassword">Confirm Password</label>
                                        <div class="input-wrapper">
                                            <i class="bi bi-lock-fill input-icon"></i>
                                            <input type="password"
                                                    class="form-control-custom"
                                                    id="regConfirmPassword"
                                                    placeholder="Repeat password"
                                                    required
                                                    autocomplete="new-password">
                                        </div>
                                        <div class="field-error" id="matchError" aria-live="polite"></div>
                                    </div>
                                </div>

                                <div class="custom-check-group" style="margin-bottom:16px;">
                                    <label class="custom-check">
                                        <input type="checkbox" id="agreePrivacy">
                                        <span>I agree to the <a href="#" id="termsLink">Terms and Conditions</a> and <a href="#" id="privacyLink">Data Privacy</a> of LDB-FRAS</span>
                                    </label>
                                </div>

                                <button type="submit" class="btn-register" id="registerBtn" style="margin-top:8px;">
                                    <i class="bi bi-person-plus"></i> Complete Registration
                                </button>

                                <a href="javascript:void(0);" id="backToStep1" class="link-back" style="margin-top:16px;display:inline-flex;">
                                    <i class="bi bi-arrow-left"></i> Change Identity
                                </a>
                            </div>
                        </form>

                        <!-- Footer Links -->
                        <div class="form-footer-links">
                            <div>
                                <a href="<?= BASE_URL ?>/login.php?role=teacher" class="link-signin">
                                    Already have an account? <strong>Sign In</strong>
                                </a>
                            </div>
                            <div>
                                <a href="<?= BASE_URL ?>/" class="link-back">
                                    <i class="bi bi-arrow-left"></i> Back to Home
                                </a>
                            </div>
                            <p class="copyright">&copy; <?= APP_YEAR ?> Liceo de Baleno</p>
                        </div>

                    </div>
                </div>

            </div>

        </div>
    </div>

    <script>
        const BASE_URL = '<?= BASE_URL ?>';

        // =============================================
        // Toggle password visibility
        // =============================================
        document.getElementById('togglePassword').addEventListener('click', function () {
            const input = document.getElementById('regPassword');
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

        // =============================================
        // Password strength meter
        // =============================================
        document.getElementById('regPassword').addEventListener('input', function () {
            const val = this.value;
            let strength = 0;
            if (val.length >= 8) strength += 25;
            if (/[a-z]/.test(val)) strength += 25;
            if (/[A-Z]/.test(val)) strength += 25;
            if (/[0-9!@#$%^&*]/.test(val)) strength += 25;

            const bar  = document.getElementById('strengthBar');
            const text = document.getElementById('strengthText');

            bar.style.width = strength + '%';

            if (!val) {
                bar.style.width = '0%';
                bar.style.background = '';
                text.textContent = 'Use 8+ characters with uppercase, lowercase, numbers & symbols';
                text.style.color = '';
            } else if (strength <= 25) {
                bar.style.background = '#EF4444';
                text.textContent = 'Weak';
                text.style.color = '#EF4444';
            } else if (strength <= 50) {
                bar.style.background = '#F59E0B';
                text.textContent = 'Fair';
                text.style.color = '#F59E0B';
            } else if (strength <= 75) {
                bar.style.background = '#3B82F6';
                text.textContent = 'Good';
                text.style.color = '#3B82F6';
            } else {
                bar.style.background = '#10B981';
                text.textContent = 'Strong';
                text.style.color = '#10B981';
            }
        });

        // =============================================
        // Confirm password real-time check
        // =============================================
        document.getElementById('regConfirmPassword').addEventListener('input', function () {
            const pass = document.getElementById('regPassword').value;
            const err  = document.getElementById('matchError');
            if (this.value && this.value !== pass) {
                this.classList.add('is-invalid');
                err.textContent = 'Passwords do not match';
                err.classList.add('visible');
            } else {
                this.classList.remove('is-invalid');
                err.classList.remove('visible');
            }
        });

        // =============================================
        // Alert helper (for step 1 verification only)
        // =============================================
        function showVerifyAlert(msg, type) {
            const iconName = type === 'danger' ? 'exclamation-circle-fill' : 'check-circle-fill';
            const area = document.getElementById('verifyAlertArea');
            area.innerHTML =
                '<div class="alert-custom alert-' + type + ' mb-3">' +
                    '<i class="bi bi-' + iconName + '"></i>' +
                    '<span>' + msg + '</span>' +
                '</div>';
        }

        // =============================================
        // Registration result modal
        // =============================================
        function showResultModal(type, title, message) {
            var overlay = document.getElementById('regResultOverlay');
            var iconWrap = document.getElementById('regResultIcon');
            var titleEl = document.getElementById('regResultTitle');
            var msgEl = document.getElementById('regResultMessage');
            var footer = document.getElementById('regResultFooter');

            if (type === 'success') {
                iconWrap.innerHTML = '<i class="bi bi-check-circle-fill"></i>';
                iconWrap.style.background = 'rgba(16,185,129,0.15)';
                iconWrap.style.color = '#10b981';
                titleEl.textContent = title || 'Registration Successful';
                msgEl.textContent = message || 'Your account has been created. Redirecting to login...';
                footer.innerHTML = '<button type="button" class="evt-btn evt-btn-primary" onclick="window.location.href=BASE_URL+\'/login.php?role=teacher\'">Go to Login</button>';
            } else {
                iconWrap.innerHTML = '<i class="bi bi-exclamation-circle-fill"></i>';
                iconWrap.style.background = 'rgba(239,68,68,0.15)';
                iconWrap.style.color = '#ef4444';
                titleEl.textContent = title || 'Registration Failed';
                msgEl.textContent = message || 'Something went wrong. Please try again.';
                footer.innerHTML = '<button type="button" class="evt-btn evt-btn-primary" onclick="closeRegResultModal()">Try Again</button>';
            }

            overlay.classList.add('show');
            document.body.style.overflow = 'hidden';
        }

        function closeRegResultModal() {
            var overlay = document.getElementById('regResultOverlay');
            overlay.classList.remove('show');
            document.body.style.overflow = '';
        }

        // =============================================
        // Verify identity (Step 1 -> Step 2)
        // =============================================
        document.getElementById('verifyBtn').addEventListener('click', function () {
            const identifier = document.getElementById('regIdentifier').value.trim();
            const btn = this;
            const area = document.getElementById('verifyAlertArea');

            if (!identifier) {
                return showVerifyAlert('Please enter your email or Employee ID', 'danger');
            }

            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status"></span> Verifying\u2026';
            area.innerHTML = '';

            fetch(BASE_URL + '/api/auth.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=verify_teacher&identifier=' + encodeURIComponent(identifier)
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-shield-check"></i> Verify Identity';

                if (data.success) {
                    document.getElementById('regEmail').value = data.email;
                    document.getElementById('stepIdentity').style.display = 'none';
                    document.getElementById('stepRegistration').style.display = 'block';
                    document.getElementById('verifyMsgText').textContent = data.message;
                } else {
                    showVerifyAlert(data.error || 'Verification failed', 'danger');
                }
            })
            .catch(function () {
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-shield-check"></i> Verify Identity';
                showVerifyAlert('Network error. Please try again.', 'danger');
            });
        });

        // =============================================
        // Back to step 1
        // =============================================
        document.getElementById('backToStep1').addEventListener('click', function () {
            document.getElementById('stepRegistration').style.display = 'none';
            document.getElementById('stepIdentity').style.display = 'block';
            document.getElementById('regIdentifier').value = '';
            document.getElementById('agreePrivacy').checked = false;
            document.getElementById('regPassword').value = '';
            document.getElementById('regConfirmPassword').value = '';
            document.getElementById('regEmail').value = '';
            document.getElementById('passwordWrapper').classList.remove('opacity-50', 'pointer-events-none');
            document.getElementById('regPassword').disabled = false;
            document.getElementById('regConfirmPassword').disabled = false;
            document.getElementById('registerBtn').disabled = false;
            document.getElementById('alertArea').innerHTML = '';
            document.getElementById('verifyAlertArea').innerHTML = '';
            document.getElementById('strengthBar').style.width = '0%';
            document.getElementById('strengthText').textContent = 'Use 8+ characters with uppercase, lowercase, numbers & symbols';
            document.getElementById('strengthText').style.color = '';
            document.getElementById('matchError').classList.remove('visible');
        });

        // =============================================
        // Submit registration
        // =============================================
        document.getElementById('registerForm').addEventListener('submit', function (e) {
            e.preventDefault();

            const email       = document.getElementById('regEmail').value.trim();
            const password    = document.getElementById('regPassword').value;
            const confirmPass = document.getElementById('regConfirmPassword').value;

            // Reset alert
            document.getElementById('alertArea').innerHTML = '';

            // Validation
            if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
                return showVerifyAlert('Invalid email. Please go back and verify again.', 'danger');
            }
            if (password.length < 8) {
                return showVerifyAlert('Password must be at least 8 characters', 'danger');
            }
            if (password !== confirmPass) {
                return showVerifyAlert('Passwords do not match', 'danger');
            }

            const agreePrivacy = document.getElementById('agreePrivacy').checked;
            if (!agreePrivacy) {
                return showVerifyAlert('You must agree to the Terms and Conditions and Data Privacy to register', 'danger');
            }

            const btn = document.getElementById('registerBtn');
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status"></span> Creating account\u2026';

            fetch(BASE_URL + '/api/auth.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=teacher_register&email=' + encodeURIComponent(email) +
                      '&password=' + encodeURIComponent(password) +
                      '&privacy_accepted=1'
            })
            .then(function (r) {
                var ct = r.headers.get('content-type') || '';
                if (ct.indexOf('application/json') === -1) {
                    throw new Error('Server returned an unexpected response. Please try again.');
                }
                return r.json();
            })
            .then(function (data) {
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-person-plus"></i> Complete Registration';

                if (data.success) {
                    showResultModal('success', 'Registration Successful', data.message + ' Redirecting to login\u2026');
                    setTimeout(function () {
                        window.location.href = BASE_URL + '/login.php?role=teacher';
                    }, 2500);
                } else {
                    showResultModal('error', 'Registration Failed', data.error || 'Registration failed. Please try again.');
                }
            })
            .catch(function (err) {
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-person-plus"></i> Complete Registration';
                showResultModal('error', 'Connection Error', (err && err.message ? err.message : 'Network error. Please check your connection and try again.'));
            });
        });

        document.addEventListener('DOMContentLoaded', function() {
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
                    if (e.target === overlay && overlay.id !== 'regResultOverlay') {
                        closeModal(overlay.id);
                    }
                    if (e.target === overlay && overlay.id === 'regResultOverlay') {
                        closeRegResultModal();
                    }
                });
            });

            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') {
                    document.querySelectorAll('.event-modal-overlay.show').forEach(function (modal) {
                        if (modal.id === 'regResultOverlay') {
                            closeRegResultModal();
                        } else {
                            closeModal(modal.id);
                        }
                    });
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

    <!-- Registration Result Modal -->
    <div class="event-modal-overlay" id="regResultOverlay">
        <div class="event-modal" style="width:min(440px,100%);height:auto;max-height:90vh;">
            <div class="event-modal-header">
                <div class="event-modal-title" id="regResultTitle">Registration Result</div>
                <button type="button" class="event-modal-close" onclick="closeRegResultModal()" aria-label="Close modal"><i class="bi bi-x-lg"></i></button>
            </div>
            <div class="event-modal-body" style="text-align:center;padding:28px 22px 20px;">
                <div id="regResultIcon" style="width:64px;height:64px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:28px;margin-bottom:16px;"></div>
                <p id="regResultMessage" style="font-size:14px;line-height:1.65;color:rgba(255,255,255,0.8);margin:0;"></p>
            </div>
            <div class="event-modal-footer" style="justify-content:center;" id="regResultFooter">
            </div>
        </div>
    </div>

</body>
</html>