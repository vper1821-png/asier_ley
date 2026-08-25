<?php
// SecureLab2v - PHP Backend Router
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Auth.php';

// ── Security Headers ──
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Content-Security-Policy: default-src \'self\'; script-src \'self\' \'unsafe-inline\' \'unsafe-eval\' https://cdn.tailwindcss.com https://fonts.googleapis.com; style-src \'self\' \'unsafe-inline\' https://fonts.googleapis.com https://fonts.gstatic.com; font-src \'self\' https://fonts.gstatic.com; img-src \'self\' data: https:; connect-src \'self\'; frame-ancestors \'none\';');

// ── Rate Limiting (simple in-memory, per IP) ──
$rateLimitKey = 'ratelimit_' . md5($_SERVER['REMOTE_ADDR'] ?? 'unknown');
$rateLimitWindow = 60; // 1 minute
$rateLimitMax = 100;   // 100 requests per minute
if (!isset($_SESSION)) @session_start();
if (!isset($_SESSION[$rateLimitKey])) {
    $_SESSION[$rateLimitKey] = ['count' => 0, 'window' => time()];
}
if (time() - $_SESSION[$rateLimitKey]['window'] > $rateLimitWindow) {
    $_SESSION[$rateLimitKey] = ['count' => 0, 'window' => time()];
}
$_SESSION[$rateLimitKey]['count']++;
if ($_SESSION[$rateLimitKey]['count'] > $rateLimitMax) {
    header('HTTP/1.1 429 Too Many Requests');
    header('Retry-After: ' . ($rateLimitWindow - (time() - $_SESSION[$rateLimitKey]['window'])));
    json_error('demasiadas solicitudes', 429);
}

// ── JWT Secret Rotation Helper ──
function getJwtSecret() {
    $base = JWT_SECRET ?? 'default_secret_change_me';
    $rotation = intdiv(time(), 86400 * 30); // rotate every 30 days
    return hash_hmac('sha256', $base . $rotation, 'rotation_salt');
}

// Override Auth::createToken to use rotated secret
$originalCreateToken = ['Auth', 'createToken'];
if (is_callable($originalCreateToken)) {
    // We'll handle this in Auth.php directly
}

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

// Health check
if ($uri === '/health') {
    json_response(['status' => 'ok', 'uptime' => time() - ($_SERVER['REQUEST_TIME'] ?? time())]);
}

// WAF check
if ($uri === '/api/hardening/check-waf') {
    require __DIR__ . '/routes/hardening.php';
    exit;
}

