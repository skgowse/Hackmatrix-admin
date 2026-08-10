<?php
/**
 * HackMatrix 1.0 - Authentication & Session Helper
 */

// Secure session initiation
function startSecureSession() {
    if (session_status() === PHP_SESSION_NONE) {
        // Set secure cookie options
        ini_set('session.cookie_httponly', 1);
        ini_set('session.use_only_cookies', 1);
        
        // If running over HTTPS, set secure cookie flag
        $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $_SERVER['SERVER_PORT'] == 443;
        
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => $isSecure,
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
        
        session_start();
    }
}

// Start session immediately
startSecureSession();

/**
 * Check if the admin is logged in and session has not expired.
 * Session timeout is set to 30 minutes of inactivity.
 * 
 * @return bool
 */
function isLoggedIn() {
    if (!isset($_SESSION['admin_id']) || !isset($_SESSION['admin_username'])) {
        return false;
    }
    
    // Check session timeout (30 minutes = 1800 seconds)
    $timeout = 1800; 
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $timeout)) {
        logout();
        return false;
    }
    
    // Update last activity time
    $_SESSION['last_activity'] = time();
    return true;
}

/**
 * Require admin login, redirect if unauthorized
 */
function requireLogin() {
    if (!isLoggedIn()) {
        // Redirect to login page
        $rootPath = '/';
        // Dynamically find root if hosted in subdirectory
        $scriptDir = dirname($_SERVER['SCRIPT_NAME']);
        if (strpos($scriptDir, '/admin') !== false) {
            header("Location: login.php");
        } else {
            header("Location: admin/login.php");
        }
        exit();
    }
}

/**
 * Log in the admin user
 * 
 * @param int $adminId
 * @param string $username
 */
function login($adminId, $username) {
    // Regenerate session ID to prevent session fixation
    session_regenerate_id(true);
    
    $_SESSION['admin_id'] = $adminId;
    $_SESSION['admin_username'] = $username;
    $_SESSION['last_activity'] = time();
}

/**
 * Log out the admin user
 */
function logout() {
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(), 
            '', 
            time() - 42000,
            $params["path"], 
            $params["domain"],
            $params["secure"], 
            $params["httponly"]
        );
    }
    session_destroy();
}

/**
 * Generate CSRF Token and store in session
 * 
 * @return string
 */
function generateCSRFToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Validate incoming CSRF token against session
 * 
 * @param string $token
 * @return bool
 */
function validateCSRFToken($token) {
    if (!isset($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Output hidden CSRF input field for forms
 */
function csrfInput() {
    echo '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(generateCSRFToken(), ENT_QUOTES, 'UTF-8') . '">';
}
