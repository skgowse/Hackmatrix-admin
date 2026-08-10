<?php
/**
 * HackMatrix 1.0 - Certificate Generation & Email Distribution
 */

@set_time_limit(0);
@ini_set('memory_limit', '512M');

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/certificate.php';
require_once __DIR__ . '/../includes/mailer.php';

// Enforce login
requireLogin();

$pdo = getDBConnection();
$error = '';
$successMsg = '';

// ---------------------------------------------------------
// 1. AJAX API ACTIONS
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $csrfToken = $_POST['csrf_token'] ?? '';
    
    if (!validateCSRFToken($csrfToken)) {
        jsonResponse(false, 'Invalid request security token (CSRF failure).');
    }
    
    // ACTION: Generate Batch of PDFs
    if ($action === 'generate_batch') {
        $ids = json_decode($_POST['ids'] ?? '[]', true);
        if (empty($ids)) {
            jsonResponse(false, 'No candidate IDs specified.');
        }
        
        // Get active template
        $template = $pdo->query("SELECT * FROM certificate_templates LIMIT 1")->fetch();
        if (!$template) {
            jsonResponse(false, 'Please upload a certificate template first.');
        }
        
        // Fetch template fields
        $stmtFields = $pdo->prepare("SELECT * FROM certificate_fields WHERE template_id = ?");
        $stmtFields->execute([$template['id']]);
        $fields = $stmtFields->fetchAll();
        
        if (empty($fields)) {
            jsonResponse(false, 'Please configure field coordinates in the Editor first.');
        }
        
        $successCount = 0;
        $failedCount = 0;
        
        // Process batch (e.g. max 10 to prevent timeouts)
        $batch = array_slice($ids, 0, 10);
        $remainingIds = array_slice($ids, 10);
        
        foreach ($batch as $id) {
            $stmt = $pdo->prepare("SELECT * FROM participants WHERE id = ?");
            $stmt->execute([$id]);
            $participant = $stmt->fetch();
            
            if ($participant) {
                $filename = $participant['certificate_id'] . '.pdf';
                $outputPath = __DIR__ . '/../certificates/' . $filename;
                
                try {
                    $res = CertificateGenerator::generate($participant, $template, $fields, $outputPath);
                    if ($res) {
                        // Update DB status
                        $update = $pdo->prepare("UPDATE certificates SET status = 'GENERATED', file_path = ?, generated_at = NOW() WHERE participant_id = ?");
                        $update->execute(['certificates/' . $filename, $id]);
                        
                        $successCount++;
                    } else {
                        throw new Exception("PDF generator returned false");
                    }
                } catch (Exception $e) {
                    $update = $pdo->prepare("UPDATE certificates SET status = 'FAILED' WHERE participant_id = ?");
                    $update->execute([$id]);
                    
                    // Log to activities
                    logActivity('CERTIFICATE_FAILED', "Failed to generate certificate ID: " . $participant['certificate_id'] . " - " . $e->getMessage());
                    $failedCount++;
                }
            }
        }
        
        logActivity('CERTIFICATE_GENERATED', "Generated $successCount certificates in bulk batch. Failed: $failedCount.");
        
        jsonResponse(true, 'Batch processed successfully.', [
            'success_count' => $successCount,
            'failed_count' => $failedCount,
            'remaining_ids' => $remainingIds
        ]);
    }
    
    // ACTION: Send Batch of Emails
    if ($action === 'send_batch') {
        $ids = json_decode($_POST['ids'] ?? '[]', true);
        if (empty($ids)) {
            jsonResponse(false, 'No candidate IDs specified.');
        }
        
        $smtp = $pdo->query("SELECT * FROM smtp_settings LIMIT 1")->fetch();
        if (!$smtp) {
            jsonResponse(false, 'SMTP Settings are not configured.');
        }
        
        $emailTemplate = $pdo->query("SELECT * FROM email_templates LIMIT 1")->fetch();
        if (!$emailTemplate) {
            jsonResponse(false, 'Email template is not configured.');
        }
        
        // Configurable delay and batch size
        $delay = intval($_POST['delay'] ?? 2); // default 2 seconds
        $batchSize = intval($_POST['batch_size'] ?? 5); // process in small batches of 5
        
        $batch = array_slice($ids, 0, $batchSize);
        $remainingIds = array_slice($ids, $batchSize);
        
        $successCount = 0;
        $failedCount = 0;
        
        foreach ($batch as $id) {
            // Get participant details
            $stmt = $pdo->prepare("SELECT * FROM participants WHERE id = ?");
            $stmt->execute([$id]);
            $participant = $stmt->fetch();
            
            // Get certificate details
            $stmtCert = $pdo->prepare("SELECT * FROM certificates WHERE participant_id = ?");
            $stmtCert->execute([$id]);
            $cert = $stmtCert->fetch();
            
            if ($participant && $cert && $cert['status'] === 'GENERATED') {
                // Mark email status as SENDING
                $upd = $pdo->prepare("UPDATE email_logs SET status = 'SENDING' WHERE participant_id = ?");
                $upd->execute([$id]);
                
                $pdfPath = __DIR__ . '/../' . $cert['file_path'];
                
                if (file_exists($pdfPath)) {
                    // Send Email using Mailer wrapper
                    $result = MailerHelper::sendCertificate($participant, $pdfPath, $smtp, $emailTemplate);
                    
                    if ($result === true) {
                        $upd = $pdo->prepare("UPDATE email_logs SET status = 'SENT', sent_at = NOW(), error_message = NULL WHERE participant_id = ?");
                        $upd->execute([$id]);
                        
                        logActivity('EMAIL_SENT', "Sent certificate to: " . $participant['email']);
                        $successCount++;
                    } else {
                        // SMTP Error
                        $upd = $pdo->prepare("UPDATE email_logs SET status = 'FAILED', error_message = ?, retry_count = retry_count + 1 WHERE participant_id = ?");
                        $upd->execute([$result, $id]);
                        
                        logActivity('EMAIL_FAILED', "Failed email to: " . $participant['email'] . " - Error: " . $result);
                        $failedCount++;
                    }
                } else {
                    // File missing
                    $errorMsg = "PDF certificate file not found on disk.";
                    $upd = $pdo->prepare("UPDATE email_logs SET status = 'FAILED', error_message = ? WHERE participant_id = ?");
                    $upd->execute([$errorMsg, $id]);
                    
                    logActivity('EMAIL_FAILED', "Missing PDF file for participant ID: " . $id);
                    $failedCount++;
                }
            } else {
                $errorMsg = "Certificate is not generated yet.";
                $upd = $pdo->prepare("UPDATE email_logs SET status = 'FAILED', error_message = ? WHERE participant_id = ?");
                $upd->execute([$errorMsg, $id]);
                $failedCount++;
            }
            
            // Apply delay between emails to respect SMTP limits
            if (count($batch) > 1 && $delay > 0) {
                sleep($delay);
            }
        }
        
        jsonResponse(true, 'Email batch processed.', [
            'success_count' => $successCount,
            'failed_count' => $failedCount,
            'remaining_ids' => $remainingIds
        ]);
    }
}

