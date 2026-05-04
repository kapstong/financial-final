<?php
try {
    require 'config.php';
    require 'includes/database.php';
    $db = Database::getInstance()->getConnection();
    echo json_encode(['status' => 'connected', 'message' => 'Database connected successfully']);
} catch(Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
}