// Route matching
$routes = [
    // Auth (React uses /api/login, /api/register; PHP uses /api/auth/*)
    'POST /api/auth/login'     => 'routes/auth.php@login',
    'POST /api/login'          => 'routes/auth.php@login',
    'POST /api/auth/register'  => 'routes/auth.php@register',
    'POST /api/auth/verify'    => 'routes/auth.php@verify',
    'POST /api/auth/forgot-password' => 'routes/auth.php@forgotPassword',
    'POST /api/auth/reset-password'  => 'routes/auth.php@resetPassword',
    'POST /api/changePassword' => 'routes/account.php@changePassword',
    'POST /api/purge-ai'       => 'routes/admin.php@purgeAi',

    // 2FA
    'POST /api/2fa/setup'           => 'routes/2fa.php@setup',
    'POST /api/2fa/verify'          => 'routes/2fa.php@verify',
    'POST /api/2fa/disable'         => 'routes/2fa.php@disable2FA',
    'POST /api/2fa/complete-login'  => 'routes/2fa.php@completeLogin',

    // Dashboard
    'POST /api/dashboard/status' => 'routes/dashboard.php@status',
    'GET /api/dashboard/stats' => 'routes/dashboard.php@stats',
    'POST /api/dashboard/stats' => 'routes/dashboard.php@stats',

    // Agents
    'POST /api/agents'              => 'routes/agents.php@listAll',
    'POST /api/agents/list'         => 'routes/agents.php@listAll',
    'POST /api/agents/combined'     => 'routes/agents.php@combined',
    'POST /api/agents/register'     => 'routes/agents.php@register',
    'POST /api/agents/auto-register' => 'routes/agents.php@autoRegister',
    'POST /api/agents/lockdown'     => 'routes/agents.php@setLockdown',
    'POST /api/agents/request-data' => 'routes/agents.php@requestData',
    'POST /api/agents/{id}/command' => 'routes/agents.php@sendCommand',
    'GET /api/agents/download-binary' => 'routes/agents.php@downloadBinary',
    'POST /api/register'            => 'routes/auth.php@register',
    'GET /api/agents/download/'     => 'routes/agents.php@download',
    'POST /api/agents/download/'    => 'routes/agents.php@download',
    'POST /api/agents/download-token' => 'routes/agents.php@downloadToken',
    'POST /api/agents/sensitive-inventory' => 'routes/agents.php@sensitiveInventory',
    'GET /api/agents/{id}' => 'routes/agents.php@getAgent',
    'POST /api/agents/{id}/logs' => 'routes/agents.php@getAgentLogs',
    'POST /api/agents/{id}/db-connections' => 'routes/agents.php@dbConnectionList',
    'POST /api/agents/{id}/db-connection' => 'routes/agents.php@dbConnectionCreate',
    'POST /api/agents/{id}/db-connection/delete' => 'routes/agents.php@dbConnectionDelete',
    'POST /api/agents/{id}/db-connection/test' => 'routes/agents.php@dbConnectionTest',

    // Alerts
    'POST /api/alerts' => 'routes/alerts.php@listAll',
    'POST /api/alerts/list' => 'routes/alerts.php@listAll',
    'POST /api/alerts/stats' => 'routes/alerts.php@stats',
    'POST /api/alerts/resolve' => 'routes/alerts.php@resolve',
    'POST /api/alerts/dismiss' => 'routes/alerts.php@dismiss',
    'POST /api/alerts/resolve-bulk' => 'routes/alerts.php@resolveBulk',
    'POST /api/alerts/delete-all' => 'routes/alerts.php@deleteAll',
    'POST /api/alerts/read' => 'routes/alerts.php@markRead',
    'POST /api/alerts/export' => 'routes/alerts.php@exportCsv',

    // Databases
    'POST /api/databases' => 'routes/databases.php@listAll',
    'POST /api/databases/list' => 'routes/databases.php@listAll',
    'POST /api/databases/connect' => 'routes/databases.php@connect',
    'POST /api/databases/local-connect' => 'routes/databases.php@localConnect',
    'POST /api/databases/logs/list' => 'routes/databases.php@logList',
    'POST /api/databases/logs/stats' => 'routes/databases.php@logStats',
    'POST /api/databases/logs/skip-query' => 'routes/databases.php@skipQuery',
    'POST /api/databases/logs/skipped' => 'routes/databases.php@skippedQueries',
    'POST /api/databases/logs/skipped-queries' => 'routes/databases.php@skippedQueries',
    'POST /api/databases/logs/revoke-skip' => 'routes/databases.php@revokeSkip',
    'POST /api/databases/logs/delete-by-query' => 'routes/databases.php@deleteByQuery',
    'GET /api/databases/logs/skipped-queries' => 'routes/databases.php@skippedQueries',

    // Notifications
    'POST /api/notifications' => 'routes/notifications.php@listAll',
    'POST /api/notifications/list' => 'routes/notifications.php@listAll',
    'POST /api/notifications/unread-count' => 'routes/notifications.php@unreadCount',
    'POST /api/notifications/create' => 'routes/notifications.php@create',
    'POST /api/notifications/read-all' => 'routes/notifications.php@markAllRead',
    'POST /api/notifications/clear-all' => 'routes/notifications.php@clearAll',

    // Tickets
    'GET /api/tickets' => 'routes/tickets.php@listAll',
    'POST /api/tickets' => 'routes/tickets.php@create',
    'POST /api/tickets/all' => 'routes/tickets.php@all',
    'POST /api/tickets/create' => 'routes/tickets.php@create',
    'POST /api/tickets/respond' => 'routes/tickets.php@respond',
    'POST /api/tickets/status' => 'routes/tickets.php@status',
    'POST /api/tickets/close' => 'routes/tickets.php@close',

    // Reports
    'POST /api/reports' => 'routes/reports.php@listAll',
    'POST /api/reports/list' => 'routes/reports.php@listAll',
    'POST /api/reports/generate' => 'routes/reports.php@generate',
    'POST /api/reports/training' => 'routes/reports.php@training',

    // Host Monitor
    'POST /api/host-monitor' => 'routes/hostMonitor.php@listAll',
    'POST /api/host-monitor/events' => 'routes/hostMonitor.php@events',
    'POST /api/host-monitor/stats' => 'routes/hostMonitor.php@eventsStats',

    // Host Privacy Control Panel - Ley 21.719
    'POST /api/host-privacy/summary' => 'routes/hostPrivacy.php@summary',
    'POST /api/host-privacy/arco' => 'routes/hostPrivacy.php@arcoCreate',
    'POST /api/host-privacy/breach' => 'routes/hostPrivacy.php@breachReport',
    'POST /api/user-monitor' => 'routes/userMonitor.php@listAll',

    // Payments
    'POST /api/payments' => 'routes/payments.php@myInfo',
    'POST /api/payments/my-info' => 'routes/payments.php@myInfo',
    'POST /api/payments/users' => 'routes/payments.php@users',
    'POST /api/payments/pending' => 'routes/payments.php@pending',
    'POST /api/payments/record' => 'routes/payments.php@record',
    'POST /api/payments/submit' => 'routes/payments.php@submit',
    'POST /api/payments/verify' => 'routes/payments.php@verify',
    'POST /api/payments/user-update' => 'routes/payments.php@userUpdate',

    // SMTP / OTP
    'POST /api/smtp/test' => 'routes/smtp.php@test',
    'POST /api/smtp/configure' => 'routes/smtp.php@configure',
    'POST /api/smtp/adminSettings' => 'routes/smtp.php@loadAdminSettings',
    'POST /api/smtp/saveAdminSettings' => 'routes/smtp.php@saveAdminSettings',
    'POST /api/smtp/testEmail' => 'routes/smtp.php@testEmail',
    'POST /api/smtp/sendOTP' => 'routes/smtp.php@sendOTP',
    'POST /api/smtp/verifyOTP' => 'routes/smtp.php@verifyOTP',
    'POST /api/smtp/bulkSend' => 'routes/smtp.php@bulkSend',

    // Captcha
    'POST /api/captcha/verify' => 'routes/captcha.php@verify',
    'POST /api/captcha' => 'routes/captcha.php@verify',

    // Passkey
    'POST /api/passkey/beginLogin' => 'routes/passkey.php@beginLogin',
    'POST /api/passkey/finishLogin' => 'routes/passkey.php@finishLogin',
    'POST /api/passkey/beginRegistration' => 'routes/passkey.php@beginRegistration',
    'POST /api/passkey/finishRegistration' => 'routes/passkey.php@finishRegistration',

    // Admin
    'POST /api/info' => 'routes/admin.php@info',
    'POST /api/list' => 'routes/admin.php@listAll',
    'POST /api/update' => 'routes/admin.php@update',
    'POST /api/plans' => 'routes/admin.php@plans',
    'POST /api/plans/save' => 'routes/admin.php@savePlans',
    'POST /api/admin/create-user' => 'routes/admin.php@createUser',
    'POST /api/admin/reset-password' => 'routes/admin.php@resetPassword',
    'POST /api/admin/update-user' => 'routes/admin.php@updateUser',
    'POST /api/admin/delete-user-full' => 'routes/admin.php@deleteUserFull',
    'POST /api/admin/user-details' => 'routes/admin.php@userDetails',
    'POST /api/admin/alerts/list' => 'routes/admin.php@adminAlerts',
    'POST /api/admin/alerts/save' => 'routes/admin.php@saveAdminAlert',
    'POST /api/admin/alerts/toggle' => 'routes/admin.php@toggleAdminAlert',
    'POST /api/admin/alerts/delete' => 'routes/admin.php@deleteAdminAlert',
    'POST /api/admin/reset-2fa' => 'routes/admin.php@reset2FA',
    'POST /api/admin/dashboard-status' => 'routes/admin.php@dashboardStatus',
    'POST /api/admin/audit-logs' => 'routes/admin.php@auditLogs',
    'POST /api/admin/reports/list' => 'routes/admin.php@adminReports',
    'POST /api/admin/reports/delete' => 'routes/admin.php@deleteReport',
    'POST /api/admin/maintenance/status' => 'routes/admin.php@maintenanceStatus',
    'POST /api/admin/maintenance/toggle' => 'routes/admin.php@toggleMaintenance',
    'POST /api/activity/logs' => 'routes/admin.php@activityLogs',
    'POST /api/logs' => 'routes/admin.php@auditLogs',

    // Compliance
    'POST /api/invisia/score'           => 'routes/compliance.php@score',
    'POST /api/invisia/checklist'       => 'routes/compliance.php@detailedChecklist',
    'POST /api/invisia/auto-sign-training' => 'routes/compliance.php@autoSignTraining',
    'POST /api/invisia/compliance/config' => 'routes/compliance.php@updateConfig',
    'GET /api/invisia/compliance/config'  => 'routes/compliance.php@getConfig',
    'POST /api/compliance/verify-invite' => 'routes/compliance.php@verifyInvite',
    'POST /api/compliance/sign'          => 'routes/compliance.php@sign',
    'GET /api/compliance/public-policy'  => 'routes/compliance.php@generatePublicPolicy',

    // ─── Compliance Files ───
    'POST /api/compliance/files/upload'    => 'routes/compliance_files.php@upload',
    'POST /api/compliance/files/analyze'   => 'routes/compliance_files.php@analyze',
    'GET  /api/compliance/files'           => 'routes/compliance_files.php@listFiles',
    'DELETE /api/compliance/files'         => 'routes/compliance_files.php@deleteFile',
    'POST /api/compliance/files/map'       => 'routes/compliance_files.php@mapColumns',
  
     // ─── Compliance Files (Agente) ───
    'POST /api/compliance/files/agent-scan' => 'routes/compliance_files.php@agentScan',

     // ─── Audit logs de archivos ───
    'GET /api/compliance/files/audit-logs' => 'routes/compliance_files.php@listFileAuditLogs',


    // ARCO
    'POST /api/arco/requests'      => 'routes/arco.php@create',
    'POST /api/arco/requests/list' => 'routes/arco.php@listRequests',
    'POST /api/arco/requests/update' => 'routes/arco.php@updateRequest',
    'POST /api/arco/requests/generate-response' => 'routes/arco.php@generateResponse',
    'POST /api/arco/track'    => 'routes/arco.php@track',
    'GET /api/arco/requests/export-portabilidad' => 'routes/arco.php@exportPortabilidad',

    // Admin
    'POST /api/admin/users'       => 'routes/admin.php@users',
    'POST /api/admin/alerts'      => 'routes/admin.php@alerts',
    'POST /api/admin/alerts/public' => 'routes/admin.php@publicAlerts',

    // Payments
    'POST /api/payments/my-info' => 'routes/payments.php@myInfo',

    // Account
    'POST /api/account/update'          => 'routes/account.php@update',
    'POST /api/account/change-password' => 'routes/account.php@changePassword',
    'POST /api/account/change-email'    => 'routes/account.php@changeEmail',
    'POST /api/account/logout-all'      => 'routes/account.php@logoutAll',

    // Notifications
    'POST /api/notifications' => 'routes/notifications.php@listAll',

    // Tickets
    'POST /api/tickets' => 'routes/tickets.php@listAll',

    // Reports
    'POST /api/reports' => 'routes/reports.php@listAll',

    // Compliant Companies
    'GET /api/compliant-companies'  => 'routes/compliantCompanies.php@search',
    'GET /api/compliant-companies/search'  => 'routes/compliantCompanies.php@search',
    'POST /api/compliant-companies' => 'routes/compliantCompanies.php@search',

    // Chat
    'POST /api/chat' => 'routes/chat.php@send',

    // Assistant
    'POST /api/assistant' => 'routes/assistant.php@query',
    'POST /api/assistant/ask' => 'routes/assistant.php@ask',
    'POST /api/assistant/feedback' => 'routes/assistant.php@feedback',

    // Hardening
    'GET /api/hardening/check-waf' => 'routes/hardening.php@checkWaf',
    'POST /api/hardening/check-waf' => 'routes/hardening.php@checkWaf',

    // SMTP
    'POST /api/smtp/test' => 'routes/smtp.php@test',

    // Onboarding
    'POST /api/onboarding' => 'routes/onboarding.php@save',
    'GET /api/onboarding'  => 'routes/onboarding.php@get',

    // User Monitor
    'POST /api/user-monitor' => 'routes/userMonitor.php@listAll',

    // Captcha
    'POST /api/captcha' => 'routes/captcha.php@verify',

    // Passkey
    'POST /api/passkey' => 'routes/passkey.php@register',
];

