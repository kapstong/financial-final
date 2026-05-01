<?php
require_once '../includes/auth.php';
require_once '../includes/database.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Department-based Access Control for Disbursements
$auth = new Auth();
$userDepartment = $_SESSION['user']['department'] ?? '';

// Define department permissions for disbursements module
$deptPermissions = [
    'finance' => ['view', 'create', 'edit', 'delete', 'process_claims'],
    'accounting' => ['view', 'create', 'edit', 'delete'],
    'hr' => ['view', 'process_claims', 'upload_vouchers'],
    'procurement' => ['view', 'create', 'upload_vouchers'],
    'admin' => ['view', 'create', 'edit', 'delete', 'process_claims', 'configure'],
];

// Department-based access control (permissive approach)
$userPerms = isset($deptPermissions[$userDepartment]) ? $deptPermissions[$userDepartment] : ['view']; // Default view access
$roleName = $_SESSION['user']['role_name'] ?? ($_SESSION['user']['role'] ?? '');
$hasAdminRole = $roleName === 'admin';

// Allow access by default - department restrictions should not block viewing
// Only restrict very specific operations, not the entire module access
if (!$hasAdminRole && empty($userPerms)) {
    // Very permissive: only block if no permissions and not admin (which should never happen now)
    header('Location: ../index.php');
    exit;
}

// Load user permissions
if (!isset($_SESSION['user'])) {
    header('Location: ../index.php');
    exit;
}

