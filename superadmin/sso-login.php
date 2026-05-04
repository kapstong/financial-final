<?php
require_once '../config.php';
require_once '../includes/cache.php';
require_once '../includes/device_detector.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$token = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = trim((string) ($_POST['token'] ?? ''));
} elseif (!empty($_GET['token']) && Config::get('security.allow_sso_get_tokens', false)) {
    $token = trim((string) $_GET['token']);
} elseif (!empty($_GET['token'])) {
    http_response_code(400);
    echo 'SSO tokens must be submitted via POST.';
    exit;
}

if ($token === '') {
    http_response_code(400);
    echo 'Token missing';
    exit;
}

$db = Database::getInstance()->getConnection();

// Normalize token for URL/base64 variants.
$token = rawurldecode($token);
if (preg_match('/(?:^|[?&])token=([^&]+)/', $token, $matches)) {
    $token = $matches[1];
}
$token = str_replace(' ', '+', $token);

$decoded = base64_decode($token, true);
if (!$decoded) {
    $token = strtr($token, '-_', '+/');
    $padding = strlen($token) % 4;
    if ($padding) {
        $token .= str_repeat('=', 4 - $padding);
    }
    $decoded = base64_decode($token, true);
}

if (!$decoded) {
    http_response_code(400);
    echo 'Invalid token';
    exit;
}

$data = json_decode($decoded, true);
if (!$data || !isset($data['payload'], $data['signature'])) {
    http_response_code(400);
    echo 'Invalid token structure';
    exit;
}

$signature = $data['signature'];
if (is_array($data['payload'])) {
    $payload = $data['payload'];
    $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES);
} elseif (is_string($data['payload'])) {
    $payloadJson = $data['payload'];
    $payload = json_decode($payloadJson, true);
} else {
    http_response_code(400);
    echo 'Invalid payload format';
    exit;
}

if (!$payload) {
    http_response_code(400);
    echo 'Invalid payload';
    exit;
}

$stmt = $db->prepare("
    SELECT secret_key
    FROM department_secrets
    WHERE department = ? AND is_active = 1
    ORDER BY id DESC LIMIT 1
");
$stmt->execute(['FIN1']);
$secretRow = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$secretRow) {
    http_response_code(500);
    echo 'Secret not found';
    exit;
}

$secret = $secretRow['secret_key'];
$expectedSignature = hash_hmac('sha256', $payloadJson, $secret);
if (!hash_equals($expectedSignature, $signature)) {
    http_response_code(401);
    echo 'Invalid or tampered token';
    exit;
}

if (isset($payload['exp']) && is_numeric($payload['exp']) && (int) $payload['exp'] > 0) {
    $exp = (int) $payload['exp'];
    if ($exp > 9999999999) {
        $exp = (int) floor($exp / 1000);
    }

    if ($exp < time()) {
        http_response_code(401);
        echo 'Token expired';
        exit;
    }

    $payload['exp'] = $exp;
}

if (($payload['dept'] ?? '') !== 'FIN1') {
    http_response_code(403);
    echo 'Invalid department access';
    exit;
}

$cache = CacheManager::getInstance();
$tokenFingerprint = hash('sha256', $signature . '|' . $payloadJson);
$cacheKey = 'sso_token:' . $tokenFingerprint;
if ($cache->exists($cacheKey)) {
    http_response_code(409);
    echo 'Token already used';
    exit;
}

$ttl = 600;
if (!empty($payload['exp'])) {
    $ttl = max(60, (int) $payload['exp'] - time());
}
$cache->set($cacheKey, 1, $ttl);

if (empty($payload['email'])) {
    http_response_code(400);
    echo 'Email missing';
    exit;
}

$stmt = $db->prepare("
    SELECT id, username, email, first_name, last_name, full_name, role, department, phone, status
    FROM users
    WHERE email = ? AND status = 'active'
    LIMIT 1
");
$stmt->execute([$payload['email']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    http_response_code(403);
    echo 'User not found or inactive';
    exit;
}

$auth = new Auth();
$result = $auth->loginByUserId((int) $user['id']);
if (!$result['success']) {
    http_response_code(403);
    echo 'Unable to establish session';
    exit;
}

$deviceInfo = detect_device_info($_SERVER['HTTP_USER_AGENT'] ?? '');
$devicePayload = [
    'device_label' => 'SSO Login',
    'device_type' => $deviceInfo['device_type'],
    'device_os' => $deviceInfo['os'],
    'device_browser' => $deviceInfo['browser'],
    'device_platform' => null,
    'device_model' => null
];

// Proceed with normal SSO login (2FA enforcement removed)

Logger::getInstance()->logUserAction(
    'SSO Login',
    'login_sessions',
    null,
    null,
    [
        'login_method' => 'sso',
        'device_label' => $devicePayload['device_label'],
        'device_type' => $devicePayload['device_type'],
        'os' => $devicePayload['device_os'],
        'browser' => $devicePayload['device_browser']
    ]
);

$role = strtolower((string) ($result['user']['role_name'] ?? $result['user']['role'] ?? ''));
if ($role === 'super_admin') {
    $target = 'index.php';
} elseif ($role === 'admin') {
    $target = '../admin/index.php';
} else {
    $target = '../staff/index.php';
}

header('Location: ' . $target);
exit;
?>
