<?php
/**
 * HackMatrix 1.0 - API: Check Team Name Availability
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

$pdo = getDBConnection();

$teamName = trim($_GET['name'] ?? '');

if (empty($teamName)) {
    jsonResponse(false, 'Team name is required.', ['available' => false]);
}

try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM teams WHERE LOWER(team_name) = ?");
    $stmt->execute([strtolower($teamName)]);
    $exists = $stmt->fetchColumn() > 0;
    
    if ($exists) {
        jsonResponse(true, 'This team name is already registered.', ['available' => false]);
    } else {
        jsonResponse(true, 'Team name is available.', ['available' => true]);
    }
} catch (Exception $e) {
    jsonResponse(false, 'Database error: ' . $e->getMessage(), ['available' => false]);
}
