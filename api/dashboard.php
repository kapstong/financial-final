<?php
/**
 * ATIERA Financial Management System - Dashboard API
 * Handles dashboard customization and widget operations
 */

require_once '../includes/auth.php';
require_once '../includes/dashboard.php';

header('Content-Type: application/json');
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$method = $_SERVER['REQUEST_METHOD'];


// Check if user is logged in
if (!isset($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$userId = $_SESSION['user']['id'];
$auth = new Auth();
ensure_api_auth($method, [
    'GET' => 'dashboard.view',
    'PUT' => 'settings.edit',
    'DELETE' => 'settings.edit',
    'POST' => 'settings.edit',
    'PATCH' => 'settings.edit',
]);

$dashboardManager = DashboardManager::getInstance();

try {
    switch ($method) {
        case 'GET':
            $action = $_GET['action'] ?? '';

            switch ($action) {
                case 'get_user_layout':
                    // Get user's dashboard layout
                    $targetUserId = $_GET['user_id'] ?? $userId;

                    // Check permissions (users can only view their own, admins can view any)
                    if ($targetUserId != $userId && !$auth->hasPermission('admin.view')) {
                        http_response_code(403);
                        echo json_encode(['error' => 'Access denied']);
                        exit;
                    }

                    $layout = $dashboardManager->getUserDashboard($targetUserId);
                    echo json_encode(['success' => true, 'layout' => $layout]);
                    break;

                case 'get_default_layout':
                    // Get default dashboard layout
                    $layout = $dashboardManager->getDefaultLayout($userId);
                    $availableWidgets = $dashboardManager->getAvailableWidgets($userId);
                    echo json_encode([
                        'success' => true,
                        'layout' => $layout,
                        'available_widgets' => $availableWidgets
                    ]);
                    break;

                case 'get_available_widgets':
                    // Get available widgets for user
                    $widgets = $dashboardManager->getAvailableWidgets($userId);
                    echo json_encode(['success' => true, 'widgets' => $widgets]);
                    break;

                case 'get_widget_data':
                    // Get data for a specific widget
                    $widgetId = $_GET['widget_id'] ?? '';
                    $config = isset($_GET['config']) ? json_decode($_GET['config'], true) : [];

                    $data = $dashboardManager->getWidgetData($widgetId, $config);
                    echo json_encode(['success' => true, 'data' => $data]);
                    break;

                case 'get_widget_config':
                    // Get widget configuration form
                    $widgetId = $_GET['widget_id'] ?? '';
                    $availableWidgets = $dashboardManager->getAvailableWidgetsList();

                    if (!isset($availableWidgets[$widgetId])) {
                        http_response_code(404);
                        echo json_encode(['error' => 'Widget not found']);
                        exit;
                    }

                    $widget = $availableWidgets[$widgetId];
                    $configForm = generateWidgetConfigForm($widgetId, $widget);
                    echo json_encode(['success' => true, 'config_form' => $configForm]);
                    break;

                case 'get_dashboard_stats':
                    // Get dashboard statistics
                    $stats = $dashboardManager->getDashboardStats();
                    echo json_encode(['success' => true, 'stats' => $stats]);
                    break;

                default:
                    http_response_code(400);
                    echo json_encode(['error' => 'Invalid action']);
                    exit;
            }
            break;

        case 'POST':
            $action = $_POST['action'] ?? '';

            switch ($action) {
                case 'save_layout':
                    // Save user's dashboard layout
                    $layout = isset($_POST['layout']) ? json_decode($_POST['layout'], true) : null;

                    if (!$layout) {
                        http_response_code(400);
                        echo json_encode(['error' => 'Invalid layout data']);
                        exit;
                    }

                    $result = $dashboardManager->saveUserDashboard($userId, $layout);
                    echo json_encode($result);
                    break;

                case 'reset_layout':
                    // Reset user's dashboard to default
                    $result = $dashboardManager->resetUserDashboard($userId);
                    echo json_encode($result);
                    break;

                case 'update_widget_config':
                    // Update widget configuration (admin only)
                    if (!$auth->hasPermission('settings.edit')) {
                        http_response_code(403);
                        echo json_encode(['error' => 'Access denied']);
                        exit;
                    }

                    $widgetId = $_POST['widget_id'] ?? '';
                    $config = $_POST['config'] ?? [];

                    // This would update global widget configuration
                    // For now, just return success
                    echo json_encode(['success' => true, 'message' => 'Widget configuration updated']);
                    break;

                default:
                    http_response_code(400);
                    echo json_encode(['error' => 'Invalid action']);
                    exit;
            }
            break;

        case 'PUT':
            // Update dashboard settings
            parse_str(file_get_contents('php://input'), $putData);
            $action = $putData['action'] ?? '';

            switch ($action) {
                case 'update_settings':
                    $settings = isset($putData['settings']) ? json_decode($putData['settings'], true) : null;

                    if (!$settings) {
                        http_response_code(400);
                        echo json_encode(['error' => 'Invalid settings data']);
                        exit;
                    }

                    // Get current layout and update settings
                    $currentLayout = $dashboardManager->getUserDashboard($userId);
                    $currentLayout['settings'] = array_merge($currentLayout['settings'] ?? [], $settings);

                    $result = $dashboardManager->saveUserDashboard($userId, $currentLayout);
                    echo json_encode($result);
                    break;

                default:
                    http_response_code(400);
                    echo json_encode(['error' => 'Invalid action']);
                    exit;
            }
            break;

        case 'DELETE':
            $action = $_GET['action'] ?? '';

            switch ($action) {
                case 'remove_widget':
                    // Remove a widget from user's dashboard
                    $widgetId = $_GET['widget_id'] ?? '';

                    $currentLayout = $dashboardManager->getUserDashboard($userId);
                    $currentLayout['widgets'] = array_filter($currentLayout['widgets'], function($widget) use ($widgetId) {
                        return $widget['id'] !== $widgetId;
                    });

                    $result = $dashboardManager->saveUserDashboard($userId, $currentLayout);
                    echo json_encode($result);
                    break;

                case 'reset_dashboard':
                    // Reset dashboard to default (admin action)
                    if (!$auth->hasPermission('settings.edit')) {
                        http_response_code(403);
                        echo json_encode(['error' => 'Access denied']);
                        exit;
                    }

                    $targetUserId = $_GET['user_id'] ?? $userId;
                    $result = $dashboardManager->resetUserDashboard($targetUserId);
                    echo json_encode($result);
                    break;

                default:
                    http_response_code(400);
                    echo json_encode(['error' => 'Invalid action']);
                    exit;
            }
            break;

        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            break;
    }
} catch (Exception $e) {
    Logger::getInstance()->logDatabaseError('Dashboard API operation', $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Internal server error']);
}

/**
 * Generate widget configuration form
 */
function generateWidgetConfigForm($widgetId, $widget) {
    $html = '<div class="mb-3">';
    $html .= '<h6>' . htmlspecialchars($widget['name']) . '</h6>';
    $html .= '<p class="text-muted small">' . htmlspecialchars($widget['description']) . '</p>';
    $html .= '</div>';

    $html .= '<div class="row g-3">';

    // Generate form fields based on widget configuration
    switch ($widgetId) {
        case 'financial_summary':
            $html .= generateSelectField('config[period]', 'Period', [
                'week' => 'This Week',
                'month' => 'This Month',
                'quarter' => 'This Quarter',
                'year' => 'This Year'
            ], $widget['default_config']['period'] ?? 'month');
            $html .= generateCheckboxField('config[show_chart]', 'Show Chart', $widget['default_config']['show_chart'] ?? true);
            break;

        case 'accounts_receivable':
            $html .= generateNumberField('config[limit]', 'Max Items', 5, 50, $widget['default_config']['limit'] ?? 10);
            $html .= generateCheckboxField('config[show_overdue]', 'Show Overdue Only', $widget['default_config']['show_overdue'] ?? true);
            break;

        case 'accounts_payable':
            $html .= generateNumberField('config[limit]', 'Max Items', 5, 50, $widget['default_config']['limit'] ?? 10);
            $html .= generateCheckboxField('config[show_due_soon]', 'Show Due Soon', $widget['default_config']['show_due_soon'] ?? true);
            break;

        case 'task_summary':
            $html .= generateCheckboxField('config[show_assigned_to_me]', 'Show Only My Tasks', $widget['default_config']['show_assigned_to_me'] ?? true);
            break;

        case 'revenue_chart':
            $html .= generateSelectField('config[chart_type]', 'Chart Type', [
                'line' => 'Line Chart',
                'bar' => 'Bar Chart',
                'area' => 'Area Chart'
            ], $widget['default_config']['chart_type'] ?? 'line');
            $html .= generateSelectField('config[period]', 'Period', [
                '3months' => '3 Months',
                '6months' => '6 Months',
                '1year' => '1 Year'
            ], $widget['default_config']['period'] ?? '6months');
            break;

        case 'notifications':
            $html .= generateNumberField('config[limit]', 'Max Notifications', 1, 20, $widget['default_config']['limit'] ?? 5);
            $html .= generateCheckboxField('config[show_unread_only]', 'Show Unread Only', $widget['default_config']['show_unread_only'] ?? true);
            break;

        case 'quick_actions':
            $html .= generateCheckboxField('config[show_create_invoice]', 'Show Create Invoice', $widget['default_config']['show_create_invoice'] ?? true);
            $html .= generateCheckboxField('config[show_create_bill]', 'Show Create Bill', $widget['default_config']['show_create_bill'] ?? true);
            break;

        case 'bookmarks':
            $html .= generateNumberField('config[max_bookmarks]', 'Max Bookmarks', 5, 20, $widget['default_config']['max_bookmarks'] ?? 8);
            break;

        default:
            $html .= '<p class="text-muted">No configuration options available for this widget.</p>';
    }

    $html .= '</div>';
    return $html;
}

/**
 * Generate form field helpers
 */
function generateSelectField($name, $label, $options, $selected = '') {
    $html = '<div class="col-md-6">';
    $html .= '<label class="form-label">' . htmlspecialchars($label) . '</label>';
    $html .= '<select class="form-control" name="' . $name . '">';

    foreach ($options as $value => $text) {
        $isSelected = ($value === $selected) ? 'selected' : '';
        $html .= '<option value="' . $value . '" ' . $isSelected . '>' . htmlspecialchars($text) . '</option>';
    }

    $html .= '</select></div>';
    return $html;
}

function generateNumberField($name, $label, $min, $max, $value = '') {
    $html = '<div class="col-md-6">';
    $html .= '<label class="form-label">' . htmlspecialchars($label) . '</label>';
    $html .= '<input type="number" class="form-control" name="' . $name . '" min="' . $min . '" max="' . $max . '" value="' . htmlspecialchars($value) . '">';
    $html .= '</div>';
    return $html;
}

function generateCheckboxField($name, $label, $checked = false) {
    $html = '<div class="col-md-6">';
    $html .= '<div class="form-check">';
    $html .= '<input class="form-check-input" type="checkbox" name="' . $name . '" value="1" ' . ($checked ? 'checked' : '') . '>';
    $html .= '<label class="form-check-label">' . htmlspecialchars($label) . '</label>';
    $html .= '</div></div>';
    return $html;
}
?>


