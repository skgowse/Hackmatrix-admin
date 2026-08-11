<?php
/**
 * HackMatrix 1.0 - Brevo API Configurations & Test Email Dispatch
 */

require_once __DIR__ . '/header.php';
require_once __DIR__ . '/../includes/certificate.php';
require_once __DIR__ . '/../includes/mailer.php';

$pdo = getDBConnection();
$error = '';
$successMsg = '';

// Handle Settings Save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    $csrfToken = $_POST['csrf_token'] ?? '';
    
    if (!validateCSRFToken($csrfToken)) {
        $error = 'Invalid security token (CSRF failure).';
    } else {
        $host = 'api.brevo.com';
        $port = 443;
        $user = 'brevo';
        $password = $_POST['smtp_password'] ?? '';
        $encryption = 'https';
        $fromEmail = trim($_POST['from_email'] ?? '');
        $fromName = trim($_POST['from_name'] ?? '');
        
        if (empty($password) && !($password === '••••••••')) {
            $error = 'Brevo API Key is required.';
        } elseif (empty($fromEmail) || empty($fromName)) {
            $error = 'Sender From Email and Sender From Name are required.';
        } elseif (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            $error = 'Invalid "From Email" address format.';
        } else {
            try {
                // Check if setting already exists
                $existing = $pdo->query("SELECT * FROM smtp_settings LIMIT 1")->fetch();
                
                // Process Password (API Key)
                if ($password === '••••••••' || empty($password)) {
                    // Preserving existing key
                    if ($existing) {
                        $encryptedPassword = $existing['smtp_password'];
                    } else {
                        $error = 'Please enter a Brevo API Key for the initial setup.';
                    }
                } else {
                    // Encrypt key
                    $encryptedPassword = encryptSMTPPassword($password);
                }
                
                if (empty($error)) {
                    if ($existing) {
                        $stmt = $pdo->prepare("UPDATE smtp_settings SET smtp_host = ?, smtp_port = ?, smtp_username = ?, smtp_password = ?, smtp_encryption = ?, from_email = ?, from_name = ? WHERE id = ?");
                        $stmt->execute([$host, $port, $user, $encryptedPassword, $encryption, $fromEmail, $fromName, $existing['id']]);
                    } else {
                        $stmt = $pdo->prepare("INSERT INTO smtp_settings (smtp_host, smtp_port, smtp_username, smtp_password, smtp_encryption, from_email, from_name) VALUES (?, ?, ?, ?, ?, ?, ?)");
                        $stmt->execute([$host, $port, $user, $encryptedPassword, $encryption, $fromEmail, $fromName]);
                    }
                    
                    $successMsg = 'Brevo API configurations updated successfully.';
                    logActivity('SMTP_SETTINGS_CHANGED', 'Updated Brevo API configurations.');
                }
            } catch (Exception $e) {
                $error = 'Database update failed: ' . $e->getMessage();
            }
        }
    }
}

