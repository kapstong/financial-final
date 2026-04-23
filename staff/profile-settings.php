<?php
require_once '../includes/auth.php';
require_once '../includes/database.php';
require_once '../includes/device_detector.php';

$debugProfile = isset($_GET['debug']) && $_GET['debug'] === '1';
if ($debugProfile) {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
    register_shutdown_function(function () {
        $err = error_get_last();
        if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            header('Content-Type: text/plain; charset=utf-8');
            echo "Fatal error: {$err['message']}\nFile: {$err['file']}\nLine: {$err['line']}\n";
        }
    });
}

$qrFeatureAvailable = true;
$qrCodesPath = __DIR__ . '/../includes/qr_codes.php';
$mailerPath = __DIR__ . '/../includes/mailer.php';
if (file_exists($qrCodesPath)) {
    require_once $qrCodesPath;
} else {
    $qrFeatureAvailable = false;
}
if (file_exists($mailerPath)) {
    require_once $mailerPath;
} else {
    $qrFeatureAvailable = false;
}

if (!isset($_SESSION['user'])) {
    header('Location: ../index.php');
    exit;
}

// Initialize database connection
$db = Database::getInstance()->getConnection();
$user_id = $_SESSION['user']['id'];

// Fetch current user data
try {
    $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Error fetching user data: " . $e->getMessage());
    $user = $_SESSION['user'];
}

