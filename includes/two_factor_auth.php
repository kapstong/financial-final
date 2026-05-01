<?php
/**
 * ATIERA Financial Management System - Two-Factor Authentication
 * Comprehensive 2FA implementation with TOTP, SMS, and backup codes
 */

class TwoFactorAuth {
    private static $instance = null;
    private $db;

    private function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Generate TOTP secret for user
     */
    public function generateTOTPSecret() {
        // TOTP removed. Use email verification codes instead.
        return null;
    }

    /**
     * Generate QR code URL for TOTP setup
     */
    public function generateTOTPQRCode($secret, $username, $issuer = 'ATIERA Finance') {
        // TOTP removed; QR code generation no longer supported.
        return null;
    }

    /**
     * Verify TOTP code
     */
    public function verifyTOTPCode($secret, $code, $timeWindow = 2) {
        // TOTP removed. This function should not be used.
        return false;
    }

    /**
     * Generate TOTP code from secret and timestamp
     */
    private function generateTOTP($secret, $timestamp) {
        // Removed TOTP generation
        return '000000';
    }

    /**
     * Base32 decode
     */
    private function base32Decode($base32) {
        // Base32 decode not needed anymore
        return '';
    }

    /**
     * Generate a temporary email verification code and send to user
     */
    public function generateAndSendEmailCode($userId) {
        try {
            $stmt = $this->db->prepare("SELECT id, email, first_name FROM users WHERE id = ? LIMIT 1");
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$user || empty($user['email'])) {
                return ['success' => false, 'error' => 'User email not found'];
            }

            $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);