// Handle Send Test Email
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_test'])) {
    $csrfToken = $_POST['csrf_token'] ?? '';
    
    if (!validateCSRFToken($csrfToken)) {
        jsonResponse(false, 'Invalid security token (CSRF failure).');
    }
    
    $testEmail = trim($_POST['test_email'] ?? '');
    
    if (empty($testEmail) || !filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
        jsonResponse(false, 'Please provide a valid test email address.');
    }
    
    try {
        // Fetch config from DB
        $smtp = $pdo->query("SELECT * FROM smtp_settings LIMIT 1")->fetch();
        if (!$smtp) {
            jsonResponse(false, 'Please configure and save your Brevo API key first.');
        }
        
        // Fetch active template
        $template = $pdo->query("SELECT * FROM certificate_templates LIMIT 1")->fetch();
        if (!$template) {
            jsonResponse(false, 'Please upload a certificate template first.');
        }
        
        // Fetch fields
        $stmtFields = $pdo->prepare("SELECT * FROM certificate_fields WHERE template_id = ?");
        $stmtFields->execute([$template['id']]);
        $fields = $stmtFields->fetchAll();
        
        if (empty($fields)) {
            jsonResponse(false, 'Please configure field coordinates in the Editor first.');
        }
        
        // Mock Candidate data for test
        $mock = [
            'participant_name' => 'John Doe',
            'branch' => 'Computer Science & Engineering',
            'team_name' => 'Team Alpha',
            'team_no' => 'HM000',
            'certificate_id' => 'HM-TEST',
            'email' => $testEmail
        ];
        
        // Compile mock PDF certificate
        $pdfFilename = 'HM_TEST_CERTIFICATE.pdf';
        $pdfPath = __DIR__ . '/../certificates/' . $pdfFilename;
        
        CertificateGenerator::generate($mock, $template, $fields, $pdfPath);
        
        // Load default email template
        $emailTemplate = $pdo->query("SELECT * FROM email_templates LIMIT 1")->fetch();
        if (!$emailTemplate) {
            // Fallback default
            $emailTemplate = [
                'subject' => 'HackMatrix 1.0 – Certificate of Participation',
                'body' => "Dear {{participant_name}},\n\nThis is a test certificate from HackMatrix 1.0."
            ];
        }
        
        // Dispatch test email
        $res = MailerHelper::sendCertificate($mock, $pdfPath, $smtp, $emailTemplate);
        
        // Clean up test certificate file
        if (file_exists($pdfPath)) {
            @unlink($pdfPath);
        }
        
        if ($res === true) {
            // Update test sent timestamp
            $pdo->prepare("UPDATE smtp_settings SET test_sent_at = NOW() WHERE id = ?")->execute([$smtp['id']]);
            logActivity('SMTP_SETTINGS_CHANGED', 'Sent a test email successfully to: ' . $testEmail);
            jsonResponse(true, 'Test email dispatched successfully! Please check your inbox.');
        } else {
            jsonResponse(false, 'Brevo Dispatch Failed: ' . $res);
        }
    } catch (Exception $e) {
        jsonResponse(false, 'System error: ' . $e->getMessage());
    }
}

// Load configurations
$smtp = $pdo->query("SELECT * FROM smtp_settings LIMIT 1")->fetch();
?>

<div class="page-header">
    <div class="page-title">
        <h1>Email Settings</h1>
        <p>Configure outgoing Brevo API credentials and test connectivity.</p>
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

