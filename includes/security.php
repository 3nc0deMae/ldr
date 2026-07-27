<?php
/**
 * LDB-FRAS - Security Module
 
 * Provides input validation, CSRF middleware, rate limiting,
 * audit logging, and secure request handling.
 */

// ============================================================
// AUDIT LOGGING
// ============================================================

/**
 * Log an action to the audit_logs table
 * @param PDO   $db
 * @param string $action     Short action label (e.g. 'login', 'add_student')
 * @param string $description Human-readable description
 * @param int|null $userId   Acting user (null = current session user)
 */
function logAudit($db, $action, $description = '', $userId = null) {
    $userId = $userId ?? getCurrentUserId();
    $ip     = getRealIP();
    $ua     = $_SERVER['HTTP_USER_AGENT'] ?? '';

    try {
        $stmt = $db->prepare(
            "INSERT INTO audit_logs (user_id, action, description, ip_address, user_agent, created_at)
             VALUES (:user_id, :action, :description, :ip, :ua, NOW())"
        );
        $stmt->execute([
            ':user_id'     => $userId,
            ':action'      => $action,
            ':description' => $description,
            ':ip'          => $ip,
            ':ua'          => substr($ua, 0, 500)
        ]);
    } catch (Exception $e) {
        error_log("Audit log failed: " . $e->getMessage());
    }
}

/**
 * Get audit logs with filters
 * @param PDO   $db
 * @param array $filters  Keys: user_id, action, date_from, date_to, search
 * @param int   $limit
 * @param int   $offset
 * @return array
 */
