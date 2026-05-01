<?php
// Absolute minimal - no dependencies, pure JSON API
header('Content-Type: application/json; charset=utf-8');
@session_start();

// Auth check
if (!isset($_SESSION['user']['id'])) {
    http_response_code(401);
    die(json_encode(['success' => false, 'error' => 'Not logged in']));
}

$userId = (int)$_SESSION['user']['id'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    // Get config directly
    $configFile = dirname(__DIR__) . '/config.php';
    if (!file_exists($configFile)) {
        throw new Exception('Config not found');
    }
    
    // Extract config without executing anything else
    $config = [];
    $configContent = file_get_contents($configFile);
    
    // Parse database config from config.php
    if (preg_match("/['\"]host['\"]\s*=>\s*['\"]([^'\"]+)/", $configContent, $m)) {
        $dbHost = $m[1];
    }
    if (preg_match("/['\"]name['\"]\s*=>\s*['\"]([^'\"]+)/", $configContent, $m)) {
        $dbName = $m[1];
    }
    if (preg_match("/['\"]user['\"]\s*=>\s*['\"]([^'\"]+)/", $configContent, $m)) {
        $dbUser = $m[1];
    }
    if (preg_match("/['\"]pass['\"]\s*=>\s*['\"]([^'\"]+)/", $configContent, $m)) {
        $dbPass = $m[1];
    }
    
    if (empty($dbHost) || empty($dbName) || empty($dbUser)) {
        throw new Exception('Database config not found');
    }
    
    // Connect directly
    $db = new PDO(
        "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",
        $dbUser,
        $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    
    // Handle send action
    if ($action === 'send') {
        // Get user email
        $stmt = $db->prepare("SELECT id, email FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user || empty($user['email'])) {
            http_response_code(400);
            die(json_encode(['success' => false, 'error' => 'User email not found']));
        }
        
        // Generate 6-digit code
        $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        
        // Store in database
        $insert = $db->prepare("
            INSERT INTO email_codes (user_id, email, code, expires_at, created_at)
            VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 2 MINUTE), NOW())
            ON DUPLICATE KEY UPDATE code = ?, expires_at = DATE_ADD(NOW(), INTERVAL 2 MINUTE), created_at = NOW()
        ");
        $insert->execute([$userId, $user['email'], $code, $code]);
        
        // Try to send email (best effort - don't fail if mail fails)
        @mail(
            $user['email'],
            'Your Verification Code',
            "Your verification code is: $code\n\nThis code expires in 2 minutes.",
            "From: noreply@financialmanagement.local\r\nContent-Type: text/plain; charset=utf-8"
        );
        
        die(json_encode(['success' => true, 'message' => 'Code sent to your email']));
    }
    
    // Handle verify action
    else if ($action === 'verify') {
        $code = trim($_POST['code'] ?? '');
        
        if (!preg_match('/^\d{6}$/', $code)) {
            http_response_code(400);
            die(json_encode(['success' => false, 'error' => 'Invalid code format']));
        }
        
        // Check code in database
        $stmt = $db->prepare("
            SELECT id FROM email_codes 
            WHERE user_id = ? AND code = ? AND expires_at > NOW()
            LIMIT 1
        ");
        $stmt->execute([$userId, $code]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($row) {
            // Mark as used and unlock session
            $db->prepare("DELETE FROM email_codes WHERE user_id = ? AND code = ?")->execute([$userId, $code]);
            
            $_SESSION['privacy_unlocked'] = true;
            $_SESSION['privacy_visible'] = true;
            
            die(json_encode(['success' => true, 'message' => 'Code verified']));
        } else {
            http_response_code(400);
            die(json_encode(['success' => false, 'error' => 'Invalid or expired code']));
        }
    }
    
    else {
        http_response_code(400);
        die(json_encode(['success' => false, 'error' => 'No action specified']));
    }
    
} catch (Exception $e) {
    http_response_code(500);
    die(json_encode(['success' => false, 'error' => $e->getMessage()]));
}
?>
