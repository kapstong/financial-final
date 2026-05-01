<?php
/**
 * Simple Email Mailer Class
 * Handles sending emails with proper formatting and error handling
 */

class Mailer {
    private static $instance = null;
    private $fromEmail;
    private $fromName;
    private $usePHPMailer = false;
    private $useSymfonyMailer = false;
    private $smtpEnabled = false;
    private $smtpHost;
    private $smtpPort;
    private $smtpUser;
    private $smtpPass;
    private $smtpEncryption;
    private $lastError = '';

    public function __construct() {
        // Attempt to load Composer autoload if present so PHPMailer can be used
        $autoloadPath = __DIR__ . '/../vendor/autoload.php';
        if (file_exists($autoloadPath)) {
            require_once $autoloadPath;
        }

        $this->useSymfonyMailer = class_exists('Symfony\\Component\\Mailer\\Mailer') && class_exists('Symfony\\Component\\Mime\\Email');
        $this->usePHPMailer = class_exists('PHPMailer\\PHPMailer\\PHPMailer');

        $configuredFromEmail = Config::get('mail.from_address') ?: 'noreply@atiera.com';
        $this->fromName = Config::get('mail.from_name') ?: 'ATIERA Finance';

        $this->smtpHost = trim((string) Config::get('mail.host'));
        $this->smtpPort = (int) Config::get('mail.port', 587);
        $this->smtpUser = trim((string) Config::get('mail.username'));
        $rawSmtpPass = (string) Config::get('mail.password');
        // Gmail app passwords are often copied with spaces every 4 chars.
        $this->smtpPass = preg_replace('/\s+/', '', $rawSmtpPass);
        $this->smtpEncryption = trim((string) Config::get('mail.encryption', 'tls'));

        $mailer = Config::get('mail.mailer', 'smtp');
        $this->smtpEnabled = ($mailer === 'smtp')
            && !empty($this->smtpHost)
            && !empty($this->smtpUser)
            && !empty($this->smtpPass);

        $this->fromEmail = $this->resolveFromEmail($configuredFromEmail);
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Send email
     *
     * @param string $to Recipient email address
     * @param string $subject Email subject
     * @param string $message Email body (plain text)
     * @param array $options Optional settings (html, headers, etc.)
     * @return bool Success status
     */
    public function send($to, $subject, $message, $options = []) {
        $this->lastError = '';

        try {
            if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
                $this->lastError = "Invalid recipient email address";
                error_log("Mailer Error: Invalid email address: $to");
                return false;
            }

            $headers = $this->buildHeaders($options);

            $isHtml = isset($options['html']) && $options['html'] === true;
            if ($isHtml) {
                $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
                $message = $this->wrapHtmlMessage($message, $subject);
            } else {
                $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
            }

            error_log("=== SENDING EMAIL ===");
            error_log("To: $to");
            error_log("From: {$this->fromEmail}");
            error_log("Subject: $subject");
            error_log("Environment: " . (Config::isDevelopment() ? 'Development' : 'Production'));
            error_log("====================");

            $sent = false;
            if ($this->smtpEnabled) {
                $sent = $this->sendSmtp($to, $subject, $message, $headers);
                if (!$sent) {
                    $detail = $this->lastError ? " | {$this->lastError}" : '';
                    error_log("Mailer Error: SMTP send failed{$detail}");

                    $sent = @mail($to, $subject, $message, $headers);
                    if ($sent) {
                        error_log("Mailer Notice: SMTP send failed; PHP mail() fallback succeeded for $to");
                        $this->lastError = '';
                    } else {
                        $fallbackError = error_get_last();
                        if ($fallbackError) {
                            $this->lastError .= " | PHP fallback: " . $fallbackError['message'];
                            error_log("Mailer Error: PHP mail() fallback failed: " . $fallbackError['message']);
                        }
                        return false;
                    }
                }
            } else {
                $sent = @mail($to, $subject, $message, $headers);
            }

            if (!$sent) {
                $lastError = error_get_last();
                if (!$this->lastError) {
                    $this->lastError = "PHP mail() returned false";
                }
                error_log("Mailer Error: Failed to send email to $to");
                if ($lastError) {
                    $this->lastError .= " | PHP: " . $lastError['message'];
                    error_log("PHP Error: " . $lastError['message']);
                }
                return false;
            }

            error_log("Email sent successfully to $to");
            return true;

        } catch (Exception $e) {
            $this->lastError = $e->getMessage();
            error_log("Mailer Exception: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            return false;
        }
    }

    public function getLastError() {
        return $this->lastError;
    }

    private function buildHeaders($options = []) {
        $headers = [];

        $headers[] = "From: {$this->fromName} <{$this->fromEmail}>";
        $replyTo = $options['reply_to'] ?? $this->fromEmail;
        $headers[] = "Reply-To: $replyTo";
        $headers[] = "X-Mailer: PHP/" . phpversion();
        $headers[] = "MIME-Version: 1.0";

        if (isset($options['priority'])) {
            $headers[] = "X-Priority: {$options['priority']}";
        }

        return implode("\r\n", $headers) . "\r\n";
    }

    private function wrapHtmlMessage($message, $subject) {
        return '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>' . htmlspecialchars($subject) . '</title>
</head>
<body style="margin: 0; padding: 0; font-family: Arial, sans-serif; background-color: #f5f5f5;">
    <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f5f5f5; padding: 20px;">
        <tr>
            <td align="center">
                <table width="600" cellpadding="0" cellspacing="0" border="0" style="background-color: #ffffff; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                    <tr>
                        <td style="background: linear-gradient(135deg, #1b2f73 0%, #0f1c49 100%); padding: 30px; text-align: center; border-radius: 8px 8px 0 0;">
                            <h1 style="margin: 0; color: #ffffff; font-size: 24px; font-weight: bold;">
                                ATIERA Finance
                            </h1>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 40px 30px;">
                            ' . $message . '
                        </td>
                    </tr>
                    <tr>
                        <td style="background-color: #f8f9fa; padding: 20px 30px; text-align: center; border-radius: 0 0 8px 8px; border-top: 1px solid #e9ecef;">
                            <p style="margin: 0; color: #6c757d; font-size: 12px;">
                                This is an automated message from ATIERA Financial Management System<br>
                                Please do not reply to this email.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>';
    }

    public function sendVerificationCode($to, $code, $firstName = '') {
        $subject = 'ATIERA - Privacy Mode Verification Code';

        $message = '
        <div style="color: #333; line-height: 1.6;">
            <p style="font-size: 16px; margin-bottom: 10px;">Hello' . ($firstName ? ' ' . htmlspecialchars($firstName) : '') . ',</p>

            <p style="font-size: 14px; color: #666; margin-bottom: 20px;">
                You requested to view financial amounts in the ATIERA system. Please use the verification code below:
            </p>

            <div style="background: linear-gradient(135deg, #1b2f73 0%, #0f1c49 100%); padding: 20px; text-align: center; border-radius: 8px; margin: 30px 0;">
                <p style="color: #ffffff; font-size: 14px; margin: 0 0 10px 0; text-transform: uppercase; letter-spacing: 2px;">
                    Verification Code
                </p>
                <p style="color: #ffffff; font-size: 36px; font-weight: bold; margin: 0; letter-spacing: 8px; font-family: monospace;">
                    ' . htmlspecialchars($code) . '
                </p>
            </div>

            <div style="background-color: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin: 20px 0;">
                <p style="margin: 0; color: #856404; font-size: 13px;">
                    <strong>Important:</strong> This code will expire in <strong>2 minutes</strong>.
                </p>
            </div>

            <p style="font-size: 14px; color: #666; margin-top: 20px;">
                If you did not request this code, please ignore this email or contact your system administrator.
            </p>

            <p style="font-size: 14px; color: #333; margin-top: 30px;">
                Best regards,<br>
                <strong>ATIERA Financial Management System</strong>
            </p>
        </div>';

        return $this->send($to, $subject, $message, ['html' => true, 'priority' => 1]);
    }

    public function testConnection() {
        $testEmail = Config::get('company.email') ?: 'admin@atiera.com';
        $subject = 'ATIERA Mailer Test';
        $message = 'This is a test email from ATIERA Financial Management System. If you receive this, email is working properly!';

        return $this->send($testEmail, $subject, $message);
    }

    private function sendSmtp($to, $subject, $message, $headers) {
        // Prefer Symfony Mailer if available, then PHPMailer. Symfony provides a modern API and provider transports.
        if ($this->useSymfonyMailer) {
            try {
                $enc = strtolower($this->smtpEncryption);
                $scheme = ($enc === 'ssl') ? 'smtps' : 'smtp';
                $host = $this->smtpHost;
                $port = $this->smtpPort;

                $dsn = $scheme . '://';
                if (!empty($this->smtpUser)) {
                    $dsn .= rawurlencode($this->smtpUser) . ':' . rawurlencode($this->smtpPass) . '@';
                }
                $dsn .= $host . ':' . $port;
                if ($enc === 'tls') {
                    $dsn .= '?encryption=starttls';
                }

                $transport = \Symfony\Component\Mailer\Transport::fromDsn($dsn);
                $symMailer = new \Symfony\Component\Mailer\Mailer($transport);

                $email = (new \Symfony\Component\Mime\Email())
                    ->from($this->getEnvelopeFromEmail())
                    ->to($to)
                    ->subject($subject);

                // prefer html if message contains HTML or headers indicate html
                $isHtml = stripos($headers, 'Content-Type: text/html') !== false || stripos($message, '<html') !== false;
                if ($isHtml) {
                    $email->html($message)->text(strip_tags($message));
                } else {
                    $email->text($message);
                }

                $symMailer->send($email);
                return true;
            } catch (\Throwable $e) {
                $this->lastError = 'Symfony Mailer Exception: ' . $e->getMessage();
                error_log($this->lastError);
                // fall through to PHPMailer or legacy socket fallback
            }
        }

        // Prefer PHPMailer if available - it's more robust and handles edge cases
        if ($this->usePHPMailer) {
            try {
                $mail = new PHPMailer\\PHPMailer\\PHPMailer(true);
                $mail->isSMTP();
                $mail->SMTPDebug = 0;
                $mail->Host = $this->smtpHost;
                $mail->Port = $this->smtpPort;
                $mail->SMTPAuth = !empty($this->smtpUser);
                if (!empty($this->smtpUser)) {
                    $mail->Username = $this->smtpUser;
                    $mail->Password = $this->smtpPass;
                }
                $enc = strtolower($this->smtpEncryption);
                if ($enc === 'ssl' || $enc === 'tls') {
                    $mail->SMTPSecure = $enc === 'ssl' ? PHPMailer\\PHPMailer\\PHPMailer::ENCRYPTION_SMTPS : PHPMailer\\PHPMailer\\PHPMailer::ENCRYPTION_STARTTLS;
                }

                $from = $this->getEnvelopeFromEmail();
                $mail->setFrom($from, $this->fromName);
                $mail->addAddress($to);
                $mail->Subject = $subject;
                $mail->isHTML(stripos($headers, 'Content-Type: text/html') !== false || stripos($message, '<html') !== false);
                $mail->Body = $message;
                if (!$mail->isHTML()) {
                    $mail->AltBody = strip_tags($message);
                }

                foreach (explode("\r\n", $headers) as $h) {
                    $h = trim($h);
                    if ($h === '') continue;
                    if (stripos($h, 'Reply-To:') === 0) {
                        $mail->addReplyTo(trim(substr($h, 9)));
                    }
                }

                $sent = $mail->send();
                if (!$sent) {
                    $this->lastError = 'PHPMailer failed to send';
                    return false;
                }
                return true;
            } catch (Exception $e) {
                $this->lastError = 'PHPMailer Exception: ' . $e->getMessage();
                error_log('PHPMailer Exception: ' . $e->getMessage());
                return false;
            }
        }

        // Fallback to legacy socket implementation if PHPMailer is not available
        $host = $this->smtpHost;
        $port = $this->smtpPort;
        $encryption = strtolower($this->smtpEncryption);
        $ehloHost = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $envelopeFrom = $this->getEnvelopeFromEmail();

        $remote = ($encryption === 'ssl') ? "ssl://{$host}" : $host;
        $fp = @fsockopen($remote, $port, $errno, $errstr, 10);
        if (!$fp) {
            $this->lastError = "SMTP connect failed: $errstr ($errno)";
            error_log("SMTP Error: $errstr ($errno)");
            return false;
        }

        if (!$this->expectSmtpCode($fp, 220)) {
            fclose($fp);
            return false;
        }

        if (!$this->smtpCommand($fp, "EHLO {$ehloHost}", 250)) {
            fclose($fp);
            return false;
        }

        if ($encryption === 'tls') {
            if (!$this->smtpCommand($fp, "STARTTLS", 220)) {
                fclose($fp);
                return false;
            }
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                $this->lastError = "Failed to start TLS encryption";
                error_log("SMTP Error: Failed to start TLS");
                fclose($fp);
                return false;
            }
            if (!$this->smtpCommand($fp, "EHLO {$ehloHost}", 250)) {
                fclose($fp);
                return false;
            }
        }

        if (!empty($this->smtpUser)) {
            if (
                !$this->smtpCommand($fp, "AUTH LOGIN", 334)
                || !$this->smtpCommand($fp, base64_encode($this->smtpUser), 334)
                || !$this->smtpCommand($fp, base64_encode($this->smtpPass), 235)
            ) {
                fclose($fp);
                return false;
            }
        }

        if (
            !$this->smtpCommand($fp, "MAIL FROM:<{$envelopeFrom}>", 250)
            || !$this->smtpCommand($fp, "RCPT TO:<{$to}>", [250, 251])
            || !$this->smtpCommand($fp, "DATA", 354)
        ) {
            fclose($fp);
            return false;
        }

        $safeSubject = str_replace(["\r", "\n"], '', (string) $subject);
        $smtpHeaders = "To: {$to}\r\nSubject: {$safeSubject}\r\n" . $headers;
        $data = $smtpHeaders . "\r\n" . $message . "\r\n.";
        if (!$this->smtpCommand($fp, $data, 250)) {
            fclose($fp);
            return false;
        }

        $this->smtpCommand($fp, "QUIT", 221);
        fclose($fp);
        return true;
    }

