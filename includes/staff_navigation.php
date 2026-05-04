<?php
/**
 * Staff Navigation Component
 * Permission-aware sidebar navigation for staff users
 */

require_once __DIR__ . '/permissions.php';

// Get current page for active state
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
$currentPage = basename($scriptName);

$permManager = PermissionManager::getInstance();
if (!empty($_SESSION['user']['id'])) {
    $permManager->loadUserPermissions($_SESSION['user']['id']);
}

function staffCan($permOrList) {
    $pm = PermissionManager::getInstance();
    if (is_array($permOrList)) {
        return $pm->hasAnyPermission($permOrList);
    }
    return $pm->hasPermission($permOrList);
}
?>

<!-- Staff Sidebar Navigation -->
<div class="sidebar" id="sidebar">
    <div class="p-3">
        <h5 class="navbar-brand"><img src="atieralogo.png" alt="Atiera Logo" style="height: 100px;"></h5>
    </div>
    <nav class="nav flex-column">
        <!-- Dashboard -->
        <a class="nav-link <?php echo ($currentPage === 'index.php') ? 'active' : ''; ?>" href="index.php">
            <i class="fas fa-tachometer-alt me-2"></i><span>Dashboard</span>
        </a>

        <?php if (staffCan('tasks.view')): ?>
            <a class="nav-link <?php echo ($currentPage === 'tasks.php') ? 'active' : ''; ?>" href="tasks.php">
                <i class="fas fa-tasks me-2"></i><span>My Tasks</span>
            </a>
        <?php endif; ?>

        <?php if (staffCan(['chart_of_accounts.view', 'bills.view', 'invoices.view'])): ?>
            <div class="nav-item">
                <a class="nav-link <?php echo ($currentPage === 'general_ledger.php') ? 'active' : ''; ?>" href="general_ledger.php">
                    <i class="fas fa-book me-2"></i><span>General Ledger</span>
                </a>
                <i class="fas fa-chevron-right" data-bs-toggle="collapse" data-bs-target="#generalLedgerMenu"
                   aria-expanded="false" style="cursor: pointer; color: white; padding: 5px 10px;"></i>
                <div class="collapse" id="generalLedgerMenu">
                    <div class="submenu">
                        <?php if (staffCan('bills.view')): ?>
                            <a class="nav-link <?php echo ($currentPage === 'accounts_payable.php') ? 'active' : ''; ?>" href="accounts_payable.php">
                                <i class="fas fa-credit-card me-2"></i><span>Accounts Payable</span>
                            </a>
                        <?php endif; ?>
                        <?php if (staffCan('invoices.view')): ?>
                            <a class="nav-link <?php echo ($currentPage === 'accounts_receivable.php') ? 'active' : ''; ?>" href="accounts_receivable.php">
                                <i class="fas fa-money-bill-wave me-2"></i><span>Accounts Receivable</span>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if (staffCan('reports.view')): ?>
            <a class="nav-link <?php echo ($currentPage === 'reports.php') ? 'active' : ''; ?>" href="reports.php">
                <i class="fas fa-chart-bar me-2"></i><span>Reports</span>
            </a>
        <?php endif; ?>

        <?php if (staffCan('disbursements.view')): ?>
            <a class="nav-link <?php echo ($currentPage === 'disbursements.php') ? 'active' : ''; ?>" href="disbursements.php">
                <i class="fas fa-money-check me-2"></i><span>Disbursements</span>
            </a>
        <?php endif; ?>

        <?php if (staffCan('budgets.view')): ?>
            <a class="nav-link <?php echo ($currentPage === 'budget_management.php') ? 'active' : ''; ?>" href="budget_management.php">
                <i class="fas fa-chart-line me-2"></i><span>Budget Management</span>
            </a>
        <?php endif; ?>

        <!-- Logout -->
        <a class="nav-link text-danger" href="../logout.php">
            <i class="fas fa-sign-out-alt me-2"></i><span>Logout</span>
        </a>
    </nav>
</div>

<!-- Sidebar Toggle -->
<div class="sidebar-toggle" onclick="toggleSidebarDesktop()" role="button" aria-label="Collapse sidebar" tabindex="0">
    <span id="sidebarToggleFallback">&lt;</span>
    <i class="fas fa-chevron-left" id="sidebarArrow" style="display: none;"></i>
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
    const fallback = document.getElementById('sidebarToggleFallback');
    const logoImg = document.querySelector('.navbar-brand img');
    sidebar.classList.toggle('sidebar-collapsed');
    const isCollapsed = sidebar.classList.contains('sidebar-collapsed');
    localStorage.setItem('staffSidebarCollapsed', isCollapsed);
    if (isCollapsed) {
        logoImg.src = 'atieralogo2.png';
        if (content) content.style.marginLeft = '120px';
        arrow.classList.remove('fa-chevron-left');
        arrow.classList.add('fa-chevron-right');
        if (fallback) fallback.textContent = '>';
        toggle.style.left = '110px';
        toggle.setAttribute('aria-label', 'Expand sidebar');
    } else {
        logoImg.src = 'atieralogo.png';
        if (content) content.style.marginLeft = '300px';
        arrow.classList.remove('fa-chevron-right');
        arrow.classList.add('fa-chevron-left');
        if (fallback) fallback.textContent = '<';
        toggle.style.left = '290px';
        toggle.setAttribute('aria-label', 'Collapse sidebar');
    }
}

// Initialize sidebar state on page load
document.addEventListener('DOMContentLoaded', function() {
    const sidebar = document.getElementById('sidebar');
    const content = document.querySelector('.content');
    const arrow = document.getElementById('sidebarArrow');
    const toggle = document.querySelector('.sidebar-toggle');
    const fallback = document.getElementById('sidebarToggleFallback');
    const logoImg = document.querySelector('.navbar-brand img');
    const isCollapsed = localStorage.getItem('staffSidebarCollapsed') === 'true';
    if (isCollapsed) {
        sidebar.classList.add('sidebar-collapsed');
        logoImg.src = 'atieralogo2.png';
        if (content) content.style.marginLeft = '120px';
        arrow.classList.remove('fa-chevron-left');
        arrow.classList.add('fa-chevron-right');
        if (fallback) fallback.textContent = '>';
        toggle.style.left = '110px';
        toggle.setAttribute('aria-label', 'Expand sidebar');
    } else {
        sidebar.classList.remove('sidebar-collapsed');
        logoImg.src = 'atieralogo.png';
        if (content) content.style.marginLeft = '300px';
        arrow.classList.remove('fa-chevron-right');
        arrow.classList.add('fa-chevron-left');
        if (fallback) fallback.textContent = '<';
        toggle.style.left = '290px';
        toggle.setAttribute('aria-label', 'Collapse sidebar');
    }
});
</script>

