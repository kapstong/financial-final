<?php
/**
 * Super Admin Navigation Component
 * Modern sidebar navigation with permission-based menu items
 */

// Get current page for active state
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
$currentPage = basename($scriptName);

// Permission checks
require_once __DIR__ . '/auth.php';
$auth = $auth ?? new Auth();
$canFinancialSetup = $auth->hasPermission('settings.edit');
$canDepartmentView = $auth->hasPermission('departments.view');
$canCashier = $auth->hasAnyPermission(['cashier.operate', 'cashier.view_all']);
$canFinancialReports = $auth->hasPermission('reports.view');

// Check if Financials menu should be shown
$showFinancialsNav = $canFinancialSetup || $canDepartmentView || $canCashier || $canFinancialReports;

// Determine if GL menu should be expanded
$glExpanded = in_array($currentPage, ['general_ledger.php', 'accounts_payable.php', 'accounts_receivable.php'], true);
?>

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
        display: block;
        margin-top: 4px;
        font-size: 0.68rem;
        line-height: 1.05;
        overflow-wrap: anywhere;
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
    .sidebar.sidebar-collapsed .nav-link i {
        display: block;
        margin: 0 auto 2px !important;
        min-height: 1.2em;
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
        margin-left: 300px;
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
    .sidebar-toggle::before {
        content: attr(data-toggle-label);
        font-size: 1.1rem;
        line-height: 1;
    }
    .sidebar-toggle i {
        display: none;
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

<div class="sidebar" id="sidebar">
    <div class="p-3">
        <h5 class="navbar-brand"><img src="atieralogo.png" alt="Atiera Logo" style="height: 100px;"></h5>
        <hr style="border-top: 2px solid white; margin: 10px 0;">
    </div>
    <nav class="nav flex-column">
        <a class="nav-link <?php echo ($currentPage === 'index.php') ? 'active' : ''; ?>" href="index.php">
            <i class="fas fa-tachometer-alt me-2"></i><span>Dashboard</span>
        </a>
        <div class="nav-item">
            <a class="nav-link <?php echo ($currentPage === 'general_ledger.php') ? 'active' : ''; ?>" href="general_ledger.php">
                <i class="fas fa-book me-2"></i><span>General Ledger</span>
            </a>
            <i class="fas fa-chevron-right" data-bs-toggle="collapse" data-bs-target="#generalLedgerMenu" aria-expanded="<?php echo $glExpanded ? 'true' : 'false'; ?>" style="cursor: pointer; color: white; padding: 5px 10px;"></i>
            <div class="collapse <?php echo $glExpanded ? 'show' : ''; ?>" id="generalLedgerMenu">
                <div class="submenu">
                    <a class="nav-link <?php echo ($currentPage === 'accounts_payable.php') ? 'active' : ''; ?>" href="accounts_payable.php">
                        <i class="fas fa-credit-card me-2"></i><span>Accounts Payable</span>
                    </a>
                    <a class="nav-link <?php echo ($currentPage === 'accounts_receivable.php') ? 'active' : ''; ?>" href="accounts_receivable.php">
                        <i class="fas fa-money-bill-wave me-2"></i><span>Accounts Receivable</span>
                    </a>
                </div>
            </div>
        </div>
        <a class="nav-link <?php echo ($currentPage === 'disbursements.php') ? 'active' : ''; ?>" href="disbursements.php">
            <i class="fas fa-money-check me-2"></i><span>Disbursements</span>
        </a>
        <a class="nav-link <?php echo ($currentPage === 'budget_management.php') ? 'active' : ''; ?>" href="budget_management.php">
            <i class="fas fa-chart-line me-2"></i><span>Budget Management</span>
        </a>
        <a class="nav-link <?php echo ($currentPage === 'reports.php') ? 'active' : ''; ?>" href="reports.php">
            <i class="fas fa-chart-bar me-2"></i><span>Reports</span>
        </a>
        <?php if ($canFinancialSetup): ?>
        <a class="nav-link <?php echo ($currentPage === 'settings.php') ? 'active' : ''; ?>" href="settings.php">
            <i class="fas fa-cogs me-2"></i><span>System Settings</span>
        </a>
        <?php endif; ?>
        <hr class="my-3">
        <a class="nav-link" href="../logout.php">
            <i class="fas fa-sign-out-alt me-2"></i><span>Logout</span>
        </a>
    </nav>
</div>
<div class="sidebar-toggle" onclick="toggleSidebarDesktop()" data-toggle-label="<" role="button" aria-label="Collapse sidebar" tabindex="0">
    <i class="fas fa-chevron-left" id="sidebarArrow"></i>
</div>

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
        localStorage.setItem('superadminSidebarCollapsed', isCollapsed);
        if (isCollapsed) {
            logoImg.src = 'atieralogo2.png';
            content.style.marginLeft = '120px';
            arrow.classList.remove('fa-chevron-left');
            arrow.classList.add('fa-chevron-right');
            toggle.style.left = '110px';
            toggle.dataset.toggleLabel = '>';
            toggle.setAttribute('aria-label', 'Expand sidebar');
        } else {
            logoImg.src = 'atieralogo.png';
            content.style.marginLeft = '300px';
            arrow.classList.remove('fa-chevron-right');
            arrow.classList.add('fa-chevron-left');
            toggle.style.left = '290px';
            toggle.dataset.toggleLabel = '<';
            toggle.setAttribute('aria-label', 'Collapse sidebar');
        }
    }

    // Initialize sidebar state on page load
    document.addEventListener('DOMContentLoaded', function() {
        const sidebar = document.getElementById('sidebar');
        const content = document.querySelector('.content');
        const arrow = document.getElementById('sidebarArrow');
        const toggle = document.querySelector('.sidebar-toggle');
        const logoImg = document.querySelector('.navbar-brand img');
        const isCollapsed = localStorage.getItem('superadminSidebarCollapsed') === 'true';
        if (isCollapsed) {
            sidebar.classList.add('sidebar-collapsed');
            logoImg.src = 'atieralogo2.png';
            content.style.marginLeft = '120px';
            arrow.classList.remove('fa-chevron-left');
            arrow.classList.add('fa-chevron-right');
            toggle.style.left = '110px';
            toggle.dataset.toggleLabel = '>';
            toggle.setAttribute('aria-label', 'Expand sidebar');
        } else {
            sidebar.classList.remove('sidebar-collapsed');
            logoImg.src = 'atieralogo.png';
            content.style.marginLeft = '300px';
            arrow.classList.remove('fa-chevron-right');
            arrow.classList.add('fa-chevron-left');
            toggle.style.left = '290px';
            toggle.dataset.toggleLabel = '<';
            toggle.setAttribute('aria-label', 'Collapse sidebar');
        }
    });
</script>
