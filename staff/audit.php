<?php
/**
 * ATIERA Financial Management System - Audit Trail Viewer
 * Admin interface for viewing audit logs and system activity
 */

require_once '../includes/auth.php';
require_once '../includes/logger.php';

$auth = new Auth();
$auth->requireLogin();
$auth->requirePermission('audit.view');

$logger = Logger::getInstance();
$user = $auth->getCurrentUser();

// Get filters from request
$filters = [
    'user_id' => $_GET['user_id'] ?? '',
    'action' => $_GET['action'] ?? '',
    'table_name' => $_GET['table_name'] ?? '',
    'date_from' => $_GET['date_from'] ?? '',
    'date_to' => $_GET['date_to'] ?? '',
    'ip_address' => $_GET['ip_address'] ?? ''
];

$page = (int)($_GET['page'] ?? 1);
$limit = (int)($_GET['limit'] ?? 50);
$offset = ($page - 1) * $limit;

// Get audit logs
$auditLogs = $logger->getAuditLogs($filters, $limit, $offset);

// Get statistics
$stats = $logger->getAuditStats();

// Get unique values for filter dropdowns
try {
    $db = Database::getInstance()->getConnection();

    // Get unique actions
    $stmt = $db->query("SELECT DISTINCT action FROM audit_log ORDER BY action");
    $uniqueActions = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Get unique tables
    $stmt = $db->query("SELECT DISTINCT table_name FROM audit_log WHERE table_name IS NOT NULL ORDER BY table_name");
    $uniqueTables = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Get unique users
    $stmt = $db->query("SELECT DISTINCT u.id, u.username, u.full_name FROM audit_log al JOIN users u ON al.user_id = u.id ORDER BY u.username");
    $uniqueUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    $uniqueActions = [];
    $uniqueTables = [];
    $uniqueUsers = [];
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Financial Management System - Audit Trail</title>
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
    </style>
</head>
<body>
    <?php include '../includes/staff_navigation.php'; ?>

    <div class="content">
        <!-- Top Navbar -->
        <?php include '../includes/global_navbar.php'; ?>
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-md-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><i class="fas fa-history"></i> Audit Trail</h2>
                <div>
                    <button type="button" class="btn btn-outline-primary me-2" onclick="exportAuditLogs()">
                        <i class="fas fa-download"></i> Export
                    </button>
                    <button type="button" class="btn btn-outline-warning" onclick="cleanupOldLogs()">
                        <i class="fas fa-broom"></i> Cleanup Old Logs
                    </button>
                </div>
            </div>

            <!-- Statistics Cards -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="card bg-primary text-white">
                        <div class="card-body">
                            <h5 class="card-title"><i class="fas fa-list"></i> Total Logs</h5>
                            <h3><?php echo number_format($stats['total_logs'] ?? 0); ?></h3>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card bg-success text-white">
                        <div class="card-body">
                            <h5 class="card-title"><i class="fas fa-users"></i> Active Users</h5>
                            <h3><?php echo number_format($stats['unique_users'] ?? 0); ?></h3>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card bg-info text-white">
                        <div class="card-body">
                            <h5 class="card-title"><i class="fas fa-clock"></i> Recent Activity</h5>
                            <h3><?php echo number_format($stats['recent_logs'] ?? 0); ?></h3>
                            <small>Last 30 days</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card bg-warning text-white">
                        <div class="card-body">
                            <h5 class="card-title"><i class="fas fa-calendar"></i> Last Activity</h5>
                            <h6><?php echo $stats['last_activity'] ? date('M j, Y H:i', strtotime($stats['last_activity'])) : 'Never'; ?></h6>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-filter"></i> Filters</h5>
                </div>
                <div class="card-body">
                    <form method="GET" class="row g-3">
                        <div class="col-md-2">
                            <label for="user_id" class="form-label">User</label>
                            <select class="form-control" id="user_id" name="user_id">
                                <option value="">All Users</option>
                                <?php foreach ($uniqueUsers as $u): ?>
                                <option value="<?php echo $u['id']; ?>" <?php echo ($filters['user_id'] == $u['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($u['username'] . ' - ' . $u['full_name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label for="action" class="form-label">Action</label>
                            <select class="form-control" id="action" name="action">
                                <option value="">All Actions</option>
                                <?php foreach ($uniqueActions as $action): ?>
                                <option value="<?php echo htmlspecialchars($action); ?>" <?php echo ($filters['action'] == $action) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($action); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label for="table_name" class="form-label">Table</label>
                            <select class="form-control" id="table_name" name="table_name">
                                <option value="">All Tables</option>
                                <?php foreach ($uniqueTables as $table): ?>
                                <option value="<?php echo htmlspecialchars($table); ?>" <?php echo ($filters['table_name'] == $table) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($table); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label for="date_from" class="form-label">From Date</label>
                            <input type="date" class="form-control" id="date_from" name="date_from" value="<?php echo htmlspecialchars($filters['date_from']); ?>">
                        </div>
                        <div class="col-md-2">
                            <label for="date_to" class="form-label">To Date</label>
                            <input type="date" class="form-control" id="date_to" name="date_to" value="<?php echo htmlspecialchars($filters['date_to']); ?>">
                        </div>
                        <div class="col-md-2">
                            <label for="ip_address" class="form-label">IP Address</label>
                            <input type="text" class="form-control" id="ip_address" name="ip_address" value="<?php echo htmlspecialchars($filters['ip_address']); ?>" placeholder="192.168.1.1">
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-primary me-2">
                                <i class="fas fa-search"></i> Filter
                            </button>
                            <a href="audit.php" class="btn btn-secondary">
                                <i class="fas fa-times"></i> Clear Filters
                            </a>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Audit Logs Table -->
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-table"></i> Audit Logs</h5>
                    <div class="btn-group" role="group">
                        <input type="number" id="limit" class="form-control form-control-sm" value="<?php echo $limit; ?>" style="width: 80px;" min="10" max="500">
                        <span class="input-group-text">per page</span>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped table-hover">
                            <thead class="table-dark">
                                <tr>
                                    <th>Date/Time</th>
                                    <th>User</th>
                                    <th>Action</th>
                                    <th>Table</th>
                                    <th>Record ID</th>
                                    <th>IP Address</th>
                                    <th>Details</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($auditLogs)): ?>
                                <tr>
                                    <td colspan="7" class="text-center text-muted">No audit logs found matching the current filters.</td>
                                </tr>
                                <?php else: ?>
                                <?php foreach ($auditLogs as $log): ?>
                                <tr>
                                    <td><?php echo date('M j, Y H:i:s', strtotime($log['created_at'])); ?></td>
                                    <td>
                                        <?php if (!empty($log['full_name']) || !empty($log['username'])): ?>
                                            <?php $displayName = $log['full_name'] ?: $log['username']; ?>
                                            <span title="<?php echo htmlspecialchars($log['username'] ?? $displayName); ?>">
                                                <?php echo htmlspecialchars($displayName); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted">System</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($log['action']); ?></td>
                                    <td><?php echo htmlspecialchars($log['table_name'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($log['record_id'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($log['ip_address']); ?></td>
                                    <td>
                                        <button type="button" class="btn btn-sm btn-info" onclick="showLogDetails(<?php echo $log['id']; ?>)">
                                            <i class="fas fa-eye"></i> Details
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <?php if (!empty($auditLogs)): ?>
                    <nav aria-label="Audit log pagination" class="mt-3">
                        <ul class="pagination justify-content-center">
                            <?php
                            // Simple pagination - in a real app you'd calculate total pages
                            $prevPage = max(1, $page - 1);
                            $nextPage = $page + 1;
                            ?>
                            <li class="page-item <?php echo ($page <= 1) ? 'disabled' : ''; ?>">
                                <a class="page-link" href="?page=<?php echo $prevPage; ?>&<?php echo http_build_query(array_filter($filters)); ?>">Previous</a>
                            </li>
                            <li class="page-item active">
                                <span class="page-link">Page <?php echo $page; ?></span>
                            </li>
                            <li class="page-item">
                                <a class="page-link" href="?page=<?php echo $nextPage; ?>&<?php echo http_build_query(array_filter($filters)); ?>">Next</a>
                            </li>
                        </ul>
                    </nav>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Log Details Modal -->
<div class="modal fade" id="logDetailsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Audit Log Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="logDetailsContent">
                    <!-- Content will be loaded here -->
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
// Show log details
function showLogDetails(logId) {
    fetch(`api/audit.php?action=details&id=${logId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const log = data.log;
                const content = `
                    <div class="row">
                        <div class="col-md-6">
                            <h6>Basic Information</h6>
                            <table class="table table-sm">
                                <tr><td><strong>ID:</strong></td><td>${log.id}</td></tr>
                                <tr><td><strong>User:</strong></td><td>${log.full_name || log.username || 'System'}</td></tr>
                                <tr><td><strong>Action:</strong></td><td>${log.action}</td></tr>
                                <tr><td><strong>Table:</strong></td><td>${log.table_name || 'N/A'}</td></tr>
                                <tr><td><strong>Record ID:</strong></td><td>${log.record_id || 'N/A'}</td></tr>
                                <tr><td><strong>IP Address:</strong></td><td>${log.ip_address}</td></tr>
                                <tr><td><strong>Timestamp:</strong></td><td>${new Date(log.created_at).toLocaleString()}</td></tr>
                            </table>
                        </div>
                        <div class="col-md-6">
                            <h6>Changes</h6>
                            ${log.old_values ? `<h7>Old Values:</h7><pre class="bg-light p-2">${JSON.stringify(JSON.parse(log.old_values), null, 2)}</pre>` : ''}
                            ${log.new_values ? `<h7>New Values:</h7><pre class="bg-light p-2">${JSON.stringify(JSON.parse(log.new_values), null, 2)}</pre>` : ''}
                            ${!log.old_values && !log.new_values ? '<p class="text-muted">No value changes recorded</p>' : ''}
                        </div>
                    </div>
                    <div class="row mt-3">
                        <div class="col-12">
                            <h6>User Agent</h6>
                            <code class="d-block bg-light p-2">${log.user_agent}</code>
                        </div>
                    </div>
                `;
                document.getElementById('logDetailsContent').innerHTML = content;
                const modalEl = document.getElementById('logDetailsModal');
                if (modalEl) {
                    new bootstrap.Modal(modalEl).show();
                }
            } else {
                alert('Error loading log details: ' + data.error);
            }
        })
        .catch(error => {
            alert('Error: ' + error.message);
        });
}

// Export audit logs
function exportAuditLogs() {
    const filters = new URLSearchParams(window.location.search);
    window.open(`api/audit.php?action=export&${filters.toString()}`, '_blank');
}

// Cleanup old logs
function cleanupOldLogs() {
    showConfirmDialog(
        'Cleanup Audit Logs',
        'Are you sure you want to cleanup audit logs older than 1 year? This action cannot be undone.',
        async () => {
            try {
                const response = await fetch('../api/audit.php?action=cleanup', { method: 'POST' });
                const data = await response.json();
                if (data.success) {
                    showAlert(`Successfully cleaned up ${data.deleted_count} old audit log entries.`, 'success');
                    location.reload();
                } else {
                    showAlert('Error: ' + data.error, 'danger');
                }
            } catch (error) {
                showAlert('Error: ' + error.message, 'danger');
            }
        }
    );
}

// Update limit when changed
document.getElementById('limit').addEventListener('change', function() {
    const url = new URL(window.location);
    url.searchParams.set('limit', this.value);
    url.searchParams.set('page', '1'); // Reset to first page
    window.location.href = url.toString();
});
</script><!-- Inactivity Timeout - Blur screen + Auto logout -->
    <script src="../includes/inactivity_timeout.js?v=3"></script>
<script src="../includes/navbar_datetime.js"></script>
<script src="../includes/tab_persistence.js?v=1"></script>
</body>
</html>





