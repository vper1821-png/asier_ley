<?php
// SecureLab2v - PHP Frontend Configuration
session_start();

// Evitar caché del navegador en desarrollo
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// API Base URL: backend local interno para llamadas PHP cURL rápidas
define('API_BASE_URL', getenv('API_BASE_URL') ?: 'https://leysecurelab.sytes.net');
define('API_BASE_URL_BROWSER', getenv('API_BASE_URL_BROWSER') ?: 'https://leysecurelab.sytes.net');
define('SITE_URL', getenv('SITE_URL') ?: 'https://leysecurelab.sytes.net');
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

    $loginOverlay = <<<'HTML'
<div style="position:fixed;inset:0;z-index:99999;display:flex;align-items:center;justify-content:center;padding:24px;font-family:Inter,system-ui,-apple-system,sans-serif;background:#08090f;">
    <div style="position:absolute;top:0;left:50%;transform:translateX(-50%);width:600px;height:300px;background:radial-gradient(ellipse,rgba(59,130,246,.10),transparent 65%);pointer-events:none;"></div>
    <div style="position:relative;width:100%;max-width:420px;background:#0e1017;border:1px solid rgba(255,255,255,.07);border-radius:20px;box-shadow:0 40px 90px rgba(0,0,0,.55);overflow:hidden;">
        <div style="padding:40px 40px 0;text-align:center;">
            <img src="/logo-nuevo.png" alt="SecureLab" style="height:88px;width:auto;margin:0 auto;display:block;" onerror="this.style.display='none'">
        </div>
        <div style="height:1px;margin:28px 40px 0;background:linear-gradient(90deg,transparent,rgba(255,255,255,.10),transparent);"></div>
        <div style="padding:28px 40px 36px;text-align:center;">
            <h1 style="font-size:18px;font-weight:600;color:#f3f4f6;margin:0 0 10px;letter-spacing:-.01em;">Sesión finalizada</h1>
            <p style="color:#8b93a5;font-size:13px;margin:0;line-height:1.7;">Tu sesión ha expirado por motivos de seguridad.<br>Vuelve a iniciar sesión para continuar.</p>
            <a href="/login" style="display:flex;align-items:center;justify-content:center;gap:8px;margin-top:28px;padding:13px 24px;border-radius:11px;background:#2f5fe0;color:#fff;text-decoration:none;font-size:13.5px;font-weight:600;letter-spacing:.01em;transition:background .15s ease;" onmouseover="this.style.background='#3b6bf0'" onmouseout="this.style.background='#2f5fe0'">
                <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"/></svg>
                Iniciar sesión
            </a>
        </div>
        <div style="padding:14px 40px;border-top:1px solid rgba(255,255,255,.05);background:rgba(255,255,255,.015);text-align:center;">
            <p style="margin:0;font-size:9.5px;color:#565f72;letter-spacing:.14em;text-transform:uppercase;">SecureLab · Cumplimiento Ley 21.719</p>
        </div>
    </div>
</div>
HTML;

    if (!headers_sent()) {
        header('Content-Type: text/html; charset=UTF-8');
        header('HTTP/1.1 401 Unauthorized');
        echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Sesión requerida - SecureLab</title></head><body style="margin:0;">' . $loginOverlay . '</body></html>';
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