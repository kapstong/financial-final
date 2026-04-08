<?php
/**
 * Mail diagnostics for ATIERA.
 *
 * Usage:
 *   php tools/test_mailer.php
 *   php tools/test_mailer.php --production
 *   php tools/test_mailer.php --send=you@example.com
 *   php tools/test_mailer.php --production --send=you@example.com
 */

function cli_set_env_value($name, $value, $override = false) {
    $hasValue = array_key_exists($name, $_SERVER) || array_key_exists($name, $_ENV) || getenv($name) !== false;
    if ($override || !$hasValue) {
        putenv(sprintf('%s=%s', $name, $value));
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }
}

function cli_load_env($path, $override = false) {
    if (!is_file($path)) {
        return false;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0 || strpos($line, '=') === false) {
            continue;
        }

        [$name, $value] = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);
        if ($value !== '' && (($value[0] === '"' && substr($value, -1) === '"') || ($value[0] === "'" && substr($value, -1) === "'"))) {
            $value = substr($value, 1, -1);
        }

        cli_set_env_value($name, $value, $override);
    }

    return true;
}

function mask_value($value) {
    $value = (string) $value;
    if ($value === '') {
        return '[empty]';
    }
    if (strpos($value, '@') !== false) {
        [$name, $domain] = explode('@', $value, 2);
        return substr($name, 0, 2) . str_repeat('*', max(0, strlen($name) - 2)) . '@' . $domain;
    }
    return substr($value, 0, 2) . str_repeat('*', max(0, strlen($value) - 2));
}

function read_smtp_response($fp, $expected) {
    $response = '';
    while ($line = fgets($fp, 515)) {
        $response .= $line;
        if (preg_match('/^\d{3} /', $line)) {
            break;
        }
    }

    $code = (int) substr($response, 0, 3);
    $allowed = is_array($expected) ? $expected : [$expected];
    $ok = in_array($code, $allowed, true);

    return [$ok, trim($response)];
}

function smtp_command($fp, $command, $expected) {
    fwrite($fp, $command . "\r\n");
    return read_smtp_response($fp, $expected);
}

$baseDir = dirname(__DIR__);
$args = $argv;
array_shift($args);
$useProduction = in_array('--production', $args, true);
$sendTo = null;
foreach ($args as $arg) {
    if (strpos($arg, '--send=') === 0) {
        $sendTo = substr($arg, 7);
    }
}

if ($useProduction) {
    cli_set_env_value('APP_ENV', 'production', true);
}

cli_load_env($baseDir . DIRECTORY_SEPARATOR . '.env');

$mailer = getenv('MAIL_MAILER') ?: 'smtp';
$host = trim((string) getenv('MAIL_HOST'));
$port = (int) (getenv('MAIL_PORT') ?: 587);
$username = trim((string) getenv('MAIL_USERNAME'));
$password = preg_replace('/\s+/', '', (string) getenv('MAIL_PASSWORD'));
$encryption = strtolower(trim((string) (getenv('MAIL_ENCRYPTION') ?: 'tls')));
$fromAddress = trim((string) getenv('MAIL_FROM_ADDRESS'));
$fromName = trim((string) (getenv('MAIL_FROM_NAME') ?: 'ATIERA Finance'));

echo 'APP_ENV=' . (getenv('APP_ENV') ?: 'development') . PHP_EOL;
echo 'MAIL_MAILER=' . $mailer . PHP_EOL;
echo 'MAIL_HOST=' . ($host !== '' ? $host : '[empty]') . PHP_EOL;
echo 'MAIL_PORT=' . $port . PHP_EOL;
echo 'MAIL_USERNAME=' . mask_value($username) . PHP_EOL;
echo 'MAIL_PASSWORD=' . mask_value($password) . PHP_EOL;
echo 'MAIL_ENCRYPTION=' . ($encryption !== '' ? $encryption : '[empty]') . PHP_EOL;
echo 'MAIL_FROM_ADDRESS=' . mask_value($fromAddress) . PHP_EOL;
echo 'MAIL_FROM_NAME=' . ($fromName !== '' ? $fromName : '[empty]') . PHP_EOL;

if ($mailer !== 'smtp') {
    fwrite(STDERR, 'MAIL_MAILER is not smtp; this script only validates SMTP settings.' . PHP_EOL);
    exit(1);
}

