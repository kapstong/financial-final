<?php
/**
 * ATIERA Financial Management System - Backup API
 * Handles backup operations and downloads
 */

require_once '../includes/auth.php';
require_once '../includes/backup.php';

header('Content-Type: application/json');
session_start();
$auth = new Auth();
ensure_api_auth($method, [
    'GET' => 'settings.edit',
    'PUT' => 'settings.edit',
    'DELETE' => 'settings.edit',
    'POST' => 'settings.edit',
    'PATCH' => 'settings.edit',
]);


// Check if user is logged in
if (!isset($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$userId = $_SESSION['user']['id'];
$method = $_SERVER['REQUEST_METHOD'];

$auth = new Auth();
$backupManager = BackupManager::getInstance();

// Check if user has permission to manage backups
if (!$auth->hasPermission('settings.edit')) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit;
}

try {
    switch ($method) {
        case 'GET':
            $action = $_GET['action'] ?? '';

            switch ($action) {
                case 'list':
                    $type = $_GET['type'] ?? null;
                    $backups = $backupManager->getBackups($type);
                    echo json_encode(['success' => true, 'backups' => $backups]);
                    break;

                case 'stats':
                    $stats = $backupManager->getBackupStats();
                    echo json_encode(['success' => true, 'stats' => $stats]);
                    break;

                case 'download':
                    if (!isset($_GET['id'])) {
                        http_response_code(400);
                        echo json_encode(['error' => 'Backup ID required']);
                        exit;
                    }

                    // Get backup info
                    $stmt = $db->prepare("SELECT * FROM backups WHERE id = ?");
                    $stmt->execute([$_GET['id']]);
                    $backup = $stmt->fetch(PDO::FETCH_ASSOC);

                    if (!$backup || !file_exists($backup['file_path'])) {
                        http_response_code(404);
                        echo json_encode(['error' => 'Backup not found']);
                        exit;
                    }

                    // Set headers for download
                    header('Content-Type: application/octet-stream');
                    header('Content-Disposition: attachment; filename="' . basename($backup['file_path']) . '"');
                    header('Content-Length: ' . filesize($backup['file_path']));

                    // Output file
                    readfile($backup['file_path']);
                    exit;

                case 'verify':
                    if (!isset($_GET['id'])) {
                        http_response_code(400);
                        echo json_encode(['error' => 'Backup ID required']);
                        exit;
                    }

                    $result = $backupManager->verifyBackup($_GET['id']);
                    echo json_encode($result);
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
                case 'create_database':
                    $result = $backupManager->createDatabaseBackup($_POST['name'] ?? null);
                    if ($result['success']) {
                        Logger::getInstance()->logUserAction(
                            'Created database backup via API',
                            'backups',
                            null,
                            null,
                            ['backup_name' => $result['name']]
                        );
                    }
                    echo json_encode($result);
                    break;

                case 'create_filesystem':
                    $result = $backupManager->createFilesystemBackup($_POST['name'] ?? null);
                    if ($result['success']) {
                        Logger::getInstance()->logUserAction(
                            'Created filesystem backup via API',
                            'backups',
                            null,
                            null,
                            ['backup_name' => $result['name']]
                        );
                    }
                    echo json_encode($result);
                    break;

                case 'create_full':
                    $result = $backupManager->createFullBackup($_POST['name'] ?? null);
                    if ($result['success']) {
                        Logger::getInstance()->logUserAction(
                            'Created full backup via API',
                            'backups',
                            null,
                            null,
                            ['backup_name' => $result['name']]
                        );
                    }
                    echo json_encode($result);
                    break;

                case 'restore':
                    if (!isset($_POST['id'])) {
                        http_response_code(400);
                        echo json_encode(['error' => 'Backup ID required']);
                        exit;
                    }

                    // Get backup info
                    $stmt = $db->prepare("SELECT * FROM backups WHERE id = ? AND type = 'database'");
                    $stmt->execute([$_POST['id']]);
                    $backup = $stmt->fetch(PDO::FETCH_ASSOC);

                    if (!$backup) {
                        http_response_code(404);
                        echo json_encode(['error' => 'Database backup not found']);
                        exit;
                    }

                    $result = $backupManager->restoreDatabase($backup['file_path']);
                    if ($result['success']) {
                        Logger::getInstance()->logUserAction(
                            'Restored database from backup via API',
                            'backups',
                            $_POST['id'],
                            null,
                            ['backup_file' => basename($backup['file_path'])]
                        );
                    }
                    echo json_encode($result);
                    break;

                case 'run_scheduled':
                    $result = $backupManager->runScheduledBackups();
                    if ($result['success']) {
                        Logger::getInstance()->logUserAction(
                            'Ran scheduled backups via API',
                            'backup_schedules',
                            null,
                            null,
                            ['results' => $result['results']]
                        );
                    }
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
                case 'delete':
                    if (!isset($_GET['id'])) {
                        http_response_code(400);
                        echo json_encode(['error' => 'Backup ID required']);
                        exit;
                    }

                    $result = $backupManager->deleteBackup($_GET['id']);
                    if ($result['success']) {
                        Logger::getInstance()->logUserAction(
                            'Deleted backup via API',
                            'backups',
                            $_GET['id'],
                            null,
                            null
                        );
                    }
                    echo json_encode($result);
                    break;

                case 'delete_schedule':
                    if (!isset($_GET['id'])) {
                        http_response_code(400);
                        echo json_encode(['error' => 'Schedule ID required']);
                        exit;
                    }

                    // Delete backup schedule
                    $stmt = $db->prepare("DELETE FROM backup_schedules WHERE id = ?");
                    $success = $stmt->execute([$_GET['id']]);

                    if ($success) {
                        Logger::getInstance()->logUserAction(
                            'Deleted backup schedule via API',
                            'backup_schedules',
                            $_GET['id'],
                            null,
                            null
                        );
                        echo json_encode(['success' => true, 'message' => 'Schedule deleted successfully']);
                    } else {
                        echo json_encode(['success' => false, 'error' => 'Failed to delete schedule']);
                    }
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
    Logger::getInstance()->logDatabaseError('Backup API operation', $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Internal server error']);
}
?>


