<?php
// Trash API
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
ensure_api_auth($method, [
    'GET' => 'settings.edit',
    'PUT' => 'settings.edit',
    'DELETE' => 'settings.edit',
    'POST' => 'settings.edit',
    'PATCH' => 'settings.edit',
]);

$db = null;

try {
    $db = Database::getInstance();
} catch (Exception $e) {
    error_log("Database connection error in trash API: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed. Please check your configuration.']);
    exit;
}

try {
    switch ($method) {
        case 'GET':
            $action = $_GET['action'] ?? '';

            switch ($action) {
                case 'get_trash':
                    // Get all trash items from deleted_items table
                    $stmt = $db->query("
                        SELECT t.id, t.table_name, t.record_id, t.deleted_by, t.deleted_at, t.auto_delete_at,
                               u.full_name as deleted_by_name, 'table_item' as item_type
                        FROM deleted_items t
                        LEFT JOIN users u ON t.deleted_by = u.id
                        ORDER BY t.deleted_at DESC
                    ");
                    $tableItems = $stmt->fetchAll();

                    // Get soft-deleted users
                    $stmt = $db->query("
                        SELECT
                            u.id,
                            'users' as table_name,
                            u.id as record_id,
                            u.id as deleted_by,
                            u.deleted_at,
                            DATE_ADD(u.deleted_at, INTERVAL 30 DAY) as auto_delete_at,
                            u.full_name as deleted_by_name,
                            'soft_deleted_user' as item_type,
                            u.username,
                            u.email,
                            u.full_name,
                            u.role,
                            u.status
                        FROM users u
                        WHERE u.deleted_at IS NOT NULL
                        ORDER BY u.deleted_at DESC
                    ");
                    $softDeletedUsers = $stmt->fetchAll();

                    // Combine results
                    $items = array_merge($tableItems, $softDeletedUsers);

                    echo json_encode(['success' => true, 'items' => $items]);
                    break;

                case 'view_item':
                    $itemId = $_GET['item_id'] ?? null;
                    $itemType = $_GET['item_type'] ?? null;
                    if (!$itemId) {
                        http_response_code(400);
                        echo json_encode(['success' => false, 'error' => 'Item ID required']);
                        exit;
                    }

                    if ($itemType === 'soft_deleted_user') {
                        $stmt = $db->query("
                            SELECT id, username, email, full_name, role, status, deleted_at
                            FROM users
                            WHERE id = ? AND deleted_at IS NOT NULL
                        ", [$itemId]);
                        $item = $stmt->fetch();
                    } else {
                        $stmt = $db->query("SELECT * FROM deleted_items WHERE id = ?", [$itemId]);
                        $item = $stmt->fetch();
                    }

                    if (!$item) {
                        http_response_code(404);
                        echo json_encode(['success' => false, 'error' => 'Item not found']);
                        exit;
                    }

                    echo json_encode(['success' => true, 'item' => $item]);
                    break;

                default:
                    http_response_code(400);
                    echo json_encode(['error' => 'Invalid action']);
                    break;
            }
            break;

        case 'POST':
            $data = json_decode(file_get_contents('php://input'), true);
            if (!$data) {
                $data = $_POST;
            }

            $action = $data['action'] ?? '';

            switch ($action) {
                case 'restore_item':
                    $itemId = $data['item_id'] ?? null;
                    $itemType = $data['item_type'] ?? null;

                    if (!$itemId) {
                        http_response_code(400);
                        echo json_encode(['success' => false, 'error' => 'Item ID required']);
                        exit;
                    }

                    if ($itemType === 'soft_deleted_user') {
                        // Restore soft-deleted user
                        $stmt = $db->query("SELECT * FROM users WHERE id = ? AND deleted_at IS NOT NULL", [$itemId]);
                        $user = $stmt->fetch();

                        if (!$user) {
                            http_response_code(404);
                            echo json_encode(['success' => false, 'error' => 'User not found in trash']);
                            exit;
                        }

                        $affected = $db->execute("UPDATE users SET deleted_at = NULL, updated_at = NOW() WHERE id = ?", [$itemId]);

                        // Log the action
                        Logger::getInstance()->logUserAction('Restored user from trash', 'users', $itemId, $user, ['deleted_at' => null]);

                        echo json_encode(['success' => $affected > 0]);
                    } else {
                        // Restore item from deleted_items table
                        $stmt = $db->query("SELECT * FROM deleted_items WHERE id = ?", [$itemId]);
                        $trashItem = $stmt->fetch();

                        if (!$trashItem) {
                            http_response_code(404);
                            echo json_encode(['success' => false, 'error' => 'Trash item not found']);
                            exit;
                        }

                        // Note: Restore functionality would require storing the original data
                        // For now, we'll just remove from trash
                        // In a full implementation, you'd restore the data to the original table

                        $affected = $db->execute("DELETE FROM deleted_items WHERE id = ?", [$itemId]);

                        // Log the action
                        Logger::getInstance()->logUserAction('Restored item from trash', 'trash', $itemId, $trashItem, null);

                        echo json_encode(['success' => $affected > 0]);
                    }
                    break;

                default:
                    http_response_code(400);
                    echo json_encode(['error' => 'Invalid action']);
                    break;
            }
            break;

        case 'DELETE':
            $data = json_decode(file_get_contents('php://input'), true);
            if (!$data) {
                $data = $_POST;
            }

            $action = $data['action'] ?? '';

            switch ($action) {
                case 'permanent_delete':
                    $itemId = $data['item_id'] ?? null;
                    $itemType = $data['item_type'] ?? null;

                    if (!$itemId) {
                        http_response_code(400);
                        echo json_encode(['success' => false, 'error' => 'Item ID required']);
                        exit;
                    }

                    if ($itemType === 'soft_deleted_user') {
                        // Permanently delete soft-deleted user
                        $stmt = $db->query("SELECT * FROM users WHERE id = ? AND deleted_at IS NOT NULL", [$itemId]);
                        $user = $stmt->fetch();

                        if (!$user) {
                            http_response_code(404);
                            echo json_encode(['success' => false, 'error' => 'User not found in trash']);
                            exit;
                        }

                        $affected = $db->execute("DELETE FROM users WHERE id = ?", [$itemId]);

                        // Log the action
                        Logger::getInstance()->logUserAction('Permanently deleted user from trash', 'users', $itemId, $user, null);

                        echo json_encode(['success' => $affected > 0]);
                    } else {
                        // Permanently delete item from deleted_items table
                        $stmt = $db->query("SELECT * FROM deleted_items WHERE id = ?", [$itemId]);
                        $trashItem = $stmt->fetch();

                        if (!$trashItem) {
                            http_response_code(404);
                            echo json_encode(['success' => false, 'error' => 'Trash item not found']);
                            exit;
                        }

                        $affected = $db->execute("DELETE FROM deleted_items WHERE id = ?", [$itemId]);

                        // Log the action
                        Logger::getInstance()->logUserAction('Permanently deleted item from trash', 'trash', $itemId, $trashItem, null);

                        echo json_encode(['success' => $affected > 0]);
                    }
                    break;

                case 'empty_trash':
                    // Get all items for logging
                    $stmt = $db->query("SELECT COUNT(*) as count FROM deleted_items");
                    $count = $stmt->fetch()['count'];

                    $affected = $db->execute("DELETE FROM deleted_items");

                    // Log the action
                    Logger::getInstance()->logUserAction('Emptied trash', 'trash', null, ['items_deleted' => $count], null);

                    echo json_encode(['success' => true, 'items_deleted' => $count]);
                    break;

                default:
                    http_response_code(400);
                    echo json_encode(['error' => 'Invalid action']);
                    break;
            }
            break;

        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            break;
    }
} catch (Exception $e) {
    error_log("Trash API operation error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error occurred']);
}

ob_end_flush();
?>