<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 30px;">
    <!-- Brevo Configuration Card -->
    <div class="card">
        <h2 style="font-size: 18px; margin-bottom: 20px; font-weight: 700;">Brevo API Settings</h2>
        
        <form method="POST">
            <?php csrfInput(); ?>
            
            <div class="form-group">
                <label for="smtp_password">BREVO API KEY</label>
                <input type="password" id="smtp_password" name="smtp_password" class="form-control" required placeholder="xkeysib-..." 
                       value="<?= $smtp ? '••••••••' : '' ?>">
                <small style="color: var(--text-muted); font-size: 11px; margin-top: 4px; display: block;">
                    Generate this key in SMTP & API section of your Brevo Dashboard.
                </small>
            </div>
            
            <div class="form-grid">
                <div class="form-group">
                    <label for="from_email">SENDER FROM EMAIL</label>
                    <input type="email" id="from_email" name="from_email" class="form-control" required placeholder="e.g. hackmatrix@vignaniit.edu.in" 
                           value="<?= isset($_POST['from_email']) ? e($_POST['from_email']) : ($smtp ? e($smtp['from_email']) : '') ?>">
                </div>
                
                <div class="form-group">
                    <label for="from_name">SENDER FROM NAME</label>
                    <input type="text" id="from_name" name="from_name" class="form-control" required placeholder="e.g. HackMatrix 1.0 Team" 
                           value="<?= isset($_POST['from_name']) ? e($_POST['from_name']) : ($smtp ? e($smtp['from_name']) : 'HackMatrix 1.0') ?>">
                </div>
            </div>
            
            <button type="submit" name="save_settings" class="btn btn-primary" style="width: 100%; margin-top: 10px;">
                <svg viewBox="0 0 24 24"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                Save Settings
            </button>
        </form>
    </div>
    
    <!-- Test Connection Card -->
    <div class="card">
        <h2 style="font-size: 18px; margin-bottom: 20px; font-weight: 700;">Connection Test</h2>
        
        <?php if ($smtp): ?>
            <div style="background: rgba(8, 13, 26, 0.4); padding: 16px; border-radius: 10px; border: 1px solid var(--border-color); margin-bottom: 20px;">
                <div style="display: flex; justify-content: space-between; margin-bottom: 12px;">
                    <span style="font-size: 13px; color: var(--text-muted); font-weight: 600;">Status:</span>
                    <span style="font-size: 13px; color: var(--success); font-weight: 700;">Brevo API Configurations Saved</span>
                </div>
                <div style="display: flex; justify-content: space-between;">
                    <span style="font-size: 13px; color: var(--text-muted); font-weight: 600;">Last Test Dispatched:</span>
                    <span style="font-size: 13px; color: white;">
                        <?= $smtp['test_sent_at'] ? e($smtp['test_sent_at']) : 'Never tested' ?>
                    </span>
                </div>
            </div>
            
            <div class="form-group">
                <label for="test_email_input">RECIPIENT EMAIL ADDRESS FOR TEST</label>
                <input type="email" id="test_email_input" class="form-control" placeholder="e.g. tester@gmail.com" required>
            </div>
            
            <button type="button" id="sendTestBtn" class="btn btn-secondary" style="width: 100%; border-color: var(--accent-primary); color: white;">
                <svg viewBox="0 0 24 24"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                Send Test Email
            </button>
            
            <!-- error debugger console -->
            <div id="smtpLogConsole" class="console" style="display: none; max-height: 200px; margin-top: 20px;">
                <span class="tag tag-danger">ERROR</span>
                <span id="smtpErrorDetails" style="white-space: pre-wrap;"></span>
            </div>
        <?php else: ?>
            <div style="text-align: center; padding: 48px 0; color: var(--text-muted);">
                <svg viewBox="0 0 24 24" style="width: 64px; height: 64px; stroke: rgba(255,255,255,0.1); fill: none; stroke-width: 1.5; margin-bottom: 16px;">
                    <path d="M22 12h-6l-2 3h-4l-2-3H2"/>
                </svg>
                <p style="font-size: 14px; font-weight: 600;">Brevo Configurations Empty</p>
                <p style="font-size: 12px; margin-top: 4px;">Fill and save the settings form on the left to configure.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
    const sendTestBtn = document.getElementById('sendTestBtn');
    
    if (sendTestBtn) {
        sendTestBtn.addEventListener('click', function() {
            const emailInput = document.getElementById('test_email_input');
            const email = emailInput.value.trim();
            const consoleBox = document.getElementById('smtpLogConsole');
            const errorDetails = document.getElementById('smtpErrorDetails');
            
            if (email === '') {
                showToast('Please enter an email address for the test.', 'warning');
                return;
            }
            
            // Disable button during operation
            sendTestBtn.disabled = true;
            sendTestBtn.innerText = 'Dispatching test...';
            consoleBox.style.display = 'none';
            
            const formData = new FormData();
            formData.append('send_test', '1');
            formData.append('csrf_token', '<?= generateCSRFToken() ?>');
            formData.append('test_email', email);
            
            fetch('email-settings.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                sendTestBtn.disabled = false;
                sendTestBtn.innerHTML = '<svg viewBox="0 0 24 24"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg> Send Test Email';
                
                if (data.success) {
                    showToast(data.message, 'success');
                    emailInput.value = '';
                } else {
                    showToast('Test email dispatch failed.', 'danger');
                    consoleBox.style.display = 'block';
                    errorDetails.innerText = data.message;
                }
            })
            .catch(err => {
                sendTestBtn.disabled = false;
                sendTestBtn.innerHTML = '<svg viewBox="0 0 24 24"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg> Send Test Email';
                showToast('Network error occurred.', 'danger');
            });
        });
    }
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
