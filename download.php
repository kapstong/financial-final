<?php
/**
 * ATIERA Financial Management System - File Download Handler
 * Secure file download with access control
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/file_upload.php';

session_start();

// Check if user is logged in
if (!isset($_SESSION['user'])) {
    header('HTTP/1.1 401 Unauthorized');
    echo 'Unauthorized access';
    exit;
}

if (!isset($_GET['file_id'])) {
    header('HTTP/1.1 400 Bad Request');
    echo 'File ID is required';
    exit;
}

$fileId = (int)$_GET['file_id'];

try {
    $fileManager = FileUploadManager::getInstance();
    $fileManager->downloadFile($fileId);

} catch (Exception $e) {
    error_log("File download error: " . $e->getMessage());
    header('HTTP/1.1 404 Not Found');
    echo 'File not found or access denied';
    exit;
}
?>

