<?php
/**
 * ATIERA Financial Management System - Integrations API
 * Handles external API integration operations
 */

// Set error handling
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    if (!(error_reporting() & $errno)) {
        return false;
    }

    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
});

register_shutdown_function(function() {
    $error = error_get_last();
    $fatalErrorTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];

    if ($error !== null && in_array($error['type'] ?? null, $fatalErrorTypes, true)) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=UTF-8');
            http_response_code(500);
        }

        echo json_encode([
            'success' => false,
            'error' => 'Fatal error: ' . $error['message'],
            'debug' => ['file' => $error['file'], 'line' => $error['line']]
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }
});

header('Content-Type: application/json; charset=UTF-8');
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/api_integrations.php';

$method = $_SERVER['REQUEST_METHOD'];
// Determine the action to align permission checks (execute/test should be view-level)
$action = ($method === 'POST') ? ($_POST['action'] ?? ($_GET['action'] ?? '')) : ($_GET['action'] ?? '');
$authMethod = ($method === 'POST' && in_array($action, ['execute', 'test'], true)) ? 'GET' : $method;
$roleName = strtolower((string) ($_SESSION['user']['role_name'] ?? ($_SESSION['user']['role'] ?? '')));
$auth = new Auth();
if (!($roleName === 'staff' && in_array($action, ['execute', 'test'], true))) {
    ensure_api_auth($authMethod, [
        'GET' => 'integrations.view',
        'POST' => 'integrations.view',
        'PUT' => 'integrations.manage',
        'DELETE' => 'integrations.manage',
        'PATCH' => 'integrations.manage',
    ]);
}

