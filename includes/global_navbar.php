<?php
$pageTitle = $pageTitle ?? null;
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
$isUserArea = strpos($scriptName, '/user/') !== false;
$fallbackTitle = ucwords(str_replace('_', ' ', pathinfo($scriptName, PATHINFO_FILENAME)));
$navTitle = $pageTitle ? htmlspecialchars($pageTitle) : htmlspecialchars($fallbackTitle ?: 'Dashboard');

$firstName = $_SESSION['user']['first_name'] ?? '';
$lastName = $_SESSION['user']['last_name'] ?? '';
$fullName = $_SESSION['user']['full_name'] ?? '';
$userName = $_SESSION['user']['username'] ?? '';

if (!empty($firstName) || !empty($lastName)) {
    $displayName = trim($firstName . ' ' . $lastName);
} elseif (!empty($fullName)) {
    $displayName = $fullName;
} else {
    $displayName = $userName;
}

$isSuperAdminArea = strpos($scriptName, '/superadmin/') !== false;
$isAdminArea = strpos($scriptName, '/admin/') !== false;
$isStaffArea = strpos($scriptName, '/staff/') !== false;
$profileLink = $isUserArea ? 'profile.php' : ($isSuperAdminArea ? 'superadmin-profile-settings.php' : ($isStaffArea ? 'profile-settings.php' : 'admin-profile-settings.php'));
$settingsLink = $isUserArea ? 'settings.php' : 'settings.php';
$searchLink = $isSuperAdminArea || $isAdminArea ? '/admin/search.php' : '/staff/search.php';
$scriptDirectory = trim(str_replace('\\', '/', dirname($scriptName)), '/.');
$pathSegments = $scriptDirectory === '' ? [] : explode('/', $scriptDirectory);
$assetPrefix = str_repeat('../', count($pathSegments));

// Get user role for display
require_once '../includes/permissions.php';
require_once __DIR__ . '/csrf.php';
$permManager = PermissionManager::getInstance();
$permManager->loadUserPermissions($_SESSION['user']['id']);
$userRoles = $permManager->getUserRoles();
$userRoleDisplay = !empty($userRoles) ? $userRoles[0]['role_name'] : 'User';
$userRoleDisplay = ucwords(str_replace('_', ' ', $userRoleDisplay));
$roleNames = array_column($userRoles, 'role_name');
$isAdminRole = in_array('admin', $roleNames, true) || in_array('super_admin', $roleNames, true);
$canViewSettings = $isAdminRole || $permManager->hasPermission('settings.view') || $permManager->hasPermission('roles.view');
$csrfToken = csrf_token();
?>
<?php include_once __DIR__ . '/loading_screen.php'; ?>
<style>
    .navbar-date-time {
        font-size: 0.95rem;
        font-weight: 600;
    }
</style>
<nav class="navbar navbar-expand-lg navbar-light bg-white mb-4 shadow-sm">
    <div class="container-fluid">
        <button class="btn btn-outline-secondary toggle-btn" type="button" onclick="toggleSidebar()">
            <i class="fas fa-bars"></i>
        </button>
        <span class="navbar-brand mb-0 h1 me-4"><?php echo $navTitle; ?></span>
        <div class="d-flex align-items-center me-4">
            <button class="btn btn-link me-3" type="button" id="privacyEyeButton"
                    title="Toggle Privacy Mode - Show/Hide Amounts" aria-label="Toggle privacy mode">
                <i class="fas fa-eye fa-lg" id="privacyEyeIcon"></i>
            </button>
            <div class="dropdown">
                <button class="btn btn-link text-dark dropdown-toggle d-flex align-items-center" type="button" id="userDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                    <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center me-2" style="width: 35px; height: 35px;">
                        <i class="fas fa-user"></i>
                    </div>
                    <div class="d-flex flex-column align-items-start">
                        <span><strong><?php echo htmlspecialchars($displayName); ?></strong></span>
                        <small class="text-muted" style="font-size: 0.75rem; line-height: 1;"><?php echo htmlspecialchars($userRoleDisplay); ?></small>
                    </div>
                </button>
                <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                    <li><a class="dropdown-item" href="<?php echo htmlspecialchars($profileLink); ?>"><i class="fas fa-user me-2"></i>Profile Settings</a></li>
                    <?php if ($canViewSettings): ?>
                        <li><a class="dropdown-item" href="<?php echo htmlspecialchars($settingsLink); ?>"><i class="fas fa-cog me-2"></i>Settings</a></li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
        <div class="d-flex align-items-center flex-grow-1">
            <form class="input-group mx-auto" style="width: 500px;" method="get" action="<?php echo htmlspecialchars($searchLink); ?>">
                <input type="text" class="form-control" name="q" placeholder="Search..." aria-label="Search">
                <button class="btn btn-outline-secondary" type="submit">
                    <i class="fas fa-search"></i>
                </button>
            </form>
        </div>
    </div>
</nav>
<meta name="csrf-token" content="<?php echo htmlspecialchars($csrfToken); ?>">
<script src="<?php echo htmlspecialchars($assetPrefix . 'includes/inactivity_timeout.js?v=4'); ?>"></script>
<script>
    (function() {
        const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
        if (!token) return;

        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('form').forEach(form => {
                const method = (form.getAttribute('method') || 'GET').toUpperCase();
                if (method === 'GET') return;
                if (form.querySelector('input[name="csrf_token"]')) return;
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'csrf_token';
                input.value = token;
                form.appendChild(input);
            });
        });
    })();
</script>
