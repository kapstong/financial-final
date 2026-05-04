<?php
// For API endpoints, we don't want to redirect on auth failure
// So we'll handle authentication differently
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/api_reports_admin_error.log');

error_log('[ADMIN API] Reports API called with params: ' . json_encode($_GET));

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Set up error handler to catch and output errors as JSON
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error: ' . $errstr]);
    exit(1);
}, E_ALL);

// Set up exception handler
set_exception_handler(function($exception) {
    http_response_code(500);
    echo json_encode(['error' => 'Exception: ' . $exception->getMessage()]);
    exit(1);
});

try {
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/logger.php';
require_once __DIR__ . '/../includes/auth.php';
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to load required files: ' . $e->getMessage()]);
    exit(1);
}

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

// Permission check for reports access
$auth = new Auth();
ensure_api_auth($method, [
    'GET' => 'reports.view',
    'POST' => 'reports.generate',
    'PUT' => 'reports.generate',
    'DELETE' => 'reports.generate',
    'PATCH' => 'reports.generate',
]);

try {
    switch ($method) {
        case 'GET':
            handleGet($db, $logger);
            break;
        case 'POST':
            handlePost($db, $logger);
            break;
        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
    }
} catch (Exception $e) {
    error_log("API Error in reports.php: " . $e->getMessage());
    if (isset($logger)) {
        $logger->log("API Error in reports.php: " . $e->getMessage(), 'ERROR');
    }
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error: ' . $e->getMessage()]);
}

function handleGet($db, $logger) {
    try {
        $reportType = isset($_GET['type']) ? $_GET['type'] : null;
        $dateFrom = isset($_GET['date_from']) ? $_GET['date_from'] : null;
        $dateTo = isset($_GET['date_to']) ? $_GET['date_to'] : null;
        $format = isset($_GET['format']) ? $_GET['format'] : 'json'; // json, csv, pdf

        if ($format !== 'json') {
        }

        if (!$reportType) {
            http_response_code(400);
            echo json_encode(['error' => 'Report type is required']);
            return;
        }

        // Set default date range if not provided (current month)
        if (!$dateFrom) {
            $dateFrom = date('Y-m-01'); // First day of current month
        }
        if (!$dateTo) {
            $dateTo = date('Y-m-t'); // Last day of current month
        }

        if ($reportType === 'trial_balance' && $format !== 'json') {
            $logger->logUserAction('exported', 'trial_balance', '', null, [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'format' => $format
            ]);
        }

        switch ($reportType) {
            case 'balance_sheet':
                generateBalanceSheet($db, $dateFrom, $dateTo, $format);
                break;
            case 'income_statement':
                generateIncomeStatement($db, $dateFrom, $dateTo, $format);
                break;
            case 'cash_flow':
                generateCashFlowStatement($db, $dateFrom, $dateTo, $format);
                break;
            case 'trial_balance':
                generateTrialBalance($db, $dateFrom, $dateTo, $format);
                break;
            case 'aging_receivable':
                generateAgingReceivable($db, $format);
                break;
            case 'aging_payable':
                generateAgingPayable($db, $format);
                break;
            case 'vendor_summary':
                generateVendorSummary($db, $dateFrom, $dateTo, $format);
                break;
            case 'customer_summary':
                generateCustomerSummary($db, $dateFrom, $dateTo, $format);
                break;
            case 'analytics_summary':
                generateAnalyticsSummary($db, $dateFrom, $dateTo, $format);
                break;
            case 'chart_of_accounts':
                $search = isset($_GET['search']) ? trim($_GET['search']) : '';
                $category = isset($_GET['category']) ? trim($_GET['category']) : '';
                generateChartOfAccounts($db, $format, $search, $category);
                break;
            default:
                http_response_code(400);
                echo json_encode(['error' => 'Invalid report type']);
                return;
        }

    } catch (Exception $e) {
        $logger->log("Error in handleGet reports: " . $e->getMessage(), 'ERROR');
        http_response_code(500);
        echo json_encode(['error' => 'Failed to generate report']);
    }
}

