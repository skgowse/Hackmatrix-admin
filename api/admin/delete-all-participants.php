<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

// Enforce login
requireLogin();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

try {
    $pdo = getDBConnection();
    
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");
    $pdo->exec("TRUNCATE TABLE email_logs;");
    $pdo->exec("TRUNCATE TABLE certificates;");
    $pdo->exec("TRUNCATE TABLE team_members;");
    $pdo->exec("TRUNCATE TABLE teams;");
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");
    
    // Clean up physical files in certificates folder
    $certDir = __DIR__ . '/../../certificates';
    if (is_dir($certDir)) {
        $files = glob($certDir . '/*.pdf');
        foreach ($files as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }
    
    echo json_encode(['success' => true, 'message' => 'All teams, participants, certificates, and logs have been permanently deleted.']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
