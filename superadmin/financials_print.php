<?php
require_once '../includes/auth.php';
require_once '../includes/database.php';
require_once '../includes/logger.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$db = Database::getInstance()->getConnection();
$logger = Logger::getInstance();

// Read filters
$financialPeriod = isset($_GET['financial_period']) ? strtolower(trim($_GET['financial_period'])) : 'monthly';
$financialDateToInput = isset($_GET['financial_date']) ? trim($_GET['financial_date']) : date('Y-m-d');
$autoPrint = (isset($_GET['auto_print']) && $_GET['auto_print'] === '1');

$financialDateToObj = DateTime::createFromFormat('Y-m-d', $financialDateToInput) ?: new DateTime();
$financialDateTo = $financialDateToObj->format('Y-m-d');
$financialDateFromObj = clone $financialDateToObj;
switch ($financialPeriod) {
    case 'daily':
        break;
    case 'weekly':
        $financialDateFromObj->modify('monday this week');
        break;
    case 'monthly':
        $financialDateFromObj->modify('first day of this month');
        break;
    case 'quarterly':
        $quarterStartMonth = (int)(floor(((int)$financialDateToObj->format('n') - 1) / 3) * 3) + 1;
        $financialDateFromObj = new DateTime($financialDateToObj->format('Y') . '-' . str_pad((string)$quarterStartMonth, 2, '0', STR_PAD_LEFT) . '-01');
        break;
    case 'semi-annually':
    case 'semiannually':
        $halfStartMonth = ((int)$financialDateToObj->format('n') <= 6) ? 1 : 7;
        $financialDateFromObj = new DateTime($financialDateToObj->format('Y') . '-' . str_pad((string)$halfStartMonth, 2, '0', STR_PAD_LEFT) . '-01');
        break;
    case 'annually':
    case 'yearly':
        $financialDateFromObj->modify('first day of january');
        break;
    default:
        $financialPeriod = 'monthly';
        $financialDateFromObj->modify('first day of this month');
        break;
}
$financialDateFrom = $financialDateFromObj->format('Y-m-d');

// Fetch chart of accounts as of date
$chartOfAccountsFinancialStmt = $db->prepare("\n    SELECT\n        coa.id, coa.account_code, coa.account_name, coa.account_type, coa.description, coa.category,\n        COALESCE(SUM(\n            CASE\n                WHEN je.id IS NULL THEN 0\n                WHEN coa.account_type IN ('asset','expense')\n                    THEN COALESCE(jel.debit, 0) - COALESCE(jel.credit, 0)\n                ELSE COALESCE(jel.credit, 0) - COALESCE(jel.debit, 0)\n            END\n        ), 0) as balance\n    FROM chart_of_accounts coa\n    LEFT JOIN journal_entry_lines jel ON coa.id = jel.account_id\n    LEFT JOIN journal_entries je ON jel.journal_entry_id = je.id\n        AND (je.status = 'posted' OR je.status IS NULL OR je.status = '')\n        AND je.entry_date <= ?\n    WHERE coa.is_active = 1\n    GROUP BY coa.id, coa.account_code, coa.account_name, coa.account_type, coa.description, coa.category\n    ORDER BY coa.account_code ASC\n");
$chartOfAccountsFinancialStmt->execute([$financialDateTo]);
$chartOfAccountsFinancial = $chartOfAccountsFinancialStmt->fetchAll(PDO::FETCH_ASSOC);

// Compute totals (balance sheet and income statement)
$finTotalAssets = $finTotalLiabilities = $finTotalEquity = 0.0;
$finTotalRevenue = $finTotalExpenses = 0.0;
foreach ($chartOfAccountsFinancial as $row) {
    $balance = floatval($row['balance']);
    switch ($row['account_type']) {
        case 'asset': $finTotalAssets += $balance; break;
        case 'liability': $finTotalLiabilities += $balance; break;
        case 'equity': $finTotalEquity += $balance; break;
        case 'revenue': $finTotalRevenue += $balance; break;
        case 'expense': $finTotalExpenses += $balance; break;
    }
}
$finNetProfit = $finTotalRevenue - $finTotalExpenses;
$displayFinTotalAssets = abs($finTotalAssets);
$displayFinTotalLiabilities = abs($finTotalLiabilities);
$displayFinTotalEquity = abs($finTotalEquity);
$displayFinNetProfit = abs($finNetProfit);
$finNetProfitLabel = $finNetProfit < 0 ? 'Net Loss' : 'Net Profit';