// ---------------------------------------------------------
// 2. FILE DOWNLOAD LOGIC (ZIP) & INLINE PDF PREVIEW
// ---------------------------------------------------------
if (isset($_GET['preview_id'])) {
    $previewId = intval($_GET['preview_id']);
    
    // Get participant details
    $stmt = $pdo->prepare("SELECT * FROM participants WHERE id = ?");
    $stmt->execute([$previewId]);
    $participant = $stmt->fetch();
    
    // Get active template
    $template = $pdo->query("SELECT * FROM certificate_templates LIMIT 1")->fetch();
    
    // Get fields configurations
    if ($participant && $template) {
        $stmtFields = $pdo->prepare("SELECT * FROM certificate_fields WHERE template_id = ?");
        $stmtFields->execute([$template['id']]);
        $fields = $stmtFields->fetchAll();
        
        if (!empty($fields)) {
            // Render directly to browser
            CertificateGenerator::generate($participant, $template, $fields, null);
            exit();
        }
    }
    die("Error generating PDF preview. Ensure template and coordinates are fully configured.");
}

// Download individual file
if (isset($_GET['download_id'])) {
    $downloadId = intval($_GET['download_id']);
    $cert = $pdo->query("SELECT c.*, p.certificate_id FROM certificates c JOIN participants p ON c.participant_id = p.id WHERE c.id = $downloadId")->fetch();
    if ($cert && file_exists(__DIR__ . '/../' . $cert['file_path'])) {
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $cert['certificate_id'] . '.pdf"');
        readfile(__DIR__ . '/../' . $cert['file_path']);
        exit();
    }
    $error = 'The requested certificate file does not exist.';
}

