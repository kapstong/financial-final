<?php
require_once '../includes/auth.php';
require_once '../includes/database.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user'])) {
    header('Location: ../index.php');
    exit;
}

// Initialize database connection
$db = Database::getInstance();

// Fetch summary data
try {
    // Total customers
    $totalCustomers = $db->query("SELECT COUNT(*) as count FROM customers WHERE status = 'active'")->fetch()['count'];

    // Outstanding receivables
    $outstandingReceivables = $db->query("SELECT COALESCE(SUM(balance), 0) as total FROM invoices WHERE status IN ('sent', 'overdue')")->fetch()['total'];

    // Overdue amount
    $overdueAmount = $db->query("SELECT COALESCE(SUM(balance), 0) as total FROM invoices WHERE status = 'overdue' AND due_date < CURDATE()")->fetch()['total'];

    // Collections this month
    $collectionsThisMonth = $db->query("SELECT COALESCE(SUM(amount), 0) as total FROM payments_received WHERE YEAR(payment_date) = YEAR(CURDATE()) AND MONTH(payment_date) = MONTH(CURDATE())")->fetch()['total'];

} catch (Exception $e) {
    $totalCustomers = 0;
    $outstandingReceivables = 0;
    $overdueAmount = 0;
    $collectionsThisMonth = 0;
}

