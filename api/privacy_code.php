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

// Set up error handler to catch and output errors as JSON
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    if (!(error_reporting() & $errno)) {
        return false;
    }

    if (in_array($errno, [E_DEPRECATED, E_USER_DEPRECATED], true)) {
        error_log(sprintf('privacy_code.php deprecation: %s in %s on line %d', $errstr, $errfile, $errline));
        return true;
    }

    http_response_code(500);
    echo json_encode(['error' => 'Server error: ' . $errstr]);
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
    // Load required files
    if (file_exists(__DIR__ . '/../includes/auth.php')) {
        require_once __DIR__ . '/../includes/auth.php';
    }
    if (file_exists(__DIR__ . '/../includes/database.php')) {
        require_once __DIR__ . '/../includes/database.php';
    }
    if (file_exists(__DIR__ . '/../includes/two_factor_auth.php')) {
        require_once __DIR__ . '/../includes/two_factor_auth.php';
    }

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    
    // Check if user is logged in
    if (!isset($_SESSION['user'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        ob_end_flush();
        exit;
    }

    $action = $_GET['action'] ?? $_POST['action'] ?? '';
    $user = $_SESSION['user'];
    
    switch ($action) {
        case 'send_code':
        case 'check_method':
            handleCheckMethod($user);
            break;

        case 'verify_code':
            handleVerifyCode($user);
            break;

        case 'set_visibility':
            handleSetVisibility($user);
            break;

        case 'check_status':
            handleCheckStatus($user);
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
}

/**
 * Handle reporting the available privacy verification method
 */
function handleCheckMethod($user) {
    try {
        $totpConfig = getTotpConfigForUser((int)($user['id'] ?? 0));
        if (!$totpConfig) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => 'Authenticator verification is not configured for this account. Enable TOTP in Profile Settings first.'
            ]);
            return;
        }

        echo json_encode([
            'success' => true,
            'method' => 'totp',
            'message' => 'Use the current 6-digit code from your authenticator app.'
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
        $code = $_POST['code'] ?? '';

        if (!preg_match('/^\d{6}$/', (string)$code)) {
            http_response_code(400);
            echo json_encode(['error' => 'A valid 6-digit authenticator code is required']);
            return;
        }

        $totpConfig = getTotpConfigForUser((int)($user['id'] ?? 0));
        if (!$totpConfig) {
            http_response_code(400);
            echo json_encode(['error' => 'Authenticator verification is not configured for this account']);
            return;
        }

        $twoFactor = TwoFactorAuth::getInstance();
        if ($twoFactor->verifyTOTPCode($totpConfig['secret'], $code, 1)) {
            // Mark as unlocked in session
            $_SESSION['privacy_unlocked'] = true;
            $_SESSION['privacy_unlocked_time'] = time();
            $_SESSION['privacy_visible'] = true;

            echo json_encode([
                'success' => true,
                'unlocked' => true,
                'message' => 'Authenticator code verified successfully'
            ]);
        } else {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid authenticator code']);
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

ob_end_flush();
?>

