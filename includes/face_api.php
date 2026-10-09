<?php
/**
 * LDB-FRAS - PHP-to-Python API Bridge
 * Handles communication between PHP backend and Python face recognition engine
 */

class FaceRecognitionAPI {
    private $apiUrl;
    private $apiKey;
    private $timeout;

    public function __construct($apiUrl = PYTHON_API_URL, $apiKey = PYTHON_API_KEY, $timeout = 120) {
        $this->apiUrl  = rtrim($apiUrl, '/');
        $this->apiKey  = $apiKey;
        $this->timeout = $timeout;
    }

    /**
     * Make HTTP request to Python API
     * @param string $endpoint
     * @param array $data
     * @return array|null
     */
    private function request($endpoint, $data = []) {
        $url = $this->apiUrl . $endpoint;

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_POST           => !empty($data),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'X-API-Key: ' . $this->apiKey
            ],
        ]);

        if (!empty($data)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($error) {
            error_log("Face API Error: $error");
            return null;
        }

        $result = json_decode($response, true);

        if ($httpCode >= 400) {
            error_log("Face API HTTP Error ($httpCode): " . ($result['error'] ?? 'Unknown'));
            return null;
        }

        return $result;
    }

    /**
     * Check if Python API is running
     * @return bool
     */
    public function isAvailable() {
        $result = $this->request('/api/health');
        return $result && $result['status'] === 'ok';
    }

    /**
     * Encode a face from image (base64)
     * @param string $base64Image
     * @return array|null ['encoding' => [...], 'status' => 'success']
     */
    public function encodeFace($base64Image) {
        return $this->request('/api/encode-face', ['image' => $base64Image]);
    }

    /**
     * Recognize a face against known students
     * @param string $base64Image
     * @param array $knownFaces [{student_id, encoding}]
     * @return array|null ['matched' => bool, 'student_id' => string, 'confidence' => float]
     */
    public function recognizeFace($base64Image, $knownFaces) {
        return $this->request('/api/recognize-face', [
            'image'       => $base64Image,
            'known_faces' => $knownFaces
        ]);
    }

    /**
     * Detect faces in an image
     * @param string $base64Image
     * @return array|null ['faces' => [...], 'count' => int]
     */
    public function detectFace($base64Image) {
        return $this->request('/api/detect-face', ['image' => $base64Image]);
    }

    /**
     * Save face image to disk
     * @param string $base64Image
     * @param string $studentId
     * @param string $faceType (front, left, right)
     * @return array|null
     */
    public function saveFaceImage($base64Image, $studentId, $faceType = 'front') {
        return $this->request('/api/save-face-image', [
            'image'      => $base64Image,
            'student_id' => $studentId,
            'face_type'  => $faceType
        ]);
    }

    /**
     * Check face image quality without encoding
     * @param string $base64Image
     * @return array|null ['quality_ok' => bool, 'issues' => [...], 'metrics' => [...]]
     */
    public function checkQuality($base64Image) {
        return $this->request('/api/check-quality', ['image' => $base64Image]);
    }

    /**
     * Batch encode multiple face images (front, left, right)
     * @param array $images ['front' => base64, 'left' => base64, 'right' => base64]
     * @return array|null ['encoding' => [...], 'faces_encoded' => int]
     */
    public function batchEncode($images) {
        return $this->request('/api/batch-encode', ['images' => $images]);
    }
}
