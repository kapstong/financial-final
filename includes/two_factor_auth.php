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
        // Generate a 32-character base32 secret
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = '';
        for ($i = 0; $i < 32; $i++) {
            $secret .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $secret;
    }

    /**
     * Generate QR code URL for TOTP setup
     */
    public function generateTOTPQRCode($secret, $username, $issuer = 'ATIERA Finance') {
        $url = 'otpauth://totp/' . urlencode($issuer . ':' . $username) . '?secret=' . $secret . '&issuer=' . urlencode($issuer);
        return $url;
    }

    /**
     * Verify TOTP code
     */
    public function verifyTOTPCode($secret, $code, $timeWindow = 2) {
        // Convert secret from base32 to binary
        $secret = $this->base32Decode($secret);

        // Get current timestamp
        $timestamp = time();

        // Check codes in time window (current time ± timeWindow minutes)
        for ($i = -$timeWindow; $i <= $timeWindow; $i++) {
            $time = $timestamp + ($i * 30); // 30 seconds per TOTP interval
            $generatedCode = $this->generateTOTP($secret, $time);

            if ($generatedCode === str_pad($code, 6, '0', STR_PAD_LEFT)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Generate TOTP code from secret and timestamp
     */
    private function generateTOTP($secret, $timestamp) {
        $time = floor($timestamp / 30); // 30-second intervals
        $time = pack('N*', 0) . pack('N*', $time); // Convert to 8-byte big-endian

        $hmac = hash_hmac('sha1', $time, $secret, true);
        $offset = ord($hmac[19]) & 0x0F;

        $code = (ord($hmac[$offset]) & 0x7F) << 24
              | (ord($hmac[$offset + 1]) & 0xFF) << 16
              | (ord($hmac[$offset + 2]) & 0xFF) << 8
              | (ord($hmac[$offset + 3]) & 0xFF);

        return str_pad($code % 1000000, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Base32 decode
     */
    private function base32Decode($base32) {
        $base32chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $base32charsfl = strlen($base32chars);

        $output = '';
        $v = 0;
        $vbits = 0;

        for ($i = 0; $i < strlen($base32); $i++) {
            $v <<= 5;
            if ($base32[$i] >= 'A' && $base32[$i] <= 'Z') {
                $v += ord($base32[$i]) - 65;
            } elseif ($base32[$i] >= '2' && $base32[$i] <= '7') {
                $v += 24 + ord($base32[$i]) - 50;
            } else {
                // Invalid character
                continue;
            }
            $vbits += 5;

            if ($vbits >= 8) {
                $output .= chr(($v >> ($vbits - 8)) & 0xFF);
                $vbits -= 8;
            }
        }

        return $output;
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
                if ($this->verifyTOTPCode($config['secret'], $code)) {
                    $this->log2FAVerification($userId, 'totp', true);
                    return ['success' => true, 'method' => 'totp'];
                }
                break;

            case 'sms':
                // SMS verification would be implemented here
                // For now, return false
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