// Initialize database connection
$db = Database::getInstance()->getConnection();
$payrollExpenseAccountId = null;
try {
    $stmt = $db->prepare("SELECT id FROM chart_of_accounts WHERE account_code = '6000' AND is_active = 1 LIMIT 1");
    $stmt->execute();
    $payrollExpenseAccountId = $stmt->fetchColumn() ?: null;
    if (!$payrollExpenseAccountId) {
        $stmt = $db->query("SELECT id FROM chart_of_accounts WHERE account_type = 'expense' AND is_active = 1 ORDER BY account_code LIMIT 1");
        $payrollExpenseAccountId = $stmt->fetchColumn() ?: null;
    }
} catch (Exception $e) {
    $payrollExpenseAccountId = null;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Financial Management System - Disbursements</title>
    <link rel="icon" type="image/png" href="../logo2.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
body {
    background-color: #F1F7EE;
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    margin: 0;
    padding: 0;
}
        .sidebar {
            height: 100vh;
            max-height: 100vh;
            overflow-y: auto;
            overscroll-behavior: contain;
            -webkit-overflow-scrolling: touch;
            padding-bottom: 2rem;
            background-color: #1e2936;
            color: white;
            min-height: 100vh;
            position: fixed;
            top: 0;
            left: 0;
            width: 300px;
            z-index: 1000;
            transition: transform 0.3s ease, width 0.3s ease;
            box-shadow: 2px 0 10px rgba(0, 0, 0, 0.1);
        }
        .sidebar.sidebar-collapsed {
            width: 120px;
        }
        .sidebar.sidebar-collapsed span {
            display: none;
        }
        .sidebar.sidebar-collapsed .nav-link {
            padding: 10px;
            text-align: center;
        }
        .sidebar.sidebar-collapsed .navbar-brand {
            text-align: center;
        }
        .sidebar.sidebar-collapsed .nav-item .dropdown-toggle {
            display: none;
        }
        .sidebar.sidebar-collapsed .submenu {
            display: none;
        }
        .sidebar .nav-link {
            color: white;
            padding: 10px 20px;
            border-radius: 5px;
            margin-bottom: 10px;
            font-size: 1.1em;
        }
        .sidebar .nav-link i {
            font-size: 1.4em;
        }
        .sidebar .nav-link:hover {
            background-color: rgba(255, 255, 255, 0.1);
            color: white;
        }
        .sidebar .nav-link.active {
            background-color: rgba(255, 255, 255, 0.2);
        }
        .sidebar .submenu {
            padding-left: 20px;
        }
        .sidebar .submenu .nav-link {
            padding: 5px 20px;
            font-size: 0.9em;
        }
        .sidebar .nav-item {
            position: relative;
        }
        .sidebar .navbar-brand {
            color: white !important;
            font-weight: bold;
        }
        .sidebar .nav-item {
            position: relative;
        }
        .sidebar .nav-item i[data-bs-toggle="collapse"] {
            position: absolute;
            right: 20px;
            top: 10px;
            transition: transform 0.3s ease;
        }
        .sidebar .nav-item i[aria-expanded="true"] {
            transform: rotate(90deg);
        }
        .sidebar .nav-item i[aria-expanded="false"] {
            transform: rotate(0deg);
        }
        .content {
            margin-left: 300px;
            padding: 20px;
            transition: margin-left 0.3s ease;
            position: relative;
            z-index: 1;
        }
        .sidebar-toggle {
            position: fixed;
            left: 290px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: white;
            font-size: 1.5em;
            width: 40px;
            height: 40px;
            background-color: #1e2936;
            border: 2px solid white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: left 0.3s ease, background-color 0.3s ease;
            z-index: 1001;
        }
        .sidebar-toggle:hover {
            background-color: rgba(255, 255, 255, 0.1);
        }
        .toggle-btn {
            display: none;
        }
        .navbar .dropdown-toggle {
            text-decoration: none !important;
        }
        .navbar .dropdown-toggle:focus {
            box-shadow: none;
        }
        .navbar .btn-link {
            text-decoration: none !important;
        }
        .navbar .btn-link:focus {
            box-shadow: none;
        }
        .navbar {
            background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
            padding: 1rem 1.5rem;
            border-bottom: 1px solid #e3e6ea;
            box-shadow: 0 2px 4px rgba(0,0,0,0.04);
            backdrop-filter: blur(10px);
            position: relative;
            z-index: 10000;
        }
        .navbar-brand {
            font-weight: 700;
            color: #2c3e50 !important;
            font-size: 1.4rem;
            letter-spacing: -0.02em;
        }
        .navbar .dropdown-toggle {
            border-radius: 8px;
            padding: 0.5rem 0.75rem;
            transition: all 0.2s ease;
        }
        .navbar .dropdown-toggle:hover {
            background-color: rgba(0,0,0,0.05);
        }
        .navbar .dropdown-toggle span {
            font-weight: 600;
            font-size: 1.1rem;
            color: #495057;
        }
        .navbar .btn-link {
            font-size: 1.1rem;
            border-radius: 8px;
            padding: 0.5rem;
            transition: all 0.2s ease;
            color: #6c757d;
        }
        .navbar .btn-link:hover {
            background-color: rgba(0,0,0,0.05);
            color: #495057;
        }
        .navbar .input-group {
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            border: 1px solid #e9ecef;
            transition: all 0.2s ease;
        }
        .navbar .input-group:focus-within {
            box-shadow: 0 4px 12px rgba(0,0,0,0.12);
            border-color: #007bff;
        }
        .navbar .form-control {
            border: none;
            padding: 0.75rem 1rem;
            font-size: 1rem;
            background-color: #ffffff;
        }
        .navbar .form-control:focus {
            box-shadow: none;
            border-color: transparent;
            background-color: #ffffff;
        }
        .navbar .btn-outline-secondary {
            border: none;
            background-color: #f8f9fa;
            color: #6c757d;
            border-left: 1px solid #e9ecef;
            padding: 0.75rem 1rem;
        }
        .navbar .btn-outline-secondary:hover {
            background-color: #e9ecef;
            color: #495057;
        }
        .navbar .dropdown-menu {
            z-index: 9999;
            box-shadow: 0 8px 25px rgba(0,0,0,0.2);
            border: none;
            border-radius: 8px;
            margin-top: 0.5rem;
        }
        .navbar .dropdown-item {
            padding: 0.75rem 1rem;
            transition: all 0.2s ease;
        }
        .navbar .dropdown-item:hover {
            background-color: #f8f9fa;
            color: #495057;
        }
        .nav-tabs {
            border-bottom: 2px solid #e9ecef;
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            border-radius: 8px 8px 0 0;
            padding: 0.5rem;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        .nav-tabs .nav-link {
            border: none;
            color: #6c757d;
            font-weight: 600;
            padding: 0.75rem 1.5rem;
            margin-right: 0.25rem;
            border-radius: 6px;
            transition: all 0.3s ease;
            position: relative;
        }
        .nav-tabs .nav-link:hover {
            background-color: rgba(30, 41, 54, 0.05);
            color: #1e2936;
        }
        .nav-tabs .nav-link.active {
            background: linear-gradient(135deg, #1e2936 0%, #2c3e50 100%);
            color: white;
            box-shadow: 0 4px 8px rgba(30, 41, 54, 0.2);
        }
        .nav-tabs .nav-link.tab-attention {
            box-shadow: 0 0 0 2px rgba(25, 135, 84, 0.35);
        }
        .card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            transition: all 0.3s ease;
            background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
        }
        .card:hover {
            box-shadow: 0 8px 30px rgba(0,0,0,0.12);
            transform: translateY(-2px);
        }
        .card-header {
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            border-bottom: 1px solid #e9ecef;
            border-radius: 12px 12px 0 0 !important;
            padding: 1.5rem;
        }
        .card-body {
            padding: 2rem;
        }
        .table {
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        .table thead th {
            background: linear-gradient(135deg, #1e2936 0%, #2c3e50 100%);
            color: white;
            font-weight: 600;
            border: none;
            padding: 1rem;
            text-transform: uppercase;
            font-size: 0.85rem;
            letter-spacing: 0.5px;
        }
        .table tbody tr {
            transition: all 0.2s ease;
            border-bottom: 1px solid #f1f1f1;
        }
        .table tbody td {
            padding: 1rem;
            vertical-align: middle;
            color: #495057;
        }
        .btn {
            border-radius: 8px;
            font-weight: 600;
            padding: 0.5rem 1.5rem;
            transition: all 0.3s ease;
            border: none;
        }
        .btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        .btn-primary {
            background: linear-gradient(135deg, #1e2936 0%, #2c3e50 100%);
        }
        .btn-success {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
        }
        .form-control, .form-select {
            border: 2px solid #e9ecef;
            border-radius: 8px;
            padding: 0.75rem;
            transition: all 0.3s ease;
            background: white;
        }
        .form-control:focus, .form-select:focus {
            border-color: #1e2936;
            box-shadow: 0 0 0 0.2rem rgba(30, 41, 54, 0.1);
            transform: translateY(-1px);
        }
        .modal-content {
            border-radius: 12px;
            border: none;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }
        .modal-header {
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            border-bottom: 1px solid #e9ecef;
            border-radius: 12px 12px 0 0;
            padding: 1.5rem 2rem;
        }
        .modal-body {
            padding: 2rem;
        }
        .modal-footer {
            border-top: 1px solid #e9ecef;
            padding: 1.5rem 2rem;
            background: #f8f9fa;
            border-radius: 0 0 12px 12px;
        }
    </style>
</head>
<body>
    <?php include '../includes/superadmin_navigation.php'; ?>

    <div class="content">
        <!-- Top Navbar -->
        <?php include '../includes/global_navbar.php'; ?>

        <!-- Navigation Tabs -->
        <ul class="nav nav-tabs mb-4" id="disbursementsTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="records-tab" data-bs-toggle="tab" data-bs-target="#records" type="button" role="tab">
                    <i class="fas fa-list me-2"></i>Disbursement Records
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="claims-tab" data-bs-toggle="tab" data-bs-target="#claims" type="button" role="tab">
                    <i class="fas fa-receipt me-2"></i>Claims Processing
                    <span class="badge bg-success ms-2 d-none" data-tab-badge="claims">0</span>
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="payroll-tab" data-bs-toggle="tab" data-bs-target="#payroll" type="button" role="tab">
                    <i class="fas fa-money-check-alt me-2"></i>Payroll Processing
                    <span class="badge bg-success ms-2 d-none" data-tab-badge="payroll">0</span>
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="incentives-tab" data-bs-toggle="tab" data-bs-target="#incentives" type="button" role="tab">
                    <i class="fas fa-gift me-2"></i>Incentives Processing
                    <span class="badge bg-success ms-2 d-none" data-tab-badge="incentives">0</span>
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="logistics-tab" data-bs-toggle="tab" data-bs-target="#logistics" type="button" role="tab">
                    <i class="fas fa-truck-loading me-2"></i>Logistics Sync
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="vouchers-tab" data-bs-toggle="tab" data-bs-target="#vouchers" type="button" role="tab">
                    <i class="fas fa-file-invoice me-2"></i>Vouchers & Documentation
                </button>
            </li>

            <li class="nav-item" role="presentation">
                <button class="nav-link" id="reports-tab" data-bs-toggle="tab" data-bs-target="#reports" type="button" role="tab">
                    <i class="fas fa-chart-bar me-2"></i>Reports & Analytics
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="audit-tab" data-bs-toggle="tab" data-bs-target="#audit" type="button" role="tab">
                    <i class="fas fa-history me-2"></i>Audit Trail
                    <span class="badge bg-success ms-2 d-none" data-tab-badge="audit">0</span>
                </button>
            </li>
        </ul>

        <!-- Tab Content -->
        <div class="tab-content" id="disbursementsTabContent">
            <!-- Disbursement Records Tab -->
            <div class="tab-pane fade show active" id="records" role="tabpanel" aria-labelledby="records-tab">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="mb-0">Master List of All Disbursements</h6>
                    <div>
                        <button class="btn btn-outline-danger me-2" id="bulkDeleteBtn" onclick="bulkDeleteDisbursements()" style="display: none;">
                            <i class="fas fa-trash me-1"></i>Bulk Delete (<span id="selectedCount">0</span>)
                        </button>
                        <button class="btn btn-outline-secondary me-2" onclick="showFilters()"><i class="fas fa-filter me-2"></i>Filter</button>
                        <button class="btn btn-primary" onclick="showAddDisbursementModal()"><i class="fas fa-plus me-2"></i>Add Disbursement</button>
                    </div>
                </div>

                <!-- Filters Section -->
                <div id="filtersSection" class="card mb-3" style="display: none;">
                    <div class="card-body">
                        <div class="row mb-3">
                            <div class="col-md-12">
                                <label class="form-label">Search Reference # <small class="text-muted">(updates as you type)</small></label>
                                <input type="text" class="form-control" id="filterReferenceSearch" placeholder="Enter Reference # or part of it..." oninput="applyFilters()">
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-3">
                                <label class="form-label">Status</label>
                                <select class="form-select" id="filterStatus" onchange="applyFilters()">
                                    <option value="">All Status</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Date From</label>
                                <input type="date" class="form-control" id="filterDateFrom" onchange="applyFilters()">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Date To</label>
                                <input type="date" class="form-control" id="filterDateTo" onchange="applyFilters()">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Payee Name <small class="text-muted">(live)</small></label>
                                <input type="text" class="form-control" id="filterPayeeName" placeholder="Search by name..." oninput="applyFilters()">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-4">
                                <label class="form-label">Department/Source</label>
                                <select class="form-select" id="filterDepartment" onchange="applyFilters()">
                                    <option value="">All Departments</option>
                                    <option value="Payroll">Payroll</option>
                                    <option value="Claims">HR3 Claims</option>
                                </select>
                            </div>
                            <div class="col-md-8">
                                <label class="form-label">&nbsp;</label>
                                <div>
                                    <button class="btn btn-outline-secondary" onclick="clearFilters()"><i class="fas fa-redo me-1"></i>Clear All Filters</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table table-striped" id="disbursementsTable">
                        <thead>
                            <tr>
                                <th width="30"><input type="checkbox" id="selectAllCheckbox" onchange="toggleSelectAll(this)"></th>
                                <th>Reference #</th>
                                <th>Payee</th>
                                <th>Payment Method</th>
                                <th>Date</th>
                                <th>Amount</th>
                                <th>Status</th>
                                <th>Source</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="disbursementsTableBody">
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Claims Processing Tab -->
            <div class="tab-pane fade" id="claims" role="tabpanel" aria-labelledby="claims-tab">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="mb-0">HR3 Claims Processing - From HR3 API</h6>
                    <div>
                        <button class="btn btn-success" onclick="loadClaims()">
                            <i class="fas fa-sync me-2"></i>Load Claims
                        </button>
                    </div>
                </div>



                <div class="table-responsive">
                    <table class="table table-striped" id="claimsTable">
                        <thead>
                            <tr>
                                <th>Claim ID</th>
                                <th>Employee</th>
                                <th>Type</th>
                                <th>Amount</th>
                                <th>Date</th>
                                <th>Description</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="claimsTableBody">
                            <tr>
                                <td colspan="7" class="text-center">
                                    <div class="text-muted">Click "Load Claims" to fetch approved claims from HR3 system</div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Payroll Processing Tab -->
            <div class="tab-pane fade" id="payroll" role="tabpanel" aria-labelledby="payroll-tab">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="mb-0">Payroll Processing</h6>
                    <div>
                        <button class="btn btn-success" onclick="loadPayroll(this)">
                            <i class="fas fa-sync me-2"></i>Load Payroll
                        </button>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table table-striped" id="payrollTable">
                        <thead>
                            <tr>
                                <th>Payroll Period</th>
                                <th>Total Amount</th>
                                <th>Employees</th>
                                <th>Submitted By</th>
                                <th>Submitted At</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="payrollTableBody">
                            <tr>
                                <td colspan="7" class="text-center">
                                    <div class="text-muted">Click "Load Payroll" to fetch payroll data from HR4 system</div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Incentives Processing Tab -->
            <div class="tab-pane fade" id="incentives" role="tabpanel" aria-labelledby="incentives-tab">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="mb-0">HR4 Incentives Processing</h6>
                    <div>
                        <button class="btn btn-success" onclick="loadIncentives(this)">
                            <i class="fas fa-sync me-2"></i>Load Incentives
                        </button>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table table-striped" id="incentivesTable">
                        <thead>
                            <tr>
                                <th>Employee</th>
                                <th>Department</th>
                                <th>Position</th>
                                <th>Period</th>
                                <th>Incentive Amount</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="incentivesTableBody">
                            <tr>
                                <td colspan="7" class="text-center">
                                    <div class="text-muted">Click "Load Incentives" to fetch incentives from HR4 system</div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Logistics Sync Tab -->
            <div class="tab-pane fade" id="logistics" role="tabpanel" aria-labelledby="logistics-tab">
                <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                    <div>
                        <h6 class="mb-0">Procurement and Transportation Integrations</h6>
                        <small class="text-muted">Sync Logistics 1 supplier invoices and purchase orders, plus Logistics 2 trip costs, into the local finance workflow.</small>
                    </div>
                    <div class="d-flex flex-wrap gap-2">
                        <button class="btn btn-outline-secondary" onclick="loadLogisticsActivity(this)">
                            <i class="fas fa-rotate me-2"></i>Refresh Activity
                        </button>
                        <button class="btn btn-primary" onclick="runLogisticsImport('logistics1', 'importInvoices', this)">
                            <i class="fas fa-file-invoice-dollar me-2"></i>Import Supplier Invoices
                        </button>
                        <button class="btn btn-outline-primary" onclick="runLogisticsImport('logistics1', 'importPurchaseOrders', this)">
                            <i class="fas fa-cart-shopping me-2"></i>Import Purchase Orders
                        </button>
                        <button class="btn btn-success" onclick="runLogisticsImport('logistics2', 'importTripCosts', this)">
                            <i class="fas fa-truck me-2"></i>Import Trip Costs
                        </button>
                    </div>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-lg-3 col-md-6">
                        <div class="card h-100 text-center">
                            <div class="card-body">
                                <i class="fas fa-file-invoice-dollar fa-2x text-primary mb-2"></i>
                                <h6 class="text-muted">Supplier Invoices</h6>
                                <h3 id="logisticsInvoiceCount">0</h3>
                                <small class="text-muted" id="logisticsInvoiceAmount">PHP 0.00</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-3 col-md-6">
                        <div class="card h-100 text-center">
                            <div class="card-body">
                                <i class="fas fa-cart-shopping fa-2x text-info mb-2"></i>
                                <h6 class="text-muted">Purchase Orders</h6>
                                <h3 id="logisticsPurchaseOrderCount">0</h3>
                                <small class="text-muted" id="logisticsPurchaseOrderAmount">PHP 0.00</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-3 col-md-6">
                        <div class="card h-100 text-center">
                            <div class="card-body">
                                <i class="fas fa-truck fa-2x text-success mb-2"></i>
                                <h6 class="text-muted">Trip Costs</h6>
                                <h3 id="logisticsTripCount">0</h3>
                                <small class="text-muted" id="logisticsTripAmount">PHP 0.00</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-3 col-md-6">
                        <div class="card h-100 text-center">
                            <div class="card-body">
                                <i class="fas fa-boxes-stacked fa-2x text-warning mb-2"></i>
                                <h6 class="text-muted">Total Imported</h6>
                                <h3 id="logisticsTotalAmount">PHP 0.00</h3>
                                <small class="text-muted" id="logisticsLastImported">No local imports yet</small>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table table-striped" id="logisticsTable">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>System</th>
                                <th>Type</th>
                                <th>Reference</th>
                                <th>Department</th>
                                <th>Description</th>
                                <th>Amount</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody id="logisticsTableBody">
                            <tr>
                                <td colspan="8" class="text-center">
                                    <div class="text-muted">Click "Refresh Activity" to load recent logistics imports</div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Vouchers & Documentation Tab -->
            <div class="tab-pane fade" id="vouchers" role="tabpanel" aria-labelledby="vouchers-tab">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="mb-0">Payment Vouchers and Documentation</h6>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#uploadVoucherModal">
                        <i class="fas fa-plus me-2"></i>Upload Voucher
                    </button>
                </div>

                <div class="row mb-3">
                    <div class="col-md-4">
                        <select class="form-select" id="disbursementFilter" onchange="filterVouchersByDisbursement()">
                            <option value="">All Recent Disbursements</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <select class="form-select">
                            <option value="">All Types</option>
                            <option value="receipt">Receipt</option>
                            <option value="invoice">Invoice</option>
                            <option value="contract">Contract</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <button class="btn btn-outline-secondary" onclick="refreshVouchers()">
                            <i class="fas fa-sync-alt me-1"></i>Refresh
                        </button>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table table-striped" id="vouchersTable">
                        <thead>
                            <tr>
                                <th>Voucher #</th>
                                <th>Type</th>
                                <th>Disbursement Ref</th>
                                <th>Date</th>
                                <th>File Info</th>
                                <th>Uploaded By</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="vouchersTableBody">
                            <!-- Vouchers will be loaded here -->
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Reports & Analytics Tab -->
            <div class="tab-pane fade" id="reports" role="tabpanel" aria-labelledby="reports-tab">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="mb-0">Disbursement Reports and Analytics</h6>
                    <button class="btn btn-outline-secondary"><i class="fas fa-download me-2"></i>Export Report</button>
                </div>
                <div class="row mb-4">
                    <div class="col-md-4">
                        <div class="card text-center">
                            <div class="card-body">
                                <h3 id="totalDisbursementsCount">-</h3>
                                <h6 class="text-muted">Total Disbursements</h6>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card text-center">
                            <div class="card-body">
                                <h3 id="totalDisbursementsAmount">-</h3>
                                <h6 class="text-muted">Total Amount</h6>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card text-center">
                            <div class="card-body">
                                <h3 id="pendingDisbursementsCount">-</h3>
                                <h6 class="text-muted">Pending</h6>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-12">
                        <div class="card">
                            <div class="card-header">
                                <h6>Cash Flow Report (Outflows)</h6>
                            </div>
                            <div class="card-body">
                                <canvas id="cashFlowChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Audit Trail Tab -->
            <div class="tab-pane fade" id="audit" role="tabpanel" aria-labelledby="audit-tab">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="mb-0">Audit Trail and Controls</h6>
                    <button class="btn btn-outline-secondary" id="disbFilterToggleBtn" onclick="toggleDisbursementFiltersInline()"><i class="fas fa-filter me-2"></i>Filter Logs</button>
                </div>
                <div id="disbFiltersInline" class="card mb-3" style="display:none;">
                    <div class="card-body">
                        <form id="disbAuditFilterInlineForm" class="row g-2 align-items-end">
                            <div class="col-auto">
                                <label for="disbFilterDateFrom" class="form-label mb-0">Date From</label>
                                <input type="date" class="form-control" id="disbFilterDateFrom">
                            </div>
                            <div class="col-auto">
                                <label for="disbFilterDateTo" class="form-label mb-0">Date To</label>
                                <input type="date" class="form-control" id="disbFilterDateTo">
                            </div>
                            <div class="col-auto">
                                <label for="disbFilterUser" class="form-label mb-0">User</label>
                                <input type="text" class="form-control" id="disbFilterUser" list="disbFilterUserList" placeholder="Name or username">
                                <datalist id="disbFilterUserList"></datalist>
                            </div>
                            <div class="col-auto">
                                <label for="disbFilterAction" class="form-label mb-0">Action</label>
                                <select class="form-select" id="disbFilterAction">
                                    <option value="">All Actions</option>
                                    <option value="Created">Created</option>
                                    <option value="Updated">Updated</option>
                                    <option value="Deleted">Deleted</option>
                                    <option value="Viewed">Viewed</option>
                                    <option value="Processed Payment">Processed Payment</option>
                                    <option value="Printed">Printed</option>
                                </select>
                            </div>
                            <div class="col-auto">
                                <label for="disbFilterRef" class="form-label mb-0">Ref / ID</label>
                                <input type="text" class="form-control" id="disbFilterRef" placeholder="DISB-YYYYMMDD-### or ID">
                            </div>
                            <div class="col-auto">
                                <button type="button" class="btn btn-secondary" onclick="clearDisbursementFilters()">Clear</button>
                                <button type="button" class="btn btn-primary ms-2" onclick="applyDisbursementFilters()">Apply</button>
                            </div>
                        </form>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-striped" id="auditTable">
                        <thead>
                            <tr>
                                <th>Date/Time</th>
                                <th>User</th>
                                <th>Action</th>
                                <th>Disbursement Ref</th>
                                <th>Details</th>
                            </tr>
                        </thead>
                        <tbody id="auditTableBody">
                            <!-- Audit logs will be loaded here -->
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Voucher Upload Modal -->
            <div class="modal fade" id="uploadVoucherModal" tabindex="-1">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">Upload Voucher</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <form id="voucherUploadForm" enctype="multipart/form-data">
                                <div class="mb-3">
                                    <label for="voucherDisbursementId" class="form-label">Disbursement Reference *</label>
                                    <select class="form-select" id="voucherDisbursementId" name="disbursement_id" required>
                                        <option value="">Select Disbursement</option>
                                        <!-- Will be populated by JavaScript -->
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label for="voucherType" class="form-label">Voucher Type *</label>
                                    <select class="form-select" id="voucherType" name="voucher_type" required>
                                        <option value="receipt">Receipt</option>
                                        <option value="invoice">Invoice</option>
                                        <option value="contract">Contract</option>
                                        <option value="other">Other Document</option>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label for="voucherFile" class="form-label">File *</label>
                                    <input type="file" class="form-control" id="voucherFile" name="voucher_file"
                                           accept="image/*,.pdf" required>
                                    <small class="form-text text-muted">
                                        Supports images (JPG, PNG, GIF) and PDF files (max 5MB)
                                    </small>
                                </div>
                            </form>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="button" class="btn btn-primary" onclick="uploadVoucher()">
                                <i class="fas fa-upload me-1"></i>Upload Voucher
                            </button>
                        </div>
                    </div>
                </div>
            </div>


        </div>
    </div>

    <!-- Disbursement Modal -->
    <div class="modal fade" id="disbursementModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle">Add Disbursement</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="disbursementForm">
                        <input type="hidden" id="disbursementId" name="disbursement_id">
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="vendorId" class="form-label">Vendor *</label>
                                <select class="form-select" id="vendorId" name="vendor_id" required>
                                    <option value="">Select Vendor</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="disbursementDate" class="form-label">Disbursement Date *</label>
                                <input type="date" class="form-control" id="disbursementDate" name="disbursement_date" required>
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="amount" class="form-label">Amount *</label>
                                <input type="number" class="form-control" id="amount" name="amount" step="0.01" placeholder="0.00" required>
                            </div>
                            <div class="col-md-6">
                                <label for="paymentMethod" class="form-label">Payment Method *</label>
                                <select class="form-select" id="paymentMethod" name="payment_method" required>
                                    <option value="">Select Method</option>
                                    <option value="cash">Cash</option>
                                    <option value="check">Check</option>
                                    <option value="bank_transfer">Bank Transfer</option>
                                    <option value="ewallet">E-wallet</option>
                                </select>
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="referenceNumber" class="form-label">Reference Number</label>
                                <input type="text" class="form-control" id="referenceNumber" name="reference_number" placeholder="Check # or Transaction ID">
                            </div>
                            <div class="col-md-6">
                                <label for="billId" class="form-label">Related Bill (Optional)</label>
                                <select class="form-select" id="billId" name="bill_id">
                                    <option value="">Select Bill</option>
                                </select>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="notes" class="form-label">Notes</label>
                            <textarea class="form-control" id="notes" name="notes" rows="3" placeholder="Additional notes"></textarea>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="saveDisbursement()">Save Disbursement</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Process Payment Modal -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../includes/alert-modal.js"></script>
    <script src="disbursements-js.php"></script>
    <!-- Helper functions that need to be available immediately - defined at global scope -->
    <script>
        // Helper function to show loading in table - defined inline to be available before external scripts load
        window.showTableLoading = function(tbodyId, loadingText) {
            const tbody = document.getElementById(tbodyId);
            if (!tbody) return;
            const table = tbody.closest('table');
            const thCount = table ? table.querySelectorAll('thead th').length : 1;
            tbody.innerHTML = `<tr><td colspan="${thCount}" class="text-center"><div class="d-inline-flex align-items-center gap-2"><i class="fas fa-spinner fa-spin"></i><span>${loadingText}</span></div></td></tr>`;
        };

        // Helper function to show error in table
        window.showTableError = function(tbodyId, errorText) {
            const tbody = document.getElementById(tbodyId);
            if (!tbody) return;
            const table = tbody.closest('table');
            const thCount = table ? table.querySelectorAll('thead th').length : 1;
            tbody.innerHTML = `<tr><td colspan="${thCount}" class="text-center text-muted">${errorText}</td></tr>`;
        };
    </script>
    <script>
        // Ensure global handler exists to avoid ReferenceError if external JS fails to load
        // Provide an inline toggle for the filter panel: it should behave like the General Ledger filter dropdown
        function toggleDisbursementFiltersInline() {
            const panel = document.getElementById('disbFiltersInline');
            if (!panel) return;
            if (panel.style.display === 'none' || panel.style.display === '') {
                panel.style.display = 'block';
                document.getElementById('disbFilterToggleBtn')?.classList.add('active');
            } else {
                panel.style.display = 'none';
                document.getElementById('disbFilterToggleBtn')?.classList.remove('active');
            }
        }
    </script>
    <script>
        // Initialize sidebar state
        document.addEventListener('DOMContentLoaded', function() {
            const sidebar = document.getElementById('sidebar');
            const content = document.querySelector('.content');
            const arrow = document.getElementById('sidebarArrow');
            const toggle = document.querySelector('.sidebar-toggle');
            const logoImg = document.querySelector('.navbar-brand img');
            // Default state is collapsed (consistent with other admin pages)
            const isCollapsed = localStorage.getItem('sidebarCollapsed') !== 'false';
            if (isCollapsed) {
                sidebar.classList.add('sidebar-collapsed');
                logoImg.src = 'atieralogo2.png';
                content.style.marginLeft = '120px';
                arrow.classList.remove('fa-chevron-left');
                arrow.classList.add('fa-chevron-right');
                toggle.style.left = '110px';
            } else {
                // Default: sidebar remains expanded
                sidebar.classList.remove('sidebar-collapsed');
                logoImg.src = 'atieralogo.png';
                content.style.marginLeft = '300px';
                arrow.classList.remove('fa-chevron-right');
                arrow.classList.add('fa-chevron-left');
                toggle.style.left = '290px';
            }

            // Load initial data
            loadDisbursements();
            loadVendors();
        });

        function toggleSidebarDesktop() {
            const sidebar = document.getElementById('sidebar');
            const content = document.querySelector('.content');
            const arrow = document.getElementById('sidebarArrow');
            const toggle = document.querySelector('.sidebar-toggle');
            const logoImg = document.querySelector('.navbar-brand img');
            sidebar.classList.toggle('sidebar-collapsed');
            const isCollapsed = sidebar.classList.contains('sidebar-collapsed');
            localStorage.setItem('sidebarCollapsed', isCollapsed);
            if (isCollapsed) {
                logoImg.src = 'atieralogo2.png';
                content.style.marginLeft = '120px';
                arrow.classList.remove('fa-chevron-left');
                arrow.classList.add('fa-chevron-right');
                toggle.style.left = '110px';
            } else {
                logoImg.src = 'atieralogo.png';
                content.style.marginLeft = '300px';
                arrow.classList.remove('fa-chevron-right');
                arrow.classList.add('fa-chevron-left');
                toggle.style.left = '290px';
            }
        }

    </script>

    <!-- HR3 Claims Processing Functions -->
    <script>
        // Wait for DOM to be fully loaded before defining functions
    window.addEventListener('DOMContentLoaded', function() {
        window.payrollApproverName = "<?php echo htmlspecialchars($_SESSION['user']['full_name'] ?? $_SESSION['user']['username'] ?? 'Finance Department', ENT_QUOTES); ?>";
        window.payrollApproverId = <?php echo (int)($_SESSION['user']['id'] ?? 0); ?>;
        window.payrollExpenseAccountId = <?php echo json_encode($payrollExpenseAccountId); ?>;
        // Auto-load HR3 claims when Claims Processing tab is activated
        const claimsTab = document.getElementById('claims-tab');
        if (claimsTab) {
            claimsTab.addEventListener('shown.bs.tab', function() {
                // Check if claims table is empty (no claims loaded yet)
                const claimsTableBody = document.getElementById('claimsTableBody');
                if (claimsTableBody && claimsTableBody.children.length === 1) {
                    const firstChild = claimsTableBody.children[0];
                    if (firstChild && firstChild.tagName === 'TR' && firstChild.textContent.includes('Click "Load Claims"')) {
                        // Auto-load claims if not already loaded
                        window.loadClaims();
                    }
                }
            });
        }
        window.loadClaims = async function() {
            const btn = (typeof event !== 'undefined' && event.target)
                ? event.target.closest('button')
                : document.querySelector('button[onclick="loadClaims()"]');
            const originalText = btn ? btn.innerHTML : '';

            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Loading Claims...';
            }

            try {
                const response = await fetch('../api/integrations.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: new URLSearchParams({
                        action: 'execute',
                        integration_name: 'hr3',
                        action_name: 'getApprovedClaims'
                    }),
                    credentials: 'include' // Include cookies for session
                });

                if (!response.ok) {
                    const errorText = await response.text();
                    console.error('HR3 Claims API Error:', response.status, response.statusText, errorText);
                    throw new Error(`HTTP ${response.status}: ${errorText || response.statusText}`);
                }

                const result = await response.json();

                if (result.success && result.result) {
                    window.displayHR3Claims(result.result);
                } else if (Array.isArray(result) && result.length > 0) {
                    window.displayHR3Claims(result);
                } else {
                    const errorMsg = result.error || 'No claims found';
                    console.error('HR3 Claims Error:', result);
                    window.showAlert('Error loading claims: ' + errorMsg, 'danger');
                }
            } catch (error) {
                console.error('HR3 Claims loading error:', error);
                window.showAlert('Error loading claims: ' + error.message, 'danger');
            } finally {
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = originalText;
                }
            }
        };

        window.loadHR3Claims = function() {
            // Backward compatibility - calls the new loadClaims function
            return window.loadClaims();
        };

        function normalizeClaimsPayload(claims) {
            if (Array.isArray(claims)) {
                return claims;
            }
            if (claims && Array.isArray(claims.result)) {
                return claims.result;
            }
            if (claims && Array.isArray(claims.data)) {
                return claims.data;
            }
            if (claims && Array.isArray(claims.claims)) {
                return claims.claims;
            }
            return [];
        }

        window.displayHR3Claims = function(claims) {
            const tbody = document.getElementById('claimsTableBody');
            const normalizedClaims = normalizeClaimsPayload(claims);

            if (!normalizedClaims || normalizedClaims.length === 0) {
                tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted">No claims available</td></tr>';
                if (window.updateTabBadge) {
                    window.updateTabBadge('claims-tab', 0);
                }
                return;
            }

            tbody.innerHTML = '';

            // Filter out already processed/paid claims - only show pending/approved claims that haven't been processed to disbursements yet
            const unprocessedClaims = normalizedClaims.filter(claim => {
                const status = (claim.status || 'Pending').toLowerCase();
                // Exclude claims that are already paid or fully processed
                return status !== 'paid' && status !== 'processed' && status !== 'cancelled' && status !== 'rejected';
            });

            if (unprocessedClaims.length === 0) {
                tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted">All claims have been processed and moved to Disbursement Records</td></tr>';
                if (window.updateTabBadge) {
                    window.updateTabBadge('claims-tab', 0);
                }
                return;
            }

            // Show unprocessed claims - let user process which ones to disburse
            const sortedClaims = unprocessedClaims.sort((a, b) => new Date(b.created_at || 0) - new Date(a.created_at || 0));

            sortedClaims.forEach(claim => {
                const amount = parseFloat(claim.total_amount || claim.amount || 0);
                const status = claim.status || 'Pending';
                const statusBadge = status === 'Approved' 
                    ? '<span class="badge bg-success">Approved</span>' 
                    : '<span class="badge bg-warning text-dark">' + status + '</span>';

                const row = document.createElement('tr');
                row.innerHTML = `
                    <td><strong>${claim.claim_id || claim.id || 'N/A'}</strong></td>
                    <td>${claim.employee_name || claim.employee || 'N/A'}</td>
                    <td>${statusBadge}</td>
                    <td><strong>₱${amount.toFixed(2)}</strong> ${claim.currency_code ? '(' + claim.currency_code + ')' : ''}</td>
                    <td>${window.formatDate(claim.created_at)}</td>
                    <td>${claim.remarks || claim.description || 'No remarks'}</td>
                    <td>
                        <button class="btn btn-success btn-sm" onclick="processHR3Claim('${claim.claim_id || claim.id}', '${claim.employee_name || claim.employee}', ${amount}, '${(claim.remarks || claim.description || '').replace(/'/g, "\\'")}', '${claim.currency_code || 'PHP'}')">
                            <i class="fas fa-money-bill-wave me-1"></i>Process
                        </button>
                    </td>
                `;
                tbody.appendChild(row);
            });

            if (window.updateTabBadge) {
                window.updateTabBadge('claims-tab', sortedClaims.length);
            }
        };

        window.processHR3Claim = async function(claimId, employeeName, amount, description, currency) {
            const btn = event.target.closest('button');
            const originalText = btn.innerHTML;

            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Processing...';

            let hr3SyncResult = null;

            try {
                // Step 1: Update HR3 claim status first
                try {
                    hr3SyncResult = await window.markHR3ClaimAsPaid(claimId);
                } catch (hr3Error) {
                    hr3SyncResult = { success: false, error: hr3Error.message };
                }

                // Step 2: Create disbursement record
                const disbursementData = {
                    payment_date: new Date().toISOString().split('T')[0],
                    amount: amount,
                    payment_method: 'bank_transfer', // Default payment method
                    reference_number: `HR3-CLAIM-${claimId}`,
                    payee: employeeName,
                    description: `HR3 Claim Payment: ${description}`
                };

                // Log HR3 claim processing to audit trail
                try {
                    const auditResponse = await fetch('../api/audit.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        credentials: 'include',
                        body: new URLSearchParams({
                            action: 'log',
                            table_name: 'hr3_claims',
                            record_id: claimId,
                            action_type: 'processed_payment',
                            old_values: JSON.stringify({ status: 'Approved' }),
                            new_values: JSON.stringify({
                                status: 'Paid',
                                disbursement_created: true,
                                processed_by: 'Current User',
                                amount: amount,
                                employee: employeeName
                            })
                        })
                    });
                    
                    const auditResult = await auditResponse.json();
                    if (!auditResult.success) {
                        console.warn('HR3 claim audit logging returned:', auditResult);
                    }
                } catch (auditError) {
                    console.warn('HR3 claim audit logging failed:', auditError);
                    // Don't fail the main operation if audit logging fails
                }

                const response = await fetch('../api/disbursements.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    credentials: 'include',
                    body: JSON.stringify({
                        action: 'process_payment',
                        ...disbursementData
                    })
                });

                const result = await response.json();

                if (result.success) {
                    if (hr3SyncResult && !hr3SyncResult.success) {
                        window.showAlert('HR3 system update failed (disbursement still created).', 'danger');
                    }

                    // Remove the processed claim row
                    btn.closest('tr').remove();

                    // Always refresh disbursements records (enhancement for visibility)
                    setTimeout(() => {
                        loadDisbursements();

                        // Show additional notification on Disbursements Records tab
                        const recordsTabLink = document.getElementById('records-tab');
                        if (recordsTabLink) {
                            // Add visual indicator that records were updated
                            recordsTabLink.innerHTML += ' <span class="badge bg-success">🔄 Updated</span>';
                            setTimeout(() => {
                                recordsTabLink.innerHTML = recordsTabLink.innerHTML.replace(' <span class="badge bg-success">🔄 Updated</span>', '');
                            }, 3000);
                        }

                        // If user is on records tab, also update the tab badge
                        if (document.getElementById('records-tab').classList.contains('active')) {
                            // No success notifications.
                        }
                    }, 500); // Small delay to ensure database commit
                } else {
                    window.showAlert('Error processing claim: ' + (result.error || 'Unknown error'), 'danger');
                }
            } catch (error) {
                window.showAlert('Error: ' + error.message, 'danger');
            } finally {
                btn.disabled = false;
                btn.innerHTML = originalText;
            }
        };

        window.markHR3ClaimAsPaid = async function(claimId) {
            // This would call the HR3 API to update claim status to "Paid"
            // Implementation depends on HR3 API capabilities
            const response = await fetch('../api/integrations.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    action: 'execute',
                    integration_name: 'hr3',
                    action_name: 'updateClaimStatus',
                    claim_id: claimId,
                    status: 'Paid'
                })
            });

            const result = await response.json();
            return result;
        };

        window.testHR3Connection = async function() {
            // Show loading
            const btn = event.target.closest('button');
            const originalText = btn.innerHTML;
            const originalClass = btn.className;

            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Testing...';
            btn.className = 'btn btn-warning';

            try {
                // First, get an actual claim from the HR3 API to test with
                const claimsResponse = await fetch('../api/integrations.php?action=execute&integration_name=hr3&action_name=getApprovedClaims', {
                    method: 'GET'
                });
                const claimsData = await claimsResponse.json();

                if (!claimsData.success || !claimsData.data || claimsData.data.length === 0) {
                    window.showAlert('No approved claims available from HR3 API to test with', 'danger');
                    btn.innerHTML = '<i class="fas fa-sync me-2"></i>Test 2-Way Sync';
                    btn.className = 'btn btn-info';
                    return;
                }

                // Use the first available claim for testing
                const testClaimId = claimsData.data[0].claim_id;

                // Test claim status update with the actual claim ID
                const response = await fetch('../api/integrations.php?action=execute&integration_name=hr3&action_name=updateClaimStatus', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: new URLSearchParams({
                        claim_id: testClaimId,
                        status: 'Paid'
                    })
                });

                const result = await response.json();

                if (result.success) {
                    // No success notifications.
                } else {
                    // Build comprehensive error message with solutions
                    let errorMessage = '❌ HR3 Connection FAILED: ' + result.error + '\n\n';

                    // HTTP 405 specific solution
                    if (result.http_code === 405 && result.detailed_solution) {
                        errorMessage += '🔧 EXACT FIX REQUIRED (HTTP 405):\n';
                        errorMessage += 'Choose your HR3 web server and apply the configuration:\n\n';

                        if (result.detailed_solution.apache_htaccess) {
                            errorMessage += '📄 APACHE (.htaccess):\n';
                            errorMessage += result.detailed_solution.apache_htaccess + '\n\n';
                        }

                        if (result.detailed_solution.nginx_location) {
                            errorMessage += '🌐 NGINX:\n';
                            errorMessage += result.detailed_solution.nginx_location + '\n\n';
                        }

                        if (result.detailed_solution.apache_vhost) {
                            errorMessage += '🖥️ APACHE VHOST:\n';
                            errorMessage += result.detailed_solution.apache_vhost + '\n';
                        }

                        errorMessage += '\n✨ After applying, click "Test HR3 Connection" again.';
                    }

                    // Generic solution
                    errorMessage += '\n💡 If above doesn\'t work, also check:\n';
                    errorMessage += '• Enable PUT support in web server configuration\n';
                    errorMessage += '• Check PHP always_populate_raw_post_data setting\n';
                    errorMessage += '• Verify file permissions on HR3 server\n';

                    window.showAlert(errorMessage, 'danger');
                }

            } catch (error) {
                window.showAlert('❌ Error testing HR3 connection: ' + error.message, 'danger');
            } finally {
                // Restore button
                btn.disabled = false;
                btn.innerHTML = originalText;
                btn.className = originalClass;
            }
        };

        window.showAlert = function(message, type = 'info') {
            const alertDiv = document.createElement('div');
            alertDiv.className = `alert alert-${type} alert-dismissible fade show`;
            alertDiv.innerHTML = `
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;

            // Insert at top of content area
            const content = document.querySelector('.content');
            content.insertBefore(alertDiv, content.firstChild);

            // Auto-hide after 5 seconds
            setTimeout(() => {
                alertDiv.remove();
            }, 5000);
        };

        window.formatDate = function(dateString) {
            if (!dateString) return 'N/A';
            try {
                return new Date(dateString).toLocaleDateString();
            } catch (e) {
                return dateString;
            }
        };
    });
    </script>

    <!-- HR4 Payroll Processing Functions -->
    <script>
        // Wait for DOM to be fully loaded before defining functions
    window.addEventListener('DOMContentLoaded', function() {

        window.loadPayroll = async function(buttonEl) {
            window.showTableLoading('payrollTableBody', 'Loading payroll...');

            const btn = buttonEl && buttonEl.closest ? buttonEl.closest('button') : null;
            const originalText = btn ? btn.innerHTML : '';

            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Loading Payroll...';
            }

            try {
                // Use the integration API to fetch payroll data
                const response = await fetch('../api/integrations.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: new URLSearchParams({
                        action: 'execute',
                        integration_name: 'hr4',
                        action_name: 'getPayrollData'
                    }),
                    credentials: 'include'
                });

                if (!response.ok) {
                    // Try to get error details from response
                    const responseText = await response.text();
                    let errorMessage = `HTTP ${response.status}`;
                    if (responseText) {
                        try {
                            const errorData = JSON.parse(responseText);
                            errorMessage = errorData.error || errorData.message || errorMessage;
                        } catch (e) {
                            errorMessage = responseText;
                        }
                    } else if (response.status === 500) {
                        errorMessage = 'HR4 Integration not configured or API is unreachable';
                    }
                    throw new Error(errorMessage);
                }

                const result = await response.json();

                if (result.success && result.result) {
                    window.displayHR4Payroll(result.result);
                    // No success notifications.
                } else if (result.success && (!result.result || result.result.length === 0)) {
                    // Integration call succeeded but returned empty data
                    window.displayHR4Payroll([]);
                } else {
                    window.showAlert('Error loading payroll: ' + (result.error || 'No payroll data found'), 'danger');
                }
            } catch (error) {
                console.error('HR4 Payroll loading error:', error);
                window.showAlert('Error loading payroll: ' + error.message, 'danger');
            } finally {
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = originalText;
                }
            }
        };

        window.displayHR4Payroll = function(payrollData) {
            const tbody = document.getElementById('payrollTableBody');

            if (!payrollData || payrollData.length === 0) {
                tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted">No payroll data found in HR4 system</td></tr>';
                if (window.updateTabBadge) {
                    window.updateTabBadge('payroll-tab', 0);
                }
                return;
            }

            tbody.innerHTML = '';

            payrollData.forEach(payroll => {
                const payrollId = payroll.approval_id || payroll.payroll_id || payroll.payrollId || payroll.id || '';
                const totalAmount = parseFloat(payroll.total_amount || payroll.net_pay || 0);
                const submittedAt = payroll.submitted_at ? new Date(payroll.submitted_at).toLocaleString() : 'N/A';
                const rawStatus = (payroll.status || '').toString();
                let statusText = payroll.display_status || rawStatus || 'Unknown';
                const rawKey = rawStatus.toLowerCase();
                // If payroll already approved/processed in external system, skip showing it here
                if (rawKey === 'approved' || rawKey === 'processed') return;
                // Do not display 'approved'/'processed' status here — leave blank
                if (rawKey === 'approved' || rawKey === 'processed') {
                    statusText = '';
                } else if (rawKey === 'rejected') {
                    statusText = 'Rejected';
                }
                const statusKey = String(statusText).toLowerCase();
                // Only allow approving for pending/for approval states — do not allow when already processed
                const canApprove = Boolean(payroll.can_approve) || ['pending', 'pending approval', 'for approval'].includes(statusKey);

                const row = document.createElement('tr');
                row.dataset.payrollId = payrollId;
                row.dataset.period = payroll.period_display || payroll.payroll_period || '';
                row.dataset.amount = totalAmount;
                row.dataset.submittedBy = payroll.submitted_by || '';
                row.dataset.employeeCount = payroll.employee_count || '';
                let actionsHtml = '';
                
                // Show approve/reject buttons only for payroll that can be approved
                if (canApprove) {
                    actionsHtml = `
                        <button class="btn btn-success btn-sm me-2" onclick="updatePayrollApproval(this, '${payrollId}', 'approve')">
                            <i class="fas fa-check me-1"></i>Approve
                        </button>
                        <button class="btn btn-danger btn-sm" onclick="updatePayrollApproval(this, '${payrollId}', 'reject')">
                            <i class="fas fa-times me-1"></i>Reject
                        </button>
                    `;
                } else {
                    // For already processed payroll, show view details button
                    actionsHtml = `
                        <button class="btn btn-info btn-sm" onclick="viewPayrollDetails(this, '${payrollId}')">
                            <i class="fas fa-eye me-1"></i>View Details
                        </button>
                    `;
                }
                
                row.innerHTML = `
                    <td><strong>${payroll.period_display || payroll.payroll_period || 'N/A'}</strong></td>
                    <td><strong class="text-success">PHP ${totalAmount.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</strong></td>
                    <td>${payroll.employee_count || 'N/A'}</td>
                    <td>${payroll.submitted_by || 'N/A'}</td>
                    <td>${submittedAt}</td>
                    <td>${statusText ? `<span class="badge ${canApprove ? 'bg-info' : 'bg-secondary'}">${statusText}</span>` : ''}</td>
                    <td>${actionsHtml}</td>
                `;
                tbody.appendChild(row);
            });

            if (window.updateTabBadge) {
                window.updateTabBadge('payroll-tab', payrollData.length);
            }
        };

        async function createPayrollDisbursement(row) {
            const payrollId = row?.dataset?.payrollId || '';
            const period = row?.dataset?.period || 'Payroll';
            const amount = parseFloat(row?.dataset?.amount || 0);

            if (!payrollId || !amount) {
                return;
            }

            const disbursementData = {
                payee: `HR4 Payroll - ${period}`,
                payment_date: new Date().toISOString().split('T')[0],
                amount: amount,
                payment_method: 'bank_transfer',
                reference_number: payrollId,
                description: `Payroll approval for ${period}`,
                account_id: window.payrollExpenseAccountId || null
            };

            const response = await fetch('../api/disbursements.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                credentials: 'include',
                body: JSON.stringify({
                    action: 'process_payment',
                    ...disbursementData
                })
            });

            const rawText = await response.text();
            let result = null;
            try {
                result = rawText ? JSON.parse(rawText) : null;
            } catch (parseError) {
                // Keep rawText for diagnostics.
            }

            if (!response.ok) {
                const errorMessage = result?.error || rawText || `HTTP ${response.status}`;
                throw new Error(errorMessage);
            }

            if (!result?.success) {
                const errorMessage = result?.error || rawText || 'Failed to record payroll disbursement';
                throw new Error(errorMessage);
            }

            loadDisbursements();
        }

        window.updatePayrollApproval = async function(buttonEl, payrollId, action) {
            const btn = buttonEl && buttonEl.closest ? buttonEl.closest('button') : null;
            const originalText = btn ? btn.innerHTML : '';
            const row = buttonEl && buttonEl.closest ? buttonEl.closest('tr') : null;
            const resolvedPayrollId = payrollId || (row && row.dataset ? row.dataset.payrollId : '');

            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Updating...';
            }

            try {
                if (!resolvedPayrollId) {
                    throw new Error('Payroll ID is missing for this record.');
                }
                let rejectionReason = '';
                if (action === 'reject') {
                    rejectionReason = prompt('Provide rejection reason (required for reject):', '');
                    if (rejectionReason === null || rejectionReason.trim() === '') {
                        if (btn) {
                            btn.disabled = false;
                            btn.innerHTML = originalText;
                        }
                        return;
                    }
                }

                const response = await fetch('../api/integrations.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    credentials: 'include',
                    body: new URLSearchParams({
                        action: 'execute',
                        integration_name: 'hr4',
                        action_name: 'updatePayrollStatus',
                        id: resolvedPayrollId,
                        approval_id: resolvedPayrollId,
                        approval_action: action,
                        approver: window.payrollApproverName || 'Finance Department',
                        approver_id: window.payrollApproverId || 0,
                        rejection_reason: rejectionReason,
                        params: JSON.stringify({
                            id: resolvedPayrollId,
                            action: action,
                            rejection_reason: rejectionReason
                        })
                    })
                });

                if (!response.ok) {
                    const responseText = await response.text();
                    let errorMessage = `HTTP ${response.status}`;
                    if (responseText) {
                        try {
                            const errorData = JSON.parse(responseText);
                            errorMessage = errorData.error || errorData.message || errorMessage;
                        } catch (e) {
                            errorMessage = responseText;
                        }
                    }
                    throw new Error(errorMessage);
                }

                const result = await response.json();

                const actionResult = result.result || result;
                if (result.success && (actionResult.success === undefined || actionResult.success)) {
                    if (action === 'approve') {
                        try {
                            await createPayrollDisbursement(row);
                        } catch (error) {
                            window.showAlert('Payroll approved, but failed to record disbursement: ' + error.message, 'warning');
                        }
                    }
                    window.loadPayroll();
                } else {
                    const message = actionResult.error || actionResult.message || result.error || 'Unknown error';
                    window.showAlert('Error updating payroll: ' + message, 'danger');
                }
            } catch (error) {
                window.showAlert('Error: ' + error.message, 'danger');
            } finally {
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = originalText;
                }
            }
        };

        // View payroll details
        window.viewPayrollDetails = function(buttonEl, payrollId) {
            // Show a simple alert with the payroll ID
            // Can be expanded to show a modal with full details
            window.showAlert('Payroll ID: ' + payrollId + ' is already processed and approved.', 'info');
        };

        // Auto-load payroll data on page load
        window.loadPayroll();
    });
    </script>

    <!-- HR4 Incentives Processing Functions -->
    <script>
    window.addEventListener('DOMContentLoaded', function() {
        window.loadIncentives = async function(buttonEl) {
            window.showTableLoading('incentivesTableBody', 'Loading incentives...');

            const btn = buttonEl && buttonEl.closest ? buttonEl.closest('button') : null;
            const originalText = btn ? btn.innerHTML : '';

            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Loading Incentives...';
            }

            try {
                const response = await fetch('../api/integrations.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: new URLSearchParams({
                        action: 'execute',
                        integration_name: 'hr4',
                        action_name: 'getIncentiveData',
                        params: JSON.stringify({ _ts: Date.now() })
                    }),
                    credentials: 'include'
                });

                if (!response.ok) {
                    const responseText = await response.text();
                    let errorMessage = `HTTP ${response.status}`;
                    if (responseText) {
                        try {
                            const errorData = JSON.parse(responseText);
                            errorMessage = errorData.error || errorData.message || errorMessage;
                        } catch (e) {
                            errorMessage = responseText;
                        }
                    }
                    throw new Error(errorMessage);
                }

                const result = await response.json();

                if (result.success && result.result) {
                    window.displayHR4Incentives(result.result);
                } else if (result.success && (!result.result || result.result.length === 0)) {
                    window.displayHR4Incentives([]);
                } else {
                    window.showAlert('Error loading incentives: ' + (result.error || 'No incentives data found'), 'danger');
                }
            } catch (error) {
                console.error('HR4 incentives loading error:', error);
                window.showAlert('Error loading incentives: ' + error.message, 'danger');
            } finally {
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = originalText;
                }
            }
        };

        window.displayHR4Incentives = function(incentivesData) {
            const tbody = document.getElementById('incentivesTableBody');

            if (!incentivesData || incentivesData.length === 0) {
                tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted">No incentives data found in HR4 system</td></tr>';
                if (window.updateTabBadge) {
                    window.updateTabBadge('incentives-tab', 0);
                }
                return;
            }

            tbody.innerHTML = '';

            incentivesData.forEach(incentive => {
                const incentiveId = incentive.incentive_id || incentive.id || '';
                const amount = parseFloat(incentive.amount || incentive.incentive_amount || 0);
                const statusRaw = (incentive.status || '').toString();
                const statusKey = statusRaw.toLowerCase();
                const displayStatus = incentive.display_status || statusRaw || 'Unknown';

                if (statusKey === 'approved' || statusKey === 'paid') return;

                const canApprove = Boolean(incentive.can_approve) || ['pending', 'pending finance', 'for approval'].includes(statusKey);

                const row = document.createElement('tr');
                row.dataset.incentiveId = incentiveId;
                row.dataset.period = incentive.period_display || '';
                row.dataset.amount = amount;
                row.dataset.employeeName = incentive.employee_name || '';
                row.dataset.department = incentive.department || '';
                row.dataset.position = incentive.position || '';

                let actionsHtml = '';
                if (canApprove) {
                    actionsHtml = `
                        <button class="btn btn-success btn-sm me-2" onclick="updateIncentiveApproval(this, '${incentiveId}', 'approve')">
                            <i class="fas fa-check me-1"></i>Approve
                        </button>
                        <button class="btn btn-danger btn-sm" onclick="updateIncentiveApproval(this, '${incentiveId}', 'reject')">
                            <i class="fas fa-times me-1"></i>Reject
                        </button>
                    `;
                } else {
                    actionsHtml = `
                        <button class="btn btn-info btn-sm" onclick="viewIncentiveDetails(this, '${incentiveId}')">
                            <i class="fas fa-eye me-1"></i>View Details
                        </button>
                    `;
                }

                row.innerHTML = `
                    <td><strong>${incentive.employee_name || 'N/A'}</strong></td>
                    <td>${incentive.department || 'N/A'}</td>
                    <td>${incentive.position || 'N/A'}</td>
                    <td>${incentive.period_display || 'N/A'}</td>
                    <td><strong class="text-success">PHP ${amount.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</strong></td>
                    <td>${displayStatus ? `<span class="badge ${canApprove ? 'bg-info' : 'bg-secondary'}">${displayStatus}</span>` : ''}</td>
                    <td>${actionsHtml}</td>
                `;
                tbody.appendChild(row);
            });

            if (window.updateTabBadge) {
                window.updateTabBadge('incentives-tab', incentivesData.length);
            }
        };

        async function createIncentiveDisbursement(row) {
            const incentiveId = row?.dataset?.incentiveId || '';
            const period = row?.dataset?.period || 'Incentive';
            const amount = parseFloat(row?.dataset?.amount || 0);
            const employeeName = row?.dataset?.employeeName || 'Employee';

            if (!incentiveId || !amount) {
                return;
            }

            const disbursementData = {
                payee: `HR4 Incentive - ${employeeName}`,
                payment_date: new Date().toISOString().split('T')[0],
                amount: amount,
                payment_method: 'bank_transfer',
                reference_number: incentiveId,
                description: `HR4 incentive for ${employeeName} (${period})`,
                account_id: window.payrollExpenseAccountId || null
            };

            const response = await fetch('../api/disbursements.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                credentials: 'include',
                body: JSON.stringify({
                    action: 'process_payment',
                    ...disbursementData
                })
            });

            const rawText = await response.text();
            let result = null;
            try {
                result = rawText ? JSON.parse(rawText) : null;
            } catch (parseError) {}

            if (!response.ok) {
                const errorMessage = result?.error || rawText || `HTTP ${response.status}`;
                throw new Error(errorMessage);
            }

            if (!result?.success) {
                const errorMessage = result?.error || rawText || 'Failed to record incentive disbursement';
                throw new Error(errorMessage);
            }

            await markIncentivePaid(incentiveId);
            loadDisbursements();
        }

        async function markIncentivePaid(incentiveId) {
            if (!incentiveId) return;
            const response = await fetch('../api/integrations.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                credentials: 'include',
                body: new URLSearchParams({
                    action: 'execute',
                    integration_name: 'hr4',
                    action_name: 'updateIncentiveStatus',
                    id: incentiveId,
                    approval_action: 'paid',
                    approver: window.payrollApproverName || 'Finance Department',
                    approver_id: window.payrollApproverId || 0
                })
            });

            if (!response.ok) {
                const responseText = await response.text();
                throw new Error(responseText || `HTTP ${response.status}`);
            }
        }

        window.updateIncentiveApproval = async function(buttonEl, incentiveId, action) {
            const btn = buttonEl && buttonEl.closest ? buttonEl.closest('button') : null;
            const originalText = btn ? btn.innerHTML : '';
            const row = buttonEl && buttonEl.closest ? buttonEl.closest('tr') : null;
            const resolvedIncentiveId = incentiveId || (row && row.dataset ? row.dataset.incentiveId : '');

            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Updating...';
            }

            try {
                if (!resolvedIncentiveId) {
                    throw new Error('Incentive ID is missing for this record.');
                }

                let rejectionReason = '';
                if (action === 'reject') {
                    rejectionReason = prompt('Provide rejection reason (required for reject):', '');
                    if (rejectionReason === null || rejectionReason.trim() === '') {
                        if (btn) {
                            btn.disabled = false;
                            btn.innerHTML = originalText;
                        }
                        return;
                    }
                }

                const response = await fetch('../api/integrations.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    credentials: 'include',
                    body: new URLSearchParams({
                        action: 'execute',
                        integration_name: 'hr4',
                        action_name: 'updateIncentiveStatus',
                        id: resolvedIncentiveId,
                        approval_action: action,
                        approver: window.payrollApproverName || 'Finance Department',
                        approver_id: window.payrollApproverId || 0,
                        rejection_reason: rejectionReason,
                        params: JSON.stringify({
                            id: resolvedIncentiveId,
                            action: action,
                            rejection_reason: rejectionReason
                        })
                    })
                });

                if (!response.ok) {
                    const responseText = await response.text();
                    let errorMessage = `HTTP ${response.status}`;
                    if (responseText) {
                        try {
                            const errorData = JSON.parse(responseText);
                            errorMessage = errorData.error || errorData.message || errorMessage;
                        } catch (e) {
                            errorMessage = responseText;
                        }
                    }
                    throw new Error(errorMessage);
                }

                const result = await response.json();
                const actionResult = result.result || result;

                if (result.success && (actionResult.success === undefined || actionResult.success)) {
                    if (action === 'approve') {
                        try {
                            await createIncentiveDisbursement(row);
                        } catch (error) {
                            window.showAlert('Incentive approved, but failed to record disbursement: ' + error.message, 'warning');
                        }
                    }
                    window.loadIncentives();
                } else {
                    const message = actionResult.error || actionResult.message || result.error || 'Unknown error';
                    window.showAlert('Error updating incentive: ' + message, 'danger');
                }
            } catch (error) {
                window.showAlert('Error: ' + error.message, 'danger');
            } finally {
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = originalText;
                }
            }
        };

        window.viewIncentiveDetails = function(buttonEl, incentiveId) {
            window.showAlert('Incentive ID: ' + incentiveId + ' is already processed and approved.', 'info');
        };
    });
    </script>

    <script>
    window.addEventListener('DOMContentLoaded', function() {
        const logisticsLabels = {
            LOGISTICS1: 'Logistics 1',
            LOGISTICS2: 'Logistics 2'
        };
        const transactionLabels = {
            supplier_invoice: 'Supplier Invoice',
            purchase_order: 'Purchase Order',
            transportation_expense: 'Trip Cost'
        };

        function formatMoney(value) {
            return 'PHP ' + Number(value || 0).toLocaleString('en-US', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        }

        function setText(id, value) {
            const element = document.getElementById(id);
            if (element) {
                element.textContent = value;
            }
        }

        function getLogisticsStatusBadge(status) {
            const key = String(status || 'pending').toLowerCase();
            const badgeMap = {
                pending: 'bg-warning text-dark',
                processed: 'bg-success',
                imported: 'bg-success',
                approved: 'bg-info',
                failed: 'bg-danger',
                error: 'bg-danger'
            };
            const badgeClass = badgeMap[key] || 'bg-secondary';
            const label = String(status || 'Pending').replace(/[_-]/g, ' ').replace(/\b\w/g, function(char) {
                return char.toUpperCase();
            });
            return '<span class="badge ' + badgeClass + '">' + escapeHtml(label) + '</span>';
        }

        function renderLogisticsSummary(summary) {
            setText('logisticsInvoiceCount', String(summary.invoice_count || 0));
            setText('logisticsInvoiceAmount', formatMoney(summary.invoice_amount));
            setText('logisticsPurchaseOrderCount', String(summary.purchase_order_count || 0));
            setText('logisticsPurchaseOrderAmount', formatMoney(summary.purchase_order_amount));
            setText('logisticsTripCount', String(summary.trip_count || 0));
            setText('logisticsTripAmount', formatMoney(summary.trip_amount));
            setText('logisticsTotalAmount', formatMoney(summary.total_amount));

            const lastImported = summary.last_imported_at
                ? 'Last import: ' + formatDate(summary.last_imported_at)
                : 'No local imports yet';
            setText('logisticsLastImported', lastImported);
        }

        function renderLogisticsRows(rows) {
            const tbody = document.getElementById('logisticsTableBody');
            if (!tbody) {
                return;
            }

            if (!Array.isArray(rows) || rows.length === 0) {
                tbody.innerHTML = '<tr><td colspan="8" class="text-center text-muted">No logistics activity found.</td></tr>';
                return;
            }

            tbody.innerHTML = rows.map(function(item) {
                const sourceLabel = logisticsLabels[item.source_system] || item.source_system || 'Unknown';
                const typeLabel = transactionLabels[item.transaction_type] || item.transaction_type || 'Import';
                return '<tr>' +
                    '<td>' + escapeHtml(formatDate(item.transaction_date)) + '</td>' +
                    '<td>' + escapeHtml(sourceLabel) + '</td>' +
                    '<td>' + escapeHtml(typeLabel) + '</td>' +
                    '<td>' + escapeHtml(item.external_reference || 'N/A') + '</td>' +
                    '<td>' + escapeHtml(item.department_name || 'Unassigned') + '</td>' +
                    '<td>' + escapeHtml(item.description || 'No description') + '</td>' +
                    '<td><strong>' + escapeHtml(formatMoney(item.amount)) + '</strong></td>' +
                    '<td>' + getLogisticsStatusBadge(item.status) + '</td>' +
                '</tr>';
            }).join('');
        }

        window.loadLogisticsActivity = async function(buttonEl) {
            window.showTableLoading('logisticsTableBody', 'Loading logistics activity...');

            const button = buttonEl && buttonEl.closest ? buttonEl.closest('button') : null;
            const originalText = button ? button.innerHTML : '';
            if (button) {
                button.disabled = true;
                button.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Loading...';
            }

            try {
                const response = await fetch('../api/logistics/activity.php?limit=25', {
                    credentials: 'include'
                });
                const result = await response.json();

                if (!response.ok || !result.success) {
                    throw new Error(result.error || 'Unable to load logistics activity');
                }

                renderLogisticsSummary(result.summary || {});
                renderLogisticsRows(result.transactions || []);
            } catch (error) {
                window.showTableError('logisticsTableBody', 'Unable to load logistics activity.');
                window.showAlert('Error loading logistics activity: ' + error.message, 'danger');
            } finally {
                if (button) {
                    button.disabled = false;
                    button.innerHTML = originalText;
                }
            }
        };

        window.runLogisticsImport = async function(integrationName, actionName, buttonEl) {
            const integrationLabels = {
                logistics1: 'Logistics 1',
                logistics2: 'Logistics 2'
            };
            const integrationLabel = integrationLabels[integrationName] || 'Logistics';
            const button = buttonEl && buttonEl.closest ? buttonEl.closest('button') : null;
            const originalText = button ? button.innerHTML : '';

            if (button) {
                button.disabled = true;
                button.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Syncing...';
            }

            try {
                const response = await fetch('../api/integrations.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    credentials: 'include',
                    body: new URLSearchParams({
                        action: 'execute',
                        integration_name: integrationName,
                        action_name: actionName,
                        params: JSON.stringify({ requested_at: Date.now() })
                    })
                });

                const result = await response.json();
                const payload = result.result || {};

                if (!response.ok || !result.success || payload.success === false) {
                    throw new Error(result.error || payload.error || 'Sync failed');
                }

                window.showAlert(payload.message || (integrationLabel + ' sync completed successfully.'), 'success');
                await window.loadLogisticsActivity();
            } catch (error) {
                window.showAlert('Error syncing ' + integrationLabel + ' data: ' + error.message, 'danger');
            } finally {
                if (button) {
                    button.disabled = false;
                    button.innerHTML = originalText;
                }
            }
        };
    });
    </script>

    <script src="../includes/financial_hq_state.js"></script>
    <script>
    (function() {
        document.head.insertAdjacentHTML('beforeend', '<style>#logistics-tab, #logistics, button[data-bs-target="#logistics"] { display:none !important; }</style>');
        let disbMergedRows = [];
        let disbVisibleRows = [];
        let disbAuditLogs = [];

        const originalApplyFiltersFn = typeof applyFilters === 'function' ? applyFilters : null;
        const originalClearFiltersFn = typeof clearFilters === 'function' ? clearFilters : null;
        const originalLoadClaimsFn = typeof window.loadClaims === 'function' ? window.loadClaims : null;
        const originalProcessClaimFn = typeof window.processHR3Claim === 'function' ? window.processHR3Claim : null;
        const originalLoadPayrollFn = typeof window.loadPayroll === 'function' ? window.loadPayroll : null;
        const originalUpdatePayrollApprovalFn = typeof window.updatePayrollApproval === 'function' ? window.updatePayrollApproval : null;
        const originalLoadIncentivesFn = typeof window.loadIncentives === 'function' ? window.loadIncentives : null;
        const originalUpdateIncentiveApprovalFn = typeof window.updateIncentiveApproval === 'function' ? window.updateIncentiveApproval : null;

        function disbEscape(value) {
            return String(value ?? '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }

        function disbFormatMoney(value) {
            return 'PHP ' + Number(value || 0).toLocaleString('en-US', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        }

        function disbMergeRecords(primary, secondary) {
            const map = new Map();
            (secondary || []).forEach(item => map.set(String(item.id), item));
            (primary || []).forEach(item => map.set(String(item.id), item));
            return Array.from(map.values());
        }

        async function disbFetchJsonSafe(url, options) {
            try {
                const response = await fetch(url, options);
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }
                return await response.json();
            } catch (error) {
                return [];
            }
        }

        function disbNormalizeRow(row) {
            return {
                ...row,
                disbursement_date: row.disbursement_date || row.payment_date || '',
                source_module: row.source_module || (
                    String(row.reference_number || '').startsWith('HR3-CLAIM-') ? 'claims' :
                    String(row.reference_number || '').startsWith('PAYROLL-') ? 'payroll' :
                    String(row.reference_number || '').startsWith('INCENTIVE-') ? 'incentives' :
                    'manual'
                )
            };
        }

        async function logDisbursementAudit(actionType, recordId, newValues, oldValues) {
            try {
                await fetch('../api/audit.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    credentials: 'include',
                    body: new URLSearchParams({
                        action: 'log',
                        table_name: 'disbursements',
                        record_id: recordId || '',
                        action_type: actionType,
                        old_values: JSON.stringify(oldValues || {}),
                        new_values: JSON.stringify(newValues || {})
                    })
                });
            } catch (error) {
                // Ignore audit write failures in the UI layer.
            }
        }

        function getDisbursementSource(row) {
            const ref = String(row.reference_number || '').toUpperCase();
            const moduleSource = String(row.source_module || '').toLowerCase();
            if (ref.startsWith('HR3-CLAIM-') || moduleSource === 'claims') {
                return { label: 'Claims', badge: 'bg-info' };
            }
            if (ref.startsWith('PAYROLL-') || moduleSource === 'payroll') {
                return { label: 'Payroll', badge: 'bg-success' };
            }
            if (ref.startsWith('INCENTIVE-') || moduleSource === 'incentives') {
                return { label: 'Incentives', badge: 'bg-warning text-dark' };
            }
            if (moduleSource === 'ap') {
                return { label: 'Accounts Payable', badge: 'bg-primary' };
            }
            return { label: 'Finance', badge: 'bg-secondary' };
        }

        function getDisbursementStatusBadge(status) {
            const key = String(status || 'pending').toLowerCase();
            const badgeMap = {
                pending: 'bg-warning text-dark',
                processed: 'bg-success',
                approved: 'bg-success',
                rejected: 'bg-danger',
                paid: 'bg-success'
            };
            const label = key.replace(/_/g, ' ').replace(/\b\w/g, char => char.toUpperCase());
            return `<span class="badge ${badgeMap[key] || 'bg-secondary'}">${disbEscape(label)}</span>`;
        }

        function getDisbursementMethodBadge(method) {
            const label = String(method || 'unknown').replace(/_/g, ' ').toUpperCase();
            return `<span class="badge bg-light text-dark border">${disbEscape(label)}</span>`;
        }

        function renderDisbursementsTable(disbursements) {
            const tbody = document.getElementById('disbursementsTableBody');
            if (!tbody) {
                return;
            }

            const refSearch = (document.getElementById('filterReferenceSearch')?.value || '').trim().toLowerCase();
            const payeeName = (document.getElementById('filterPayeeName')?.value || '').trim().toLowerCase();
            const department = document.getElementById('filterDepartment')?.value || '';
            const filteredRows = (disbursements || []).filter(row => {
                const refValue = String(row.disbursement_number || row.reference_number || row.id || '').toLowerCase();
                const payeeValue = String(row.payee || '').toLowerCase();
                const source = getDisbursementSource(row).label;
                if (refSearch && !refValue.includes(refSearch)) {
                    return false;
                }
                if (payeeName && !payeeValue.includes(payeeName)) {
                    return false;
                }
                if (department && source !== department) {
                    return false;
                }
                return true;
            });

            if (!filteredRows.length) {
                disbVisibleRows = [];
                tbody.innerHTML = '<tr><td colspan="9" class="text-center text-muted">No matching disbursements found.</td></tr>';
                return;
            }

            disbVisibleRows = filteredRows.slice();
            tbody.innerHTML = filteredRows.map(row => {
                const source = getDisbursementSource(row);
                const canDelete = !(row.source === 'seed');
                const checkbox = canDelete
                    ? `<input type="checkbox" class="disbursement-checkbox" value="${row.id}" onchange="toggleSelection(this)">`
                    : '<input type="checkbox" disabled title="Seed fallback records are retained for continuity">';
                const actions = `
                    <button class="btn btn-sm btn-outline-primary me-1" onclick="viewDisbursement('${row.id}')"><i class="fas fa-eye"></i></button>
                    ${canDelete ? `<button class="btn btn-sm btn-outline-danger" onclick="deleteDisbursement('${row.id}')"><i class="fas fa-trash"></i></button>` : ''}
                `;

                return `
                    <tr>
                        <td>${checkbox}</td>
                        <td>${disbEscape(row.disbursement_number || row.reference_number || row.id)}</td>
                        <td>${disbEscape(row.payee || 'N/A')}</td>
                        <td>${getDisbursementMethodBadge(row.payment_method)}</td>
                        <td>${row.disbursement_date ? new Date(row.disbursement_date).toLocaleDateString() : 'N/A'}</td>
                        <td><span class="amount-cell">${disbFormatMoney(row.amount)}</span></td>
                        <td>${getDisbursementStatusBadge(row.status)}</td>
                        <td><span class="badge ${source.badge}">${disbEscape(source.label)}</span></td>
                        <td>${actions}</td>
                    </tr>
                `;
            }).join('');
        }
        window.renderDisbursementsTable = renderDisbursementsTable;

        async function fetchDisbursementRows() {
            const params = new URLSearchParams(window.currentFilters || {});
            const apiRows = await disbFetchJsonSafe(`../api/disbursements.php?${params.toString()}`, {
                credentials: 'include'
            });
            const normalizedApiRows = Array.isArray(apiRows) ? apiRows.map(disbNormalizeRow) : [];
            const fallbackRows = (window.FinancialHQState?.getDisbursements?.() || []).map(disbNormalizeRow);
            disbMergedRows = disbMergeRecords(normalizedApiRows, fallbackRows);
            return disbMergedRows;
        }

        window.loadDisbursements = async function() {
            const rows = await fetchDisbursementRows();
            renderDisbursementsTable(rows);
            if (typeof populateStatusFilter === 'function') {
                populateStatusFilter(rows);
            }
            window.loadDisbursementReports();
        };

        window.loadDisbursementReports = function() {
            const rows = disbMergedRows.length ? disbMergedRows : (window.FinancialHQState?.getDisbursements?.() || []);
            const totalCount = rows.length;
            const totalAmount = rows.reduce((sum, row) => sum + Number(row.amount || 0), 0);
            const pendingCount = rows.filter(row => String(row.status || '').toLowerCase() === 'pending').length;

            const countEl = document.getElementById('totalDisbursementsCount');
            const totalEl = document.getElementById('totalDisbursementsAmount');
            const pendingEl = document.getElementById('pendingDisbursementsCount');
            if (countEl) countEl.textContent = totalCount.toLocaleString();
            if (totalEl) totalEl.textContent = disbFormatMoney(totalAmount);
            if (pendingEl) pendingEl.textContent = pendingCount.toLocaleString();

            const monthly = {};
            rows.forEach(row => {
                const month = row.disbursement_date
                    ? new Date(row.disbursement_date).toLocaleDateString('en-US', { year: 'numeric', month: 'short' })
                    : 'Unknown';
                monthly[month] = (monthly[month] || 0) + Number(row.amount || 0);
            });

            const canvas = document.getElementById('cashFlowChart');
            if (canvas && window.Chart) {
                if (Chart.getChart(canvas)) {
                    Chart.getChart(canvas).destroy();
                }
                new Chart(canvas, {
                    type: 'line',
                    data: {
                        labels: Object.keys(monthly),
                        datasets: [{
                            label: 'Disbursement Outflows',
                            data: Object.values(monthly),
                            borderColor: '#1e2936',
                            backgroundColor: 'rgba(30, 41, 54, 0.12)',
                            tension: 0.25,
                            fill: true
                        }]
                    },
                    options: {
                        responsive: true,
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: {
                                    callback: value => 'PHP ' + Number(value).toLocaleString()
                                }
                            }
                        }
                    }
                });
            }
        };

        window.applyFilters = async function() {
            if (originalApplyFiltersFn) {
                await originalApplyFiltersFn();
            } else {
                window.currentFilters = {
                    status: document.getElementById('filterStatus')?.value || '',
                    date_from: document.getElementById('filterDateFrom')?.value || '',
                    date_to: document.getElementById('filterDateTo')?.value || '',
                    reference_search: document.getElementById('filterReferenceSearch')?.value || '',
                    payee_name: document.getElementById('filterPayeeName')?.value || '',
                    department: document.getElementById('filterDepartment')?.value || ''
                };
                Object.keys(window.currentFilters).forEach(key => {
                    if (!window.currentFilters[key]) {
                        delete window.currentFilters[key];
                    }
                });
                await window.loadDisbursements();
            }
            await logDisbursementAudit('filtered', '', {
                detail: 'Applied disbursement table filters',
                filters: window.currentFilters || {}
            }, null);
        };

        window.clearFilters = function() {
            if (originalClearFiltersFn) {
                originalClearFiltersFn();
            } else {
                ['filterStatus', 'filterDateFrom', 'filterDateTo', 'filterReferenceSearch', 'filterPayeeName', 'filterDepartment'].forEach(id => {
                    const field = document.getElementById(id);
                    if (field) {
                        field.value = '';
                    }
                });
                window.currentFilters = {};
                window.loadDisbursements();
            }
            logDisbursementAudit('filtered', '', {
                detail: 'Cleared disbursement table filters'
            }, null);
        };

        function showLocalDisbursementModal(row) {
            const source = getDisbursementSource(row);
            const wrapper = document.createElement('div');
            wrapper.className = 'modal fade';
            wrapper.innerHTML = `
                <div class="modal-dialog modal-lg">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">Disbursement Details</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <div class="row">
                                <div class="col-md-6 mb-3"><strong>Reference</strong><br>${disbEscape(row.disbursement_number || row.reference_number || row.id)}</div>
                                <div class="col-md-6 mb-3"><strong>Source</strong><br><span class="badge ${source.badge}">${disbEscape(source.label)}</span></div>
                                <div class="col-md-6 mb-3"><strong>Payee</strong><br>${disbEscape(row.payee || 'N/A')}</div>
                                <div class="col-md-6 mb-3"><strong>Payment Method</strong><br>${disbEscape(String(row.payment_method || '').replace(/_/g, ' ').toUpperCase())}</div>
                                <div class="col-md-6 mb-3"><strong>Amount</strong><br>${disbFormatMoney(row.amount)}</div>
                                <div class="col-md-6 mb-3"><strong>Status</strong><br>${getDisbursementStatusBadge(row.status)}</div>
                                <div class="col-md-12"><strong>Notes</strong><br>${disbEscape(row.notes || row.purpose || 'No additional notes')}</div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        </div>
                    </div>
                </div>
            `;
            document.body.appendChild(wrapper);
            const modal = new bootstrap.Modal(wrapper);
            modal.show();
            wrapper.addEventListener('hidden.bs.modal', function() {
                wrapper.remove();
            });
        }

        window.viewDisbursement = async function(id) {
            const seedRow = disbMergedRows.find(item => String(item.id) === String(id) && item.source === 'seed');
            if (seedRow) {
                showLocalDisbursementModal(seedRow);
                await logDisbursementAudit('viewed', id, {
                    disbursement_number: seedRow.disbursement_number,
                    reference_number: seedRow.reference_number,
                    detail: 'Viewed local fallback disbursement'
                }, null);
                window.loadAuditTrail();
                return;
            }

            const apiRow = await disbFetchJsonSafe(`../api/disbursements.php?id=${encodeURIComponent(id)}`, {
                credentials: 'include'
            });
            if (apiRow && !Array.isArray(apiRow) && !apiRow.error) {
                showLocalDisbursementModal(disbNormalizeRow(apiRow));
                await logDisbursementAudit('viewed', id, {
                    disbursement_number: apiRow.disbursement_number || id,
                    reference_number: apiRow.reference_number || '',
                    detail: 'Viewed disbursement record'
                }, null);
                window.loadAuditTrail();
                return;
            }

            showAlert('Unable to load disbursement details.', 'danger');
        };

        window.deleteDisbursement = async function(id) {
            const seedRow = disbMergedRows.find(item => String(item.id) === String(id) && item.source === 'seed');
            if (seedRow) {
                showAlert('Fallback disbursement records are retained for continuity and cannot be deleted.', 'warning');
                return;
            }

            showConfirmDialog(
                'Delete Disbursement',
                'Are you sure you want to delete this disbursement?',
                async () => {
                    try {
                        const response = await fetch(`../api/disbursements.php?id=${encodeURIComponent(id)}`, {
                            method: 'DELETE',
                            credentials: 'include'
                        });
                        const result = await response.json();
                        if (!response.ok || result.error) {
                            throw new Error(result.error || 'Delete failed');
                        }
                        await logDisbursementAudit('deleted', id, null, { detail: 'Deleted disbursement record' });
                        showAlert('Disbursement deleted successfully.', 'success');
                        await window.loadDisbursements();
                        window.loadAuditTrail();
                    } catch (error) {
                        showAlert('Error deleting disbursement: ' + error.message, 'danger');
                    }
                }
            );
        };

        window.saveDisbursement = async function() {
            const form = document.getElementById('disbursementForm');
            const formData = new FormData(form);
            const data = Object.fromEntries(formData);
            const vendorSelect = document.getElementById('vendorId');
            const payee = vendorSelect?.options?.[vendorSelect.selectedIndex]?.text || '';

            const payload = {
                payee: payee,
                disbursement_date: data.disbursement_date,
                amount: Number(data.amount || 0),
                payment_method: data.payment_method,
                reference_number: data.reference_number || '',
                purpose: data.notes || '',
                notes: data.notes || '',
                bill_id: data.bill_id || ''
            };

            if (!payload.payee || !payload.disbursement_date || !payload.amount || !payload.payment_method) {
                showAlert('Please complete the disbursement form.', 'warning');
                return;
            }

            try {
                const isUpdate = Boolean(data.disbursement_id);
                const response = await fetch(isUpdate
                    ? `../api/disbursements.php?id=${encodeURIComponent(data.disbursement_id)}`
                    : '../api/disbursements.php', {
                    method: isUpdate ? 'PUT' : 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    credentials: 'include',
                    body: JSON.stringify({
                        ...payload,
                        disbursement_id: data.disbursement_id || ''
                    })
                });
                const result = await response.json();
                if (!response.ok || result.error) {
                    throw new Error(result.error || 'Save failed');
                }
                await logDisbursementAudit(isUpdate ? 'updated' : 'created', result.id || data.disbursement_id || '', payload, null);
                bootstrap.Modal.getInstance(document.getElementById('disbursementModal'))?.hide();
                showAlert(result.message || 'Disbursement saved successfully.', 'success');
                await window.loadDisbursements();
                window.loadAuditTrail();
            } catch (error) {
                const fallback = window.FinancialHQState?.addDisbursement?.({
                    ...payload,
                    status: 'pending',
                    source_module: 'manual',
                    source: 'seed'
                });
                await logDisbursementAudit('created', '', payload, null);
                bootstrap.Modal.getInstance(document.getElementById('disbursementModal'))?.hide();
                showAlert('Saved to fallback finance queue while the API is unavailable.', 'warning');
                await window.loadDisbursements();
                window.loadAuditTrail();
            }
        };

        function renderClaimsFallback(rows) {
            const tbody = document.getElementById('claimsTableBody');
            if (!tbody) {
                return;
            }

            const activeRows = rows.filter(item => !['paid', 'processed', 'rejected', 'cancelled'].includes(String(item.status || '').toLowerCase()));
            if (!activeRows.length) {
                tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted">All fallback claims are already processed.</td></tr>';
                window.updateTabBadge?.('claims-tab', 0);
                return;
            }

            tbody.innerHTML = activeRows.map(claim => `
                <tr>
                    <td><strong>${disbEscape(claim.claim_id || claim.id)}</strong></td>
                    <td>${disbEscape(claim.employee_name || 'N/A')}</td>
                    <td><span class="badge bg-success">Approved</span></td>
                    <td><strong>${disbFormatMoney(claim.amount || 0)}</strong></td>
                    <td>${claim.submitted_at ? new Date(claim.submitted_at).toLocaleDateString() : 'N/A'}</td>
                    <td>${disbEscape(claim.claim_type || 'HR Claim')}</td>
                    <td><button class="btn btn-success btn-sm" onclick='processHR3Claim(${JSON.stringify(claim.claim_id || claim.id)}, ${JSON.stringify(claim.employee_name || '')}, ${Number(claim.amount || 0)}, ${JSON.stringify(claim.claim_type || 'HR Claim')}, "PHP")'><i class="fas fa-money-bill-wave me-1"></i>Process</button></td>
                </tr>
            `).join('');
            window.updateTabBadge?.('claims-tab', activeRows.length);
        }

        window.loadClaims = async function(buttonEl) {
            const button = buttonEl && buttonEl.closest ? buttonEl.closest('button') : document.querySelector('button[onclick="loadClaims()"]');
            const originalText = button ? button.innerHTML : '';
            if (button) {
                button.disabled = true;
                button.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Loading Claims...';
            }

            try {
                const response = await fetch('../api/integrations.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    credentials: 'include',
                    body: new URLSearchParams({
                        action: 'execute',
                        integration_name: 'hr3',
                        action_name: 'getApprovedClaims'
                    })
                });
                const result = await response.json();
                const payload = result.result || result.data || result;
                const rows = Array.isArray(payload) ? payload : (Array.isArray(payload?.claims) ? payload.claims : []);

                if (response.ok && result.success && rows.length) {
                    if (typeof window.displayHR3Claims === 'function') {
                        window.displayHR3Claims(rows);
                    }
                    logDisbursementAudit('viewed', '', { detail: 'Loaded HR3 claims queue from live integration' }, null);
                } else {
                    renderClaimsFallback(window.FinancialHQState?.getHrClaims?.() || []);
                    showAlert('Using fallback claims queue while the HR3 API is unavailable.', 'warning');
                    logDisbursementAudit('viewed', '', { detail: 'Loaded fallback HR3 claims queue' }, null);
                }
            } catch (error) {
                renderClaimsFallback(window.FinancialHQState?.getHrClaims?.() || []);
                showAlert('Using fallback claims queue while the HR3 API is unavailable.', 'warning');
                logDisbursementAudit('viewed', '', { detail: 'Loaded fallback HR3 claims queue' }, null);
            } finally {
                if (button) {
                    button.disabled = false;
                    button.innerHTML = originalText;
                }
            }
        };

        window.processHR3Claim = async function(claimId, employeeName, amount, description, currency) {
            const seedClaim = (window.FinancialHQState?.getHrClaims?.() || []).find(item => {
                return String(item.id) === String(claimId) || String(item.claim_id) === String(claimId);
            });
            if (!seedClaim || seedClaim.source !== 'seed') {
                if (originalProcessClaimFn) {
                    return originalProcessClaimFn(claimId, employeeName, amount, description, currency);
                }
                return;
            }

            window.FinancialHQState?.updateHrClaimStatus?.(claimId, 'paid');
            const state = window.FinancialHQState?.addDisbursement?.({
                payee: employeeName,
                disbursement_date: new Date().toISOString().slice(0, 10),
                amount: amount,
                payment_method: 'bank_transfer',
                reference_number: `HR3-CLAIM-${claimId}`,
                notes: `Processed HR3 fallback claim for ${employeeName}`,
                status: 'processed',
                source_module: 'claims',
                source: 'seed'
            });
            await logDisbursementAudit('processed_payment', claimId, {
                employee: employeeName,
                amount: amount,
                reference_number: `HR3-CLAIM-${claimId}`
            }, { status: 'approved' });
            showAlert('Claim processed successfully through the fallback queue.', 'success');
            window.loadClaims();
            window.loadDisbursements();
            window.loadAuditTrail();
        };

        function renderPayrollFallback(rows) {
            const tbody = document.getElementById('payrollTableBody');
            if (!tbody) {
                return;
            }

            const activeRows = rows.filter(item => !['approved', 'paid', 'rejected'].includes(String(item.status || '').toLowerCase()));
            if (!activeRows.length) {
                tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted">No payroll batches waiting for finance action.</td></tr>';
                window.updateTabBadge?.('payroll-tab', 0);
                return;
            }

            tbody.innerHTML = activeRows.map(row => `
                <tr data-payroll-id="${disbEscape(row.approval_id || row.id)}" data-period="${disbEscape(row.period_display || row.payroll_period || '')}" data-amount="${Number(row.total_amount || 0)}" data-employees="${Number(row.employee_count || 0)}" data-submitted-by="${disbEscape(row.submitted_by || 'Compensation Team')}">
                    <td><strong>${disbEscape(row.period_display || row.payroll_period || 'N/A')}</strong></td>
                    <td>${disbFormatMoney(row.total_amount || 0)}</td>
                    <td>${Number(row.employee_count || 0).toLocaleString()}</td>
                    <td>${disbEscape(row.submitted_by || 'Compensation Team')}</td>
                    <td>${row.submitted_at ? new Date(row.submitted_at).toLocaleString() : 'N/A'}</td>
                    <td><span class="badge bg-info">${disbEscape(row.status || 'pending')}</span></td>
                    <td>
                        <button class="btn btn-success btn-sm me-2" onclick="updatePayrollApproval(this, '${disbEscape(row.approval_id || row.id)}', 'approve')"><i class="fas fa-check me-1"></i>Approve</button>
                        <button class="btn btn-danger btn-sm" onclick="updatePayrollApproval(this, '${disbEscape(row.approval_id || row.id)}', 'reject')"><i class="fas fa-times me-1"></i>Reject</button>
                    </td>
                </tr>
            `).join('');
            window.updateTabBadge?.('payroll-tab', activeRows.length);
        }

        window.loadPayroll = async function(buttonEl) {
            const button = buttonEl && buttonEl.closest ? buttonEl.closest('button') : document.querySelector('button[onclick*="loadPayroll"]');
            const originalText = button ? button.innerHTML : '';
            if (button) {
                button.disabled = true;
                button.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Loading Payroll...';
            }
            try {
                const response = await fetch('../api/integrations.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    credentials: 'include',
                    body: new URLSearchParams({
                        action: 'execute',
                        integration_name: 'hr4',
                        action_name: 'getPayrollApprovals',
                        params: JSON.stringify({ _ts: Date.now() })
                    })
                });
                const result = await response.json();
                const rows = Array.isArray(result?.result) ? result.result : [];
                if (response.ok && result.success && rows.length) {
                    window.displayHR4Payroll?.(rows);
                    logDisbursementAudit('viewed', '', { detail: 'Loaded HR4 payroll queue from live integration' }, null);
                } else {
                    renderPayrollFallback(window.FinancialHQState?.getHrPayroll?.() || []);
                    showAlert('Using fallback payroll queue while the HR4 API is unavailable.', 'warning');
                    logDisbursementAudit('viewed', '', { detail: 'Loaded fallback HR4 payroll queue' }, null);
                }
            } catch (error) {
                renderPayrollFallback(window.FinancialHQState?.getHrPayroll?.() || []);
                showAlert('Using fallback payroll queue while the HR4 API is unavailable.', 'warning');
                logDisbursementAudit('viewed', '', { detail: 'Loaded fallback HR4 payroll queue' }, null);
            } finally {
                if (button) {
                    button.disabled = false;
                    button.innerHTML = originalText;
                }
            }
        };

        window.updatePayrollApproval = async function(buttonEl, payrollId, action) {
            const seedPayroll = (window.FinancialHQState?.getHrPayroll?.() || []).find(item => {
                return String(item.id) === String(payrollId) || String(item.approval_id) === String(payrollId);
            });
            if (!seedPayroll || seedPayroll.source !== 'seed') {
                if (originalUpdatePayrollApprovalFn) {
                    return originalUpdatePayrollApprovalFn(buttonEl, payrollId, action);
                }
                return;
            }

            window.FinancialHQState?.updateHrPayrollStatus?.(payrollId, action === 'approve' ? 'approved' : 'rejected');
            if (action === 'approve') {
                window.FinancialHQState?.addDisbursement?.({
                    payee: `Payroll ${seedPayroll.period_display || seedPayroll.payroll_period}`,
                    disbursement_date: new Date().toISOString().slice(0, 10),
                    amount: seedPayroll.total_amount,
                    payment_method: 'bank_transfer',
                    reference_number: `PAYROLL-${payrollId}`,
                    notes: `Approved fallback payroll batch for ${seedPayroll.employee_count} employees`,
                    status: 'processed',
                    source_module: 'payroll',
                    source: 'seed'
                });
            }
            await logDisbursementAudit('processed_payment', payrollId, {
                payroll_period: seedPayroll.period_display || seedPayroll.payroll_period,
                amount: seedPayroll.total_amount,
                action: action
            }, { status: seedPayroll.status || 'pending' });
            showAlert(`Payroll batch ${action === 'approve' ? 'approved' : 'rejected'} successfully.`, 'success');
            window.loadPayroll();
            window.loadDisbursements();
            window.loadAuditTrail();
        };

        function renderIncentivesFallback(rows) {
            const tbody = document.getElementById('incentivesTableBody');
            if (!tbody) {
                return;
            }
            const activeRows = rows.filter(item => !['approved', 'paid', 'rejected'].includes(String(item.status || '').toLowerCase()));
            if (!activeRows.length) {
                tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted">No incentive batches waiting for finance action.</td></tr>';
                window.updateTabBadge?.('incentives-tab', 0);
                return;
            }

            tbody.innerHTML = activeRows.map(row => `
                <tr data-incentive-id="${disbEscape(row.id)}" data-period="${disbEscape(row.period || '')}" data-amount="${Number(row.amount || 0)}" data-employee-name="${disbEscape(row.employee_name || '')}" data-department="${disbEscape(row.department || '')}" data-position="${disbEscape(row.position || '')}">
                    <td><strong>${disbEscape(row.employee_name || 'N/A')}</strong></td>
                    <td>${disbEscape(row.department || 'N/A')}</td>
                    <td>${disbEscape(row.position || 'N/A')}</td>
                    <td>${disbEscape(row.period || 'N/A')}</td>
                    <td><strong class="text-success">${disbFormatMoney(row.amount || 0)}</strong></td>
                    <td><span class="badge bg-info">${disbEscape(row.status || 'pending')}</span></td>
                    <td>
                        <button class="btn btn-success btn-sm me-2" onclick="updateIncentiveApproval(this, '${disbEscape(row.id)}', 'approve')"><i class="fas fa-check me-1"></i>Approve</button>
                        <button class="btn btn-danger btn-sm" onclick="updateIncentiveApproval(this, '${disbEscape(row.id)}', 'reject')"><i class="fas fa-times me-1"></i>Reject</button>
                    </td>
                </tr>
            `).join('');
            window.updateTabBadge?.('incentives-tab', activeRows.length);
        }

        window.loadIncentives = async function(buttonEl) {
            const button = buttonEl && buttonEl.closest ? buttonEl.closest('button') : document.querySelector('button[onclick*="loadIncentives"]');
            const originalText = button ? button.innerHTML : '';
            if (button) {
                button.disabled = true;
                button.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Loading Incentives...';
            }
            try {
                const response = await fetch('../api/integrations.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    credentials: 'include',
                    body: new URLSearchParams({
                        action: 'execute',
                        integration_name: 'hr4',
                        action_name: 'getIncentiveData',
                        params: JSON.stringify({ _ts: Date.now() })
                    })
                });
                const result = await response.json();
                const rows = Array.isArray(result?.result) ? result.result : [];
                if (response.ok && result.success && rows.length) {
                    window.displayHR4Incentives?.(rows);
                    logDisbursementAudit('viewed', '', { detail: 'Loaded HR4 incentives queue from live integration' }, null);
                } else {
                    renderIncentivesFallback(window.FinancialHQState?.getHrIncentives?.() || []);
                    showAlert('Using fallback incentives queue while the HR4 API is unavailable.', 'warning');
                    logDisbursementAudit('viewed', '', { detail: 'Loaded fallback HR4 incentives queue' }, null);
                }
            } catch (error) {
                renderIncentivesFallback(window.FinancialHQState?.getHrIncentives?.() || []);
                showAlert('Using fallback incentives queue while the HR4 API is unavailable.', 'warning');
                logDisbursementAudit('viewed', '', { detail: 'Loaded fallback HR4 incentives queue' }, null);
            } finally {
                if (button) {
                    button.disabled = false;
                    button.innerHTML = originalText;
                }
            }
        };

        window.updateIncentiveApproval = async function(buttonEl, incentiveId, action) {
            const seedIncentive = (window.FinancialHQState?.getHrIncentives?.() || []).find(item => String(item.id) === String(incentiveId));
            if (!seedIncentive || seedIncentive.source !== 'seed') {
                if (originalUpdateIncentiveApprovalFn) {
                    return originalUpdateIncentiveApprovalFn(buttonEl, incentiveId, action);
                }
                return;
            }

            window.FinancialHQState?.updateHrIncentiveStatus?.(incentiveId, action === 'approve' ? 'approved' : 'rejected');
            if (action === 'approve') {
                window.FinancialHQState?.addDisbursement?.({
                    payee: `Incentive - ${seedIncentive.employee_name}`,
                    disbursement_date: new Date().toISOString().slice(0, 10),
                    amount: seedIncentive.amount,
                    payment_method: 'bank_transfer',
                    reference_number: `INCENTIVE-${incentiveId}`,
                    notes: `Approved fallback incentive payout for ${seedIncentive.employee_name}`,
                    status: 'processed',
                    source_module: 'incentives',
                    source: 'seed'
                });
            }
            await logDisbursementAudit('processed_payment', incentiveId, {
                employee: seedIncentive.employee_name,
                amount: seedIncentive.amount,
                action: action
            }, { status: seedIncentive.status || 'pending' });
            showAlert(`Incentive ${action === 'approve' ? 'approved' : 'rejected'} successfully.`, 'success');
            window.loadIncentives();
            window.loadDisbursements();
            window.loadAuditTrail();
        };

        function renderAuditRows(logs) {
            const tbody = document.getElementById('auditTableBody');
            if (!tbody) {
                return;
            }
            disbAuditLogs = Array.isArray(logs) ? logs : [];

            if (!disbAuditLogs.length) {
                tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted">No audit logs found.</td></tr>';
                window.updateTabBadge?.('audit-tab', 0);
                return;
            }

            const actions = new Set();
            const users = new Set();
            disbAuditLogs.forEach(log => {
                actions.add(String(log.action_label || log.action || '').trim());
                users.add(String(log.full_name || log.username || 'Unknown').trim());
            });

            const actionSelect = document.getElementById('disbFilterAction');
            if (actionSelect) {
                const first = actionSelect.querySelector('option');
                actionSelect.innerHTML = '';
                if (first) {
                    actionSelect.appendChild(first);
                } else {
                    actionSelect.innerHTML = '<option value="">All Actions</option>';
                }
                Array.from(actions).filter(Boolean).sort().forEach(action => {
                    const opt = document.createElement('option');
                    opt.value = action;
                    opt.textContent = action;
                    actionSelect.appendChild(opt);
                });
            }

            const userList = document.getElementById('disbFilterUserList');
            if (userList) {
                userList.innerHTML = '';
                Array.from(users).filter(Boolean).sort().forEach(user => {
                    const opt = document.createElement('option');
                    opt.value = user;
                    userList.appendChild(opt);
                });
            }

            tbody.innerHTML = disbAuditLogs.map(log => {
                const ref = log.disbursement_number
                    || log.record_id
                    || (() => {
                        try {
                            const next = log.new_values ? JSON.parse(log.new_values) : {};
                            return next.disbursement_number || next.reference_number || next.employee || 'N/A';
                        } catch (error) {
                            return 'N/A';
                        }
                    })();
                return `
                    <tr>
                        <td>${disbEscape(log.formatted_date || new Date(log.created_at || Date.now()).toLocaleString())}</td>
                        <td>${disbEscape(log.full_name || log.username || 'Unknown')}</td>
                        <td><span class="badge bg-info">${disbEscape(log.action_label || log.action || 'N/A')}</span></td>
                        <td>${disbEscape(ref)}</td>
                        <td>${disbEscape(log.action_description || '')}</td>
                    </tr>
                `;
            }).join('');
            window.updateTabBadge?.('audit-tab', disbAuditLogs.length);
        }

        function getFilteredAuditRows() {
            const dateFrom = document.getElementById('disbFilterDateFrom')?.value || '';
            const dateTo = document.getElementById('disbFilterDateTo')?.value || '';
            const user = (document.getElementById('disbFilterUser')?.value || '').toLowerCase();
            const action = document.getElementById('disbFilterAction')?.value || '';
            const ref = (document.getElementById('disbFilterRef')?.value || '').toLowerCase();

            return disbAuditLogs.filter(log => {
                const createdAt = String(log.created_at || '');
                const formatted = String(log.formatted_date || '');
                const logUser = String(log.full_name || log.username || '').toLowerCase();
                const logAction = String(log.action_label || log.action || '');
                const logRef = String(log.disbursement_number || log.record_id || log.action_description || '').toLowerCase();

                if (dateFrom && createdAt.slice(0, 10) < dateFrom) return false;
                if (dateTo && createdAt.slice(0, 10) > dateTo) return false;
                if (user && !logUser.includes(user)) return false;
                if (action && logAction !== action) return false;
                if (ref && !logRef.includes(ref) && !formatted.toLowerCase().includes(ref)) return false;
                return true;
            });
        }

        window.applyDisbursementFilters = function() {
            renderAuditRows(getFilteredAuditRows());
        };

        window.clearDisbursementFilters = function() {
            document.getElementById('disbFilterDateFrom').value = '';
            document.getElementById('disbFilterDateTo').value = '';
            document.getElementById('disbFilterUser').value = '';
            document.getElementById('disbFilterAction').value = '';
            document.getElementById('disbFilterRef').value = '';
            renderAuditRows(disbAuditLogs);
        };

        window.exportDisbursementAudit = function() {
            const rows = getFilteredAuditRows();
            if (!rows.length) {
                showAlert('No audit logs available for export.', 'warning');
                return;
            }

            const csv = [
                ['Date/Time', 'User', 'Action', 'Reference', 'Details'].join(','),
                ...rows.map(log => [
                    log.formatted_date || new Date(log.created_at || Date.now()).toLocaleString(),
                    log.full_name || log.username || 'Unknown',
                    log.action_label || log.action || '',
                    log.disbursement_number || log.record_id || '',
                    log.action_description || ''
                ].map(value => `"${String(value ?? '').replace(/"/g, '""')}"`).join(','))
            ].join('\n');

            const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            link.download = `disbursement_audit_${new Date().toISOString().slice(0, 10)}.csv`;
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            URL.revokeObjectURL(url);
            logDisbursementAudit('exported', '', { detail: 'Exported filtered disbursement audit log' }, null);
        };

        window.loadAuditTrail = async function() {
            const logs = await disbFetchJsonSafe('../api/audit.php?scope=disbursements', {
                credentials: 'include'
            });
            renderAuditRows(Array.isArray(logs) ? logs : []);
        };

        window.exportDisbursementReport = function() {
            const rows = disbVisibleRows.length ? disbVisibleRows : (disbMergedRows.length ? disbMergedRows : (window.FinancialHQState?.getDisbursements?.() || []));
            if (!rows.length) {
                showAlert('No disbursement records available for export.', 'warning');
                return;
            }

            const csv = [
                ['Disbursement #', 'Payee', 'Payment Method', 'Date', 'Amount', 'Status', 'Source'].join(','),
                ...rows.map(row => [
                    row.disbursement_number || row.reference_number || row.id,
                    row.payee || 'N/A',
                    row.payment_method || '',
                    row.disbursement_date || '',
                    Number(row.amount || 0).toFixed(2),
                    row.status || '',
                    getDisbursementSource(row).label
                ].map(value => `"${String(value ?? '').replace(/"/g, '""')}"`).join(','))
            ].join('\n');

            const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            link.download = `disbursements_${new Date().toISOString().slice(0, 10)}.csv`;
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            URL.revokeObjectURL(url);
            logDisbursementAudit('exported', '', { detail: 'Exported disbursement report' }, null);
        };

        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('logistics-tab')?.closest('.nav-item')?.remove();
            document.getElementById('logistics')?.remove();

            const payrollTab = document.getElementById('payroll-tab');
            if (payrollTab) {
                payrollTab.childNodes.forEach(node => {
                    if (node.nodeType === Node.TEXT_NODE) {
                        node.textContent = node.textContent.replace(/\(\d+\)/g, '');
                    }
                });
            }

            const auditPanel = document.getElementById('disbFiltersInline');
            if (auditPanel) {
                auditPanel.style.display = 'block';
            }
            document.getElementById('disbFilterToggleBtn')?.remove();

            const reportsHeaderButton = document.querySelector('#reports .btn.btn-outline-secondary');
            if (reportsHeaderButton) {
                reportsHeaderButton.setAttribute('type', 'button');
                reportsHeaderButton.setAttribute('onclick', 'exportDisbursementReport()');
            }

            const auditHeader = document.querySelector('#audit .d-flex.justify-content-between.align-items-center.mb-3');
            if (auditHeader && !document.getElementById('disbAuditExportBtn')) {
                const exportBtn = document.createElement('button');
                exportBtn.className = 'btn btn-outline-secondary';
                exportBtn.id = 'disbAuditExportBtn';
                exportBtn.type = 'button';
                exportBtn.innerHTML = '<i class="fas fa-download me-2"></i>Export Audit';
                exportBtn.addEventListener('click', window.exportDisbursementAudit);
                auditHeader.appendChild(exportBtn);
            }

            document.getElementById('disbFilterDateFrom')?.addEventListener('change', window.applyDisbursementFilters);
            document.getElementById('disbFilterDateTo')?.addEventListener('change', window.applyDisbursementFilters);
            document.getElementById('disbFilterUser')?.addEventListener('input', window.applyDisbursementFilters);
            document.getElementById('disbFilterAction')?.addEventListener('change', window.applyDisbursementFilters);
            document.getElementById('disbFilterRef')?.addEventListener('input', window.applyDisbursementFilters);
        });
    })();
    </script>

    <!-- Privacy Mode - Hide amounts with asterisks + Eye button -->
    <script src="../includes/privacy_mode.js?v=14"></script>

    <!-- Inactivity Timeout - Blur screen + Auto logout -->
    <script src="../includes/inactivity_timeout.js?v=3"></script>
<script src="../includes/navbar_datetime.js"></script>
<script src="../includes/tab_persistence.js?v=1"></script>
</body>
</html>
    <script src="../includes/inactivity_timeout.js?v=3"></script>
<script src="../includes/navbar_datetime.js"></script>



