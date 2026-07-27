<?php
/**
 * LDB-FRAS - Session Management
 * Handles user authentication state and role-based access
 */

/**
 * Check if user is logged in
 * @return bool
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

/**
 * Require user to be logged in, redirect if not
 */
function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: ' . BASE_URL . '/login.php');
        exit();
    }
}

/**
 * Require specific user role
 * @param string|array $roles
 */
function requireRole($roles) {
    requireLogin();
    
    if (is_string($roles)) {
        $roles = [$roles];
    }
    
    if (!isset($_SESSION['user_role']) || !in_array($_SESSION['user_role'], $roles)) {
        header('Location: ' . BASE_URL . '/unauthorized.php');
        exit();
    }
}

/**
 * Get current user ID
 * @return int|null
 */
function getCurrentUserId() {
    return $_SESSION['user_id'] ?? null;
}

/**
 * Get current user role
 * @return string|null
 */
function getCurrentUserRole() {
    return $_SESSION['user_role'] ?? null;
}

/**
 * Get current user email
 * @return string|null
 */
function getCurrentUserEmail() {
    return $_SESSION['user_email'] ?? null;
}

/**
 * Set user session after login
 * @param array $user
 */
function setUserSession($user) {
    $_SESSION['user_id']    = $user['id'];
    $_SESSION['user_role']  = $user['role'];
    $_SESSION['user_email'] = $user['email'];
    $_SESSION['login_time'] = time();
    $_SESSION['user_ip']    = $_SERVER['REMOTE_ADDR'] ?? '';
    // Ensure a CSRF token exists for this authenticated session so that
    // AJAX/API POST requests validate correctly even before any page render.
    generateCSRFToken();
}

/**
 * Destroy user session (logout)
 */
function destroySession() {
    session_unset();
    session_destroy();
}

/**
 * Check session timeout (30 minutes)
 * @return bool
 */
function isSessionExpired() {
    $timeout = 1800; // 30 minutes in seconds
    if (isset($_SESSION['login_time'])) {
        return (time() - $_SESSION['login_time'] > $timeout);
    }
    return true;
}

/**
 * Regenerate session ID to prevent fixation attacks
 */
function regenerateSession() {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
}
