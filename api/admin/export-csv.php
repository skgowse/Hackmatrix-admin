<?php
/**
 * HackMatrix 1.0 - Admin API: Export Filtered Participants to CSV
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

if (!isLoggedIn()) {
    die("Unauthorized access.");
}

$pdo = getDBConnection();

$search = trim($_GET['search'] ?? '');
$domain = trim($_GET['domain'] ?? '');
$teamSize = trim($_GET['team_size'] ?? '');
$status = trim($_GET['status'] ?? '');

try {
    // Write system activity audit log
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $stmtLog = $pdo->prepare("INSERT INTO activity_logs (admin_id, action, details, ip_address) VALUES (?, 'PARTICIPANT_EXPORT', ?, ?)");
    $stmtLog->execute([$_SESSION['admin_id'] ?? null, "Exported participant records to CSV format.", $ip]);
    
    // Construct Query
    $queryStr = "SELECT t.team_id, t.team_name, t.college, t.team_size, t.domain, t.project_title, t.status, t.created_at,
                        tm.role, tm.name, tm.email, tm.mobile, tm.branch, tm.year
                 FROM team_members tm
                 JOIN teams t ON tm.team_id = t.id
                 WHERE 1=1";
    $params = [];
    
    if ($search !== '') {
        $queryStr .= " AND (t.team_id LIKE ? OR t.team_name LIKE ? OR t.college LIKE ? OR t.project_title LIKE ? 
                       OR tm.name LIKE ? OR tm.email LIKE ? OR tm.mobile LIKE ?)";
        $searchWild = '%' . $search . '%';
        $params = array_merge($params, [$searchWild, $searchWild, $searchWild, $searchWild, $searchWild, $searchWild, $searchWild]);
    }
    
    if ($domain !== '') {
        $queryStr .= " AND t.domain = ?";
        $params[] = $domain;
    }
    
    if ($teamSize !== '') {
        $queryStr .= " AND t.team_size = ?";
        $params[] = intval($teamSize);
    }
    
    if ($status !== '') {
        $queryStr .= " AND t.status = ?";
        $params[] = $status;
    }
    
    $queryStr .= " ORDER BY t.id ASC, tm.role DESC, tm.id ASC";
    $stmt = $pdo->prepare($queryStr);
    $stmt->execute($params);
    
    // Output headers
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=participants_export_' . date('Y-m-d') . '.csv');
    
    $output = fopen('php://output', 'w');
    
    // CSV Header row
    fputcsv($output, [
        'Team ID', 
        'Team Name', 
        'College', 
        'Team Size', 
        'Domain', 
        'Project Title', 
        'Role', 
        'Participant Name', 
        'Email', 
        'Mobile', 
        'Branch', 
        'Year', 
        'Registration Date', 
        'Status'
    ]);
    
    while ($row = $stmt->fetch()) {
        fputcsv($output, [
            $row['team_id'],
            $row['team_name'],
            $row['college'],
            $row['team_size'],
            $row['domain'],
            $row['project_title'],
            $row['role'],
            $row['name'],
            $row['email'],
            $row['mobile'],
            $row['branch'],
            $row['year'],
            $row['created_at'],
            $row['status']
        ]);
    }
    
    fclose($output);
    exit();
} catch (Exception $e) {
    die("Export failed: " . $e->getMessage());
}
