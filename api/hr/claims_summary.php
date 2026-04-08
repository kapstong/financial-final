<?php
require_once __DIR__ . '/../_bootstrap.php';

$method = $_SERVER['REQUEST_METHOD'];
$configFile = __DIR__ . '/../../config/integrations/hr3.json';
$defaultClaimsSummaryUrl = 'https://hr3.atierahotelandrestaurant.com/claims/claimsSummary.php';
$config = ['claims_summary_url' => $defaultClaimsSummaryUrl];

if (file_exists($configFile)) {
    $configData = json_decode(file_get_contents($configFile), true);
    if (is_array($configData)) {
        $config = array_merge($config, $configData);
    }
}

function resolveHr3ClaimsSummaryUrl(array $config, $defaultUrl) {
    $configuredSummaryUrl = trim((string) ($config['claims_summary_url'] ?? ''));
    if ($configuredSummaryUrl !== '') {
        return $configuredSummaryUrl;
    }

    $configuredApiUrl = trim((string) ($config['api_url'] ?? ''));
    if ($configuredApiUrl === '') {
        return $defaultUrl;
    }

    if (stripos($configuredApiUrl, 'claimsSummary.php') !== false) {
        return $configuredApiUrl;
    }

    $derivedUrl = preg_replace(
        '#/api/claimsapi\.php(?:\?.*)?$#i',
        '/claims/claimsSummary.php',
        str_replace('\\', '/', $configuredApiUrl)
    );

    if (is_string($derivedUrl) && stripos($derivedUrl, 'claimsSummary.php') !== false) {
        return $derivedUrl;
    }

    return $defaultUrl;
}

$apiUrl = resolveHr3ClaimsSummaryUrl($config, $defaultClaimsSummaryUrl);

$queryParams = $_GET;
unset($queryParams['api_key']);

if (!empty($queryParams)) {
    $separator = strpos($apiUrl, '?') === false ? '?' : '&';
    $apiUrl .= $separator . http_build_query($queryParams);
}

$ch = curl_init($apiUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

$headers = ['Content-Type: application/json'];
if (!empty($config['api_key'])) {
    $headers[] = 'Authorization: Bearer ' . $config['api_key'];
}

if ($method !== 'GET') {
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    $body = file_get_contents('php://input');
    if (!empty($body)) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
}

curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError) {
    api_send(['success' => false, 'error' => 'Connection error: ' . $curlError], 502);
}

if ($httpCode < 200 || $httpCode >= 300) {
    api_send(['success' => false, 'error' => 'Upstream returned HTTP ' . $httpCode], 502);
}

$data = json_decode($response, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    api_send(['success' => false, 'error' => 'Invalid JSON from upstream'], 502);
}

api_send([
    'success' => true,
    'endpoint' => '/api/hr/claims_summary',
    'source' => $apiUrl,
    'data' => $data
]);
