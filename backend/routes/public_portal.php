<?php
require_once __DIR__ . '/../Auth.php';
require_once __DIR__ . '/../lib/NotificationService.php';

// Configuración del portal
const PORTAL_CODE_TTL = 600;            // 10 min
const PORTAL_MAX_ATTEMPTS = 5;          // intentos por sesión
const PORTAL_RESEND_COOLDOWN = 60;      // 1 min entre reenvíos

function portalRequestCode() {
    $body = get_body();
    $email = strtolower(trim($body['email'] ?? ''));

    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_error('email inválido');
    }

    $db = Database::getInstance();
    $now = date('c');

    // ── Anti-abuse: cooldown por email ──
    $cooldownCutoff = date('c', time() - PORTAL_RESEND_COOLDOWN);
    $recent = $db->find('portal_sessions', [
        'email' => $email,
        'createdAt' => ['$gte' => $cooldownCutoff],
    ]);
    if (!empty($recent)) {
        // Respondemos igual (anti-enumeración) pero sin emitir nuevo código.
        json_response(['success' => true, 'message' => 'Si el email existe recibirás un código']);
    }

    // ── ¿Existe el titular en alguna colección? ──
    $exists = $db->findOne('compliance_consents', ['email' => $email])
           || $db->findOne('arco_requests', ['solicitante.email' => $email]);

    if ($exists) {
        // Invalidar códigos previos vigentes de este email
        $pending = $db->find('portal_sessions', [
            'email' => $email,
            'used' => false,
            'expiresAt' => ['$gte' => $now],
        ]);
        foreach ($pending as $p) {
            $db->updateOne('portal_sessions', ['_id' => $p['_id']], ['used' => true]);
        }

        $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $db->insertOne('portal_sessions', [
            'email' => $email,
            'codeHash' => hash('sha256', $code),
            'expiresAt' => date('c', time() + PORTAL_CODE_TTL),
            'used' => false,
            'attempts' => 0,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            'createdAt' => $now,
        ]);

        // Resolver companyId: consentimiento primero, ARCO como fallback
        $companyId = '';
        $consent = $db->findOne('compliance_consents', ['email' => $email]);
        if ($consent && !empty($consent['userId'])) {
            $companyId = $consent['userId'];
        } else {
            $arco = $db->findOne('arco_requests', ['solicitante.email' => $email]);
            if ($arco) {
                $companyId = $arco['companyId'] ?? ($arco['userId'] ?? '');
            }
        }

        NotificationService::queue($db, [
            'companyId' => $companyId,
            'channel' => 'email',
            'recipient' => $email,
            'recipientType' => 'titular',
            'templateCode' => 'portal_magic_code',
            'variables' => ['code' => $code, 'ttlMinutes' => PORTAL_CODE_TTL / 60],
        ]);
    }

    // Anti-enumeración: siempre OK
    json_response(['success' => true, 'message' => 'Si el email existe recibirás un código']);
}

function portalVerifyCode() {
    $body = get_body();
    $email = strtolower(trim($body['email'] ?? ''));
    $code = trim((string)($body['code'] ?? ''));

    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_error('email inválido');
    }
    if (!preg_match('/^\d{6}$/', $code)) {
        json_error('código inválido o expirado', 401);
    }

    $db = Database::getInstance();
    $now = date('c');

    // Buscar la sesión vigente más reciente para este email
    $sessions = $db->find('portal_sessions', [
        'email' => $email,
        'used' => false,
        'expiresAt' => ['$gte' => $now],
    ]);

    if (empty($sessions)) {
        json_error('código inválido o expirado', 401);
    }

    // Tomar la más reciente por createdAt (string ISO, comparación lexicográfica válida)
    usort($sessions, fn($a, $b) => strcmp($b['createdAt'] ?? '', $a['createdAt'] ?? ''));
    $session = $sessions[0];

    // Límite de intentos
    $attempts = (int)($session['attempts'] ?? 0);
    if ($attempts >= PORTAL_MAX_ATTEMPTS) {
        $db->updateOne('portal_sessions', ['_id' => $session['_id']], ['used' => true]);
        json_error('demasiados intentos. Solicita un nuevo código.', 429);
    }

    // Comparar hash en tiempo constante
    if (!hash_equals((string)$session['codeHash'], hash('sha256', $code))) {
        $db->updateOne('portal_sessions', ['_id' => $session['_id']], [
            'attempts' => $attempts + 1,
            'lastAttemptAt' => $now,
        ]);
        json_error('código inválido o expirado', 401);
    }

    // Éxito: emitir token y marcar sesión como usada
    $token = Auth::createPortalToken($email);

    $db->updateOne('portal_sessions', ['_id' => $session['_id']], [
        'used' => true,
        'usedAt' => $now,
    ]);

    // Limpieza oportunista: marcar como usadas las sesiones ya expiradas de este email
    foreach ($sessions as $s) {
        if ($s['_id'] !== $session['_id']) {
            $db->updateOne('portal_sessions', ['_id' => $s['_id']], ['used' => true]);
        }
    }

    json_response(['success' => true, 'token' => $token]);
}

function portalMyData() {
    $email = Auth::requirePortalSession();
    $db = Database::getInstance();

    $consents = $db->find('compliance_consents', ['email' => $email]);
    $arcoRequests = $db->find('arco_requests', ['solicitante.email' => $email]);

    $activeConsents = count(array_filter($consents, fn($c) => empty($c['revokedAt'])));
    $openRequests = count(array_filter($arcoRequests, fn($r) => !in_array(
        $r['status'] ?? '',
        ['completed', 'resolved', 'finished', 'rejected'],
        true
    )));

    json_response([
        'email' => $email,
        'consents' => $consents,
        'arcoRequests' => $arcoRequests,
        'summary' => [
            'totalConsents' => count($consents),
            'activeConsents' => $activeConsents,
            'openRequests' => $openRequests,
        ],
    ]);
}

function portalRevokeConsent() {
    $email = Auth::requirePortalSession();
    $body = get_body();
    $consentId = $body['consentId'] ?? '';

    if (!$consentId) json_error('consentId requerido');

    $db = Database::getInstance();
    $consent = $db->findOne('compliance_consents', ['_id' => $consentId, 'email' => $email]);

    if (!$consent) json_error('consentimiento no encontrado', 404);
    if (!empty($consent['revokedAt'])) json_error('ya estaba revocado');

    $db->updateOne('compliance_consents', ['_id' => $consentId], [
        'active' => false,
        'revokedAt' => date('c'),
        'revokedVia' => 'portal_titular',
        'revokedFromIp' => $_SERVER['REMOTE_ADDR'] ?? '',
    ]);

    NotificationService::queue($db, [
        'companyId' => $consent['userId'] ?? '',
        'channel' => 'email',
        'recipient' => $email,
        'recipientType' => 'titular',
        'templateCode' => 'consent_revoked',
        'variables' => [
            'nombre' => $consent['name'] ?? '',
            'purpose' => $consent['purpose'] ?? '',
            'fecha' => date('d/m/Y'),
        ],
    ]);

    json_response(['success' => true]);
}

function portalDownloadAll() {
    $email = Auth::requirePortalSession();
    $db = Database::getInstance();

    $data = [
        'exportedAt' => date('c'),
        'subject' => ['email' => $email],
        'consents' => $db->find('compliance_consents', ['email' => $email]),
        'arcoRequests' => $db->find('arco_requests', ['solicitante.email' => $email]),
    ];

    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="mis-datos-' . date('Ymd-His') . '.json"');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}