<?php
/**
 * Privacy Mode Code API
 * Handles sending, verifying, and checking privacy mode verification codes
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Start output buffering
ob_start();
ini_set('display_errors', 0);
error_reporting(E_ALL);

// Log to file for debugging
$debugLog = function($msg) {
    error_log('[privacy_code.php] ' . $msg);
};

$debugLog('API call started');

// Set up error handler to catch and output errors as JSON
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    if (!(error_reporting() & $errno)) {
        return false;
    }

    if (in_array($errno, [E_DEPRECATED, E_USER_DEPRECATED], true)) {
        error_log(sprintf('privacy_code.php deprecation: %s in %s on line %d', $errstr, $errfile, $errline));
        return true;
    }

    $errorMsg = "Error in {$errfile}:{$errline}: {$errstr}";
    error_log($errorMsg);
    http_response_code(500);
    echo json_encode(['error' => $errorMsg, 'errno' => $errno]);
    ob_end_flush();
    exit(1);
}, E_ALL);

// Set up exception handler
set_exception_handler(function($exception) {
    http_response_code(500);
    echo json_encode(['error' => 'Exception: ' . $exception->getMessage()]);
    ob_end_flush();
    exit(1);
});

try {
    $debugLog('Files: starting load');

    // Start session FIRST before anything else
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $debugLog('Session started');

    // Load required files - catch any load errors
    $files = [
        __DIR__ . '/../config.php',
        __DIR__ . '/../includes/auth.php',
        __DIR__ . '/../includes/database.php',
        __DIR__ . '/../includes/logger.php',
        __DIR__ . '/../includes/mailer.php',
        __DIR__ . '/../includes/two_factor_auth.php'
    ];

    foreach ($files as $file) {
        if (file_exists($file)) {
            $debugLog("Loading {$file}");
            require_once $file;
            $debugLog("Loaded {$file}");
        } else {
            throw new Exception("File not found: {$file}");
        }
    }

    $debugLog('All files loaded successfully');
    $debugLog('Session user: ' . (isset($_SESSION['user']) ? 'set' : 'not set'));

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $action = $_GET['action'] ?? $_POST['action'] ?? '';
    
    // Debug endpoint - no auth needed
    if ($action === 'debug') {
        echo json_encode([
            'status' => 'ok',
            'timestamp' => date('Y-m-d H:i:s'),
            'includes_loaded' => true
        ]);
        ob_end_flush();
        exit;
    }
    
    // Check if user is logged in
    if (!isset($_SESSION['user'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        ob_end_flush();
        exit;
    }

    $user = $_SESSION['user'];
    
    $debugLog("Action: {$action}");
    
    switch ($action) {
        case 'send_code':
        case 'check_method':
            $debugLog('Calling handleCheckMethod');
            handleCheckMethod($user);
            break;

        case 'verify_code':
            $debugLog('Calling handleVerifyCode');
            handleVerifyCode($user);
            break;

        case 'set_visibility':
            $debugLog('Calling handleSetVisibility');
            handleSetVisibility($user);
            break;

        case 'check_status':
            $debugLog('Calling handleCheckStatus');
            handleCheckStatus($user);
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
    }

} catch (Exception $e) {
    $debugLog('Exception caught: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
}

/**
 * Handle reporting the available privacy verification method
 */
function handleCheckMethod($user) {
    try {
        $db = Database::getInstance()->getConnection();
        ensureEmailCodesTable($db);
        cleanupExpiredEmailCodes($db);

        $account = getPrivacyVerificationUser($db, (int)($user['id'] ?? 0));
        if (!$account || empty($account['email'])) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => 'A valid account email is required to receive a verification code.'
            ]);
            return;
        }

        $method = 'email';

        // If the request intended to send a code, send it now
        $action = $_GET['action'] ?? $_POST['action'] ?? '';
        if ($action === 'send_code') {
            $result = sendPrivacyEmailCode($db, $account);
            if (!empty($result['success'])) {
                echo json_encode(['success' => true, 'method' => $method, 'message' => 'Verification code sent to your email.']);
            } else {
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => $result['error'] ?? 'Failed to send code']);
            }
            return;
        }

        echo json_encode([
            'success' => true,
            'method' => $method,
            'message' => $method === 'email' ? 'A 6-digit code will be sent to your email.' : 'Use your configured verification method.'
        ]);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to prepare verification: ' . $e->getMessage()]);
    }
}

/**
 * Handle verifying the code
 */
function handleVerifyCode($user) {
    try {
        $db = Database::getInstance()->getConnection();
        ensureEmailCodesTable($db);
        cleanupExpiredEmailCodes($db);

        $code = $_POST['code'] ?? '';

        if (!preg_match('/^\d{6}$/', (string)$code)) {
            http_response_code(400);
            echo json_encode(['error' => 'A valid 6-digit code is required']);
            return;
        }

        $verified = verifyPrivacyEmailCode($db, (int)$user['id'], (string)$code);
        if ($verified) {
            // Mark as unlocked in session
            $_SESSION['privacy_unlocked'] = true;
            $_SESSION['privacy_unlocked_time'] = time();
            $_SESSION['privacy_visible'] = true;

            echo json_encode([
                'success' => true,
                'unlocked' => true,
                'message' => 'Verification code accepted'
            ]);
        } else {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid or expired verification code']);
        }

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Verification failed: ' . $e->getMessage()]);
    }
}