// Check static routes first
$matchMethod = ($method === 'HEAD') ? 'GET' : $method;
$routeKey = $matchMethod . ' ' . $uri;
if (isset($routes[$routeKey])) {
    [$file, $action] = explode('@', $routes[$routeKey]);
    require_once __DIR__ . '/' . $file;
    $action();
    exit;
}

// Ensure admin seed exists on every request
require_once __DIR__ . '/seed.php';
seedAdminUser();

// Dynamic routes
if (preg_match('#^/api/agents/deploy$#', $uri) && $method === 'POST') {
    require_once __DIR__ . '/routes/agents.php';
    createDeploy();
    exit;
}

if (preg_match('#^/api/agents/install/linux$#', $uri, $m)) {
    require_once __DIR__ . '/routes/agents.php';
    linuxInstall();
    exit;
}

if (preg_match('#^/api/agents/download/(.+)$#', $uri, $m)) {
    $_GET['platform'] = $m[1];
    require_once __DIR__ . '/routes/agents.php';
    download();
    exit;
}

if (preg_match('#^/api/agents/([a-zA-Z0-9_-]+)/data$#', $uri, $m)) {
    $_GET['id'] = $m[1];
    require_once __DIR__ . '/routes/agents.php';
    getAgentData();
    exit;
}