function getAuditLogs($db, $filters = [], $limit = 25, $offset = 0) {
    $sql = "SELECT al.*, u.email AS user_email, u.role AS user_role
            FROM audit_logs al
            LEFT JOIN users u ON al.user_id = u.id";
    $conditions = [];
    $params     = [];

    if (!empty($filters['user_id'])) {
        $conditions[]      = "al.user_id = :user_id";
        $params[':user_id'] = $filters['user_id'];
    }
    if (!empty($filters['action'])) {
        $conditions[]       = "al.action LIKE :action";
        $params[':action']  = '%' . $filters['action'] . '%';
    }
    if (!empty($filters['date_from'])) {
        $conditions[]         = "DATE(al.created_at) >= :date_from";
        $params[':date_from'] = $filters['date_from'];
    }
    if (!empty($filters['date_to'])) {
        $conditions[]       = "DATE(al.created_at) <= :date_to";
        $params[':date_to'] = $filters['date_to'];
    }
    if (!empty($filters['search'])) {
        $conditions[]        = "(al.description LIKE :search OR al.action LIKE :search2 OR u.email LIKE :search3)";
        $params[':search']   = '%' . $filters['search'] . '%';
        $params[':search2']  = '%' . $filters['search'] . '%';
        $params[':search3']  = '%' . $filters['search'] . '%';
    }

    if ($conditions) {
        $sql .= " WHERE " . implode(" AND ", $conditions);
    }
    $sql .= " ORDER BY al.created_at DESC LIMIT $limit OFFSET $offset";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Count audit logs matching filters (for pagination)
 * @param PDO   $db
 * @param array $filters
 * @return int
 */
function countAuditLogs($db, $filters = []) {
    $sql        = "SELECT COUNT(*) FROM audit_logs al LEFT JOIN users u ON al.user_id = u.id";
    $conditions = [];
    $params     = [];

    if (!empty($filters['user_id'])) {
        $conditions[]      = "al.user_id = :user_id";
        $params[':user_id'] = $filters['user_id'];
    }
    if (!empty($filters['action'])) {
        $conditions[]       = "al.action LIKE :action";
        $params[':action']  = '%' . $filters['action'] . '%';
    }
    if (!empty($filters['date_from'])) {
        $conditions[]         = "DATE(al.created_at) >= :date_from";
        $params[':date_from'] = $filters['date_from'];
    }
    if (!empty($filters['date_to'])) {
        $conditions[]       = "DATE(al.created_at) <= :date_to";
        $params[':date_to'] = $filters['date_to'];
    }
    if (!empty($filters['search'])) {
        $conditions[]        = "(al.description LIKE :search OR al.action LIKE :search2 OR u.email LIKE :search3)";
        $params[':search']   = '%' . $filters['search'] . '%';
        $params[':search2']  = '%' . $filters['search'] . '%';
        $params[':search3']  = '%' . $filters['search'] . '%';
    }

    if ($conditions) {
        $sql .= " WHERE " . implode(" AND ", $conditions);
    }

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

// ============================================================
// INPUT VALIDATION
// ============================================================

/**
 * Validate that required fields are present and non-empty
 * @param array $data     Source array (e.g. $_POST)
 * @param array $fields   Field names to check
 * @return array          Errors keyed by field name; empty = all passed
 */
function validateRequired($data, $fields) {
    $errors = [];
    foreach ($fields as $field) {
        if (!isset($data[$field]) || trim($data[$field]) === '') {
            $errors[$field] = ucfirst(str_replace('_', ' ', $field)) . ' is required.';
        }
    }
    return $errors;
}

/**
 * Validate string length
 * @param string $value
 * @param int    $min
 * @param int    $max
 * @param string $label
 * @return string|null  Error message or null
 */
function validateLength($value, $min, $max, $label = 'Field') {
    $len = mb_strlen(trim($value));
    if ($len < $min || $len > $max) {
        return "$label must be between $min and $max characters.";
    }
    return null;
}

/**
 * Validate numeric value within optional range
 * @param mixed  $value
 * @param float|null $min
 * @param float|null $max
 * @param string $label
 * @return string|null
 */
function validateNumeric($value, $min = null, $max = null, $label = 'Field') {
    if (!is_numeric($value)) {
        return "$label must be a number.";
    }
    if ($min !== null && floatval($value) < $min) {
        return "$label must be at least $min.";
    }
    if ($max !== null && floatval($value) > $max) {
        return "$label must be at most $max.";
    }
    return null;
}

/**
 * Validate value against a regex pattern
 * @param string $value
 * @param string $pattern
 * @param string $label
 * @param string $errorMsg
 * @return string|null
 */
function validatePattern($value, $pattern, $label = 'Field', $errorMsg = '') {
    if (!preg_match($pattern, $value)) {
        return $errorMsg ?: "$label format is invalid.";
    }
    return null;
}

/**
 * Validate a date string (Y-m-d)
 * @param string $value
 * @param string $label
 * @return string|null
 */
function validateDate($value, $label = 'Date') {
    $d = \DateTime::createFromFormat('Y-m-d', $value);
    if (!$d || $d->format('Y-m-d') !== $value) {
        return "$label is not a valid date (YYYY-MM-DD).";
    }
    return null;
}

/**
 * Validate an enum value against allowed list
 * @param string $value
 * @param array  $allowed
 * @param string $label
 * @return string|null
 */
function validateEnum($value, $allowed, $label = 'Field') {
    if (!in_array($value, $allowed, true)) {
        return "$label must be one of: " . implode(', ', $allowed) . '.';
    }
    return null;
}

// ============================================================
// SANITIZATION HELPERS
// ============================================================

/**
 * Sanitize all string values in an array (shallow)
 * @param array $data
 * @return array
 */
function sanitizeArray($data) {
    $clean = [];
    foreach ($data as $key => $value) {
        $clean[sanitize($key)] = is_string($value) ? sanitize($value) : $value;
    }
    return $clean;
}

/**
 * Strip null bytes and control characters from a string
 * @param string $value
 * @return string
 */
function cleanString($value) {
    $value = str_replace("\0", '', $value);
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value);
    return trim($value);
}

/**
 * Sanitize filename to prevent path traversal
 * @param string $filename
 * @return string
 */
function sanitizeFilename($filename) {
    $filename = basename($filename);
    $filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);
    return $filename;
}

// ============================================================
// CSRF PROTECTION
// ============================================================

/**
 * Validate CSRF token from POST body or header
 * Checks $_POST['csrf_token'] then X-CSRF-Token header
 * @return bool
 */
function validateCSRFFromRequest() {
    $token = $_POST['csrf_token']
           ?? $_SERVER['HTTP_X_CSRF_TOKEN']
           ?? '';
    return verifyCSRFToken($token);
}

