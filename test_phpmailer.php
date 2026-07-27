<?php
require_once __DIR__ . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

echo "=== PHPMailer Installation Test ===\n\n";

// Test 1: Class autoloading
echo "1. Autoload Test: ";
echo class_exists('PHPMailer\PHPMailer\PHPMailer') ? "PASSED\n" : "FAILED\n";

// Test 2: Instantiation
echo "2. Instantiation Test: ";
try {
    $mail = new PHPMailer(true);
    echo "PASSED\n";
} catch (Exception $e) {
    echo "FAILED: " . $e->getMessage() . "\n";
}

// Test 3: Basic configuration
echo "3. Configuration Test: ";
$mail = new PHPMailer(true);
$mail->isSMTP();
$mail->Host = 'smtp.example.com';
$mail->SMTPAuth = true;
$mail->Username = 'test@example.com';
$mail->Password = 'password';
$mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
$mail->Port = 465;
echo ($mail->Host === 'smtp.example.com' && $mail->Port === 465) ? "PASSED\n" : "FAILED\n";

// Test 4: Message setup
echo "4. Message Setup Test: ";
$mail->setFrom('from@example.com', 'Test');
$mail->addAddress('to@example.com', 'Recipient');
$mail->Subject = 'PHPMailer Test';
$mail->Body = 'This is a test email.';
echo ($mail->Subject === 'PHPMailer Test') ? "PASSED\n" : "FAILED\n";

// Test 5: Version check
echo "5. Version Test: ";
$version = PHPMailer::VERSION;
echo !empty($version) ? "PASSED (v$version)\n" : "FAILED\n";

echo "\n=== All Tests Completed ===\n";
echo "Note: Actual sending requires valid SMTP credentials.\n";
