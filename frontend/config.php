<?php
// SecureLab2v - PHP Frontend Configuration
session_start();

define('API_BASE_URL', getenv('API_BASE_URL') ?: 'http://backend-service:3838');
define('SITE_URL', getenv('SITE_URL') ?: 'https://leysecurelab.sytes.net');
define('SITE_NAME', 'SecureLab');
define('SITE_SUBTITLE', 'Cumplimiento ley 21.719');

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function api_request($method, $path, $data = null) {
    $url = API_BASE_URL . $path;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($data) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['body' => json_decode($response, true), 'status' => $httpCode];
}

function api_post_form($path, $data = []) {
    $url = API_BASE_URL . $path;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);

    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true);
}

function api_get($path, $params = []) {
    if (!empty($params)) {
        $path .= (strpos($path, '?') === false ? '?' : '&') . http_build_query($params);
    }
    $ch = curl_init(API_BASE_URL . $path);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true);
}

function api_delete($path, $params = []) {
    if (!empty($params)) {
        $path .= (strpos($path, '?') === false ? '?' : '&') . http_build_query($params);
    }
    $ch = curl_init(API_BASE_URL . $path);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true);
}

function is_logged_in() {
    return !empty($_SESSION['token']) && !empty($_SESSION['user']);
}

function require_login() {
    if (!is_logged_in()) {
        header('Location: /login');
        exit;
    }
}

function require_admin() {
    require_login();
    $role = $_SESSION['user']['role'] ?? '';
    $isAdmin = !empty($_SESSION['user']['isAdmin']);
    if (!$isAdmin && !in_array($role, ['admin', 'superadmin'])) {
        header('Location: /dashboard');
        exit;
    }
}

function is_active() {
    return is_logged_in() && !empty($_SESSION['user']['isActive']);
}

function h($str) {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}
function api_put($path, $data = []) {
    $data['token'] = $_SESSION['token'] ?? '';
    $url = API_BASE_URL . $path;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true);
}