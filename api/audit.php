<?php
// Audits API for Disbursements Module
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

ob_start();
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
require_once '../includes/logger.php';
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to load required files: ' . $e->getMessage()]);
    ob_end_flush();
    exit(1);
}

$method = $_SERVER['REQUEST_METHOD'];
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    ob_end_flush();
    exit;
}

$db = Database::getInstance()->getConnection();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$auth = new Auth();
ensure_api_auth($method, [
    'GET' => 'audit.view',
    'PUT' => 'audit.view',
    'DELETE' => 'audit.view',
    'POST' => 'audit.view',
    'PATCH' => 'audit.view',
]);

function getAuditTrail($db, $filters = []) {
    try {
        $where = [];
        $params = [];
        $allowedTables = ['disbursements', 'hr3_claims', 'payroll'];
        $allowedActions = ['created', 'updated', 'deleted', 'approved', 'rejected', 'processed_payment', 'viewed', 'printed'];
        $scope = $filters['scope'] ?? '';

        // Filter by table
        if (isset($filters['table_name'])) {
            $tables = array_filter(array_map('trim', explode(',', $filters['table_name'])));
            if (!empty($tables)) {
                if (count($tables) > 1) {
                    $placeholders = implode(',', array_fill(0, count($tables), '?'));
                    $where[] = "a.table_name IN ($placeholders)";
                    $params = array_merge($params, $tables);
                } else {
                    $where[] = "a.table_name = ?";
                    $params[] = $tables[0];
                }
            }
        }

        if ($scope === 'disbursements') {
            if (!empty($allowedTables)) {
                $placeholders = implode(',', array_fill(0, count($allowedTables), '?'));
                $where[] = "a.table_name IN ($placeholders)";
                $params = array_merge($params, $allowedTables);
            }

            if (!empty($allowedActions)) {
                $placeholders = implode(',', array_fill(0, count($allowedActions), '?'));
                $where[] = "a.action IN ($placeholders)";
                $params = array_merge($params, $allowedActions);
            }
        }

        // Only user-driven actions
        $where[] = "a.user_id IS NOT NULL";

        // Filter by user id
        if (isset($filters['user_id'])) {
            $where[] = "a.user_id = ?";
            $params[] = $filters['user_id'];
        }

        // Filter by user name/username
        if (isset($filters['user'])) {
            $where[] = "(u.username LIKE ? OR u.full_name LIKE ?)";
            $userLike = '%' . $filters['user'] . '%';
            $params[] = $userLike;
            $params[] = $userLike;
        }

        if (isset($filters['action'])) {
            $where[] = "a.action LIKE ?";
            $params[] = '%' . $filters['action'] . '%';
        }

        // Filter by date range
        if (isset($filters['date_from'])) {
            $where[] = "a.created_at >= ?";
            $params[] = $filters['date_from'];
        }

        if (isset($filters['date_to'])) {
            $where[] = "a.created_at <= ?";
            $params[] = $filters['date_to'];
        }

        // Filter by record ID (for disbursements)
        if (isset($filters['record_id'])) {
            $where[] = "a.record_id = ?";
            $params[] = $filters['record_id'];
        }

        // No default filter - allow all tables unless specifically filtering

        $whereClause = $where ? "WHERE " . implode(" AND ", $where) : "";

        $stmt = $db->prepare("
            SELECT a.*,
                   u.username, u.full_name,
                   d.disbursement_number
            FROM audit_log a
            LEFT JOIN users u ON a.user_id = u.id
            LEFT JOIN disbursements d ON a.record_id = d.id AND a.table_name = 'disbursements'
            $whereClause
            ORDER BY a.created_at DESC
            LIMIT 1000
        ");
        $stmt->execute($params);

        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Format the results and enrich missing disbursement/user data when possible
        foreach ($logs as &$log) {
            $log['formatted_date'] = date('M j, Y g:i:s A', strtotime($log['created_at']));
            $log['action_label'] = formatActionLabel($log['action']);

            // Try to populate disbursement_number from stored JSON fields or by querying the disbursements table
            $oldValues = $log['old_values'] ? json_decode($log['old_values'], true) : [];
            $newValues = $log['new_values'] ? json_decode($log['new_values'], true) : [];

            // Normalize common field aliases to keep audit payloads aligned
            $aliases = [
                'payment_date' => 'disbursement_date',
                'disb_no' => 'disbursement_number',
                'disb_number' => 'disbursement_number',
                'ref' => 'reference_number',
                'ref_number' => 'reference_number',
                'reference' => 'reference_number'
            ];

            foreach ($aliases as $alias => $canonical) {
                if (isset($newValues[$alias]) && !isset($newValues[$canonical])) {
                    $newValues[$canonical] = $newValues[$alias];
                }
                if (isset($oldValues[$alias]) && !isset($oldValues[$canonical])) {
                    $oldValues[$canonical] = $oldValues[$alias];
                }
            }

            if ($log['table_name'] === 'disbursements') {
                // If record_id is present but disbursement_number missing, query the disbursements table
                if (empty($log['disbursement_number']) && !empty($log['record_id'])) {
                    try {
                        $stmt2 = $db->prepare("SELECT disbursement_number FROM disbursements WHERE id = ? LIMIT 1");
                        $stmt2->execute([$log['record_id']]);
                        $row = $stmt2->fetch(PDO::FETCH_ASSOC);
                        if ($row && !empty($row['disbursement_number'])) {
                            $log['disbursement_number'] = $row['disbursement_number'];
                        }
                    } catch (Exception $e) {
                        // ignore DB lookup errors
                    }
                }

                // If disbursement_number present in new/old values but record_id is missing, try to resolve it
                if (empty($log['record_id'])) {
                    $possibleNum = $newValues['disbursement_number'] ?? $oldValues['disbursement_number'] ?? $newValues['disbursement_no'] ?? $oldValues['disbursement_no'] ?? null;
                    if (!empty($possibleNum) && empty($log['disbursement_number'])) {
                        $log['disbursement_number'] = $possibleNum;
                    }
                    if (!empty($possibleNum) && empty($log['record_id'])) {
                        try {
                            $stmt3 = $db->prepare("SELECT id FROM disbursements WHERE disbursement_number = ? LIMIT 1");
                            $stmt3->execute([$possibleNum]);
                            $r = $stmt3->fetch(PDO::FETCH_ASSOC);
                            if ($r && !empty($r['id'])) {
                                $log['record_id'] = $r['id'];
                            }
                        } catch (Exception $e) {
                            // ignore
                        }
                    }
                }

                // If still missing, fallback to parsing description fields for common DISB tokens
                if (empty($log['disbursement_number'])) {
                    $text = ($log['action_description'] ?? '') . ' ' . ($newValues['description'] ?? '') . ' ' . ($oldValues['description'] ?? '');
                    if (preg_match('/(DISB-\d{8}-\d{3}|DISB-\d+)|(\bID\s*(\d+)\b)|(\b\d{1,6}\b)/i', $text, $m)) {
                        foreach ($m as $candidate) {
                            if (!empty($candidate)) {
                                $log['disbursement_number'] = $candidate;
                                break;
                            }
                        }
                    }
                }
            } else {
                // Non-disbursement tables: still try to pick up possible disbursement_number from payload
                if (empty($log['disbursement_number'])) {
                    $possible = $newValues['disbursement_number'] ?? $oldValues['disbursement_number'] ?? null;
                    if (!empty($possible)) $log['disbursement_number'] = $possible;
                }
            }

            // Normalize a display-friendly user label
            if (!empty($log['full_name'])) {
                $log['display_user'] = $log['full_name'];
            } elseif (!empty($log['username'])) {
                $log['display_user'] = $log['username'];
            } elseif (!empty($log['user_id'])) {
                $log['display_user'] = 'User #' . $log['user_id'];
            } else {
                $log['display_user'] = 'System';
            }

            // Compute the action description (this may also use the enriched disbursement_number)
            $log['action_description'] = formatAction($log);
        }

        return $logs;

    } catch (Exception $e) {
        error_log("Error fetching audit trail: " . $e->getMessage());
        return [];
    }
}

function logDisbursementAction($db, $action, $recordId, $oldValues = null, $newValues = null, $userId = null) {
    try {
        $userId = $userId ?? $_SESSION['user']['id'] ?? 1;

            $stmt = $db->prepare("
                INSERT INTO audit_log (
                    user_id, action, table_name, record_id, old_values, new_values,
                    ip_address, user_agent, created_at
                ) VALUES (?, ?, 'disbursements', ?, ?, ?, ?, ?, NOW())
            ");

        $stmt->execute([
            $userId,
            $action,
            $recordId,
            $oldValues ? json_encode($oldValues) : null,
            $newValues ? json_encode($newValues) : null,
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null
        ]);

        return true;

    } catch (Exception $e) {
        error_log("Error logging disbursement action: " . $e->getMessage());
        return false;
    }
}

function formatActionLabel($action) {
    $action = strtolower($action);
    $labels = [
        'created' => 'Created',
        'updated' => 'Updated',
        'deleted' => 'Deleted',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
        'processed_payment' => 'Processed Payment'
    ];
    return $labels[$action] ?? ucfirst($action);
}

function formatAction($log) {
    $action = strtolower($log['action']);
    $table = $log['table_name'];
    $record = '';
    $oldValues = $log['old_values'] ? json_decode($log['old_values'], true) : [];
    $newValues = $log['new_values'] ? json_decode($log['new_values'], true) : [];

    // Format record description based on table
    switch ($table) {
        case 'disbursements':
            // Prefer human-readable disbursement number when available.
            if (!empty($log['disbursement_number'])) {
                $record = "disbursement {$log['disbursement_number']}";
            } elseif (!empty($log['record_id'])) {
                $record = "disbursement ID {$log['record_id']}";
            } else {
                // Attempt to read from stored values if available, otherwise mark unknown
                $possible = $newValues['disbursement_number'] ?? $oldValues['disbursement_number'] ?? null;
                if (!empty($possible)) {
                    $record = "disbursement {$possible}";
                } else {
                    $record = "disbursement (unknown)";
                }
            }
            break;
        case 'payroll':
            $record = "payroll ID {$log['record_id']}";
            break;
        case 'hr3_claims':
            $record = "HR3 claim {$log['record_id']}";
            break;
        case 'budgets':
            $budgetName = $newValues['budget_name'] ?? $newValues['name'] ?? $oldValues['budget_name'] ?? $oldValues['name'] ?? null;
            $record = $budgetName ? "budget {$budgetName}" : "budget ID {$log['record_id']}";
            break;
        case 'budget_items':
            $record = "budget item ID {$log['record_id']}";
            break;
        case 'budget_adjustments':
            $record = "budget adjustment ID {$log['record_id']}";
            break;
        case 'budget_categories':
            $categoryName = $newValues['category_name'] ?? $oldValues['category_name'] ?? null;
            $record = $categoryName ? "budget category {$categoryName}" : "budget category ID {$log['record_id']}";
            break;
        case 'hr3_integrations':
            $record = "HR3 claims data";
            break;
        case 'journal_entries':
            $record = "journal entry ID {$log['record_id']}";
            break;
        case 'chart_of_accounts':
            $record = "account ID {$log['record_id']}";
            break;
        default:
            $record = "record ID {$log['record_id']}";
    }

    switch ($action) {
        case 'created':
        case 'inserted':
            return "Created $record";
        case 'updated':
        case 'modified':
            return "Updated $record";
        case 'deleted':
        case 'removed':
        case 'deactivated':
            return "Deleted $record";
        case 'viewed':
            return "Viewed $record";
        case 'approved':
            return "Approved $record";
        case 'rejected':
            return "Rejected $record";
        case 'requested':
            return "Requested $record";
        case 'integration_execute':
            return "Loaded $record";
        case 'processed_payment':
            if (!empty($newValues['description'])) {
                return $newValues['description'];
            }
            return "Processed payment for $record";
        case 'generated':
            return "Generated report";
        default:
            return ucfirst($action) . " $record";
    }
}

try {
    switch ($method) {
        case 'GET':
            $filters = [];

            // Apply filters from query parameters
            if (isset($_GET['user_id'])) $filters['user_id'] = $_GET['user_id'];
            if (isset($_GET['date_from'])) $filters['date_from'] = $_GET['date_from'];
            if (isset($_GET['date_to'])) $filters['date_to'] = $_GET['date_to'];
            if (isset($_GET['record_id'])) $filters['record_id'] = $_GET['record_id'];
            if (isset($_GET['action']) && !in_array($_GET['action'], ['details', 'export', 'cleanup'])) {
                $filters['action'] = $_GET['action'];
            }
            if (isset($_GET['table_name'])) $filters['table_name'] = $_GET['table_name'];
            if (isset($_GET['scope'])) $filters['scope'] = $_GET['scope'];
            if (isset($_GET['user'])) $filters['user'] = $_GET['user'];

            $auditTrail = getAuditTrail($db, $filters);
            echo json_encode($auditTrail);
            break;
        case 'POST':
            if (isset($_POST['action']) && $_POST['action'] === 'log') {
                $action = strtolower(trim($_POST['action_type'] ?? ''));
                $table = trim($_POST['table_name'] ?? '');
                $recordId = trim($_POST['record_id'] ?? '');
                $oldValues = $_POST['old_values'] ?? null;
                $newValues = $_POST['new_values'] ?? null;

                $allowedTables = ['disbursements', 'hr3_claims', 'payroll'];
                $allowedActions = ['created', 'updated', 'deleted', 'approved', 'rejected', 'processed_payment', 'printed'];

                if (!in_array($table, $allowedTables, true) || !in_array($action, $allowedActions, true)) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Unsupported audit log action']);
                    break;
                }

                $stmt = $db->prepare("
                    INSERT INTO audit_log (
                        user_id, action, table_name, record_id, old_values, new_values,
                        ip_address, user_agent, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ");

                $stmt->execute([
                    $_SESSION['user']['id'] ?? null,
                    $action,
                    $table,
                    $recordId !== '' ? $recordId : null,
                    $oldValues,
                    $newValues
                        ? (is_string($newValues) ? $newValues : json_encode($newValues))
                        : null,
                    $_SERVER['REMOTE_ADDR'] ?? null,
                    $_SERVER['HTTP_USER_AGENT'] ?? null
                ]);

                echo json_encode(['success' => true]);
                break;
            }
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            break;

        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
    }
} catch (Exception $e) {
    error_log("Audit API error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
}

ob_end_flush();
?>


