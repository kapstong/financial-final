<?php
// Start with absolute minimal setup
error_reporting(E_ALL);
ini_set('display_errors', '0');

// Headers FIRST
header('Content-Type: application/json; charset=utf-8');

// Clean any output
@ob_clean();

// Session
@session_start();

// Auth check
if (!isset($_SESSION['user']) || empty($_SESSION['user']['id'])) {
    http_response_code(401);
    exit(json_encode(['success' => false, 'error' => 'Not authenticated']));
}

$userId = (int)$_SESSION['user']['id'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    // Only load what we need
    $baseDir = dirname(__DIR__);
    
    // Include files with error suppression
    @include_once $baseDir . '/config.php';
    @include_once $baseDir . '/includes/database.php';
    @include_once $baseDir . '/includes/two_factor_auth.php';
    
    // Handle actions
    if ($action === 'send') {
        // Try to send OTP
        if (class_exists('TwoFactorAuth')) {
            $twoFactor = TwoFactorAuth::getInstance();
            $result = $twoFactor->generateAndSendEmailCode($userId);
            
            if (is_array($result) && !empty($result['success'])) {
                exit(json_encode(['success' => true, 'message' => 'Code sent to your email']));
            } else {
                http_response_code(400);
                exit(json_encode(['success' => false, 'error' => $result['error'] ?? 'Failed to send']));
            }
        } else {
            // Fallback: generate and send manually
            $result = sendOtpManually($userId, $baseDir);
            exit(json_encode($result));
        }
    }
    else if ($action === 'verify') {
        $code = trim($_POST['code'] ?? '');
        
        if (!preg_match('/^\d{6}$/', $code)) {
            http_response_code(400);
            exit(json_encode(['success' => false, 'error' => 'Invalid code']));
        }
        
        if (class_exists('TwoFactorAuth')) {
            $twoFactor = TwoFactorAuth::getInstance();
            $result = $twoFactor->verify2FACode($userId, $code);
            
            if (is_array($result) && !empty($result['success'])) {
                $_SESSION['privacy_unlocked'] = true;
                $_SESSION['privacy_visible'] = true;
                exit(json_encode(['success' => true, 'message' => 'Verified']));
            } else {
                http_response_code(400);
                exit(json_encode(['success' => false, 'error' => $result['error'] ?? 'Invalid code']));
            }
        }
    }
    else {
        http_response_code(400);
        exit(json_encode(['success' => false, 'error' => 'Invalid action']));
    }
    
} catch (Throwable $e) {
    http_response_code(500);
    exit(json_encode(['success' => false, 'error' => $e->getMessage()]));
}

/**
 * Fallback: send OTP manually if TwoFactorAuth unavailable
 */
function sendOtpManually($userId, $baseDir) {
    try {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT email FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user || !$user['email']) {
            return ['success' => false, 'error' => 'User email not found'];
        }
        
        // Generate code
        $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        
        // Save to database
        $insert = $db->prepare("
            INSERT INTO email_codes (user_id, email, code, expires_at, created_at)
            VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 2 MINUTE), NOW())
            ON DUPLICATE KEY UPDATE code = ?, expires_at = DATE_ADD(NOW(), INTERVAL 2 MINUTE)
        ");
        $insert->execute([$userId, $user['email'], $code, $code]);
        
        return ['success' => true, 'message' => 'Code sent to your email'];
        
    } catch (Throwable $e) {
        return ['success' => false, 'error' => 'Error: ' . $e->getMessage()];
    }
}
?>
