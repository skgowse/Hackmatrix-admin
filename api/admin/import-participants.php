<?php
/**
 * HackMatrix 1.0 - Admin API: Import Teams from CSV/Excel
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

if (!isLoggedIn()) {
    jsonResponse(false, 'Unauthorized access.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Only POST requests are allowed.');
}

// CSRF check
$csrfToken = $_POST['csrf_token'] ?? '';
if (!validateCSRFToken($csrfToken)) {
    jsonResponse(false, 'CSRF verification failed.');
}

if (!isset($_FILES['import_file']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
    jsonResponse(false, 'Please select a valid CSV or Excel file to upload.');
}

$fileTmpPath = $_FILES['import_file']['tmp_name'];
$fileName = $_FILES['import_file']['name'];
$fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

$allowedExtensions = ['csv', 'xlsx', 'xls'];
if (!in_array($fileExtension, $allowedExtensions)) {
    jsonResponse(false, 'Only CSV, XLSX, and XLS file formats are supported.');
}

$pdo = getDBConnection();

$rowsData = [];

// 1. Parse File Content based on extension
try {
    if ($fileExtension === 'csv') {
        // Read CSV
        if (($handle = fopen($fileTmpPath, 'r')) !== false) {
            // Read headers
            $headers = fgetcsv($handle, 1000, ',');
            if ($headers) {
                $headers = array_map(function($h) { return strtolower(trim($h)); }, $headers);
                while (($data = fgetcsv($handle, 1000, ',')) !== false) {
                    if (count($data) >= count($headers)) {
                        $rowData = array_combine(array_slice($headers, 0, count($data)), array_slice($data, 0, count($headers)));
                        $rowsData[] = $rowData;
                    }
                }
            }
            fclose($handle);
        }
    } else {
        // Read Excel using PhpSpreadsheet
        $spreadsheet = IOFactory::load($fileTmpPath);
        $worksheet = $spreadsheet->getActiveSheet();
        $rows = $worksheet->toArray();
        if (count($rows) > 0) {
            $rawHeaders = $rows[0];
            $headers = [];
            foreach ($rawHeaders as $index => $h) {
                $h = trim($h ?? '');
                if ($h === '') {
                    if ($index === 0) $h = 'team_name';
                    elseif ($index === 1) $h = 'member_1_name';
                    elseif ($index === 2) $h = 'member_1_email';
                }
                $headers[$index] = strtolower($h);
            }
            for ($i = 1; $i < count($rows); $i++) {
                $data = $rows[$i];
                // Skip completely empty rows
                if (count(array_filter($data)) === 0) continue;
                
                $rowData = [];
                foreach ($headers as $index => $header) {
                    $rowData[$header] = trim($data[$index] ?? '');
                }
                $rowsData[] = $rowData;
            }
        }
    }
} catch (Exception $e) {
    jsonResponse(false, 'Failed parsing file: ' . $e->getMessage());
}

if (empty($rowsData)) {
    jsonResponse(false, 'The uploaded file does not contain any valid data rows.');
}

// 2. Map file columns to expected keys
// Helper to look up key by name (case-insensitive, ignores spaces/underscores)
function getVal($row, $keys) {
    foreach ($keys as $key) {
        $normalizedKey = strtolower(str_replace([' ', '_', '-'], '', $key));
        foreach ($row as $rKey => $rVal) {
            $normalizedRKey = strtolower(str_replace([' ', '_', '-'], '', $rKey));
            if ($normalizedRKey === $normalizedKey) {
                return trim($rVal);
            }
        }
    }
    return '';
}

// Validate lists
$allowedDomains = ['AI & ML', 'Cloud Computing', 'Cybersecurity', 'Robotics'];
$allowedBranches = ['AI&DS', 'AI&ML', 'CIVIL', 'CSE', 'CSE-AI', 'CSE-CS', 'CSE-DS', 'ECE', 'ECM', 'EEE', 'IT', 'MECH'];
$allowedSalutations = ['Mr.', 'Miss.', 'Mrs.', 'Ms.'];
$allowedColleges = ['VIIT', 'VIEW'];

$successCount = 0;
$errors = [];

foreach ($rowsData as $index => $row) {
    $rowNum = $index + 2; // Row number in spreadsheet (1-indexed + header row)
    
    $teamName = getVal($row, ['Team Name', 'TeamName', 'team_name']);
    
    $college = strtoupper(getVal($row, ['College', 'college', 'institution', 'College/Institution']));
    if (empty($college)) {
        $college = 'VIIT'; // Default to VIIT
    }
    
    $domain = getVal($row, ['Domain', 'domain', 'Hackathon Domain', 'hackathon_domain', 'Theme', 'theme', 'Hackathon Theme', 'hackathon_theme']);
    if (empty($domain)) {
        $domain = 'AI & ML'; // Default to AI & ML
    }
    
    if (empty($teamName)) {
        $errors[] = "Row $rowNum: Team Name is missing.";
        continue;
    }
    
    if (!in_array($college, $allowedColleges)) {
        $errors[] = "Row $rowNum (Team '$teamName'): College must be 'VIIT' or 'VIEW'. Found: '$college'.";
        continue;
    }
    
    // Normalize Domain
    $matchedDomain = '';
    foreach ($allowedDomains as $d) {
        if (strcasecmp($domain, $d) === 0) {
            $matchedDomain = $d;
            break;
        }
    }
    if (empty($matchedDomain)) {
        $errors[] = "Row $rowNum (Team '$teamName'): Invalid Domain '$domain'. Must be one of: " . implode(', ', $allowedDomains);
        continue;
    }
    
    // Read members
    $members = [];
    for ($mNum = 1; $mNum <= 4; $mNum++) {
        if ($mNum === 1) {
            $name = getVal($row, ["Member 1 Name", "member_1_name", "Member1Name", "Team Lead Name", "teamleadname"]);
            $salutation = '';
            $email = strtolower(getVal($row, ["Member 1 Email", "member_1_email", "Member1Email", "Team Lead Email", "teamleademail", "Team Lead Mail Id", "teamleadmailid"]));
            $mobile = '';
            $branch = strtoupper(getVal($row, ["Member 1 Branch", "member_1_branch", "Member1Branch", "Team Lead Branch", "teamleadbranch"]));
            $year = '';
        } else {
            $name = getVal($row, ["Member {$mNum} Name", "member_{$mNum}_name", "Member{$mNum}Name", "Team Member {$mNum} Name", "teammember{$mNum}name", "Team member {$mNum} name"]);
            $salutation = '';
            $email = strtolower(getVal($row, ["Member {$mNum} Email", "member_{$mNum}_email", "Member{$mNum}Email", "Team Member {$mNum} Email", "teammember{$mNum}email", "Team Member {$mNum} Mail Id", "teammember{$mNum}mailid", "Team Member {$mNum} MailId"]));
            $mobile = '';
            $branch = strtoupper(getVal($row, ["Member {$mNum} Branch", "member_{$mNum}_branch", "Member{$mNum}Branch", "Team Member {$mNum} Branch", "teammember{$mNum}branch"]));
            $year = '';
        }
        
        // Correct branch naming typo
        if ($branch === 'AIDS') $branch = 'AI&DS';
        if ($branch === 'AIML') $branch = 'AI&ML';
        
        if (!empty($name) || !empty($email) || !empty($branch)) {
            $members[] = [
                'name' => $name,
                'salutation' => $salutation,
                'email' => $email,
                'mobile' => $mobile,
                'branch' => $branch,
                'year' => $year
            ];
        }
    }
    
    $teamSize = count($members);
    if ($teamSize < 2 || $teamSize > 4) {
        $errors[] = "Row $rowNum (Team '$teamName'): A team must have 2 to 4 members. Found: $teamSize.";
        continue;
    }
    
    // Check database uniqueness for Team Name
    $stmtCheckTeam = $pdo->prepare("SELECT COUNT(*) FROM teams WHERE team_name = ?");
    $stmtCheckTeam->execute([$teamName]);
    if ($stmtCheckTeam->fetchColumn() > 0) {
        $errors[] = "Row $rowNum (Team '$teamName'): Team name already exists in database.";
        continue;
    }
    
    // Validate members data
    $memberValidationError = false;
    foreach ($members as $mIdx => $m) {
        $mNum = $mIdx + 1;
        $roleName = ($mIdx === 0) ? 'Team Lead' : "Member $mNum";
        
        if (empty($m['name'])) {
            $errors[] = "Row $rowNum (Team '$teamName'): Name is missing for $roleName.";
            $memberValidationError = true;
            break;
        }
        
        
        
        // Email validation
        $emailRes = validateEmailStrongly($m['email']);
        if ($emailRes !== true) {
            $errors[] = "Row $rowNum (Team '$teamName'): Email for $roleName is invalid. $emailRes";
            $memberValidationError = true;
            break;
        }
        
        // Email database uniqueness
        $stmtCheckEmail = $pdo->prepare("SELECT COUNT(*) FROM team_members WHERE email = ?");
        $stmtCheckEmail->execute([$m['email']]);
        if ($stmtCheckEmail->fetchColumn() > 0) {
            $errors[] = "Row $rowNum (Team '$teamName'): Email '{$m['email']}' for $roleName already exists in database.";
            $memberValidationError = true;
            break;
        }
        
        
        
        // Branch validation
        $matchedBranch = '';
        foreach ($allowedBranches as $b) {
            if (strcasecmp($m['branch'], $b) === 0 || str_replace('&', '', strtolower($m['branch'])) === str_replace('&', '', strtolower($b))) {
                $matchedBranch = $b;
                break;
            }
        }
        if (empty($matchedBranch)) {
            $errors[] = "Row $rowNum (Team '$teamName'): Invalid branch '{$m['branch']}' for $roleName. Allowed: " . implode(', ', $allowedBranches);
            $memberValidationError = true;
            break;
        }
        $members[$mIdx]['branch'] = $matchedBranch;
        
        $members[$mIdx]['year'] = '';
    }
    
    if ($memberValidationError) {
        continue;
    }
    
    // 3. Insert into Database
    try {
        $pdo->beginTransaction();
        
        // Get next Team Sequence
        $stmtSeq = $pdo->query("SELECT MAX(CAST(SUBSTRING(team_id, 6) AS UNSIGNED)) FROM teams");
        $maxSeq = $stmtSeq->fetchColumn();
        $newSeq = $maxSeq ? intval($maxSeq) + 1 : 1;
        $teamCode = "HM26-" . str_pad($newSeq, 4, '0', STR_PAD_LEFT);
        
        // Insert team
        $stmtTeam = $pdo->prepare("INSERT INTO teams (team_id, team_name, college, team_size, domain, project_title, status) VALUES (?, ?, ?, ?, ?, '', 'ACTIVE')");
        $stmtTeam->execute([
            $teamCode,
            $teamName,
            $college,
            $teamSize,
            $matchedDomain
        ]);
        $teamDbId = $pdo->lastInsertId();
        
        // Insert members
        foreach ($members as $mIdx => $m) {
            $role = ($mIdx === 0) ? 'Team Lead' : 'Member';
            $memberCertId = $teamCode . "-" . ($mIdx + 1);
            
            $stmtM = $pdo->prepare("INSERT INTO team_members (team_id, name, email, mobile, branch, year, role, certificate_id, salutation) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmtM->execute([
                $teamDbId,
                $m['name'],
                $m['email'],
                $m['mobile'],
                $m['branch'],
                $m['year'],
                $role,
                $memberCertId,
                $m['salutation']
            ]);
            $memberDbId = $pdo->lastInsertId();
            
            // Queue certificate
            $stmtCert = $pdo->prepare("INSERT INTO certificates (participant_id, certificate_id, file_path, status) VALUES (?, ?, '', 'PENDING')");
            $stmtCert->execute([$memberDbId, $memberCertId]);
            
            // Queue email logs
            $stmtMail = $pdo->prepare("INSERT INTO email_logs (participant_id, certificate_id, certificate_file, email, recipient_name, status, attempt_count) VALUES (?, ?, '', ?, ?, 'PENDING', 0)");
            $stmtMail->execute([$memberDbId, $memberCertId, $m['email'], $m['name']]);
        }
        
        // Audit log
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $stmtLog = $pdo->prepare("INSERT INTO activity_logs (admin_id, action, details, ip_address) VALUES (?, 'PARTICIPANT_CREATED', ?, ?)");
        $stmtLog->execute([$_SESSION['admin_id'], "Imported team: $teamName ($teamCode) with $teamSize members.", $ip]);
        
        $pdo->commit();
        $successCount++;
        
    } catch (Exception $ex) {
        $pdo->rollBack();
        $errors[] = "Row $rowNum (Team '$teamName'): Database error during insertion: " . $ex->getMessage();
    }
}

jsonResponse(true, "Import complete.", [
    'success_count' => $successCount,
    'total_rows' => count($rowsData),
    'errors' => $errors
]);
