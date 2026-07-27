<?php
// Include your existing configuration
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/notifications.php';

// --- CONFIGURATION ---
// 1. Generate a NEW key in your dashboard and paste it here
$apiKey = 'pk_6fTdwAYplXA2FljB1x4sLWkGADZi6YlWiRO8C83td5aAeimcCr47kSDprZNPgpJr'; 

// 2. This must be the full international number of the phone linked to your httpSMS app
$fromNumber = '+639858614137'; 

$apiUrl = 'https://api.httpsms.com/v1/messages/send';
$to = '+639858614137';
$message = '[LDB-FRAS] This is a test SMS from the attendance system.';

// --- PAYLOAD PREPARATION ---
$payload = json_encode([
    'content' => $message,
    'from'    => $fromNumber,
    'to'      => $to
]);

// --- CURL EXECUTION ---
$ch = curl_init($apiUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_SSL_VERIFYPEER => true, 
    CURLOPT_HTTPHEADER     => [
        'x-api-key: ' . $apiKey,
        'Content-Type: application/json',
        'Accept: application/json'
    ]
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error    = curl_error($ch);
curl_close($ch);

// --- OUTPUT ---
echo "<h2>SMS API Test (httpSMS)</h2>";
echo "<p><strong>HTTP Status Code:</strong> " . $httpCode . "</p>";

if ($error) {
    echo "<p style='color:red; font-weight:bold;'>❌ cURL Error: " . htmlspecialchars($error) . "</p>";
} else {
    echo "<p><strong>Response Body:</strong></p>";
    echo "<pre>" . htmlspecialchars($response) . "</pre>";
}

if ($httpCode >= 200 && $httpCode < 300) {
    echo "<p style='color:green; font-weight:bold;'>✅ SMS sent successfully!</p>";
} else {
    echo "<p style='color:red; font-weight:bold;'>❌ Failed to send SMS. Ensure your API Key is correct and your device is online in the httpSMS app.</p>";
}
?>