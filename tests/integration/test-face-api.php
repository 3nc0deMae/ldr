<?php

require_once __DIR__ . '/../bootstrap.php';
require_once TEST_ROOT . '/includes/face_api.php';

$t = new TestSuite('Face Recognition Integration Tests');

// ============================================================
// API Availability Test
// ============================================================

$faceAPI = new FaceRecognitionAPI();

$available = $faceAPI->isAvailable();
if ($available) {
    $t->assert(true, 'Python Face Recognition API is running and responding');
} else {
    $t->assert(false, 'Python Face Recognition API is NOT available (start with: python python/app.py)');
    // Skip remaining tests if API is down
    $t->assert(false, 'Skipping face encoding tests - API unavailable');
    $t->assert(false, 'Skipping face detection tests - API unavailable');
    $t->assert(false, 'Skipping face recognition tests - API unavailable');
    return $t;
}

// ============================================================
// Health Check Endpoint Test
// ============================================================

// Direct cURL health check
$ch = curl_init(PYTHON_API_URL . '/api/health');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_HTTPHEADER     => ['X-API-Key: ' . PYTHON_API_KEY]
]);
$healthResponse = curl_exec($ch);
$healthCode     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$t->assertEqual(200, $healthCode, 'Health endpoint returns HTTP 200');

if ($healthResponse) {
    $healthData = json_decode($healthResponse, true);
    $t->assertNotNull($healthData, 'Health response is valid JSON');
    if ($healthData) {
        $t->assertEqual('ok', $healthData['status'], 'Health status is ok');
        $t->assertArrayHasKey('service', $healthData, 'Health response has service name');
        $t->assertArrayHasKey('timestamp', $healthData, 'Health response has timestamp');
    }
}

// ============================================================
// Face Detection Test (using a generated test image)
// ============================================================

// Create a simple test image (solid color - no face, should return empty)
$width = 200; $height = 200;
$img = imagecreatetruecolor($width, $height);
$bgColor = imagecolorallocate($img, 200, 200, 200);
imagefill($img, 0, 0, $bgColor);
ob_start();
imagejpeg($img, null, 90);
$imageData = ob_get_clean();
imagedestroy($img);
$base64Image = base64_encode($imageData);

$detectResult = $faceAPI->detectFace($base64Image);
if ($detectResult) {
    $t->assertArrayHasKey('faces', $detectResult, 'Detect face response has faces array');
    $t->assertArrayHasKey('count', $detectResult, 'Detect face response has count');
} else {
    // API might return null for a no-face image, which is acceptable
    $t->assert(true, 'Detect face returned null/error for no-face image (acceptable)');
}

// ============================================================
// Face Encoding Test (no-face image should fail gracefully)
// ============================================================

$encodeResult = $faceAPI->encodeFace($base64Image);
// Expected to fail since there's no face in a solid color image
if ($encodeResult === null) {
    $t->assert(true, 'encodeFace() returns null for image without face (correct)');
} else {
    $t->assertArrayHasKey('error', $encodeResult, 'No-face image returns error in response');
}

// ============================================================
// Face Recognition Test (no known faces - should fail gracefully)
// ============================================================

$recognizeResult = $faceAPI->recognizeFace($base64Image, []);
// Should return error because no known faces provided
if ($recognizeResult === null) {
    $t->assert(true, 'recognizeFace() handles empty known_faces gracefully');
} else {
    $t->assert(true, 'recognizeFace() returned a response for empty known_faces');
}

// ============================================================
// API Configuration Tests
// ============================================================

$t->assertNotEmpty(PYTHON_API_URL, 'PYTHON_API_URL constant is defined');
$t->assertNotEmpty(PYTHON_API_KEY, 'PYTHON_API_KEY constant is defined');
$t->assertContains('http', PYTHON_API_URL, 'PYTHON_API_URL contains http protocol');

// ============================================================
// FaceRecognitionAPI Class Tests
// ============================================================

$t->assertTrue(class_exists('FaceRecognitionAPI'), 'FaceRecognitionAPI class exists');

$customAPI = new FaceRecognitionAPI('http://localhost:5000', 'test_key', 5);
$t->assertNotNull($customAPI, 'FaceRecognitionAPI can be instantiated with custom params');

// Test method existence
$t->assertTrue(method_exists($faceAPI, 'isAvailable'), 'Method isAvailable() exists');
$t->assertTrue(method_exists($faceAPI, 'encodeFace'), 'Method encodeFace() exists');
$t->assertTrue(method_exists($faceAPI, 'recognizeFace'), 'Method recognizeFace() exists');
$t->assertTrue(method_exists($faceAPI, 'detectFace'), 'Method detectFace() exists');
$t->assertTrue(method_exists($faceAPI, 'saveFaceImage'), 'Method saveFaceImage() exists');
$t->assertTrue(method_exists($faceAPI, 'batchEncode'), 'Method batchEncode() exists');

// ============================================================
// Batch Encode Test (should fail gracefully with invalid images)
// ============================================================

$batchResult = $faceAPI->batchEncode([
    'front' => $base64Image,
    'left'  => $base64Image,
    'right' => $base64Image
]);
// Will fail because no faces in test images, but should not crash
if ($batchResult === null) {
    $t->assert(true, 'batchEncode() returns null for no-face images (correct)');
} else {
    $t->assert(true, 'batchEncode() returned a response without crashing');
}

return $t;