$activityLogs = [];
try {
    $stmt = $db->prepare("
        SELECT action, ip_address, user_agent, new_values, created_at
        FROM audit_log
        WHERE user_id = ? AND action IN ('User Login', 'QR Login')
        ORDER BY created_at DESC
        LIMIT 50
    ");
    $stmt->execute([$user_id]);
    $activityLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $activityLogs = [];
}

function maskEmail($email) {
    if (!$email || !strpos($email, '@')) {
        return '***@***.***';
    }
    $parts = explode('@', $email);
    $name = $parts[0];
    $domain = $parts[1];
    $nameLength = strlen($name);
    $maskedName = substr($name, 0, 1) . str_repeat('*', max(1, $nameLength - 2)) . substr($name, -1);
    return $maskedName . '@' . $domain;
}

$qrAllowed = $qrFeatureAvailable && in_array(strtolower($user['role'] ?? ''), ['admin', 'staff'], true);
$qrToken = null;
$qrRecord = null;
$qrLoginUrl = '';
$qrCodeTtlSeconds = 600;
$qrEmailVerified = false;
$qrMaskedEmail = '';

if ($qrAllowed) {
    try {
        $qrRecord = qr_login_get_active_code($user_id);
        if ($qrRecord) {
            $qrToken = qr_login_decrypt_token($qrRecord['token_cipher'] ?? '', $qrRecord['token_iv'] ?? '');
        }
        if ($qrToken) {
            $qrLoginUrl = rtrim(Config::get('app.url'), '/') . '/qr-login.php?t=' . urlencode($qrToken);
        }

        $qrMaskedEmail = maskEmail($user['email'] ?? '');
        $verifiedUntil = $_SESSION['qr_email_verified_until'] ?? 0;
        if ($verifiedUntil && time() < $verifiedUntil) {
            $qrEmailVerified = true;
        }
    } catch (Throwable $e) {
        error_log('QR login disabled on staff profile settings: ' . $e->getMessage());
        $qrAllowed = false;
        $qrToken = null;
        $qrRecord = null;
        $qrLoginUrl = '';
    }
}

// Handle form submission for profile update
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['qr_send_code'])) {
        if (!$qrAllowed) {
            $message = 'QR fast login is not available for your role.';
            $messageType = 'danger';
        } else {
            try {
                $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                $_SESSION['qr_email_code'] = $code;
                $_SESSION['qr_email_code_time'] = time();
                $_SESSION['qr_email_verified_until'] = 0;

                $email = $user['email'] ?? '';
                if (!$email) {
                    throw new Exception('No email address on file.');
                }

                $mailer = Mailer::getInstance();
                $firstName = $user['first_name'] ?? '';
                $sent = $mailer->sendVerificationCode($email, $code, $firstName);
                if (!$sent) {
                    throw new Exception($mailer->getLastError() ?: 'Unable to send verification email.');
                }

                $qrMaskedEmail = maskEmail($email);
                $message = 'Verification code sent to ' . $qrMaskedEmail . '.';
                $messageType = 'success';
            } catch (Exception $e) {
                $message = 'Failed to send verification code: ' . $e->getMessage();
                $messageType = 'danger';
            }
        }
    }

    if (isset($_POST['qr_verify_code'])) {
        if (!$qrAllowed) {
            $message = 'QR fast login is not available for your role.';
            $messageType = 'danger';
        } else {
            $input = trim($_POST['qr_code'] ?? '');
            $sent = $_SESSION['qr_email_code'] ?? '';
            $sentAt = $_SESSION['qr_email_code_time'] ?? 0;
            if (!$sent || !$sentAt) {
                $message = 'No verification code found. Please send a new code.';
                $messageType = 'danger';
            } elseif ((time() - $sentAt) > $qrCodeTtlSeconds) {
                unset($_SESSION['qr_email_code'], $_SESSION['qr_email_code_time']);
                $message = 'Verification code expired. Please send a new code.';
                $messageType = 'danger';
            } elseif ($input !== $sent) {
                $message = 'Invalid verification code.';
                $messageType = 'danger';
            } else {
                unset($_SESSION['qr_email_code'], $_SESSION['qr_email_code_time']);
                $_SESSION['qr_email_verified_until'] = time() + $qrCodeTtlSeconds;
                $qrEmailVerified = true;
                $message = 'Email verified. You can now generate your QR code.';
                $messageType = 'success';
            }
        }
    }

    if (isset($_POST['qr_action'])) {
        if (!$qrAllowed) {
            $message = 'QR fast login is not available for your role.';
            $messageType = 'danger';
        } else {
            try {
                if (!$qrEmailVerified) {
                    $message = 'Please verify your email before generating the QR code.';
                    $messageType = 'warning';
                    if (empty($_SESSION['qr_email_code'])) {
                        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                        $_SESSION['qr_email_code'] = $code;
                        $_SESSION['qr_email_code_time'] = time();
                        $email = $user['email'] ?? '';
                        if ($email) {
                            $mailer = Mailer::getInstance();
                            $firstName = $user['first_name'] ?? '';
                            $sent = $mailer->sendVerificationCode($email, $code, $firstName);
                            if (!$sent) {
                                throw new Exception($mailer->getLastError() ?: 'Unable to send verification email.');
                            }
                            $qrMaskedEmail = maskEmail($email);
                            $message = 'Verification required. We sent a code to ' . $qrMaskedEmail . '.';
                        }
                    }
                } else {
                    $action = $_POST['qr_action'];
                    $rotate = $action === 'renew';
                    $result = qr_login_generate_code($user_id, $rotate);
                    $qrToken = $result['token'];
                    $qrRecord = qr_login_get_active_code($user_id);
                    if ($qrToken) {
                        $qrLoginUrl = rtrim(Config::get('app.url'), '/') . '/qr-login.php?t=' . urlencode($qrToken);
                    }
                    $message = $rotate ? 'QR code renewed successfully.' : 'QR code generated successfully.';
                    $messageType = 'success';
                }
            } catch (Exception $e) {
                $message = 'Error generating QR code: ' . $e->getMessage();
                $messageType = 'danger';
            }
        }
    }
    if (isset($_POST['update_profile'])) {
        try {
            $first_name = trim($_POST['first_name'] ?? '');
            $last_name = trim($_POST['last_name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $department = trim($_POST['department'] ?? '');

            // Validate email
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('Invalid email address');
            }

            // Update user profile
            $stmt = $db->prepare("
                UPDATE users SET
                    first_name = ?,
                    last_name = ?,
                    email = ?,
                    phone = ?,
                    department = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$first_name, $last_name, $email, $phone, $department, $user_id]);

            // Update session data
            $_SESSION['user']['first_name'] = $first_name;
            $_SESSION['user']['last_name'] = $last_name;
            $_SESSION['user']['email'] = $email;
            $_SESSION['user']['phone'] = $phone;
            $_SESSION['user']['department'] = $department;

            $message = 'Profile updated successfully';
            $messageType = 'success';

            // Refresh user data
            $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

        } catch (Exception $e) {
            $message = 'Error updating profile: ' . $e->getMessage();
            $messageType = 'danger';
        }
    }

    if (isset($_POST['change_password'])) {
        try {
            $current_password = $_POST['current_password'] ?? '';
            $new_password = $_POST['new_password'] ?? '';
            $confirm_password = $_POST['confirm_password'] ?? '';

            if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
                throw new Exception('All password fields are required');
            }

            if ($new_password !== $confirm_password) {
                throw new Exception('New passwords do not match');
            }

            if (strlen($new_password) < 8) {
                throw new Exception('New password must be at least 8 characters long');
            }

            // Verify current password
            $stmt = $db->prepare("SELECT password_hash FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $passwordHash = $stmt->fetchColumn();

            if (!password_verify($current_password, $passwordHash)) {
                throw new Exception('Current password is incorrect');
            }

            // Update password
            $new_password_hash = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $db->prepare("UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$new_password_hash, $user_id]);

            $message = 'Password changed successfully';
            $messageType = 'success';

        } catch (Exception $e) {
            $message = 'Error changing password: ' . $e->getMessage();
            $messageType = 'danger';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile Settings - ATIERA Financial Management</title>
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
            text-decoration: none !important;
        }
        .navbar .btn-link:focus {
            box-shadow: none;
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
        .content {
            margin-left: 120px;
            padding: 20px;
            transition: margin-left 0.3s ease;
            position: relative;
            z-index: 1;
        }
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
        .modal-body {
            padding: 2rem;
        }
        .modal-footer {
            border-top: 1px solid #e9ecef;
            padding: 1.5rem 2rem;
            background: #f8f9fa;
            border-radius: 0 0 12px 12px;
        }
        .qr-card {
            position: relative;
            background: linear-gradient(135deg, #0f1c49 0%, #1b2f73 60%, #d4af37 120%);
            color: #fff;
            border-radius: 16px;
            padding: 1.5rem;
            min-height: 240px;
            overflow: hidden;
            box-shadow: 0 18px 40px rgba(2,6,23,.25);
        }
        .qr-card::after {
            content: '';
            position: absolute;
            inset: 0;
            background: radial-gradient(circle at 85% 15%, rgba(255,255,255,.25), transparent 45%);
            opacity: 0.6;
            pointer-events: none;
        }
        .qr-card-header {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 1rem;
        }
        .qr-card-header img {
            height: 36px;
            width: auto;
            filter: drop-shadow(0 2px 6px rgba(0,0,0,.25));
        }
        .qr-card-title {
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            font-size: 0.85rem;
        }
        .qr-card-body {
            display: flex;
            gap: 1.25rem;
            align-items: center;
            flex-wrap: wrap;
        }
        .qr-code-wrap {
            background: #fff;
            padding: 0.5rem;
            border-radius: 12px;
            box-shadow: inset 0 0 0 2px rgba(255,255,255,.4);
        }
        .qr-meta {
            flex: 1;
            min-width: 180px;
        }
        .qr-meta h4 {
            margin: 0 0 0.25rem;
            font-size: 1.2rem;
            font-weight: 700;
        }
        .qr-meta small {
            display: block;
            opacity: 0.8;
        }
        .qr-actions {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
        }
        .qr-placeholder {
            border: 2px dashed rgba(30,41,54,.3);
            border-radius: 12px;
            padding: 1.5rem;
            text-align: center;
            color: #6c757d;
            background: #f8f9fa;
        }
        @media print {
            @page { margin: 10mm; }
            body * { visibility: hidden; }
            #qrPrintArea, #qrPrintArea * { visibility: visible; }
            #qrPrintArea {
                position: absolute;
                top: 0;
                left: 0;
                right: 0;
                margin: 0 auto;
                max-width: 360px;
                page-break-inside: avoid;
                break-inside: avoid;
                background: #fff !important;
                color: #111 !important;
                border: 1px solid #d0d7de;
                box-shadow: none !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            #qrPrintArea::after { display: none !important; }
            #qrPrintArea .qr-card-header img { filter: none !important; }
            #qrPrintArea .qr-meta h4,
            #qrPrintArea .qr-meta small,
            #qrPrintArea .qr-card-title,
            #qrPrintArea .qr-card-header small {
                color: #111 !important;
            }
            #qrPrintArea .qr-code-wrap {
                background: #fff !important;
                box-shadow: none !important;
                border: 1px solid #d0d7de;
            }
        }
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
        }
    </style>
</head>
<body>
    <?php include '../includes/staff_navigation.php'; ?>

    <div class="content">
        <?php include '../includes/global_navbar.php'; ?>

        <div class="container-fluid">

            <?php if ($message): ?>
                <div class="alert alert-<?= $messageType ?> alert-dismissible fade show" role="alert">
                    <i class="fas fa-<?= $messageType === 'success' ? 'check-circle' : 'exclamation-triangle' ?> me-2"></i>
                    <?= htmlspecialchars($message) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <!-- Navigation Tabs -->
            <ul class="nav nav-tabs mb-4" id="profileTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="profile-tab" data-bs-toggle="tab" data-bs-target="#profile" type="button" role="tab" aria-controls="profile" aria-selected="true">
                        <i class="fas fa-user me-2"></i>Profile Information
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="security-tab" data-bs-toggle="tab" data-bs-target="#security" type="button" role="tab" aria-controls="security" aria-selected="false">
                        <i class="fas fa-shield-alt me-2"></i>Security
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="preferences-tab" data-bs-toggle="tab" data-bs-target="#preferences" type="button" role="tab" aria-controls="preferences" aria-selected="false">
                        <i class="fas fa-cog me-2"></i>Preferences
                    </button>
                </li>

            </ul>

            <!-- Tab Content -->
            <div class="tab-content" id="profileTabContent">
                <!-- Profile Information Tab -->
                <div class="tab-pane fade show active" id="profile" role="tabpanel" aria-labelledby="profile-tab">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="fas fa-user me-2"></i>Personal Information</h5>
                        </div>
                        <div class="card-body">
                            <form method="POST" action="">
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="first_name" class="form-label fw-bold">First Name</label>
                                        <input type="text" class="form-control" id="first_name" name="first_name"
                                               value="<?= htmlspecialchars($user['first_name'] ?? '') ?>" required>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label for="last_name" class="form-label fw-bold">Last Name</label>
                                        <input type="text" class="form-control" id="last_name" name="last_name"
                                               value="<?= htmlspecialchars($user['last_name'] ?? '') ?>" required>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="email" class="form-label fw-bold">Email Address</label>
                                        <input type="email" class="form-control" id="email" name="email"
                                               value="<?= htmlspecialchars($user['email'] ?? '') ?>" required>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label for="phone" class="form-label fw-bold">Phone Number</label>
                                        <input type="tel" class="form-control" id="phone" name="phone"
                                               value="<?= htmlspecialchars($user['phone'] ?? '') ?>">
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="department" class="form-label fw-bold">Department</label>
                                        <input type="text" class="form-control" id="department" name="department"
                                               value="<?= htmlspecialchars($user['department'] ?? 'Finance') ?>" required>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label for="username" class="form-label fw-bold">Username</label>
                                        <input type="text" class="form-control" id="username"
                                               value="<?= htmlspecialchars($user['username'] ?? '') ?>" disabled>
                                        <small class="text-muted">Username cannot be changed</small>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="role" class="form-label fw-bold">Role</label>
                                        <input type="text" class="form-control" id="role"
                                               value="<?= htmlspecialchars($user['role'] ?? '') ?>" disabled>
                                        <small class="text-muted">Role is managed by administrators</small>
                                    </div>
                                </div>
                                <button type="submit" name="update_profile" class="btn btn-primary">
                                    <i class="fas fa-save me-2"></i>Save Changes
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Security Tab -->
                <div class="tab-pane fade" id="security" role="tabpanel" aria-labelledby="security-tab">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="fas fa-shield-alt me-2"></i>Security Settings</h5>
                        </div>
                        <div class="card-body">
                            <form method="POST" action="">
                                <h6 class="text-primary fw-bold mb-3" style="color: #1b2f73 !important;">Change Password</h6>
                                <div class="mb-3">
                                    <label for="current_password" class="form-label fw-bold">Current Password</label>
                                    <input type="password" class="form-control" id="current_password" name="current_password" required>
                                </div>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="new_password" class="form-label fw-bold">New Password</label>
                                        <input type="password" class="form-control" id="new_password" name="new_password" required>
                                        <small class="text-muted">Minimum 8 characters</small>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label for="confirm_password" class="form-label fw-bold">Confirm New Password</label>
                                        <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                                    </div>
                                </div>
                                <button type="submit" name="change_password" class="btn btn-warning">
                                    <i class="fas fa-key me-2"></i>Change Password
                                </button>
                            </form>

                            <hr class="my-4">

                            <h6 class="text-primary fw-bold mb-3" style="color: #1b2f73 !important;">Two-Factor Authentication</h6>
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle me-2"></i>
                                Two-factor authentication adds an extra layer of security to your account.
                            </div>
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="radio" name="twofa" id="totp" value="totp">
                                <label class="form-check-label fw-bold" for="totp">
                                    TOTP (Time-based One-Time Password)
                                </label>
                                <small class="d-block text-muted ms-4">Use an authenticator app like Google Authenticator</small>
                            </div>
                            <div class="form-check mb-3">
                                <input class="form-check-input" type="radio" name="twofa" id="sms" value="sms">
                                <label class="form-check-label fw-bold" for="sms">
                                    SMS (Text Message)
                                </label>
                                <small class="d-block text-muted ms-4">Receive codes via text message</small>
                            </div>
                            <button type="button" class="btn btn-outline-primary">
                                <i class="fas fa-mobile-alt me-2"></i>Enable 2FA
                            </button>

                            <hr class="my-4">

                            <?php if ($qrAllowed): ?>
                            <div class="mb-3">
                                <h6 class="text-primary fw-bold mb-2" style="color: #1b2f73 !important;">Fast QR Login</h6>
                                <p class="text-muted mb-3">Generate a QR code for quick sign-in. You can print it as a card and renew it anytime.</p>
                                <div class="mb-3">
                                    <div class="d-flex flex-column flex-md-row align-items-md-center gap-3">
                                        <form method="POST" action="" class="d-inline">
                                            <?php csrf_input(); ?>
                                            <input type="hidden" name="qr_send_code" value="1">
                                            <button type="submit" class="btn btn-outline-primary">
                                                <i class="fas fa-envelope me-2"></i>Send Email Code
                                            </button>
                                        </form>
                                        <form method="POST" action="" class="d-flex align-items-center gap-2">
                                            <?php csrf_input(); ?>
                                            <input type="hidden" name="qr_verify_code" value="1">
                                            <input type="text" name="qr_code" class="form-control" placeholder="6-digit code" maxlength="6" style="max-width: 180px;">
                                            <button type="submit" class="btn btn-primary">
                                                <i class="fas fa-check me-2"></i>Verify
                                            </button>
                                        </form>
                                    </div>
                                    <small class="text-muted d-block mt-2">
                                        Code expires in 10 minutes. <?php echo $qrMaskedEmail ? 'Sent to ' . htmlspecialchars($qrMaskedEmail) . '.' : ''; ?>
                                    </small>
                                    <?php if ($qrEmailVerified): ?>
                                        <small class="text-success d-block mt-1"><i class="fas fa-shield-check me-1"></i>Email verified.</small>
                                    <?php endif; ?>
                                </div>
                                <div class="row g-4 align-items-start">
                                    <div class="col-lg-5">
                                        <?php if ($qrLoginUrl): ?>
                                            <div class="qr-code-wrap text-center">
                                                <img id="qrImage" alt="Fast Login QR" style="width: 200px; height: 200px;">
                                            </div>
                                            <small class="text-muted d-block mt-2">
                                                Generated: <?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($qrRecord['created_at'] ?? 'now'))); ?>
                                            </small>
                                        <?php else: ?>
                                            <div class="qr-placeholder">
                                                <strong>No QR code yet</strong>
                                                <div>Generate your fast login QR below.</div>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="col-lg-7">
                                        <?php if ($qrLoginUrl): ?>
                                        <div class="qr-card mb-3" id="qrPrintArea">
                                            <div class="qr-card-header">
                                                <img src="../logo.png" alt="ATIERA">
                                                <div>
                                                    <div class="qr-card-title">ATIERA Fast Login</div>
                                                    <small>Authorized access card</small>
                                                </div>
                                            </div>
                                            <div class="qr-card-body">
                                                <div class="qr-code-wrap">
                                                    <img id="qrCardImage" alt="Fast Login QR" style="width: 140px; height: 140px;">
                                                </div>
                                                <div class="qr-meta">
                                                    <h4><?php echo htmlspecialchars($user['full_name'] ?? $user['username']); ?></h4>
                                                    <small>Role: <?php echo htmlspecialchars(ucfirst($user['role'] ?? 'staff')); ?></small>
                                                    <small>Department: <?php echo htmlspecialchars($user['department'] ?? 'Finance'); ?></small>
                                                </div>
                                            </div>
                                        </div>
                                        <?php else: ?>
                                            <div class="qr-placeholder mb-3">Print-ready card will appear once a QR code is generated.</div>
                                        <?php endif; ?>
                                        <div class="qr-actions">
                                            <form method="POST" action="">
                                                <?php csrf_input(); ?>
                                                <input type="hidden" name="qr_action" value="<?php echo $qrLoginUrl ? 'renew' : 'generate'; ?>">
                                                <button type="submit" class="btn btn-primary" <?php echo $qrEmailVerified ? '' : 'disabled'; ?>>
                                                    <i class="fas fa-qrcode me-2"></i><?php echo $qrLoginUrl ? 'Renew QR Code' : 'Generate QR Code'; ?>
                                                </button>
                                            </form>
                                            <button type="button" class="btn btn-outline-primary" id="printQrCardBtn" <?php echo $qrLoginUrl ? '' : 'disabled'; ?>>
                                                <i class="fas fa-print me-2"></i>Print Card
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <hr class="my-4">
                            <?php endif; ?>

                            <div class="d-flex flex-column flex-md-row align-items-start align-items-md-center gap-3">
                                <div>
                                    <h6 class="text-primary fw-bold mb-1" style="color: #1b2f73 !important;">Activity Log</h6>
                                    <small class="text-muted">View recent sign-ins and security-related activity.</small>
                                </div>
                                <button type="button" class="btn btn-outline-secondary ms-md-auto" data-bs-toggle="modal" data-bs-target="#activityLogModal">
                                    <i class="fas fa-history me-2"></i>Activity Log
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Preferences Tab -->
                <div class="tab-pane fade" id="preferences" role="tabpanel" aria-labelledby="preferences-tab">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="fas fa-cog me-2"></i>System Preferences</h5>
                        </div>
                        <div class="card-body">
                            <form method="POST" action="">
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="default_currency" class="form-label fw-bold">Default Currency</label>
                                        <select class="form-select" id="default_currency" name="default_currency">
                                            <option value="PHP" selected>PHP - Philippine Peso (₱)</option>
                                            <option value="USD">USD - US Dollar ($)</option>
                                            <option value="EUR">EUR - Euro (€)</option>
                                        </select>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label for="landing_page" class="form-label fw-bold">Default Landing Page</label>
                                        <select class="form-select" id="landing_page" name="landing_page">
                                            <option value="dashboard" selected>Dashboard</option>
                                            <option value="general_ledger">General Ledger</option>
                                            <option value="accounts_receivable">Accounts Receivable</option>
                                            <option value="accounts_payable">Accounts Payable</option>
                                            <option value="reports">Reports</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="language" class="form-label fw-bold">Language</label>
                                        <select class="form-select" id="language" name="language">
                                            <option value="en" selected>English</option>
                                            <option value="fil">Filipino</option>
                                        </select>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label for="theme" class="form-label fw-bold">Theme</label>
                                        <select class="form-select" id="theme" name="theme">
                                            <option value="light" selected>Light</option>
                                            <option value="dark">Dark</option>
                                            <option value="auto">Auto (System)</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="date_format" class="form-label fw-bold">Date Format</label>
                                        <select class="form-select" id="date_format" name="date_format">
                                            <option value="MM/DD/YYYY" selected>MM/DD/YYYY (US)</option>
                                            <option value="DD/MM/YYYY">DD/MM/YYYY (International)</option>
                                            <option value="YYYY-MM-DD">YYYY-MM-DD (ISO)</option>
                                        </select>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label for="number_format" class="form-label fw-bold">Number Format</label>
                                        <select class="form-select" id="number_format" name="number_format">
                                            <option value="1,234.56" selected>1,234.56 (US)</option>
                                            <option value="1.234,56">1.234,56 (EU)</option>
                                            <option value="1 234.56">1 234.56 (International)</option>
                                        </select>
                                    </div>
                                </div>
                                <button type="submit" name="update_preferences" class="btn btn-primary">
                                    <i class="fas fa-save me-2"></i>Save Changes
                                </button>
                            </form>
                        </div>
                    </div>
                </div>


            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../includes/alert-modal.js"></script>
    <script src="../includes/privacy_mode.js?v=12"></script>

    <div class="modal fade" id="activityLogModal" tabindex="-1" aria-labelledby="activityLogModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="activityLogModalLabel"><i class="fas fa-history me-2"></i>Activity Log</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <?php if (empty($activityLogs)): ?>
                        <div class="alert alert-info mb-0">No activity logs found yet.</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Device</th>
                                        <th>IP Address</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($activityLogs as $log): ?>
                                        <?php
                                            $devicePayload = [];
                                            if (!empty($log['new_values'])) {
                                                $decoded = json_decode($log['new_values'], true);
                                                if (is_array($decoded)) {
                                                    $devicePayload = $decoded;
                                                }
                                            }
                                            $deviceInfo = detect_device_info($log['user_agent'] ?? '');
                                            $deviceLabel = $devicePayload['device_label'] ?? $deviceInfo['device_type'];
                                            $deviceType = $devicePayload['device_type'] ?? $deviceInfo['device_type'];
                                            $deviceOs = $devicePayload['os'] ?? $deviceInfo['os'];
                                            $deviceBrowser = $devicePayload['browser'] ?? $deviceInfo['browser'];
                                            $deviceDetail = trim($deviceType . ' • ' . $deviceOs . ' • ' . $deviceBrowser);
                                        ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($log['created_at']))); ?></td>
                                            <td>
                                                <div class="fw-semibold"><?php echo htmlspecialchars($deviceLabel ?: 'Unknown'); ?></div>
                                                <small class="text-muted"><?php echo htmlspecialchars($deviceDetail ?: 'Unknown'); ?></small>
                                            </td>
                                            <td><?php echo htmlspecialchars($log['ip_address'] ?? ''); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

<script src="https://cdn.jsdelivr.net/npm/qrcode@1.5.4/build/qrcode.min.js"></script>
<script>
    const qrLoginUrl = <?php echo json_encode($qrLoginUrl); ?>;
    const qrImage = document.getElementById('qrImage');
    const qrCardImage = document.getElementById('qrCardImage');
    const printBtn = document.getElementById('printQrCardBtn');

    if (qrLoginUrl && window.QRCode) {
        QRCode.toDataURL(qrLoginUrl, { width: 220, margin: 1, color: { dark: '#1b2f73', light: '#ffffff' } }, (err, url) => {
            if (err) return;
            if (qrImage) qrImage.src = url;
            if (qrCardImage) qrCardImage.src = url;
        });
    } else {
        if (qrImage) qrImage.style.display = 'none';
        if (qrCardImage) qrCardImage.style.display = 'none';
    }

    if (printBtn) {
        printBtn.addEventListener('click', () => {
            const printWindow = window.open('../qr-card-print.php', '_blank', 'noopener,noreferrer');
            if (!printWindow) {
                alert('Popup blocked. Please allow popups for this site to print your QR card.');
            }
        });
    }
</script>
<script src="../includes/tab_persistence.js?v=1"></script>
</body>
</html>

