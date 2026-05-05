<?php
header('Content-Type: application/json');
ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once '../includes/auth.php';
require_once '../includes/database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$pdo = Database::getInstance()->getConnection();
$userId = (int)($_SESSION['user']['id'] ?? 1);

function tableExists(PDO $pdo, string $table): bool {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE table_schema = DATABASE() AND table_name = ?");
    $stmt->execute([$table]);
    return (int)$stmt->fetchColumn() > 0;
}

function fetchAll(PDO $pdo, string $sql, array $params = []): array {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function sourceRows(array $rows): array {
    return array_map(function ($row) {
        $row['source'] = 'database_seed';
        return $row;
    }, $rows);
}

function queueRows(PDO $pdo, string $type): array {
    if (!tableExists($pdo, 'financial_work_queue')) {
        return [];
    }

    $rows = fetchAll($pdo, "
        SELECT *
        FROM financial_work_queue
        WHERE queue_type = ?
        ORDER BY COALESCE(submitted_at, created_at) DESC, id DESC
    ", [$type]);

    return array_map(function ($row) use ($type) {
        $base = [
            'id' => $row['id'],
            'status' => $row['status'],
            'source' => 'seed',
        ];

        if ($type === 'hr_claim') {
            return $base + [
                'claim_id' => $row['id'],
                'employee_name' => $row['employee_name'],
                'department' => $row['department'],
                'claim_type' => $row['claim_type'],
                'amount' => (float)$row['total_amount'],
                'submitted_at' => $row['submitted_at'],
                'can_process' => (bool)$row['can_process'],
            ];
        }

        if ($type === 'hr_payroll') {
            return $base + [
                'approval_id' => $row['id'],
                'payroll_period' => $row['period_display'],
                'period_display' => $row['period_display'],
                'total_amount' => (float)$row['total_amount'],
                'employee_count' => (int)$row['employee_count'],
                'submitted_by' => $row['submitted_by'] ?: 'Compensation Team',
                'submitted_at' => $row['submitted_at'],
                'can_approve' => (bool)$row['can_process'],
            ];
        }

        return $base + [
            'employee_name' => $row['employee_name'],
            'department' => $row['department'],
            'position' => $row['position'],
            'period' => $row['period_display'],
            'amount' => (float)$row['total_amount'],
            'can_process' => (bool)$row['can_process'],
        ];
    }, $rows);
}

function getState(PDO $pdo): array {
    $vendors = sourceRows(fetchAll($pdo, "
        SELECT id, vendor_code, company_name, contact_person, email, phone, payment_terms, status
        FROM vendors
        WHERE id >= 9101 OR vendor_code LIKE 'VEND-91%'
        ORDER BY company_name
    "));

    $customers = sourceRows(fetchAll($pdo, "
        SELECT id, customer_code, company_name, contact_person, email, phone, credit_limit, current_balance, status
        FROM customers
        WHERE id >= 9201 OR customer_code LIKE 'CUST-92%'
        ORDER BY company_name
    "));

    $bills = sourceRows(fetchAll($pdo, "
        SELECT b.*, v.company_name AS vendor_name, v.vendor_code
        FROM bills b
        LEFT JOIN vendors v ON v.id = b.vendor_id
        WHERE b.id >= 9301 OR b.bill_number LIKE 'BILL-2026-3%'
        ORDER BY b.bill_date DESC
    "));

    $invoices = sourceRows(fetchAll($pdo, "
        SELECT i.*, c.company_name AS customer_name, c.customer_code
        FROM invoices i
        LEFT JOIN customers c ON c.id = i.customer_id
        WHERE i.id >= 9401 OR i.invoice_number LIKE 'INV-2026-4%'
        ORDER BY i.invoice_date DESC
    "));

    $paymentsMade = sourceRows(fetchAll($pdo, "
        SELECT pm.*, v.company_name AS vendor_name, b.bill_number
        FROM payments_made pm
        LEFT JOIN vendors v ON v.id = pm.vendor_id
        LEFT JOIN bills b ON b.id = pm.bill_id
        WHERE pm.id >= 9501 OR pm.payment_number LIKE 'PMT-2026-6%'
        ORDER BY pm.payment_date DESC
    "));

    $paymentsReceived = sourceRows(fetchAll($pdo, "
        SELECT pr.*, c.company_name AS customer_name, i.invoice_number
        FROM payments_received pr
        LEFT JOIN customers c ON c.id = pr.customer_id
        LEFT JOIN invoices i ON i.id = pr.invoice_id
        WHERE pr.id >= 9601 OR pr.payment_number LIKE 'RCV-2026-7%'
        ORDER BY pr.payment_date DESC
    "));

    $adjustments = sourceRows(fetchAll($pdo, "
        SELECT a.*,
               CASE WHEN a.vendor_id IS NOT NULL THEN 'payable' ELSE 'receivable' END AS type,
               v.company_name AS vendor_name,
               c.company_name AS customer_name,
               b.bill_number,
               i.invoice_number
        FROM adjustments a
        LEFT JOIN vendors v ON v.id = a.vendor_id
        LEFT JOIN customers c ON c.id = a.customer_id
        LEFT JOIN bills b ON b.id = a.bill_id
        LEFT JOIN invoices i ON i.id = a.invoice_id
        WHERE a.id >= 9701 OR a.adjustment_number LIKE 'ADJ-%-2026-%'
        ORDER BY a.adjustment_date DESC
    "));

    $disbursements = sourceRows(fetchAll($pdo, "
        SELECT d.*,
               CASE
                   WHEN d.reference_number LIKE 'REQ-CLM%' OR d.reference_number LIKE 'HR3-CLAIM-%' THEN 'claims'
                   WHEN d.reference_number LIKE 'PAYROLL-%' THEN 'payroll'
                   WHEN d.reference_number LIKE 'INCENTIVE-%' THEN 'incentives'
                   WHEN d.reference_number LIKE 'HBG-%' THEN 'ap'
                   ELSE NULL
               END AS source_module
        FROM disbursements d
        WHERE d.id >= 9801 OR d.disbursement_number LIKE 'DISB-2026%'
        ORDER BY d.disbursement_date DESC, d.id DESC
    "));

    $budgets = sourceRows(fetchAll($pdo, "
        SELECT b.*,
               b.budget_name AS name,
               b.total_budgeted AS total_amount,
               COALESCE(SUM(bi.actual_amount), 0) AS utilized_amount,
               d.dept_name AS department,
               u.full_name AS created_by_name
        FROM budgets b
        LEFT JOIN budget_items bi ON bi.budget_id = b.id
        LEFT JOIN departments d ON d.id = b.department_id
        LEFT JOIN users u ON u.id = b.created_by
        WHERE b.id >= 9901 OR b.budget_name IN ('FY2026 Core Operations Plan', 'Guest Experience Acceleration', 'Facilities Reliability Program')
        GROUP BY b.id
        ORDER BY b.start_date DESC, b.id DESC
    "));

    $allocations = sourceRows(fetchAll($pdo, "
        SELECT bi.id,
               bi.budget_id,
               bi.department_id,
               COALESCE(d.dept_name, bi.notes, 'Unassigned') AS department,
               COALESCE(bc.category_name, 'General') AS category,
               bi.budgeted_amount AS total_amount,
               0 AS reserved_amount,
               bi.actual_amount AS utilized_amount,
               (bi.budgeted_amount - bi.actual_amount) AS remaining
        FROM budget_items bi
        LEFT JOIN departments d ON d.id = bi.department_id
        LEFT JOIN budget_categories bc ON bc.id = bi.category_id
        WHERE bi.id >= 9911
        ORDER BY bi.id
    "));

    $budgetAdjustments = sourceRows(fetchAll($pdo, "
        SELECT ba.*,
               CONCAT('BGT-ADJ-2026-', LPAD(GREATEST(ba.id - 9900, 1), 3, '0')) AS adjustment_number,
               d.dept_name AS department_name,
               u.full_name AS requested_by_name,
               ua.full_name AS approved_by_name
        FROM budget_adjustments ba
        LEFT JOIN departments d ON d.id = ba.department_id
        LEFT JOIN users u ON u.id = ba.requested_by
        LEFT JOIN users ua ON ua.id = ba.approved_by
        WHERE ba.id >= 9921
        ORDER BY ba.effective_date DESC, ba.id DESC
    "));

    return [
        'vendors' => $vendors,
        'customers' => $customers,
        'bills' => $bills,
        'invoices' => $invoices,
        'payments_made' => $paymentsMade,
        'payments_received' => $paymentsReceived,
        'adjustments' => $adjustments,
        'hr_claims' => queueRows($pdo, 'hr_claim'),
        'hr_payroll' => queueRows($pdo, 'hr_payroll'),
        'hr_incentives' => queueRows($pdo, 'hr_incentive'),
        'disbursements' => $disbursements,
        'budget_plans' => $budgets,
        'budget_allocations' => $allocations,
        'budget_adjustments' => $budgetAdjustments,
    ];
}

function normalizeDisbursementStatus(?string $status): string {
    $status = strtolower((string)$status);
    if (in_array($status, ['pending', 'approved', 'paid', 'cancelled'], true)) {
        return $status;
    }
    if (in_array($status, ['processed', 'completed'], true)) {
        return 'paid';
    }
    return 'pending';
}

function firstExpenseAccountId(PDO $pdo): ?int {
    $stmt = $pdo->query("SELECT id FROM chart_of_accounts WHERE account_type = 'expense' AND is_active = 1 ORDER BY account_code LIMIT 1");
    $value = $stmt->fetchColumn();
    return $value ? (int)$value : null;
}

function payrollAccountId(PDO $pdo): ?int {
    $stmt = $pdo->prepare("SELECT id FROM chart_of_accounts WHERE account_code = '6000' AND is_active = 1 LIMIT 1");
    $stmt->execute();
    $value = $stmt->fetchColumn();
    return $value ? (int)$value : firstExpenseAccountId($pdo);
}

function createDisbursement(PDO $pdo, array $data, int $userId): array {
    $date = substr((string)($data['disbursement_date'] ?? $data['payment_date'] ?? date('Y-m-d')), 0, 10);
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM disbursements WHERE disbursement_date = ?");
    $stmt->execute([$date]);
    $count = (int)$stmt->fetchColumn() + 1;
    $number = $data['disbursement_number'] ?? ('DISB-' . str_replace('-', '', $date) . '-' . str_pad((string)$count, 3, '0', STR_PAD_LEFT));
    $accountId = $data['account_id'] ?? null;
    if (!$accountId && stripos(($data['payee'] ?? '') . ' ' . ($data['reference_number'] ?? ''), 'payroll') !== false) {
        $accountId = payrollAccountId($pdo);
    }
    if (!$accountId) {
        $accountId = firstExpenseAccountId($pdo);
    }

    $stmt = $pdo->prepare("
        INSERT INTO disbursements (
            disbursement_number, disbursement_date, payee, amount, payment_method,
            reference_number, purpose, department, account_id, approved_by, recorded_by,
            created_by, status, needs_approval, approval_status
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 'not_required')
    ");
    $stmt->execute([
        $number,
        $date,
        substr((string)($data['payee'] ?? 'Unassigned Payee'), 0, 100),
        (float)($data['amount'] ?? 0),
        $data['payment_method'] ?? 'bank_transfer',
        $data['reference_number'] ?? null,
        $data['purpose'] ?? $data['notes'] ?? $data['description'] ?? 'Financial seed workflow disbursement',
        $data['department'] ?? null,
        $accountId,
        $userId,
        $userId,
        $userId,
        normalizeDisbursementStatus($data['status'] ?? 'paid')
    ]);

    $id = (int)$pdo->lastInsertId();
    $row = fetchAll($pdo, "SELECT * FROM disbursements WHERE id = ?", [$id])[0] ?? [];
    $row['source'] = 'database_seed';
    return $row;
}

function updateQueueStatus(PDO $pdo, string $id, string $status): void {
    if (!tableExists($pdo, 'financial_work_queue')) {
        return;
    }
    $canProcess = in_array(strtolower($status), ['approved', 'pending'], true) ? 1 : 0;
    $stmt = $pdo->prepare("UPDATE financial_work_queue SET status = ?, can_process = ?, updated_at = NOW() WHERE id = ?");
    $stmt->execute([$status, $canProcess, $id]);
}

function handlePost(PDO $pdo, int $userId): array {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        $data = $_POST;
    }

    $action = $data['action'] ?? '';
    if ($action === 'create_disbursement') {
        return ['success' => true, 'record' => createDisbursement($pdo, $data['record'] ?? $data, $userId), 'state' => getState($pdo)];
    }

    if ($action === 'update_queue_status') {
        updateQueueStatus($pdo, (string)($data['id'] ?? ''), (string)($data['status'] ?? 'pending'));
        return ['success' => true, 'state' => getState($pdo)];
    }

    if ($action === 'update_bill_workflow') {
        $status = ($data['decision'] ?? '') === 'approve' ? 'approved' : 'cancelled';
        $stmt = $pdo->prepare("UPDATE bills SET status = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$status, $data['id'] ?? 0]);
        return ['success' => true, 'state' => getState($pdo)];
    }

    if ($action === 'update_invoice_status') {
        $status = (string)($data['status'] ?? 'sent');
        if ($status === 'paid') {
            $stmt = $pdo->prepare("UPDATE invoices SET status = 'paid', paid_amount = total_amount, balance = 0, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$data['id'] ?? 0]);
        } else {
            $stmt = $pdo->prepare("UPDATE invoices SET status = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$status, $data['id'] ?? 0]);
        }
        return ['success' => true, 'state' => getState($pdo)];
    }

    if ($action === 'budget_adjustment_status') {
        $approvedBy = ($data['status'] ?? '') === 'approved' ? $userId : null;
        $stmt = $pdo->prepare("UPDATE budget_adjustments SET status = ?, approved_by = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$data['status'] ?? 'pending', $approvedBy, $data['id'] ?? 0]);
        return ['success' => true, 'state' => getState($pdo)];
    }

    if ($action === 'delete_budget_adjustment') {
        $stmt = $pdo->prepare("DELETE FROM budget_adjustments WHERE id = ?");
        $stmt->execute([$data['id'] ?? 0]);
        return ['success' => true, 'state' => getState($pdo)];
    }

    http_response_code(400);
    return ['success' => false, 'error' => 'Unknown action'];
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        echo json_encode(handlePost($pdo, $userId));
        exit;
    }

    echo json_encode(getState($pdo));
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
