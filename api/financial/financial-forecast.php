<?php
require_once __DIR__ . '/../_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    api_send(['success' => false, 'error' => 'Method not allowed'], 405);
}

$monthsBack = intval($_GET['months_back'] ?? 6);
if ($monthsBack < 1 || $monthsBack > 24) {
    $monthsBack = 6;
}

function buildForecastMonthRange($monthsBack) {
    $endMonth = new DateTimeImmutable(date('Y-m-01'));
    $startMonth = $endMonth->modify('-' . max($monthsBack - 1, 0) . ' months');
    return [$startMonth, $endMonth];
}

function normalizeMonthlyTotals(array $rows, DateTimeImmutable $startMonth, DateTimeImmutable $endMonth) {
    $monthMap = [];
    foreach ($rows as $row) {
        if (empty($row['month_key'])) {
            continue;
        }
        $monthMap[$row['month_key']] = (float)($row['total'] ?? 0);
    }

    $normalized = [];
    for ($cursor = $startMonth; $cursor->getTimestamp() <= $endMonth->getTimestamp(); $cursor = $cursor->modify('+1 month')) {
        $monthKey = $cursor->format('Y-m');
        $normalized[] = [
            'month_key' => $monthKey,
            'total' => (float)($monthMap[$monthKey] ?? 0)
        ];
    }

    return $normalized;
}

function financialForecastSources() {
    return [
        'invoices' => [
            'type' => 'revenue',
            'label' => 'Invoices',
            'table' => 'invoices',
            'date_field' => 'invoice_date',
            'amount_field' => 'total_amount',
            'description' => 'Recognized receivables from customer invoices, excluding cancelled invoices.',
            'sql' => "SELECT DATE_FORMAT(invoice_date, '%Y-%m') as month_key, 'invoices' as source, COALESCE(SUM(total_amount), 0) as total, COUNT(*) as entries FROM invoices WHERE status <> 'cancelled' AND invoice_date BETWEEN ? AND ? GROUP BY month_key"
        ],
        'payments_received' => [
            'type' => 'revenue',
            'label' => 'Payments Received',
            'table' => 'payments_received',
            'date_field' => 'payment_date',
            'amount_field' => 'amount',
            'description' => 'Cash collections recorded from customers and invoice payments.',
            'sql' => "SELECT DATE_FORMAT(payment_date, '%Y-%m') as month_key, 'payments_received' as source, COALESCE(SUM(amount), 0) as total, COUNT(*) as entries FROM payments_received WHERE payment_date BETWEEN ? AND ? GROUP BY month_key"
        ],
        'outlet_daily_sales' => [
            'type' => 'revenue',
            'label' => 'Outlet Daily Sales',
            'table' => 'outlet_daily_sales',
            'date_field' => 'business_date',
            'amount_field' => 'net_sales',
            'description' => 'Daily outlet sales entered through financial outlet workflows.',
            'sql' => "SELECT DATE_FORMAT(business_date, '%Y-%m') as month_key, 'outlet_daily_sales' as source, COALESCE(SUM(net_sales), 0) as total, COUNT(*) as entries FROM outlet_daily_sales WHERE business_date BETWEEN ? AND ? GROUP BY month_key"
        ],
        'daily_revenue_summary' => [
            'type' => 'revenue',
            'label' => 'Daily Revenue Summary',
            'table' => 'daily_revenue_summary',
            'date_field' => 'business_date',
            'amount_field' => 'net_revenue',
            'description' => 'Aggregated daily revenue by department, revenue center, and source system.',
            'sql' => "SELECT DATE_FORMAT(business_date, '%Y-%m') as month_key, 'daily_revenue_summary' as source, COALESCE(SUM(net_revenue), 0) as total, COALESCE(SUM(total_transactions), COUNT(*)) as entries FROM daily_revenue_summary WHERE business_date BETWEEN ? AND ? GROUP BY month_key"
        ],
        'bills' => [
            'type' => 'expense',
            'label' => 'Bills',
            'table' => 'bills',
            'date_field' => 'bill_date',
            'amount_field' => 'total_amount',
            'description' => 'Recognized payables from vendor bills, excluding cancelled bills.',
            'sql' => "SELECT DATE_FORMAT(bill_date, '%Y-%m') as month_key, 'bills' as source, COALESCE(SUM(total_amount), 0) as total, COUNT(*) as entries FROM bills WHERE status <> 'cancelled' AND bill_date BETWEEN ? AND ? GROUP BY month_key"
        ],
        'disbursements' => [
            'type' => 'expense',
            'label' => 'Disbursements',
            'table' => 'disbursements',
            'date_field' => 'disbursement_date',
            'amount_field' => 'amount',
            'description' => 'Approved and paid outflows from the Disbursements module.',
            'sql' => "SELECT DATE_FORMAT(disbursement_date, '%Y-%m') as month_key, 'disbursements' as source, COALESCE(SUM(amount), 0) as total, COUNT(*) as entries FROM disbursements WHERE status IN ('approved', 'paid') AND disbursement_date BETWEEN ? AND ? GROUP BY month_key"
        ],
        'payments_made' => [
            'type' => 'expense',
            'label' => 'Payments Made',
            'table' => 'payments_made',
            'date_field' => 'payment_date',
            'amount_field' => 'amount',
            'description' => 'Vendor and operational payments from Accounts Payable workflows.',
            'sql' => "SELECT DATE_FORMAT(payment_date, '%Y-%m') as month_key, 'payments_made' as source, COALESCE(SUM(amount), 0) as total, COUNT(*) as entries FROM payments_made WHERE payment_date BETWEEN ? AND ? GROUP BY month_key"
        ],
        'budget_actuals' => [
            'type' => 'expense',
            'label' => 'Budget Actuals',
            'table' => 'budget_actuals',
            'date_field' => 'transaction_date',
            'amount_field' => 'amount',
            'description' => 'Budget actual spend by budget, category, department, and account.',
            'sql' => "SELECT DATE_FORMAT(transaction_date, '%Y-%m') as month_key, 'budget_actuals' as source, COALESCE(SUM(amount), 0) as total, COUNT(*) as entries FROM budget_actuals WHERE transaction_date BETWEEN ? AND ? GROUP BY month_key"
        ],
        'daily_expense_summary' => [
            'type' => 'expense',
            'label' => 'Daily Expense Summary',
            'table' => 'daily_expense_summary',
            'date_field' => 'business_date',
            'amount_field' => 'total_amount',
            'description' => 'Aggregated daily expenses by department, category, and source system.',
            'sql' => "SELECT DATE_FORMAT(business_date, '%Y-%m') as month_key, 'daily_expense_summary' as source, COALESCE(SUM(total_amount), 0) as total, COALESCE(SUM(total_transactions), COUNT(*)) as entries FROM daily_expense_summary WHERE business_date BETWEEN ? AND ? GROUP BY month_key"
        ]
    ];
}