/**
 * Handle checking unlock status
 */
function handleCheckStatus($user) {
    try {
        $unlocked = isset($_SESSION['privacy_unlocked']) && $_SESSION['privacy_unlocked'] === true;
        $visible = isset($_SESSION['privacy_visible']) && $_SESSION['privacy_visible'] === true;

        echo json_encode([
            'success' => true,
            'unlocked' => $unlocked,
            'visible' => $visible
        ]);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Status check failed: ' . $e->getMessage()]);
    }
}

/**
 * Handle updating privacy visibility
 */
function handleSetVisibility($user) {
    try {
        $visibleParam = $_POST['visible'] ?? $_GET['visible'] ?? null;
        if ($visibleParam === null) {
            http_response_code(400);
            echo json_encode(['error' => 'Visibility flag is required']);
            return;
        }

        $visible = $visibleParam === '1' || $visibleParam === 1 || $visibleParam === true || $visibleParam === 'true';

        if ($visible && (!isset($_SESSION['privacy_unlocked']) || $_SESSION['privacy_unlocked'] !== true)) {
            http_response_code(403);
            echo json_encode(['error' => 'Privacy mode is locked']);
            return;
        }

        $_SESSION['privacy_visible'] = $visible;

        echo json_encode([
            'success' => true,
            'visible' => $visible
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to update visibility: ' . $e->getMessage()]);
    }
}

/**
 * Fetch enabled TOTP configuration for the current user.
 */
function getTotpConfigForUser(int $userId): ?array
{
    if ($userId <= 0) {
        return null;
    }

    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare("
        SELECT method, secret
        FROM user_2fa
        WHERE user_id = ? AND is_enabled = 1
        ORDER BY created_at DESC
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    $config = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$config || ($config['method'] ?? '') !== 'totp' || empty($config['secret'])) {
        return null;
    }

    return $config;
}

function ensureEmailCodesTable(PDO $db): void
{
    static $initialized = false;

    if ($initialized) {
        return;
    }

    $db->exec(
        'CREATE TABLE IF NOT EXISTS email_codes (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id INT UNSIGNED NOT NULL,
            email VARCHAR(255) NOT NULL,
            code VARCHAR(10) NOT NULL,
            expires_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY ux_email_codes_user_email (user_id, email),
            KEY idx_email_codes_expires_at (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $initialized = true;
}

function cleanupExpiredEmailCodes(PDO $db): void
{
    $db->exec('DELETE FROM email_codes WHERE expires_at <= NOW()');
}

function getPrivacyVerificationUser(PDO $db, int $userId): ?array
{
    if ($userId <= 0) {
        return null;
    }

    $stmt = $db->prepare('SELECT id, email, first_name FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    return $user ?: null;
}

function sendPrivacyEmailCode(PDO $db, array $user): array
{
    $userId = (int)($user['id'] ?? 0);
    $email = trim((string)($user['email'] ?? ''));

    if ($userId <= 0 || $email === '') {
        return ['success' => false, 'error' => 'User email not found'];
    }

    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

    $insert = $db->prepare(
        'INSERT INTO email_codes (user_id, email, code, expires_at, created_at)
         VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 2 MINUTE), NOW())
         ON DUPLICATE KEY UPDATE code = VALUES(code), expires_at = VALUES(expires_at), created_at = VALUES(created_at)'
    );
    $insert->execute([$userId, $email, $code]);

    $mailer = Mailer::getInstance();
    $sent = $mailer->sendVerificationCode($email, $code, (string)($user['first_name'] ?? ''));
    if ($sent) {
        return ['success' => true];
    }

    $delete = $db->prepare('DELETE FROM email_codes WHERE user_id = ? AND email = ?');
    $delete->execute([$userId, $email]);

    $error = trim((string) $mailer->getLastError());
    if ($error === '') {
        $error = 'Failed to send verification email';
    }

    return ['success' => false, 'error' => $error];
}

function verifyPrivacyEmailCode(PDO $db, int $userId, string $code): bool
{
    if ($userId <= 0 || !preg_match('/^\d{6}$/', $code)) {
        return false;
    }

    $stmt = $db->prepare(
        'SELECT id
         FROM email_codes
         WHERE user_id = ? AND code = ? AND expires_at > NOW()
         ORDER BY created_at DESC
         LIMIT 1'
    );
    $stmt->execute([$userId, $code]);
    $record = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$record) {
        return false;
    }

    $delete = $db->prepare('DELETE FROM email_codes WHERE id = ?');
    $delete->execute([(int) $record['id']]);

    return true;
}

ob_end_flush();
?>