// Download selected as ZIP
if (isset($_POST['download_selected_zip']) || isset($_GET['download_all_zip'])) {
    $ids = [];
    if (isset($_POST['selected_ids'])) {
        $ids = array_map('intval', $_POST['selected_ids']);
    } elseif (isset($_GET['download_all_zip'])) {
        $ids = $pdo->query("SELECT participant_id FROM certificates WHERE status = 'GENERATED'")->fetchAll(PDO::FETCH_COLUMN);
    }
    
    if (empty($ids)) {
        $error = 'No generated certificates selected for ZIP download.';
    } else {
        $zip = new ZipArchive();
        $zipName = 'hackmatrix_certificates_' . time() . '.zip';
        $zipPath = sys_get_temp_dir() . '/' . $zipName;
        
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === TRUE) {
            $addedFiles = 0;
            foreach ($ids as $id) {
                $stmt = $pdo->prepare("SELECT file_path, certificate_id FROM certificates WHERE participant_id = ? AND status = 'GENERATED'");
                $stmt->execute([$id]);
                $cert = $stmt->fetch();
                
                if ($cert) {
                    $fullPath = __DIR__ . '/../' . $cert['file_path'];
                    if (file_exists($fullPath)) {
                        $zip->addFile($fullPath, $cert['certificate_id'] . '.pdf');
                        $addedFiles++;
                    }
                }
            }
            $zip->close();
            
            if ($addedFiles > 0) {
                header('Content-Type: application/zip');
                header('Content-Disposition: attachment; filename="' . $zipName . '"');
                header('Content-Length: ' . filesize($zipPath));
                readfile($zipPath);
                unlink($zipPath); // clean up
                exit();
            } else {
                $error = 'None of the selected certificates exist on disk to form a ZIP.';
            }
        } else {
            $error = 'Failed to create temporary ZIP archive.';
        }
    }
}

// ---------------------------------------------------------
// 3. LOAD VISUAL HTML LAYOUT HIGHLIGHTS
// ---------------------------------------------------------
require_once __DIR__ . '/header.php';
$tab = $_GET['tab'] ?? 'generate'; // 'generate' or 'send'



// ---------------------------------------------------------
// 4. MAIN DATA POPULATION (Paging & Searching)
// ---------------------------------------------------------
$search = trim($_GET['search'] ?? '');
$filterStatus = $_GET['status'] ?? ''; // Generated status or Email status

$queryStr = "SELECT p.*, c.status AS cert_status, c.id AS cert_tbl_id, e.status AS email_status 
             FROM participants p 
             LEFT JOIN certificates c ON p.id = c.participant_id 
             LEFT JOIN email_logs e ON p.id = e.participant_id 
             WHERE 1=1";
$params = [];

if ($search !== '') {
    $queryStr .= " AND (p.participant_name LIKE ? OR p.email LIKE ? OR p.certificate_id LIKE ? OR p.team_name LIKE ?)";
    $searchWild = '%' . $search . '%';
    $params = array_merge($params, [$searchWild, $searchWild, $searchWild, $searchWild]);
}

if ($filterStatus !== '') {
    if ($tab === 'generate') {
        $queryStr .= " AND c.status = ?";
    } else {
        $queryStr .= " AND e.status = ?";
    }
    $params[] = $filterStatus;
}

$queryStr .= " ORDER BY p.id DESC";
$stmt = $pdo->prepare($queryStr);
$stmt->execute($params);
$candidatesList = $stmt->fetchAll();
?>

<!-- Tab Switch Header -->
<div style="display: flex; gap: 16px; border-bottom: 1px solid var(--border-color); padding-bottom: 2px; margin-bottom: 30px;">
    <a href="certificates.php?tab=generate" class="btn <?= $tab === 'generate' ? 'btn-primary' : 'btn-secondary' ?>" style="border-radius: 8px 8px 0 0; padding: 12px 24px; border: none; margin-bottom: -3px;">
        <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
        Certificate Generation
    </a>
    <a href="certificates.php?tab=send" class="btn <?= $tab === 'send' ? 'btn-primary' : 'btn-secondary' ?>" style="border-radius: 8px 8px 0 0; padding: 12px 24px; border: none; margin-bottom: -3px;">
        <svg viewBox="0 0 24 24"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
        Email Distribution
    </a>
