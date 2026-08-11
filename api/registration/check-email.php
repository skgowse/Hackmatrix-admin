<?php
/**
 * HackMatrix 1.0 - API: Check Email Availability (Global)
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

$pdo = getDBConnection();

$email = trim($_GET['email'] ?? '');

if (empty($email)) {
    jsonResponse(false, 'Email address is required.', ['available' => false]);
}

$emailValid = validateEmailStrongly($email);
if ($emailValid !== true) {
    jsonResponse(false, $emailValid, ['available' => false]);
}

try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM team_members WHERE LOWER(email) = ?");
    $stmt->execute([strtolower($email)]);
    $exists = $stmt->fetchColumn() > 0;
    
    if ($exists) {
        jsonResponse(true, 'This email is already registered for HACKMATRIX 1.0.', ['available' => false]);
    } else {
        jsonResponse(true, 'Email is available.', ['available' => true]);
    }
} catch (Exception $e) {
    jsonResponse(false, 'Database error: ' . $e->getMessage(), ['available' => false]);
}
