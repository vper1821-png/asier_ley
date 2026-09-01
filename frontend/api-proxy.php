<?php
// SecureLab2v - API proxy: forwards browser AJAX calls to the backend with the session token
require_once __DIR__ . '/config.php';

$path = $_GET['path'] ?? '';
if (!$path || !str_starts_with($path, '/api/') || str_contains($path, '..')) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'ruta inválida']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Parse body first so we can extract token from it
$raw = file_get_contents('php://input');
$body = [];
if ($raw !== '') {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $body = $decoded;
    } else {
        parse_str($raw, $body);
    }
}

// Debug log para el problema de guardado de compliance
if (str_contains($path, '/api/invisia/compliance/checklist') || str_contains($path, '/api/invisia/compliance/')) {
    $logBody = $body;
    unset($logBody['token']);
    file_put_contents('/tmp/api-proxy-compliance.log', date('c') . ' ' . $method . ' ' . $path . ' body=' . json_encode($logBody, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
}

// Extract token from multiple sources: session, GET, body, Authorization header
$sessionToken = $_SESSION['token'] ?? $_GET['token'] ?? $body['token'] ?? '';
if (!$sessionToken) {
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (str_starts_with($auth, 'Bearer ')) {
        $sessionToken = substr($auth, 7);
    }
}

if (!$sessionToken && !is_logged_in()) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'no autenticado']);
    exit;
}

if ($sessionToken) {
    $body['token'] = $sessionToken;
}

// Forward query string params (except path)
$query = $_GET;
unset($query['path']);
if ($sessionToken) {
    $query['token'] = $sessionToken;
}

// Backend URL from server-side config (overridable via API_BASE_URL env var)
$backendBase = API_BASE_URL;
$url = $backendBase . $path;
$url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);

$headersToSend = [
    'Accept: */*',
];
if (!empty($_SERVER['HTTP_HOST'])) {
    $headersToSend[] = 'Host: ' . $_SERVER['HTTP_HOST'];
}
if ($sessionToken) {
    $headersToSend[] = 'Authorization: Bearer ' . $sessionToken;
}

// ── Streaming directo para descargas de archivos (NSIS, binarios, reportes) ──
if (str_contains($path, 'download') || isset($_GET['installer'])) {
    @set_time_limit(180);
    if (function_exists('apache_setenv')) @apache_setenv('no-gzip', '1');
    @ini_set('zlib.output_compression', 'Off');

    $ch = curl_init($url);
    if ($method === 'HEAD') {
        curl_setopt($ch, CURLOPT_NOBODY, true);
    }
    curl_setopt_array($ch, [
        CURLOPT_TIMEOUT => 180,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_ENCODING => '',
        CURLOPT_HEADERFUNCTION => function($curl, $header) {
            $len = strlen($header);
            $trimmed = trim($header);
            if (!empty($trimmed)) {
                $lower = strtolower($trimmed);
                if (str_starts_with($lower, 'content-type:') ||
                    str_starts_with($lower, 'content-disposition:') ||
                    str_starts_with($lower, 'cache-control:') ||
                    str_starts_with($lower, 'pragma:') ||
                    str_starts_with($lower, 'expires:')) {
                    header($trimmed, true);
                }
            }
            return $len;
        },
        CURLOPT_WRITEFUNCTION => function($curl, $data) {
            echo $data;
            if (ob_get_level() > 0) @ob_flush();
            @flush();
            return strlen($data);
        }
    ]);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headersToSend);
    curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    exit;
}

// ── Solicitudes estándar JSON / API ──
$ch = curl_init($url);
if ($method === 'HEAD') {
    curl_setopt($ch, CURLOPT_NOBODY, true);
}
$responseHeaders = [];

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 60,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_CUSTOMREQUEST => $method,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => 0,
    CURLOPT_HEADERFUNCTION => function($curl, $header) use (&$responseHeaders) {
        $len = strlen($header);
        $parts = explode(':', $header, 2);
        if (count($parts) === 2) {
            $name = strtolower(trim($parts[0]));
            $val = trim($parts[1]);
            $responseHeaders[$name] = $val;
        }
        return $len;
    }
]);

if ($method !== 'GET') {
    $headersToSend[] = 'Content-Type: application/x-www-form-urlencoded';
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($body));
}

curl_setopt($ch, CURLOPT_HTTPHEADER, $headersToSend);

$response = curl_exec($ch);
$curlErr = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($response === false || $httpCode === 0) {
    http_response_code(502);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'backend no disponible: ' . $curlErr]);
    exit;
}

http_response_code($httpCode);

if (!empty($responseHeaders['content-type'])) {
    header('Content-Type: ' . $responseHeaders['content-type']);
} else {
    header('Content-Type: application/json; charset=utf-8');
}

echo $response;
