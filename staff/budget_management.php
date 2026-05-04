<?php
require_once '../includes/auth.php';
require_once '../includes/database.php';

if (!isset($_SESSION['user'])) {
    header('Location: ../index.php');
    exit;
}

// Initialize database connection
$db = Database::getInstance()->getConnection();

$budgetRequestedByName = trim(
    ($_SESSION['user']['first_name'] ?? '') . ' ' . ($_SESSION['user']['last_name'] ?? '')
);
if ($budgetRequestedByName === '') {
    $budgetRequestedByName = $_SESSION['user']['full_name'] ?? ($_SESSION['user']['username'] ?? 'User');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Financial Management System - Budget Management</title>
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
            z-index: 1030;
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

        .modal {
            z-index: 5000;
            pointer-events: auto;
        }

        .modal-backdrop {
            z-index: 4990;
            pointer-events: none;
        }

        .modal-backdrop.show {
            opacity: 0.15;
        }

        .modal-dialog,
        .modal-content,
        .modal * {
            pointer-events: auto;
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

        .tracking-card {
            display: grid;
            grid-template-rows: auto auto auto;
            place-items: center;
            min-height: 190px;
            gap: 0.35rem;
        }

        .tracking-card i {
            line-height: 1;
        }

        .tracking-card h6 {
            margin: 0;
        }

        .tracking-card h3 {
            margin: 0;
            width: 100%;
            text-align: center;
            line-height: 1.05;
            font-variant-numeric: tabular-nums;
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
            .budget-overview-card .card-body {
                grid-template-columns: 1fr;
            }

            .budget-overview-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

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

        /* Budget-specific styles */
        .budget-progress {
            height: 8px;
            border-radius: 4px;
            background-color: #e9ecef;
            overflow: hidden;
        }

        .budget-progress-bar {
            height: 100%;
            border-radius: 4px;
            transition: width 0.3s ease;
        }

        .budget-over .budget-progress-bar {
            background: linear-gradient(90deg, #dc3545, #fd7e14);
        }

        .budget-under .budget-progress-bar {
            background: linear-gradient(90deg, #28a745, #20c997);
        }

        .budget-on-track .budget-progress-bar {
            background: linear-gradient(90deg, #ffc107, #fd7e14);
        }

        .variance-positive {
            color: #dc3545;
            font-weight: 600;
        }

        .variance-negative {
            color: #28a745;
            font-weight: 600;
        }

        .forecast-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .forecast-card.black-forecast h3 {
            color: black !important;
        }

        .forecast-card .card-body {
            padding: 2.5rem;
        }

        .budget-overview-card {
            margin-bottom: 1.5rem;
            overflow: hidden;
        }

        .budget-overview-card .card-body {
            display: grid;
            grid-template-columns: minmax(240px, 1.2fr) minmax(0, 1.8fr);
            gap: 1.5rem;
            align-items: center;
            padding: 1.5rem 1.75rem;
        }

        .budget-overview-copy h4 {
            margin: 0 0 0.35rem;
            color: #1e2936;
            font-weight: 800;
            letter-spacing: -0.02em;
        }

        .budget-overview-copy p {
            margin: 0;
            color: #64748b;
            max-width: 42rem;
        }

        .budget-overview-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 0.85rem;
        }

        .budget-overview-pill {
            background: linear-gradient(145deg, rgba(30, 41, 54, 0.04), rgba(255, 255, 255, 0.92));
            border: 1px solid rgba(30, 41, 54, 0.08);
            border-radius: 16px;
            padding: 1rem 1.1rem;
            min-height: 106px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            gap: 0.45rem;
        }

        .budget-overview-pill span {
            font-size: 0.78rem;
            font-weight: 700;
            color: #64748b;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        .budget-overview-pill strong {
            font-size: 1.55rem;
            line-height: 1;
            color: #1e2936;
            font-weight: 800;
        }

        .budget-overview-pill small {
            color: #64748b;
            font-size: 0.82rem;
        }

        .budget-source-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            margin-left: 0.55rem;
            padding: 0.2rem 0.55rem;
            border-radius: 999px;
            background: rgba(30, 41, 54, 0.08);
            color: #334155;
            font-size: 0.72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.06em;
        }

        .budget-source-badge.external {
            background: rgba(59, 130, 246, 0.12);
            color: #1d4ed8;
        }

        .budget-source-badge.local {
            background: rgba(16, 185, 129, 0.12);
            color: #047857;
        }

        .report-results-card {
            display: none;
        }

        .report-results-card.is-visible {
            display: block;
        }

        .report-content table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
        }

        .report-content th,
        .report-content td {
            border-bottom: 1px solid #e9ecef;
            padding: 0.75rem 0.5rem;
            text-align: left;
            vertical-align: top;
        }

        .report-content th {
            color: #1e2936;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.06em;
        }

        .report-summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 0.75rem;
            margin-bottom: 1rem;
        }

        .report-summary-item {
            border: 1px solid #e9ecef;
            border-radius: 12px;
            padding: 0.9rem 1rem;
            background: #fff;
        }

        .report-summary-item span {
            display: block;
            color: #64748b;
            font-size: 0.78rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            margin-bottom: 0.35rem;
        }

        .report-summary-item strong {
            color: #1e2936;
            font-size: 1.15rem;
            font-weight: 800;
        }
    </style>
</head>
<body>
    <?php include '../includes/staff_navigation.php'; ?>

    <div class="content">
        <!-- Top Navbar -->
        <?php include '../includes/global_navbar.php'; ?>

        <div class="card budget-overview-card">
            <div class="card-body">
                <div class="budget-overview-copy">
                    <h4>Budget Command Center</h4>
                    <p>Manage organization-wide budgets, monitor live allocation pressure, and keep department spending visible before it becomes a problem.</p>
                </div>
                <div class="budget-overview-grid">
                    <div class="budget-overview-pill">
                        <span>Budgets</span>
                        <strong id="overviewBudgetCount">0</strong>
                        <small id="overviewBudgetMix">No budget records loaded yet</small>
                    </div>
                    <div class="budget-overview-pill">
                        <span>Budgeted Value</span>
                        <strong id="overviewBudgetValue">PHP 0</strong>
                        <small id="overviewBudgetValueMeta">Across local and linked plans</small>
                    </div>
                    <div class="budget-overview-pill">
                        <span>Utilization Rate</span>
                        <strong id="overviewUtilizationRate">0%</strong>
                        <small id="overviewUtilizationMeta">Based on visible allocations</small>
                    </div>
                    <div class="budget-overview-pill">
                        <span>Open Alerts</span>
                        <strong id="overviewAlertCount">0</strong>
                        <small id="overviewAlertMeta">No thresholds triggered</small>
                    </div>
                </div>
            </div>
        </div>

        <!-- Navigation Tabs -->
        <ul class="nav nav-tabs mb-4" id="budgetTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="planning-tab" data-bs-toggle="tab" data-bs-target="#planning" type="button" role="tab">
                    <i class="fas fa-calendar-plus me-2"></i>Budget Planning
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="allocation-tab" data-bs-toggle="tab" data-bs-target="#allocation" type="button" role="tab">
                    <i class="fas fa-sitemap me-2"></i>Budget Allocation
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="tracking-tab" data-bs-toggle="tab" data-bs-target="#tracking" type="button" role="tab">
                    <i class="fas fa-chart-line me-2"></i>Actual vs Budget
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="alerts-tab" data-bs-toggle="tab" data-bs-target="#alerts" type="button" role="tab">
                    <i class="fas fa-exclamation-triangle me-2"></i>Budget Alerts
                </button>
            </li>
            
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="adjustments-tab" data-bs-toggle="tab" data-bs-target="#adjustments" type="button" role="tab">
                    <i class="fas fa-sliders-h me-2"></i>Budget Adjustments
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
                </button>
            </li>
        </ul>

        <!-- Tab Content -->
        <div class="tab-content" id="budgetTabContent">
                        <!-- Budget Planning Tab -->
            <div class="tab-pane fade show active" id="planning" role="tabpanel" aria-labelledby="planning-tab">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="mb-0">Budget Planning & Setup</h6>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createBudgetModal"><i class="fas fa-plus me-2"></i>Create Budget</button>
                </div>
                <div class="row mb-4">
                    <div class="col-md-12">
                        <div class="card">
                            <div class="card-header">
                                <h6>Active Budgets & Cycles</h6>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-striped" id="trackingCategoryTable">
                                        <thead>
                                            <tr>
                                                <th>Budget</th>
                                                <th>Cycle</th>
                                                <th>Owner</th>
                                                <th>Total</th>
                                                <th>Utilized</th>
                                                <th>Status</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody id="trackingCyclesBody">
                                              <tr>
                                                  <td colspan="7" class="text-center text-muted">Loading budgets...</td>
                                              </tr>
                                          </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- Removed informational cards (Planning Checklist, Category Mix, Budget Calendar) per request -->
            </div>
            <!-- Budget Allocation Tab -->
            <div class="tab-pane fade" id="allocation" role="tabpanel" aria-labelledby="allocation-tab" data-privacy-exempt>
                <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
                    <div>
                        <h6 class="mb-1">Budget Allocation Hub</h6>
                        <small class="text-muted">Manage allocations by department with live utilization alerts.</small>
                    </div>
                    <div class="d-flex flex-wrap gap-2">
                        <button class="btn btn-outline-secondary"><i class="fas fa-lock me-2"></i>Lock Allocations</button>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#allocateFundsModal"><i class="fas fa-plus me-2"></i>Allocate Funds</button>
                    </div>
                </div>
                <div class="row g-3 mb-4">
                    <div class="col-md-3">
                        <div class="reports-card tracking-card">
                            <i class="fas fa-sack-dollar fa-2x mb-3 text-primary"></i>
                            <h6>Total Allocated</h6>
                            <h3 id="allocationSummaryTotal">Loading...</h3>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="reports-card tracking-card">
                            <i class="fas fa-arrow-up-right-dots fa-2x mb-3 text-warning"></i>
                            <h6>Utilized</h6>
                            <h3 id="allocationSummaryUtilized">Loading...</h3>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="reports-card tracking-card">
                            <i class="fas fa-wallet fa-2x mb-3 text-success"></i>
                            <h6>Remaining</h6>
                            <h3 id="allocationSummaryRemaining">Loading...</h3>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="reports-card tracking-card">
                            <i class="fas fa-gauge-high fa-2x mb-3 text-info"></i>
                            <h6>Utilization Rate</h6>
                            <h3 id="allocationSummaryRate">Loading...</h3>
                        </div>
                    </div>
                </div>
                <div class="card mb-4">
                    <div class="card-body">
                        <div class="row g-3 align-items-end">
                            <div class="col-md-4">
                                <label class="form-label">Search Department</label>
                                <input type="text" id="allocationSearch" class="form-control" placeholder="Type department name">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Status Filter</label>
                                <select id="allocationStatusFilter" class="form-select">
                                    <option value="all">All Statuses</option>
                                    <option value="red">Red (100%)</option>
                                    <option value="orange">Orange (90%)</option>
                                    <option value="light_orange">Light Orange (80%)</option>
                                    <option value="yellow">Yellow (70%)</option>
                                    <option value="good">Good (&lt;70%)</option>
                                </select>
                            </div>
                            <div class="col-md-5">
                                <label class="form-label">Threshold Guide</label>
                                <div class="d-flex flex-wrap gap-2">
                                    <span class="badge bg-warning text-dark">70% - Yellow</span>
                                    <span class="badge bg-warning text-dark">80% - Light Orange</span>
                                    <span class="badge bg-warning text-dark">90% - Orange</span>
                                    <span class="badge bg-danger">100% - Red</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="card">
                    <div class="card-header">
                        <div class="d-flex justify-content-between align-items-center">
                            <h6>Department Allocations</h6>
                            <button class="btn btn-outline-secondary btn-sm" onclick="loadAllocations()">
                                <i class="fas fa-sync me-2"></i>Refresh
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>Department</th>
                                        <th>Allocated</th>
                                        <th>Reserved</th>
                                        <th>Utilized</th>
                                        <th>Remaining</th>
                                        <th>Utilization %</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="allocationTableBody">
                                      <tr>
                                          <td colspan="8" class="text-center text-muted">Loading allocations...</td>
                                      </tr>
                                  </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Actual vs Budget Tracking Tab -->
            <div class="tab-pane fade" id="tracking" role="tabpanel" aria-labelledby="tracking-tab">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="mb-0">Actual vs Budget Tracking</h6>
                    <div>
                        <select class="form-select form-select-sm me-2" style="width: auto;">
                            <option value="last_30_days">Last 30 Days</option>
                              <option value="last_quarter">Last Quarter</option>
                              <option value="year_to_date">Year to Date</option>
                        </select>
                        <button class="btn btn-outline-secondary" id="trackingRefreshButton"><i class="fas fa-sync me-2"></i>Refresh</button>
                    </div>
                </div>
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="reports-card tracking-card">
                            <i class="fas fa-chart-pie fa-2x mb-3 text-primary"></i>
                            <h6>Total Budget</h6>
                            <h3>Loading...</h3>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="reports-card tracking-card">
                            <i class="fas fa-coins fa-2x mb-3 text-success"></i>
                            <h6>Actual Spent</h6>
                            <h3>Loading...</h3>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="reports-card tracking-card">
                            <i class="fas fa-percentage fa-2x mb-3 text-warning"></i>
                            <h6>Variance</h6>
                            <h3 class="variance-negative">Loading...</h3>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="reports-card tracking-card">
                            <i class="fas fa-clock fa-2x mb-3 text-info"></i>
                            <h6>Remaining</h6>
                            <h3>Loading...</h3>
                        </div>
                    </div>
                </div>
                <div class="row mb-4">
                    <div class="col-md-12">
                        <div class="card">
                            <div class="card-header">
                                <h6>Spending Requests vs Budget</h6>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-striped">
                                        <thead>
                                            <tr>
                                                <th>Department</th>
                                                <th>Requests Approved</th>
                                                <th>Requests Pending</th>
                                                <th>Request Amount</th>
                                                <th>Budget Remaining</th>
                                                <th>Status</th>
                                            </tr>
                                        </thead>
                                        <tbody id="claimsBudgetBody">
                                            <tr>
                                                <td colspan="6" class="text-center text-muted">Loading requests...</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                                <div class="d-flex justify-content-end">
                                    <button class="btn btn-outline-primary btn-sm" type="button" data-bs-toggle="tab" data-bs-target="#allocation"><i class="fas fa-sitemap me-2"></i>Review Budget Allocations</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-12">
                        <div class="card">
                            <div class="card-header">
                                <h6>Budget vs Actual by Category</h6>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-striped" id="trackingBudgetTable">
                                          <thead>
                                              <tr>
                                                  <th>Category</th>
                                                  <th>Budget Amount</th>
                                                  <th>Actual Amount</th>
                                                  <th>Variance</th>
                                                  <th>Variance %</th>
                                                  <th>Status</th>
                                              </tr>
                                          </thead>
                                          <tbody id="trackingBudgetBody">
                                              <tr>
                                                  <td colspan="6" class="text-center text-muted">Loading tracking data...</td>
                                              </tr>
                                          </tbody>
                                      </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Budget Alerts Tab -->
            <div class="tab-pane fade" id="alerts" role="tabpanel" aria-labelledby="alerts-tab">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="mb-0">Budget Alerts & Notifications</h6>
                    <div>
                        <select class="form-select form-select-sm me-2" style="width: auto;" id="alertsFilter">
                            <option value="all">All Alerts</option>
                            <option value="red">Red (100%)</option>
                            <option value="orange">Orange (90%)</option>
                            <option value="light_orange">Light Orange (80%)</option>
                            <option value="yellow">Yellow (70%)</option>
                        </select>
                        <button class="btn btn-outline-secondary" onclick="loadAlerts()"><i class="fas fa-sync me-2"></i>Refresh</button>
                    </div>
                </div>
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="reports-card alert-card">
                            <i class="fas fa-exclamation-triangle fa-2x mb-3 text-danger"></i>
                            <h6>Red (100%)</h6>
                            <h3 id="redCount">0</h3>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="reports-card alert-card">
                            <i class="fas fa-exclamation-circle fa-2x mb-3 text-warning"></i>
                            <h6>Orange (90%)</h6>
                            <h3 id="orangeCount">0</h3>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="reports-card alert-card">
                            <i class="fas fa-info-circle fa-2x mb-3 text-warning"></i>
                            <h6>Light Orange (80%)</h6>
                            <h3 id="lightOrangeCount">0</h3>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="reports-card alert-card">
                            <i class="fas fa-bell fa-2x mb-3 text-secondary"></i>
                            <h6>Yellow (70%)</h6>
                            <h3 id="yellowCount">0</h3>
                        </div>
                    </div>
                </div>
                <div class="row mb-4">
                    <div class="col-md-12">
                        <div class="card">
                            <div class="card-header">
                                <h6>Spending Requests Over Budget Queue</h6>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-striped">
                                        <thead>
                                            <tr>
                                                <th>Request ID</th>
                                                <th>Employee</th>
                                                <th>Department</th>
                                                <th>Amount</th>
                                                <th>Budget Remaining</th>
                                                <th>Action Needed</th>
                                            </tr>
                                        </thead>
                                        <tbody id="claimsOverBudgetBody">
                                            <tr>
                                                <td colspan="6" class="text-center text-muted">Loading requests...</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                                <div class="d-flex justify-content-end">
                                    <button class="btn btn-outline-primary btn-sm" type="button" data-bs-toggle="tab" data-bs-target="#allocation"><i class="fas fa-sitemap me-2"></i>Open Allocation Review</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-12">
                        <div class="card">
                            <div class="card-header">
                                <h6>Departments Over Budget</h6>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-striped" id="alertsTable">
                                        <thead>
                                            <tr>
                                                <th>Department</th>
                                                <th>Budget Year</th>
                                                <th>Budgeted Amount</th>
                                        <th>Utilized Amount</th>
                                        <th>Utilization %</th>
                                        <th>Over Amount</th>
                                                <th>Severity</th>
                                                <th>Alert Date</th>
                                                <th>Action</th>
                                            </tr>
                                        </thead>
                                        <tbody id="alertsTableBody">
                                            <tr>
                                                <td colspan="9" class="text-center text-muted">Loading alerts...</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Forecasting removed (moved to Dashboard) -->

            <!-- Budget Adjustments Tab -->
            <div class="tab-pane fade" id="adjustments" role="tabpanel" aria-labelledby="adjustments-tab">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="mb-0">Budget Adjustments</h6>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#adjustmentRequestModal"><i class="fas fa-plus me-2"></i>Request Adjustment</button>
                </div>
                <div class="row mb-4">
                    <div class="col-md-12">
                        <div class="card">
                            <div class="card-header">
                                <h6>Active Adjustment Requests</h6>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-striped">
                                        <thead>
                                            <tr>
                                                <th>Request ID</th>
                                                <th>Department</th>
                                                <th>Requested By</th>
                                                <th>Type</th>
                                                <th>Amount</th>
                                                <th>Reason</th>
                                                <th>Status</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody id="adjustmentsTableBody">
                                              <tr>
                                                  <td colspan="8" class="text-center text-muted">No adjustment requests available.</td>
                                              </tr>
                                          </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h6>Adjustment Guidelines</h6>
                            </div>
                            <div class="card-body">
                                <ul class="list-unstyled mb-0">
                                    <li class="mb-2"><i class="fas fa-check text-success me-2"></i>Provide clear financial justification.</li>
                                    <li class="mb-2"><i class="fas fa-check text-success me-2"></i>Attach supporting request, invoice, or payroll data if applicable.</li>
                                    <li class="mb-2"><i class="fas fa-check text-success me-2"></i>Allow 3-5 business days for review.</li>
                                    <li><i class="fas fa-check text-success me-2"></i>Approved adjustments update allocations automatically.</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h6>Recent Approvals</h6>
                            </div>
                            <div class="card-body">
                                <ul class="list-unstyled mb-0">
                                      <li class=\"text-muted\">No recent approvals available.</li>
                                  </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Reports & Analytics Tab -->
            <div class="tab-pane fade" id="reports" role="tabpanel" aria-labelledby="reports-tab">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="mb-0">Budget Reports & Analytics</h6>
                    <button class="btn btn-outline-secondary" id="exportReportsBtn"><i class="fas fa-download me-2"></i>Export Current Report</button>
                </div>
                <div class="row">
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h6>Budget vs Actual Report</h6>
                            </div>
                            <div class="card-body">
                                <p>Detailed variance breakdown by department and category with month-over-month trends.</p>
                                <button class="btn btn-primary" id="generateBudgetVsActualBtn">Generate Report</button>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h6>Spending Requests Impact Report</h6>
                            </div>
                            <div class="card-body">
                                <p>Summarizes request volumes, approvals, and budget impact across departments.</p>
                                <button class="btn btn-primary" id="generateClaimsImpactBtn">Generate Report</button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="row mt-3">
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h6>Department Performance</h6>
                            </div>
                            <div class="card-body">
                                <p>Highlights departments trending over budget with recommended actions.</p>
                                <button class="btn btn-primary" id="generateDeptPerformanceBtn">Generate Report</button>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h6>Forecast Snapshot</h6>
                            </div>
                            <div class="card-body">
                                <p>Rolling 90-day forecast with risk flags for departments nearing budget limits.</p>
                                <button class="btn btn-primary" id="generateForecastSnapshotBtn">Generate Report</button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="row mt-4 report-results-card" id="reportResults">
                    <div class="col-md-12">
                        <div class="card">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h6 class="mb-0" id="reportTitle">Report Results</h6>
                                <div class="d-flex gap-2">
                                    <button class="btn btn-sm btn-outline-secondary" id="printReportBtn"><i class="fas fa-print me-2"></i>Print</button>
                                    <button class="btn btn-sm btn-outline-secondary" id="closeReportBtn"><i class="fas fa-times me-2"></i>Close</button>
                                </div>
                            </div>
                            <div class="card-body report-content" id="reportContent"></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Audit Trail Tab -->
            <div class="tab-pane fade" id="audit" role="tabpanel" aria-labelledby="audit-tab">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="mb-0">Budget Audit Trail</h6>
                    <button class="btn btn-outline-secondary" id="auditRefreshButton"><i class="fas fa-sync me-2"></i>Refresh Logs</button>
                </div>
                <div class="card">
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>Date/Time</th>
                                        <th>User</th>
                                        <th>Action</th>
                                        <th>Budget/Item</th>
                                        <th>Details</th>
                                        <th>Source</th>
                                        <th>IP Address</th>
                                    </tr>
                                </thead>
                                <tbody id="auditTrailBody">
                                      <tr>
                                          <td colspan="7" class="text-center text-muted">No audit records available.</td>
                                      </tr>
                                  </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

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
        let currentBudgets = [];
        let currentAllocations = [];
        let currentTrackingData = [];
        let currentAdjustments = [];
        let currentDepartments = [];
        let currentCategories = [];
        let currentAccounts = [];
        let vendors = [];
        let currentAlerts = [];
        let currentHr3ClaimsBreakdown = null;
        let currentReport = null;

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

            // Load initial data
            loadBudgets();
            loadAllocations();
            loadTrackingData();
            loadAlerts();
            loadAdjustments();
            loadDepartments();
            loadCategories();
            loadAccounts();
            loadVendors();
            loadClaimsData();
            loadAuditTrail();
            startVendorPolling();
        });

        // Load budgets
        async function loadBudgets() {
            try {
                const response = await fetch('../api/budgets.php?source=all');
                const data = await response.json();

                if (data.error) {
                    throw new Error(data.error);
                }

                currentBudgets = data.budgets || [];
                renderBudgetsTable();
                populateBudgetDropdowns(currentBudgets);
                updateBudgetOverview();

            } catch (error) {
                console.error('Error loading budgets:', error);
                showAlert('Error loading budgets: ' + error.message, 'danger');
            }
        }

        // Render budgets table
        function renderBudgetsTable() {
            const tbody = document.querySelector('#planning .table tbody');
            if (!tbody) {
                return;
            }
            tbody.innerHTML = '';

            if (currentBudgets.length === 0) {
                tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted">No budgets found. Create your first budget.</td></tr>';
                return;
            }

            currentBudgets.forEach(budget => {
                const statusBadge = getStatusBadge(budget.status || 'draft');
                const ownerName = budget.approved_by_name || budget.created_by_name || 'N/A';
                const utilizedLabel = budget.utilized_amount != null
                    ? `PHP ${parseFloat(budget.utilized_amount || 0).toLocaleString()}`
                    : 'N/A';
                const sourceBadge = budget.is_external
                    ? '<span class="budget-source-badge external">External</span>'
                    : '<span class="budget-source-badge local">Local</span>';
                const actions = budget.is_external
                    ? `<button class="btn btn-sm btn-outline-primary" onclick="viewBudget('${budget.id}')">View</button>`
                    : `
                        <button class="btn btn-sm btn-outline-primary" onclick="viewBudget('${budget.id}')">View</button>
                        <button class="btn btn-sm btn-outline-secondary" onclick="editBudget('${budget.id}')">Edit</button>
                    `;
                const row = `
                    <tr>
                        <td><strong>${budget.name}</strong>${sourceBadge}</td>
                        <td>${formatBudgetPeriod(budget.start_date, budget.end_date)}</td>
                        <td>${ownerName}</td>
                        <td>PHP ${parseFloat(budget.total_amount || 0).toLocaleString()}</td>
                        <td>${utilizedLabel}</td>
                        <td>${statusBadge}</td>
                        <td>${actions}</td>
                    </tr>
                `;
                tbody.innerHTML += row;
            });
        }

        // Load allocations
        async function loadAllocations() {
            try {
                const response = await fetch('../api/budgets.php?action=allocations&include_external=1');
                const data = await response.json();

                if (data.error) {
                    throw new Error(data.error);
                }

                currentAllocations = data.allocations || [];
                renderAllocationsTable();
                updateAllocationSummary();
                renderClaimsSummary();
                renderClaimsOverBudget();
                updateBudgetOverview();

            } catch (error) {
                console.error('Error loading allocations:', error);
                showAlert('Error loading allocations: ' + error.message, 'danger');
            }
        }

        // Render allocations table
        function renderAllocationsTable() {
            const tbody = document.getElementById('allocationTableBody');
            if (!tbody) {
                return;
            }
            tbody.innerHTML = '';

            const filteredAllocations = getFilteredAllocations();

            if (filteredAllocations.length === 0) {
                tbody.innerHTML = '<tr><td colspan="8" class="text-center text-muted">No allocations found.</td></tr>';
                return;
            }

            filteredAllocations.forEach(allocation => {
                const progressPercent = allocation.total_amount > 0 ? (allocation.utilized_amount / allocation.total_amount) * 100 : 0;
                const progressClass = progressPercent >= 100 ? 'budget-over' : progressPercent >= 70 ? 'budget-on-track' : 'budget-under';
                const statusBadge = getAllocationStatusBadge(progressPercent);
                const reservedLabel = allocation.reserved_amount != null
                    ? `PHP ${parseFloat(allocation.reserved_amount || 0).toLocaleString()}`
                    : 'N/A';

                const row = `
                    <tr>
                        <td><strong>${allocation.department}</strong></td>
                        <td>PHP ${parseFloat(allocation.total_amount || 0).toLocaleString()}</td>
                        <td>${reservedLabel}</td>
                        <td>PHP ${parseFloat(allocation.utilized_amount || 0).toLocaleString()}</td>
                        <td>PHP ${parseFloat(allocation.remaining || 0).toLocaleString()}</td>
                        <td>
                            <div class="budget-progress ${progressClass}">
                                <div class="budget-progress-bar" style="width: ${Math.min(progressPercent, 100)}%"></div>
                            </div>
                            <small class="text-muted">${progressPercent.toFixed(1)}%</small>
                        </td>
                        <td>${statusBadge}</td>
                        <td>
                            <button class="btn btn-sm btn-outline-primary" onclick="adjustAllocation(${allocation.id})">Adjust</button>
                        </td>
                    </tr>
                `;
                tbody.innerHTML += row;
            });
        }

        function updateAllocationSummary() {
            const filteredAllocations = getFilteredAllocations();
            const totalAllocated = filteredAllocations.reduce((sum, item) => sum + (parseFloat(item.total_amount) || 0), 0);
            const totalUtilized = filteredAllocations.reduce((sum, item) => sum + (parseFloat(item.utilized_amount) || 0), 0);
            const totalRemaining = filteredAllocations.reduce((sum, item) => sum + (parseFloat(item.remaining) || 0), 0);
            const utilizationRate = totalAllocated > 0 ? (totalUtilized / totalAllocated) * 100 : 0;

            const totalEl = document.getElementById('allocationSummaryTotal');
            const utilizedEl = document.getElementById('allocationSummaryUtilized');
            const remainingEl = document.getElementById('allocationSummaryRemaining');
            const rateEl = document.getElementById('allocationSummaryRate');

            if (totalEl) totalEl.textContent = `PHP ${totalAllocated.toLocaleString()}`;
            if (utilizedEl) utilizedEl.textContent = `PHP ${totalUtilized.toLocaleString()}`;
            if (remainingEl) remainingEl.textContent = `PHP ${totalRemaining.toLocaleString()}`;
            if (rateEl) rateEl.textContent = `${utilizationRate.toFixed(1)}%`;
        }

        function updateBudgetOverview() {
            const budgetCountEl = document.getElementById('overviewBudgetCount');
            const budgetMixEl = document.getElementById('overviewBudgetMix');
            const budgetValueEl = document.getElementById('overviewBudgetValue');
            const utilizationRateEl = document.getElementById('overviewUtilizationRate');
            const utilizationMetaEl = document.getElementById('overviewUtilizationMeta');
            const alertCountEl = document.getElementById('overviewAlertCount');
            const alertMetaEl = document.getElementById('overviewAlertMeta');

            const totalBudgets = currentBudgets.length;
            const localBudgets = currentBudgets.filter(item => !item.is_external).length;
            const externalBudgets = totalBudgets - localBudgets;
            const totalBudgetValue = currentBudgets.reduce((sum, item) => sum + (parseFloat(item.total_amount) || 0), 0);
            const overviewAllocations = document.getElementById('allocationSearch')
                ? getFilteredAllocations()
                : currentAllocations;
            const allocationTotals = overviewAllocations.reduce((acc, item) => {
                acc.total += parseFloat(item.total_amount) || 0;
                acc.utilized += parseFloat(item.utilized_amount) || 0;
                return acc;
            }, { total: 0, utilized: 0 });
            const utilizationRate = allocationTotals.total > 0
                ? (allocationTotals.utilized / allocationTotals.total) * 100
                : 0;

            if (budgetCountEl) budgetCountEl.textContent = totalBudgets.toLocaleString();
            if (budgetMixEl) budgetMixEl.textContent = `${localBudgets} local / ${externalBudgets} linked`;
            if (budgetValueEl) budgetValueEl.textContent = `PHP ${totalBudgetValue.toLocaleString()}`;
            if (utilizationRateEl) utilizationRateEl.textContent = `${utilizationRate.toFixed(1)}%`;
            if (utilizationMetaEl) {
                utilizationMetaEl.textContent = allocationTotals.total > 0
                    ? `PHP ${(allocationTotals.total - allocationTotals.utilized).toLocaleString()} remaining`
                    : 'No tracked allocations yet';
            }
            if (alertCountEl) alertCountEl.textContent = currentAlerts.length.toLocaleString();
            if (alertMetaEl) {
                if (!currentAlerts.length) {
                    alertMetaEl.textContent = 'No thresholds triggered';
                } else {
                    const severityRank = { red: 4, orange: 3, light_orange: 2, yellow: 1 };
                    const topAlert = [...currentAlerts].sort((left, right) => (severityRank[right.severity] || 0) - (severityRank[left.severity] || 0))[0];
                    const highestSeverity = topAlert?.severity_label || topAlert?.severity || 'Alert';
                    alertMetaEl.textContent = `Highest severity: ${highestSeverity}`;
                }
            }
        }

        function getFilteredAllocations() {
            const searchInput = document.getElementById('allocationSearch');
            const statusSelect = document.getElementById('allocationStatusFilter');
            const query = searchInput ? searchInput.value.trim().toLowerCase() : '';
            const statusFilter = statusSelect ? statusSelect.value : 'all';

            return currentAllocations.filter(allocation => {
                const nameMatch = !query || (allocation.department || '').toLowerCase().includes(query);
                const progressPercent = allocation.total_amount > 0 ? (allocation.utilized_amount / allocation.total_amount) * 100 : 0;
                const statusKey = getAllocationStatusKey(progressPercent);

                if (statusFilter === 'all') {
                    return nameMatch;
                }
                return nameMatch && statusKey === statusFilter;
            });
        }

        function getAllocationStatusKey(progressPercent) {
            if (progressPercent >= 100) {
                return 'red';
            }
            if (progressPercent >= 90) {
                return 'orange';
            }
            if (progressPercent >= 80) {
                return 'light_orange';
            }
            if (progressPercent >= 70) {
                return 'yellow';
            }
            return 'good';
        }

        function renderAdjustmentsTable() {
            const tbody = document.getElementById('adjustmentsTableBody');
            if (!tbody) {
                return;
            }
            tbody.innerHTML = '';

            if (currentAdjustments.length === 0) {
                tbody.innerHTML = '<tr><td colspan="8" class="text-center text-muted">No adjustment requests available.</td></tr>';
                return;
            }

            currentAdjustments.forEach(adjustment => {
                const statusBadge = getStatusBadge(adjustment.status || 'pending');
                const statusValue = (adjustment.status || 'pending').toLowerCase();
                const actionButtons = statusValue === 'pending'
                    ? `
                        <button class="btn btn-sm btn-outline-success me-1" onclick="approveAdjustment(${adjustment.id})">Approve</button>
                        <button class="btn btn-sm btn-outline-danger" onclick="rejectAdjustment(${adjustment.id})">Reject</button>
                      `
                    : `
                        <button class="btn btn-sm btn-outline-primary me-1" onclick="editAdjustment(${adjustment.id})">Edit</button>
                        <button class="btn btn-sm btn-outline-danger" onclick="deleteAdjustment(${adjustment.id})">Delete</button>
                      `;
                const row = `
                    <tr>
                        <td>${adjustment.id}</td>
                        <td>${adjustment.department_name || 'Unassigned'}</td>
                        <td>${adjustment.requested_by_name || 'N/A'}</td>
                        <td>${adjustment.adjustment_type}</td>
                        <td>PHP ${parseFloat(adjustment.amount || 0).toLocaleString()}</td>
                        <td>${adjustment.reason || ''}</td>
                        <td>${statusBadge}</td>
                        <td>
                            ${actionButtons}
                        </td>
                    </tr>
                `;
                tbody.innerHTML += row;
            });
        }

        // Load tracking data
        async function loadTrackingData() {
            try {
                const trackingPeriodSelect = document.querySelector('#tracking select');
                const period = trackingPeriodSelect ? trackingPeriodSelect.value : 'year_to_date';
                const trackingParams = new URLSearchParams({ action: 'tracking', period });
                const response = await fetch(`../api/budgets.php?${trackingParams.toString()}`);
                const data = await response.json();

                if (data.error) {
                    throw new Error(data.error);
                }

                currentTrackingData = data.tracking || [];
                renderTrackingTable();
                updateTrackingCards(data.summary);

            } catch (error) {
                console.error('Error loading tracking data:', error);
                showAlert('Error loading tracking data: ' + error.message, 'danger');
            }
        }

        // Render tracking table
        function renderTrackingTable() {
            const tbody = document.getElementById('trackingBudgetBody');
            tbody.innerHTML = '';

            if (currentTrackingData.length === 0) {
                tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted">No tracking data available.</td></tr>';
                return;
            }

            currentTrackingData.forEach(item => {
                const variance = (item.actual_amount || 0) - (item.budget_amount || 0);
                const variancePercent = item.budget_amount > 0 ? (variance / item.budget_amount) * 100 : 0;
                const varianceClass = variance >= 0 ? 'variance-positive' : 'variance-negative';
                const statusBadge = getVarianceStatusBadge(variancePercent);

                const row = `
                    <tr>
                        <td>${item.category}</td>
                        <td>PHP ${parseFloat(item.budget_amount || 0).toLocaleString()}</td>
                        <td>PHP ${parseFloat(item.actual_amount || 0).toLocaleString()}</td>
                        <td class="${varianceClass}">PHP ${Math.abs(variance).toLocaleString()}</td>
                        <td class="${varianceClass}">${variancePercent >= 0 ? '+' : ''}${variancePercent.toFixed(1)}%</td>
                        <td>${statusBadge}</td>
                    </tr>
                `;
                tbody.innerHTML += row;
            });
        }

        // Update tracking cards
        function updateTrackingCards(summary) {
            if (!summary) return;

            // Update the cards with real data
            const cards = document.querySelectorAll('#tracking .reports-card h3');
            if (cards.length >= 4) {
                cards[0].textContent = `PHP ${parseFloat(summary.total_budget || 0).toLocaleString()}`;
                cards[1].textContent = `PHP ${parseFloat(summary.actual_spent || 0).toLocaleString()}`;
                cards[2].textContent = `${parseFloat(summary.variance_percent || 0).toFixed(1)}%`;
                cards[3].textContent = `PHP ${parseFloat(summary.remaining || 0).toLocaleString()}`;

                // Update variance color
                const varianceCard = cards[2].closest('.reports-card');
                const varianceValue = parseFloat(summary.variance_percent || 0);
                if (varianceValue < 0) {
                    cards[2].className = 'variance-negative';
                } else {
                    cards[2].className = 'variance-positive';
                }
            }
        }

        
          async function loadClaimsData() {
            const trackingBody = document.getElementById('claimsBudgetBody');
            const alertsBody = document.getElementById('claimsOverBudgetBody');
            if (trackingBody) {
                trackingBody.innerHTML = '<tr><td colspan="6" class="text-center text-muted">Loading requests...</td></tr>';
            }
            if (alertsBody) {
                alertsBody.innerHTML = '<tr><td colspan="6" class="text-center text-muted">Loading requests...</td></tr>';
            }

            try {
                const response = await fetch('../api/integrations.php?action=execute&integration_name=hr3&action_name=getClaimsBreakdown');
                const payload = await response.json();

                if (!payload.success) {
                    throw new Error(payload.error || 'Failed to load spending requests');
                }

                const result = payload.result || {};
                if (result.success === false) {
                    throw new Error(result.error || 'Failed to load spending requests');
                }

                const breakdown = result.data || result;
                if (!breakdown || !breakdown.summary) {
                    throw new Error('No spending request data available');
                }

                currentHr3ClaimsBreakdown = breakdown;
                renderClaimsSummary();
                renderClaimsOverBudget();

            } catch (error) {
                console.error('Error loading spending requests:', error);
                if (trackingBody) {
                    trackingBody.innerHTML = '<tr><td colspan="6" class="text-center text-muted">No request data available.</td></tr>';
                }
                if (alertsBody) {
                    alertsBody.innerHTML = '<tr><td colspan="6" class="text-center text-muted">No request data available.</td></tr>';
                }
            }
        }

        function getAllocationRemaining(department) {
            const allocation = currentAllocations.find(item => item.department === department);
            return allocation ? allocation.remaining : null;
        }

        function getClaimsBudgetStatus(remaining, totalAmount) {
            if (remaining == null) {
                return { label: 'Unknown', className: 'bg-secondary' };
            }

            if (remaining <= 0) {
                return { label: 'Over Budget', className: 'bg-danger' };
            }

            if (remaining < totalAmount) {
                return { label: 'Near Limit', className: 'bg-warning text-dark' };
            }

            return { label: 'Within Limit', className: 'bg-success' };
        }

        function renderClaimsSummary() {
            const tbody = document.getElementById('claimsBudgetBody');
            if (!tbody) {
                return;
            }

            if (!currentHr3ClaimsBreakdown || !currentHr3ClaimsBreakdown.summary) {
                tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted">No request data available.</td></tr>';
                return;
            }

            const departmentBreakdown = currentHr3ClaimsBreakdown.summary.department_breakdown || {};
            const departments = Object.keys(departmentBreakdown);
            const claims = Array.isArray(currentHr3ClaimsBreakdown.claims) ? currentHr3ClaimsBreakdown.claims : [];

            if (departments.length === 0) {
                tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted">No request data available.</td></tr>';
                return;
            }

            tbody.innerHTML = '';
            departments.forEach(department => {
                const data = departmentBreakdown[department] || {};
                const totalAmount = data.total_amount || 0;
                const departmentClaims = claims.filter(claim => claim.department === department);
                const approvedClaims = departmentClaims.filter(claim => claim.status === 'approved').length;
                const pendingClaims = departmentClaims.filter(claim => claim.status === 'pending').length;
                const remaining = getAllocationRemaining(department);
                const remainingLabel = remaining == null ? 'N/A' : `PHP ${parseFloat(remaining || 0).toLocaleString()}`;
                const status = getClaimsBudgetStatus(remaining, totalAmount);

                const row = `
                    <tr>
                        <td>${department}</td>
                        <td>${approvedClaims}</td>
                        <td>${pendingClaims}</td>
                        <td>PHP ${parseFloat(totalAmount || 0).toLocaleString()}</td>
                        <td>${remainingLabel}</td>
                        <td><span class="badge ${status.className}">${status.label}</span></td>
                    </tr>
                `;
                tbody.innerHTML += row;
            });
        }

        function renderClaimsOverBudget() {
            const tbody = document.getElementById('claimsOverBudgetBody');
            if (!tbody) {
                return;
            }

            if (!currentHr3ClaimsBreakdown || !Array.isArray(currentHr3ClaimsBreakdown.claims)) {
                tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted">No request data available.</td></tr>';
                return;
            }

            const overBudgetClaims = currentHr3ClaimsBreakdown.claims.filter(claim => {
                const remaining = getAllocationRemaining(claim.department);
                if (remaining == null) {
                    return false;
                }
                const amount = claim.amount || 0;
                return remaining <= 0 || amount > remaining;
            });

            if (overBudgetClaims.length === 0) {
                tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted">No requests over budget.</td></tr>';
                return;
            }

            tbody.innerHTML = '';
            overBudgetClaims.forEach(claim => {
                const remaining = getAllocationRemaining(claim.department);
                const remainingLabel = remaining == null ? 'N/A' : `PHP ${parseFloat(remaining || 0).toLocaleString()}`;
                const actionLabel = remaining != null && remaining <= 0 ? 'Supplemental Budget' : 'Finance Review';
                const actionClass = remaining != null && remaining <= 0 ? 'bg-danger' : 'bg-warning text-dark';

                const row = `
                    <tr>
                        <td>${claim.id}</td>
                        <td>${claim.employee_name}</td>
                        <td>${claim.department}</td>
                        <td>PHP ${parseFloat(claim.amount || 0).toLocaleString()}</td>
                        <td>${remainingLabel}</td>
                        <td><span class="badge ${actionClass}">${actionLabel}</span></td>
                    </tr>
                `;
                tbody.innerHTML += row;
            });
        }

