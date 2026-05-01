<?php
/**
 * Shared helpers for system-wide maintenance mode.
 */

function maintenanceModeConfigPath(): string
{
    return dirname(__DIR__) . '/config/maintenance_mode.json';
}

function getMaintenanceModeState(): array
{
    $default = [
        'enabled' => false,
        'message' => 'The system is temporarily unavailable while scheduled maintenance is in progress.',
        'updated_at' => null,
        'updated_by' => null
    ];

    $path = maintenanceModeConfigPath();
    if (!is_file($path)) {
        return $default;
    }

    $raw = @file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        return $default;
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return $default;
    }

    return array_merge($default, $decoded);
}

function setMaintenanceModeState(bool $enabled, string $message = '', ?int $updatedBy = null): array
{
    $state = [
        'enabled' => $enabled,
        'message' => trim($message) !== '' ? trim($message) : 'The system is temporarily unavailable while scheduled maintenance is in progress.',
        'updated_at' => date('Y-m-d H:i:s'),
        'updated_by' => $updatedBy
    ];

    $path = maintenanceModeConfigPath();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    file_put_contents($path, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);

    return $state;
}

function maintenanceModeIsEnabled(): bool
{
    $state = getMaintenanceModeState();
    return !empty($state['enabled']);
}

function maintenanceModeCurrentMessage(): string
{
    $state = getMaintenanceModeState();
    return (string)($state['message'] ?? '');
}

function maintenanceModeUserBypassed(): bool
{
    $role = strtolower((string)($_SESSION['user']['role_name'] ?? ($_SESSION['user']['role'] ?? '')));
    return in_array($role, ['super_admin', 'superadmin'], true);
}

function renderMaintenanceModePage(string $message): string
{
    $safeMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');

    return '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Maintenance Mode</title>
    <style>
        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #f4f6fb 0%, #e8edf5 100%);
            color: #1e2936;
        }
        .maintenance-card {
            max-width: 640px;
            margin: 2rem;
            padding: 2rem;
            background: #ffffff;
            border-radius: 18px;
            box-shadow: 0 20px 60px rgba(30, 41, 54, 0.12);
            text-align: center;
        }
        .maintenance-icon {
            width: 72px;
            height: 72px;
            margin: 0 auto 1rem;
            border-radius: 50%;
            background: #1e2936;
            color: #ffffff;
            display: grid;
            place-items: center;
            font-size: 2rem;
        }
        h1 {
            margin: 0 0 0.75rem;
            font-size: 1.9rem;
        }
        p {
            margin: 0;
            line-height: 1.65;
            color: #52606d;
        }
    </style>
</head>
<body>
    <div class="maintenance-card">
        <div class="maintenance-icon">!</div>
        <h1>Maintenance In Progress</h1>
        <p>' . $safeMessage . '</p>
    </div>
</body>
</html>';
}

function enforceMaintenanceModeForRequest(): void
{
    if (!maintenanceModeIsEnabled() || maintenanceModeUserBypassed()) {
        return;
    }

    $scriptPath = strtolower(str_replace('\\', '/', (string)($_SERVER['PHP_SELF'] ?? '')));
    $isApiRequest = strpos($scriptPath, '/api/') !== false;
    $allowedDuringMaintenance = [
        '/logout.php',
        '/superadmin/settings.php',
        '/maintenance.php'
    ];

    foreach ($allowedDuringMaintenance as $allowedPath) {
        if (strpos($scriptPath, $allowedPath) !== false) {
            return;
        }
    }

    $message = maintenanceModeCurrentMessage();

    if ($isApiRequest) {
        http_response_code(503);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => 'System maintenance mode is active.',
            'message' => $message
        ]);
        exit;
    }

    http_response_code(503);
    echo renderMaintenanceModePage($message);
    exit;
}

