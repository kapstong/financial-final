<?php
require_once '../includes/auth.php';
require_once '../includes/database.php';

$auth = new Auth();

if (!isset($_SESSION['user'])) {
    header('Location: ../index.php');
    exit;
}

// Initialize database connection
$db = Database::getInstance()->getConnection();

// Fetch summary data
try {
    // Outstanding bills
    $outstandingBills = $db->query("SELECT COALESCE(SUM(balance), 0) as total FROM bills WHERE status IN ('draft', 'approved', 'overdue')")->fetch()['total'];

    // Overdue amount
    $overdueAmount = $db->query("SELECT COALESCE(SUM(balance), 0) as total FROM bills WHERE status = 'overdue' AND due_date < CURDATE()")->fetch()['total'];

    // Paid this month
    $paidThisMonth = $db->query("SELECT COALESCE(SUM(amount), 0) as total FROM payments_made WHERE YEAR(payment_date) = YEAR(CURDATE()) AND MONTH(payment_date) = MONTH(CURDATE())")->fetch()['total'];

    // Total payables (from bills API data)
    $totalPayablesStmt = $db->query("SELECT COALESCE(SUM(balance), 0) as total FROM bills WHERE status IN ('draft', 'approved')");
    $totalPayables = $totalPayablesStmt->fetch()['total'];

    // Overdue payables
    $overduePayablesStmt = $db->query("SELECT COALESCE(SUM(balance), 0) as total FROM bills WHERE status = 'overdue' AND due_date < CURDATE()");
    $overduePayables = $overduePayablesStmt->fetch()['total'];

    // Average payment period (days to pay bills)
    $avgPaymentPeriodStmt = $db->query("
        SELECT COALESCE(AVG(DATEDIFF(payment_date, bill_date)), 0) as avg_days
        FROM payments_made p
        JOIN bills b ON p.bill_id = b.id
        WHERE p.payment_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
    ");
    $avgPaymentPeriod = round($avgPaymentPeriodStmt->fetch()['avg_days']);

} catch (Exception $e) {
    $outstandingBills = 0;
    $overdueAmount = 0;
    $paidThisMonth = 0;
    $totalPayables = 0;
    $overduePayables = 0;
    $avgPaymentPeriod = 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Financial Management System - Accounts Payable</title>
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
    <?php include '../includes/staff_navigation.php'; ?>

    <div class="content">
        <!-- Top Navbar -->
        <?php include '../includes/global_navbar.php'; ?>

        <!-- Overview Cards -->
        <div class="row mb-4">
            <div class="col-md-4">
                <div class="card text-center">
                    <div class="card-body">
                        <i class="fas fa-file-invoice-dollar fa-2x text-warning mb-2"></i>
                        <h5 class="card-title">Outstanding Bills</h5>
                        <p class="card-text display-6">₱<?php echo number_format($outstandingBills, 2); ?></p>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card text-center">
                    <div class="card-body">
                        <i class="fas fa-clock fa-2x text-danger mb-2"></i>
                        <h5 class="card-title">Overdue</h5>
                        <p class="card-text display-6">₱<?php echo number_format($overdueAmount, 2); ?></p>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card text-center">
                    <div class="card-body">
                        <i class="fas fa-money-bill-wave fa-2x text-success mb-2"></i>
                        <h5 class="card-title">Paid This Month</h5>
                        <p class="card-text display-6">₱<?php echo number_format($paidThisMonth, 2); ?></p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Navigation Tabs -->
        <ul class="nav nav-tabs mb-4" id="apTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="bills-tab" data-bs-toggle="tab" data-bs-target="#bills" type="button" role="tab">
                    <i class="fas fa-file-invoice me-2"></i>Bills Management
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="aging-tab" data-bs-toggle="tab" data-bs-target="#aging" type="button" role="tab">
                    <i class="fas fa-chart-bar me-2"></i>Aging Report
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="payments-tab" data-bs-toggle="tab" data-bs-target="#payments" type="button" role="tab">
                    <i class="fas fa-credit-card me-2"></i>Payments
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="collectibles-tab" data-bs-toggle="tab" data-bs-target="#collectibles" type="button" role="tab">
                    <i class="fas fa-hand-holding-usd me-2"></i>Collectibles
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="adjustments-tab" data-bs-toggle="tab" data-bs-target="#adjustments" type="button" role="tab">
                    <i class="fas fa-balance-scale me-2"></i>Adjustments
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="reports-tab" data-bs-toggle="tab" data-bs-target="#reports" type="button" role="tab">
                    <i class="fas fa-chart-line me-2"></i>Reports
                </button>
            </li>
        </ul>

        <!-- Tab Content -->
        <div class="tab-content" id="apTabContent">
            <!-- Bills Management Tab -->
            <div class="tab-pane fade show active" id="bills" role="tabpanel">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">Bills / Payables Management</h5>
                        <!-- Receive Bills button removed per request -->
                    </div>
                    <div class="card-body">
                        <div class="row mb-3">
                            <div class="col-md-3">
                                <select class="form-select" id="billStatusFilter">
                                    <option value="">All Status</option>
                                    <option value="unpaid">Unpaid</option>
                                    <option value="paid">Paid</option>
                                    <option value="overdue">Overdue</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <input type="date" class="form-control" id="billDateFrom">
                            </div>
                            <div class="col-md-3">
                                <input type="date" class="form-control" id="billDateTo">
                            </div>
                            <div class="col-md-3">
                                <button class="btn btn-secondary" onclick="filterBills()">Filter</button>
                            </div>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-striped" id="billsTable">
                                <thead>
                                    <tr>
                                        <th>Bill #</th>
                                        <th>Vendor</th>
                                        <th>Date</th>
                                        <th>Amount</th>
                                        <th>Due Date</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <!-- Bill data will be loaded here -->
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
                        <h5 class="mb-0">Aging of Accounts Payable</h5>
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
                                        <th>Vendor</th>
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

            <!-- Payments Tab -->
            <div class="tab-pane fade" id="payments" role="tabpanel">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">Payments / Disbursements</h5>
                        <button class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#addPaymentModal">
                            <i class="fas fa-plus me-1"></i>Record Payment
                        </button>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped" id="paymentsTable">
                                <thead>
                                    <tr>
                                        <th>Payment #</th>
                                        <th>Vendor</th>
                                        <th>Amount</th>
                                        <th>Method</th>
                                        <th>Date</th>
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

            <!-- Collectibles Tab -->
            <div class="tab-pane fade" id="collectibles" role="tabpanel">
                <div class="row mb-3">
                    <div class="col-md-6">
                        <div class="card text-center">
                            <div class="card-body">
                                <i class="fas fa-check-circle fa-2x text-success mb-2"></i>
                                <h6 class="card-title">Paid Collectibles</h6>
                                <p class="card-text display-6" id="collectiblesPaidTotal">₱0.00</p>
                                <small class="text-muted" id="collectiblesPaidCount">0 bills</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card text-center">
                            <div class="card-body">
                                <i class="fas fa-hourglass-half fa-2x text-warning mb-2"></i>
                                <h6 class="card-title">Unpaid Collectibles</h6>
                                <p class="card-text display-6" id="collectiblesUnpaidTotal">₱0.00</p>
                                <small class="text-muted" id="collectiblesUnpaidCount">0 bills</small>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">Collectibles (Paid vs Unpaid)</h5>
                        <button class="btn btn-outline-secondary btn-sm" onclick="loadCollectibles()">
                            <i class="fas fa-sync-alt me-1"></i>Refresh
                        </button>
                    </div>
                    <div class="card-body">
                        <div class="row mb-3">
                            <div class="col-md-3">
                                <select class="form-select" id="collectibleStatusFilter">
                                    <option value="">All Status</option>
                                    <option value="paid">Paid</option>
                                    <option value="unpaid">Unpaid</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <input type="date" class="form-control" id="collectibleDateFrom">
                            </div>
                            <div class="col-md-3">
                                <input type="date" class="form-control" id="collectibleDateTo">
                            </div>
                            <div class="col-md-3">
                                <button class="btn btn-secondary" onclick="filterCollectibles()">Filter</button>
                            </div>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-striped" id="collectiblesTable">
                                <thead>
                                    <tr>
                                        <th>Bill #</th>
                                        <th>Vendor</th>
                                        <th>Bill Date</th>
                                        <th>Due Date</th>
                                        <th>Amount</th>
                                        <th>Collectible Status</th>
                                        <th>Bill Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <!-- Collectibles data will be loaded here -->
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
                        <h5 class="mb-0">Adjustments & Debit Memos</h5>
                        <button class="btn btn-warning btn-sm" data-bs-toggle="modal" data-bs-target="#addAdjustmentModal">
                            <i class="fas fa-plus me-1"></i>Add Adjustment
                        </button>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped" id="adjustmentsTable">
                                <thead>
                                    <tr>
                                        <th>Adjustment #</th>
                                        <th>Vendor</th>
                                        <th>Type</th>
                                        <th>Amount</th>
                                        <th>Date</th>
                                        <th>Description</th>
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
                                        <h6>Total Payables</h6>
                                        <h3>₱<?php echo number_format($totalPayables, 2); ?></h3>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="reports-card">
                                    <div class="card-body text-center">
                                        <i class="fas fa-exclamation-triangle fa-2x mb-3 text-warning"></i>
                                        <h6>Overdue Amount</h6>
                                        <h3>₱<?php echo number_format($overduePayables, 2); ?></h3>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="reports-card">
                                    <div class="card-body text-center">
                                        <i class="fas fa-clock fa-2x mb-3 text-info"></i>
                                        <h6>Avg Payment Period</h6>
                                        <h3><?php echo $avgPaymentPeriod; ?> days</h3>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="mt-4 text-center">
                            <button class="btn btn-primary me-3" onclick="exportReport('payables')">
                                <i class="fas fa-download me-2"></i>Export Payables Report
                            </button>
                            <button class="btn btn-secondary me-3" onclick="exportReport('payments')">
                                <i class="fas fa-download me-2"></i>Export Payments Report
                            </button>
                            <button class="btn btn-info" onclick="exportReport('aging')">
                                <i class="fas fa-download me-2"></i>Export Aging Report
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Integration & Security Info -->
        <div class="row mt-4">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h6><i class="fas fa-link me-2"></i>Integration with GL & Other Modules</h6>
                    </div>
                    <div class="card-body">
                        <ul class="list-unstyled">
                            <li><i class="fas fa-check text-success me-2"></i>Every transaction posts to General Ledger</li>
                            <li><i class="fas fa-check text-success me-2"></i>AP balance reflects in GL under Liabilities</li>
                            <li><i class="fas fa-check text-success me-2"></i>Disbursements connect to Cash/Bank accounts</li>
                            <li><i class="fas fa-check text-success me-2"></i>Refunds/collections flow into Cash inflows</li>
                            <li><i class="fas fa-check text-success me-2"></i>Visible in Dashboard summaries & global reports</li>
                        </ul>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h6><i class="fas fa-shield-alt me-2"></i>Security & Audit Trail</h6>
                    </div>
                    <div class="card-body">
                        <ul class="list-unstyled">
                            <li><i class="fas fa-lock text-primary me-2"></i>Tracks who created bills, approved payments</li>
                            <li><i class="fas fa-lock text-primary me-2"></i>Logs refunds and adjustments</li>
                            <li><i class="fas fa-lock text-primary me-2"></i>Ensures accountability and prevents fraud</li>
                            <li><i class="fas fa-lock text-primary me-2"></i>Complete audit trail for compliance</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer -->

    <!-- Receive Bills Modal -->
    <div class="modal fade" id="addBillModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                                <h5 class="modal-title">Add Bill</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                <div class="modal-body">
                    <form id="addBillForm">
                        <div class="mb-3">
                            <label for="billsFile" class="form-label">Upload Bills (CSV/PDF)</label>
                            <input type="file" class="form-control" id="billsFile" name="bills_file" accept=".csv,.pdf">
                            <div class="form-text">Upload a CSV for batch import or PDF attachments from departments. AP will validate and record bills.</div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="billVendor" class="form-label">Vendor *</label>
                                    <select class="form-select" id="billVendor" name="vendor_id" required>
                                        <option value="">Select Vendor</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="billNumber" class="form-label">Bill Number *</label>
                                    <input type="text" class="form-control" id="billNumber" name="bill_number" readonly>
                                    <div class="form-text text-muted">
                                        <i class="fas fa-info-circle me-1"></i>
                                        Bill number will be auto-generated (e.g., BILL-2025-001)
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="billDate" class="form-label">Bill Date *</label>
                                    <input type="date" class="form-control" id="billDate" name="bill_date" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="dueDate" class="form-label">Due Date *</label>
                                    <input type="date" class="form-control" id="dueDate" name="due_date" required>
                                </div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="billAmount" class="form-label">Amount *</label>
                            <input type="number" class="form-control" id="billAmount" name="amount" step="0.01" required>
                        </div>
                        <div class="mb-3">
                            <label for="billDescription" class="form-label">Description</label>
                            <textarea class="form-control" id="billDescription" name="description" rows="3"></textarea>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" form="addBillForm">Receive</button>
                </div>
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
                <div class="modal-body">
                    <form id="addPaymentForm">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="paymentVendor" class="form-label">Vendor *</label>
                                    <select class="form-select" id="paymentVendor" name="vendor_id" required>
                                        <option value="">Select Vendor</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="paymentBill" class="form-label">Bill (Optional)</label>
                                    <select class="form-select" id="paymentBill" name="bill_id">
                                        <option value="">Select Bill</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="paymentDate" class="form-label">Payment Date *</label>
                                    <input type="date" class="form-control" id="paymentDate" name="payment_date" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="paymentAmount" class="form-label">Amount *</label>
                                    <input type="number" class="form-control" id="paymentAmount" name="amount" step="0.01" required>
                                </div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="paymentMethod" class="form-label">Payment Method *</label>
                            <select class="form-select" id="paymentMethod" name="payment_method" required>
                                <option value="check">Check</option>
                                <option value="transfer">Bank Transfer</option>
                                <option value="cash">Cash</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="paymentNotes" class="form-label">Notes</label>
                            <textarea class="form-control" id="paymentNotes" name="notes" rows="3" placeholder="Auto-generated reference number: Will be displayed after submission"></textarea>
                        </div>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>
                            <strong>Reference Number:</strong> Automatically generated based on payment method (CHK/TRF/CSH + Year + Number)
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" form="addPaymentForm">Record Payment</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Adjustment Modal -->
    <div class="modal fade" id="addAdjustmentModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="addAdjustmentModalTitle">Add Adjustment</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="addAdjustmentForm">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="adjustmentVendor" class="form-label">Vendor *</label>
                                    <select class="form-select" id="adjustmentVendor" name="vendor_id" required>
                                        <option value="">Select Vendor</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="adjustmentType" class="form-label">Adjustment Type *</label>
                                    <select class="form-select" id="adjustmentType" name="adjustment_type" required>
                                        <option value="debit_memo">Debit Memo</option>
                                        <option value="credit_memo">Credit Memo</option>
                                        <option value="discount">Discount</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="adjustmentDate" class="form-label">Adjustment Date *</label>
                                    <input type="date" class="form-control" id="adjustmentDate" name="adjustment_date" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="adjustmentAmount" class="form-label">Amount *</label>
                                    <input type="number" class="form-control" id="adjustmentAmount" name="amount" step="0.01" required>
                                </div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="adjustmentDescription" class="form-label">Description *</label>
                            <textarea class="form-control" id="adjustmentDescription" name="description" rows="3" required></textarea>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" form="addAdjustmentForm" id="addAdjustmentModalSubmitBtn">Add Adjustment</button>
                </div>
            </div>
        </div>
    </div>

    <!-- View Adjustment Modal -->
    <div class="modal fade" id="viewAdjustmentModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Adjustment Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label fw-bold">Adjustment Number</label>
                                <p id="view_adjustment_number" class="form-control-plaintext"></p>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label fw-bold">Status</label>
                                <p id="view_adjustment_status" class="form-control-plaintext"></p>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label fw-bold">Vendor</label>
                                <p id="view_vendor_name" class="form-control-plaintext"></p>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label fw-bold">Adjustment Type</label>
                                <p id="view_adjustment_type" class="form-control-plaintext"></p>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label fw-bold">Amount</label>
                                <p id="view_amount" class="form-control-plaintext"></p>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label fw-bold">Date</label>
                                <p id="view_adjustment_date" class="form-control-plaintext"></p>
                            </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Reason</label>
                        <p id="view_reason" class="form-control-plaintext"></p>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Journal Entries</label>
                        <div id="view_journal_entries" class="card">
                            <div class="card-body">
                                <small class="text-muted">Journal entries automatically created for this adjustment.</small>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-arrow-left me-2"></i>Back
                    </button>
                    <button type="button" class="btn btn-primary" id="editAdjustmentBtn">
                        <i class="fas fa-edit me-2"></i>Edit
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script src="../includes/document_exporter.js?v=1"></script>
    <script>
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

        // Set default active tab
            currentTab = 'bills';

            // Set up event listeners
            setupEventListeners();

            // Set default date
            const today = new Date().toISOString().split('T')[0];
            document.getElementById('paymentDate').value = today;

            // Load initial data
            loadVendors();
            loadBills();
            loadPayments();
            loadCollectibles();
            loadAdjustments();
        });

        // Handle bill form submission
        document.getElementById('addBillForm').addEventListener('submit', async function(e) {
            e.preventDefault();

            // Check if we're in edit mode BEFORE accessing form data
            const isEditMode = !!this.dataset.editBillId;
            const editBillId = this.dataset.editBillId;

            // Ensure bill number is captured even for readonly fields
            const billNumber = document.getElementById('billNumber').value?.trim();
            const vendorId = document.getElementById('billVendor').value?.trim();
            const billDate = document.getElementById('billDate').value?.trim();
            const dueDate = document.getElementById('dueDate').value?.trim();
            const amount = document.getElementById('billAmount').value?.trim();
            const description = document.getElementById('billDescription').value?.trim();

            if (!vendorId || !billDate || !dueDate || !amount) {
                showAlert('Please fill in all required fields', 'danger');
                return;
            }

            if (isEditMode && !billNumber) {
                showAlert('Bill number is required for editing', 'danger');
                return;
            }

            let billData = {
                vendor_id: parseInt(vendorId),
                bill_date: billDate,
                due_date: dueDate,
                amount: parseFloat(amount),
                description: description || '',
                bill_number: billNumber
            };

            // Add status for new bills only
            if (!isEditMode) {
                billData.status = 'draft';
            }

            const method = isEditMode ? 'PUT' : 'POST';
            const apiUrl = isEditMode ? `../api/bills.php?id=${editBillId}` : '../api/bills.php';

            try {
                const response = await fetch(apiUrl, {
                    method: method,
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify(billData)
                });

                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                }

                const result = await response.json();

                const hasSuccess = result.success !== undefined && result.success !== null;

                if (hasSuccess) {
                    const action = isEditMode ? 'updated' : 'created';
                    const billPreview = result.bill_number || billNumber || 'Unknown';
                    showAlert(`Bill ${action} successfully! Bill #: ${billPreview}`, 'success');

                    const modalEl = document.getElementById('addBillModal');
                    if (modalEl) {
                        const modal = bootstrap.Modal.getInstance(modalEl);
                        if (modal) modal.hide();
                    }

                    // Reset form back to add mode
                    resetBillForm();
                    this.reset();

                    // Pre-populate bill number for next use
                    await prePopulateBillNumber();

                    loadBills(); // Refresh the bills table
                } else {
                    throw new Error(result.error || `Failed to ${isEditMode ? 'update' : 'create'} bill`);
                }
            } catch (error) {
                console.error(`Error ${isEditMode ? 'updating' : 'creating'} bill:`, error);
                showAlert(`Error ${isEditMode ? 'updating' : 'creating'} bill: ` + error.message, 'danger');
            }
        });

        // Handle payment form submission
        document.getElementById('addPaymentForm').addEventListener('submit', async function(e) {
            e.preventDefault();

            const formData = new FormData(this);
            const paymentData = {
                payment_type: 'made', // This is for payments made to vendors
                vendor_id: formData.get('vendor_id'),
                bill_id: formData.get('bill_id') || null,
                payment_date: formData.get('payment_date'),
                amount: parseFloat(formData.get('amount')),
                payment_method: formData.get('payment_method'),
                notes: formData.get('notes') || null
            };

            // Auto-generate reference number if not provided
            const referenceNumber = generateReferenceNumber(paymentData.payment_method);
            paymentData.reference_number = referenceNumber;

            try {
                const response = await fetch('../api/payments.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify(paymentData)
                });

                const data = await response.json();

                if (data.success) {
                    showAlert('Payment recorded successfully! Payment #: ' + data.payment_number, 'success');
                    const modalEl = document.getElementById('addPaymentModal');
                    if (modalEl) {
                        const modal = bootstrap.Modal.getInstance(modalEl);
                        if (modal) modal.hide();
                    }
                    this.reset();

                    // Refresh payments table
                    if (currentTab === 'payments') {
                        loadPayments();
                    }

                    // Refresh bills table to show updated balances
                    if (currentTab === 'bills') {
                        loadBills();
                    }

                } else {
                    throw new Error(data.error || 'Failed to record payment');
                }
            } catch (error) {
                console.error('Error recording payment:', error);
                showAlert('Error recording payment: ' + error.message, 'danger');
            }
        });

        document.getElementById('addAdjustmentForm').addEventListener('submit', async function(e) {
            e.preventDefault();

            const formData = new FormData(this);
            const description = formData.get('description') || '';

            // Check if we're in edit mode (has adjustmentId)
            const isEditMode = this.dataset.adjustmentId;

            const adjustmentData = {
                vendor_id: parseInt(formData.get('vendor_id')),
                adjustment_type: formData.get('adjustment_type'),
                adjustment_date: formData.get('adjustment_date'),
                amount: parseFloat(formData.get('amount')),
                reason: description,
                description: description
            };

            const apiUrl = isEditMode ? `../api/adjustments.php?id=${this.dataset.adjustmentId}` : '../api/adjustments.php';
            const method = isEditMode ? 'PUT' : 'POST';

            try {
                const response = await fetch(apiUrl, {
                    method: method,
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify(adjustmentData)
                });

                const result = await response.json();

                if (result.success) {
                    const action = isEditMode ? 'updated' : 'created';
                    const message = isEditMode ?
                        'Adjustment updated successfully' :
                        `Adjustment created successfully! Adjustment #: ${result.adjustment_number}`;

                    showAlert(message, 'success');
                    const modalEl = document.getElementById('addAdjustmentModal');
                    if (modalEl) {
                        const modal = bootstrap.Modal.getInstance(modalEl);
                        if (modal) modal.hide();
                    }
                    this.reset();
                    loadAdjustments(); // Refresh the adjustments table

                    // Reset form back to add mode
                    if (isEditMode) {
                        resetAdjustmentForm();
                    }
                } else {
                    throw new Error(result.error || `Failed to ${isEditMode ? 'update' : 'create'} adjustment`);
                }
            } catch (error) {
                console.error(`Error ${isEditMode ? 'updating' : 'creating'} adjustment:`, error);
                showAlert(`Error ${isEditMode ? 'updating' : 'creating'} adjustment: ` + error.message, 'danger');
            }
        });

        // Load vendors
        async function loadVendors() {
            try {
                const response = await fetch('../api/vendors.php');
                const data = await response.json();

                if (data.error) {
                    throw new Error(data.error);
                }

                populateVendorDropdowns(data);
                // Update localStorage with last update timestamp for cross-module sync
                localStorage.setItem('vendorsLastUpdate', Date.now());
            } catch (error) {
                console.error('Error loading vendors:', error);
                showAlert('Error loading vendors: ' + error.message, 'danger');
            }
        }

        // Load bills
        async function loadBills() {
            try {
                const response = await fetch('../api/bills.php');
                const data = await response.json();

                if (data.error) {
                    throw new Error(data.error);
                }

                renderBillsTable(data);
            } catch (error) {
                console.error('Error loading bills:', error);
                showAlert('Error loading bills: ' + error.message, 'danger');
            }
        }

        // Render bills table
        function renderBillsTable(bills) {
            const tbody = document.querySelector('#billsTable tbody');
            tbody.innerHTML = '';

            if (!bills || bills.length === 0) {
                tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted">No bills found</td></tr>';
                return;
            }

            bills.forEach(bill => {
                // Determine status badge color
                let statusBadge;
                let badgeColor;
                switch(bill.status ? bill.status.toLowerCase() : 'draft') {
                    case 'draft':
                        badgeColor = 'secondary';
                        break;
                    case 'approved':
                        badgeColor = 'warning';
                        break;
                    case 'paid':
                        badgeColor = 'success';
                        break;
                    case 'overdue':
                        badgeColor = 'danger';
                        break;
                    default:
                        badgeColor = 'secondary';
                }

                const statusFormatted = bill.status ? bill.status.charAt(0).toUpperCase() + bill.status.slice(1) : 'Draft';
                statusBadge = `<span class="badge bg-${badgeColor}">${statusFormatted}</span>`;

                // Format dates
                const billDate = bill.bill_date ? new Date(bill.bill_date).toLocaleDateString() : 'N/A';
                const dueDate = bill.due_date ? new Date(bill.due_date).toLocaleDateString() : 'N/A';

                // Format amounts
                const amount = parseFloat(bill.amount || bill.total_amount || 0);

                const row = `
                    <tr>
                        <td>${bill.bill_number || bill.id}</td>
                        <td>${bill.vendor_name || 'Unknown Vendor'}</td>
                        <td>${billDate}</td>
                        <td>₱${amount.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}</td>
                        <td>${dueDate}</td>
                        <td>${statusBadge}</td>
                        <td>
                            <button class="btn btn-sm btn-outline-info" onclick="viewBill(${bill.id})" title="View Details">
                                <i class="fas fa-eye"></i>
                            </button>
                            <button class="btn btn-sm btn-outline-danger" onclick="deleteBill(${bill.id})" title="Delete">
                                <i class="fas fa-trash"></i>
                            </button>
                        </td>
                    </tr>
                `;
                tbody.innerHTML += row;
            });
        }

        // Load payments made to vendors
        async function loadPayments() {
            try {
                const response = await fetch('../api/payments.php?type=made');
                const data = await response.json();

                // Filter out collection entries (those with COLL- reference)
                const payments = data.filter(payment =>
                    !payment.reference_number || !payment.reference_number.startsWith('COLL-')
                );

                renderPaymentsTable(payments);
            } catch (error) {
                console.error('Error loading payments:', error);
                showAlert('Error loading payments: ' + error.message, 'danger');
            }
        }

        // Render payments table
        function renderPaymentsTable(payments) {
            const tbody = document.querySelector('#paymentsTable tbody');
            tbody.innerHTML = '';

            if (!payments || payments.length === 0) {
                tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted">No payments recorded</td></tr>';
                return;
            }

            payments.forEach(payment => {
                // Format payment method display
                const methodDisplay = payment.payment_method ? payment.payment_method.replace('_', ' ').toUpperCase() : 'Unknown';

                const row = `
                    <tr>
                        <td>${payment.payment_number || payment.reference_number}</td>
                        <td>${payment.vendor_name || 'Unknown Vendor'}</td>
                        <td>₱${parseFloat(payment.amount).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}</td>
                        <td><span class="badge bg-info">${methodDisplay}</span></td>
                        <td>${new Date(payment.payment_date).toLocaleDateString()}</td>
                        <td>${payment.reference_number || 'N/A'}</td>
                        <td>
                            <button class="btn btn-sm btn-outline-info" onclick="viewPayment(${payment.id})" title="View Details">
                                <i class="fas fa-eye"></i>
                            </button>
                            <button class="btn btn-sm btn-outline-danger" onclick="deletePayment(${payment.id})" title="Delete">
                                <i class="fas fa-trash"></i>
                            </button>
                        </td>
                    </tr>
                `;
                tbody.innerHTML += row;
            });
        }

        let collectiblesData = [];

        const CURRENCY_SYMBOL = '<?php echo "₱"; ?>';
        const PRIVACY_VISIBLE = true;

        function getCollectibleStatus(bill) {
            const status = (bill.status || '').toLowerCase();
            return status == 'paid' ? 'paid' : 'unpaid';
        }

        function getBillAmountValue(bill) {
            return parseFloat(bill.total_amount ?? bill.amount ?? 0);
        }

        function updateCollectiblesSummary(bills) {
            let paidTotal = 0;
            let unpaidTotal = 0;
            let paidCount = 0;
            let unpaidCount = 0;

            bills.forEach(bill => {
                const amount = getBillAmountValue(bill);
                if (getCollectibleStatus(bill) == 'paid') {
                    paidTotal += amount;
                    paidCount += 1;
                } else {
                    unpaidTotal += amount;
                    unpaidCount += 1;
                }
            });

            const paidTotalEl = document.getElementById('collectiblesPaidTotal');
            const unpaidTotalEl = document.getElementById('collectiblesUnpaidTotal');
            const paidCountEl = document.getElementById('collectiblesPaidCount');
            const unpaidCountEl = document.getElementById('collectiblesUnpaidCount');

            if (paidTotalEl) {
                if (PRIVACY_VISIBLE) {
                    paidTotalEl.textContent = `${CURRENCY_SYMBOL}${paidTotal.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
                } else {
                    paidTotalEl.textContent = `${CURRENCY_SYMBOL} ${'*'.repeat(8)}`;
                }
            }
            if (unpaidTotalEl) {
                if (PRIVACY_VISIBLE) {
                    unpaidTotalEl.textContent = `${CURRENCY_SYMBOL}${unpaidTotal.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
                } else {
                    unpaidTotalEl.textContent = `${CURRENCY_SYMBOL} ${'*'.repeat(8)}`;
                }
            }
            if (paidCountEl) {
                paidCountEl.textContent = `${paidCount} bills`;
            }
            if (unpaidCountEl) {
                unpaidCountEl.textContent = `${unpaidCount} bills`;
            }
        }

        async function loadCollectibles() {
            try {
                const response = await fetch('../api/bills.php');
                const data = await response.json();

                if (data.error) {
                    throw new Error(data.error);
                }

                collectiblesData = Array.isArray(data) ? data : [];
                updateCollectiblesSummary(collectiblesData);
                renderCollectiblesTable(collectiblesData);
            } catch (error) {
                console.error('Error loading collectibles:', error);
                showAlert('Error loading collectibles: ' + error.message, 'danger');
            }
        }

        function filterCollectibles() {
            renderCollectiblesTable(collectiblesData);
        }

        function renderCollectiblesTable(bills) {
            const tbody = document.querySelector('#collectiblesTable tbody');
            if (!tbody) {
                return;
            }
            tbody.innerHTML = '';

            const statusFilter = document.getElementById('collectibleStatusFilter')?.value || '';
            const dateFrom = document.getElementById('collectibleDateFrom')?.value;
            const dateTo = document.getElementById('collectibleDateTo')?.value;
            const fromDate = dateFrom ? new Date(dateFrom) : null;
            const toDate = dateTo ? new Date(dateTo) : null;

            const filtered = (bills || []).filter(bill => {
                const collectibleStatus = getCollectibleStatus(bill);
                if (statusFilter && collectibleStatus != statusFilter) {
                    return false;
                }
                if (bill.bill_date) {
                    const billDate = new Date(bill.bill_date);
                    if (fromDate && billDate < fromDate) {
                        return false;
                    }
                    if (toDate && billDate > toDate) {
                        return false;
                    }
                } else if (fromDate || toDate) {
                    return false;
                }
                return true;
            });

            if (!filtered.length) {
                tbody.innerHTML = '<tr><td colspan="8" class="text-center text-muted">No collectibles found</td></tr>';
                return;
            }

            filtered.forEach(bill => {
                const amount = getBillAmountValue(bill);
                const collectibleStatus = getCollectibleStatus(bill);
                const statusBadge = collectibleStatus == 'paid'
                    ? '<span class="badge bg-success">Paid</span>'
                    : '<span class="badge bg-warning text-dark">Unpaid</span>';
                const billStatus = bill.status ? bill.status.charAt(0).toUpperCase() + bill.status.slice(1) : 'Draft';

                const amountDisplay = PRIVACY_VISIBLE
                    ? `${CURRENCY_SYMBOL}${amount.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}`
                    : `${CURRENCY_SYMBOL} ${'*'.repeat(8)}`;

                const row = `
                    <tr>
                        <td>${bill.bill_number || bill.id}</td>
                        <td>${bill.vendor_name || 'Unknown Vendor'}</td>
                        <td>${bill.bill_date ? new Date(bill.bill_date).toLocaleDateString() : 'N/A'}</td>
                        <td>${bill.due_date ? new Date(bill.due_date).toLocaleDateString() : 'N/A'}</td>
                        <td>${amountDisplay}</td>
                        <td>${statusBadge}</td>
                        <td>${billStatus}</td>
                        <td>
                            <button class="btn btn-sm btn-outline-info" onclick="viewBill(${bill.id})" title="View Details">
                                <i class="fas fa-eye"></i>
                            </button>
                        </td>
                    </tr>
                `;
                tbody.innerHTML += row;
            });
        }

        // Load adjustments from adjustments API (payable type only)
 (payable type only)
        async function loadAdjustments() {
            try {
                const response = await fetch('../api/adjustments.php?type=payable');
                const data = await response.json();

                renderAdjustmentsTable(data);
            } catch (error) {
                console.error('Error loading adjustments:', error);
                showAlert('Error loading adjustments: ' + error.message, 'danger');
            }
        }

        // Render adjustments table
        function renderAdjustmentsTable(adjustments) {
            const tbody = document.querySelector('#adjustmentsTable tbody');
            tbody.innerHTML = '';

            if (!adjustments || adjustments.length === 0) {
                tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted">No adjustments recorded</td></tr>';
                return;
            }

            adjustments.forEach(adjustment => {
                // Format adjustment type for display
                const typeDisplay = adjustment.adjustment_type.replace('_', ' ').toUpperCase();
                const typeBadgeColors = {
                    'CREDIT_MEMO': 'success',
                    'DEBIT_MEMO': 'warning',
                    'DISCOUNT': 'info',
                    'WRITE_OFF': 'danger',
                    'default': 'secondary'
                };

                const badgeColor = typeBadgeColors[adjustment.adjustment_type.toUpperCase()] || 'secondary';

                const row = `
                    <tr>
                        <td>${adjustment.adjustment_number}</td>
                        <td>${adjustment.vendor_name || 'Unknown Vendor'}</td>
                        <td><span class="badge bg-${badgeColor}">${typeDisplay}</span></td>
                        <td>₱${parseFloat(adjustment.amount).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}</td>
                        <td>${new Date(adjustment.adjustment_date).toLocaleDateString()}</td>
                        <td>${adjustment.reason || 'N/A'}</td>
                        <td>
                            <button class="btn btn-sm btn-outline-info" onclick="viewAdjustment(${adjustment.id})" title="View Details">
                                <i class="fas fa-eye"></i>
                            </button>
                            <button class="btn btn-sm btn-outline-danger" onclick="deleteAdjustment(${adjustment.id})" title="Delete">
                                <i class="fas fa-trash"></i>
                            </button>
                        </td>
                    </tr>
                `;
                tbody.innerHTML += row;
            });
        }

        // Populate vendor dropdowns in forms
        function populateVendorDropdowns(vendors) {
            const dropdowns = [
                'billVendor', 'paymentVendor', 'adjustmentVendor'
            ];

            dropdowns.forEach(dropdownId => {
                const select = document.getElementById(dropdownId);
                if (select) {
                    select.innerHTML = '<option value="">Select Vendor</option>';
                    vendors.forEach(vendor => {
                        select.innerHTML += `<option value="${vendor.id}">${vendor.company_name}</option>`;
                    });
                }
            });
        }

        // Filter bills
        function filterBills() {
            let apiUrl = '../api/bills.php';
            const params = [];

            // Get status filter
            const statusSelect = document.getElementById('billStatusFilter');
            const status = statusSelect ? statusSelect.value : '';
            if (status && status !== 'all') {
                // Map frontend values to backend values
                let statusValue = status;
                switch(status) {
                    case 'unpaid':
                        statusValue = 'draft,approved,overdue';
                        break;
                    case 'paid':
                        statusValue = 'paid';
                        break;
                }
                params.push(`status=${encodeURIComponent(statusValue)}`);
            }

            // Get date filters
            const dateFrom = document.getElementById('billDateFrom')?.value;
            const dateTo = document.getElementById('billDateTo')?.value;

            if (dateFrom) {
                params.push(`date_from=${encodeURIComponent(dateFrom)}`);
            }
            if (dateTo) {
                params.push(`date_to=${encodeURIComponent(dateTo)}`);
            }

            // Build API URL
            if (params.length > 0) {
                apiUrl += '?' + params.join('&');
            }

            // Fetch filtered bills
            fetch(apiUrl)
                .then(response => response.json())
                .then(data => {
                    if (data.error) {
                        throw new Error(data.error);
                    }
                    renderBillsTable(data);
                    showAlert(`Filtered ${data.length} bills`, 'success');
                })
                .catch(error => {
                    console.error('Error filtering bills:', error);
                    showAlert('Error filtering bills: ' + error.message, 'danger');
                });
        }

        // Generate aging report
        async function generateAgingReport() {
            try {
                // Get the selected period from dropdown
                const periodSelect = document.getElementById('agingPeriod');
                const selectedPeriod = periodSelect ? periodSelect.value : '30';

                // Get all bills with their aging status
                const response = await fetch('../api/bills.php?action=aging&period=' + selectedPeriod);
                const data = await response.json();

                if (data.error) {
                    throw new Error(data.error);
                }

                // Update the aging table with the data
                renderAgingReport(data);

                showAlert('Aging report generated successfully', 'success');

            } catch (error) {
                console.error('Error generating aging report:', error);
                showAlert('Error generating aging report: ' + error.message, 'danger');
            }
        }

        // Render aging report table
        function renderAgingReport(data) {
            const tbody = document.querySelector('#agingTable tbody');
            tbody.innerHTML = '';

            if (!data || data.length === 0) {
                tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted">No aging data found</td></tr>';
                return;
            }

            // Group by vendor
            const vendorGroups = {};
            data.forEach(item => {
                if (!vendorGroups[item.vendor_name]) {
                    vendorGroups[item.vendor_name] = {
                        current: 0,
                        days_1_30: 0,
                        days_31_60: 0,
                        days_61_90: 0,
                        days_90_plus: 0,
                        total: 0
                    };
                }

                // Add to appropriate aging bucket and total
                if (item.aging_bucket === 'current') {
                    vendorGroups[item.vendor_name].current += parseFloat(item.balance);
                } else if (item.aging_bucket === '1-30') {
                    vendorGroups[item.vendor_name].days_1_30 += parseFloat(item.balance);
                } else if (item.aging_bucket === '31-60') {
                    vendorGroups[item.vendor_name].days_31_60 += parseFloat(item.balance);
                } else if (item.aging_bucket === '61-90') {
                    vendorGroups[item.vendor_name].days_61_90 += parseFloat(item.balance);
                } else if (item.aging_bucket === '90+') {
                    vendorGroups[item.vendor_name].days_90_plus += parseFloat(item.balance);
                }

                vendorGroups[item.vendor_name].total += parseFloat(item.balance);
            });

            // Calculate totals across all vendors
            let grandTotals = {
                current: 0,
                days_1_30: 0,
                days_31_60: 0,
                days_61_90: 0,
                days_90_plus: 0,
                total: 0
            };

            // Render each vendor row
            Object.keys(vendorGroups).forEach(vendorName => {
                const vendor = vendorGroups[vendorName];

                // Update grand totals
                grandTotals.current += vendor.current;
                grandTotals.days_1_30 += vendor.days_1_30;
                grandTotals.days_31_60 += vendor.days_31_60;
                grandTotals.days_61_90 += vendor.days_61_90;
                grandTotals.days_90_plus += vendor.days_90_plus;
                grandTotals.total += vendor.total;

                const row = document.createElement('tr');
                row.innerHTML = `
                    <td><strong>${vendorName}</strong></td>
                    <td>₱${vendor.current.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}</td>
                    <td>₱${vendor.days_1_30.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}</td>
                    <td>₱${vendor.days_31_60.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}</td>
                    <td>₱${vendor.days_61_90.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}</td>
                    <td>₱${vendor.days_90_plus.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}</td>
                    <td><strong>₱${vendor.total.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}</strong></td>
                `;
                tbody.appendChild(row);
            });

            // Add totals row
            const totalsRow = document.createElement('tr');
            totalsRow.className = 'table-primary';
            totalsRow.innerHTML = `
                <td><strong>TOTAL</strong></td>
                <td><strong>₱${grandTotals.current.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}</strong></td>
                <td><strong>₱${grandTotals.days_1_30.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}</strong></td>
                <td><strong>₱${grandTotals.days_31_60.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}</strong></td>
                <td><strong>₱${grandTotals.days_61_90.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}</strong></td>
                <td><strong>₱${grandTotals.days_90_plus.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}</strong></td>
                <td><strong class="text-danger">₱${grandTotals.total.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}</strong></td>
            `;
            tbody.appendChild(totalsRow);
        }

        // Export report
        async function exportReport(type) {
            try {
                let apiUrl = '';
                let filename = '';

                switch(type) {
                    case 'payables':
                        apiUrl = '../api/bills.php';
                        filename = `payables_report_${new Date().toISOString().split('T')[0]}.xls`;
                        break;
                    case 'payments':
                        apiUrl = '../api/payments.php?type=made';
                        filename = `payments_report_${new Date().toISOString().split('T')[0]}.xls`;
                        break;
                    case 'aging':
                        // Get aging data with 120+ days period to get all data
                        apiUrl = '../api/bills.php?action=aging&period=120';
                        filename = `aging_report_${new Date().toISOString().split('T')[0]}.xls`;
                        break;
                    default:
                        throw new Error('Unknown report type');
                }

                const response = await fetch(apiUrl);
                const data = await response.json();

                if (data.error) {
                    throw new Error(data.error);
                }

                // Filter out collection entries from payments report
                let exportData = data;
                if (type === 'payments') {
                    exportData = data.filter(payment =>
                        !payment.reference_number || !payment.reference_number.startsWith('COLL-')
                    );
                }

                if (!exportData || exportData.length === 0) {
                    throw new Error('No data available for export');
                }

                // Generate CSV content
                const csvContent = generateCSV(type, exportData);

                // Download CSV
                downloadCSV(csvContent, filename);

                showAlert(`Report exported successfully (${exportData.length} records)`, 'success');

            } catch (error) {
                console.error('Error exporting report:', error);
                showAlert('Error exporting report: ' + error.message, 'danger');
            }
        }

        // Generate CSV content from data
        function generateCSV(type, data) {
            let headers = [];
            let rows = [];

            switch(type) {
                case 'payables':
                    headers = ['Bill Number', 'Vendor', 'Bill Date', 'Due Date', 'Amount', 'Balance', 'Status', 'Vendor Code'];
                    rows = data.map(item => [
                        item.bill_number || item.id,
                        item.vendor_name || 'Unknown',
                        item.bill_date || '',
                        item.due_date || '',
                        parseFloat(item.amount || item.total_amount || 0).toFixed(2),
                        parseFloat(item.balance || 0).toFixed(2),
                        item.status || 'Draft',
                        item.vendor_code || ''
                    ]);
                    break;

                case 'payments':
                    headers = ['Payment Number', 'Vendor', 'Amount', 'Payment Method', 'Payment Date', 'Reference Number', 'Bill Number', 'Notes'];
                    rows = data.map(item => [
                        item.payment_number || item.reference_number,
                        item.vendor_name || 'Unknown',
                        parseFloat(item.amount || 0).toFixed(2),
                        item.payment_method ? item.payment_method.replace('_', ' ').toUpperCase() : 'Unknown',
                        item.payment_date || '',
                        item.reference_number || '',
                        item.bill_number || 'N/A',
                        item.notes || ''
                    ]);
                    break;

                case 'aging':
                    headers = ['Bill Number', 'Vendor', 'Bill Date', 'Due Date', 'Balance', 'Days Past Due', 'Aging Bucket', 'Status'];
                    rows = data.map(item => [
                        item.bill_number || item.id,
                        item.vendor_name || 'Unknown',
                        item.bill_date || '',
                        item.due_date || '',
                        parseFloat(item.balance || 0).toFixed(2),
                        item.days_past_due || 0,
                        item.aging_bucket || 'Unknown',
                        item.status || 'Draft'
                    ]);
                    break;
            }

            // Create CSV content
            let csv = headers.join(',') + '\n';
            rows.forEach(row => {
                const escapedRow = row.map(field =>
                    '"' + String(field || '').replace(/"/g, '""') + '"'
                );
                csv += escapedRow.join(',') + '\n';
            });

            return csv;
        }

        // Download formatted workbook
        function downloadCSV(content, filename) {
            const title = filename.includes('aging') ? 'Accounts Payable Aging Report'
                : filename.includes('payments') ? 'Payments Made Report'
                : 'Accounts Payable Report';
            DocumentExporter.downloadTextReport(content, filename, title);
        }

        // Utility functions
        function getStatusBadge(status) {
            const badges = {
                'active': '<span class="badge bg-success">Active</span>',
                'inactive': '<span class="badge bg-secondary">Inactive</span>',
                'suspended': '<span class="badge bg-warning">Suspended</span>'
            };
            return badges[status] || '<span class="badge bg-secondary">Unknown</span>';
        }

        function formatAccountId(accountId) {
            return accountId || '2000-AP';
        }

        // Auto-generate bill number
        async function generateNextBillNumber() {
            try {
                // Get the next bill number from the API
                const response = await fetch('../api/bills.php?action=next_number');
                const data = await response.json();

                if (data.success && data.next_number) {
                    return data.next_number; // Return the generated number (e.g., "BILL-2025-001")
                } else {
                    // Fallback numbering if API fails
                    const currentYear = new Date().getFullYear();
                    return `BILL-${currentYear}-001`;
                }
            } catch (error) {
                console.error('Error generating bill number:', error);
                // Fallback numbering
                const currentYear = new Date().getFullYear();
                return `BILL-${currentYear}-001`;
            }
        }

        // Pre-populate bill number in the form (called when modal opens)
        async function prePopulateBillNumber() {
            try {
                const billNumberInput = document.getElementById('billNumber');
                if (billNumberInput && !billNumberInput.dataset.manualEntry) {
                    billNumberInput.value = await generateNextBillNumber();
                }
            } catch (error) {
                console.error('Error pre-populating bill number:', error);
            }
        }

        // Auto-generate reference number based on payment method
        function generateReferenceNumber(paymentMethod) {
            const timestamp = Date.now();
            const randomNum = Math.floor(Math.random() * 1000).toString().padStart(3, '0');

            switch(paymentMethod) {
                case 'check':
                    return `CHK-${new Date().getFullYear()}-${randomNum}`;
                case 'bank_transfer':
                    return `TRF-${new Date().getFullYear()}-${randomNum}`;
                case 'cash':
                    return `CSH-${new Date().getFullYear()}-${randomNum}`;
                default:
                    return `PAY-${new Date().getFullYear()}-${randomNum}`;
            }
        }

        // Set up event listeners
        function setupEventListeners() {
            // Handle tab changes to track current tab
            const tabs = document.querySelectorAll('#apTabs button[data-bs-toggle="tab"]');
            tabs.forEach(tab => {
                tab.addEventListener('click', function() {
                    const targetId = this.getAttribute('data-bs-target').replace('#', '');
                    currentTab = targetId;
                });
            });

            // Handle vendor selection for bills in payment form
            document.getElementById('paymentVendor').addEventListener('change', function() {
                const vendorId = this.value;
                if (vendorId) {
                    loadBillsForVendor(vendorId);
                } else {
                    document.getElementById('paymentBill').innerHTML = '<option value="">Select Bill</option>';
                }
            });
        }

        // Load bills for a specific vendor (for payment dropdown)
        async function loadBillsForVendor(vendorId) {
            try {
                const response = await fetch(`../api/bills.php?vendor_id=${vendorId}&status=draft,approved,overdue`);
                const data = await response.json();

                if (data.error) {
                    throw new Error(data.error);
                }

                const billSelect = document.getElementById('paymentBill');
                billSelect.innerHTML = '<option value="">Select Bill (Optional)</option>';

                if (data && data.length > 0) {
                    data.forEach(bill => {
                        const balanceText = parseFloat(bill.balance) > 0 ? ` (Balance: ₱${parseFloat(bill.balance).toLocaleString()})` : '';
                        billSelect.innerHTML += `<option value="${bill.id}">Bill ${bill.bill_number} - ₱${parseFloat(bill.total_amount).toLocaleString()}${balanceText}</option>`;
                    });
                } else {
                    billSelect.innerHTML += '<option disabled>No unpaid bills for this vendor</option>';
                }

            } catch (error) {
                console.error('Error loading bills for vendor:', error);
                document.getElementById('paymentBill').innerHTML = '<option value="">Error loading bills</option>';
            }
        }

        // View bill details
        async function viewBill(billId) {
            try {
                const response = await fetch(`../api/bills.php?id=${billId}`);
                const data = await response.json();

                if (data.error) {
                    throw new Error(data.error);
                }

                let bill = data;
                if (Array.isArray(data)) {
                    bill = data[0];
                }

                if (!bill || !bill.id) {
                    throw new Error('Bill not found');
                }

                // Create a view bill modal content
                const modalContent = `
                    <div class="modal-dialog modal-lg">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title">Bill Details - ${bill.bill_number}</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label fw-bold">Bill Number</label>
                                            <p class="form-control-plaintext">${bill.bill_number}</p>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label fw-bold">Vendor</label>
                                            <p class="form-control-plaintext">${bill.vendor_name || 'Unknown Vendor'}</p>
                                        </div>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label fw-bold">Bill Date</label>
                                            <p class="form-control-plaintext">${new Date(bill.bill_date).toLocaleDateString()}</p>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label fw-bold">Due Date</label>
                                            <p class="form-control-plaintext">${new Date(bill.due_date).toLocaleDateString()}</p>
                                        </div>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label fw-bold">Amount</label>
                                            <p class="form-control-plaintext">₱${parseFloat(bill.amount).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}</p>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label fw-bold">Balance</label>
                                            <p class="form-control-plaintext">₱${parseFloat(bill.balance || bill.amount).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}</p>
                                        </div>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label fw-bold">Status</label>
                                            <p class="form-control-plaintext">${bill.status ? bill.status.charAt(0).toUpperCase() + bill.status.slice(1) : 'Draft'}</p>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label fw-bold">Created</label>
                                            <p class="form-control-plaintext">${bill.created_at ? new Date(bill.created_at).toLocaleDateString() : 'N/A'}</p>
                                        </div>
                                    </div>
                                </div>
                                ${bill.description ? `
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Description</label>
                                    <p class="form-control-plaintext">${bill.description}</p>
                                </div>
                                ` : ''}
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                            </div>
                        </div>
                    </div>
                `;

                // Create modal
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
                console.error('Error viewing bill:', error);
                showAlert('Error loading bill details: ' + error.message, 'danger');
            }
        }

        // Edit bill
        async function editBill(billId) {
            try {
                const response = await fetch(`../api/bills.php?id=${billId}`);
                const data = await response.json();

                if (data.error) {
                    throw new Error(data.error);
                }

                let bill = data;
                if (Array.isArray(data)) {
                    bill = data[0];
                }

                if (!bill || !bill.id) {
                    throw new Error('Bill not found');
                }

                // Close the view modal if it exists
                const existingModal = document.querySelector('.modal.show');
                if (existingModal) {
                    const modal = bootstrap.Modal.getInstance(existingModal);
                    if (modal) modal.hide();
                }

                // Pre-populate the add bill form
                document.getElementById('billNumber').value = bill.bill_number || '';
                document.getElementById('billVendor').value = bill.vendor_id || '';
                document.getElementById('billDate').value = bill.bill_date || '';
                document.getElementById('dueDate').value = bill.due_date || '';
                document.getElementById('billAmount').value = bill.amount || '';
                document.getElementById('billDescription').value = bill.description || '';

                // Change modal title
                document.querySelector('#addBillModal .modal-title').textContent = 'Edit Bill';

                // Store bill ID for edit mode
                const form = document.getElementById('addBillForm');
                form.dataset.editBillId = billId;

                // Change submit button text
                const submitBtn = document.querySelector('#addBillModal .btn-primary');
                submitBtn.textContent = 'Update Bill';

                // Show the modal
                const modalEl = document.getElementById('addBillModal');
                const modal = new bootstrap.Modal(modalEl);
                modal.show();

            } catch (error) {
                console.error('Error loading bill for edit:', error);
                showAlert('Error loading bill: ' + error.message, 'danger');
            }
        }

        // Delete bill
        async function deleteBill(billId) {
            showConfirmDialog(
                'Delete Bill',
                'Are you sure you want to delete this bill? This action cannot be undone.',
                async () => {
            try {
                const response = await fetch(`../api/bills.php?id=${billId}`, {
                    method: 'DELETE'
                });

                const result = await response.json();

                if (result.success) {
                    showAlert('Bill deleted successfully', 'success');
                    loadBills(); // Refresh the bills table
                } else {
                    throw new Error(result.error || 'Failed to delete bill');
                }
            } catch (error) {
                console.error('Error deleting bill:', error);
                showAlert('Error deleting bill: ' + error.message, 'danger');
            }
                }
            );
        }

        // Update bill form submission to handle both create and edit
        // Find the bill form submission handler and update it
        // The handler is already set up to handle edit mode by checking form.dataset.editBillId

        // View payment details
        async function viewPayment(paymentId) {
            try {
                const response = await fetch(`../api/payments.php?id=${paymentId}`);
                const data = await response.json();

                if (data.error) {
                    throw new Error(data.error);
                }

                let payment = data;
                if (Array.isArray(data)) {
                    payment = data[0];
                }

                if (!payment || !payment.id) {
                    throw new Error('Payment not found');
                }

                // Create a view payment modal content
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
                                            <label class="form-label fw-bold">Payment Number</label>
                                            <p class="form-control-plaintext">${payment.payment_number || payment.reference_number}</p>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label fw-bold">Vendor</label>
                                            <p class="form-control-plaintext">${payment.vendor_name || 'Unknown Vendor'}</p>
                                        </div>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label fw-bold">Payment Date</label>
                                            <p class="form-control-plaintext">${new Date(payment.payment_date).toLocaleDateString()}</p>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label fw-bold">Amount</label>
                                            <p class="form-control-plaintext">₱${parseFloat(payment.amount).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}</p>
                                        </div>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label fw-bold">Payment Method</label>
                                            <p class="form-control-plaintext">${payment.payment_method ? payment.payment_method.replace('_', ' ').toUpperCase() : 'Unknown'}</p>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label fw-bold">Reference Number</label>
                                            <p class="form-control-plaintext">${payment.reference_number || 'N/A'}</p>
                                        </div>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label fw-bold">Bill (if applicable)</label>
                                            <p class="form-control-plaintext">${payment.bill_number ? `Bill ${payment.bill_number}` : 'No specific bill'}</p>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label fw-bold">Created</label>
                                            <p class="form-control-plaintext">${payment.created_at ? new Date(payment.created_at).toLocaleDateString() : 'N/A'}</p>
                                        </div>
                                    </div>
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

                // Create modal
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

        // Delete payment
        async function deletePayment(paymentId) {
            showConfirmDialog(
                'Delete Payment',
                'Are you sure you want to delete this payment? This action cannot be undone.',
                async () => {
            try {
                const response = await fetch(`../api/payments.php?id=${paymentId}&type=made`, {
                    method: 'DELETE'
                });

                const result = await response.json();

                if (result.success) {
                    showAlert('Payment deleted successfully', 'success');
                    loadPayments(); // Refresh the payments table
                } else {
                    throw new Error(result.error || 'Failed to delete payment');
                }
            } catch (error) {
                console.error('Error deleting payment:', error);
                showAlert('Error deleting payment: ' + error.message, 'danger');
            }
        }
        // View adjustment details
        async function viewAdjustment(adjustmentId) {
            try {
                // Fetch adjustment details from API
                const response = await fetch(`../api/adjustments.php?id=${adjustmentId}`);

                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }

                const adjustment = await response.json();

                // Check if we got a single object or an array
                let adjustmentData = adjustment;
                if (Array.isArray(adjustment)) {
                    adjustmentData = adjustment[0];
                }

                if (!adjustmentData || adjustment.error) {
                    throw new Error(adjustment.error || 'No adjustment data found');
                }

                // Populate view modal with adjustment data (with safe property access)
                document.getElementById('view_adjustment_number').textContent = adjustmentData.adjustment_number || 'N/A';
                document.getElementById('view_adjustment_status').textContent = 'Active';
                document.getElementById('view_vendor_name').textContent = adjustmentData.vendor_name || adjustmentData.vendor_id || 'Unknown Vendor';
                document.getElementById('view_adjustment_type').textContent = adjustmentData.adjustment_type ? adjustmentData.adjustment_type.replace('_', ' ').toUpperCase() : 'Unknown Type';
                document.getElementById('view_amount').textContent = `₱${parseFloat(adjustmentData.amount || 0).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;

                try {
                    document.getElementById('view_adjustment_date').textContent = adjustmentData.adjustment_date ?
                        new Date(adjustmentData.adjustment_date).toLocaleDateString() : 'Unknown Date';
                } catch (e) {
                    document.getElementById('view_adjustment_date').textContent = 'Unknown Date';
                }

                document.getElementById('view_reason').textContent = adjustmentData.reason || adjustmentData.description || 'N/A';

                // Store adjustment data for edit functionality
                window.currentAdjustment = adjustmentData;

                // Set up edit button
                const editBtn = document.getElementById('editAdjustmentBtn');
                if (editBtn) {
                    editBtn.onclick = function() {
                        editAdjustmentFromView();
                    };
                }

                // Show the view modal
                const modalEl = document.getElementById('viewAdjustmentModal');
                if (modalEl) {
                    const modal = new bootstrap.Modal(modalEl);
                    modal.show();
                }

            } catch (error) {
                console.error('Error viewing adjustment:', error);
                showAlert('Error loading adjustment details: ' + error.message, 'danger');
            }
        }

        // Edit adjustment from view modal - pre-populate add adjustment modal
        function editAdjustmentFromView() {
            const adjustment = window.currentAdjustment;
            if (!adjustment) {
                showAlert('No adjustment data available for editing', 'warning');
                return;
            }

            // Close view modal
            const viewModal = bootstrap.Modal.getInstance(document.getElementById('viewAdjustmentModal'));
            if (viewModal) viewModal.hide();

            // Pre-populate add adjustment modal
            const form = document.getElementById('addAdjustmentForm');

            // Set values
            document.getElementById('adjustmentVendor').value = adjustment.vendor_id;
            document.getElementById('adjustmentType').value = adjustment.adjustment_type;
            document.getElementById('adjustmentDate').value = adjustment.adjustment_date;
            document.getElementById('adjustmentAmount').value = adjustment.amount;
            document.getElementById('adjustmentDescription').value = adjustment.reason || '';

            // Change modal title and submit button
            document.getElementById('addAdjustmentModalTitle').textContent = 'Edit Adjustment';
            document.getElementById('addAdjustmentModalSubmitBtn').textContent = 'Update Adjustment';

            // Store adjustment ID for edit mode (form handler will check this)
            form.dataset.adjustmentId = adjustment.id;
            form.dataset.editMode = 'true'; // Clear flag for edit mode

            // Show the add modal
            const modalEl = document.getElementById('addAdjustmentModal');
            if (modalEl) {
                const modal = new bootstrap.Modal(modalEl);
                modal.show();
            }
        }

        // Reset adjustment form back to add mode
        function resetAdjustmentForm() {
            const form = document.getElementById('addAdjustmentForm');
            form.reset();
            delete form.dataset.adjustmentId;

            // Reset modal title and button
            document.getElementById('addAdjustmentModalTitle').textContent = 'Add Adjustment';
            document.getElementById('addAdjustmentModalSubmitBtn').textContent = 'Add Adjustment';
        }

        // Reset bill form back to add mode
        function resetBillForm() {
            const form = document.getElementById('addBillForm');
            form.reset();
            delete form.dataset.editBillId;

            // Reset modal title and button to add mode
            const titleEl = document.querySelector('#addBillModal .modal-title');
            if (titleEl) titleEl.textContent = 'Add Bill';
            const submitBtn = document.querySelector('#addBillModal .btn-primary');
            if (submitBtn) {
                submitBtn.textContent = 'Add Bill';
            }
        }

        // Delete adjustment
        async function deleteAdjustment(adjustmentId) {
            showConfirmDialog(
                'Delete Adjustment',
                'Are you sure you want to delete this adjustment? This action cannot be undone.',
                async () => {
            try {
                const response = await fetch(`../api/adjustments.php?id=${adjustmentId}`, {
                    method: 'DELETE'
                });

                const result = await response.json();

                if (result.success) {
                    showAlert('Adjustment deleted successfully', 'success');
                    loadAdjustments(); // Refresh the adjustments table
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

        // Alert function
        function showAlert(message, type = 'info') {
            const alertDiv = document.createElement('div');
            alertDiv.className = `alert alert-${type} alert-dismissible fade show position-fixed`;
            alertDiv.style.cssText = 'top: 20px; right: 20px; z-index: 9999; min-width: 300px;';
            alertDiv.innerHTML = `
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;

            document.body.appendChild(alertDiv);

            setTimeout(() => {
                if (alertDiv.parentNode) {
                    alertDiv.remove();
                }
            }, 5000);
        }
    </script><!-- Inactivity Timeout - Blur screen + Auto logout -->
    <script src="../includes/inactivity_timeout.js?v=3"></script>
<script src="../includes/navbar_datetime.js"></script>
<script src="../includes/tab_persistence.js?v=1"></script>
</body>
</html>
    <!-- Inactivity Timeout - Blur screen + Auto logout -->