if (preg_match('#^/api/agents/([a-zA-Z0-9_-]+)/forensics$#', $uri, $m)) {
    $_GET['id'] = $m[1];
    require_once __DIR__ . '/routes/agents.php';
    forensics();
    exit;
}

if (preg_match('#^/api/agents/([a-zA-Z0-9_-]+)/commands$#', $uri, $m) && in_array($method, ['GET', 'POST'])) {
    $_GET['id'] = $m[1];
    require_once __DIR__ . '/routes/agents.php';
    listCommands();
    exit;
}

if (preg_match('#^/api/agents/([a-zA-Z0-9_-]+)/heartbeat$#', $uri, $m)) {
    $_GET['agentId'] = $m[1];
    require_once __DIR__ . '/routes/agents.php';
    heartbeat();
    exit;
}

if (preg_match('#^/api/agents/([a-zA-Z0-9_-]+)/db-connections$#', $uri, $m) && $method === 'GET') {
    $_GET['id'] = $m[1];
    require_once __DIR__ . '/routes/agents.php';
    dbConnectionList();
    exit;
}

if (preg_match('#^/api/agents/([a-zA-Z0-9_-]+)/db-connection$#', $uri, $m) && $method === 'POST') {
    $_GET['id'] = $m[1];
    require_once __DIR__ . '/routes/agents.php';
    dbConnectionCreate();
    exit;
}