</div>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <?= e($error) ?>
    </div>
<?php endif; ?>

<!-- ---------------------------------------------------------
     TAB 1: CERTIFICATE GENERATION VIEW
     --------------------------------------------------------- -->
<?php if ($tab === 'generate'): ?>
    <div class="page-header" style="margin-bottom: 20px;">
        <div class="page-title">
            <h2 style="font-size: 20px; font-weight: 700;">Certificate File Generation Engine</h2>
            <p>Compile custom certificate PDFs for participants locally on disk.</p>
        </div>
        <div class="btn-group">
            <button onclick="startBulkGeneration(null)" class="btn btn-primary">
                <svg viewBox="0 0 24 24"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                Generate All Certificates
            </button>
            <button onclick="submitBulkZip()" class="btn btn-secondary">
                <svg viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                Download Selected ZIP
            </button>
            <a href="certificates.php?download_all_zip=1" class="btn btn-secondary">
                <svg viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                Download All ZIP
            </a>
        </div>
    </div>

    <!-- Stats summary row -->
    <?php
    $totalCount = count($candidatesList);
    $generatedCount = 0;
    $pendingGen = 0;
    $failedGen = 0;
    foreach ($candidatesList as $c) {
        if ($c['cert_status'] === 'GENERATED') $generatedCount++;
        elseif ($c['cert_status'] === 'FAILED') $failedGen++;
        else $pendingGen++;
    }
    ?>
    <div class="dashboard-grid" style="margin-bottom: 24px;">
        <div class="stat-card">
            <div class="stat-card-icon blue"><svg viewBox="0 0 24 24" width="24" height="24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg></div>
            <div class="stat-card-info"><div class="value"><?= $totalCount ?></div><h3>Total Candidates</h3></div>
        </div>
        <div class="stat-card">
            <div class="stat-card-icon green"><svg viewBox="0 0 24 24" width="24" height="24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg></div>
            <div class="stat-card-info"><div class="value"><?= $generatedCount ?></div><h3>Generated</h3></div>
        </div>
        <div class="stat-card">
            <div class="stat-card-icon yellow"><svg viewBox="0 0 24 24" width="24" height="24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg></div>
            <div class="stat-card-info"><div class="value"><?= $pendingGen ?></div><h3>Pending</h3></div>
        </div>
        <div class="stat-card">
            <div class="stat-card-icon red"><svg viewBox="0 0 24 24" width="24" height="24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg></div>
            <div class="stat-card-info"><div class="value"><?= $failedGen ?></div><h3>Failed</h3></div>
        </div>
    </div>

    <!-- Progress Dashboard Box (Visible during compilation) -->
    <div id="progressBox" class="card" style="display: none; border-color: var(--accent-primary);">
        <h3 id="progressTitle" style="font-size: 15px; font-weight: 700; margin-bottom: 8px;">Generating Certificates...</h3>
        <div style="display: flex; justify-content: space-between; font-size: 13px; color: var(--text-muted); margin-bottom: 8px;">
            <span id="progressStats">0 / 0 completed</span>
            <span id="progressPercent" style="font-weight: 700; color: white;">0%</span>
        </div>
        <div class="progress-container">
            <div id="progressBar" class="progress-bar" style="width: 0%;"></div>
        </div>
    </div>

    <!-- Candidate List Table -->
    <div class="card" style="padding: 18px 24px;">
        <form method="GET" class="table-controls">
            <input type="hidden" name="tab" value="generate">
            <div class="search-box">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input type="text" name="search" class="form-control" placeholder="Search candidates..." value="<?= e($search) ?>">
            </div>
            <div style="display: flex; gap: 12px; align-items: center; justify-content: flex-end; flex: 1;">
                <select name="status" class="form-control" style="max-width: 180px; margin-bottom: 0;">
                    <option value="">All Statuses</option>
                    <option value="PENDING" <?= $filterStatus === 'PENDING' ? 'selected' : '' ?>>Pending</option>
                    <option value="GENERATED" <?= $filterStatus === 'GENERATED' ? 'selected' : '' ?>>Generated</option>
                    <option value="FAILED" <?= $filterStatus === 'FAILED' ? 'selected' : '' ?>>Failed</option>
                </select>
                <button type="submit" class="btn btn-secondary">Filter</button>
            </div>
        </form>

        <form id="bulkForm" method="POST">
            <div class="table-container">
                <table class="table">
                    <thead>
                        <tr>
                            <th style="width: 40px; text-align: center;">
                                <input type="checkbox" id="selectAllCheck" onclick="toggleSelectAll(this)">
                            </th>
                            <th>Certificate ID</th>
                            <th>Participant</th>
                            <th>Team Details</th>
                            <th>Status</th>
                            <th style="text-align: right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($candidatesList) > 0): ?>
                            <?php foreach ($candidatesList as $c): ?>
                                <tr>
                                    <td style="text-align: center;">
                                        <input type="checkbox" name="selected_ids[]" value="<?= $c['id'] ?>" class="candidate-check">
                                    </td>
                                    <td style="font-family: var(--font-mono); font-weight: 700; color: var(--accent-primary);"><?= e($c['certificate_id']) ?></td>
                                    <td>
                                        <div style="font-weight: 600; color: white;"><?= e($c['participant_name']) ?></div>
                                        <div style="font-size: 12px; color: var(--text-muted);"><?= e($c['email']) ?></div>
                                    </td>
                                    <td>
                                        <div style="font-size: 13px; font-weight: 600;"><?= e($c['team_name']) ?></div>
                                        <div style="font-size: 11px; color: var(--text-muted);">No: <?= e($c['team_no']) ?></div>
                                    </td>
                                    <td>
                                        <?php if ($c['cert_status'] === 'GENERATED'): ?>
                                            <span class="badge badge-success">Generated</span>
                                        <?php elseif ($c['cert_status'] === 'FAILED'): ?>
                                            <span class="badge badge-danger">Failed</span>
                                        <?php else: ?>
                                            <span class="badge badge-warning">Pending</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align: right;">
                                        <div style="display: inline-flex; gap: 8px;">
                                            <a href="certificates.php?preview_id=<?= $c['id'] ?>" target="_blank" class="btn btn-secondary" style="padding: 6px 10px; font-size: 12px;">
                                                Preview
                                            </a>
                                            <button type="button" onclick="startBulkGeneration([<?= $c['id'] ?>])" class="btn btn-secondary" style="padding: 6px 10px; font-size: 12px;">
                                                Generate
                                            </button>
                                            <?php if ($c['cert_status'] === 'GENERATED'): ?>
                                                <a href="certificates.php?download_id=<?= $c['cert_tbl_id'] ?>" class="btn btn-success" style="padding: 6px 10px; font-size: 12px;">
                                                    Download
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" style="text-align: center; padding: 40px; color: var(--text-muted);">No matching candidates found.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <div class="btn-group" style="margin-top: 20px;">
                <button type="button" onclick="generateSelected()" class="btn btn-primary">Generate Selected</button>
            </div>
        </form>
    </div>

