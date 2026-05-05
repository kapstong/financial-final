<?php
// For API endpoints, we don't want to redirect on auth failure
// So we'll handle authentication differently
require_once '../includes/database.php';
require_once '../includes/logger.php';
require_once '../includes/coa_validation.php';
require_once '../includes/budget_alerts.php';
require_once '../includes/python_runner.php';
require_once '../includes/privacy.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

$db = Database::getInstance()->getConnection(); 
$logger = Logger::getInstance();

// Check authentication for API calls
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required']);
    exit();
}

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($method) {
        case 'GET':
            handleGet($db, $logger);
            break;
        case 'POST':
            handlePost($db, $logger);
            break;
        case 'PUT':
            handlePut($db, $logger);
            break;
        case 'DELETE':
            handleDelete($db, $logger);
            break;
        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
    }
} catch (Exception $e) {
    $logger->log("API Error in budgets.php: " . $e->getMessage(), 'ERROR');
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
}

function handleGet($db, $logger) {
    try {
        $action = isset($_GET['action']) ? $_GET['action'] : null;

        switch ($action) {
            case 'forecast':
                getForecastData($db);
                break;
            case 'categories':
                getCategories($db);
                break;
            case 'allocations':
                getAllocations($db);
                break;
            case 'adjustments':
                getAdjustments($db);
                break;
            case 'tracking':
                getTrackingData($db);
                break;
            case 'alerts':
                getAlerts($db);
                break;
            default:
                getBudgets($db);
        }
    } catch (Exception $e) {
        $logger->log("Error in handleGet budgets: " . $e->getMessage(), 'ERROR');
        http_response_code(500);
        echo json_encode(['error' => 'Failed to fetch budget data']);
    }
}

function getBudgets($db) {
    $source = isset($_GET['source']) ? $_GET['source'] : 'all';
    if ($source === 'internal') {
        getInternalBudgets($db);
        return;
    }

    if ($source === 'external') {
        $externalBudgets = fetchExternalBudgetRequests($db);
        echo json_encode(['budgets' => $externalBudgets]);
        return;
    }

    $internalBudgets = getInternalBudgetsArray($db);
    $externalBudgets = fetchExternalBudgetRequests($db);
    $budgets = array_merge($internalBudgets, $externalBudgets);

    usort($budgets, function ($left, $right) {
        $leftDate = $left['created_at'] ?? $left['start_date'] ?? '';
        $rightDate = $right['created_at'] ?? $right['start_date'] ?? '';
        return strcmp((string) $rightDate, (string) $leftDate);
    });

    echo json_encode(['budgets' => $budgets]);
}

function getInternalBudgets($db) {
    echo json_encode(['budgets' => getInternalBudgetsArray($db)]);
}

