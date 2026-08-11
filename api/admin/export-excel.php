<?php
/**
 * HackMatrix 1.0 - Admin API: Export Filtered Participants to Excel (.xlsx)
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;

if (!isLoggedIn()) {
    die("Unauthorized access.");
}

$pdo = getDBConnection();

$search = trim($_GET['search'] ?? '');
$domain = trim($_GET['domain'] ?? '');
$teamSize = trim($_GET['team_size'] ?? '');
$status = trim($_GET['status'] ?? '');

try {
    // Write system activity audit log
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $stmtLog = $pdo->prepare("INSERT INTO activity_logs (admin_id, action, details, ip_address) VALUES (?, 'PARTICIPANT_EXPORT', ?, ?)");
    $stmtLog->execute([$_SESSION['admin_id'] ?? null, "Exported participant records to Excel (.xlsx) format.", $ip]);
    
    // Construct Query
    $queryStr = "SELECT t.team_id, t.team_name, t.college, t.team_size, t.domain, t.project_title, t.status, t.created_at,
                        tm.role, tm.name, tm.email, tm.mobile, tm.branch, tm.year
                 FROM team_members tm
                 JOIN teams t ON tm.team_id = t.id
                 WHERE 1=1";
    $params = [];
    
    if ($search !== '') {
        $queryStr .= " AND (t.team_id LIKE ? OR t.team_name LIKE ? OR t.college LIKE ? OR t.project_title LIKE ? 
                       OR tm.name LIKE ? OR tm.email LIKE ? OR tm.mobile LIKE ?)";
        $searchWild = '%' . $search . '%';
        $params = array_merge($params, [$searchWild, $searchWild, $searchWild, $searchWild, $searchWild, $searchWild, $searchWild]);
    }
    
    if ($domain !== '') {
        $queryStr .= " AND t.domain = ?";
        $params[] = $domain;
    }
    
    if ($teamSize !== '') {
        $queryStr .= " AND t.team_size = ?";
        $params[] = intval($teamSize);
    }
    
    if ($status !== '') {
        $queryStr .= " AND t.status = ?";
        $params[] = $status;
    }
    
    $queryStr .= " ORDER BY t.id ASC, tm.role DESC, tm.id ASC";
    $stmt = $pdo->prepare($queryStr);
    $stmt->execute($params);
    $records = $stmt->fetchAll();
    
    // Create new spreadsheet
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Participants');
    
    // Headers list
    $headers = [
        'Team ID', 
        'Team Name', 
        'College', 
        'Team Size', 
        'Domain', 
        'Project Title', 
        'Role', 
        'Participant Name', 
        'Email', 
        'Mobile', 
        'Branch', 
        'Year', 
        'Registration Date', 
        'Status'
    ];
    
    // Fill headers in Row 1
    $colLetter = 'A';
    foreach ($headers as $headerText) {
        $sheet->setCellValue($colLetter . '1', $headerText);
        $colLetter++;
    }
    
    // Fill data rows starting at Row 2
    $rowIdx = 2;
    foreach ($records as $row) {
        $sheet->setCellValue('A' . $rowIdx, $row['team_id']);
        $sheet->setCellValue('B' . $rowIdx, $row['team_name']);
        $sheet->setCellValue('C' . $rowIdx, $row['college']);
        $sheet->setCellValue('D' . $rowIdx, (int)$row['team_size']);
        $sheet->setCellValue('E' . $rowIdx, $row['domain']);
        $sheet->setCellValue('F' . $rowIdx, $row['project_title']);
        $sheet->setCellValue('G' . $rowIdx, $row['role']);
        $sheet->setCellValue('H' . $rowIdx, $row['name']);
        $sheet->setCellValue('I' . $rowIdx, $row['email']);
        $sheet->setCellValue('J' . $rowIdx, $row['mobile']);
        $sheet->setCellValue('K' . $rowIdx, $row['branch']);
        $sheet->setCellValue('L' . $rowIdx, $row['year']);
        $sheet->setCellValue('M' . $rowIdx, $row['created_at']);
        $sheet->setCellValue('N' . $rowIdx, $row['status']);
        $rowIdx++;
    }
    
    // Styling the spreadsheet beautifully
    $lastCol = 'N';
    $headerRange = 'A1:' . $lastCol . '1';
    
    // Styling headers
    $sheet->getStyle($headerRange)->getFont()->setBold(true)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color(\PhpOffice\PhpSpreadsheet\Style\Color::COLOR_WHITE));
    $sheet->getStyle($headerRange)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('4F46E5'); // Indigo Accent Color
    
    // Add gridlines and border borders
    $styleArray = [
        'borders' => [
            'allBorders' => [
                'borderStyle' => Border::BORDER_THIN,
                'color' => ['argb' => 'DDDDDD'],
            ],
        ],
    ];
    $sheet->getStyle('A1:' . $lastCol . ($rowIdx - 1))->applyFromArray($styleArray);
    
    // Auto-adjust columns widths
    $lastColIdx = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($lastCol);
    for ($col = 1; $col <= $lastColIdx; $col++) {
        $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
        $sheet->getColumnDimension($colLetter)->setAutoSize(true);
    }
    
    // Redirect output to browser
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="participants_export_' . date('Y-m-d') . '.xlsx"');
    header('Cache-Control: max-age=0');
    
    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    exit();
} catch (Exception $e) {
    die("Excel Export failed: " . $e->getMessage());
}
