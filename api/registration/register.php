<?php
/**
 * HackMatrix 1.0 - API: Register Team (Transaction-Based)
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

// Enforce POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Only POST requests are allowed.');
}

$pdo = getDBConnection();

// 1. Validate CSRF Token
$csrfToken = $_POST['csrf_token'] ?? '';
if (!validateCSRFToken($csrfToken)) {
    jsonResponse(false, 'Invalid security token (CSRF failure).');
}

// 2. Extract and Sanitize Fields
$teamName = trim($_POST['team_name'] ?? '');
$college = trim($_POST['college'] ?? '');
$teamSize = intval($_POST['team_size'] ?? 0);
$domain = trim($_POST['domain'] ?? '');
$projectTitle = '';
$members = $_POST['members'] ?? [];

// Predefined allowed values
$allowedDomains = ['AI & ML', 'Cloud Computing', 'Cybersecurity', 'Robotics'];
$allowedYears = ['1', '2', '3', '4'];
$allowedBranches = ['AI&DS', 'AI&ML', 'CIVIL', 'CSE', 'CSE-AI', 'CSE-CS', 'CSE-DS', 'ECE', 'ECM', 'EEE', 'IT', 'MECH'];

// 3. Validation Checks
if (empty($teamName) || empty($college) || empty($domain)) {
    jsonResponse(false, 'All team information fields are required.');
}

if (!in_array($teamSize, [2, 3, 4])) {
    jsonResponse(false, 'Team size must be 2, 3, or 4 members.');
}

if (!in_array($domain, $allowedDomains)) {
    jsonResponse(false, 'Selected domain is invalid.');
}

if (count($members) !== $teamSize) {
    jsonResponse(false, 'Submitted member forms do not match selected team size.');
}

// 4. Validate Each Member and Check Internal Duplicates
$emailsForm = [];
$mobilesForm = [];

foreach ($members as $index => $m) {
    $name = trim($m['name'] ?? '');
    $email = strtolower(trim($m['email'] ?? ''));
    $mobile = '';
    $branch = trim($m['branch'] ?? '');
    $year = '';

    if (empty($name) || empty($email) || empty($branch)) {
        jsonResponse(false, "All fields are required for Member $num ($roleName).");
    }
    
    $emailValid = validateEmailStrongly($email);
    if ($emailValid !== true) {
        jsonResponse(false, "Member $num ($roleName): " . $emailValid);
    }
    

    
    // Check internal duplicates in form submission
    if (in_array($email, $emailsForm)) {
        jsonResponse(false, "Duplicate email address '$email' found within this team submission.");
    }
    $emailsForm[] = $email;
    

}

// 5. Global Database Uniqueness Checks (Team Name, Email, Mobile)
try {
    // Check Team Name Duplication
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM teams WHERE LOWER(team_name) = ?");
    $stmt->execute([strtolower($teamName)]);
    if ($stmt->fetchColumn() > 0) {
        jsonResponse(false, "The team name '$teamName' is already registered.");
    }
    
    // Check Emails Duplication
    $emailPlaceholders = implode(',', array_fill(0, count($emailsForm), '?'));
    $stmt = $pdo->prepare("SELECT email FROM team_members WHERE LOWER(email) IN ($emailPlaceholders)");
    $stmt->execute($emailsForm);
    $dupEmails = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (!empty($dupEmails)) {
        jsonResponse(false, "The email '" . $dupEmails[0] . "' is already registered for HACKMATRIX 1.0.");
    }
    

} catch (Exception $e) {
    jsonResponse(false, 'Validation failed: ' . $e->getMessage());
}

// 6. DB Transaction Execution
try {
    $pdo->beginTransaction();
    
    // Get next Team Code ID
    $nextId = $pdo->query("SELECT IFNULL(MAX(id), 0) + 1 FROM teams")->fetchColumn();
    $teamCode = sprintf("HM26-%04d", $nextId);
    
    // Insert Team
    $stmt = $pdo->prepare("INSERT INTO teams (team_id, team_name, college, team_size, domain, project_title, status) VALUES (?, ?, ?, ?, ?, ?, 'ACTIVE')");
    $stmt->execute([
        $teamCode,
        $teamName,
        $college,
        $teamSize,
        $domain,
        $projectTitle
    ]);
    $teamDbId = $pdo->lastInsertId();
    
    // Insert Members
    foreach ($members as $index => $m) {
        $name = trim($m['name'] ?? '');
        $email = strtolower(trim($m['email'] ?? ''));
        $mobile = preg_replace('/[^0-9]/', '', $m['mobile'] ?? '');
        if (strlen($mobile) > 10) {
            $mobile = substr($mobile, -10);
        }
        $branch = trim($m['branch'] ?? '');
        $year = trim($m['year'] ?? '');
        $salutation = '';
        $role = ($index === 0) ? 'Team Lead' : 'Member';
        
        // Generate member certificate ID: e.g. HM26-0005-1, HM26-0005-2, etc.
        $memberCertId = $teamCode . "-" . ($index + 1);
        
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
        $memberDbId = $pdo->lastInsertId();
        
        // Setup certificates queue record
        $stmtCert = $pdo->prepare("INSERT INTO certificates (participant_id, certificate_id, file_path, status) VALUES (?, ?, '', 'PENDING')");
        $stmtCert->execute([$memberDbId, $memberCertId]);
        
        // Setup email queue logs record
        $stmtMail = $pdo->prepare("INSERT INTO email_logs (participant_id, certificate_id, email, status) VALUES (?, ?, ?, 'PENDING')");
        $stmtMail->execute([$memberDbId, $memberCertId, $email]);
    }
    
    // Write system activity audit log
    // Get IP
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $stmtLog = $pdo->prepare("INSERT INTO activity_logs (admin_id, action, details, ip_address) VALUES (NULL, 'PARTICIPANT_CREATED', ?, ?)");
    $stmtLog->execute(["Registered team: $teamName ($teamCode) with $teamSize members.", $ip]);
    
    $pdo->commit();
    
    // Success Response
    jsonResponse(true, 'Registration successful!', [
        'team_id' => $teamCode,
        'team_name' => $teamName,
        'team_size' => $teamSize,
        'created_at' => date('Y-m-d H:i:s')
    ]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    jsonResponse(false, 'Registration transaction failed: ' . $e->getMessage());
}
