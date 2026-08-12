<?php
/**
 * HackMatrix 1.0 - Email Delivery Helper (Brevo API Edition)
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

class MailerHelper {
    
    /**
     * Parses variables in templates
     * 
     * @param string $text
     * @param array $participant
     * @return string
     */
    public static function parseTemplate($text, $participant) {
        $placeholders = [
            '{{participant_name}}' => $participant['participant_name'],
            '{{branch}}'           => $participant['branch'],
            '{{team_name}}'        => $participant['team_name'] ?? '',
            '{{team_no}}'          => $participant['team_no'] ?? '',
            '{{certificate_id}}'   => $participant['certificate_id']
        ];
        
        return str_replace(array_keys($placeholders), array_values($placeholders), $text);
    }
    
    /**
     * Dispatches a certificate email to a participant via Brevo API.
     * 
     * @param array $participant Row from `participants`
     * @param string $pdfPath Absolute path to the PDF certificate
     * @param array|null $smtpSettings Override settings (optional)
     * @param array|null $emailTemplate Override template (optional)
     * @return bool|string True on success, or error message on failure
     */
    public static function sendCertificate($participant, $pdfPath, $smtpSettings = null, $emailTemplate = null) {
        try {
            $pdo = getDBConnection();
            
            if ($emailTemplate === null) {
                $emailTemplate = $pdo->query("SELECT * FROM email_templates LIMIT 1")->fetch();
                if (!$emailTemplate) {
                    return "Email template is not configured.";
                }
            }
            
            if ($smtpSettings === null) {
                $smtpSettings = $pdo->query("SELECT * FROM smtp_settings LIMIT 1")->fetch();
            }
            
            if (!$smtpSettings) {
                return "Brevo API settings are not configured.";
            }
            
            // The API key is stored in the `smtp_password` column
            $apiKey = decryptSMTPPassword($smtpSettings['smtp_password']);
            if (empty($apiKey)) {
                return "Brevo API Key is missing. Please configure it in Email Settings.";
            }
            
            $fromEmail = $smtpSettings['from_email'];
            $fromName = $smtpSettings['from_name'];
            
            // Parse Subject and Body templates
            $subject = self::parseTemplate($emailTemplate['subject'], $participant);
            $body    = self::parseTemplate($emailTemplate['body'], $participant);
            
            // Prepare PDF attachment
            if (!file_exists($pdfPath)) {
                return "Attachment file does not exist on disk: " . basename($pdfPath);
            }
            $pdfContent = file_get_contents($pdfPath);
            $pdfBase64 = base64_encode($pdfContent);
            
            // Base64 encode the email header image
            $headerImgPath = __DIR__ . '/../uploads/templates/email_header.png';
            $headerBase64 = '';
            if (file_exists($headerImgPath)) {
                $headerBase64 = base64_encode(file_get_contents($headerImgPath));
            }
            
            $attachments = [];
            // Add header image as inline attachment
            if ($headerBase64 !== '') {
                $attachments[] = [
                    'name' => 'email_header.png',
                    'content' => $headerBase64
                ];
            }
            // Add certificate PDF
            $attachments[] = [
                'name' => $participant['certificate_id'] . '.pdf',
                'content' => $pdfBase64
            ];
            
            // Parse plain text to HTML with breaks
            $htmlBody = nl2br(htmlspecialchars($body));
            
            // Build styled HTML email
            $htmlContent = '
            <!DOCTYPE html>
            <html>
            <head>
                <meta charset="utf-8">
                <style>
                    body {
                        font-family: "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
                        color: #1f2937;
                        background-color: #f3f4f6;
                        margin: 0;
                        padding: 0;
                    }
                    .email-container {
                        max-width: 600px;
                        margin: 30px auto;
                        background-color: #ffffff;
                        border-radius: 12px;
                        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
                        overflow: hidden;
                        border: 1px solid #e5e7eb;
                    }
                    .header-banner {
                        width: 100%;
                        max-width: 100%;
                        height: auto;
                        display: block;
                    }
                    .content-body {
                        padding: 30px 40px;
                        line-height: 1.7;
                        font-size: 15px;
                    }
                    .footer {
                        background-color: #f9fafb;
                        padding: 20px 40px;
                        text-align: center;
                        font-size: 12px;
                        color: #6b7280;
                        border-top: 1px solid #e5e7eb;
                    }
                </style>
            </head>
            <body>
                <div class="email-container">
            ';
            
            if ($headerBase64 !== '') {
                $htmlContent .= '<img src="cid:email_header.png" alt="HackMatrix 1.0" class="header-banner">';
            }
            
            $htmlContent .= '
                    <div class="content-body">
                        ' . $htmlBody . '
                    </div>
                    <div class="footer">
                        Department of Artificial Intelligence & Data Science, VIIT &copy; 2026. All rights reserved.
                    </div>
                </div>
            </body>
            </html>
            ';
            
            // Build Brevo API payload
            $payload = [
                'sender' => [
                    'name' => $fromName,
                    'email' => $fromEmail
                ],
                'to' => [
                    [
                        'email' => $participant['email'],
                        'name' => $participant['participant_name']
                    ]
                ],
                'subject' => $subject,
                'htmlContent' => $htmlContent,
                'textContent' => $body,
                'attachment' => $attachments
            ];
            
            // Execute HTTP Request to Brevo API
            $ch = curl_init('https://api.brevo.com/v3/smtp/email');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'accept: application/json',
                'api-key: ' . $apiKey,
                'content-type: application/json'
            ]);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);
            
            if ($curlError) {
                return "Brevo API Connection Error: " . $curlError;
            }
            
            $resDecoded = json_decode($response, true);
            if ($httpCode >= 200 && $httpCode < 300) {
                return true;
            } else {
                $errorMsg = $resDecoded['message'] ?? $resDecoded['code'] ?? 'Unknown Brevo API error.';
                return "Brevo API Error ($httpCode): " . $errorMsg;
            }
        } catch (Exception $e) {
            return $e->getMessage() ?: "Unknown error occurred.";
        }
    }
}
