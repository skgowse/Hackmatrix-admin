<?php
/**
 * HackMatrix 1.0 - Admin API: Delete Team & Members
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

if (!isLoggedIn()) {
    jsonResponse(false, 'Unauthorized access.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Only POST requests are allowed.');
}

$pdo = getDBConnection();

// CSRF check
$csrfToken = $_POST['csrf_token'] ?? '';
if (!validateCSRFToken($csrfToken)) {
    jsonResponse(false, 'Invalid security token (CSRF failure).');
}

$teamId = intval($_POST['id'] ?? 0);
if ($teamId <= 0) {
    jsonResponse(false, 'Invalid team ID.');
}

try {
    // Retrieve team details for logs before delete
    $stmt = $pdo->prepare("SELECT team_id, team_name, team_size FROM teams WHERE id = ?");
    $stmt->execute([$teamId]);
    $team = $stmt->fetch();
    
    if (!$team) {
        jsonResponse(false, 'Team not found or already deleted.');
    }
    
    $pdo->beginTransaction();
    
    // Delete team (cascade constraints will delete members, certificates, email_logs)
    $stmt = $pdo->prepare("DELETE FROM teams WHERE id = ?");
    $stmt->execute([$teamId]);
    
    // Log activity
    $adminId = $_SESSION['admin_id'] ?? null;
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $stmtLog = $pdo->prepare("INSERT INTO activity_logs (admin_id, action, details, ip_address) VALUES (?, 'PARTICIPANT_DELETED', ?, ?)");
    $stmtLog->execute([$adminId, "Deleted team: " . $team['team_name'] . " (" . $team['team_id'] . ") with " . $team['team_size'] . " members.", $ip]);
    
    $pdo->commit();
    
    jsonResponse(true, 'Team deleted successfully.');
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    jsonResponse(false, 'Database deletion failed: ' . $e->getMessage());
}
