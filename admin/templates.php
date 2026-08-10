<?php
/**
 * HackMatrix 1.0 - Certificate Template Management
 */

require_once __DIR__ . '/header.php';

$pdo = getDBConnection();
$error = '';
$successMsg = '';

// Handle Template Upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_template'])) {
    $csrfToken = $_POST['csrf_token'] ?? '';
    $templateName = trim($_POST['template_name'] ?? '');
    $pageSize = $_POST['page_size'] ?? 'A4';
    $orientation = $_POST['orientation'] ?? 'L';
    
    // Validate CSRF
    if (!validateCSRFToken($csrfToken)) {
        $error = 'Invalid security token (CSRF failure).';
    } elseif (empty($templateName)) {
        $error = 'Please enter a name for the template.';
    } elseif (empty($_FILES['template_file']['name'])) {
        $error = 'Please select a template file to upload.';
    } else {
        $file = $_FILES['template_file'];
        $maxSize = 10 * 1024 * 1024; // 10MB limit
        $allowedExtensions = ['pdf', 'png', 'jpg', 'jpeg'];
        
        $validation = validateUploadedFile($file, $maxSize, $allowedExtensions);
        if ($validation !== true) {
            $error = $validation;
        } else {
            // Generate a secure unique name
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $secureFilename = 'template_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            $uploadPath = __DIR__ . '/../uploads/templates/' . $secureFilename;
            
            // Move file to target directory
            if (move_uploaded_file($file['tmp_name'], $uploadPath)) {
                // Determine dimensions
                $width = 297; // Default A4 Landscape in mm
                $height = 210;
                
                if ($pageSize === 'Letter') {
                    $width = ($orientation === 'L') ? 279.4 : 215.9;
                    $height = ($orientation === 'L') ? 215.9 : 279.4;
                } else { // A4
                    $width = ($orientation === 'L') ? 297 : 210;
                    $height = ($orientation === 'L') ? 210 : 297;
                }
                
                try {
                    // Start transaction
                    $pdo->beginTransaction();
                    
                    // We only support one active template for simplicity, so clear others (or delete physical files)
                    $stmt = $pdo->query("SELECT file_path FROM certificate_templates");
                    while ($oldTemplate = $stmt->fetch()) {
                        $oldFile = __DIR__ . '/../' . $oldTemplate['file_path'];
                        if (file_exists($oldFile)) {
                            @unlink($oldFile);
                        }
                    }
                    
                    // Truncate templates table (cascade deletes fields)
                    $pdo->exec("DELETE FROM certificate_templates");
                    
                    // Insert new template
                    $stmt = $pdo->prepare("INSERT INTO certificate_templates (template_name, file_path, page_width, page_height, orientation) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([
                        $templateName,
                        'uploads/templates/' . $secureFilename,
                        $width,
                        $height,
                        $orientation
                    ]);
                    
                    $newTemplateId = $pdo->lastInsertId();
                    
                    // Create default coordinates for fields to make editing easier
                    $defaultFields = [
                        ['participant_name', 50.0, 45.0, 'helvetica', 28, 'B', '#000000', 'C'],
                        ['branch', 50.0, 58.0, 'helvetica', 18, '', '#4b5563', 'C'],
                        ['team_name', 50.0, 68.0, 'helvetica', 16, 'I', '#4b5563', 'C'],
                        ['team_no', 30.0, 80.0, 'helvetica', 12, '', '#9ca3af', 'L'],
                        ['certificate_id', 70.0, 80.0, 'helvetica', 11, '', '#9ca3af', 'R']
                    ];
                    
                    $stmtField = $pdo->prepare("INSERT INTO certificate_fields (template_id, field_name, x_position, y_position, font_name, font_size, font_style, text_color, alignment) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    foreach ($defaultFields as $df) {
                        $stmtField->execute([
                            $newTemplateId,
                            $df[0], // name
                            $df[1], // x
                            $df[2], // y
                            $df[3], // font
                            $df[4], // size
                            $df[5], // style
                            $df[6], // color
                            $df[7]  // align
                        ]);
                    }
                    
                    $pdo->commit();
                    $successMsg = 'Certificate template uploaded successfully and default fields initialized.';
                    logActivity('TEMPLATE_CHANGED', 'Uploaded new template: ' . $templateName);
                } catch (Exception $e) {
                    $pdo->rollBack();
                    @unlink($uploadPath);
                    $error = 'Database storage failed: ' . $e->getMessage();
                }
            } else {
                $error = 'Failed to move uploaded file to target folder.';
            }
        }
    }
}

