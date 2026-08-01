<?php
/**
 * Workflow: Email → Generate OTP → Send OTP → Verify OTP → Reset Password
 */
require_once __DIR__ . '/config.php';
$role = sanitize($_GET['role'] ?? 'admin');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - <?= APP_FULL_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/pages-theme.css">
    <style>
        * { font-family: 'Inter', sans-serif; box-sizing: border-box; }

        body {
            position: relative;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
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

        .reset-wrapper {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .reset-card {
            background: rgba(10, 34, 76, 0.90) !important;
            backdrop-filter: blur(24px) saturate(1.6);
            -webkit-backdrop-filter: blur(24px) saturate(1.6);
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-radius: 20px;
            box-shadow: 0 24px 80px rgba(0, 0, 0, 0.4);
            color: #ffffff;
            width: min(480px, 100%);
            animation: modalSlideIn .35s cubic-bezier(.34,1.56,.64,1);
            display: flex;
            flex-direction: column;
            max-height: 90vh;
        }

        @keyframes modalSlideIn {
            from { opacity: 0; transform: translateY(24px) scale(.96); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }

        .reset-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 18px 22px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.06);
            flex-shrink: 0;
        }

        .reset-card-title {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 16px;
            font-weight: 800;
            letter-spacing: -0.02em;
            color: #ffffff;
            text-decoration: none;
        }

        .reset-card-title i { font-size: 20px; color: #60A5FA; }

        .reset-card-close {
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

        .reset-card-close:hover {
            background: rgba(239, 68, 68, 0.2);
            color: #F87171;
        }

        .reset-card-body {
            padding: 22px;
            overflow-y: auto;
            flex: 1 1 auto;
            min-height: 0;
        }

        .reset-card-body::-webkit-scrollbar { width: 5px; }
        .reset-card-body::-webkit-scrollbar-track { background: transparent; }
        .reset-card-body::-webkit-scrollbar-thumb { background: rgba(255, 255, 255, 0.15); border-radius: 10px; }
        .reset-card-body::-webkit-scrollbar-thumb:hover { background: rgba(255, 255, 255, 0.25); }
        .reset-card-body { scrollbar-width: thin; scrollbar-color: rgba(255, 255, 255, 0.15) transparent; }

        .reset-icon {
            width: 56px; height: 56px;
            background: rgba(96, 165, 250, 0.15);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 16px;
        }

        .reset-icon i { font-size: 24px; color: #60A5FA; }

        .step-progress {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 0;
            margin-bottom: 24px;
        }

        .step-dot {
            width: 32px; height: 32px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.08);
            color: rgba(255, 255, 255, 0.4);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            font-weight: 700;
            transition: all 0.3s;
            position: relative;
            z-index: 2;
            border: 1.5px solid rgba(255, 255, 255, 0.12);
        }

        .step-dot.active {
            background: rgba(0, 102, 254, 0.5);
            color: white;
            border-color: rgba(0, 102, 254, 0.7);
            box-shadow: 0 4px 12px rgba(0, 102, 254, 0.3);
        }

        .step-dot.completed {
            background: rgba(16, 185, 129, 0.4);
            color: white;
            border-color: rgba(16, 185, 129, 0.6);
        }

        .step-line {
            width: 50px; height: 3px;
            background: rgba(255, 255, 255, 0.08);
            transition: background 0.3s;
            z-index: 1;
        }

        .step-line.active { background: rgba(0, 102, 254, 0.6); }
        .step-line.completed { background: rgba(16, 185, 129, 0.6); }

        .form-control-custom {
            width: 100%;
            padding: 12px 16px;
            border-radius: 10px;
            border: 1.5px solid rgba(255, 255, 255, 0.12);
            font-size: 14px;
            font-family: inherit;
            background: rgba(255, 255, 255, 0.06);
            color: #ffffff;
            transition: all 0.25s;
        }

        .form-control-custom:focus {
            outline: none;
            border-color: #60A5FA;
            background: rgba(255, 255, 255, 0.08);
            box-shadow: 0 0 0 3px rgba(96, 165, 250, 0.15);
            color: #ffffff;
        }

        .form-control-custom::placeholder { color: rgba(255, 255, 255, 0.25); }

        .form-label-custom {
            display: block;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            margin-bottom: 6px;
            color: rgba(255, 255, 255, 0.5);
        }

        .form-text-custom {
            font-size: 11px;
            margin-top: 4px;
            color: rgba(255, 255, 255, 0.35);
        }

        .otp-input {
            width: 50px; height: 56px;
            text-align: center;
            font-size: 24px;
            font-weight: 700;
            border: 1.5px solid rgba(255, 255, 255, 0.12);
            border-radius: 12px;
            background: rgba(255, 255, 255, 0.06);
            color: #ffffff;
            transition: all 0.2s;
        }

        .otp-input:focus {
            outline: none;
            border-color: #60A5FA;
            box-shadow: 0 0 0 3px rgba(96, 165, 250, 0.15);
            color: #ffffff;
        }

        .btn-action {
            background: rgba(0, 102, 254, 0.5);
            border: none;
            padding: 13px;
            border-radius: 10px;
            font-weight: 700;
            font-size: 14px;
            color: white;
            width: 100%;
            transition: all 0.25s;
            box-shadow: 0 4px 12px rgba(0, 102, 254, 0.3);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .btn-action:hover {
            background: rgba(0, 102, 254, 0.65);
            color: white;
            box-shadow: 0 6px 16px rgba(0, 102, 254, 0.4);
            transform: translateY(-1px);
        }

        .btn-action:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none !important;
        }

        .alert-custom {
            border: none;
            border-radius: 10px;
            padding: 12px 16px;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 16px;
            background: rgba(239, 68, 68, 0.12);
            color: #FCA5A5;
        }

        .alert-custom.alert-success {
            background: rgba(16, 185, 129, 0.12);
            color: #34D399;
        }

        .text-muted-custom { color: rgba(255, 255, 255, 0.4) !important; }

        .link-secondary-custom {
            color: rgba(255, 255, 255, 0.6);
            text-decoration: none;
        }

        .link-secondary-custom:hover {
            color: #ffffff;
            text-decoration: underline;
        }

        .btn-link-custom {
            color: #60A5FA;
            text-decoration: none;
        }

        .btn-link-custom:hover { color: #ffffff; text-decoration: underline; }

        .separator-custom {
            display: inline-block;
            margin: 0 8px;
            color: rgba(255, 255, 255, 0.15);
        }

        .text-faint { color: rgba(255, 255, 255, 0.35); }
        .text-faint-strong { color: rgba(255, 255, 255, 0.45); }

        /* Responsive Navbar */
        @media (max-width: 991px) {
            .ldb-navbar { padding: 12px 0; }
            .nav-content { padding: 0 8px; }
            .nav-title { font-size: 1.1rem; letter-spacing: 0.8px; }
            .nav-subtitle { font-size: 0.75rem; }
            .ldb-logo { width: 60px; height: 60px; }
            .nav-scanner-icon { width: 44px; height: 44px; }
        }

        @media (max-width: 575px) {
            .nav-title { font-size: 0.8rem; }
            .ldb-logo { width: 38px; height: 38px; }
            .nav-scanner-icon { display: none; }
        }

        /* Responsive Card */
        @media (max-width: 480px) {
            .reset-card { border-radius: 16px; }
            .reset-card-header { padding: 14px 18px; }
            .reset-card-body { padding: 18px; }
            .step-line { width: 32px; }
        }
    </style>
</head>
<body>
    <div class="reset-wrapper">
        <div class="reset-card">
            <div class="reset-card-header">
                <div class="reset-card-title">
                    <i class="bi bi-shield-lock" id="headerIcon"></i>
                    <span id="headerTitle">Forgot Password</span>
                </div>
                <a href="<?= BASE_URL ?>/login.php?role=<?= $role ?>" class="reset-card-close" title="Close">
                    <i class="bi bi-x-lg"></i>
                </a>
            </div>

            <div class="reset-card-body">
                <!-- Step Progress Indicator -->
                <div class="step-progress" id="stepProgress">
                    <div class="step-dot active" id="step1Dot">1</div>
                    <div class="step-line" id="line1"></div>
                    <div class="step-dot" id="step2Dot">2</div>
                    <div class="step-line" id="line2"></div>
                    <div class="step-dot" id="step3Dot">3</div>
                </div>

                <!-- Header -->
                <div class="text-center mb-4" id="stepHeader">
                    <div class="reset-icon">
                        <i class="bi bi-shield-lock" id="stepIcon"></i>
                    </div>
                    <h5 class="fw-bold" id="stepTitle" style="color:#fff;">Forgot Password</h5>
                    <p class="text-muted-custom" style="font-size:13.5px;" id="stepDesc">Enter your email to receive a reset code</p>
                </div>

                <div id="alertArea"></div>

                <!-- Step 1: Email -->
                <form id="emailForm">
                    <div class="mb-3">
                        <label class="form-label-custom" for="resetEmail">Email Address</label>
                        <input type="email" class="form-control-custom" id="resetEmail"
                               placeholder="Enter your registered email" required>
                    </div>
                    <button type="submit" class="btn-action" id="emailBtn">
                        <i class="bi bi-send"></i> Send OTP
                    </button>
                </form>

                <!-- Step 2: OTP Verification -->
                <form id="otpForm" style="display:none;">
                    <div class="mb-4">
                        <label class="form-label-custom text-center d-block" for="otpContainer">
                            Enter 6-digit OTP Code
                        </label>
                        <div class="d-flex justify-content-center gap-2 mt-2" id="otpContainer">
                            <input type="text" class="otp-input" maxlength="1" data-idx="0" inputmode="numeric">
                            <input type="text" class="otp-input" maxlength="1" data-idx="1" inputmode="numeric">
                            <input type="text" class="otp-input" maxlength="1" data-idx="2" inputmode="numeric">
                            <input type="text" class="otp-input" maxlength="1" data-idx="3" inputmode="numeric">
                            <input type="text" class="otp-input" maxlength="1" data-idx="4" inputmode="numeric">
                            <input type="text" class="otp-input" maxlength="1" data-idx="5" inputmode="numeric">
                        </div>
                        <p class="text-center mt-2 text-muted-custom" style="font-size:12px;">
                            Check your email for the verification code
                        </p>
                        <p class="text-center mt-1 mb-0" id="otpTimerContainer" style="display:none; font-size:12px;">
                            <span id="otpTimer" style="color:#34D399; font-weight:600;"></span>
                        </p>
                    </div>
                    <input type="hidden" id="otpUserId">
                    <button type="submit" class="btn-action" id="otpBtn">
                        <i class="bi bi-check-circle"></i> Verify OTP
                    </button>
                    <div class="text-center mt-3">
                        <button type="button" class="btn btn-link btn-sm p-0 btn-link-custom" id="resendBtn" style="display:none;" onclick="resendOTP()">
                            <i class="bi bi-arrow-clockwise"></i> Resend OTP
                        </button>
                        <span class="separator-custom" id="resendSeparator" style="display:none;">|</span>
                        <button type="button" class="btn btn-link btn-sm p-0 text-faint" id="changeEmailBtn" onclick="goBackToEmail()">
                            <i class="bi bi-arrow-left"></i> Change email
                        </button>
                    </div>
                </form>

                <!-- Step 3: Reset Password -->
                <form id="resetForm" style="display:none;">
                    <div class="mb-3">
                        <label class="form-label-custom" for="newPassword">New Password</label>
                        <input type="password" class="form-control-custom" id="newPassword"
                               placeholder="Min. 8 characters" required minlength="8">
                        <div class="form-text-custom">Use at least 8 characters with letters and numbers.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label-custom" for="confirmPassword">Confirm Password</label>
                        <input type="password" class="form-control-custom" id="confirmPassword"
                               placeholder="Repeat new password" required>
                    </div>
                    <input type="hidden" id="resetUserId">
                    <button type="submit" class="btn-action" id="resetBtn">
                        <i class="bi bi-lock"></i> Reset Password
                    </button>
                </form>

                <div class="text-center mt-3">
                    <a href="<?= BASE_URL ?>/login.php?role=<?= $role ?>" class="link-secondary-custom" style="font-size:13px;">
                        <i class="bi bi-arrow-left"></i> Back to Login
                    </a>
                </div>
            </div>
        </div>
    </div>

    <script>
        const BASE_URL = '<?= BASE_URL ?>';
        let otpCountdown = null;
        let otpSecondsLeft = 60;

        function startOTPTimer() {
            otpSecondsLeft = 60;
            updateTimerDisplay();
            document.getElementById('resendBtn').style.display = 'none';
            document.getElementById('resendSeparator').style.display = 'none';
            document.getElementById('otpTimerContainer').style.display = 'block';
            document.getElementById('otpBtn').disabled = false;

            otpCountdown = setInterval(() => {
                otpSecondsLeft--;
                updateTimerDisplay();
                if (otpSecondsLeft <= 0) {
                    clearInterval(otpCountdown);
                    otpCountdown = null;
                    document.getElementById('otpTimerContainer').style.display = 'none';
                    document.getElementById('resendBtn').style.display = 'inline';
                    document.getElementById('resendSeparator').style.display = 'inline';
                    document.getElementById('otpBtn').disabled = true;
                    otpInputs.forEach(i => i.value = '');
                }
            }, 1000);
        }

        function updateTimerDisplay() {
            const timerEl = document.getElementById('otpTimer');
            if (timerEl) {
                timerEl.textContent = 'OTP expires in ' + otpSecondsLeft + 's';
            }
        }

        function stopOTPTimer() {
            if (otpCountdown) {
                clearInterval(otpCountdown);
                otpCountdown = null;
            }
        }

        function resetOTPStep() {
            stopOTPTimer();
            document.getElementById('otpTimerContainer').style.display = 'none';
            document.getElementById('resendBtn').style.display = 'none';
            document.getElementById('resendSeparator').style.display = 'none';
            document.getElementById('otpBtn').disabled = false;
            otpInputs.forEach(i => i.value = '');
            otpSecondsLeft = 60;
        }

        const otpInputs = document.querySelectorAll('.otp-input');
        otpInputs.forEach((input, idx) => {
            input.addEventListener('input', (e) => {
                e.target.value = e.target.value.replace(/[^0-9]/g, '');
                if (e.target.value && idx < otpInputs.length - 1) {
                    otpInputs[idx + 1].focus();
                }
            });
            input.addEventListener('keydown', (e) => {
                if (e.key === 'Backspace' && !e.target.value && idx > 0) {
                    otpInputs[idx - 1].focus();
                }
            });
            input.addEventListener('paste', (e) => {
                e.preventDefault();
                const data = (e.clipboardData || window.clipboardData).getData('text').replace(/[^0-9]/g, '');
                data.split('').forEach((ch, i) => {
                    if (otpInputs[idx + i]) otpInputs[idx + i].value = ch;
                });
            });
        });

        function getOTPValue() {
            return Array.from(otpInputs).map(i => i.value).join('');
        }

        function showAlert(msg, type) {
            document.getElementById('alertArea').innerHTML =
                `<div class="alert-custom alert-${type} mb-3"><i class="bi bi-${type === 'danger' ? 'exclamation-circle' : 'check-circle'}"></i>${msg}</div>`;
            setTimeout(() => { document.getElementById('alertArea').innerHTML = ''; }, 5000);
        }

        function updateProgress(step) {
            for (let i = 1; i <= 3; i++) {
                const dot = document.getElementById(`step${i}Dot`);
                dot.classList.remove('active', 'completed');
                if (i < step) dot.classList.add('completed');
                else if (i === step) dot.classList.add('active');
            }
            if (step >= 2) document.getElementById('line1').classList.add(step > 2 ? 'completed' : 'active');
            if (step >= 3) document.getElementById('line2').classList.add('active');

            const titles  = { 1: 'Forgot Password', 2: 'Verify OTP', 3: 'Reset Password' };
            const descs   = { 1: 'Enter your email to receive a reset code', 2: 'Enter the 6-digit code sent to your email', 3: 'Create your new password' };
            const icons   = { 1: 'bi-shield-lock', 2: 'bi-shield-check', 3: 'bi-key' };

            document.getElementById('stepTitle').textContent = titles[step];
            document.getElementById('stepDesc').textContent  = descs[step];
            document.getElementById('stepIcon').className    = `bi ${icons[step]}`;
        }

        document.getElementById('emailForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const email = document.getElementById('resetEmail').value.trim();
            if (!email) return showAlert('Email is required', 'danger');

            const btn = document.getElementById('emailBtn');
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Sending...';

            fetch(BASE_URL + '/api/auth.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=forgot_password&email=' + encodeURIComponent(email)
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('otpUserId').value = data.user_id || '';
                    document.getElementById('emailForm').style.display = 'none';
                    document.getElementById('otpForm').style.display = 'block';
                    document.getElementById('resetForm').style.display = 'none';
                    document.getElementById('emailErrorArea') && (document.getElementById('emailErrorArea').style.display = 'none');
                    resetOTPStep();
                    startOTPTimer();
                    updateProgress(2);
                    otpInputs[0].focus();
                } else {
                    showAlert(data.error || 'Error sending OTP', 'danger');
                    btn.disabled = false;
                    btn.innerHTML = '<i class="bi bi-send"></i> Send OTP';
                }
            })
            .catch(() => {
                showAlert('Network error. Please try again.', 'danger');
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-send"></i> Send OTP';
            });
        });

        document.getElementById('otpForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const userId = document.getElementById('otpUserId').value;
            const otp = getOTPValue();
            if (otp.length !== 6) return showAlert('Please enter all 6 digits', 'danger');

            const btn = document.getElementById('otpBtn');
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Verifying...';

            fetch(BASE_URL + '/api/auth.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=verify_otp&user_id=' + userId + '&otp=' + otp
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    stopOTPTimer();
                    document.getElementById('otpTimerContainer').style.display = 'none';
                    document.getElementById('resetUserId').value = userId;
                    document.getElementById('emailForm').style.display = 'none';
                    document.getElementById('otpForm').style.display = 'none';
                    document.getElementById('resetForm').style.display = 'block';
                    updateProgress(3);
                } else {
                    showAlert(data.error || 'Invalid OTP', 'danger');
                    btn.disabled = false;
                    btn.innerHTML = '<i class="bi bi-check-circle"></i> Verify OTP';
                    otpInputs.forEach(i => i.value = '');
                    otpInputs[0].focus();
                }
            })
            .catch(() => {
                showAlert('Network error.', 'danger');
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-check-circle"></i> Verify OTP';
            });
        });

        function resendOTP() {
            const email = document.getElementById('resetEmail').value.trim();
            if (!email) return showAlert('Please go back and enter your email', 'danger');

            const btn = document.getElementById('otpBtn');
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Sending OTP...';

            fetch(BASE_URL + '/api/auth.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=forgot_password&email=' + encodeURIComponent(email)
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    showAlert('A new OTP has been sent to your email.', 'success');
                    resetOTPStep();
                    startOTPTimer();
                    otpInputs[0].focus();
                } else {
                    showAlert(data.error || 'Error resending OTP. Please try again.', 'danger');
                    btn.disabled = false;
                    btn.innerHTML = '<i class="bi bi-check-circle"></i> Verify OTP';
                }
            })
            .catch(() => {
                showAlert('Network error. Please try again.', 'danger');
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-check-circle"></i> Verify OTP';
            });
        }

        function goBackToEmail() {
            stopOTPTimer();
            document.getElementById('otpForm').style.display = 'none';
            document.getElementById('resetForm').style.display = 'none';
            document.getElementById('emailForm').style.display = 'block';
            document.getElementById('otpTimerContainer').style.display = 'none';
            document.getElementById('resendBtn').style.display = 'none';
            document.getElementById('resendSeparator').style.display = 'none';
            document.getElementById('emailBtn').disabled = false;
            document.getElementById('emailBtn').innerHTML = '<i class="bi bi-send"></i> Send OTP';
            otpInputs.forEach(i => i.value = '');
            document.getElementById('resetEmail').focus();
            updateProgress(1);
            document.getElementById('alertArea').innerHTML = '';
        }

        document.getElementById('resetForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const userId = document.getElementById('resetUserId').value;
            const newPass = document.getElementById('newPassword').value;
            const confirmPass = document.getElementById('confirmPassword').value;

            if (newPass.length < 8) return showAlert('Password must be at least 8 characters', 'danger');
            if (newPass !== confirmPass) return showAlert('Passwords do not match', 'danger');

            const btn = document.getElementById('resetBtn');
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Resetting...';

            fetch(BASE_URL + '/api/auth.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=reset_password&user_id=' + userId +
                      '&new_password=' + encodeURIComponent(newPass) +
                      '&confirm_password=' + encodeURIComponent(confirmPass)
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    showAlert('Password reset successful! Redirecting...', 'success');
                    setTimeout(() => { window.location.href = BASE_URL + '/login.php?role=<?= $role ?>'; }, 2000);
                } else {
                    showAlert(data.error || 'Error resetting password', 'danger');
                    btn.disabled = false;
                    btn.innerHTML = '<i class="bi bi-lock"></i> Reset Password';
                }
            })
            .catch(() => {
                showAlert('Network error.', 'danger');
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-lock"></i> Reset Password';
            });
        });
    </script>
</body>
</html>
