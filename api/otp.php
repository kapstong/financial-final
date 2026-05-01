<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/mailer.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

try {
    if (empty($_SESSION['user']['id'])) {
        respondJson(401, ['success' => false, 'error' => 'Not logged in']);
    }

    $db = Database::getInstance()->getConnection();
    ensureEmailCodesTable($db);

    $userId = (int) $_SESSION['user']['id'];
    $action = strtolower((string) ($_GET['action'] ?? $_POST['action'] ?? ''));

    if ($action === 'send' || $action === 'send_code') {
        handleSend($db, $userId);
    }

    if ($action === 'verify' || $action === 'verify_code') {
        handleVerify($db, $userId);
    }

    respondJson(400, ['success' => false, 'error' => 'No action specified']);
} catch (Throwable $e) {
    error_log('[otp.php] ' . $e->getMessage());

    $message = 'Server error';
    if (class_exists('Config') && Config::isDevelopment()) {
        $message = $e->getMessage();
    }

    respondJson(500, ['success' => false, 'error' => $message]);
}

function handleSend(PDO $db, int $userId): void
{
    $stmt = $db->prepare('SELECT id, email, first_name FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || empty($user['email'])) {
        respondJson(400, ['success' => false, 'error' => 'User email not found']);
    }

    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

    $insert = $db->prepare(
        'INSERT INTO email_codes (user_id, email, code, expires_at, created_at)
         VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 2 MINUTE), NOW())
         ON DUPLICATE KEY UPDATE code = VALUES(code), expires_at = VALUES(expires_at), created_at = VALUES(created_at)'
    );
    $insert->execute([$userId, $user['email'], $code]);

    $mailer = Mailer::getInstance();
    $sent = $mailer->sendVerificationCode($user['email'], $code, $user['first_name'] ?? '');

    if (!$sent) {
        $delete = $db->prepare('DELETE FROM email_codes WHERE user_id = ? AND email = ?');
        $delete->execute([$userId, $user['email']]);

        $error = trim((string) $mailer->getLastError());
        if ($error === '') {
            $error = 'Failed to send verification email';
        }

        respondJson(500, ['success' => false, 'error' => $error]);
    }

    respondJson(200, ['success' => true, 'message' => 'Code sent to your email']);
}

function handleVerify(PDO $db, int $userId): void
{
    $code = trim((string) ($_POST['code'] ?? ''));

    if (!preg_match('/^\d{6}$/', $code)) {
        respondJson(400, ['success' => false, 'error' => 'Invalid code format']);
    }

    $stmt = $db->prepare(
        'SELECT id
         FROM email_codes
         WHERE user_id = ? AND code = ? AND expires_at > NOW()
         ORDER BY created_at DESC
         LIMIT 1'
    );
    $stmt->execute([$userId, $code]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        respondJson(400, ['success' => false, 'error' => 'Invalid or expired code']);
    }

    $delete = $db->prepare('DELETE FROM email_codes WHERE id = ?');
    $delete->execute([(int) $row['id']]);

    $_SESSION['privacy_unlocked'] = true;
    $_SESSION['privacy_unlocked_time'] = time();
    $_SESSION['privacy_visible'] = true;

    respondJson(200, ['success' => true, 'message' => 'Code verified']);
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

    $db->exec('DELETE FROM email_codes WHERE expires_at <= NOW()');
    $initialized = true;
}

function respondJson(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}
