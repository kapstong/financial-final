<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/privacy.php';
require_once __DIR__ . '/../includes/mailer.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['user']['id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method === 'GET') {
    echo json_encode([
        'success' => true,
        'privacy_mode' => privacyModeEnabled(),
        'visible' => privacyIsVisible(),
        'email' => maskPrivacyEmail($_SESSION['user']['email'] ?? '')
    ]);
    exit;
}

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

if (!csrf_verify_request()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

$action = $input['action'] ?? '';

try {
    if ($action === 'enable') {
        privacySetMode(true);
        clearPrivacyOtp();
        echo json_encode(['success' => true, 'privacy_mode' => true, 'visible' => false]);
        exit;
    }

    if ($action === 'send_otp') {
        $email = trim((string) ($_SESSION['user']['email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'No valid email address is attached to this account.']);
            exit;
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $_SESSION['privacy_otp_hash'] = password_hash($code, PASSWORD_DEFAULT);
        $_SESSION['privacy_otp_expires_at'] = time() + 600;
        $_SESSION['privacy_otp_attempts'] = 0;

        sendPrivacyOtp($email, $code);

        echo json_encode([
            'success' => true,
            'message' => 'Verification code sent.',
            'email' => maskPrivacyEmail($email)
        ]);
        exit;
    }

    if ($action === 'verify_otp') {
        $code = preg_replace('/\D/', '', (string) ($input['code'] ?? ''));
        $hash = $_SESSION['privacy_otp_hash'] ?? '';
        $expiresAt = (int) ($_SESSION['privacy_otp_expires_at'] ?? 0);
        $attempts = (int) ($_SESSION['privacy_otp_attempts'] ?? 0);

        if (!preg_match('/^\d{6}$/', $code)) {
            http_response_code(422);
            echo json_encode(['success' => false, 'error' => 'Enter the 6-digit verification code.']);
            exit;
        }

        if (!$hash || !$expiresAt || time() > $expiresAt) {
            clearPrivacyOtp();
            http_response_code(422);
            echo json_encode(['success' => false, 'error' => 'Verification code expired. Send a new code.']);
            exit;
        }

        if ($attempts >= 5) {
            clearPrivacyOtp();
            http_response_code(429);
            echo json_encode(['success' => false, 'error' => 'Too many attempts. Send a new code.']);
            exit;
        }

        $_SESSION['privacy_otp_attempts'] = $attempts + 1;

        if (!password_verify($code, $hash)) {
            http_response_code(422);
            echo json_encode(['success' => false, 'error' => 'Invalid verification code.']);
            exit;
        }

        privacySetMode(false);
        clearPrivacyOtp();

        echo json_encode(['success' => true, 'privacy_mode' => false, 'visible' => true]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid action']);
} catch (Throwable $e) {
    error_log('Privacy OTP error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Unable to process privacy verification.']);
}

function sendPrivacyOtp($email, $code)
{
    $safeCode = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');
    $html = '<p>Your ATIERA privacy verification code is:</p>' .
        '<p style="font-size:24px;font-weight:bold;letter-spacing:4px;">' . $safeCode . '</p>' .
        '<p>This code expires in 10 minutes. If you did not request this, keep privacy mode enabled.</p>';
    $text = "Your ATIERA privacy verification code is: {$code}\n\nThis code expires in 10 minutes.";

    atiera_send_email($email, 'ATIERA Privacy Mode Verification Code', $html, $text);
}

function maskPrivacyEmail($email)
{
    $email = (string) $email;
    if ($email === '' || strpos($email, '@') === false) {
        return '';
    }

    [$name, $domain] = explode('@', $email, 2);
    $maskedName = substr($name, 0, 1) . str_repeat('*', max(1, strlen($name) - 2)) . substr($name, -1);

    return $maskedName . '@' . $domain;
}

function clearPrivacyOtp()
{
    unset($_SESSION['privacy_otp_hash'], $_SESSION['privacy_otp_expires_at'], $_SESSION['privacy_otp_attempts']);
}
?>
