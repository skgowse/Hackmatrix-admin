<?php
/**
 * HackMatrix 1.0 - Core Helper Functions
 */

require_once __DIR__ . '/../config/database.php';

/**
 * Escapes HTML output to prevent Cross-Site Scripting (XSS)
 * 
 * @param string|null $text
 * @return string
 */
function e($text) {
    if ($text === null) {
        return '';
    }
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

/**
 * Encrypts SMTP password securely using AES-256-CBC
 * 
 * @param string $password
 * @return string Encrypted string in base64 format (with IV appended)
 */
function encryptSMTPPassword($password) {
    $cipher = "aes-256-cbc";
    $key = hash('sha256', SMTP_CRYPT_KEY, true);
    $ivlen = openssl_cipher_iv_length($cipher);
    $iv = random_bytes($ivlen);
    $ciphertext_raw = openssl_encrypt($password, $cipher, $key, OPENSSL_RAW_DATA, $iv);
    // Combine IV and ciphertext and base64 encode
    return base64_encode($iv . $ciphertext_raw);
}

/**
 * Decrypts SMTP password using AES-256-CBC
 * 
 * @param string $encryptedPassword
 * @return string Decrypted password string
 */
function decryptSMTPPassword($encryptedPassword) {
    $cipher = "aes-256-cbc";
    $key = hash('sha256', SMTP_CRYPT_KEY, true);
    $c = base64_decode($encryptedPassword);
    $ivlen = openssl_cipher_iv_length($cipher);
    if (strlen($c) < $ivlen) {
        return '';
    }
    $iv = substr($c, 0, $ivlen);
    $ciphertext_raw = substr($c, $ivlen);
    $original_plaintext = openssl_decrypt($ciphertext_raw, $cipher, $key, OPENSSL_RAW_DATA, $iv);
    return $original_plaintext ?: '';
}

/**
 * Write a log entry to the admin activity log in the database
 * 
 * @param string $action Action name
 * @param string|null $details Contextual details about the action
 */
function logActivity($action, $details = null) {
    try {
        $pdo = getDBConnection();
        
        // Get admin ID from session if logged in
        $adminId = isset($_SESSION['admin_id']) ? $_SESSION['admin_id'] : null;
        
        // Get IP Address
        $ipAddress = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '127.0.0.1';
        // Handle proxy IPs if necessary
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ipAddress = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
        }
        
        $stmt = $pdo->prepare("INSERT INTO activity_logs (admin_id, action, details, ip_address) VALUES (?, ?, ?, ?)");
        $stmt->execute([$adminId, $action, $details, trim($ipAddress)]);
    } catch (Exception $e) {
        // Fallback: write to PHP error log if DB connection fails
        error_log("Failed to log activity: " . $e->getMessage());
    }
}

/**
 * Sends a standardized JSON response and exits
 * 
 * @param bool $success
 * @param string $message
 * @param array $data
 */
function jsonResponse($success, $message, $data = []) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message
    ], $data));
    exit();
}

/**
 * Helper to check file upload size and error codes
 * 
 * @param array $file $_FILES['input_name']
 * @param int $maxSize Max size in bytes
 * @param array $allowedExtensions Array of lowercase file extensions
 * @return string|true Error message string if invalid, or true if valid
 */
function validateUploadedFile($file, $maxSize, $allowedExtensions) {
    if (!isset($file) || $file['error'] !== UPLOAD_ERR_OK) {
        switch ($file['error'] ?? UPLOAD_ERR_NO_FILE) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return "The uploaded file exceeds the maximum allowed size.";
            case UPLOAD_ERR_PARTIAL:
                return "The file was only partially uploaded.";
            case UPLOAD_ERR_NO_FILE:
                return "No file was uploaded.";
            default:
                return "File upload failed with error code: " . ($file['error'] ?? 'unknown');
        }
    }
    
    // Check file size
    if ($file['size'] > $maxSize) {
        return "The file size (" . round($file['size'] / (1024 * 1024), 2) . " MB) exceeds the limit of " . round($maxSize / (1024 * 1024), 2) . " MB.";
    }
    
    // Validate file extension
    $filename = $file['name'];
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if (!in_array($extension, $allowedExtensions)) {
        return "Invalid file extension. Allowed extensions are: " . implode(', ', $allowedExtensions);
    }
    
    return true;
}
