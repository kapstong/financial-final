<?php
// Users API
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Start output buffering to catch any unwanted output
ob_start();

// Suppress any HTML output from errors
ini_set('display_errors', 0);
error_reporting(E_ALL);

// Set up error handler to catch and output errors as JSON
set_error_handler(function($errno, $errstr, $errfile, $errline) {
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
require_once '../includes/auth.php';
require_once '../includes/database.php';
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to load required files: ' . $e->getMessage()]);
    ob_end_flush();
    exit(1);
}
require_once '../includes/logger.php';

$method = $_SERVER['REQUEST_METHOD'];
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized - Session not found']);
    ob_end_flush();
    exit;
}

$auth = new Auth();
$auth = new Auth();
ensure_api_auth($method, [
    'GET' => 'users.view',
    'PUT' => 'users.edit',
    'DELETE' => 'users.delete',
    'POST' => 'users.create',
    'PATCH' => 'users.edit',
]);
$permissionMap = [
    'GET' => 'users.view',
    'POST' => 'users.create',
    'PUT' => 'users.edit',
    'PATCH' => 'users.edit',
    'DELETE' => 'users.delete'
];
$requiredPermission = $permissionMap[$method] ?? null;
if ($requiredPermission && !$auth->hasRole('admin') && !$auth->hasRole('super_admin') && !$auth->hasPermission($requiredPermission)) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden - Insufficient privileges']);
    exit;
}
?>

<?php
$db = null;

