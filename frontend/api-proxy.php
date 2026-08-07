<?php
// SecureLab2v - API proxy: forwards browser AJAX calls to the backend with the session token
require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['error' => 'no autenticado']);
    exit;
}

$path = $_GET['path'] ?? '';
if (!$path || !str_starts_with($path, '/api/') || str_contains($path, '..')) {
    http_response_code(400);
    echo json_encode(['error' => 'ruta inválida']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Merge incoming body with the session token
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
$body['token'] = $_SESSION['token'];

// Forward query string params (except path)
$query = $_GET;
unset($query['path']);
$url = API_BASE_URL . $path;
if (!empty($query)) {
    $url .= (str_contains($path, '?') ? '&' : '?') . http_build_query($query);
}

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 60,
    CURLOPT_CUSTOMREQUEST => $method,
    CURLOPT_POSTFIELDS => http_build_query($body),
    CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE) ?: 502;
curl_close($ch);

http_response_code($httpCode);
echo $response !== false ? $response : json_encode(['error' => 'backend no disponible']);
