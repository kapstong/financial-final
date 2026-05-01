<?php
// Ultra-simple test endpoint
@session_start();
header('Content-Type: application/json');

echo json_encode([
    'success' => true,
    'test' => 'API is working',
    'action' => $_GET['action'] ?? 'none',
    'has_session_user' => isset($_SESSION['user']) ? true : false
]);
