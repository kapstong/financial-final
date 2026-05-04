<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function privacyModeEnabled()
{
    if (!array_key_exists('privacy_mode_enabled', $_SESSION)) {
        $_SESSION['privacy_mode_enabled'] = true;
    }

    return (bool) $_SESSION['privacy_mode_enabled'];
}

function privacyIsVisible()
{
    return !privacyModeEnabled();
}

function privacySetMode($enabled)
{
    $_SESSION['privacy_mode_enabled'] = (bool) $enabled;
}

function privacyMaskText($value)
{
    return preg_replace('/[\d,.]/', '*', (string) $value);
}

function privacyMoneyHtml($formattedValue)
{
    $formattedValue = (string) $formattedValue;
    $visible = privacyIsVisible();
    $displayValue = $visible ? $formattedValue : privacyMaskText($formattedValue);

    return '<span class="privacy-money" data-privacy-money="1">' .
        htmlspecialchars($displayValue, ENT_QUOTES, 'UTF-8') .
        '</span>';
}

function privacyRestrictedOutputMessage()
{
    return 'Privacy mode is ON. Disable privacy mode with OTP verification before exporting, downloading, printing, or generating reports.';
}

function privacyRequestIsRestrictedOutput()
{
    if (!privacyModeEnabled()) {
        return false;
    }

    $scriptPath = strtolower(str_replace('\\', '/', (string) ($_SERVER['PHP_SELF'] ?? '')));
    $fileBase = strtolower(pathinfo($scriptPath, PATHINFO_FILENAME));
    $queryAction = strtolower((string) ($_GET['action'] ?? ($_POST['action'] ?? '')));
    $format = strtolower((string) ($_GET['format'] ?? ($_POST['format'] ?? '')));
    $requestUri = strtolower((string) ($_SERVER['REQUEST_URI'] ?? ''));

    if ($fileBase === 'privacy') {
        return false;
    }

    if (in_array($fileBase, ['pdf', 'download'], true)) {
        return true;
    }

    if ($fileBase === 'reports') {
        return strpos($scriptPath, '/api/') !== false;
    }

    if ($fileBase === 'audit' && $queryAction === 'export') {
        return true;
    }

    if ($fileBase === 'backups' && $queryAction === 'download') {
        return true;
    }

    if (in_array($format, ['csv', 'pdf', 'excel', 'xlsx', 'xls'], true)) {
        return true;
    }

    return strpos($requestUri, 'action=export') !== false
        || strpos($requestUri, 'action=download') !== false
        || strpos($requestUri, 'export=') !== false
        || strpos($requestUri, '/download.php') !== false;
}

function privacyBlockRestrictedOutputIfNeeded()
{
    if (!privacyRequestIsRestrictedOutput()) {
        return;
    }

    $message = privacyRestrictedOutputMessage();
    $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
    $isApi = strpos(strtolower(str_replace('\\', '/', (string) ($_SERVER['PHP_SELF'] ?? ''))), '/api/') !== false;
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

    http_response_code(403);
    if ($isApi || $isAjax || strpos($accept, 'application/json') !== false) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => $message, 'privacy_mode' => true]);
    } else {
        header('Content-Type: text/plain; charset=UTF-8');
        echo $message;
    }
    exit;
}
?>
