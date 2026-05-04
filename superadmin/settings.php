<?php
require_once '../includes/auth.php';
require_once '../includes/permissions.php';
require_once '../includes/logger.php';

$auth = new Auth();
$auth->requireLogin();

// Allow superadmin users to bypass permission checks
$user = $auth->getCurrentUser();
$isSuperAdmin = ($user['role'] === 'super_admin');

if (!$isSuperAdmin) {
    $auth->requirePermission('roles.view');
}

$permManager = PermissionManager::getInstance();
$user = $auth->getCurrentUser();

$message = '';
$error = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (!$isSuperAdmin && !$auth->hasPermission('roles.manage')) {
        $error = 'You do not have permission to manage roles and permissions.';
    } else {
        // No role-related actions for user management
    }
}

// Handle AJAX requests for removing roles/permissions
if ($_SERVER['REQUEST_METHOD'] === 'DELETE' && isset($_GET['action'])) {
    header('Content-Type: application/json');

    global $isSuperAdmin;
    if (!$isSuperAdmin && !$auth->hasPermission('roles.manage')) {
        echo json_encode(['success' => false, 'error' => 'Access denied']);
        exit;
    }

    $action = $_GET['action'];

    // No role-related DELETE actions for user management
    echo json_encode(['success' => false, 'error' => 'Invalid action']);
    exit;
}

