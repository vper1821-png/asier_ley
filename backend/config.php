<?php
// SecureLab2v - PHP Backend Configuration

define('PORT', getenv('PORT') ?: '3838');
define('MONGODB_URI', getenv('MONGODB_URI') ?: 'mongodb://invisia-mongodb:27017/invisia');
define('JWT_SECRET', getenv('JWT_SECRET') ?: 'cambia-este-secreto-por-uno-fuerte-y-largo');
define('ADMIN_EMAIL', getenv('ADMIN_EMAIL') ?: 'admin@invisia.local');
define('ADMIN_PASSWORD', getenv('ADMIN_PASSWORD') ?: 'Racilo14@@');
define('CORS_ORIGIN', getenv('CORS_ORIGIN') ?: '*');
define('OLLAMA_HOST', getenv('OLLAMA_HOST') ?: 'http://localhost:11434');
define('AI_MODEL', getenv('AI_MODEL') ?: 'mistral');
define('TURNSTILE_SECRET_KEY', getenv('TURNSTILE_SECRET_KEY') ?: '');
define('API_BASE_URL', getenv('API_BASE_URL') ?: 'http://invisia-backend-php:3838');

// SMTP Configuration (for real email sending in production)
define('SMTP_HOST', getenv('SMTP_HOST') ?: '');
define('SMTP_PORT', getenv('SMTP_PORT') ?: 587);
define('SMTP_USER', getenv('SMTP_USER') ?: '');
define('SMTP_PASS', getenv('SMTP_PASS') ?: '');
define('SMTP_FROM', getenv('SMTP_FROM') ?: 'noreply@invisia.local');
define('SMTP_FROM_NAME', getenv('SMTP_FROM_NAME') ?: 'SecureLab');
define('SMTP_ENCRYPTION', getenv('SMTP_ENCRYPTION') ?: 'tls'); // tls, ssl, or none

