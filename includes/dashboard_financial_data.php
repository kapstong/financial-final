<?php

function dashboardFetchScalar(PDO $db, string $sql, array $params = []): float
{
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return (float)($row['total'] ?? 0);
}

function dashboardMonthWindows(int $months = 6): array
{
    $windows = [];
    for ($i = $months - 1; $i >= 0; $i--) {
        $month = date('Y-m', strtotime("-$i months"));
        $start = $month . '-01';
        $windows[] = [
            'start' => $start,
            'end' => date('Y-m-t', strtotime($start)),
            'label' => date('M Y', strtotime($start)),
        ];
    }

    return $windows;
}

function dashboardRecognizedRevenue(PDO $db, string $start, string $end): float
{
    return dashboardFetchScalar($db, "
        SELECT COALESCE(SUM(total), 0) AS total
        FROM (
            SELECT total_amount AS total
            FROM invoices
            WHERE status <> 'cancelled' AND invoice_date BETWEEN ? AND ?

            UNION ALL

            SELECT net_sales AS total
            FROM outlet_daily_sales
            WHERE business_date BETWEEN ? AND ?

            UNION ALL

            SELECT amount AS total
            FROM payments_received
            WHERE payment_date BETWEEN ? AND ? AND invoice_id IS NULL
        ) revenue_sources
    ", [$start, $end, $start, $end, $start, $end]);
}

function dashboardRecognizedExpenses(PDO $db, string $start, string $end): float
{
    return dashboardFetchScalar($db, "
        SELECT COALESCE(SUM(total), 0) AS total
        FROM (
            SELECT total_amount AS total
            FROM bills
            WHERE status <> 'cancelled' AND bill_date BETWEEN ? AND ?

            UNION ALL

            SELECT amount AS total
            FROM disbursements
            WHERE status IN ('approved', 'paid') AND disbursement_date BETWEEN ? AND ?

            UNION ALL

            SELECT amount AS total
            FROM payments_made
            WHERE payment_date BETWEEN ? AND ? AND bill_id IS NULL
        ) expense_sources
    ", [$start, $end, $start, $end, $start, $end]);
}

function dashboardCollections(PDO $db, string $start, string $end): float
{
    return dashboardFetchScalar($db, "
        SELECT COALESCE(SUM(amount), 0) AS total
        FROM payments_received
        WHERE payment_date BETWEEN ? AND ?
    ", [$start, $end]);
}

function dashboardDisbursements(PDO $db, string $start, string $end): float
{
    return dashboardFetchScalar($db, "
        SELECT COALESCE(SUM(total), 0) AS total
        FROM (
            SELECT amount AS total
            FROM payments_made
            WHERE payment_date BETWEEN ? AND ?

            UNION ALL

            SELECT amount AS total
            FROM disbursements
            WHERE status = 'paid' AND disbursement_date BETWEEN ? AND ?
        ) outgoing_cash
    ", [$start, $end, $start, $end]);
}

function dashboardCashBalance(PDO $db): float
{
    $bankBalance = dashboardFetchScalar($db, "
        SELECT COALESCE(SUM(current_balance), 0) AS total
        FROM bank_accounts
        WHERE is_active = 1
    ");

    if ($bankBalance != 0.0) {
        return $bankBalance;
    }

    $journalBalance = dashboardFetchScalar($db, "
        SELECT COALESCE(SUM(
            CASE
                WHEN jel.debit > 0 THEN jel.debit
                WHEN jel.credit > 0 THEN -jel.credit
                ELSE 0
            END
        ), 0) AS total
        FROM journal_entry_lines jel
        JOIN chart_of_accounts coa ON jel.account_id = coa.id
        JOIN journal_entries je ON jel.journal_entry_id = je.id
        WHERE coa.account_code IN ('1000', '1001') AND je.status = 'posted'
    ");

    if ($journalBalance != 0.0) {
        return $journalBalance;
    }

    return dashboardCollections($db, '1900-01-01', date('Y-m-d'))
        - dashboardDisbursements($db, '1900-01-01', date('Y-m-d'));
}

function dashboardBudgetActual(PDO $db, string $start, string $end): float
{
    return dashboardFetchScalar($db, "
        SELECT COALESCE(SUM(total), 0) AS total
        FROM (
            SELECT amount AS total
            FROM budget_actuals
            WHERE transaction_date BETWEEN ? AND ?

            UNION ALL

            SELECT amount AS total
            FROM disbursements
            WHERE status = 'paid' AND disbursement_date BETWEEN ? AND ?

            UNION ALL

            SELECT amount AS total
            FROM payments_made
            WHERE payment_date BETWEEN ? AND ? AND bill_id IS NULL
        ) actual_sources
    ", [$start, $end, $start, $end, $start, $end]);
}

function dashboardBudgeted(PDO $db, string $monthStart): float
{
    return dashboardFetchScalar($db, "
        SELECT COALESCE(SUM(
            CASE
                WHEN b.start_date IS NOT NULL AND b.end_date IS NOT NULL
                    THEN bi.budgeted_amount / GREATEST(1, TIMESTAMPDIFF(MONTH, b.start_date, b.end_date) + 1)
                ELSE bi.budgeted_amount / 12
            END
        ), 0) AS total
        FROM budget_items bi
        JOIN budgets b ON bi.budget_id = b.id
        JOIN budget_categories bc ON bi.category_id = bc.id
        WHERE b.budget_year = YEAR(?) AND bc.category_type = 'expense'
    ", [$monthStart]);
}

function getDashboardFinancialData(PDO $db): array
{
    $yearStart = date('Y-01-01');
    $yearEnd = date('Y-12-31');
    $monthWindows = dashboardMonthWindows();

    $chartData = [];
    $cashFlowData = [];
    $budgetActualData = [];

    foreach ($monthWindows as $window) {
        $revenue = dashboardRecognizedRevenue($db, $window['start'], $window['end']);
        $expenses = dashboardRecognizedExpenses($db, $window['start'], $window['end']);

        $chartData[] = [
            'month' => $window['label'],
            'revenue' => $revenue,
            'expenses' => $expenses,
        ];

        $cashFlowData[] = [
            'month' => $window['label'],
            'collections' => dashboardCollections($db, $window['start'], $window['end']),
            'disbursements' => dashboardDisbursements($db, $window['start'], $window['end']),
        ];

        $budgetActualData[] = [
            'month' => $window['label'],
            'budgeted' => dashboardBudgeted($db, $window['start']),
            'actual' => dashboardBudgetActual($db, $window['start'], $window['end']),
        ];
    }

    $incomeSourceData = $db->query("
        SELECT source, SUM(amount) AS amount
        FROM (
            SELECT
                CASE o.outlet_type
                    WHEN 'rooms' THEN 'Rooms'
                    WHEN 'restaurant' THEN 'Restaurant'
                    WHEN 'bar' THEN 'Bar'
                    WHEN 'banquet' THEN 'Banquet & Events'
                    WHEN 'spa' THEN 'Spa & Wellness'
                    ELSE 'Other Services'
                END AS source,
                ods.net_sales AS amount
            FROM outlet_daily_sales ods
            JOIN outlets o ON ods.outlet_id = o.id
            WHERE YEAR(ods.business_date) = YEAR(CURDATE())

            UNION ALL

            SELECT
                CASE
                    WHEN LOWER(ii.description) LIKE '%room%' OR LOWER(ii.description) LIKE '%accommodation%' THEN 'Rooms'
                    WHEN LOWER(ii.description) LIKE '%catering%' OR LOWER(ii.description) LIKE '%dinner%' OR LOWER(ii.description) LIKE '%coffee%' THEN 'Restaurant'
                    WHEN LOWER(ii.description) LIKE '%banquet%' OR LOWER(ii.description) LIKE '%conference%' OR LOWER(ii.description) LIKE '%event%' THEN 'Banquet & Events'
                    WHEN LOWER(ii.description) LIKE '%spa%' OR LOWER(ii.description) LIKE '%wellness%' THEN 'Spa & Wellness'
                    ELSE 'Other Services'
                END AS source,
                ii.line_total AS amount
            FROM invoice_items ii
            JOIN invoices i ON ii.invoice_id = i.id
            WHERE i.status <> 'cancelled' AND YEAR(i.invoice_date) = YEAR(CURDATE())
        ) income_sources
        GROUP BY source
        ORDER BY amount DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $incomeLabels = [];
    $incomeAmounts = [];
    foreach ($incomeSourceData as $item) {
        $incomeLabels[] = $item['source'];
        $incomeAmounts[] = (float)$item['amount'];
    }

    if (empty($incomeLabels)) {
        $incomeLabels = ['Rooms', 'Restaurant', 'Bar', 'Banquet & Events', 'Spa & Wellness', 'Other Services'];
        $incomeAmounts = [0, 0, 0, 0, 0, 0];
    }

    return [
        'totalIncome' => dashboardRecognizedRevenue($db, $yearStart, $yearEnd),
        'totalExpenses' => dashboardRecognizedExpenses($db, $yearStart, $yearEnd),
        'cashBalance' => dashboardCashBalance($db),
        'todayIncome' => dashboardRecognizedRevenue($db, date('Y-m-d'), date('Y-m-d')),
        'todayExpenses' => dashboardRecognizedExpenses($db, date('Y-m-d'), date('Y-m-d')),
        'chartData' => $chartData,
        'cashFlowData' => $cashFlowData,
        'budgetActualData' => $budgetActualData,
        'incomeLabels' => $incomeLabels,
        'incomeAmounts' => $incomeAmounts,
        'annualBudgetTotal' => dashboardFetchScalar($db, "
            SELECT COALESCE(SUM(bi.budgeted_amount), 0) AS total
            FROM budget_items bi
            JOIN budgets b ON bi.budget_id = b.id
            WHERE b.budget_year = YEAR(CURDATE())
        "),
    ];
}