    private function resolveFromEmail($configuredFromEmail) {
        $configuredFromEmail = trim((string) $configuredFromEmail);
        $smtpUserEmail = trim((string) $this->smtpUser);
        $smtpHost = strtolower((string) $this->smtpHost);

        if (!filter_var($configuredFromEmail, FILTER_VALIDATE_EMAIL)) {
            return filter_var($smtpUserEmail, FILTER_VALIDATE_EMAIL) ? $smtpUserEmail : 'noreply@atiera.com';
        }

        // Gmail commonly rejects unauthenticated From addresses that are not the logged-in sender.
        if (
            $this->smtpEnabled
            && strpos($smtpHost, 'gmail.com') !== false
            && filter_var($smtpUserEmail, FILTER_VALIDATE_EMAIL)
        ) {
            $fromDomain = strtolower(substr(strrchr($configuredFromEmail, '@') ?: '', 1));
            $smtpDomain = strtolower(substr(strrchr($smtpUserEmail, '@') ?: '', 1));
            if ($fromDomain !== $smtpDomain) {
                error_log("Mailer Notice: Overriding mail.from_address with SMTP username for Gmail compatibility.");
                return $smtpUserEmail;
            }
        }

        return $configuredFromEmail;
    }

    private function getEnvelopeFromEmail() {
        if ($this->smtpEnabled && filter_var($this->smtpUser, FILTER_VALIDATE_EMAIL)) {
            return $this->smtpUser;
        }
        return $this->fromEmail;
    }

    private function smtpCommand($fp, $command, $expect) {
        fwrite($fp, $command . "\r\n");
        return $this->expectSmtpCode($fp, $expect);
    }

    private function expectSmtpCode($fp, $expect) {
        $response = '';
        while ($line = fgets($fp, 515)) {
            $response .= $line;
            if (preg_match('/^\\d{3} /', $line)) {
                break;
            }
        }

        $code = intval(substr($response, 0, 3));
        if (is_array($expect)) {
            if (!in_array($code, $expect, true)) {
                error_log("SMTP Error: Unexpected response {$response}");
                $this->lastError = "SMTP unexpected response: " . trim($response);
                return false;
            }
        } else {
            if ($code !== $expect) {
                error_log("SMTP Error: Unexpected response {$response}");
                $this->lastError = "SMTP unexpected response: " . trim($response);
                return false;
            }
        }

        return true;
    }
}
?>

