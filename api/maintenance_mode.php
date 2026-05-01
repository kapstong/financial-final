<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

require_once '../includes/auth.php';
require_once '../includes/maintenance_mode.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if (!isset($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$auth = new Auth();
ensure_api_auth($method, [
    'GET' => 'settings.view',
    'POST' => 'settings.edit'
]);

if ($method === 'GET') {
    echo json_encode([
        'success' => true,
        'state' => getMaintenanceModeState()
    ]);
    exit;
}

if ($method === 'POST') {
    $payload = json_decode(file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        $payload = $_POST;
    }

    $enabled = !empty($payload['enabled']);
    $message = trim((string)($payload['message'] ?? ''));
    $state = setMaintenanceModeState($enabled, $message, (int)($_SESSION['user']['id'] ?? 0));

    Logger::getInstance()->logUserAction(
        $enabled ? 'enabled_maintenance_mode' : 'disabled_maintenance_mode',
        'settings',
        null,
        null,
        $state
    );

    echo json_encode([
        'success' => true,
        'state' => $state
    ]);
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'error' => 'Method not allowed']);

