<?php
/**
 * HackMatrix 1.0 - Admin Dashboard
 */

require_once __DIR__ . '/header.php';

$pdo = getDBConnection();

// Fetch summary metrics dynamically
$totalParticipants = $pdo->query("SELECT COUNT(*) FROM participants")->fetchColumn();
$totalCertificates = $pdo->query("SELECT COUNT(*) FROM certificates")->fetchColumn();

$generatedCount = $pdo->query("SELECT COUNT(*) FROM certificates WHERE status = 'GENERATED'")->fetchColumn();
$failedGenCount = $pdo->query("SELECT COUNT(*) FROM certificates WHERE status = 'FAILED'")->fetchColumn();
$notGeneratedCount = $totalParticipants - $generatedCount;

$emailsSent = $pdo->query("SELECT COUNT(*) FROM email_logs WHERE status = 'SENT'")->fetchColumn();
$emailsFailed = $pdo->query("SELECT COUNT(*) FROM email_logs WHERE status = 'FAILED'")->fetchColumn();
$emailsPending = $pdo->query("SELECT COUNT(*) FROM email_logs WHERE status = 'PENDING'")->fetchColumn();

// Fetch 5 most recent activity logs
$recentActivities = $pdo->query("SELECT l.*, a.username FROM activity_logs l LEFT JOIN admins a ON l.admin_id = a.id ORDER BY l.id DESC LIMIT 5")->fetchAll();

// Fetch 5 most recent email logs
$recentEmails = $pdo->query("SELECT e.*, p.participant_name FROM email_logs e JOIN participants p ON e.participant_id = p.id ORDER BY e.id DESC LIMIT 5")->fetchAll();
?>

<div class="page-header">
    <div class="page-title">
        <h1>HackMatrix 1.0 Admin Dashboard</h1>
        <p>Operational control center for certificate compiles and email delivery queues.</p>
    </div>
</div>

<!-- Main Statistics Summary Cards -->
<div class="dashboard-grid">
    <a href="participants.php" class="stat-card" style="text-decoration: none;">
        <div class="stat-card-icon blue">
            <svg viewBox="0 0 24 24" width="24" height="24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
        </div>
        <div class="stat-card-info">
            <div class="value"><?= $totalParticipants ?></div>
            <h3>Total Participants</h3>
        </div>
    </a>
    
    <a href="certificates.php" class="stat-card" style="text-decoration: none;">
        <div class="stat-card-icon purple">
            <svg viewBox="0 0 24 24" width="24" height="24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
        </div>
        <div class="stat-card-info">
            <div class="value"><?= $totalCertificates ?></div>
            <h3>Total Certificates</h3>
        </div>
    </a>
    
    <a href="certificates.php?status=GENERATED" class="stat-card" style="text-decoration: none;">
        <div class="stat-card-icon green">
            <svg viewBox="0 0 24 24" width="24" height="24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
        </div>
        <div class="stat-card-info">
            <div class="value"><?= $generatedCount ?></div>
            <h3>Certificates Generated</h3>
        </div>
    </a>
</div>

<div class="dashboard-grid" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));">
    <a href="logs.php?type=email&search=SENT" class="stat-card" style="text-decoration: none; background: rgba(16, 185, 129, 0.04);">
        <div class="stat-card-icon green" style="background: rgba(16, 185, 129, 0.08);">
            <svg viewBox="0 0 24 24" width="20" height="20"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
        </div>
        <div class="stat-card-info">
            <div class="value"><?= $emailsSent ?></div>
            <h3>Emails Sent</h3>
        </div>
    </a>
    
    <a href="logs.php?type=email&search=FAILED" class="stat-card" style="text-decoration: none; background: rgba(239, 68, 68, 0.04);">
        <div class="stat-card-icon red" style="background: rgba(239, 68, 68, 0.08);">
            <svg viewBox="0 0 24 24" width="20" height="20"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
        </div>
        <div class="stat-card-info">
            <div class="value"><?= $emailsFailed ?></div>
            <h3>Emails Failed</h3>
        </div>
    </a>
    
    <a href="certificates.php?tab=send&status=PENDING" class="stat-card" style="text-decoration: none; background: rgba(245, 158, 11, 0.04);">
        <div class="stat-card-icon yellow" style="background: rgba(245, 158, 11, 0.08);">
            <svg viewBox="0 0 24 24" width="20" height="20"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        </div>
        <div class="stat-card-info">
            <div class="value"><?= $emailsPending ?></div>
            <h3>Emails Pending</h3>
        </div>
    </a>
    
    <a href="certificates.php?status=PENDING" class="stat-card" style="text-decoration: none; background: rgba(255, 255, 255, 0.01);">
        <div class="stat-card-icon blue" style="background: rgba(255, 255, 255, 0.05); color: var(--text-muted);">
            <svg viewBox="0 0 24 24" width="20" height="20"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
        </div>
        <div class="stat-card-info">
            <div class="value"><?= $notGeneratedCount ?></div>
            <h3>Not Generated</h3>
        </div>
    </a>
