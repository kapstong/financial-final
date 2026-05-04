<?php
// For API endpoints, we don't want to redirect on auth failure
// So we'll handle authentication differently
require_once '../includes/database.php';
require_once '../includes/logger.php';
require_once '../includes/coa_validation.php';
require_once '../includes/budget_alerts.php';
require_once '../includes/python_runner.php';

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

function runRandomForestForecast(array $history, int $predictMonths) {
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
    $result = run_python_script($scriptPath ?: '', [$csvPath, $predictMonths], 45);
    @unlink($csvPath);

    if (($result['exit_code'] ?? -1) !== 0) {
        return [
            'error' => 'Random Forest forecast failed',
            'details' => trim((string)($result['stderr'] ?? $result['stdout'] ?? ''))
        ];
    }

    $payload = json_decode((string)$result['stdout'], true);
    if (!is_array($payload)) {
        return ['error' => 'Random Forest forecast returned invalid JSON'];
    }

    return $payload;
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

function getForecastData($db) {
    $months = clampForecastMonths($_GET['months'] ?? 36);
    $forecastDate = trim((string)($_GET['forecast_date'] ?? ''));
    $predictMonths = $forecastDate !== ''
        ? forecastMonthsForTargetDate($forecastDate, 12)
        : clampPredictMonths($_GET['predict_months'] ?? 12);
    $category = strtolower(trim((string)($_GET['category'] ?? 'combined')));
    $allowedCategories = ['combined', 'disbursements', 'payments_made'];
    if (!in_array($category, $allowedCategories, true)) {
        $category = 'combined';
    }

    $endMonth = new DateTimeImmutable(date('Y-m-01'));
    $startDate = $endMonth->modify('-' . max($months - 1, 0) . ' months')->format('Y-m-01');
    $endDate = date('Y-m-d');

    $query = "
        SELECT month, source, SUM(amount) as amount, COUNT(*) as entries FROM (
            SELECT DATE_FORMAT(disbursement_date, '%Y-%m-01') as month, amount, 'disbursements' as source
            FROM disbursements
            WHERE disbursement_date IS NOT NULL
              AND disbursement_date BETWEEN ? AND ?
            UNION ALL
            SELECT DATE_FORMAT(payment_date, '%Y-%m-01') as month, amount, 'payments_made' as source
            FROM payments_made
            WHERE payment_date IS NOT NULL
              AND payment_date BETWEEN ? AND ?
        ) t
        GROUP BY month, source
        ORDER BY month ASC, source ASC
    ";

    $stmt = $db->prepare($query);
    $stmt->execute([$startDate, $endDate, $startDate, $endDate]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($rows)) {
        echo json_encode(['error' => 'Insufficient historical data for forecasting']);
        return;
    }

    $monthSourceMap = [];
    $sourceTotals = [
        'disbursements' => 0.0,
        'payments_made' => 0.0
    ];
    $sourceEntries = [
        'disbursements' => 0,
        'payments_made' => 0
    ];

    foreach ($rows as $row) {
        $monthKey = date('Y-m-01', strtotime((string)$row['month']));
        $sourceKey = $row['source'];
        $amount = (float)($row['amount'] ?? 0);
        $entries = (int)($row['entries'] ?? 0);

        if (!isset($monthSourceMap[$monthKey])) {
            $monthSourceMap[$monthKey] = [
                'disbursements' => 0.0,
                'payments_made' => 0.0
            ];
        }

        $monthSourceMap[$monthKey][$sourceKey] = $amount;
        $sourceTotals[$sourceKey] += $amount;
        $sourceEntries[$sourceKey] += $entries;
    }

    $history = [];
    $endCursor = new DateTimeImmutable(date('Y-m-01'));
    $startCursor = $endCursor->modify('-' . max($months - 1, 0) . ' months');
    for ($cursor = $startCursor; $cursor->getTimestamp() <= $endCursor->getTimestamp(); $cursor = $cursor->modify('+1 month')) {
        $monthKey = $cursor->format('Y-m-01');
        $breakdown = $monthSourceMap[$monthKey] ?? [
            'disbursements' => 0.0,
            'payments_made' => 0.0
        ];

        $selectedValue = 0.0;
        if ($category === 'disbursements') {
            $selectedValue = (float)$breakdown['disbursements'];
        } elseif ($category === 'payments_made') {
            $selectedValue = (float)$breakdown['payments_made'];
        } else {
            $selectedValue = (float)$breakdown['disbursements'] + (float)$breakdown['payments_made'];
        }

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
    $sourceLabels = [
        'disbursements' => 'Disbursements',
        'payments_made' => 'Payments Made'
    ];
    foreach ($sourceLabels as $sourceKey => $label) {
        $recent = array_slice(array_map(function ($item) use ($sourceKey) {
            return (float)($item['source_breakdown'][$sourceKey] ?? 0);
        }, $history), -3);

        $drivers[] = [
            'source' => $sourceKey,
            'label' => $label,
            'total' => (float)$sourceTotals[$sourceKey],
            'average_monthly' => count($history) ? (float)$sourceTotals[$sourceKey] / count($history) : 0,
            'recent_average' => count($recent) ? array_sum($recent) / count($recent) : 0,
            'entries' => (int)$sourceEntries[$sourceKey],
            'share_percent' => $total > 0 ? ((float)$sourceTotals[$sourceKey] / $total) * 100 : 0
        ];
    }

    $modelResult = runRandomForestForecast($history, $predictMonths);
    if (isset($modelResult['error'])) {
        echo json_encode([
            'error' => $modelResult['error'],
            'details' => $modelResult['details'] ?? null,
            'history' => $history
        ]);
        return;
    }

    echo json_encode([
        'history' => $history,
        'forecast' => $modelResult['forecast'] ?? [],
        'summary' => [
            'months' => count($history),
            'predict_months' => $predictMonths,
            'forecast_date' => $forecastDate !== '' ? $forecastDate : null,
            'total' => (float)$total,
            'average_monthly' => (float)$avg,
            'selected_category' => $category,
            'source_totals' => $sourceTotals
        ],
        'model' => [
            'method' => $modelResult['method'] ?? 'random_forest_regressor',
            'details' => $modelResult['details'] ?? null
        ],
        'drivers' => $drivers,
        'lineage' => [
            [
                'source' => 'disbursements',
                'label' => 'Disbursements',
                'description' => 'Posted outflows recorded in the Disbursements module.'
            ],
            [
                'source' => 'payments_made',
                'label' => 'Payments Made',
                'description' => 'Operational and vendor payments captured in AP workflows.'
            ]
        ],
        'method' => $modelResult['method'] ?? 'random_forest_regressor'
    ]);
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