if ($host === '' || $username === '' || $password === '') {
    fwrite(STDERR, 'SMTP is not fully configured. Check MAIL_HOST, MAIL_USERNAME, and MAIL_PASSWORD.' . PHP_EOL);
    exit(1);
}

$remote = ($encryption === 'ssl') ? "ssl://{$host}" : $host;
$fp = @fsockopen($remote, $port, $errno, $errstr, 10);
if (!$fp) {
    fwrite(STDERR, "CONNECT failed: {$errstr} ({$errno})" . PHP_EOL);
    exit(1);
}
echo "CONNECT ok" . PHP_EOL;

[$ok, $response] = read_smtp_response($fp, 220);
echo "S: {$response}" . PHP_EOL;
if (!$ok) {
    fclose($fp);
    exit(1);
}

$ehloHost = 'localhost';
[$ok, $response] = smtp_command($fp, "EHLO {$ehloHost}", 250);
echo "EHLO: {$response}" . PHP_EOL;
if (!$ok) {
    fclose($fp);
    exit(1);
}

if ($encryption === 'tls') {
    [$ok, $response] = smtp_command($fp, 'STARTTLS', 220);
    echo "STARTTLS: {$response}" . PHP_EOL;
    if (!$ok) {
        fclose($fp);
        exit(1);
    }

    if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
        fwrite(STDERR, 'TLS negotiation failed.' . PHP_EOL);
        fclose($fp);
        exit(1);
    }
    echo "TLS ok" . PHP_EOL;

    [$ok, $response] = smtp_command($fp, "EHLO {$ehloHost}", 250);
    echo "EHLO(TLS): {$response}" . PHP_EOL;
    if (!$ok) {
        fclose($fp);
        exit(1);
    }
}

[$ok, $response] = smtp_command($fp, 'AUTH LOGIN', 334);
echo "AUTH LOGIN: {$response}" . PHP_EOL;
if (!$ok) {
    fclose($fp);
    exit(1);
}

[$ok, $response] = smtp_command($fp, base64_encode($username), 334);
echo "AUTH USER: {$response}" . PHP_EOL;
if (!$ok) {
    fclose($fp);
    exit(1);
}

[$ok, $response] = smtp_command($fp, base64_encode($password), 235);
echo "AUTH PASS: {$response}" . PHP_EOL;
if (!$ok) {
    fclose($fp);
    exit(1);
}

echo "SMTP authentication ok" . PHP_EOL;

if ($sendTo && filter_var($sendTo, FILTER_VALIDATE_EMAIL)) {
    $envelopeFrom = filter_var($username, FILTER_VALIDATE_EMAIL) ? $username : $fromAddress;
    $safeFromAddress = filter_var($fromAddress, FILTER_VALIDATE_EMAIL) ? $fromAddress : $envelopeFrom;
    $subject = 'ATIERA Mail Diagnostic';
    $body = "This is a test email generated by tools/test_mailer.php at " . date('c');
    $headers = [];
    $headers[] = "From: {$fromName} <{$safeFromAddress}>";
    $headers[] = "Reply-To: {$safeFromAddress}";
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: text/plain; charset=UTF-8';
    $payload = 'To: ' . $sendTo . "\r\n" .
        'Subject: ' . $subject . "\r\n" .
        implode("\r\n", $headers) . "\r\n\r\n" .
        $body . "\r\n.";

    [$ok, $response] = smtp_command($fp, "MAIL FROM:<{$envelopeFrom}>", 250);
    echo "MAIL FROM: {$response}" . PHP_EOL;
    if (!$ok) {
        fclose($fp);
        exit(1);
    }

    [$ok, $response] = smtp_command($fp, "RCPT TO:<{$sendTo}>", [250, 251]);
    echo "RCPT TO: {$response}" . PHP_EOL;
    if (!$ok) {
        fclose($fp);
        exit(1);
    }

    [$ok, $response] = smtp_command($fp, 'DATA', 354);
    echo "DATA: {$response}" . PHP_EOL;
    if (!$ok) {
        fclose($fp);
        exit(1);
    }

    [$ok, $response] = smtp_command($fp, $payload, 250);
    echo "SEND: {$response}" . PHP_EOL;
    if (!$ok) {
        fclose($fp);
        exit(1);
    }

    echo "Test email accepted by SMTP server for {$sendTo}" . PHP_EOL;
}

smtp_command($fp, 'QUIT', 221);
fclose($fp);
echo "DONE" . PHP_EOL;
