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
                'textContent' => $body,
                'attachment' => [
                    [
                        'name' => $participant['certificate_id'] . '.pdf',
                        'content' => $pdfBase64
                    ]
                ]
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