function getInternalBudgetsArray($db) {
    // Get all budgets
    $stmt = $db->prepare("
        SELECT b.*,
               u1.full_name as created_by_name,
               u2.full_name as approved_by_name,
               d.dept_name as department_name,
               v.company_name as vendor_name,
               COALESCE(bt.utilized_amount, 0) as utilized_amount
        FROM budgets b
        LEFT JOIN users u1 ON b.created_by = u1.id
        LEFT JOIN users u2 ON b.approved_by = u2.id
        LEFT JOIN departments d ON b.department_id = d.id
        LEFT JOIN vendors v ON b.vendor_id = v.id
        LEFT JOIN (
            SELECT budget_id, COALESCE(SUM(actual_amount), 0) as utilized_amount
            FROM budget_items
            GROUP BY budget_id
        ) bt ON bt.budget_id = b.id
        ORDER BY b.created_at DESC
    ");
    $stmt->execute();
    $budgets = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // For each budget, get the items and calculate totals
    foreach ($budgets as &$budget) {
        $budget['start_date'] = $budget['start_date'] ?: ($budget['budget_year'] . '-01-01');
        $budget['end_date'] = $budget['end_date'] ?: ($budget['budget_year'] . '-12-31');
        $budget['department'] = $budget['department_name'] ?: 'Unassigned';
        $budget['name'] = $budget['budget_name'];
        $budget['total_amount'] = $budget['total_budgeted'];
        $budget['utilized_amount'] = (float) ($budget['utilized_amount'] ?? 0);
    }

    return $budgets;
}

function getCategories($db) {
    $stmt = $db->prepare("
        SELECT bc.*,
               d.dept_name as department_name
        FROM budget_categories bc
        LEFT JOIN departments d ON bc.department_id = d.id
        WHERE bc.is_active = 1
        ORDER BY bc.category_type, bc.category_name
    ");
    $stmt->execute();
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['categories' => $categories]);
}

function getAdjustments($db) {
    $stmt = $db->prepare("
        SELECT ba.*,
               d.dept_name as department_name,
               u.full_name as requested_by_name,
               ua.full_name as approved_by_name
        FROM budget_adjustments ba
        LEFT JOIN departments d ON ba.department_id = d.id
        LEFT JOIN users u ON ba.requested_by = u.id
        LEFT JOIN users ua ON ba.approved_by = ua.id
        ORDER BY ba.created_at DESC
    ");
    $stmt->execute();
    $adjustments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['adjustments' => $adjustments]);
}

function getAllocations($db) {
    $stmt = $db->prepare("
        SELECT
            COALESCE(d.dept_name, 'Unassigned') as department,
            totals.department_id,
            totals.total_amount,
            totals.utilized_amount,
            COALESCE(reserved.reserved_amount, 0) as reserved_amount
        FROM (
            SELECT
                bi.department_id,
                COALESCE(SUM(bi.budgeted_amount), 0) as total_amount,
                COALESCE(SUM(bi.actual_amount), 0) as utilized_amount
            FROM budget_items bi
            JOIN budgets b ON bi.budget_id = b.id
            WHERE b.status IN ('draft', 'pending', 'approved', 'active')
            GROUP BY bi.department_id
        ) totals
        LEFT JOIN departments d ON totals.department_id = d.id
        LEFT JOIN (
            SELECT
                ba.department_id,
                COALESCE(SUM(ba.amount), 0) as reserved_amount
            FROM budget_adjustments ba
            JOIN budgets b ON ba.budget_id = b.id
            WHERE ba.status = 'pending'
              AND b.status IN ('draft', 'pending', 'approved', 'active')
            GROUP BY ba.department_id
        ) reserved ON (
            reserved.department_id = totals.department_id
            OR (reserved.department_id IS NULL AND totals.department_id IS NULL)
        )
        ORDER BY d.dept_name
    ");
    $stmt->execute();
    $rawAllocations = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $allocations = [];
    foreach ($rawAllocations as $alloc) {
        $totalAmount = (float)$alloc['total_amount'];
        $utilizedAmount = (float)$alloc['utilized_amount'];
        $reservedAmount = (float)$alloc['reserved_amount'];
        $remaining = $totalAmount - $utilizedAmount - $reservedAmount;

        $allocations[] = [
            'id' => count($allocations) + 1, // Simple ID for frontend
            'department' => $alloc['department'] ?: 'Unassigned',
            'department_id' => $alloc['department_id'],
            'total_amount' => $totalAmount,
            'utilized_amount' => $utilizedAmount,
            'reserved_amount' => $reservedAmount,
            'remaining' => (float)$remaining
        ];
    }

    if (isset($_GET['include_external']) && $_GET['include_external'] === '1') {
        $externalAllocations = fetchExternalAllocations($db);
        $allocations = filterInternalAllocations($allocations, $externalAllocations);
        foreach ($externalAllocations as $external) {
            $allocations[] = $external;
        }
    }

    echo json_encode(['allocations' => $allocations]);
}

function fetchExternalAllocations($db) {
    try {
        $stmt = $db->prepare("
            SELECT system_code, system_name, api_endpoint, api_key, configuration
            FROM system_integrations
            WHERE is_active = 1
              AND api_endpoint IS NOT NULL
              AND api_endpoint <> ''
        ");
        $stmt->execute();
        $systems = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $allocations = [];
        foreach ($systems as $system) {
            $endpoint = trim($system['api_endpoint'] ?? '');
            if ($endpoint === '') {
                continue;
            }

            $config = [];
            if (!empty($system['configuration'])) {
                $decoded = json_decode($system['configuration'], true);
                if (is_array($decoded)) {
                    $config = $decoded;
                }
            }

            $departmentCode = $config['department_code'] ?? $system['system_code'];
            $url = buildExternalAllocationUrl($endpoint, $departmentCode);

            $response = httpGetJson($url, $system['api_key'] ?? null);
            if (!$response || !is_array($response)) {
                continue;
            }

            $periodData = selectPreferredPeriod($response);
            $total = (float)($periodData['total_budget'] ?? $response['total_budget'] ?? 0);
            $spent = (float)($periodData['spent'] ?? $response['spent'] ?? 0);
            $allocated = (float)($periodData['allocated'] ?? $response['allocated'] ?? $total);
            $remaining = $total - $spent;

            $displayName = $config['display_name'] ?? $config['department_label'] ?? $system['system_name'] ?? $departmentCode;
            $displayName = normalizeDisplayName($displayName);

            $allocations[] = [
                'id' => count($allocations) + 1000,
                'department' => $displayName,
                'department_id' => null,
                'total_amount' => $total,
                'utilized_amount' => $spent,
                'reserved_amount' => 0,
                'remaining' => $remaining,
                'is_external' => true,
                'external_source' => $system['system_code'],
                'external_department_code' => $departmentCode
            ];
        }

        return $allocations;
    } catch (Exception $e) {
        return [];
    }
}

function fetchExternalBudgetRequests($db) {
    try {
        $stmt = $db->prepare("
            SELECT system_code, system_name, api_endpoint, api_key, configuration
            FROM system_integrations
            WHERE is_active = 1
              AND api_endpoint IS NOT NULL
              AND api_endpoint <> ''
        ");
        $stmt->execute();
        $systems = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $budgets = [];
        foreach ($systems as $system) {
            $endpoint = trim($system['api_endpoint'] ?? '');
            if ($endpoint === '') {
                continue;
            }

            $config = [];
            if (!empty($system['configuration'])) {
                $decoded = json_decode($system['configuration'], true);
                if (is_array($decoded)) {
                    $config = $decoded;
                }
            }

            $requestEndpoint = $config['budget_request_endpoint'] ?? $endpoint;
            $departmentCode = $config['department_code'] ?? $system['system_code'];
            $url = buildExternalBudgetRequestUrl($requestEndpoint, $departmentCode, $config);

            $response = httpGetJson($url, $system['api_key'] ?? null);
            $requests = normalizeExternalBudgetRequests($response);

            foreach ($requests as $request) {
                $budgets[] = formatExternalBudgetRequest($request, $system, $departmentCode);
            }
        }

        return $budgets;
    } catch (Exception $e) {
        return [];
    }
}

function buildExternalBudgetRequestUrl($endpoint, $departmentCode, $config = []) {
    $parsed = parse_url($endpoint);
    $query = [];
    if (!empty($parsed['query'])) {
        parse_str($parsed['query'], $query);
    }

    $actionKey = $config['budget_request_action_key'] ?? 'action';
    $actionValue = $config['budget_request_action_value'] ?? 'budget_requests';
    if (!isset($query[$actionKey]) && $actionKey !== '') {
        $query[$actionKey] = $actionValue;
    }

    if (!isset($query['department_code']) && $departmentCode !== '') {
        $query['department_code'] = $departmentCode;
    }

    $base = $endpoint;
    if (!empty($parsed['query'])) {
        $base = substr($endpoint, 0, strpos($endpoint, '?'));
    }

    return $base . '?' . http_build_query($query);
}

function normalizeExternalBudgetRequests($response) {
    if (empty($response)) {
        return [];
    }

    if (isset($response['budgets']) && is_array($response['budgets'])) {
        return $response['budgets'];
    }

    if (isset($response['requests']) && is_array($response['requests'])) {
        return $response['requests'];
    }

    if (is_array($response) && array_keys($response) === range(0, count($response) - 1)) {
        return $response;
    }

    return [$response];
}

function formatExternalBudgetRequest($request, $system, $departmentCode) {
    $requestId = $request['id'] ?? $request['request_id'] ?? null;
    $status = $request['status'] ?? $request['request_status'] ?? 'pending';
    $total = $request['total_budgeted']
        ?? $request['total_amount']
        ?? $request['amount']
        ?? 0;
    $utilizedAmount = $request['utilized_amount']
        ?? $request['actual_amount']
        ?? $request['spent']
        ?? 0;

    $startDate = $request['start_date'] ?? $request['period_start'] ?? null;
    $endDate = $request['end_date'] ?? $request['period_end'] ?? null;
    $year = $request['budget_year'] ?? ($startDate ? date('Y', strtotime($startDate)) : date('Y'));

    $departmentName = $request['department_name']
        ?? $request['department']
        ?? $system['system_name']
        ?? $departmentCode;

    $budgetName = $request['budget_name'] ?? $request['name'] ?? 'External Budget Request';
    $createdBy = $request['requested_by'] ?? $request['created_by'] ?? $request['owner'] ?? null;

    return [
        'id' => $requestId ? ('EXT-' . $system['system_code'] . '-' . $requestId) : null,
        'budget_name' => $budgetName,
        'name' => $budgetName,
        'description' => $request['description'] ?? '',
        'budget_year' => $year,
        'total_budgeted' => (float)$total,
        'total_amount' => (float)$total,
        'utilized_amount' => (float)$utilizedAmount,
        'start_date' => $startDate ?: ($year . '-01-01'),
        'end_date' => $endDate ?: ($year . '-12-31'),
        'status' => $status,
        'department_name' => $departmentName,
        'department' => $departmentName,
        'created_by_name' => $createdBy,
        'created_at' => $request['created_at'] ?? $request['requested_at'] ?? $request['date_created'] ?? null,
        'approved_by_name' => $request['approved_by'] ?? null,
        'is_external' => true,
        'external_source' => $system['system_code'],
        'external_request_id' => $requestId,
        'external_department_code' => $departmentCode
    ];
}

function httpGetJson($url, $apiKey = null) {
    $ch = curl_init();
    if ($ch === false) {
        return null;
    }

    $headers = [];
    if (!empty($apiKey)) {
        $headers[] = 'X-API-Key: ' . $apiKey;
    }

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 6);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_FAILONERROR, false);

    $raw = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false || $status >= 400) {
        return null;
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

function selectPreferredPeriod($response) {
    if (!isset($response['periods']) || !is_array($response['periods'])) {
        return $response;
    }

    $preferred = ['yearly', 'annually', 'semi-annually', 'quarterly', 'monthly'];
    $index = [];
    foreach ($response['periods'] as $period) {
        if (!isset($period['period'])) {
            continue;
        }
        $index[strtolower($period['period'])] = $period;
    }

    foreach ($preferred as $periodKey) {
        if (isset($index[$periodKey])) {
            return $index[$periodKey];
        }
    }

    return $response['periods'][0] ?? $response;
}

function buildExternalAllocationUrl($endpoint, $departmentCode) {
    $parsed = parse_url($endpoint);
    $query = [];
    if (!empty($parsed['query'])) {
        parse_str($parsed['query'], $query);
    }

    if (!isset($query['department_code']) && $departmentCode !== '') {
        $query['department_code'] = $departmentCode;
    }

    $base = $endpoint;
    if (!empty($parsed['query'])) {
        $base = substr($endpoint, 0, strpos($endpoint, '?'));
    }

    return $base . '?' . http_build_query($query);
}

function normalizeDisplayName($name) {
    $trimmed = trim((string)$name);
    if ($trimmed === '') {
        return $trimmed;
    }

    if (stripos($trimmed, ' budget') !== false) {
        $trimmed = preg_replace('/\s+budget\b/i', '', $trimmed);
    }

    return trim($trimmed);
}

function filterInternalAllocations($allocations, $externalAllocations) {
    if (empty($externalAllocations)) {
        return $allocations;
    }

    $externalKeys = [];
    foreach ($externalAllocations as $external) {
        $key = normalizeAllocationKey($external['external_department_code'] ?? $external['department'] ?? '');
        if ($key !== '') {
            $externalKeys[$key] = true;
        }
    }

    if (empty($externalKeys)) {
        return $allocations;
    }

    $filtered = [];
    foreach ($allocations as $allocation) {
        $internalKey = normalizeAllocationKey($allocation['department'] ?? '');
        if ($internalKey !== '' && isset($externalKeys[$internalKey])) {
            continue;
        }
        $filtered[] = $allocation;
    }

    return $filtered;
}

function normalizeAllocationKey($value) {
    $key = strtolower(trim((string)$value));
    $key = preg_replace('/\s+/', '', $key);
    $key = preg_replace('/[_-]+/', '', $key);
    return $key;
}

function clampForecastMonths($value, $default = 36, $max = 120) {
    $months = (int)$value;
    if ($months < 1) {
        return $default;
    }
    return min($months, $max);
}

function clampPredictMonths($value, $default = 12, $max = 36) {
    $months = (int)$value;
    if ($months < 1) {
        return $default;
    }
    return min($months, $max);
}

function forecastMonthsForTargetDate($targetDate, $defaultMonths = 12, $maxMonths = 36) {
    $target = DateTimeImmutable::createFromFormat('Y-m-d', (string)$targetDate);
    if (!$target) {
        return $defaultMonths;
    }

    $currentMonth = new DateTimeImmutable(date('Y-m-01'));
    $targetMonth = new DateTimeImmutable($target->format('Y-m-01'));
    if ($targetMonth < $currentMonth) {
        return 1;
    }

    $diff = $currentMonth->diff($targetMonth);
    $months = ($diff->y * 12) + $diff->m + 1;
    return min(max($months, 1), $maxMonths);
}

function runRandomForestForecast(array $history, int $predictMonths, bool $allowNegative = false) {
    $tmpFile = tempnam(sys_get_temp_dir(), 'budget_forecast_');
    if ($tmpFile === false) {
        return ['error' => 'Unable to prepare forecast training data'];
    }

    $csvPath = $tmpFile . '.csv';
    rename($tmpFile, $csvPath);

    $handle = fopen($csvPath, 'w');
    if ($handle === false) {
        @unlink($csvPath);
        return ['error' => 'Unable to write forecast training data'];
    }

    fputcsv($handle, ['date', 'amount']);
    foreach ($history as $item) {
        fputcsv($handle, [$item['date'], (float)$item['value']]);
    }
    fclose($handle);

    $scriptPath = realpath(__DIR__ . '/../scripts/budget_forecast.py');
    $result = run_python_script($scriptPath ?: '', [$csvPath, $predictMonths, $allowNegative ? 'allow_negative' : 'non_negative'], 45);
    @unlink($csvPath);

    if (($result['exit_code'] ?? -1) !== 0) {
        $fallback = buildPhpForecastFallback($history, $predictMonths, $allowNegative);
        $fallback['details']['python_error'] = trim((string)($result['stderr'] ?? $result['stdout'] ?? ''));
        return $fallback;
    }

    $payload = json_decode((string)$result['stdout'], true);
    if (!is_array($payload)) {
        $fallback = buildPhpForecastFallback($history, $predictMonths, $allowNegative);
        $fallback['details']['python_error'] = 'Random Forest forecast returned invalid JSON';
        return $fallback;
    }

    return $payload;
}

function buildPhpForecastFallback(array $history, int $predictMonths, bool $allowNegative = false) {
    $values = array_map(function ($item) {
        return (float)($item['value'] ?? 0);
    }, $history);

    $lastHistory = end($history);
    $lastDateValue = $lastHistory['date'] ?? date('Y-m-01');
    $lastDate = new DateTimeImmutable(date('Y-m-01', strtotime($lastDateValue)));
    $lastValue = count($values) ? (float)end($values) : 0.0;

    $growthRates = [];
    for ($i = 1; $i < count($values); $i++) {
        $previous = (float)$values[$i - 1];
        $growthRates[] = $previous == 0.0 ? 0.0 : (((float)$values[$i] - $previous) / $previous);
    }
    $averageGrowth = count($growthRates) ? array_sum($growthRates) / count($growthRates) : 0.0;

    $forecast = [];
    for ($i = 1; $i <= $predictMonths; $i++) {
        $lastValue = $lastValue * (1 + $averageGrowth);
        if (!$allowNegative) {
            $lastValue = max(0.0, $lastValue);
        }
        $forecast[] = [
            'date' => $lastDate->modify('+' . $i . ' months')->format('Y-m-01'),
            'value' => round($lastValue, 2)
        ];
    }

    return [
        'method' => 'php_avg_growth_fallback',
        'forecast' => $forecast,
        'details' => [
            'estimator' => 'PHP average growth fallback',
            'average_monthly_growth' => $averageGrowth,
            'training_rows' => count($history)
        ]
    ];
}

function buildMonthlyHistory(array $rows, $months) {
    $monthMap = [];
    foreach ($rows as $row) {
        if (empty($row['month'])) {
            continue;
        }

        $monthKey = date('Y-m-01', strtotime($row['month']));
        $monthMap[$monthKey] = (float)($row['amount'] ?? 0);
    }

    $endMonth = new DateTimeImmutable(date('Y-m-01'));
    $startMonth = $endMonth->modify('-' . max($months - 1, 0) . ' months');
    $history = [];

    for ($cursor = $startMonth; $cursor->getTimestamp() <= $endMonth->getTimestamp(); $cursor = $cursor->modify('+1 month')) {
        $monthKey = $cursor->format('Y-m-01');
        $history[] = [
            'date' => $monthKey,
            'value' => (float)($monthMap[$monthKey] ?? 0)
        ];
    }

    return $history;
}

function getForecastSourceDefinitions() {
    return [
        'disbursements' => [
            'label' => 'Disbursements',
            'direction' => 'expense',
            'table' => 'disbursements',
            'date_field' => 'disbursement_date',
            'amount_field' => 'amount',
            'description' => 'Approved and paid cash outflows recorded in the Disbursements module.',
            'sql' => "
                SELECT DATE_FORMAT(disbursement_date, '%Y-%m-01') as month,
                       'disbursements' as source,
                       COALESCE(SUM(amount), 0) as amount,
                       COUNT(*) as entries,
                       MIN(disbursement_date) as first_transaction_date,
                       MAX(disbursement_date) as last_transaction_date
                FROM disbursements
                WHERE disbursement_date IS NOT NULL
                  AND status IN ('approved', 'paid')
                  AND disbursement_date BETWEEN ? AND ?
                GROUP BY month
            "
        ],
        'payments_made' => [
            'label' => 'Payments Made',
            'direction' => 'expense',
            'table' => 'payments_made',
            'date_field' => 'payment_date',
            'amount_field' => 'amount',
            'description' => 'Vendor and operational payments captured in Accounts Payable workflows.',
            'sql' => "
                SELECT DATE_FORMAT(payment_date, '%Y-%m-01') as month,
                       'payments_made' as source,
                       COALESCE(SUM(amount), 0) as amount,
                       COUNT(*) as entries,
                       MIN(payment_date) as first_transaction_date,
                       MAX(payment_date) as last_transaction_date
                FROM payments_made
                WHERE payment_date IS NOT NULL
                  AND payment_date BETWEEN ? AND ?
                GROUP BY month
            "
        ],
        'bills' => [
            'label' => 'Bills',
            'direction' => 'expense',
            'table' => 'bills',
            'date_field' => 'bill_date',
            'amount_field' => 'total_amount',
            'description' => 'Recognized payables from the Bills and Accounts Payable module, excluding cancelled bills.',
            'sql' => "
                SELECT DATE_FORMAT(bill_date, '%Y-%m-01') as month,
                       'bills' as source,
                       COALESCE(SUM(total_amount), 0) as amount,
                       COUNT(*) as entries,
                       MIN(bill_date) as first_transaction_date,
                       MAX(bill_date) as last_transaction_date
                FROM bills
                WHERE bill_date IS NOT NULL
                  AND status <> 'cancelled'
                  AND bill_date BETWEEN ? AND ?
                GROUP BY month
            "
        ],
        'payments_received' => [
            'label' => 'Payments Received',
            'direction' => 'revenue',
            'table' => 'payments_received',
            'date_field' => 'payment_date',
            'amount_field' => 'amount',
            'description' => 'Cash collections recorded from customers, invoices, refunds, and credits.',
            'sql' => "
                SELECT DATE_FORMAT(payment_date, '%Y-%m-01') as month,
                       'payments_received' as source,
                       COALESCE(SUM(amount), 0) as amount,
                       COUNT(*) as entries,
                       MIN(payment_date) as first_transaction_date,
                       MAX(payment_date) as last_transaction_date
                FROM payments_received
                WHERE payment_date IS NOT NULL
                  AND payment_date BETWEEN ? AND ?
                GROUP BY month
            "
        ],
        'invoices' => [
            'label' => 'Invoices',
            'direction' => 'revenue',
            'table' => 'invoices',
            'date_field' => 'invoice_date',
            'amount_field' => 'total_amount',
            'description' => 'Recognized receivables from customer invoices, excluding cancelled invoices.',
            'sql' => "
                SELECT DATE_FORMAT(invoice_date, '%Y-%m-01') as month,
                       'invoices' as source,
                       COALESCE(SUM(total_amount), 0) as amount,
                       COUNT(*) as entries,
                       MIN(invoice_date) as first_transaction_date,
                       MAX(invoice_date) as last_transaction_date
                FROM invoices
                WHERE invoice_date IS NOT NULL
                  AND status <> 'cancelled'
                  AND invoice_date BETWEEN ? AND ?
                GROUP BY month
            "
        ],
        'budget_actuals' => [
            'label' => 'Budget Actuals',
            'direction' => 'expense',
            'table' => 'budget_actuals',
            'date_field' => 'transaction_date',
            'amount_field' => 'amount',
            'description' => 'Budget actual spend transactions tied to budgets, departments, categories, and accounts.',
            'sql' => "
                SELECT DATE_FORMAT(transaction_date, '%Y-%m-01') as month,
                       'budget_actuals' as source,
                       COALESCE(SUM(amount), 0) as amount,
                       COUNT(*) as entries,
                       MIN(transaction_date) as first_transaction_date,
                       MAX(transaction_date) as last_transaction_date
                FROM budget_actuals
                WHERE transaction_date IS NOT NULL
                  AND transaction_date BETWEEN ? AND ?
                GROUP BY month
            "
        ],
        'fixed_assets' => [
            'label' => 'Fixed Assets',
            'direction' => 'expense',
            'table' => 'fixed_assets',
            'date_field' => 'purchase_date',
            'amount_field' => 'purchase_cost',
            'description' => 'Capital asset purchases from the Fixed Assets module.',
            'sql' => "
                SELECT DATE_FORMAT(purchase_date, '%Y-%m-01') as month,
                       'fixed_assets' as source,
                       COALESCE(SUM(CASE WHEN purchase_cost > 0 THEN purchase_cost ELSE purchase_price END), 0) as amount,
                       COUNT(*) as entries,
                       MIN(purchase_date) as first_transaction_date,
                       MAX(purchase_date) as last_transaction_date
                FROM fixed_assets
                WHERE purchase_date IS NOT NULL
                  AND purchase_date BETWEEN ? AND ?
                GROUP BY month
            "
        ],
        'cashier_collections' => [
            'label' => 'Cashier Collections',
            'direction' => 'revenue',
            'table' => 'cashier_transactions',
            'date_field' => 'transaction_date',
            'amount_field' => 'amount',
            'description' => 'Cashier collections and deposits from cashier sessions and outlet activity.',
            'sql' => "
                SELECT DATE_FORMAT(transaction_date, '%Y-%m-01') as month,
                       'cashier_collections' as source,
                       COALESCE(SUM(amount), 0) as amount,
                       COUNT(*) as entries,
                       MIN(DATE(transaction_date)) as first_transaction_date,
                       MAX(DATE(transaction_date)) as last_transaction_date
                FROM cashier_transactions
                WHERE transaction_date IS NOT NULL
                  AND transaction_type IN ('collection', 'deposit')
                  AND DATE(transaction_date) BETWEEN ? AND ?
                GROUP BY month
            "
        ],
        'cashier_outflows' => [
            'label' => 'Cashier Outflows',
            'direction' => 'expense',
            'table' => 'cashier_transactions',
            'date_field' => 'transaction_date',
            'amount_field' => 'amount',
            'description' => 'Cashier payments, withdrawals, and negative adjustments from cashier sessions.',
            'sql' => "
                SELECT DATE_FORMAT(transaction_date, '%Y-%m-01') as month,
                       'cashier_outflows' as source,
                       COALESCE(SUM(amount), 0) as amount,
                       COUNT(*) as entries,
                       MIN(DATE(transaction_date)) as first_transaction_date,
                       MAX(DATE(transaction_date)) as last_transaction_date
                FROM cashier_transactions
                WHERE transaction_date IS NOT NULL
                  AND transaction_type IN ('payment', 'withdrawal', 'adjustment')
                  AND DATE(transaction_date) BETWEEN ? AND ?
                GROUP BY month
            "
        ],
        'imported_revenue' => [
            'label' => 'Imported Revenue',
            'direction' => 'revenue',
            'table' => 'imported_transactions',
            'date_field' => 'transaction_date',
            'amount_field' => 'amount',
            'description' => 'Revenue-like transactions imported from integrated systems such as hotel, POS, and restaurant systems.',
            'sql' => "
                SELECT DATE_FORMAT(transaction_date, '%Y-%m-01') as month,
                       'imported_revenue' as source,
                       COALESCE(SUM(amount), 0) as amount,
                       COUNT(*) as entries,
                       MIN(DATE(transaction_date)) as first_transaction_date,
                       MAX(DATE(transaction_date)) as last_transaction_date
                FROM imported_transactions
                WHERE transaction_date IS NOT NULL
                  AND status NOT IN ('rejected', 'duplicate')
                  AND LOWER(transaction_type) NOT REGEXP 'expense|payroll|supplier|invoice|procurement|cost|usage'
                  AND DATE(transaction_date) BETWEEN ? AND ?
                GROUP BY month
            "
        ],
        'imported_expenses' => [
            'label' => 'Imported Expenses',
            'direction' => 'expense',
            'table' => 'imported_transactions',
            'date_field' => 'transaction_date',
            'amount_field' => 'amount',
            'description' => 'Expense-like transactions imported from HR, logistics, procurement, inventory, and other integrated systems.',
            'sql' => "
                SELECT DATE_FORMAT(transaction_date, '%Y-%m-01') as month,
                       'imported_expenses' as source,
                       COALESCE(SUM(amount), 0) as amount,
                       COUNT(*) as entries,
                       MIN(DATE(transaction_date)) as first_transaction_date,
                       MAX(DATE(transaction_date)) as last_transaction_date
                FROM imported_transactions
                WHERE transaction_date IS NOT NULL
                  AND status NOT IN ('rejected', 'duplicate')
                  AND LOWER(transaction_type) REGEXP 'expense|payroll|supplier|invoice|procurement|cost|usage'
                  AND DATE(transaction_date) BETWEEN ? AND ?
                GROUP BY month
            "
        ],
        'daily_revenue_summary' => [
            'label' => 'Daily Revenue Summary',
            'direction' => 'revenue',
            'table' => 'daily_revenue_summary',
            'date_field' => 'business_date',
            'amount_field' => 'net_revenue',
            'description' => 'Daily summarized revenue by department, revenue center, source system, and category.',
            'sql' => "
                SELECT DATE_FORMAT(business_date, '%Y-%m-01') as month,
                       'daily_revenue_summary' as source,
                       COALESCE(SUM(net_revenue), 0) as amount,
                       COALESCE(SUM(total_transactions), COUNT(*)) as entries,
                       MIN(business_date) as first_transaction_date,
                       MAX(business_date) as last_transaction_date
                FROM daily_revenue_summary
                WHERE business_date IS NOT NULL
                  AND business_date BETWEEN ? AND ?
                GROUP BY month
            "
        ],
        'daily_expense_summary' => [
            'label' => 'Daily Expense Summary',
            'direction' => 'expense',
            'table' => 'daily_expense_summary',
            'date_field' => 'business_date',
            'amount_field' => 'total_amount',
            'description' => 'Daily summarized expenses by department, category, and source system.',
            'sql' => "
                SELECT DATE_FORMAT(business_date, '%Y-%m-01') as month,
                       'daily_expense_summary' as source,
                       COALESCE(SUM(total_amount), 0) as amount,
                       COALESCE(SUM(total_transactions), COUNT(*)) as entries,
                       MIN(business_date) as first_transaction_date,
                       MAX(business_date) as last_transaction_date
                FROM daily_expense_summary
                WHERE business_date IS NOT NULL
                  AND business_date BETWEEN ? AND ?
                GROUP BY month
            "
        ]
    ];
}

function resolveForecastCategory($category, array $sourceDefinitions) {
    $category = strtolower(trim((string)$category));
    $allowed = array_merge(['combined', 'expenses', 'revenue', 'net_cash_flow'], array_keys($sourceDefinitions));
    if (!in_array($category, $allowed, true)) {
        return 'combined';
    }
    return $category;
}

function initializeForecastSourceMap(array $sourceDefinitions) {
    $map = [];
    foreach ($sourceDefinitions as $sourceKey => $_definition) {
        $map[$sourceKey] = 0.0;
    }
    return $map;
}

function selectedForecastValue(array $breakdown, string $category, array $sourceDefinitions) {
    if (isset($sourceDefinitions[$category])) {
        return (float)($breakdown[$category] ?? 0);
    }

    $revenue = 0.0;
    $expenses = 0.0;
    foreach ($sourceDefinitions as $sourceKey => $definition) {
        $amount = (float)($breakdown[$sourceKey] ?? 0);
        if (($definition['direction'] ?? 'expense') === 'revenue') {
            $revenue += $amount;
        } else {
            $expenses += $amount;
        }
    }

    if ($category === 'revenue') {
        return $revenue;
    }
    if ($category === 'net_cash_flow') {
        return $revenue - $expenses;
    }

    return $expenses;
}

function forecastSourceIncludedInTraining(string $sourceKey, string $category, array $sourceDefinitions) {
    if (isset($sourceDefinitions[$category])) {
        return $sourceKey === $category;
    }
    if ($category === 'revenue') {
        return ($sourceDefinitions[$sourceKey]['direction'] ?? 'expense') === 'revenue';
    }
    if ($category === 'net_cash_flow') {
        return true;
    }

    return ($sourceDefinitions[$sourceKey]['direction'] ?? 'expense') === 'expense';
}

function getForecastData($db) {
    $months = clampForecastMonths($_GET['months'] ?? 36);
    $forecastDate = trim((string)($_GET['forecast_date'] ?? ''));
    $predictMonths = $forecastDate !== ''
        ? forecastMonthsForTargetDate($forecastDate, 12)
        : clampPredictMonths($_GET['predict_months'] ?? 12);
    $sourceDefinitions = getForecastSourceDefinitions();
    $category = resolveForecastCategory($_GET['category'] ?? 'combined', $sourceDefinitions);

    $endMonth = new DateTimeImmutable(date('Y-m-01'));
    $startDate = $endMonth->modify('-' . max($months - 1, 0) . ' months')->format('Y-m-01');
    $endDate = date('Y-m-d');

    $queryParts = [];
    $queryParams = [];
    foreach ($sourceDefinitions as $definition) {
        $queryParts[] = $definition['sql'];
        $queryParams[] = $startDate;
        $queryParams[] = $endDate;
    }

    $query = "
        SELECT month,
               source,
               SUM(amount) as amount,
               SUM(entries) as entries,
               MIN(first_transaction_date) as first_transaction_date,
               MAX(last_transaction_date) as last_transaction_date
        FROM (
            " . implode("\nUNION ALL\n", $queryParts) . "
        ) forecast_sources
        GROUP BY month, source
        ORDER BY month ASC, source ASC
    ";

    $stmt = $db->prepare($query);
    $stmt->execute($queryParams);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($rows)) {
        echo json_encode(['error' => 'Insufficient historical data for forecasting']);
        return;
    }

    $monthSourceMap = [];
    $sourceTotals = initializeForecastSourceMap($sourceDefinitions);
    $sourceEntries = array_fill_keys(array_keys($sourceDefinitions), 0);
    $sourceCoverage = [];
    foreach ($sourceDefinitions as $sourceKey => $_definition) {
        $sourceCoverage[$sourceKey] = [
            'first_transaction_date' => null,
            'last_transaction_date' => null
        ];
    }

    foreach ($rows as $row) {
        $monthKey = date('Y-m-01', strtotime((string)$row['month']));
        $sourceKey = $row['source'];
        if (!isset($sourceDefinitions[$sourceKey])) {
            continue;
        }
        $amount = (float)($row['amount'] ?? 0);
        $entries = (int)($row['entries'] ?? 0);

        if (!isset($monthSourceMap[$monthKey])) {
            $monthSourceMap[$monthKey] = initializeForecastSourceMap($sourceDefinitions);
        }

        $monthSourceMap[$monthKey][$sourceKey] = $amount;
        $sourceTotals[$sourceKey] += $amount;
        $sourceEntries[$sourceKey] += $entries;

        $firstDate = $row['first_transaction_date'] ?? null;
        $lastDate = $row['last_transaction_date'] ?? null;
        if ($firstDate && (!$sourceCoverage[$sourceKey]['first_transaction_date'] || $firstDate < $sourceCoverage[$sourceKey]['first_transaction_date'])) {
            $sourceCoverage[$sourceKey]['first_transaction_date'] = $firstDate;
        }
        if ($lastDate && (!$sourceCoverage[$sourceKey]['last_transaction_date'] || $lastDate > $sourceCoverage[$sourceKey]['last_transaction_date'])) {
            $sourceCoverage[$sourceKey]['last_transaction_date'] = $lastDate;
        }
    }

    $history = [];
    $endCursor = new DateTimeImmutable(date('Y-m-01'));
    $startCursor = $endCursor->modify('-' . max($months - 1, 0) . ' months');
    for ($cursor = $startCursor; $cursor->getTimestamp() <= $endCursor->getTimestamp(); $cursor = $cursor->modify('+1 month')) {
        $monthKey = $cursor->format('Y-m-01');
        $breakdown = $monthSourceMap[$monthKey] ?? initializeForecastSourceMap($sourceDefinitions);
        $selectedValue = selectedForecastValue($breakdown, $category, $sourceDefinitions);

        $history[] = [
            'date' => $monthKey,
            'value' => $selectedValue,
            'source_breakdown' => $breakdown
        ];
    }

    // summary
    $total = 0;
    foreach ($history as $h) $total += $h['value'];
    $avg = count($history) ? $total / count($history) : 0;

    $drivers = [];
    foreach ($sourceDefinitions as $sourceKey => $definition) {
        $recent = array_slice(array_map(function ($item) use ($sourceKey) {
            return (float)($item['source_breakdown'][$sourceKey] ?? 0);
        }, $history), -3);

        $drivers[] = [
            'source' => $sourceKey,
            'label' => $definition['label'],
            'direction' => $definition['direction'],
            'included_in_training' => forecastSourceIncludedInTraining($sourceKey, $category, $sourceDefinitions),
            'table' => $definition['table'],
            'date_field' => $definition['date_field'],
            'amount_field' => $definition['amount_field'],
            'total' => (float)$sourceTotals[$sourceKey],
            'average_monthly' => count($history) ? (float)$sourceTotals[$sourceKey] / count($history) : 0,
            'recent_average' => count($recent) ? array_sum($recent) / count($recent) : 0,
            'entries' => (int)$sourceEntries[$sourceKey],
            'share_percent' => ($total != 0.0 && forecastSourceIncludedInTraining($sourceKey, $category, $sourceDefinitions)) ? ((float)$sourceTotals[$sourceKey] / abs($total)) * 100 : 0,
            'first_transaction_date' => $sourceCoverage[$sourceKey]['first_transaction_date'],
            'last_transaction_date' => $sourceCoverage[$sourceKey]['last_transaction_date']
        ];
    }

    $modelResult = runRandomForestForecast($history, $predictMonths, $category === 'net_cash_flow');
    if (isset($modelResult['error'])) {
        $errorResponse = [
            'error' => $modelResult['error'],
            'details' => $modelResult['details'] ?? null,
            'history' => $history
        ];
        if (privacyModeEnabled()) {
            $errorResponse = redactForecastResponseForPrivacy($errorResponse);
        }
        echo json_encode($errorResponse);
        return;
    }

    $response = [
        'history' => $history,
        'forecast' => $modelResult['forecast'] ?? [],
        'summary' => [
            'months' => count($history),
            'predict_months' => $predictMonths,
            'forecast_date' => $forecastDate !== '' ? $forecastDate : null,
            'total' => (float)$total,
            'average_monthly' => (float)$avg,
            'selected_category' => $category,
            'source_totals' => $sourceTotals,
            'source_entries' => $sourceEntries,
            'source_count' => count($sourceDefinitions),
            'included_transactions' => array_sum($sourceEntries),
            'training_basis' => $category === 'combined' ? 'all expense/outflow sources' : $category
        ],
        'model' => [
            'method' => $modelResult['method'] ?? 'random_forest_regressor',
            'details' => $modelResult['details'] ?? null
        ],
        'drivers' => $drivers,
        'lineage' => array_map(function ($sourceKey, $definition) use ($sourceCoverage) {
            return [
                'source' => $sourceKey,
                'label' => $definition['label'],
                'direction' => $definition['direction'],
                'table' => $definition['table'],
                'date_field' => $definition['date_field'],
                'amount_field' => $definition['amount_field'],
                'description' => $definition['description'],
                'first_transaction_date' => $sourceCoverage[$sourceKey]['first_transaction_date'],
                'last_transaction_date' => $sourceCoverage[$sourceKey]['last_transaction_date']
            ];
        }, array_keys($sourceDefinitions), $sourceDefinitions),
        'method' => $modelResult['method'] ?? 'random_forest_regressor'
    ];

    if (privacyModeEnabled()) {
        $response = redactForecastResponseForPrivacy($response);
    }

    echo json_encode($response);
}

function redactForecastResponseForPrivacy(array $response) {
    foreach (['history', 'forecast'] as $seriesKey) {
        if (!isset($response[$seriesKey]) || !is_array($response[$seriesKey])) {
            continue;
        }

        foreach ($response[$seriesKey] as &$item) {
            if (!is_array($item)) {
                continue;
            }
            if (array_key_exists('value', $item)) {
                $item['value'] = null;
            }
            if (isset($item['source_breakdown']) && is_array($item['source_breakdown'])) {
                foreach ($item['source_breakdown'] as $source => $_value) {
                    $item['source_breakdown'][$source] = null;
                }
            }
        }
        unset($item);
    }

    if (isset($response['summary']) && is_array($response['summary'])) {
        foreach (['total', 'average_monthly'] as $key) {
            if (array_key_exists($key, $response['summary'])) {
                $response['summary'][$key] = null;
            }
        }
        if (isset($response['summary']['source_totals']) && is_array($response['summary']['source_totals'])) {
            foreach ($response['summary']['source_totals'] as $source => $_value) {
                $response['summary']['source_totals'][$source] = null;
            }
        }
    }

    if (isset($response['drivers']) && is_array($response['drivers'])) {
        foreach ($response['drivers'] as &$driver) {
            if (!is_array($driver)) {
                continue;
            }
            foreach (['total', 'average_monthly', 'recent_average', 'share_percent'] as $key) {
                if (array_key_exists($key, $driver)) {
                    $driver[$key] = null;
                }
            }
        }
        unset($driver);
    }

    $response['privacy_redacted'] = true;
    $response['privacy_message'] = 'Privacy mode is ON. Forecast amounts are redacted until OTP verification is completed.';

    return $response;
}

function getTrackingData($db) {
    $period = isset($_GET['period']) ? $_GET['period'] : 'year_to_date';
    $query = "
        SELECT
            bc.category_name as category,
            SUM(bi.budgeted_amount) as budget_amount,
            SUM(bi.actual_amount) as actual_amount,
            bc.category_type
        FROM budget_items bi
        JOIN budget_categories bc ON bi.category_id = bc.id
        JOIN budgets b ON bi.budget_id = b.id
        WHERE b.status IN ('approved', 'active')
    ";
    $params = [];

    if ($period === 'year_to_date') {
        $query .= " AND b.budget_year = YEAR(CURDATE())";
    }

    $query .= "
        GROUP BY bc.id, bc.category_name, bc.category_type
        ORDER BY bc.category_name
    ";

    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $trackingData = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Calculate summary
    $totalBudget = 0;
    $totalActual = 0;
    foreach ($trackingData as $item) {
        $totalBudget += $item['budget_amount'];
        $totalActual += $item['actual_amount'];
    }

    $variance = $totalActual - $totalBudget;
    $variancePercent = $totalBudget > 0 ? ($variance / $totalBudget) * 100 : 0;

    $summary = [
        'total_budget' => (float)$totalBudget,
        'actual_spent' => (float)$totalActual,
        'variance_percent' => (float)$variancePercent,
        'remaining' => (float)($totalBudget - $totalActual)
    ];

    echo json_encode([
        'tracking' => $trackingData,
        'summary' => $summary
    ]);
}

function getAlerts($db) {
    $alerts = calculateBudgetAlerts($db);
    echo json_encode(['alerts' => $alerts]);
}

function handlePost($db, $logger) {
    try {
        $data = json_decode(file_get_contents('php://input'), true);

        if (!$data) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid JSON data']);
            return;
        }

        $action = $data['action'] ?? null;
        if ($action === 'item') {
            createBudgetItem($db, $logger, $data);
            return;
        }

        if ($action === 'adjustment') {
            createAdjustment($db, $logger, $data);
            return;
        }

        if ($action === 'adjustment_status') {
            $id = isset($data['adjustment_id']) ? (int)$data['adjustment_id'] : null;
            if (!$id) {
                http_response_code(400);
                echo json_encode(['error' => 'Adjustment ID is required']);
                return;
            }
            updateAdjustmentStatus($db, $logger, $id, $data);
            return;
        }

        if ($action === 'category') {
            createCategory($db, $logger, $data);
            return;
        }

        // Validate required fields
        $required = ['name', 'start_date', 'end_date', 'total_amount'];
        foreach ($required as $field) {
            if (!isset($data[$field]) || empty($data[$field])) {
                http_response_code(400);
                echo json_encode(['error' => "Missing required field: $field"]);
                return;
            }
        }

        $oldBudgetStmt = $db->prepare("SELECT * FROM budgets WHERE id = ?");
        $oldBudgetStmt->execute([$id]);
        $oldBudget = $oldBudgetStmt->fetch(PDO::FETCH_ASSOC);

        $oldBudgetStmt = $db->prepare("SELECT * FROM budgets WHERE id = ?");
        $oldBudgetStmt->execute([$id]);
        $oldBudget = $oldBudgetStmt->fetch(PDO::FETCH_ASSOC);

        $db->beginTransaction();

        // Extract year from start_date
        $budgetYear = date('Y', strtotime($data['start_date']));

        // Insert budget
        $stmt = $db->prepare("
            INSERT INTO budgets (
                budget_year, budget_name, description, total_budgeted,
                status, created_by, department_id, vendor_id, start_date, end_date
            ) VALUES (?, ?, ?, ?, 'draft', ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $budgetYear,
            $data['name'],
            $data['description'] ?? '',
            $data['total_amount'],
            $_SESSION['user']['id'] ?? 1,
            $data['department_id'] ?? null,
            $data['vendor_id'] ?? null,
            $data['start_date'],
            $data['end_date']
        ]);

        $budgetId = $db->lastInsertId();

        $db->commit();

        $logger->log("Budget created: {$data['name']}", 'INFO');
        $logger->logUserAction(
            'created',
            'budgets',
            $budgetId,
            null,
            mergeAuditMeta([
                'budget_name' => $data['name'],
                'description' => $data['description'] ?? '',
                'total_budgeted' => $data['total_amount'],
                'department_id' => $data['department_id'] ?? null,
                'vendor_id' => $data['vendor_id'] ?? null,
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date']
            ], $data)
        );

        echo json_encode([
            'success' => true,
            'message' => 'Budget created successfully',
            'budget_id' => $budgetId
        ]);

    } catch (Exception $e) {
        $db->rollBack();
        $logger->log("Error in handlePost budgets: " . $e->getMessage(), 'ERROR');
        http_response_code(500);
        echo json_encode(['error' => 'Failed to create budget']);
    }
}

function handlePut($db, $logger) {
    try {
        $id = isset($_GET['id']) ? (int)$_GET['id'] : null;

        if (!$id) {
            http_response_code(400);
            echo json_encode(['error' => 'Budget ID is required']);
            return;
        }

        $data = json_decode(file_get_contents('php://input'), true);

        if (!$data) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid JSON data']);
            return;
        }

        if (isset($data['action']) && $data['action'] === 'adjustment_update') {
            updateAdjustmentDetails($db, $logger, $id, $data);
            return;
        }

        if (isset($data['action']) && $data['action'] === 'adjustment') {
            updateAdjustmentStatus($db, $logger, $id, $data);
            return;
        }

        $db->beginTransaction();

        // Update budget
        $stmt = $db->prepare("
            UPDATE budgets SET
                budget_name = ?,
                description = ?,
                total_budgeted = ?,
                department_id = ?,
                vendor_id = ?,
                start_date = ?,
                end_date = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");

        $stmt->execute([
            $data['name'],
            $data['description'] ?? '',
            $data['total_amount'],
            $data['department_id'] ?? null,
            $data['vendor_id'] ?? null,
            $data['start_date'] ?? null,
            $data['end_date'] ?? null,
            $id
        ]);

        $db->commit();

        $logger->log("Budget updated: $id", 'INFO');
        $logger->logUserAction(
            'updated',
            'budgets',
            $id,
            $oldBudget ?: null,
            mergeAuditMeta([
                'budget_name' => $data['name'],
                'description' => $data['description'] ?? '',
                'total_budgeted' => $data['total_amount'],
                'department_id' => $data['department_id'] ?? null,
                'vendor_id' => $data['vendor_id'] ?? null,
                'start_date' => $data['start_date'] ?? null,
                'end_date' => $data['end_date'] ?? null
            ], $data)
        );

        echo json_encode([
            'success' => true,
            'message' => 'Budget updated successfully'
        ]);

    } catch (Exception $e) {
        $db->rollBack();
        $logger->log("Error in handlePut budgets: " . $e->getMessage(), 'ERROR');
        http_response_code(500);
        echo json_encode(['error' => 'Failed to update budget']);
    }
}

function handleDelete($db, $logger) {
    try {
        $action = isset($_GET['action']) ? $_GET['action'] : null;
        if ($action === 'adjustment') {
            $id = isset($_GET['id']) ? (int)$_GET['id'] : null;
            if (!$id) {
                http_response_code(400);
                echo json_encode(['error' => 'Adjustment ID is required']);
                return;
            }
            deleteAdjustment($db, $logger, $id);
            return;
        }

        $id = isset($_GET['id']) ? (int)$_GET['id'] : null;

        if (!$id) {
            http_response_code(400);
            echo json_encode(['error' => 'Budget ID is required']);
            return;
        }

        $db->beginTransaction();

        // Delete budget (cascade will delete items)
        $stmt = $db->prepare("DELETE FROM budgets WHERE id = ?");
        $stmt->execute([$id]);

        $db->commit();

        $logger->log("Budget deleted: $id", 'INFO');
        $logger->logUserAction(
            'deleted',
            'budgets',
            $id,
            $oldBudget ?: null,
            mergeAuditMeta(['deleted' => true])
        );

        echo json_encode([
            'success' => true,
            'message' => 'Budget deleted successfully'
        ]);

    } catch (Exception $e) {
        $db->rollBack();
        $logger->log("Error in handleDelete budgets: " . $e->getMessage(), 'ERROR');
        http_response_code(500);
        echo json_encode(['error' => 'Failed to delete budget']);
    }
}

function createBudgetItem($db, $logger, $data) {
    $required = ['budget_id', 'category_id', 'budgeted_amount', 'account_id'];
    foreach ($required as $field) {
        if (!isset($data[$field]) || $data[$field] === '') {
            http_response_code(400);
            echo json_encode(['error' => "Missing required field: $field"]);
            return;
        }
    }

    $invalidAccounts = findInvalidChartOfAccountsIds($db, [$data['account_id']]);
    if (!empty($invalidAccounts)) {
        http_response_code(400);
        echo json_encode([
            'error' => 'Selected account is invalid or inactive.',
            'invalid_account_ids' => $invalidAccounts
        ]);
        return;
    }

    $db->beginTransaction();

    $stmt = $db->prepare("
        INSERT INTO budget_items
        (budget_id, category_id, department_id, account_id, vendor_id, budgeted_amount, notes)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $data['budget_id'],
        $data['category_id'],
        $data['department_id'] ?? null,
        $data['account_id'] ?? null,
        $data['vendor_id'] ?? null,
        $data['budgeted_amount'],
        $data['notes'] ?? ''
    ]);
    $itemId = $db->lastInsertId();

    $recalcStmt = $db->prepare("
        UPDATE budgets b
        JOIN (
            SELECT budget_id, COALESCE(SUM(budgeted_amount), 0) as total
            FROM budget_items
            WHERE budget_id = ?
            GROUP BY budget_id
        ) bi ON b.id = bi.budget_id
        SET b.total_budgeted = bi.total
        WHERE b.id = ?
    ");
    $recalcStmt->execute([$data['budget_id'], $data['budget_id']]);

    $db->commit();

    $logger->log("Budget item created for budget {$data['budget_id']}", 'INFO');
    $logger->logUserAction(
        'created',
        'budget_items',
        $itemId,
        null,
        mergeAuditMeta([
            'budget_id' => $data['budget_id'],
            'category_id' => $data['category_id'],
            'department_id' => $data['department_id'] ?? null,
            'account_id' => $data['account_id'] ?? null,
            'vendor_id' => $data['vendor_id'] ?? null,
            'budgeted_amount' => $data['budgeted_amount'],
            'notes' => $data['notes'] ?? ''
        ], $data)
    );

    echo json_encode([
        'success' => true,
        'message' => 'Budget item created successfully'
    ]);
}

function createAdjustment($db, $logger, $data) {
    $required = ['budget_id', 'adjustment_type', 'amount', 'department_id'];
    foreach ($required as $field) {
        if (!isset($data[$field]) || $data[$field] === '') {
            http_response_code(400);
            echo json_encode(['error' => "Missing required field: $field"]);
            return;
        }
    }

    $db->beginTransaction();

    $stmt = $db->prepare("
        INSERT INTO budget_adjustments
        (budget_id, department_id, vendor_id, adjustment_type, amount, reason, status, requested_by, effective_date)
        VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, ?)
    ");
    $stmt->execute([
        $data['budget_id'],
        $data['department_id'],
        $data['vendor_id'] ?? null,
        $data['adjustment_type'],
        $data['amount'],
        $data['reason'] ?? '',
        $_SESSION['user']['id'] ?? 1,
        $data['effective_date'] ?? null
    ]);
    $adjustmentId = $db->lastInsertId();

    $db->commit();

    $logger->log("Budget adjustment requested for budget {$data['budget_id']}", 'INFO');
    $logger->logUserAction(
        'requested',
        'budget_adjustments',
        $adjustmentId,
        null,
        mergeAuditMeta([
            'budget_id' => $data['budget_id'],
            'department_id' => $data['department_id'],
            'vendor_id' => $data['vendor_id'] ?? null,
            'adjustment_type' => $data['adjustment_type'],
            'amount' => $data['amount'],
            'reason' => $data['reason'] ?? '',
            'status' => 'pending',
            'effective_date' => $data['effective_date'] ?? null
        ], $data)
    );

    echo json_encode([
        'success' => true,
        'message' => 'Adjustment request submitted'
    ]);
}

function updateAdjustmentStatus($db, $logger, $id, $data) {
    $status = $data['status'] ?? null;
    if (!$status || !in_array($status, ['approved', 'rejected'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid adjustment status']);
        return;
    }

    $existingStmt = $db->prepare("SELECT * FROM budget_adjustments WHERE id = ?");
    $existingStmt->execute([$id]);
    $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);

    $db->beginTransaction();

    $stmt = $db->prepare("
        UPDATE budget_adjustments
        SET status = ?, approved_by = ?, updated_at = CURRENT_TIMESTAMP
        WHERE id = ?
    ");
    $stmt->execute([
        $status,
        $_SESSION['user']['id'] ?? 1,
        $id
    ]);

    if ($status === 'approved') {
        $adjustmentStmt = $db->prepare("
            SELECT budget_id, department_id, adjustment_type, amount
            FROM budget_adjustments
            WHERE id = ?
        ");
        $adjustmentStmt->execute([$id]);
        $adjustment = $adjustmentStmt->fetch(PDO::FETCH_ASSOC);

        if ($adjustment) {
            $amountDelta = $adjustment['adjustment_type'] === 'decrease'
                ? -1 * (float)$adjustment['amount']
                : (float)$adjustment['amount'];

            $updateItems = $db->prepare("
                UPDATE budget_items
                SET budgeted_amount = budgeted_amount + ?
                WHERE budget_id = ? AND department_id = ?
            ");
            $updateItems->execute([
                $amountDelta,
                $adjustment['budget_id'],
                $adjustment['department_id']
            ]);

            $recalcStmt = $db->prepare("
                UPDATE budgets b
                JOIN (
                    SELECT budget_id, COALESCE(SUM(budgeted_amount), 0) as total
                    FROM budget_items
                    WHERE budget_id = ?
                    GROUP BY budget_id
                ) bi ON b.id = bi.budget_id
                SET b.total_budgeted = bi.total
                WHERE b.id = ?
            ");
            $recalcStmt->execute([$adjustment['budget_id'], $adjustment['budget_id']]);
        }
    }

    $db->commit();

    $logger->log("Budget adjustment updated: {$id}", 'INFO');
    $logger->logUserAction(
        $status,
        'budget_adjustments',
        $id,
        $existing ?: null,
        mergeAuditMeta([
            'status' => $status
        ], $data)
    );

    echo json_encode([
        'success' => true,
        'message' => 'Adjustment updated'
    ]);
}

function updateAdjustmentDetails($db, $logger, $id, $data) {
    $required = ['budget_id', 'adjustment_type', 'amount', 'department_id'];
    foreach ($required as $field) {
        if (!isset($data[$field]) || $data[$field] === '') {
            http_response_code(400);
            echo json_encode(['error' => "Missing required field: $field"]);
            return;
        }
    }

    $stmt = $db->prepare("SELECT * FROM budget_adjustments WHERE id = ?");
    $stmt->execute([$id]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$existing) {
        http_response_code(404);
        echo json_encode(['error' => 'Adjustment not found']);
        return;
    }

    $vendorId = $data['vendor_id'] ?? null;
    if ($vendorId === '') {
        $vendorId = null;
    }

    $db->beginTransaction();

    $updateStmt = $db->prepare("
        UPDATE budget_adjustments
        SET budget_id = ?,
            department_id = ?,
            vendor_id = ?,
            adjustment_type = ?,
            amount = ?,
            reason = ?,
            effective_date = ?,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = ?
    ");
    $updateStmt->execute([
        $data['budget_id'],
        $data['department_id'],
        $vendorId,
        $data['adjustment_type'],
        $data['amount'],
        $data['reason'] ?? '',
        $data['effective_date'] ?? null,
        $id
    ]);

    if ($existing['status'] === 'approved') {
        $oldDelta = $existing['adjustment_type'] === 'decrease'
            ? -1 * (float)$existing['amount']
            : (float)$existing['amount'];
        $newDelta = $data['adjustment_type'] === 'decrease'
            ? -1 * (float)$data['amount']
            : (float)$data['amount'];

        $updateItems = $db->prepare("
            UPDATE budget_items
            SET budgeted_amount = budgeted_amount + ?
            WHERE budget_id = ? AND department_id = ?
        ");

        $updateItems->execute([
            -1 * $oldDelta,
            $existing['budget_id'],
            $existing['department_id']
        ]);

        $updateItems->execute([
            $newDelta,
            $data['budget_id'],
            $data['department_id']
        ]);

        recalcBudgetTotals($db, $existing['budget_id']);
        if ((int)$data['budget_id'] !== (int)$existing['budget_id']) {
            recalcBudgetTotals($db, $data['budget_id']);
        }
    }

    $db->commit();

    $logger->log("Budget adjustment details updated: {$id}", 'INFO');
    $logger->logUserAction(
        'updated',
        'budget_adjustments',
        $id,
        $existing ?: null,
        mergeAuditMeta([
            'budget_id' => $data['budget_id'],
            'department_id' => $data['department_id'],
            'vendor_id' => $vendorId,
            'adjustment_type' => $data['adjustment_type'],
            'amount' => $data['amount'],
            'reason' => $data['reason'] ?? '',
            'effective_date' => $data['effective_date'] ?? null,
            'status' => $existing['status'] ?? null
        ], $data)
    );

    echo json_encode([
        'success' => true,
        'message' => 'Adjustment details updated'
    ]);
}

function deleteAdjustment($db, $logger, $id) {
    $stmt = $db->prepare("SELECT * FROM budget_adjustments WHERE id = ?");
    $stmt->execute([$id]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$existing) {
        http_response_code(404);
        echo json_encode(['error' => 'Adjustment not found']);
        return;
    }

    $db->beginTransaction();

    if ($existing['status'] === 'approved') {
        $oldDelta = $existing['adjustment_type'] === 'decrease'
            ? -1 * (float)$existing['amount']
            : (float)$existing['amount'];

        $updateItems = $db->prepare("
            UPDATE budget_items
            SET budgeted_amount = budgeted_amount + ?
            WHERE budget_id = ? AND department_id = ?
        ");
        $updateItems->execute([
            -1 * $oldDelta,
            $existing['budget_id'],
            $existing['department_id']
        ]);

        recalcBudgetTotals($db, $existing['budget_id']);
    }

    $deleteStmt = $db->prepare("DELETE FROM budget_adjustments WHERE id = ?");
    $deleteStmt->execute([$id]);

    $db->commit();

    $logger->log("Budget adjustment deleted: {$id}", 'INFO');
    $logger->logUserAction(
        'deleted',
        'budget_adjustments',
        $id,
        $existing ?: null,
        mergeAuditMeta(['deleted' => true])
    );

    echo json_encode([
        'success' => true,
        'message' => 'Adjustment deleted'
    ]);
}

function recalcBudgetTotals($db, $budgetId) {
    $recalcStmt = $db->prepare("
        UPDATE budgets b
        JOIN (
            SELECT budget_id, COALESCE(SUM(budgeted_amount), 0) as total
            FROM budget_items
            WHERE budget_id = ?
            GROUP BY budget_id
        ) bi ON b.id = bi.budget_id
        SET b.total_budgeted = bi.total
        WHERE b.id = ?
    ");
    $recalcStmt->execute([$budgetId, $budgetId]);
}

function auditMeta($data = []) {
    return [
        'source' => $data['source'] ?? 'budget_management_ui',
        'module' => 'budget_management',
        'endpoint' => $_SERVER['REQUEST_URI'] ?? '',
        'origin' => $_SERVER['HTTP_REFERER'] ?? ''
    ];
}

function mergeAuditMeta($values, $data = []) {
    return array_merge($values ?? [], auditMeta($data));
}

function createCategory($db, $logger, $data) {
    $required = ['category_name', 'category_type'];
    foreach ($required as $field) {
        if (!isset($data[$field]) || $data[$field] === '') {
            http_response_code(400);
            echo json_encode(['error' => "Missing required field: $field"]);
            return;
        }
    }

    $rawCode = $data['category_code'] ?? $data['category_name'];
    $categoryCode = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '_', $rawCode));
    $categoryCode = trim($categoryCode, '_');
    if ($categoryCode === '') {
        $categoryCode = 'CAT_' . time();
    }
    $categoryCode = substr($categoryCode, 0, 30);

    $stmt = $db->prepare("
        INSERT INTO budget_categories
        (category_code, category_name, category_type, department_id, is_active)
        VALUES (?, ?, ?, ?, 1)
    ");
    $stmt->execute([
        $categoryCode,
        $data['category_name'],
        $data['category_type'],
        $data['department_id'] ?? null
    ]);
    $categoryId = $db->lastInsertId();

    $logger->log("Budget category created: {$data['category_name']}", 'INFO');
    $logger->logUserAction(
        'created',
        'budget_categories',
        $categoryId,
        null,
        mergeAuditMeta([
            'category_code' => $categoryCode,
            'category_name' => $data['category_name'],
            'category_type' => $data['category_type'],
            'department_id' => $data['department_id'] ?? null
        ], $data)
    );

    echo json_encode([
        'success' => true,
        'message' => 'Category created'
    ]);
}
?>

