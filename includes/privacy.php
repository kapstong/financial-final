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
?>
