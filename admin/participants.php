<?php
/**
 * HackMatrix 1.0 - Participant Management
 */

@set_time_limit(0);
@ini_set('memory_limit', '512M');

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

// Enforce login on all admin pages
requireLogin();

$pdo = getDBConnection();
$error = '';
$successMsg = '';
$invalidRows = [];
$importCount = 0;

// 1. Handle CSV Export (MUST be handled before outputting any HTML content)
if (isset($_GET['export'])) {
    // Generate CSV output directly to browser
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=participants_export_' . date('Y-m-d') . '.csv');
    
    $output = fopen('php://output', 'w');
    // Header row
    fputcsv($output, ['team_no', 'team_name', 'participant_name', 'branch', 'email', 'certificate_id']);
    
    $stmt = $pdo->query("SELECT team_no, team_name, participant_name, branch, email, certificate_id FROM participants ORDER BY id ASC");
    while ($row = $stmt->fetch()) {
        fputcsv($output, $row);
    }
    fclose($output);
    exit();
}

require_once __DIR__ . '/header.php';

// 2. Handle CSV Upload and Import
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import_csv'])) {
    $csrfToken = $_POST['csrf_token'] ?? '';
    
    if (!validateCSRFToken($csrfToken)) {
        $error = 'Invalid security token (CSRF failure).';
    } elseif (empty($_FILES['csv_file']['name'])) {
        $error = 'Please select a CSV file to upload.';
    } else {
        $file = $_FILES['csv_file'];
        $fileExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        
        if ($fileExt !== 'csv') {
            $error = 'Invalid file format. Please upload a .csv file.';
        } else {
            $handle = fopen($file['tmp_name'], 'r');
            if ($handle !== false) {
                // Get header row
                $header = fgetcsv($handle, 1000, ',');
                $header = array_map('trim', $header);
                
                // Determine format
                $isTeamFormat = (count($header) > 6);
                
                $validRowsToInsert = [];
                $emailsInBatch = [];
                $certIdsInBatch = [];
                $rowNum = 1; // Row 1 is header, data starts at 2
                
                // Fetch existing emails and certificate IDs from DB to verify duplicates
                $existingEmails = [];
                $existingCerts = [];
                
                $stmt = $pdo->query("SELECT email, certificate_id FROM participants");
                while ($dbRow = $stmt->fetch()) {
                    $existingEmails[strtolower($dbRow['email'])] = true;
                    $existingCerts[strtolower($dbRow['certificate_id'])] = true;
                }
                
                $totalDBCount = $pdo->query("SELECT COUNT(*) FROM participants")->fetchColumn();
                
                while (($row = fgetcsv($handle, 1000, ',')) !== false) {
                    $rowNum++;
                    
                    // Skip empty rows
                    if (empty(array_filter($row))) {
                        continue;
                    }
                    
                    $rowErrors = [];
                    
                    if ($isTeamFormat) {
                        // Multi-member team format
                        $teamName = trim($row[0] ?? '');
                        if (empty($teamName)) {
                            $teamName = "Team " . ($rowNum - 1);
                        }
                        $teamNo = sprintf("HM-T%03d", $rowNum - 1);
                        
                        $rowMembers = [];
                        
                        // Parse in blocks of 3 columns starting at index 1
                        for ($i = 1; $i < count($row); $i += 3) {
                            $partName = trim($row[$i] ?? '');
                            if (empty($partName)) {
                                continue;
                            }
                            
                            $val1 = trim($row[$i + 1] ?? '');
                            $val2 = trim($row[$i + 2] ?? '');
                            
                            // Heuristic: identify which value is the email
                            $email = '';
                            $branch = '';
                            if (strpos($val1, '@') !== false) {
                                $email = $val1;
                                $branch = $val2;
                            } elseif (strpos($val2, '@') !== false) {
                                $email = $val2;
                                $branch = $val1;
                            } else {
                                // Fallback
                                $email = $val1;
                                $branch = $val2;
                            }
                            
                            $memberErrors = [];
                            if (empty($email)) {
                                $memberErrors[] = "Email missing for $partName.";
                            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                                $memberErrors[] = "Invalid email format ($email) for $partName.";
                            }
                            
                            $lowerEmail = strtolower($email);
                            if (!empty($email) && in_array($lowerEmail, $emailsInBatch)) {
                                $memberErrors[] = "Duplicate email ($email) within CSV.";
                            }
                            if (!empty($email) && isset($existingEmails[$lowerEmail])) {
                                $memberErrors[] = "Email $email already exists in DB.";
                            }
                            
                            if (count($memberErrors) > 0) {
                                $rowErrors = array_merge($rowErrors, $memberErrors);
                            } else {
                                $rowMembers[] = [
                                    'participant_name' => $partName,
                                    'email' => $email,
                                    'branch' => $branch
                                ];
                            }
                        }
                        
                        if (count($rowErrors) > 0) {
                            $invalidRows[] = [
                                'row_num' => $rowNum,
                                'data' => implode(', ', array_slice($row, 0, 7)) . '...',
                                'errors' => implode(' | ', $rowErrors)
                            ];
                        } else {
                            foreach ($rowMembers as $m) {
                                $totalDBCount++;
                                $certId = sprintf("HM26-%04d", $totalDBCount);
                                
                                $validRowsToInsert[] = [
                                    'team_no' => $teamNo,
                                    'team_name' => $teamName,
                                    'participant_name' => $m['participant_name'],
                                    'branch' => $m['branch'],
                                    'email' => $m['email'],
                                    'certificate_id' => $certId
                                ];
                                
                                $emailsInBatch[] = strtolower($m['email']);
                                $certIdsInBatch[] = strtolower($certId);
                            }
                        }
                        
                    } else {
                        // Standard 6-column format
                        $teamNo = trim($row[0] ?? '');
                        $teamName = trim($row[1] ?? '');
                        $partName = trim($row[2] ?? '');
                        $branch = trim($row[3] ?? '');
                        $email = trim($row[4] ?? '');
                        $certId = trim($row[5] ?? '');
                        
                        if (empty($teamNo) || empty($teamName) || empty($partName) || empty($branch) || empty($email) || empty($certId)) {
                            $rowErrors[] = 'Missing required fields.';
                        }
                        
                        if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                            $rowErrors[] = 'Invalid email address format.';
                        }
                        
                        $lowerEmail = strtolower($email);
                        if (!empty($email) && in_array($lowerEmail, $emailsInBatch)) {
                            $rowErrors[] = 'Duplicate email address within CSV.';
                        }
                        
                        $lowerCertId = strtolower($certId);
                        if (!empty($certId) && in_array($lowerCertId, $certIdsInBatch)) {
                            $rowErrors[] = 'Duplicate Certificate ID within CSV.';
                        }
                        
                        if (!empty($email) && isset($existingEmails[$lowerEmail])) {
                            $rowErrors[] = 'Email address already exists in database.';
                        }
                        
                        if (!empty($certId) && isset($existingCerts[$lowerCertId])) {
                            $rowErrors[] = 'Certificate ID already exists in database.';
                        }
                        
                        if (count($rowErrors) > 0) {
                            $invalidRows[] = [
                                'row_num' => $rowNum,
                                'data' => implode(', ', $row),
                                'errors' => implode(' | ', $rowErrors)
                            ];
                        } else {
                            $validRowsToInsert[] = [
                                'team_no' => $teamNo,
                                'team_name' => $teamName,
                                'participant_name' => $partName,
                                'branch' => $branch,
                                'email' => $email,
                                'certificate_id' => $certId
                            ];
                            
                            $emailsInBatch[] = $lowerEmail;
                            $certIdsInBatch[] = $lowerCertId;
                        }
                    }
                }
                fclose($handle);
                    
                    // Insert valid rows
                    if (count($validRowsToInsert) > 0) {
                        try {
                            $pdo->beginTransaction();
                            $insertStmt = $pdo->prepare("INSERT INTO participants (team_no, team_name, participant_name, branch, email, certificate_id) VALUES (?, ?, ?, ?, ?, ?)");
                            $certStmt = $pdo->prepare("INSERT INTO certificates (participant_id, certificate_id, file_path, status) VALUES (?, ?, '', 'PENDING')");
                            $mailStmt = $pdo->prepare("INSERT INTO email_logs (participant_id, certificate_id, email, status) VALUES (?, ?, ?, 'PENDING')");
                            
                            foreach ($validRowsToInsert as $vr) {
                                $insertStmt->execute([
                                    $vr['team_no'],
                                    $vr['team_name'],
                                    $vr['participant_name'],
                                    $vr['branch'],
                                    $vr['email'],
                                    $vr['certificate_id']
                                ]);
                                
                                $participantId = $pdo->lastInsertId();
                                
                                // Auto initialize certificate status record
                                $certStmt->execute([$participantId, $vr['certificate_id']]);
                                // Auto initialize email delivery status record
                                $mailStmt->execute([$participantId, $vr['certificate_id'], $vr['email']]);
                                
                                $importCount++;
                            }
                            $pdo->commit();
                            
                            $successMsg = "Successfully imported $importCount participants.";
                            logActivity('CSV_UPLOADED', "Imported CSV with $importCount participants. Found " . count($invalidRows) . " invalid rows.");
                        } catch (Exception $e) {
                            $pdo->rollBack();
                            $error = 'Import database transaction failed: ' . $e->getMessage();
                        }
                    } else {
                        $error = 'No valid rows found in the uploaded CSV.';
                    }
            } else {
                $error = 'Failed to read the uploaded CSV file.';
            }
        }
    }
}

