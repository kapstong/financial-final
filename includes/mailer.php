<?php
require_once __DIR__ . '/../config.php';

function atiera_send_email($to, $subject, $htmlBody, $textBody = '')
{
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Invalid recipient email address.');
    }

    $mailer = strtolower((string) Config::get('mail.mailer'));
    if ($mailer === 'mail') {
        return atiera_send_php_mail($to, $subject, $htmlBody, $textBody);
    }

    return atiera_send_smtp_mail($to, $subject, $htmlBody, $textBody);
}

function atiera_send_php_mail($to, $subject, $htmlBody, $textBody = '')
{
    $fromAddress = Config::get('mail.from_address');
    $fromName = Config::get('mail.from_name');
    $boundary = 'atiera_' . bin2hex(random_bytes(12));
    $headers = [
        'MIME-Version: 1.0',
        'From: ' . atiera_mailbox($fromAddress, $fromName),
        'Content-Type: multipart/alternative; boundary="' . $boundary . '"'
    ];

    $body = "--{$boundary}\r\n";
    $body .= "Content-Type: text/plain; charset=UTF-8\r\n\r\n";
    $body .= ($textBody ?: strip_tags($htmlBody)) . "\r\n";
    $body .= "--{$boundary}\r\n";
    $body .= "Content-Type: text/html; charset=UTF-8\r\n\r\n";
    $body .= $htmlBody . "\r\n";
    $body .= "--{$boundary}--";

    if (!mail($to, $subject, $body, implode("\r\n", $headers))) {
        throw new RuntimeException('PHP mail() failed.');
    }

    return true;
}

function atiera_send_smtp_mail($to, $subject, $htmlBody, $textBody = '')
{
    $host = (string) Config::get('mail.host');
    $port = (int) Config::get('mail.port');
    $username = (string) Config::get('mail.username');
    $password = (string) Config::get('mail.password');
    $encryption = strtolower((string) Config::get('mail.encryption'));
    $fromAddress = (string) Config::get('mail.from_address');
    $fromName = (string) Config::get('mail.from_name');

    if ($host === '' || $port <= 0) {
        throw new RuntimeException('SMTP host and port are required.');
    }

    $transport = $encryption === 'ssl' ? 'ssl://' . $host : $host;
    $socket = fsockopen($transport, $port, $errno, $errstr, 15);
    if (!$socket) {
        throw new RuntimeException("SMTP connection failed: {$errstr}");
    }

    stream_set_timeout($socket, 15);

    try {
        atiera_smtp_expect($socket, [220]);
        atiera_smtp_command($socket, 'EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost'), [250]);

        if ($encryption === 'tls') {
            atiera_smtp_command($socket, 'STARTTLS', [220]);
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('Unable to enable SMTP TLS.');
            }
            atiera_smtp_command($socket, 'EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost'), [250]);
        }

        if ($username !== '') {
            atiera_smtp_command($socket, 'AUTH LOGIN', [334]);
            atiera_smtp_command($socket, base64_encode($username), [334]);
            atiera_smtp_command($socket, base64_encode($password), [235]);
        }

        atiera_smtp_command($socket, 'MAIL FROM:<' . $fromAddress . '>', [250]);
        atiera_smtp_command($socket, 'RCPT TO:<' . $to . '>', [250, 251]);
        atiera_smtp_command($socket, 'DATA', [354]);
        fwrite($socket, atiera_build_message($to, $subject, $htmlBody, $textBody, $fromAddress, $fromName) . "\r\n.\r\n");
        atiera_smtp_expect($socket, [250]);
        atiera_smtp_command($socket, 'QUIT', [221]);
    } finally {
        fclose($socket);
    }

    return true;
}

function atiera_smtp_command($socket, $command, array $expectedCodes)
{
    fwrite($socket, $command . "\r\n");
    return atiera_smtp_expect($socket, $expectedCodes);
}

function atiera_smtp_expect($socket, array $expectedCodes)
{
    $response = '';
    while (($line = fgets($socket, 515)) !== false) {
        $response .= $line;
        if (isset($line[3]) && $line[3] === ' ') {
            break;
        }
    }

    $code = (int) substr($response, 0, 3);
    if (!in_array($code, $expectedCodes, true)) {
        throw new RuntimeException('SMTP error: ' . trim($response));
    }

    return $response;
}

function atiera_build_message($to, $subject, $htmlBody, $textBody, $fromAddress, $fromName)
{
    $boundary = 'atiera_' . bin2hex(random_bytes(12));
    $headers = [
        'From: ' . atiera_mailbox($fromAddress, $fromName),
        'To: <' . $to . '>',
        'Subject: ' . atiera_header_encode($subject),
        'MIME-Version: 1.0',
        'Content-Type: multipart/alternative; boundary="' . $boundary . '"'
    ];

    $message = implode("\r\n", $headers) . "\r\n\r\n";
    $message .= "--{$boundary}\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n";
    $message .= ($textBody ?: strip_tags($htmlBody)) . "\r\n";
    $message .= "--{$boundary}\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n";
    $message .= $htmlBody . "\r\n";
    $message .= "--{$boundary}--";

    return str_replace(["\r\n.", "\n."], ["\r\n..", "\n.."], $message);
}

function atiera_mailbox($email, $name)
{
    $email = trim((string) $email);
    $name = trim((string) $name);

    if ($name === '') {
        return '<' . $email . '>';
    }

    return atiera_header_encode($name) . ' <' . $email . '>';
}

function atiera_header_encode($value)
{
    return '=?UTF-8?B?' . base64_encode((string) $value) . '?=';
}
?>
