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

                window.showAlert(payload.message || 'Logistics sync completed successfully.', 'success');
                await window.loadLogisticsActivity();
            } catch (error) {
                window.showAlert('Error syncing logistics data: ' + error.message, 'danger');
            } finally {
                if (button) {
                    button.disabled = false;
                    button.innerHTML = originalText;
                }
            }
        };
    });
    </script>

    <!-- Privacy Mode - Hide amounts with asterisks + Eye button -->
    <script src="../includes/privacy_mode.js?v=12"></script>

    <!-- Inactivity Timeout - Blur screen + Auto logout -->
    <script src="../includes/inactivity_timeout.js?v=3"></script>
<script src="../includes/navbar_datetime.js"></script>
<script src="../includes/tab_persistence.js?v=1"></script>
</body>
</html>
    <script src="../includes/inactivity_timeout.js?v=3"></script>
<script src="../includes/navbar_datetime.js"></script>

