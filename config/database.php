<?php
/**
 * HackMatrix 1.0 - Database Configuration
 */

// Database Connection Settings
define('DB_HOST', '127.0.0.1');
define('DB_PORT', '3307');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'hackmatrix_certificates');

// Security Key for AES-256 SMTP Password Encryption
// IMPORTANT: Keep this key secure and do not change it once passwords are saved in the DB
define('SMTP_CRYPT_KEY', 'HM_Secure_Crypt_Secret_Key_2026#HackMatrix');

/**
 * Returns a PDO database connection instance.
 * Automatically tries to connect using the configured credentials.
 * 
 * @return PDO
 * @throws PDOException
 */
function getDBConnection() {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4";
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            // Attempt connection without dbname if database doesn't exist yet (for setup.php)
            if ($e->getCode() == 1049) {
                $fallbackDsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";charset=utf8mb4";
                $pdo = new PDO($fallbackDsn, DB_USER, DB_PASS, $options);
            } else {
                throw $e;
            }
        }
    }
    return $pdo;
}
