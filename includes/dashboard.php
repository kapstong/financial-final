<?php
/**
 * ATIERA Financial Management System - Dashboard Customization
 * Comprehensive dashboard widget system with drag-and-drop customization
 */

class DashboardManager {
    private static $instance = null;
    private $db;
    private $availableWidgets = [];

    private function __construct() {
        $this->db = Database::getInstance()->getConnection();
        $this->initializeWidgets();
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Initialize available dashboard widgets
     */
    private function initializeWidgets() {
        $this->availableWidgets = [
            // Financial Overview Widgets
            'financial_summary' => [
                'name' => 'Financial Summary',
                'description' => 'Total revenue, expenses, and profit overview',
                'category' => 'financial',
                'size' => 'large',
                'permissions' => ['dashboard.view'],
                'refresh_interval' => 300, // 5 minutes
                'default_config' => ['show_chart' => true, 'period' => 'month']
            ],
            'cash_flow' => [
                'name' => 'Cash Flow',
                'description' => 'Cash inflow and outflow tracking',
                'category' => 'financial',
                'size' => 'medium',
                'permissions' => ['dashboard.view'],
                'refresh_interval' => 300,
                'default_config' => ['period' => 'month', 'show_trend' => true]
            ],
            'accounts_receivable' => [
                'name' => 'Accounts Receivable',
                'description' => 'Outstanding invoices and collection status',
                'category' => 'receivables',
                'size' => 'medium',
                'permissions' => ['invoices.view'],
                'refresh_interval' => 600, // 10 minutes
                'default_config' => ['show_overdue' => true, 'limit' => 10]
            ],
            'accounts_payable' => [
                'name' => 'Accounts Payable',
                'description' => 'Outstanding bills and payment status',
                'category' => 'payables',
                'size' => 'medium',
                'permissions' => ['bills.view'],
                'refresh_interval' => 600,
                'default_config' => ['show_due_soon' => true, 'limit' => 10]
            ],

            // Operational Widgets
            'recent_transactions' => [
                'name' => 'Recent Transactions',
                'description' => 'Latest journal entries and payments',
                'category' => 'transactions',
                'size' => 'large',
                'permissions' => ['journal.view'],
                'refresh_interval' => 180, // 3 minutes
                'default_config' => ['limit' => 10, 'show_amounts' => true]
            ],
            'task_summary' => [
                'name' => 'Task Summary',
                'description' => 'Pending and overdue tasks overview',
                'category' => 'tasks',
                'size' => 'small',
                'permissions' => ['tasks.view'],
                'refresh_interval' => 300,
                'default_config' => ['show_assigned_to_me' => true]
            ],
            'budget_overview' => [
                'name' => 'Budget Overview',
                'description' => 'Budget vs actual spending comparison',
                'category' => 'budget',
                'size' => 'medium',
                'permissions' => ['budgets.view'],
                'refresh_interval' => 3600, // 1 hour
                'default_config' => ['show_variance' => true, 'period' => 'current_year']
            ],

            // Analytics Widgets
            'top_customers' => [
                'name' => 'Top Customers',
                'description' => 'Highest revenue generating customers',
                'category' => 'analytics',
                'size' => 'small',
                'permissions' => ['customers.view'],
                'refresh_interval' => 1800, // 30 minutes
                'default_config' => ['limit' => 5, 'period' => 'quarter']
            ],
            'revenue_chart' => [
                'name' => 'Revenue Chart',
                'description' => 'Revenue trends over time',
                'category' => 'analytics',
                'size' => 'large',
                'permissions' => ['reports.view'],
                'refresh_interval' => 3600,
                'default_config' => ['chart_type' => 'line', 'period' => '6months']
            ],
            'expense_breakdown' => [
                'name' => 'Expense Breakdown',
                'description' => 'Expenses categorized by type',
                'category' => 'analytics',
                'size' => 'medium',
                'permissions' => ['reports.view'],
                'refresh_interval' => 3600,
                'default_config' => ['show_percentage' => true, 'period' => 'month']
            ],

            // System Widgets
            'system_status' => [
                'name' => 'System Status',
                'description' => 'Server and application health indicators',
                'category' => 'system',
                'size' => 'small',
                'permissions' => ['admin.view'],
                'refresh_interval' => 60, // 1 minute
                'default_config' => ['show_disk_usage' => true, 'show_memory' => true]
            ],
            'recent_activity' => [
                'name' => 'Recent Activity',
                'description' => 'Latest user actions and system events',
                'category' => 'system',
                'size' => 'medium',
                'permissions' => ['audit.view'],
                'refresh_interval' => 120, // 2 minutes
                'default_config' => ['limit' => 10, 'show_user_actions' => true]
            ],


            // Quick Actions Widgets
            'quick_actions' => [
                'name' => 'Quick Actions',
                'description' => 'Frequently used actions and shortcuts',
                'category' => 'actions',
                'size' => 'small',
                'permissions' => ['dashboard.view'],
                'refresh_interval' => 0, // Static
                'default_config' => ['show_create_invoice' => true, 'show_create_bill' => true]
            ],
            'bookmarks' => [
                'name' => 'Bookmarks',
                'description' => 'Quick access to favorite pages and reports',
                'category' => 'actions',
                'size' => 'small',
                'permissions' => ['dashboard.view'],
                'refresh_interval' => 0,
                'default_config' => ['max_bookmarks' => 8]
            ]
        ];
    }

    /**
     * Get user's dashboard layout
     */
    public function getUserDashboard($userId) {
        try {
            $stmt = $this->db->prepare("
                SELECT layout_config, last_updated
                FROM user_dashboards
                WHERE user_id = ?
                LIMIT 1
            ");
            $stmt->execute([$userId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($result) {
                return json_decode($result['layout_config'], true);
            }

            // Return default layout if none exists
            return $this->getDefaultLayout($userId);

        } catch (Exception $e) {
            Logger::getInstance()->error("Failed to get user dashboard for user $userId: " . $e->getMessage());
            return $this->getDefaultLayout($userId);
        }
    }

    /**
     * Save user's dashboard layout
     */
    public function saveUserDashboard($userId, $layout) {
        try {
            // Validate layout structure
            if (!$this->validateLayout($layout)) {
                return ['success' => false, 'error' => 'Invalid dashboard layout'];
            }

            $layoutJson = json_encode($layout);

            $stmt = $this->db->prepare("
                INSERT INTO user_dashboards (user_id, layout_config, last_updated)
                VALUES (?, ?, NOW())
                ON DUPLICATE KEY UPDATE layout_config = ?, last_updated = NOW()
            ");

            $result = $stmt->execute([$userId, $layoutJson, $layoutJson]);

            if ($result) {
                Logger::getInstance()->logUserAction(
                    'Updated dashboard layout',
                    'user_dashboards',
                    null,
                    null,
                    ['user_id' => $userId]
                );

                return ['success' => true, 'message' => 'Dashboard layout saved successfully'];
            }

            return ['success' => false, 'error' => 'Failed to save dashboard layout'];

        } catch (Exception $e) {
            Logger::getInstance()->error("Failed to save dashboard for user $userId: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Get default dashboard layout
     */
    public function getDefaultLayout($userId) {
        // Get user role to determine appropriate widgets
        $userRole = $this->getUserRole($userId);

        $defaultWidgets = [];

        // Add widgets based on user role and permissions
        if ($this->hasPermission($userId, 'dashboard.view')) {
            $defaultWidgets[] = [
                'id' => 'financial_summary',
                'x' => 0,
                'y' => 0,
                'width' => 12,
                'height' => 4,
                'config' => $this->availableWidgets['financial_summary']['default_config']
            ];
        }

        if ($this->hasPermission($userId, 'invoices.view')) {
            $defaultWidgets[] = [
                'id' => 'accounts_receivable',
                'x' => 0,
                'y' => 4,
                'width' => 6,
                'height' => 3,
                'config' => $this->availableWidgets['accounts_receivable']['default_config']
            ];
        }

        if ($this->hasPermission($userId, 'bills.view')) {
            $defaultWidgets[] = [
                'id' => 'accounts_payable',
                'x' => 6,
                'y' => 4,
                'width' => 6,
                'height' => 3,
                'config' => $this->availableWidgets['accounts_payable']['default_config']
            ];
        }

        if ($this->hasPermission($userId, 'tasks.view')) {
            $defaultWidgets[] = [
                'id' => 'task_summary',
                'x' => 0,
                'y' => 7,
                'width' => 4,
                'height' => 2,
                'config' => $this->availableWidgets['task_summary']['default_config']
            ];
        }



        $defaultWidgets[] = [
            'id' => 'quick_actions',
            'x' => 8,
            'y' => 7,
            'width' => 4,
            'height' => 2,
            'config' => $this->availableWidgets['quick_actions']['default_config']
        ];

        return [
            'widgets' => $defaultWidgets,
            'settings' => [
                'theme' => 'light',
                'auto_refresh' => true,
                'refresh_interval' => 300
            ]
        ];
    }

    /**
     * Get available widgets for user
     */
    public function getAvailableWidgets($userId) {
        $available = [];

        foreach ($this->availableWidgets as $widgetId => $widget) {
            if ($this->hasPermission($userId, $widget['permissions'])) {
                $available[$widgetId] = $widget;
            }
        }

        return $available;
    }

    /**
     * Get widget data
     */
    public function getWidgetData($widgetId, $config = []) {
        if (!isset($this->availableWidgets[$widgetId])) {
            return ['error' => 'Widget not found'];
        }

        $widget = $this->availableWidgets[$widgetId];
        $method = 'get' . str_replace('_', '', ucwords($widgetId, '_')) . 'Data';

        if (method_exists($this, $method)) {
            try {
                return $this->$method($config);
            } catch (Exception $e) {
                Logger::getInstance()->error("Failed to get data for widget $widgetId: " . $e->getMessage());
                return ['error' => 'Failed to load widget data'];
            }
        }

        return ['error' => 'Widget data method not implemented'];
    }

    /**
     * Validate dashboard layout
     */
    private function validateLayout($layout) {
        if (!is_array($layout) || !isset($layout['widgets'])) {
            return false;
        }

        foreach ($layout['widgets'] as $widget) {
            if (!isset($widget['id']) || !isset($this->availableWidgets[$widget['id']])) {
                return false;
            }

            // Validate widget position and size
            if (!isset($widget['x']) || !isset($widget['y']) ||
                !isset($widget['width']) || !isset($widget['height'])) {
                return false;
            }

            // Ensure non-negative dimensions
            if ($widget['x'] < 0 || $widget['y'] < 0 ||
                $widget['width'] <= 0 || $widget['height'] <= 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if user has permission for widget
     */
    private function hasPermission($userId, $permissions) {
        if (!is_array($permissions)) {
            $permissions = [$permissions];
        }

        $permManager = PermissionManager::getInstance();
        $permManager->loadUserPermissions($userId);
        foreach ($permissions as $permission) {
            if ($permManager->hasPermission($permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get user role
     */
    private function getUserRole($userId) {
        try {
            $stmt = $this->db->prepare("SELECT role FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $result = $stmt->fetch();
            return $result ? $result['role'] : 'staff';
        } catch (Exception $e) {
            return 'staff';
        }
    }

    /**
     * Reset user dashboard to default
     */
    public function resetUserDashboard($userId) {
        try {
            $stmt = $this->db->prepare("DELETE FROM user_dashboards WHERE user_id = ?");
            $stmt->execute([$userId]);

            Logger::getInstance()->logUserAction(
                'Reset dashboard to default',
                'user_dashboards',
                null,
                null,
                ['user_id' => $userId]
            );

            return ['success' => true, 'message' => 'Dashboard reset to default'];

        } catch (Exception $e) {
            Logger::getInstance()->error("Failed to reset dashboard for user $userId: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Get dashboard statistics
     */
    public function getDashboardStats() {
        try {
            $stmt = $this->db->query("
                SELECT
                    COUNT(*) as total_dashboards,
                    AVG(JSON_LENGTH(layout_config, '$.widgets')) as avg_widgets,
                    COUNT(CASE WHEN last_updated >= DATE_SUB(NOW(), INTERVAL 1 DAY) THEN 1 END) as recently_updated
                FROM user_dashboards
            ");
            return $stmt->fetch(PDO::FETCH_ASSOC);

        } catch (Exception $e) {
            return [
                'total_dashboards' => 0,
                'avg_widgets' => 0,
                'recently_updated' => 0
            ];
        }
    }

    // Widget Data Methods

    private function getFinancialSummaryData($config) {
        $period = $config['period'] ?? 'month';

        try {
            // Calculate date range
            $dateRange = $this->getDateRange($period);

            $stmt = $this->db->prepare("
                SELECT
                    SUM(CASE WHEN type = 'revenue' THEN amount ELSE 0 END) as total_revenue,
                    SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END) as total_expenses,
                    (SUM(CASE WHEN type = 'revenue' THEN amount ELSE 0 END) -
                     SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END)) as net_profit
                FROM (
                    SELECT 'revenue' as type, total_amount as amount
                    FROM invoices
                    WHERE status = 'paid' AND invoice_date BETWEEN ? AND ?

                    UNION ALL

                    SELECT 'expense' as type, total_amount as amount
                    FROM bills
                    WHERE status = 'paid' AND bill_date BETWEEN ? AND ?
                ) as financial_data
            ");

            $stmt->execute([$dateRange['start'], $dateRange['end'], $dateRange['start'], $dateRange['end']]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            return [
                'total_revenue' => (float)($result['total_revenue'] ?? 0),
                'total_expenses' => (float)($result['total_expenses'] ?? 0),
                'net_profit' => (float)($result['net_profit'] ?? 0),
                'period' => $period
            ];

        } catch (Exception $e) {
            return ['error' => 'Failed to load financial summary'];
        }
    }

    private function getAccountsReceivableData($config) {
        $limit = $config['limit'] ?? 10;

        try {
            $stmt = $this->db->prepare("
                SELECT
                    i.invoice_number,
                    c.company_name,
                    i.total_amount,
                    i.balance,
                    i.due_date,
                    DATEDIFF(i.due_date, CURDATE()) as days_overdue
                FROM invoices i
                LEFT JOIN customers c ON i.customer_id = c.id
                WHERE i.status IN ('sent', 'overdue') AND i.balance > 0
                ORDER BY i.due_date ASC
                LIMIT ?
            ");
            $stmt->execute([$limit]);
            $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $totalOutstanding = array_sum(array_column($invoices, 'balance'));
            $overdueCount = count(array_filter($invoices, fn($inv) => $inv['days_overdue'] < 0));

            return [
                'invoices' => $invoices,
                'total_outstanding' => $totalOutstanding,
                'overdue_count' => $overdueCount
            ];

        } catch (Exception $e) {
            return ['error' => 'Failed to load accounts receivable data'];
        }
    }

    private function getTaskSummaryData($config) {
        try {
            $userId = $_SESSION['user']['id'] ?? null;
            $whereClause = $config['show_assigned_to_me'] && $userId ? "AND assigned_to = $userId" : "";

            $stmt = $this->db->prepare("
                SELECT
                    COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_count,
                    COUNT(CASE WHEN status = 'in_progress' THEN 1 END) as in_progress_count,
                    COUNT(CASE WHEN due_date < CURDATE() AND status != 'completed' THEN 1 END) as overdue_count
                FROM tasks
                WHERE 1=1 $whereClause
            ");
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            return [
                'pending' => (int)$result['pending_count'],
                'in_progress' => (int)$result['in_progress_count'],
                'overdue' => (int)$result['overdue_count']
            ];

        } catch (Exception $e) {
            return ['error' => 'Failed to load task summary'];
        }
    }



    /**
     * Get date range for period
     */
    private function getDateRange($period) {
        $now = new DateTime();

        switch ($period) {
            case 'week':
                $start = clone $now;
                $start->modify('monday this week');
                $end = clone $start;
                $end->modify('sunday this week');
                break;
            case 'month':
                $start = new DateTime($now->format('Y-m-01'));
                $end = new DateTime($now->format('Y-m-t'));
                break;
            case 'quarter':
                $quarter = ceil($now->format('n') / 3);
                $start = new DateTime($now->format('Y') . '-' . (($quarter - 1) * 3 + 1) . '-01');
                $end = clone $start;
                $end->modify('+2 months');
                $end = new DateTime($end->format('Y-m-t'));
                break;
            case 'year':
                $start = new DateTime($now->format('Y-01-01'));
                $end = new DateTime($now->format('Y-12-31'));
                break;
            default:
                $start = new DateTime($now->format('Y-m-01'));
                $end = new DateTime($now->format('Y-m-t'));
        }

        return [
            'start' => $start->format('Y-m-d'),
            'end' => $end->format('Y-m-d')
        ];
    }

    /**
     * Get available widgets list
     */
    public function getAvailableWidgetsList() {
        return $this->availableWidgets;
    }

    // Additional Widget Data Methods

    private function getRevenueChartData($config) {
        $period = $config['period'] ?? '6months';
        $chartType = $config['chart_type'] ?? 'line';

        try {
            // Calculate date range
            $dateRange = $this->getDateRange($period);

            $stmt = $this->db->prepare("
                SELECT
                    DATE_FORMAT(payment_date, '%Y-%m') as month,
                    SUM(amount) as revenue
                FROM payments_received
                WHERE payment_date BETWEEN ? AND ?
                GROUP BY DATE_FORMAT(payment_date, '%Y-%m')
                ORDER BY month ASC
            ");

            $stmt->execute([$dateRange['start'], $dateRange['end']]);
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return [
                'chart_type' => $chartType,
                'period' => $period,
                'data' => $data
            ];

        } catch (Exception $e) {
            return ['error' => 'Failed to load revenue chart data'];
        }
    }

    private function getExpenseBreakdownData($config) {
        $period = $config['period'] ?? 'month';
        $showPercentage = $config['show_percentage'] ?? true;

        try {
            // Calculate date range
            $dateRange = $this->getDateRange($period);

            $stmt = $this->db->prepare("
                SELECT
                    coa.account_name,
                    coa.category,
                    SUM(bi.line_total) as amount
                FROM bill_items bi
                JOIN bills b ON bi.bill_id = b.id
                JOIN chart_of_accounts coa ON bi.account_id = coa.id
                WHERE b.status = 'paid' AND b.bill_date BETWEEN ? AND ?
                GROUP BY coa.id, coa.account_name, coa.category
                ORDER BY amount DESC
            ");

            $stmt->execute([$dateRange['start'], $dateRange['end']]);
            $expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $total = array_sum(array_column($expenses, 'amount'));

            if ($showPercentage && $total > 0) {
                foreach ($expenses as &$expense) {
                    $expense['percentage'] = round(($expense['amount'] / $total) * 100, 1);
                }
            }

            return [
                'expenses' => $expenses,
                'total' => $total,
                'period' => $period,
                'show_percentage' => $showPercentage
            ];

        } catch (Exception $e) {
            return ['error' => 'Failed to load expense breakdown data'];
        }
    }

    private function getTopCustomersData($config) {
        $limit = $config['limit'] ?? 5;
        $period = $config['period'] ?? 'quarter';

        try {
            // Calculate date range
            $dateRange = $this->getDateRange($period);

            $stmt = $this->db->prepare("
                SELECT
                    c.company_name,
                    c.customer_code,
                    SUM(pr.amount) as total_revenue,
                    COUNT(pr.id) as transaction_count
                FROM customers c
                JOIN payments_received pr ON c.id = pr.customer_id
                WHERE pr.payment_date BETWEEN ? AND ?
                GROUP BY c.id, c.company_name, c.customer_code
                ORDER BY total_revenue DESC
                LIMIT ?
            ");

            $stmt->execute([$dateRange['start'], $dateRange['end'], $limit]);
            $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return [
                'customers' => $customers,
                'limit' => $limit,
                'period' => $period
            ];

        } catch (Exception $e) {
            return ['error' => 'Failed to load top customers data'];
        }
    }

    private function getRecentTransactionsData($config) {
        $limit = $config['limit'] ?? 10;
        $showAmounts = $config['show_amounts'] ?? true;

        try {
            $stmt = $this->db->prepare("
                (SELECT
                    'payment_received' as type,
                    pr.payment_date as date,
                    CONCAT('Payment from ', c.company_name) as description,
                    pr.amount,
                    pr.payment_method,
                    pr.reference_number,
                    pr.created_at
                FROM payments_received pr
                JOIN customers c ON pr.customer_id = c.id
                ORDER BY pr.created_at DESC LIMIT ?)
                UNION ALL
                (SELECT
                    'payment_made' as type,
                    pm.payment_date as date,
                    CONCAT('Payment to ', v.company_name) as description,
                    pm.amount,
                    pm.payment_method,
                    pm.reference_number,
                    pm.created_at
                FROM payments_made pm
                JOIN vendors v ON pm.vendor_id = v.id
                ORDER BY pm.created_at DESC LIMIT ?)
                ORDER BY created_at DESC LIMIT ?
            ");

            $halfLimit = ceil($limit / 2);
            $stmt->execute([$halfLimit, $halfLimit, $limit]);
            $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (!$showAmounts) {
                foreach ($transactions as &$transaction) {
                    unset($transaction['amount']);
                }
            }

            return [
                'transactions' => $transactions,
                'limit' => $limit,
                'show_amounts' => $showAmounts
            ];

        } catch (Exception $e) {
            return ['error' => 'Failed to load recent transactions data'];
        }
    }

    private function getBudgetOverviewData($config) {
        $period = $config['period'] ?? 'current_year';
        $showVariance = $config['show_variance'] ?? true;

        try {
            // Get current year
            $year = date('Y');

            $stmt = $this->db->prepare("
                SELECT
                    bc.category_name,
                    bc.category_type,
                    SUM(bi.budgeted_amount) as budgeted,
                    SUM(bi.actual_amount) as actual,
                    (SUM(bi.actual_amount) - SUM(bi.budgeted_amount)) as variance
                FROM budget_items bi
                JOIN budgets b ON bi.budget_id = b.id
                JOIN budget_categories bc ON bi.category_id = bc.id
                WHERE b.budget_year = ?
                GROUP BY bc.id, bc.category_name, bc.category_type
                ORDER BY bc.category_type, bc.category_name
            ");

            $stmt->execute([$year]);
            $budgetData = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if ($showVariance) {
                foreach ($budgetData as &$item) {
                    $item['variance_percentage'] = $item['budgeted'] > 0 ?
                        round(($item['variance'] / $item['budgeted']) * 100, 1) : 0;
                }
            }

            return [
                'budget_data' => $budgetData,
                'period' => $period,
                'show_variance' => $showVariance
            ];

        } catch (Exception $e) {
            return ['error' => 'Failed to load budget overview data'];
        }
    }

    private function getCashFlowData($config) {
        $period = $config['period'] ?? 'month';
        $showTrend = $config['show_trend'] ?? true;

        try {
            // Calculate date range
            $dateRange = $this->getDateRange($period);

            $stmt = $this->db->prepare("
                SELECT
                    DATE_FORMAT(date, '%Y-%m-%d') as date,
                    SUM(CASE WHEN type = 'inflow' THEN amount ELSE 0 END) as inflow,
                    SUM(CASE WHEN type = 'outflow' THEN amount ELSE 0 END) as outflow,
                    (SUM(CASE WHEN type = 'inflow' THEN amount ELSE 0 END) -
                     SUM(CASE WHEN type = 'outflow' THEN amount ELSE 0 END)) as net_flow
                FROM (
                    SELECT payment_date as date, amount, 'inflow' as type
                    FROM payments_received
                    WHERE payment_date BETWEEN ? AND ?

                    UNION ALL

                    SELECT payment_date as date, amount, 'outflow' as type
                    FROM payments_made
                    WHERE payment_date BETWEEN ? AND ?
                ) as cash_flow
                GROUP BY DATE_FORMAT(date, '%Y-%m-%d')
                ORDER BY date ASC
            ");

            $stmt->execute([$dateRange['start'], $dateRange['end'], $dateRange['start'], $dateRange['end']]);
            $cashFlowData = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $trend = [];
            if ($showTrend && count($cashFlowData) > 1) {
                for ($i = 1; $i < count($cashFlowData); $i++) {
                    $trend[] = [
                        'date' => $cashFlowData[$i]['date'],
                        'change' => $cashFlowData[$i]['net_flow'] - $cashFlowData[$i-1]['net_flow']
                    ];
                }
            }

            return [
                'cash_flow' => $cashFlowData,
                'trend' => $trend,
                'period' => $period,
                'show_trend' => $showTrend
            ];

        } catch (Exception $e) {
            return ['error' => 'Failed to load cash flow data'];
        }
    }

    private function getRecentActivityData($config) {
        $limit = $config['limit'] ?? 10;
        $showUserActions = $config['show_user_actions'] ?? true;

        try {
            $userClause = $showUserActions ? "" : "AND user_id IS NOT NULL";

            $stmt = $this->db->prepare("
                SELECT
                    action,
                    table_name,
                    old_values,
                    new_values,
                    ip_address,
                    created_at,
                    u.full_name as user_name
                FROM audit_log al
                LEFT JOIN users u ON al.user_id = u.id
                WHERE 1=1 $userClause
                ORDER BY created_at DESC
                LIMIT ?
            ");

            $stmt->execute([$limit]);
            $activities = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return [
                'activities' => $activities,
                'limit' => $limit,
                'show_user_actions' => $showUserActions
            ];

        } catch (Exception $e) {
            return ['error' => 'Failed to load recent activity data'];
        }
    }

    private function getSystemStatusData($config) {
        $showDiskUsage = $config['show_disk_usage'] ?? true;
        $showMemory = $config['show_memory'] ?? true;

        try {
            $status = [
                'server_status' => 'online',
                'database_status' => 'connected',
                'last_backup' => null,
                'uptime' => null
            ];

            // Check database connection
            $stmt = $this->db->query("SELECT 1");
            $status['database_status'] = $stmt ? 'connected' : 'disconnected';

            // Get last backup
            $stmt = $this->db->query("SELECT created_at FROM backups ORDER BY created_at DESC LIMIT 1");
            $lastBackup = $stmt->fetch();
            $status['last_backup'] = $lastBackup ? $lastBackup['created_at'] : null;

            // Disk usage (if enabled)
            if ($showDiskUsage) {
                $diskTotal = disk_total_space('/');
                $diskFree = disk_free_space('/');
                $status['disk_usage'] = [
                    'total' => $diskTotal,
                    'free' => $diskFree,
                    'used' => $diskTotal - $diskFree,
                    'percentage' => $diskTotal > 0 ? round((($diskTotal - $diskFree) / $diskTotal) * 100, 1) : 0
                ];
            }

            // Memory usage (if enabled)
            if ($showMemory) {
                $memoryUsage = memory_get_peak_usage(true);
                $status['memory_usage'] = [
                    'peak_usage' => $memoryUsage,
                    'formatted' => $this->formatBytes($memoryUsage)
                ];
            }

            return [
                'status' => $status,
                'config' => $config
            ];

        } catch (Exception $e) {
            return ['error' => 'Failed to load system status data'];
        }
    }

    /**
     * Helper method to format bytes
     */
    private function formatBytes($bytes) {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }
}
?>

