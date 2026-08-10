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
        
        // Base vertical anchor (default to 53% if name field settings are missing)
        $base_y = 111.3;
        $nameFont = 'helvetica';
        $nameStyle = 'B';
        $nameSize = 28;
        $nameColor = '#1e3a8a';
        
        if ($nameField) {
            $base_y = ($nameField['y_position'] / 100) * $pageHeight;
            $nameFont = $nameField['font_name'];
            $nameStyle = $nameField['font_style'];
            $nameSize = intval($nameField['font_size']);
            $nameColor = $nameField['text_color'];
        }
        list($nr, $ng, $nb) = sscanf($nameColor, "#%02x%02x%02x");
        
        $branchFont = 'helvetica';
        $branchStyle = 'B';
        $branchSize = 16;
        $branchColor = '#1e3a8a';
        if ($branchField) {
            $branchFont = $branchField['font_name'];
            $branchStyle = $branchField['font_style'];
            $branchSize = intval($branchField['font_size']);
            $branchColor = $branchField['text_color'];
        }
        list($br, $bg, $bb) = sscanf($branchColor, "#%02x%02x%02x");
        
        $certColor = '#1e3a8a';
        if ($certField) {
            $certColor = $certField['text_color'];
        }
        list($cr, $cg, $cb) = sscanf($certColor, "#%02x%02x%02x");

        // Format name to professional Title Case if lowercase/uppercase
        $nameText = trim($participant['participant_name']);
        if (strtolower($nameText) === $nameText || strtoupper($nameText) === $nameText) {
            $nameText = ucwords(strtolower($nameText));
        }
        
        // Format branch to professional representation
        $branchText = trim($participant['branch']);
        if (strlen($branchText) <= 5) {
            $branchText = strtoupper($branchText);
        } elseif (strtolower($branchText) === $branchText || strtoupper($branchText) === $branchText) {
            $branchText = ucwords(strtolower($branchText));
        }

        // 1. Draw "This certificate is proudly presented to"
        $pdf->SetFont('helvetica', 'I', 12);
        $pdf->SetTextColor(75, 85, 99);
        $pdf->SetXY(0, $base_y - 14);
        $pdf->Cell($pageWidth, 6, "This certificate is proudly presented to", 0, 0, 'C');

        // 2. Draw Candidate Name
        $pdf->SetFont($nameFont, $nameStyle, $nameSize);
        $pdf->SetTextColor($nr, $ng, $nb);
        $cellHeight = $nameSize * 0.45;
        $pdf->SetXY(0, $base_y - ($cellHeight / 2));
        $pdf->Cell($pageWidth, $cellHeight, $nameText, 0, 0, 'C');

        // 3. Draw "of"
        $pdf->SetFont('helvetica', 'I', 12);
        $pdf->SetTextColor(75, 85, 99);
        $pdf->SetXY(0, $base_y + ($cellHeight / 2) + 2);
        $pdf->Cell($pageWidth, 5, "of", 0, 0, 'C');

        // 4. Draw Branch / Department
        $pdf->SetFont($branchFont, $branchStyle, $branchSize);
        $pdf->SetTextColor($br, $bg, $bb);
        $branchCellHeight = $branchSize * 0.45;
        $pdf->SetXY(0, $base_y + ($cellHeight / 2) + 8);
        $pdf->Cell($pageWidth, $branchCellHeight, $branchText, 0, 0, 'C');

        // 5. Draw Description Paragraph
        $pdf->SetFont('helvetica', '', 11.5);
        $pdf->SetTextColor(55, 65, 81);
        $descText = "for successfully participating in HackMatrix 1.0, a 2-Day Hackathon organized by the Department of Artificial Intelligence & Data Science, VIIT.";
        $desc_y = $base_y + ($cellHeight / 2) + 8 + $branchCellHeight + 5;
        $pdf->SetXY(($pageWidth - 210) / 2, $desc_y);
        $pdf->MultiCell(210, 5.5, $descText, 0, 'C', false);

        // 6. Draw Certificate ID
        $pdf->SetFont('helvetica', 'B', 10.5);
        $pdf->SetTextColor($cr, $cg, $cb);
        $pdf->SetXY(0, $desc_y + 16);
        $pdf->Cell($pageWidth, 5, "Certificate ID: " . $participant['certificate_id'], 0, 0, 'C');
        
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
