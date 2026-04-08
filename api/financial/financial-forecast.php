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

list($startMonth, $endMonth) = buildForecastMonthRange($monthsBack);
$startDate = $startMonth->format('Y-m-01');
$endDate = date('Y-m-d');

$revenueStmt = $db->prepare("    SELECT DATE_FORMAT(business_date, '%Y-%m') as month_key,
           COALESCE(SUM(net_revenue), 0) as total
    FROM daily_revenue_summary
    WHERE business_date BETWEEN ? AND ?
    GROUP BY month_key
    ORDER BY month_key
");
$revenueStmt->execute([$startDate, $endDate]);
$revenueRows = normalizeMonthlyTotals($revenueStmt->fetchAll(PDO::FETCH_ASSOC), $startMonth, $endMonth);

$expenseStmt = $db->prepare("    SELECT DATE_FORMAT(business_date, '%Y-%m') as month_key,
           COALESCE(SUM(total_amount), 0) as total
    FROM daily_expense_summary
    WHERE business_date BETWEEN ? AND ?
    GROUP BY month_key
    ORDER BY month_key
");
$expenseStmt->execute([$startDate, $endDate]);
$expenseRows = normalizeMonthlyTotals($expenseStmt->fetchAll(PDO::FETCH_ASSOC), $startMonth, $endMonth);

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
    'forecast' => $forecast
]);