/**
 * CSRF middleware — call at top of POST-handling API files
 * Sends 403 JSON response if token is invalid
 * @param bool $isAjax  If true, returns JSON; otherwise redirects
 */
function csrfMiddleware($isAjax = false) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;

    if (!validateCSRFFromRequest()) {
        if ($isAjax || (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
            strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')) {
            jsonResponse(['success' => false, 'message' => 'Invalid CSRF token.'], 403);
        } else {
            http_response_code(403);
            die('403 — Invalid CSRF token. Please go back and reload the page.');
        }
    }
}

/**
 * Return a hidden-input HTML string with the CSRF token
 * @return string
 */
function csrfField() {
    return '<input type="hidden" name="csrf_token" value="' . generateCSRFToken() . '">';
}

// ============================================================
// REQUEST METHOD HELPERS
// ============================================================

/**
 * Require POST method; reject with 405 otherwise
 */
function requirePOST() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        die('405 — Method Not Allowed.');
    }
}

/**
 * Require AJAX (XMLHttpRequest) header
 */
function requireAjax() {
    if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) ||
        strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
        http_response_code(400);
        jsonResponse(['success' => false, 'message' => 'AJAX request required.'], 400);
    }
}

/**
 * Get a required POST parameter or exit with error
 * @param string $key
 * @param string $errorResponse  'json' or 'redirect'
 * @return string
 */
function requirePOSTParam($key, $errorResponse = 'json') {
    if (empty($_POST[$key])) {
        if ($errorResponse === 'json') {
            jsonResponse(['success' => false, 'message' => "Missing required field: $key"], 400);
        } else {
            redirect($_SERVER['HTTP_REFERER'] ?? '/', "Missing required field.", 'danger');
        }
    }
    return trim($_POST[$key]);
}

// ============================================================
// RATE LIMITING (file-based, no external dependencies)
// ============================================================

/**
 * Check rate limit for a given key (IP, user, etc.)
 * Uses a simple file-based sliding window counter.
 *
 * @param string $key        Unique identifier (e.g. IP or user ID)
 * @param int    $maxAttempts Maximum allowed attempts in window
 * @param int    $windowSec  Window size in seconds (default 60)
 * @return bool              True = within limit, False = exceeded
 */
function checkRateLimit($key, $maxAttempts = 30, $windowSec = 60) {
    $dir = sys_get_temp_dir() . '/ldb_fras_rate';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    $file    = $dir . '/' . md5($key) . '.json';
    $now     = time();
    $cutoff  = $now - $windowSec;
    $attempts = [];

    if (file_exists($file)) {
        $raw = @file_get_contents($file);
        $attempts = json_decode($raw, true) ?: [];
    }

    // Purge expired entries
    $attempts = array_filter($attempts, fn($ts) => $ts >= $cutoff);

    if (count($attempts) >= $maxAttempts) {
        return false;
    }

    $attempts[] = $now;
    @file_put_contents($file, json_encode(array_values($attempts)), LOCK_EX);
    return true;
}

/**
 * Apply rate limiting middleware — call at top of API files
 * Sends 429 response if limit exceeded
 * @param int $maxAttempts
 * @param int $windowSec
 */
function rateLimitMiddleware($maxAttempts = 60, $windowSec = 60) {
    $ip  = getRealIP();
    $key = 'api_' . $ip;

    if (!checkRateLimit($key, $maxAttempts, $windowSec)) {
        http_response_code(429);
        header('Retry-After: ' . $windowSec);
        jsonResponse([
            'success' => false,
            'message' => 'Too many requests. Please try again later.'
        ], 429);
    }
}

/**
 * Apply login-specific rate limiting (stricter)
 * @param string $identifier  Email or IP
 */
function loginRateLimit($identifier) {
    $key = 'login_' . $identifier;
    if (!checkRateLimit($key, 5, 300)) {
        return false;
    }
    return true;
}

// ============================================================
// IP & USER AGENT HELPERS
// ============================================================

/**
 * Get client's real IP address (handles proxies)
 * @return string
 */