// Handle Template Deletion
if (isset($_POST['delete_template'])) {
    $csrfToken = $_POST['csrf_token'] ?? '';
    
    if (!validateCSRFToken($csrfToken)) {
        $error = 'Invalid security token (CSRF failure).';
    } else {
        try {
            $stmt = $pdo->query("SELECT file_path FROM certificate_templates LIMIT 1");
            $template = $stmt->fetch();
            
            if ($template) {
                $filePath = __DIR__ . '/../' . $template['file_path'];
                if (file_exists($filePath)) {
                    @unlink($filePath);
                }
                
                $pdo->exec("DELETE FROM certificate_templates");
                $successMsg = 'Certificate template deleted successfully.';
                logActivity('TEMPLATE_CHANGED', 'Deleted template file and record.');
            }
        } catch (Exception $e) {
            $error = 'Deletion failed: ' . $e->getMessage();
        }
    }
}

// Get Active Template
$template = $pdo->query("SELECT * FROM certificate_templates LIMIT 1")->fetch();
?>

<div class="page-header">
    <div class="page-title">
        <h1>Certificate Template</h1>
        <p>Upload and manage the background design for your hackathon certificates.</p>
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

<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 30px;" class="template-layout">
    <!-- Upload Section -->
    <div class="card">
        <h2 style="font-size: 18px; margin-bottom: 20px; font-weight: 700;">Upload Template Design</h2>
        
        <form method="POST" enctype="multipart/form-data">
            <?php csrfInput(); ?>
            
            <div class="form-group">
                <label for="template_name">TEMPLATE NAME</label>
                <input type="text" id="template_name" name="template_name" class="form-control" placeholder="e.g. HackMatrix 1.0 Participation Certificate" required 
                       value="<?= isset($_POST['template_name']) ? e($_POST['template_name']) : '' ?>">
            </div>
            
            <div class="form-group">
                <label for="template_file">SELECT FILE (PDF, PNG, JPG - MAX 10MB)</label>
                <input type="file" id="template_file" name="template_file" class="form-control" accept=".pdf,.png,.jpg,.jpeg" required style="padding: 8px 12px;">
                <p style="font-size: 11px; color: var(--text-muted); margin-top: 6px;">
                    > [!NOTE]<br>
                    A4 PDF format is highly recommended for vector scaling and absolute clarity in print.
                </p>
            </div>
            
            <div class="form-grid">
                <div class="form-group">
                    <label for="page_size">PAGE SIZE</label>
                    <select id="page_size" name="page_size" class="form-control">
                        <option value="A4">A4 (297 x 210 mm)</option>
                        <option value="Letter">Letter (279.4 x 215.9 mm)</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="orientation">ORIENTATION</label>
                    <select id="orientation" name="orientation" class="form-control">
                        <option value="L">Landscape (Horizontal)</option>
                        <option value="P">Portrait (Vertical)</option>
                    </select>
                </div>
            </div>
            
            <button type="submit" name="upload_template" class="btn btn-primary" style="width: 100%; margin-top: 10px;">
                <svg viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                Upload & Initialize Template
            </button>
        </form>
    </div>
    
    <!-- Status / Active Template -->
    <div class="card">
        <h2 style="font-size: 18px; margin-bottom: 20px; font-weight: 700;">Active Design</h2>
        
        <?php if ($template): ?>
            <div style="display: flex; flex-direction: column; gap: 16px;">
                <div style="background: rgba(8, 13, 26, 0.4); padding: 16px; border-radius: 10px; border: 1px solid var(--border-color);">
                    <div style="display: flex; justify-content: space-between; margin-bottom: 12px;">
                        <span style="font-size: 13px; color: var(--text-muted); font-weight: 600;">Name:</span>
                        <span style="font-size: 14px; font-weight: 700; color: white;"><?= e($template['template_name']) ?></span>
                    </div>
                    <div style="display: flex; justify-content: space-between; margin-bottom: 12px;">
                        <span style="font-size: 13px; color: var(--text-muted); font-weight: 600;">File Path:</span>
                        <span style="font-size: 13px; font-family: var(--font-mono); color: var(--accent-primary);"><?= e($template['file_path']) ?></span>
                    </div>
                    <div style="display: flex; justify-content: space-between; margin-bottom: 12px;">
                        <span style="font-size: 13px; color: var(--text-muted); font-weight: 600;">Dimensions:</span>
                        <span style="font-size: 13px; color: white;"><?= e($template['page_width']) ?>mm &times; <?= e($template['page_height']) ?>mm</span>
                    </div>
                    <div style="display: flex; justify-content: space-between;">
                        <span style="font-size: 13px; color: var(--text-muted); font-weight: 600;">Orientation:</span>
                        <span style="font-size: 13px; color: white;"><?= $template['orientation'] === 'L' ? 'Landscape' : 'Portrait' ?></span>
                    </div>
                </div>
                
                <div style="border: 1px solid var(--border-color); border-radius: 12px; overflow: hidden; background: #080d1a; display: flex; align-items: center; justify-content: center; height: 180px;">
                    <?php 
                    $fileExt = pathinfo($template['file_path'], PATHINFO_EXTENSION);
                    if (in_array($fileExt, ['png', 'jpg', 'jpeg'])): ?>
                        <img src="../<?= e($template['file_path']) ?>" alt="Template Preview" style="max-width: 100%; max-height: 100%; object-fit: contain;">
                    <?php else: ?>
                        <div style="text-align: center; color: var(--text-muted);">
                            <svg viewBox="0 0 24 24" style="width: 48px; height: 48px; stroke: var(--accent-primary); fill: none; stroke-width: 1.5; margin-bottom: 10px;">
                                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/>
                            </svg>
                            <p style="font-weight: 600; font-size: 13px;">A4 PDF Template Active</p>
                            <a href="../<?= e($template['file_path']) ?>" target="_blank" style="color: var(--accent-primary); font-size: 12px; text-decoration: none; margin-top: 6px; display: inline-block;">Open Template PDF &nearr;</a>
                        </div>
                    <?php endif; ?>
                </div>

                <div style="display: flex; gap: 12px;">
                    <a href="certificate-editor.php" class="btn btn-secondary" style="flex: 1;">
                        <svg viewBox="0 0 24 24"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
                        Edit Coordinates
                    </a>
                    
                    <form method="POST" style="flex: 1;" onsubmit="return confirm('Are you sure you want to delete this template and all configuration mappings?');">
                        <?php csrfInput(); ?>
                        <button type="submit" name="delete_template" class="btn btn-danger" style="width: 100%;">
                            <svg viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>
                            Delete Design
                        </button>
                    </form>
                </div>
            </div>
        <?php else: ?>
            <div style="text-align: center; padding: 48px 0; color: var(--text-muted);">
                <svg viewBox="0 0 24 24" style="width: 64px; height: 64px; stroke: rgba(255,255,255,0.1); fill: none; stroke-width: 1.5; margin-bottom: 16px;">
                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/>
                </svg>
                <p style="font-size: 14px; font-weight: 600;">No Active Template Design Found</p>
                <p style="font-size: 12px; margin-top: 4px;">Upload a PDF or Image background to get started.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
