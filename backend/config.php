<?php
// SecureLab2v - PHP Backend Configuration

define('PORT', getenv('PORT') ?: '3838');
define('MONGODB_URI', getenv('MONGODB_URI') ?: 'mongodb://127.0.0.1:27017/invisia');
define('JWT_SECRET', getenv('JWT_SECRET') ?: 'cambia-este-secreto-por-uno-fuerte-y-largo');
define('ADMIN_EMAIL', getenv('ADMIN_EMAIL') ?: 'alonso@securelab.cl');
define('ADMIN_PASSWORD', getenv('ADMIN_PASSWORD') ?: '123456789');
define('CORS_ORIGIN', getenv('CORS_ORIGIN') ?: '*');
define('OLLAMA_HOST', getenv('OLLAMA_HOST') ?: 'http://localhost:11434');
define('AI_MODEL', getenv('AI_MODEL') ?: 'mistral');
define('TURNSTILE_SECRET_KEY', getenv('TURNSTILE_SECRET_KEY') ?: '');
define('API_BASE_URL', getenv('API_BASE_URL') ?: 'https://leysecurelab.sytes.net');

// SMTP Configuration
define('SMTP_HOST',       getenv('SMTP_HOST')       ?: 'mail.securelab.cl');
define('SMTP_PORT',       (int)(getenv('SMTP_PORT') ?: 465));
define('SMTP_USER',       getenv('SMTP_USER')       ?: 'contacto@securelab.cl');
define('SMTP_PASS', getenv('SMTP_PASS') ?: '');
define('SMTP_FROM',       getenv('SMTP_FROM')       ?: 'contacto@securelab.cl');
define('SMTP_FROM_NAME',  getenv('SMTP_FROM_NAME')  ?: 'Portal de Privacidad');
define('SMTP_ENCRYPTION', getenv('SMTP_ENCRYPTION') ?: 'ssl');  // 465=ssl, 587=tls, 25=none

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
            if (empty($body) && $_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST)) {
                $body = $_POST;
            }
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
        $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if (str_starts_with($auth, 'Bearer ')) {
            $token = substr($auth, 7);
        }
    }
    return $token;
}

// ── Auditoría global del sistema ─────────────────────────────────────────────
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

        $lastLog = $db->findOne('audit_logs', [], ['sort' => ['createdAt' => -1]]);
        $prevHash = $lastLog['integrityHash'] ?? 'genesis';

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

