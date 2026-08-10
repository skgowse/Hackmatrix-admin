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
        
        // 2. Overlay fields
        foreach ($fields as $field) {
            $fieldName = $field['field_name'];
            
            // Calculate absolute mm positions from percentages
            $x_mm = ($field['x_position'] / 100) * $pageWidth;
            $y_mm = ($field['y_position'] / 100) * $pageHeight;
            
            $fontName = $field['font_name'];
            $fontSize = intval($field['font_size']);
            $fontStyle = $field['font_style'];
            $colorHex = $field['text_color'];
            $alignment = $field['alignment']; // 'L', 'C', 'R'
            
            list($r, $g, $b) = sscanf($colorHex, "#%02x%02x%02x");
            
            if ($fieldName === 'participant_name') {
                $text = $participant['participant_name'];
                
                // 1. Draw "This certificate is proudly presented to" above the name
                $pdf->SetFont($fontName, '', 14);
                $pdf->SetTextColor(75, 85, 99); // Gray #4b5563
                $pdf->SetXY(0, $y_mm - 12);
                $pdf->Cell($pageWidth, 6, "This certificate is proudly presented to", 0, 0, 'C');
                
                // 2. Draw the candidate name
                $pdf->SetFont($fontName, $fontStyle, $fontSize);
                $pdf->SetTextColor($r, $g, $b);
                $cellHeight = $fontSize * 0.45;
                $pdf->SetXY(0, $y_mm - ($cellHeight / 2));
                $pdf->Cell($pageWidth, $cellHeight, $text, 0, 0, 'C');
                
            } elseif ($fieldName === 'branch') {
                $text = $participant['branch'];
                
                // 1. Draw "of" above the branch
                $pdf->SetFont($fontName, '', 14);
                $pdf->SetTextColor(75, 85, 99); // Gray #4b5563
                $pdf->SetXY(0, $y_mm - 9);
                $pdf->Cell($pageWidth, 5, "of", 0, 0, 'C');
                
                // 2. Draw the branch name
                $pdf->SetFont($fontName, $fontStyle, $fontSize);
                $pdf->SetTextColor($r, $g, $b);
                $cellHeight = $fontSize * 0.45;
                $pdf->SetXY(0, $y_mm - ($cellHeight / 2));
                $pdf->Cell($pageWidth, $cellHeight, $text, 0, 0, 'C');
                
                // 3. Draw description paragraph below the branch
                $pdf->SetFont($fontName, '', 12);
                $pdf->SetTextColor(75, 85, 99); // Gray #4b5563
                
                $descText = "for successfully participating in HackMatrix 1.0, a 2-Day Hackathon organized by the Department of Artificial Intelligence & Data Science, VIIT.";
                
                // Draw a wrapped paragraph centered on the page. We use MultiCell for word wrap.
                // Width = 200mm, left offset = ($pageWidth - 200)/2 = 48.5mm
                $pdf->SetXY(48.5, $y_mm + ($cellHeight / 2) + 6);
                $pdf->MultiCell(200, 6, $descText, 0, 'C', false);
                
            } elseif ($fieldName === 'certificate_id') {
                $text = "Certificate ID: " . $participant['certificate_id'];
                
                $pdf->SetFont($fontName, $fontStyle, $fontSize);
                $pdf->SetTextColor($r, $g, $b);
                $cellHeight = $fontSize * 0.45;
                $adjusted_y = $y_mm - ($cellHeight / 2);
                
                if ($alignment === 'C') {
                    $pdf->SetXY(0, $adjusted_y);
                    $pdf->Cell($pageWidth, $cellHeight, $text, 0, 0, 'C');
                } elseif ($alignment === 'R') {
                    $pdf->SetXY(0, $adjusted_y);
                    $pdf->Cell($x_mm, $cellHeight, $text, 0, 0, 'R');
                } else {
                    $pdf->SetXY($x_mm, $adjusted_y);
                    $pdf->Cell(0, $cellHeight, $text, 0, 0, 'L');
                }
            }
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
