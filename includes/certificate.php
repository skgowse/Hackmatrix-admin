<?php
/**
 * HackMatrix 1.0 - PDF Certificate Generator Helper (TCPDF + FPDI)
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/database.php';

use setasign\Fpdi\TcpdfFpdi;

class CertificateGenerator {
    
    /**
     * Generates a PDF certificate for a participant.
     * 
     * @param array $participant Row from `participants` table
     * @param array $template Row from `certificate_templates` table
     * @param array $fields Array of fields configuration from `certificate_fields`
     * @param string|null $outputPath Absolute file path to save the generated PDF. If null, outputs to browser inline.
     * @return bool|string True on success, or path, or false/throws on error.
     */
    public static function generate($participant, $template, $fields, $outputPath = null) {
        $pdf = new TcpdfFpdi();
        
        // Remove standard headers and footers
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(0, 0, 0);
        $pdf->SetAutoPageBreak(false);
        
        $pageWidth = floatval($template['page_width']);
        $pageHeight = floatval($template['page_height']);
        $orientation = $template['orientation']; // 'L' or 'P'
        
        $templatePath = __DIR__ . '/../' . $template['file_path'];
        
        if (!file_exists($templatePath)) {
            throw new Exception("Template file does not exist: " . $template['file_path']);
        }
        
        $fileExt = strtolower(pathinfo($templatePath, PATHINFO_EXTENSION));
        
        // 1. Setup template background
        if ($fileExt === 'pdf') {
            try {
                $pageCount = $pdf->setSourceFile($templatePath);
                $tplId = $pdf->importPage(1);
                $pdf->AddPage($orientation, [$pageWidth, $pageHeight]);
                $pdf->useTemplate($tplId, 0, 0, $pageWidth, $pageHeight, true);
            } catch (Exception $e) {
                throw new Exception("FPDI failed to parse PDF template: " . $e->getMessage());
            }
        } else {
            // Image template (PNG, JPG, JPEG)
            $pdf->AddPage($orientation, [$pageWidth, $pageHeight]);
            // Draw background image spanning full page size
            $pdf->Image($templatePath, 0, 0, $pageWidth, $pageHeight, '', '', '', false, 300, '', false, false, 0);
        }
        
        // 2. Setup layout variables by reading fields configuration
        $nameField = null;
        $branchField = null;
        $certField = null;
        
        foreach ($fields as $field) {
            if ($field['field_name'] === 'participant_name') {
                $nameField = $field;
            } elseif ($field['field_name'] === 'branch') {
                $branchField = $field;
            } elseif ($field['field_name'] === 'certificate_id') {
                $certField = $field;
            }
        }
        
        // Coordinates in mm
        $name_x = 148.5; // default center
        $name_y = 111.3; // default center Y (53%)
        $nameFont = 'helvetica';
        $nameStyle = 'B';
        $nameSize = 28;
        $nameColor = '#1e3a8a';
        $nameAlign = 'C';
        
        if ($nameField) {
            $name_x = ($nameField['x_position'] / 100) * $pageWidth;
            $name_y = ($nameField['y_position'] / 100) * $pageHeight;
            $nameFont = $nameField['font_name'];
            $nameStyle = $nameField['font_style'];
            $nameSize = intval($nameField['font_size']);
            $nameColor = $nameField['text_color'];
            $nameAlign = $nameField['alignment'] ?: 'C';
        }
        list($nr, $ng, $nb) = sscanf($nameColor, "#%02x%02x%02x");
        
        $branch_x = 148.5;
        $branch_y = 134.4; // default center Y (64%)
        $branchFont = 'helvetica';
        $branchStyle = 'B';
        $branchSize = 16;
        $branchColor = '#1e3a8a';
        $branchAlign = 'C';
        
        if ($branchField) {
            $branch_x = ($branchField['x_position'] / 100) * $pageWidth;
            $branch_y = ($branchField['y_position'] / 100) * $pageHeight;
            $branchFont = $branchField['font_name'];
            $branchStyle = $branchField['font_style'];
            $branchSize = intval($branchField['font_size']);
            $branchColor = $branchField['text_color'];
            $branchAlign = $branchField['alignment'] ?: 'C';
        }
        list($br, $bg, $bb) = sscanf($branchColor, "#%02x%02x%02x");
        
        $cert_x = 253.9; // default 85.5%
        $cert_y = 202.6; // default 96.5%
        $certFont = 'helvetica';
        $certStyle = 'B';
        $certSize = 11;
        $certColor = '#1e3a8a';
        $certAlign = 'C';
        
        if ($certField) {
            $cert_x = ($certField['x_position'] / 100) * $pageWidth;
            $cert_y = ($certField['y_position'] / 100) * $pageHeight;
            $certFont = $certField['font_name'];
            $certStyle = $certField['font_style'];
            $certSize = intval($certField['font_size']);
            $certColor = $certField['text_color'];
            $certAlign = $certField['alignment'];
        }
        list($cr, $cg, $cb) = sscanf($certColor, "#%02x%02x%02x");

        // Format name to professional Title Case if lowercase/uppercase
        $nameText = trim($participant['participant_name']);
        if (strtolower($nameText) === $nameText || strtoupper($nameText) === $nameText) {
            $nameText = ucwords(strtolower($nameText));
        }
        
        $salutation = trim($participant['salutation'] ?? '');
        if ($salutation !== '') {
            $nameText = $salutation . ' ' . $nameText;
        }
        if ($nameText !== '') {
            $nameText .= ',';
        }
        
        // Format branch to full name representation
        $branchAbbr = trim($participant['branch']);
        $branchMap = [
            'AI&DS'   => 'Artificial Intelligence & Data Science',
            'AI&ML'   => 'Artificial Intelligence & Machine Learning',
            'CIVIL'   => 'Civil Engineering',
            'CSE'     => 'Computer Science & Engineering',
            'CSE-AI'  => 'Computer Science & Engineering (Artificial Intelligence)',
            'CSE-CS'  => 'Computer Science & Engineering (Cyber Security)',
            'CSE-DS'  => 'Computer Science & Engineering (Data Science)',
            'ECE'     => 'Electronics & Communication Engineering',
            'ECM'     => 'Electronics & Computer Engineering',
            'EEE'     => 'Electrical & Electronics Engineering',
            'IT'      => 'Information Technology',
            'MECH'    => 'Mechanical Engineering'
        ];
        
        $branchText = isset($branchMap[$branchAbbr]) ? $branchMap[$branchAbbr] : $branchAbbr;

        // 1. Draw Candidate Name
        $pdf->SetFont($nameFont, $nameStyle, $nameSize);
        $pdf->SetTextColor($nr, $ng, $nb);
        $cellHeight = $nameSize * 0.45;
        $nameWidth = $pdf->GetStringWidth($nameText);
        if ($nameAlign === 'C') {
            $pdf->SetXY($name_x - ($nameWidth / 2), $name_y - ($cellHeight / 2));
            $pdf->Cell($nameWidth, $cellHeight, $nameText, 0, 0, 'C');
        } elseif ($nameAlign === 'R') {
            $pdf->SetXY($name_x - $nameWidth, $name_y - ($cellHeight / 2));
            $pdf->Cell($nameWidth, $cellHeight, $nameText, 0, 0, 'R');
        } else {
            $pdf->SetXY($name_x, $name_y - ($cellHeight / 2));
            $pdf->Cell($nameWidth, $cellHeight, $nameText, 0, 0, 'L');
        }

        // 2. Draw Branch / Department
        $charCount = strlen($branchText);
        if ($charCount > 35) {
            $branchSize = $branchSize * 0.60;
        } elseif ($charCount > 20) {
            $branchSize = $branchSize * 0.75;
        }
        
        $pdf->SetFont($branchFont, $branchStyle, $branchSize);
        $pdf->SetTextColor($br, $bg, $bb);
        $branchCellHeight = $branchSize * 0.45;
        $branchWidth = $pdf->GetStringWidth($branchText);
        if ($branchAlign === 'C') {
            $pdf->SetXY($branch_x - ($branchWidth / 2), $branch_y - ($branchCellHeight / 2));
            $pdf->Cell($branchWidth, $branchCellHeight, $branchText, 0, 0, 'C');
        } elseif ($branchAlign === 'R') {
            $pdf->SetXY($branch_x - $branchWidth, $branch_y - ($branchCellHeight / 2));
            $pdf->Cell($branchWidth, $branchCellHeight, $branchText, 0, 0, 'R');
        } else {
            $pdf->SetXY($branch_x, $branch_y - ($branchCellHeight / 2));
            $pdf->Cell($branchWidth, $branchCellHeight, $branchText, 0, 0, 'L');
        }


        
        // 3. Output PDF file
        if ($outputPath !== null) {
            // Ensure output directory exists
            $dir = dirname($outputPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            
            // Save to physical file on disk
            $pdf->Output($outputPath, 'F');
            return true;
        } else {
            // Output inline to browser
            $pdf->Output($participant['certificate_id'] . '.pdf', 'I');
            exit();
        }
    }
}
