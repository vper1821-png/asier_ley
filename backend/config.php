<?php
// SecureLab2v - PHP Backend Configuration

define('PORT', getenv('PORT') ?: '3838');
define('MONGODB_URI', getenv('MONGODB_URI') ?: 'mongodb://localhost:27017/invisia');
define('JWT_SECRET', getenv('JWT_SECRET') ?: 'cambia-este-secreto-por-uno-fuerte-y-largo');
define('ADMIN_EMAIL', getenv('ADMIN_EMAIL') ?: 'admin@invisia.local');
define('ADMIN_PASSWORD', getenv('ADMIN_PASSWORD') ?: 'cambia-esta-contraseña-segura');
define('CORS_ORIGIN', getenv('CORS_ORIGIN') ?: 'http://localhost:5173');
define('OLLAMA_HOST', getenv('OLLAMA_HOST') ?: 'http://localhost:11434');
define('AI_MODEL', getenv('AI_MODEL') ?: 'mistral');
define('TURNSTILE_SECRET_KEY', getenv('TURNSTILE_SECRET_KEY') ?: '');
define('API_BASE_URL', getenv('API_BASE_URL') ?: '');

// CORS headers
header('Access-Control-Allow-Origin: ' . CORS_ORIGIN);
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function json_response($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function json_error($message, $code = 400) {
    json_response(['error' => $message], $code);
}

function get_body() {
    static $cached = null;
    if ($cached !== null) return $cached;

    $raw = file_get_contents('php://input');
    $body = json_decode($raw, true);
    if ($body === null) {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (str_contains($contentType, 'application/x-www-form-urlencoded')) {
            parse_str($raw, $body);
        } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST)) {
            $body = $_POST;
        }
    }
    $cached = is_array($body) ? $body : [];
    return $cached;
}

function get_token() {
    $body = get_body();
    $token = $body['token'] ?? $_GET['token'] ?? '';

    if (!$token) {
        $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (str_starts_with($auth, 'Bearer ')) {
            $token = substr($auth, 7);
        }
    }
    return $token;
}
