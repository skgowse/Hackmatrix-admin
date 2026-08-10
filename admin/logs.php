<?php
/**
 * HackMatrix 1.0 - Activity & Email Logs Viewer & Reports
 */

require_once __DIR__ . '/header.php';

$pdo = getDBConnection();
$type = $_GET['type'] ?? 'activity'; // 'activity' or 'email'

// Handle CSV Delivery Report Download
if (isset($_GET['download_report'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=hackmatrix_delivery_report_' . date('Y-m-d') . '.csv');
    
    $output = fopen('php://output', 'w');
    // Header columns
    fputcsv($output, ['participant_name', 'email', 'certificate_id', 'certificate_status', 'email_status', 'sent_at', 'error_message']);
    
    // Join participants, certificates, and email logs
    $query = "SELECT p.participant_name, p.email, p.certificate_id, 
                     COALESCE(c.status, 'PENDING') AS certificate_status, 
                     COALESCE(e.status, 'PENDING') AS email_status, 
                     e.sent_at, e.error_message
              FROM participants p
              LEFT JOIN certificates c ON p.id = c.participant_id
              LEFT JOIN email_logs e ON p.id = e.participant_id
              ORDER BY p.id ASC";
              
    $stmt = $pdo->query($query);
    while ($row = $stmt->fetch()) {
        fputcsv($output, [
            $row['participant_name'],
            $row['email'],
            $row['certificate_id'],
            $row['certificate_status'],
            $row['email_status'],
            $row['sent_at'] ?: 'N/A',
            $row['error_message'] ?: ''
        ]);
    }
    fclose($output);
    exit();
}

$search = trim($_GET['search'] ?? '');
$limit = 15;
$page = max(1, intval($_GET['page'] ?? 1));
$offset = ($page - 1) * $limit;

if ($type === 'email') {
    // ---------------------------------------------------------
    // EMAIL DELIVERY LOGS
    // ---------------------------------------------------------
    $queryStr = "SELECT e.*, p.participant_name 
                 FROM email_logs e 
                 JOIN participants p ON e.participant_id = p.id 
                 WHERE 1=1";
    $params = [];
    
    if ($search !== '') {
        $queryStr .= " AND (p.participant_name LIKE ? OR e.email LIKE ? OR e.certificate_id LIKE ? OR e.status LIKE ?)";
        $searchWild = '%' . $search . '%';
        $params = [$searchWild, $searchWild, $searchWild, $searchWild];
    }
    
    // Total count for paging
    $countQueryStr = str_replace("SELECT e.*, p.participant_name", "SELECT COUNT(*)", $queryStr);
    $stmtCount = $pdo->prepare($countQueryStr);
    $stmtCount->execute($params);
    $totalRows = $stmtCount->fetchColumn();
    $totalPages = ceil($totalRows / $limit);
    
    $queryStr .= " ORDER BY e.id DESC LIMIT $limit OFFSET $offset";
    $stmt = $pdo->prepare($queryStr);
    $stmt->execute($params);
    $logs = $stmt->fetchAll();
    
} else {
    // ---------------------------------------------------------
    // ACTIVITY LOGS
    // ---------------------------------------------------------
    $queryStr = "SELECT l.*, a.username 
                 FROM activity_logs l 
                 LEFT JOIN admins a ON l.admin_id = a.id 
                 WHERE 1=1";
    $params = [];
    
    if ($search !== '') {
        $queryStr .= " AND (l.action LIKE ? OR l.details LIKE ? OR l.ip_address LIKE ? OR a.username LIKE ?)";
        $searchWild = '%' . $search . '%';
        $params = [$searchWild, $searchWild, $searchWild, $searchWild];
    }
    
    // Total count for paging
    $countQueryStr = str_replace("SELECT l.*, a.username", "SELECT COUNT(*)", $queryStr);
    $stmtCount = $pdo->prepare($countQueryStr);
    $stmtCount->execute($params);
    $totalRows = $stmtCount->fetchColumn();
    $totalPages = ceil($totalRows / $limit);
    
    $queryStr .= " ORDER BY l.id DESC LIMIT $limit OFFSET $offset";
    $stmt = $pdo->prepare($queryStr);
    $stmt->execute($params);
    $logs = $stmt->fetchAll();
}
?>

<div class="page-header">
    <div class="page-title">
        <h1>Audit & Delivery Logs</h1>
        <p>Review system activities, trace security operations, and export email logs.</p>
    </div>
    <div class="btn-group">
        <a href="logs.php?download_report=1" class="btn btn-primary">
            <svg viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            Download Delivery CSV Report
        </a>
    </div>
</div>

<!-- Tab Switch Header -->
<div style="display: flex; gap: 16px; border-bottom: 1px solid var(--border-color); padding-bottom: 2px; margin-bottom: 24px;">
    <a href="logs.php?type=activity" class="btn <?= $type === 'activity' ? 'btn-primary' : 'btn-secondary' ?>" style="border-radius: 8px 8px 0 0; padding: 12px 24px; border: none; margin-bottom: -3px;">
        Admin Activity Logs
    </a>
    <a href="logs.php?type=email" class="btn <?= $type === 'email' ? 'btn-primary' : 'btn-secondary' ?>" style="border-radius: 8px 8px 0 0; padding: 12px 24px; border: none; margin-bottom: -3px;">
        Email Transmission Logs
    </a>
</div>

<!-- Search controls -->
<div class="card" style="padding: 16px 24px;">
    <form method="GET" class="table-controls">
        <input type="hidden" name="type" value="<?= e($type) ?>">
        <div class="search-box">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            <input type="text" name="search" class="form-control" placeholder="Search logs..." value="<?= e($search) ?>">
        </div>
        <div>
            <button type="submit" class="btn btn-secondary">Search</button>
            <?php if ($search !== ''): ?>
                <a href="logs.php?type=<?= e($type) ?>" class="btn btn-secondary" style="padding: 12px;">Clear</a>
            <?php endif; ?>
        </div>
    </form>

    <div class="table-container">
        <?php if ($type === 'email'): ?>
            <!-- Email Logs Table -->
            <table class="table">
                <thead>
                    <tr>
                        <th style="width: 80px;">ID</th>
                        <th>Participant Name</th>
                        <th>Email Recipient</th>
                        <th>Certificate ID</th>
                        <th>Sent Time</th>
                        <th>Status</th>
                        <th>Retries</th>
                        <th>Details / Errors</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($logs) > 0): ?>
                        <?php foreach ($logs as $log): ?>
                            <tr>
                                <td style="font-family: var(--font-mono); font-weight: 700; color: var(--text-muted);"><?= $log['id'] ?></td>
                                <td style="font-weight: 600; color: white;"><?= e($log['participant_name']) ?></td>
                                <td><?= e($log['email']) ?></td>
                                <td style="font-family: var(--font-mono); font-weight: 700; color: var(--accent-primary);"><?= e($log['certificate_id']) ?></td>
                                <td style="font-size: 13px;"><?= $log['sent_at'] ? e($log['sent_at']) : 'Pending' ?></td>
                                <td>
                                    <?php if ($log['status'] === 'SENT'): ?>
                                        <span class="badge badge-success">Sent</span>
                                    <?php elseif ($log['status'] === 'FAILED'): ?>
                                        <span class="badge badge-danger">Failed</span>
                                    <?php elseif ($log['status'] === 'SENDING'): ?>
                                        <span class="badge badge-info">Sending</span>
                                    <?php else: ?>
                                        <span class="badge badge-warning">Pending</span>
                                    <?php endif; ?>
                                </td>
                                <td style="font-family: var(--font-mono); text-align: center;"><?= $log['retry_count'] ?></td>
                                <td style="font-size: 12px; font-family: var(--font-mono); max-width: 250px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?= e($log['error_message']) ?>">
                                    <?= $log['error_message'] ? e($log['error_message']) : '<span style="color: var(--text-muted);">None</span>' ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="8" style="text-align: center; padding: 40px; color: var(--text-muted);">No email logs found.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
            
        <?php else: ?>
            <!-- Activity Logs Table -->
            <table class="table">
                <thead>
                    <tr>
                        <th style="width: 80px;">ID</th>
                        <th>Operator</th>
                        <th>Action</th>
                        <th>Details Context</th>
                        <th>IP Address</th>
                        <th>Timestamp</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($logs) > 0): ?>
                        <?php foreach ($logs as $log): ?>
                            <tr>
                                <td style="font-family: var(--font-mono); font-weight: 700; color: var(--text-muted);"><?= $log['id'] ?></td>
                                <td style="font-weight: 600; color: white;"><?= e($log['username'] ?: 'System') ?></td>
                                <td>
                                    <span class="badge <?= strpos($log['action'], 'FAILED') !== false ? 'badge-danger' : 'badge-info' ?>">
                                        <?= e($log['action']) ?>
                                    </span>
                                </td>
                                <td style="font-size: 13px; max-width: 300px; word-wrap: break-word;"><?= e($log['details']) ?></td>
                                <td style="font-family: var(--font-mono); font-size: 13px;"><?= e($log['ip_address']) ?></td>
                                <td style="font-size: 13px;"><?= e($log['created_at']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" style="text-align: center; padding: 40px; color: var(--text-muted);">No activity logs found.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <span style="font-size: 13px; color: var(--text-muted);">Showing <?= $offset + 1 ?> to <?= min($offset + $limit, $totalRows) ?> of <?= $totalRows ?> rows</span>
            <div class="pagination-links">
                <?php if ($page > 1): ?>
                    <a href="logs.php?type=<?= e($type) ?>&page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>">&laquo; Prev</a>
                <?php endif; ?>
                
                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <?php if ($i == $page): ?>
                        <span class="active"><?= $i ?></span>
                    <?php else: ?>
                        <a href="logs.php?type=<?= e($type) ?>&page=<?= $i ?>&search=<?= urlencode($search) ?>"><?= $i ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
                
                <?php if ($page < $totalPages): ?>
                    <a href="logs.php?type=<?= e($type) ?>&page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>">Next &raquo;</a>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