try {
    $db = Database::getInstance();
} catch (Exception $e) {
    error_log("Database connection error in users API: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed. Please check your configuration.']);
    exit;
}

function sanitizeUser($user) {
    if (!$user || !is_array($user)) {
        return $user;
    }
    $blockedKeys = [
        'password',
        'password_hash',
        'password_reset_token',
        'reset_token',
        'reset_token_expires'
    ];
    foreach ($blockedKeys as $key) {
        if (array_key_exists($key, $user)) {
            unset($user[$key]);
        }
    }
    return $user;
}

try {
    switch ($method) {
        case 'GET':
            $userId = $_GET['user_id'] ?? $_GET['id'] ?? null;
            if ($userId) {
                // Get single user (exclude soft-deleted)
                $stmt = $db->query(
                    "SELECT id, username, email, full_name, role, status, last_login, created_at, department, phone
                     FROM users WHERE id = ? AND deleted_at IS NULL",
                    [$userId]
                );
                $user = $stmt->fetch();
                if ($user) {
                    echo json_encode(['success' => true, 'user' => sanitizeUser($user)]);
                } else {
                    echo json_encode(['success' => false, 'error' => 'User not found']);
                }
            } else {
                // Get all users with optional filters (exclude soft-deleted users)
                $where = ["deleted_at IS NULL"];
                $params = [];

                if (isset($_GET['status'])) {
                    $where[] = "status = ?";
                    $params[] = $_GET['status'];
                }

                if (isset($_GET['role'])) {
                    $where[] = "role = ?";
                    $params[] = $_GET['role'];
                }

                $whereClause = "WHERE " . implode(" AND ", $where);

                $users = $db->select(
                    "SELECT id, username, email, full_name, role, status, last_login, created_at, department, phone
                     FROM users {$whereClause}
                     ORDER BY created_at DESC"
                );
                $users = array_map('sanitizeUser', $users);
                echo json_encode($users);
            }
            break;

        case 'POST':
            // Create new user
            $data = json_decode(file_get_contents('php://input'), true);

            if (!$data) {
                // Handle form data
                $data = $_POST;
            }

            // Validate required fields
            if (empty($data['username']) || empty($data['password']) || empty($data['email']) || empty($data['full_name'])) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Missing required fields: username, password, email, full_name']);
                exit;
            }

            // Check if username already exists
            $existing = $db->select("SELECT id FROM users WHERE username = ?", [$data['username']]);
            if (!empty($existing)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Username already exists']);
                exit;
            }

            // Check if email already exists
            $existing = $db->select("SELECT id FROM users WHERE email = ?", [$data['email']]);
            if (!empty($existing)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Email already exists']);
                exit;
            }

            $userId = $db->insert(
                "INSERT INTO users (username, password_hash, email, full_name, role, status, department, phone)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $data['username'],
                    password_hash($data['password'], PASSWORD_DEFAULT),
                    $data['email'],
                    $data['full_name'],
                    $data['role'] ?? 'staff',
                    $data['status'] ?? 'active',
                    $data['department'] ?? null,
                    $data['phone'] ?? null
                ]
            );

            // Log the action (disabled temporarily)
            // Logger::getInstance()->logUserAction('Created user', 'users', $userId, null, $data);

            echo json_encode(['success' => true, 'id' => $userId]);
            break;

        case 'PUT':
            // Update user
            if (!isset($_GET['id'])) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'User ID required']);
                exit;
            }

            $data = json_decode(file_get_contents('php://input'), true);
            if (!$data) {
                $data = $_POST;
            }

            // Get old values for audit
            $oldUser = $db->select("SELECT * FROM users WHERE id = ?", [$_GET['id']]);
            $oldValues = $oldUser[0] ?? null;

            if (!$oldValues) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'User not found']);
                exit;
            }

            $fields = [];
            $params = [];

            if (isset($data['email'])) {
                // Check if email is already used by another user
                $existing = $db->select("SELECT id FROM users WHERE email = ? AND id != ?", [$data['email'], $_GET['id']]);
                if (!empty($existing)) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'error' => 'Email already exists']);
                    exit;
                }
                $fields[] = "email = ?";
                $params[] = $data['email'];
            }
            if (isset($data['full_name'])) {
                $fields[] = "full_name = ?";
                $params[] = $data['full_name'];
            }
            if (isset($data['role'])) {
                $fields[] = "role = ?";
                $params[] = $data['role'];
            }
            if (isset($data['status'])) {
                $fields[] = "status = ?";
                $params[] = $data['status'];
            }
            if (isset($data['department'])) {
                $fields[] = "department = ?";
                $params[] = $data['department'];
            }
            if (isset($data['phone'])) {
                $fields[] = "phone = ?";
                $params[] = $data['phone'];
            }
            if (isset($data['password']) && !empty($data['password'])) {
                $fields[] = "password_hash = ?";
                $params[] = password_hash($data['password'], PASSWORD_DEFAULT);
            }

            if (empty($fields)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'No fields to update']);
                exit;
            }

            $params[] = $_GET['id'];
            $sql = "UPDATE users SET " . implode(', ', $fields) . " WHERE id = ?";

            $affected = $db->execute($sql, $params);

            // Log the action (disabled temporarily)
            // Logger::getInstance()->logUserAction('Updated user', 'users', $_GET['id'], $oldValues, $data);

            // Check if update was successful (affected > 0) or if no changes were made (affected = 0 but no error)
            if ($affected >= 0) {
                // affected = 0 means no changes were made (data was already correct)
                // affected > 0 means rows were updated
                // affected = -1 or other negative values would indicate error
                echo json_encode(['success' => true, 'message' => 'User updated successfully']);
            } else {
                echo json_encode(['success' => false, 'error' => 'Failed to update user']);
            }
            break;

        case 'DELETE':
            // Handle soft delete via POST data (for compatibility with frontend)
            $data = json_decode(file_get_contents('php://input'), true);
            if (!$data) {
                $data = $_POST;
            }

            $userId = $data['user_id'] ?? $_GET['id'] ?? null;

            if (!$userId) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'User ID required']);
                exit;
            }

            // Prevent deletion of current user
            if ($userId == $_SESSION['user']['id']) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Cannot delete your own account']);
                exit;
            }

            // Get old values for audit
            $oldUser = $db->select("SELECT * FROM users WHERE id = ?", [$userId]);
            $oldValues = $oldUser[0] ?? null;

            if (!$oldValues) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'User not found']);
                exit;
            }

            // Soft delete: set deleted_at timestamp (keep original status)
            $affected = $db->execute(
                "UPDATE users SET deleted_at = NOW(), updated_at = NOW() WHERE id = ?",
                [$userId]
            );

            // Log the action
            Logger::getInstance()->logUserAction('Soft deleted user', 'users', $userId, $oldValues, ['deleted_at' => date('Y-m-d H:i:s')]);

            echo json_encode(['success' => $affected > 0]);
            break;

        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            break;
    }
} catch (Exception $e) {
    error_log("User API operation error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error occurred']);
}

ob_end_flush();
?>