// Get data for display
$permissions = $permManager->getAllPermissions();
$users = $auth->getAllUsers();
$departments = [
    [
        'name' => 'Human Resource 3',
        'scope' => 'Workforce Operations & Time Management',
        'modules' => [
            'Claims and Reimbursement'
        ],
        'integration_key' => 'hr3',
        'integrated' => true
    ],
    [
        'name' => 'Human Resource 4',
        'scope' => 'Compensation & HR Intelligence',
        'modules' => [
            'Payroll Management'
        ],
        'integration_key' => 'hr4',
        'integrated' => true
    ],
    [
        'name' => 'Logistics 1',
        'scope' => 'Smart Supply Chain & Procurement Management',
        'modules' => [
            'Procurement & Sourcing Management (PSM)',
            'Document Tracking & Logistics Records (DTRS)'
        ],
        'integration_key' => 'logistics1',
        'integrated' => true
    ],
    [
        'name' => 'Logistics 2',
        'scope' => 'Fleet and Transportation Operations',
        'modules' => [
            'Driver and Trip Performance Monitoring',
            'Transport Cost Analysis & Optimization (TCAO)'
        ],
        'integration_key' => 'logistics2',
        'integrated' => true
    ],
    [
        'name' => 'Core 1 - Hotel',
        'scope' => 'Hotel Operations',
        'modules' => [
            'Billing and Payment Module',
            'Point of Sale (POS) Module',
            'Inventory and Stock Management Module',
            'Reservation and Booking Module',
            'Analytics and Reporting Module'
        ],
        'integration_key' => null,
        'integrated' => false
    ],
    [
        'name' => 'Core 2 - Restaurant',
        'scope' => 'Restaurant Operations',
        'modules' => [
            'Billing and Payment Module',
            'Order Taking and POS Module',
            'Inventory and Stock Management Module',
            'Analytics and Reporting Module',
            'Integration with Payment Gateways Module'
        ],
        'integration_key' => null,
        'integrated' => false
    ]
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Financial Management System - Admin Settings</title>
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

        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header">
                        <h5>System-Level Settings</h5>
                        <small class="text-muted">Changes here affect the entire system/company. All modifications require audit logs and may need dual approval/OTP.</small>
                    </div>
                    <div class="card-body">
                        <ul class="nav nav-tabs" id="settingsTabs" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link active" id="maintenance-tab" data-bs-toggle="tab" data-bs-target="#maintenance" type="button" role="tab" aria-controls="maintenance" aria-selected="true"><i class="fas fa-tools"></i> Maintenance Mode</button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="user-tab" data-bs-toggle="tab" data-bs-target="#user" type="button" role="tab" aria-controls="user" aria-selected="false"><i class="fas fa-users"></i> User Management</button>
                            </li>

                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="departments-tab" data-bs-toggle="tab" data-bs-target="#departments" type="button" role="tab" aria-controls="departments" aria-selected="false"><i class="fas fa-sitemap"></i> Departments Integration</button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="trash-tab" data-bs-toggle="tab" data-bs-target="#trash" type="button" role="tab" aria-controls="trash" aria-selected="false"><i class="fas fa-trash"></i> Recently Deleted</button>
                            </li>
                        </ul>
                        <div class="tab-content mt-3" id="settingsTabContent">
                            <div class="tab-pane fade show active" id="maintenance" role="tabpanel" aria-labelledby="maintenance-tab">
                                <h6>Maintenance Mode</h6>
                                <form id="maintenanceModeForm">
                                    <div class="mb-3">
                                        <label for="maintenanceToggle" class="form-label">Enable Maintenance Mode</label>
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" id="maintenanceToggle">
                                            <label class="form-check-label" for="maintenanceToggle">
                                                Toggle maintenance mode on/off for non-superadmin users
                                            </label>
                                        </div>
                                    </div>
                                    <div class="mb-3">
                                        <label for="maintenanceMessage" class="form-label">Maintenance Banner Message</label>
                                        <textarea class="form-control" id="maintenanceMessage" rows="3" placeholder="Enter message to display during maintenance"></textarea>
                                    </div>
                                    <div id="maintenanceAlertContainer"></div>
                                    <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i>Save Changes</button>
                                </form>
                            </div>
                            <div class="tab-pane fade" id="user" role="tabpanel" aria-labelledby="user-tab">
                                <div id="usersAlertContainer"></div>
                                <div class="d-flex justify-content-between align-items-center mb-4">
                                    <h6 class="mb-0">User Management</h6>
                                </div>

                                <!-- Users Table -->
                                <div class="card">
                                    <div class="card-header">
                                        <h5 class="mb-0"><i class="fas fa-users me-2"></i> System Users</h5>
                                    </div>
                                    <div class="card-body">
                                        <div class="table-responsive">
                                            <table class="table table-striped table-hover">
                                                <thead class="table-dark">
                                                    <tr>
                                                        <th>Username</th>
                                                        <th>Full Name</th>
                                                        <th>Email</th>
                                                        <th>Role</th>
                                                        <th>Status</th>
                                                        <th>Last Login</th>
                                                        <th>Actions</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($users as $userData): ?>
                                                    <tr>
                                                        <td><?php echo htmlspecialchars($userData['username']); ?></td>
                                                        <td><?php echo htmlspecialchars($userData['full_name']); ?></td>
                                                        <td><?php echo htmlspecialchars($userData['email'] ?? 'N/A'); ?></td>
                                                        <td>
                                                            <span class="badge bg-<?php
                                                                echo $userData['role'] === 'super_admin' ? 'danger' :
                                                                     ($userData['role'] === 'admin' ? 'warning' : 'info');
                                                            ?>">
                                                                <?php echo ucfirst(str_replace('_', ' ', $userData['role'])); ?>
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <span class="badge bg-<?php echo $userData['status'] === 'active' ? 'success' : 'secondary'; ?>">
                                                                <?php echo ucfirst($userData['status'] ?? 'active'); ?>
                                                            </span>
                                                        </td>
                                                        <td><?php echo $userData['last_login'] ? date('M j, Y H:i', strtotime($userData['last_login'])) : 'Never'; ?></td>
                                                        <td>
                                                            <div class="btn-group btn-group-sm">
                                                                <button type="button" class="btn btn-outline-primary btn-sm" onclick="editUser(<?php echo $userData['id']; ?>)">
                                                                    <i class="fas fa-edit"></i> Edit
                                                                </button>
                                                                <button type="button" class="btn btn-outline-danger btn-sm" onclick="deleteUser(<?php echo $userData['id']; ?>, '<?php echo htmlspecialchars($userData['username']); ?>')">
                                                                    <i class="fas fa-trash"></i> Delete
                                                                </button>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="tab-pane fade" id="departments" role="tabpanel" aria-labelledby="departments-tab">
                                <div id="departmentsAlertContainer"></div>
                                <div class="card">
                                    <div class="card-header">
                                        <h5><i class="fas fa-sitemap me-2"></i>Integrated Departments</h5>
                                        <small class="text-muted">Live health checks run on page load for connected department APIs.</small>
                                    </div>
                                    <div class="card-body">
                                        <div class="table-responsive">
                                            <table class="table table-striped table-hover table-mobile-stack">
                                                <thead class="table-dark">
                                                    <tr>
                                                        <th>Department</th>
                                                        <th>Scope</th>
                                                        <th>Modules</th>
                                                        <th>API Status</th>
                                                        <th>Actions</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($departments as $department): ?>
                                                    <tr>
                                                        <td data-label="Department"><strong><?php echo htmlspecialchars($department['name']); ?></strong></td>
                                                        <td data-label="Scope"><?php echo htmlspecialchars($department['scope']); ?></td>
                                                        <td data-label="Modules">
                                                            <?php echo htmlspecialchars(implode(', ', $department['modules'])); ?>
                                                        </td>
                                                        <td data-label="API Status">
                                                            <?php if ($department['integration_key']): ?>
                                                                <span class="badge bg-secondary" id="status-<?php echo htmlspecialchars($department['integration_key']); ?>">Checking...</span>
                                                                <div class="text-muted small mt-1" id="status-detail-<?php echo htmlspecialchars($department['integration_key']); ?>"></div>
                                                            <?php else: ?>
                                                                <span class="badge bg-secondary">Not Integrated</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td data-label="Actions">
                                                            <?php if ($department['integration_key']): ?>
                                                                <button class="btn btn-outline-primary btn-sm" onclick="testDepartmentIntegration('<?php echo $department['integration_key']; ?>')">
                                                                    <i class="fas fa-vial"></i> Test
                                                                </button>
                                                            <?php else: ?>
                                                                <span class="text-muted">N/A</span>
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="tab-pane fade" id="trash" role="tabpanel" aria-labelledby="trash-tab">
                                <div id="trashAlertContainer"></div>
                                <div class="d-flex justify-content-between align-items-center mb-4">
                                    <div>
                                        <h6 class="mb-0">Recently Deleted Items</h6>
                                        <small class="text-muted">Items are automatically permanently deleted after 30 days</small>
                                    </div>
                                    <div class="btn-group">
                                        <button type="button" class="btn btn-outline-info btn-sm" onclick="refreshTrash()">
                                            <i class="fas fa-sync"></i> Refresh
                                        </button>
                                        <button type="button" class="btn btn-outline-danger btn-sm" onclick="emptyTrash()">
                                            <i class="fas fa-trash-alt"></i> Empty All
                                        </button>
                                    </div>
                                </div>

                                <!-- Trash Table -->
                                <div class="card">
                                    <div class="card-header">
                                        <h5 class="mb-0"><i class="fas fa-trash me-2"></i> Deleted Items</h5>
                                    </div>
                                    <div class="card-body">
                                        <div class="table-responsive">
                                            <table class="table table-striped table-hover">
                                                <thead class="table-dark">
                                                    <tr>
                                                        <th>Item Type</th>
                                                        <th>Item ID</th>
                                                        <th>Deleted By</th>
                                                        <th>Deleted At</th>
                                                        <th>Auto Delete In</th>
                                                        <th>Actions</th>
                                                    </tr>
                                                </thead>
                                                <tbody id="trashTableBody">
                                                    <!-- Will be populated by JavaScript -->
                                                    <tr>
                                                        <td colspan="6" class="text-center text-muted py-4">
                                                            <i class="fas fa-inbox fa-2x mb-2"></i>
                                                            <br>Loading deleted items...
                                                        </td>
                                                    </tr>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </div>

                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>

    <!-- Modals -->
    <!-- Edit User Modal -->
    <div class="modal fade" id="editUserModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit User</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="editUserForm">
                    <div class="modal-body">
                        <input type="hidden" id="edit_user_id" name="user_id">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="edit_username" class="form-label">Username *</label>
                                    <input type="text" class="form-control" id="edit_username" name="username" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="edit_full_name" class="form-label">Full Name *</label>
                                    <input type="text" class="form-control" id="edit_full_name" name="full_name" required>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="edit_email" class="form-label">Email</label>
                                    <input type="email" class="form-control" id="edit_email" name="email">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="edit_role" class="form-label">Role *</label>
                                    <select class="form-select" id="edit_role" name="role" required>
                                        <option value="staff">Staff</option>
                                        <option value="admin">Admin</option>
                                        <option value="super_admin">Super Admin</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="edit_status" class="form-label">Status *</label>
                                    <select class="form-select" id="edit_status" name="status" required>
                                        <option value="active">Active</option>
                                        <option value="inactive">Inactive</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- Permissions Section -->
                        <div class="mb-3">
                            <label class="form-label">Permissions</label>
                            <div id="permissionsContainer" class="border rounded p-3" style="max-height: 300px; overflow-y: auto;">
                                <!-- Permissions will be loaded here -->
                                <div class="text-center text-muted">
                                    <i class="fas fa-spinner fa-spin"></i> Loading permissions...
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update User</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- View Trash Item Modal -->
    <div class="modal fade" id="viewTrashModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Deleted Item Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="trashViewContent"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>



    <!-- Footer -->
    </div>
    <!-- End content div -->

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../includes/alert-modal.js"></script>
    <script>
        function showDepartmentsAlert(message, type) {
            const alert = `
                <div class="alert alert-${type} alert-dismissible fade show" role="alert">
                    ${message}
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            `;
            document.getElementById('departmentsAlertContainer').innerHTML = alert;

            setTimeout(() => {
                document.querySelector('#departmentsAlertContainer .alert')?.remove();
            }, 5000);
        }

        function checkDepartmentIntegrationStatus(name) {
            const badge = document.getElementById(`status-${name}`);
            const detail = document.getElementById(`status-detail-${name}`);
            if (!badge) {
                return;
            }

            const formData = new FormData();
            formData.append('action', 'test');
            formData.append('integration_name', name);

            fetch('../api/integrations.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(result => {
                if (result.success) {
                    badge.className = 'badge bg-success';
                    badge.textContent = 'Working';
                    if (detail) {
                        detail.textContent = '';
                    }
                } else {
                    badge.className = 'badge bg-danger';
                    badge.textContent = 'Failed';
                    if (detail) {
                        detail.textContent = result.error || result.message || 'No error details returned.';
                    }
                }
            })
            .catch(() => {
                badge.className = 'badge bg-danger';
                badge.textContent = 'Failed';
                if (detail) {
                    detail.textContent = 'Request failed. Check API URL, network, or server response.';
                }
            });
        }

        function syncDepartmentIntegrations() {
            const departments = ['hr3', 'hr4', 'logistics1', 'logistics2'];
            departments.forEach(checkDepartmentIntegrationStatus);
        }

        function testDepartmentIntegration(name) {
            const formData = new FormData();
            formData.append('action', 'test');
            formData.append('integration_name', name);

            fetch('../api/integrations.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(result => {
                if (result.success) {
                    showDepartmentsAlert(result.message || 'Connection successful', 'success');
                } else {
                    showDepartmentsAlert(result.error || result.message || 'Connection failed', 'danger');
                }
            })
            .catch(error => showDepartmentsAlert('Error: ' + error.message, 'danger'));
        }

        // Roles & Permissions Functions
        function showRolesAlert(message, type) {
            const alert = `
                <div class="alert alert-${type} alert-dismissible fade show" role="alert">
                    ${message}
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            `;
            document.getElementById('rolesAlertContainer').innerHTML = alert;
            setTimeout(() => {
                document.querySelector('#rolesAlertContainer .alert')?.remove();
            }, 5000);
        }

        function viewRolePermissions(roleId) {
            fetch(`../api/roles.php?action=role_permissions&role_id=${roleId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        let permissions = data.permissions.map(p => p.name).join(', ');
                        alert(`Permissions for this role:\n${permissions}`);
                    } else {
                        alert('Error loading permissions: ' + data.error);
                    }
                })
                .catch(error => {
                    alert('Error: ' + error.message);
                });
        }

        function viewRoleUsers(roleId) {
            fetch(`../api/roles.php?action=role_users&role_id=${roleId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        let users = data.users.map(u => u.username).join(', ');
                        alert(`Users with this role:\n${users}`);
                    } else {
                        alert('Error loading users: ' + data.error);
                    }
                })
                .catch(error => {
                    alert('Error: ' + error.message);
                });
        }

        function viewUserPermissions(userId) {
            fetch(`../api/roles.php?action=user_roles&user_id=${userId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        let permissions = data.permissions.join(', ');
                        alert(`Permissions for this user:\n${permissions}`);
                    } else {
                        alert('Error loading permissions: ' + data.error);
                    }
                })
                .catch(error => {
                    alert('Error: ' + error.message);
                });
        }

        function assignPermissionToRole(roleId) {
            document.getElementById('permission_role_id').value = roleId;
            const modal = new bootstrap.Modal(document.getElementById('assignPermissionModal'));
            modal.show();
        }

        function assignRoleToUser(userId) {
            const modal = new bootstrap.Modal(document.getElementById('assignRoleModal'));
            // Pre-select the user
            document.getElementById('assign_user_id').value = userId;
            modal.show();
        }

        // User Management Functions
        function showUsersAlert(message, type) {
            const alert = `
                <div class="alert alert-${type} alert-dismissible fade show" role="alert">
                    ${message}
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            `;
            document.getElementById('usersAlertContainer').innerHTML = alert;
            setTimeout(() => {
                document.querySelector('#usersAlertContainer .alert')?.remove();
            }, 5000);
        }

        function editUser(userId) {
            // Fetch user data
            fetch(`../api/users.php?action=get_user&user_id=${userId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const user = data.user;

                        // Populate form fields
                        document.getElementById('edit_user_id').value = user.id;
                        document.getElementById('edit_username').value = user.username;
                        document.getElementById('edit_full_name').value = user.full_name;
                        document.getElementById('edit_email').value = user.email || '';
                        document.getElementById('edit_role').value = user.role;
                        document.getElementById('edit_status').value = user.status;

                        // Load permissions
                        loadUserPermissions(userId);

                        // Show modal
                        const modal = new bootstrap.Modal(document.getElementById('editUserModal'));
                        modal.show();
                    } else {
                        showUsersAlert(data.error || 'Failed to load user data', 'danger');
                    }
                })
                .catch(error => {
                    showUsersAlert('Error: ' + error.message, 'danger');
                });
        }

        function loadUserPermissions(userId) {
            const container = document.getElementById('permissionsContainer');
            container.innerHTML = '<div class="text-center text-muted"><i class="fas fa-spinner fa-spin"></i> Loading permissions...</div>';

            // Fetch all available permissions
            fetch('../api/roles.php?action=permissions')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const allPermissions = data.permissions;

                        // Fetch user's current permissions
                        fetch(`../api/roles.php?action=user_roles&user_id=${userId}`)
                            .then(response => response.json())
                            .then(userData => {
                                console.log('User permissions API response:', userData);

                                if (userData.debug) {
                                    console.log('User roles debug info:', userData.debug);
                                    console.log('User has roles:', userData.debug.role_names);
                                    console.log('User permissions count:', userData.debug.permissions_count);
                                    console.log('User permissions list:', userData.debug.permissions_list);
                                }

                                const userPermissions = userData.success ? userData.permissions : [];

                                // Build permissions checkboxes
                                let html = '<div class="row">';
                                allPermissions.forEach(permission => {
                                    const isChecked = userPermissions.includes(permission.name);
                                    console.log(`Permission "${permission.name}": ${isChecked ? 'CHECKED' : 'unchecked'}`);

                                    html += `
                                        <div class="col-md-6">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox"
                                                       name="permissions[]" value="${permission.name}"
                                                       id="perm_${permission.id}" ${isChecked ? 'checked' : ''}>
                                                <label class="form-check-label" for="perm_${permission.id}">
                                                    <strong>${permission.name}</strong><br>
                                                    <small class="text-muted">${permission.description || 'No description'}</small>
                                                </label>
                                            </div>
                                        </div>
                                    `;
                                });
                                html += '</div>';

                                container.innerHTML = html;
                            })
                            .catch(error => {
                                console.error('Error loading user permissions:', error);
                                container.innerHTML = '<div class="text-danger">Error loading user permissions: ' + error.message + '</div>';
                            });
                    } else {
                        container.innerHTML = '<div class="text-danger">Error loading permissions: ' + data.error + '</div>';
                    }
                })
                .catch(error => {
                    container.innerHTML = '<div class="text-danger">Error: ' + error.message + '</div>';
                });
        }

        // Handle edit user form submission
        document.getElementById('editUserForm').addEventListener('submit', async function(e) {
            e.preventDefault();

            console.log('=== Starting user update process ===');

            const formData = new FormData(this);
            const userId = formData.get('user_id');
            const userData = {
                user_id: userId,
                username: formData.get('username'),
                full_name: formData.get('full_name'),
                email: formData.get('email'),
                role: formData.get('role'),
                status: formData.get('status')
            };
            const selectedPermissions = formData.getAll('permissions[]');

            console.log('User ID:', userId);
            console.log('User data to send:', userData);
            console.log('Selected permissions:', selectedPermissions);

            try {
                console.log('=== Updating user data ===');
                // Update basic user data first
                const userResponse = await fetch(`../api/users.php?id=${userId}`, {
                    method: 'PUT',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify(userData)
                });

                console.log('User API response status:', userResponse.status);
                console.log('User API response ok:', userResponse.ok);
                console.log('User API response headers:', Object.fromEntries(userResponse.headers.entries()));

                let userResult;
                try {
                    userResult = await userResponse.json();
                    console.log('User API response data:', userResult);
                } catch (jsonError) {
                    console.error('Failed to parse user API JSON response:', jsonError);
                    const textResponse = await userResponse.text();
                    console.error('Raw user API response:', textResponse);
                    throw new Error('Invalid JSON response from user API');
                }

                if (!userResult.success) {
                    console.error('User update failed:', userResult.error);
                    throw new Error(userResult.error || 'Failed to update user data');
                }

                console.log('=== User data update successful ===');

                console.log('=== Updating user permissions ===');
                // Update user permissions separately
                const permissionResponse = await fetch('../api/roles.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        action: 'update_user_permissions',
                        user_id: userId,
                        permissions: selectedPermissions
                    })
                });

                console.log('Permissions API response status:', permissionResponse.status);
                console.log('Permissions API response ok:', permissionResponse.ok);
                console.log('Permissions API response headers:', Object.fromEntries(permissionResponse.headers.entries()));

                const clonedResponse = permissionResponse.clone();
                let permissionResult;
                try {
                    permissionResult = await permissionResponse.json();
                    console.log('Permissions API response data:', permissionResult);
                } catch (jsonError) {
                    console.error('Failed to parse permissions API JSON response:', jsonError);
                    let textResponse = '';
                    try {
                        textResponse = await clonedResponse.text();
                        console.error('Raw permissions API response:', textResponse);
                    } catch (textError) {
                        console.error('Could not read response text either:', textError);
                    }
                    if (!textResponse.trim()) {
                        throw new Error(`Invalid JSON response from permissions API (empty body, status ${permissionResponse.status})`);
                    }
                    throw new Error('Invalid JSON response from permissions API');
                }

                if (!permissionResponse.ok) {
                    throw new Error(permissionResult.error || `Permissions API failed with status ${permissionResponse.status}`);
                }

                if (!permissionResult.success) {
                    console.error('Permissions update failed:', permissionResult.error);
                    console.error('Permissions API errors:', permissionResult.errors);
                    throw new Error(permissionResult.error || 'Failed to update user permissions');
                }

                console.log('=== Permissions update successful ===');
                console.log('=== User update process completed successfully ===');

                showUsersAlert('User updated successfully', 'success');
                const modal = bootstrap.Modal.getInstance(document.getElementById('editUserModal'));
                modal.hide();
                setTimeout(() => location.reload(), 1500);

            } catch (error) {
                console.error('=== User update process failed ===');
                console.error('Error details:', error);
                console.error('Error message:', error.message);
                console.error('Error stack:', error.stack);
                showUsersAlert('Error: ' + error.message, 'danger');
            }
        });

        function deleteUser(userId, username) {
            showConfirmDialog(
                'Delete User',
                `Are you sure you want to delete user "${username}"? This action will soft delete the user.`,
                async () => {
                    try {
                        const response = await fetch('../api/users.php', {
                            method: 'DELETE',
                            headers: {
                                'Content-Type': 'application/json',
                            },
                            body: JSON.stringify({
                                action: 'delete_user',
                                user_id: userId
                            })
                        });
                        const data = await response.json();
                        if (data.success) {
                            showUsersAlert('User deleted successfully', 'success');
                            setTimeout(() => location.reload(), 1500);
                        } else {
                            showUsersAlert(data.error || 'Failed to delete user', 'danger');
                        }
                    } catch (error) {
                        showUsersAlert('Error: ' + error.message, 'danger');
                    }
                }
            );
        }

        function showMaintenanceAlert(message, type) {
            const alert = `
                <div class="alert alert-${type} alert-dismissible fade show" role="alert">
                    ${message}
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            `;
            document.getElementById('maintenanceAlertContainer').innerHTML = alert;
            setTimeout(() => {
                document.querySelector('#maintenanceAlertContainer .alert')?.remove();
            }, 5000);
        }

        async function loadMaintenanceModeState() {
            try {
                const response = await fetch('../api/maintenance_mode.php');
                const data = await response.json();
                if (!data.success) {
                    throw new Error(data.error || 'Failed to load maintenance mode state');
                }

                const state = data.state || {};
                document.getElementById('maintenanceToggle').checked = !!state.enabled;
                document.getElementById('maintenanceMessage').value = state.message || '';
            } catch (error) {
                showMaintenanceAlert('Failed to load maintenance mode state: ' + error.message, 'danger');
            }
        }

        // Trash Management Functions
        function showTrashAlert(message, type) {
            const alert = `
                <div class="alert alert-${type} alert-dismissible fade show" role="alert">
                    ${message}
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            `;
            document.getElementById('trashAlertContainer').innerHTML = alert;
            setTimeout(() => {
                document.querySelector('#trashAlertContainer .alert')?.remove();
            }, 5000);
        }

        function refreshTrash() {
            loadTrashItems();
        }

        function emptyTrash() {
            showConfirmDialog(
                'Empty Trash',
                'Are you sure you want to permanently delete ALL items in the trash? This action cannot be undone.',
                async () => {
                    try {
                        const response = await fetch('../api/trash.php', {
                            method: 'DELETE',
                            headers: {
                                'Content-Type': 'application/json',
                            },
                            body: JSON.stringify({
                                action: 'empty_trash'
                            })
                        });
                        const data = await response.json();
                        if (data.success) {
                            showTrashAlert('All trash items permanently deleted', 'success');
                            loadTrashItems();
                        } else {
                            showTrashAlert(data.error || 'Failed to empty trash', 'danger');
                        }
                    } catch (error) {
                        showTrashAlert('Error: ' + error.message, 'danger');
                    }
                }
            );
        }

        function loadTrashItems() {
            const tbody = document.getElementById('trashTableBody');
            tbody.innerHTML = `
                <tr>
                    <td colspan="6" class="text-center text-muted py-4">
                        <i class="fas fa-spinner fa-spin fa-2x mb-2"></i>
                        <br>Loading deleted items...
                    </td>
                </tr>
            `;

            fetch('../api/trash.php?action=get_trash')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        if (data.items.length === 0) {
                            tbody.innerHTML = `
                                <tr>
                                    <td colspan="6" class="text-center text-muted py-4">
                                        <i class="fas fa-inbox fa-2x mb-2"></i>
                                        <br>No deleted items found
                                    </td>
                                </tr>
                            `;
                            return;
                        }

                        tbody.innerHTML = data.items.map(item => {
                            const deletedDate = new Date(item.deleted_at);
                            const autoDeleteDate = new Date(item.auto_delete_at);
                            const now = new Date();
                            const daysLeft = Math.ceil((autoDeleteDate - now) / (1000 * 60 * 60 * 24));

                            return `
                                <tr>
                                    <td>${item.table_name}</td>
                                    <td>${item.record_id}</td>
                                    <td>${item.deleted_by_name || 'Unknown'}</td>
                                    <td>${deletedDate.toLocaleDateString()} ${deletedDate.toLocaleTimeString()}</td>
                                    <td>
                                        <span class="badge bg-${daysLeft <= 7 ? 'danger' : daysLeft <= 14 ? 'warning' : 'info'}">
                                            ${daysLeft > 0 ? daysLeft + ' days' : 'Expired'}
                                        </span>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <button type="button" class="btn btn-outline-info btn-sm" onclick="viewTrashItem(${item.id}, '${item.item_type}')">
                                                <i class="fas fa-eye"></i> View
                                            </button>
                                            <button type="button" class="btn btn-outline-success btn-sm" onclick="restoreTrashItem(${item.id}, '${item.item_type}')">
                                                <i class="fas fa-undo"></i> Restore
                                            </button>
                                            <button type="button" class="btn btn-outline-danger btn-sm" onclick="permanentDelete(${item.id}, '${item.item_type}')">
                                                <i class="fas fa-trash-alt"></i> Delete
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            `;
                        }).join('');
                    } else {
                        tbody.innerHTML = `
                            <tr>
                                <td colspan="6" class="text-center text-danger py-4">
                                    <i class="fas fa-exclamation-triangle fa-2x mb-2"></i>
                                    <br>Error loading trash items: ${data.error}
                                </td>
                            </tr>
                        `;
                    }
                })
                .catch(error => {
                    tbody.innerHTML = `
                        <tr>
                            <td colspan="6" class="text-center text-danger py-4">
                                <i class="fas fa-exclamation-triangle fa-2x mb-2"></i>
                                <br>Error: ${error.message}
                            </td>
                        </tr>
                    `;
                });
        }

        function viewTrashItem(itemId, itemType) {
            const params = new URLSearchParams({
                action: 'view_item',
                item_id: itemId,
                item_type: itemType || ''
            });
            fetch(`../api/trash.php?${params.toString()}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const content = document.getElementById('trashViewContent');
                        const item = data.item || {};
                        let html = '';

                        if (itemType === 'soft_deleted_user') {
                            html = `
                                <div class="card">
                                    <div class="card-body">
                                        <div class="mb-2"><strong>Type:</strong> Soft Deleted User</div>
                                        <div class="mb-2"><strong>Username:</strong> ${item.username || '-'}</div>
                                        <div class="mb-2"><strong>Full Name:</strong> ${item.full_name || '-'}</div>
                                        <div class="mb-2"><strong>Email:</strong> ${item.email || '-'}</div>
                                        <div class="mb-2"><strong>Role:</strong> ${item.role || '-'}</div>
                                        <div class="mb-2"><strong>Status:</strong> ${item.status || '-'}</div>
                                        <div class="mb-2"><strong>Deleted At:</strong> ${item.deleted_at || '-'}</div>
                                    </div>
                                </div>
                            `;
                        } else {
                            html = `
                                <div class="card">
                                    <div class="card-body">
                                        <div class="mb-2"><strong>Type:</strong> Deleted Record</div>
                                        <pre class="mb-0" style="white-space: pre-wrap; color: #495057;">${JSON.stringify(item, null, 2)}</pre>
                                    </div>
                                </div>
                            `;
                        }

                        content.innerHTML = html;
                        const modal = new bootstrap.Modal(document.getElementById('viewTrashModal'));
                        modal.show();
                    } else {
                        showTrashAlert(data.error || 'Failed to load item', 'danger');
                    }
                })
                .catch(error => {
                    showTrashAlert('Error: ' + error.message, 'danger');
                });
        }

        function restoreTrashItem(itemId, itemType) {
            showConfirmDialog(
                'Restore Item',
                'Are you sure you want to restore this item?',
                async () => {
                    try {
                        const response = await fetch('../api/trash.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                            },
                            body: JSON.stringify({
                                action: 'restore_item',
                                item_id: itemId,
                                item_type: itemType
                            })
                        });
                        const data = await response.json();
                        if (data.success) {
                            showTrashAlert('Item restored successfully', 'success');
                            loadTrashItems();
                        } else {
                            showTrashAlert(data.error || 'Failed to restore item', 'danger');
                        }
                    } catch (error) {
                        showTrashAlert('Error: ' + error.message, 'danger');
                    }
                }
            );
        }

        function permanentDelete(itemId, itemType) {
            showConfirmDialog(
                'Permanent Delete',
                'Are you sure you want to permanently delete this item? This action cannot be undone.',
                async () => {
                    try {
                        const response = await fetch('../api/trash.php', {
                            method: 'DELETE',
                            headers: {
                                'Content-Type': 'application/json',
                            },
                            body: JSON.stringify({
                                action: 'permanent_delete',
                                item_id: itemId,
                                item_type: itemType
                            })
                        });
                        const data = await response.json();
                        if (data.success) {
                            showTrashAlert('Item permanently deleted', 'success');
                            loadTrashItems();
                        } else {
                            showTrashAlert(data.error || 'Failed to delete item', 'danger');
                        }
                    } catch (error) {
                        showTrashAlert('Error: ' + error.message, 'danger');
                    }
                }
            );
        }

        // Tab persistence functionality
        function saveActiveTab(tabId) {
            localStorage.setItem('superadminSettingsActiveTab', tabId);
        }

        function restoreActiveTab() {
            const savedTab = localStorage.getItem('superadminSettingsActiveTab');
            if (savedTab) {
                const tabElement = document.getElementById(savedTab + '-tab');
                if (tabElement) {
                    const tab = new bootstrap.Tab(tabElement);
                    tab.show();
                    return true;
                }
            }
            return false;
        }

        // Initialize trash on page load when trash tab is active
        document.addEventListener('DOMContentLoaded', function() {
            const maintenanceForm = document.getElementById('maintenanceModeForm');
            maintenanceForm?.addEventListener('submit', async function(e) {
                e.preventDefault();

                const submitBtn = maintenanceForm.querySelector('button[type="submit"]');
                const originalText = submitBtn.innerHTML;
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Saving...';

                try {
                    const response = await fetch('../api/maintenance_mode.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({
                            enabled: document.getElementById('maintenanceToggle').checked,
                            message: document.getElementById('maintenanceMessage').value
                        })
                    });

                    const data = await response.json();
                    if (!data.success) {
                        throw new Error(data.error || 'Failed to save maintenance mode');
                    }

                    showMaintenanceAlert('Maintenance mode settings saved successfully', 'success');
                } catch (error) {
                    showMaintenanceAlert('Error saving maintenance mode: ' + error.message, 'danger');
                } finally {
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = originalText;
                }
            });

            loadMaintenanceModeState();

            // Restore active tab
            const tabRestored = restoreActiveTab();

            // Save active tab when it changes
            document.querySelectorAll('#settingsTabs .nav-link').forEach(tab => {
                tab.addEventListener('shown.bs.tab', function(e) {
                    const tabId = e.target.id.replace('-tab', '');
                    saveActiveTab(tabId);
                });
            });

            // Load trash items if on trash tab
            const trashTab = document.getElementById('trash-tab');
            if (trashTab && trashTab.classList.contains('active')) {
                loadTrashItems();
            }

            // Load trash items when trash tab is clicked
            trashTab?.addEventListener('shown.bs.tab', function() {
                loadTrashItems();
            });

            syncDepartmentIntegrations();
        });

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
            syncDepartmentIntegrations();
        });
    </script>
<script src="../includes/tab_persistence.js?v=1"></script>
</body>
</html>