if (preg_match('#^/api/agents/([a-zA-Z0-9_-]+)/db-connection/delete$#', $uri, $m) && $method === 'POST') {
    $_GET['id'] = $m[1];
    require_once __DIR__ . '/routes/agents.php';
    dbConnectionDelete();
    exit;
}

if (preg_match('#^/api/agents/([a-zA-Z0-9_-]+)/db-connection/test$#', $uri, $m) && $method === 'POST') {
    $_GET['id'] = $m[1];
    require_once __DIR__ . '/routes/agents.php';
    dbConnectionTest();
    exit;
}

if (preg_match('#^/api/agents/([a-zA-Z0-9_-]+)/(delete|command)$#', $uri, $m) && $method === 'POST') {
    $_GET['id'] = $m[1];
    require_once __DIR__ . '/routes/agents.php';
    if ($m[2] === 'delete') {
        deleteAgent();
    } else {
        sendCommand();
    }
    exit;
}

if (preg_match('#^/api/agents/([a-zA-Z0-9_-]+)/message$#', $uri, $m) && $method === 'POST') {
    $_GET['agentId'] = $m[1];
    require_once __DIR__ . '/routes/agents.php';
    message();
    exit;
}

if (preg_match('#^/api/agents/([a-zA-Z0-9_-]+)$#', $uri, $m) && $method === 'GET') {
    $_GET['id'] = $m[1];
    require_once __DIR__ . '/routes/agents.php';
    getAgent();
    exit;
}