<!-- ---------------------------------------------------------
     TAB 2: EMAIL DISTRIBUTION VIEW
     --------------------------------------------------------- -->
<?php else: ?>
    <div class="page-header" style="margin-bottom: 20px;">
        <div class="page-title">
            <h2 style="font-size: 20px; font-weight: 700;">Certificate Email Distribution Queue</h2>
            <p>Distribute generated PDF files to participant emails through college SMTP server.</p>
        </div>
        <div class="btn-group">
            <button onclick="startBulkEmailing(null)" class="btn btn-primary">
                <svg viewBox="0 0 24 24"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                Send to All
            </button>
            <button onclick="retryFailedEmails()" class="btn btn-secondary" style="color: var(--warning); border-color: rgba(245, 158, 11, 0.2); background: rgba(245,158,11,0.02);">
                <svg viewBox="0 0 24 24"><path d="M21.5 2v6h-6M21.34 15.57a10 10 0 1 1-.57-8.38l5.67-5.67"/></svg>
                Retry Failed Emails
            </button>
        </div>
    </div>

    <!-- Email stats row -->
    <?php
    $mailTotal = count($candidatesList);
    $mailSent = 0;
    $mailPending = 0;
    $mailSending = 0;
    $mailFailed = 0;
    foreach ($candidatesList as $c) {
        if ($c['email_status'] === 'SENT') $mailSent++;
        elseif ($c['email_status'] === 'FAILED') $mailFailed++;
        elseif ($c['email_status'] === 'SENDING') $mailSending++;
        else $mailPending++;
    }
    ?>
    <div class="dashboard-grid" style="margin-bottom: 24px;">
        <div class="stat-card">
            <div class="stat-card-icon blue"><svg viewBox="0 0 24 24" width="24" height="24"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg></div>
            <div class="stat-card-info"><div class="value"><?= $mailTotal ?></div><h3>Total Queue</h3></div>
        </div>
        <div class="stat-card">
            <div class="stat-card-icon green"><svg viewBox="0 0 24 24" width="24" height="24"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg></div>
            <div class="stat-card-info"><div class="value"><?= $mailSent ?></div><h3>Emails Sent</h3></div>
        </div>
        <div class="stat-card">
            <div class="stat-card-icon yellow"><svg viewBox="0 0 24 24" width="24" height="24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg></div>
            <div class="stat-card-info"><div class="value"><?= $mailPending ?></div><h3>Pending Delivery</h3></div>
        </div>
        <div class="stat-card">
            <div class="stat-card-icon red"><svg viewBox="0 0 24 24" width="24" height="24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg></div>
            <div class="stat-card-info"><div class="value"><?= $mailFailed ?></div><h3>Emails Failed</h3></div>
        </div>
    </div>

    <!-- Controlled Delivery Options Widget -->
    <div class="card" style="padding: 20px;">
        <h3 style="font-size: 15px; font-weight: 700; margin-bottom: 16px;">Delivery Queue Safety Settings</h3>
        <div class="form-grid" style="margin-bottom: 0;">
            <div class="form-group" style="margin-bottom: 0;">
                <label for="smtpDelay">INTERVAL DELAY BETWEEN EMAILS (SECONDS)</label>
                <input type="number" id="smtpDelay" class="form-control" min="0" max="30" value="2">
            </div>
            <div class="form-group" style="margin-bottom: 0;">
                <label for="smtpBatchSize">BATCH SIZE (EMAILS PER BATCH)</label>
                <input type="number" id="smtpBatchSize" class="form-control" min="1" max="100" value="5">
            </div>
        </div>
    </div>

    <!-- Progress Dashboard Box (Visible during mailing) -->
    <div id="progressBox" class="card" style="display: none; border-color: var(--accent-secondary);">
        <h3 id="progressTitle" style="font-size: 15px; font-weight: 700; margin-bottom: 8px;">Sending Certificates...</h3>
        <div style="display: flex; justify-content: space-between; font-size: 13px; color: var(--text-muted); margin-bottom: 8px;">
            <span id="progressStats">0 / 0 dispatched</span>
            <span id="progressPercent" style="font-weight: 700; color: white;">0%</span>
        </div>
        <div class="progress-container">
            <div id="progressBar" class="progress-bar" style="width: 0%; background: linear-gradient(90deg, var(--accent-secondary), #818cf8);"></div>
        </div>
    </div>

    <!-- Candidate Mail Queue Table -->
    <div class="card" style="padding: 18px 24px;">
        <form method="GET" class="table-controls">
            <input type="hidden" name="tab" value="send">
            <div class="search-box">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input type="text" name="search" class="form-control" placeholder="Search candidates..." value="<?= e($search) ?>">
            </div>
            <div style="display: flex; gap: 12px; align-items: center; justify-content: flex-end; flex: 1;">
                <select name="status" class="form-control" style="max-width: 180px; margin-bottom: 0;">
                    <option value="">All Statuses</option>
                    <option value="PENDING" <?= $filterStatus === 'PENDING' ? 'selected' : '' ?>>Pending</option>
                    <option value="SENDING" <?= $filterStatus === 'SENDING' ? 'selected' : '' ?>>Sending</option>
                    <option value="SENT" <?= $filterStatus === 'SENT' ? 'selected' : '' ?>>Sent</option>
                    <option value="FAILED" <?= $filterStatus === 'FAILED' ? 'selected' : '' ?>>Failed</option>
                </select>
                <button type="submit" class="btn btn-secondary">Filter</button>
            </div>
        </form>

        <form id="bulkForm" method="POST">
            <div class="table-container">
                <table class="table">
                    <thead>
                        <tr>
                            <th style="width: 40px; text-align: center;">
                                <input type="checkbox" id="selectAllCheck" onclick="toggleSelectAll(this)">
                            </th>
                            <th>Participant</th>
                            <th>Certificate ID</th>
                            <th>PDF File</th>
                            <th>Mail Status</th>
                            <th style="text-align: right;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($candidatesList) > 0): ?>
                            <?php foreach ($candidatesList as $c): ?>
                                <tr>
                                    <td style="text-align: center;">
                                        <input type="checkbox" name="selected_ids[]" value="<?= $c['id'] ?>" class="candidate-check">
                                    </td>
                                    <td>
                                        <div style="font-weight: 600; color: white;"><?= e($c['participant_name']) ?></div>
                                        <div style="font-size: 12px; color: var(--text-muted);"><?= e($c['email']) ?></div>
                                    </td>
                                    <td style="font-family: var(--font-mono); font-weight: 700;"><?= e($c['certificate_id']) ?></td>
                                    <td>
                                        <?php if ($c['cert_status'] === 'GENERATED'): ?>
                                            <span style="font-size: 13px; color: var(--success); font-weight: 600;">&#10003; Ready</span>
                                        <?php else: ?>
                                            <span style="font-size: 13px; color: var(--warning);">File Not Compiled</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($c['email_status'] === 'SENT'): ?>
                                            <span class="badge badge-success">Sent</span>
                                        <?php elseif ($c['email_status'] === 'FAILED'): ?>
                                            <span class="badge badge-danger">Failed</span>
                                        <?php elseif ($c['email_status'] === 'SENDING'): ?>
                                            <span class="badge badge-info">Sending</span>
                                        <?php else: ?>
                                            <span class="badge badge-warning">Pending</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align: right;">
                                        <button type="button" onclick="startBulkEmailing([<?= $c['id'] ?>])" class="btn btn-secondary" style="padding: 6px 10px; font-size: 12px;">
                                            Send Email
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" style="text-align: center; padding: 40px; color: var(--text-muted);">No candidates in mail queue.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <div class="btn-group" style="margin-top: 20px;">
                <button type="button" onclick="sendSelected()" class="btn btn-primary">Send Selected</button>
            </div>
        </form>
    </div>