// Load customer data for the page
$customers = [];
try {
    $customers = $db->select(
        "SELECT id, customer_code, company_name, contact_person, email, phone, credit_limit, status
         FROM customers ORDER BY company_name ASC"
    );
} catch (Exception $e) {
    $customers = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Financial Management System - Accounts Receivable</title>
    <link rel="icon" type="image/png" href="../logo2.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
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
        .sidebar.sidebar-collapsed .nav-item i[data-bs-toggle="collapse"] {
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
            margin-left: 120px;
            padding: 20px;
            transition: margin-left 0.3s ease;
            position: relative;
            z-index: 1;
        }
        .sidebar .navbar-brand {
            color: white !important;
            font-weight: bold;
        }
        .sidebar .navbar-brand img {
            height: 50px;
            width: auto;
            max-width: 100%;
            transition: height 0.3s ease;
        }
        .sidebar.sidebar-collapsed .navbar-brand img {
            height: 80px;
        }
        .sidebar-toggle {
            position: fixed;
            left: 10px;
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
        .hover-link:hover {
            color: #007bff !important;
            transition: color 0.2s ease;
        }
        /* Enhanced UI Styles */
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

        .nav-tabs .nav-link i {
            margin-right: 0.5rem;
            font-size: 0.9em;
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

        .card-header h5 {
            color: #1e2936;
            font-weight: 700;
            margin: 0;
            font-size: 1.25rem;
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

        .table tbody tr:hover {
            background-color: rgba(30, 41, 54, 0.02);
            transform: scale(1.01);
        }

        .table tbody td {
            padding: 1rem;
            vertical-align: middle;
            color: #495057;
        }

        .badge {
            font-size: 0.75rem;
            padding: 0.375rem 0.75rem;
            border-radius: 20px;
            font-weight: 600;
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

        .btn-warning {
            background: linear-gradient(135deg, #ffc107 0%, #fd7e14 100%);
            color: #212529;
        }

        .btn-outline-primary {
            border: 2px solid #1e2936;
            color: #1e2936;
        }

        .btn-outline-primary:hover {
            background: linear-gradient(135deg, #1e2936 0%, #2c3e50 100%);
            color: white;
        }

        .btn-outline-danger {
            border: 2px solid #dc3545;
            color: #dc3545;
        }

        .btn-outline-danger:hover {
            background: linear-gradient(135deg, #dc3545 0%, #fd7e14 100%);
            color: white;
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

        .modal-title {
            color: #1e2936;
            font-weight: 700;
            font-size: 1.25rem;
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

        /* Reports Cards Enhancement */
        .reports-card {
            background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
            border-radius: 12px;
            padding: 2rem;
            text-align: center;
            transition: all 0.3s ease;
            border: 1px solid #e9ecef;
            position: relative;
            overflow: hidden;
        }

        .reports-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #1e2936, #2c3e50);
        }

        .reports-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 35px rgba(0,0,0,0.1);
        }

        .reports-card h3 {
            color: #1e2936;
            font-weight: 800;
            font-size: 2rem;
            margin: 0.5rem 0;
        }

        .reports-card h6 {
            color: #6c757d;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-size: 0.8rem;
        }

        /* Loading Animation */
        .loading {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(30, 41, 54, 0.3);
            border-radius: 50%;
            border-top-color: #1e2936;
            animation: spin 1s ease-in-out infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* Enhanced Responsive Design */
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
                transition: transform 0.3s ease;
            }
            .sidebar.show {
                transform: translateX(0);
            }
            .content {
                margin-left: 0;
                padding: 20px;
            }
            .toggle-btn {
                display: block;
            }

            .nav-tabs {
                flex-direction: column;
                padding: 0.25rem;
            }

            .nav-tabs .nav-link {
                margin-right: 0;
                margin-bottom: 0.25rem;
                text-align: center;
            }

            .card-body {
                padding: 1rem;
            }

            .table-responsive {
                font-size: 0.875rem;
            }

            .modal-dialog {
                margin: 0.5rem;
            }

            .reports-card {
                margin-bottom: 1rem;
            }
        }

        /* Invoice Items Styling */
        .invoice-item {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
            border: 1px solid #e9ecef;
            transition: all 0.3s ease;
        }

        .invoice-item:hover {
            background: white;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }

        /* Status Indicators */
        .status-indicator {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .status-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            display: inline-block;
        }

        .status-paid .status-dot { background-color: #28a745; }
        .status-overdue .status-dot { background-color: #dc3545; }
        .status-sent .status-dot { background-color: #ffc107; }
        .status-draft .status-dot { background-color: #6c757d; }

        /* Enhanced Footer */
        .footer-enhanced {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border-top: 3px solid #1e2936;
            box-shadow: 0 -4px 20px rgba(0,0,0,0.08);
        }
    </style>
</head>
<body>
    <?php include '../includes/superadmin_navigation.php'; ?>

    <div class="content">
        <!-- Top Navbar -->
        <?php include '../includes/global_navbar.php'; ?>

        <!-- Overview Cards -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <i class="fas fa-users fa-2x text-primary mb-2"></i>
                        <h5 class="card-title">Total Customers</h5>
                        <p class="card-text display-6"><?php echo number_format($totalCustomers); ?></p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <i class="fas fa-file-invoice-dollar fa-2x text-warning mb-2"></i>
                        <h5 class="card-title">Outstanding Receivables</h5>
                        <p class="card-text display-6">₱<?php echo number_format($outstandingReceivables, 2); ?></p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <i class="fas fa-clock fa-2x text-danger mb-2"></i>
                        <h5 class="card-title">Overdue</h5>
                        <p class="card-text display-6">₱<?php echo number_format($overdueAmount, 2); ?></p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <i class="fas fa-money-bill-wave fa-2x text-success mb-2"></i>
                        <h5 class="card-title">Collections This Month</h5>
                        <p class="card-text display-6">₱<?php echo number_format($collectionsThisMonth, 2); ?></p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Navigation Tabs -->
        <ul class="nav nav-tabs mb-4" id="arTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="customers-tab" data-bs-toggle="tab" data-bs-target="#customers" type="button" role="tab">
                    <i class="fas fa-users me-2"></i>Customer Records
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="invoices-tab" data-bs-toggle="tab" data-bs-target="#invoices" type="button" role="tab">
                    <i class="fas fa-file-invoice me-2"></i>Invoices
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="aging-tab" data-bs-toggle="tab" data-bs-target="#aging" type="button" role="tab">
                    <i class="fas fa-clock me-2"></i>Aging Report
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="collections-tab" data-bs-toggle="tab" data-bs-target="#collections" type="button" role="tab">
                    <i class="fas fa-money-bill-wave me-2"></i>Collections
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="adjustments-tab" data-bs-toggle="tab" data-bs-target="#adjustments" type="button" role="tab">
                    <i class="fas fa-balance-scale me-2"></i>Adjustments
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="reports-tab" data-bs-toggle="tab" data-bs-target="#reports" type="button" role="tab">
                    <i class="fas fa-chart-bar me-2"></i>Reports
                </button>
            </li>
        </ul>

        <!-- Tab Content -->
        <div class="tab-content" id="arTabContent">
            <!-- Customer Records Tab -->
            <div class="tab-pane fade show active" id="customers" role="tabpanel">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">Customer Records</h5>
                        <?php
                        $allowedRoles = ['core1', 'core2', 'customer_admin'];
                        $userRole = $_SESSION['user']['role_name'] ?? ($_SESSION['user']['role'] ?? null);
                        $userDept = $_SESSION['user']['department'] ?? null;
                        if (in_array($userRole, $allowedRoles) || in_array($userDept, ['core1', 'core2'])): ?>
                            <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addCustomerModal">
                                <i class="fas fa-plus me-1"></i>Add Customer
                            </button>
                        <?php endif; ?>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped" id="customersTable">
                                <thead>
                                    <tr>
                                        <th>Customer ID</th>
                                        <th>Company Name</th>
                                        <th>Contact Person</th>
                                        <th>Email</th>
                                        <th>Phone</th>
                                        <th>Credit Limit</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <!-- Customer data will be loaded here -->
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Invoices Tab -->
            <div class="tab-pane fade" id="invoices" role="tabpanel">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">Invoices</h5>
                        <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addInvoiceModal">
                            <i class="fas fa-plus me-1"></i>Create Invoice
                        </button>
                    </div>
                    <div class="card-body">
                        <div class="row mb-3">
                            <div class="col-md-3">
                                <select class="form-select" id="invoiceStatusFilter">
                                    <option value="">All Status</option>
                                    <option value="draft">Draft</option>
                                    <option value="sent">Sent</option>
                                    <option value="paid">Paid</option>
                                    <option value="overdue">Overdue</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <input type="date" class="form-control" id="invoiceDateFrom">
                            </div>
                            <div class="col-md-3">
                                <input type="date" class="form-control" id="invoiceDateTo">
                            </div>
                            <div class="col-md-3">
                                <button class="btn btn-secondary" onclick="filterInvoices()">Filter</button>
                            </div>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-striped" id="invoicesTable">
                                <thead>
                                    <tr>
                                        <th>Invoice #</th>
                                        <th>Customer</th>
                                        <th>Date</th>
                                        <th>Due Date</th>
                                        <th>Amount</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <!-- Invoice data will be loaded here -->
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Aging Report Tab -->
            <div class="tab-pane fade" id="aging" role="tabpanel">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Aging of Accounts Receivable</h5>
                    </div>
                    <div class="card-body">
                        <div class="row mb-3">
                            <div class="col-md-3">
                                <select class="form-select" id="agingPeriod">
                                    <option value="30">30 Days</option>
                                    <option value="60">60 Days</option>
                                    <option value="90">90 Days</option>
                                    <option value="120">120+ Days</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <button class="btn btn-primary" onclick="generateAgingReport()">Generate Report</button>
                            </div>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-striped" id="agingTable">
                                <thead>
                                    <tr>
                                        <th>Customer</th>
                                        <th>Current</th>
                                        <th>1-30 Days</th>
                                        <th>31-60 Days</th>
                                        <th>61-90 Days</th>
                                        <th>90+ Days</th>
                                        <th>Total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <!-- Aging data will be loaded here -->
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Collections Tab -->
            <div class="tab-pane fade" id="collections" role="tabpanel">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">Collections</h5>
                        <button class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#addPaymentModal">
                            <i class="fas fa-plus me-1"></i>Record Payment
                        </button>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped" id="collectionsTable">
                                <thead>
                                    <tr>
                                        <th>Payment ID</th>
                                        <th>Customer</th>
                                        <th>Invoice #</th>
                                        <th>Payment Date</th>
                                        <th>Amount</th>
                                        <th>Method</th>
                                        <th>Reference</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <!-- Payment data will be loaded here -->
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Adjustments Tab -->
            <div class="tab-pane fade" id="adjustments" role="tabpanel">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">Adjustments & Credit Memos</h5>
                        <button class="btn btn-warning btn-sm" data-bs-toggle="modal" data-bs-target="#addAdjustmentModal">
                            <i class="fas fa-plus me-1"></i>Add Adjustment
                        </button>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped" id="adjustmentsTable">
                                <thead>
                                    <tr>
                                        <th>Adjustment ID</th>
                                        <th>Type</th>
                                        <th>Customer</th>
                                        <th>Invoice #</th>
                                        <th>Amount</th>
                                        <th>Reason</th>
                                        <th>Date</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <!-- Adjustment data will be loaded here -->
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Reports Tab -->
            <div class="tab-pane fade" id="reports" role="tabpanel">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-chart-bar me-2"></i>Reports & Analytics</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-4">
                                <div class="reports-card">
                                    <div class="card-body text-center">
                                        <i class="fas fa-peso-sign fa-2x mb-3 text-muted"></i>
                                        <h6>Total Receivables</h6>
                                        <h3>₱<?php echo number_format($outstandingReceivables, 2); ?></h3>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="reports-card">
                                    <div class="card-body text-center">
                                        <i class="fas fa-exclamation-triangle fa-2x mb-3 text-warning"></i>
                                        <h6>Overdue Amount</h6>
                                        <h3>₱<?php echo number_format($overdueAmount, 2); ?></h3>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="reports-card">
                                    <div class="card-body text-center">
                                        <i class="fas fa-clock fa-2x mb-3 text-info"></i>
                                        <h6>Collections This Month</h6>
                                        <h3>₱<?php echo number_format($collectionsThisMonth, 2); ?></h3>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="mt-4 text-center">
                            <button class="btn btn-primary me-3" onclick="exportReport('receivables')">
                                <i class="fas fa-download me-2"></i>Export Receivables Report
                            </button>
                            <button class="btn btn-secondary me-3" onclick="exportReport('collections')">
                                <i class="fas fa-download me-2"></i>Export Collections Report
                            </button>
                            <button class="btn btn-info" onclick="exportReport('aging')">
                                <i class="fas fa-download me-2"></i>Export Aging Report
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer -->

    <!-- Add Customer Modal -->
    <div class="modal fade" id="addCustomerModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add New Customer</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="customerForm">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="customerName" class="form-label">Company Name *</label>
                                    <input type="text" class="form-control" id="customerName" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="contactPerson" class="form-label">Contact Person *</label>
                                    <input type="text" class="form-control" id="contactPerson" required>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="customerEmail" class="form-label">Email *</label>
                                    <input type="email" class="form-control" id="customerEmail" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="customerPhone" class="form-label">Phone</label>
                                    <input type="tel" class="form-control" id="customerPhone">
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="customerAddress" class="form-label">Address</label>
                                    <textarea class="form-control" id="customerAddress" rows="3"></textarea>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="creditLimit" class="form-label">Credit Limit</label>
                                    <input type="number" class="form-control" id="creditLimit" step="0.01" min="0">
                                </div>
                                <div class="mb-3">
                                    <label for="customerStatus" class="form-label">Status</label>
                                    <select class="form-select" id="customerStatus">
                                        <option value="active">Active</option>
                                        <option value="inactive">Inactive</option>
                                        <option value="suspended">Suspended</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Customer</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Add Invoice Modal -->
    <div class="modal fade" id="addInvoiceModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Create New Invoice</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="invoiceForm">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-12">
                                <div class="mb-3">
                                    <label for="invoiceCustomer" class="form-label">Customer *</label>
                                    <select class="form-select" id="invoiceCustomer" required>
                                        <option value="">Select Customer</option>
                                        <!-- Customer options will be loaded here -->
                                    </select>
                                    <div class="form-text text-muted">
                                        <i class="fas fa-info-circle me-1"></i>
                                        Invoice number will be auto-generated (e.g., INV-2025-0001)
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="invoiceDate" class="form-label">Invoice Date *</label>
                                    <input type="date" class="form-control" id="invoiceDate" required>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="dueDate" class="form-label">Due Date *</label>
                                    <input type="date" class="form-control" id="dueDate" required>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="invoiceStatus" class="form-label">Status</label>
                                    <select class="form-select" id="invoiceStatus">
                                        <option value="draft">Draft</option>
                                        <option value="sent">Sent</option>
                                        <option value="paid">Paid</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Invoice Items</label>
                            <div id="invoiceItems">
                                <div class="row mb-2 invoice-item">
                                    <div class="col-md-4">
                                        <input type="text" class="form-control" placeholder="Description" required>
                                    </div>
                                    <div class="col-md-2">
                                        <input type="number" class="form-control quantity" placeholder="Qty" step="1" min="1" required>
                                    </div>
                                    <div class="col-md-2">
                                        <input type="number" class="form-control unit-price" placeholder="Unit Price" step="0.01" min="0" required>
                                    </div>
                                    <div class="col-md-2">
                                        <input type="number" class="form-control line-total" placeholder="Total" readonly>
                                    </div>
                                    <div class="col-md-2">
                                        <button type="button" class="btn btn-danger btn-sm remove-item">Remove</button>
                                    </div>
                                </div>
                            </div>
                            <button type="button" class="btn btn-secondary btn-sm" id="addItem">Add Item</button>
                        </div>
                        <div class="row">
                            <div class="col-md-6 offset-md-6">
                                <div class="mb-2 d-flex justify-content-between">
                                    <strong>Subtotal:</strong>
                                    <span id="subtotal">$0.00</span>
                                </div>
                                <div class="mb-2 d-flex justify-content-between">
                                    <strong>Tax (10%):</strong>
                                    <span id="tax">$0.00</span>
                                </div>
                                <div class="mb-2 d-flex justify-content-between">
                                    <strong>Total:</strong>
                                    <span id="total">$0.00</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Invoice</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Add Payment Modal -->
    <div class="modal fade" id="addPaymentModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Record Payment</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="paymentForm">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="paymentCustomer" class="form-label">Customer *</label>
                                    <select class="form-select" id="paymentCustomer" required>
                                        <option value="">Select Customer</option>
                                        <!-- Customer options will be loaded here -->
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="paymentInvoice" class="form-label">Invoice</label>
                                    <select class="form-select" id="paymentInvoice">
                                        <option value="">Select Invoice (Optional)</option>
                                        <!-- Invoice options will be loaded here -->
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="paymentDate" class="form-label">Payment Date *</label>
                                    <input type="date" class="form-control" id="paymentDate" required>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="paymentAmount" class="form-label">Amount *</label>
                                    <input type="number" class="form-control" id="paymentAmount" step="0.01" min="0" required>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="paymentMethod" class="form-label">Payment Method *</label>
                                    <select class="form-select" id="paymentMethod" required>
                                        <option value="cash">Cash</option>
                                        <option value="check">Check</option>
                                        <option value="bank_transfer">Bank Transfer</option>
                                        <option value="credit_card">Credit Card</option>
                                        <option value="other">Other</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="paymentReference" class="form-label">Reference/Check Number</label>
                            <input type="text" class="form-control" id="paymentReference">
                        </div>
                        <div class="mb-3">
                            <label for="paymentNotes" class="form-label">Notes</label>
                            <textarea class="form-control" id="paymentNotes" rows="3"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success">Record Payment</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Add Adjustment Modal -->
    <div class="modal fade" id="addAdjustmentModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add Adjustment</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="adjustmentForm">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="adjustmentType" class="form-label">Adjustment Type *</label>
                                    <select class="form-select" id="adjustmentType" required>
                                        <option value="credit_memo">Credit Memo</option>
                                        <option value="debit_memo">Debit Memo</option>
                                        <option value="write_off">Write Off</option>
                                        <option value="discount">Discount</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="adjustmentCustomer" class="form-label">Customer *</label>
                                    <select class="form-select" id="adjustmentCustomer" required>
                                        <option value="">Select Customer</option>
                                        <!-- Customer options will be loaded here -->
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="adjustmentInvoice" class="form-label">Related Invoice</label>
                                    <select class="form-select" id="adjustmentInvoice">
                                        <option value="">Select Invoice (Optional)</option>
                                        <!-- Invoice options will be loaded here -->
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="adjustmentAmount" class="form-label">Amount *</label>
                                    <input type="number" class="form-control" id="adjustmentAmount" step="0.01" min="0" required>
                                </div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="adjustmentReason" class="form-label">Reason *</label>
                            <textarea class="form-control" id="adjustmentReason" rows="3" required></textarea>
                        </div>
                        <div class="mb-3">
                            <label for="adjustmentDate" class="form-label">Adjustment Date *</label>
                            <input type="date" class="form-control" id="adjustmentDate" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-warning">Save Adjustment</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../includes/alert-modal.js"></script>
    <script>
        // Pass customers data from PHP to JavaScript
        const phpCustomers = <?php echo json_encode($customers); ?>;

        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('show');
        }

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


        // Initialize sidebar state on page load
        document.addEventListener('DOMContentLoaded', function() {
            const sidebar = document.getElementById('sidebar');
            const content = document.querySelector('.content');
            const arrow = document.getElementById('sidebarArrow');
            const toggle = document.querySelector('.sidebar-toggle');
            const logoImg = document.querySelector('.navbar-brand img');
            const isCollapsed = localStorage.getItem('sidebarCollapsed') === 'true';
            if (isCollapsed) {
                sidebar.classList.add('sidebar-collapsed');
                logoImg.src = 'atieralogo2.png';
                content.style.marginLeft = '120px';
                arrow.classList.remove('fa-chevron-left');
                arrow.classList.add('fa-chevron-right');
                toggle.style.left = '110px';
            } else {
                sidebar.classList.remove('sidebar-collapsed');
                logoImg.src = 'atieralogo.png';
                content.style.marginLeft = '300px';
                arrow.classList.remove('fa-chevron-right');
                arrow.classList.add('fa-chevron-left');
                toggle.style.left = '290px';
            }

            // Initialize AR system
            initializeARSystem();
        });

        // AR System Functions
        function initializeARSystem() {
            loadCustomers();
            loadInvoices();
            loadPayments();
            loadAdjustments();
            loadReportsData();
            setupEventListeners();
        }

        function setupEventListeners() {
            // Customer form
            document.getElementById('customerForm').addEventListener('submit', handleCustomerSubmit);

            // Invoice form
            document.getElementById('invoiceForm').addEventListener('submit', handleInvoiceSubmit);
            document.getElementById('addItem').addEventListener('click', addInvoiceItem);

            // Payment form
            document.getElementById('paymentForm').addEventListener('submit', handlePaymentSubmit);

            // Adjustment form
            document.getElementById('adjustmentForm').addEventListener('submit', handleAdjustmentSubmit);

            // Invoice calculations
            document.addEventListener('input', handleInvoiceCalculations);
        }

        // Customer Functions
        function loadCustomers() {
            try {
                const tbody = document.querySelector('#customersTable tbody');
                tbody.innerHTML = '';

                if (Array.isArray(phpCustomers) && phpCustomers.length > 0) {
                    phpCustomers.forEach(customer => {
                        const row = `
                            <tr>
                                <td>${customer.customer_code || ''}</td>
                                <td>${customer.company_name || ''}</td>
                                <td>${customer.contact_person || ''}</td>
                                <td>${customer.email || ''}</td>
                                <td>${customer.phone || ''}</td>
                                <td>₱${parseFloat(customer.credit_limit || 0).toLocaleString()}</td>
                                <td><span class="badge bg-${customer.status === 'active' ? 'success' : 'secondary'}">${customer.status || 'unknown'}</span></td>
                                <td>
                                    <button class="btn btn-sm btn-outline-primary me-1" onclick="editCustomer(${customer.id})">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button class="btn btn-sm btn-outline-danger" onclick="deleteCustomer(${customer.id})">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </td>
                            </tr>
                        `;
                        tbody.innerHTML += row;
                    });

                    // Update customer dropdowns
                    updateCustomerDropdowns(phpCustomers);
                    showAlert('Customers loaded successfully', 'success');
                } else if (Array.isArray(phpCustomers) && phpCustomers.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="8" class="text-center text-muted">No customers found. Add your first customer to get started.</td></tr>';
                } else {
                    tbody.innerHTML = '<tr><td colspan="8" class="text-center text-muted">Error loading customers. Please try again.</td></tr>';
                    showAlert('Error loading customers data', 'danger');
                }
            } catch (error) {
                console.error('Error loading customers:', error);
                const tbody = document.querySelector('#customersTable tbody');
                tbody.innerHTML = '<tr><td colspan="8" class="text-center text-muted">Error loading customers. Please try again.</td></tr>';
                showAlert('Error loading customers: ' + error.message, 'danger');
            }
        }

        async function handleCustomerSubmit(e) {
            e.preventDefault();
            const formData = new FormData(e.target);
            const action = e.target.getAttribute('data-mode') === 'update' ? 'update' : 'create';
            const customerId = e.target.getAttribute('data-id') || '';

            // Collect form data
            const customerData = {
                company_name: document.getElementById('customerName').value,
                contact_person: document.getElementById('contactPerson').value,
                email: document.getElementById('customerEmail').value,
                phone: document.getElementById('customerPhone').value,
                address: document.getElementById('customerAddress').value,
                credit_limit: parseFloat(document.getElementById('creditLimit').value) || 0,
                status: document.getElementById('customerStatus').value || 'active'
            };

            try {
                const url = action === 'update' && customerId
                    ? `customer_handler.php?action=${action}&id=${customerId}`
                    : `customer_handler.php?action=${action}`;

                const response = await fetch(url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify(customerData)
                });

                const result = await response.json();

                if (result.success) {
                    // Close modal and reload data
                    const modal = bootstrap.Modal.getInstance(document.getElementById('addCustomerModal'));
                    modal.hide();

                    // Reload page to get fresh data
                    window.location.reload();

                    showAlert(result.message || 'Customer saved successfully', 'success');
                } else {
                    showAlert(result.error || 'Error saving customer', 'danger');
                }
            } catch (error) {
                console.error('Error saving customer:', error);
                showAlert('Error saving customer', 'danger');
            }
        }

        async function editCustomer(id) {
            try {
                const response = await fetch(`customer_handler.php?action=read&id=${id}`);
                const customer = await response.json();

                if (customer) {
                    // Populate form with customer data
                    document.getElementById('customerName').value = customer.company_name;
                    document.getElementById('contactPerson').value = customer.contact_person || '';
                    document.getElementById('customerEmail').value = customer.email || '';
                    document.getElementById('customerPhone').value = customer.phone || '';
                    document.getElementById('customerAddress').value = customer.address || '';
                    document.getElementById('creditLimit').value = customer.credit_limit;
                    document.getElementById('customerStatus').value = customer.status;

                    // Change form to update mode
                    const form = document.getElementById('customerForm');
                    form.setAttribute('data-mode', 'update');
                    form.setAttribute('data-id', id);

                    // Update modal title
                    document.querySelector('#addCustomerModal .modal-title').textContent = 'Edit Customer';

                    // Show modal
                    const modal = new bootstrap.Modal(document.getElementById('addCustomerModal'));
                    modal.show();
                }
            } catch (error) {
                console.error('Error loading customer:', error);
                showAlert('Error loading customer', 'danger');
            }
        }

        async function deleteCustomer(id) {
            showConfirmDialog(
                'Delete Customer',
                'Are you sure you want to delete this customer?',
                async () => {
                try {
                    const response = await fetch(`customer_handler.php?action=delete&id=${id}`, {
                        method: 'DELETE'
                    });

                    const result = await response.json();

                    if (response.ok && result.success) {
                        // Reload page to get fresh data
                        window.location.reload();
                        showAlert('Customer deleted successfully', 'success');
                    } else {
                        showAlert(result.error || 'Error deleting customer', 'danger');
                    }
                } catch (error) {
                    console.error('Error deleting customer:', error);
                    showAlert('Error deleting customer: ' + error.message, 'danger');
                }
                }
            );
        }

        // Invoice Functions
        async function loadInvoices() {
            try {
                showLoading();
                const response = await fetch('../api/invoices.php');
                const invoices = await response.json();

                if (invoices && !invoices.error) {
                    const tbody = document.querySelector('#invoicesTable tbody');
                    tbody.innerHTML = '';

                    if (invoices.length > 0) {
                        invoices.forEach(invoice => {
                            const statusClass = getStatusClass(invoice.status);
                            const statusDotClass = getStatusDotClass(invoice.status);
                            const row = `
                                <tr>
                                    <td>${invoice.invoice_number}</td>
                                    <td>${invoice.customer_name}</td>
                                    <td>${new Date(invoice.invoice_date).toLocaleDateString()}</td>
                                    <td>${new Date(invoice.due_date).toLocaleDateString()}</td>
                                    <td>₱${parseFloat(invoice.total_amount).toLocaleString()}</td>
                                    <td>
                                        <div class="status-indicator ${statusDotClass}">
                                            <span class="status-dot"></span>
                                            <span class="badge bg-${statusClass}">${invoice.status}</span>
                                        </div>
                                    </td>
                                    <td>
                                        <button class="btn btn-sm btn-outline-primary me-1" onclick="viewInvoice('${invoice.id}')" title="View Invoice">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <button class="btn btn-sm btn-outline-success me-1" onclick="sendInvoice('${invoice.id}')" title="Send Invoice">
                                            <i class="fas fa-paper-plane"></i>
                                        </button>
                                        <button class="btn btn-sm btn-outline-danger" onclick="deleteInvoice('${invoice.id}')" title="Delete Invoice">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                            `;
                            tbody.innerHTML += row;
                        });
                    } else {
                        tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted">No invoices found. Create your first invoice to get started.</td></tr>';
                    }
                } else {
                    // Handle error
                    const tbody = document.querySelector('#invoicesTable tbody');
                    tbody.innerHTML = '<tr><td colspan="7" class="text-center text-danger">Error loading invoices</td></tr>';
                    showAlert('Error loading invoices', 'danger');
                }
            } catch (error) {
                console.error('Error loading invoices:', error);
                const tbody = document.querySelector('#invoicesTable tbody');
                tbody.innerHTML = '<tr><td colspan="7" class="text-center text-danger">Error loading invoices data</td></tr>';
                showAlert('Error loading invoices', 'danger');
            } finally {
                hideLoading();
            }
        }

        async function handleInvoiceSubmit(e) {
            e.preventDefault();

            // Collect invoice data
            const invoiceData = {
                customer_id: parseInt(document.getElementById('invoiceCustomer').value),
                invoice_date: document.getElementById('invoiceDate').value,
                due_date: document.getElementById('dueDate').value,
                status: document.getElementById('invoiceStatus').value || 'draft',
                tax_rate: 10.00, // 10% VAT as default
                items: []
            };

            // Collect invoice items
            const items = document.querySelectorAll('.invoice-item');
            items.forEach(item => {
                const description = item.querySelector('input[type="text"]').value;
                const quantity = parseFloat(item.querySelector('.quantity').value) || 0;
                const unitPrice = parseFloat(item.querySelector('.unit-price').value) || 0;

                if (description && quantity > 0 && unitPrice > 0) {
                    invoiceData.items.push({
                        description: description,
                        quantity: quantity,
                        unit_price: unitPrice
                    });
                }
            });

            if (invoiceData.items.length === 0) {
                showAlert('Please add at least one item to the invoice', 'danger');
                return;
            }

            try {
                showLoading();
                const response = await fetch('../api/invoices.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify(invoiceData)
                });

                const result = await response.json();

                if (result.success) {
                    // Close modal and reload data
                    const modal = bootstrap.Modal.getInstance(document.getElementById('addInvoiceModal'));
                    modal.hide();

                    // Reload invoices data
                    loadInvoices();

                    // Reset form
                    e.target.reset();
                    document.getElementById('invoiceItems').innerHTML = `
                        <div class="row mb-2 invoice-item">
                            <div class="col-md-4">
                                <input type="text" class="form-control" placeholder="Description" required>
                            </div>
                            <div class="col-md-2">
                                <input type="number" class="form-control quantity" placeholder="Qty" step="1" min="1" required>
                            </div>
                            <div class="col-md-2">
                                <input type="number" class="form-control unit-price" placeholder="Unit Price" step="0.01" min="0" required>
                            </div>
                            <div class="col-md-2">
                                <input type="number" class="form-control line-total" placeholder="Total" readonly>
                            </div>
                            <div class="col-md-2">
                                <button type="button" class="btn btn-danger btn-sm remove-item">Remove</button>
                            </div>
                        </div>
                    `;
                    updateInvoiceTotals();

                    showAlert(`Invoice created successfully! Invoice #: ${result.invoice_number}`, 'success');
                } else {
                    showAlert(result.error || 'Failed to create invoice', 'danger');
                }
            } catch (error) {
                console.error('Error saving invoice:', error);
                showAlert('Error creating invoice: ' + error.message, 'danger');
            } finally {
                hideLoading();
            }
        }

        function addInvoiceItem() {
            const itemHtml = `
                <div class="row mb-2 invoice-item">
                    <div class="col-md-4">
                        <input type="text" class="form-control" placeholder="Description" required>
                    </div>
                    <div class="col-md-2">
                        <input type="number" class="form-control quantity" placeholder="Qty" step="1" min="1" required>
                    </div>
                    <div class="col-md-2">
                        <input type="number" class="form-control unit-price" placeholder="Unit Price" step="0.01" min="0" required>
                    </div>
                    <div class="col-md-2">
                        <input type="number" class="form-control line-total" placeholder="Total" readonly>
                    </div>
                    <div class="col-md-2">
                        <button type="button" class="btn btn-danger btn-sm remove-item">Remove</button>
                    </div>
                </div>
            `;
            document.getElementById('invoiceItems').insertAdjacentHTML('beforeend', itemHtml);
        }

        function handleInvoiceCalculations(e) {
            if (e.target.classList.contains('quantity') || e.target.classList.contains('unit-price')) {
                const item = e.target.closest('.invoice-item');
                const quantity = parseFloat(item.querySelector('.quantity').value) || 0;
                const unitPrice = parseFloat(item.querySelector('.unit-price').value) || 0;
                const lineTotal = quantity * unitPrice;
                item.querySelector('.line-total').value = lineTotal.toFixed(2);
                updateInvoiceTotals();
            }

            // Handle remove item
            if (e.target.classList.contains('remove-item')) {
                e.target.closest('.invoice-item').remove();
                updateInvoiceTotals();
            }
        }

        function updateInvoiceTotals() {
            const items = document.querySelectorAll('.invoice-item');
            let subtotal = 0;
            items.forEach(item => {
                const lineTotal = parseFloat(item.querySelector('.line-total').value) || 0;
                subtotal += lineTotal;
            });

            const tax = subtotal * 0.10;
            const total = subtotal + tax;

            document.getElementById('subtotal').textContent = `₱${subtotal.toFixed(2)}`;
            document.getElementById('tax').textContent = `₱${tax.toFixed(2)}`;
            document.getElementById('total').textContent = `₱${total.toFixed(2)}`;
        }

        function filterInvoices() {
            // Mock filter - replace with actual implementation
            loadInvoices();
        }

        // Payment Functions
        async function loadPayments() {
            try {
                const response = await fetch('../api/payments.php?type=received');
                const result = await response.json();

                if (result.success !== false && Array.isArray(result)) {
                    const tbody = document.querySelector('#collectionsTable tbody');
                    tbody.innerHTML = '';

                    if (result.length > 0) {
                        result.forEach(payment => {
                            const invoiceNumber = payment.invoice_number || 'N/A';
                            const row = `
                                <tr>
                                    <td>${payment.payment_number || payment.id}</td>
                                    <td>${payment.customer_name || 'Unknown'}</td>
                                    <td>${invoiceNumber}</td>
                                    <td>${new Date(payment.payment_date).toLocaleDateString()}</td>
                                    <td>₱${parseFloat(payment.amount).toLocaleString()}</td>
                                    <td>${payment.payment_method.replace('_', ' ').toUpperCase()}</td>
                                    <td>${payment.reference_number || ''}</td>
                                    <td>
                                        <button class="btn btn-sm btn-outline-primary me-1" onclick="viewPayment('${payment.id}')">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <button class="btn btn-sm btn-outline-danger" onclick="deletePayment('${payment.id}')">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                            `;
                            tbody.innerHTML += row;
                        });
                    } else {
                        tbody.innerHTML = '<tr><td colspan="8" class="text-center text-muted">No payments found. Add your first payment to get started.</td></tr>';
                    }
                } else {
                    console.error('Failed to load payments:', result);
                    const tbody = document.querySelector('#collectionsTable tbody');
                    tbody.innerHTML = '<tr><td colspan="8" class="text-center text-danger">Error loading payments data</td></tr>';
                }
            } catch (error) {
                console.error('Error loading payments:', error);
                const tbody = document.querySelector('#collectionsTable tbody');
                tbody.innerHTML = '<tr><td colspan="8" class="text-center text-danger">Error loading payments data</td></tr>';
            }
        }

        async function handlePaymentSubmit(e) {
            e.preventDefault();
            const formData = new FormData(e.target);
            const paymentData = Object.fromEntries(formData);

            // Convert to API format
            const apiData = {
                customer_id: parseInt(paymentData.paymentCustomer),
                invoice_id: paymentData.paymentInvoice ? parseInt(paymentData.paymentInvoice) : null,
                payment_date: paymentData.paymentDate,
                amount: parseFloat(paymentData.paymentAmount),
                payment_method: paymentData.paymentMethod,
                reference_number: paymentData.paymentReference,
                notes: paymentData.paymentNotes,
                payment_type: 'received' // This is for collections (payments received)
            };

            try {
                const response = await fetch('../api/payments.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify(apiData)
                });

                const result = await response.json();

                if (result.success) {
                    // Close modal and reload data
                    const modal = bootstrap.Modal.getInstance(document.getElementById('addPaymentModal'));
                    modal.hide();

                    // Reset form
                    e.target.reset();

                    // Reload payments data
                    loadPayments();

                    // Show success message
                    showAlert('Payment recorded successfully!', 'success');
                } else {
                    showAlert(result.error || 'Failed to record payment', 'danger');
                }
            } catch (error) {
                console.error('Error saving payment:', error);
                showAlert('Error recording payment: ' + error.message, 'danger');
            }
        }

        // Adjustment Functions
        async function loadAdjustments() {
            try {
                const response = await fetch('../api/adjustments.php?type=receivable');
                const adjustments = await response.json();

                const tbody = document.querySelector('#adjustmentsTable tbody');
                tbody.innerHTML = '';

                if (adjustments && Array.isArray(adjustments) && adjustments.length > 0) {
                    adjustments.forEach(adjustment => {
                        const typeLabel = adjustment.adjustment_type.replace('_', ' ').toUpperCase();
                        const customerName = adjustment.customer_name || 'Unknown Customer';
                        const invoiceNumber = adjustment.invoice_number || 'N/A';
                        const row = `
                            <tr>
                                <td>${adjustment.adjustment_number || adjustment.id}</td>
                                <td>${typeLabel}</td>
                                <td>${customerName}</td>
                                <td>${invoiceNumber}</td>
                                <td>₱${parseFloat(adjustment.amount).toLocaleString()}</td>
                                <td>${adjustment.reason}</td>
                                <td>${new Date(adjustment.adjustment_date).toLocaleDateString()}</td>
                                <td>
                                    <button class="btn btn-sm btn-outline-primary me-1" onclick="viewAdjustment('${adjustment.id}')">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    <button class="btn btn-sm btn-outline-danger" onclick="deleteAdjustment('${adjustment.id}')">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </td>
                            </tr>
                        `;
                        tbody.innerHTML += row;
                    });
                } else {
                    tbody.innerHTML = '<tr><td colspan="8" class="text-center text-muted">No adjustments found. Add your first adjustment to get started.</td></tr>';
                }
            } catch (error) {
                console.error('Error loading adjustments:', error);
                const tbody = document.querySelector('#adjustmentsTable tbody');
                tbody.innerHTML = '<tr><td colspan="8" class="text-center text-danger">Error loading adjustments data</td></tr>';
                showAlert('Error loading adjustments', 'danger');
            }
        }

        async function handleAdjustmentSubmit(e) {
            e.preventDefault();

            // Collect adjustment data
            const adjustmentData = {
                adjustment_type: document.getElementById('adjustmentType').value,
                customer_id: parseInt(document.getElementById('adjustmentCustomer').value),
                invoice_id: document.getElementById('adjustmentInvoice').value ? parseInt(document.getElementById('adjustmentInvoice').value) : null,
                amount: parseFloat(document.getElementById('adjustmentAmount').value),
                reason: document.getElementById('adjustmentReason').value,
                adjustment_date: document.getElementById('adjustmentDate').value
            };

            try {
                const response = await fetch('../api/adjustments.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify(adjustmentData)
                });

                const result = await response.json();

                if (result.success) {
                    // Close modal and reload data
                    const modal = bootstrap.Modal.getInstance(document.getElementById('addAdjustmentModal'));
                    modal.hide();
                    loadAdjustments();

                    // Reset form
                    e.target.reset();

                    showAlert(`Adjustment created successfully! Adjustment #: ${result.adjustment_number}`, 'success');
                } else {
                    showAlert(result.error || 'Failed to create adjustment', 'danger');
                }
            } catch (error) {
                console.error('Error saving adjustment:', error);
                showAlert('Error creating adjustment: ' + error.message, 'danger');
            }
        }

        // Aging Report Functions
        async function generateAgingReport() {
            try {
                const response = await fetch('../api/invoices.php?aging=true');
                const agingData = await response.json();

                const tbody = document.querySelector('#agingTable tbody');
                tbody.innerHTML = '';

                if (agingData && Array.isArray(agingData) && agingData.length > 0) {
                    agingData.forEach(customerData => {
                        const current = parseFloat(customerData.current) || 0;
                        const days30 = parseFloat(customerData.days30) || 0;
                        const days60 = parseFloat(customerData.days60) || 0;
                        const days90 = parseFloat(customerData.days90) || 0;
                        const legacy = parseFloat(customerData.legacy) || 0;
                        const total = parseFloat(customerData.total) || 0;

                        const row = `
                            <tr>
                                <td>${customerData.customer_name}</td>
                                <td>₱${current.toLocaleString()}</td>
                                <td>₱${days30.toLocaleString()}</td>
                                <td>₱${days60.toLocaleString()}</td>
                                <td>₱${days90.toLocaleString()}</td>
                                <td>₱${legacy.toLocaleString()}</td>
                                <td><strong>₱${total.toLocaleString()}</strong></td>
                            </tr>
                        `;
                        tbody.innerHTML += row;
                    });
                } else {
                    tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted">No aging data available. Create some invoices with due dates to see aging.</td></tr>';
                }

                showAlert('Aging report generated successfully', 'success');
            } catch (error) {
                console.error('Error generating aging report:', error);
                const tbody = document.querySelector('#agingTable tbody');
                tbody.innerHTML = '<tr><td colspan="7" class="text-center text-danger">Error generating aging report</td></tr>';
                showAlert('Error generating aging report: ' + error.message, 'danger');
            }
        }

        // Reports Functions
        async function loadReportsData() {
            try {
                const response = await fetch('../api/invoices.php?action=get_summary_stats');
                const data = await response.json();

                const totalReceivablesEl = document.getElementById('totalReceivables');
                const overdueAmountEl = document.getElementById('overdueAmount');

                if (totalReceivablesEl && data.total_receivables !== undefined) {
                    totalReceivablesEl.textContent = formatCurrency(data.total_receivables);
                }
                if (overdueAmountEl && data.overdue_amount !== undefined) {
                    overdueAmountEl.textContent = formatCurrency(data.overdue_amount);
                }
            } catch (error) {
                console.error('Error loading reports data:', error);
                showAlert('Failed to load reports data', 'danger');
            }
        }

        async function exportReport(type) {
            try {
                showAlert(`Generating ${type} report...`, 'info');

                let data = [];
                let filename = '';
                let headers = [];

                switch (type) {
                    case 'receivables':
                        // Export receivables report
                        const receivablesResponse = await fetch('../api/invoices.php');
                        const receivablesData = await receivablesResponse.json();

                        data = receivablesData.map(invoice => ({
                            'Invoice #': invoice.invoice_number,
                            'Customer': invoice.customer_name,
                            'Customer Code': invoice.customer_code,
                            'Date': new Date(invoice.invoice_date).toLocaleDateString(),
                            'Due Date': new Date(invoice.due_date).toLocaleDateString(),
                            'Amount': parseFloat(invoice.total_amount).toLocaleString(),
                            'Balance': parseFloat(invoice.balance).toLocaleString(),
                            'Status': invoice.status,
                            'Days Overdue': invoice.status === 'overdue' ? Math.floor((new Date() - new Date(invoice.due_date)) / (1000 * 60 * 60 * 24)) : 0
                        }));

                        headers = ['Invoice #', 'Customer', 'Customer Code', 'Date', 'Due Date', 'Amount', 'Balance', 'Status', 'Days Overdue'];
                        filename = `receivables_report_${new Date().toISOString().split('T')[0]}.csv`;
                        break;

                    case 'collections':
                        // Export collections report
                        const collectionsResponse = await fetch('../api/payments.php?type=received');
                        const collectionsData = await collectionsResponse.json();

                        if (collectionsData.success !== false) {
                            data = Array.isArray(collectionsData) ? collectionsData : collectionsData.data || [];
                            data = data.map(payment => ({
                                'Payment #': payment.payment_number || payment.id,
                                'Customer': payment.customer_name || 'Unknown',
                                'Date': new Date(payment.payment_date).toLocaleDateString(),
                                'Amount': parseFloat(payment.amount).toLocaleString(),
                                'Method': payment.payment_method.replace('_', ' ').toUpperCase(),
                                'Reference': payment.reference_number || '',
                                'Invoice': payment.invoice_number || 'N/A'
                            }));

                            headers = ['Payment #', 'Customer', 'Date', 'Amount', 'Method', 'Reference', 'Invoice'];
                            filename = `collections_report_${new Date().toISOString().split('T')[0]}.csv`;
                        }
                        break;

                    case 'aging':
                        // Export aging report
                        const agingResponse = await fetch('../api/invoices.php?aging=true');
                        const agingData = await agingResponse.json();

                        if (agingData && Array.isArray(agingData)) {
                            data = agingData.map(customer => ({
                                'Customer': customer.customer_name,
                                'Customer Code': customer.customer_code,
                                'Current': parseFloat(customer.current).toLocaleString(),
                                '1-30 Days': parseFloat(customer.days30).toLocaleString(),
                                '31-60 Days': parseFloat(customer.days60).toLocaleString(),
                                '61-90 Days': parseFloat(customer.days90).toLocaleString(),
                                '90+ Days': parseFloat(customer.legacy).toLocaleString(),
                                'Total': parseFloat(customer.total).toLocaleString()
                            }));

                            headers = ['Customer', 'Customer Code', 'Current', '1-30 Days', '31-60 Days', '61-90 Days', '90+ Days', 'Total'];
                            filename = `aging_report_${new Date().toISOString().split('T')[0]}.csv`;
                        }
                        break;

                    default:
                        throw new Error('Unknown report type');
                }

                if (data.length === 0) {
                    showAlert(`No data available for ${type} report`, 'warning');
                    return;
                }

                // Generate CSV content
                const csvContent = generateCSV(headers, data);

                // Create download link
                const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
                const link = document.createElement('a');
                const url = URL.createObjectURL(blob);

                link.setAttribute('href', url);
                link.setAttribute('download', filename);
                link.style.visibility = 'hidden';

                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);

                // Revoke the object URL
                setTimeout(() => URL.revokeObjectURL(url), 100);

                showAlert(`${type.charAt(0).toUpperCase() + type.slice(1)} report exported successfully!`, 'success');

            } catch (error) {
                console.error('Error exporting report:', error);
                showAlert(`Error exporting ${type} report: ${error.message}`, 'danger');
            }
        }

        // Helper function to generate CSV content
        function generateCSV(headers, data) {
            const csvRows = [];

            // Add headers
            csvRows.push(headers.map(header => `"${header}"`).join(','));

            // Add data rows
            data.forEach(row => {
                const csvRow = headers.map(header => {
                    const value = row[header] || '';
                    // Escape quotes and wrap in quotes
                    const escapedValue = String(value).replace(/"/g, '""');
                    return `"${escapedValue}"`;
                });
                csvRows.push(csvRow.join(','));
            });

            return csvRows.join('\n');
        }

        // Utility Functions
        function updateCustomerDropdowns() {
            const dropdowns = ['invoiceCustomer', 'paymentCustomer', 'adjustmentCustomer'];
            dropdowns.forEach(id => {
                const select = document.getElementById(id);
                if (select) {
                    select.innerHTML = '<option value="">Select Customer</option>';
                    if (Array.isArray(phpCustomers)) {
                        phpCustomers.forEach(customer => {
                            select.innerHTML += `<option value="${customer.id}">${customer.company_name}</option>`;
                        });
                    }
                }
            });
        }

        // Utility Functions
        function showAlert(message, type = 'info') {
            // Create alert element
            const alertDiv = document.createElement('div');
            alertDiv.className = `alert alert-${type} alert-dismissible fade show position-fixed`;
            alertDiv.style.cssText = 'top: 20px; right: 20px; z-index: 9999; min-width: 300px;';
            alertDiv.innerHTML = `
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;

            document.body.appendChild(alertDiv);

            // Auto remove after 5 seconds
            setTimeout(() => {
                if (alertDiv.parentNode) {
                    alertDiv.remove();
                }
            }, 5000);
        }

        // Invoice action functions
        async function viewInvoice(id) {
            try {
                const response = await fetch(`../api/invoices.php?id=${id}`);
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                const invoice = await response.json();
                if (invoice.error) {
                    throw new Error(invoice.error);
                }

                // Create invoice details modal
                const modalContent = `
                    <div class="modal-dialog modal-xl">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title">Invoice Details - ${invoice.invoice_number}</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <strong>Customer:</strong> ${invoice.customer_name}
                                    </div>
                                    <div class="col-md-3">
                                        <strong>Invoice Date:</strong> ${new Date(invoice.invoice_date).toLocaleDateString()}
                                    </div>
                                    <div class="col-md-3">
                                        <strong>Due Date:</strong> ${new Date(invoice.due_date).toLocaleDateString()}
                                    </div>
                                </div>
                                <div class="row mb-3">
                                    <div class="col-md-3">
                                        <strong>Status:</strong>
                                        <span class="badge bg-${getStatusClass(invoice.status)}">${invoice.status}</span>
                                    </div>
                                    <div class="col-md-3">
                                        <strong>Balance:</strong> ₱${parseFloat(invoice.balance).toLocaleString()}
                                    </div>
                                    <div class="col-md-6">
                                        <strong>Total:</strong> ₱${parseFloat(invoice.total_amount).toLocaleString()}
                                    </div>
                                </div>

                                <h6>Invoice Items:</h6>
                                <div class="table-responsive">
                                    <table class="table table-striped">
                                        <thead>
                                            <tr>
                                                <th>Description</th>
                                                <th class="text-end">Quantity</th>
                                                <th class="text-end">Unit Price</th>
                                                <th class="text-end">Line Total</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            ${invoice.items && invoice.items.length > 0 ?
                                                invoice.items.map(item => `
                                                    <tr>
                                                        <td>${item.description}</td>
                                                        <td class="text-end">${item.quantity}</td>
                                                        <td class="text-end">₱${parseFloat(item.unit_price).toLocaleString()}</td>
                                                        <td class="text-end">₱${parseFloat(item.line_total).toLocaleString()}</td>
                                                    </tr>
                                                `).join('') :
                                                '<tr><td colspan="4" class="text-center">No items found</td></tr>'
                                            }
                                        </tbody>
                                    </table>
                                </div>

                                ${invoice.notes ? `<div class="mt-3"><strong>Notes:</strong> ${invoice.notes}</div>` : ''}
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                <button type="button" class="btn btn-success" onclick="sendInvoice('${invoice.id}')">
                                    <i class="fas fa-paper-plane me-1"></i>Send Invoice
                                </button>
                            </div>
                        </div>
                    </div>
                `;

                // Create and show modal
                const modalDiv = document.createElement('div');
                modalDiv.className = 'modal fade';
                modalDiv.innerHTML = modalContent;
                document.body.appendChild(modalDiv);

                const modal = new bootstrap.Modal(modalDiv);
                modal.show();

                // Remove modal from DOM when hidden
                modalDiv.addEventListener('hidden.bs.modal', function() {
                    document.body.removeChild(modalDiv);
                });

            } catch (error) {
                console.error('Error viewing invoice:', error);
                showAlert('Error loading invoice details: ' + error.message, 'danger');
            }
        }

        async function sendInvoice(id) {
            showConfirmDialog(
                'Send Invoice',
                'Are you sure you want to mark this invoice as sent? This will send notification emails.',
                async () => {
                    try {
                        const response = await fetch(`../api/invoices.php?id=${id}`, {
                            method: 'PUT',
                            headers: {
                                'Content-Type': 'application/json',
                            },
                            body: JSON.stringify({ status: 'sent' })
                        });
                        if (!response.ok) {
                            throw new Error(`HTTP error! status: ${response.status}`);
                        }

                        const result = await response.json();

                        if (result.success || result === true) {
                            showAlert('Invoice sent successfully! Notifications will be sent.', 'success');

                            // Close any open modals
                            const openModals = document.querySelectorAll('.modal.show');
                            openModals.forEach(modalEl => {
                                const modal = bootstrap.Modal.getInstance(modalEl);
                                if (modal) modal.hide();
                            });

                            // Reload invoices to show updated status
                            loadInvoices();
                        } else {
                            throw new Error(result.error || 'Failed to send invoice');
                        }
                    } catch (error) {
                        console.error('Error sending invoice:', error);
                        showAlert('Error sending invoice: ' + error.message, 'danger');
                    }
                }
            );
        }

        async function deleteInvoice(id) {
            showConfirmDialog(
                'Delete Invoice',
                'Are you sure you want to delete this invoice? This action cannot be undone.',
                async () => {
                    try {
                        const response = await fetch(`../api/invoices.php?id=${id}`, {
                            method: 'DELETE'
                        });
                        if (!response.ok) {
                            throw new Error(`HTTP error! status: ${response.status}`);
                        }

                        const result = await response.json();

                        if (result.success || result === true) {
                            showAlert('Invoice deleted successfully', 'success');
                            loadInvoices(); // Reload invoices list
                        } else {
                            throw new Error(result.error || 'Failed to delete invoice');
                        }
                    } catch (error) {
                        console.error('Error deleting invoice:', error);
                        showAlert('Error deleting invoice: ' + error.message, 'danger');
                    }
                }
            );
        }

        // Payment action functions
        async function viewPayment(id) {
            try {
                const response = await fetch(`../api/payments.php?id=${id}&type=received`);
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                const payment = await response.json();

                if (payment.error) {
                    throw new Error(payment.error);
                }

                // Create payment details modal
                const modalContent = `
                    <div class="modal-dialog modal-lg">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title">Payment Details - ${payment.payment_number || payment.reference_number}</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label fw-bold">Customer</label>
                                            <p class="form-control-plaintext">${payment.customer_name || 'Unknown'}</p>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label fw-bold">Payment Date</label>
                                            <p class="form-control-plaintext">${new Date(payment.payment_date).toLocaleDateString()}</p>
                                        </div>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label fw-bold">Amount</label>
                                            <p class="form-control-plaintext">₱${parseFloat(payment.amount).toLocaleString()}</p>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label fw-bold">Method</label>
                                            <p class="form-control-plaintext">${payment.payment_method.replace('_', ' ').toUpperCase()}</p>
                                        </div>
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Reference Number</label>
                                    <p class="form-control-plaintext">${payment.reference_number || 'N/A'}</p>
                                </div>
                                ${payment.notes ? `
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Notes</label>
                                    <p class="form-control-plaintext">${payment.notes}</p>
                                </div>
                                ` : ''}
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                            </div>
                        </div>
                    </div>
                `;

                // Create and show modal
                const modalDiv = document.createElement('div');
                modalDiv.className = 'modal fade';
                modalDiv.innerHTML = modalContent;
                document.body.appendChild(modalDiv);

                const modal = new bootstrap.Modal(modalDiv);
                modal.show();

                // Remove modal from DOM when hidden
                modalDiv.addEventListener('hidden.bs.modal', function() {
                    document.body.removeChild(modalDiv);
                });

            } catch (error) {
                console.error('Error viewing payment:', error);
                showAlert('Error loading payment details: ' + error.message, 'danger');
            }
        }

        async function deletePayment(id) {
            showConfirmDialog(
                'Delete Payment',
                'Are you sure you want to delete this payment? This action cannot be undone.',
                async () => {
                    try {
                        const response = await fetch(`../api/payments.php?id=${id}&type=received`, {
                            method: 'DELETE'
                        });
                        if (!response.ok) {
                            throw new Error(`HTTP error! status: ${response.status}`);
                        }

                        const result = await response.json();

                        if (result.success || result === true) {
                            showAlert('Payment deleted successfully', 'success');
                            loadPayments(); // Reload payments list
                        } else {
                            throw new Error(result.error || 'Failed to delete payment');
                        }
                    } catch (error) {
                        console.error('Error deleting payment:', error);
                        showAlert('Error deleting payment: ' + error.message, 'danger');
                    }
                }
            );
        }

        // Adjustment action functions
        async function viewAdjustment(id) {
            try {
                const response = await fetch(`../api/adjustments.php?id=${id}`);
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                const adjustment = await response.json();

                // Create adjustment details modal
                const modalContent = `
                    <div class="modal-dialog modal-lg">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title">Adjustment Details - ${adjustment.adjustment_number || 'ADJ-' + adjustment.id}</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label fw-bold">Customer</label>
                                            <p class="form-control-plaintext">${adjustment.customer_name || 'Unknown'}</p>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label fw-bold">Type</label>
                                            <p class="form-control-plaintext">${adjustment.adjustment_type.replace('_', ' ').toUpperCase()}</p>
                                        </div>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label fw-bold">Amount</label>
                                            <p class="form-control-plaintext">₱${parseFloat(adjustment.amount).toLocaleString()}</p>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label fw-bold">Date</label>
                                            <p class="form-control-plaintext">${new Date(adjustment.adjustment_date).toLocaleDateString()}</p>
                                        </div>
                                    </div>
                                </div>
                                ${adjustment.reason ? `
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Reason</label>
                                    <p class="form-control-plaintext">${adjustment.reason}</p>
                                </div>
                                ` : ''}
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                            </div>
                        </div>
                    </div>
                `;

                // Create and show modal
                const modalDiv = document.createElement('div');
                modalDiv.className = 'modal fade';
                modalDiv.innerHTML = modalContent;
                document.body.appendChild(modalDiv);

                const modal = new bootstrap.Modal(modalDiv);
                modal.show();

                // Remove modal from DOM when hidden
                modalDiv.addEventListener('hidden.bs.modal', function() {
                    document.body.removeChild(modalDiv);
                });

            } catch (error) {
                console.error('Error viewing adjustment:', error);
                showAlert('Error loading adjustment details: ' + error.message, 'danger');
            }
        }

        async function deleteAdjustment(id) {
            showConfirmDialog(
                'Delete Adjustment',
                'Are you sure you want to delete this adjustment? This action cannot be undone.',
                async () => {
                    try {
                        const response = await fetch(`../api/adjustments.php?id=${id}`, {
                            method: 'DELETE'
                        });
                        if (!response.ok) {
                            throw new Error(`HTTP error! status: ${response.status}`);
                        }

                        const result = await response.json();

                        if (result.success) {
                            showAlert('Adjustment deleted successfully', 'success');
                            loadAdjustments(); // Reload adjustments list
                        } else {
                            throw new Error(result.error || 'Failed to delete adjustment');
                        }
                    } catch (error) {
                        console.error('Error deleting adjustment:', error);
                        showAlert('Error deleting adjustment: ' + error.message, 'danger');
                    }
                }
            );
        }

        // Loading and utility functions
        function showLoading() {
            const loadingBtn = document.querySelector('.btn-primary[form="invoiceForm"]');
            if (loadingBtn) {
                loadingBtn.innerHTML = '<span class="loading"></span> Processing...';
                loadingBtn.disabled = true;
            }
        }

        function hideLoading() {
            const loadingBtn = document.querySelector('.btn-primary[form="invoiceForm"]');
            if (loadingBtn) {
                loadingBtn.innerHTML = 'Save Invoice';
                loadingBtn.disabled = false;
            }
        }

        function getStatusClass(status) {
            const statusClasses = {
                'draft': 'secondary',
                'sent': 'warning',
                'paid': 'success',
                'overdue': 'danger'
            };
            return statusClasses[status] || 'secondary';
        }

        function getStatusDotClass(status) {
            const statusDotClasses = {
                'draft': 'status-draft',
                'sent': 'status-sent',
                'paid': 'status-paid',
                'overdue': 'status-overdue'
            };
            return statusDotClasses[status] || 'status-draft';
        }
    </script>

    <script src="../includes/financial_hq_state.js"></script>
    <script>
        let arMergedCustomers = [];
        let arMergedInvoices = [];
        let arMergedPayments = [];
        let arMergedAdjustments = [];
        let arCurrentAging = [];

        const originalViewInvoiceFn = typeof viewInvoice === 'function' ? viewInvoice : null;
        const originalSendInvoiceFn = typeof sendInvoice === 'function' ? sendInvoice : null;
        const originalDeleteInvoiceFn = typeof deleteInvoice === 'function' ? deleteInvoice : null;
        const originalViewPaymentFn = typeof viewPayment === 'function' ? viewPayment : null;
        const originalDeletePaymentFn = typeof deletePayment === 'function' ? deletePayment : null;
        const originalViewAdjustmentFn = typeof viewAdjustment === 'function' ? viewAdjustment : null;
        const originalDeleteAdjustmentFn = typeof deleteAdjustment === 'function' ? deleteAdjustment : null;

        function arMergeRecords(primary, secondary) {
            const map = new Map();
            (secondary || []).forEach(item => map.set(String(item.id), item));
            (primary || []).forEach(item => map.set(String(item.id), item));
            return Array.from(map.values());
        }

        async function arFetchJsonSafe(url) {
            try {
                const response = await fetch(url);
                const payload = await response.json();
                if (Array.isArray(payload)) {
                    return payload;
                }
                if (Array.isArray(payload?.data)) {
                    return payload.data;
                }
                if (Array.isArray(payload?.result)) {
                    return payload.result;
                }
                return [];
            } catch (error) {
                return [];
            }
        }

        function arEscape(value) {
            return String(value ?? '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }

        function arFormatMoney(value) {
            return 'PHP ' + Number(value || 0).toLocaleString('en-US', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        }

        function arCloseVisibleModals() {
            document.querySelectorAll('.modal.show').forEach(modalEl => {
                const modal = bootstrap.Modal.getInstance(modalEl);
                if (modal) {
                    modal.hide();
                }
            });
        }

        function arShowModal(title, bodyHtml, footerHtml) {
            const wrapper = document.createElement('div');
            wrapper.className = 'modal fade';
            wrapper.innerHTML = `
                <div class="modal-dialog modal-lg">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">${title}</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">${bodyHtml}</div>
                        <div class="modal-footer">${footerHtml || '<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>'}</div>
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

        function arUpdateCustomerDropdowns(customers) {
            ['invoiceCustomer', 'paymentCustomer', 'adjustmentCustomer'].forEach(id => {
                const select = document.getElementById(id);
                if (!select) {
                    return;
                }
                select.innerHTML = '<option value="">Select Customer</option>';
                (customers || []).forEach(customer => {
                    select.innerHTML += `<option value="${customer.id}">${arEscape(customer.company_name)}</option>`;
                });
            });
        }

        function arUpdateReportCards() {
            const outstanding = arMergedInvoices.reduce((sum, invoice) => sum + Number(invoice.balance ?? invoice.total_amount ?? 0), 0);
            const overdue = arMergedInvoices
                .filter(invoice => ['overdue', 'partial'].includes(String(invoice.status || '').toLowerCase()))
                .reduce((sum, invoice) => sum + Number(invoice.balance ?? 0), 0);
            const thisMonthCollections = arMergedPayments
                .filter(payment => String(payment.payment_date || '').startsWith('2026-05') || String(payment.payment_date || '').startsWith('2026-04'))
                .reduce((sum, payment) => sum + Number(payment.amount || 0), 0);

            const totalCards = document.querySelectorAll('#reports .reports-card h3');
            if (totalCards.length >= 3) {
                totalCards[0].textContent = arFormatMoney(outstanding);
                totalCards[1].textContent = arFormatMoney(overdue);
                totalCards[2].textContent = arFormatMoney(thisMonthCollections);
            }

            const totalReceivablesEl = document.getElementById('totalReceivables');
            const overdueAmountEl = document.getElementById('overdueAmount');
            if (totalReceivablesEl) totalReceivablesEl.textContent = arFormatMoney(outstanding);
            if (overdueAmountEl) overdueAmountEl.textContent = arFormatMoney(overdue);
        }

        function viewCustomerRecord(id) {
            const customer = arMergedCustomers.find(item => String(item.id) === String(id));
            if (!customer) {
                showAlert('Customer record not found', 'warning');
                return;
            }
            arShowModal(
                `Customer Record - ${arEscape(customer.company_name)}`,
                `
                    <div class="row">
                        <div class="col-md-6 mb-3"><strong>Customer Code:</strong><br>${arEscape(customer.customer_code || 'N/A')}</div>
                        <div class="col-md-6 mb-3"><strong>Contact Person:</strong><br>${arEscape(customer.contact_person || 'N/A')}</div>
                        <div class="col-md-6 mb-3"><strong>Email:</strong><br>${arEscape(customer.email || 'N/A')}</div>
                        <div class="col-md-6 mb-3"><strong>Phone:</strong><br>${arEscape(customer.phone || 'N/A')}</div>
                        <div class="col-md-6 mb-3"><strong>Credit Limit:</strong><br>${arFormatMoney(customer.credit_limit || 0)}</div>
                        <div class="col-md-6 mb-3"><strong>Status:</strong><br>${arEscape(customer.status || 'N/A')}</div>
                    </div>
                `
            );
        }

        loadCustomers = function() {
            const tbody = document.querySelector('#customersTable tbody');
            arMergedCustomers = arMergeRecords(Array.isArray(phpCustomers) ? phpCustomers : [], window.FinancialHQState?.getCustomers?.() || []);

            if (!tbody) {
                return;
            }

            if (!arMergedCustomers.length) {
                tbody.innerHTML = '<tr><td colspan="8" class="text-center text-muted">No customer records available.</td></tr>';
                return;
            }

            tbody.innerHTML = arMergedCustomers.map(customer => {
                const actions = customer.source === 'seed'
                    ? `<button class="btn btn-sm btn-outline-primary" onclick="viewCustomerRecord('${customer.id}')"><i class="fas fa-eye"></i></button>`
                    : `
                        <button class="btn btn-sm btn-outline-primary me-1" onclick="editCustomer(${customer.id})"><i class="fas fa-edit"></i></button>
                        <button class="btn btn-sm btn-outline-danger" onclick="deleteCustomer(${customer.id})"><i class="fas fa-trash"></i></button>
                    `;

                return `
                    <tr>
                        <td>${arEscape(customer.customer_code || '')}</td>
                        <td>${arEscape(customer.company_name || '')}</td>
                        <td>${arEscape(customer.contact_person || '')}</td>
                        <td>${arEscape(customer.email || '')}</td>
                        <td>${arEscape(customer.phone || '')}</td>
                        <td>${arFormatMoney(customer.credit_limit || 0)}</td>
                        <td><span class="badge bg-${customer.status === 'active' ? 'success' : 'secondary'}">${arEscape(customer.status || 'unknown')}</span></td>
                        <td>${actions}</td>
                    </tr>
                `;
            }).join('');

            arUpdateCustomerDropdowns(arMergedCustomers);
        };

        updateCustomerDropdowns = function(customers) {
            arUpdateCustomerDropdowns(customers && customers.length ? customers : arMergedCustomers);
        };

        loadInvoices = async function() {
            const tbody = document.querySelector('#invoicesTable tbody');
            const apiInvoices = await arFetchJsonSafe('../api/invoices.php');
            arMergedInvoices = arMergeRecords(apiInvoices, window.FinancialHQState?.getInvoices?.() || []);

            if (!tbody) {
                return;
            }

            if (!arMergedInvoices.length) {
                tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted">No invoices found.</td></tr>';
                arUpdateReportCards();
                return;
            }

            tbody.innerHTML = arMergedInvoices.map(invoice => {
                const status = String(invoice.status || 'draft').toLowerCase();
                const sendButton = invoice.source === 'seed' && invoice.record_mode !== 'process'
                    ? ''
                    : `<button class="btn btn-sm btn-outline-success me-1" onclick="sendInvoice('${invoice.id}')" title="Send Invoice"><i class="fas fa-paper-plane"></i></button>`;
                const deleteButton = invoice.source === 'seed'
                    ? ''
                    : `<button class="btn btn-sm btn-outline-danger" onclick="deleteInvoice('${invoice.id}')" title="Delete Invoice"><i class="fas fa-trash"></i></button>`;

                return `
                    <tr>
                        <td>${arEscape(invoice.invoice_number || invoice.id)}</td>
                        <td>${arEscape(invoice.customer_name || 'Unknown')}</td>
                        <td>${invoice.invoice_date ? new Date(invoice.invoice_date).toLocaleDateString() : 'N/A'}</td>
                        <td>${invoice.due_date ? new Date(invoice.due_date).toLocaleDateString() : 'N/A'}</td>
                        <td>${arFormatMoney(invoice.total_amount || 0)}</td>
                        <td>
                            <div class="status-indicator ${getStatusDotClass(status)}">
                                <span class="status-dot"></span>
                                <span class="badge bg-${getStatusClass(status)}">${arEscape(status)}</span>
                            </div>
                        </td>
                        <td>
                            <button class="btn btn-sm btn-outline-primary me-1" onclick="viewInvoice('${invoice.id}')" title="View Invoice"><i class="fas fa-eye"></i></button>
                            ${sendButton}
                            ${deleteButton}
                        </td>
                    </tr>
                `;
            }).join('');

            arUpdateReportCards();
        };

        viewInvoice = async function(id) {
            const seedInvoice = arMergedInvoices.find(item => String(item.id) === String(id) && item.source === 'seed');
            if (!seedInvoice) {
                if (originalViewInvoiceFn) {
                    return originalViewInvoiceFn(id);
                }
                return;
            }

            const canSend = seedInvoice.record_mode === 'process' && !['sent', 'paid'].includes(String(seedInvoice.status || '').toLowerCase());
            arShowModal(
                `Invoice Details - ${arEscape(seedInvoice.invoice_number || seedInvoice.id)}`,
                `
                    <div class="row">
                        <div class="col-md-6 mb-3"><strong>Customer</strong><br>${arEscape(seedInvoice.customer_name || 'Unknown')}</div>
                        <div class="col-md-6 mb-3"><strong>Status</strong><br>${arEscape(seedInvoice.status || 'N/A')}</div>
                        <div class="col-md-6 mb-3"><strong>Invoice Date</strong><br>${seedInvoice.invoice_date ? new Date(seedInvoice.invoice_date).toLocaleDateString() : 'N/A'}</div>
                        <div class="col-md-6 mb-3"><strong>Due Date</strong><br>${seedInvoice.due_date ? new Date(seedInvoice.due_date).toLocaleDateString() : 'N/A'}</div>
                        <div class="col-md-6 mb-3"><strong>Total Amount</strong><br>${arFormatMoney(seedInvoice.total_amount || 0)}</div>
                        <div class="col-md-6 mb-3"><strong>Outstanding Balance</strong><br>${arFormatMoney(seedInvoice.balance ?? seedInvoice.total_amount ?? 0)}</div>
                    </div>
                `,
                `
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    ${canSend ? `<button type="button" class="btn btn-success" onclick="sendInvoice('${seedInvoice.id}')"><i class="fas fa-paper-plane me-1"></i>Send Invoice</button>` : ''}
                `
            );
        };

        sendInvoice = async function(id) {
            const seedInvoice = arMergedInvoices.find(item => String(item.id) === String(id) && item.source === 'seed');
            if (!seedInvoice) {
                if (originalSendInvoiceFn) {
                    return originalSendInvoiceFn(id);
                }
                return;
            }

            if (seedInvoice.record_mode !== 'process') {
                showAlert('This invoice is retained as a historical customer record.', 'warning');
                return;
            }

            showConfirmDialog(
                'Send Invoice',
                'Mark this invoice as sent and update the customer record?',
                async () => {
                    window.FinancialHQState?.updateInvoiceStatus?.(id, 'sent');
                    arCloseVisibleModals();
                    showAlert('Invoice sent successfully.', 'success');
                    await loadInvoices();
                }
            );
        };

        deleteInvoice = async function(id) {
            const seedInvoice = arMergedInvoices.find(item => String(item.id) === String(id) && item.source === 'seed');
            if (seedInvoice) {
                showAlert('This invoice is preserved for receivables history.', 'warning');
                return;
            }
            if (originalDeleteInvoiceFn) {
                return originalDeleteInvoiceFn(id);
            }
        };

        loadPayments = async function() {
            const tbody = document.querySelector('#collectionsTable tbody');
            const apiPayments = await arFetchJsonSafe('../api/payments.php?type=received');
            arMergedPayments = arMergeRecords(apiPayments, window.FinancialHQState?.getPaymentsReceived?.() || []);

            if (!tbody) {
                return;
            }

            if (!arMergedPayments.length) {
                tbody.innerHTML = '<tr><td colspan="8" class="text-center text-muted">No collections recorded.</td></tr>';
                arUpdateReportCards();
                return;
            }

            tbody.innerHTML = arMergedPayments.map(payment => {
                const actions = payment.source === 'seed'
                    ? `<button class="btn btn-sm btn-outline-primary" onclick="viewPayment('${payment.id}')"><i class="fas fa-eye"></i></button>`
                    : `
                        <button class="btn btn-sm btn-outline-primary me-1" onclick="viewPayment('${payment.id}')"><i class="fas fa-eye"></i></button>
                        <button class="btn btn-sm btn-outline-danger" onclick="deletePayment('${payment.id}')"><i class="fas fa-trash"></i></button>
                    `;

                return `
                    <tr>
                        <td>${arEscape(payment.payment_number || payment.id)}</td>
                        <td>${arEscape(payment.customer_name || 'Unknown')}</td>
                        <td>${arEscape(payment.invoice_number || 'N/A')}</td>
                        <td>${payment.payment_date ? new Date(payment.payment_date).toLocaleDateString() : 'N/A'}</td>
                        <td>${arFormatMoney(payment.amount || 0)}</td>
                        <td>${arEscape(String(payment.payment_method || 'unknown').replace(/_/g, ' ').toUpperCase())}</td>
                        <td>${arEscape(payment.reference_number || 'N/A')}</td>
                        <td>${actions}</td>
                    </tr>
                `;
            }).join('');

            arUpdateReportCards();
        };

        viewPayment = async function(id) {
            const seedPayment = arMergedPayments.find(item => String(item.id) === String(id) && item.source === 'seed');
            if (!seedPayment) {
                if (originalViewPaymentFn) {
                    return originalViewPaymentFn(id);
                }
                return;
            }

            arShowModal(
                `Collection Details - ${arEscape(seedPayment.payment_number || seedPayment.id)}`,
                `
                    <div class="row">
                        <div class="col-md-6 mb-3"><strong>Customer</strong><br>${arEscape(seedPayment.customer_name || 'Unknown')}</div>
                        <div class="col-md-6 mb-3"><strong>Invoice</strong><br>${arEscape(seedPayment.invoice_number || 'N/A')}</div>
                        <div class="col-md-6 mb-3"><strong>Payment Date</strong><br>${seedPayment.payment_date ? new Date(seedPayment.payment_date).toLocaleDateString() : 'N/A'}</div>
                        <div class="col-md-6 mb-3"><strong>Payment Method</strong><br>${arEscape(String(seedPayment.payment_method || '').replace(/_/g, ' ').toUpperCase())}</div>
                        <div class="col-md-6 mb-3"><strong>Amount</strong><br>${arFormatMoney(seedPayment.amount || 0)}</div>
                        <div class="col-md-6 mb-3"><strong>Reference</strong><br>${arEscape(seedPayment.reference_number || 'N/A')}</div>
                    </div>
                `
            );
        };

        deletePayment = async function(id) {
            const seedPayment = arMergedPayments.find(item => String(item.id) === String(id) && item.source === 'seed');
            if (seedPayment) {
                showAlert('This collection record is preserved for audit continuity.', 'warning');
                return;
            }
            if (originalDeletePaymentFn) {
                return originalDeletePaymentFn(id);
            }
        };

        loadAdjustments = async function() {
            const tbody = document.querySelector('#adjustmentsTable tbody');
            const apiAdjustments = await arFetchJsonSafe('../api/adjustments.php?type=receivable');
            arMergedAdjustments = arMergeRecords(apiAdjustments, window.FinancialHQState?.getAdjustments?.('receivable') || []);

            if (!tbody) {
                return;
            }

            if (!arMergedAdjustments.length) {
                tbody.innerHTML = '<tr><td colspan="8" class="text-center text-muted">No receivable adjustments recorded.</td></tr>';
                return;
            }

            tbody.innerHTML = arMergedAdjustments.map(adjustment => {
                const actions = adjustment.source === 'seed'
                    ? `<button class="btn btn-sm btn-outline-primary" onclick="viewAdjustment('${adjustment.id}')"><i class="fas fa-eye"></i></button>`
                    : `
                        <button class="btn btn-sm btn-outline-primary me-1" onclick="viewAdjustment('${adjustment.id}')"><i class="fas fa-eye"></i></button>
                        <button class="btn btn-sm btn-outline-danger" onclick="deleteAdjustment('${adjustment.id}')"><i class="fas fa-trash"></i></button>
                    `;

                const relatedInvoice = arMergedInvoices.find(item => String(item.id) === String(adjustment.invoice_id));
                return `
                    <tr>
                        <td>${arEscape(adjustment.adjustment_number || adjustment.id)}</td>
                        <td>${arEscape(String(adjustment.adjustment_type || '').replace(/_/g, ' ').toUpperCase())}</td>
                        <td>${arEscape(adjustment.customer_name || 'Unknown')}</td>
                        <td>${arEscape(adjustment.invoice_number || relatedInvoice?.invoice_number || 'N/A')}</td>
                        <td>${arFormatMoney(adjustment.amount || 0)}</td>
                        <td>${arEscape(adjustment.reason || 'N/A')}</td>
                        <td>${adjustment.adjustment_date ? new Date(adjustment.adjustment_date).toLocaleDateString() : 'N/A'}</td>
                        <td>${actions}</td>
                    </tr>
                `;
            }).join('');
        };

        viewAdjustment = async function(id) {
            const seedAdjustment = arMergedAdjustments.find(item => String(item.id) === String(id) && item.source === 'seed');
            if (!seedAdjustment) {
                if (originalViewAdjustmentFn) {
                    return originalViewAdjustmentFn(id);
                }
                return;
            }

            const relatedInvoice = arMergedInvoices.find(item => String(item.id) === String(seedAdjustment.invoice_id));
            arShowModal(
                `Adjustment Details - ${arEscape(seedAdjustment.adjustment_number || seedAdjustment.id)}`,
                `
                    <div class="row">
                        <div class="col-md-6 mb-3"><strong>Customer</strong><br>${arEscape(seedAdjustment.customer_name || 'Unknown')}</div>
                        <div class="col-md-6 mb-3"><strong>Invoice</strong><br>${arEscape(relatedInvoice?.invoice_number || 'N/A')}</div>
                        <div class="col-md-6 mb-3"><strong>Type</strong><br>${arEscape(String(seedAdjustment.adjustment_type || '').replace(/_/g, ' ').toUpperCase())}</div>
                        <div class="col-md-6 mb-3"><strong>Date</strong><br>${seedAdjustment.adjustment_date ? new Date(seedAdjustment.adjustment_date).toLocaleDateString() : 'N/A'}</div>
                        <div class="col-md-12 mb-3"><strong>Reason</strong><br>${arEscape(seedAdjustment.reason || 'N/A')}</div>
                        <div class="col-md-6 mb-3"><strong>Amount</strong><br>${arFormatMoney(seedAdjustment.amount || 0)}</div>
                    </div>
                `
            );
        };

        deleteAdjustment = async function(id) {
            const seedAdjustment = arMergedAdjustments.find(item => String(item.id) === String(id) && item.source === 'seed');
            if (seedAdjustment) {
                showAlert('This adjustment record is preserved for reporting history.', 'warning');
                return;
            }
            if (originalDeleteAdjustmentFn) {
                return originalDeleteAdjustmentFn(id);
            }
        };

        generateAgingReport = async function() {
            const tbody = document.querySelector('#agingTable tbody');
            arCurrentAging = window.FinancialHQState?.getReceivablesAging?.() || [];

            if (!tbody) {
                return;
            }

            if (!arCurrentAging.length) {
                tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted">No aging data available.</td></tr>';
                return;
            }

            tbody.innerHTML = arCurrentAging.map(customer => `
                <tr>
                    <td>${arEscape(customer.customer_name || 'Unknown')}</td>
                    <td>${arFormatMoney(customer.current || 0)}</td>
                    <td>${arFormatMoney(customer.days30 || 0)}</td>
                    <td>${arFormatMoney(customer.days60 || 0)}</td>
                    <td>${arFormatMoney(customer.days90 || 0)}</td>
                    <td>${arFormatMoney(customer.legacy || 0)}</td>
                    <td>${arFormatMoney(customer.total || 0)}</td>
                </tr>
            `).join('');

            showAlert('Aging report generated successfully', 'success');
        };

        loadReportsData = function() {
            arUpdateReportCards();
        };

        exportReport = async function(type) {
            let headers = [];
            let rows = [];
            const filename = `${type}_report_${new Date().toISOString().slice(0, 10)}.csv`;

            if (type === 'receivables') {
                headers = ['Invoice #', 'Customer', 'Customer Code', 'Invoice Date', 'Due Date', 'Amount', 'Balance', 'Status'];
                rows = arMergedInvoices.map(invoice => [
                    invoice.invoice_number || invoice.id,
                    invoice.customer_name || 'Unknown',
                    invoice.customer_code || '',
                    invoice.invoice_date || '',
                    invoice.due_date || '',
                    Number(invoice.total_amount || 0).toFixed(2),
                    Number(invoice.balance ?? invoice.total_amount ?? 0).toFixed(2),
                    invoice.status || ''
                ]);
            } else if (type === 'collections') {
                headers = ['Payment #', 'Customer', 'Invoice #', 'Payment Date', 'Amount', 'Method', 'Reference'];
                rows = arMergedPayments.map(payment => [
                    payment.payment_number || payment.id,
                    payment.customer_name || 'Unknown',
                    payment.invoice_number || 'N/A',
                    payment.payment_date || '',
                    Number(payment.amount || 0).toFixed(2),
                    payment.payment_method || '',
                    payment.reference_number || ''
                ]);
            } else {
                if (!arCurrentAging.length) {
                    arCurrentAging = window.FinancialHQState?.getReceivablesAging?.() || [];
                }
                headers = ['Customer', 'Current', '1-30 Days', '31-60 Days', '61-90 Days', '90+ Days', 'Total'];
                rows = arCurrentAging.map(customer => [
                    customer.customer_name || 'Unknown',
                    Number(customer.current || 0).toFixed(2),
                    Number(customer.days30 || 0).toFixed(2),
                    Number(customer.days60 || 0).toFixed(2),
                    Number(customer.days90 || 0).toFixed(2),
                    Number(customer.legacy || 0).toFixed(2),
                    Number(customer.total || 0).toFixed(2)
                ]);
            }

            if (!rows.length) {
                showAlert(`No data available for ${type} export.`, 'warning');
                return;
            }

            const csv = [
                headers.join(','),
                ...rows.map(row => row.map(value => `"${String(value ?? '').replace(/"/g, '""')}"`).join(','))
            ].join('\n');

            const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            link.download = filename;
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            URL.revokeObjectURL(url);
            showAlert(`${type.charAt(0).toUpperCase() + type.slice(1)} report exported successfully.`, 'success');
        };
    </script><!-- Inactivity Timeout - Blur screen + Auto logout -->
    <script src="../includes/inactivity_timeout.js?v=3"></script>
<script src="../includes/navbar_datetime.js"></script>
<script src="../includes/tab_persistence.js?v=1"></script>
</body>
</html>
    <!-- Inactivity Timeout - Blur screen + Auto logout -->