// 3. Handle Manual Participant Add/Edit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['add_participant']) || isset($_POST['edit_participant']))) {
    $csrfToken = $_POST['csrf_token'] ?? '';
    
    if (!validateCSRFToken($csrfToken)) {
        $error = 'Invalid security token (CSRF failure).';
    } else {
        $partName = trim($_POST['participant_name'] ?? '');
        $branch = trim($_POST['branch'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $isEdit = isset($_POST['edit_participant']);
        $participantId = intval($_POST['participant_id'] ?? 0);
        
        if (empty($partName) || empty($branch) || empty($email)) {
            $error = 'All fields are required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Invalid email address format.';
        } else {
            try {
                // Check duplicate email
                $dupEmailQuery = $isEdit ? "SELECT COUNT(*) FROM participants WHERE email = ? AND id != ?" : "SELECT COUNT(*) FROM participants WHERE email = ?";
                $dupEmailParams = $isEdit ? [$email, $participantId] : [$email];
                $stmt = $pdo->prepare($dupEmailQuery);
                $stmt->execute($dupEmailParams);
                $emailDup = $stmt->fetchColumn() > 0;
                
                if ($emailDup) {
                    $error = 'The email address is already in use by another participant.';
                } else {
                    if ($isEdit) {
                        $pdo->beginTransaction();
                        // Update participant (only name, branch, and email)
                        $stmt = $pdo->prepare("UPDATE participants SET participant_name = ?, branch = ?, email = ? WHERE id = ?");
                        $stmt->execute([$partName, $branch, $email, $participantId]);
                        
                        // Update email_logs structures if email changed
                        $stmt = $pdo->prepare("UPDATE email_logs SET email = ? WHERE participant_id = ?");
                        $stmt->execute([$email, $participantId]);
                        
                        $pdo->commit();
                        $successMsg = 'Participant details updated successfully.';
                        logActivity('PARTICIPANT_ADDED', "Updated participant ID $participantId ($partName)");
                    } else {
                        $pdo->beginTransaction();
                        // Auto-generate certificate_id, team_no, team_name
                        $nextId = $pdo->query("SELECT IFNULL(MAX(id), 0) + 1 FROM participants")->fetchColumn();
                        $certId = sprintf("HM26-%04d", $nextId);
                        $teamNo = "HM-IND-" . $nextId;
                        $teamName = "Individual";
                        
                        // Insert new participant
                        $stmt = $pdo->prepare("INSERT INTO participants (team_no, team_name, participant_name, branch, email, certificate_id) VALUES (?, ?, ?, ?, ?, ?)");
                        $stmt->execute([$teamNo, $teamName, $partName, $branch, $email, $certId]);
                        $newId = $pdo->lastInsertId();
                        
                        // Add records in certificates and email_logs
                        $stmt = $pdo->prepare("INSERT INTO certificates (participant_id, certificate_id, file_path, status) VALUES (?, ?, '', 'PENDING')");
                        $stmt->execute([$newId, $certId]);
                        
                        $stmt = $pdo->prepare("INSERT INTO email_logs (participant_id, certificate_id, email, status) VALUES (?, ?, ?, 'PENDING')");
                        $stmt->execute([$newId, $certId, $email]);
                        
                        $pdo->commit();
                        $successMsg = 'New participant added manually.';
                        logActivity('PARTICIPANT_ADDED', "Manually added participant: $partName ($certId)");
                    }
                }
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = 'Database transaction failed: ' . $e->getMessage();
            }
        }
    }
}

// 4. Handle Participant Deletion
if (isset($_POST['delete_id'])) {
    $csrfToken = $_POST['csrf_token'] ?? '';
    
    if (!validateCSRFToken($csrfToken)) {
        $error = 'Invalid security token (CSRF failure).';
    } else {
        $deleteId = intval($_POST['delete_id']);
        try {
            $stmt = $pdo->prepare("SELECT participant_name FROM participants WHERE id = ?");
            $stmt->execute([$deleteId]);
            $name = $stmt->fetchColumn();
            
            if ($name) {
                // Delete participant (Foreign Keys will cascade delete certificates and email logs)
                $stmt = $pdo->prepare("DELETE FROM participants WHERE id = ?");
                $stmt->execute([$deleteId]);
                
                $successMsg = "Participant $name deleted successfully.";
                logActivity('PARTICIPANT_ADDED', "Deleted participant: $name (ID: $deleteId)");
            }
        } catch (Exception $e) {
            $error = 'Failed to delete participant: ' . $e->getMessage();
        }
    }
}

// 5. Query filters & Search logic
$search = trim($_GET['search'] ?? '');
$branchFilter = trim($_GET['branch'] ?? '');

$queryStr = "SELECT * FROM participants WHERE 1=1";
$params = [];

if ($search !== '') {
    $queryStr .= " AND (participant_name LIKE ? OR email LIKE ? OR certificate_id LIKE ? OR team_name LIKE ? OR team_no LIKE ?)";
    $searchWild = '%' . $search . '%';
    $params = array_merge($params, [$searchWild, $searchWild, $searchWild, $searchWild, $searchWild]);
}

if ($branchFilter !== '') {
    $queryStr .= " AND branch = ?";
    $params[] = $branchFilter;
}

// Get all unique branches for filtering selector
$branches = $pdo->query("SELECT DISTINCT branch FROM participants ORDER BY branch ASC")->fetchAll(PDO::FETCH_COLUMN);

// Pagination
$limit = 10;
$page = max(1, intval($_GET['page'] ?? 1));
$offset = ($page - 1) * $limit;

// Get total count for pagination
$countQueryStr = str_replace("SELECT *", "SELECT COUNT(*)", $queryStr);
$stmtCount = $pdo->prepare($countQueryStr);
$stmtCount->execute($params);
$totalRows = $stmtCount->fetchColumn();
$totalPages = ceil($totalRows / $limit);

// Execute query with limit/offset
$queryStr .= " ORDER BY id DESC LIMIT $limit OFFSET $offset";
$stmt = $pdo->prepare($queryStr);
$stmt->execute($params);
$participants = $stmt->fetchAll();
?>

<div class="page-header">
    <div class="page-title">
        <h1>Participant Management</h1>
        <p>Import CSV datasets, register candidates, and manage candidate details.</p>
    </div>
    <div class="btn-group">
        <button onclick="openAddModal()" class="btn btn-primary">
            <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            Add Candidate
        </button>
        <a href="participants.php?export=1" class="btn btn-secondary">
            <svg viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            Export CSV
        </a>
    </div>
</div>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <?= e($error) ?>
    </div>
<?php endif; ?>

<?php if (!empty($successMsg)): ?>
    <div class="alert alert-success">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
        <?= e($successMsg) ?>
    </div>
<?php endif; ?>

<!-- Invalid CSV Rows Display Section -->
<?php if (count($invalidRows) > 0): ?>
    <div class="card" style="border-color: rgba(239, 68, 68, 0.3); background-color: rgba(239, 68, 68, 0.03);">
        <h3 style="color: var(--danger); font-size: 16px; margin-bottom: 12px; font-weight: 700;">Invalid CSV Lines (Rejected during parsing)</h3>
        <p style="font-size: 12px; color: var(--text-muted); margin-bottom: 16px;">The following records contain errors and were not imported. Please correct them in your CSV and re-upload.</p>
        
        <div class="table-container">
            <table class="table">
                <thead>
                    <tr>
                        <th style="width: 80px;">Line</th>
                        <th>Row Content Preview</th>
                        <th style="color: var(--danger);">Parsing Errors</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($invalidRows as $ir): ?>
                        <tr>
                            <td style="font-family: var(--font-mono); font-weight: 700; color: var(--text-muted);"><?= $ir['row_num'] ?></td>
                            <td style="font-family: var(--font-mono); font-size: 12px;"><?= e($ir['data']) ?></td>
                            <td style="color: #f87171; font-weight: 500; font-size: 13px;"><?= e($ir['errors']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<!-- Import File Section -->
<div class="card" style="margin-bottom: 24px;">
    <h3 style="font-size: 16px; margin-bottom: 16px; font-weight: 700;">Import CSV Candidates</h3>
    <form method="POST" enctype="multipart/form-data" style="display: flex; gap: 16px; align-items: flex-end; flex-wrap: wrap;">
        <?php csrfInput(); ?>
        <div class="form-group" style="margin-bottom: 0; flex: 1; min-width: 250px;">
            <label for="csv_file" style="margin-bottom: 6px;">CSV FILE</label>
            <input type="file" id="csv_file" name="csv_file" class="form-control" accept=".csv" required style="padding: 9px 12px; height: 42px;">
        </div>
        
        <button type="submit" name="import_csv" class="btn btn-secondary" style="height: 42px; padding: 0 24px; min-width: 180px;">
            <svg viewBox="0 0 24 24" style="margin-right: 8px;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
            Upload & Parse CSV
        </button>
        
        <div style="font-size: 11px; color: var(--text-muted); line-height: 1.5; flex-basis: 100%; margin-top: 12px; border-top: 1px solid var(--border-color); padding-top: 12px;">
            <strong>Expected CSV Layout (no spacing in header):</strong> <code style="background: #080d1a; padding: 2px 6px; border-radius: 4px; font-family: var(--font-mono);">team_no,team_name,participant_name,branch,email,certificate_id</code>
        </div>
    </form>
</div>

<!-- Listing & Filters Section -->
<div class="card" style="padding: 18px 24px;">
    <!-- Controls bar -->
    <form method="GET" class="table-controls">
        <div class="search-box">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            <input type="text" name="search" class="form-control" placeholder="Search by name, email, team..." value="<?= e($search) ?>">
        </div>
        
        <div style="display: flex; gap: 12px; align-items: center; flex: 1; justify-content: flex-end;">
            <select name="branch" class="form-control" style="max-width: 200px; margin-bottom: 0;">
                <option value="">All Branches</option>
                <?php foreach ($branches as $br): ?>
                    <option value="<?= e($br) ?>" <?= $branchFilter === $br ? 'selected' : '' ?>><?= e($br) ?></option>
                <?php endforeach; ?>
            </select>
            
            <button type="submit" class="btn btn-secondary">Filter</button>
            
            <?php if ($search !== '' || $branchFilter !== ''): ?>
                <a href="participants.php" class="btn btn-secondary" style="padding: 12px;">Clear</a>
            <?php endif; ?>
        </div>
    </form>
    
    <!-- Table -->
    <div class="table-container">
        <table class="table">
            <thead>
                <tr>
                    <th>Certificate ID</th>
                    <th>Participant Name</th>
                    <th>Email</th>
                    <th>Branch</th>
                    <th>Team Details</th>
                    <th style="text-align: right; width: 120px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($participants) > 0): ?>
                    <?php foreach ($participants as $p): ?>
                        <tr>
                            <td style="font-family: var(--font-mono); font-weight: 700; font-size: 13px; color: var(--accent-primary);">
                                <?= e($p['certificate_id']) ?>
                            </td>
                            <td style="font-weight: 600; color: white;">
                                <?= e($p['participant_name']) ?>
                            </td>
                            <td><?= e($p['email']) ?></td>
                            <td><?= e($p['branch']) ?></td>
                            <td>
                                <div style="font-size: 13px; font-weight: 600;"><?= e($p['team_name']) ?></div>
                                <div style="font-size: 11px; color: var(--text-muted);">No: <?= e($p['team_no']) ?></div>
                            </td>
                            <td style="text-align: right;">
                                <div style="display: inline-flex; gap: 8px;">
                                    <button onclick="openEditModal(<?= e(json_encode($p)) ?>)" class="btn btn-secondary" style="padding: 6px 10px; font-size: 12px;">
                                        Edit
                                    </button>
                                    
                                    <form method="POST" onsubmit="return confirm('Are you sure you want to delete this participant? All generated certificates and email logs will be deleted.');">
                                        <?php csrfInput(); ?>
                                        <input type="hidden" name="delete_id" value="<?= $p['id'] ?>">
                                        <button type="submit" class="btn btn-danger" style="padding: 6px 10px; font-size: 12px;">
                                            Delete
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="6" style="text-align: center; padding: 40px; color: var(--text-muted);">
                            No participants found.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    
    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <span style="font-size: 13px; color: var(--text-muted);">Showing <?= $offset + 1 ?> to <?= min($offset + $limit, $totalRows) ?> of <?= $totalRows ?> participants</span>
            <div class="pagination-links">
                <?php if ($page > 1): ?>
                    <a href="participants.php?page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>&branch=<?= urlencode($branchFilter) ?>">&laquo; Prev</a>
                <?php endif; ?>
                
                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <?php if ($i == $page): ?>
                        <span class="active"><?= $i ?></span>
                    <?php else: ?>
                        <a href="participants.php?page=<?= $i ?>&search=<?= urlencode($search) ?>&branch=<?= urlencode($branchFilter) ?>"><?= $i ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
                
                <?php if ($page < $totalPages): ?>
                    <a href="participants.php?page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>&branch=<?= urlencode($branchFilter) ?>">Next &raquo;</a>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Modal Dialog for Manual Add / Edit Participant -->
<div id="participantModal" class="modal-overlay">
    <div class="modal">
        <div class="modal-header">
            <h3 id="modalTitle">Add Candidate Details</h3>
            <button class="modal-close" onclick="closeModal()">&times;</button>
        </div>
        
        <form method="POST">
            <?php csrfInput(); ?>
            <input type="hidden" name="participant_id" id="part_id_input">
            
            <div class="form-group">
                <label for="modal_part_name">PARTICIPANT NAME</label>
                <input type="text" id="modal_part_name" name="participant_name" class="form-control" required placeholder="e.g. Rahul Kumar">
            </div>
            
            <div class="form-group">
                <label for="modal_email">EMAIL ADDRESS</label>
                <input type="email" id="modal_email" name="email" class="form-control" required placeholder="e.g. rahul@gmail.com">
            </div>
            
            <div class="form-group">
                <label for="modal_branch">BRANCH</label>
                <input type="text" id="modal_branch" name="branch" class="form-control" required placeholder="e.g. AI & DS">
            </div>


            
            <div class="btn-group" style="margin-top: 24px; justify-content: flex-end;">
                <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                <button type="submit" name="add_participant" id="modalSubmitBtn" class="btn btn-primary">Save Candidate</button>
            </div>
        </form>
    </div>
</div>

<script>
    const modal = document.getElementById('participantModal');
    
    function openAddModal() {
        document.getElementById('modalTitle').innerText = 'Add Candidate Details';
        document.getElementById('modalSubmitBtn').name = 'add_participant';
        document.getElementById('modalSubmitBtn').innerText = 'Save Candidate';
        document.getElementById('part_id_input').value = '';
        
        // Reset form inputs
        document.getElementById('modal_part_name').value = '';
        document.getElementById('modal_email').value = '';
        document.getElementById('modal_branch').value = '';
        
        modal.classList.add('active');
    }
    
    function openEditModal(participant) {
        document.getElementById('modalTitle').innerText = 'Edit Candidate Details';
        document.getElementById('modalSubmitBtn').name = 'edit_participant';
        document.getElementById('modalSubmitBtn').innerText = 'Update Candidate';
        document.getElementById('part_id_input').value = participant.id;
        
        // Fill form inputs
        document.getElementById('modal_part_name').value = participant.participant_name;
        document.getElementById('modal_email').value = participant.email;
        document.getElementById('modal_branch').value = participant.branch;
        
        modal.classList.add('active');
    }
    
    function closeModal() {
        modal.classList.remove('active');
    }
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
