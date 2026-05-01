<?php
/**
 * OTP (One-Time Password) API
 * Handles OTP generation, sending, and verification
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Prevent any output before JSON
if (ob_get_level() === 0) {
    ob_start();
}

// Start session first
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Minimal error handling - catch everything
try {
    // Load only essential includes
    require_once __DIR__ . '/../config.php';
    require_once __DIR__ . '/../includes/database.php';
    require_once __DIR__ . '/../includes/logger.php';
    require_once __DIR__ . '/../includes/mailer.php';
    require_once __DIR__ . '/../includes/two_factor_auth.php';
    
    // Check authentication
    if (!isset($_SESSION['user']) || empty($_SESSION['user']['id'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        ob_end_flush();
        exit;
    }
    
    $userId = (int)$_SESSION['user']['id'];
    $action = $_GET['action'] ?? $_POST['action'] ?? '';
    
    switch ($action) {
        case 'send':
            handleSendOTP($userId);
            break;
            
        case 'verify':
            handleVerifyOTP($userId);
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
    }
    
    ob_end_flush();
    
} catch (Exception $e) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
    exit;
}

/**
 * Send OTP to user's email
 */
function handleSendOTP($userId) {
    try {
        $twoFactor = TwoFactorAuth::getInstance();
        $result = $twoFactor->generateAndSendEmailCode($userId);
        
        if ($result['success']) {
            echo json_encode([
                'success' => true,
                'message' => 'OTP sent to your email',
                'expires_in' => 120  // 2 minutes
            ]);
        } else {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => $result['error'] ?? 'Failed to send OTP'
            ]);
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

/**
 * Verify OTP code
 */
function handleVerifyOTP($userId) {
    try {
        $code = $_POST['code'] ?? '';
        
        // Validate format
        if (!preg_match('/^\d{6}$/', (string)$code)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid code format']);
            return;
        }
        
        $twoFactor = TwoFactorAuth::getInstance();
        $result = $twoFactor->verify2FACode($userId, $code);
        
        if (!empty($result['success']) && $result['success'] === true) {
            // Mark privacy mode as unlocked in session
            $_SESSION['privacy_unlocked'] = true;
            $_SESSION['privacy_unlocked_time'] = time();
            $_SESSION['privacy_visible'] = true;
            
            echo json_encode([
                'success' => true,
                'message' => 'OTP verified successfully'
            ]);
        } else {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => $result['error'] ?? 'Invalid or expired OTP'
            ]);
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}
?>