function buildFinancialSourceHistory(PDO $db, array $sources, string $startDate, string $endDate, DateTimeImmutable $startMonth, DateTimeImmutable $endMonth) {
    $parts = [];
    $params = [];
    foreach ($sources as $source) {
        $parts[] = $source['sql'];
        $params[] = $startDate;
        $params[] = $endDate;
    }

    $stmt = $db->prepare("
        SELECT month_key, source, SUM(total) as total, SUM(entries) as entries
        FROM (" . implode(" UNION ALL ", $parts) . ") source_rows
        GROUP BY month_key, source
        ORDER BY month_key, source
    ");
    $stmt->execute($params);

    $sourceTotals = [];
    $sourceEntries = [];
    foreach ($sources as $key => $_source) {
        $sourceTotals[$key] = 0.0;
        $sourceEntries[$key] = 0;
    }

    $monthly = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $monthKey = $row['month_key'];
        $sourceKey = $row['source'];
        if (!isset($sources[$sourceKey])) {
            continue;
        }
        if (!isset($monthly[$monthKey])) {
            $monthly[$monthKey] = [];
        }
        $amount = (float)($row['total'] ?? 0);
        $entries = (int)($row['entries'] ?? 0);
        $monthly[$monthKey][$sourceKey] = $amount;
        $sourceTotals[$sourceKey] += $amount;
        $sourceEntries[$sourceKey] += $entries;
    }

    $history = [];
    for ($cursor = $startMonth; $cursor->getTimestamp() <= $endMonth->getTimestamp(); $cursor = $cursor->modify('+1 month')) {
        $monthKey = $cursor->format('Y-m');
        $total = 0.0;
        foreach ($monthly[$monthKey] ?? [] as $amount) {
            $total += (float)$amount;
        }
        $history[] = [
            'month_key' => $monthKey,
            'total' => $total,
            'source_breakdown' => $monthly[$monthKey] ?? []
        ];
    }

    return [$history, $sourceTotals, $sourceEntries];
}

list($startMonth, $endMonth) = buildForecastMonthRange($monthsBack);
$startDate = $startMonth->format('Y-m-01');
$endDate = date('Y-m-d');

$allSources = financialForecastSources();
$revenueSources = array_filter($allSources, function ($source) { return $source['type'] === 'revenue'; });
$expenseSources = array_filter($allSources, function ($source) { return $source['type'] === 'expense'; });

list($revenueRows, $revenueSourceTotals, $revenueSourceEntries) = buildFinancialSourceHistory($db, $revenueSources, $startDate, $endDate, $startMonth, $endMonth);
list($expenseRows, $expenseSourceTotals, $expenseSourceEntries) = buildFinancialSourceHistory($db, $expenseSources, $startDate, $endDate, $startMonth, $endMonth);

$revenueTotals = array_map(function($row) { return floatval($row['total']); }, $revenueRows);
$expenseTotals = array_map(function($row) { return floatval($row['total']); }, $expenseRows);

$avgRevenue = count($revenueTotals) ? array_sum($revenueTotals) / count($revenueTotals) : 0.0;
$avgExpense = count($expenseTotals) ? array_sum($expenseTotals) / count($expenseTotals) : 0.0;

$forecast = [];
for ($i = 1; $i <= 3; $i++) {
    $monthKey = $endMonth->modify("+{$i} months")->format('Y-m');
    $forecast[] = [
        'month' => $monthKey,
        'forecast_revenue' => $avgRevenue,
        'forecast_expenses' => $avgExpense,
        'forecast_profit' => $avgRevenue - $avgExpense
    ];
}

api_send([
    'success' => true,
    'endpoint' => '/api/financial/financial-forecast',
    'model' => 'baseline_average',
    'months_back' => $monthsBack,
    'history' => [
        'revenue' => $revenueRows,
        'expenses' => $expenseRows
    ],
    'forecast' => $forecast,
    'source_totals' => [
        'revenue' => $revenueSourceTotals,
        'expenses' => $expenseSourceTotals
    ],
    'source_entries' => [
        'revenue' => $revenueSourceEntries,
        'expenses' => $expenseSourceEntries
    ],
    'lineage' => array_map(function ($key, $source) {
        return [
            'source' => $key,
            'type' => $source['type'],
            'label' => $source['label'],
            'table' => $source['table'],
            'date_field' => $source['date_field'],
            'amount_field' => $source['amount_field'],
            'description' => $source['description']
        ];
    }, array_keys($allSources), $allSources)
]);