if (preg_match('#^/api/agents/([a-zA-Z0-9_-]+)/logs$#', $uri, $m) && $method === 'GET') {
    $_GET['id'] = $m[1];
    require_once __DIR__ . '/routes/agents.php';
    getAgentLogs();
    exit;
}

if (preg_match('#^/api/agents/([a-zA-Z0-9_-]+)$#', $uri, $m) && $method === 'POST') {
    $_GET['id'] = $m[1];
    require_once __DIR__ . '/routes/agents.php';
    updateAgent();
    exit;
}

if ($uri === '/api/folders/list' && $method === 'POST') {
    require_once __DIR__ . '/routes/agents.php';
    folderList();
    exit;
}

if ($uri === '/api/folders/create' && $method === 'POST') {
    require_once __DIR__ . '/routes/agents.php';
    folderCreate();
    exit;
}

if ($uri === '/api/folders/delete' && $method === 'POST') {
    require_once __DIR__ . '/routes/agents.php';
    folderDelete();
    exit;
}

if ($uri === '/api/reports/download-all' && $method === 'GET') {
    $_GET['id'] = 'all';
    require_once __DIR__ . '/routes/reports.php';
    download();
    exit;
}

if (preg_match('#^/api/reports/download/(.+)$#', $uri, $m) && $method === 'GET') {
    $_GET['id'] = $m[1];
    require_once __DIR__ . '/routes/reports.php';
    download();
    exit;
}

if (preg_match('#^/api/arco/requests/([A-Z0-9-]+)/document$#', $uri, $m) && $method === 'GET') {
    $_GET['requestId'] = $m[1];
    require_once __DIR__ . '/routes/arco.php';
    downloadResponse();
    exit;
}

if (preg_match('#^/api/arco/requests/([A-Z0-9-]+)/receipt$#', $uri, $m) && $method === 'GET') {
    $_GET['requestId'] = $m[1];
    require_once __DIR__ . '/routes/arco.php';
    downloadReceipt();
    exit;
}

