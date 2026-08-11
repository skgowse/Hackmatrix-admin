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
            if (defined('SMTP_VERBOSE_DEBUG') && SMTP_VERBOSE_DEBUG) {
                $mail->SMTPDebug = 2;
            }
            
            // Recipient
            $mail->addAddress($participant['email'], $participant['participant_name']);
            
            // Parse Subject and Body templates
            $subject = self::parseTemplate($emailTemplate['subject'], $participant);
            $body    = self::parseTemplate($emailTemplate['body'], $participant);
            
            $mail->Subject = $subject;
            
            // Embed College Header Banner
            $headerPath = __DIR__ . '/../assets/images/email_header.png';
            $hasHeader = false;
            if (file_exists($headerPath)) {
                $mail->addEmbeddedImage($headerPath, 'email_header');
                $hasHeader = true;
            }
            
            // Setup HTML Body
            $mail->isHTML(true);
            
            $htmlMessage = nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8'));
            
            $headerHtml = '';
            if ($hasHeader) {
                $headerHtml = '<tr>
                    <td align="center" style="background-color: #ffffff;">
                        <img src="cid:email_header" alt="College Header" style="width: 100%; max-width: 600px; height: auto; display: block; border-bottom: 2px solid #3b82f6;">
                    </td>
                </tr>';
            }
            
            $mail->Body = '
            <!DOCTYPE html>
            <html>
            <head>
                <meta charset="utf-8">
                <title>' . htmlspecialchars($subject, ENT_QUOTES, 'UTF-8') . '</title>
            </head>
            <body style="margin: 0; padding: 0; background-color: #f3f4f6; font-family: \'Segoe UI\', Helvetica, Arial, sans-serif;">
                <table width="100%" border="0" cellspacing="0" cellpadding="0" style="background-color: #f3f4f6; padding: 30px 15px;">
                    <tr>
                        <td align="center">
                            <table width="100%" max-width="600" border="0" cellspacing="0" cellpadding="0" style="max-width: 600px; background-color: #ffffff; border-radius: 16px; overflow: hidden; box-shadow: 0 10px 25px rgba(0,0,0,0.05); border: 1px solid #e5e7eb;">
                                ' . $headerHtml . '
                                <tr>
                                    <td style="padding: 40px 30px; color: #374151; font-size: 15px; line-height: 1.7; text-align: left;">
                                        ' . $htmlMessage . '
                                    </td>
                                </tr>
                                <tr>
                                    <td align="center" style="padding: 20px 30px; background-color: #f9fafb; border-top: 1px solid #f3f4f6; color: #9ca3af; font-size: 12px; font-family: sans-serif;">
                                        &copy; ' . date('Y') . ' HackMatrix. All rights reserved.
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>
            </body>
            </html>';
            
            // Plaintext fallback
            $mail->AltBody = $body;
            
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
