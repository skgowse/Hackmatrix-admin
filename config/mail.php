<?php
/**
 * HackMatrix 1.0 - PHPMailer Loader
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/../includes/functions.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * Returns an instance of PHPMailer configured with the SMTP settings from the database.
 * 
 * @param array|null $smtp Custom settings array (optional, e.g. for testing unsaved settings)
 * @return PHPMailer
 * @throws Exception
 */
function getMailerInstance($smtp = null) {
    if ($smtp === null) {
        $pdo = getDBConnection();
        $smtp = $pdo->query("SELECT * FROM smtp_settings LIMIT 1")->fetch();
    }
    
    if (!$smtp) {
        throw new Exception("SMTP settings are not configured in the database.");
    }
    
    $mail = new PHPMailer(true);
    
    // Server settings
    $mail->isSMTP();
    $mail->Host       = $smtp['smtp_host'];
    $mail->SMTPAuth   = true;
    $mail->Username   = $smtp['smtp_username'];
    
    // Decrypt password
    $decryptedPassword = decryptSMTPPassword($smtp['smtp_password']);
    $mail->Password   = $decryptedPassword;
    
    // Encryption setup
    $encryption = strtolower($smtp['smtp_encryption']);
    if ($encryption === 'tls' || $encryption === 'starttls') {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = $smtp['smtp_port'] ?: 587;
    } elseif ($encryption === 'ssl') {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port       = $smtp['smtp_port'] ?: 465;
    } else {
        $mail->SMTPSecure = '';
        $mail->SMTPAutoTLS = false;
        $mail->Port       = $smtp['smtp_port'] ?: 25;
    }
    
    // Sender configuration
    $mail->setFrom($smtp['from_email'], $smtp['from_name']);
    
    // Charset for multi-lingual candidates
    $mail->CharSet = 'UTF-8';
    
    return $mail;
}
