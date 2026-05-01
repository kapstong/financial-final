<?php
/**
 * OTP (One-Time Password) API - Clean implementation
 */

// Set response type FIRST - before anything else
header('Content-Type: application/json; charset=utf-8');

// No output buffering - keep it simple
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check auth immediately
if (!isset($_SESSION['user']) || empty($_SESSION['user']['id'])) {
    http_response_code(401);
    die(json_encode(['success' => false, 'error' => 'Unauthorized']));
}

$userId = (int)$_SESSION['user']['id'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// Load dependencies
try {
    require_once __DIR__ . '/../config.php';
    require_once __DIR__ . '/../includes/database.php';
    require_once __DIR__ . '/../includes/two_factor_auth.php';
} catch (Exception $e) {
    http_response_code(500);
    die(json_encode(['success' => false, 'error' => 'Failed to load dependencies: ' . $e->getMessage()]));
}

// Route action
try {
    if ($action === 'send') {
        $twoFactor = TwoFactorAuth::getInstance();
        $result = $twoFactor->generateAndSendEmailCode($userId);
        
        if ($result['success']) {
            echo json_encode(['success' => true, 'message' => 'OTP sent to your email']);
        } else {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => $result['error'] ?? 'Failed to send OTP']);
        }
    } 
    elseif ($action === 'verify') {
        $code = $_POST['code'] ?? '';
        
        if (!preg_match('/^\d{6}$/', (string)$code)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid code format']);
            exit;
        }
        
        $twoFactor = TwoFactorAuth::getInstance();
        $result = $twoFactor->verify2FACode($userId, $code);
        
        if (!empty($result['success']) && $result['success'] === true) {
            $_SESSION['privacy_unlocked'] = true;
            $_SESSION['privacy_unlocked_time'] = time();
            $_SESSION['privacy_visible'] = true;
            echo json_encode(['success' => true, 'message' => 'OTP verified']);
        } else {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => $result['error'] ?? 'Invalid OTP']);
        }
    }
    else {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
