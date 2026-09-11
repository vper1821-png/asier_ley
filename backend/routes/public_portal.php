<?php
require_once __DIR__ . '/../Auth.php';
require_once __DIR__ . '/../lib/NotificationService.php';

function portalRequestCode() {
    $body = get_body();
    $email = strtolower(trim($body['email'] ?? ''));
    
    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_error('email inválido');
    }
    
    $db = Database::getInstance();
    
    // Verificar que el email exista en alguna colección
    $exists = $db->findOne('compliance_consents', ['email' => $email])
           || $db->findOne('arco_requests', ['solicitante.email' => $email]);
    
    // Anti-enumeración: siempre OK
    if ($exists) {
        $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $db->insertOne('portal_sessions', [
            'email' => $email,
            'codeHash' => hash('sha256', $code),
            'expiresAt' => date('c', time() + 600),
            'used' => false,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            'createdAt' => date('c')
        ]);
        
        // Determinar companyId para la notificación
        $consent = $db->findOne('compliance_consents', ['email' => $email]);
        $companyId = $consent['userId'] ?? '';
        
        NotificationService::queue($db, [
            'companyId' => $companyId,
            'channel' => 'email',
            'recipient' => $email,
            'recipientType' => 'titular',
            'templateCode' => 'portal_magic_code',
            'variables' => ['code' => $code]
        ]);
    }
    
    json_response(['success' => true, 'message' => 'Si el email existe recibirás un código']);
}

function portalVerifyCode() {
    $body = get_body();
    $email = strtolower(trim($body['email'] ?? ''));
    $code = trim($body['code'] ?? '');
    
    if (!$email || !$code) json_error('email y código requeridos');
    
    $db = Database::getInstance();
    $session = $db->findOne('portal_sessions', [
        'email' => $email,
        'codeHash' => hash('sha256', $code),
        'used' => false,
        'expiresAt' => ['$gte' => date('c')]
    ]);
    
    if (!$session) json_error('código inválido o expirado');
    
    $token = Auth::createPortalToken($email);
    
    $db->updateOne('portal_sessions', ['_id' => $session['_id']], [
        'used' => true,
        'usedAt' => date('c')
    ]);
    
    json_response(['success' => true, 'token' => $token]);
}

function portalMyData() {
    $email = Auth::requirePortalSession();
    $db = Database::getInstance();
    
    $consents = $db->find('compliance_consents', ['email' => $email]);
    $arcoRequests = $db->find('arco_requests', ['solicitante.email' => $email]);
    
    $activeConsents = count(array_filter($consents, fn($c) => empty($c['revokedAt'])));
    $openRequests = count(array_filter($arcoRequests, fn($r) => !in_array($r['status'] ?? '', ['completed','resolved','finished','rejected'], true)));
    
    json_response([
        'email' => $email,
        'consents' => $consents,
        'arcoRequests' => $arcoRequests,
        'summary' => [
            'totalConsents' => count($consents),
            'activeConsents' => $activeConsents,
            'openRequests' => $openRequests,
        ]
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
        'revokedFromIp' => $_SERVER['REMOTE_ADDR'] ?? ''
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
            'fecha' => date('d/m/Y')
        ]
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