// Cash-flow classification (reuse heuristics as in general_ledger)
$cashAccountCodesManual = [];
$cashAccountIds = [];
$allCoaStmt = $db->query("SELECT id, account_code, account_name, account_type, category FROM chart_of_accounts WHERE is_active = 1")->fetchAll(PDO::FETCH_ASSOC);
foreach ($allCoaStmt as $coaRow) {
    $name = strtolower($coaRow['account_name'] ?? '');
    $code = (string)($coaRow['account_code'] ?? '');
    $category = strtolower((string)($coaRow['category'] ?? ''));
    if (in_array($code, $cashAccountCodesManual, true)) {
        $cashAccountIds[] = $coaRow['id'];
        continue;
    }
    if ($coaRow['account_type'] === 'asset' && (stripos($name, 'cash') !== false || stripos($name, 'bank') !== false || $category === 'cash')) {
        $cashAccountIds[] = $coaRow['id'];
    }
}
$cashAccountIds = array_values(array_unique($cashAccountIds));

$finOperatingCF = $finInvestingCF = $finFinancingCF = 0.0;
$cashStartTotal = $cashEndTotal = $cashDelta = 0.0;
if (!empty($cashAccountIds)) {
    $stmt = $db->prepare("SELECT jel.journal_entry_id, jel.account_id, COALESCE(jel.debit,0) AS debit, COALESCE(jel.credit,0) AS credit, coa.account_type, coa.account_name FROM journal_entry_lines jel JOIN journal_entries je ON jel.journal_entry_id = je.id AND (je.status = 'posted' OR je.status IS NULL OR je.status = '') AND je.entry_date BETWEEN ? AND ? JOIN chart_of_accounts coa ON jel.account_id = coa.id ORDER BY jel.journal_entry_id");
    $stmt->execute([$financialDateFrom, $financialDateTo]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $entries = [];
    foreach ($rows as $r) {
        $jid = $r['journal_entry_id'];
        if (!isset($entries[$jid])) $entries[$jid] = [];
        $entries[$jid][] = $r;
    }
    foreach ($entries as $lines) {
        $cashMovement = 0.0;
        $classification = ['operating' => 0.0, 'investing' => 0.0, 'financing' => 0.0];
        foreach ($lines as $ln) {
            $amt = floatval($ln['debit']) - floatval($ln['credit']);
            if (in_array((int)$ln['account_id'], $cashAccountIds, true)) {
                $cashMovement += $amt;
            }
        }
        if (abs($cashMovement) < 0.01) continue;
        foreach ($lines as $ln) {
            if (in_array((int)$ln['account_id'], $cashAccountIds, true)) continue;
            $amt = floatval($ln['debit']) - floatval($ln['credit']);
            $cashEq = -$amt;
            $atype = strtolower((string)($ln['account_type'] ?? ''));
            if ($atype === 'revenue' || $atype === 'expense') {
                $classification['operating'] += $cashEq;
            } elseif ($atype === 'liability' || $atype === 'equity') {
                $classification['financing'] += $cashEq;
            } elseif ($atype === 'asset') {
                $aname = strtolower((string)($ln['account_name'] ?? ''));
                if (stripos($aname, 'receiv') !== false || stripos($aname, 'inventory') !== false || stripos($aname, 'prepaid') !== false) {
                    $classification['operating'] += $cashEq;
                } else {
                    $classification['investing'] += $cashEq;
                }
            } else {
                $classification['operating'] += $cashEq;
            }
        }
        $finOperatingCF += $classification['operating'];
        $finInvestingCF += $classification['investing'];
        $finFinancingCF += $classification['financing'];
    }
    $placeholders = implode(',', array_fill(0, count($cashAccountIds), '?'));
    $startDateObj = DateTime::createFromFormat('Y-m-d', $financialDateFrom) ?: new DateTime();
    $startDateObj->modify('-1 day');
    $cashStartDate = $startDateObj->format('Y-m-d');
    $cashBalStmt = $db->prepare("SELECT coa.id, COALESCE(SUM(CASE WHEN je.entry_date <= ? THEN (COALESCE(jel.debit,0) - COALESCE(jel.credit,0)) ELSE 0 END),0) as bal_start, COALESCE(SUM(CASE WHEN je.entry_date <= ? THEN (COALESCE(jel.debit,0) - COALESCE(jel.credit,0)) ELSE 0 END),0) as bal_end FROM chart_of_accounts coa LEFT JOIN journal_entry_lines jel ON coa.id = jel.account_id LEFT JOIN journal_entries je ON jel.journal_entry_id = je.id AND (je.status = 'posted' OR je.status IS NULL OR je.status = '') WHERE coa.id IN ($placeholders) GROUP BY coa.id");
    $params = array_merge([$cashStartDate, $financialDateTo], $cashAccountIds);
    $cashBalStmt->execute($params);
    $cashBalances = $cashBalStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cashBalances as $cb) {
        $cashStartTotal += floatval($cb['bal_start']);
        $cashEndTotal += floatval($cb['bal_end']);
    }
    $cashDelta = $cashEndTotal - $cashStartTotal;
}