if (preg_match('#^/api/auth/users/([a-zA-Z0-9_-]+)$#', $uri, $m) && $method === 'DELETE') {
    $_GET['userId'] = $m[1];
    require_once __DIR__ . '/routes/admin.php';
    deleteUserById();
    exit;
}

if (preg_match('#^/api/admin/user/([a-zA-Z0-9]+)$#', $uri, $m)) {
    $_GET['userId'] = $m[1];
    require_once __DIR__ . '/routes/admin.php';
    userDetail();
    exit;
}

// ── Agent Deploy (admin) ──
if (preg_match('#^/api/admin/agent-deploy$#', $uri) && $method === 'POST') {
    require_once __DIR__ . '/routes/admin.php';
    agentDeployCreate();
    exit;
}

if (preg_match('#^/api/agent/download/([a-zA-Z0-9_-]+)$#', $uri, $m)) {
    $_GET['platform'] = $m[1];
    require_once __DIR__ . '/routes/admin.php';
    agentDownload();
    exit;
}

// ── Data Reset (superadmin only) ──
if (preg_match('#^/api/admin/data-reset/backup$#', $uri) && $method === 'POST') {
    require_once __DIR__ . '/routes/admin.php';
    dataResetBackup();
    exit;
}

if (preg_match('#^/api/admin/data-reset/execute$#', $uri) && $method === 'POST') {
    require_once __DIR__ . '/routes/admin.php';
    dataResetExecute();
    exit;
}

if (preg_match('#^/api/databases/client/([a-zA-Z0-9-]+)$#', $uri, $m) && $method === 'POST') {
    $_GET['action'] = $m[1];
    require_once __DIR__ . '/routes/databases.php';
    clientAction($_GET['action']);
    exit;
}

if (preg_match('#^/api/databases/([a-zA-Z0-9_-]+)/client/([a-zA-Z0-9-]+)$#', $uri, $m) && $method === 'POST') {
    $_GET['dbId'] = $m[1];
    $_GET['action'] = $m[2];
    require_once __DIR__ . '/routes/databases.php';
    clientAction($_GET['action']);
    exit;
}

if (preg_match('#^/api/databases/([a-zA-Z0-9_-]+)(?:/([a-zA-Z0-9_-]+))?$#', $uri, $m) && $method === 'POST') {
    $_GET['id'] = $m[1];
    $action = $m[2] ?? '';
    require_once __DIR__ . '/routes/databases.php';
    if ($action === '') {
        update();
    } elseif ($action === 'delete') {
        delete();
    } elseif ($action === 'test') {
        testConnection();
    } elseif ($action === 'scan') {
        scan();
    } elseif ($action === 'query') {
        query();
    } elseif ($action === 'report') {
        generateReport();
    } elseif ($action === 'sync-agent') {
        syncAgent();
    } else {
        json_error('acción no soportada', 404);
    }
    exit;
}

if (preg_match('#^/api/notifications/([a-zA-Z0-9_-]+)/(read|delete)$#', $uri, $m) && $method === 'POST') {
    $_GET['id'] = $m[1];
    require_once __DIR__ . '/routes/notifications.php';
    if ($m[2] === 'read') {
        markRead();
    } else {
        deleteOne();
    }
    exit;
}

if (preg_match('#^/api/tickets/([a-zA-Z0-9_-]+)(?:/(reply|status))?$#', $uri, $m)) {
    $_GET['id'] = $m[1];
    $action = $m[2] ?? '';
    require_once __DIR__ . '/routes/tickets.php';
    if ($action === '') {
        detail();
    } elseif ($action === 'reply') {
        reply();
    } elseif ($action === 'status') {
        status();
    } else {
        json_error('acción no soportada', 404);
    }
    exit;
}

