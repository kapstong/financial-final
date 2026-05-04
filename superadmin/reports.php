<?php
require_once '../includes/auth.php';
require_once '../includes/database.php';
    // Analytics removed: UI and handlers deleted
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Financial Management System - Reports</title>
    <link rel="icon" type="image/png" href="../logo2.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        
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

        /* Financial Statement Styles */
        .financial-statement {
            background: white;
            border-radius: 8px;
            padding: 2rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }

        .statement-header {
            border-bottom: 2px solid #1e2936;
            padding-bottom: 1rem;
            margin-bottom: 2rem;
        }

        .statement-title {
            color: #1e2936;
            font-weight: 800;
            font-size: 1.5rem;
            margin: 0;
        }

        .statement-period {
            color: #6c757d;
            font-size: 0.9rem;
            margin: 0.5rem 0 0 0;
        }

        .account-category {
            background: #f8f9fa;
            padding: 1rem;
            margin: 1rem 0;
            border-radius: 6px;
            border-left: 4px solid #1e2936;
        }

        .account-category h6 {
            color: #1e2936;
            font-weight: 700;
            margin: 0 0 0.5rem 0;
            text-transform: uppercase;
            font-size: 0.8rem;
            letter-spacing: 1px;
        }

        .account-item {
            display: flex;
            justify-content: space-between;
            padding: 0.5rem 0;
            border-bottom: 1px solid #e9ecef;
        }

        .account-item:last-child {
            border-bottom: none;
        }

        .account-name {
            font-weight: 500;
            color: #495057;
        }

        .account-amount {
            font-weight: 600;
            color: #1e2936;
        }

        .total-row {
            background: linear-gradient(135deg, #1e2936 0%, #2c3e50 100%);
            color: white;
            font-weight: 700;
            font-size: 1.1rem;
            margin-top: 1rem;
        }

        .total-row .account-name,
        .total-row .account-amount {
            color: white;
        }

        .positive-amount {
            color: #28a745 !important;
        }

        .negative-amount {
            color: #dc3545 !important;
        }

        /* Chart container */
        .chart-container {
            position: relative;
            height: 400px;
            width: 100%;
        }
    </style>
</head>
<body>
    <?php include '../includes/superadmin_navigation.php'; ?>

    <div class="content">
        <!-- Top Navbar -->
        <?php include '../includes/global_navbar.php'; ?>

        <!-- Navigation Tabs -->
        <ul class="nav nav-tabs mb-4" id="reportsTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="income-tab" data-bs-toggle="tab" data-bs-target="#income" type="button" role="tab">
                    <i class="fas fa-chart-line me-2"></i>Profit & Loss
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="balance-tab" data-bs-toggle="tab" data-bs-target="#balance" type="button" role="tab">
                    <i class="fas fa-balance-scale me-2"></i>Balance Sheet
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="cashflow-tab" data-bs-toggle="tab" data-bs-target="#cashflow" type="button" role="tab">
                    <i class="fas fa-money-bill-wave me-2"></i>Cash Flow
                </button>
            </li>
            <!-- Analytics tab removed -->

        </ul>

        <!-- Tab Content -->
        <div class="tab-content" id="reportsTabContent">
            <!-- Profit & Loss Statement Tab -->
            <div class="tab-pane fade show active" id="income" role="tabpanel" aria-labelledby="income-tab">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="mb-0">Profit & Loss Statement (Income Statement)</h6>
                    <div>
                        <select class="form-select form-select-sm me-2" id="incomePeriodSelect" style="width: auto;" onchange="updateIncomeStatementPeriod()">
                            <option value="daily">Daily</option>
                            <option value="weekly">Weekly</option>
                            <option value="monthly">Monthly</option>
                            <option value="quarterly">Quarterly</option>
                            <option value="semi_annually">Semi-Annually</option>
                            <option value="annually">Annually</option>
                            <option value="custom">Custom Range</option>
                        </select>
                        <button class="btn btn-outline-secondary me-2" onclick="exportIncomeStatement('csv')" title="Export as CSV"><i class="fas fa-file-csv me-2"></i>Export CSV</button>
                        <button class="btn btn-outline-secondary me-2" onclick="exportIncomeStatement('pdf')" title="Export as PDF"><i class="fas fa-file-pdf me-2"></i>Export PDF</button>
                        <button class="btn btn-primary" onclick="generateIncomeStatement()"><i class="fas fa-sync me-2"></i>Generate Report</button>
                    </div>
                </div>

                <!-- Custom Date Range (Hidden by default) -->
                <div id="incomeCustomRange" class="card mb-3" style="display: none;">
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-4">
                                <label class="form-label">From Date</label>
                                <input type="date" class="form-control" id="incomeFromDate">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">To Date</label>
                                <input type="date" class="form-control" id="incomeToDate">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">&nbsp;</label>
                                <button class="btn btn-primary w-100" onclick="generateIncomeStatement()">Apply Custom Range</button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-12">
                        <div class="financial-statement" id="incomeStatementContainer">
                            <div class="statement-header">
                                <h1 class="statement-title">Profit & Loss Statement</h1>
                                <p class="statement-period" id="incomeStatementPeriod">Loading...</p>
                            </div>
                            <div class="text-center py-5">
                                <div class="loading mb-3"></div>
                                <p class="text-muted">Generating income statement...</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Balance Sheet Tab -->
            <div class="tab-pane fade" id="balance" role="tabpanel" aria-labelledby="balance-tab">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="mb-0">Balance Sheet</h6>
                    <div>
                        <select class="form-select form-select-sm me-2" style="width: auto;" id="balanceDateSelect" onchange="updateBalanceSheetPeriod()">
                            <option value="daily">Daily</option>
                            <option value="weekly">Weekly</option>
                            <option value="monthly">Monthly</option>
                            <option value="quarterly">Quarterly</option>
                            <option value="semi_annually">Semi-Annually</option>
                            <option value="annually">Annually</option>
                            <option value="custom">Custom Range</option>
                        </select>
                        <button class="btn btn-outline-secondary me-2" onclick="exportBalanceSheet('csv')" title="Export as CSV"><i class="fas fa-file-csv me-2"></i>Export CSV</button>
                        <button class="btn btn-outline-secondary me-2" onclick="exportBalanceSheet('pdf')" title="Export as PDF"><i class="fas fa-file-pdf me-2"></i>Export PDF</button>
                        <button class="btn btn-primary" onclick="generateBalanceSheet()"><i class="fas fa-sync me-2"></i>Generate Report</button>
                    </div>
                </div>

                <!-- Custom Date Range for Balance Sheet (hidden by default) -->
                <div id="balanceCustomRange" class="card mb-3" style="display: none;">
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-4">
                                <label class="form-label">From Date</label>
                                <input type="date" class="form-control" id="balanceFromDate">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">To Date</label>
                                <input type="date" class="form-control" id="balanceToDate">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">&nbsp;</label>
                                <button class="btn btn-primary w-100" onclick="generateBalanceSheet()">Apply Custom Range</button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-12">
                        <div class="financial-statement" id="balanceSheetContainer">
                            <div class="statement-header">
                                <h1 class="statement-title">Balance Sheet</h1>
                                <p class="statement-period">Loading...</p>
                            </div>
                            <div class="text-center py-5">
                                <div class="loading mb-3"></div>
                                <p class="text-muted">Generating balance sheet...</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Cash Flow Statement Tab -->
            <div class="tab-pane fade" id="cashflow" role="tabpanel" aria-labelledby="cashflow-tab">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="mb-0">Cash Flow Statement</h6>
                    <div>
                        <select class="form-select form-select-sm me-2" style="width: auto;" id="cashFlowPeriodSelect" onchange="updateCashFlowPeriod()">
                            <option value="daily">Daily</option>
                            <option value="weekly">Weekly</option>
                            <option value="monthly">Monthly</option>
                            <option value="quarterly">Quarterly</option>
                            <option value="semi_annually">Semi-Annually</option>
                            <option value="annually">Annually</option>
                            <option value="custom">Custom Range</option>
                        </select>
                        <button class="btn btn-outline-secondary me-2" onclick="exportCashFlow('csv')" title="Export as CSV"><i class="fas fa-file-csv me-2"></i>Export CSV</button>
                        <button class="btn btn-outline-secondary me-2" onclick="exportCashFlow('pdf')" title="Export as PDF"><i class="fas fa-file-pdf me-2"></i>Export PDF</button>
                        <button class="btn btn-primary" onclick="generateCashFlow()"><i class="fas fa-sync me-2"></i>Generate Report</button>
                    </div>
                </div>

                <!-- Custom Date Range for Cash Flow (hidden by default) -->
                <div id="cashFlowCustomRange" class="card mb-3" style="display: none;">
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-4">
                                <label class="form-label">From Date</label>
                                <input type="date" class="form-control" id="cashFlowFromDate">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">To Date</label>
                                <input type="date" class="form-control" id="cashFlowToDate">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">&nbsp;</label>
                                <button class="btn btn-primary w-100" onclick="generateCashFlow()">Apply Custom Range</button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-12">
                        <div class="financial-statement" id="cashFlowContainer">
                            <div class="statement-header">
                                <h1 class="statement-title">Cash Flow Statement</h1>
                                <p class="statement-period">Loading...</p>
                            </div>
                            <div class="text-center py-5">
                                <div class="loading mb-3"></div>
                                <p class="text-muted">Generating cash flow statement...</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Analytics & Export removed -->
        </div>
    </div>

    <!-- Footer -->

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../includes/alert-modal.js"></script>
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


        // Global variables
        let currentIncomeStatementData = null;
        let currentBalanceSheetData = null;
        let currentCashFlowData = null;

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

            // Load initial income statement
            generateIncomeStatement();

            // Auto-generate reports when tabs are shown
            const balanceTab = document.getElementById('balance-tab');
            const cashflowTab = document.getElementById('cashflow-tab');

            let balanceGenerated = false;
            let cashflowGenerated = false;

            balanceTab.addEventListener('shown.bs.tab', function() {
                if (!balanceGenerated) {
                    generateBalanceSheet();
                    balanceGenerated = true;
                }
            });

            cashflowTab.addEventListener('shown.bs.tab', function() {
                if (!cashflowGenerated) {
                    generateCashFlow();
                    cashflowGenerated = true;
                }
            });
        });

        // Update income statement period selector
        function updateIncomeStatementPeriod() {
            const periodSelect = document.getElementById('incomePeriodSelect');
            const customRange = document.getElementById('incomeCustomRange');

            if (periodSelect.value === 'custom') {
                customRange.style.display = 'block';
            } else {
                customRange.style.display = 'none';
            }
        }

        // Generate income statement
        async function generateIncomeStatement() {
            const container = document.getElementById('incomeStatementContainer');
            const periodElement = document.getElementById('incomeStatementPeriod');

            // Show loading state
            container.innerHTML = `
                <div class="statement-header">
                    <h1 class="statement-title">Profit & Loss Statement</h1>
                    <p class="statement-period">Loading...</p>
                </div>
                <div class="text-center py-5">
                    <div class="loading mb-3"></div>
                    <p class="text-muted">Generating income statement...</p>
                </div>
            `;

            let data = null;
            try {
                // Get date range based on selection
                const periodSelect = document.getElementById('incomePeriodSelect');
                let dateFrom, dateTo;

                if (periodSelect.value === 'custom') {
                    dateFrom = document.getElementById('incomeFromDate').value;
                    dateTo = document.getElementById('incomeToDate').value;
                } else {
                    const dates = getDateRange(periodSelect.value);
                    dateFrom = dates.from;
                    dateTo = dates.to;
                }

                // Fetch income statement data
                const response = await fetch(`../api/reports.php?type=income_statement&date_from=${dateFrom}&date_to=${dateTo}`);

                // Get the response text first (even if status is error, API returns JSON with details)
                const responseText = await response.text();
                if (!responseText) {
                    throw new Error('Empty response from server');
                }

                // Parse JSON
                try {
                    data = JSON.parse(responseText);
                } catch (parseError) {
                    console.error('Response text:', responseText);
                    throw new Error('Invalid JSON response from server. Response: ' + responseText.substring(0, 500));
                }

                // Check for errors in the response
                if (!response.ok) {
                    throw new Error(data.error || `HTTP error! status: ${response.status}`);
                }

                if (data.error) {
                    throw new Error(data.error);
                }

                if (!data.report_type) {
                    throw new Error('Invalid report response format');
                }

                // Store data globally for export
                currentIncomeStatementData = data;

                // Render the income statement
                renderIncomeStatement(data);

            } catch (error) {
                console.error('Error generating income statement:', error);
                console.error('Response data:', data);

                // Build detailed error message
                let errorHTML = `Error generating income statement: ${error.message}`;
                if (data && data.file) {
                    errorHTML += `<br><small><strong>File:</strong> ${data.file}:${data.line}</small>`;
                }
                if (data && data.trace) {
                    errorHTML += `<br><details><summary>Stack trace</summary><pre style="text-align: left; font-size: 11px; max-height: 200px; overflow: auto; background: #f5f5f5; padding: 10px; margin-top: 10px;">${data.trace}</pre></details>`;
                }

                container.innerHTML = `
                    <div class="statement-header">
                        <h1 class="statement-title">Profit & Loss Statement</h1>
                        <p class="statement-period">Error loading report</p>
                    </div>
                    <div class="text-center py-5">
                        <div class="alert alert-danger" style="text-align: left;">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            ${errorHTML}
                        </div>
                        <button class="btn btn-primary" onclick="generateIncomeStatement()">Try Again</button>
                    </div>
                `;
            }
        }

        // Render income statement
        function renderIncomeStatement(data) {
            const container = document.getElementById('incomeStatementContainer');

            // Build HTML content
            let html = `
                <div class="statement-header">
                    <h1 class="statement-title">Profit & Loss Statement</h1>
                    <p class="statement-period">For the period ${formatDate(data.date_from)} to ${formatDate(data.date_to)}</p>
                </div>

                <div class="account-category">
                    <h6>Revenue</h6>
            `;

            // Add revenue accounts
            if (data.revenue.accounts && data.revenue.accounts.length > 0) {
                data.revenue.accounts.forEach(account => {
                    html += `
                        <div class="account-item">
                            <span class="account-name">${account.account_name}</span>
                            <span class="account-amount">₱${parseFloat(account.amount || 0).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</span>
                        </div>
                    `;
                });
            }

            html += `
                    <div class="account-item total-row">
                        <span class="account-name"><strong>Total Revenue</strong></span>
                        <span class="account-amount"><strong>₱${parseFloat(data.revenue.total || 0).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</strong></span>
                    </div>
                </div>

                <div class="account-category">
                    <h6>Cost of Goods Sold</h6>
                    <div class="account-item total-row">
                        <span class="account-name"><strong>Total COGS</strong></span>
                        <span class="account-amount"><strong>₱0.00</strong></span>
                    </div>
                </div>

                <div class="account-category">
                    <h6>Gross Profit</h6>
                    <div class="account-item total-row">
                        <span class="account-name"><strong>Gross Profit</strong></span>
                        <span class="account-amount positive-amount"><strong>₱${parseFloat(data.revenue.total || 0).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</strong></span>
                    </div>
                </div>

                <div class="account-category">
                    <h6>Operating Expenses</h6>
            `;

            // Add expense accounts
            if (data.expenses.accounts && data.expenses.accounts.length > 0) {
                data.expenses.accounts.forEach(account => {
                    html += `
                        <div class="account-item">
                            <span class="account-name">${account.account_name}</span>
                            <span class="account-amount">₱${parseFloat(account.amount || 0).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</span>
                        </div>
                    `;
                });
            }

            html += `
                    <div class="account-item total-row">
                        <span class="account-name"><strong>Total Operating Expenses</strong></span>
                        <span class="account-amount"><strong>₱${parseFloat(data.expenses.total || 0).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</strong></span>
                    </div>
                </div>

                <div class="account-category">
                    <h6>Net Profit</h6>
                    <div class="account-item total-row">
                        <span class="account-name"><strong>Net Profit</strong></span>
                        <span class="account-amount ${parseFloat(data.net_profit || 0) >= 0 ? 'positive-amount' : 'negative-amount'}"><strong>₱${parseFloat(data.net_profit || 0).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</strong></span>
                    </div>
                </div>
            `;

            container.innerHTML = html;

            // Apply privacy masking immediately to prevent flash
            setTimeout(function() {
                if (typeof PrivacyMode !== 'undefined') {
                    PrivacyMode.hide();
                }
            }, 10);
        }

        // Export income statement
        function exportIncomeStatement(format) {
            format = format || 'csv'; // default to CSV
            
            if (!currentIncomeStatementData) {
                showAlert('Please generate the report first', 'warning');
                return;
            }

            if (format === 'pdf') {
                // Export via API with PDF format
                const dateFrom = currentIncomeStatementData.date_from;
                const dateTo = currentIncomeStatementData.date_to;
                const url = `../api/reports.php?type=income_statement&date_from=${dateFrom}&date_to=${dateTo}&format=pdf`;
                
                // Create and trigger download
                const link = document.createElement('a');
                link.href = url;
                link.download = `income_statement_${dateFrom}_to_${dateTo}.pdf`;
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
                
                showAlert('Income statement PDF download started', 'success');
                return;
            }

            // Create CSV content
            let csvContent = 'data:text/csv;charset=utf-8,';
            csvContent += 'Profit & Loss Statement\n';
            csvContent += `Period: ${currentIncomeStatementData.date_from} to ${currentIncomeStatementData.date_to}\n\n`;

            csvContent += 'Revenue\n';
            csvContent += 'Account,Amount\n';
            if (currentIncomeStatementData.revenue.accounts) {
                currentIncomeStatementData.revenue.accounts.forEach(account => {
                    csvContent += `"${account.account_name}","${account.amount}"\n`;
                });
            }
            csvContent += `"Total Revenue","${currentIncomeStatementData.revenue.total}"\n\n`;

            csvContent += 'Expenses\n';
            csvContent += 'Account,Amount\n';
            if (currentIncomeStatementData.expenses.accounts) {
                currentIncomeStatementData.expenses.accounts.forEach(account => {
                    csvContent += `"${account.account_name}","${account.amount}"\n`;
                });
            }
            csvContent += `"Total Expenses","${currentIncomeStatementData.expenses.total}"\n\n`;

            csvContent += `"Net Profit","${currentIncomeStatementData.net_profit}"\n`;

            // Download CSV
            const encodedUri = encodeURI(csvContent);
            const link = document.createElement('a');
            link.setAttribute('href', encodedUri);
            link.setAttribute('download', `income_statement_${currentIncomeStatementData.date_from}_to_${currentIncomeStatementData.date_to}.csv`);
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);

            showAlert('Income statement exported successfully', 'success');
        }

        // Get date range based on period
        function getDateRange(period) {
            const now = new Date();
            let from, to;

            function startOfWeek(d) {
                const date = new Date(d);
                const day = date.getDay();
                const diff = (day === 0 ? -6 : 1 - day);
                date.setDate(date.getDate() + diff);
                date.setHours(0,0,0,0);
                return date;
            }

            switch (period) {
                case 'daily':
                    from = new Date(now.getFullYear(), now.getMonth(), now.getDate());
                    to = new Date(from);
                    break;
                case 'weekly':
                    from = startOfWeek(now);
                    to = new Date(from);
                    to.setDate(from.getDate() + 6);
                    break;
                case 'monthly':
                    from = new Date(now.getFullYear(), now.getMonth(), 1);
                    to = new Date(now.getFullYear(), now.getMonth() + 1, 0);
                    break;
                case 'quarterly':
                    const qStartMonth = Math.floor(now.getMonth() / 3) * 3;
                    from = new Date(now.getFullYear(), qStartMonth, 1);
                    to = new Date(now.getFullYear(), qStartMonth + 3, 0);
                    break;
                case 'semi_annually':
                    if (now.getMonth() < 6) {
                        from = new Date(now.getFullYear(), 0, 1);
                        to = new Date(now.getFullYear(), 6, 0);
                    } else {
                        from = new Date(now.getFullYear(), 6, 1);
                        to = new Date(now.getFullYear(), 12, 0);
                    }
                    break;
                case 'annually':
                case 'yearly':
                    from = new Date(now.getFullYear(), 0, 1);
                    to = new Date(now.getFullYear(), 11, 31);
                    break;
                default:
                    from = new Date(now.getFullYear(), now.getMonth(), 1);
                    to = new Date(now.getFullYear(), now.getMonth() + 1, 0);
            }

            return {
                from: from.toISOString().split('T')[0],
                to: to.toISOString().split('T')[0]
            };
        }

        // Format date helper function
        function formatDate(dateString) {
            if (!dateString) return 'N/A';
            const date = new Date(dateString);
            return date.toLocaleDateString('en-US', {
                year: 'numeric',
                month: 'short',
                day: 'numeric'
            });
        }

        // Show/hide balance sheet custom range and regenerate
        function updateBalanceSheetPeriod() {
            const periodSelect = document.getElementById('balanceDateSelect');
            const customRange = document.getElementById('balanceCustomRange');

            if (periodSelect && periodSelect.value === 'custom') {
                if (customRange) customRange.style.display = 'block';
            } else {
                if (customRange) customRange.style.display = 'none';
            }

            if (typeof generateBalanceSheet === 'function') {
                generateBalanceSheet();
            }
        }

        // Generate balance sheet
        async function generateBalanceSheet() {
            const container = document.getElementById('balanceSheetContainer');
            const dateSelect = document.getElementById('balanceDateSelect');
            let asOfDate = 'current';

            // Show loading state
            container.innerHTML = `
                <div class="statement-header">
                    <h1 class="statement-title">Balance Sheet</h1>
                    <p class="statement-period">Loading...</p>
                </div>
                <div class="text-center py-5">
                    <div class="loading mb-3"></div>
                    <p class="text-muted">Generating balance sheet...</p>
                </div>
            `;

            try {
                // Determine as-of date based on selection or custom range
                if (dateSelect) {
                    if (dateSelect.value === 'custom') {
                        const from = document.getElementById('balanceFromDate') ? document.getElementById('balanceFromDate').value : '';
                        const to = document.getElementById('balanceToDate') ? document.getElementById('balanceToDate').value : '';
                        asOfDate = to || from || 'current';
                    } else {
                        const dates = getDateRange(dateSelect.value);
                        asOfDate = dates.to;
                    }
                }

                // Fetch balance sheet data
                const response = await fetch(`../api/reports.php?type=balance_sheet&as_of_date=${asOfDate}`);

                // Check if response is OK
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }

                // Get the response text first to check if it's empty
                const responseText = await response.text();
                if (!responseText) {
                    throw new Error('Empty response from server');
                }

                // Parse JSON
                let data;
                try {
                    data = JSON.parse(responseText);
                } catch (parseError) {
                    console.error('Response text:', responseText);
                    throw new Error('Invalid JSON response from server');
                }

                if (data.error) {
                    throw new Error(data.error);
                }

                if (!data.report_type || data.report_type !== 'Balance Sheet') {
                    throw new Error('Invalid balance sheet response format');
                }

                // Store data globally for export
                currentBalanceSheetData = data;

                // Render the balance sheet
                renderBalanceSheet(data);

            } catch (error) {
                console.error('Error generating balance sheet:', error);
                container.innerHTML = `
                    <div class="statement-header">
                        <h1 class="statement-title">Balance Sheet</h1>
                        <p class="statement-period">Error loading report</p>
                    </div>
                    <div class="text-center py-5">
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            Error generating balance sheet: ${error.message}
                        </div>
                        <button class="btn btn-primary" onclick="generateBalanceSheet()">Try Again</button>
                    </div>
                `;
            }
        }

        // Render balance sheet
        function renderBalanceSheet(data) {
            const container = document.getElementById('balanceSheetContainer');

            let assetsHtml = '<div class="account-category"><h6>Assets</h6>';
            if (data.assets.accounts && data.assets.accounts.length > 0) {
                data.assets.accounts.forEach(account => {
                    assetsHtml += `
                        <div class="account-item">
                            <span class="account-name">${account.account_name}</span>
                            <span class="account-amount">₱${parseFloat(account.account_balance || 0).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</span>
                        </div>
                    `;
                });
            }
            assetsHtml += `
                <div class="account-item total-row">
                    <span class="account-name"><strong>Total Assets</strong></span>
                    <span class="account-amount"><strong>₱${parseFloat(data.assets.total || 0).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</strong></span>
                </div>
            </div>`;

            let liabilitiesHtml = '<div class="account-category"><h6>Liabilities & Equity</h6>';
            if (data.liabilities.accounts && data.liabilities.accounts.length > 0) {
                data.liabilities.accounts.forEach(account => {
                    liabilitiesHtml += `
                        <div class="account-item">
                            <span class="account-name">${account.account_name}</span>
                            <span class="account-amount">₱${parseFloat(account.account_balance || 0).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</span>
                        </div>
                    `;
                });
            }

            if (data.equity.accounts && data.equity.accounts.length > 0) {
                data.equity.accounts.forEach(account => {
                    liabilitiesHtml += `
                        <div class="account-item">
                            <span class="account-name">${account.account_name}</span>
                            <span class="account-amount">₱${parseFloat(account.account_balance || 0).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</span>
                        </div>
                    `;
                });
            }

            liabilitiesHtml += `
                <div class="account-item total-row">
                    <span class="account-name"><strong>Total Liabilities & Equity</strong></span>
                    <span class="account-amount"><strong>₱${parseFloat((data.liabilities.total || 0) + (data.equity.total || 0)).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</strong></span>
                </div>
            </div>`;

            container.innerHTML = `
                <div class="statement-header">
                    <h1 class="statement-title">Balance Sheet</h1>
                    <p class="statement-period">As of ${formatDate(data.as_of_date)}</p>
                </div>
                <div class="row">
                    <div class="col-md-6">${assetsHtml}</div>
                    <div class="col-md-6">${liabilitiesHtml}</div>
                </div>
            `;

            // Apply privacy masking immediately to prevent flash
            setTimeout(function() {
                if (typeof PrivacyMode !== 'undefined') {
                    PrivacyMode.hide();
                }
            }, 10);
        }

        // Generate cash flow statement
        // Show/hide cash flow custom range and regenerate
        function updateCashFlowPeriod() {
            const periodSelect = document.getElementById('cashFlowPeriodSelect');
            const customRange = document.getElementById('cashFlowCustomRange');

            if (periodSelect && periodSelect.value === 'custom') {
                if (customRange) customRange.style.display = 'block';
            } else {
                if (customRange) customRange.style.display = 'none';
            }

            if (typeof generateCashFlow === 'function') {
                generateCashFlow();
            }
        }

        // Generate cash flow statement
        async function generateCashFlow() {
            const container = document.getElementById('cashFlowContainer');
            const periodSelect = document.getElementById('cashFlowPeriodSelect');
            let dateFrom = '';
            let dateTo = '';

            // Show loading state
            container.innerHTML = `
                <div class="statement-header">
                    <h1 class="statement-title">Cash Flow Statement</h1>
                    <p class="statement-period">Loading...</p>
                </div>
                <div class="text-center py-5">
                    <div class="loading mb-3"></div>
                    <p class="text-muted">Generating cash flow statement...</p>
                </div>
            `;

            try {
                // Determine date range
                if (periodSelect) {
                    if (periodSelect.value === 'custom') {
                        dateFrom = document.getElementById('cashFlowFromDate') ? document.getElementById('cashFlowFromDate').value : '';
                        dateTo = document.getElementById('cashFlowToDate') ? document.getElementById('cashFlowToDate').value : '';
                    } else {
                        const dates = getDateRange(periodSelect.value);
                        dateFrom = dates.from;
                        dateTo = dates.to;
                    }
                } else {
                    const dates = getDateRange('quarterly');
                    dateFrom = dates.from;
                    dateTo = dates.to;
                }

                // Fetch cash flow data
                const response = await fetch(`../api/reports.php?type=cash_flow&date_from=${dateFrom}&date_to=${dateTo}`);

                // Check if response is OK
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }

                // Get the response text first to check if it's empty
                const responseText = await response.text();
                if (!responseText) {
                    throw new Error('Empty response from server');
                }

                // Parse JSON
                let data;
                try {
                    data = JSON.parse(responseText);
                } catch (parseError) {
                    console.error('Response text:', responseText);
                    throw new Error('Invalid JSON response from server');
                }

                if (data.error) {
                    throw new Error(data.error);
                }

                if (!data.report_type || data.report_type !== 'Cash Flow Statement') {
                    throw new Error('Invalid cash flow response format');
                }

                // Store data globally for export
                currentCashFlowData = data;

                // Render the cash flow statement
                renderCashFlow(data);

            } catch (error) {
                console.error('Error generating cash flow:', error);
                container.innerHTML = `
                    <div class="statement-header">
                        <h1 class="statement-title">Cash Flow Statement</h1>
                        <p class="statement-period">Error loading report</p>
                    </div>
                    <div class="text-center py-5">
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            Error: ${error.message}
                        </div>
                        <button class="btn btn-primary" onclick="generateCashFlow()">Try Again</button>
                    </div>
                `;
            }
        }

        // Render cash flow statement
        function renderCashFlow(data) {
            const container = document.getElementById('cashFlowContainer');

            // Check if we have cash_flow data
            if (!data.cash_flow) {
                container.innerHTML = `
                    <div class="text-center py-5">
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            Error: Invalid cash flow data format. Missing cash flow information.
                        </div>
                    </div>
                `;
                return;
            }

            const operating = data.cash_flow.operating_activities;
            const investing = data.cash_flow.investing_activities;
            const financing = data.cash_flow.financing_activities;

            // Default values if data is missing
            const operatingAmount = operating?.amount || 0;
            const investingAmount = investing?.amount || 0;
            const financingAmount = financing?.amount || 0;
            const netCashFlow = operatingAmount + investingAmount + financingAmount;

            // Check if all activities have the expected structure
            if (!operating || !investing || !financing ||
                typeof operating.amount !== 'number' ||
                typeof investing.amount !== 'number' ||
                typeof financing.amount !== 'number') {
                container.innerHTML = `
                    <div class="text-center py-5">
                        <div class="alert alert-warning">
                            <i class="fas fa-info-circle me-2"></i>
                            Cash flow data is incomplete. Some activities may not be available.
                        </div>
                    <div class="text-center py-3">
                        <div class="alert alert-info">
                            Net Cash Flow: ₱${parseFloat(netCashFlow).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}
                        </div>
                    </div>
                `;
                return; // Exit early for incomplete data
            }

            let html = `
                <div class="statement-header">
                    <h1 class="statement-title">Cash Flow Statement</h1>
                    <p class="statement-period">For the period ${formatDate(data.start_date)} to ${formatDate(data.end_date)}</p>
                </div>

                <div class="account-category">
                    <h6>Operating Activities</h6>
                    <div class="ps-3">
                        <div class="fw-bold text-success mb-2">Cash Inflows (Revenue):</div>
            `;

            // Revenue breakdown
            if (operating.revenue && operating.revenue.length > 0) {
                operating.revenue.forEach(rev => {
                    html += `
                        <div class="account-item ps-3">
                            <span class="account-name">${rev.name}</span>
                            <span class="account-amount text-success">₱${parseFloat(rev.amount || 0).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</span>
                        </div>
                    `;
                });
            } else {
                html += `
                    <div class="account-item ps-3">
                        <span class="account-name text-muted">No revenue recorded</span>
                        <span class="account-amount">₱0.00</span>
                    </div>
                `;
            }

            html += `
                        <div class="account-item ps-3 border-bottom pb-2">
                            <span class="account-name"><strong>Total Revenue</strong></span>
                            <span class="account-amount text-success"><strong>₱${parseFloat(operating.total_revenue || 0).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</strong></span>
                        </div>

                        <div class="fw-bold text-danger mb-2 mt-3">Cash Outflows (Operating Expenses):</div>
            `;

            // Expense category breakdown
            if (operating.expenses_by_category && operating.expenses_by_category.length > 0) {
                operating.expenses_by_category.forEach(expense => {
                    const sourceBadges = expense.sources ? expense.sources.split(',').map(src => {
                        const badgeClass = src === 'HR_SYSTEM' ? 'bg-primary' : src === 'LOGISTICS1' ? 'bg-warning' : src === 'LOGISTICS2' ? 'bg-info' : 'bg-secondary';
                        return `<span class="badge ${badgeClass} text-xs">${src}</span>`;
                    }).join(' ') : '';

                    html += `
                        <div class="account-item ps-3">
                            <span class="account-name">
                                ${expense.name}
                                ${sourceBadges}
                                <button class="btn btn-sm btn-link text-decoration-none p-0 ms-2"
                                        onclick="toggleExpenseDetail('${expense.subcategory}')"
                                        title="View detailed breakdown">
                                    <i class="fas fa-chevron-down" id="icon-${expense.subcategory}"></i>
                                </button>
                            </span>
                            <span class="account-amount text-danger">₱${parseFloat(expense.amount || 0).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</span>
                        </div>
                        <div id="detail-${expense.subcategory}" class="ps-5 d-none" style="background-color: #f8f9fa; margin: 5px 0; padding: 10px; border-radius: 4px;">
                    `;

                    // Show detailed breakdown for this category
                    if (operating.expense_details && operating.expense_details.length > 0) {
                        const categoryDetails = operating.expense_details.filter(d => d.expense_category === expense.subcategory);
                        if (categoryDetails.length > 0) {
                            categoryDetails.forEach(detail => {
                                const sourceLabel = detail.source_system === 'HR_SYSTEM' ? 'HR4 Payroll' :
                                                  detail.source_system === 'LOGISTICS1' ? 'Logistics 1' :
                                                  detail.source_system === 'LOGISTICS2' ? 'Logistics 2' : detail.source_system;
                                html += `
                                    <div class="account-item" style="font-size: 0.9em;">
                                        <span class="account-name text-muted">
                                            ${detail.department || 'General'}
                                            <span class="badge bg-light text-dark">${sourceLabel}</span>
                                            <small>(${detail.transaction_count} transactions)</small>
                                        </span>
                                        <span class="account-amount">₱${parseFloat(detail.amount || 0).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</span>
                                    </div>
                                `;
                            });
                        }
                    }

                    html += `</div>`;
                });
            } else {
                html += `
                    <div class="account-item ps-3">
                        <span class="account-name text-muted">No operating expenses recorded</span>
                        <span class="account-amount">₱0.00</span>
                    </div>
                `;
            }

            html += `
                        <div class="account-item ps-3 border-bottom pb-2">
                            <span class="account-name"><strong>Total Operating Expenses</strong></span>
                            <span class="account-amount text-danger"><strong>₱${parseFloat(operating.total_expenses || 0).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</strong></span>
                        </div>
                    </div>

                    <div class="account-item total-row mt-2">
                        <span class="account-name"><strong>Net Cash from Operating Activities</strong></span>
                        <span class="account-amount ${operating.amount >= 0 ? 'text-success' : 'text-danger'}"><strong>₱${parseFloat(operating.amount || 0).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</strong></span>
                    </div>
                </div>

                <div class="account-category">
                    <h6>Investing Activities</h6>
            `;

            if (investing.accounts && investing.accounts.length > 0) {
                investing.accounts.forEach(account => {
                    html += `
                        <div class="account-item">
                            <span class="account-name">${account.account_name}</span>
                            <span class="account-amount">₱${parseFloat(account.amount || 0).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</span>
                        </div>
                    `;
                });
            } else {
                html += `
                    <div class="account-item">
                        <span class="account-name">No investing activities</span>
                        <span class="account-amount">₱0.00</span>
                    </div>
                `;
            }

            html += `
                    <div class="account-item total-row">
                        <span class="account-name"><strong>Net Cash from Investing Activities</strong></span>
                        <span class="account-amount ${investing.amount < 0 ? 'negative-amount' : ''}"><strong>₱${parseFloat(investing.amount || 0).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</strong></span>
                    </div>
                </div>

                <div class="account-category">
                    <h6>Financing Activities</h6>
            `;

            if (financing.accounts && financing.accounts.length > 0) {
                financing.accounts.forEach(account => {
                    html += `
                        <div class="account-item">
                            <span class="account-name">${account.account_name}</span>
                            <span class="account-amount">₱${parseFloat(account.amount || 0).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</span>
                        </div>
                    `;
                });
            } else {
                html += `
                    <div class="account-item">
                        <span class="account-name">No financing activities</span>
                        <span class="account-amount">₱0.00</span>
                    </div>
                `;
            }

            html += `
                    <div class="account-item total-row">
                        <span class="account-name"><strong>Net Cash from Financing Activities</strong></span>
                        <span class="account-amount"><strong>₱${parseFloat(financing.amount || 0).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</strong></span>
                    </div>
                </div>

                <div class="account-category">
                    <h6>Net Cash Flow & Change in Cash</h6>
                    <div class="account-item total-row">
                        <span class="account-name"><strong>Net Increase/(Decrease) in Cash</strong></span>
                        <span class="account-amount ${netCashFlow >= 0 ? 'positive-amount' : 'negative-amount'}"><strong>₱${parseFloat(netCashFlow).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</strong></span>
                    </div>
                </div>
            `;

            container.innerHTML = html;

            // Apply privacy masking immediately to prevent flash
            setTimeout(function() {
                if (typeof PrivacyMode !== 'undefined') {
                    PrivacyMode.hide();
                }
            }, 10);
        }

        // Generate budget report
        async function generateBudgetReport() {
            const container = document.getElementById('budgetVsActualContainer');

            // Show loading state
            container.innerHTML = `
                <div class="text-center py-3">
                    <div class="loading mb-3"></div>
                    <p class="text-muted">Generating budget report...</p>
                </div>
            `;

            try {
                const response = await fetch('../api/reports.php?type=budget_vs_actual');

                // Check if response is OK
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }

                // Get the response text first to check if it's empty
                const responseText = await response.text();
                if (!responseText) {
                    throw new Error('Empty response from server');
                }

                // Parse JSON
                let data;
                try {
                    data = JSON.parse(responseText);
                } catch (parseError) {
                    console.error('Response text:', responseText);
                    throw new Error('Invalid JSON response from server');
                }

                if (data.error) {
                    throw new Error(data.error);
                }

                // For now, just show a message that budget reporting is not implemented
                container.innerHTML = `
                    <div class="text-center py-3">
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>
                            Budget vs Actual reporting requires budget data setup first.
                        </div>
                    </div>
                `;

            } catch (error) {
                console.error('Error generating budget report:', error);
                container.innerHTML = `
                    <div class="text-center py-3">
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            Error generating budget report: ${error.message}
                        </div>
                    </div>
                `;
            }
        }

        // Generate cash flow summary
        async function generateCashFlowSummary() {
            const container = document.getElementById('cashFlowSummaryContainer');

            // Show loading state
            container.innerHTML = `
                <div class="text-center py-3">
                    <div class="loading mb-3"></div>
                    <p class="text-muted">Generating cash flow summary...</p>
                </div>
            `;

            try {
                const response = await fetch('../api/reports.php?type=cash_flow_summary');

                // Check if response is OK
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }

                // Get the response text first to check if it's empty
                const responseText = await response.text();
                if (!responseText) {
                    throw new Error('Empty response from server');
                }

                // Parse JSON
                let data;
                try {
                    data = JSON.parse(responseText);
                } catch (parseError) {
                    console.error('Response text:', responseText);
                    throw new Error('Invalid JSON response from server');
                }

                if (!data.success || data.error) {
                    throw new Error(data.error || 'Failed to generate cash flow summary');
                }

                const summary = data.summary;
                container.innerHTML = `
                    <div class="d-flex justify-content-between">
                        <span>Payroll Expenses (30 days)</span>
                        <span class="text-danger">-₱${parseFloat(summary.payroll_expenses || 0).toLocaleString()}</span>
                    </div>
                    <div class="d-flex justify-content-between">
                        <span>Operating Expenses (30 days)</span>
                        <span class="text-danger">-₱${parseFloat(summary.operating_expenses || 0).toLocaleString()}</span>
                    </div>
                    <div class="d-flex justify-content-between">
                        <span>Days Monitored</span>
                        <span>${summary.days_counted || 0} days</span>
                    </div>
                    <hr>
                    <div class="d-flex justify-content-between">
                        <span><strong>Total Cash Outflow</strong></span>
                        <span class="text-danger"><strong>-₱${parseFloat(summary.total_expenses || 0).toLocaleString()}</strong></span>
                    </div>
                `;

            } catch (error) {
                console.error('Error generating cash flow summary:', error);
                container.innerHTML = `
                    <div class="text-center py-3">
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            Error generating cash flow summary: ${error.message}
                        </div>
                    </div>
                `;
            }
        }

        // Export functions
        function exportBalanceSheet(format) {
            format = format || 'csv'; // default to CSV
            
            if (!currentBalanceSheetData) {
                showAlert('Please generate the balance sheet first', 'warning');
                return;
            }

            if (format === 'pdf') {
                // Export via API with PDF format
                const dateFrom = currentBalanceSheetData.date_from;
                const dateTo = currentBalanceSheetData.as_of_date;
                const url = `../api/reports.php?type=balance_sheet&date_from=${dateFrom}&date_to=${dateTo}&format=pdf`;
                
                // Create and trigger download
                const link = document.createElement('a');
                link.href = url;
                link.download = `balance_sheet_${dateTo}.pdf`;
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
                
                showAlert('Balance Sheet PDF download started', 'success');
                return;
            }

            // Create CSV content
            let csvContent = 'data:text/csv;charset=utf-8,';
            csvContent += 'Balance Sheet\n';
            csvContent += `As of: ${currentBalanceSheetData.as_of_date}\n\n`;

            // Assets section
            csvContent += 'ASSETS\n';
            csvContent += 'Account,Amount\n';
            if (currentBalanceSheetData.assets.accounts) {
                currentBalanceSheetData.assets.accounts.forEach(account => {
                    csvContent += `"${account.account_name}","${account.account_balance || 0}"\n`;
                });
            }
            csvContent += `"Total Assets","${currentBalanceSheetData.assets.total}"\n\n`;

            // Liabilities section
            csvContent += 'LIABILITIES\n';
            csvContent += 'Account,Amount\n';
            if (currentBalanceSheetData.liabilities.accounts) {
                currentBalanceSheetData.liabilities.accounts.forEach(account => {
                    csvContent += `"${account.account_name}","${account.account_balance || 0}"\n`;
                });
            }
            csvContent += `"Total Liabilities","${currentBalanceSheetData.liabilities.total}"\n\n`;

            // Equity section
            csvContent += 'EQUITY\n';
            csvContent += 'Account,Amount\n';
            if (currentBalanceSheetData.equity.accounts) {
                currentBalanceSheetData.equity.accounts.forEach(account => {
                    csvContent += `"${account.account_name}","${account.account_balance || 0}"\n`;
                });
            }
            csvContent += `"Total Equity","${currentBalanceSheetData.equity.total}"\n\n`;

            csvContent += `"Total Liabilities & Equity","${currentBalanceSheetData.liabilities.total + currentBalanceSheetData.equity.total}"\n`;

            // Download CSV
            const encodedUri = encodeURI(csvContent);
            const link = document.createElement('a');
            link.setAttribute('href', encodedUri);
            link.setAttribute('download', `balance_sheet_${currentBalanceSheetData.as_of_date}.csv`);
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);

            showAlert('Balance sheet exported successfully', 'success');
        }

        function exportCashFlow(format) {
            format = format || 'csv'; // default to CSV
            
            if (!currentCashFlowData) {
                showAlert('Please generate the cash flow statement first', 'warning');
                return;
            }

            if (format === 'pdf') {
                // Export via API with PDF format
                const dateFrom = currentCashFlowData.start_date;
                const dateTo = currentCashFlowData.end_date;
                const url = `../api/reports.php?type=cash_flow&date_from=${dateFrom}&date_to=${dateTo}&format=pdf`;
                
                // Create and trigger download
                const link = document.createElement('a');
                link.href = url;
                link.download = `cash_flow_${dateFrom}_to_${dateTo}.pdf`;
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
                
                showAlert('Cash Flow PDF download started', 'success');
                return;
            }

            // Create CSV content
            let csvContent = 'data:text/csv;charset=utf-8,';
            csvContent += 'Cash Flow Statement\n';
            csvContent += `Period: ${currentCashFlowData.start_date} to ${currentCashFlowData.end_date}\n\n`;

            // Operating Activities - use top-level structure
            csvContent += 'OPERATING ACTIVITIES\n\n';
            csvContent += 'Cash Inflows (Revenue)\n';
            csvContent += 'Account,Amount\n';
            const operating = currentCashFlowData.operating_activities;

            if (operating.revenue && operating.revenue.length > 0) {
                operating.revenue.forEach(rev => {
                    csvContent += `"${rev.name}","${rev.amount || 0}"\n`;
                });
            }
            csvContent += `"Total Revenue","${operating.total_revenue || 0}"\n\n`;

            csvContent += 'Cash Outflows (Operating Expenses)\n';
            csvContent += 'Category,Source System,Amount\n';
            if (operating.expenses_by_category && operating.expenses_by_category.length > 0) {
                operating.expenses_by_category.forEach(expense => {
                    csvContent += `"${expense.name}","${expense.sources || 'N/A'}","${expense.amount || 0}"\n`;
                });
            }
            csvContent += `"Total Operating Expenses","","${operating.total_expenses || 0}"\n\n`;

            csvContent += 'Detailed Expense Breakdown by Department\n';
            csvContent += 'Department,Category,Source,Amount,Transactions\n';
            if (operating.expense_details && operating.expense_details.length > 0) {
                operating.expense_details.forEach(detail => {
                    csvContent += `"${detail.department || 'General'}","${detail.expense_category}","${detail.source_system}","${detail.amount || 0}","${detail.transaction_count}"\n`;
                });
            }

            csvContent += `\n"Net Cash from Operating Activities","","","${operating.amount}"\n\n`;

            // Investing Activities - use top-level structure
            csvContent += 'INVESTING ACTIVITIES\n';
            csvContent += 'Account,Amount\n';
            const investing = currentCashFlowData.investing_activities;
            if (investing.accounts) {
                investing.accounts.forEach(account => {
                    csvContent += `"${account.account_name}","${account.amount || 0}"\n`;
                });
            }
            csvContent += `"Net Cash from Investing Activities","${investing.amount}"\n\n`;

            // Financing Activities - use top-level structure
            csvContent += 'FINANCING ACTIVITIES\n';
            csvContent += 'Account,Amount\n';
            const financing = currentCashFlowData.financing_activities;
            if (financing.accounts) {
                financing.accounts.forEach(account => {
                    csvContent += `"${account.account_name}","${account.amount || 0}"\n`;
                });
            }
            csvContent += `"Net Cash from Financing Activities","${financing.amount}"\n\n`;

            csvContent += `"Net Change in Cash","${currentCashFlowData.net_cash_flow}"\n`;

            // Download CSV
            const encodedUri = encodeURI(csvContent);
            const link = document.createElement('a');
            link.setAttribute('href', encodedUri);
            link.setAttribute('download', `cash_flow_${currentCashFlowData.start_date}_to_${currentCashFlowData.end_date}.csv`);
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);

            showAlert('Cash flow statement exported successfully', 'success');
        }

        // Export all reports at once
        function exportAllReports() {
            const format = 'csv';
            let exportCount = 0;

            // Export Income Statement if available
            if (currentIncomeStatementData) {
                exportIncomeStatement(format);
                exportCount++;
            }

            // Export Balance Sheet if available
            if (currentBalanceSheetData) {
                setTimeout(() => exportBalanceSheet(format), 500);
                exportCount++;
            }

            // Export Cash Flow if available
            if (currentCashFlowData) {
                setTimeout(() => exportCashFlow(format), 1000);
                exportCount++;
            }

            if (exportCount === 0) {
                showAlert('Please generate at least one report first', 'warning');
            } else {
                showAlert(`Exporting ${exportCount} report(s) as CSV files...`, 'success');
            }
        }

        // Export current active tab report as PDF
        function exportCurrentReportPDF() {
            const activeTab = document.querySelector('.nav-link.active');
            if (!activeTab) {
                showAlert('No active report tab found', 'warning');
                return;
            }

            const tabId = activeTab.getAttribute('id');

            switch(tabId) {
                case 'income-tab':
                    exportIncomeStatement('pdf');
                    break;
                case 'balance-tab':
                    exportBalanceSheet('pdf');
                    break;
                case 'cashflow-tab':
                    exportCashFlow('pdf');
                    break;
                default:
                    showAlert('Please switch to a report tab (Income, Balance Sheet, or Cash Flow)', 'info');
            }
        }

        // Export current active tab report as Excel (CSV format)
        function exportCurrentReportExcel() {
            const activeTab = document.querySelector('.nav-link.active');
            if (!activeTab) {
                showAlert('No active report tab found', 'warning');
                return;
            }

            const tabId = activeTab.getAttribute('id');

            switch(tabId) {
                case 'income-tab':
                    if (!currentIncomeStatementData) {
                        showAlert('Please generate the Income Statement first', 'warning');
                        return;
                    }
                    exportIncomeStatement('csv');
                    break;
                case 'balance-tab':
                    if (!currentBalanceSheetData) {
                        showAlert('Please generate the Balance Sheet first', 'warning');
                        return;
                    }
                    exportBalanceSheet('csv');
                    break;
                case 'cashflow-tab':
                    if (!currentCashFlowData) {
                        showAlert('Please generate the Cash Flow Statement first', 'warning');
                        return;
                    }
                    exportCashFlow('csv');
                    break;
                default:
                    showAlert('Please switch to a report tab (Income, Balance Sheet, or Cash Flow)', 'info');
            }
        }

        // Print current active report
        function printCurrentReport() {
            const activeTab = document.querySelector('.nav-link.active');
            if (!activeTab) {
                showAlert('No active report tab found', 'warning');
                return;
            }

            const tabId = activeTab.getAttribute('id');
            let printContainer;

            switch(tabId) {
                case 'income-tab':
                    printContainer = document.getElementById('incomeStatementContainer');
                    if (!currentIncomeStatementData) {
                        showAlert('Please generate the Income Statement first', 'warning');
                        return;
                    }
                    break;
                case 'balance-tab':
                    printContainer = document.getElementById('balanceSheetContainer');
                    if (!currentBalanceSheetData) {
                        showAlert('Please generate the Balance Sheet first', 'warning');
                        return;
                    }
                    break;
                case 'cashflow-tab':
                    printContainer = document.getElementById('cashFlowContainer');
                    if (!currentCashFlowData) {
                        showAlert('Please generate the Cash Flow Statement first', 'warning');
                        return;
                    }
                    break;
                default:
                    showAlert('Please switch to a report tab (Income, Balance Sheet, or Cash Flow)', 'info');
                    return;
            }

            // Open print dialog with the report content
            const printWindow = window.open('', '', 'height=600,width=800');
            printWindow.document.write('<html><head><title>Print Report</title>');
            printWindow.document.write('<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">');
            printWindow.document.write('<style>');
            printWindow.document.write(`
                body { font-family: Arial, sans-serif; padding: 20px; }
                .financial-statement { max-width: 800px; margin: 0 auto; }
                .statement-title { font-size: 24px; font-weight: bold; margin-bottom: 10px; }
                .statement-period { font-size: 14px; color: #666; margin-bottom: 20px; }
                .account-category { margin-bottom: 20px; }
                .account-category h6 { font-weight: bold; border-bottom: 2px solid #000; padding-bottom: 5px; }
                .account-item { display: flex; justify-content: space-between; padding: 5px 0; }
                .total-row { font-weight: bold; border-top: 1px solid #000; margin-top: 5px; padding-top: 5px; }
                @media print {
                    .no-print { display: none; }
                }
            `);
            printWindow.document.write('</style></head><body>');
            printWindow.document.write(printContainer.innerHTML);
            printWindow.document.write('<script src="../includes/tab_persistence.js?v=1"><\/script>');
            printWindow.document.write('</body></html>');
            printWindow.document.close();

            setTimeout(() => {
                printWindow.print();
            }, 250);

            showAlert('Opening print dialog...', 'info');
        }

        // Toggle expense detail breakdown in cash flow statement
        function toggleExpenseDetail(categoryId) {
            const detailDiv = document.getElementById(`detail-${categoryId}`);
            const icon = document.getElementById(`icon-${categoryId}`);

            if (detailDiv && icon) {
                if (detailDiv.classList.contains('d-none')) {
                    detailDiv.classList.remove('d-none');
                    icon.classList.remove('fa-chevron-down');
                    icon.classList.add('fa-chevron-up');
                } else {
                    detailDiv.classList.add('d-none');
                    icon.classList.remove('fa-chevron-up');
                    icon.classList.add('fa-chevron-down');
                }
            }
        }

        // Email current report
        function emailCurrentReport() {
            const activeTab = document.querySelector('.nav-link.active');
            if (!activeTab) {
                showAlert('No active report tab found', 'warning');
                return;
            }

            const tabId = activeTab.getAttribute('id');
            let reportName = '';
            let hasData = false;

            switch(tabId) {
                case 'income-tab':
                    reportName = 'Income Statement';
                    hasData = !!currentIncomeStatementData;
                    break;
                case 'balance-tab':
                    reportName = 'Balance Sheet';
                    hasData = !!currentBalanceSheetData;
                    break;
                case 'cashflow-tab':
                    reportName = 'Cash Flow Statement';
                    hasData = !!currentCashFlowData;
                    break;
                default:
                    showAlert('Please switch to a report tab (Income, Balance Sheet, or Cash Flow)', 'info');
                    return;
            }

            if (!hasData) {
                showAlert(`Please generate the ${reportName} first`, 'warning');
                return;
            }

            // Prompt for email address
            const email = prompt(`Enter email address to send ${reportName}:`);

            if (email && email.trim()) {
                // Validate email format
                const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                if (!emailRegex.test(email)) {
                    showAlert('Please enter a valid email address', 'warning');
                    return;
                }

                // Show loading indicator
                const loadingAlert = showAlert('Sending email...', 'info');

                // Get current date range from filters
                const dateFrom = document.getElementById('reportDateFrom')?.value || '';
                const dateTo = document.getElementById('reportDateTo')?.value || '';

                // Send email via API
                fetch('../api/reports.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'email',
                        report_type: tabId.replace('-tab', ''),
                        report_name: reportName,
                        email: email,
                        date_from: dateFrom,
                        date_to: dateTo
                    })
                })
                .then(response => response.json())
                .then(result => {
                    if (result.success) {
                        showAlert(`Report successfully sent to ${email}!`, 'success');
                    } else {
                        throw new Error(result.error || 'Failed to send email');
                    }
                })
                .catch(error => {
                    showAlert('Error sending email: ' + error.message, 'danger');
                });
            }
        }

        // Show alert function
        function showAlert(message, type = 'info') {
            // Create alert element
            const alertDiv = document.createElement('div');
            alertDiv.className = `alert alert-${type} alert-dismissible fade show position-fixed`;
            alertDiv.style.cssText = `
                top: 20px;
                right: 20px;
                z-index: 9999;
                max-width: 400px;
                box-shadow: 0 4px 12px rgba(0,0,0,0.3);
            `;

            const icons = {
                success: 'fas fa-check-circle',
                danger: 'fas fa-exclamation-triangle',
                warning: 'fas fa-exclamation-triangle',
                info: 'fas fa-info-circle'
            };

            alertDiv.innerHTML = `
                <i class="${icons[type] || 'fas fa-info-circle'} me-2"></i>
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

        let reportTrendsChart = null;

        // View charts functionality
        function viewCharts() {
            const analyticsTab = document.getElementById('analytics-tab');
            if (analyticsTab) {
                analyticsTab.click();
            }
            loadAnalyticsSummary();
        }

        function loadAnalyticsSummary() {
            fetch('../api/reports.php?type=analytics_summary')
                .then(response => {
                    if (!response.ok) {
                        throw new Error('HTTP ' + response.status);
                    }
                    return response.json();
                })
                .then(data => {
                    if (!data.success) {
                        showAlert(data.error || 'Failed to load analytics', 'danger');
                        return;
                    }

                    if (!data.mtd || !data.trend) {
                        showAlert('Invalid analytics response format', 'danger');
                        return;
                    }

                    const mtdRevenue = data.mtd.revenue || 0;
                    const mtdExpenses = data.mtd.expenses || 0;
                    const mtdNet = data.mtd.net || 0;

                    document.getElementById('analyticsMtdRevenue').textContent = '₱ ' + mtdRevenue.toLocaleString();
                    document.getElementById('analyticsMtdExpenses').textContent = '₱ ' + mtdExpenses.toLocaleString();
                    document.getElementById('analyticsNetResult').textContent = '₱ ' + mtdNet.toLocaleString();
                    document.getElementById('analyticsNetResult').className = 'fw-bold fs-4 ' + (mtdNet >= 0 ? 'text-success' : 'text-danger');

                    const ctx = document.getElementById('reportTrendsChart');
                    if (!ctx) return;

                    if (reportTrendsChart) {
                        reportTrendsChart.destroy();
                    }

                    reportTrendsChart = new Chart(ctx.getContext('2d'), {
                        type: 'line',
                        data: {
                            labels: data.trend.labels,
                            datasets: [
                                {
                                    label: 'Revenue',
                                    data: data.trend.revenue,
                                    borderColor: 'rgba(40, 167, 69, 1)',
                                    backgroundColor: 'rgba(40, 167, 69, 0.1)',
                                    tension: 0.3,
                                    fill: true
                                },
                                {
                                    label: 'Expenses',
                                    data: data.trend.expenses,
                                    borderColor: 'rgba(220, 53, 69, 1)',
                                    backgroundColor: 'rgba(220, 53, 69, 0.1)',
                                    tension: 0.3,
                                    fill: true
                                }
                            ]
                        },
                        options: {
                            responsive: true,
                            plugins: {
                                legend: {
                                    position: 'top'
                                }
                            },
                            scales: {
                                y: {
                                    beginAtZero: true,
                                    ticks: {
                                        callback: function(value) {
                                            return '₱' + value.toLocaleString();
                                        }
                                    }
                                }
                            }
                        }
                    });
                })
                .catch(error => {
                    console.error('Error loading analytics:', error);
                    showAlert('Error loading analytics: ' + error.message, 'danger');
                });
        }

        document.addEventListener('DOMContentLoaded', function() {
            const analyticsTab = document.getElementById('analytics-tab');
            if (analyticsTab) {
                analyticsTab.addEventListener('shown.bs.tab', loadAnalyticsSummary);
            }
        });
    </script>

    <script src="../includes/financial_hq_state.js"></script>
    <script>
        (function() {
            const reportStyle = document.createElement('style');
            reportStyle.textContent = `
                .statement-shell { display: grid; gap: 1rem; }
                .statement-metrics { display: grid; gap: 1rem; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); }
                .statement-metric-card { background: #f8fafc; border: 1px solid #d7dee7; border-radius: 12px; padding: 1rem; }
                .statement-metric-card span { display: block; color: #5c6773; font-size: 0.9rem; }
                .statement-metric-card strong { display: block; font-size: 1.25rem; color: #1e2936; margin-top: 0.35rem; }
                .statement-section-card { border: 1px solid #d7dee7; border-radius: 12px; overflow: hidden; background: #fff; }
                .statement-section-card .card-header { background: #eef3f8; border-bottom: 1px solid #d7dee7; padding: 0.85rem 1rem; font-weight: 600; }
                .statement-breakdown { border-top: 1px solid #e8edf2; padding: 0.85rem 1rem; }
                .statement-breakdown summary { cursor: pointer; color: #1e2936; font-weight: 600; }
                .statement-table { margin: 0; }
                .statement-table th, .statement-table td { vertical-align: middle; }
                .statement-total-row td { font-weight: 700; background: #f4f7fb; }
            `;
            document.head.appendChild(reportStyle);

            function statementEscape(value) {
                return String(value ?? '')
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#39;');
            }

            function statementMoney(value) {
                return 'PHP ' + Number(value || 0).toLocaleString('en-US', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                });
            }

            function appendReportPeriodOptions(selectId) {
                const select = document.getElementById(selectId);
                if (!select) {
                    return;
                }
                const additions = [
                    ['month_to_date', 'Month-to-Date'],
                    ['year_to_date', 'Year-to-Date'],
                    ['biweekly', 'Bi-Weekly'],
                    ['last_90_days', 'Last 90 Days']
                ];
                additions.forEach(([value, label]) => {
                    if (!select.querySelector(`option[value="${value}"]`)) {
                        const option = document.createElement('option');
                        option.value = value;
                        option.textContent = label;
                        select.insertBefore(option, select.querySelector('option[value="custom"]'));
                    }
                });
            }

            function groupRows(items, keyGetter, amountGetter, mapFn) {
                const grouped = new Map();
                (items || []).forEach(item => {
                    const key = keyGetter(item);
                    const amount = Number(amountGetter(item) || 0);
                    if (!grouped.has(key)) {
                        grouped.set(key, { key, amount: 0, rows: [] });
                    }
                    grouped.get(key).amount += amount;
                    grouped.get(key).rows.push(item);
                });
                return Array.from(grouped.values()).map(group => mapFn(group)).filter(row => Number(row.amount || 0) !== 0);
            }

            function withinDateRange(value, from, to) {
                const dateOnly = String(value || '').slice(0, 10);
                return (!from || dateOnly >= from) && (!to || dateOnly <= to);
            }

            window.getDateRange = function(period) {
                const now = new Date();
                let from;
                let to;
                function startOfWeek(date) {
                    const next = new Date(date);
                    const day = next.getDay();
                    const diff = day === 0 ? -6 : 1 - day;
                    next.setDate(next.getDate() + diff);
                    next.setHours(0, 0, 0, 0);
                    return next;
                }

                switch (period) {
                    case 'daily':
                        from = new Date(now.getFullYear(), now.getMonth(), now.getDate());
                        to = new Date(from);
                        break;
                    case 'weekly':
                        from = startOfWeek(now);
                        to = new Date(from);
                        to.setDate(from.getDate() + 6);
                        break;
                    case 'biweekly':
                        to = new Date(now.getFullYear(), now.getMonth(), now.getDate());
                        from = new Date(to);
                        from.setDate(to.getDate() - 13);
                        break;
                    case 'monthly':
                    case 'month_to_date':
                        from = new Date(now.getFullYear(), now.getMonth(), 1);
                        to = period === 'month_to_date' ? new Date(now) : new Date(now.getFullYear(), now.getMonth() + 1, 0);
                        break;
                    case 'quarterly':
                        const quarterStartMonth = Math.floor(now.getMonth() / 3) * 3;
                        from = new Date(now.getFullYear(), quarterStartMonth, 1);
                        to = new Date(now.getFullYear(), quarterStartMonth + 3, 0);
                        break;
                    case 'semi_annually':
                        if (now.getMonth() < 6) {
                            from = new Date(now.getFullYear(), 0, 1);
                            to = new Date(now.getFullYear(), 6, 0);
                        } else {
                            from = new Date(now.getFullYear(), 6, 1);
                            to = new Date(now.getFullYear(), 12, 0);
                        }
                        break;
                    case 'annually':
                    case 'year_to_date':
                        from = new Date(now.getFullYear(), 0, 1);
                        to = period === 'year_to_date' ? new Date(now) : new Date(now.getFullYear(), 11, 31);
                        break;
                    case 'last_90_days':
                        to = new Date(now.getFullYear(), now.getMonth(), now.getDate());
                        from = new Date(to);
                        from.setDate(to.getDate() - 89);
                        break;
                    default:
                        from = new Date(now.getFullYear(), now.getMonth(), 1);
                        to = new Date(now.getFullYear(), now.getMonth() + 1, 0);
                }

                return {
                    from: from.toISOString().slice(0, 10),
                    to: to.toISOString().slice(0, 10)
                };
            };

            function buildFallbackIncomeStatement(dateFrom, dateTo) {
                const invoices = (window.FinancialHQState?.getInvoices?.() || []).filter(item => withinDateRange(item.invoice_date, dateFrom, dateTo));
                const bills = (window.FinancialHQState?.getBills?.() || []).filter(item => withinDateRange(item.bill_date, dateFrom, dateTo));
                const disbursements = (window.FinancialHQState?.getDisbursements?.() || []).filter(item => withinDateRange(item.disbursement_date, dateFrom, dateTo));

                const revenueRows = groupRows(
                    invoices,
                    item => item.customer_name || item.customer_code || item.invoice_number,
                    item => item.total_amount,
                    group => ({
                        account_name: group.key,
                        amount: group.amount,
                        source: 'Accounts Receivable invoices'
                    })
                );

                const cogsRows = groupRows(
                    bills.filter(item => /(food|supply|inventory|ingredients)/i.test(item.vendor_name || '')),
                    item => item.vendor_name || item.bill_number,
                    item => item.amount || item.total_amount,
                    group => ({
                        account_name: group.key,
                        amount: group.amount,
                        source: 'Accounts Payable bills'
                    })
                );

                const operatingExpenseRows = groupRows(
                    bills.filter(item => !/(food|supply|inventory|ingredients)/i.test(item.vendor_name || '')).concat(disbursements),
                    item => item.vendor_name || item.payee || item.disbursement_number || 'Expense',
                    item => item.amount || item.total_amount,
                    group => ({
                        account_name: group.key,
                        amount: group.amount,
                        source: 'Disbursements and operating payables'
                    })
                );

                const totalRevenue = revenueRows.reduce((sum, row) => sum + Number(row.amount || 0), 0);
                const totalCogs = cogsRows.reduce((sum, row) => sum + Number(row.amount || 0), 0);
                const totalExpenses = operatingExpenseRows.reduce((sum, row) => sum + Number(row.amount || 0), 0);
                const grossProfit = totalRevenue - totalCogs;
                const netProfit = grossProfit - totalExpenses;

                return {
                    report_type: 'Income Statement',
                    source: 'fallback',
                    date_from: dateFrom,
                    date_to: dateTo,
                    generated_at: new Date().toISOString(),
                    revenue: { accounts: revenueRows, total: totalRevenue },
                    cogs: { accounts: cogsRows, total: totalCogs },
                    expenses: { accounts: operatingExpenseRows, total: totalExpenses },
                    gross_profit: grossProfit,
                    net_profit: netProfit
                };
            }

            function buildFallbackBalanceSheet(asOfDate) {
                const invoices = (window.FinancialHQState?.getInvoices?.() || []).filter(item => String(item.invoice_date || '') <= asOfDate);
                const bills = (window.FinancialHQState?.getBills?.() || []).filter(item => String(item.bill_date || '') <= asOfDate);
                const received = (window.FinancialHQState?.getPaymentsReceived?.() || []).filter(item => String(item.payment_date || '') <= asOfDate);
                const made = (window.FinancialHQState?.getPaymentsMade?.() || []).filter(item => String(item.payment_date || '') <= asOfDate);
                const disbursements = (window.FinancialHQState?.getDisbursements?.() || []).filter(item => String(item.disbursement_date || '') <= asOfDate);
                const payrollPending = (window.FinancialHQState?.getHrPayroll?.() || []).filter(item => String(item.status || '').toLowerCase() === 'pending');

                const cashBalance = received.reduce((sum, row) => sum + Number(row.amount || 0), 0)
                    - made.reduce((sum, row) => sum + Number(row.amount || 0), 0)
                    - disbursements.reduce((sum, row) => sum + Number(row.amount || 0), 0);
                const receivablesBalance = invoices.reduce((sum, row) => sum + Number(row.balance ?? row.total_amount ?? 0), 0);
                const payableBalance = bills.reduce((sum, row) => sum + Number(row.balance ?? row.amount ?? row.total_amount ?? 0), 0);
                const payrollLiability = payrollPending.reduce((sum, row) => sum + Number(row.total_amount || 0), 0);
                const retainedEarnings = buildFallbackIncomeStatement('2026-01-01', asOfDate).net_profit;

                const assetsAccounts = [
                    { account_name: 'Cash Position', account_balance: cashBalance, source: 'Collections less disbursements' },
                    { account_name: 'Outstanding Receivables', account_balance: receivablesBalance, source: 'Customer invoice balances' }
                ];
                const liabilitiesAccounts = [
                    { account_name: 'Accounts Payable', account_balance: payableBalance, source: 'Open vendor bills' },
                    { account_name: 'Pending Payroll', account_balance: payrollLiability, source: 'Payroll approvals pending release' }
                ];
                const totalAssets = assetsAccounts.reduce((sum, row) => sum + Number(row.account_balance || 0), 0);
                const totalLiabilities = liabilitiesAccounts.reduce((sum, row) => sum + Number(row.account_balance || 0), 0);
                const equityPlug = totalAssets - totalLiabilities;
                const equityAccounts = [
                    { account_name: 'Retained Earnings', account_balance: retainedEarnings, source: 'Year-to-date net profit' },
                    { account_name: 'Operational Equity Reserve', account_balance: equityPlug - retainedEarnings, source: 'Balancing equity position' }
                ];
                const totalEquity = equityAccounts.reduce((sum, row) => sum + Number(row.account_balance || 0), 0);

                return {
                    report_type: 'Balance Sheet',
                    source: 'fallback',
                    date_from: '2026-01-01',
                    date_to: asOfDate,
                    as_of_date: asOfDate,
                    generated_at: new Date().toISOString(),
                    assets: { accounts: assetsAccounts, total: totalAssets },
                    liabilities: { accounts: liabilitiesAccounts, total: totalLiabilities },
                    equity: { accounts: equityAccounts, retained_earnings: retainedEarnings, total: totalEquity },
                    total_liabilities_equity: totalLiabilities + totalEquity
                };
            }

            function buildFallbackCashFlow(dateFrom, dateTo) {
                const collections = (window.FinancialHQState?.getPaymentsReceived?.() || []).filter(item => withinDateRange(item.payment_date, dateFrom, dateTo));
                const paymentsMade = (window.FinancialHQState?.getPaymentsMade?.() || []).filter(item => withinDateRange(item.payment_date, dateFrom, dateTo));
                const disbursements = (window.FinancialHQState?.getDisbursements?.() || []).filter(item => withinDateRange(item.disbursement_date, dateFrom, dateTo));

                const revenueRows = groupRows(
                    collections,
                    item => item.customer_name || item.invoice_number || item.payment_number,
                    item => item.amount,
                    group => ({ name: group.key, amount: group.amount, source: 'Collections' })
                );

                const expenseRows = groupRows(
                    paymentsMade.concat(disbursements),
                    item => item.vendor_name || item.payee || item.payment_number || item.disbursement_number,
                    item => item.amount,
                    group => ({
                        name: group.key,
                        subcategory: group.key,
                        amount: group.amount,
                        sources: 'Finance'
                    })
                );

                const expenseDetails = expenseRows.map(row => ({
                    department: row.name,
                    expense_category: row.subcategory,
                    source_system: 'FINANCE',
                    amount: row.amount,
                    transaction_count: 1
                }));

                const totalRevenue = revenueRows.reduce((sum, row) => sum + Number(row.amount || 0), 0);
                const totalExpenses = expenseRows.reduce((sum, row) => sum + Number(row.amount || 0), 0);
                const operatingAmount = totalRevenue - totalExpenses;

                return {
                    report_type: 'Cash Flow Statement',
                    source: 'fallback',
                    start_date: dateFrom,
                    end_date: dateTo,
                    date_from: dateFrom,
                    date_to: dateTo,
                    generated_at: new Date().toISOString(),
                    operating_activities: {
                        revenue: revenueRows,
                        total_revenue: totalRevenue,
                        expenses_by_category: expenseRows,
                        expense_details: expenseDetails,
                        total_expenses: totalExpenses,
                        amount: operatingAmount
                    },
                    investing_activities: {
                        accounts: [{ account_name: 'Capital Equipment Activity', amount: 0 }],
                        amount: 0
                    },
                    financing_activities: {
                        accounts: [{ account_name: 'Working Capital Movement', amount: 0 }],
                        amount: 0
                    },
                    cash_flow: {
                        operating_activities: {
                            revenue: revenueRows,
                            total_revenue: totalRevenue,
                            expenses_by_category: expenseRows,
                            expense_details: expenseDetails,
                            total_expenses: totalExpenses,
                            amount: operatingAmount
                        },
                        investing_activities: { accounts: [{ account_name: 'Capital Equipment Activity', amount: 0 }], amount: 0 },
                        financing_activities: { accounts: [{ account_name: 'Working Capital Movement', amount: 0 }], amount: 0 },
                        net_change: operatingAmount
                    },
                    net_cash_flow: operatingAmount
                };
            }

            function buildSummaryCards(items) {
                return `
                    <div class="statement-metrics">
                        ${items.map(item => `
                            <div class="statement-metric-card">
                                <span>${statementEscape(item.label)}</span>
                                <strong>${statementEscape(item.value)}</strong>
                            </div>
                        `).join('')}
                    </div>
                `;
            }

            function buildBreakdownTable(title, rows, totalLabel, totalValue, valueKey) {
                const amountField = valueKey || 'amount';
                return `
                    <div class="statement-section-card">
                        <div class="card-header">${statementEscape(title)}</div>
                        <div class="table-responsive">
                            <table class="table table-striped statement-table">
                                <thead>
                                    <tr>
                                        <th>Source</th>
                                        <th class="text-end">Amount</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${rows.length ? rows.map(row => `
                                        <tr>
                                            <td>${statementEscape(row.account_name || row.name || row.department || 'N/A')}</td>
                                            <td class="text-end">${statementMoney(row[amountField] || row.account_balance || 0)}</td>
                                        </tr>
                                    `).join('') : '<tr><td colspan="2" class="text-center text-muted">No data in this section.</td></tr>'}
                                    <tr class="statement-total-row">
                                        <td>${statementEscape(totalLabel)}</td>
                                        <td class="text-end">${statementMoney(totalValue || 0)}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                `;
            }

            window.renderIncomeStatement = function(data) {
                const container = document.getElementById('incomeStatementContainer');
                if (!container) {
                    return;
                }
                const revenueRows = data.revenue?.accounts || [];
                const cogsRows = data.cogs?.accounts || [];
                const expenseRows = data.expenses?.accounts || [];
                const grossProfit = data.gross_profit ?? ((data.revenue?.total || 0) - (data.cogs?.total || 0));
                container.innerHTML = `
                    <div class="statement-header">
                        <h1 class="statement-title">Profit & Loss Statement</h1>
                        <p class="statement-period">For the period ${formatDate(data.date_from)} to ${formatDate(data.date_to)}</p>
                    </div>
                    <div class="statement-shell">
                        ${buildSummaryCards([
                            { label: 'Total Revenue', value: statementMoney(data.revenue?.total || 0) },
                            { label: 'Total COGS', value: statementMoney(data.cogs?.total || 0) },
                            { label: 'Gross Profit', value: statementMoney(grossProfit) },
                            { label: 'Operating Expenses', value: statementMoney(data.expenses?.total || 0) },
                            { label: 'Net Profit', value: statementMoney(data.net_profit || 0) }
                        ])}
                        ${buildBreakdownTable('Revenue Breakdown', revenueRows, 'Total Revenue', data.revenue?.total || 0, 'amount')}
                        <details class="statement-breakdown"><summary>Total Revenue sources</summary>${buildBreakdownTable('Total Revenue Sources', revenueRows, 'Total Revenue', data.revenue?.total || 0, 'amount')}</details>
                        ${buildBreakdownTable('Cost of Goods Sold', cogsRows, 'Total COGS', data.cogs?.total || 0, 'amount')}
                        <details class="statement-breakdown"><summary>Total COGS sources</summary>${buildBreakdownTable('COGS Sources', cogsRows, 'Total COGS', data.cogs?.total || 0, 'amount')}</details>
                        ${buildBreakdownTable('Operating Expenses', expenseRows, 'Total Operating Expenses', data.expenses?.total || 0, 'amount')}
                        <details class="statement-breakdown"><summary>Operating expense sources</summary>${buildBreakdownTable('Operating Expense Sources', expenseRows, 'Total Operating Expenses', data.expenses?.total || 0, 'amount')}</details>
                        <details class="statement-breakdown"><summary>Gross Profit and Net Profit drivers</summary>
                            <div class="statement-section-card">
                                <div class="card-header">Profit Bridge</div>
                                <div class="table-responsive">
                                    <table class="table table-striped statement-table">
                                        <tbody>
                                            <tr><td>Total Revenue</td><td class="text-end">${statementMoney(data.revenue?.total || 0)}</td></tr>
                                            <tr><td>Less: Total COGS</td><td class="text-end">${statementMoney(data.cogs?.total || 0)}</td></tr>
                                            <tr class="statement-total-row"><td>Gross Profit</td><td class="text-end">${statementMoney(grossProfit)}</td></tr>
                                            <tr><td>Less: Operating Expenses</td><td class="text-end">${statementMoney(data.expenses?.total || 0)}</td></tr>
                                            <tr class="statement-total-row"><td>Net Profit</td><td class="text-end">${statementMoney(data.net_profit || 0)}</td></tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </details>
                    </div>
                `;
                setTimeout(function() {
                    if (typeof PrivacyMode !== 'undefined') {
                        PrivacyMode.hide();
                    }
                }, 10);
            };

            window.renderBalanceSheet = function(data) {
                const container = document.getElementById('balanceSheetContainer');
                if (!container) {
                    return;
                }
                container.innerHTML = `
                    <div class="statement-header">
                        <h1 class="statement-title">Balance Sheet</h1>
                        <p class="statement-period">As of ${formatDate(data.as_of_date)}</p>
                    </div>
                    <div class="statement-shell">
                        ${buildSummaryCards([
                            { label: 'Total Assets', value: statementMoney(data.assets?.total || 0) },
                            { label: 'Total Liabilities', value: statementMoney(data.liabilities?.total || 0) },
                            { label: 'Total Equity', value: statementMoney(data.equity?.total || 0) },
                            { label: 'Liabilities + Equity', value: statementMoney(data.total_liabilities_equity || 0) }
                        ])}
                        ${buildBreakdownTable('Assets', data.assets?.accounts || [], 'Total Assets', data.assets?.total || 0, 'account_balance')}
                        <details class="statement-breakdown"><summary>Asset source breakdown</summary>${buildBreakdownTable('Asset Sources', data.assets?.accounts || [], 'Total Assets', data.assets?.total || 0, 'account_balance')}</details>
                        ${buildBreakdownTable('Liabilities', data.liabilities?.accounts || [], 'Total Liabilities', data.liabilities?.total || 0, 'account_balance')}
                        <details class="statement-breakdown"><summary>Liability source breakdown</summary>${buildBreakdownTable('Liability Sources', data.liabilities?.accounts || [], 'Total Liabilities', data.liabilities?.total || 0, 'account_balance')}</details>
                        ${buildBreakdownTable('Equity', data.equity?.accounts || [], 'Total Equity', data.equity?.total || 0, 'account_balance')}
                        <details class="statement-breakdown"><summary>Equity source breakdown</summary>${buildBreakdownTable('Equity Sources', data.equity?.accounts || [], 'Total Equity', data.equity?.total || 0, 'account_balance')}</details>
                    </div>
                `;
                setTimeout(function() {
                    if (typeof PrivacyMode !== 'undefined') {
                        PrivacyMode.hide();
                    }
                }, 10);
            };

            window.renderCashFlow = function(data) {
                const container = document.getElementById('cashFlowContainer');
                if (!container) {
                    return;
                }
                const operating = data.operating_activities || {};
                const investing = data.investing_activities || {};
                const financing = data.financing_activities || {};
                container.innerHTML = `
                    <div class="statement-header">
                        <h1 class="statement-title">Cash Flow Statement</h1>
                        <p class="statement-period">For the period ${formatDate(data.start_date)} to ${formatDate(data.end_date)}</p>
                    </div>
                    <div class="statement-shell">
                        ${buildSummaryCards([
                            { label: 'Cash Inflows', value: statementMoney(operating.total_revenue || 0) },
                            { label: 'Cash Outflows', value: statementMoney(operating.total_expenses || 0) },
                            { label: 'Operating Cash Flow', value: statementMoney(operating.amount || 0) },
                            { label: 'Net Cash Flow', value: statementMoney(data.net_cash_flow || 0) }
                        ])}
                        ${buildBreakdownTable('Operating Cash Inflows', operating.revenue || [], 'Total Cash Inflows', operating.total_revenue || 0, 'amount')}
                        <details class="statement-breakdown"><summary>Inflows source breakdown</summary>${buildBreakdownTable('Inflows Source Breakdown', operating.revenue || [], 'Total Cash Inflows', operating.total_revenue || 0, 'amount')}</details>
                        ${buildBreakdownTable('Operating Cash Outflows', operating.expenses_by_category || [], 'Total Cash Outflows', operating.total_expenses || 0, 'amount')}
                        <details class="statement-breakdown"><summary>Outflows source breakdown</summary>${buildBreakdownTable('Outflows Source Breakdown', operating.expense_details || [], 'Total Cash Outflows', operating.total_expenses || 0, 'amount')}</details>
                        ${buildBreakdownTable('Investing Activities', investing.accounts || [], 'Net Cash from Investing Activities', investing.amount || 0, 'amount')}
                        ${buildBreakdownTable('Financing Activities', financing.accounts || [], 'Net Cash from Financing Activities', financing.amount || 0, 'amount')}
                    </div>
                `;
                setTimeout(function() {
                    if (typeof PrivacyMode !== 'undefined') {
                        PrivacyMode.hide();
                    }
                }, 10);
            };

            function openReportPrintWindow(title, html) {
                const printWindow = window.open('', '_blank', 'width=1100,height=800');
                if (!printWindow) {
                    showAlert('Unable to open print preview window.', 'warning');
                    return;
                }
                printWindow.document.write(`
                    <html>
                    <head>
                        <title>${statementEscape(title)}</title>
                        <style>
                            body { font-family: Arial, sans-serif; padding: 24px; color: #1e2936; }
                            table { width: 100%; border-collapse: collapse; margin: 12px 0 18px; }
                            th, td { border: 1px solid #d0d7de; padding: 8px 10px; text-align: left; }
                            .statement-metrics { display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; margin-bottom: 16px; }
                            .statement-metric-card { border: 1px solid #d0d7de; padding: 12px; border-radius: 8px; }
                            .card-header { font-weight: 700; margin-bottom: 8px; }
                            details { margin-bottom: 12px; }
                        </style>
                    </head>
                    <body>${html}</body>
                    </html>
                `);
                printWindow.document.close();
                printWindow.focus();
                printWindow.print();
            }

            function downloadCsv(filename, headers, rows) {
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
            }

            window.generateIncomeStatement = async function() {
                const periodSelect = document.getElementById('incomePeriodSelect');
                let dateFrom;
                let dateTo;
                if (periodSelect?.value === 'custom') {
                    dateFrom = document.getElementById('incomeFromDate')?.value || '';
                    dateTo = document.getElementById('incomeToDate')?.value || '';
                } else {
                    const range = getDateRange(periodSelect?.value || 'monthly');
                    dateFrom = range.from;
                    dateTo = range.to;
                }

                try {
                    const response = await fetch(`../api/reports.php?type=income_statement&date_from=${dateFrom}&date_to=${dateTo}`);
                    const data = await response.json();
                    if (!response.ok || data.error) {
                        throw new Error(data.error || 'Unable to generate income statement');
                    }
                    currentIncomeStatementData = {
                        ...data,
                        cogs: data.cogs || { accounts: [], total: 0 },
                        gross_profit: data.gross_profit ?? ((data.revenue?.total || 0) - (data.cogs?.total || 0))
                    };
                } catch (error) {
                    currentIncomeStatementData = buildFallbackIncomeStatement(dateFrom, dateTo);
                }
                renderIncomeStatement(currentIncomeStatementData);
            };

            window.generateBalanceSheet = async function() {
                const periodSelect = document.getElementById('balanceDateSelect');
                let asOfDate;
                if (periodSelect?.value === 'custom') {
                    asOfDate = document.getElementById('balanceToDate')?.value || document.getElementById('balanceFromDate')?.value || new Date().toISOString().slice(0, 10);
                } else {
                    asOfDate = getDateRange(periodSelect?.value || 'monthly').to;
                }

                try {
                    const response = await fetch(`../api/reports.php?type=balance_sheet&as_of_date=${asOfDate}`);
                    const data = await response.json();
                    if (!response.ok || data.error) {
                        throw new Error(data.error || 'Unable to generate balance sheet');
                    }
                    currentBalanceSheetData = data;
                } catch (error) {
                    currentBalanceSheetData = buildFallbackBalanceSheet(asOfDate);
                }
                renderBalanceSheet(currentBalanceSheetData);
            };

            window.generateCashFlow = async function() {
                const periodSelect = document.getElementById('cashFlowPeriodSelect');
                let dateFrom;
                let dateTo;
                if (periodSelect?.value === 'custom') {
                    dateFrom = document.getElementById('cashFlowFromDate')?.value || '';
                    dateTo = document.getElementById('cashFlowToDate')?.value || '';
                } else {
                    const range = getDateRange(periodSelect?.value || 'monthly');
                    dateFrom = range.from;
                    dateTo = range.to;
                }

                try {
                    const response = await fetch(`../api/reports.php?type=cash_flow&date_from=${dateFrom}&date_to=${dateTo}`);
                    const data = await response.json();
                    if (!response.ok || data.error) {
                        throw new Error(data.error || 'Unable to generate cash flow');
                    }
                    currentCashFlowData = data;
                } catch (error) {
                    currentCashFlowData = buildFallbackCashFlow(dateFrom, dateTo);
                }
                renderCashFlow(currentCashFlowData);
            };

            window.exportIncomeStatement = function(format) {
                if (!currentIncomeStatementData) {
                    showAlert('Generate the Profit & Loss statement first.', 'warning');
                    return;
                }
                if (format === 'pdf') {
                    openReportPrintWindow('Profit & Loss Statement', document.getElementById('incomeStatementContainer').innerHTML);
                    return;
                }
                const rows = [
                    ...(currentIncomeStatementData.revenue?.accounts || []).map(row => ['Revenue', row.account_name, Number(row.amount || 0).toFixed(2)]),
                    ...(currentIncomeStatementData.cogs?.accounts || []).map(row => ['COGS', row.account_name, Number(row.amount || 0).toFixed(2)]),
                    ...(currentIncomeStatementData.expenses?.accounts || []).map(row => ['Operating Expense', row.account_name, Number(row.amount || 0).toFixed(2)]),
                    ['Summary', 'Gross Profit', Number(currentIncomeStatementData.gross_profit || 0).toFixed(2)],
                    ['Summary', 'Net Profit', Number(currentIncomeStatementData.net_profit || 0).toFixed(2)]
                ];
                downloadCsv(`income_statement_${currentIncomeStatementData.date_from}_to_${currentIncomeStatementData.date_to}.csv`, ['Section', 'Source', 'Amount'], rows);
            };

            window.exportBalanceSheet = function(format) {
                if (!currentBalanceSheetData) {
                    showAlert('Generate the Balance Sheet first.', 'warning');
                    return;
                }
                if (format === 'pdf') {
                    openReportPrintWindow('Balance Sheet', document.getElementById('balanceSheetContainer').innerHTML);
                    return;
                }
                const rows = [
                    ...(currentBalanceSheetData.assets?.accounts || []).map(row => ['Asset', row.account_name, Number(row.account_balance || 0).toFixed(2)]),
                    ...(currentBalanceSheetData.liabilities?.accounts || []).map(row => ['Liability', row.account_name, Number(row.account_balance || 0).toFixed(2)]),
                    ...(currentBalanceSheetData.equity?.accounts || []).map(row => ['Equity', row.account_name, Number(row.account_balance || 0).toFixed(2)]),
                    ['Summary', 'Total Liabilities + Equity', Number(currentBalanceSheetData.total_liabilities_equity || 0).toFixed(2)]
                ];
                downloadCsv(`balance_sheet_${currentBalanceSheetData.as_of_date}.csv`, ['Section', 'Source', 'Amount'], rows);
            };

            window.exportCashFlow = function(format) {
                if (!currentCashFlowData) {
                    showAlert('Generate the Cash Flow statement first.', 'warning');
                    return;
                }
                if (format === 'pdf') {
                    openReportPrintWindow('Cash Flow Statement', document.getElementById('cashFlowContainer').innerHTML);
                    return;
                }
                const rows = [
                    ...(currentCashFlowData.operating_activities?.revenue || []).map(row => ['Operating Inflow', row.name, Number(row.amount || 0).toFixed(2)]),
                    ...(currentCashFlowData.operating_activities?.expenses_by_category || []).map(row => ['Operating Outflow', row.name, Number(row.amount || 0).toFixed(2)]),
                    ...(currentCashFlowData.investing_activities?.accounts || []).map(row => ['Investing', row.account_name, Number(row.amount || 0).toFixed(2)]),
                    ...(currentCashFlowData.financing_activities?.accounts || []).map(row => ['Financing', row.account_name, Number(row.amount || 0).toFixed(2)]),
                    ['Summary', 'Net Cash Flow', Number(currentCashFlowData.net_cash_flow || 0).toFixed(2)]
                ];
                downloadCsv(`cash_flow_${currentCashFlowData.start_date}_to_${currentCashFlowData.end_date}.csv`, ['Section', 'Source', 'Amount'], rows);
            };

            document.addEventListener('DOMContentLoaded', function() {
                ['incomePeriodSelect', 'balanceDateSelect', 'cashFlowPeriodSelect'].forEach(appendReportPeriodOptions);
            });
        })();
    </script>

    <script src="../includes/inactivity_timeout.js?v=3"></script>
<script src="../includes/navbar_datetime.js"></script>
<script src="../includes/tab_persistence.js?v=1"></script>
 </body>
 </html>