function getRealIP() {
    $headers = [
        'HTTP_CLIENT_IP',
        'HTTP_X_FORWARDED_FOR',
        'HTTP_X_FORWARDED',
        'HTTP_X_CLUSTER_CLIENT_IP',
        'HTTP_FORWARDED_FOR',
        'HTTP_FORWARDED',
        'REMOTE_ADDR'
    ];
    foreach ($headers as $header) {
        if (!empty($_SERVER[$header])) {
            $ips = explode(',', $_SERVER[$header]);
            foreach ($ips as $ip) {
                $ip = trim($ip);
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/**
 * Get a short browser description from User-Agent
 * @param string $ua
 * @return string
 */
function parseUserAgent($ua = '') {
    $ua = $ua ?: ($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown');
    $browsers = ['Chrome' => 'Chrome', 'Firefox' => 'Firefox', 'Safari' => 'Safari', 'Edge' => 'Edge', 'Opera' => 'Opera'];
    foreach ($browsers as $key => $name) {
        if (stripos($ua, $key) !== false) return $name;
    }
    return 'Other';
}

// ============================================================
// SECURITY HEADERS
// ============================================================

/**
 * Send standard security headers for HTTP responses
 */
function sendSecurityHeaders() {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: same-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
}

// ============================================================
// SESSION SECURITY
// ============================================================

/**
 * Check if the session IP has changed (possible hijacking)
 * @return bool  True if suspicious
 */
function isSessionHijacked() {
    if (!isset($_SESSION['user_ip'])) return false;
    $currentIP = getRealIP();
    // Allow same IP or empty stored IP
    if (empty($_SESSION['user_ip']) || $_SESSION['user_ip'] === $currentIP) {
        return false;
    }
    return true;
}

/**
 * Enforce session security: check expiry and hijacking
 * Call after requireLogin()
 * @return void  Redirects to login on failure
 */
function enforceSessionSecurity() {
    if (isSessionExpired()) {
        destroySession();
        header('Location: ' . BASE_URL . '/login.php?expired=1');
        exit();
    }
    if (isSessionHijacked()) {
        destroySession();
        header('Location: ' . BASE_URL . '/login.php?hijack=1');
        exit();
    }
}

// ============================================================
// FILE UPLOAD VALIDATION
// ============================================================

/**
 * Validate an uploaded file
 * @param array  $file         $_FILES entry
 * @param array  $allowedExt   Allowed extensions (e.g. ['jpg','jpeg','png'])
 * @param int    $maxSizeBytes Max file size in bytes (default 5MB)
 * @return string|null         Error message or null if valid
 */
function validateUpload($file, $allowedExt = ['jpg', 'jpeg', 'png'], $maxSizeBytes = 5242880) {
    if (!isset($file) || $file['error'] !== UPLOAD_ERR_OK) {
        return 'File upload failed or no file provided.';
    }
    if ($file['size'] > $maxSizeBytes) {
        $maxMB = round($maxSizeBytes / 1048576, 1);
        return "File size exceeds maximum of {$maxMB} MB.";
    }
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExt, true)) {
        return 'File type not allowed. Allowed: ' . implode(', ', $allowedExt);
    }
    // Check MIME type
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    if (!in_array($mime, $allowedMimes, true)) {
        return 'Invalid file MIME type.';
    }
    return null;
}

// ============================================================
// PASSWORD STRENGTH CHECKER
// ============================================================

/**
 * Check password strength
 * @param string $password
 * @param int    $minLength
 * @return array  ['valid' => bool, 'errors' => [...]]
 */
function checkPasswordStrength($password, $minLength = 8) {
    $errors = [];
    if (strlen($password) < $minLength) {
        $errors[] = "Password must be at least $minLength characters.";
    }
    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] = 'Password must contain at least one uppercase letter.';
    }
    if (!preg_match('/[a-z]/', $password)) {
        $errors[] = 'Password must contain at least one lowercase letter.';
    }
    if (!preg_match('/[0-9]/', $password)) {
        $errors[] = 'Password must contain at least one number.';
    }
    return ['valid' => empty($errors), 'errors' => $errors];
}
