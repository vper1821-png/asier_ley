<?php
// Hardening / WAF check routes

function checkWaf() {
    $domain = $_GET['domain'] ?? '';
    $domain = preg_replace('#^https?://#', '', $domain);
    $domain = preg_replace('#/.*$#', '', $domain);
    $domain = trim($domain);

    if (!$domain) {
        json_response(['waf' => false, 'provider' => null, 'error' => 'no domain']);
    }

    $ch = curl_init("https://$domain");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_USERAGENT => 'SecureLab-WAF-Checker/1.0',
        CURLOPT_SSL_VERIFYPEER => false,
    ]);

    $response = curl_exec($ch);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $headers = substr($response, 0, $headerSize);
    $provider = null;

    if (stripos($headers, 'cf-ray:') !== false) $provider = 'Cloudflare';
    elseif (stripos($headers, 'x-sucuri-id:') !== false) $provider = 'Sucuri CloudProxy';
    elseif (stripos($headers, 'x-iinfo:') !== false) $provider = 'Imperva / Incapsula';
    elseif (stripos($headers, 'server: cloudflare') !== false) $provider = 'Cloudflare';
    elseif (stripos($headers, 'x-akamai-') !== false) $provider = 'Akamai';

    json_response([
        'waf' => $provider !== null,
        'provider' => $provider,
        'status' => $statusCode,
    ]);
}

// Called directly from index.php
if (basename($_SERVER['SCRIPT_FILENAME']) === 'hardening.php') {
    checkWaf();
}
