<?php
/**
 * HackMatrix 1.0 - Email Template Customization
 */

require_once __DIR__ . '/header.php';
require_once __DIR__ . '/../includes/mailer.php';

$pdo = getDBConnection();
$error = '';
$successMsg = '';

// Handle Template Save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_template'])) {
    $csrfToken = $_POST['csrf_token'] ?? '';
    
    if (!validateCSRFToken($csrfToken)) {
        $error = 'Invalid security token (CSRF failure).';
    } else {
        $subject = trim($_POST['subject'] ?? '');
        $body = trim($_POST['body'] ?? '');
        
        if (empty($subject) || empty($body)) {
            $error = 'Both Subject and Email Body are required.';
        } else {
            try {
                $existing = $pdo->query("SELECT * FROM email_templates LIMIT 1")->fetch();
                
                if ($existing) {
                    $stmt = $pdo->prepare("UPDATE email_templates SET subject = ?, body = ? WHERE id = ?");
                    $stmt->execute([$subject, $body, $existing['id']]);
                } else {
                    $stmt = $pdo->prepare("INSERT INTO email_templates (subject, body) VALUES (?, ?)");
                    $stmt->execute([$subject, $body]);
                }
                
                $successMsg = 'Email template configurations updated successfully.';
                logActivity('SMTP_SETTINGS_CHANGED', 'Updated email subject and body template.');
            } catch (Exception $e) {
                $error = 'Database update failed: ' . $e->getMessage();
            }
        }
    }
}

// Load current template
$emailTemplate = $pdo->query("SELECT * FROM email_templates LIMIT 1")->fetch();

// Sample rendering for visual preview
$mockParticipant = [
    'participant_name' => 'Rahul Kumar',
    'email' => 'rahul@gmail.com',
    'branch' => 'Artificial Intelligence & Data Science',
    'team_name' => 'Team Alpha',
    'team_no' => 'HM001',
    'certificate_id' => 'HM26-001'
];

$previewSubject = '';
$previewBody = '';

if ($emailTemplate) {
    $previewSubject = MailerHelper::parseTemplate($emailTemplate['subject'], $mockParticipant);
    $previewBody = MailerHelper::parseTemplate($emailTemplate['body'], $mockParticipant);
}
?>

<div class="page-header">
    <div class="page-title">
        <h1>Email Template</h1>
        <p>Edit and customize the email subject, message body, and attachment placeholders.</p>
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

<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 30px;" class="template-editor-grid">
    <!-- Editor Card -->
    <div class="card">
        <h2 style="font-size: 18px; margin-bottom: 20px; font-weight: 700;">Template Fields</h2>
        
        <form method="POST">
            <?php csrfInput(); ?>
            
            <div class="form-group">
                <label for="subject">EMAIL SUBJECT</label>
                <input type="text" id="subject" name="subject" class="form-control" required placeholder="e.g. HackMatrix 1.0 – Certificate of Participation" 
                       value="<?= isset($_POST['subject']) ? e($_POST['subject']) : ($emailTemplate ? e($emailTemplate['subject']) : '') ?>">
            </div>
            
            <div class="form-group">
                <label for="body">EMAIL BODY (PLAINTEXT)</label>
                <textarea id="body" name="body" class="form-control" required rows="10" placeholder="Type message body here..." style="resize: vertical; font-family: inherit; line-height: 1.6;"><?= isset($_POST['body']) ? e($_POST['body']) : ($emailTemplate ? e($emailTemplate['body']) : '') ?></textarea>
            </div>
            
            <button type="submit" name="save_template" class="btn btn-primary" style="width: 100%; margin-top: 10px;">
                <svg viewBox="0 0 24 24"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                Save Template
            </button>
        </form>
        
        <div style="margin-top: 24px; border-top: 1px solid var(--border-color); padding-top: 20px;">
            <h4 style="font-size: 13px; color: var(--text-muted); font-weight: 700; margin-bottom: 12px; letter-spacing: 0.5px;">AVAILABLE PLACEHOLDER TAGS:</h4>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px; font-family: var(--font-mono); font-size: 12px; color: var(--accent-primary);">
                <div>{{participant_name}}</div>
                <div>{{branch}}</div>
                <div>{{team_name}}</div>
                <div>{{team_no}}</div>
                <div>{{certificate_id}}</div>
            </div>
        </div>
    </div>
    
    <!-- Rendered Visual Preview Card -->
    <div class="card">
        <h2 style="font-size: 18px; margin-bottom: 20px; font-weight: 700;">Live Dispatch Preview</h2>
        
        <?php if ($emailTemplate): ?>
            <div style="background: rgba(8, 13, 26, 0.5); border: 1px solid var(--border-color); border-radius: 12px; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.3);">
                <!-- Header envelope details -->
                <div style="padding: 16px 20px; border-bottom: 1px solid var(--border-color); background: rgba(13,19,33,0.4); display: flex; flex-direction: column; gap: 8px;">
                    <div style="display: flex; font-size: 13px;">
                        <span style="color: var(--text-muted); width: 80px; font-weight: 600;">Subject:</span>
                        <span style="font-weight: 700; color: white;"><?= e($previewSubject) ?></span>
                    </div>
                    <div style="display: flex; font-size: 13px;">
                        <span style="color: var(--text-muted); width: 80px; font-weight: 600;">Recipient:</span>
                        <span style="color: white; font-weight: 600;"><?= e($mockParticipant['participant_name']) ?> &lt;<?= e($mockParticipant['email']) ?>&gt;</span>
                    </div>
                </div>
                
                <!-- Email body content -->
                <div style="padding: 24px 20px; min-height: 240px; white-space: pre-wrap; font-size: 14px; line-height: 1.6; color: #d1d5db; font-family: inherit;">
                    <?= e($previewBody) ?>
                </div>
                
                <!-- Attachment panel visual -->
                <div style="padding: 12px 20px; border-top: 1px solid var(--border-color); background: rgba(8,13,26,0.8); display: flex; align-items: center; justify-content: space-between;">
                    <div style="display: flex; align-items: center;">
                        <svg viewBox="0 0 24 24" style="width: 24px; height: 24px; stroke: var(--danger); fill: none; stroke-width: 2; margin-right: 12px;">
                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/>
                        </svg>
                        <div>
                            <div style="font-size: 13px; font-weight: 700; color: white;"><?= e($mockParticipant['certificate_id']) ?>.pdf</div>
                            <div style="font-size: 11px; color: var(--text-muted);">PDF Certificate attachment (Auto Generated)</div>
                        </div>
                    </div>
                    <span style="font-size: 11px; color: var(--text-muted); font-weight: 600;">ATTACHED</span>
                </div>
            </div>
        <?php else: ?>
            <div style="text-align: center; padding: 48px 0; color: var(--text-muted);">
                <svg viewBox="0 0 24 24" style="width: 64px; height: 64px; stroke: rgba(255,255,255,0.1); fill: none; stroke-width: 1.5; margin-bottom: 16px;">
                    <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/>
                </svg>
                <p style="font-size: 14px; font-weight: 600;">No Email Template Configured</p>
                <p style="font-size: 12px; margin-top: 4px;">Fill the form on the left to initialize.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
