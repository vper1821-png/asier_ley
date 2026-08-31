<?php
// SecureLab2v - PHP Frontend Configuration
session_start();

// Evitar caché del navegador en desarrollo
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// API Base URL: backend local interno para llamadas PHP cURL rápidas
define('API_BASE_URL', getenv('API_BASE_URL') ?: 'http://localhost:3838');
define('API_BASE_URL_BROWSER', getenv('API_BASE_URL_BROWSER') ?: 'http://localhost:3838');
define('SITE_URL', getenv('SITE_URL') ?: 'http://localhost:8080');
define('SITE_NAME', 'SecureLab');
define('SITE_SUBTITLE', 'Cumplimiento ley 21.719');
define('TURNSTILE_SITE_KEY', getenv('TURNSTILE_SITE_KEY') ?: '');

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function api_request($method, $path, $data = null) {
    $url = API_BASE_URL . $path;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
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
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
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
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
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
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true);
}

function is_logged_in() {
    return !empty($_SESSION['token']) && !empty($_SESSION['user']);
}

function require_login() {
    if (is_logged_in()) {
        return;
    }

    $loginOverlay = '<div style="position:fixed;inset:0;background:#0b0b0f;display:flex;align-items:center;justify-content:center;z-index:99999;font-family:Inter,system-ui,sans-serif;"><div style="background:#15151a;border:1px solid rgba(255,255,255,0.06);border-radius:16px;padding:32px;max-width:360px;width:90%;text-align:center;box-shadow:0 25px 50px rgba(0,0,0,0.5);"><h1 style="font-size:20px;font-weight:700;color:#f9fafb;margin:0 0 8px 0;">Sesion expirada</h1><p style="color:#9ca3af;font-size:14px;margin:0 0 24px 0;line-height:1.5;">Tu sesion ha finalizado. Inicia sesion de nuevo para continuar.</p><a href="/login" style="display:inline-flex;align-items:center;justify-content:center;padding:12px 24px;border-radius:12px;background:#2563eb;color:#fff;text-decoration:none;font-size:14px;font-weight:600;transition:background 0.2s;">Iniciar sesion</a></div></div>';

    if (!headers_sent()) {
        header('Content-Type: text/html; charset=UTF-8');
        header('HTTP/1.1 401 Unauthorized');
        echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Sesion requerida - SecureLab</title></head><body style="margin:0;background:#0b0b0f;">' . $loginOverlay . '</body></html>';
    } else {
        echo $loginOverlay;
    }
    exit;
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
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true);
}