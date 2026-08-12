<?php
/**
 * HackMatrix 1.0 - Admin API: Update Team & Members
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

if (!isLoggedIn()) {
    jsonResponse(false, 'Unauthorized access.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Only POST requests are allowed.');
}

$pdo = getDBConnection();

// 1. CSRF Verification
$csrfToken = $_POST['csrf_token'] ?? '';
if (!validateCSRFToken($csrfToken)) {
    jsonResponse(false, 'Invalid security token (CSRF failure).');
}

$teamDbId = intval($_POST['team_db_id'] ?? 0);

// 2. Extract and Sanitize Inputs
$teamName = trim($_POST['team_name'] ?? '');
$college = trim($_POST['college'] ?? '');
$teamSize = intval($_POST['team_size'] ?? 0);
$domain = trim($_POST['domain'] ?? '');
$projectTitle = '';
$status = trim($_POST['status'] ?? 'ACTIVE');
$members = $_POST['members'] ?? [];

$allowedDomains = ['AI & ML', 'Cloud Computing', 'Cybersecurity', 'Robotics'];
$allowedYears = ['1', '2', '3', '4'];

if (empty($teamName) || empty($college) || empty($domain)) {
    jsonResponse(false, 'All team profile fields are required.');
}

if (!in_array($teamSize, [2, 3, 4])) {
    jsonResponse(false, 'Team size must be 2, 3, or 4.');
}

if (!in_array($domain, $allowedDomains)) {
    jsonResponse(false, 'Selected domain is invalid.');
}

if (count($members) !== $teamSize) {
    jsonResponse(false, 'The number of submitted members must match the team size.');
}

// 3. Validate Each Member and Check Internal Duplicates
$emailsForm = [];
$mobilesForm = [];

foreach ($members as $index => $m) {
    $mId = intval($m['id'] ?? 0);
    $name = trim($m['name'] ?? '');
    $email = strtolower(trim($m['email'] ?? ''));
    $mobile = '';
    $branch = trim($m['branch'] ?? '');
    $year = '';
    
    $num = $index + 1;
    $roleName = ($index === 0) ? 'Team Lead' : 'Member';
    

    if (empty($name) || empty($email) || empty($branch)) {
        jsonResponse(false, "All fields are required for Member $num ($roleName).");
    }
    
    $emailValid = validateEmailStrongly($email);
    if ($emailValid !== true) {
        jsonResponse(false, "Member $num ($roleName): " . $emailValid);
    }
    

}

try {
    // 4. Verify Database Uniqueness Constraints
    // Team name check
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM teams WHERE LOWER(team_name) = ? AND id != ?");
    $stmt->execute([strtolower($teamName), $teamDbId]);
    if ($stmt->fetchColumn() > 0) {
        jsonResponse(false, "The team name '$teamName' is already registered by another team.");
    }
    
    $teamCode = '';
    if ($teamDbId > 0) {
        // Fetch current team code
        $stmtTeam = $pdo->prepare("SELECT team_id FROM teams WHERE id = ?");
        $stmtTeam->execute([$teamDbId]);
        $teamCode = $stmtTeam->fetchColumn();
        
        if (!$teamCode) {
            jsonResponse(false, 'Team record not found.');
        }
    }
    
    // Member checks
    foreach ($members as $index => $m) {
        $mId = intval($m['id'] ?? 0);
        $email = strtolower(trim($m['email'] ?? ''));
        $mobile = '';
        
        $num = $index + 1;
        
        // Check email database uniqueness (excluding the member being updated)
        if ($mId > 0) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM team_members WHERE LOWER(email) = ? AND id != ?");
            $stmt->execute([$email, $mId]);
        } else {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM team_members WHERE LOWER(email) = ?");
            $stmt->execute([$email]);
        }
        if ($stmt->fetchColumn() > 0) {
            jsonResponse(false, "The email '$email' for Member $num is already registered by another participant.");
        }
        

    }
    
    // 5. Update Database within Transaction
    $pdo->beginTransaction();
    
    if ($teamDbId > 0) {
        // Update Team profile
        $stmt = $pdo->prepare("UPDATE teams SET team_name = ?, college = ?, team_size = ?, domain = ?, project_title = ?, status = ? WHERE id = ?");
        $stmt->execute([
            $teamName,
            $college,
            $teamSize,
            $domain,
            $projectTitle,
            $status,
            $teamDbId
        ]);
    } else {
        // Get next Team Sequence
        $stmtSeq = $pdo->query("SELECT MAX(CAST(SUBSTRING(team_id, 6) AS UNSIGNED)) FROM teams");
        $maxSeq = $stmtSeq->fetchColumn();
        $newSeq = $maxSeq ? intval($maxSeq) + 1 : 1;
        $teamCode = "HM26-" . str_pad($newSeq, 4, '0', STR_PAD_LEFT);
        
        // Insert Team profile
        $stmt = $pdo->prepare("INSERT INTO teams (team_id, team_name, college, team_size, domain, project_title, status) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $teamCode,
            $teamName,
            $college,
            $teamSize,
            $domain,
            $projectTitle,
            $status
        ]);
        $teamDbId = $pdo->lastInsertId();
    }
    
    // Identify submitted member IDs to determine which ones to delete
    $submittedMemberIds = [];
    
    // Process members (Insert or Update)
    foreach ($members as $index => $m) {
        $mId = intval($m['id'] ?? 0);
        $name = trim($m['name'] ?? '');
        $email = strtolower(trim($m['email'] ?? ''));
        $mobile = '';
        $branch = trim($m['branch'] ?? '');
        $year = '';
        $salutation = '';
        $role = ($index === 0) ? 'Team Lead' : 'Member';
        $memberCertId = $teamCode . "-" . ($index + 1);
        
        if ($mId > 0) {
            // Update existing member
            $stmt = $pdo->prepare("UPDATE team_members SET name = ?, email = ?, mobile = ?, branch = ?, year = ?, role = ?, certificate_id = ?, salutation = ? WHERE id = ?");
            $stmt->execute([
                $name,
                $email,
                $mobile,
                $branch,
                $year,
                $role,
                $memberCertId,
                $salutation,
                $mId
            ]);
            
            // Sync email logs if email or name changed
            $stmtSync = $pdo->prepare("UPDATE email_logs SET email = ?, recipient_name = ? WHERE participant_id = ?");
            $stmtSync->execute([$email, $name, $mId]);
            
            $submittedMemberIds[] = $mId;
        } else {
            // Insert new member (due to size increase)
            $stmt = $pdo->prepare("INSERT INTO team_members (team_id, name, email, mobile, branch, year, role, certificate_id, salutation) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $teamDbId,
                $name,
                $email,
                $mobile,
                $branch,
                $year,
                $role,
                $memberCertId,
                $salutation
            ]);
            $newMemberId = $pdo->lastInsertId();
            
            // Add queue records
            $stmtCert = $pdo->prepare("INSERT INTO certificates (participant_id, certificate_id, file_path, status) VALUES (?, ?, '', 'PENDING')");
            $stmtCert->execute([$newMemberId, $memberCertId]);
            
            $stmtMail = $pdo->prepare("INSERT INTO email_logs (participant_id, certificate_id, certificate_file, email, recipient_name, status, attempt_count) VALUES (?, ?, '', ?, ?, 'PENDING', 0)");
            $stmtMail->execute([$newMemberId, $memberCertId, $email, $name]);
            
            $submittedMemberIds[] = $newMemberId;
        }
    }
    
    // Find database members NOT in the submission list and delete them (due to size decrease)
    $stmt = $pdo->prepare("SELECT id FROM team_members WHERE team_id = ?");
    $stmt->execute([$teamDbId]);
    $dbMemberIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $membersToDelete = array_diff($dbMemberIds, $submittedMemberIds);
    if (!empty($membersToDelete)) {
        $deletePlaceholders = implode(',', array_fill(0, count($membersToDelete), '?'));
        $stmtDel = $pdo->prepare("DELETE FROM team_members WHERE id IN ($deletePlaceholders)");
        $stmtDel->execute(array_values($membersToDelete));
    }
    
    // Log activity
    $adminUser = $_SESSION['admin_username'] ?? 'admin';
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $stmtLog = $pdo->prepare("INSERT INTO activity_logs (admin_id, action, details, ip_address) VALUES (?, 'PARTICIPANT_UPDATED', ?, ?)");
    $stmtLog->execute([$_SESSION['admin_id'] ?? null, "Updated team details for $teamName ($teamCode).", $ip]);
    
    $pdo->commit();
    
    jsonResponse(true, 'Team details updated successfully.');
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    jsonResponse(false, 'Transaction failed: ' . $e->getMessage());
}
