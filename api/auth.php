<?php
/**
 * LDB-FRAS - Authentication API
 * Handles forgot password, OTP verification, teacher registration
 */

ob_start();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/notifications.php';

ob_end_clean();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

$action = $_POST['action'] ?? '';

try {
    switch ($action) {

        case 'forgot_password':
            rateLimitMiddleware(5, 60);

            $email = sanitize($_POST['email'] ?? '');
            if (empty($email) || !isValidEmail($email)) {
                jsonResponse(['error' => 'Valid email address is required'], 400);
            }

            $user = getUserByEmail($db, $email);
            if (!$user) {
                jsonResponse(['success' => true, 'message' => 'If the email exists, an OTP has been sent.']);
            }

            if (!checkRateLimit('forgot_pw_' . $email, 3, 300)) {
                jsonResponse(['error' => 'Too many password reset attempts. Please try again later.'], 429);
            }

            $otp = generateOTP();
            $expires = date('Y-m-d H:i:s', strtotime('+1 minute'));

            $stmt = $db->prepare("UPDATE users SET otp_code = ?, otp_expires = ? WHERE id = ?");
            $stmt->execute([$otp, $expires, $user['id']]);

            $schoolName = getSetting($db, 'school_name', 'Liceo de Baleno');
            $otpSubject = 'Password Reset OTP - ' . $schoolName;
            $otpBody = "<p>Dear User,</p>
                        <p>You have requested to reset your password for your <strong>" . $schoolName . "</strong> account.</p>
                        <p>Use the following One-Time Password (OTP) to verify your identity and reset your password:</p>
                        <h2 style='text-align:center; letter-spacing:6px; color:#0066FE; padding:16px 0;'>{$otp}</h2>
                        <p style='color:#DC3545;'><strong>This code will expire in 1 minute.</strong></p>
                        <p>If you did not request a password reset, please ignore this email and your account will remain secure.</p>
                        <p>Thank you,<br>{$schoolName}<br>IT System Administration</p>";

            try {
                sendEmailNotification($user['email'], $otpSubject, $otpBody);
            } catch (Throwable $e) {
                error_log('OTP email send failed: ' . $e->getMessage());
            }
            try { logAudit($db, 'forgot_password_request', "Password reset OTP sent to {$user['email']}", $user['id']); } catch (Throwable $e) {}

            jsonResponse([
                'success' => true,
                'message' => 'OTP has been sent to your email. Please check your inbox.',
                'user_id' => $user['id']
            ]);
            break;

        case 'verify_otp':
            $userId = intval($_POST['user_id'] ?? 0);
            $otp    = sanitize($_POST['otp'] ?? '');

            if (!$userId || empty($otp)) {
                jsonResponse(['error' => 'User ID and OTP are required'], 400);
            }

            $user = getUserById($db, $userId);
            if (!$user) {
                jsonResponse(['error' => 'User not found'], 404);
            }

            if ($user['otp_code'] !== $otp) {
                try { logAudit($db, 'forgot_password_failed', "Invalid OTP attempt for user ID {$userId}", $userId); } catch (Throwable $e) {}
                jsonResponse(['error' => 'Invalid OTP'], 400);
            }

            if (strtotime($user['otp_expires']) < time()) {
                try { logAudit($db, 'forgot_password_expired', "Expired OTP for user ID {$userId}", $userId); } catch (Throwable $e) {}
                jsonResponse(['error' => 'OTP has expired. Please request a new one.'], 400);
            }

            try { logAudit($db, 'forgot_password_verified', "OTP verified for user ID {$userId}", $userId); } catch (Throwable $e) {}
            jsonResponse(['success' => true, 'message' => 'OTP verified', 'user_id' => $userId]);
            break;

        case 'reset_password':
            $userId      = intval($_POST['user_id'] ?? 0);
            $newPassword = $_POST['new_password'] ?? '';
            $confirmPass = $_POST['confirm_password'] ?? '';

            if (!$userId || empty($newPassword)) {
                jsonResponse(['error' => 'User ID and new password are required'], 400);
            }

            if ($newPassword !== $confirmPass) {
                jsonResponse(['error' => 'Passwords do not match'], 400);
            }

            $strength = checkPasswordStrength($newPassword);
            if (!$strength['valid']) {
                jsonResponse(['error' => implode(' ', $strength['errors'])], 400);
            }

            if (updatePassword($db, $userId, $newPassword)) {
                $stmt = $db->prepare("UPDATE users SET otp_code = NULL, otp_expires = NULL WHERE id = ?");
                $stmt->execute([$userId]);

                $user = getUserById($db, $userId);
                try { logAudit($db, 'password_reset', "Password reset successful for user ID {$userId}", $userId); } catch (Throwable $e) {}

                jsonResponse(['success' => true, 'message' => 'Password reset successfully. You may now login with your new password.']);
            } else {
                jsonResponse(['error' => 'Failed to reset password'], 500);
            }
            break;

        case 'verify_teacher':
            $identifier = sanitize($_POST['identifier'] ?? '');

            if (empty($identifier)) {
                jsonResponse(['error' => 'Email or Employee ID is required'], 400);
            }

            $stmt = $db->prepare("SELECT id, first_name, last_name, email FROM teachers WHERE email = ? OR employee_id = ? LIMIT 1");
            $stmt->execute([$identifier, $identifier]);
            $teacher = $stmt->fetch();

            if (!$teacher) {
                jsonResponse(['error' => 'No teacher record found with this email or ID. Contact the administrator.'], 404);
            }

            jsonResponse([
                'success' => true,
                'message' => 'Identity verified. Welcome, ' . $teacher['first_name'] . ' ' . $teacher['last_name'] . '.',
                'teacher_name' => $teacher['first_name'] . ' ' . $teacher['last_name'],
                'email' => $teacher['email']
            ]);
            break;

        case 'teacher_register':
            $email           = sanitize($_POST['email'] ?? '');
            $password        = $_POST['password'] ?? '';
            $privacyAccepted = isset($_POST['privacy_accepted']) ? 1 : 0;

            if (empty($email) || empty($password)) {
                jsonResponse(['error' => 'Email and password are required'], 400);
            }

            if (!$privacyAccepted) {
                jsonResponse(['error' => 'You must accept the Data Privacy terms and conditions to register'], 400);
            }

            $stmt = $db->prepare("SELECT * FROM teachers WHERE email = ?");
            $stmt->execute([$email]);
            $teacher = $stmt->fetch();

            if (!$teacher) {
                jsonResponse(['error' => 'No teacher record found with this email. Contact admin.'], 400);
            }

            $existingUser = getUserByEmail($db, $email);
            if ($existingUser) {
                jsonResponse(['error' => 'An account with this email already exists'], 400);
            }

            $userId = createUser($db, [
                'role'     => 'teacher',
                'email'    => $email,
                'password' => $password,
                'status'   => 'active'
            ]);

            if ($userId) {
                $stmt = $db->prepare("UPDATE teachers SET user_id = ?, privacy_accepted_at = NOW() WHERE id = ?");
                $stmt->execute([$userId, $teacher['id']]);

                jsonResponse([
                    'success' => true,
                    'message' => 'Registration successful! You can now login.',
                    'user_id' => $userId
                ]);
            } else {
                jsonResponse(['error' => 'Registration failed. Please try again.'], 500);
            }
            break;

        default:
            jsonResponse(['error' => 'Invalid action'], 400);
    }
} catch (Throwable $e) {
    error_log('auth.php error: ' . $e->getMessage());
    ob_end_clean();
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode(['error' => 'A server error occurred. Please try again.']);
    exit();
}
