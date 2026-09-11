<?php
// SecureLab2v - PHP Frontend Router
require_once __DIR__ . '/config.php';

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri = rtrim($uri, '/') ?: '/';

$routes = [
    '/'                    => 'pages/landing.php',
    '/login'               => 'pages/login.php',
    '/register'            => 'pages/register.php',
    '/logout'              => 'pages/logout.php',
    '/pending'             => 'pages/pending.php',
    '/dashboard'           => 'pages/dashboard.php',
    '/agents'              => 'pages/agents.php',
    '/alerts'              => 'pages/alerts.php',
    '/reports'             => 'pages/reports.php',
    '/databases'           => 'pages/databases.php',
    '/db-logs'             => 'pages/db-logs.php',
    '/hardening'           => 'pages/hardening.php',
    '/tickets'             => 'pages/tickets.php',
    '/arco'                => 'pages/arco.php',
    '/compliance'          => 'pages/compliance.php',
    '/certification'       => 'pages/certification.php',
    '/privacy'             => 'pages/privacy.php',
    '/politica-privacidad' => 'pages/privacy-policy.php',
    '/arco-solicitud'      => 'pages/arco-public.php',
    '/track'               => 'pages/citizen-portal.php',
    '/dpo'                 => 'pages/dpo.php',
    '/admin'               => 'pages/admin.php',
    '/host-monitor'        => 'pages/host-monitor.php',
    '/host-privacy'        => 'pages/host-privacy.php',
    '/settings'            => 'pages/settings.php',
    '/mi-privacidad'       => 'pages/mi-privacidad.php',
];

// /firmar/:token
if (preg_match('#^/firmar/([a-zA-Z0-9_-]+)$#', $uri, $m)) {
    $_GET['token'] = $m[1];
    require __DIR__ . '/pages/sign-invite.php';
    exit;
}

// /admin/user/:userId
if (preg_match('#^/admin/user/([a-zA-Z0-9]+)$#', $uri, $m)) {
    $_GET['userId'] = $m[1];
    require __DIR__ . '/pages/user-monitor.php';
    exit;
}

// /verify/:certId — verificación pública
if (preg_match('#^/verify/([A-Z0-9\-\.]+)$#', $uri, $m)) {
    $_GET['certId'] = $m[1];
    require __DIR__ . '/pages/verify-certificate.php';
    exit;
}

// Compliance RAT export passthrough
if ($uri === '/compliance-export') {
    if (!is_logged_in()) { header('Location: /login'); exit; }
    header('Location: ' . SITE_URL . '/api/compliance/ropa-export?token=' . urlencode($_SESSION['token']));
    exit;
}

// Agent download passthrough
if ($uri === '/download-agent') {
    if (!is_logged_in()) { header('Location: /login'); exit; }
    $platform = $_GET['platform'] ?? 'win-x64';
    header('Location: ' . SITE_URL . '/api/agents/download/' . urlencode($platform) . '?token=' . urlencode($_SESSION['token']));
    exit;
}

if (isset($routes[$uri])) {
    require __DIR__ . '/' . $routes[$uri];
} else {
    http_response_code(404);
    $pageTitle = '404 - Página no encontrada';
    require __DIR__ . '/includes/header.php';
    ?>
    <div class="min-h-screen flex items-center justify-center">
        <div class="text-center">
            <h1 class="text-6xl font-bold text-primary-500 mb-4">404</h1>
            <p class="text-text-muted mb-6">Página no encontrada</p>
            <a href="/" class="btn-primary inline-block">Volver al inicio</a>
        </div>
    </div>
    <?php
    require __DIR__ . '/includes/footer.php';
}