<?php endif; ?>

<!-- ---------------------------------------------------------
     JAVASCRIPT LOGIC FOR GENERATION AND EMAILING QUEUES
     --------------------------------------------------------- -->
<script>
    // Selected checkbox states
    function toggleSelectAll(master) {
        document.querySelectorAll('.candidate-check').forEach(box => {
            box.checked = master.checked;
        });
    }

    // 1. Generation AJAX Loop
    function generateSelected() {
        const checked = Array.from(document.querySelectorAll('.candidate-check:checked')).map(box => parseInt(box.value));
        if (checked.length === 0) {
            showToast('Please select at least one candidate.', 'warning');
            return;
        }
        startBulkGeneration(checked);
    }
    
    function submitBulkZip() {
        const checked = Array.from(document.querySelectorAll('.candidate-check:checked')).map(box => box.value);
        if (checked.length === 0) {
            showToast('Please select candidates first.', 'warning');
            return;
        }
        // Create dynamic form submit for selected files ZIP
        const form = document.getElementById('bulkForm');
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'download_selected_zip';
        input.value = '1';
        form.appendChild(input);
        form.submit();
    }

    let globalIdsToProcess = [];
    let totalIdsToProcessCount = 0;
    let completedCount = 0;
    
    function startBulkGeneration(ids) {
        if (ids === null) {
            // Load all pending candidates
            globalIdsToProcess = <?= json_encode(array_column($candidatesList, 'id')) ?>;
        } else {
            globalIdsToProcess = ids;
        }
        
        if (globalIdsToProcess.length === 0) {
            showToast('No candidates found to process.', 'warning');
            return;
        }
        
        totalIdsToProcessCount = globalIdsToProcess.length;
        completedCount = 0;
        
        // Show progress box
        const progressBox = document.getElementById('progressBox');
        progressBox.style.display = 'block';
        updateProgressBar(0);
        
        document.getElementById('progressTitle').innerText = 'Generating PDF files...';
        
        // Execute batch loop
        ajaxGenerateBatch();
    }
    
    function ajaxGenerateBatch() {
        if (globalIdsToProcess.length === 0) {
            document.getElementById('progressTitle').innerText = 'Certificate Generation Completed!';
            showToast('Successfully generated certificates!', 'success');
            setTimeout(() => { location.reload(); }, 1500);
            return;
        }
        
        const formData = new FormData();
        formData.append('action', 'generate_batch');
        formData.append('csrf_token', '<?= generateCSRFToken() ?>');
        formData.append('ids', JSON.stringify(globalIdsToProcess));
        
        fetch('certificates.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                const batchSize = globalIdsToProcess.length - data.remaining_ids.length;
                completedCount += batchSize;
                globalIdsToProcess = data.remaining_ids;
                
                const percent = Math.round((completedCount / totalIdsToProcessCount) * 100);
                updateProgressBar(percent);
                document.getElementById('progressStats').innerText = `${completedCount} / ${totalIdsToProcessCount} completed`;
                
                // Call next batch
                ajaxGenerateBatch();
            } else {
                showToast(data.message, 'danger');
                document.getElementById('progressTitle').innerText = 'Generation halted due to error.';
            }
        })
        .catch(err => {
            showToast('Network error during generation batch processing.', 'danger');
        });
    }

    function updateProgressBar(percent) {
        document.getElementById('progressBar').style.width = percent + '%';
        document.getElementById('progressPercent').innerText = percent + '%';
    }

    // 2. Email Sending AJAX Loop
    function sendSelected() {
        const checked = Array.from(document.querySelectorAll('.candidate-check:checked')).map(box => parseInt(box.value));
        if (checked.length === 0) {
            showToast('Please select at least one candidate.', 'warning');
            return;
        }
        startBulkEmailing(checked);
    }
    
    function retryFailedEmails() {
        // Find failed emails
        const failedIds = [];
        <?php foreach ($candidatesList as $c): ?>
            <?php if ($c['email_status'] === 'FAILED'): ?>
                failedIds.push(<?= $c['id'] ?>);
            <?php endif; ?>
        <?php endforeach; ?>
        
        if (failedIds.length === 0) {
            showToast('No failed emails found to retry.', 'info');
            return;
        }
        
        startBulkEmailing(failedIds);
    }
    
    function startBulkEmailing(ids) {
        if (ids === null) {
            // Send to all whose emails are PENDING or FAILED
            globalIdsToProcess = [];
            <?php foreach ($candidatesList as $c): ?>
                <?php if ($c['email_status'] !== 'SENT' && $c['cert_status'] === 'GENERATED'): ?>
                    globalIdsToProcess.push(<?= $c['id'] ?>);
                <?php endif; ?>
            <?php endforeach; ?>
        } else {
            globalIdsToProcess = ids;
        }
        
        if (globalIdsToProcess.length === 0) {
            showToast('No certificates found ready for emailing. Please compile PDFs first.', 'warning');
            return;
        }
        
        totalIdsToProcessCount = globalIdsToProcess.length;
        completedCount = 0;
        
        // Show progress box
        const progressBox = document.getElementById('progressBox');
        progressBox.style.display = 'block';
        updateProgressBar(0);
        
        document.getElementById('progressTitle').innerText = 'Distributing emails in batches...';
        
        // Execute batch loop
        ajaxSendBatch();
    }
    
    function ajaxSendBatch() {
        if (globalIdsToProcess.length === 0) {
            document.getElementById('progressTitle').innerText = 'Email Dispatch Completed!';
            showToast('All certificate emails successfully processed!', 'success');
            setTimeout(() => { location.reload(); }, 1500);
            return;
        }
        
        const delay = document.getElementById('smtpDelay').value;
        const batchSize = document.getElementById('smtpBatchSize').value;
        
        const formData = new FormData();
        formData.append('action', 'send_batch');
        formData.append('csrf_token', '<?= generateCSRFToken() ?>');
        formData.append('ids', JSON.stringify(globalIdsToProcess));
        formData.append('delay', delay);
        formData.append('batch_size', batchSize);
        
        fetch('certificates.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                const sentCount = globalIdsToProcess.length - data.remaining_ids.length;
                completedCount += sentCount;
                globalIdsToProcess = data.remaining_ids;
                
                const percent = Math.round((completedCount / totalIdsToProcessCount) * 100);
                updateProgressBar(percent);
                document.getElementById('progressStats').innerText = `${completedCount} / ${totalIdsToProcessCount} dispatched`;
                
                // Call next batch
                ajaxSendBatch();
            } else {
                showToast(data.message, 'danger');
                document.getElementById('progressTitle').innerText = 'Email delivery halted due to error.';
            }
        })
        .catch(err => {
            showToast('Network error during bulk email distribution.', 'danger');
        });
    }
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
