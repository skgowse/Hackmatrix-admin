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

/**
 * Perform strong validation on an email address:
 * - Check format
 * - Check for common typo domains (e.g. gnail.com, gamil.com, yaho.com)
 * - Perform a DNS check (MX/A record search)
 * 
 * @param string $email
 * @return string|true Returns true if valid, or a string error message if invalid.
 */
function validateEmailStrongly($email) {
    $email = strtolower(trim($email));
    
    // 1. Basic filter validation
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return 'Invalid email format.';
    }
    
    // 2. Extract domain part
    $parts = explode('@', $email);
    $domain = end($parts);
    
    // 3. Known typo domain list
    $typos = [
        'gamil.com' => 'gmail.com',
        'gnail.com' => 'gmail.com',
        'gmsil.com' => 'gmail.com',
        'gmial.com' => 'gmail.com',
        'gmaill.com' => 'gmail.com',
        'gmal.com' => 'gmail.com',
        'gmil.com' => 'gmail.com',
        'gail.com' => 'gmail.com',
        'gmeil.com' => 'gmail.com',
        'gmaile.com' => 'gmail.com',
        'gmail.co' => 'gmail.com',
        'gamil.co' => 'gmail.com',
        'gnail.co' => 'gmail.com',
        'gmsil.co' => 'gmail.com',
        'yahooo.com' => 'yahoo.com',
        'yaho.com' => 'yahoo.com',
        'yahu.com' => 'yahoo.com',
        'yhoo.com' => 'yahoo.com',
        'outlok.com' => 'outlook.com',
        'outloo.com' => 'outlook.com',
        'hotmale.com' => 'hotmail.com',
        'hotmial.com' => 'hotmail.com',
        'hotmil.com' => 'hotmail.com'
    ];
    
    if (array_key_exists($domain, $typos)) {
        return "Invalid email domain. Did you mean '{$typos[$domain]}'?";
    }
    
    // 4. DNS MX record validation (only when connected online)
    if (function_exists('checkdnsrr')) {
        $hasMX = @checkdnsrr($domain, 'MX');
        if (!$hasMX) {
            $hasA = @checkdnsrr($domain, 'A');
            $hasAAAA = @checkdnsrr($domain, 'AAAA');
            if (!$hasA && !$hasAAAA) {
                return "The email domain '{$domain}' does not exist or has no active mail server.";
            }
        }
    }
    
    return true;
}
