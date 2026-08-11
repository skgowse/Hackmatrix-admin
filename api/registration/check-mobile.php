<?php
/**
 * HackMatrix 1.0 - API: Check Mobile Availability
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

$pdo = getDBConnection();

$mobile = trim($_GET['mobile'] ?? '');

if (empty($mobile)) {
    jsonResponse(false, 'Mobile number is required.', ['available' => false]);
}

// Clean and validate Indian format (10 digits)
$cleanMobile = preg_replace('/[^0-9]/', '', $mobile);
if (strlen($cleanMobile) > 10) {
    $cleanMobile = substr($cleanMobile, -10);
}

if (strlen($cleanMobile) !== 10) {
    jsonResponse(false, 'Mobile number must be exactly 10 digits.', ['available' => false]);
}

try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM team_members WHERE mobile = ?");
    $stmt->execute([$cleanMobile]);
    $exists = $stmt->fetchColumn() > 0;
    
    if ($exists) {
        jsonResponse(true, 'This mobile number is already registered.', ['available' => false]);
    } else {
        jsonResponse(true, 'Mobile number is available.', ['available' => true]);
    }
} catch (Exception $e) {
    jsonResponse(false, 'Database error: ' . $e->getMessage(), ['available' => false]);
}