</div>

<!-- Quick Action Shortcuts -->
<div class="card" style="padding: 28px;">
    <h2 style="font-size: 18px; margin-bottom: 20px; font-weight: 700;">Operational Workflows</h2>
    <div class="btn-group" style="flex-wrap: wrap; gap: 16px;">
        <a href="certificates.php" class="btn btn-primary" style="padding: 14px 28px;">
            <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            Generate Certificates
        </a>
        
        <a href="certificates.php?tab=send" class="btn btn-secondary" style="padding: 14px 28px; border-color: var(--accent-primary); color: white;">
            <svg viewBox="0 0 24 24"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
            Send Certificates
        </a>
        
        <a href="certificates.php?tab=send&retry=1" class="btn btn-secondary" style="padding: 14px 28px; color: var(--warning); border-color: rgba(245, 158, 11, 0.3); background: rgba(245,158,11,0.02);" onclick="triggerRetryShortcut(event)">
            <svg viewBox="0 0 24 24"><path d="M21.5 2v6h-6M21.34 15.57a10 10 0 1 1-.57-8.38l5.67-5.67"/></svg>
            Retry Failed Emails
        </a>
    </div>
</div>

<!-- Activity and Email Logs side-by-side -->
<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px;">
    <!-- Recent Emails -->
    <div class="card" style="padding: 20px 24px;">
        <h3 style="font-size: 16px; font-weight: 700; margin-bottom: 16px; display: flex; justify-content: space-between; align-items: center;">
            Recent Email Dispatches
            <a href="logs.php?type=email" style="font-size: 12px; color: var(--accent-primary); text-decoration: none; font-weight: 600;">View All &rarr;</a>
        </h3>
        
        <div class="table-container">
            <table class="table" style="font-size: 13px;">
                <thead>
                    <tr>
                        <th>Recipient</th>
                        <th>Cert ID</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($recentEmails) > 0): ?>
                        <?php foreach ($recentEmails as $re): ?>
                            <tr>
                                <td style="font-weight: 600; color: white;">
                                    <?= e($re['participant_name']) ?>
                                    <div style="font-size: 11px; color: var(--text-muted);"><?= e($re['email']) ?></div>
                                </td>
                                <td style="font-family: var(--font-mono); font-weight: 700;"><?= e($re['certificate_id']) ?></td>
                                <td>
                                    <?php if ($re['status'] === 'SENT'): ?>
                                        <span class="badge badge-success" style="font-size: 9px;">Sent</span>
                                    <?php elseif ($re['status'] === 'FAILED'): ?>
                                        <span class="badge badge-danger" style="font-size: 9px;">Failed</span>
                                    <?php else: ?>
                                        <span class="badge badge-warning" style="font-size: 9px;">Pending</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="3" style="text-align: center; padding: 20px; color: var(--text-muted);">No email actions logged.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <!-- Recent Activities -->
    <div class="card" style="padding: 20px 24px;">
        <h3 style="font-size: 16px; font-weight: 700; margin-bottom: 16px; display: flex; justify-content: space-between; align-items: center;">
            System Activities
            <a href="logs.php?type=activity" style="font-size: 12px; color: var(--accent-primary); text-decoration: none; font-weight: 600;">View All &rarr;</a>
        </h3>
        
        <div class="table-container">
            <table class="table" style="font-size: 13px;">
                <thead>
                    <tr>
                        <th>Action</th>
                        <th>IP Address</th>
                        <th>Time</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($recentActivities) > 0): ?>
                        <?php foreach ($recentActivities as $ra): ?>
                            <tr>
                                <td>
                                    <span class="badge <?= strpos($ra['action'], 'FAILED') !== false ? 'badge-danger' : 'badge-info' ?>" style="font-size: 9px; font-weight: 700;">
                                        <?= e($ra['action']) ?>
                                    </span>
                                    <div style="font-size: 11px; color: var(--text-main); margin-top: 4px;" title="<?= e($ra['details']) ?>">
                                        <?= e(mb_strimwidth($ra['details'], 0, 45, '...')) ?>
                                    </div>
                                </td>
                                <td style="font-family: var(--font-mono); font-size: 11px;"><?= e($ra['ip_address']) ?></td>
                                <td style="font-size: 11px; color: var(--text-muted);"><?= date('H:i:s d-M', strtotime($ra['created_at'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="3" style="text-align: center; padding: 20px; color: var(--text-muted);">No operations registered.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
    function triggerRetryShortcut(e) {
        e.preventDefault();
        // Redirect to email distribution list and trigger retry directly via local storage command
        localStorage.setItem('trigger_retry_on_load', '1');
        window.location.href = 'certificates.php?tab=send';
    }
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