// Create budget
        async function createBudget(formData) {
            try {
                const response = await fetch('../api/budgets.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify(formData)
                });

                const data = await response.json();

                if (data.error) {
                    throw new Error(data.error);
                }

                showAlert('Budget created successfully', 'success');
                loadBudgets(); // Refresh the list
                loadAuditTrail();

                // Close modal
                const modalEl = document.getElementById('createBudgetModal');
                if (modalEl) {
                    const modal = bootstrap.Modal.getInstance(modalEl);
                    if (modal) modal.hide();
                }

            } catch (error) {
                console.error('Error creating budget:', error);
                showAlert('Error creating budget: ' + error.message, 'danger');
            }
        }

        async function createBudgetItem(formData) {
            try {
                const response = await fetch('../api/budgets.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify(formData)
                });

                const data = await response.json();
                if (data.error) {
                    throw new Error(data.error);
                }

                showAlert('Allocation saved', 'success');
                loadBudgets();
                loadAllocations();
                loadTrackingData();
                loadAlerts();
                loadAuditTrail();

                const modalEl = document.getElementById('allocateFundsModal');
                if (modalEl) {
                    const modal = bootstrap.Modal.getInstance(modalEl);
                    if (modal) modal.hide();
                }
            } catch (error) {
                console.error('Error saving allocation:', error);
                showAlert('Error saving allocation: ' + error.message, 'danger');
            }
        }

        async function requestAdjustment(formData) {
            try {
                const response = await fetch('../api/budgets.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify(formData)
                });

                const data = await response.json();
                if (data.error) {
                    throw new Error(data.error);
                }

                showAlert('Adjustment request submitted', 'success');
                loadAdjustments();
                loadAllocations();
                loadBudgets();
                loadAuditTrail();

                const modalEl = document.getElementById('adjustmentRequestModal');
                if (modalEl) {
                    const modal = bootstrap.Modal.getInstance(modalEl);
                    if (modal) modal.hide();
                }
            } catch (error) {
                console.error('Error requesting adjustment:', error);
                showAlert('Error requesting adjustment: ' + error.message, 'danger');
            }
        }

        // View budget details
        function viewBudget(budgetId) {
            const budget = currentBudgets.find(b => b.id == budgetId);
            if (!budget) {
                showAlert('Budget not found', 'warning');
                return;
            }

            const modalEl = document.getElementById('viewBudgetModal');
            if (!modalEl) {
                showAlert(`Viewing budget: ${budget.name}`, 'info');
                return;
            }

            modalEl.querySelector('#viewBudgetName').textContent = budget.name || 'N/A';
            modalEl.querySelector('#viewBudgetPeriod').textContent = formatBudgetPeriod(budget.start_date, budget.end_date);
            modalEl.querySelector('#viewBudgetDepartment').textContent = budget.department || 'Unassigned';
            modalEl.querySelector('#viewBudgetVendor').textContent = budget.vendor_name || 'N/A';
            modalEl.querySelector('#viewBudgetStatus').innerHTML = getStatusBadge(budget.status || 'draft');
            modalEl.querySelector('#viewBudgetAmount').textContent = `PHP ${parseFloat(budget.total_amount || 0).toLocaleString()}`;
            modalEl.querySelector('#viewBudgetOwner').textContent = budget.approved_by_name || budget.created_by_name || 'N/A';
            modalEl.querySelector('#viewBudgetDescription').textContent = budget.description || 'N/A';

            new bootstrap.Modal(modalEl).show();
        }

        // Edit budget
        function editBudget(budgetId) {
            const budget = currentBudgets.find(b => b.id == budgetId);
            if (!budget) {
                showAlert('Budget not found', 'warning');
                return;
            }

            const modalEl = document.getElementById('editBudgetModal');
            if (!modalEl) {
                showAlert(`Editing budget: ${budget.name}`, 'info');
                return;
            }

            modalEl.querySelector('#editBudgetId').value = budget.id;
            modalEl.querySelector('#editBudgetName').value = budget.name || '';
            modalEl.querySelector('#editStartDate').value = budget.start_date || '';
            modalEl.querySelector('#editEndDate').value = budget.end_date || '';
            modalEl.querySelector('#editTotalAmount').value = budget.total_amount || '';
            modalEl.querySelector('#editBudgetDescription').value = budget.description || '';

            setSelectValue(
                modalEl.querySelector('#editBudgetDepartment'),
                budget.department_id,
                budget.department || 'Unassigned'
            );
            setSelectValue(
                modalEl.querySelector('#editBudgetVendor'),
                budget.vendor_id,
                budget.vendor_name || 'N/A'
            );

            new bootstrap.Modal(modalEl).show();
        }

        // Adjust allocation
        function adjustAllocation(allocationId) {
            const allocation = currentAllocations.find(a => a.id == allocationId);
            if (!allocation) {
                showAlert('Allocation not found', 'warning');
                return;
            }

            const modalEl = document.getElementById('allocationDetailModal');
            if (!modalEl) {
                showAlert(`Adjusting allocation for: ${allocation.department}`, 'info');
                return;
            }

            modalEl.querySelector('#allocationDepartment').textContent = allocation.department || 'Unassigned';
            modalEl.querySelector('#allocationTotal').textContent = `PHP ${parseFloat(allocation.total_amount || 0).toLocaleString()}`;
            modalEl.querySelector('#allocationReserved').textContent = `PHP ${parseFloat(allocation.reserved_amount || 0).toLocaleString()}`;
            modalEl.querySelector('#allocationUtilized').textContent = `PHP ${parseFloat(allocation.utilized_amount || 0).toLocaleString()}`;
            modalEl.querySelector('#allocationRemaining').textContent = `PHP ${parseFloat(allocation.remaining || 0).toLocaleString()}`;

            const requestBtn = modalEl.querySelector('#allocationRequestAdjustment');
            if (requestBtn) {
                requestBtn.setAttribute('data-department-id', allocation.department_id || '');
                requestBtn.setAttribute('data-department-name', allocation.department || '');
            }

            new bootstrap.Modal(modalEl).show();
        }

        function setSelectValue(select, value, label) {
            if (!select) {
                return;
            }

            const stringValue = value != null ? String(value) : '';
            if (stringValue && !select.querySelector(`option[value="${stringValue}"]`)) {
                const option = document.createElement('option');
                option.value = stringValue;
                option.textContent = label || stringValue;
                select.appendChild(option);
            }
            select.value = stringValue;
        }

        async function updateBudget(budgetId, formData) {
            try {
                const response = await fetch(`../api/budgets.php?id=${encodeURIComponent(budgetId)}`, {
                    method: 'PUT',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify(formData)
                });

                const data = await response.json();
                if (data.error) {
                    throw new Error(data.error);
                }

                showAlert('Budget updated successfully', 'success');
                loadBudgets();
                loadAllocations();
                loadTrackingData();
                loadAlerts();
                loadAuditTrail();

                const modalEl = document.getElementById('editBudgetModal');
                if (modalEl) {
                    const modal = bootstrap.Modal.getInstance(modalEl);
                    if (modal) modal.hide();
                }
            } catch (error) {
                console.error('Error updating budget:', error);
                showAlert('Error updating budget: ' + error.message, 'danger');
            }
        }

        async function approveAdjustment(adjustmentId) {
            await updateAdjustmentStatus(adjustmentId, 'approved');
        }

        async function rejectAdjustment(adjustmentId) {
            await updateAdjustmentStatus(adjustmentId, 'rejected');
        }

        async function updateAdjustmentStatus(adjustmentId, status) {
            try {
                const response = await fetch('../api/budgets.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        action: 'adjustment_status',
                        adjustment_id: adjustmentId,
                        status
                    })
                });

                const data = await response.json();
                if (data.error) {
                    throw new Error(data.error);
                }

                showAlert(`Adjustment ${status}`, 'success');
                loadAdjustments();
                loadAllocations();
                loadBudgets();
                loadTrackingData();
                loadAlerts();
                loadAuditTrail();

            } catch (error) {
                console.error('Error updating adjustment:', error);
                showAlert('Error updating adjustment: ' + error.message, 'danger');
            }
        }

        function editAdjustment(adjustmentId) {
            const adjustment = currentAdjustments.find(item => item.id == adjustmentId);
            if (!adjustment) {
                showAlert('Adjustment not found', 'warning');
                return;
            }

            const modalEl = document.getElementById('adjustmentRequestModal');
            const form = document.getElementById('adjustmentRequestForm');
            if (!modalEl || !form) {
                showAlert('Adjustment form not available', 'warning');
                return;
            }

            form.dataset.adjustmentId = adjustment.id;

            const modalTitle = modalEl.querySelector('.modal-title');
            if (modalTitle) {
                modalTitle.textContent = 'Edit Budget Adjustment';
            }
            const submitButton = modalEl.querySelector('button[type="submit"][form="adjustmentRequestForm"]');
            if (submitButton) {
                submitButton.textContent = 'Update Adjustment';
            }

            const budget = currentBudgets.find(item => item.id == adjustment.budget_id);
            const department = currentDepartments.find(item => item.id == adjustment.department_id);
            const vendor = vendors.find(item => item.id == adjustment.vendor_id);

            setSelectValue(
                document.getElementById('adjustmentBudget'),
                adjustment.budget_id,
                budget ? budget.name : `Budget #${adjustment.budget_id}`
            );
            setSelectValue(
                document.getElementById('adjustmentDepartment'),
                adjustment.department_id,
                department ? department.dept_name : 'Unassigned'
            );
            setSelectValue(
                document.getElementById('adjustmentVendor'),
                adjustment.vendor_id,
                vendor ? vendor.company_name : 'N/A'
            );

            document.getElementById('adjustmentType').value = adjustment.adjustment_type || '';
            document.getElementById('adjustmentAmount').value = adjustment.amount || '';
            document.getElementById('adjustmentReason').value = adjustment.reason || '';
            document.getElementById('expectedDate').value = adjustment.effective_date || '';

            const requestedByInput = document.getElementById('requestedBy');
            if (requestedByInput) {
                requestedByInput.value = adjustment.requested_by_name || requestedByInput.value;
            }

            new bootstrap.Modal(modalEl).show();
        }

        function resetAdjustmentForm() {
            const modalEl = document.getElementById('adjustmentRequestModal');
            const form = document.getElementById('adjustmentRequestForm');
            if (!modalEl || !form) {
                return;
            }

            form.reset();
            delete form.dataset.adjustmentId;

            const modalTitle = modalEl.querySelector('.modal-title');
            if (modalTitle) {
                modalTitle.textContent = 'Request Budget Adjustment';
            }
            const submitButton = modalEl.querySelector('button[type="submit"][form="adjustmentRequestForm"]');
            if (submitButton) {
                submitButton.textContent = 'Submit Request';
            }
        }

        async function updateAdjustment(adjustmentId, formData) {
            try {
                const response = await fetch(`../api/budgets.php?id=${encodeURIComponent(adjustmentId)}`, {
                    method: 'PUT',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        action: 'adjustment_update',
                        ...formData
                    })
                });

                const data = await response.json();
                if (data.error) {
                    throw new Error(data.error);
                }

                showAlert('Adjustment updated successfully', 'success');
                loadAdjustments();
                loadAllocations();
                loadBudgets();
                loadTrackingData();
                loadAlerts();
                loadAuditTrail();

                const modalEl = document.getElementById('adjustmentRequestModal');
                if (modalEl) {
                    const modal = bootstrap.Modal.getInstance(modalEl);
                    if (modal) modal.hide();
                }
            } catch (error) {
                console.error('Error updating adjustment:', error);
                showAlert('Error updating adjustment: ' + error.message, 'danger');
            }
        }

        async function deleteAdjustment(adjustmentId) {
            showConfirmDialog(
                'Delete Adjustment',
                'Are you sure you want to delete this adjustment? This action cannot be undone.',
                async () => {
                    try {
                        const response = await fetch(`../api/budgets.php?action=adjustment&id=${encodeURIComponent(adjustmentId)}`, {
                            method: 'DELETE'
                        });

                        const data = await response.json();
                        if (data.error) {
                            throw new Error(data.error);
                        }

                        showAlert('Adjustment deleted successfully', 'success');
                        loadAdjustments();
                        loadAllocations();
                        loadBudgets();
                        loadTrackingData();
                        loadAlerts();
                        loadAuditTrail();
                    } catch (error) {
                        console.error('Error deleting adjustment:', error);
                        showAlert('Error deleting adjustment: ' + error.message, 'danger');
                    }
                }
            );
        }

        // Utility functions
        function getStatusBadge(status) {
            const statusMap = {
                'draft': 'bg-info',
                'pending': 'bg-warning',
                'approved': 'bg-success',
                'active': 'bg-primary',
                'closed': 'bg-secondary',
                'completed': 'bg-secondary',
                'rejected': 'bg-danger'
            };

            const badgeClass = statusMap[status] || 'bg-secondary';
            return `<span class="badge ${badgeClass}">${status.charAt(0).toUpperCase() + status.slice(1)}</span>`;
        }

        function getAllocationStatusBadge(progressPercent) {
            if (progressPercent >= 100) {
                return '<span class="badge bg-danger">Red (100%)</span>';
            }
            if (progressPercent >= 90) {
                return '<span class="badge bg-warning text-dark">Orange (90%)</span>';
            }
            if (progressPercent >= 80) {
                return '<span class="badge bg-warning text-dark">Light Orange (80%)</span>';
            }
            if (progressPercent >= 70) {
                return '<span class="badge bg-warning text-dark">Yellow (70%)</span>';
            }
            return '<span class="badge bg-success">Good</span>';
        }

        function getVarianceStatusBadge(variancePercent) {
            if (variancePercent > 10) {
                return '<span class="badge bg-danger">Over Budget</span>';
            } else if (variancePercent > 5) {
                return '<span class="badge bg-warning">Slightly Over</span>';
            } else if (variancePercent < -10) {
                return '<span class="badge bg-success">Under Budget</span>';
            } else {
                return '<span class="badge bg-info">On Target</span>';
            }
        }

        function formatBudgetPeriod(startDate, endDate) {
            if (!startDate || !endDate) return 'N/A';

            const start = new Date(startDate);
            const end = new Date(endDate);

            const startMonth = start.toLocaleDateString('en-US', { month: 'short' });
            const endMonth = end.toLocaleDateString('en-US', { month: 'short' });
            const startYear = start.getFullYear();
            const endYear = end.getFullYear();

            if (startYear === endYear) {
                return `${startMonth}-${endMonth} ${startYear}`;
            } else {
                return `${startMonth} ${startYear} - ${endMonth} ${endYear}`;
            }
        }

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

        // Event listeners for dynamic functionality
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('.modal').forEach((modalEl) => {
                modalEl.addEventListener('show.bs.modal', () => {
                    if (modalEl.parentElement !== document.body) {
                        document.body.appendChild(modalEl);
                    }
                    setTimeout(() => {
                        document.querySelectorAll('.modal-backdrop').forEach((backdrop) => {
                            backdrop.style.pointerEvents = 'none';
                            backdrop.style.opacity = '0.15';
                            backdrop.style.zIndex = '4990';
                        });
                    }, 0);
                });
            });

            // Handle create budget form submission
            const createBudgetForm = document.getElementById('createBudgetForm');
            if (createBudgetForm) {
                createBudgetForm.addEventListener('submit', function(e) {
                    e.preventDefault();

                    const formData = new FormData(this);
                    const budgetData = {
                        name: formData.get('budgetName'),
                        department_id: formData.get('department_id') || null,
                        vendor_id: formData.get('vendor_id') || null,
                        start_date: formData.get('startDate'),
                        end_date: formData.get('endDate'),
                        total_amount: parseFloat(formData.get('totalAmount')),
                        description: formData.get('budgetDescription')
                    };

                    createBudget(budgetData);
                });
            }

            const editBudgetForm = document.getElementById('editBudgetForm');
            if (editBudgetForm) {
                editBudgetForm.addEventListener('submit', function(e) {
                    e.preventDefault();

                    const formData = new FormData(this);
                    const budgetId = formData.get('budget_id');
                    if (!budgetId) {
                        showAlert('Budget ID is missing', 'warning');
                        return;
                    }

                    const budgetData = {
                        name: formData.get('budgetName'),
                        department_id: formData.get('department_id') || null,
                        vendor_id: formData.get('vendor_id') || null,
                        start_date: formData.get('startDate'),
                        end_date: formData.get('endDate'),
                        total_amount: parseFloat(formData.get('totalAmount')),
                        description: formData.get('budgetDescription')
                    };

                    updateBudget(budgetId, budgetData);
                });
            }

            const allocationRequestBtn = document.getElementById('allocationRequestAdjustment');
            if (allocationRequestBtn) {
                allocationRequestBtn.addEventListener('click', function() {
                    const departmentId = this.getAttribute('data-department-id') || '';
                    const departmentName = this.getAttribute('data-department-name') || '';
                    const select = document.getElementById('adjustmentDepartment');
                    setSelectValue(select, departmentId, departmentName);

                    const allocationModal = bootstrap.Modal.getInstance(document.getElementById('allocationDetailModal'));
                    if (allocationModal) {
                        allocationModal.hide();
                    }

                    const adjustmentModal = document.getElementById('adjustmentRequestModal');
                    if (adjustmentModal) {
                        new bootstrap.Modal(adjustmentModal).show();
                    }
                });
            }

            const allocateFundsForm = document.getElementById('allocateFundsForm');
            if (allocateFundsForm) {
                allocateFundsForm.addEventListener('submit', function(e) {
                    e.preventDefault();

                    const formData = new FormData(this);
                    const itemData = {
                        action: 'item',
                        budget_id: formData.get('budget_id'),
                        category_id: formData.get('category_id'),
                        department_id: formData.get('department_id') || null,
                        account_id: formData.get('account_id') || null,
                        vendor_id: formData.get('vendor_id') || null,
                        budgeted_amount: parseFloat(formData.get('budgeted_amount')),
                        notes: formData.get('notes')
                    };

                    createBudgetItem(itemData);
                });
            }

            const adjustmentRequestForm = document.getElementById('adjustmentRequestForm');
            if (adjustmentRequestForm) {
                adjustmentRequestForm.addEventListener('submit', function(e) {
                    e.preventDefault();

                    const formData = new FormData(this);
                    const adjustmentData = {
                        budget_id: formData.get('budget_id'),
                        adjustment_type: formData.get('adjustmentType'),
                        amount: parseFloat(formData.get('adjustmentAmount')),
                        department_id: formData.get('department_id'),
                        vendor_id: formData.get('vendor_id') || null,
                        reason: formData.get('adjustmentReason'),
                        effective_date: formData.get('expectedDate') || null
                    };

                    const adjustmentId = this.dataset.adjustmentId;
                    if (adjustmentId) {
                        updateAdjustment(adjustmentId, adjustmentData);
                    } else {
                        requestAdjustment({ action: 'adjustment', ...adjustmentData });
                    }
                });
            }

            // Handle tracking period change
            const trackingPeriodSelect = document.querySelector('#tracking select');
            if (trackingPeriodSelect) {
                trackingPeriodSelect.addEventListener('change', function() {
                    loadTrackingData();
                });
            }

            const trackingRefreshButton = document.getElementById('trackingRefreshButton');
            if (trackingRefreshButton) {
                trackingRefreshButton.addEventListener('click', function() {
                    loadTrackingData();
                });
            }

            const adjustmentModal = document.getElementById('adjustmentRequestModal');
            if (adjustmentModal) {
                adjustmentModal.addEventListener('hidden.bs.modal', resetAdjustmentForm);
            }

            const auditTab = document.getElementById('audit-tab');
            if (auditTab) {
                auditTab.addEventListener('shown.bs.tab', function() {
                    loadAuditTrail();
                });
            }

            const auditRefreshButton = document.getElementById('auditRefreshButton');
            if (auditRefreshButton) {
                auditRefreshButton.addEventListener('click', function() {
                    loadAuditTrail();
                });
            }

            const allocationSearch = document.getElementById('allocationSearch');
            if (allocationSearch) {
                allocationSearch.addEventListener('input', function() {
                    renderAllocationsTable();
                    updateAllocationSummary();
                    updateBudgetOverview();
                });
            }

            const allocationStatusFilter = document.getElementById('allocationStatusFilter');
            if (allocationStatusFilter) {
                allocationStatusFilter.addEventListener('change', function() {
                    renderAllocationsTable();
                    updateAllocationSummary();
                    updateBudgetOverview();
                });
            }

            const alertsFilter = document.getElementById('alertsFilter');
            if (alertsFilter) {
                alertsFilter.addEventListener('change', function() {
                    renderAlertsTable();
                });
            }

            const generateBudgetVsActualBtn = document.getElementById('generateBudgetVsActualBtn');
            if (generateBudgetVsActualBtn) {
                generateBudgetVsActualBtn.addEventListener('click', generateBudgetVsActualReport);
            }

            const generateClaimsImpactBtn = document.getElementById('generateClaimsImpactBtn');
            if (generateClaimsImpactBtn) {
                generateClaimsImpactBtn.addEventListener('click', generateClaimsImpactReport);
            }

            const generateDeptPerformanceBtn = document.getElementById('generateDeptPerformanceBtn');
            if (generateDeptPerformanceBtn) {
                generateDeptPerformanceBtn.addEventListener('click', generateDepartmentPerformanceReport);
            }

            const generateForecastSnapshotBtn = document.getElementById('generateForecastSnapshotBtn');
            if (generateForecastSnapshotBtn) {
                generateForecastSnapshotBtn.addEventListener('click', generateForecastSnapshotReport);
            }

            const exportReportsBtn = document.getElementById('exportReportsBtn');
            if (exportReportsBtn) {
                exportReportsBtn.addEventListener('click', exportCurrentReport);
            }

            const closeReportBtn = document.getElementById('closeReportBtn');
            if (closeReportBtn) {
                closeReportBtn.addEventListener('click', closeReport);
            }

            const printReportBtn = document.getElementById('printReportBtn');
            if (printReportBtn) {
                printReportBtn.addEventListener('click', function() {
                    window.print();
                });
            }
        });

        // Load vendors for dropdowns
        async function loadVendors() {
            try {
                const response = await fetch('../api/vendors.php');
                const data = await response.json();

                if (data.error) {
                    throw new Error(data.error);
                }

                vendors = data; // Store globally for dropdown population
                populateVendorDropdowns(data);

            } catch (error) {
                console.error('Error loading vendors:', error);
                showAlert('Error loading vendors: ' + error.message, 'danger');
            }
        }

        async function loadDepartments() {
            try {
                const response = await fetch('../api/financials/departments.php');
                const data = await response.json();

                if (!data.success) {
                    throw new Error(data.error || 'Failed to load departments');
                }

                currentDepartments = data.departments || [];
                populateDepartmentDropdowns(currentDepartments);

            } catch (error) {
                console.error('Error loading departments:', error);
                showAlert('Error loading departments: ' + error.message, 'danger');
            }
        }

        async function loadCategories() {
            try {
                const response = await fetch('../api/budgets.php?action=categories');
                const data = await response.json();

                if (data.error) {
                    throw new Error(data.error);
                }

                currentCategories = data.categories || [];
                populateCategoryDropdowns(currentCategories);

            } catch (error) {
                console.error('Error loading categories:', error);
                showAlert('Error loading categories: ' + error.message, 'danger');
            }
        }

        async function loadAccounts() {
            try {
                const response = await fetch('../api/chart_of_accounts.php?active=true');
                const data = await response.json();

                if (data.error) {
                    throw new Error(data.error);
                }

                currentAccounts = Array.isArray(data) ? data : [];
                populateAccountDropdowns(currentAccounts);

            } catch (error) {
                console.error('Error loading accounts:', error);
                showAlert('Error loading accounts: ' + error.message, 'danger');
            }
        }

        async function loadAdjustments() {
            try {
                const response = await fetch('../api/budgets.php?action=adjustments');
                const data = await response.json();

                if (data.error) {
                    throw new Error(data.error);
                }

                currentAdjustments = data.adjustments || [];
                renderAdjustmentsTable();

            } catch (error) {
                console.error('Error loading adjustments:', error);
                showAlert('Error loading adjustments: ' + error.message, 'danger');
            }
        }

        function parseAuditValues(values) {
            if (!values) {
                return null;
            }
            try {
                return JSON.parse(values);
            } catch (error) {
                return null;
            }
        }

        function formatAuditTarget(log, newValues, oldValues) {
            const recordId = log.record_id || 'N/A';
            switch (log.table_name) {
                case 'budgets': {
                    const name = (newValues && (newValues.budget_name || newValues.name)) ||
                        (oldValues && (oldValues.budget_name || oldValues.name));
                    return name ? `Budget: ${name}` : `Budget #${recordId}`;
                }
                case 'budget_items':
                    return `Budget Item #${recordId}`;
                case 'budget_adjustments':
                    return `Adjustment #${recordId}`;
                case 'budget_categories': {
                    const name = (newValues && newValues.category_name) || (oldValues && oldValues.category_name);
                    return name ? `Category: ${name}` : `Category #${recordId}`;
                }
                case 'hr3_integrations':
                    return 'Claims Integration';
                default:
                    return log.table_name ? `${log.table_name} #${recordId}` : `Record #${recordId}`;
            }
        }

        function formatAuditDetails(log, targetLabel, newValues) {
            const actionLabel = log.action ? log.action.charAt(0).toUpperCase() + log.action.slice(1) : 'Action';
            let detail = log.action_description || `${actionLabel} ${targetLabel}`;
            if (newValues && newValues.reason) {
                detail += ` - ${newValues.reason}`;
            }
            return detail;
        }

        function formatAuditSource(log, newValues, oldValues) {
            let source = (newValues && newValues.source) || (oldValues && oldValues.source);
            if (!source) {
                source = log.table_name === 'hr3_integrations' ? 'Claims API' : 'Budget Management UI';
            }
            const origin = (newValues && newValues.origin) || (oldValues && oldValues.origin);
            return origin ? `${source} (${origin})` : source;
        }

        async function loadAuditTrail() {
            const tbody = document.getElementById('auditTrailBody');
            if (!tbody) {
                return;
            }

            tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted">Loading audit trail...</td></tr>';

            try {
                const tables = [
                    'budgets',
                    'budget_items',
                    'budget_adjustments',
                    'budget_categories',
                    'hr3_integrations'
                ];
                const response = await fetch(`../api/audit.php?table_name=${encodeURIComponent(tables.join(','))}`);
                const logs = await response.json();

                if (!Array.isArray(logs) || logs.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted">No audit records available.</td></tr>';
                    return;
                }

                tbody.innerHTML = logs.map(log => {
                    const newValues = parseAuditValues(log.new_values);
                    const oldValues = parseAuditValues(log.old_values);
                    const targetLabel = formatAuditTarget(log, newValues, oldValues);
                    const details = formatAuditDetails(log, targetLabel, newValues);
                    const source = formatAuditSource(log, newValues, oldValues);
                    const timestamp = log.formatted_date || new Date(log.created_at).toLocaleString();
                    const user = log.full_name || log.username || 'Unknown';

                    return `
                        <tr>
                            <td>${timestamp}</td>
                            <td>${user}</td>
                            <td>${log.action || 'N/A'}</td>
                            <td>${targetLabel}</td>
                            <td>${details}</td>
                            <td>${source}</td>
                            <td>${log.ip_address || 'N/A'}</td>
                        </tr>
                    `;
                }).join('');

            } catch (error) {
                console.error('Error loading audit trail:', error);
                tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted">Error loading audit records.</td></tr>';
            }
        }

        // Populate vendor dropdowns in modals
        function populateVendorDropdowns(vendors) {
            const vendorSelects = [
                'budgetVendor', 'allocationVendor', 'adjustmentVendor', 'trackingVendor', 'editBudgetVendor'
            ];

            vendorSelects.forEach(selectId => {
                const select = document.getElementById(selectId);
                if (select) {
                    select.innerHTML = '<option value="">Select Vendor</option>';
                    vendors.forEach(vendor => {
                        if (vendor.status === 'active') {
                            select.innerHTML += `<option value="${vendor.id}">${vendor.company_name}</option>`;
                        }
                    });
                }
            });
        }

        function populateDepartmentDropdowns(departments) {
            const departmentSelects = [
                'budgetDepartment', 'allocationDepartment', 'adjustmentDepartment', 'editBudgetDepartment'
            ];

            departmentSelects.forEach(selectId => {
                const select = document.getElementById(selectId);
                if (select) {
                    select.innerHTML = '<option value="">Select Department</option>';
                    departments.forEach(dept => {
                        if (dept.is_active == 1 || dept.is_active === undefined) {
                            select.innerHTML += `<option value="${dept.id}">${dept.dept_name}</option>`;
                        }
                    });
                }
            });
        }

        function populateCategoryDropdowns(categories) {
            const select = document.getElementById('allocationCategory');
            if (!select) {
                return;
            }
            select.innerHTML = '<option value="">Select Category</option>';
            categories.forEach(category => {
                select.innerHTML += `<option value="${category.id}">${category.category_name} (${category.category_type})</option>`;
            });
        }

        function populateAccountDropdowns(accounts) {
            const select = document.getElementById('allocationAccount');
            if (!select) {
                return;
            }
            select.innerHTML = '<option value="">Select Account</option>';
            accounts.forEach(account => {
                if (account.is_active == 1 || account.is_active === undefined) {
                    select.innerHTML += `<option value="${account.id}">${account.account_code} - ${account.account_name}</option>`;
                }
            });
        }

        function populateBudgetDropdowns(budgets) {
            const budgetSelects = [
                'allocationBudget', 'adjustmentBudget'
            ];
            const localBudgets = budgets.filter(budget => !budget.is_external);

            budgetSelects.forEach(selectId => {
                const select = document.getElementById(selectId);
                if (!select) {
                    return;
                }
                select.innerHTML = '<option value="">Select Budget</option>';
                localBudgets.forEach(budget => {
                    select.innerHTML += `<option value="${budget.id}">${budget.name}</option>`;
                });
            });
        }

        // Load alerts
        async function loadAlerts() {
            try {
                const response = await fetch('../api/budgets.php?action=alerts');
                const data = await response.json();

                if (data.error) {
                    throw new Error(data.error);
                }

                currentAlerts = data.alerts || [];
                renderAlertsTable();
                updateAlertsCards();
                updateBudgetOverview();
                showThresholdToast();

            } catch (error) {
                console.error('Error loading alerts:', error);
                showAlert('Error loading alerts: ' + error.message, 'danger');
            }
        }

        // Render alerts table
        function renderAlertsTable() {
            const tbody = document.getElementById('alertsTableBody');
            tbody.innerHTML = '';

            if (currentAlerts.length === 0) {
                tbody.innerHTML = '<tr><td colspan="9" class="text-center text-muted">No budget alerts at this time.</td></tr>';
                return;
            }

            // Apply filter if selected
            const filterValue = document.getElementById('alertsFilter').value;
            let filteredAlerts = currentAlerts;

            if (filterValue !== 'all') {
                filteredAlerts = currentAlerts.filter(alert => alert.severity === filterValue);
            }

            filteredAlerts.forEach(alert => {
                const severityClass = {
                    'red': 'bg-danger text-white',
                    'orange': 'bg-warning text-dark',
                    'light_orange': 'bg-warning text-dark',
                    'yellow': 'bg-warning text-dark'
                }[alert.severity] || 'bg-secondary';

                const row = `
                    <tr>
                        <td><strong>${alert.department}</strong></td>
                        <td>${alert.budget_year}</td>
                        <td>PHP ${parseFloat(alert.budgeted_amount).toLocaleString()}</td>
                        <td>PHP ${parseFloat(alert.utilized_amount).toLocaleString()}</td>
                        <td>${parseFloat(alert.utilization_percent).toFixed(1)}%</td>
                        <td class="variance-positive">PHP ${parseFloat(alert.over_amount).toLocaleString()}</td>
                        <td><span class="badge ${severityClass}">${alert.severity_label || alert.severity}</span></td>
                        <td>${alert.alert_date}</td>
                        <td>
                            <button class="btn btn-sm btn-outline-primary" onclick="viewAlertDetails(${alert.id})">View Details</button>
                        </td>
                    </tr>
                `;
                tbody.innerHTML += row;
            });
        }

        // Update alerts cards
        function updateAlertsCards() {
            const redCount = currentAlerts.filter(a => a.severity === 'red').length;
            const orangeCount = currentAlerts.filter(a => a.severity === 'orange').length;
            const lightOrangeCount = currentAlerts.filter(a => a.severity === 'light_orange').length;
            const yellowCount = currentAlerts.filter(a => a.severity === 'yellow').length;

            const redEl = document.getElementById('redCount');
            const orangeEl = document.getElementById('orangeCount');
            const lightOrangeEl = document.getElementById('lightOrangeCount');
            const yellowEl = document.getElementById('yellowCount');

            if (redEl) redEl.textContent = redCount;
            if (orangeEl) orangeEl.textContent = orangeCount;
            if (lightOrangeEl) lightOrangeEl.textContent = lightOrangeCount;
            if (yellowEl) yellowEl.textContent = yellowCount;
        }

        function showThresholdToast() {
            if (!currentAlerts.length) {
                return;
            }
            const severityPriority = { red: 4, orange: 3, light_orange: 2, yellow: 1 };
            const topAlert = currentAlerts.reduce((best, alert) => {
                if (!best) return alert;
                return (severityPriority[alert.severity] || 0) > (severityPriority[best.severity] || 0) ? alert : best;
            }, null);

            if (!topAlert) {
                return;
            }

            const message = `${topAlert.department} is at ${parseFloat(topAlert.utilization_percent).toFixed(1)}% of budget (${topAlert.severity_label || topAlert.severity}).`;
            const alertType = topAlert.severity === 'red' ? 'danger' : (topAlert.severity === 'orange' ? 'warning' : 'info');
            showAlert(message, alertType);
        }
        // View alert details
        function viewAlertDetails(alertId) {
            const alert = currentAlerts.find(a => a.id == alertId);
            if (!alert) {
                showAlert('Alert not found', 'warning');
                return;
            }

            const modalEl = document.getElementById('alertDetailsModal');
            if (!modalEl) {
                showAlert(`Alert Details: ${alert.department} is at ${alert.utilization_percent.toFixed(1)}% of budget`, 'warning');
                return;
            }

            modalEl.querySelector('#alertDepartment').textContent = alert.department;
            modalEl.querySelector('#alertYear').textContent = alert.budget_year;
            modalEl.querySelector('#alertBudgeted').textContent = `PHP ${parseFloat(alert.budgeted_amount || 0).toLocaleString()}`;
            modalEl.querySelector('#alertActual').textContent = `PHP ${parseFloat(alert.utilized_amount || 0).toLocaleString()}`;
            modalEl.querySelector('#alertOverAmount').textContent = `PHP ${parseFloat(alert.over_amount || 0).toLocaleString()}`;
            modalEl.querySelector('#alertOverPercent').textContent = `${parseFloat(alert.utilization_percent || 0).toFixed(1)}%`;
            modalEl.querySelector('#alertSeverity').textContent = alert.severity_label || alert.severity || 'N/A';
            modalEl.querySelector('#alertDate').textContent = alert.alert_date || 'N/A';

            new bootstrap.Modal(modalEl).show();
        }

        function escapeHtml(value) {
            return String(value ?? '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }

        function buildReportSummaryItems(items) {
            return `
                <div class="report-summary-grid">
                    ${items.map(item => `
                        <div class="report-summary-item">
                            <span>${escapeHtml(item.label)}</span>
                            <strong>${escapeHtml(item.value)}</strong>
                        </div>
                    `).join('')}
                </div>
            `;
        }

        function showReport(title, html, exportRows = []) {
            const reportCard = document.getElementById('reportResults');
            const reportTitle = document.getElementById('reportTitle');
            const reportContent = document.getElementById('reportContent');
            if (!reportCard || !reportTitle || !reportContent) {
                return;
            }

            currentReport = { title, rows: exportRows };
            reportTitle.textContent = title;
            reportContent.innerHTML = html;
            reportCard.classList.add('is-visible');
            reportCard.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }

        function closeReport() {
            const reportCard = document.getElementById('reportResults');
            const reportContent = document.getElementById('reportContent');
            if (reportCard) {
                reportCard.classList.remove('is-visible');
            }
            if (reportContent) {
                reportContent.innerHTML = '';
            }
            currentReport = null;
        }

        function exportCurrentReport() {
            if (!currentReport || !Array.isArray(currentReport.rows) || !currentReport.rows.length) {
                showAlert('Generate a report before exporting.', 'warning');
                return;
            }

            const headers = Object.keys(currentReport.rows[0]);
            const csv = [
                headers.join(','),
                ...currentReport.rows.map(row => headers.map(header => {
                    const normalized = String(row[header] ?? '').replace(/"/g, '""');
                    return `"${normalized}"`;
                }).join(','))
            ].join('\n');

            const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            link.download = currentReport.title.toLowerCase().replace(/[^a-z0-9]+/g, '-') + '.csv';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            URL.revokeObjectURL(url);
        }

        function generateBudgetVsActualReport() {
            if (!currentTrackingData.length) {
                showAlert('No tracking data is available yet.', 'warning');
                return;
            }

            const totalBudget = currentTrackingData.reduce((sum, item) => sum + (parseFloat(item.budget_amount) || 0), 0);
            const totalActual = currentTrackingData.reduce((sum, item) => sum + (parseFloat(item.actual_amount) || 0), 0);
            const variance = totalActual - totalBudget;

            showReport(
                'Budget vs Actual Report',
                `
                    ${buildReportSummaryItems([
                        { label: 'Budgeted', value: 'PHP ' + totalBudget.toLocaleString() },
                        { label: 'Actual', value: 'PHP ' + totalActual.toLocaleString() },
                        { label: 'Variance', value: 'PHP ' + variance.toLocaleString() }
                    ])}
                    <table>
                        <thead>
                            <tr>
                                <th>Category</th>
                                <th>Budget</th>
                                <th>Actual</th>
                                <th>Variance</th>
                                <th>Variance %</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${currentTrackingData.map(item => {
                                const budget = parseFloat(item.budget_amount) || 0;
                                const actual = parseFloat(item.actual_amount) || 0;
                                const rowVariance = actual - budget;
                                const rowVariancePercent = budget > 0 ? (rowVariance / budget) * 100 : 0;
                                return `
                                    <tr>
                                        <td>${escapeHtml(item.category)}</td>
                                        <td>PHP ${budget.toLocaleString()}</td>
                                        <td>PHP ${actual.toLocaleString()}</td>
                                        <td>PHP ${rowVariance.toLocaleString()}</td>
                                        <td>${rowVariancePercent.toFixed(1)}%</td>
                                    </tr>
                                `;
                            }).join('')}
                        </tbody>
                    </table>
                `,
                currentTrackingData.map(item => {
                    const budget = parseFloat(item.budget_amount) || 0;
                    const actual = parseFloat(item.actual_amount) || 0;
                    const rowVariance = actual - budget;
                    return {
                        Category: item.category,
                        Budget: budget.toFixed(2),
                        Actual: actual.toFixed(2),
                        Variance: rowVariance.toFixed(2),
                        VariancePercent: (budget > 0 ? (rowVariance / budget) * 100 : 0).toFixed(2)
                    };
                })
            );
        }

        function generateDepartmentPerformanceReport() {
            if (!currentAllocations.length) {
                showAlert('No allocation data is available yet.', 'warning');
                return;
            }

            const ranked = [...currentAllocations].sort((left, right) => {
                const leftRate = left.total_amount > 0 ? (left.utilized_amount / left.total_amount) : 0;
                const rightRate = right.total_amount > 0 ? (right.utilized_amount / right.total_amount) : 0;
                return rightRate - leftRate;
            });

            showReport(
                'Department Performance Report',
                `
                    ${buildReportSummaryItems([
                        { label: 'Departments', value: ranked.length.toLocaleString() },
                        { label: 'At Risk', value: ranked.filter(item => (item.total_amount > 0 ? (item.utilized_amount / item.total_amount) * 100 : 0) >= 80).length.toLocaleString() },
                        { label: 'Healthy', value: ranked.filter(item => (item.total_amount > 0 ? (item.utilized_amount / item.total_amount) * 100 : 0) < 70).length.toLocaleString() }
                    ])}
                    <table>
                        <thead>
                            <tr>
                                <th>Department</th>
                                <th>Allocated</th>
                                <th>Utilized</th>
                                <th>Remaining</th>
                                <th>Utilization %</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${ranked.map(item => {
                                const utilization = item.total_amount > 0 ? (item.utilized_amount / item.total_amount) * 100 : 0;
                                return `
                                    <tr>
                                        <td>${escapeHtml(item.department)}</td>
                                        <td>PHP ${(parseFloat(item.total_amount) || 0).toLocaleString()}</td>
                                        <td>PHP ${(parseFloat(item.utilized_amount) || 0).toLocaleString()}</td>
                                        <td>PHP ${(parseFloat(item.remaining) || 0).toLocaleString()}</td>
                                        <td>${utilization.toFixed(1)}%</td>
                                        <td>${escapeHtml(getAllocationStatusKey(utilization))}</td>
                                    </tr>
                                `;
                            }).join('')}
                        </tbody>
                    </table>
                `,
                ranked.map(item => {
                    const utilization = item.total_amount > 0 ? (item.utilized_amount / item.total_amount) * 100 : 0;
                    return {
                        Department: item.department,
                        Allocated: (parseFloat(item.total_amount) || 0).toFixed(2),
                        Utilized: (parseFloat(item.utilized_amount) || 0).toFixed(2),
                        Remaining: (parseFloat(item.remaining) || 0).toFixed(2),
                        UtilizationPercent: utilization.toFixed(2),
                        Status: getAllocationStatusKey(utilization)
                    };
                })
            );
        }

        function generateClaimsImpactReport() {
            if (!currentHr3ClaimsBreakdown || !Array.isArray(currentHr3ClaimsBreakdown.claims) || !currentHr3ClaimsBreakdown.claims.length) {
                showAlert('No spending request data is available yet.', 'warning');
                return;
            }

            const claims = currentHr3ClaimsBreakdown.claims;
            const totalClaims = claims.length;
            const totalAmount = claims.reduce((sum, item) => sum + (parseFloat(item.amount) || 0), 0);
            const pendingCount = claims.filter(item => item.status === 'pending').length;

            showReport(
                'Spending Requests Impact Report',
                `
                    ${buildReportSummaryItems([
                        { label: 'Requests', value: totalClaims.toLocaleString() },
                        { label: 'Total Amount', value: 'PHP ' + totalAmount.toLocaleString() },
                        { label: 'Pending', value: pendingCount.toLocaleString() }
                    ])}
                    <table>
                        <thead>
                            <tr>
                                <th>Request ID</th>
                                <th>Employee</th>
                                <th>Department</th>
                                <th>Amount</th>
                                <th>Status</th>
                                <th>Submitted</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${claims.map(item => `
                                <tr>
                                    <td>${escapeHtml(item.id)}</td>
                                    <td>${escapeHtml(item.employee_name)}</td>
                                    <td>${escapeHtml(item.department)}</td>
                                    <td>PHP ${(parseFloat(item.amount) || 0).toLocaleString()}</td>
                                    <td>${escapeHtml(item.status)}</td>
                                    <td>${escapeHtml(item.date_submitted || 'N/A')}</td>
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>
                `,
                claims.map(item => ({
                    ClaimId: item.id,
                    Employee: item.employee_name,
                    Department: item.department,
                    Amount: (parseFloat(item.amount) || 0).toFixed(2),
                    Status: item.status,
                    Submitted: item.date_submitted || ''
                }))
            );
        }

        function generateForecastSnapshotReport() {
            const riskyAllocations = currentAllocations
                .filter(item => item.total_amount > 0)
                .map(item => ({
                    ...item,
                    utilization: (item.utilized_amount / item.total_amount) * 100
                }))
                .sort((left, right) => right.utilization - left.utilization)
                .slice(0, 8);

            if (!riskyAllocations.length) {
                showAlert('No allocation history is available for a forecast snapshot.', 'warning');
                return;
            }

            showReport(
                'Forecast Snapshot Report',
                `
                    ${buildReportSummaryItems([
                        { label: 'Tracked Departments', value: riskyAllocations.length.toLocaleString() },
                        { label: 'Highest Utilization', value: riskyAllocations[0].utilization.toFixed(1) + '%' },
                        { label: 'Watchlist', value: riskyAllocations.filter(item => item.utilization >= 80).length.toLocaleString() }
                    ])}
                    <table>
                        <thead>
                            <tr>
                                <th>Department</th>
                                <th>Allocated</th>
                                <th>Utilized</th>
                                <th>Remaining</th>
                                <th>Utilization %</th>
                                <th>Flag</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${riskyAllocations.map(item => `
                                <tr>
                                    <td>${escapeHtml(item.department)}</td>
                                    <td>PHP ${(parseFloat(item.total_amount) || 0).toLocaleString()}</td>
                                    <td>PHP ${(parseFloat(item.utilized_amount) || 0).toLocaleString()}</td>
                                    <td>PHP ${(parseFloat(item.remaining) || 0).toLocaleString()}</td>
                                    <td>${item.utilization.toFixed(1)}%</td>
                                    <td>${item.utilization >= 100 ? 'Immediate action' : (item.utilization >= 80 ? 'Watch closely' : 'Stable')}</td>
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>
                `,
                riskyAllocations.map(item => ({
                    Department: item.department,
                    Allocated: (parseFloat(item.total_amount) || 0).toFixed(2),
                    Utilized: (parseFloat(item.utilized_amount) || 0).toFixed(2),
                    Remaining: (parseFloat(item.remaining) || 0).toFixed(2),
                    UtilizationPercent: item.utilization.toFixed(2),
                    Flag: item.utilization >= 100 ? 'Immediate action' : (item.utilization >= 80 ? 'Watch closely' : 'Stable')
                }))
            );
        }

        // Polling function to check for vendor updates
        function startVendorPolling() {
            setInterval(async function() {
                try {
                    // Check if vendor data has been updated
                    const lastUpdate = localStorage.getItem('vendorsLastUpdate');
                    const currentTimestamp = Date.now();

                    // If no last update or if it's been more than 2 seconds since last check,
                    // refresh vendor data to catch any cross-module changes
                    if (!lastUpdate || (currentTimestamp - parseInt(lastUpdate)) > 2000) {
                        await loadVendors();
                    }
                } catch (error) {
                    console.error('Error checking for vendor updates:', error);
                }
            }, 10000); // Check every 10 seconds
        }
    </script>

    <!-- Create Budget Modal -->
    <div class="modal fade" id="createBudgetModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Create New Budget</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="createBudgetForm">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="budgetName" class="form-label">Budget Name *</label>
                                    <input type="text" class="form-control" id="budgetName" name="budgetName" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="budgetDepartment" class="form-label">Department</label>
                                    <select class="form-select" id="budgetDepartment" name="department_id">
                                        <option value="">Loading departments...</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="startDate" class="form-label">Start Date *</label>
                                    <input type="date" class="form-control" id="startDate" name="startDate" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="endDate" class="form-label">End Date *</label>
                                    <input type="date" class="form-control" id="endDate" name="endDate" required>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="totalAmount" class="form-label">Total Budget Amount *</label>
                                    <input type="number" class="form-control" id="totalAmount" name="totalAmount" step="0.01" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="budgetVendor" class="form-label">Primary Vendor (Optional)</label>
                                    <select class="form-select" id="budgetVendor" name="vendor_id">
                                        <option value="">Loading vendors...</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="budgetDescription" class="form-label">Description</label>
                            <textarea class="form-control" id="budgetDescription" name="budgetDescription" rows="3"></textarea>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" form="createBudgetForm">Create Budget</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Allocate Funds Modal -->
    <div class="modal fade" id="allocateFundsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Allocate Budget Funds</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="allocateFundsForm">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="allocationBudget" class="form-label">Budget *</label>
                                    <select class="form-select" id="allocationBudget" name="budget_id" required>
                                        <option value="">Loading budgets...</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="allocationCategory" class="form-label">Category *</label>
                                    <select class="form-select" id="allocationCategory" name="category_id" required>
                                        <option value="">Loading categories...</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="allocationDepartment" class="form-label">Department</label>
                                    <select class="form-select" id="allocationDepartment" name="department_id">
                                        <option value="">Loading departments...</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="allocationAccount" class="form-label">Account</label>
                                    <select class="form-select" id="allocationAccount" name="account_id">
                                        <option value="">Loading accounts...</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="allocationVendor" class="form-label">Vendor (Optional)</label>
                                    <select class="form-select" id="allocationVendor" name="vendor_id">
                                        <option value="">Loading vendors...</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="allocationAmount" class="form-label">Allocated Amount *</label>
                                    <input type="number" class="form-control" id="allocationAmount" name="budgeted_amount" step="0.01" required>
                                </div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="allocationNotes" class="form-label">Notes</label>
                            <textarea class="form-control" id="allocationNotes" name="notes" rows="3"></textarea>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" form="allocateFundsForm">Save Allocation</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Adjustment Request Modal -->
    <div class="modal fade" id="adjustmentRequestModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Request Budget Adjustment</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="adjustmentRequestForm">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="adjustmentBudget" class="form-label">Budget *</label>
                                    <select class="form-select" id="adjustmentBudget" name="budget_id" required>
                                        <option value="">Loading budgets...</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="adjustmentType" class="form-label">Adjustment Type *</label>
                                    <select class="form-select" id="adjustmentType" name="adjustmentType" required>
                                        <option value="">Select Type</option>
                                        <option value="increase">Increase Budget</option>
                                        <option value="decrease">Decrease Budget</option>
                                        <option value="transfer">Transfer Funds</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="adjustmentAmount" class="form-label">Amount *</label>
                                    <input type="number" class="form-control" id="adjustmentAmount" name="adjustmentAmount" step="0.01" required>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="adjustmentDepartment" class="form-label">Department *</label>
                                    <select class="form-select" id="adjustmentDepartment" name="department_id" required>
                                        <option value="">Loading departments...</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="adjustmentVendor" class="form-label">Related Vendor</label>
                                    <select class="form-select" id="adjustmentVendor" name="vendor_id">
                                        <option value="">Loading vendors...</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="adjustmentReason" class="form-label">Reason for Adjustment *</label>
                            <textarea class="form-control" id="adjustmentReason" name="adjustmentReason" rows="4" required placeholder="Please provide detailed reason for this budget adjustment"></textarea>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="requestedBy" class="form-label">Requested By</label>
                                    <input type="text" class="form-control" id="requestedBy" name="requestedBy" value="<?php echo htmlspecialchars($budgetRequestedByName); ?>" readonly>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="expectedDate" class="form-label">Effective Date</label>
                                    <input type="date" class="form-control" id="expectedDate" name="expectedDate">
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" form="adjustmentRequestForm">Submit Request</button>
                </div>
            </div>
        </div>
    </div>

    <!-- View Budget Modal -->
    <div class="modal fade" id="viewBudgetModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Budget Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Budget Name</label>
                            <div id="viewBudgetName" class="fw-semibold"></div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Period</label>
                            <div id="viewBudgetPeriod" class="fw-semibold"></div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Department</label>
                            <div id="viewBudgetDepartment" class="fw-semibold"></div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Vendor</label>
                            <div id="viewBudgetVendor" class="fw-semibold"></div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Status</label>
                            <div id="viewBudgetStatus"></div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Total Amount</label>
                            <div id="viewBudgetAmount" class="fw-semibold"></div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Owner</label>
                            <div id="viewBudgetOwner" class="fw-semibold"></div>
                        </div>
                        <div class="col-md-12 mb-3">
                            <label class="form-label">Description</label>
                            <div id="viewBudgetDescription" class="fw-semibold"></div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Budget Modal -->
    <div class="modal fade" id="editBudgetModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Budget</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="editBudgetForm">
                        <input type="hidden" id="editBudgetId" name="budget_id">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="editBudgetName" class="form-label">Budget Name *</label>
                                    <input type="text" class="form-control" id="editBudgetName" name="budgetName" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="editBudgetDepartment" class="form-label">Department</label>
                                    <select class="form-select" id="editBudgetDepartment" name="department_id">
                                        <option value="">Select Department</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="editStartDate" class="form-label">Start Date *</label>
                                    <input type="date" class="form-control" id="editStartDate" name="startDate" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="editEndDate" class="form-label">End Date *</label>
                                    <input type="date" class="form-control" id="editEndDate" name="endDate" required>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="editTotalAmount" class="form-label">Total Budget Amount *</label>
                                    <input type="number" class="form-control" id="editTotalAmount" name="totalAmount" step="0.01" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="editBudgetVendor" class="form-label">Primary Vendor (Optional)</label>
                                    <select class="form-select" id="editBudgetVendor" name="vendor_id">
                                        <option value="">Select Vendor</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="editBudgetDescription" class="form-label">Description</label>
                            <textarea class="form-control" id="editBudgetDescription" name="budgetDescription" rows="3"></textarea>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" form="editBudgetForm">Save Changes</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Allocation Details Modal -->
    <div class="modal fade" id="allocationDetailModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Allocation Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Department</label>
                            <div id="allocationDepartment" class="fw-semibold"></div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Total Allocated</label>
                            <div id="allocationTotal" class="fw-semibold"></div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Reserved Amount</label>
                            <div id="allocationReserved" class="fw-semibold"></div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Utilized Amount</label>
                            <div id="allocationUtilized" class="fw-semibold"></div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Remaining</label>
                            <div id="allocationRemaining" class="fw-semibold"></div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-primary" id="allocationRequestAdjustment">Request Adjustment</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Alert Details Modal -->
    <div class="modal fade" id="alertDetailsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Budget Alert Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Department</label>
                            <div id="alertDepartment" class="fw-semibold"></div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Budget Year</label>
                            <div id="alertYear" class="fw-semibold"></div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Budgeted Amount</label>
                            <div id="alertBudgeted" class="fw-semibold"></div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Utilized Amount</label>
                            <div id="alertActual" class="fw-semibold"></div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Over Amount</label>
                            <div id="alertOverAmount" class="fw-semibold"></div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Utilization Percent</label>
                            <div id="alertOverPercent" class="fw-semibold"></div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Severity</label>
                            <div id="alertSeverity" class="fw-semibold"></div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Alert Date</label>
                            <div id="alertDate" class="fw-semibold"></div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div><!-- Inactivity Timeout - Blur screen + Auto logout -->
    <script src="../includes/inactivity_timeout.js?v=3"></script>
<script src="../includes/navbar_datetime.js"></script>

<script src="../includes/tab_persistence.js?v=1"></script>
</body>
</html>


