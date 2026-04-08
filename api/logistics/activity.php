<?php
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/logger.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
ensure_api_auth($method, [
    'GET' => 'disbursements.view',
]);

$db = Database::getInstance()->getConnection();

function logisticsSend($payload, $status = 200) {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function logisticsTableExists(PDO $db, $tableName) {
    $stmt = $db->prepare('SHOW TABLES LIKE ?');
    $stmt->execute([$tableName]);
    return (bool) $stmt->fetchColumn();
}

function getLogisticsSummary(PDO $db) {
    $stmt = $db->query("
        SELECT
            SUM(CASE WHEN source_system = 'LOGISTICS1' AND transaction_type = 'supplier_invoice' THEN 1 ELSE 0 END) AS invoice_count,
            COALESCE(SUM(CASE WHEN source_system = 'LOGISTICS1' AND transaction_type = 'supplier_invoice' THEN amount ELSE 0 END), 0) AS invoice_amount,
            SUM(CASE WHEN source_system = 'LOGISTICS1' AND transaction_type = 'purchase_order' THEN 1 ELSE 0 END) AS purchase_order_count,
            COALESCE(SUM(CASE WHEN source_system = 'LOGISTICS1' AND transaction_type = 'purchase_order' THEN amount ELSE 0 END), 0) AS purchase_order_amount,
            SUM(CASE WHEN source_system = 'LOGISTICS2' AND transaction_type = 'transportation_expense' THEN 1 ELSE 0 END) AS trip_count,
            COALESCE(SUM(CASE WHEN source_system = 'LOGISTICS2' AND transaction_type = 'transportation_expense' THEN amount ELSE 0 END), 0) AS trip_amount,
            COALESCE(SUM(CASE WHEN source_system IN ('LOGISTICS1', 'LOGISTICS2') THEN amount ELSE 0 END), 0) AS total_amount,
            MAX(transaction_date) AS last_imported_at
        FROM imported_transactions
        WHERE source_system IN ('LOGISTICS1', 'LOGISTICS2')
    ");

    $summary = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'invoice_count' => (int) ($summary['invoice_count'] ?? 0),
        'invoice_amount' => (float) ($summary['invoice_amount'] ?? 0),
        'purchase_order_count' => (int) ($summary['purchase_order_count'] ?? 0),
        'purchase_order_amount' => (float) ($summary['purchase_order_amount'] ?? 0),
        'trip_count' => (int) ($summary['trip_count'] ?? 0),
        'trip_amount' => (float) ($summary['trip_amount'] ?? 0),
        'total_amount' => (float) ($summary['total_amount'] ?? 0),
        'last_imported_at' => $summary['last_imported_at'] ?? null,
    ];
}

function getLogisticsTransactions(PDO $db, $limit) {
    $sql = "
        SELECT
            it.transaction_date,
            it.source_system,
            it.transaction_type,
            it.external_reference,
            it.description,
            it.amount,
            it.status,
            d.dept_name AS department_name
        FROM imported_transactions it
        LEFT JOIN departments d ON it.department_id = d.id
        WHERE it.source_system IN ('LOGISTICS1', 'LOGISTICS2')
        ORDER BY it.transaction_date DESC, it.external_reference DESC
        LIMIT {$limit}
    ";

    $stmt = $db->query($sql);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    return array_map(static function ($row) {
        return [
            'transaction_date' => $row['transaction_date'] ?? null,
            'source_system' => $row['source_system'] ?? '',
            'transaction_type' => $row['transaction_type'] ?? '',
            'external_reference' => $row['external_reference'] ?? '',
            'description' => $row['description'] ?? '',
            'amount' => (float) ($row['amount'] ?? 0),
            'status' => $row['status'] ?? 'pending',
            'department_name' => $row['department_name'] ?? 'Unassigned',
        ];
    }, $rows);
}

try {
    if ($method !== 'GET') {
        logisticsSend(['success' => false, 'error' => 'Method not allowed'], 405);
    }

    if (!logisticsTableExists($db, 'imported_transactions')) {
        logisticsSend([
            'success' => true,
            'summary' => [
                'invoice_count' => 0,
                'invoice_amount' => 0.0,
                'purchase_order_count' => 0,
                'purchase_order_amount' => 0.0,
                'trip_count' => 0,
                'trip_amount' => 0.0,
                'total_amount' => 0.0,
                'last_imported_at' => null,
            ],
            'transactions' => [],
        ]);
    }

    $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 25;
    if ($limit < 1) {
        $limit = 25;
    }
    if ($limit > 100) {
        $limit = 100;
    }

    logisticsSend([
        'success' => true,
        'summary' => getLogisticsSummary($db),
        'transactions' => getLogisticsTransactions($db, $limit),
    ]);
} catch (Throwable $e) {
    Logger::getInstance()->logDatabaseError('Logistics activity API', $e->getMessage());
    logisticsSend(['success' => false, 'error' => 'Failed to load logistics activity'], 500);
}
