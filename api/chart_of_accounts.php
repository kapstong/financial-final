<?php
require_once '../includes/auth.php';
require_once '../includes/database.php';
require_once '../includes/logger.php';

header('Content-Type: application/json');

// Only start session if not already started (handles AJAX calls)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
$auth = new Auth();
ensure_api_auth($method, [
    'GET' => 'chart_of_accounts.view',
    'PUT' => 'chart_of_accounts.edit',
    'DELETE' => 'chart_of_accounts.delete',
    'POST' => 'chart_of_accounts.create',
    'PATCH' => 'chart_of_accounts.edit',
]);

}

// Check if user is logged in
if (!isset($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$db = Database::getInstance();
$method = $_SERVER['REQUEST_METHOD'];
$userId = $_SESSION['user']['id'];

function normalizeChartOfAccountName($value) {
    if (!is_string($value)) {
        return $value;
    }

    return preg_replace('/\s*\(demo\)\s*$/i', '', trim($value));
}

function sanitizeChartOfAccountPayload($data) {
    if (is_array($data)) {
        foreach ($data as $key => $value) {
            if ($key === 'account_name' && is_string($value)) {
                $data[$key] = normalizeChartOfAccountName($value);
            } else {
                $data[$key] = sanitizeChartOfAccountPayload($value);
            }
        }
    }

    return $data;
}

try {
    switch ($method) {
        case 'GET':
            if (isset($_GET['id'])) {
                // Get single account
                $account = $db->select(
                    "SELECT coa.*
                     FROM chart_of_accounts coa
                     WHERE coa.id = ?",
                    [$_GET['id']]
                );

                if (empty($account)) {
                    echo json_encode(['error' => 'Account not found']);
                    exit;
                }

                echo json_encode(sanitizeChartOfAccountPayload($account[0]));
            } else {
                // Get all accounts with optional filtering
                $where = [];
                $params = [];

                if (isset($_GET['type'])) {
                    $where[] = "account_type = ?";
                    $params[] = $_GET['type'];
                }

                if (isset($_GET['category'])) {
                    $where[] = "category = ?";
                    $params[] = $_GET['category'];
                }

                if (isset($_GET['active'])) {
                    $where[] = "is_active = ?";
                    $params[] = $_GET['active'] === 'true' ? 1 : 0;
                }

                $whereClause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

                $accounts = $db->select(
                    "SELECT coa.*
                     FROM chart_of_accounts coa
                     {$whereClause}
                     ORDER BY account_code ASC",
                    $params
                );

                echo json_encode(sanitizeChartOfAccountPayload($accounts));
            }
            break;

        case 'POST':
            // Create new account
            $data = json_decode(file_get_contents('php://input'), true);

            if (!$data) {
                $data = $_POST;
            }
            $data = sanitizeChartOfAccountPayload($data);

            // Validate required fields
            if (empty($data['account_code']) || empty($data['account_name']) || empty($data['account_type'])) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Missing required fields']);
                exit;
            }

            // Validate account code format and uniqueness
            if (!preg_match('/^[0-9]{4,6}$/', $data['account_code'])) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Account code must be 4-6 digits']);
                exit;
            }

            $existing = $db->select("SELECT id FROM chart_of_accounts WHERE account_code = ?", [$data['account_code']]);
            if (!empty($existing)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Account code already exists']);
                exit;
            }

            // Validate account type
            $validTypes = ['asset', 'liability', 'equity', 'revenue', 'expense'];
            if (!in_array($data['account_type'], $validTypes)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Invalid account type']);
                exit;
            }

            $accountId = $db->insert(
                "INSERT INTO chart_of_accounts (account_code, account_name, account_type, category,
                                               description, is_active)
                 VALUES (?, ?, ?, ?, ?, ?)",
                [
                    $data['account_code'],
                    $data['account_name'],
                    $data['account_type'],
                    $data['category'] ?? null,
                    $data['description'] ?? null,
                    $data['is_active'] ?? true ? 1 : 0
                ]
            );

            // Log the action
            Logger::getInstance()->logUserAction('Created chart of accounts entry', 'chart_of_accounts', $accountId, null, $data);

            echo json_encode([
                'success' => true,
                'id' => $accountId,
                'account_code' => $data['account_code']
            ]);
            break;

        case 'PUT':
            // Update account
            if (!isset($_GET['id'])) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Account ID required']);
                exit;
            }

            $data = json_decode(file_get_contents('php://input'), true);
            if (!$data) {
                $data = $_POST;
            }
            $data = sanitizeChartOfAccountPayload($data);

            // Get current account
            $currentAccount = $db->select("SELECT * FROM chart_of_accounts WHERE id = ?", [$_GET['id']]);
            if (empty($currentAccount)) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Account not found']);
                exit;
            }

            $currentAccount = $currentAccount[0];

            // Check if account code is being changed and if it's unique
            if (isset($data['account_code']) && $data['account_code'] !== $currentAccount['account_code']) {
                if (!preg_match('/^[0-9]{4,6}$/', $data['account_code'])) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'error' => 'Account code must be 4-6 digits']);
                    exit;
                }

                $existing = $db->select("SELECT id FROM chart_of_accounts WHERE account_code = ? AND id != ?", [$data['account_code'], $_GET['id']]);
                if (!empty($existing)) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'error' => 'Account code already exists']);
                    exit;
                }
            }

            $fields = [];
            $params = [];

            if (isset($data['account_code'])) {
                $fields[] = "account_code = ?";
                $params[] = $data['account_code'];
            }
            if (isset($data['account_name'])) {
                $fields[] = "account_name = ?";
                $params[] = $data['account_name'];
            }
            if (isset($data['account_type'])) {
                $validTypes = ['asset', 'liability', 'equity', 'revenue', 'expense'];
                if (!in_array($data['account_type'], $validTypes)) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'error' => 'Invalid account type']);
                    exit;
                }
                $fields[] = "account_type = ?";
                $params[] = $data['account_type'];
            }
            if (isset($data['category'])) {
                $fields[] = "category = ?";
                $params[] = $data['category'];
            }
            if (isset($data['description'])) {
                $fields[] = "description = ?";
                $params[] = $data['description'];
            }
            if (isset($data['is_active'])) {
                $fields[] = "is_active = ?";
                $params[] = $data['is_active'] ? 1 : 0;
            }

            if (empty($fields)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'No fields to update']);
                exit;
            }

            $params[] = $_GET['id'];
            $sql = "UPDATE chart_of_accounts SET " . implode(', ', $fields) . " WHERE id = ?";

            $affected = $db->execute($sql, $params);

            // Log the action
            Logger::getInstance()->logUserAction('Updated chart of accounts entry', 'chart_of_accounts', $_GET['id'], $currentAccount, $data);

            echo json_encode(['success' => $affected > 0]);
            break;

        case 'DELETE':
            // Delete account (soft delete by deactivating)
            if (!isset($_GET['id'])) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Account ID required']);
                exit;
            }

            // Get current account
            $currentAccount = $db->select("SELECT * FROM chart_of_accounts WHERE id = ?", [$_GET['id']]);
            if (empty($currentAccount)) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Account not found']);
                exit;
            }

            $currentAccount = $currentAccount[0];

            // Check if account is used in any journal entries
            $usage = $db->select("SELECT COUNT(*) as count FROM journal_entry_lines WHERE account_id = ?", [$_GET['id']]);
            if ($usage[0]['count'] > 0) {
                // Soft delete - just deactivate
                $affected = $db->execute("UPDATE chart_of_accounts SET is_active = 0 WHERE id = ?", [$_GET['id']]);

                // Log the action
                Logger::getInstance()->logUserAction('Deactivated chart of accounts entry', 'chart_of_accounts', $_GET['id'], $currentAccount, ['is_active' => false]);

                echo json_encode(['success' => $affected > 0, 'message' => 'Account deactivated (has existing transactions)']);
            } else {
                // Hard delete if no transactions
                $affected = $db->execute("DELETE FROM chart_of_accounts WHERE id = ?", [$_GET['id']]);

                // Log the action
                Logger::getInstance()->logUserAction('Deleted chart of accounts entry', 'chart_of_accounts', $_GET['id'], $currentAccount, null);

                echo json_encode(['success' => $affected > 0]);
            }
            break;

        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            break;
    }
} catch (Exception $e) {
    Logger::getInstance()->logDatabaseError('Chart of Accounts API operation', $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error occurred']);
}
?>


