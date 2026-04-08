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
    $stmt = $db->prepare("
        SELECT 1
        FROM information_schema.tables
        WHERE table_schema = DATABASE()
          AND table_name = ?
        LIMIT 1
    ");
    $stmt->execute([$tableName]);
    return (bool) $stmt->fetchColumn();
}

function logisticsGetColumns(PDO $db, $tableName) {
    $stmt = $db->query("SHOW COLUMNS FROM `{$tableName}`");
    $columns = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $columns[] = $row['Field'];
    }
    return $columns;
}

function logisticsColumnExists(array $columns, $name) {
    return in_array($name, $columns, true);
}

function logisticsFirstColumn(array $columns, array $candidates) {
    foreach ($candidates as $candidate) {
        if (logisticsColumnExists($columns, $candidate)) {
            return $candidate;
        }
    }
    return null;
}

function logisticsQuotedColumn($alias, $column) {
    if ($column === null) {
        return null;
    }
    if ($alias === null || $alias === '') {
        return '`' . $column . '`';
    }
    return $alias . '.`' . $column . '`';
}

function getLogisticsSummary(PDO $db, array $columns) {
    $sourceColumn = logisticsFirstColumn($columns, ['source_system']);
    $typeColumn = logisticsFirstColumn($columns, ['transaction_type']);
    $amountColumn = logisticsFirstColumn($columns, ['amount', 'total_amount']);
    $dateColumn = logisticsFirstColumn($columns, ['transaction_date', 'created_at', 'updated_at']);

    if ($sourceColumn === null || $typeColumn === null) {
        return [
            'invoice_count' => 0,
            'invoice_amount' => 0.0,
            'purchase_order_count' => 0,
            'purchase_order_amount' => 0.0,
            'trip_count' => 0,
            'trip_amount' => 0.0,
            'total_amount' => 0.0,
            'last_imported_at' => null,
        ];
    }

    $sourceExpr = logisticsQuotedColumn('', $sourceColumn);
    $typeExpr = logisticsQuotedColumn('', $typeColumn);
    $amountExpr = $amountColumn !== null ? logisticsQuotedColumn('', $amountColumn) : '0';
    $dateExpr = $dateColumn !== null ? logisticsQuotedColumn('', $dateColumn) : 'NULL';

    $stmt = $db->query("
        SELECT
            SUM(CASE WHEN {$sourceExpr} = 'LOGISTICS1' AND {$typeExpr} = 'supplier_invoice' THEN 1 ELSE 0 END) AS invoice_count,
            COALESCE(SUM(CASE WHEN {$sourceExpr} = 'LOGISTICS1' AND {$typeExpr} = 'supplier_invoice' THEN {$amountExpr} ELSE 0 END), 0) AS invoice_amount,
            SUM(CASE WHEN {$sourceExpr} = 'LOGISTICS1' AND {$typeExpr} = 'purchase_order' THEN 1 ELSE 0 END) AS purchase_order_count,
            COALESCE(SUM(CASE WHEN {$sourceExpr} = 'LOGISTICS1' AND {$typeExpr} = 'purchase_order' THEN {$amountExpr} ELSE 0 END), 0) AS purchase_order_amount,
            SUM(CASE WHEN {$sourceExpr} = 'LOGISTICS2' AND {$typeExpr} = 'transportation_expense' THEN 1 ELSE 0 END) AS trip_count,
            COALESCE(SUM(CASE WHEN {$sourceExpr} = 'LOGISTICS2' AND {$typeExpr} = 'transportation_expense' THEN {$amountExpr} ELSE 0 END), 0) AS trip_amount,
            COALESCE(SUM(CASE WHEN {$sourceExpr} IN ('LOGISTICS1', 'LOGISTICS2') THEN {$amountExpr} ELSE 0 END), 0) AS total_amount,
            MAX({$dateExpr}) AS last_imported_at
        FROM imported_transactions
        WHERE {$sourceExpr} IN ('LOGISTICS1', 'LOGISTICS2')
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

function getLogisticsTransactions(PDO $db, $limit, array $columns, array $departmentColumns) {
    $sourceColumn = logisticsFirstColumn($columns, ['source_system']);
    if ($sourceColumn === null) {
        return [];
    }

    $dateColumn = logisticsFirstColumn($columns, ['transaction_date', 'created_at', 'updated_at']);
    $typeColumn = logisticsFirstColumn($columns, ['transaction_type']);
    $referenceColumn = logisticsFirstColumn($columns, ['external_reference', 'external_id', 'reference_number', 'id']);
    $descriptionColumn = logisticsFirstColumn($columns, ['description', 'external_reference', 'external_id']);
    $amountColumn = logisticsFirstColumn($columns, ['amount', 'total_amount']);
    $statusColumn = logisticsFirstColumn($columns, ['status']);
    $departmentIdColumn = logisticsFirstColumn($columns, ['department_id']);

    $dateExpr = $dateColumn !== null ? logisticsQuotedColumn('it', $dateColumn) : 'NULL';
    $typeExpr = $typeColumn !== null ? logisticsQuotedColumn('it', $typeColumn) : "''";
    $referenceExpr = $referenceColumn !== null ? logisticsQuotedColumn('it', $referenceColumn) : "''";
    $descriptionExpr = $descriptionColumn !== null ? logisticsQuotedColumn('it', $descriptionColumn) : "''";
    $amountExpr = $amountColumn !== null ? logisticsQuotedColumn('it', $amountColumn) : '0';
    $statusExpr = $statusColumn !== null ? logisticsQuotedColumn('it', $statusColumn) : "'pending'";
    $sourceExpr = logisticsQuotedColumn('it', $sourceColumn);

    $joinDepartments = $departmentIdColumn !== null
        && logisticsTableExists($db, 'departments')
        && !empty($departmentColumns);

    $departmentNameColumn = logisticsFirstColumn($departmentColumns, ['dept_name', 'department_name', 'name']);
    $departmentExpr = $joinDepartments && $departmentNameColumn !== null
        ? logisticsQuotedColumn('d', $departmentNameColumn)
        : "'Unassigned'";

    $joinSql = '';
    if ($joinDepartments) {
        $joinSql = ' LEFT JOIN departments d ON ' . logisticsQuotedColumn('it', $departmentIdColumn) . ' = d.id';
    }

    $orderDateExpr = $dateColumn !== null ? logisticsQuotedColumn('it', $dateColumn) : $referenceExpr;
    $orderRefExpr = $referenceColumn !== null ? logisticsQuotedColumn('it', $referenceColumn) : $sourceExpr;

    $sql = "
        SELECT
            {$dateExpr} AS transaction_date,
            {$sourceExpr} AS source_system,
            {$typeExpr} AS transaction_type,
            {$referenceExpr} AS external_reference,
            {$descriptionExpr} AS description,
            {$amountExpr} AS amount,
            {$statusExpr} AS status,
            {$departmentExpr} AS department_name
        FROM imported_transactions it
        {$joinSql}
        WHERE {$sourceExpr} IN ('LOGISTICS1', 'LOGISTICS2')
        ORDER BY {$orderDateExpr} DESC, {$orderRefExpr} DESC
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

    $transactionColumns = logisticsGetColumns($db, 'imported_transactions');
    $departmentColumns = logisticsTableExists($db, 'departments')
        ? logisticsGetColumns($db, 'departments')
        : [];

    logisticsSend([
        'success' => true,
        'summary' => getLogisticsSummary($db, $transactionColumns),
        'transactions' => getLogisticsTransactions($db, $limit, $transactionColumns, $departmentColumns),
    ]);
} catch (Throwable $e) {
    try {
        Logger::getInstance()->logDatabaseError('Logistics activity API', $e->getMessage());
    } catch (Throwable $loggingError) {
        // Ignore logging failures so the API can still return the original error.
    }
    logisticsSend(['success' => false, 'error' => 'Failed to load logistics activity: ' . $e->getMessage()], 500);
}