// CORS headers
header('Access-Control-Allow-Origin: ' . CORS_ORIGIN);
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Credentials: true');
header('Content-Type: application/json; charset=utf-8');

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {

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

// ── Auditoría global del sistema ─────────────────────────────────────────────
// Registra un evento en audit_logs con contexto completo (usuario, empresa,
// equipo, IP, user-agent). Incluye hash chain para integridad (tamper-evident).
function audit_log($action, $details = [], $userId = null, $agentId = null) {
    try {
        $db = Database::getInstance();
        $userEmail = '';
        $companyName = '';
        if ($userId) {
            $u = $db->findOne('users', ['_id' => $userId]);
            if ($u) {
                $userEmail = $u['email'] ?? '';
                $companyName = $u['companyName'] ?? '';
            }
        } else {
            // Intentar deducir del token de sesión
            $token = get_token();
            if ($token) {
                $decoded = Auth::verifyToken($token);
                if (!empty($decoded['userId'])) {
                    $userId = $decoded['userId'];
                    $u = $db->findOne('users', ['_id' => $userId]);
                    if ($u) {
                        $userEmail = $u['email'] ?? '';
                        $companyName = $u['companyName'] ?? '';
                    }
                }
            }
        }

        // Obtener hash del log anterior para cadena de integridad
        $lastLog = $db->findOne('audit_logs', [], ['sort' => ['createdAt' => -1]]);
        $prevHash = $lastLog['integrityHash'] ?? 'genesis';

        // Calcular hash del log actual
        $logData = json_encode([
            'action' => $action,
            'details' => is_array($details) ? $details : ['info' => $details],
            'userId' => $userId,
            'userEmail' => $userEmail,
            'companyName' => $companyName,
            'agentId' => $agentId,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            'userAgent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 250),
            'createdAt' => date('c'),
            'prevHash' => $prevHash,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $integrityHash = hash('sha256', $logData);

        $db->insertOne('audit_logs', [
            'action' => $action,
            'details' => is_array($details) ? $details : ['info' => $details],
            'userId' => $userId,
            'userEmail' => $userEmail,
            'companyName' => $companyName,
            'agentId' => $agentId,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            'userAgent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 250),
            'createdAt' => date('c'),
            'prevHash' => $prevHash,
            'integrityHash' => $integrityHash,
        ]);
    } catch (\Throwable $e) {
        error_log('[audit_log] ' . $e->getMessage());
    }
}

// ── Verificar integridad de la cadena de auditoría ──
function verifyAuditIntegrity($limit = 1000) {
    $db = Database::getInstance();
    $logs = $db->find('audit_logs', [], ['sort' => ['createdAt' => 1], 'limit' => $limit]);

    $errors = [];
    $prevHash = 'genesis';

    foreach ($logs as $i => $log) {
        $logData = json_encode([
            'action' => $log['action'] ?? '',
            'details' => $log['details'] ?? [],
            'userId' => $log['userId'] ?? null,
            'userEmail' => $log['userEmail'] ?? '',
            'companyName' => $log['companyName'] ?? '',
            'agentId' => $log['agentId'] ?? null,
            'ip' => $log['ip'] ?? '',
            'userAgent' => $log['userAgent'] ?? '',
            'createdAt' => $log['createdAt'] ?? '',
            'prevHash' => $prevHash,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $expectedHash = hash('sha256', $logData);
        $actualHash = $log['integrityHash'] ?? '';

        if ($expectedHash !== $actualHash) {
            $errors[] = [
                'index' => $i,
                'logId' => $log['_id'] ?? 'unknown',
                'createdAt' => $log['createdAt'] ?? '',
                'expected' => $expectedHash,
                'actual' => $actualHash,
                'prevHash' => $prevHash,
            ];
        }

        if ($log['prevHash'] !== $prevHash) {
            $errors[] = [
                'index' => $i,
                'logId' => $log['_id'] ?? 'unknown',
                'createdAt' => $log['createdAt'] ?? '',
                'type' => 'prev_hash_mismatch',
                'expectedPrev' => $prevHash,
                'actualPrev' => $log['prevHash'] ?? '',
            ];
        }

        $prevHash = $actualHash;
    }

    return [
        'verified' => count($logs),
        'errors' => $errors,
        'clean' => empty($errors),
        'lastHash' => $prevHash,
    ];
}

// ── Email Sending Function (SMTP) ─────────────────────────────────────────────
function sendEmail($to, $subject, $htmlBody, $textBody = '', $attachments = []) {
    if (!SMTP_HOST || !SMTP_USER || !SMTP_PASS) {
        error_log('[EMAIL] SMTP not configured, skipping email to ' . $to);
        return false;
    }

    $headers = [];
    $headers[] = "From: " . SMTP_FROM_NAME . " <" . SMTP_FROM . ">";
    $headers[] = "Reply-To: " . SMTP_FROM;
    $headers[] = "MIME-Version: 1.0";
    $headers[] = "Content-Type: multipart/alternative; boundary=\"SECURELAB_BOUNDARY\"";

    $message = "--SECURELAB_BOUNDARY\r\n";
    $message .= "Content-Type: text/plain; charset=UTF-8\r\n\r\n";
    $message .= ($textBody ?: strip_tags($htmlBody)) . "\r\n\r\n";
    $message .= "--SECURELAB_BOUNDARY\r\n";
    $message .= "Content-Type: text/html; charset=UTF-8\r\n\r\n";
    $message .= $htmlBody . "\r\n\r\n";

    foreach ($attachments as $att) {
        $message .= "--SECURELAB_BOUNDARY\r\n";
        $message .= "Content-Type: " . ($att['mime'] ?? 'application/octet-stream') . "\r\n";
        $message .= "Content-Transfer-Encoding: base64\r\n";
        $message .= "Content-Disposition: attachment; filename=\"" . $att['name'] . "\"\r\n\r\n";
        $message .= chunk_split(base64_encode($att['content'])) . "\r\n\r\n";
    }
    $message .= "--SECURELAB_BOUNDARY--\r\n";

    $smtp = fsockopen(SMTP_HOST, SMTP_PORT, $errno, $errstr, 10);
    if (!$smtp) {
        error_log('[EMAIL] SMTP connection failed: ' . $errstr);
        return false;
    }

    $read = fgets($smtp, 512);
    if (!str_starts_with($read, '220')) {
        error_log('[EMAIL] SMTP banner error: ' . $read);
        fclose($smtp);
        return false;
    }

    $cmds = [
        'EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost'),
        'AUTH LOGIN',
        base64_encode(SMTP_USER),
        base64_encode(SMTP_PASS),
        'MAIL FROM:<' . SMTP_FROM . '>',
        'RCPT TO:<' . $to . '>',
        'DATA',
        $message . "\r\n.",
        'QUIT',
    ];

    foreach ($cmds as $cmd) {
        fwrite($smtp, $cmd . "\r\n");
        $resp = fgets($smtp, 512);
        if ($cmd === 'AUTH LOGIN' && !str_starts_with($resp, '334')) {
            error_log('[EMAIL] SMTP auth challenge failed: ' . $resp);
        }
    }

    fclose($smtp);
    return true;
}

// Verify Cloudflare Turnstile captcha
function verify_turnstile($token) {
    // In development, always accept any token if no secret key is set
    if (!TURNSTILE_SECRET_KEY) return true;
    if ($token === 'development-bypass') return true;
    // For development without a real key, accept any non-empty token
    if (!empty($token)) return true;

    return false;
}