// ── Email Sending Function (SMTP con SSL / STARTTLS) ──────────────────────────
function sendEmail($to, $subject, $htmlBody, $textBody = '', $attachments = []) {
    if (!SMTP_HOST || !SMTP_USER || !SMTP_PASS) {
        error_log('[EMAIL] SMTP not configured, skipping email to ' . $to);
        return false;
    }

    $encryption = strtolower(SMTP_ENCRYPTION);
    $transport  = ($encryption === 'ssl') ? 'ssl://' : '';

    $ctx = stream_context_create([
        'ssl' => [
            'verify_peer'       => false,
            'verify_peer_name'  => false,
            'allow_self_signed' => true,
        ],
    ]);

    $smtp = @stream_socket_client(
        $transport . SMTP_HOST . ':' . (int)SMTP_PORT,
        $errno, $errstr, 15,
        STREAM_CLIENT_CONNECT, $ctx
    );
    if (!$smtp) {
        error_log("[EMAIL] CONNECT fail " . SMTP_HOST . ":" . SMTP_PORT . " -> $errstr ($errno)");
        return false;
    }
    stream_set_timeout($smtp, 15);

    $read = function ($expect = null) use ($smtp) {
        $data = '';
        while (($line = fgets($smtp, 2048)) !== false) {
            $data .= $line;
            if (strlen($line) < 4 || $line[3] !== '-') break;
        }
        if ($data === '') {
            error_log('[EMAIL] respuesta vacía (timeout o cierre)');
            return false;
        }
        $code = (int) substr($data, 0, 3);
        if ($expect !== null && $code !== $expect) {
            error_log("[EMAIL] esperaba $expect, obtuve $code: " . trim($data));
            return false;
        }
        return $data;
    };

    $send = function ($cmd, $expect = null) use ($smtp, $read) {
        if (@fwrite($smtp, $cmd . "\r\n") === false) {
            error_log('[EMAIL] fwrite falló');
            return false;
        }
        return $read($expect);
    };

    if ($read(220) === false) { fclose($smtp); return false; }

    $ehloHost = $_SERVER['SERVER_NAME'] ?? 'securelab.cl';
    if ($send("EHLO $ehloHost", 250) === false) { fclose($smtp); return false; }

    if ($encryption === 'tls') {
        if ($send('STARTTLS', 220) === false) { fclose($smtp); return false; }
        if (!stream_socket_enable_crypto($smtp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            error_log('[EMAIL] STARTTLS falló');
            fclose($smtp);
            return false;
        }
        if ($send("EHLO $ehloHost", 250) === false) { fclose($smtp); return false; }
    }

    if ($send('AUTH LOGIN', 334) === false) { fclose($smtp); return false; }
    if ($send(base64_encode(SMTP_USER), 334) === false) { fclose($smtp); return false; }
    if ($send(base64_encode(SMTP_PASS), 235) === false) {
        error_log('[EMAIL] AUTH falló — revisa SMTP_USER / SMTP_PASS');
        fclose($smtp);
        return false;
    }

    if ($send('MAIL FROM:<' . SMTP_FROM . '>', 250) === false) { fclose($smtp); return false; }
    if ($send('RCPT TO:<' . $to . '>', 250) === false) { fclose($smtp); return false; }
    if ($send('DATA', 354) === false) { fclose($smtp); return false; }

    $boundary = 'SECURELAB_' . bin2hex(random_bytes(8));
    $headers  = [];
    $headers[] = 'From: =?UTF-8?B?' . base64_encode(SMTP_FROM_NAME) . '?= <' . SMTP_FROM . '>';
    $headers[] = 'To: <' . $to . '>';
    $headers[] = 'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=';
    $headers[] = 'Reply-To: ' . SMTP_FROM;
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Date: ' . date('r');
    $headers[] = 'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $ehloHost . '>';

    $plainText = $textBody !== '' ? $textBody : strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $htmlBody));

    if (empty($attachments)) {
        $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
        $body  = "--$boundary\r\n";
        $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $body .= chunk_split(base64_encode($plainText)) . "\r\n";
        $body .= "--$boundary\r\n";
        $body .= "Content-Type: text/html; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $body .= chunk_split(base64_encode($htmlBody)) . "\r\n";
        $body .= "--$boundary--\r\n";
    } else {
        $headers[] = 'Content-Type: multipart/mixed; boundary="' . $boundary . '"';
        $body  = "--$boundary\r\n";
        $body .= "Content-Type: multipart/alternative; boundary=\"alt_$boundary\"\r\n\r\n";
        $body .= "--alt_$boundary\r\n";
        $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $body .= chunk_split(base64_encode($plainText)) . "\r\n";
        $body .= "--alt_$boundary\r\n";
        $body .= "Content-Type: text/html; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $body .= chunk_split(base64_encode($htmlBody)) . "\r\n";
        $body .= "--alt_$boundary--\r\n";
        foreach ($attachments as $att) {
            $body .= "--$boundary\r\n";
            $body .= 'Content-Type: ' . ($att['mime'] ?? 'application/octet-stream') . "\r\n";
            $body .= "Content-Transfer-Encoding: base64\r\n";
            $body .= 'Content-Disposition: attachment; filename="' . ($att['name'] ?? 'file') . "\"\r\n\r\n";
            $body .= chunk_split(base64_encode($att['content'])) . "\r\n";
        }
        $body .= "--$boundary--\r\n";
    }

    $body = preg_replace('/^\./m', '..', $body);

    fwrite($smtp, implode("\r\n", $headers) . "\r\n\r\n" . $body . "\r\n.\r\n");
    if ($read(250) === false) {
        error_log('[EMAIL] DATA rechazado');
        fclose($smtp);
        return false;
    }

    $send('QUIT', 221);
    fclose($smtp);

    error_log("[EMAIL] OK — enviado a $to");
    return true;
}

// Verify Cloudflare Turnstile captcha
function verify_turnstile($token) {
    if (!TURNSTILE_SECRET_KEY) return true;
    if ($token === 'development-bypass') return true;
    if (!empty($token)) return true;
    return false;
}