function generateBalanceSheet($db, $dateFrom, $dateTo, $format) {
    // Get asset accounts balances
    $stmt = $db->prepare("
        SELECT
            coa.account_name,
            coa.account_code,
            coa.account_type,
            COALESCE(SUM(CASE WHEN jel.debit > 0 THEN jel.debit ELSE -jel.credit END), 0) as balance,
            COALESCE(SUM(CASE WHEN jel.debit > 0 THEN jel.debit ELSE -jel.credit END), 0) as account_balance
        FROM chart_of_accounts coa
        LEFT JOIN journal_entry_lines jel ON coa.id = jel.account_id
        LEFT JOIN journal_entries je ON jel.journal_entry_id = je.id
        WHERE coa.account_type = 'asset'
        AND coa.is_active = 1
        AND je.entry_date <= ?
        GROUP BY coa.id, coa.account_name, coa.account_code, coa.account_type
        HAVING balance != 0
        ORDER BY coa.account_code
    ");
    $stmt->execute([$dateTo]);
    $assets = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get liability accounts balances
    $stmt = $db->prepare("
        SELECT
            coa.account_name,
            coa.account_code,
            coa.account_type,
            COALESCE(SUM(CASE WHEN jel.credit > 0 THEN jel.credit ELSE -jel.debit END), 0) as balance,
            COALESCE(SUM(CASE WHEN jel.credit > 0 THEN jel.credit ELSE -jel.debit END), 0) as account_balance
        FROM chart_of_accounts coa
        LEFT JOIN journal_entry_lines jel ON coa.id = jel.account_id
        LEFT JOIN journal_entries je ON jel.journal_entry_id = je.id
        WHERE coa.account_type = 'liability'
        AND coa.is_active = 1
        AND je.entry_date <= ?
        GROUP BY coa.id, coa.account_name, coa.account_code, coa.account_type
        HAVING balance != 0
        ORDER BY coa.account_code
    ");
    $stmt->execute([$dateTo]);
    $liabilities = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get equity accounts balances
    $stmt = $db->prepare("
        SELECT
            coa.account_name,
            coa.account_code,
            coa.account_type,
            COALESCE(SUM(CASE WHEN jel.credit > 0 THEN jel.credit ELSE -jel.debit END), 0) as balance,
            COALESCE(SUM(CASE WHEN jel.credit > 0 THEN jel.credit ELSE -jel.debit END), 0) as account_balance
        FROM chart_of_accounts coa
        LEFT JOIN journal_entry_lines jel ON coa.id = jel.account_id
        LEFT JOIN journal_entries je ON jel.journal_entry_id = je.id
        WHERE coa.account_type = 'equity'
        AND coa.is_active = 1
        AND je.entry_date <= ?
        GROUP BY coa.id, coa.account_name, coa.account_code, coa.account_type
        HAVING balance != 0
        ORDER BY coa.account_code
    ");
    $stmt->execute([$dateTo]);
    $equities = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Calculate retained earnings (net profit)
    $netProfit = calculateNetProfit($db, $dateFrom, $dateTo);

    $totalAssets = array_sum(array_column($assets, 'balance'));
    $totalLiabilities = array_sum(array_column($liabilities, 'balance'));
    $totalEquity = array_sum(array_column($equities, 'balance')) + $netProfit;

    $report = [
        'report_type' => 'Balance Sheet',
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'as_of_date' => $dateTo,
        'generated_at' => date('Y-m-d H:i:s'),
        'assets' => [
            'accounts' => $assets,
            'total' => $totalAssets
        ],
        'liabilities' => [
            'accounts' => $liabilities,
            'total' => $totalLiabilities
        ],
        'equity' => [
            'accounts' => $equities,
            'retained_earnings' => $netProfit,
            'total' => $totalEquity
        ],
        'total_liabilities_equity' => $totalLiabilities + $totalEquity
    ];

    outputReport($report, $format, 'balance_sheet');
}

function generateIncomeStatement($db, $dateFrom, $dateTo, $format) {
    try {
        // Get revenue accounts
        $stmt = $db->prepare("
            SELECT
                coa.account_name,
                coa.account_code,
                COALESCE(SUM(jel.credit - jel.debit), 0) as amount
            FROM chart_of_accounts coa
            LEFT JOIN journal_entry_lines jel ON coa.id = jel.account_id
            LEFT JOIN journal_entries je ON jel.journal_entry_id = je.id
            WHERE coa.account_type = 'revenue'
            AND coa.is_active = 1
            AND je.entry_date BETWEEN ? AND ?
            GROUP BY coa.id, coa.account_name, coa.account_code
            HAVING amount != 0
            ORDER BY coa.account_code
        ");
        $stmt->execute([$dateFrom, $dateTo]);
        $revenues = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Get expense accounts
        $stmt = $db->prepare("
            SELECT
                coa.account_name,
                coa.account_code,
                COALESCE(SUM(jel.debit - jel.credit), 0) as amount
            FROM chart_of_accounts coa
            LEFT JOIN journal_entry_lines jel ON coa.id = jel.account_id
            LEFT JOIN journal_entries je ON jel.journal_entry_id = je.id
            WHERE coa.account_type = 'expense'
            AND coa.is_active = 1
            AND je.entry_date BETWEEN ? AND ?
            GROUP BY coa.id, coa.account_name, coa.account_code
            HAVING amount != 0
            ORDER BY coa.account_code
        ");
        $stmt->execute([$dateFrom, $dateTo]);
        $expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $totalRevenue = array_sum(array_column($revenues, 'amount'));
        $totalExpenses = array_sum(array_column($expenses, 'amount'));
        $netProfit = $totalRevenue - $totalExpenses;

        $report = [
            'report_type' => 'Income Statement',
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'generated_at' => date('Y-m-d H:i:s'),
            'revenue' => [
                'accounts' => $revenues,
                'total' => $totalRevenue
            ],
            'expenses' => [
                'accounts' => $expenses,
                'total' => $totalExpenses
            ],
            'net_profit' => $netProfit
        ];

        outputReport($report, $format, 'income_statement');
    } catch (Exception $e) {
        // Return empty report structure on error
        $report = [
            'report_type' => 'Income Statement',
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'generated_at' => date('Y-m-d H:i:s'),
            'success' => true,
            'warning' => 'Unable to generate report data',
            'revenue' => [
                'accounts' => [],
                'total' => 0
            ],
            'expenses' => [
                'accounts' => [],
                'total' => 0
            ],
            'net_profit' => 0
        ];

        outputReport($report, $format, 'income_statement');
    }
}

function generateAnalyticsSummary($db, $dateFrom, $dateTo, $format) {
    try {
        // Get MTD (Month-to-Date) data
        $mtdFrom = date('Y-m-01'); // First day of current month
        $mtdTo = date('Y-m-t'); // Last day of current month
        
        $mtdRevenue = calculateRevenue($db, $mtdFrom, $mtdTo);
        $mtdExpenses = calculateExpenses($db, $mtdFrom, $mtdTo);
        $mtdNet = $mtdRevenue - $mtdExpenses;
        
        // Get trend data for last 12 months
        $trendLabels = [];
        $trendRevenue = [];
        $trendExpenses = [];
        
        for ($i = 11; $i >= 0; $i--) {
            $monthStart = date('Y-m-01', strtotime("-$i months"));
            $monthEnd = date('Y-m-t', strtotime("-$i months"));
            $monthLabel = date('M Y', strtotime("-$i months"));
            
            $trendLabels[] = $monthLabel;
            $trendRevenue[] = calculateRevenue($db, $monthStart, $monthEnd);
            $trendExpenses[] = calculateExpenses($db, $monthStart, $monthEnd);
        }
        
        $report = [
            'success' => true,
            'mtd' => [
                'revenue' => $mtdRevenue,
                'expenses' => $mtdExpenses,
                'net' => $mtdNet
            ],
            'trend' => [
                'labels' => $trendLabels,
                'revenue' => $trendRevenue,
                'expenses' => $trendExpenses
            ]
        ];
        
        outputReport($report, $format, 'analytics_summary');
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to generate analytics summary: ' . $e->getMessage()]);
    }
}

function calculateRevenue($db, $dateFrom, $dateTo) {
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(jel.credit - jel.debit), 0) as total
        FROM journal_entry_lines jel
        JOIN journal_entries je ON jel.journal_entry_id = je.id
        JOIN chart_of_accounts coa ON jel.account_id = coa.id
        WHERE coa.account_type = 'revenue'
        AND je.entry_date BETWEEN ? AND ?
    ");
    $stmt->execute([$dateFrom, $dateTo]);
    return (float)($stmt->fetch()['total'] ?? 0);
}

function calculateExpenses($db, $dateFrom, $dateTo) {
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(jel.debit - jel.credit), 0) as total
        FROM journal_entry_lines jel
        JOIN journal_entries je ON jel.journal_entry_id = je.id
        JOIN chart_of_accounts coa ON jel.account_id = coa.id
        WHERE coa.account_type = 'expense'
        AND je.entry_date BETWEEN ? AND ?
    ");
    $stmt->execute([$dateFrom, $dateTo]);
    return (float)($stmt->fetch()['total'] ?? 0);
}

function generateCashFlowStatement($db, $dateFrom, $dateTo, $format) {
    try {
        // Get revenue accounts for operating activities
        $stmt = $db->prepare("
            SELECT
                coa.account_name as name,
                coa.account_code,
                COALESCE(SUM(jel.credit - jel.debit), 0) as amount
            FROM chart_of_accounts coa
            LEFT JOIN journal_entry_lines jel ON coa.id = jel.account_id
            LEFT JOIN journal_entries je ON jel.journal_entry_id = je.id
            WHERE coa.account_type = 'revenue'
            AND coa.is_active = 1
            AND je.entry_date BETWEEN ? AND ?
            GROUP BY coa.id, coa.account_name, coa.account_code
            HAVING amount != 0
            ORDER BY coa.account_code
        ");
        $stmt->execute([$dateFrom, $dateTo]);
        $revenues = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $totalRevenue = array_sum(array_column($revenues, 'amount'));

        // Get expense accounts (operating expenses)
        $stmt = $db->prepare("
            SELECT
                coa.account_name as name,
                coa.account_code,
                COALESCE(SUM(jel.debit - jel.credit), 0) as amount
            FROM chart_of_accounts coa
            LEFT JOIN journal_entry_lines jel ON coa.id = jel.account_id
            LEFT JOIN journal_entries je ON jel.journal_entry_id = je.id
            WHERE coa.account_type = 'expense'
            AND coa.is_active = 1
            AND je.entry_date BETWEEN ? AND ?
            GROUP BY coa.id, coa.account_name, coa.account_code
            HAVING amount != 0
            ORDER BY coa.account_code
        ");
        $stmt->execute([$dateFrom, $dateTo]);
        $expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $totalExpenses = array_sum(array_column($expenses, 'amount'));

        // Group expenses by category for better reporting
        $expensesByCategory = [];
        foreach ($expenses as $expense) {
            $category = substr($expense['account_code'], 0, 2); // Group by first 2 chars of code
            if (!isset($expensesByCategory[$category])) {
                $expensesByCategory[$category] = [
                    'name' => $expense['name'],
                    'subcategory' => $category,
                    'amount' => 0,
                    'sources' => ''
                ];
            }
            $expensesByCategory[$category]['amount'] += $expense['amount'];
        }

        // Get investing activities (typically fixed asset changes)
        $stmt = $db->prepare("
            SELECT
                coa.account_name,
                COALESCE(SUM(CASE WHEN jel.debit > 0 THEN jel.debit ELSE -jel.credit END), 0) as amount
            FROM chart_of_accounts coa
            LEFT JOIN journal_entry_lines jel ON coa.id = jel.account_id
            LEFT JOIN journal_entries je ON jel.journal_entry_id = je.id
            WHERE coa.account_type = 'asset'
            AND coa.account_code LIKE 'FIXED%'
            AND coa.is_active = 1
            AND je.entry_date BETWEEN ? AND ?
            GROUP BY coa.id, coa.account_name
            HAVING amount != 0
            ORDER BY coa.account_code
        ");
        $stmt->execute([$dateFrom, $dateTo]);
        $investingAccounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $totalInvesting = array_sum(array_column($investingAccounts, 'amount'));

        // Get financing activities (loans, equity changes)
        $stmt = $db->prepare("
            SELECT
                coa.account_name,
                COALESCE(SUM(CASE WHEN jel.credit > 0 THEN jel.credit ELSE -jel.debit END), 0) as amount
            FROM chart_of_accounts coa
            LEFT JOIN journal_entry_lines jel ON coa.id = jel.account_id
            LEFT JOIN journal_entries je ON jel.journal_entry_id = je.id
            WHERE (coa.account_type = 'liability' OR coa.account_type = 'equity')
            AND coa.is_active = 1
            AND je.entry_date BETWEEN ? AND ?
            GROUP BY coa.id, coa.account_name
            HAVING amount != 0
            ORDER BY coa.account_code
        ");
        $stmt->execute([$dateFrom, $dateTo]);
        $financingAccounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $totalFinancing = array_sum(array_column($financingAccounts, 'amount'));

        // Calculate net cash flow
        $operatingCashFlow = $totalRevenue - $totalExpenses;
        $netCashFlow = $operatingCashFlow + $totalInvesting + $totalFinancing;

        $report = [
            'report_type' => 'Cash Flow Statement',
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'start_date' => $dateFrom,
            'end_date' => $dateTo,
            'generated_at' => date('Y-m-d H:i:s'),
            'operating_activities' => [
                'revenue' => $revenues,
                'total_revenue' => $totalRevenue,
                'expenses_by_category' => array_values($expensesByCategory),
                'expense_details' => $expenses,
                'total_expenses' => $totalExpenses,
                'amount' => $operatingCashFlow,
                'net_cash_operating' => $operatingCashFlow
            ],
            'investing_activities' => [
                'accounts' => $investingAccounts,
                'amount' => $totalInvesting,
                'net_cash_investing' => $totalInvesting
            ],
            'financing_activities' => [
                'accounts' => $financingAccounts,
                'amount' => $totalFinancing,
                'net_cash_financing' => $totalFinancing
            ],
            'cash_flow' => [
                'operating_activities' => [
                    'revenue' => $revenues,
                    'total_revenue' => $totalRevenue,
                    'expenses_by_category' => array_values($expensesByCategory),
                    'expense_details' => $expenses,
                    'total_expenses' => $totalExpenses,
                    'amount' => $operatingCashFlow
                ],
                'investing_activities' => [
                    'accounts' => $investingAccounts,
                    'amount' => $totalInvesting
                ],
                'financing_activities' => [
                    'accounts' => $financingAccounts,
                    'amount' => $totalFinancing
                ],
                'net_change' => $netCashFlow
            ],
            'net_cash_flow' => $netCashFlow
        ];

        outputReport($report, $format, 'cash_flow_statement');
    } catch (Exception $e) {
        // Return error response
        $report = [
            'report_type' => 'Cash Flow Statement',
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'start_date' => $dateFrom,
            'end_date' => $dateTo,
            'generated_at' => date('Y-m-d H:i:s'),
            'error' => 'Unable to generate report: ' . $e->getMessage(),
            'operating_activities' => [
                'revenue' => [],
                'total_revenue' => 0,
                'expenses_by_category' => [],
                'expense_details' => [],
                'total_expenses' => 0,
                'amount' => 0
            ],
            'investing_activities' => [
                'accounts' => [],
                'amount' => 0
            ],
            'financing_activities' => [
                'accounts' => [],
                'amount' => 0
            ],
            'net_cash_flow' => 0
        ];

        outputReport($report, $format, 'cash_flow_statement');
    }
}

function generateTrialBalance($db, $dateFrom, $dateTo, $format) {
    $stmt = $db->prepare("
        SELECT
            coa.account_code,
            coa.account_name,
            coa.account_type,
            COALESCE(SUM(jel.debit), 0) as debit_total,
            COALESCE(SUM(jel.credit), 0) as credit_total,
            (COALESCE(SUM(jel.debit), 0) - COALESCE(SUM(jel.credit), 0)) as balance
        FROM chart_of_accounts coa
        LEFT JOIN journal_entry_lines jel ON coa.id = jel.account_id
        LEFT JOIN journal_entries je ON jel.journal_entry_id = je.id
        WHERE coa.is_active = 1
        AND (je.status = 'posted' OR je.status IS NULL OR je.status = '')
        AND (je.entry_date IS NULL OR je.entry_date <= ?)
        GROUP BY coa.id, coa.account_code, coa.account_name, coa.account_type
        HAVING debit_total != 0 OR credit_total != 0
        ORDER BY coa.account_code
    ");
    $stmt->execute([$dateTo]);
    $rawAccounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $accounts = [];
    $totalDebits = 0;
    $totalCredits = 0;

    foreach ($rawAccounts as $row) {
        $normalDebit = in_array($row['account_type'], ['asset', 'expense'], true);
        $balance = $normalDebit
            ? (floatval($row['debit_total']) - floatval($row['credit_total']))
            : (floatval($row['credit_total']) - floatval($row['debit_total']));

        $debitBalance = 0;
        $creditBalance = 0;
        if ($balance >= 0) {
            if ($normalDebit) {
                $debitBalance = $balance;
            } else {
                $creditBalance = $balance;
            }
        } else {
            if ($normalDebit) {
                $creditBalance = abs($balance);
            } else {
                $debitBalance = abs($balance);
            }
        }

        $totalDebits += $debitBalance;
        $totalCredits += $creditBalance;

        $accounts[] = [
            'account_code' => $row['account_code'],
            'account_name' => $row['account_name'],
            'account_type' => $row['account_type'],
            'debit_total' => $row['debit_total'],
            'credit_total' => $row['credit_total'],
            'debit_balance' => $debitBalance,
            'credit_balance' => $creditBalance,
            'balance' => $balance
        ];
    }

    $report = [
        'report_type' => 'Trial Balance',
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'generated_at' => date('Y-m-d H:i:s'),
        'accounts' => $accounts,
        'totals' => [
            'debit' => $totalDebits,
            'credit' => $totalCredits,
            'difference' => abs($totalDebits - $totalCredits)
        ]
    ];

    outputReport($report, $format, 'trial_balance');
}

function generateChartOfAccounts($db, $format, $search = '', $category = '') {
    $conditions = ['is_active = 1'];
    $params = [];

    if ($search !== '') {
        $conditions[] = "(
            LOWER(account_code) LIKE ?
            OR LOWER(account_name) LIKE ?
            OR LOWER(account_type) LIKE ?
            OR LOWER(COALESCE(category, '')) LIKE ?
            OR LOWER(COALESCE(description, '')) LIKE ?
        )";
        $like = '%' . strtolower($search) . '%';
        $params = array_merge($params, [$like, $like, $like, $like, $like]);
    }

    if ($category !== '') {
        $categoryValue = strtolower($category);
        if ($categoryValue === 'uncategorized') {
            $conditions[] = "(category IS NULL OR TRIM(category) = '')";
        } else {
            $conditions[] = "LOWER(category) = ?";
            $params[] = $categoryValue;
        }
    }

    $whereClause = implode(' AND ', $conditions);
    $stmt = $db->prepare("
        SELECT
            account_code,
            account_name,
            account_type,
            category,
            description
        FROM chart_of_accounts
        WHERE $whereClause
        ORDER BY account_code ASC
    ");
    $stmt->execute($params);
    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($accounts as &$account) {
        $category = trim($account['category'] ?? '');
        if ($category === '') {
            $category = 'Uncategorized';
        }
        $account['category'] = $category;
        $account['description'] = $account['description'] ?? '';
    }
    unset($account);

    $report = [
        'report_type' => 'Chart of Accounts',
        'generated_at' => date('Y-m-d H:i:s'),
        'total_accounts' => count($accounts),
        'accounts' => $accounts
    ];

    outputReport($report, $format, 'chart_of_accounts');
}

function generateAgingReceivable($db, $format) {
    $stmt = $db->prepare("
        SELECT
            c.company_name as customer_name,
            i.invoice_number,
            i.total_amount,
            i.balance,
            DATEDIFF(CURDATE(), i.due_date) as days_overdue,
            CASE
                WHEN DATEDIFF(CURDATE(), i.due_date) <= 0 THEN 'current'
                WHEN DATEDIFF(CURDATE(), i.due_date) <= 30 THEN '1-30'
                WHEN DATEDIFF(CURDATE(), i.due_date) <= 60 THEN '31-60'
                WHEN DATEDIFF(CURDATE(), i.due_date) <= 90 THEN '61-90'
                ELSE '90+'
            END as aging_category
        FROM invoices i
        JOIN customers c ON i.customer_id = c.id
        WHERE i.balance > 0
        AND i.status IN ('sent', 'overdue')
        ORDER BY c.company_name, i.due_date
    ");
    $stmt->execute();
    $agingData = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Group by aging categories
    $categories = ['current' => [], '1-30' => [], '31-60' => [], '61-90' => [], '90+' => []];
    foreach ($agingData as $item) {
        $categories[$item['aging_category']][] = $item;
    }

    $totals = [];
    foreach ($categories as $category => $items) {
        $totals[$category] = array_sum(array_column($items, 'balance'));
    }

    $report = [
        'report_type' => 'Aging of Accounts Receivable',
        'generated_at' => date('Y-m-d H:i:s'),
        'aging_data' => $agingData,
        'categories' => $categories,
        'totals' => $totals,
        'grand_total' => array_sum($totals)
    ];

    outputReport($report, $format, 'aging_receivable');
}

function generateAgingPayable($db, $format) {
    $stmt = $db->prepare("
        SELECT
            v.company_name as vendor_name,
            b.bill_number,
            b.total_amount,
            b.balance,
            DATEDIFF(CURDATE(), b.due_date) as days_overdue,
            CASE
                WHEN DATEDIFF(CURDATE(), b.due_date) <= 0 THEN 'current'
                WHEN DATEDIFF(CURDATE(), b.due_date) <= 30 THEN '1-30'
                WHEN DATEDIFF(CURDATE(), b.due_date) <= 60 THEN '31-60'
                WHEN DATEDIFF(CURDATE(), b.due_date) <= 90 THEN '61-90'
                ELSE '90+'
            END as aging_category
        FROM bills b
        JOIN vendors v ON b.vendor_id = v.id
        WHERE b.balance > 0
        AND b.status IN ('approved', 'overdue')
        ORDER BY v.company_name, b.due_date
    ");
    $stmt->execute();
    $agingData = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Group by aging categories
    $categories = ['current' => [], '1-30' => [], '31-60' => [], '61-90' => [], '90+' => []];
    foreach ($agingData as $item) {
        $categories[$item['aging_category']][] = $item;
    }

    $totals = [];
    foreach ($categories as $category => $items) {
        $totals[$category] = array_sum(array_column($items, 'balance'));
    }

    $report = [
        'report_type' => 'Aging of Accounts Payable',
        'generated_at' => date('Y-m-d H:i:s'),
        'aging_data' => $agingData,
        'categories' => $categories,
        'totals' => $totals,
        'grand_total' => array_sum($totals)
    ];

    outputReport($report, $format, 'aging_payable');
}

function generateVendorSummary($db, $dateFrom, $dateTo, $format) {
    $stmt = $db->prepare("
        SELECT
            v.company_name,
            v.contact_person,
            COUNT(b.id) as bills_count,
            COALESCE(SUM(b.total_amount), 0) as total_billed,
            COALESCE(SUM(b.balance), 0) as outstanding_balance,
            COALESCE(SUM(p.amount), 0) as payments_received
        FROM vendors v
        LEFT JOIN bills b ON v.id = b.vendor_id AND b.created_at BETWEEN ? AND ?
        LEFT JOIN payments_made p ON v.id = p.vendor_id AND p.payment_date BETWEEN ? AND ?
        WHERE v.is_active = 1
        GROUP BY v.id, v.company_name, v.contact_person
        ORDER BY outstanding_balance DESC
    ");
    $stmt->execute([$dateFrom, $dateTo, $dateFrom, $dateTo]);
    $vendorData = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $report = [
        'report_type' => 'Vendor Summary Report',
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'generated_at' => date('Y-m-d H:i:s'),
        'vendors' => $vendorData,
        'totals' => [
            'total_vendors' => count($vendorData),
            'total_billed' => array_sum(array_column($vendorData, 'total_billed')),
            'total_outstanding' => array_sum(array_column($vendorData, 'outstanding_balance')),
            'total_payments' => array_sum(array_column($vendorData, 'payments_received'))
        ]
    ];

    outputReport($report, $format, 'vendor_summary');
}

function generateCustomerSummary($db, $dateFrom, $dateTo, $format) {
    $stmt = $db->prepare("
        SELECT
            c.company_name,
            c.contact_person,
            COUNT(i.id) as invoices_count,
            COALESCE(SUM(i.total_amount), 0) as total_invoiced,
            COALESCE(SUM(i.balance), 0) as outstanding_balance,
            COALESCE(SUM(p.amount), 0) as payments_received
        FROM customers c
        LEFT JOIN invoices i ON c.id = i.customer_id AND i.created_at BETWEEN ? AND ?
        LEFT JOIN payments_received p ON c.id = p.customer_id AND p.payment_date BETWEEN ? AND ?
        WHERE c.is_active = 1
        GROUP BY c.id, c.company_name, c.contact_person
        ORDER BY outstanding_balance DESC
    ");
    $stmt->execute([$dateFrom, $dateTo, $dateFrom, $dateTo]);
    $customerData = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $report = [
        'report_type' => 'Customer Summary Report',
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'generated_at' => date('Y-m-d H:i:s'),
        'customers' => $customerData,
        'totals' => [
            'total_customers' => count($customerData),
            'total_invoiced' => array_sum(array_column($customerData, 'total_invoiced')),
            'total_outstanding' => array_sum(array_column($customerData, 'outstanding_balance')),
            'total_payments' => array_sum(array_column($customerData, 'payments_received'))
        ]
    ];

    outputReport($report, $format, 'customer_summary');
}

function calculateNetProfit($db, $dateFrom, $dateTo) {
    // Calculate total revenue
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(jel.credit - jel.debit), 0) as total_revenue
        FROM journal_entry_lines jel
        JOIN journal_entries je ON jel.journal_entry_id = je.id
        JOIN chart_of_accounts coa ON jel.account_id = coa.id
        WHERE coa.account_type = 'revenue'
        AND je.entry_date BETWEEN ? AND ?
    ");
    $stmt->execute([$dateFrom, $dateTo]);
    $totalRevenue = $stmt->fetch()['total_revenue'] ?? 0;

    // Calculate total expenses
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(jel.debit - jel.credit), 0) as total_expenses
        FROM journal_entry_lines jel
        JOIN journal_entries je ON jel.journal_entry_id = je.id
        JOIN chart_of_accounts coa ON jel.account_id = coa.id
        WHERE coa.account_type = 'expense'
        AND je.entry_date BETWEEN ? AND ?
    ");
    $stmt->execute([$dateFrom, $dateTo]);
    $totalExpenses = $stmt->fetch()['total_expenses'] ?? 0;

    return $totalRevenue - $totalExpenses;
}

function sanitizeReportDisplayLabels($value) {
    if (is_array($value)) {
        foreach ($value as $key => $item) {
            if (is_string($item) && in_array($key, ['account_name', 'name'], true)) {
                $value[$key] = preg_replace('/\s*\(demo\)\s*$/i', '', trim($item));
            } else {
                $value[$key] = sanitizeReportDisplayLabels($item);
            }
        }
    }

    return $value;
}

function outputReport($report, $format, $filename) {
    $report = sanitizeReportDisplayLabels($report);

    switch ($format) {
        case 'csv':
            outputCSV($report, $filename);
            break;
        case 'pdf':
            outputPDF($report, $filename);
            break;
        case 'json':
        default:
            if (!isset($report['success'])) {
                $report['success'] = empty($report['error']);
            }
            echo json_encode($report);
            break;
    }
}

function outputPDF($report, $filename) {
    try {
        require_once __DIR__ . '/../includes/database.php';
        require_once __DIR__ . '/../includes/pdf_generator.php';
        
        // Create a new PDF instance for this report
        $pdfGenerator = PDFGenerator::getInstance();
        $pdfGenerator->resetPDF(); // Reset to ensure fresh PDF
        
        // Generate appropriate PDF based on report type
        switch ($filename) {
            case 'balance_sheet':
                $pdfGenerator->generateBalanceSheetPDF($report);
                break;
            case 'income_statement':
                $pdfGenerator->generateIncomeStatementPDF($report);
                break;
            case 'cash_flow_statement':
                $pdfGenerator->generateCashFlowPDF($report);
                break;
            case 'trial_balance':
                $pdfGenerator->generateTrialBalancePDF($report);
                break;
            default:
                // Fallback to financial report
                $pdfGenerator->generateFinancialReportPDF($report['date_from'] ?? date('Y-m-01'), $report['date_to'] ?? date('Y-m-d'));
        }
    } catch (Exception $e) {
        // Fallback to CSV if PDF generation fails
        error_log('PDF Generation failed: ' . $e->getMessage());
        outputCSV($report, $filename);
    }
}

function outputCSV($report, $filename) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '_' . date('Y-m-d') . '.csv"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    $output = fopen('php://output', 'w');

    // Write report header
    fputcsv($output, ['Report Type', $report['report_type']]);
    fputcsv($output, ['Generated At', $report['generated_at']]);
    if (isset($report['date_from']) && isset($report['date_to'])) {
        fputcsv($output, ['Period', $report['date_from'] . ' to ' . $report['date_to']]);
    }
    if (isset($report['as_of_date'])) {
        fputcsv($output, ['As of', $report['as_of_date']]);
    }
    fputcsv($output, []);

    // Write report-specific data based on type
    switch ($filename) {
        case 'balance_sheet':
            fputcsv($output, ['ASSETS']);
            fputcsv($output, ['Account', 'Balance']);
            if (isset($report['assets']['accounts'])) {
                foreach ($report['assets']['accounts'] as $account) {
                    fputcsv($output, [$account['account_name'], $account['account_balance'] ?? $account['balance'] ?? 0]);
                }
            }
            fputcsv($output, ['Total Assets', $report['assets']['total']]);
            fputcsv($output, []);

            fputcsv($output, ['LIABILITIES']);
            fputcsv($output, ['Account', 'Balance']);
            if (isset($report['liabilities']['accounts'])) {
                foreach ($report['liabilities']['accounts'] as $account) {
                    fputcsv($output, [$account['account_name'], $account['account_balance'] ?? $account['balance'] ?? 0]);
                }
            }
            fputcsv($output, ['Total Liabilities', $report['liabilities']['total']]);
            fputcsv($output, []);

            fputcsv($output, ['EQUITY']);
            fputcsv($output, ['Account', 'Balance']);
            if (isset($report['equity']['accounts'])) {
                foreach ($report['equity']['accounts'] as $account) {
                    fputcsv($output, [$account['account_name'], $account['account_balance'] ?? $account['balance'] ?? 0]);
                }
            }
            fputcsv($output, ['Retained Earnings', $report['equity']['retained_earnings']]);
            fputcsv($output, ['Total Equity', $report['equity']['total']]);
            break;

        case 'income_statement':
            fputcsv($output, ['REVENUE']);
            fputcsv($output, ['Account', 'Amount']);
            if (isset($report['revenue']['accounts'])) {
                foreach ($report['revenue']['accounts'] as $account) {
                    fputcsv($output, [$account['account_name'], $account['amount'] ?? 0]);
                }
            }
            fputcsv($output, ['Total Revenue', $report['revenue']['total']]);
            fputcsv($output, []);

            fputcsv($output, ['EXPENSES']);
            fputcsv($output, ['Account', 'Amount']);
            if (isset($report['expenses']['accounts'])) {
                foreach ($report['expenses']['accounts'] as $account) {
                    fputcsv($output, [$account['account_name'], $account['amount'] ?? 0]);
                }
            }
            fputcsv($output, ['Total Expenses', $report['expenses']['total']]);
            fputcsv($output, []);

            fputcsv($output, ['Net Profit', $report['net_profit']]);
            break;

        case 'cash_flow_statement':
            fputcsv($output, ['OPERATING ACTIVITIES']);
            
            fputcsv($output, ['Cash Inflows (Revenue)']);
            fputcsv($output, ['Account', 'Amount']);
            if (isset($report['operating_activities']['revenue'])) {
                foreach ($report['operating_activities']['revenue'] as $account) {
                    fputcsv($output, [$account['name'], $account['amount'] ?? 0]);
                }
            }
            fputcsv($output, ['Total Revenue', $report['operating_activities']['total_revenue'] ?? 0]);
            fputcsv($output, []);

            fputcsv($output, ['Cash Outflows (Operating Expenses)']);
            fputcsv($output, ['Category', 'Amount']);
            if (isset($report['operating_activities']['expenses_by_category'])) {
                foreach ($report['operating_activities']['expenses_by_category'] as $expense) {
                    fputcsv($output, [$expense['name'], $expense['amount'] ?? 0]);
                }
            }
            fputcsv($output, ['Total Operating Expenses', $report['operating_activities']['total_expenses'] ?? 0]);
            fputcsv($output, []);

            fputcsv($output, ['Net Cash from Operating Activities', $report['operating_activities']['amount'] ?? 0]);
            fputcsv($output, []);

            fputcsv($output, ['INVESTING ACTIVITIES']);
            if (isset($report['investing_activities']['accounts'])) {
                foreach ($report['investing_activities']['accounts'] as $account) {
                    fputcsv($output, [$account['account_name'], $account['amount'] ?? 0]);
                }
            }
            fputcsv($output, ['Net Cash from Investing Activities', $report['investing_activities']['amount'] ?? 0]);
            fputcsv($output, []);

            fputcsv($output, ['FINANCING ACTIVITIES']);
            if (isset($report['financing_activities']['accounts'])) {
                foreach ($report['financing_activities']['accounts'] as $account) {
                    fputcsv($output, [$account['account_name'], $account['amount'] ?? 0]);
                }
            }
            fputcsv($output, ['Net Cash from Financing Activities', $report['financing_activities']['amount'] ?? 0]);
            fputcsv($output, []);

            fputcsv($output, ['Net Change in Cash', $report['net_cash_flow'] ?? 0]);
            break;

        case 'trial_balance':
            fputcsv($output, ['Account Code', 'Account Name', 'Debit Balance', 'Credit Balance', 'Balance']);
            if (isset($report['accounts'])) {
                foreach ($report['accounts'] as $account) {
                    fputcsv($output, [
                        $account['account_code'],
                        $account['account_name'],
                        $account['debit_balance'],
                        $account['credit_balance'],
                        $account['balance']
                    ]);
                }
            }
            fputcsv($output, []);
            fputcsv($output, ['Total Debits', $report['totals']['debit']]);
            fputcsv($output, ['Total Credits', $report['totals']['credit']]);
            fputcsv($output, ['Difference', $report['totals']['difference']]);
            break;
        case 'chart_of_accounts':
            fputcsv($output, ['Account Code', 'Account Name', 'Type', 'Category', 'Description']);
            if (isset($report['accounts'])) {
                foreach ($report['accounts'] as $account) {
                    fputcsv($output, [
                        $account['account_code'],
                        $account['account_name'],
                        $account['account_type'],
                        $account['category'],
                        $account['description']
                    ]);
                }
            }
            fputcsv($output, []);
            fputcsv($output, ['Total Accounts', $report['total_accounts']]);
            break;

        // Add more cases for other report types as needed
        default:
            fputcsv($output, ['Report data in JSON format']);
            fputcsv($output, [json_encode($report)]);
    }

    fclose($output);
    exit();
}

/**
 * Handle POST requests - Email report functionality
 */
function handlePost($db, $logger) {
    try {
        // Get POST data
        $input = json_decode(file_get_contents('php://input'), true);

        if (!$input) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid JSON data']);
            return;
        }

        $action = $input['action'] ?? null;

        if ($action === 'email') {
            handleEmailReport($db, $logger, $input);
        } else {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
        }

    } catch (Exception $e) {
        error_log("Error in handlePost: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Internal server error: ' . $e->getMessage()]);
    }
}

/**
 * Send report via email
 */
function handleEmailReport($db, $logger, $data) {
    try {
        // Validate required fields
        $email = $data['email'] ?? null;
        $reportType = $data['report_type'] ?? null;
        $reportName = $data['report_name'] ?? 'Financial Report';

        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);
            echo json_encode(['error' => 'Valid email address is required']);
            return;
        }

        if (!$reportType) {
            http_response_code(400);
            echo json_encode(['error' => 'Report type is required']);
            return;
        }

        // Get report data
        $dateFrom = $data['date_from'] ?? date('Y-m-01');
        $dateTo = $data['date_to'] ?? date('Y-m-t');
        $reportData = $data['report_data'] ?? null;

        // If report data not provided, fetch it
        if (!$reportData) {
            // Fetch report data based on type
            $reportData = fetchReportData($db, $reportType, $dateFrom, $dateTo);
        }

        // Build email content
        $subject = "ATIERA Finance - {$reportName}";
        $message = buildReportEmailBody($reportName, $reportType, $reportData, $dateFrom, $dateTo);

        // Email functionality disabled - log instead
        if ($logger) {
            $logger->log("Report '{$reportName}' would be emailed to {$email} by user " . ($_SESSION['user']['username'] ?? 'unknown') . " (mail system disabled)", 'INFO');
        }

        http_response_code(200);
        echo json_encode([
                'success' => true,
                'message' => "Report email request logged (mail system disabled)"
            ]);

    } catch (Exception $e) {
        error_log("Error sending report email: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Error sending email: ' . $e->getMessage()]);
    }
}

/**
 * Fetch report data for email
 */
function fetchReportData($db, $reportType, $dateFrom, $dateTo) {
    // This is a simplified version - you can expand this based on report type
    switch ($reportType) {
        case 'balance_sheet':
        case 'balance-sheet':
            return ['type' => 'Balance Sheet', 'period' => "$dateFrom to $dateTo"];
        case 'income_statement':
        case 'income-statement':
            return ['type' => 'Income Statement', 'period' => "$dateFrom to $dateTo"];
        case 'cash_flow':
        case 'cash-flow':
            return ['type' => 'Cash Flow Statement', 'period' => "$dateFrom to $dateTo"];
        case 'trial_balance':
        case 'trial-balance':
            return ['type' => 'Trial Balance', 'period' => "$dateFrom to $dateTo"];
        case 'budget_variance':
        case 'budget-variance':
            return ['type' => 'Budget Variance Report', 'period' => "$dateFrom to $dateTo"];
        default:
            return ['type' => ucwords(str_replace(['_', '-'], ' ', $reportType)), 'period' => "$dateFrom to $dateTo"];
    }
}

/**
 * Build HTML email body for report
 */
function buildReportEmailBody($reportName, $reportType, $reportData, $dateFrom, $dateTo) {
    $periodText = date('M d, Y', strtotime($dateFrom)) . ' to ' . date('M d, Y', strtotime($dateTo));

    $html = '
    <div style="color: #333; line-height: 1.6;">
        <h2 style="color: #1b2f73; margin-bottom: 20px;">' . htmlspecialchars($reportName) . '</h2>

        <div style="background-color: #f8f9fa; border-left: 4px solid #1b2f73; padding: 15px; margin: 20px 0;">
            <p style="margin: 5px 0; font-size: 14px;"><strong>Report Type:</strong> ' . htmlspecialchars($reportType) . '</p>
            <p style="margin: 5px 0; font-size: 14px;"><strong>Period:</strong> ' . htmlspecialchars($periodText) . '</p>
            <p style="margin: 5px 0; font-size: 14px;"><strong>Generated:</strong> ' . date('M d, Y h:i A') . '</p>
        </div>

        <p style="font-size: 14px; color: #666; margin: 20px 0;">
            Your financial report has been generated successfully. Please log in to the ATIERA Financial Management System to view the complete details and download the report in your preferred format.
        </p>

        <div style="margin: 30px 0;">
            <a href="' . (Config::get('app.url') ?: 'http://localhost') . '/admin/reports.php"
               style="display: inline-block; background: linear-gradient(135deg, #1b2f73 0%, #0f1c49 100%); color: #ffffff; padding: 12px 30px; text-decoration: none; border-radius: 5px; font-weight: bold;">
                View Full Report
            </a>
        </div>

        <div style="background-color: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin: 20px 0;">
            <p style="margin: 0; color: #856404; font-size: 13px;">
                <strong>Note:</strong> This is a notification email. For detailed report data, charts, and export options, please access the system directly.
            </p>
        </div>

        <p style="font-size: 14px; color: #333; margin-top: 30px;">
            Best regards,<br>
            <strong>ATIERA Financial Management System</strong>
        </p>
    </div>';

    return $html;
}
?>

