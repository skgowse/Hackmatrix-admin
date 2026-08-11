<?php
/**
 * HackMatrix 1.0 - Admin API: View Team Details & Members
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

if (!isLoggedIn()) {
    jsonResponse(false, 'Unauthorized access.');
}

$pdo = getDBConnection();

$teamId = intval($_GET['id'] ?? 0);

if ($teamId <= 0) {
    jsonResponse(false, 'Invalid team ID.');
}

try {
    // 1. Fetch team
    $stmt = $pdo->prepare("SELECT * FROM teams WHERE id = ?");
    $stmt->execute([$teamId]);
    $team = $stmt->fetch();
    
    if (!$team) {
        jsonResponse(false, 'Team not found.');
    }
    
    // 2. Fetch members
    $stmt = $pdo->prepare("SELECT * FROM team_members WHERE team_id = ? ORDER BY id ASC");
    $stmt->execute([$teamId]);
    $members = $stmt->fetchAll();
    
    jsonResponse(true, 'Team details loaded.', [
        'team' => $team,
        'members' => $members
    ]);
} catch (Exception $e) {
    jsonResponse(false, 'Database error: ' . $e->getMessage());
}