            // Persist code in sms_codes table (reuse for email codes) with 2 minute expiry
            $insert = $this->db->prepare("INSERT INTO sms_codes (user_id, phone_number, code, expires_at, created_at)
                VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 2 MINUTE), NOW())
                ON DUPLICATE KEY UPDATE code = ?, expires_at = DATE_ADD(NOW(), INTERVAL 2 MINUTE)");
            $insert->execute([$userId, $user['email'], $code, $code]);

            // Send email
            $mailer = Mailer::getInstance();
            $sent = $mailer->sendVerificationCode($user['email'], $code, $user['first_name'] ?? '');
            if ($sent) {
                Logger::getInstance()->info("Email 2FA code sent to {$user['email']} for user {$userId}");
                return ['success' => true, 'message' => 'Email code sent'];
            }

            // If mail failed, remove the DB entry so codes can't be used
            $del = $this->db->prepare("DELETE FROM sms_codes WHERE user_id = ? AND phone_number = ? AND code = ?");
            $del->execute([$userId, $user['email'], $code]);

            Logger::getInstance()->error('Failed to send email 2FA code: ' . $mailer->getLastError());
            return ['success' => false, 'error' => 'Failed to send email code: ' . $mailer->getLastError()];

        } catch (Exception $e) {
            Logger::getInstance()->error('Failed to generate/send email 2FA code: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Verify email-based 2FA code (session-backed)
     */
    private function verifyEmailCode($userId, $code) {
        try {
            $stmt = $this->db->prepare("SELECT id FROM sms_codes WHERE user_id = ? AND phone_number = ? AND code = ? AND expires_at > NOW() LIMIT 1");
            // phone_number stores email for email codes
            $stmt->execute([$userId, $this->getUserEmailById($userId), $code]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                // consume code
                $del = $this->db->prepare("DELETE FROM sms_codes WHERE id = ?");
                $del->execute([$row['id']]);
                return true;
            }
            return false;
        } catch (Exception $e) {
            return false;
        }
    }

    private function getUserEmailById($userId) {
        try {
            $stmt = $this->db->prepare("SELECT email FROM users WHERE id = ? LIMIT 1");
            $stmt->execute([$userId]);
            $r = $stmt->fetch(PDO::FETCH_ASSOC);
            return $r['email'] ?? null;
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Generate backup codes for account recovery
     */
    public function generateBackupCodes($count = 10) {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $codes[] = strtoupper(bin2hex(random_bytes(5)));
        }
        return $codes;
    }

    /**
     * Enable 2FA for user
     */
    public function enable2FA($userId, $method, $config = []) {
        try {
            // Check if 2FA is already enabled
            if ($this->is2FAEnabled($userId)) {
                return ['success' => false, 'error' => '2FA is already enabled for this user'];
            }

            $backupCodes = $this->generateBackupCodes();
            $backupCodesJson = json_encode($backupCodes);

            $stmt = $this->db->prepare("
                INSERT INTO user_2fa (user_id, method, secret, backup_codes, is_enabled, created_at)
                VALUES (?, ?, ?, ?, 1, NOW())
            ");

            $result = $stmt->execute([
                $userId,
                $method,
                $config['secret'] ?? null,
                $backupCodesJson
            ]);

            if ($result) {
                // Log the 2FA enable event
                Logger::getInstance()->logUserAction(
                    'Enabled 2FA',
                    'user_2fa',
                    $this->db->lastInsertId(),
                    null,
                    ['method' => $method]
                );

                return [
                    'success' => true,
                    'backup_codes' => $backupCodes,
                    'message' => '2FA enabled successfully'
                ];
            }

            return ['success' => false, 'error' => 'Failed to enable 2FA'];

        } catch (Exception $e) {
            Logger::getInstance()->error("Failed to enable 2FA for user $userId: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Disable 2FA for user
     */
    public function disable2FA($userId) {
        try {
            $stmt = $this->db->prepare("
                UPDATE user_2fa SET is_enabled = 0, disabled_at = NOW()
                WHERE user_id = ? AND is_enabled = 1
            ");

            $result = $stmt->execute([$userId]);

            if ($result) {
                Logger::getInstance()->logUserAction(
                    'Disabled 2FA',
                    'user_2fa',
                    null,
                    null,
                    ['user_id' => $userId]
                );

                return ['success' => true, 'message' => '2FA disabled successfully'];
            }

            return ['success' => false, 'error' => 'Failed to disable 2FA'];

        } catch (Exception $e) {
            Logger::getInstance()->error("Failed to disable 2FA for user $userId: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Check if 2FA is enabled for user
     */
    public function is2FAEnabled($userId) {
        try {
            $stmt = $this->db->prepare("
                SELECT id FROM user_2fa
                WHERE user_id = ? AND is_enabled = 1
                LIMIT 1
            ");
            $stmt->execute([$userId]);
            return $stmt->fetch() !== false;

        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Get 2FA configuration for user
     */
    public function get2FAConfig($userId) {
        try {
            $stmt = $this->db->prepare("
                SELECT * FROM user_2fa
                WHERE user_id = ? AND is_enabled = 1
                LIMIT 1
            ");
            $stmt->execute([$userId]);
            return $stmt->fetch(PDO::FETCH_ASSOC);

        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Verify 2FA code during login
     */
    public function verify2FACode($userId, $code) {
        $config = $this->get2FAConfig($userId);
        if (!$config) {
            return ['success' => false, 'error' => '2FA not enabled for this user'];
        }

        $method = $config['method'];

        switch ($method) {
            case 'totp':
            case 'email':
                // TOTP has been removed; treat configured TOTP method as email codes now
                if ($this->verifyEmailCode($userId, $code)) {
                    $this->log2FAVerification($userId, 'email', true);
                    return ['success' => true, 'method' => 'email'];
                }
                break;

            case 'sms':
                // SMS verification would be implemented here
                break;

            case 'backup_code':
                $backupCodes = json_decode($config['backup_codes'], true);
                $normalizedCode = strtoupper(trim((string) $code));
                $matchedIndex = null;
                foreach ((array) $backupCodes as $index => $backupCode) {
                    if (is_string($backupCode) && hash_equals($backupCode, $normalizedCode)) {
                        $matchedIndex = $index;
                        break;
                    }
                }

                if ($matchedIndex !== null) {
                    // Remove used backup code
                    unset($backupCodes[$matchedIndex]);

                    $stmt = $this->db->prepare("
                        UPDATE user_2fa SET backup_codes = ? WHERE user_id = ?
                    ");
                    $stmt->execute([json_encode(array_values($backupCodes)), $userId]);

                    $this->log2FAVerification($userId, 'backup_code', true);
                    return ['success' => true, 'method' => 'backup_code'];
                }
                break;
        }

        // Log failed verification attempt
        $this->log2FAVerification($userId, $method, false);
        return ['success' => false, 'error' => 'Invalid 2FA code'];
    }

    /**
     * Send SMS verification code
     */
    public function sendSMSCode($userId, $phoneNumber) {
        try {
            // Generate a 6-digit code
            $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);

            // Store the code temporarily (expires in 5 minutes)
            $stmt = $this->db->prepare("
                INSERT INTO sms_codes (user_id, phone_number, code, expires_at, created_at)
                VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 5 MINUTE), NOW())
                ON DUPLICATE KEY UPDATE code = ?, expires_at = DATE_ADD(NOW(), INTERVAL 5 MINUTE)
            ");
            $stmt->execute([$userId, $phoneNumber, $code, $code]);

            // Demo mode: SMS delivery is not integrated with an external provider.
            return ['success' => true, 'message' => 'SMS sent successfully (demo mode)'];

        } catch (Exception $e) {
            Logger::getInstance()->error("Failed to send SMS code to user $userId: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Verify SMS code
     */
    public function verifySMSCode($userId, $code) {
        try {
            $stmt = $this->db->prepare("
                SELECT id FROM sms_codes
                WHERE user_id = ? AND code = ? AND expires_at > NOW()
                LIMIT 1
            ");
            $stmt->execute([$userId, $code]);

            if ($stmt->fetch()) {
                // Delete used code
                $stmt = $this->db->prepare("DELETE FROM sms_codes WHERE user_id = ? AND code = ?");
                $stmt->execute([$userId, $code]);

                $this->log2FAVerification($userId, 'sms', true);
                return ['success' => true, 'method' => 'sms'];
            }

            $this->log2FAVerification($userId, 'sms', false);
            return ['success' => false, 'error' => 'Invalid or expired SMS code'];

        } catch (Exception $e) {
            Logger::getInstance()->error("Failed to verify SMS code for user $userId: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Regenerate backup codes
     */
    public function regenerateBackupCodes($userId) {
        try {
            $backupCodes = $this->generateBackupCodes();
            $backupCodesJson = json_encode($backupCodes);

            $stmt = $this->db->prepare("
                UPDATE user_2fa SET backup_codes = ? WHERE user_id = ? AND is_enabled = 1
            ");
            $result = $stmt->execute([$backupCodesJson, $userId]);

            if ($result) {
                Logger::getInstance()->logUserAction(
                    'Regenerated backup codes',
                    'user_2fa',
                    null,
                    null,
                    ['user_id' => $userId]
                );

                return [
                    'success' => true,
                    'backup_codes' => $backupCodes,
                    'message' => 'Backup codes regenerated successfully'
                ];
            }

            return ['success' => false, 'error' => 'Failed to regenerate backup codes'];

        } catch (Exception $e) {
            Logger::getInstance()->error("Failed to regenerate backup codes for user $userId: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Get 2FA statistics
     */
    public function get2FAStats() {
        try {
            $stmt = $this->db->query("
                SELECT
                    COUNT(CASE WHEN is_enabled = 1 THEN 1 END) as enabled_users,
                    COUNT(CASE WHEN method = 'totp' AND is_enabled = 1 THEN 1 END) as totp_users,
                    COUNT(CASE WHEN method = 'sms' AND is_enabled = 1 THEN 1 END) as sms_users,
                    COUNT(*) as total_users
                FROM user_2fa
            ");
            return $stmt->fetch(PDO::FETCH_ASSOC);

        } catch (Exception $e) {
            return [
                'enabled_users' => 0,
                'totp_users' => 0,
                'sms_users' => 0,
                'total_users' => 0
            ];
        }
    }

    /**
     * Log 2FA verification attempt
     */
    private function log2FAVerification($userId, $method, $success) {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO twofa_attempts (user_id, method, success, ip_address, user_agent, attempted_at)
                VALUES (?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $userId,
                $method,
                $success ? 1 : 0,
                $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
            ]);

        } catch (Exception $e) {
            // Don't fail the verification if logging fails
        }
    }

    /**
     * Check if user needs 2FA verification
     */
    public function requires2FAVerification($userId) {
        return $this->is2FAEnabled($userId) && !isset($_SESSION['2fa_verified']);
    }

    /**
     * Mark user as 2FA verified for current session
     */
    public function mark2FAVerified($userId) {
        $_SESSION['2fa_verified'] = true;
        $_SESSION['2fa_verified_at'] = time();
    }

    /**
     * Clear 2FA verification status
     */
    public function clear2FAVerification() {
        unset($_SESSION['2fa_verified']);
        unset($_SESSION['2fa_verified_at']);
    }

    /**
     * Get failed 2FA attempts for security monitoring
     */
    public function getFailedAttempts($userId, $hours = 24) {
        try {
            $stmt = $this->db->prepare("
                SELECT COUNT(*) as failed_count
                FROM twofa_attempts
                WHERE user_id = ? AND success = 0 AND attempted_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
            ");
            $stmt->execute([$userId, $hours]);
            $result = $stmt->fetch();
            return $result['failed_count'];

        } catch (Exception $e) {
            return 0;
        }
    }

    /**
     * Check if account should be locked due to failed 2FA attempts
     */
    public function shouldLockAccount($userId, $maxAttempts = 5, $lockoutHours = 1) {
        if (class_exists('Config')) {
            $maxAttempts = (int) Config::get('security.twofa_attempts_max', $maxAttempts);
            $lockoutHours = (int) Config::get('security.twofa_lockout_hours', $lockoutHours);
        }

        $maxAttempts = max(1, $maxAttempts);
        $lockoutHours = max(1, $lockoutHours);
        $failedAttempts = $this->getFailedAttempts($userId, $lockoutHours);
        return $failedAttempts >= $maxAttempts;
    }
}
?>

