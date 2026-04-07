<?php
/**
 * Generate an SSO token without embedding secrets in source control.
 *
 * Usage:
 *   php tools/generate_sso_token.php --email=user@example.com --secret=shared-secret
 * Optional:
 *   --dept=FIN1 --role=super_admin --exp=1712505600 --post-url=https://example.com/superadmin/sso-login.php
 */

$options = getopt('', [
    'email:',
    'dept::',
    'role::',
    'exp::',
    'secret:',
    'post-url::'
]);

$email = trim((string) ($options['email'] ?? getenv('SSO_EMAIL') ?: ''));
$dept = trim((string) ($options['dept'] ?? getenv('SSO_DEPT') ?: 'FIN1'));
$role = trim((string) ($options['role'] ?? getenv('SSO_ROLE') ?: 'super_admin'));
$exp = $options['exp'] ?? getenv('SSO_EXP');
$secret = trim((string) ($options['secret'] ?? getenv('SSO_SHARED_SECRET') ?: ''));
$postUrl = trim((string) ($options['post-url'] ?? getenv('SSO_POST_URL') ?: ''));

if ($email === '' || $secret === '') {
    fwrite(STDERR, "Usage: php tools/generate_sso_token.php --email=user@example.com --secret=shared-secret [--dept=FIN1] [--role=super_admin] [--exp=unix-timestamp] [--post-url=https://host/superadmin/sso-login.php]\n");
    exit(1);
}

$payload = [
    'email' => $email,
    'dept' => $dept,
    'role' => $role
];

if ($exp !== false && $exp !== null && $exp !== '') {
    $payload['exp'] = (int) $exp;
}

$payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES);
$signature = hash_hmac('sha256', $payloadJson, $secret);
$token = base64_encode(json_encode([
    'payload' => $payload,
    'signature' => $signature
], JSON_UNESCAPED_SLASHES));

fwrite(STDOUT, "TOKEN:\n" . $token . PHP_EOL);

if ($postUrl !== '') {
    $escapedUrl = htmlspecialchars($postUrl, ENT_QUOTES, 'UTF-8');
    $escapedToken = htmlspecialchars($token, ENT_QUOTES, 'UTF-8');
    fwrite(STDOUT, PHP_EOL . "POST FORM:\n");
    fwrite(STDOUT, "<form method=\"POST\" action=\"{$escapedUrl}\">\n");
    fwrite(STDOUT, "  <input type=\"hidden\" name=\"token\" value=\"{$escapedToken}\">\n");
    fwrite(STDOUT, "  <button type=\"submit\">Continue SSO Login</button>\n");
    fwrite(STDOUT, "</form>\n");
}
?>
