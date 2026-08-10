<?php
/**
 * HackMatrix 1.0 - Email Delivery Helper
 */

require_once __DIR__ . '/../config/mail.php';

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
            '{{team_name}}'        => $participant['team_name'],
            '{{team_no}}'          => $participant['team_no'],
            '{{certificate_id}}'   => $participant['certificate_id']
        ];
        
        return str_replace(array_keys($placeholders), array_values($placeholders), $text);
    }
    
    /**
     * Dispatches a certificate email to a participant.
     * 
     * @param array $participant Row from `participants`
     * @param string $pdfPath Absolute path to the PDF certificate
     * @param array|null $smtpSettings Override settings (optional)
     * @param array|null $emailTemplate Override template (optional)
     * @return bool|string True on success, or error message on failure
     */
    public static function sendCertificate($participant, $pdfPath, $smtpSettings = null, $emailTemplate = null) {
        try {
            if ($emailTemplate === null) {
                $pdo = getDBConnection();
                $emailTemplate = $pdo->query("SELECT * FROM email_templates LIMIT 1")->fetch();
                if (!$emailTemplate) {
                    return "Email template is not configured.";
                }
            }
            
            $mail = getMailerInstance($smtpSettings);
            
            // Recipient
            $mail->addAddress($participant['email'], $participant['participant_name']);
            
            // Content
            $mail->isHTML(false); // plaintext formatted template
            
            // Parse Subject and Body templates
            $subject = self::parseTemplate($emailTemplate['subject'], $participant);
            $body    = self::parseTemplate($emailTemplate['body'], $participant);
            
            $mail->Subject = $subject;
            $mail->Body    = $body;
            
            // Attach Certificate PDF
            if (!file_exists($pdfPath)) {
                return "Attachment file does not exist on disk: " . basename($pdfPath);
            }
            
            $mail->addAttachment($pdfPath, $participant['certificate_id'] . '.pdf');
            
            // Dispatch!
            $mail->send();
            return true;
        } catch (Exception $e) {
            return $e->getMessage() ?: "Unknown SMTP error occurred.";
        }
    }
}
