<?php
/**
 * Production bootstrap overrides.
 *
 * This file intentionally avoids embedding secrets. Production credentials and
 * service keys must come from process environment variables or an untracked
 * env file on the server.
 */

putenv('APP_ENV=production');
$_ENV['APP_ENV'] = 'production';
$_SERVER['APP_ENV'] = 'production';

if (getenv('APP_URL') === false || trim((string) getenv('APP_URL')) === '') {
    putenv('APP_URL=https://financial.atierahotelandrestaurant.com');
    $_ENV['APP_URL'] = 'https://financial.atierahotelandrestaurant.com';
    $_SERVER['APP_URL'] = 'https://financial.atierahotelandrestaurant.com';
}

// Force secure cookie handling on the production host before sessions start.
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.gc_maxlifetime', getenv('SESSION_LIFETIME') ?: 7200);
    ini_set('session.cookie_lifetime', getenv('SESSION_LIFETIME') ?: 7200);
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_only_cookies', 1);
    ini_set('session.cookie_secure', 1);
    ini_set('session.cookie_samesite', 'Lax');
}
?>