function sendIntegrationResponse($payload, $status = 200) {
    http_response_code($status);
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

    if ($json === false) {
        http_response_code(500);
        $json = json_encode([
            'success' => false,
            'error' => 'Failed to encode integration response'
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    echo $json;
    exit;
}

function buildIntegrationParams(array $source) {
    $params = $source;
    unset($params['action'], $params['integration_name'], $params['action_name'], $params['params']);

    if (isset($source['params'])) {
        $decoded = json_decode($source['params'], true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $params = array_merge($decoded, $params);
        }
    }

    return $params;
}

try {
    switch ($method) {
        case 'GET':
            $action = $_GET['action'] ?? '';

            switch ($action) {
                case 'execute':
                    $integrationName = $_GET['integration_name'] ?? '';
                    $actionName = $_GET['action_name'] ?? '';

                    if ($integrationName === '' || $actionName === '') {
                        sendIntegrationResponse(['success' => false, 'error' => 'Missing integration_name or action_name'], 400);
                    }

                    $manager = APIIntegrationManager::getInstance();
                    $params = buildIntegrationParams($_GET);
                    $result = $manager->executeIntegrationAction($integrationName, $actionName, $params);
                    sendIntegrationResponse(['success' => true, 'result' => $result]);
                    break;
                default:
                    sendIntegrationResponse(['error' => 'Invalid action'], 400);
            }
            break;

        case 'POST':
            $action = $_POST['action'] ?? ($_GET['action'] ?? '');

            switch ($action) {
                case 'test':
                    $integrationName = $_POST['integration_name'] ?? '';
                    if ($integrationName === '') {
                        sendIntegrationResponse(['success' => false, 'error' => 'Missing integration_name'], 400);
                    }

                    $manager = APIIntegrationManager::getInstance();
                    $result = $manager->testIntegration($integrationName);
                    sendIntegrationResponse($result);
                    break;
                case 'execute':
                    $integrationName = $_POST['integration_name'] ?? ($_GET['integration_name'] ?? '');
                    $actionName = $_POST['action_name'] ?? ($_GET['action_name'] ?? '');

                    if ($integrationName === '' || $actionName === '') {
                        sendIntegrationResponse(['success' => false, 'error' => 'Missing integration_name or action_name'], 400);
                    }

                    $manager = APIIntegrationManager::getInstance();
                    $params = buildIntegrationParams($_POST);
                    $result = $manager->executeIntegrationAction($integrationName, $actionName, $params);
                    sendIntegrationResponse(['success' => true, 'result' => $result]);
                    break;

                default:
                    sendIntegrationResponse(['error' => 'Invalid action'], 400);
            }
            break;

        case 'PUT':
        case 'DELETE':
            sendIntegrationResponse(['error' => 'Invalid action'], 400);
            break;

        default:
            sendIntegrationResponse(['error' => 'Method not allowed'], 405);
            break;
    }
} catch (Throwable $e) {
    Logger::getInstance()->logDatabaseError('Integration API operation', $e->getMessage());
    $status = 500;
    if (stripos($e->getMessage(), 'HTTP ') !== false || stripos($e->getMessage(), 'Connection error') !== false) {
        $status = 502;
    }
    sendIntegrationResponse(['success' => false, 'error' => $e->getMessage()], $status);
}

/**
 * Generate configuration form HTML
 */
function generateConfigForm($metadata, $currentConfig = null) {
    $html = '<div class="row g-3">';

    foreach ($metadata['required_config'] as $field) {
        $fieldType = getFieldType($field);
        $fieldLabel = ucfirst(str_replace(['_', '-'], ' ', $field));
        $fieldValue = $currentConfig[$field] ?? '';
        $fieldPlaceholder = getFieldPlaceholder($field);

        $html .= '<div class="col-md-6">';
        $html .= '<label for="' . $field . '" class="form-label">' . $fieldLabel . ' *</label>';

        if ($fieldType === 'textarea') {
            $html .= '<textarea class="form-control" id="' . $field . '" name="config[' . $field . ']" rows="3" placeholder="' . $fieldPlaceholder . '" required>' . htmlspecialchars($fieldValue) . '</textarea>';
        } elseif ($fieldType === 'select') {
            $html .= '<select class="form-control" id="' . $field . '" name="config[' . $field . ']" required>';
            $html .= '<option value="">Select ' . $fieldLabel . '</option>';
            $options = getFieldOptions($field);
            foreach ($options as $value => $label) {
                $selected = ($fieldValue === $value) ? 'selected' : '';
                $html .= '<option value="' . $value . '" ' . $selected . '>' . $label . '</option>';
            }
            $html .= '</select>';
        } else {
            $html .= '<input type="' . $fieldType . '" class="form-control" id="' . $field . '" name="config[' . $field . ']" value="' . htmlspecialchars($fieldValue) . '" placeholder="' . $fieldPlaceholder . '" required>';
        }

        $html .= '</div>';
    }

    $html .= '</div>';
    return $html;
}

/**
 * Get field type based on field name
 */
function getFieldType($field) {
    $fieldTypes = [
        'webhook_secret' => 'password',
        'api_key' => 'password',
        'auth_token' => 'password',
        'access_token' => 'password',
        'client_secret' => 'password',
        'refresh_token' => 'password',
        'phone_number' => 'tel',
        'email' => 'email',
        'url' => 'url',
        'webhook_url' => 'url',
        'server_prefix' => 'text',
        'tenant_id' => 'text',
        'company_id' => 'text'
    ];

    return $fieldTypes[$field] ?? 'text';
}

/**
 * Get field placeholder
 */
function getFieldPlaceholder($field) {
    $placeholders = [
        'api_key' => 'sk_test_... or sg_...',
        'auth_token' => 'Your authentication token',
        'access_token' => 'Your access token',
        'client_id' => 'Your client ID',
        'client_secret' => 'Your client secret',
        'webhook_secret' => 'whsec_...',
        'webhook_url' => 'https://your-domain.com/webhook',
        'phone_number' => '+1234567890',
        'email' => 'your-email@example.com',
        'server_prefix' => 'us1 or eu1',
        'tenant_id' => 'Your tenant ID',
        'company_id' => 'Your company ID'
    ];

    return $placeholders[$field] ?? 'Enter ' . ucfirst(str_replace(['_', '-'], ' ', $field));
}

/**
 * Get field options for select fields
 */
function getFieldOptions($field) {
    if ($field === 'server_prefix') {
        return [
            'us1' => 'US Server 1',
            'us2' => 'US Server 2',
            'us3' => 'US Server 3',
            'eu1' => 'EU Server 1',
            'au1' => 'AU Server 1'
        ];
    }

    return [];
}

/**
 * Get integration logs
 */
function getIntegrationLogs($limit = 50) {
    try {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            SELECT * FROM integration_logs
            ORDER BY created_at DESC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        Logger::getInstance()->error("Failed to get integration logs: " . $e->getMessage());
        return [];
    }
}
?>