if (preg_match('#^/api/user-monitor/([a-zA-Z0-9_-]+)$#', $uri, $m) && $method === 'POST') {
    $_GET['userId'] = $m[1];
    require_once __DIR__ . '/routes/userMonitor.php';
    detail();
    exit;
}

if (preg_match('#^/api/payments/history/([a-zA-Z0-9_-]+)$#', $uri, $m) && $method === 'POST') {
    $_GET['userId'] = $m[1];
    require_once __DIR__ . '/routes/payments.php';
    history();
    exit;
}

if (preg_match('#^/api/smtp/bulkStatus/([a-zA-Z0-9_-]+)$#', $uri, $m) && $method === 'GET') {
    $_GET['jobId'] = $m[1];
    require_once __DIR__ . '/routes/smtp.php';
    bulkStatus();
    exit;
}

if (preg_match('#^/api/invisia/compliance/.+$#', $uri) || preg_match('#^/api/compliance/.+$#', $uri)) {
    require_once __DIR__ . '/routes/compliance.php';
    crud();
    exit;
}

// DB Logs API
if (preg_match('#^/api/db-logs$#', $uri)) {
    require_once __DIR__ . '/routes/db-logs.php';
    exit;
}

// Generate PDF for Incident Response Plan
if (preg_match('#^/api/generate-incident-response-pdf$#', $uri) && $method === 'POST') {
    require_once __DIR__ . '/generate-incident-response-pdf.php';
    exit;
}

// Serve PDF files from reports directory
if (preg_match('#^/backend/reports/(.+\.pdf)$#', $uri, $matches) && $method === 'GET') {
    $filename = basename($matches[1]);
    $filePath = __DIR__ . '/reports/' . $filename;
    
    // Security check: ensure the file is in the reports directory
    $realPath = realpath($filePath);
    $reportsDir = realpath(__DIR__ . '/reports');
    
    if ($realPath === false || strpos($realPath, $reportsDir) !== 0) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied']);
        exit;
    }
    
    if (file_exists($filePath) && is_file($filePath) && is_readable($filePath)) {
        // Verify it's a PDF file
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $filePath);
        finfo_close($finfo);
        
        if ($mimeType === 'application/pdf') {
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . filesize($filePath));
            header('Cache-Control: no-cache, must-revalidate');
            header('Pragma: no-cache');
            header('Expires: Sat, 26 Jul 1997 05:00:00 GMT');
            
            readfile($filePath);
            exit;
        } else {
            http_response_code(403);
            echo json_encode(['error' => 'File is not a PDF']);
            exit;
        }
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'File not found']);
        exit;
    }
}

// Alternative endpoint to serve PDFs through API (public access for generated files)
if (preg_match('#^/api/reports/download/(.+\.pdf)$#', $uri, $matches) && $method === 'GET') {
    $filename = basename($matches[1]);
    $filePath = __DIR__ . '/reports/' . $filename;
    
    // Security check: ensure the file is in the reports directory
    $realPath = realpath($filePath);
    $reportsDir = realpath(__DIR__ . '/reports');
    
    if ($realPath === false || strpos($realPath, $reportsDir) !== 0) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied']);
        exit;
    }
    
    if (file_exists($filePath) && is_file($filePath) && is_readable($filePath)) {
        // Verify it's a PDF file
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $filePath);
        finfo_close($finfo);
        
        if ($mimeType === 'application/pdf') {
            header('Content-Type: application/pdf');
            header('Content-Disposition: inline; filename="' . $filename . '"');
            header('Content-Length: ' . filesize($filePath));
            header('Cache-Control: public, max-age=3600');
            header('Access-Control-Allow-Origin: *');
            
            readfile($filePath);
            exit;
        } else {
            http_response_code(403);
            echo json_encode(['error' => 'File is not a PDF']);
            exit;
        }
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'File not found']);
        exit;
    }
}

// 404
json_error('Ruta no encontrada', 404);
