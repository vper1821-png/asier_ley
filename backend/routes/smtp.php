<?php
// SMTP routes

function test() {
    $user = Auth::requireAuth();
    $body = get_body();

    $host = $body['host'] ?? '';
    $port = $body['port'] ?? 587;
    $email = $body['email'] ?? '';
    $password = $body['password'] ?? '';

    if (!$host || !$email) json_error('host y email requeridos');

    // Basic SMTP test
    $errno = 0;
    $errstr = '';
    $socket = @fsockopen($host, $port, $errno, $errstr, 10);

    if (!$socket) {
        json_response(['success' => false, 'error' => "No se pudo conectar: $errstr"]);
    }

    fclose($socket);
    json_response(['success' => true, 'message' => 'Conexión SMTP exitosa']);
}

function loadAdminSettings() {
    $user = Auth::requireAuth();
    $db = Database::getInstance();
    $settings = $db->findOne('smtp_settings', ['scope' => 'admin']);
    if (!$settings) {
        json_response(['success' => true, 'settings' => [
            'host' => '', 'port' => 587, 'username' => '', 'password' => '', 'from' => '',
            'encryption' => 'tls', 'enabled' => false,
        ]]);
    }
    unset($settings['password']);
    json_response(['success' => true, 'settings' => $settings]);
}

function saveAdminSettings() {
    $user = Auth::requireAuth();
    $body = get_body();
    $db = Database::getInstance();

    $data = [
        'host' => $body['host'] ?? '',
        'port' => (int)($body['port'] ?? 587),
        'username' => $body['username'] ?? '',
        'password' => $body['password'] ?? '',
        'from' => $body['from'] ?? '',
        'encryption' => $body['encryption'] ?? 'tls',
        'enabled' => filter_var($body['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN),
    ];

    $existing = $db->findOne('smtp_settings', ['scope' => 'admin']);
    if ($existing) {
        $db->updateOne('smtp_settings', ['_id' => $existing['_id']], $data);
    } else {
        $data['scope'] = 'admin';
        $db->insertOne('smtp_settings', $data);
    }

    json_response(['success' => true]);
}

function testEmail() {
    $user = Auth::requireAuth();
    $body = get_body();
    $to = $body['email'] ?? '';
    if (!$to) json_error('email requerido');
    json_response(['success' => true, 'message' => "Email de prueba encolado a $to (sin servicio SMTP real)"]);
}

function sendOTP() {
    $body = get_body();
    $email = strtolower(trim($body['email'] ?? ''));
    $purpose = $body['purpose'] ?? 'verification';

    if (!$email) json_error('email requerido');

    $db = Database::getInstance();
    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $db->insertOne('otp_codes', [
        'email' => $email,
        'code' => $code,
        'purpose' => $purpose,
        'used' => false,
        'expiresAt' => date('c', time() + 600),
    ]);

    json_response(['success' => true, 'message' => 'Código enviado (modo desarrollo)']);
}

function verifyOTP() {
    $body = get_body();
    $email = strtolower(trim($body['email'] ?? ''));
    $code = $body['code'] ?? '';
    $purpose = $body['purpose'] ?? 'verification';

    if (!$email || !$code) json_error('email y código requeridos');

    $db = Database::getInstance();
    $otp = $db->findOne('otp_codes', ['email' => $email, 'code' => $code, 'purpose' => $purpose, 'used' => false]);
    if (!$otp) json_error('código inválido');
    if (strtotime($otp['expiresAt'] ?? '') < time()) json_error('código expirado');

    $db->updateOne('otp_codes', ['_id' => $otp['_id']], ['used' => true, 'verifiedAt' => date('c')]);
    json_response(['success' => true]);
}

function bulkSend() {
    $user = Auth::requireAuth();
    $body = get_body();
    $subject = $body['subject'] ?? '';
    $html = $body['html'] ?? '';
    $contacts = json_decode($body['contacts'] ?? '[]', true);

    if (!$subject || !$html || empty($contacts) || !is_array($contacts)) json_error('subject, html y contacts requeridos');

    $db = Database::getInstance();
    $job = $db->insertOne('smtp_jobs', [
        'userId' => $user['_id'],
        'subject' => $subject,
        'html' => $html,
        'contacts' => $contacts,
        'total' => count($contacts),
        'sent' => 0,
        'failed' => 0,
        'status' => 'queued',
        'createdAt' => date('c'),
    ]);

    json_response(['success' => true, 'jobId' => $job['_id']]);
}

function bulkStatus() {
    $user = Auth::requireAuth();
    $jobId = $_GET['jobId'] ?? '';
    if (!$jobId) json_error('jobId requerido');
    $db = Database::getInstance();
    $job = $db->findOne('smtp_jobs', ['_id' => $jobId]);
    if (!$job) json_error('job no encontrado', 404);
    json_response($job);
}

function configure() {
    $user = Auth::requireAuth();
    $body = get_body();
    $db = Database::getInstance();

    $data = [
        'userId' => $user['_id'],
        'host' => $body['host'] ?? '',
        'port' => (int)($body['port'] ?? 587),
        'username' => $body['username'] ?? '',
        'password' => $body['password'] ?? '',
        'from' => $body['from'] ?? '',
        'encryption' => $body['encryption'] ?? 'tls',
    ];

    $existing = $db->findOne('smtp_settings', ['userId' => $user['_id']]);
    if ($existing) {
        $db->updateOne('smtp_settings', ['_id' => $existing['_id']], $data);
    } else {
        $db->insertOne('smtp_settings', $data);
    }

    json_response(['success' => true]);
}