// Logging
// Log print action to audit trail (use 'printed' for consistency with other print actions)
$logger->logUserAction('printed', 'financial_statements', null, null, [
    'filters' => [
        'financial_period' => $financialPeriod,
        'financial_date' => $financialDateTo
    ]
]);

$printedAt = date('M d, Y h:i A');

?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Financial Statements - Print</title>
<style>
    body { font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif; color: #1f2a37; margin: 20px; }
    .page { max-width: 900px; margin: 0 auto; }
    h1 { text-align: center; }
    .meta { text-align: center; color: #666; margin-bottom: 12px; }
    table { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
    th, td { padding: 8px 6px; border: 1px solid #ddd; }
    .text-right { text-align: right; }
    .watermark { position: fixed; left: 0; right: 0; top: 35%; margin: 0 auto; width: 60%; opacity: 0.08; z-index: -1; pointer-events: none; }
    @media print { .no-print { display: none; } }
</style>
</head>
<body>
<?php include_once __DIR__ . '/../includes/loading_screen.php'; ?>
<div class="page">
    <img src="../logo2.png" class="watermark" alt="ATIERA Watermark">
    <h1>Financial Statements</h1>
    <div class="meta">Period: <?php echo htmlspecialchars($financialDateFrom); ?> to <?php echo htmlspecialchars($financialDateTo); ?> | Generated: <?php echo $printedAt; ?></div>

    <h3>Balance Sheet</h3>
    <table>
        <tr><th>Assets</th><th class="text-right">Amount</th></tr>
        <tr><td>Total Assets</td><td class="text-right">&#8369;<?php echo number_format($displayFinTotalAssets,2); ?></td></tr>
        <tr><th>Liabilities & Equity</th><th class="text-right"></th></tr>
        <tr><td>Total Liabilities</td><td class="text-right">&#8369;<?php echo number_format($displayFinTotalLiabilities,2); ?></td></tr>
        <tr><td>Total Equity</td><td class="text-right">&#8369;<?php echo number_format($displayFinTotalEquity,2); ?></td></tr>
    </table>

    <h3>Income Statement</h3>
    <table>
        <tr><th>Revenue / Expense</th><th class="text-right">Amount</th></tr>
        <tr><td>Total Revenue</td><td class="text-right">&#8369;<?php echo number_format($finTotalRevenue,2); ?></td></tr>
        <tr><td>Total Expenses</td><td class="text-right">&#8369;<?php echo number_format($finTotalExpenses,2); ?></td></tr>
        <tr style="font-weight:600;"><td><?php echo htmlspecialchars($finNetProfitLabel); ?></td><td class="text-right">&#8369;<?php echo number_format($displayFinNetProfit,2); ?></td></tr>
    </table>

    <h3>Cash Flow (Classified)</h3>
    <table>
        <tr><td>Operating</td><td class="text-right">&#8369;<?php echo number_format($finOperatingCF,2); ?></td></tr>
        <tr><td>Investing</td><td class="text-right">&#8369;<?php echo number_format($finInvestingCF,2); ?></td></tr>
        <tr><td>Financing</td><td class="text-right">&#8369;<?php echo number_format($finFinancingCF,2); ?></td></tr>
        <tr style="font-weight:600;"><td>Net Cash Flow</td><td class="text-right">&#8369;<?php echo number_format(($finOperatingCF + $finInvestingCF + $finFinancingCF),2); ?></td></tr>
        <tr><td>Cash Balance Change (Reconciled)</td><td class="text-right">&#8369;<?php echo number_format($cashDelta,2); ?></td></tr>
    </table>

    <div style="margin-top:18px; color:#777; font-size:0.9em;">Generated by ATIERA Financial Management System on <?php echo $printedAt; ?></div>

    <div class="no-print" style="margin-top:18px; text-align:center;">
        <button onclick="window.print();" style="padding:8px 14px; margin-right:8px;">Print</button>
    </div>
</div>
<script>
if (<?php echo json_encode($autoPrint ? true : false); ?>) {
    window.onload = function() { window.print(); };
}
</script>
</body>
</html>
