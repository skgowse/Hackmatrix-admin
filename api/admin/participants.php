<?php
/**
 * HackMatrix 1.0 - Admin API: List & Filter Teams
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

if (!isLoggedIn()) {
    jsonResponse(false, 'Unauthorized access.');
}

$pdo = getDBConnection();

$search = trim($_GET['search'] ?? '');
$domain = trim($_GET['domain'] ?? '');
$teamSize = trim($_GET['team_size'] ?? '');
$status = trim($_GET['status'] ?? '');
$limit = max(1, intval($_GET['limit'] ?? 10));
$page = max(1, intval($_GET['page'] ?? 1));
$offset = ($page - 1) * $limit;

try {
    $queryStr = "SELECT t.*, 
                 (SELECT name FROM team_members WHERE team_id = t.id AND role = 'Team Lead' LIMIT 1) AS team_lead,
                 (SELECT email FROM team_members WHERE team_id = t.id AND role = 'Team Lead' LIMIT 1) AS lead_email
                 FROM teams t WHERE 1=1";
    $params = [];
    
    if ($search !== '') {
        $queryStr .= " AND (t.team_id LIKE ? OR t.team_name LIKE ? OR t.college LIKE ? OR t.project_title LIKE ? 
                       OR EXISTS (SELECT 1 FROM team_members WHERE team_id = t.id AND (name LIKE ? OR email LIKE ? OR mobile LIKE ?)))";
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
    
    // Count total rows
    $countQueryStr = "SELECT COUNT(*) FROM (" . $queryStr . ") AS count_table";
    $stmtCount = $pdo->prepare($countQueryStr);
    $stmtCount->execute($params);
    $totalRows = $stmtCount->fetchColumn();
    $totalPages = ceil($totalRows / $limit);
    
    // Retrieve paginated records
    $queryStr .= " ORDER BY t.id DESC LIMIT $limit OFFSET $offset";
    $stmt = $pdo->prepare($queryStr);
    $stmt->execute($params);
    $teams = $stmt->fetchAll();
    
    jsonResponse(true, 'Teams retrieved successfully.', [
        'teams' => $teams,
        'pagination' => [
            'total_rows' => (int)$totalRows,
            'total_pages' => (int)$totalPages,
            'current_page' => $page,
            'limit' => $limit
        ]
    ]);
} catch (Exception $e) {
    jsonResponse(false, 'Database query failed: ' . $e->getMessage());
}
